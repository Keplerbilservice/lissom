<?php
/**
 * Kjører SQL-filene i db/migrations/ i rekkefølge, og husker hvilke som er
 * kjørt. Trygg å kjøre om igjen — det som allerede er gjort hoppes over.
 *
 * Fra SSH på webhotellet:   php ~/lissom-app/bin/migrate.php
 * Se hva som mangler:       php ~/lissom-app/bin/migrate.php --status
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$mappe = dirname(__DIR__) . '/db/migrations';
if (!is_dir($mappe)) {
    // På webhotellet ligger migrasjonene ved siden av app/.
    $mappe = dirname(__DIR__) . '/migrations';
}

DB::kjor('CREATE TABLE IF NOT EXISTS migrations (
    fil        VARCHAR(191) NOT NULL,
    kjort_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$kjort = array_column(DB::alle('SELECT fil FROM migrations'), 'fil');
$filer = glob($mappe . '/*.sql') ?: [];
sort($filer);

$mangler = array_values(array_filter($filer, static fn($f) => !in_array(basename($f), $kjort, true)));

if (in_array('--status', $argv, true)) {
    echo "Kjørt:   " . count($kjort) . "\n";
    echo "Mangler: " . count($mangler) . "\n";
    foreach ($mangler as $f) {
        echo '  · ' . basename($f) . "\n";
    }
    exit(0);
}

if ($mangler === []) {
    echo "Databasen er oppdatert. Ingenting å gjøre.\n";
    exit(0);
}

foreach ($mangler as $fil) {
    $navn = basename($fil);
    echo "→ {$navn} ... ";

    $sql = file_get_contents($fil);
    if ($sql === false) {
        echo "KUNNE IKKE LESE FILA\n";
        exit(1);
    }

    try {
        // MySQL tillater ikke DDL i transaksjoner, så vi kjører setningene rett
        // fram. Går noe galt, stopper vi — og migrasjonen merkes ikke som kjørt.
        DB::kobling()->exec($sql);
        DB::settInn('migrations', ['fil' => $navn]);
        echo "ok\n";
    } catch (Throwable $e) {
        echo "FEIL\n\n" . $e->getMessage() . "\n\n";
        echo "Ingenting mer kjøres. Rett opp i {$navn} og prøv igjen.\n";
        exit(1);
    }
}

echo "\nFerdig. " . count($mangler) . " migrasjon(er) kjørt.\n";
