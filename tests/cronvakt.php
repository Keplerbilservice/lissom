<?php
/**
 * Vakta som avgjor om en cron-jobb faar kjore.
 *
 * Eieren, 6. september 2026, med en videresendt e-post fra Cron Daemon:
 *
 *     Cron <rbvapxvz@gungnir> php ~/lissom-app/bin/cron.php betalinger
 *     Status: 404 Not Found
 *     Content-type: text/html; charset=UTF-8
 *
 * Vakta sto som «PHP_SAPI !== 'cli'». «php» paa den tjeneren er CGI-utgaven,
 * og alle seks jobbene stoppet paa den linja — ingen varsler, ingen
 * betalinger, ingen medlemstrekk. Det er grunnen til at pengene aldri ble
 * trukket.
 *
 * SAPI-navnet er feil sted aa spoerre. Det som skiller en jobb fra en
 * nettforespoersel er at nettforespoerselen HAR en foresporsel.
 *
 * Denne testen kjorer avgjorelsen for alle tilfellene som finnes — ogsaa
 * CGI-utgaven, som ikke finnes i utviklingsmiljoet.
 *
 * Kjor:  php tests/cronvakt.php
 */
declare(strict_types=1);

$kilde = file_get_contents(dirname(__DIR__) . '/bin/cron.php');
$i = strpos($kilde, 'function cron_fra_nettet');
if ($i === false) {
    fwrite(STDERR, "Fant ikke cron_fra_nettet() i bin/cron.php\n");
    exit(1);
}
$j = strpos($kilde, "\n}\n", $i) + 3;
eval(substr($kilde, $i, $j - $i));

$ok = 0;
$feil = 0;
$sjekk = static function (string $navn, bool $v) use (&$ok, &$feil): void {
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn\n"; }
};

echo "\n── Cron-vakta ───────────────────────────────────────────────\n";

// ── Slik cron kjorer: ingen foresporsel rundt ────────────────────────
$sjekk('cron med CLI-utgaven slipper gjennom',
    cron_fra_nettet('cli', ['PATH' => '/usr/bin']) === false);
$sjekk('cron med CGI-utgaven slipper gjennom — det er den tjeneren bruker',
    cron_fra_nettet('cgi-fcgi', ['PATH' => '/usr/bin']) === false);
$sjekk('… ogsaa den gamle «cgi»',
    cron_fra_nettet('cgi', ['PATH' => '/usr/bin']) === false);
$sjekk('… og «phpdbg»',
    cron_fra_nettet('phpdbg', []) === false);

// ── Slik nettet kommer: alltid med en foresporsel ────────────────────
$nett = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'lissom.no', 'REMOTE_ADDR' => '1.2.3.4'];
$sjekk('Apache stoppes', cron_fra_nettet('apache2handler', $nett) === true);
$sjekk('PHP-FPM stoppes', cron_fra_nettet('fpm-fcgi', $nett) === true);
$sjekk('LiteSpeed stoppes', cron_fra_nettet('litespeed', $nett) === true);
$sjekk('CGI bak en webtjener stoppes ogsaa',
    cron_fra_nettet('cgi-fcgi', $nett) === true);
$sjekk('… og en foresporsel uten vertsnavn',
    cron_fra_nettet('cgi-fcgi', ['REQUEST_METHOD' => 'POST']) === true);
$sjekk('… og en med bare vertsnavn',
    cron_fra_nettet('cgi-fcgi', ['HTTP_HOST' => 'lissom.no']) === true);
$sjekk('Apache stoppes selv uten foresporsel i miljoet',
    cron_fra_nettet('apache2handler', []) === true);

echo "\n──────────────────────────────────────────────\n";
echo ($ok + $feil) . " sjekker, $ok gikk gjennom" . ($feil ? ", $feil feilet" : '') . "\n";
exit($feil > 0 ? 1 : 0);
