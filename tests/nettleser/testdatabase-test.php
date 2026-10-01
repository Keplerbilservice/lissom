<?php
/** Rene negativtester; ingen ekte secrets eller databasetilkobling. */
declare(strict_types=1);
require __DIR__ . '/testdatabase.php';
$antall = 0;
function avvis(callable $test): void {
    global $antall;
    try { $test(); } catch (RuntimeException $e) { $antall++; return; }
    throw new RuntimeException('Usikkert testoppsett ble godtatt.');
}
$s = ['miljo' => 'test', 'db_vert' => '127.0.0.1', 'db_navn' => 'lissom_test',
    'db_bruker' => 'test', 'db_passord' => ''];
foreach (['lissom_test', 'lissom_test_agent_1'] as $navn) {
    krev_testdatabase_identitet(array_replace($s, ['db_navn' => $navn]), $navn);
    $antall++;
}
krev_testdatabase_oppsett(array_replace($s, ['db_port' => 3311]));
$antall++;
foreach ([['miljo' => 'produksjon'], ['miljo' => ''], ['db_vert' => 'db.example.com'],
    ['db_vert' => '127.0.0.1;dbname=lissom'], ['db_navn' => 'lissom'],
    ['db_navn' => 'lissom_test;host=remote'], ['db_navn' => 'lissom_test_'],
    ['db_navn' => null], ['db_bruker' => ''], ['db_passord' => null],
    ['db_port' => 0], ['db_port' => 65536], ['db_port' => '3311'],
    ['db_port' => '3311;host=remote'], ['db_port' => -1]] as $endring) {
    avvis(static fn() => krev_testdatabase_oppsett(array_replace($s, $endring)));
}
avvis(static fn() => krev_testdatabase_identitet($s, 'lissom'));
avvis(static fn() => krev_testdatabase_identitet($s, false));

$tmp = sys_get_temp_dir() . '/lissom-vakt-' . bin2hex(random_bytes(6));
$rot = $tmp . '/repo';
mkdir($rot . '/tests/nettleser', 0700, true);
mkdir($rot . '/app', 0700, true);
mkdir($tmp . '/lissom-secrets', 0700);
copy(__DIR__ . '/seed.php', $rot . '/tests/nettleser/seed.php');
copy(__DIR__ . '/testdatabase.php', $rot . '/tests/nettleser/testdatabase.php');
file_put_contents($rot . '/app/bootstrap.php', '<?php file_put_contents(__DIR__ . "/STARTET", "feil"); exit(99);');
try {
    avvis(static fn() => krev_testdatabase($rot)); // Manglende oppsett.
    file_put_contents($rot . '/app/secrets.php', '<?php return ' . var_export(array_replace($s, ['db_navn' => 'lissom']), true) . ';');
    foreach (['', ' --rydd'] as $argument) {
        $ut = []; $kode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($rot . '/tests/nettleser/seed.php') . $argument . ' 2>&1', $ut, $kode);
        if ($kode !== 1 || is_file($rot . '/app/STARTET')) {
            throw new RuntimeException('Seed kom forbi vakten eller ga feil exitkode.');
        }
        $antall++;
    }
    // En ekstern secrets-fil må avvises uten å bli lest, selv med gyldig lokal config.
    file_put_contents($rot . '/app/secrets.php', '<?php return ' . var_export($s, true) . ';');
    file_put_contents($tmp . '/lissom-secrets/secrets.php', '<?php file_put_contents(__DIR__ . "/LEST", "feil"); return [];');
    avvis(static fn() => krev_testdatabase($rot));
    if (is_file($tmp . '/lissom-secrets/LEST')) { throw new RuntimeException('Eksterne secrets ble lest.'); }
} finally {
    foreach ([$rot . '/tests/nettleser/seed.php', $rot . '/tests/nettleser/testdatabase.php',
        $rot . '/app/bootstrap.php', $rot . '/app/secrets.php', $rot . '/app/STARTET',
        $tmp . '/lissom-secrets/secrets.php', $tmp . '/lissom-secrets/LEST'] as $fil) {
        if (is_file($fil)) { unlink($fil); }
    }
    foreach ([$rot . '/tests/nettleser', $rot . '/tests', $rot . '/app', $rot,
        $tmp . '/lissom-secrets', $tmp] as $mappe) { rmdir($mappe); }
}
echo "OK: {$antall} testdatabasekontroller\n";
