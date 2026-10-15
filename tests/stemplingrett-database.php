<?php
/** Målrettet databasekontroll av forslag, tilgang og retting av verkstedtid. */
declare(strict_types=1);
require __DIR__ . '/nettleser/testdatabase.php';
krev_testdatabase(dirname(__DIR__));
require dirname(__DIR__) . '/app/bootstrap.php';

$tag = bin2hex(random_bytes(5));
$medlemId = DB::settInn('members', ['navn' => 'Stemplingtest ' . $tag,
    'epost' => $tag . '@lissom.test', 'status' => 'aktiv']);
$annenId = DB::settInn('members', ['navn' => 'Annen stemplingtest ' . $tag,
    'epost' => $tag . '-annen@lissom.test', 'status' => 'aktiv']);
$dato = (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
$tid = StemplingRett::beregn($dato, '11:00', '20:33');
$oktId = DB::settInn('check_ins', ['member_id' => $medlemId, 'inn_tid' => $tid['inn'],
    'ut_tid' => $tid['ut'], 'minutter' => 360]);
$sjekk = static function (bool $ok, string $melding): void {
    if (!$ok) { throw new RuntimeException($melding); }
};
try {
    $sakId = StemplingRett::foresla($medlemId, $oktId, $dato, '11:00', '14:00', 'Glemte å stemple ut');
    $sjekk((int) DB::verdi('SELECT minutter FROM check_ins WHERE id = :i', ['i' => $oktId]) === 360,
        'Medlemmets forslag endret timekontoen.');
    try {
        StemplingRett::foresla($annenId, $oktId, $dato, '11:00', '14:00', 'Feil medlem og økt');
        throw new RuntimeException('Medlem fikk foreslå tid for en annens økt.');
    } catch (InvalidArgumentException) {}
    $oversikt = StemplingRett::oversikt($medlemId, $sakId, $oktId);
    $sjekk(count($oversikt['saker']) === 1 && $oversikt['saker'][0]['oktId'] === $oktId,
        'Forslaget ble ikke knyttet til valgt økt.');
    $rettet = StemplingRett::rett($medlemId, $oktId, $dato, '11:00', '14:00',
        'Avklart med medlemmet', $annenId, $sakId);
    $sjekk($rettet['id'] === $oktId && $rettet['minutter'] === 180, 'Feil resultat av rettingen.');
    $sjekk((int) DB::verdi('SELECT minutter FROM check_ins WHERE id = :i', ['i' => $oktId]) === 180,
        'Rettede minutter ble ikke lagret.');
    $sjekk(DB::verdi('SELECT status FROM stempling_rettinger WHERE id = :s', ['s' => $sakId]) === 'rettet',
        'Forslaget ble ikke lukket etter retting.');
    try {
        StemplingRett::rett($medlemId, 0, $dato, '13:00', '15:00', 'Overlappstest', $annenId);
        throw new RuntimeException('Overlappende økt ble godtatt.');
    } catch (InvalidArgumentException) {}
    $ny = StemplingRett::rett($medlemId, 0, $dato, '16:00', '17:00', 'Manglet stempling', $annenId);
    $sjekk($ny['minutter'] === 60 && $ny['id'] !== $oktId, 'Manglende økt ble ikke lagt inn riktig.');
    echo "StemplingRett database OK\n";
} finally {
    DB::kjor('DELETE FROM stempling_rettinger WHERE member_id IN (:m, :a)', ['m' => $medlemId, 'a' => $annenId]);
    DB::kjor('DELETE FROM check_ins WHERE member_id IN (:m, :a)', ['m' => $medlemId, 'a' => $annenId]);
    DB::kjor('DELETE FROM members WHERE id IN (:m, :a)', ['m' => $medlemId, 'a' => $annenId]);
}
