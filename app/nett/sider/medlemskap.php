<?php
/**
 * Medlemskap — /medlemskap, tegnet paa serveren.
 *
 * Portert fra skjermen «Medlemskap» i lissom-2108.html. Planene kommer fra
 * Medlemskap::planer() — det samme /api/medlemskap.php gir appen. «Les mer»
 * paa et kort aapner planen i appen (/medlemskap?plan=<navn>), der
 * innmeldingen og Vipps-avtalen er.
 */

declare(strict_types=1);

$e = [Nett::class, 'e'];
$innh = [Nett::class, 'innh'];

// planer() i nettsida.
$BILDER = ['Prøv Lissom' => 'uploads_shutterstock_2829104351.jpg', '30 timer' => 'uploads_shutterstock_2829103797.jpg', 'Årsmedlemskap' => 'uploads_shutterstock_2830613711.jpg', 'Fri tilgang' => 'uploads_shutterstock_2829104157.jpg'];
$rekke = array_values($BILDER);
$planer = [];
foreach (Medlemskap::planer() as $i => $p) {
    $timer = $p['timer'] === null ? null : (int) $p['timer'];
    $timetekst = $timer === null ? 'Ingen timebegrensning' : $timer . ' timer i måneden';
    $engangs = (int) $p['engangs'] === 1;
    $punkter = Medlemskap::punkter($p['punkter'] ?? null);
    $planer[] = [
        'navn'   => (string) $p['navn'],
        'pris'   => Booking::kroner((int) $p['pris_ore']),
        'periode' => (string) (($p['undertekst'] ?? '') ?: $timetekst),
        'merke'  => (string) (($p['merke'] ?? '') ?: ($engangs ? 'Prøveperiode' : 'Medlemskap')),
        'beskrivelse' => (string) ($p['beskrivelse'] ?? ''),
        'punkter' => $punkter !== [] ? $punkter : [$timetekst, $engangs ? 'Kan kun benyttes én gang' : 'Tilgang 24/7 med dørkode'],
        'bilde'  => (string) (($p['bilde'] ?? '') ?: ($BILDER[$p['navn']] ?? $rekke[$i % count($rekke)])),
    ];
}

$h = '<div role="main" data-screen-label="Medlemskap">' . "\n";
$h .= Deler::topp('Medlemskap');
$h .= '<section style="background: var(--clay-50); padding: var(--section-y) var(--space-8) var(--space-12);"><div style="max-width: var(--width-content); margin: 0 auto;">'
    . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($innh('Medlemskap/0/Kicker')) . '</div>'
    . '<h1 style="margin: 0 0 var(--space-4); font-size: var(--text-5xl);">' . $e($innh('Medlemskap/0/Overskrift')) . '</h1>'
    . '<p style="margin: 0; color: var(--text-body); font-size: var(--text-lg); max-width: 54ch; text-wrap: pretty;">' . $e($innh('Medlemskap/0/Ingress')) . '</p></div></section>' . "\n";
$h .= '<section style="background: var(--clay-50); padding: 0 var(--space-8) var(--space-12);"><div class="lx-kortgrid" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-8); align-items: stretch;">';
foreach ($planer as $p) {
    $h .= Deler::kurskort(['level' => $p['merke'], 'title' => $p['navn'], 'text' => $p['beskrivelse'], 'price' => $p['pris'], 'duration' => $p['periode'], 'image' => $p['bilde'], 'cta' => 'Les mer', 'href' => '/medlemskap?plan=' . rawurlencode($p['navn'])]);
}
$h .= '</div></section>' . "\n";

$avsnitt = 'margin: 0 0 var(--space-4); color: var(--text-body); font-size: var(--text-base); line-height: var(--leading-relaxed, 1.65); text-wrap: pretty;';
$h .= '<section style="background: var(--clay-50); padding: 0 var(--space-8) var(--space-12);"><div class="lx-cols2" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-10); align-items: start;">'
    . '<div><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($innh('Medlemskap/1/Kicker')) . '</div>'
    . '<h2 style="margin: 0 0 var(--space-4); font-size: var(--text-3xl);">' . $e($innh('Medlemskap/1/Overskrift')) . '</h2>'
    . '<p style="' . $avsnitt . '">Et medlemskap hos Lissom gir deg mer enn bare tilgang til et verksted. Du blir en del av et kreativt, sosialt og faglig fellesskap hvor du kan utvikle deg videre i ditt eget tempo.</p>'
    . '<p style="' . $avsnitt . '">For mange er medlemskap faktisk rimeligere enn å bygge opp et eget keramikkverksted hjemme. Du slipper investeringer i utstyr, verktøy, brenning og plass, samtidig som du får tilgang til et fullt utstyrt verksted og et inspirerende miljø.</p>'
    . '<p style="' . $avsnitt . '">Mange opplever at de lærer minst like mye av samtalene rundt arbeidsbordet som av selve kursene. Som medlem blir du en del av et miljø hvor man inspirerer hverandre, deler erfaringer og utvikler seg sammen.</p>'
    . '<p style="margin: 0; color: var(--text-body); font-size: var(--text-base); line-height: var(--leading-relaxed, 1.65); text-wrap: pretty;">Enten du har gått kurs hos oss tidligere eller ønsker et sted å dyrke interessen din videre, er medlemskapet en flott måte å få mer tid med leire, flere kreative opplevelser og et fellesskap å høre til i.</p></div>'
    . '<div style="background: var(--surface-card); border: 2px solid var(--lissom-brown); border-radius: var(--radius-lg); padding: var(--space-8);">'
    . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-4);">' . $e($innh('Medlemskap/1/Overskrift, fordeler')) . '</div>'
    . '<div style="display: flex; flex-direction: column; gap: 12px;">';
foreach (['Tilgang til verksted og utstyr', 'Mulighet til å jobbe med egne prosjekter', 'Gratis veiledning fra oss ved behov', 'Læring og erfaringsutveksling med andre medlemmer', 'Tilgang til brenning og glasering etter gjeldende ordninger', 'Egen hylleplass til pågående arbeider', 'Et kreativt og sosialt miljø med mennesker som deler samme interesse'] as $f) {
    $h .= '<div style="display: flex; gap: 10px; align-items: flex-start; font-size: var(--text-base); line-height: 1.5; color: var(--text-body);"><span style="color: var(--sage-600); font-weight: 700; flex: 0 0 auto;">✓</span><span>' . $e($f) . '</span></div>';
}
$h .= '</div></div></div></section>' . "\n";

$h .= '<section style="background: var(--clay-50); padding: 0 var(--space-8) var(--space-12);"><div class="lx-cols2" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-6);">'
    . '<div style="background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: var(--space-6);"><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--sage-600); margin-bottom: var(--space-3);">' . $e($innh('Medlemskap/1/Overskrift, passer for')) . '</div><div style="display: flex; flex-direction: column; gap: 8px;">';
foreach (['Har tatt kurs og vil fortsette på egen hånd', 'Vil jobbe selvstendig med egne prosjekter', 'Ønsker fast tilgang til verksted, brenning og glasering', 'Vil bli del av et kreativt miljø — og kan selge gjennom Lissom'] as $p) {
    $h .= '<div style="display: flex; gap: 10px; align-items: flex-start; font-size: var(--text-sm); color: var(--text-body);"><span style="color: var(--sage-600); font-weight: 700; flex: 0 0 auto;">✓</span><span>' . $e($p) . '</span></div>';
}
$h .= '</div></div>'
    . '<div style="background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: var(--space-6);"><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($innh('Medlemskap/1/Overskrift, ikke for')) . '</div><div style="display: flex; flex-direction: column; gap: 8px;">';
foreach (['Aldri har jobbet med leire — ta nybegynnerkurset først', 'Bare vil prøve én kveld — da passer Paint on Pots bedre'] as $p) {
    $h .= '<div style="display: flex; gap: 10px; align-items: flex-start; font-size: var(--text-sm); color: var(--text-body);"><span style="color: var(--terracotta-600); font-weight: 700; flex: 0 0 auto;">✗</span><span>' . $e($p) . '</span></div>';
}
$h .= '<div style="margin-top: var(--space-2); font-size: var(--text-sm);"><a href="/kurs" style="appearance: none; background: transparent; border: none; padding: 0; cursor: pointer; font: inherit; color: var(--text-heading); font-weight: 700; text-decoration: underline; text-underline-offset: 3px;">' . $e($innh('Medlemskap/1/Lenke til kurs')) . '</a></div>'
    . '</div></div></div></section>' . "\n";

$h .= '<section style="background: var(--clay-50); padding: 0 var(--space-8) var(--section-y);"><div style="max-width: var(--width-content); margin: 0 auto;">'
    . '<h2 style="margin: 0 0 var(--space-8); font-size: var(--text-3xl);">' . $e($innh('Medlemskap/2/Overskrift')) . '</h2>'
    . '<div class="lx-cols4" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-8);">';
foreach ($planer as $p) {
    $h .= '<div><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-2);">' . $e($p['merke']) . '</div>'
        . '<h3 style="font-family: var(--font-display); font-weight: 700; font-size: var(--text-xl); color: var(--text-heading); margin: 0 0 var(--space-4);">' . $e($p['navn']) . ' · ' . $e($p['pris']) . '</h3>'
        . '<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px;">';
    foreach ($p['punkter'] as $pk) {
        $h .= '<li style="display: flex; gap: 10px; align-items: flex-start; font-size: var(--text-sm); color: var(--text-body);"><span style="width: 6px; height: 6px; border-radius: 50%; background: var(--terracotta-500); margin-top: 8px; flex: 0 0 auto;"></span><span>' . $e((string) $pk) . '</span></li>';
    }
    $h .= '</ul></div>';
}
$h .= '</div><div class="lx-cols4" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-6); margin-top: var(--space-10);">';
foreach ([['Én hylleplass', 'Hvert medlemskap inkluderer én hylle til arbeidene dine.'], ['Brenning følger planen', 'Vi brenner etter verkstedets brenningsplan, vanligvis ukentlig. Glasur og leire kjøpes i internbutikken.'], ['Verkstedets regler', 'Egne materialer og glasurer må godkjennes, og HMS- og ordensreglene gjelder alle medlemmer.']] as [$vt, $vx]) {
    $h .= '<div style="background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: var(--space-6);"><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-2);">Viktig å vite</div><h3 style="margin: 0 0 var(--space-2); font-family: var(--font-display); font-weight: 700; font-size: var(--text-base); color: var(--text-heading);">' . $e($vt) . '</h3><p style="margin: 0; font-size: var(--text-sm); line-height: 1.5; color: var(--text-body); text-wrap: pretty;">' . $e($vx) . '</p></div>';
}
$h .= '</div></div></section>' . "\n";

$h .= '<section style="background: var(--clay-100); padding: var(--section-y) var(--space-8);"><div style="max-width: var(--width-content); margin: 0 auto;">'
    . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($innh('Medlemskap/3/Kicker')) . '</div>'
    . '<h2 style="margin: 0 0 var(--space-10); max-width: 20ch;">' . $e($innh('Medlemskap/3/Overskrift')) . '</h2>'
    . '<div class="lx-cols4" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-6);">';
foreach ([['1', 'Stemple inn med QR', 'Skann koden ved døra, eller trykk «Stemple inn» i appen. Timen starter når du stempler.'], ['2', 'Se timene dine', 'Min side viser hvor mange timer du har igjen denne måneden, oppdatert i sanntid.'], ['3', 'Stemple ut', 'Trykk «Stemple ut» når du går. Glemmer du det, stopper telleren automatisk ved stengetid.'], ['4', 'Fornyes hver måned', 'Nye timer den 1. i måneden. Ubrukte timer overføres ikke.']] as [$nr, $st, $sx]) {
    $h .= '<div style="background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: var(--space-6);"><div style="font-family: var(--font-display); font-weight: 800; font-size: var(--text-2xl); color: var(--terracotta-500); margin-bottom: var(--space-3);">' . $nr . '</div><h3 style="font-family: var(--font-display); font-weight: 700; font-size: var(--text-lg); margin: 0 0 var(--space-2); color: var(--text-heading);">' . $e($st) . '</h3><p style="margin: 0; font-size: var(--text-sm); color: var(--text-muted); text-wrap: pretty;">' . $e($sx) . '</p></div>';
}
$h .= '</div></div></section>' . "\n";

$h .= '<section style="background: var(--clay-50); padding: var(--section-y) var(--space-8);"><div style="max-width: 820px; margin: 0 auto; text-align: center;">'
    . '<img src="mark-cup.svg" alt="" aria-hidden="true" style="width: 56px; margin: 0 auto var(--space-6); display: block; opacity: .85;" loading="lazy" decoding="async" width="192" height="112">';
$sitat = $innh('Medlemskap/4/Sitat');
if ($sitat !== '') {
    $h .= '<blockquote style="margin: 0 0 var(--space-5); font-family: var(--font-display); font-weight: 700; font-size: clamp(24px, 2.2vw, 34px); line-height: 1.35; color: var(--text-heading); text-wrap: pretty;">' . $e($sitat) . '</blockquote>'
        . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--text-muted);">' . $e($innh('Medlemskap/4/Avsender')) . '</div>';
}
$h .= '</div></section>' . "\n";
$h .= '</div>' . "\n";
$h .= Deler::bunn(true);

return ['kropp' => $h, 'aktiv' => 'Medlemskap'];
