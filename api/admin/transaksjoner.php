<?php
/**
 * Transaksjonene som CSV, til regnskapet.
 *
 *   GET ?maaned=2026-08     én maaned
 *   GET ?fra=2026-01-01&til=2026-12-31
 *
 * «Eksporter til regnskap» og «Last ned transaksjoner» aapnet for en boks
 * som sa «SAF-T, CSV eller PDF» og deretter lukket seg igjen. Ingen fil kom
 * ut. Her kommer den: én linje per betaling, med referansen fra Vipps saa
 * radene lar seg kjenne igjen mot oppgjoret.
 *
 * Semikolon som skilletegn og BOM foran — det er det norsk Excel forventer.
 * Komma gir alt i én kolonne, og uten BOM blir «ø» til «Ã¸».
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
// Regnskapsfoereren slipper inn her. Eieren, 1. september: «jeg oensker aa
// lage en bruker log in til min regnskapsoerer». Hun ser OEkonomi og
// betalingene; resten av admin er stengt for rollen.
krev_regnskap();

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$naa  = new DateTimeImmutable('now', $oslo);

// Perioden. Uten noe oppgitt: maaneden vi staar i.
$maaned = Foresporsel::tekst('maaned');
if (preg_match('/^\d{4}-\d{2}$/', $maaned) === 1) {
    $fra = new DateTimeImmutable($maaned . '-01 00:00:00', $oslo);
    $til = $fra->modify('+1 month');
    $navn = $maaned;
} else {
    $f = Foresporsel::tekst('fra');
    $t = Foresporsel::tekst('til');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) === 1) {
        $fra = new DateTimeImmutable($f . ' 00:00:00', $oslo);
        $til = (new DateTimeImmutable($t . ' 00:00:00', $oslo))->modify('+1 day');
        $navn = $f . '_' . $t;
    } else {
        $fra = $naa->modify('first day of this month')->setTime(0, 0);
        $til = $fra->modify('+1 month');
        $navn = $fra->format('Y-m');
    }
}

if ($til <= $fra) {
    Svar::feil('Til-datoen må være etter fra-datoen.');
}

// Betalingsmaaten staar paa ordren, ikke paa betalingen: et salg over disk
// gjores opp med kontant eller Vipps, og verkstedet velger hvilken.
// Uttrekket skrev «Vipps» paa hver eneste linje — ogsaa paa kontantsalg — og
// da stemmer ikke bankinnskuddet med bilaget hos regnskapsforeren.
$harOrdre   = DB::harTabell('orders') && DB::harKolonne('orders', 'betalt_maate');
$ordreFelt  = $harOrdre ? ', o.betalt_maate, o.ordrenr, o.kunde_navn' : '';
$ordreJoin  = $harOrdre ? ' LEFT JOIN orders o ON o.payment_id = p.id' : '';

$rader = DB::alle(
    "SELECT p.id, p.created_at, p.vipps_reference, p.vipps_psp_ref, p.formal, p.type,
            p.status, p.belop_ore, p.refundert_ore, m.navn AS medlemsnavn" . $ordreFelt . "
       FROM payments p
       LEFT JOIN members m ON m.id = p.member_id" . $ordreJoin . "
      WHERE p.created_at >= :fra AND p.created_at < :til
      ORDER BY p.created_at, p.id",
    [
        'fra' => $fra->setTimezone($utc)->format('Y-m-d H:i:s'),
        'til' => $til->setTimezone($utc)->format('Y-m-d H:i:s'),
    ]
);

$FORMAL = [
    'booking'    => 'Kurs og events',
    'dropin'     => 'Drop-in',
    'gavekort'   => 'Gavekort',
    'ordre'      => 'Butikk',
    'medlemskap' => 'Medlemskap',
];

$STATUS = [
    'opprettet'        => 'Opprettet',
    'venter'           => 'Venter',
    'autorisert'       => 'Autorisert',
    'betalt'           => 'Betalt',
    'avbrutt'          => 'Avbrutt',
    'feilet'           => 'Feilet',
    'refundert'        => 'Refundert',
    'delvis_refundert' => 'Delvis refundert',
];

// Kroner med komma, slik norsk Excel leser tall.
$kr = static fn(int $ore): string => number_format($ore / 100, 2, ',', '');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="lissom-transaksjoner-' . $navn . '.csv"');
header('Cache-Control: no-store');

$ut = fopen('php://output', 'wb');
fwrite($ut, "\xEF\xBB\xBF");   // BOM, ellers viser Excel ae/oe/aa feil

fputcsv($ut, [
    'Dato', 'Tid', 'Referanse', 'Vipps-ID', 'Hva', 'Betalingsmåte',
    'Status', 'Beløp', 'Refundert', 'Netto', 'Kunde',
], ';', '"', '');

// Hva som skal staa i «Betalingsmaate».
//
// Staar det en maate paa ordren, er det den som gjelder — den er valgt av
// den som tok imot pengene. Ellers er det Vipps, som er den eneste veien
// betalinger kommer inn av seg selv.
$maateFor = static function (array $r): string {
    $paaOrdre = trim((string) ($r['betalt_maate'] ?? ''));
    if ($paaOrdre !== '') {
        return $paaOrdre;
    }
    return $r['type'] === 'recurring_charge' ? 'Vipps månedstrekk' : 'Vipps';
};

$sumBrutto = 0;
$sumRefundert = 0;

foreach ($rader as $r) {
    $tid = (new DateTimeImmutable((string) $r['created_at'], $utc))->setTimezone($oslo);
    $brutto = (int) $r['belop_ore'];
    $refund = (int) $r['refundert_ore'];

    // Bare det som faktisk er penger inn teller i summen nederst. En avbrutt
    // betaling skal staa i lista — den forklarer et hull — men ikke summeres.
    if (in_array($r['status'], ['betalt', 'delvis_refundert', 'refundert'], true)) {
        $sumBrutto += $brutto;
        $sumRefundert += $refund;
    }

    fputcsv($ut, [
        $tid->format('d.m.Y'),
        $tid->format('H:i'),
        (string) $r['vipps_reference'],
        (string) ($r['vipps_psp_ref'] ?? ''),
        $FORMAL[$r['formal']] ?? (string) $r['formal'],
        $maateFor($r),
        $STATUS[$r['status']] ?? (string) $r['status'],
        $kr($brutto),
        $refund > 0 ? $kr($refund) : '',
        $kr($brutto - $refund),
        // Et salg over disk har ingen medlemskonto. Da staar navnet den som
        // tok imot pengene skrev inn — ellers sto kolonnen tom.
        (string) ($r['medlemsnavn'] ?: ($r['kunde_navn'] ?? '')),
    ], ';', '"', '');
}

fputcsv($ut, [], ';', '"', '');
fputcsv($ut, [
    'Sum', '', '', '', '', '', count($rader) . ' rader',
    $kr($sumBrutto), $sumRefundert > 0 ? $kr($sumRefundert) : '', $kr($sumBrutto - $sumRefundert), '',
], ';', '"', '');

fclose($ut);
