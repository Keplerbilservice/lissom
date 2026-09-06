<?php
/**
 * Ett spoersmaal, ett sted: kommer dette fra nettet, eller er det en jobb?
 *
 * Dette sto to steder, og begge spurte om PHP_SAPI — som er feil sted aa
 * spoerre. Begge tok feil paa samme tjener, av samme grunn.
 *
 * Eieren, 6. september 2026, med en videresendt e-post fra Cron Daemon:
 *
 *     Cron <rbvapxvz@gungnir> php ~/lissom-app/bin/cron.php betalinger
 *     Status: 404 Not Found
 *     Content-type: text/html; charset=UTF-8
 *
 * «php» paa den tjeneren er CGI-utgaven, ikke CLI-utgaven. bin/cron.php sto
 * som «PHP_SAPI !== 'cli'» og svarte 404 — alle seks jobbene stoppet foer
 * foerste linje arbeid. Ingen varsler, ingen betalinger, ingen medlemstrekk.
 * Det er grunnen til at pengene aldri ble trukket.
 *
 * Tikk::planlegg() sto som «PHP_SAPI === 'cli'» og tok feil den andre veien:
 * under CGI trodde den at cron-jobben var en besokende, og la nettsidens
 * bakgrunnsarbeid oppaa jobben som alt kjorte det samme.
 *
 * Det som skiller en jobb fra en nettforespoersel er at nettforespoerselen
 * HAR en foresporsel: webtjeneren setter alltid REQUEST_METHOD. Cron setter
 * den aldri, uansett hvilken PHP-utgave som kjorer.
 *
 * Fila lastes med require_once fra bin/cron.php foer app/bootstrap.php, og
 * fra bootstrap selv. Den skal ikke trenge noe annet for aa virke.
 *
 * Hele avgjorelsen kjores for alle SAPI-ene i tests/cronvakt.php.
 */

declare(strict_types=1);

/**
 * @param array<string,mixed> $server
 */
function er_nettforesporsel(string $sapi, array $server): bool
{
    // De tre SAPI-ene en webtjener faktisk bruker. «cgi» og «cgi-fcgi» staar
    // ikke her: det er dem cron bruker paa tjenere som denne.
    if (in_array($sapi, ['apache2handler', 'fpm-fcgi', 'litespeed'], true)) {
        return true;
    }
    // En ekte foresporsel har en metode og et vertsnavn. En jobb har ingen.
    return isset($server['REQUEST_METHOD']) || isset($server['HTTP_HOST']);
}
