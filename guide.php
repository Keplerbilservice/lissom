<?php
/**
 * De åpne guidene under /nyttig-info/<adresse> — med måling og samtykke.
 *
 * Guidene er ferdige filer i guider/ (bin/guidepakke.mjs) og ble servert
 * rett fra .htaccess. Da hadde de ingenting av det resten av nettsiden
 * har: ingen samtykkeboks, ingen Google Analytics, ingen Google Ads,
 * ingen Meta-piksel. Målt 21. september 2026: sju artikler som fanger
 * nasjonale søk, og ikke ett besøk ble telt — den som gikk videre til et
 * kurs mistet sporet.
 *
 * Her sendes fila ut som før, men med det samme lille tillegget nederst
 * som serversidene har: samtykkeboksen (Deler::samtykke), måle-ID-ene
 * (window.lissomMaal) og nett.js. Boksen bruker nettsidas CSS-variabler;
 * guiden har ikke nett.css, så de variablene den trenger legges ved.
 * Ingen måling før noen har sagt ja — samme regel som ellers.
 */

declare(strict_types=1);

$slug = (string) ($_GET['slug'] ?? '');
$fil = __DIR__ . '/guider/' . $slug . '.html';
if (preg_match('~^[a-z0-9-]{1,80}$~', $slug) !== 1 || !is_file($fil)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Fant ikke guiden.';
    exit;
}

$html = (string) file_get_contents($fil);

$tillegg = '';
try {
    require_once __DIR__ . '/api/_boot.php';
    require_once APP_DIR . '/nett/nett.php';

    $boks = Deler::samtykke();

    // Variablene boksen og knappene bruker, med verdiene fra nett.css.
    $css = (string) @file_get_contents(__DIR__ . '/nett.css');
    preg_match_all('~var\(--([a-z0-9-]+)~', $boks, $brukt);
    $regler = [];
    foreach (array_unique($brukt[1]) as $navn) {
        if (preg_match('~--' . preg_quote($navn, '~') . ':([^;]+);~', $css, $m) === 1) {
            $regler[] = '--' . $navn . ':' . trim($m[1]);
        }
    }
    // Verdier som selv peker paa andre variabler (var(--x)) — ett nivaa til.
    foreach ($regler as $r) {
        preg_match_all('~var\(--([a-z0-9-]+)~', $r, $indre);
        foreach ($indre[1] as $navn) {
            if (!in_array($navn, $brukt[1], true) && preg_match('~--' . preg_quote($navn, '~') . ':([^;]+);~', $css, $m) === 1) {
                $regler[] = '--' . $navn . ':' . trim($m[1]);
            }
        }
    }

    $tillegg = '<style>:root{' . implode(';', array_unique($regler)) . '}'
        . '[data-nett-samtykke] button{font-family:"Alegreya Sans",sans-serif;cursor:pointer}</style>' . "\n"
        . $boks
        . '<script>window.lissomMaal = ' . Nett::maalJson() . ";\n"
        . (string) @file_get_contents(__DIR__ . '/nett.js') . "</script>\n";
} catch (Throwable $e) {
    // Uten base eller oppsett gaar guiden ut som foer — bare uten maaling.
    $tillegg = '';
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=300');
$pos = strripos($html, '</body>');
echo $pos === false ? $html . $tillegg : substr($html, 0, $pos) . $tillegg . substr($html, $pos);
