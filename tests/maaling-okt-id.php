<?php
/**
 * Økt-ID-en fra GA4-cookien (_ga_<id>) til kjøpet som sendes fra serveren.
 * Google byttet format fra GS1 til GS2 i 2025; bare GS1 ble lest, og kjøpene
 * havnet under «(not set)» i GA4. GO fra eieren 2. oktober 2026.
 * Kjør: php tests/maaling-okt-id.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/lib/maaling.php';

$feil = 0;
$sjekk = static function (string $navn, bool $ok) use (&$feil): void {
    echo ($ok ? 'OK   ' : 'FEIL ') . $navn . "\n";
    if (!$ok) {
        $feil++;
    }
};
$sid = static function (array $cookies): ?string {
    $_COOKIE = $cookies;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'test';
    $ut = json_decode(Maaling::sporingFraNettleser() ?: '{}', true);
    return $ut['sid'] ?? null;
};

$sjekk('GS1 (gammelt format) gir økt-ID', $sid(['_ga' => 'GA1.1.111.222', '_ga_GMJSTL5KP2' => 'GS1.1.1759412345.3.1.1759412400.0.0.0']) === '1759412345');
$sjekk('GS2 (nytt format) gir økt-ID', $sid(['_ga' => 'GA1.1.111.222', '_ga_GMJSTL5KP2' => 'GS2.1.s1759412345$o3$g1$t1759412400$j60$l0$h0']) === '1759412345');
$sjekk('GS2 med bare økten gir økt-ID', $sid(['_ga_GMJSTL5KP2' => 'GS2.1.s1759412345']) === '1759412345');
$sjekk('Søppelverdi gir ingen økt-ID', $sid(['_ga_GMJSTL5KP2' => 'GS2.1.sabc']) === null);
$_COOKIE = [];
$sjekk('Uten cookies (ingen samtykke) lagres ingenting', Maaling::sporingFraNettleser() === '');

echo $feil ? "\n$feil feil\n" : "\nAlle sjekker grønne\n";
exit($feil ? 1 : 0);
