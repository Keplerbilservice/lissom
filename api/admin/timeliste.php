<?php
/**
 * Timelisten: kursholdertimene som lønnes, per maaned.
 *
 *   GET ?periode=2026-09             liste og summer per kursholder
 *   GET ?periode=2026-09&format=csv  samme, som fil til Excel
 *
 * Eieren, 26. september 2026 (GO paa skissen): «en timeliste som jeg kan
 * eksportere til regnskapsfører». Regnskapsfoereren har innlogging og ser
 * Økonomi — derfor krev_regnskap (admin slipper ogsaa inn). Bare kursholdere
 * med «Lønn»; de med «Timer» faar tida lagt til verkstedtimene i stedet.
 * Ubekreftede timer staar med, merket, saa det synes hva som mangler.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_regnskap();
if (!Kursholder::timerKlar()) {
    Svar::json(['rader' => [], 'summer' => [], 'mangler' => true]);
}
Kursholder::lagForslag();

$periode = (string) ($_GET['periode'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
    $periode = (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m');
}
$fra = $periode . '-01';
$til = (new DateTimeImmutable($fra))->modify('+1 month')->format('Y-m-d');

$harBetaling = DB::harKolonne('kursholdere', 'betaling');
$rader = DB::alle(
    "SELECT t.dato, t.timer, t.hva, t.status, t.kilde, k.navn, k.timesats_ore
       FROM kursholder_timer t
       JOIN kursholdere k ON k.id = t.kursholder_id
      WHERE t.dato >= :f AND t.dato < :t" . ($harBetaling ? " AND k.betaling = 'lonn'" : '') . "
   ORDER BY k.navn, t.dato, t.id",
    ['f' => $fra, 't' => $til]
);

$kr = static fn(int $ore): string => 'kr ' . number_format($ore / 100, 0, ',', ' ');
$tall = static fn(float $t): string => number_format($t, 2, ',', '');
$ut = [];
$summer = [];
foreach ($rader as $r) {
    $timer = (float) $r['timer'];
    $sats = $r['timesats_ore'] !== null ? (int) $r['timesats_ore'] : null;
    $sumOre = $sats !== null ? (int) round($timer * $sats) : null;
    $ut[] = [
        'dato'   => (new DateTimeImmutable((string) $r['dato']))->format('d.m.Y'),
        'navn'   => (string) $r['navn'],
        'hva'    => (string) ($r['hva'] ?? ''),
        'timer'  => $timer,
        'status' => (string) $r['status'],
        'kilde'  => (string) $r['kilde'],
        'sats'   => $sats,
        'sumOre' => $sumOre,
    ];
    $n = (string) $r['navn'];
    $summer[$n] ??= ['navn' => $n, 'timer' => 0.0, 'sumOre' => 0, 'ubekreftet' => 0];
    $summer[$n]['timer'] += $timer;
    $summer[$n]['sumOre'] += $sumOre ?? 0;
    if ($r['status'] !== 'bekreftet') {
        $summer[$n]['ubekreftet']++;
    }
}

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="timeliste-' . $periode . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Dato', 'Kursholder', 'Kurs', 'Timer', 'Status', 'Kilde', 'Timesats', 'Sum'], ';');
    $kildeNavn = ['lengde' => 'Kursets lengde', 'stemplet' => 'Stemplet', 'manuell' => 'Ført i admin'];
    foreach ($ut as $r) {
        fputcsv($f, [
            $r['dato'], $r['navn'], $r['hva'], $tall($r['timer']),
            $r['status'] === 'bekreftet' ? 'Bekreftet' : 'Ikke bekreftet',
            $kildeNavn[$r['kilde']] ?? $r['kilde'],
            $r['sats'] !== null ? number_format($r['sats'] / 100, 2, ',', '') : '',
            $r['sumOre'] !== null ? number_format($r['sumOre'] / 100, 2, ',', '') : '',
        ], ';');
    }
    foreach ($summer as $s) {
        fputcsv($f, ['', 'Sum ' . $s['navn'], '', $tall($s['timer']), '', '', '', number_format($s['sumOre'] / 100, 2, ',', '')], ';');
    }
    fclose($f);
    exit;
}

Svar::json([
    'periode' => $periode,
    'rader'   => array_map(static fn(array $r): array => [
        'dato'   => $r['dato'],
        'navn'   => $r['navn'],
        'hva'    => $r['hva'],
        'timer'  => $tall($r['timer']),
        'bekreftet' => $r['status'] === 'bekreftet',
        'sum'    => $r['sumOre'] !== null ? $kr($r['sumOre']) : '',
    ], $ut),
    'summer'  => array_map(static fn(array $s): array => [
        'navn'  => $s['navn'],
        'timer' => $tall($s['timer']),
        'sum'   => $kr($s['sumOre']),
        'ubekreftet' => $s['ubekreftet'],
    ], array_values($summer)),
]);
