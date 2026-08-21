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

Foresporsel::krevMetode('GET');

$nokkel = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');

if ($nokkel === '' || $oppgitt === '' || !hash_equals($nokkel, $oppgitt)) {
    Svar::feil('Fant ikke siden.', 404);
}

// Migrasjonene legges ved siden av app/ av deploy-jobben.
$mappe = null;
foreach ([APP_DIR . '/../migrations', APP_DIR . '/../db/migrations'] as $m) {
    if (is_dir($m)) { $mappe = $m; break; }
}
if ($mappe === null) {
    Svar::feil('Fant ikke migrasjonsmappa paa serveren.', 500);
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
            ? 'Databasen er oppdatert. Ingenting aa gjore.'
            : 'Legg til &kjor=ja i adressen for aa kjore dem.',
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
