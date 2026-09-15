<?php
/**
 * Kurs og events — /kurs og /events, tegnet paa serveren.
 *
 * Portert fra skjermen «Kurs og events» i lissom-2108.html. Filtrene er
 * ekte adresser: /kurs?tema=Dreiing&tid=Kveldstid. Appen holdt dem i
 * tilstand; her er de i adressen, saa et filter kan deles og bokmerkes, og
 * tilbake-knappen i nettleseren gaar ett filter tilbake.
 *
 *   /kurs            «Kursene»: kursene uten events og Paint on Pots
 *   /events          «Events»
 *   ?tema=Vis alle | Dreiing | Håndbygging | Events
 *   ?tid=Dagtid | Kveldstid | Helg
 */

declare(strict_types=1);

$e = [Nett::class, 'e'];
$innh = [Nett::class, 'innh'];
$sp = Nett::sporring();
$erEvents = Nett::$adresse === '/events';

// Kategorien — kursFilter i nettsida.
$kategorier = ['Vis alle', 'Dreiing', 'Håndbygging', 'Events'];
$valgt = isset($sp['tema']) && in_array($sp['tema'], $kategorier, true) ? $sp['tema'] : ($erEvents ? 'Events' : 'Kursene');
$erAlle = $valgt === 'Vis alle';
// Naar paa dagen — kursTid i nettsida.
$tider = ['Dagtid', 'Kveldstid', 'Helg'];
$naar = isset($sp['tid']) && in_array($sp['tid'], $tider, true) ? $sp['tid'] : null;

$base = $erEvents ? '/events' : '/kurs';
$lenke = static function (?string $tema, ?string $tid) use ($base, $erEvents): string {
    $q = [];
    // Events-sida uten tema er Events; kurssida uten tema er «Kursene».
    if ($tema !== null && !($erEvents && $tema === 'Events') && !(!$erEvents && $tema === 'Kursene')) {
        $q['tema'] = $tema;
    }
    if ($tid !== null) {
        $q['tid'] = $tid;
    }
    return $base . ($q !== [] ? '?' . http_build_query($q) : '');
};

$alle = Kort::kurs();
$vist = Kort::filtrert($valgt, $naar);

$tittel = ['Kurs' => 'Våre kurs', 'Events' => 'Date Night, Paint on Pots og Sip & Clay', 'Dreiing' => 'Dreiekurs', 'Håndbygging' => 'Plateteknikk og håndbygging'][$valgt] ?? 'Dreiekurs, plateteknikk og håndbygging';

$h = '<div role="main" data-screen-label="Kurs og events">' . "\n";
$h .= Deler::topp($valgt === 'Events' ? 'Events' : 'Kurs');

// Tag i ds-bundle.js, som lenke.
$tag = static function (string $navn, bool $valgt, string $href) use ($e): string {
    return '<a href="' . $e($href) . '" style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: var(--radius-pill); font-family: var(--font-sans); font-size: var(--text-sm); font-weight: 500; cursor: pointer; text-decoration: none; border: 1px solid '
        . ($valgt ? 'var(--lissom-brown)' : 'var(--border-default)') . '; background: ' . ($valgt ? 'var(--lissom-brown)' : 'transparent') . '; color: ' . ($valgt ? 'var(--clay-50)' : 'var(--text-body)')
        . '; transform: none; transition: var(--transition-base);"' . ($valgt ? ' aria-current="true"' : ' data-hover="border-color: var(--lissom-brown); background: var(--clay-100); color: var(--lissom-brown); transform: translateY(-1px);"') . '>' . $e($navn) . '</a>';
};

$h .= '<section style="background: var(--clay-50); padding: var(--section-y) var(--space-8) var(--space-10);">'
    . '<div style="max-width: var(--width-content); margin: 0 auto;">'
    . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($innh('Kurs og events/0/Kicker')) . '</div>'
    . '<h1 style="margin: 0 0 var(--space-4); font-size: var(--text-5xl);">' . $e($tittel) . '</h1>'
    . '<p style="margin: 0 0 var(--space-8); color: var(--text-body); font-size: var(--text-lg); max-width: 52ch; text-wrap: pretty;">' . $e($innh('Kurs og events/0/Ingress')) . '</p>';
// Naar paa dagen.
$h .= '<div style="margin-bottom: var(--space-5);">'
    . '<div style="font-size: var(--text-sm); font-weight: 600; color: var(--text-heading); margin-bottom: 8px;">Når på dagen passer det best for deg?</div>'
    . '<div style="display: flex; gap: 8px; flex-wrap: wrap;">';
foreach ([null, 'Dagtid', 'Kveldstid', 'Helg'] as $t) {
    $paa = $naar === $t;
    // Trykk paa den som er valgt, tar den bort — som i appen.
    $h .= $tag($t ?? 'Når som helst', $paa, $lenke($valgt, $paa ? null : $t));
}
$h .= '</div></div>';
// Kategoriene: de som har noe aa vise, pluss den som er valgt.
$finnes = static function (string $navn) use ($alle, $naar): bool {
    $ut = Kort::iKategori($alle, $navn);
    if ($naar !== null) {
        $ut = array_filter(array_map(static fn(array $k): ?array => Kort::medValgtTid($k, $naar), $ut));
    }
    return $ut !== [];
};
$h .= '<div style="display: flex; gap: 8px; flex-wrap: wrap;">';
foreach ($kategorier as $navn) {
    if (!($navn === 'Vis alle' || $navn === $valgt || $finnes($navn))) {
        continue;
    }
    $paa = $navn === $valgt || ($navn === 'Vis alle' && $erAlle);
    $h .= $tag($navn, $paa, $lenke($navn, $naar));
}
$h .= '</div>';
if ($vist === []) {
    $tom = $naar !== null
        ? 'Ingen kurs ' . (['Dagtid' => 'på dagtid', 'Kveldstid' => 'på kveldstid', 'Helg' => 'i helgen'][$naar] ?? '') . ' akkurat nå.'
        : 'Ingen kurs i denne kategorien akkurat nå.';
    $h .= '<div style="margin-top: var(--space-6); display: flex; gap: var(--space-4); align-items: center; flex-wrap: wrap;">'
        . '<span style="font-size: var(--text-base); color: var(--text-body);">' . $e($tom) . '</span>'
        . '<a href="' . $e($lenke('Vis alle', null)) . '" style="appearance: none; background: transparent; border: none; cursor: pointer; padding: 0; font: var(--type-body); font-weight: 700; color: var(--lissom-brown); text-decoration: underline; text-underline-offset: 3px;">Vis alle kurs</a>'
        . '</div>';
}
$dv = $innh('Kurs og events/0/Detalj venstre');
$dh = $innh('Kurs og events/0/Detalj høyre');
if ($dv !== '' || $dh !== '') {
    $h .= '<div style="margin-top: var(--space-6); font-size: var(--text-sm); color: var(--text-muted); display: flex; gap: var(--space-2) var(--space-3); flex-wrap: wrap; align-items: center;">'
        . '<span>' . $e($dv) . '</span>' . ($dv !== '' && $dh !== '' ? '<span aria-hidden="true">·</span>' : '') . '<span>' . $e($dh) . '</span></div>';
}
$h .= '</div></section>' . "\n";

// Kortene.
$h .= '<section id="kursliste" style="background: var(--clay-50); padding: 0 var(--space-8) var(--space-12);">'
    . '<div class="lx-kortgrid" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-8);">';
$hode = '';
foreach ($vist as $i => $k) {
    if ($i === 0 && $k['image'] !== '') {
        $ss = Nett::srcset($k['image']);
        $hode = '<link rel="preload" as="image" href="' . $e($k['image']) . '"' . ($ss !== '' ? ' imagesrcset="' . $e($ss) . '" imagesizes="' . Nett::SIZES_KORT . '"' : '') . '>' . "\n";
    }
    $h .= Deler::kurskort($k + ['eager' => $i === 0]);
}
$h .= '</div></section>' . "\n";

// Velkommen.
$h .= '<section style="background: var(--clay-50); padding: 0 var(--space-8) var(--space-12);">'
    . '<div class="lx-cols2" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-10); align-items: start; background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: var(--space-10);">'
    . '<div>'
    . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($innh('Kurs og events/2/Kicker')) . '</div>'
    . '<h2 style="margin: 0 0 var(--space-4); font-size: var(--text-3xl);">' . $e($innh('Kurs og events/2/Overskrift')) . '</h2>'
    . '<p style="margin: 0 0 var(--space-4); color: var(--text-body); line-height: 1.65; text-wrap: pretty;">Det handler om å senke skuldrene. Om å koble av fra en travel hverdag. Om å oppleve den gode følelsen av å være helt til stede i øyeblikket.</p>'
    . '<p style="margin: 0 0 var(--space-4); color: var(--text-body); line-height: 1.65; text-wrap: pretty;">Når hendene får arbeide med leiren, skjer det noe. Tankene roer seg, tiden går litt saktere, og du får rom til å være kreativ uten prestasjonspress. Mange blir overrasket over hvor avslappende det er. Andre oppdager en skaperglede de ikke visste at de hadde.</p>'
    . '<p style="margin: 0; color: var(--text-body); line-height: 1.65; text-wrap: pretty;">Enten du velger dreiekurs eller plateteknikk, trenger du ingen forkunnskaper. Vi hjelper deg hele veien, i et trygt og inkluderende miljø hvor det er lov å prøve, feile, lære og le.</p>'
    . '</div><div>'
    . '<p style="margin: 0 0 var(--space-4); color: var(--text-body); line-height: 1.65; text-wrap: pretty;">Hos oss møtes mennesker i alle aldre og med ulik erfaring. Noen kommer for å lære et håndverk. Andre kommer for å finne ro. Mange kommer tilbake fordi de finner begge deler.</p>'
    . '<p style="margin: 0 0 var(--space-4); color: var(--text-body); line-height: 1.65; text-wrap: pretty;">Du vil kjenne gleden når leiren tar form mellom hendene dine. Stoltheten når noe du har laget selv står ferdig. Og kanskje viktigst av alt: den sjeldne følelsen av å få være akkurat den du er.</p>'
    . '<p style="margin: 0 0 var(--space-4); color: var(--text-body); line-height: 1.65; text-wrap: pretty;">Lissom er et verksted for kreativitet, mestring og fellesskap. Et sted hvor du kan puste ut, slippe fantasien løs og skape noe helt unikt.</p>'
    . '<p style="margin: 0; color: var(--text-heading); font-weight: 700; line-height: 1.65;">Vi gleder oss til å ønske deg velkommen.</p>'
    . '</div></div></section>' . "\n";

// Introboksene.
$bokser = $valgt === 'Events' ? [
    ['Passer for', ['Venninnekvelder og par', 'Utdrikningslag og bursdager', 'Bedrifter og teambuilding']],
    ['Slik fungerer det', ['Kom som du er — ingen erfaring nødvendig', 'Alt utstyr og materialer er inkludert', 'Arbeidene brennes og hentes etter to til fire uker']],
    ['For grupper', ['Flere enn 8? Vi setter opp egen kveld', 'Send en uforpliktende forespørsel', 'Vi svarer som regel samme dag']],
] : [
    ['Passer for', ['Nybegynnere — ingen forkunnskaper', 'Deg som vil lære teknikk fra bunnen', 'Deg som vil gjøre noe kreativt, alene eller sammen']],
    ['Hva får du', ['Tett veiledning i liten gruppe', 'Alt utstyr, leire, glasur og brenning', 'Noe du har laget selv — med hjem']],
    ['Finn kurset som passer', ['Hvert kurs viser nivå, varighet og innhold', 'Se hva som er inkludert før du booker', 'Usikker? Send oss en forespørsel nederst']],
];
$h .= '<section style="background: var(--clay-50); padding: 0 var(--space-8) var(--space-12);">'
    . '<div class="lx-cols4" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-6);">';
foreach ($bokser as [$bt, $punkter]) {
    $h .= '<div style="background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: var(--space-6);">'
        . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($bt) . '</div>'
        . '<div style="display: flex; flex-direction: column; gap: 8px;">';
    foreach ($punkter as $p) {
        $h .= '<div style="display: flex; gap: 10px; align-items: flex-start; font-size: var(--text-sm); color: var(--text-body);"><span style="color: var(--sage-600); font-weight: 700; flex: 0 0 auto;">✓</span><span>' . $e($p) . '</span></div>';
    }
    $h .= '</div></div>';
}
$h .= '</div></section>' . "\n";

// Ordensregler og HMS.
$regler = [
    'Du får låne forkle av oss, men regn med å bli litt skitten.',
    'Ta av ringer og armbånd, og sett opp langt hår før du setter deg ved dreieskiva.',
    'Husk å bruke en fuktig klut, så du unngår at støvet virvler opp. Aldri kost eller blås bort leirestøv — det kan inneholde kvarts.',
    'Unngå sliping og pussing av leire. Er det mye støv i lokalet, bruk støvmaske.',
    'Ovnen betjenes kun av oss. Hold en meters avstand når den er varm.',
    'Rydd og vask plassen din før du går.',
];
$h .= '<section data-theme="sun" style="background: var(--lissom-yellow); padding: var(--section-y) var(--space-8); position: relative; overflow: hidden;">'
    . '<img src="mark-cup.svg" alt="" aria-hidden="true" style="position: absolute; right: -110px; bottom: -180px; width: 520px; opacity: 0.1; pointer-events: none;" loading="lazy" decoding="async" width="192" height="112">'
    . '<div class="lx-split" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: 1fr 1.5fr; gap: var(--space-16); align-items: start; position: relative;">'
    . '<div><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--brown-500); margin-bottom: var(--space-3);">Før du kommer</div>'
    . '<h2 style="color: var(--lissom-brown); margin: 0 0 var(--space-5); font-size: var(--text-4xl);">Ordensregler og HMS</h2>'
    . '<p style="margin: 0; color: var(--brown-500); font-size: var(--text-base); text-wrap: pretty;">Gjelder for alle kurs og events. Vi går gjennom det samme når du kommer, så du trenger ikke pugge noe.</p></div>'
    . '<div style="display: flex; flex-direction: column; gap: var(--space-4);">';
foreach ($regler as $r) {
    $h .= '<div style="display: flex; gap: var(--space-4); align-items: flex-start; border-top: 1px solid rgba(77,29,18,.2); padding-top: var(--space-4);"><span style="width: 7px; height: 7px; border-radius: 50%; background: var(--lissom-brown); margin-top: 9px; flex: 0 0 auto;"></span><span style="font-size: var(--text-base); color: var(--lissom-brown); text-wrap: pretty;">' . $e($r) . '</span></div>';
}
$h .= '</div></div></section>' . "\n";

$h .= '</div>' . "\n";
$h .= Deler::bunn(true);

return [
    'kropp' => $h,
    'aktiv' => $valgt === 'Events' ? 'Events' : 'Kurs',
    'hode'  => $hode,
];
