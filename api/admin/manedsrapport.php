<?php
/**
 * Maanedsrapporten paa Penger (admin-ny, 8. oktober 2026).
 *
 * Flyttet fra gammel admin («Månedsrapport (PDF)», lagRapport). Omsetningen
 * med og uten mva per kilde for én maaned, fra Omsetning::perKilde() — samme
 * tall som Penger og Oversikt viser.
 *
 *   GET ?maaned=2026-09     den maaneden
 *   GET                     maaneden vi staar i
 *
 * Grensene regnes i norsk tid og gjores om til UTC, som i okonomi.php.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
krev_admin();

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');

$maaned = Foresporsel::tekst('maaned');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $maaned) === 1) {
    $fra = new DateTimeImmutable($maaned . '-01 00:00:00', $oslo);
} else {
    $fra = (new DateTimeImmutable('now', $oslo))->modify('first day of this month')->setTime(0, 0);
}
$til = $fra->modify('+1 month');

$MAANEDER = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
             'juli', 'august', 'september', 'oktober', 'november', 'desember'];

$k = Omsetning::perKilde(
    $fra->setTimezone($utc)->format('Y-m-d H:i:s'),
    $til->setTimezone($utc)->format('Y-m-d H:i:s')
);

$eks    = (int) $k['sumEksOre'];
$brutto = (int) ($k['sumBruttoOre'] ?? $eks);

Svar::ok([
    'maaned'       => $fra->format('Y-m'),
    'navn'         => ucfirst($MAANEDER[(int) $fra->format('n') - 1]) . ' ' . $fra->format('Y'),
    'sumEksOre'    => $eks,
    'sumBruttoOre' => $brutto,
    'mvaOre'       => $brutto - $eks,
    'kilder'       => array_map(static fn(array $r): array => [
        'navn'      => $r['navn'],
        'eksOre'    => (int) $r['eksOre'],
        'bruttoOre' => (int) ($r['bruttoOre'] ?? $r['eksOre']),
        'mvaOre'    => (int) ($r['bruttoOre'] ?? $r['eksOre']) - (int) $r['eksOre'],
        'antall'    => count($r['salg']),
    ], $k['kilder']),
]);
