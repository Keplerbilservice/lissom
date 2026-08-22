<?php
/**
 * Kjorer databasemigrasjonene over nett.
 *
 * Webhotellet har ikke Terminal, saa bin/migrate.php kan ikke kjores fra
 * kommandolinjen. Uten dette maatte hver endring importeres for haand i
 * phpMyAdmin — tungvint, og lett aa gjore i feil rekkefolge.
 *
 *   https://ny.lissom.no/api/migrer.php?nokkel=...          viser hva som mangler
 *   https://ny.lissom.no/api/migrer.php?nokkel=...&kjor=ja  kjorer dem
 *
 * Krever samme nokkel som helsesjekken. Uten den: 404, og den roper ikke at
 * den finnes. Kjorer aldri noe uten at «kjor=ja» er oppgitt.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

// Bade GET (adressefeltet) og POST (knappen i admin). Metoden avgjor hvilken
// sperre som gjelder — se lenger ned.
if (!in_array(Foresporsel::metode(), ['GET', 'POST'], true)) {
    Svar::feil('Feil metode.', 405);
}

// To veier inn.
//
// Noekkelen fra secrets.php virker alltid, ogsaa naar innlogging er det som
// er i stykker. Men aa lete den fram i ei fil paa serveren hver gang er
// tungvint, saa en innlogget admin slipper ogsaa til — med ett forbehold:
//
// Dette endepunktet endrer databasen, og det er et GET-kall. Uten en sperre
// kunne et bilde paa et fremmed nettsted utlost det mens du var innlogget.
// Sec-Fetch-Site forteller hvor kallet kom fra: «none» naar du skriver
// adressen selv eller bruker et bokmerke, «same-origin» fra en lenke paa vaar
// egen side, og «cross-site» naar noe paa et annet nettsted ber om den.
// Bare de to forste slipper gjennom. Mangler headeren (svaert gammel
// nettleser), kreves noekkelen.
$nokkel  = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');
$medNokkel = $nokkel !== '' && $oppgitt !== '' && hash_equals($nokkel, $oppgitt);

$fraEgenHand = in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '', ['none', 'same-origin'], true);

// Uten noekkel maa du vaere admin. Punktum.
if (!$medNokkel && !Sesjon::erAdmin()) {
    Svar::feil('Fant ikke siden.', 404);
}

// Aa lese hva som mangler endrer ingenting, og trenger ingen ekstra sperre.
// Aa kjore dem gjor det, og da maa vi vite at kallet kom fra oss:
//
//   POST      — Origin eller Referer sjekkes, som paa alle andre skjemaer.
//               Dette er veien knappen i admin gaar.
//   GET       — Sec-Fetch-Site maa si «none» (adressen skrevet inn selv) eller
//               «same-origin». Dette er veien adressefeltet gaar.
//
// Sec-Fetch-headerne sendes bare i sikker kontekst. Over HTTPS er de der; over
// vanlig http er de ikke det, og da faller GET-veien bort. Derfor bruker
// knappen POST — den virker uansett.
if (Foresporsel::tekst('kjor') === 'ja' && !$medNokkel) {
    if (Foresporsel::metode() === 'POST') {
        Foresporsel::krevSammeOpphav();
    } elseif (!$fraEgenHand) {
        Svar::feil('Fant ikke siden.', 404);
    }
}

// Migrasjonene legges ved siden av app/ av deploy-jobben.
$mappe = null;
foreach ([APP_DIR . '/../migrations', APP_DIR . '/../db/migrations'] as $m) {
    if (is_dir($m)) { $mappe = $m; break; }
}
if ($mappe === null) {
    Svar::feil('Fant ikke migrasjonsmappa på serveren.', 500);
}

DB::kjor('CREATE TABLE IF NOT EXISTS migrations (
    fil      VARCHAR(191) NOT NULL,
    kjort_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$kjort = array_column(DB::alle('SELECT fil FROM migrations ORDER BY fil'), 'fil');
$filer = glob($mappe . '/*.sql') ?: [];
sort($filer);
$mangler = array_values(array_filter($filer, static fn($f) => !in_array(basename($f), $kjort, true)));

if (Foresporsel::tekst('kjor') !== 'ja') {
    Svar::json([
        'kjort'   => $kjort,
        'mangler' => array_map('basename', $mangler),
        'hvordan' => $mangler === []
            ? 'Databasen er oppdatert. Ingenting å gjøre.'
            : 'Legg til &kjor=ja i adressen for å kjøre dem.',
    ]);
}

$resultat = [];
foreach ($mangler as $fil) {
    $navn = basename($fil);
    $sql = file_get_contents($fil);
    if ($sql === false) {
        $resultat[] = ['fil' => $navn, 'status' => 'kunne ikke lese fila'];
        break;
    }
    try {
        DB::kobling()->exec($sql);
        DB::settInn('migrations', ['fil' => $navn]);
        $resultat[] = ['fil' => $navn, 'status' => 'ok'];
    } catch (Throwable $e) {
        // Stopp med en gang. Migrasjonen merkes ikke som kjort, saa den kan
        // provess paa nytt naar feilen er rettet.
        $resultat[] = ['fil' => $navn, 'status' => 'FEIL', 'feil' => $e->getMessage()];
        logg_feil('Migrasjon feilet: ' . $navn, $e);
        break;
    }
}

revider('migrasjon_kjort', null, null, ['resultat' => $resultat]);

Svar::json([
    'kjort_naa' => $resultat,
    'tabeller'  => count(DB::alle('SHOW TABLES')),
]);
