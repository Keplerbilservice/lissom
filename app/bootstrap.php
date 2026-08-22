<?php
/**
 * Felles oppstart for alle endepunkter.
 *
 * Denne fila og resten av app/ ligger UTENFOR webroten på webhotellet, slik at
 * ingen kan laste den ned ved å gjette adressen. Endepunktene i /api henter den
 * inn via api/_boot.php.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Krever PHP 8.1 eller nyere. Sett PHP-versjon i kontrollpanelet hos Domene.no.');
}

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

define('APP_DIR', __DIR__);

// Hemmeligheter lastes opp manuelt til serveren én gang, og er aldri i git.
// De ligger med vilje i en EGEN mappe utenfor lissom-app/, slik at deploy-jobben
// aldri kan komme til å overskrive eller slette dem.
// Se app/secrets.example.php for malen.
$hemmeligheter = '';
foreach ([
    dirname(APP_DIR) . '/../lissom-secrets/secrets.php', // anbefalt: ~/lissom-secrets/
    APP_DIR . '/secrets.php',                            // lokalt under utvikling
] as $sti) {
    if (is_file($sti)) {
        $hemmeligheter = $sti;
        break;
    }
}
if ($hemmeligheter === '') {
    http_response_code(500);
    error_log('Lissom: fant ikke secrets.php. Forventet i ~/lissom-secrets/secrets.php');
    exit('Serveren er ikke ferdig satt opp.');
}

/** @var array<string,mixed> $LISSOM_SECRETS */
$LISSOM_SECRETS = require $hemmeligheter;

require APP_DIR . '/config.php';
require APP_DIR . '/lib/db.php';
require APP_DIR . '/lib/http.php';
require APP_DIR . '/lib/nett.php';
require APP_DIR . '/lib/logg.php';
require APP_DIR . '/lib/session.php';
require APP_DIR . '/lib/auth.php';
require APP_DIR . '/lib/ratelimit.php';
require APP_DIR . '/lib/varsler.php';
require APP_DIR . '/lib/vipps.php';
require APP_DIR . '/lib/booking.php';
require APP_DIR . '/lib/stempling.php';
require APP_DIR . '/lib/tikk.php';

// Vis aldri PHP-feil til publikum — de lekker filstier og SQL. De havner i
// feilloggen på webhotellet i stedet.
error_reporting(E_ALL);
ini_set('display_errors', Config::erUtvikling() ? '1' : '0');
ini_set('log_errors', '1');

// Bakgrunnsarbeid uten cron: forste forespoersel i hvert minuttvindu tommer
// varselkoen og sjekker betalinger som henger. Kjorer etter at svaret er sendt.
Tikk::planlegg();

set_exception_handler(static function (Throwable $e): void {
    logg_feil('Ubehandlet feil', $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(
        Config::erUtvikling()
            ? ['feil' => $e->getMessage(), 'sted' => $e->getFile() . ':' . $e->getLine()]
            : ['feil' => 'Noe gikk galt. Prøv igjen, eller ta kontakt med oss.'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
});
