<?php
/**
 * Dagsoppgjor til regnskapet.
 *
 *   GET ?maaned=2026-08          alle dagene i maaneden
 *   GET ?dato=2026-08-24         én dag
 *   GET ?maaned=2026-08&csv=ja   samme, som fil
 *
 * Regnskapsforeren ba om ett dagsoppgjor per dag som bilag, framfor at hvert
 * enkelt salg opprettes som faktura. Det er det denne lager: én rad per dag
 * per inntektstype, med konto og mva-kode fra oppsettet.
 *
 * Kontoene staar i innstillinger og ikke her, fordi det er regnskapsforeren
 * som eier dem. Mangler en konto, staar linja der likevel og sier at den
 * mangler — et bilag med en gjettet konto er verre enn et som sier fra.
 *
 * Dagen er norsk dag. Betalingene ligger i UTC, saa en betaling klokka 00.30
 * den forste hadde havnet paa feil dag uten omregningen.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

// Kontoene lagres her og ikke sammen med e-postoppsettet: de hoerer til
// regnskapet, og en skjerm som lagrer to ting den ene ikke eier, blir fort
// den som toemmer den andre.
const REGNSKAPSFELTER = [
    'regnskap_konto_kurs', 'regnskap_mva_kurs',
    'regnskap_konto_medlemskap', 'regnskap_mva_medlemskap',
    'regnskap_konto_butikk', 'regnskap_mva_butikk',
    'regnskap_konto_gavekort', 'regnskap_mva_gavekort',
    'regnskap_motkonto_vipps', 'regnskap_motkonto_kontant',
    'regnskap_motkonto_faktura',
];

if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();
    if (!DB::harTabell('innstillinger')) {
        Svar::feil('Migrasjon 036 er ikke kjørt. Kjør vedlikehold under Oversikt først.');
    }

    // Bare det som faktisk staar i forespoerselen skrives. Et felt som er med
    // og tomt skal toemmes — det er slik man fjerner en konto man ikke vil ha.
    $kropp  = Foresporsel::kropp();
    $lagret = 0;
    foreach (REGNSKAPSFELTER as $f) {
        if (!array_key_exists($f, $kropp)) {
            continue;
        }
        // Kontonummer og mva-kode er tall. Vi tar imot tall, og ingenting annet.
        $v = preg_replace('/[^0-9]/', '', trim((string) Foresporsel::tekst($f)));
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            [$f, $v, (int) (Sesjon::medlem()['id'] ?? 0) ?: null]
        );
        $lagret++;
    }
    Config::glemBasen();
    revider('regnskapsoppsett_lagret', null, null, ['felter' => $lagret]);

    Svar::ok(['beskjed' => $lagret === 1
        ? 'Kontoen er lagret.'
        : $lagret . ' felter er lagret.']);
}

Foresporsel::krevMetode('GET');

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');

// ── Hvilken periode ────────────────────────────────────────────────────
$dato   = Foresporsel::tekst('dato');
$maaned = Foresporsel::tekst('maaned');

if ($dato !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dato)) {
    $fra = new DateTimeImmutable($dato . ' 00:00:00', $oslo);
    $til = $fra->modify('+1 day');
} else {
    if (!preg_match('/^\d{4}-\d{2}$/', $maaned)) {
        $maaned = (new DateTimeImmutable('now', $oslo))->format('Y-m');
    }
    $fra = new DateTimeImmutable($maaned . '-01 00:00:00', $oslo);
    $til = $fra->modify('+1 month');
}

$iUtc = static fn(DateTimeImmutable $d): string => $d->setTimezone($utc)->format('Y-m-d H:i:s');

// ── Oppsettet fra regnskapsforeren ─────────────────────────────────────
//
// «ordre» er butikksalg. «booking» er kurs og events. Kontoene er avklart
// med regnskapsfoereren 1. september, men staar likevel i basen og ikke her,
// fordi det er hun som eier dem.
//
// Drop-in staar ikke her. Tilbudet ble tatt ned med migrasjon 110 og 111, og
// eieren 1. september: «vi har ikke drop-inn ... aldri ha det med».
$OPPSETT = [
    'booking'    => ['navn' => 'Kurs og events', 'konto' => 'regnskap_konto_kurs',       'mva' => 'regnskap_mva_kurs'],
    'medlemskap' => ['navn' => 'Medlemskap',     'konto' => 'regnskap_konto_medlemskap', 'mva' => 'regnskap_mva_medlemskap'],
    'ordre'      => ['navn' => 'Varer i butikk', 'konto' => 'regnskap_konto_butikk',     'mva' => 'regnskap_mva_butikk'],
    'gavekort'   => ['navn' => 'Gavekort solgt (gjeld)', 'konto' => 'regnskap_konto_gavekort', 'mva' => 'regnskap_mva_gavekort'],
];

$MOTKONTO = [
    'Vipps'    => 'regnskap_motkonto_vipps',
    'Kontant'  => 'regnskap_motkonto_kontant',
    'Faktura'  => 'regnskap_motkonto_faktura',
    // Et gavekort som loeses inn er ingen innbetaling. Det trekker ned
    // gjelden fra den dagen kortet ble solgt — samme konto, andre vei.
    'Gavekort' => 'regnskap_konto_gavekort',
];

// ── Salgene ────────────────────────────────────────────────────────────
//
// Bare det som faktisk er gjort opp. Refusjoner trekkes fra samme dag som
// selve betalingen staar, slik at dagen summerer til det som ble igjen.
// Gavekortkolonnene kom med migrasjon 040. Uten dem er alt betalt med penger.
$gavekortFelt = DB::harKolonne('payments', 'gavekort_ore')
    ? 'p.gavekort_ore' : '0 AS gavekort_ore';

$rader = DB::alle(
    "SELECT p.id, p.formal, p.type, p.belop_ore, p.refundert_ore, p.created_at,
            {$gavekortFelt}, o.betalt_maate
       FROM payments p
  LEFT JOIN orders o ON o.payment_id = p.id
      WHERE p.status IN ('betalt', 'delvis_refundert')
        AND p.created_at >= :fra AND p.created_at < :til
   ORDER BY p.created_at",
    ['fra' => $iUtc($fra), 'til' => $iUtc($til)]
);

/**
 * Hvordan pengene kom inn.
 *
 * Et disksalg har maaten skrevet i klartekst paa ordren. Alt annet gikk
 * gjennom Vipps. Motkontoen er ikke den samme for de tre, saa de holdes fra
 * hverandre — ellers gaar ikke bilaget i balanse.
 */
$maate = static function (array $r): string {
    $m = (string) ($r['betalt_maate'] ?? '');
    if ($m === 'Kontant') {
        return 'Kontant';
    }
    if ($m === 'Faktura') {
        return 'Faktura';
    }
    // «Vipps i verkstedet» og en vanlig nettbetaling lander samme sted.
    return 'Vipps';
};

// ── Paameldinger lagt inn for haand ────────────────────────────────────
//
// De lager ingen betalingsrad: de er gjort opp i verkstedet, med kontanter,
// Vipps eller faktura, og pengene gikk aldri gjennom oss. Uten disse ville
// bilaget vaert ufullstendig — en kursdeltaker som betalte kontant i doera
// hadde ikke staatt noe sted i regnskapet.
//
// Bare betalte. «Betaler ved oppmoete» staar som reservert til den er gjort
// opp, og «Gratis» er null kroner og faller ut av seg selv.
$manuelle = DB::alle(
    "SELECT b.id, b.belop_ore, b.betalt_maate, b.created_at
       FROM bookings b
      WHERE b.payment_id IS NULL
        AND b.status = 'betalt'
        AND b.belop_ore > 0
        AND b.created_at >= :fra AND b.created_at < :til",
    ['fra' => $iUtc($fra), 'til' => $iUtc($til)]
);
foreach ($manuelle as $m) {
    $rader[] = [
        'id'            => 'b' . $m['id'],
        'formal'        => 'booking',
        'type'          => 'manuell',
        'belop_ore'     => $m['belop_ore'],
        'refundert_ore' => 0,
        'created_at'    => $m['created_at'],
        'betalt_maate'  => $m['betalt_maate'],
    ];
}

$dager = [];
foreach ($rader as $r) {
    $d = (new DateTimeImmutable((string) $r['created_at'], $utc))->setTimezone($oslo)->format('Y-m-d');

    // «belop_ore» er det kunden faktisk betalte. Ble en del av prisen dekket
    // av et gavekort, staar den delen for seg — og inntekten er summen av de
    // to. Uten det ville et kurs til 1 490 betalt med et gavekort paa 1 000
    // staatt som 490 i inntekt, og de tusen aldri blitt inntektsfoert i det
    // hele tatt.
    $penger   = (int) $r['belop_ore'] - (int) ($r['refundert_ore'] ?? 0);
    $gavekort = (int) ($r['gavekort_ore'] ?? 0);
    if ($penger === 0 && $gavekort === 0) {
        continue;
    }

    $dager[$d] = $dager[$d] ?? ['inntekt' => [], 'inn' => [], 'antall' => 0];
    $dager[$d]['antall']++;

    $f = (string) $r['formal'];
    // Salg av et gavekort er ikke inntekt. Det er gjeld til den som eier
    // kortet, og blir inntekt foerst den dagen det loeses inn — paa den
    // kontoen det da brukes til.
    $dager[$d]['inntekt'][$f] = ($dager[$d]['inntekt'][$f] ?? 0) + $penger + $gavekort;

    // Pengene inn, og gavekortet som ble brukt. Gavekortet er ingen
    // innbetaling: det trekker ned gjelden fra den dagen kortet ble solgt.
    $m = $maate($r);
    if ($penger !== 0) {
        $dager[$d]['inn'][$m] = ($dager[$d]['inn'][$m] ?? 0) + $penger;
    }
    if ($gavekort !== 0) {
        $dager[$d]['inn']['Gavekort'] = ($dager[$d]['inn']['Gavekort'] ?? 0) + $gavekort;
    }
}

krsort($dager);

$kr = static fn(int $ore): string => number_format($ore / 100, 2, ',', '');

$ut = [];
$manglerKonto = [];

foreach ($dager as $d => $v) {
    $linjer = [];
    $sum = 0;

    foreach ($v['inntekt'] as $formal => $ore) {
        $o = $OPPSETT[$formal] ?? ['navn' => $formal, 'konto' => '', 'mva' => ''];
        $konto = $o['konto'] !== '' ? trim((string) Config::hent($o['konto'], '')) : '';
        $mva   = $o['mva'] !== ''   ? trim((string) Config::hent($o['mva'], ''))   : '';

        if ($konto === '' && !in_array($o['navn'], $manglerKonto, true)) {
            $manglerKonto[] = $o['navn'];
        }

        $linjer[] = [
            'hva'      => $o['navn'],
            'konto'    => $konto,
            'mvakode'  => $mva,
            'belopOre' => $ore,
            'belop'    => $kr($ore),
            'mangler'  => $konto === '',
        ];
        $sum += $ore;
    }

    $inn = [];
    foreach ($v['inn'] as $m => $ore) {
        $konto = trim((string) Config::hent($MOTKONTO[$m] ?? '', ''));
        if ($konto === '' && !in_array('Motkonto ' . $m, $manglerKonto, true)) {
            $manglerKonto[] = 'Motkonto ' . $m;
        }
        $inn[] = [
            'maate'    => $m,
            'konto'    => $konto,
            'belopOre' => $ore,
            'belop'    => $kr($ore),
            'mangler'  => $konto === '',
        ];
    }

    $ut[] = [
        'dato'     => $d,
        'norsk'    => Booking::norskDatoKort($d . ' 12:00:00'),
        'bilagstekst' => 'Dagsoppgjør Lissom ' . $d,
        'antall'   => $v['antall'],
        'linjer'   => $linjer,
        'inn'      => $inn,
        'sumOre'   => $sum,
        'sum'      => $kr($sum),
        // Gaar bilaget opp? Debet og kredit skal vaere like store. Gjor de
        // ikke det, er det en feil i dataene og ikke i oppsettet.
        'balanse'  => $sum === array_sum(array_column($inn, 'belopOre')),
    ];
}

// ── Fil, hvis den bes om ───────────────────────────────────────────────
if (Foresporsel::tekst('csv') === 'ja') {
    $navn = $dato !== '' ? $dato : $fra->format('Y-m');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lissom-dagsoppgjor-' . $navn . '.csv"');
    header('Cache-Control: no-store');

    $f = fopen('php://output', 'wb');
    fwrite($f, "\xEF\xBB\xBF");
    // Ett beloepsfelt med fortegn, ikke to kolonner.
    //
    // Regnskapsfoereren, 1. september: «Det skal ikke vaere debet- og
    // kreditkolonner men man bruker fortegn i beloep (positivt beloep =
    // debet, negativt beloep = kredit). For oevrig ser det bra ut.»
    //
    // Inntekt er kredit og skrives negativt; pengene inn er debet og skrives
    // positivt. Et gavekort som loeses inn er ogsaa debet — det trekker ned
    // gjelden fra dagen kortet ble solgt.
    fputcsv($f, ['Dato', 'Bilagstekst', 'Konto', 'Mva-kode', 'Beløp', 'Beskrivelse'], ';', '"', '');

    foreach ($ut as $b) {
        foreach ($b['linjer'] as $l) {
            fputcsv($f, [$b['dato'], $b['bilagstekst'], $l['konto'] ?: 'MANGLER',
                         $l['mvakode'], $kr(-$l['belopOre']), $l['hva']], ';', '"', '');
        }
        foreach ($b['inn'] as $i) {
            fputcsv($f, [$b['dato'], $b['bilagstekst'], $i['konto'] ?: 'MANGLER',
                         '', $kr($i['belopOre']),
                         $i['maate'] === 'Gavekort' ? 'Gavekort innløst' : 'Innbetalt · ' . $i['maate']], ';', '"', '');
        }
    }
    if ($ut === []) {
        fputcsv($f, ['Ingen oppgjorte salg i perioden.'], ';', '"', '');
    }
    fclose($f);
    exit;
}

$oppsett = [];
foreach (REGNSKAPSFELTER as $f) {
    $oppsett[$f] = trim((string) Config::hent($f, ''));
}

Svar::json([
    'oppsett'      => $oppsett,
    'fra'          => $fra->format('Y-m-d'),
    'til'          => $til->modify('-1 day')->format('Y-m-d'),
    'bilag'        => $ut,
    'antallDager'  => count($ut),
    'manglerKonto' => $manglerKonto,
    // Medlemssalg staar med vilje utenfor: pengene gaar rett til medlemmets
    // eget Vippsnummer og er ikke verkstedets inntekt.
    'utenfor'      => 'Medlemmenes egne salg er ikke med — pengene går direkte til selgeren.',
]);
