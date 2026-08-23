<?php
/**
 * Legger designsystemets stilark rett inn i nettsida.
 *
 *   php bin/inline-css.php
 *
 * De aatte ds-*.css-filene er til sammen 14 kB. Som egne filer kostet de
 * likevel over ett sekund for noe ble tegnet: de staar i <helmet>, som
 * dc-runtime leser foerst etter at support.js har kjort, saa nettleseren
 * venter i to omganger — ett hopp for skriptet, ett for hver fil.
 *
 * Inne i <head> er de der med det samme, uten en eneste forespoersel.
 *
 * Filene er fortsatt kilden. Denne kopien skal ikke redigeres for haand —
 * endrer du en ds-*.css, kjorer du dette paa nytt. Blokka mellom merkene
 * skrives da over.
 */

declare(strict_types=1);

const START = '<!-- ds-css:start — skrevet av bin/inline-css.php, ikke rediger her -->';
const SLUTT = '<!-- ds-css:slutt -->';

/** Rekkefolgen betyr noe: variabler for det som bruker dem. */
const FILER = [
    'ds-fonts.css',
    'ds-colors.css',
    'ds-typography.css',
    'ds-spacing.css',
    'ds-elevation.css',
    'ds-motion.css',
    'ds-base.css',
    'ds-styles.css',
];

$rot  = dirname(__DIR__);
$side = $rot . '/lissom-2108.html';

$deler = [];
foreach (FILER as $f) {
    $sti = $rot . '/' . $f;
    if (!is_file($sti)) {
        fwrite(STDERR, "Fant ikke {$f}\n");
        exit(1);
    }
    $css = (string) file_get_contents($sti);
    // «</style>» inne i en CSS-fil ville lukket blokka vaar midt i.
    if (stripos($css, '</style') !== false) {
        fwrite(STDERR, "{$f} inneholder </style> og kan ikke legges inn.\n");
        exit(1);
    }
    $deler[] = "/* {$f} */\n" . trim($css);
}

$blokk = START . "\n<style>\n" . implode("\n\n", $deler) . "\n</style>\n" . SLUTT;

$html = (string) file_get_contents($side);
$a = strpos($html, START);
$b = strpos($html, SLUTT);

if ($a === false || $b === false) {
    fwrite(STDERR, "Merkene mangler i lissom-2108.html. Legg inn:\n" . START . "\n" . SLUTT . "\n");
    exit(1);
}

$ny = substr($html, 0, $a) . $blokk . substr($html, $b + strlen(SLUTT));
file_put_contents($side, $ny);

printf("%d stilark lagt inn, %.1f kB\n", count(FILER), strlen($blokk) / 1024);
