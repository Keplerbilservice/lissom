<?php
/**
 * Meta::autosvar() mot en falsk Graph API (tests/falsk-autosvar.php).
 *
 * Eieren, 30. september 2026: svar paa alle kommentarer automatisk, med en
 * fast liste. Testen ser at nye kommentarer faar svar, og at egne, skjulte,
 * besvarte og gamle blir latt vaere.
 *
 *   php tests/autosvar.php
 */
declare(strict_types=1);

$rot = dirname(__DIR__);
$port = 8163;
$logg = sys_get_temp_dir() . '/lissom-autosvar-logg.txt';
file_put_contents($logg, '');

$tjener = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $rot . '/tests/falsk-autosvar.php'],
    [['pipe', 'r'], ['file', 'NUL', 'w'], ['file', 'NUL', 'w']], $pipes
);
usleep(1500000);

define('APP_DIR', $rot . '/app');
$LISSOM_SECRETS = [
    'miljo'        => 'test',
    'meta_token'   => 'test-token',
    'meta_ig_id'   => 'ig1',
    'meta_side_id' => 'side1',
];
require $rot . '/app/config.php';
require $rot . '/app/lib/nett.php';
require $rot . '/app/lib/meta.php';
putenv('LISSOM_META_BASE=http://127.0.0.1:' . $port);

$r = Meta::autosvar();
proc_terminate($tjener);

$linjer = array_values(array_filter(explode("\n", (string) file_get_contents($logg))));
$ok = 0; $feil = 0;
$sjekk = function (string $navn, bool $sant) use (&$ok, &$feil): void {
    $sant ? $ok++ : $feil++;
    echo ($sant ? '  OK    ' : '  FEIL  ') . $navn . "\n";
};

$sjekk('tre svar (ig-ny, fb-ny, ann-ny)', $r['svart'] === 3 && count($linjer) === 3);
$sjekk('ingen feil', $r['feil'] === []);
$sjekk('Instagram svarer under /replies', (bool) array_filter($linjer, fn($l) => str_contains($l, '/ig-ny/replies ')));
$sjekk('Facebook svarer under /comments', (bool) array_filter($linjer, fn($l) => str_contains($l, '/fb-ny/comments ')));
$sjekk('annonsekommentaren faar svar', (bool) array_filter($linjer, fn($l) => str_contains($l, '/ann-ny/comments ')));
foreach (['ig-svart', 'ig-skjult', 'ig-egen', 'ig-gammel', 'fb-egen', 'ann-skjult'] as $id) {
    $sjekk($id . ' faar ikke svar', !array_filter($linjer, fn($l) => str_contains($l, '/' . $id . '/')));
}
foreach ($linjer as $l) {
    $tekst = substr($l, strpos($l, ' ') + 1);
    $sjekk('svaret er fra lista: ' . $tekst, in_array($tekst, Meta::AUTOSVAR, true));
}

unlink($logg);
echo "\n{$ok} ok, {$feil} feil\n";
exit($feil === 0 ? 0 : 1);
