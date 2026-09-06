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

require_once dirname(__DIR__) . '/app/lib/foresporsel.php';
$kilde = file_get_contents(dirname(__DIR__) . '/bin/cron.php');

$ok = 0;
$feil = 0;
$sjekk = static function (string $navn, bool $v) use (&$ok, &$feil): void {
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn\n"; }
};

echo "\n── Cron-vakta ───────────────────────────────────────────────\n";

// ── Slik cron kjorer: ingen foresporsel rundt ────────────────────────
$sjekk('cron med CLI-utgaven slipper gjennom',
    er_nettforesporsel('cli', ['PATH' => '/usr/bin']) === false);
$sjekk('cron med CGI-utgaven slipper gjennom — det er den tjeneren bruker',
    er_nettforesporsel('cgi-fcgi', ['PATH' => '/usr/bin']) === false);
$sjekk('… ogsaa den gamle «cgi»',
    er_nettforesporsel('cgi', ['PATH' => '/usr/bin']) === false);
$sjekk('… og «phpdbg»',
    er_nettforesporsel('phpdbg', []) === false);

// ── Slik nettet kommer: alltid med en foresporsel ────────────────────
$nett = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'lissom.no', 'REMOTE_ADDR' => '1.2.3.4'];
$sjekk('Apache stoppes', er_nettforesporsel('apache2handler', $nett) === true);
$sjekk('PHP-FPM stoppes', er_nettforesporsel('fpm-fcgi', $nett) === true);
$sjekk('LiteSpeed stoppes', er_nettforesporsel('litespeed', $nett) === true);
$sjekk('CGI bak en webtjener stoppes ogsaa',
    er_nettforesporsel('cgi-fcgi', $nett) === true);
$sjekk('… og en foresporsel uten vertsnavn',
    er_nettforesporsel('cgi-fcgi', ['REQUEST_METHOD' => 'POST']) === true);
$sjekk('… og en med bare vertsnavn',
    er_nettforesporsel('cgi-fcgi', ['HTTP_HOST' => 'lissom.no']) === true);
$sjekk('Apache stoppes selv uten foresporsel i miljoet',
    er_nettforesporsel('apache2handler', []) === true);

// ── Konstanter som bare finnes i CLI-utgaven ─────────────────────────
//
// Eieren, 6. september 2026, e-post fra Cron Daemon 22:10 og 22:20 — etter
// at 404-en var rettet:
//
//     Status: 500 Internal Server Error
//     Content-Type: application/json; charset=utf-8
//     {"feil":"Noe gikk galt. Proev igjen, eller ta kontakt med oss."}
//
// Jobben kom forbi vakta og stoppet paa «stream_isatty(STDOUT)». STDOUT,
// STDERR og STDIN settes av CLI-utgaven av PHP. CGI-utgaven — den tjeneren
// bruker — har dem ikke, og et ukjent konstantnavn er en Error i PHP 8.
//
// Kommentarene faar nevne dem; koden skal ikke roere dem. Derfor leses fila
// som PHP-tegn, ikke som tekst.
$navn = [];
foreach (token_get_all($kilde) as $t) {
    if (is_array($t) && $t[0] === T_STRING) {
        $navn[] = $t[1];
    }
}
foreach (['STDOUT', 'STDERR', 'STDIN'] as $k) {
    $sjekk("bin/cron.php roerer ikke {$k} — den finnes ikke i CGI-utgaven",
        !in_array($k, $navn, true));
}

// Et ukjent konstantnavn er en Error. Det er selve mekanismen som veltet
// jobben, og den maales her i stedet for aa antas.
$kastet = '';
try {
    /** @phpstan-ignore-next-line */
    stream_isatty(EN_KONSTANT_SOM_IKKE_FINNES);
} catch (Throwable $e) {
    $kastet = $e::class;
}
$sjekk('et ukjent konstantnavn kaster Error', $kastet === 'Error');

// ── Jobben skal svare som en jobb, ikke som en nettside ──────────────
//
// Handtereren i app/bootstrap.php svarer med HTTP-status og JSON. Faar den
// en cron-jobb i fanget, blir det «Status: 500» i en e-post uten et ord om
// hva som var galt — og sluttkoden blir 0, saa cron tror alt gikk bra.
$php = PHP_BINARY;
$cron = escapeshellarg(dirname(__DIR__) . '/bin/cron.php');

$kjor = static function (string $arg) use ($php, $cron): array {
    $p = proc_open(
        escapeshellarg($php) . ' ' . $cron . ' ' . escapeshellarg($arg),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $r
    );
    if (!is_resource($p)) { return ['', '', -1]; }
    $ut = (string) stream_get_contents($r[1]);
    $feil = (string) stream_get_contents($r[2]);
    fclose($r[1]);
    fclose($r[2]);
    return [$ut, $feil, proc_close($p)];
};

[$ut, $stderr, $kode] = $kjor('finnes-ikke');
$sjekk('ukjent jobbnavn: ingenting paa stdout', trim($ut) === '');
$sjekk('ukjent jobbnavn: bruksanvisningen paa stderr', str_contains($stderr, 'Bruk: php bin/cron.php'));
$sjekk('ukjent jobbnavn: sluttkode 1', $kode === 1);
$sjekk('ingen HTTP-status ut av en jobb', !str_contains($ut . $stderr, 'Status: 500'));
$sjekk('ingen JSON-linje ut av en jobb', !str_contains($ut . $stderr, '{"feil"'));

echo "\n──────────────────────────────────────────────\n";
echo ($ok + $feil) . " sjekker, $ok gikk gjennom" . ($feil ? ", $feil feilet" : '') . "\n";
exit($feil > 0 ? 1 : 0);
