<?php
/**
 * Kjopshistorikken min.
 *
 * Min side viste fire oppdiktede kjop med kvitteringsnummer til alle som
 * logget inn — «Leire, stengods graa, kr. 290,-, LIS-10488». Kunden hadde
 * ingen maate aa vite at det ikke var deres egne.
 *
 * Alt her bygges paa betalingene: hver betaling vet hva den gjaldt, og
 * detaljene hentes fra det den peker paa. Det som aldri ble betalt staar
 * ikke i historikken.
 *
 * Kjop gjort for man logget inn tas ogsaa med, naar e-posten er den samme —
 * ellers ville en kunde som booket som gjest og ble medlem etterpaa sett en
 * tom side.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');
$medlem = krev_medlem();

const MAANEDER = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                  'juli', 'august', 'september', 'oktober', 'november', 'desember'];

/** UTC-tidspunkt til «22. august 14:12», i norsk tid. */
function norsk_tidspunkt(string $utc): string
{
    $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Oslo'));

    return sprintf('%d. %s %s', (int) $d->format('j'), MAANEDER[(int) $d->format('n') - 1], $d->format('H:i'));
}

/** UTC-tidspunkt til «august 2026». */
function maaned(string $utc): string
{
    $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Oslo'));

    return MAANEDER[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
}

$mid   = (int) $medlem['id'];
$epost = (string) ($medlem['epost'] ?? '');

/** Ble kvitteringen faktisk sendt? Vi paastaar det ikke uten aa vite. */
$kvitteringen = static function (string $type, ?int $id): string {
    if ($id === null) {
        return 'Ikke sendt';
    }
    $rad = DB::en(
        'SELECT status FROM notifications
          WHERE ref_type = :t AND ref_id = :i AND kanal = :k
          ORDER BY id DESC LIMIT 1',
        ['t' => $type, 'i' => $id, 'k' => 'epost']
    );
    if ($rad === null) {
        return 'Ikke sendt';
    }
    return match ((string) $rad['status']) {
        'sendt'  => 'Sendt på e-post',
        'feilet' => 'Kom ikke fram — ta kontakt',
        default  => 'På vei',
    };
};

$linjer = [];

// ---------------------------------------------------------------- ordrer
$ordrer = DB::alle(
    "SELECT o.id, o.ordrenr, o.sum_ore, o.status, o.created_at,
            p.id AS payment_id, p.vipps_reference, p.status AS betalingsstatus,
            p.refundert_ore, p.created_at AS betalt_at
       FROM orders o
       JOIN payments p ON p.id = o.payment_id
      WHERE (o.member_id = :m OR (:e <> '' AND o.kunde_epost = :e2))
        AND p.status IN ('betalt','refundert','delvis_refundert')
      ORDER BY p.created_at DESC",
    ['m' => $mid, 'e' => $epost, 'e2' => $epost]
);

foreach ($ordrer as $o) {
    $varer = DB::alle(
        'SELECT tittel, antall FROM order_lines WHERE order_id = :o ORDER BY id',
        ['o' => (int) $o['id']]
    );
    $navn = array_map(
        static fn($v) => (int) $v['antall'] > 1 ? $v['antall'] . ' × ' . $v['tittel'] : (string) $v['tittel'],
        $varer
    );

    $linjer[] = [
        'navn'      => count($navn) === 1 ? $navn[0] : 'Bestilling i butikken',
        'detalj'    => count($navn) === 1
                        ? 'Butikken'
                        : implode(', ', array_slice($navn, 0, 3)) . (count($navn) > 3 ? ' m.m.' : ''),
        'sumOre'    => (int) $o['sum_ore'],
        'tidUtc'    => (string) $o['betalt_at'],
        'ref'       => (string) $o['ordrenr'],
        'betaltMed' => 'Vipps',
        'kvittering'=> $kvitteringen('ordre', (int) $o['id']),
        'status'    => (string) $o['betalingsstatus'],
        'refOre'    => (int) $o['refundert_ore'],
    ];
}

// ------------------------------------------------------------- bookinger
$bookinger = DB::alle(
    "SELECT b.id, b.belop_ore, b.antall, c.tittel, cs.start_tid,
            p.vipps_reference, p.status AS betalingsstatus, p.refundert_ore,
            p.created_at AS betalt_at
       FROM bookings b
       JOIN courses c ON c.id = b.course_id
  LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
       JOIN payments p ON p.id = b.payment_id
      WHERE (b.member_id = :m OR (:e <> '' AND b.gjest_epost = :e2))
        AND p.status IN ('betalt','refundert','delvis_refundert')
      ORDER BY p.created_at DESC",
    ['m' => $mid, 'e' => $epost, 'e2' => $epost]
);

foreach ($bookinger as $b) {
    $linjer[] = [
        'navn'      => (string) $b['tittel'],
        'detalj'    => ($b['start_tid'] ? Booking::norskDato((string) $b['start_tid']) : 'Dato kommer')
                        . ((int) $b['antall'] > 1 ? ' · ' . $b['antall'] . ' plasser' : ''),
        'sumOre'    => (int) $b['belop_ore'],
        'tidUtc'    => (string) $b['betalt_at'],
        'ref'       => (string) $b['vipps_reference'],
        'betaltMed' => 'Vipps',
        'kvittering'=> $kvitteringen('booking', (int) $b['id']),
        'status'    => (string) $b['betalingsstatus'],
        'refOre'    => (int) $b['refundert_ore'],
    ];
}

// -------------------------------------------------------------- gavekort
//
// Gavekortet knyttes til kjoperen gjennom betalingen. Koden vises ikke her:
// den staar i e-posten, og et gavekort er en verdi som ikke skal ligge
// framme paa en skjerm noen andre kan se over skulderen.
$kort = DB::alle(
    "SELECT g.id, g.opprinnelig_ore, g.gyldig_til, g.mottaker_epost,
            p.vipps_reference, p.status AS betalingsstatus, p.refundert_ore,
            p.created_at AS betalt_at
       FROM gift_cards g
       JOIN payments p ON p.id = g.payment_id
      WHERE (p.member_id = :m OR (:e <> '' AND g.kjoper_epost = :e2))
        AND p.status IN ('betalt','refundert','delvis_refundert')
      ORDER BY p.created_at DESC",
    ['m' => $mid, 'e' => $epost, 'e2' => $epost]
);

foreach ($kort as $g) {
    $linjer[] = [
        'navn'      => 'Gavekort',
        'detalj'    => ($g['mottaker_epost'] ? 'Sendt til ' . $g['mottaker_epost'] : 'Sendt til deg')
                        . ' · gyldig til ' . date('d.m.Y', strtotime((string) $g['gyldig_til'])),
        'sumOre'    => (int) $g['opprinnelig_ore'],
        'tidUtc'    => (string) $g['betalt_at'],
        'ref'       => (string) $g['vipps_reference'],
        'betaltMed' => 'Vipps',
        'kvittering'=> $kvitteringen('gavekort', (int) $g['id']),
        'status'    => (string) $g['betalingsstatus'],
        'refOre'    => (int) $g['refundert_ore'],
    ];
}

// ------------------------------------------------------------ medlemskap
$trekk = DB::alle(
    "SELECT id, belop_ore, vipps_reference, status, refundert_ore, created_at
       FROM payments
      WHERE member_id = :m AND formal = 'medlemskap'
        AND status IN ('betalt','refundert','delvis_refundert')
      ORDER BY created_at DESC",
    ['m' => $mid]
);

foreach ($trekk as $t) {
    $linjer[] = [
        'navn'      => 'Medlemskap',
        'detalj'    => 'Månedstrekk · ' . maaned((string) $t['created_at']),
        'sumOre'    => (int) $t['belop_ore'],
        'tidUtc'    => (string) $t['created_at'],
        'ref'       => (string) $t['vipps_reference'],
        'betaltMed' => 'Vipps',
        'kvittering'=> $kvitteringen('medlemskap', (int) $t['id']),
        'status'    => (string) $t['status'],
        'refOre'    => (int) $t['refundert_ore'],
    ];
}

// Alt sammen, nyeste forst.
usort($linjer, static fn($a, $b) => strcmp($b['tidUtc'], $a['tidUtc']));

$ut = array_map(static function (array $l): array {
    $refundert = $l['refOre'] > 0;

    return [
        'navn'       => $l['navn'],
        'detalj'     => $l['detalj'] . ($refundert ? ' · refundert' : ''),
        'sum'        => Booking::kroner($l['sumOre']),
        'tid'        => norsk_tidspunkt($l['tidUtc']),
        'ref'        => $l['ref'],
        'betaltMed'  => $l['betaltMed'],
        'kvittering' => $l['kvittering'],
        'refundert'  => $refundert ? Booking::kroner($l['refOre']) . ' refundert' : null,
    ];
}, $linjer);

Svar::json(['kjop' => $ut], 200);
