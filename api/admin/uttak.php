<?php
/**
 * Salg over disk.
 *
 *   GET                      varene som kan selges, og dagens salg
 *   POST handling=selg       { linjer: [{id, antall}], maate, kunde }
 *   POST handling=annuller   { ordreId }
 *
 * Butikken kunne bare selge gjennom nettbutikken, med Vipps. Selger Monica
 * en kopp til noen som staar i verkstedet, fantes det ikke noe sted aa
 * registrere det — hverken lageret eller omsetningen fikk vite om det.
 *
 * Salget blir en helt vanlig ordre med en betaling. Da dukker det opp i
 * omsetningen, i betalingslista og i transaksjonsuttrekket som alt annet,
 * uten en egen tabell aa holde i takt.
 *
 * Feilregistreringer slettes ikke. De annulleres: ordren settes til
 * kansellert, betalingen til refundert, og lageret legges tilbake. Sporet
 * blir staaende — det er et regnskap.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

/** Maatene et salg kan gjores opp paa. Samme liste som paameldingene bruker. */
const MAATER = ['Kontant', 'Vipps'];

if (Foresporsel::metode() === 'GET') {
    $varer = DB::alle(
        "SELECT id, tittel, kategori, pris_ore, lager, kun_medlemmer, status
           FROM products
          WHERE status <> 'kladd'
          ORDER BY kategori IS NULL, kategori, tittel"
    );

    // Dagens salg over disk, saa man ser hva som er slaatt inn — og kan
    // annullere det med en gang hvis det ble feil.
    $oslo   = new DateTimeZone('Europe/Oslo');
    $fraOslo = (new DateTimeImmutable('today', $oslo))->setTimezone(new DateTimeZone('UTC'));
    $idag = DB::alle(
        "SELECT o.id, o.ordrenr, o.sum_ore, o.status, o.betalt_maate, o.created_at,
                (SELECT GROUP_CONCAT(CONCAT(ol.antall, ' × ', ol.tittel) SEPARATOR ', ')
                   FROM order_lines ol WHERE ol.order_id = o.id) AS linjer
           FROM orders o
           JOIN payments p ON p.id = o.payment_id
          WHERE p.type = 'manuell' AND o.created_at >= :fra
          ORDER BY o.id DESC",
        ['fra' => $fraOslo->format('Y-m-d H:i:s')]
    );

    Svar::json([
        'maater' => MAATER,
        'varer'  => array_map(static fn($v) => [
            'id'        => (int) $v['id'],
            'tittel'    => (string) $v['tittel'],
            'kategori'  => (string) ($v['kategori'] ?? ''),
            'prisOre'   => (int) $v['pris_ore'],
            'pris'      => Booking::kroner((int) $v['pris_ore']),
            // NULL betyr «vi teller ikke lager paa denne».
            'lager'     => $v['lager'] === null ? null : (int) $v['lager'],
            'utsolgt'   => (string) $v['status'] === 'utsolgt',
            'kunMedlemmer' => (int) $v['kun_medlemmer'] === 1,
        ], $varer),
        'idag' => array_map(static fn($o) => [
            'id'      => (int) $o['id'],
            'ordrenr' => (string) $o['ordrenr'],
            'sum'     => Booking::kroner((int) $o['sum_ore']),
            'status'  => (string) $o['status'],
            'maate'   => (string) ($o['betalt_maate'] ?? ''),
            'linjer'  => (string) ($o['linjer'] ?? ''),
            'tid'     => Booking::norskDatoKort((string) $o['created_at']),
        ], $idag),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$kropp    = Foresporsel::kropp();
$handling = Foresporsel::tekst('handling', 'selg');

// ─────────────────────────────────────────────────────────────── annuller
if ($handling === 'annuller') {
    $ordreId = (int) ($kropp['ordreId'] ?? 0);

    $ordre = DB::en(
        "SELECT o.*, p.id AS betaling_id, p.belop_ore, p.type AS betalingstype
           FROM orders o JOIN payments p ON p.id = o.payment_id
          WHERE o.id = :i",
        ['i' => $ordreId]
    );
    if ($ordre === null) {
        Svar::feil('Fant ikke salget.', 404);
    }
    if ((string) $ordre['betalingstype'] !== 'manuell') {
        Svar::feil('Dette salget er gjort opp i Vipps. Det må refunderes der.', 409);
    }
    if ((string) $ordre['status'] === 'kansellert') {
        Svar::feil('Salget er alt annullert.');
    }

    DB::iTransaksjon(static function () use ($ordre): void {
        // Varene tilbake paa lager. Bare der lager telles.
        foreach (DB::alle('SELECT product_id, antall FROM order_lines WHERE order_id = :o',
                          ['o' => (int) $ordre['id']]) as $l) {
            if ($l['product_id'] === null) {
                continue;
            }
            DB::kjor(
                'UPDATE products SET lager = lager + :a WHERE id = :p AND lager IS NOT NULL',
                ['a' => (int) $l['antall'], 'p' => (int) $l['product_id']]
            );
        }
        DB::oppdater('orders', ['status' => 'kansellert'], ['id' => (int) $ordre['id']]);
        DB::oppdater('payments', [
            'status'         => 'refundert',
            'refundert_ore'  => (int) $ordre['belop_ore'],
        ], ['id' => (int) $ordre['betaling_id']]);
    });

    revider('uttak_annullert', 'ordre', (int) $ordre['id'], ['ordrenr' => $ordre['ordrenr']]);
    Svar::ok(['beskjed' => 'Salget er annullert, og varene er lagt tilbake på lager.']);
}

// ────────────────────────────────────────────────────────────────── selg
if ($handling !== 'selg') {
    Svar::feil('Ukjent handling.');
}

$linjerInn = $kropp['linjer'] ?? [];
if (!is_array($linjerInn) || $linjerInn === []) {
    Svar::feil('Legg til minst én vare.');
}
if (count($linjerInn) > 50) {
    Svar::feil('For mange varer i ett salg.');
}

$maate = (string) ($kropp['maate'] ?? MAATER[0]);
if (!in_array($maate, MAATER, true)) {
    Svar::feil('Ukjent betalingsmåte.');
}

// Prisen hentes fra basen, aldri fra nettleseren. Ellers kunne summen i
// regnskapet vaert en annen enn den varen koster.
$rader = [];
$sum   = 0;
foreach ($linjerInn as $l) {
    $id     = (int) ($l['id'] ?? 0);
    $antall = max(1, min(999, (int) ($l['antall'] ?? 1)));

    $vare = DB::en('SELECT id, tittel, pris_ore, lager FROM products WHERE id = :i', ['i' => $id]);
    if ($vare === null) {
        Svar::feil('En av varene finnes ikke lenger. Last siden på nytt.', 409);
    }
    if ($vare['lager'] !== null && (int) $vare['lager'] < $antall) {
        Svar::feil('Det er bare ' . (int) $vare['lager'] . ' igjen av «' . $vare['tittel'] . '».', 409);
    }

    $sum += (int) $vare['pris_ore'] * $antall;
    $rader[] = ['vare' => $vare, 'antall' => $antall];
}

if ($sum <= 0) {
    Svar::feil('Salget har ingen sum.');
}

$kunde   = mb_substr(trim((string) ($kropp['kunde'] ?? '')), 0, 191);
$ordrenr = 'D-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

$ordreId = DB::iTransaksjon(static function () use ($rader, $sum, $maate, $kunde, $ordrenr): int {
    $betalingId = DB::settInn('payments', [
        // Referansen er paakrevd og unik. Et disksalg har ingen fra Vipps,
        // saa den lages her — med en egen forstavelse, saa det er tydelig
        // hvor den kommer fra.
        'vipps_reference' => 'KASSE-' . $ordrenr,
        'type'            => 'manuell',
        'formal'          => 'ordre',
        'belop_ore'       => $sum,
        'status'          => 'betalt',
        'idempotency_key' => Vipps::uuid(),
    ]);

    $id = DB::settInn('orders', [
        'ordrenr'      => $ordrenr,
        'kunde_navn'   => $kunde !== '' ? $kunde : 'Salg over disk',
        'sum_ore'      => $sum,
        // Kunden gaar ut av doera med varen. Da er den hentet.
        'status'       => 'hentet',
        'betalt_maate' => $maate,
        'payment_id'   => $betalingId,
    ]);

    foreach ($rader as $r) {
        DB::settInn('order_lines', [
            'order_id'   => $id,
            'product_id' => (int) $r['vare']['id'],
            // Tittelen kopieres inn: varen kan endre navn senere, men
            // kvitteringen skal vise hva som faktisk ble solgt.
            'tittel'     => (string) $r['vare']['tittel'],
            'antall'     => (int) $r['antall'],
            'pris_ore'   => (int) $r['vare']['pris_ore'],
        ]);
        if ($r['vare']['lager'] !== null) {
            DB::kjor(
                'UPDATE products SET lager = GREATEST(0, lager - :a) WHERE id = :p AND lager IS NOT NULL',
                ['a' => (int) $r['antall'], 'p' => (int) $r['vare']['id']]
            );
        }
    }

    return $id;
});

revider('uttak_registrert', 'ordre', $ordreId, ['ordrenr' => $ordrenr, 'sum' => $sum, 'maate' => $maate]);

Svar::ok([
    'ordrenr' => $ordrenr,
    'sum'     => Booking::kroner($sum),
    'beskjed' => 'Salget er registrert: ' . Booking::kroner($sum) . ' med ' . mb_strtolower($maate) . '.',
]);
