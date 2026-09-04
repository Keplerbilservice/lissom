<?php
/**
 * Salg over disk.
 *
 *   GET                      varene som kan selges, og dagens salg
 *   POST handling=salg       { belop, maate, slag, kunde }   ett belop, ett trykk
 *   POST handling=vippskrav  { belop, slag, kunde, telefon }  krav rett i Vipps
 *   POST handling=selg       { linjer: [{id, antall}], maate, kunde }
 *   POST handling=delt       { slag, kunde, gavekortKode, deler: [{maate, belop}] }
 *   POST handling=gavekort   { belop, opprinnelse, maate, mottakerEpost, hilsen }
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

$admin = krev_admin();

/** Maatene et salg kan gjores opp paa. Samme liste som paameldingene bruker. */
const MAATER = ['Kontant', 'Vipps'];

/**
 * Maatene et salg med fritt beloep kan foeres paa.
 *
 * «Gratis» kom 4. september: eieren, «jeg maa ha et null kroners salg over
 * disk ogsaa». En ting gitt bort er fortsatt et salg som skal staa i lista —
 * den er bare null kroner.
 *
 * «Ikke betalt» kom samme dag: «kassa skal kunne foere som ikke betalt». Det
 * er det motsatte av «Gratis» — beloepet staar, men pengene er ikke kommet.
 * Salget lages uten betalingsrad, akkurat som en haandfoert kursplass merket
 * «Ikke betalt», og dukker opp i lista over dem som skylder til noen trykker
 * «Kontant» eller «Vipps» ved navnet.
 *
 * Den staar bare her, og ikke i MAATER, fordi de andre veiene inn i kassa
 * regner ut summen selv: varekurven henter prisen fra basen, og gavekortet
 * skal lyde paa et beloep. Der ville «Gratis» blitt en betalingsrad med full
 * sum og status «betalt» uten at det kom inn en krone — regnskapet ville
 * loeyet. Et gavekort som gis bort har allerede sin egen vei: opprinnelse
 * «gitt».
 *
 * Dagsoppgjoret deler dagen i Kontant og Vipps. «Gratis» er null kroner og
 * legger derfor null til uansett hvilken kolonne den havner i — den
 * forstyrrer ingen telling. Samme regel som «Gratis» har paa kurs, se
 * api/admin/kursbetaling.php.
 */
const SALGMAATER = ['Kontant', 'Vipps', 'Gratis', 'Ikke betalt'];

/**
 * Maatene et varesalg fra kurven kan foeres paa.
 *
 * «Ikke betalt» kom 4. september: eieren, «kassa skal kunne foere som ikke
 * betalt», etter «her skal alle som ikke har betalt vises, om det er butikk,
 * kurs eller medlemskap». Varen gaar ut av doera, pengene kommer senere.
 *
 * «Gratis» staar ikke her. Kurven henter prisen fra basen, og en gratislinje
 * ville betydd at varen skulle vaere gratis for alle. Et fritt beloep kan
 * settes til null; en hylleprise kan det ikke.
 */
const KURVMAATER = ['Kontant', 'Vipps', 'Ikke betalt'];

/**
 * Maatene en DEL av et oppgjor kan vaere.
 *
 * Gavekortet staar her og ikke i MAATER, fordi det ikke er en maate aa
 * betale paa: det er ingen penger inn. Et salg som gjores opp med ett
 * beloep kan ikke vaere «Gavekort» alene uten en kode aa trekke fra, og
 * derfor er lista delt i to.
 */
const DELMAATER = ['Gavekort', 'Kontant', 'Vipps'];

/**
 * Leser et beloep slik det ble tastet, og gir oere.
 *
 * Kassa tastes paa i en fart, og «690», «690,-», «kr. 690» og «690.50» er
 * alle det samme. Regningen gjores her og ikke i nettleseren, der den kan
 * endres paa veien.
 *
 * @return int|null null naar det ikke er et tall i det hele tatt. Et tomt
 *                 felt gir 0 — skjermen har flere felter, og de fleste salg
 *                 bruker ikke alle.
 */
function les_belop(mixed $raa): ?int
{
    $t = str_replace([' ', "\u{a0}", 'kr', ',-'], '', (string) $raa);
    $t = trim(str_replace(',', '.', $t));
    if ($t === '') {
        return 0;
    }
    if (!is_numeric($t)) {
        return null;
    }
    return (int) round((float) $t * 100);
}

/**
 * Hva et salg over disk er, regnskapsmessig.
 *
 * Nokkelen er det som staar paa skjermen. Verdien er `payments.formal`, som
 * er den dagsoppgjoret og transaksjonsuttrekket grupperer paa — og det er
 * derfra kontoen og mva-koden hentes, fra oppsettet under OEkonomi. Et
 * kassesalg blir dermed liggende paa samme konto som det samme salget gjort
 * paa nett, uten et eget regelverk ved siden av.
 */
const SLAG = [
    'kurs'       => ['formal' => 'booking',    'tittel' => 'Kurs — solgt i verkstedet'],
    'medlemskap' => ['formal' => 'medlemskap', 'tittel' => 'Medlemskap — solgt i verkstedet'],
    'produkt'    => ['formal' => 'ordre',      'tittel' => 'Produkt — solgt i verkstedet'],
];

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
      LEFT JOIN payments p ON p.id = o.payment_id
          WHERE o.created_at >= :fra
            AND (p.type = 'manuell'
                 -- Et salg foert som «Ikke betalt» har ingen betalingsrad.
                 -- Uten dette leddet sto det ingen steder i kassa, og en
                 -- feilregistrering kunne ikke annulleres. Summen for dagen
                 -- teller det ikke med — se «utDagsum» i nettleseren.
                 OR (o.payment_id IS NULL AND o.betalt_maate = 'Ikke betalt'))
          ORDER BY o.id DESC",
        ['fra' => $fraOslo->format('Y-m-d H:i:s')]
    );

    Svar::json([
        'maater' => MAATER,
        // Fritt beloep har to maater mer enn de andre: «Gratis» og
        // «Ikke betalt». Kurven har den siste, men ikke den forste.
        'salgmaater' => SALGMAATER,
        'kurvmaater' => KURVMAATER,
        // Delene et oppgjor kan settes sammen av. Skjermen bygger ett felt
        // per maate, og gavekortet er den som ogsaa trenger en kode.
        'delmaater' => DELMAATER,
        // Uten kolonnene fra migrasjon 134 kan hverken delt oppgjor eller
        // utstedelse foeres riktig. Da skal knappene si fra framfor aa lage
        // en betaling regnskapet ikke kan tolke.
        'kanDele'     => DB::harKolonne('payments', 'order_id'),
        'kanUtstede'  => DB::harKolonne('gift_cards', 'opprinnelse'),
        'slag'   => array_map(
            static fn(string $n): array => ['verdi' => $n, 'navn' => ucfirst($n)],
            array_keys(SLAG)
        ),
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
// ────────────────────────────────────────────────────── gjor opp gjelden
//
// Et salg foert som «Ikke betalt» staar i lista over dem som skylder, med
// «Kontant» og «Vipps» ved siden av navnet. Ett trykk gjor det opp.
//
// Her lages betalingsraden som ikke ble laget da salget gikk over disken.
// Fra dette oeyeblikket er salget som ethvert annet kassesalg: samme rad,
// samme kobling, samme vei inn i omsetningen og dagsoppgjoret. Datoen paa
// raden er i dag, for det er i dag pengene kom.
if ($handling === 'gjorOpp') {
    $ordreId = (int) ($kropp['ordreId'] ?? 0);
    $maate   = (string) ($kropp['maate'] ?? MAATER[0]);
    // Bare de to som faktisk foerer penger. «Gratis» og «Ikke betalt» ville
    // vaert aa gjore opp uten aa gjore opp.
    if (!in_array($maate, MAATER, true)) {
        Svar::feil('Velg kontant eller Vipps.');
    }

    $ordre = DB::en('SELECT id, ordrenr, sum_ore, status, betalt_maate, payment_id
                       FROM orders WHERE id = :i', ['i' => $ordreId]);
    if ($ordre === null) {
        Svar::feil('Fant ikke salget.', 404);
    }
    // Betalingsraden er sperren, ikke maaten. Har salget en rad, er det
    // gjort opp — eller det venter paa Vipps, og da skal ikke vi roere det.
    if ($ordre['payment_id'] !== null) {
        Svar::feil('Salget er alt gjort opp.', 409);
    }
    if (in_array((string) $ordre['status'], ['kansellert', 'refundert'], true)) {
        Svar::feil('Salget er annullert.', 409);
    }
    $sum = (int) $ordre['sum_ore'];
    if ($sum <= 0) {
        Svar::feil('Salget har ingen sum å gjøre opp.');
    }

    // Hvilken konto og mva-kode salget skal foeres paa.
    //
    // Et fritt beloep kan vaere kurs, medlemskap eller produkt, og det
    // avgjor kontoen i dagsoppgjoret. Uten betalingsrad var det ingen
    // «formal» aa arve, saa den leses av linja salget ble skrevet med —
    // samme SLAG-tabell som skrev den, lest andre veien, saa de to ikke kan
    // drive fra hverandre. En kurv fra hylla har varenes egne titler og
    // faller til «ordre», som er riktig: det er butikk.
    $linjetittel = (string) DB::verdi(
        'SELECT tittel FROM order_lines WHERE order_id = :o ORDER BY id LIMIT 1',
        ['o' => $ordreId]
    );
    $formal = 'ordre';
    foreach (SLAG as $def) {
        if ($def['tittel'] === $linjetittel) {
            $formal = $def['formal'];
            break;
        }
    }

    $adminId = (int) ($admin['id'] ?? 0);
    DB::iTransaksjon(static function () use ($ordre, $sum, $maate, $adminId, $formal): void {
        $felt = [
            'vipps_reference' => 'KASSE-' . $ordre['ordrenr'],
            'type'            => 'manuell',
            'formal'          => $formal,
            'belop_ore'       => $sum,
            'status'          => 'betalt',
            'idempotency_key' => Vipps::uuid(),
        ];
        if (DB::harKolonne('payments', 'maate')) {
            $felt['maate'] = $maate;
        }
        if (DB::harKolonne('payments', 'registrert_av') && $adminId > 0) {
            $felt['registrert_av'] = $adminId;
        }
        $betalingId = DB::settInn('payments', $felt);

        DB::oppdater('orders', [
            'betalt_maate' => $maate,
            'payment_id'   => $betalingId,
        ], ['id' => (int) $ordre['id']]);

        if (DB::harKolonne('payments', 'order_id')) {
            DB::oppdater('payments', ['order_id' => (int) $ordre['id']], ['id' => $betalingId]);
        }
    });

    revider('uttak_gjort_opp', 'ordre', $ordreId,
            ['ordrenr' => $ordre['ordrenr'], 'sum' => $sum, 'maate' => $maate]);

    Svar::ok(['beskjed' => $ordre['ordrenr'] . ' er gjort opp med '
                         . mb_strtolower($maate) . ' — ' . Booking::kroner($sum) . '.']);
}

if ($handling === 'annuller') {
    $ordreId = (int) ($kropp['ordreId'] ?? 0);

    // «LEFT JOIN»: et salg foert som «Ikke betalt» har ingen betalingsrad,
    // og skal likevel kunne annulleres — en feilregistrering maa kunne tas
    // bort samme dag som alle andre.
    $ordre = DB::en(
        "SELECT o.*, p.id AS betaling_id, p.belop_ore, p.type AS betalingstype
           FROM orders o LEFT JOIN payments p ON p.id = o.payment_id
          WHERE o.id = :i",
        ['i' => $ordreId]
    );
    if ($ordre === null) {
        Svar::feil('Fant ikke salget.', 404);
    }
    $ubetaltSalg = $ordre['payment_id'] === null
        && (string) ($ordre['betalt_maate'] ?? '') === 'Ikke betalt';
    if (!$ubetaltSalg && (string) $ordre['betalingstype'] !== 'manuell') {
        Svar::feil('Dette salget er gjort opp i Vipps. Det må refunderes der.', 409);
    }
    if ((string) $ordre['status'] === 'kansellert') {
        Svar::feil('Salget er alt annullert.');
    }

    // Alle delene salget ble gjort opp med.
    //
    // Et delt oppgjor har én rad per del, og «orders.payment_id» peker bare
    // paa den forste. Annullerte vi den alene, ville kontantdelen staatt igjen
    // som betalt og dagen summert til for mye. «payments.order_id» kom med
    // migrasjon 134 og finner dem alle; finnes ikke kolonnen, er det ett salg
    // med én rad, slik det alltid har vaert.
    $deler = DB::harKolonne('payments', 'order_id')
        ? DB::alle(
            'SELECT id, belop_ore, refundert_ore FROM payments WHERE order_id = :o',
            ['o' => (int) $ordre['id']]
        )
        : [];
    if ($deler === []) {
        $deler = [[
            'id'            => (int) $ordre['betaling_id'],
            'belop_ore'     => (int) $ordre['belop_ore'],
            'refundert_ore' => 0,
        ]];
    }

    // Gavekortet forst, og utenfor transaksjonen under: trekket ble gjort med
    // Booking::trekkGavekort(), og motstykket hoerer sammen med den — ikke med
    // lageret.
    $tilbake = 0;
    foreach ($deler as $d) {
        $tilbake += Booking::angreGavekort((int) $d['id']);
    }

    DB::iTransaksjon(static function () use ($ordre, $deler): void {
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
        foreach ($deler as $d) {
            DB::oppdater('payments', [
                'status'         => 'refundert',
                'refundert_ore'  => (int) $d['belop_ore'],
            ], ['id' => (int) $d['id']]);
        }
    });

    revider('uttak_annullert', 'ordre', (int) $ordre['id'], [
        'ordrenr'         => $ordre['ordrenr'],
        'deler'           => count($deler),
        'gavekort_ore'    => $tilbake,
    ]);
    Svar::ok(['beskjed' => 'Salget er annullert, og varene er lagt tilbake på lager.'
        . ($tilbake > 0
            ? ' ' . Booking::kroner($tilbake) . ' er lagt tilbake på gavekortet.'
            : '')]);
}

// ────────────────────────────────────────────────────────────── salg
//
// Ett belop, én betalingsmaate, og hva det var. Ingen varelinjer aa lete
// fram: det aller meste som selges over disk er et kurs noen betaler for i
// doera, et medlemskap, eller en ting fra hylla.
// ── Vipps-QR ────────────────────────────────────────────────────────────
//
// Salgsenheten har ikke lov til aa sende betalingskrav — Vipps svarer 400 med
// «ErrorCode 5080». Det er en tillatelse hos Vipps, og ingen kode retter den.
//
// En helt vanlig Vipps-betaling virker derimot. Den gir oss en betalingsadresse
// tilbake, og den kan vises som en QR paa skjermen i verkstedet. Kunden skanner
// med kameraet og betaler i Vipps.
//
// Forskjellen fra kravet: kunden maa vaere til stede. Til gjengjeld slipper hun
// aa oppgi nummeret sitt, og vi slipper aa vente paa Vipps.
//
// Alt annet er likt: samme ordre, samme betalingsrad, «ny» til pengene er inne,
// og webhooken gjor den ferdig.
if ($handling === 'vippsqr') {
    $slag = (string) ($kropp['slag'] ?? 'produkt');
    if (!isset(SLAG[$slag])) {
        Svar::feil('Velg om det er kurs, medlemskap eller produkt.');
    }

    $raa = str_replace([' ', "\u{a0}", 'kr', ',-'], '', (string) ($kropp['belop'] ?? ''));
    $raa = str_replace(',', '.', trim($raa));
    if ($raa === '' || !is_numeric($raa)) {
        Svar::feil('Skriv inn et beløp.');
    }
    $sum = (int) round((float) $raa * 100);
    if ($sum <= 0) {
        Svar::feil('Beløpet må være over null.');
    }
    if ($sum > 10000000) {
        Svar::feil('Beløpet må være under 100 000 kroner.');
    }

    $kunde   = mb_substr(trim((string) ($kropp['kunde'] ?? '')), 0, 191);
    $ordrenr = 'Q-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $formal  = SLAG[$slag]['formal'];
    $tittel  = SLAG[$slag]['tittel'];
    $referanse = Vipps::nyReferanse('QR');

    $ordreId = DB::iTransaksjon(static function () use ($sum, $kunde, $ordrenr, $formal, $tittel, $referanse): int {
        $betalingId = DB::settInn('payments', [
            'vipps_reference' => $referanse,
            'type'            => 'epayment',
            'formal'          => $formal,
            'belop_ore'       => $sum,
            'status'          => 'opprettet',
            'idempotency_key' => Vipps::uuid(),
        ]);
        $id = DB::settInn('orders', [
            'ordrenr'      => $ordrenr,
            'kunde_navn'   => $kunde !== '' ? $kunde : 'Vipps-QR',
            'sum_ore'      => $sum,
            'status'       => 'ny',
            'betalt_maate' => 'Vipps',
            'payment_id'   => $betalingId,
        ]);

        // Betalingen peker tilbake paa ordren.
        //
        // Raden lages foer ordren — den maa finnes for ordren kan peke paa
        // den — saa koblingen settes her. «payments.order_id» kom med
        // migrasjon 134, og skal bety det samme paa alle salg: alle radene
        // som hoerer til ordren. Sto den bare paa de delte, ville et vanlig
        // kassesalg vaert usynlig for alt som leser den.
        if (DB::harKolonne('payments', 'order_id')) {
            DB::oppdater('payments', ['order_id' => $id], ['id' => $betalingId]);
        }
        DB::settInn('order_lines', [
            'order_id' => $id, 'product_id' => null,
            'tittel' => $tittel, 'antall' => 1, 'pris_ore' => $sum,
        ]);
        return $id;
    });

    try {
        // Ingen telefon, og push staar av: dette er den vanlige veien, der
        // kunden foelger en adresse. Adressen er det QR-en peker paa.
        $svar = Vipps::opprettBetaling(
            $referanse,
            $sum,
            $tittel . ' — Lissom Keramikk',
            Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse)
        );
    } catch (Throwable $e) {
        DB::kjor('DELETE FROM order_lines WHERE order_id = :o', ['o' => $ordreId]);
        $pid = DB::verdi('SELECT payment_id FROM orders WHERE id = :o', ['o' => $ordreId]);
        DB::kjor('DELETE FROM orders WHERE id = :o', ['o' => $ordreId]);
        if ($pid) { DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pid]); }
        logg_feil('Fikk ikke laget Vipps-QR for ordre ' . $ordrenr, $e);
        Svar::feil('Fikk ikke laget koden. ' . $e->getMessage() . ' Ingenting er registrert.');
    }

    $url = trim((string) ($svar['url'] ?? ''));
    if ($url === '') {
        DB::kjor('DELETE FROM order_lines WHERE order_id = :o', ['o' => $ordreId]);
        $pid = DB::verdi('SELECT payment_id FROM orders WHERE id = :o', ['o' => $ordreId]);
        DB::kjor('DELETE FROM orders WHERE id = :o', ['o' => $ordreId]);
        if ($pid) { DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pid]); }
        Svar::feil('Vipps ga ingen betalingsadresse. Ingenting er registrert.');
    }

    DB::oppdater('payments', ['status' => 'venter'], ['vipps_reference' => $referanse]);
    revider('vippsqr_laget', 'order', $ordreId, ['belop' => $sum, 'slag' => $slag]);

    Svar::ok([
        'url'       => $url,
        'referanse' => $referanse,
        'belop'     => Booking::kroner($sum),
        'beskjed'   => 'Koden er klar. La kunden skanne den — salget står som '
                     . 'venter til pengene er inne.',
    ]);
}

// ── Betalt ennaa? ───────────────────────────────────────────────────────
//
// Skjermen viser QR-en mens kunden skanner. Webhooken setter betalingen til
// «betalt» naar pengene er inne, men skjermen faar ikke vite det av seg selv.
// Her spor den.
if ($handling === 'betalstatus') {
    $ref = trim((string) ($kropp['referanse'] ?? ''));
    if ($ref === '') {
        Svar::feil('Mangler referansen.');
    }
    $rad = DB::en('SELECT status FROM payments WHERE vipps_reference = :r', ['r' => $ref]);
    if ($rad === null) {
        Svar::feil('Fant ikke betalingen.', 404);
    }
    Svar::ok(['status' => (string) $rad['status'],
              'betalt' => (string) $rad['status'] === 'betalt']);
}

// ── Vippskrav ───────────────────────────────────────────────────────────
//
// «Registrer et salg» bokforer at noe ER betalt. Den sender ingenting.
// Eieren: «jeg faar registrert medlemskap, men jeg faar jo ikke sendt ut
// vippskrav. Jeg faar jo ikke inn penga.»
//
// Her sendes kravet rett i Vipps-appen til den vi ber — kunden trenger ikke
// staa foran skjermen. Salget staar som «venter» til pengene er i havn;
// webhooken og Booking::markerBetalt() gjor det ferdig, akkurat som en
// betaling fra nettsida.
if ($handling === 'vippskrav') {
    $slag = (string) ($kropp['slag'] ?? 'produkt');
    if (!isset(SLAG[$slag])) {
        Svar::feil('Velg om det er kurs, medlemskap eller produkt.');
    }

    $tlf = normaliser_telefon((string) ($kropp['telefon'] ?? ''));
    if ($tlf === '') {
        Svar::feil('Skriv inn mobilnummeret kravet skal til.');
    }

    $raa = str_replace([' ', "\u{a0}", 'kr', ',-'], '', (string) ($kropp['belop'] ?? ''));
    $raa = str_replace(',', '.', trim($raa));
    if ($raa === '' || !is_numeric($raa)) {
        Svar::feil('Skriv inn et beløp.');
    }
    $sum = (int) round((float) $raa * 100);
    if ($sum <= 0) {
        Svar::feil('Beløpet må være over null.');
    }
    if ($sum > 10000000) {
        Svar::feil('Beløpet må være under 100 000 kroner.');
    }

    $kunde   = mb_substr(trim((string) ($kropp['kunde'] ?? '')), 0, 191);
    $ordrenr = 'K-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $formal  = SLAG[$slag]['formal'];
    $tittel  = SLAG[$slag]['tittel'];
    $referanse = Vipps::nyReferanse('KRV');

    $ordreId = DB::iTransaksjon(static function () use ($sum, $kunde, $ordrenr, $formal, $tittel, $referanse, $tlf): int {
        $betalingId = DB::settInn('payments', [
            'vipps_reference' => $referanse,
            'type'            => 'epayment',
            'formal'          => $formal,
            'belop_ore'       => $sum,
            'status'          => 'opprettet',
            'idempotency_key' => Vipps::uuid(),
        ]);
        // «ny», ikke «hentet»: ingenting er betalt for kunden har trykket ja i
        // appen. Sto den som hentet, ville dagsoppgjoret talt penger som aldri
        // kom. «ny» er den eneste statusen ordretabellen har for «ikke betalt
        // ennaa» — se enum-et paa orders.status; «venter» finnes ikke der, og
        // MariaDB kappet den til tom streng.
        //
        // Booking::markerBetalt() setter den til «betalt» naar pengene er inne.
        $id = DB::settInn('orders', [
            'ordrenr'      => $ordrenr,
            'kunde_navn'   => $kunde !== '' ? $kunde : $tlf,
            'sum_ore'      => $sum,
            'status'       => 'ny',
            'betalt_maate' => 'Vipps',
            'payment_id'   => $betalingId,
        ]);

        // Betalingen peker tilbake paa ordren.
        //
        // Raden lages foer ordren — den maa finnes for ordren kan peke paa
        // den — saa koblingen settes her. «payments.order_id» kom med
        // migrasjon 134, og skal bety det samme paa alle salg: alle radene
        // som hoerer til ordren. Sto den bare paa de delte, ville et vanlig
        // kassesalg vaert usynlig for alt som leser den.
        if (DB::harKolonne('payments', 'order_id')) {
            DB::oppdater('payments', ['order_id' => $id], ['id' => $betalingId]);
        }
        DB::settInn('order_lines', [
            'order_id' => $id, 'product_id' => null,
            'tittel' => $tittel, 'antall' => 1, 'pris_ore' => $sum,
        ]);
        return $id;
    });

    try {
        Vipps::opprettBetaling(
            $referanse,
            $sum,
            $tittel . ' — Lissom Keramikk',
            Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
            $tlf,
            true
        );
    } catch (Throwable $e) {
        // Kravet kom aldri av gaarde. Da skal det ikke ligge igjen en ordre
        // som ser ut som om noen skylder oss penger.
        DB::kjor('DELETE FROM order_lines WHERE order_id = :o', ['o' => $ordreId]);
        $pid = DB::verdi('SELECT payment_id FROM orders WHERE id = :o', ['o' => $ordreId]);
        DB::kjor('DELETE FROM orders WHERE id = :o', ['o' => $ordreId]);
        if ($pid) { DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pid]); }
        logg_feil('Fikk ikke sendt vippskrav til ' . $tlf, $e);
        // Grunnen slik Vipps ga den. Sto det bare «prov igjen», var det ingen
        // vei videre for den som ikke har tilgang til feilloggen paa
        // webhotellet — og de fleste grunnene loeses ikke ved aa prove igjen.
        Svar::feil('Fikk ikke sendt kravet. ' . $e->getMessage()
                 . ' Ingenting er registrert.');
    }

    DB::oppdater('payments', ['status' => 'venter'], ['vipps_reference' => $referanse]);
    revider('vippskrav_sendt', 'order', $ordreId, ['belop' => $sum, 'slag' => $slag]);

    Svar::ok([
        'beskjed' => 'Kravet på ' . Booking::kroner($sum) . ' er sendt til ' . $tlf
            . '. Salget står som ubetalt til kravet er godtatt.',
    ]);
}

if ($handling === 'salg') {
    $slag = (string) ($kropp['slag'] ?? 'produkt');
    if (!isset(SLAG[$slag])) {
        Svar::feil('Velg om det er kurs, medlemskap eller produkt.');
    }

    $maate = (string) ($kropp['maate'] ?? SALGMAATER[0]);
    if (!in_array($maate, SALGMAATER, true)) {
        Svar::feil('Velg kontant, Vipps eller gratis.');
    }

    // Belopet kommer i kroner, med komma eller punktum. Vi regner i oere, og
    // gjor det her — ikke i nettleseren, der det kan endres paa veien.
    $raa = str_replace([' ', "\u{a0}", 'kr', ',-'], '', (string) ($kropp['belop'] ?? ''));
    $raa = str_replace(',', '.', trim($raa));
    // Er det gratis, er det ikke noe beloep aa taste. Samme regel som paa
    // kurs, se api/admin/kursbetaling.php.
    if ($maate === 'Gratis' && $raa === '') {
        $raa = '0';
    }
    if ($raa === '' || !is_numeric($raa)) {
        Svar::feil('Skriv inn et beløp.');
    }
    $sum = (int) round((float) $raa * 100);
    // Null er lov naar det ER gratis, og bare da. Et tomt eller null beloep
    // med en betalingsmaate paa er en glipp, ikke et valg.
    if ($sum < 0 || ($sum === 0 && $maate !== 'Gratis')) {
        Svar::feil($maate === 'Gratis'
            ? 'Beløpet kan ikke være negativt.'
            : 'Beløpet må være over null, eller velg «Gratis».');
    }
    if ($sum > 10000000) {
        Svar::feil('Beløpet må være under 100 000 kroner.');
    }

    $kunde   = mb_substr(trim((string) ($kropp['kunde'] ?? '')), 0, 191);
    $ordrenr = 'D-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $formal  = SLAG[$slag]['formal'];
    $tittel  = SLAG[$slag]['tittel'];

    $ordreId = DB::iTransaksjon(static function () use ($sum, $maate, $kunde, $ordrenr, $formal, $tittel): int {
        // Et ubetalt salg har ingen betalingsrad.
        //
        // Raden er selve pengene: den er det omsetningen, dagsoppgjoret og
        // betalingslista leser. Lagde vi en med status «venter», ville hvert
        // sted maattet huske aa se bort fra den — og det stedet som glemte
        // det, ville talt penger som ikke finnes.
        //
        // Samme vei som en haandfoert kursplass merket «Ikke betalt»: ingen
        // rad, og «payment_id IS NULL» er det som skiller den fra et
        // forlatt Vipps-forsoek, som alltid har en.
        $ubetalt = $maate === 'Ikke betalt';

        $betalingId = $ubetalt ? null : DB::settInn('payments', [
            'vipps_reference' => 'KASSE-' . $ordrenr,
            'type'            => 'manuell',
            // Denne avgjor kontoen og mva-koden i dagsoppgjoret.
            'formal'          => $formal,
            'belop_ore'       => $sum,
            'status'          => 'betalt',
            'idempotency_key' => Vipps::uuid(),
        ]);

        $id = DB::settInn('orders', [
            'ordrenr'      => $ordrenr,
            'kunde_navn'   => $kunde !== '' ? $kunde : 'Salg over disk',
            'sum_ore'      => $sum,
            // Varen gaar ut av doera uansett om den er betalt. «hentet» sier
            // hvor varen er, ikke hvor pengene er.
            'status'       => 'hentet',
            'betalt_maate' => $maate,
            'payment_id'   => $betalingId,
        ]);

        // Betalingen peker tilbake paa ordren.
        //
        // Raden lages foer ordren — den maa finnes for ordren kan peke paa
        // den — saa koblingen settes her. «payments.order_id» kom med
        // migrasjon 134, og skal bety det samme paa alle salg: alle radene
        // som hoerer til ordren. Sto den bare paa de delte, ville et vanlig
        // kassesalg vaert usynlig for alt som leser den.
        if ($betalingId !== null && DB::harKolonne('payments', 'order_id')) {
            DB::oppdater('payments', ['order_id' => $id], ['id' => $betalingId]);
        }

        // Én linje, saa salget staar med et navn i «Solgt i dag» og paa
        // kvitteringen. Ingen product_id: dette er ikke en vare fra hylla,
        // og lageret skal ikke roeres.
        DB::settInn('order_lines', [
            'order_id'   => $id,
            'product_id' => null,
            'tittel'     => $tittel,
            'antall'     => 1,
            'pris_ore'   => $sum,
        ]);

        return $id;
    });

    revider('kassesalg_registrert', 'ordre', $ordreId,
            ['ordrenr' => $ordrenr, 'sum' => $sum, 'maate' => $maate, 'slag' => $slag]);

    // Belopet skrives ut slik det ble tastet. Booking::kroner() runder til
    // hele kroner, og «199,50» ville da kvittert med «kr. 200,-».
    $belopTekst = $sum % 100 === 0
        ? Booking::kroner($sum)
        : 'kr. ' . number_format($sum / 100, 2, ',', "\u{a0}");

    Svar::ok([
        'ordrenr' => $ordrenr,
        'beskjed' => $tittel . ' · ' . $belopTekst . ' · ' . $maate . '. '
                   . ($maate === 'Ikke betalt'
                        ? 'Det står under «Ikke betalt» til pengene er inne.'
                        : 'Det er med i regnskapet.'),
    ]);
}

// ─────────────────────────────────────────────────────────────── delt
//
// Ett salg, gjort opp i flere deler.
//
// Eieren: «de har et gavekort, men ikke paa hele beloepet ... jeg maa kunne
// taste inn gavekort, kontant og vipps, de maa kunne dele opp.»
//
// «salg» tar ett beloep med én maate. Skulle noen betale 300 med gavekort,
// 200 kontant og resten i Vipps, matte det slaas inn som tre salg — og da
// stemte verken ordrenummeret eller kvitteringen.
//
// Her blir det én ordre med én rad i payments per del. Radene henger sammen
// gjennom «payments.order_id» fra migrasjon 134, og hver av dem sier selv
// hvordan pengene kom inn, i «payments.maate». Dagsoppgjoret leser den, saa
// kontantdelen og Vipps-delen havner paa hver sin motkonto slik de skal.
//
// Gavekortdelen er ikke penger inn. Den staar med belop_ore = 0 og
// gavekort_ore = det kortet dekket, akkurat som en betaling fra nettsida —
// og trekkes fra kortet med Booking::trekkGavekort(), som er den samme
// koden nettsida har brukt hele tiden.
if ($handling === 'delt') {
    // Uten «payments.order_id» henger ikke delene sammen. Salget ville blitt
    // registrert, men en annullering hadde bare strøket den forste raden og
    // latt resten staa som betalt. Da er det bedre aa ikke ta imot det.
    if (!DB::harKolonne('payments', 'order_id')) {
        Svar::feil('Delt betaling krever en oppdatering av databasen. '
                 . 'Kjør «Kjør oppdateringer» under Vedlikehold først.', 503);
    }

    $slag = (string) ($kropp['slag'] ?? 'produkt');
    if (!isset(SLAG[$slag])) {
        Svar::feil('Velg om det er kurs, medlemskap eller produkt.');
    }

    $deler = $kropp['deler'] ?? [];
    if (!is_array($deler) || $deler === []) {
        Svar::feil('Legg inn minst én del av oppgjøret.');
    }
    if (count($deler) > 5) {
        Svar::feil('Et salg kan deles i høyst fem.');
    }

    // Delene leses ut for noe skrives. Gaar én av dem ikke opp, skal
    // ingenting ligge igjen i basen.
    $rene = [];
    $sum = 0;
    $gavekortOre = 0;
    foreach ($deler as $d) {
        if (!is_array($d)) {
            Svar::feil('En av delene ser ikke riktig ut.');
        }
        $m = (string) ($d['maate'] ?? '');
        if (!in_array($m, DELMAATER, true)) {
            Svar::feil('«' . $m . '» er ikke en betalingsmåte vi kan føre.');
        }
        $ore = les_belop($d['belop'] ?? '');
        if ($ore === null) {
            Svar::feil('Skriv inn et beløp på hver del du bruker.');
        }
        if ($ore <= 0) {
            // En tom del er ikke en feil — skjermen har tre felter, og de
            // fleste salg bruker to. Den hoppes bare over.
            continue;
        }
        if ($m === 'Gavekort') {
            if ($gavekortOre > 0) {
                Svar::feil('Bare ett gavekort per salg.');
            }
            $gavekortOre = $ore;
        }
        $sum += $ore;
        $rene[] = ['maate' => $m, 'ore' => $ore];
    }

    if ($rene === []) {
        Svar::feil('Skriv inn et beløp på minst én del.');
    }
    if ($sum > 10000000) {
        Svar::feil('Beløpet må være under 100 000 kroner.');
    }

    // Gavekortet slaas opp for noe skrives, slik at et salg aldri blir
    // registrert med et kort som ikke finnes eller ikke har daekning.
    $kort = null;
    if ($gavekortOre > 0) {
        $kode = trim((string) ($kropp['gavekortKode'] ?? ''));
        if ($kode === '') {
            Svar::feil('Skriv inn koden på gavekortet.');
        }
        $kort = Booking::finnGavekort($kode);
        if ($kort === null) {
            Svar::feil('Fant ikke gavekortet, eller det er brukt opp.');
        }
        if ($gavekortOre > $kort['saldo_ore']) {
            Svar::feil('Gavekortet har bare ' . Booking::kroner($kort['saldo_ore'])
                     . ' igjen. Sett ned beløpet på gavekortdelen.');
        }
    }

    $kunde   = mb_substr(trim((string) ($kropp['kunde'] ?? '')), 0, 191);
    $ordrenr = 'D-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $formal  = SLAG[$slag]['formal'];
    $tittel  = SLAG[$slag]['tittel'];
    $adminId = (int) ($admin['id'] ?? 0);
    // Maatene slik de sto, i den rekkefolgen de ble tastet. «betalt_maate» er
    // VARCHAR(32), saa den kappes — den fulle sannheten staar paa radene.
    $maateTekst = mb_substr(implode(' + ', array_column($rene, 'maate')), 0, 32);

    $laget = DB::iTransaksjon(
        static function () use ($rene, $sum, $kunde, $ordrenr, $formal, $tittel,
                                $kort, $adminId, $maateTekst): array {
            // Ordren opprettes med hovedraden som mangler, og fylles inn
            // etterpaa: radene trenger ordrens id, og ordren trenger den
            // forste raden. Én av dem maa komme forst.
            $ordreId = DB::settInn('orders', [
                'ordrenr'      => $ordrenr,
                'kunde_navn'   => $kunde !== '' ? $kunde : 'Salg over disk',
                'sum_ore'      => $sum,
                'status'       => 'hentet',
                'betalt_maate' => $maateTekst,
                'payment_id'   => null,
            ]);

            $ider = [];
            $pengerad = null;
            $gavekortRad = null;
            foreach ($rene as $i => $r) {
                $erGavekort = $r['maate'] === 'Gavekort';
                $felt = [
                    // Referansen er paakrevd og unik. Et disksalg har ingen
                    // fra Vipps, saa den lages her — med delnummeret bakerst,
                    // for at fem deler paa samme ordre ikke skal kollidere.
                    'vipps_reference' => 'KASSE-' . $ordrenr . '-' . ($i + 1),
                    'type'            => 'manuell',
                    'formal'          => $formal,
                    // Gavekortet er ingen innbetaling. Beloepet staar i
                    // gavekort_ore, og penger inn er null.
                    'belop_ore'       => $erGavekort ? 0 : $r['ore'],
                    'status'          => 'betalt',
                    'idempotency_key' => Vipps::uuid(),
                ];
                if (DB::harKolonne('payments', 'order_id')) {
                    $felt['order_id'] = $ordreId;
                }
                if (DB::harKolonne('payments', 'maate')) {
                    $felt['maate'] = $r['maate'];
                }
                if (DB::harKolonne('payments', 'registrert_av') && $adminId > 0) {
                    $felt['registrert_av'] = $adminId;
                }
                if ($erGavekort && DB::harKolonne('payments', 'gavekort_id')) {
                    $felt['gavekort_id']  = $kort['id'];
                    $felt['gavekort_ore'] = $r['ore'];
                }
                $id = DB::settInn('payments', $felt);
                $ider[] = $id;
                if ($erGavekort) {
                    $gavekortRad = $id;
                } elseif ($pengerad === null) {
                    $pengerad = $id;
                }
            }

            // Hovedraden er den forste pengedelen om det finnes en. Ellers
            // gavekortraden. Alt som leser «orders.payment_id» — annullering,
            // kvittering, refusjon — foelger den, og da skal den peke paa en
            // rad med penger i, ikke paa gavekortraden som staar med null.
            DB::oppdater('orders', ['payment_id' => $pengerad ?? $ider[0]], ['id' => $ordreId]);

            DB::settInn('order_lines', [
                'order_id'   => $ordreId,
                'product_id' => null,
                'tittel'     => $tittel,
                'antall'     => 1,
                'pris_ore'   => $sum,
            ]);

            return ['ordreId' => $ordreId, 'gavekortRad' => $gavekortRad];
        }
    );

    // Trekket skjer etter at salget staar. Gaar det galt her, er salget
    // registrert og kortet urort — det er den veien som kan rettes for haand.
    if ($laget['gavekortRad'] !== null) {
        Booking::trekkGavekort((int) $laget['gavekortRad']);
    }

    revider('kassesalg_delt', 'ordre', (int) $laget['ordreId'], [
        'ordrenr'  => $ordrenr,
        'sum'      => $sum,
        'slag'     => $slag,
        'deler'    => $rene,
        'gavekort' => $kort['kode'] ?? null,
    ]);

    $biter = array_map(
        static fn(array $r): string => mb_strtolower($r['maate']) . ' ' . Booking::kroner($r['ore']),
        $rene
    );

    Svar::ok([
        'ordrenr' => $ordrenr,
        'sum'     => Booking::kroner($sum),
        'beskjed' => $tittel . ' · ' . Booking::kroner($sum) . ' · '
                   . implode(', ', $biter) . '. Det er med i regnskapet.',
        'gavekortIgjen' => $kort === null ? null
            : Booking::kroner(max(0, $kort['saldo_ore'] - $gavekortOre)),
    ]);
}

// ────────────────────────────────────────────────────── utsted gavekort
//
// Eieren: «jeg vil ha to typer gavekort, et som er ting vi gir ut som ikke
// skal skatteberegnes og et som faktisk er kjopt av oss, da maa det vaere
// online og det maa vaere nummerert.»
//
// Begge deler er det samme kortet: samme nummererte kode, samme saldo, samme
// uttakslogg, og begge virker i kassa og paa nettsida. Forskjellen er hva som
// skjer i regnskapet.
//
//   Solgt   Noen betalte for kortet. Pengene er inne, men tjenesten er ikke
//           levert. Det blir en betaling med formal «gavekort», som
//           dagsoppgjoret forer som gjeld — ikke inntekt. Inntekten kommer
//           den dagen kortet loeses inn.
//
//   Gitt    Verkstedet ga det bort. Ingen penger kom inn, og det opprettes
//           ingen betaling. Da finnes det heller ingen gjeld aa foere.
//           Innloesningen inntektsfoeres mot en kostnadskonto i stedet.
//
// Kortet aktiveres med det samme og faar koden sin. Det er forskjellen fra et
// kjop paa nettsida, der koden holdes tilbake til Vipps har bekreftet — her
// staar kunden foran disken, og pengene er alt talt opp.
if ($handling === 'gavekort') {
    if (!DB::harKolonne('gift_cards', 'opprinnelse')) {
        Svar::feil('Gavekort i kassa krever en oppdatering av databasen. '
                 . 'Kjør «Kjør oppdateringer» under Vedlikehold først.', 503);
    }

    $opprinnelse = (string) ($kropp['opprinnelse'] ?? 'kjopt');
    if (!in_array($opprinnelse, ['kjopt', 'gitt'], true)) {
        Svar::feil('Velg om kortet er solgt eller gitt bort.');
    }

    $ore = les_belop($kropp['belop'] ?? '');
    if ($ore === null || $ore <= 0) {
        Svar::feil('Skriv inn beløpet kortet skal lyde på.');
    }
    // Samme grenser som paa nettsida, saa et kort utstedt over disk ikke kan
    // vaere noe nettsida ville avvist.
    if ($ore < 10000 || $ore > 2000000) {
        Svar::feil('Velg et beløp mellom 100 og 20 000 kroner.');
    }

    $maate = (string) ($kropp['maate'] ?? MAATER[0]);
    if ($opprinnelse === 'kjopt' && !in_array($maate, MAATER, true)) {
        Svar::feil('Velg om kortet ble betalt kontant eller med Vipps.');
    }

    $mNavn  = mb_substr(trim((string) ($kropp['mottakerNavn'] ?? '')), 0, 191);
    $mEpost = mb_substr(trim((string) ($kropp['mottakerEpost'] ?? '')), 0, 191);
    $hilsen = mb_substr(trim((string) ($kropp['hilsen'] ?? '')), 0, 500);
    if ($mEpost !== '' && !filter_var($mEpost, FILTER_VALIDATE_EMAIL)) {
        Svar::feil('Adressen til mottakeren ser ikke riktig ut.');
    }

    $adminId = (int) ($admin['id'] ?? 0);
    $ordrenr = 'G-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

    $kortId = DB::iTransaksjon(
        static function () use ($ore, $opprinnelse, $maate, $mNavn, $mEpost,
                                $hilsen, $adminId, $ordrenr): int {
            $betalingId = null;

            // Bare det som er solgt gir en betaling. Et kort vi gir bort har
            // ingen penger bak seg, og en betalingsrad paa null kroner ville
            // sagt at det kom inn noe som aldri kom.
            if ($opprinnelse === 'kjopt') {
                $felt = [
                    'vipps_reference' => 'KASSE-' . $ordrenr,
                    'type'            => 'manuell',
                    'formal'          => 'gavekort',
                    'belop_ore'       => $ore,
                    'status'          => 'betalt',
                    'idempotency_key' => Vipps::uuid(),
                ];
                if (DB::harKolonne('payments', 'maate')) {
                    $felt['maate'] = $maate;
                }
                if (DB::harKolonne('payments', 'registrert_av') && $adminId > 0) {
                    $felt['registrert_av'] = $adminId;
                }
                $betalingId = DB::settInn('payments', $felt);
            }

            // Kortet legges inn som ubetalt og aktiveres rett etterpaa.
            // Booking::aktiverGavekort() er den som lager koden og sender
            // e-posten, og den krever at kortet staar som ubetalt naar den
            // kalles — samme vei som et kjop paa nettsida gaar.
            return DB::settInn('gift_cards', [
                'kode'            => 'UBETALT-' . strtoupper(bin2hex(random_bytes(6))),
                'opprinnelig_ore' => $ore,
                'saldo_ore'       => 0,
                'gyldig_til'      => gmdate('Y-m-d', strtotime('+3 years')),
                // Staar det ingen kjoper, er det verkstedet som staar bak.
                // Hilsenen i e-posten signeres med dette navnet.
                'kjoper_navn'     => $mNavn !== '' ? $mNavn : 'Lissom Keramikk',
                'kjoper_epost'    => null,
                'mottaker_epost'  => $mEpost !== '' ? $mEpost : null,
                'hilsen'          => $hilsen !== '' ? $hilsen : null,
                'payment_id'      => $betalingId,
                'status'          => 'ubetalt',
                'opprinnelse'     => $opprinnelse,
                'utstedt_av'      => $adminId > 0 ? $adminId : null,
            ]);
        }
    );

    // E-post bare naar det er en adresse. Et kort som rekkes over disken
    // har koden paa skjermen, og skal ikke utloese et krav til admin om
    // aa sende noe for haand.
    Booking::aktiverGavekort($kortId, $mEpost !== '');

    $kode = (string) DB::verdi('SELECT kode FROM gift_cards WHERE id = :i', ['i' => $kortId]);

    revider('gavekort_utstedt', 'gift_card', $kortId, [
        'belop'       => $ore,
        'opprinnelse' => $opprinnelse,
        'maate'       => $opprinnelse === 'kjopt' ? $maate : null,
    ]);

    Svar::ok([
        'kode'    => $kode,
        'belop'   => Booking::kroner($ore),
        'beskjed' => $opprinnelse === 'kjopt'
            ? 'Gavekort på ' . Booking::kroner($ore) . ' er solgt og betalt med '
              . mb_strtolower($maate) . '. Koden er ' . $kode . '.'
            : 'Gavekort på ' . Booking::kroner($ore) . ' er gitt bort. Koden er '
              . $kode . '. Det står som kostnad, ikke som salg.',
        'sendt'   => $mEpost !== '' ? $mEpost : null,
    ]);
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

$maate = (string) ($kropp['maate'] ?? KURVMAATER[0]);
if (!in_array($maate, KURVMAATER, true)) {
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
    // Ingen betalingsrad naar det ikke er betalt. Samme regel som paa salget
    // med fritt beloep — se «$ubetalt» der. Varene gaar likevel ut av
    // lageret: de staar ikke lenger i hylla.
    $betalingId = $maate === 'Ikke betalt' ? null : DB::settInn('payments', [
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

    // Betalingen peker tilbake paa ordren.
    //
    // Raden lages foer ordren — den maa finnes for ordren kan peke paa
    // den — saa koblingen settes her. «payments.order_id» kom med
    // migrasjon 134, og skal bety det samme paa alle salg: alle radene
    // som hoerer til ordren. Sto den bare paa de delte, ville et vanlig
    // kassesalg vaert usynlig for alt som leser den.
    if ($betalingId !== null && DB::harKolonne('payments', 'order_id')) {
        DB::oppdater('payments', ['order_id' => $id], ['id' => $betalingId]);
    }

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
    'beskjed' => $maate === 'Ikke betalt'
        ? 'Salget er registrert: ' . Booking::kroner($sum)
          . ', ikke betalt. Det står i lista til pengene er inne.'
        : 'Salget er registrert: ' . Booking::kroner($sum) . ' med ' . mb_strtolower($maate) . '.',
]);
