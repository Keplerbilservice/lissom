<?php
/** Målrettet kontroll av stemplingsforslagets datovalidering og sekstimerstak. */
declare(strict_types=1);
require dirname(__DIR__) . '/app/lib/stemplingrett.php';

$dato = (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
$kort = StemplingRett::beregn($dato, '11:00', '15:00');
if ($kort['minutter'] !== 240) { throw new RuntimeException('Fire timer må gi 240 minutter.'); }
$lang = StemplingRett::beregn($dato, '11:00', '20:33');
if ($lang['minutter'] !== 360) { throw new RuntimeException('Lang økt må få seks timers tak.'); }
$natt = StemplingRett::beregn($dato, '22:00', '00:30');
if ($natt['minutter'] !== 150) { throw new RuntimeException('Økt over midnatt må gi 150 minutter.'); }
foreach ([[$dato, '15:00', '11:00'], ['2026-02-30', '11:00', '12:00'], [$dato, '23:30', '23:30']] as $ugyldig) {
    try { StemplingRett::beregn(...$ugyldig); }
    catch (InvalidArgumentException) { continue; }
    throw new RuntimeException('Ugyldig tid ble godkjent: ' . implode(' ', $ugyldig));
}
echo "StemplingRett::beregn OK\n";
