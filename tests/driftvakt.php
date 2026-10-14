<?php
declare(strict_types=1);

$rot = dirname(__DIR__);
$klasse = (string) file_get_contents($rot . '/app/lib/driftvakt.php');
$cron = (string) file_get_contents($rot . '/bin/cron.php');
$boot = (string) file_get_contents($rot . '/app/bootstrap.php');

$sjekker = [
    'fast mottaker er Joakim' => str_contains($klasse, "STANDARD_EPOST = 'joakim@kepler.no'"),
    'vakta bruker eksisterende datasjekk' => str_contains($klasse, 'Vaktdata::sjekk()'),
    'vakta kontakter ikke Vipps' => !str_contains($klasse, 'Vipps::'),
    'vakta retter ikke forretningsdata' => !preg_match('/\b(UPDATE|DELETE)\s+(payments|members|subscriptions|bookings)\b/i', $klasse),
    'like avvik dempes i ett doegn' => str_contains($klasse, 'time() - 86400'),
    'cron har driftvakt-jobben' => str_contains($cron, "case 'driftvakt':") && str_contains($cron, 'Driftvakt::kjor()'),
    'klassen autolastes' => str_contains($boot, "'Driftvakt' => 'driftvakt.php'"),
];

$feil = 0;
foreach ($sjekker as $navn => $ok) {
    echo ($ok ? 'OK   ' : 'FEIL ') . $navn . "\n";
    if (!$ok) { $feil++; }
}
exit($feil === 0 ? 0 : 1);
