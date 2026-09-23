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

require_once APP_DIR . '/lib/foresporsel.php';
require APP_DIR . '/config.php';

/**
 * Klassene lastes naar de brukes.
 *
 * Her sto 34 «require» paa rad, og hver eneste foresporsel leste alle
 * sammen — 764 kB PHP for aa svare paa noe som helst. api/meg.php svarer
 * med 19 byte og lastet likevel Vipps, AI, PDF-lesing, dokumenter,
 * robottekster og maaling.
 *
 * Maalt 22. september 2026: api/meg.php brukte 0,16–0,26 s, mens en
 * statisk fil fra samme server gaar paa 0,05 s. Den faste avgiften traff
 * hvert eneste kall, og appen gjor ni av dem naar bookingsida aapnes.
 *
 * De tre filene under definerer globale FUNKSJONER — logg(), krev_admin(),
 * http_post_json(). Dem finner ingen autolaster, for den leter etter
 * klassenavn. foresporsel.php er alt lastet over, av samme grunn.
 *
 * Kartet er skrevet ut, ikke gjettet fram av filnavnet: «ratelimit.php»
 * inneholder Rate, og «varsler.php» inneholder baade Varsel og Utsending.
 * bin/autolastsjekk.mjs passer paa at det stemmer med filene paa disken.
 */
spl_autoload_register(static function (string $klasse): void {
    static $kart = [
        'AI' => 'ai.php',
        'Gemini' => 'gemini.php',
        'Apent' => 'apent.php',
        'Artikler' => 'artikler.php',
        'Avmelding' => 'avmelding.php',
        'Bilder' => 'bilder.php',
        'Booking' => 'booking.php',
        'DB' => 'db.php',
        'Dokumenter' => 'dokumenter.php',
        'Dugnad' => 'dugnad.php',
        'Ferie' => 'ferie.php',
        'Foresporsel' => 'http.php',
        'Frys' => 'frys.php',
        'Katalog' => 'katalog.php',
        'Kursholder' => 'kursholder.php',
        'Kursmal' => 'kursmal.php',
        'Lenker' => 'lenker.php',
        'Maaling' => 'maaling.php',
        'Maler' => 'maler.php',
        'Medlemskap' => 'medlemskap.php',
        'Meta' => 'meta.php',
        'Medlemsordre' => 'medlemsordre.php',
        'Oppmote' => 'oppmote.php',
        'Oppsett' => 'oppsett.php',
        'Pdftekst' => 'pdftekst.php',
        'Rate' => 'ratelimit.php',
        'Robottekst' => 'robottekst.php',
        'Samlinger' => 'samlinger.php',
        'Serier' => 'serier.php',
        'Skolerute' => 'skolerute.php',
        'Sesjon' => 'session.php',
        'Sikkerhetskopi' => 'sikkerhetskopi.php',
        'Stempling' => 'stempling.php',
        'Svar' => 'http.php',
        'Tikk' => 'tikk.php',
        'Tillegg' => 'tillegg.php',
        'Utsending' => 'varsler.php',
        'Varsel' => 'varsler.php',
        'Veileder' => 'veileder.php',
        'Vipps' => 'vipps.php',
    ];
    if (isset($kart[$klasse])) {
        require APP_DIR . '/lib/' . $kart[$klasse];
    }
});

require APP_DIR . '/lib/logg.php';
require APP_DIR . '/lib/nett.php';
require APP_DIR . '/lib/auth.php';

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
