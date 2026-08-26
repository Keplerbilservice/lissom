<?php
/**
 * Hva som er klart til henting.
 *
 * Åpent endepunkt — dette er informasjon kundene skal kunne se uten å logge
 * inn, og uten å ringe. Det var hele poenget: alle må kunne gå inn og sjekke
 * selv om tingene deres er ferdige.
 *
 * Ingen navn og ingen antall. Bare kurset, datoen og at det er klart.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

if (!DB::harKolonne('course_sessions', 'hentemelding_at')) {
    Svar::json(['ok' => true, 'meldinger' => [], 'uker' => 3]);
}

const UKER = 3;

$rader = DB::alle(
    "SELECT cs.start_tid, cs.hentemelding_at, c.tittel
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
      WHERE cs.hentemelding_at IS NOT NULL
        AND cs.hentemelding_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL :u WEEK)
   ORDER BY cs.hentemelding_at DESC",
    ['u' => UKER]
);

$ut = [];
foreach ($rader as $r) {
    // Hvor lenge det er igjen aa hente. En dato alene sier lite naar du ikke
    // husker naar meldingen kom.
    $frist = (new DateTimeImmutable((string) $r['hentemelding_at'], new DateTimeZone('UTC')))
        ->modify('+' . UKER . ' weeks');
    $dager = (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->diff($frist)->format('%r%a');

    $ut[] = [
        'kurs'   => $r['tittel'],
        'dato'   => Booking::norskDato((string) $r['start_tid']),
        'meldt'  => Booking::norskDato((string) $r['hentemelding_at']),
        'igjen'  => $dager <= 0 ? 'Siste frist er ute'
                  : ($dager === 1 ? 'Én dag igjen' : $dager . ' dager igjen'),
        'snart'  => $dager <= 5,
    ];
}

Svar::json(['ok' => true, 'meldinger' => $ut, 'uker' => UKER]);
