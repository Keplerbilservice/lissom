<?php
/**
 * Nyttig info — /nyttig-info, tegnet paa serveren.
 *
 * Portert fra skjermen «Nyttig info» i lissom-2108.html: plakatene, guidene
 * (ferdige filer i guider/), artiklene uten kategori (api/nyttig.php) og
 * lenkene. Artiklene aapnet foer inne paa sida uten egen adresse; her gaar
 * kortet til /nyheter/<slug>, som tegner enhver publisert artikkel.
 */

declare(strict_types=1);

$e = [Nett::class, 'e'];
$lagret = Nett::lagret();

// Trivselsreglene: antallet staar paa kortet. Det eieren har lagret under
// Plakat/trivsel, ellers de ni faste — plakatPunkter() i nettsida.
$trivsel = 9;
$raa = $lagret['Plakat/trivsel'] ?? '';
if ($raa !== '') {
    $l = json_decode($raa, true);
    if (is_array($l) && $l !== []) {
        $trivsel = count($l);
    }
}

$kort = [
    ['Brennetabell', 'Cone til grader', 'Orton-coner omregnet til celsius og fahrenheit — verdiene vi styrer ovnene etter.', '/nyttig-info/brennetabell'],
    ['Medlemmer', 'Informasjon til medlemmer', 'Det viktigste å vite om brenning, glasur og oppbevaring i verkstedet.', '/nyttig-info/medlemsinfo'],
    ['Verkstedet', 'Trivselsregler', $trivsel . ' enkle regler før du går for dagen — og det viktigste av alt.', '/nyttig-info/trivselsregler'],
];
// Guidene — Component.GUIDER i nettsida. Ferdige filer i guider/.
foreach ([
    ['hvorfor-sprakk-den', 'Hvorfor sprakk den?', 'Feilsøking av sprekker og brudd – fra tørkehylle til ovn.'],
    ['matsikker-keramikk', 'Matsikker keramikk', 'Hva som kreves for at kopper og fat er trygge i bruk.'],
    ['glasurfeil', 'Glasurfeil', 'Renning, nålestikk, krakelering, avskalling – årsak og løsning.'],
    ['gjenvinning-av-leire', 'Gjenvinning av leire', 'Fra tørre rester til fullverdig leire.'],
    ['lage-slikker', 'Lage slikker', 'Flytende leire til liming, dekor og støping.'],
    ['torking-av-leire', 'Tørking av leire', 'Jevn og kontrollert tørk uten sprekker og skjevheter.'],
    ['vanlige-sporsmal', 'Keramikk – vanlige spørsmål', 'Leirens stadier, leirtyper, cones, glasur og brenning – litt om alt.'],
] as [$gs, $gn, $go]) {
    $kort[] = ['Guider fra verkstedet', $gn, $go, '/nyttig-info/' . $gs];
}

Artikler::publiserForfalte();
$artikler = DB::alle("SELECT * FROM articles WHERE status = 'publisert' AND (kategori IS NULL OR kategori = '') ORDER BY sortering, id DESC");
$lenker = DB::alle('SELECT * FROM links ORDER BY sortering, navn');

$kortStil = 'display: flex; flex-direction: column; min-height: 240px; box-sizing: border-box; background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-xl, 22px); padding: var(--space-6) var(--space-7); text-decoration: none; color: inherit; box-shadow: var(--shadow-sm); transition: box-shadow var(--duration-base) var(--ease-clay), transform var(--duration-base) var(--ease-clay);';
$hover = ' data-hover="box-shadow: var(--shadow-md); transform: translateY(-2px);"';

$h = '<div role="main" data-screen-label="Nyttig info">' . "\n";
$h .= Deler::topp('Nyttig info');
$h .= '<section style="background: var(--clay-50); padding: var(--section-y) var(--space-8) var(--space-10);"><div style="max-width: var(--width-content); margin: 0 auto;">'
    . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">Nyttig info</div>'
    . '<h1 class="lx-kunnskap-h1" style="margin: 0 0 var(--space-4); font-size: var(--text-5xl);">Kunnskapsbiblioteket</h1>'
    . '<p style="margin: 0; color: var(--text-body); font-size: var(--text-lg); max-width: 52ch; text-wrap: pretty;">Guider, tabeller og tips fra verkstedet — for medlemmer, kursdeltakere og alle som er nysgjerrige på keramikk.</p>'
    . '</div></section>' . "\n";
$h .= '<section style="background: var(--clay-50); padding: 0 var(--space-8) var(--section-y);"><div class="lx-kortgrid" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-8); align-items: start;">';
foreach ($kort as [$eyebrow, $navn, $om, $href]) {
    $h .= '<a href="' . $e($href) . '" style="' . $kortStil . '"' . $hover . '>'
        . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600);">' . $e($eyebrow) . '</div>'
        . '<h3 style="font-family: var(--font-display); font-weight: 700; font-size: var(--text-xl); color: var(--text-heading); margin: var(--space-2) 0;">' . $e($navn) . '</h3>'
        . '<p style="margin: 0 0 var(--space-4); font-size: var(--text-base); color: var(--text-muted); text-wrap: pretty;">' . $e($om) . '</p>'
        . '<span style="margin-top: auto; font-weight: 700; color: var(--lissom-brown); font-size: var(--text-base);">Åpne siden →</span></a>';
}
foreach ($artikler as $a) {
    $h .= Deler::kurskort([
        'level'    => (string) ($a['dato'] ?? ''),
        'title'    => (string) $a['tittel'],
        'text'     => (string) ($a['ingress'] ?? ''),
        'image'    => (string) ($a['bilde'] ?: 'assets_photos_butikken.jpg'),
        'imageAlt' => (string) ($a['bilde_alt'] ?? ''),
        'cta'      => 'Les artikkelen',
        'href'     => '/nyheter/' . rawurlencode((string) $a['slug']),
    ]);
}
foreach ($lenker as $l) {
    $h .= '<a href="' . $e((string) $l['url']) . '" target="_blank" rel="noopener" style="' . $kortStil . '"' . $hover . '>'
        . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600);">Ressurs</div>'
        . '<h3 style="font-family: var(--font-display); font-weight: 700; font-size: var(--text-xl); color: var(--text-heading); margin: var(--space-2) 0;">' . $e((string) $l['navn']) . ' ↗</h3>'
        . '<p style="margin: 0; font-size: var(--text-base); color: var(--text-muted); text-wrap: pretty;">' . $e((string) ($l['om'] ?? '')) . '</p></a>';
}
$h .= '</div></section>' . "\n";
$h .= '</div>' . "\n";
$h .= Deler::bunn(true);

return ['kropp' => $h, 'aktiv' => 'Nyttig info'];
