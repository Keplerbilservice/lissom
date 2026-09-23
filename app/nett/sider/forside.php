<?php
/**
 * Forsida, tegnet paa serveren.
 *
 * Portert fra skjermen «Forside» i lissom-2108.html (sc-if erForside),
 * seksjon for seksjon, med de samme inline-stilene og de samme klassene
 * (lx-hero, lx-kortgrid, lx-hide-m, lx-kunmobil …) saa nett.css treffer.
 * Verdiene er de samme som renderVals regner i appen — se kommentaren ved
 * hver.
 *
 * Returnerer kroppen og hva som lyser i toppen. Nett::dokument() legger
 * hodet og skriptet rundt.
 */

declare(strict_types=1);

$e = [Nett::class, 'e'];
$innh = [Nett::class, 'innh'];
$lagret = Nett::lagret();

$h = '<div role="main" data-screen-label="Forside">' . "\n";
$h .= Deler::topp('Forside', true);

// ── Heroen ───────────────────────────────────────────────────────────────
$heroBilde = $innh('Forside/0/Hero-bilde');
$h .= '<section class="lx-hero" data-theme="sun" style="background: var(--lissom-yellow); position: relative; overflow: clip; display: grid; min-height: 82vh;">'
    . '<img src="mark-cup.svg" alt="" aria-hidden="true" style="position: absolute; left: -140px; bottom: -180px; width: 560px; opacity: 0.07; pointer-events: none;" loading="lazy" decoding="async" width="192" height="112">'
    . '<div class="lx-hjertelogo" aria-hidden="true" style="display: none; position: absolute; left: -320px; top: -40px; width: 900px; height: 870px; opacity: 0.12; pointer-events: none; transform: rotate(-8deg); -webkit-mask-image: linear-gradient(215deg, black 25%, transparent 80%); mask-image: linear-gradient(215deg, black 25%, transparent 80%);">'
    . '<div style="width: 100%; height: 100%; background: linear-gradient(160deg, var(--terracotta-500) 0%, var(--brown-500) 45%, var(--lissom-brown) 100%); -webkit-mask-image: url(\'uploads_logo-7df29c0f.png\'); mask-image: url(\'uploads_logo-7df29c0f.png\'); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center;"></div>'
    . '</div>'
    . '<div class="lx-hero-hjerte" style="position: absolute; top: -30%; bottom: -46%; right: -16%; aspect-ratio: 1.036;">'
    . '<div style="position: absolute; inset: 0; background-image: ' . Nett::cssUrl($heroBilde) . '; background-size: cover; background-position: center top; mask-image: url(\'heart-logo-mask.png\'); -webkit-mask-image: url(\'heart-logo-mask.png\'); mask-size: 100% 100%; -webkit-mask-size: 100% 100%; mask-repeat: no-repeat; -webkit-mask-repeat: no-repeat; mask-position: center; -webkit-mask-position: center;" role="img" aria-label="Keramiker former leire på dreieskiven"></div>'
    . '</div>'
    . '<div class="lx-hide-m lx-hero-grad" style="position: absolute; inset: 0; background: linear-gradient(100deg, var(--lissom-yellow) 38%, rgba(255,207,56,0.75) 54%, rgba(255,207,56,0.35) 72%, transparent 90%); pointer-events: none;"></div>'
    . '<div class="lx-hide-m lx-hero-grad" style="position: absolute; inset: 0; background: linear-gradient(to top left, var(--lissom-yellow) 8%, rgba(255,207,56,0.75) 22%, rgba(255,207,56,0.35) 40%, transparent 58%); pointer-events: none;"></div>'
    . '<div class="lx-hero-pad" style="position: relative; width: 100%; max-width: none; margin: 0; padding: 150px var(--space-8) 80px max(var(--space-10), calc((100vw - var(--width-content)) / 2)); align-self: safe center;">'
    . '<div style="font: var(--type-eyebrow); font-size: var(--text-lg); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--brown-500); margin-bottom: var(--space-8);">' . $e($innh('Forside/0/Kicker')) . '</div>'
    . '<h1 class="lx-hero-h1" data-nett-hero-h1 style="font-family: var(--font-display); font-weight: 800; font-size: clamp(40px, 4.6vw, 76px); line-height: 1.12; letter-spacing: var(--tracking-display); color: var(--lissom-brown); margin: 0 0 var(--space-16); max-width: none; text-wrap: balance; cursor: default;">'
    . $e($innh('Forside/0/Overskrift')) . ' <em style="font-style: italic; font-weight: 700;">' . $e($innh('Forside/0/Overskrift, kursiv del')) . '</em></h1>'
    . '<div style="max-width: 980px;">'
    . '<p class="lx-hero-p" style="font: var(--type-body); font-size: clamp(17px, 1.2vw, 21px); line-height: 1.55; color: var(--brown-500); margin: 0; text-wrap: pretty;">' . $e($innh('Forside/0/Brødtekst')) . '</p>';

// Knapp 2 gaar til Paint on Pots-bookingen naar det ligger datoer ute,
// ellers til datoene paa Paint on Pots-sida — popTilDatoer() i nettsida.
$popHref = '/paint-on-pots#pop-datoer';
foreach (Katalog::offentlig(false) as $k) {
    if ($k['tittel'] === 'Paint on Pots' && ($k['datoer'] ?? []) !== [] && ($k['slug'] ?? '') !== '') {
        $popHref = '/kurs/' . rawurlencode((string) $k['slug']);
    }
}
$h .= '<div class="lx-hero-cta" style="display: flex; gap: var(--space-5); margin-top: var(--space-8); flex-wrap: wrap; zoom: 1.15;">'
    . Deler::knapp($innh('Forside/0/Knapp 1'), ['href' => '/kurs', 'variant' => 'ink', 'size' => 'lg', 'iconAfter' => 'arrow-right'])
    . Deler::knapp($innh('Forside/0/Knapp 2'), ['href' => $popHref, 'variant' => 'secondary', 'size' => 'lg'])
    . '</div>';
$lenkeStil = 'appearance: none; background: transparent; border: none; padding: 0; cursor: pointer; font: inherit; color: var(--lissom-brown); font-weight: 700; text-decoration: underline; text-underline-offset: 3px;';
$h .= '<p class="lx-hero-p" style="margin: calc(var(--space-8) + 40px) 0 0; font-size: clamp(17px, 1.2vw, 21px); line-height: 1.5; color: var(--brown-500);">' . $e($innh('Forside/0/Gruppelinje'))
    // «Les mer» aapner forespoerselsskjemaet — som i appen (goForesporsel).
    // Her gikk lenka til /kontakt, saa den som ikke var innlogget (alle paa
    // mobil) fikk en annen side enn eieren saa paa PC. Eieren, 21. september
    // 2026: «aapner annerledes paa mobil, pc er fasit». Samme grep som
    // bedrift.php: ?skjema=1 gir adressen til appen, som aapner skjemaet.
    . ' <a href="/?skjema=1" style="' . $lenkeStil . '">' . $e($innh('Forside/0/Gruppelenke')) . '</a></p>';
if (Nett::bryterPaa('kursvelger') && !Nett::mobilSkjult('kursvelger')) {
    $h .= '<p class="lx-hero-p" style="margin: var(--space-6) 0 0; font-size: clamp(17px, 1.2vw, 21px); line-height: 1.5; color: var(--brown-500);">' . $e($innh('Forside/0/Kursvelgerlinje'))
        . ' <a href="/kurs#kursvelger" style="' . $lenkeStil . '">' . $e($innh('Forside/0/Kursvelgerlenke')) . '</a></p>';
}
$h .= '</div></div></section>' . "\n";

// ── Banneret under heroen ────────────────────────────────────────────────
// Alt i det settes i admin under Oversikt — bannerNaa() i nettsida.
if (($lagret['Banner/pa'] ?? '') !== 'nei' && !Nett::mobilSkjult('apenthus')) {
    $std = ['tittel' => 'Gi et gavekort', 'tekst' => 'Kan brukes på alle våre tjenester og produkter.', 'knapp' => 'Kjøp gavekort', 'lenke' => 'gavekort', 'bilde' => 'assets_photos_butikken.jpg'];
    $b = [];
    foreach ($std as $f => $v) {
        $lag = $lagret['Banner/' . $f] ?? '';
        $b[$f] = $lag === '' ? $v : $lag;
    }
    $maal = ['butikk' => '/butikk', 'gavekort' => '/gavekort', 'kurs' => '/kurs', 'events' => '/events', 'paintonpots' => '/paint-on-pots', 'medlemskap' => '/medlemskap', 'kontakt' => '/kontakt'];
    $h .= '<section data-theme="ink" style="background: var(--lissom-brown); padding: var(--space-8); position: relative; overflow: clip;">'
        . '<div class="lx-hide-m" style="position: absolute; top: 0; bottom: 0; right: 0; width: 52%; background-image: ' . Nett::cssUrl($b['bilde']) . '; background-size: cover; background-position: center 30%; mask-image: linear-gradient(to right, transparent 0%, black 60%); -webkit-mask-image: linear-gradient(to right, transparent 0%, black 60%); opacity: 0.85; pointer-events: none;"></div>'
        . '<div class="lx-band" style="max-width: var(--width-content); margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: var(--space-6) var(--space-10); flex-wrap: wrap; position: relative;">'
        . '<div style="min-width: 0;"><div style="margin-bottom: var(--space-2);">'
        . '<span class="lx-wrapm" style="font-family: var(--font-display); font-weight: 800; font-size: clamp(24px, 2vw, 34px); color: var(--clay-50);">' . $e($b['tittel']) . '</span></div>'
        . '<p style="margin: 0; font-size: var(--text-lg); color: var(--clay-300); text-wrap: pretty;">' . $e($b['tekst']) . '</p></div>'
        . Deler::knapp($b['knapp'], ['href' => $maal[$b['lenke']] ?? '/butikk', 'size' => 'lg', 'iconAfter' => 'arrow-right'])
        . '</div></section>' . "\n";
}

// ── Velg din inngang ─────────────────────────────────────────────────────
$h .= '<section style="background: var(--clay-50); padding: var(--section-y) var(--space-8) var(--space-12);">'
    . '<div style="max-width: var(--width-content); margin: 0 auto;">'
    . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($innh('Forside/1/Kicker')) . '</div>'
    . '<h2 style="margin: 0 0 var(--space-8);">' . $e($innh('Forside/1/Overskrift')) . '</h2>'
    . '<div class="lx-kortgrid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-8);">';
$innganger = [
    ['level' => '01 · Dreiekurs', 'title' => 'Dreiekurs', 'text' => 'To kvelder ved dreieskiva: sentrere, dreie, trimme foten og dekorere. Vi glaserer og brenner. Fra leireklump til ferdig kopp.', 'image' => 'assets_photos_dreiing-naerbilde.jpg', 'imageAlt' => 'Hender former en kopp på dreieskiva hos Lissom Keramikk', 'cta' => 'Se dreiekurs', 'href' => '/kurs'],
    ['level' => '02 · Plateteknikk og håndbygging', 'title' => 'Boller og store fat', 'text' => 'Kjevle ut leira, forme over form, få rene kanter. Én kveld: boller, et stort fat eller en fransk smørklokke.', 'image' => 'assets_photos_plateteknikk-fat.jpg', 'imageAlt' => 'Stort fat laget med plateteknikk hos Lissom Keramikk', 'cta' => 'Se kursene', 'href' => '/kurs'],
    ['level' => '03 · Paint on Pots', 'title' => 'Paint on Pots', 'text' => 'Velg en ferdigbrent kopp, skål eller figur og mal den slik du vil. Vi glaserer og brenner — klar til henting etter to til fire uker.', 'image' => 'uploads_b7012bbb-b81d-4c66-8614-030285ed4d5e.jpg', 'imageAlt' => 'Paint on Pots: ferdigbrent keramikk som males hos Lissom Keramikk', 'cta' => 'Les mer', 'href' => '/paint-on-pots'],
    ['level' => '04 · Medlemskap', 'title' => 'Medlemskap', 'text' => 'Egen hylle, dørkode døgnet rundt og timer i verkstedet hver måned. Prøv én måned uten binding.', 'image' => 'uploads_shutterstock_2829101499.jpg', 'imageAlt' => 'Medlem jobber ved dreieskiva i verkstedet hos Lissom', 'cta' => 'Se medlemskap', 'href' => '/medlemskap'],
];
foreach ($innganger as $k) {
    $h .= Deler::kurskort($k);
}
$h .= '</div></div></section>' . "\n";

// ── Kommende datoer ──────────────────────────────────────────────────────
$kort = Kort::kurs();
$h .= '<section style="background: var(--clay-100); padding: var(--section-y) var(--space-8);">'
    . '<div style="max-width: var(--width-content); margin: 0 auto;">'
    . '<div style="display: flex; align-items: flex-end; justify-content: space-between; gap: var(--space-8); flex-wrap: wrap; margin-bottom: var(--space-8);">'
    . '<div><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($innh('Forside/2/Kicker')) . '</div>'
    . '<h2 style="margin: 0;">' . $e($innh('Forside/2/Overskrift')) . '</h2></div>'
    . '<span class="lx-hide-m">' . Deler::knapp($innh('Forside/2/Knapp'), ['href' => '/kurs', 'variant' => 'secondary', 'size' => 'sm', 'iconAfter' => 'arrow-right']) . '</span>'
    . '</div>'
    . '<div class="lx-kortgrid lx-hide-m" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-8);">';
foreach (array_slice($kort, 0, 4) as $k) {
    $h .= Deler::kurskort($k);
}
$h .= '</div>';
// Paa mobil: én knapp i stedet for fire kort — kursTeller i nettsida.
$antall = count($kort);
$h .= '<div class="lx-kunmobil">'
    . '<p style="margin: 0 0 var(--space-5); font-size: var(--text-base); color: var(--text-body); text-wrap: pretty;">' . $e(Nett::kursTeller($kort)) . '</p>'
    . Deler::knapp($innh('Forside/2/Knapp på mobil'), ['href' => '/kurs', 'variant' => 'ink', 'iconAfter' => 'arrow-right', 'full' => true])
    . '</div></div></section>' . "\n";

// ── Referansekundene — feltet som bytter ────────────────────────────────
//
// Eieren, 20. september 2026: «i samme karusellen saa ligger det og andre
// ting, kan du soerge for at det kun er referansekunder i denne».
//
// Events og Medlemskap sto her ogsaa, som to faste kort foran kundene.
// Medlemskap staar fra foer som eget kort under «Velg din inngang», saa det
// mistet ingenting. Events sto bare her — og paa spoersmaalet om hvor det
// skulle: «ta Events helt av forsida». Det finnes fortsatt i toppmenyen og
// paa /events.
//
// Knappene fulgte de to faste. En kunde har ingen knapper — den har en logo,
// et sitat og en lenke til seg selv — saa de er borte fra markupen ogsaa.
$rot = [];
if (Nett::bryterPaa('referanser') && DB::harTabell('referansekunder')) {
    $logoFelt = DB::harKolonne('referansekunder', 'logo') ? 'logo,' : '';
    foreach (DB::alle("SELECT navn, bilde, {$logoFelt} tekst, sitat, sitat_av, lenke FROM referansekunder WHERE aktiv = 1 AND samtykke = 1 ORDER BY sortering, navn") as $r) {
        if ((string) $r['navn'] === '' || (string) ($r['bilde'] ?? '') === '') {
            continue;
        }
        $rot[] = ['merke' => (string) $r['navn'], 'stikktittel' => (string) $r['navn'],
            'tittel' => (string) ($r['sitat'] ?? '') !== '' ? '«' . $r['sitat'] . '»' : 'Laget for ' . $r['navn'],
            'tekst' => (string) ($r['tekst'] ?? ''), 'bilde' => (string) $r['bilde'], 'alt' => 'Keramikk laget for ' . $r['navn'],
            'logo' => (string) ($r['logo'] ?? ''), 'lenke' => (string) ($r['lenke'] ?? ''), 'lenkeTekst' => 'Se ' . $r['navn'] . ' →',
            'undertekst' => (string) ($r['sitat_av'] ?? '')];
    }
}
// Ingen kunder, ingen seksjon. Et tomt felt med en tom bilderamme er verre
// enn ingenting — og «$rot[0]» finnes ikke aa tegne det foerste kortet med.
if ($rot !== []) {
    $r0 = $rot[0];
    $h .= '<section class="lx-tettbunn" style="background: var(--clay-50); padding: var(--section-y) var(--space-8);">'
        . '<div class="lx-split" data-tone="rot" data-nett-rot="rot" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: 0.75fr 1.25fr; gap: var(--space-16); align-items: center; opacity: 1; transition: opacity 0.8s var(--ease-clay, ease); background: var(--clay-50);">'
        . '<img data-rot="bilde" src="' . $e($r0['bilde']) . '" alt="' . $e($r0['alt']) . '" style="width: 100%; height: 440px; object-fit: cover; border-radius: var(--radius-xl, 22px); display: block;" fetchpriority="high" decoding="async">'
        . '<div>'
        // Logoen til den foerste kunden staar fra foerste tegning. Den sto som
        // «display: none» og fikk bilde foerst naar nett.js byttet kort, 12 s
        // senere — eieren, 21. september 2026: «kepler logoen mangler».
        . '<div data-rot="logo" role="img"' . ((string) ($r0['logo'] ?? '') !== '' ? ' aria-label="' . $e('Logoen til ' . $r0['stikktittel']) . '"' : '') . ' style="display: ' . ((string) ($r0['logo'] ?? '') !== '' ? 'block' : 'none') . '; width: 132px; height: 60px; margin-bottom: var(--space-4); background-size: contain; background-repeat: no-repeat; background-position: left center; mix-blend-mode: multiply;' . ((string) ($r0['logo'] ?? '') !== '' ? ' background-image: ' . Nett::cssUrl((string) $r0['logo']) . ';' : '') . '"></div>'
        . '<div data-rot="stikktittel" style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($r0['stikktittel']) . '</div>'
        . '<h2 data-rot="tittel" style="margin: 0 0 var(--space-5);">' . $e($r0['tittel']) . '</h2>'
        . '<div data-rot="undertekst" style="display: none; margin: calc(var(--space-5) * -1) 0 var(--space-5); font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--text-muted);"></div>'
        . '<p data-rot="tekst" style="margin: 0 0 var(--space-4); font-size: var(--text-lg); line-height: 1.55; color: var(--text-body); max-width: 50ch; text-wrap: pretty;">' . $e($r0['tekst']) . '</p>'
        . '<a data-rot="lenke" href="#" target="_blank" rel="noopener noreferrer" style="display: none; margin-top: var(--space-4); font: var(--type-body-sm); font-weight: 700; color: var(--lissom-brown); text-decoration: underline; text-underline-offset: 3px;"></a>';
    if (count($rot) > 1) {
        $h .= '<div style="display: flex; gap: 10px; margin-top: var(--space-8);">' . Deler::prikker(array_column($rot, 'merke'), 'rot') . '</div>';
    }
    $h .= '</div></div></section>' . "\n";
}

// ── Salgskampanjen ───────────────────────────────────────────────────────
if (Nett::bryterPaa('salgsuke') && !Nett::mobilSkjult('salgsuke')) {
    $f = static function (string $n, ?string $gammel, string $standard) use ($lagret): string {
        $v = $lagret['Kampanje/' . $n] ?? '';
        if ($v !== '') { return $v; }
        $g = $gammel !== null ? ($lagret['Salgsuke/' . $gammel] ?? '') : '';
        return $g !== '' ? $g : $standard;
    };
    $periode = trim($lagret['Salgsuke/periode'] ?? '');
    $prisOre = (int) ($lagret['Kampanje/pris'] ?? 0);
    $bilde = $lagret['Kampanje/bilde'] ?? '';
    $maal = $lagret['Kampanje/maal'] ?? 'butikk';
    $h .= '<section data-theme="ink" style="background: var(--lissom-brown);">'
        . '<div class="lx-kampanje" style="max-width: var(--width-content); margin: 0 auto; display: flex; align-items: stretch; gap: 0;">'
        . '<div style="flex: 1 1 auto; min-width: 0; padding: var(--space-12) var(--space-8); display: flex; align-items: center; justify-content: space-between; gap: var(--space-6) var(--space-10); flex-wrap: wrap;">'
        . '<div style="min-width: 0; max-width: 72ch;">'
        . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--lissom-yellow); margin-bottom: var(--space-2);">' . $e($f('merke', null, $periode !== '' ? 'Salgsuke · ' . $periode : 'Salgsuke')) . '</div>'
        . '<h2 style="margin: 0 0 var(--space-3); color: var(--clay-50);">' . $e($f('tittel', 'tittel', 'Medlemmenes salgsuke')) . '</h2>'
        . '<p style="margin: 0; font-size: var(--text-lg); line-height: 1.55; color: var(--clay-200); text-wrap: pretty;">' . $e($f('tekst', 'tekst', 'I én uke selger vi medlemmenes håndlagde keramikk sammen i nettbutikken. Alt er laget i verkstedet på Teie.')) . '</p>'
        . ($prisOre > 0 ? '<div style="margin: var(--space-5) 0 0; font-family: var(--font-display); font-weight: 700; font-size: var(--text-3xl); color: var(--lissom-yellow);">' . $e('kr. ' . number_format((int) round($prisOre / 100), 0, ',', ' ') . ',-') . '</div>' : '')
        . '</div>'
        . Deler::knapp($f('knapp', null, 'Se medlemmenes keramikk'), ['href' => ['butikk' => '/butikk', 'medlemsbutikk' => '/butikk?kolleksjon=medlem', 'kurs' => '/kurs', 'events' => '/events', 'medlemskap' => '/medlemskap', 'gavekort' => '/gavekort'][$maal]
            // «kurs/<slug>»: rett til kurssida (api/admin/kampanjer.php).
            ?? (preg_match('~^kurs/([a-z0-9-]{1,120})$~', $maal, $mt) === 1 ? '/kurs/' . $mt[1] : '/butikk'), 'size' => 'lg', 'iconAfter' => 'arrow-right'])
        . '</div>'
        . ($bilde !== '' ? '<div class="lx-kampanjebilde" style="flex: 0 0 340px; align-self: stretch;"><img src="' . $e($bilde) . '" alt="" loading="lazy" decoding="async" style="width: 100%; height: 100%; object-fit: cover; display: block;"></div>' : '')
        . '</div></section>' . "\n";
}

// ── Butikken ─────────────────────────────────────────────────────────────
$produkter = Kort::forsideProdukter();
$h .= '<section class="lx-tetttopp" style="background: var(--clay-50); padding: var(--section-y) var(--space-8);">'
    . '<div style="max-width: var(--width-content); margin: 0 auto;">'
    . '<div style="display: flex; align-items: flex-end; justify-content: space-between; gap: var(--space-8); flex-wrap: wrap; margin-bottom: var(--space-8);">'
    . '<div><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($innh('Forside/3/Kicker')) . '</div>'
    . '<h2 style="margin: 0;">' . $e($innh('Forside/3/Overskrift')) . '</h2></div>'
    . Deler::knapp($innh('Forside/3/Knapp'), ['href' => '/butikk', 'variant' => 'secondary', 'size' => 'sm', 'iconAfter' => 'arrow-right'])
    . '</div>'
    . '<div class="lx-kortgrid lx-hide-m" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-8); align-items: stretch;">';
foreach ($produkter as $p) {
    $h .= Deler::varekort($p);
}
$h .= '</div>';
// Paa mobil: ett produkt om gangen, og de bytter av seg selv.
$h .= '<div class="lx-kunmobil"><div data-tone="but" data-nett-rot="but" style="opacity: 1; transition: opacity 0.8s var(--ease-clay, ease);">';
foreach ($produkter as $i => $p) {
    $h .= '<div data-but="' . $i . '"' . ($i > 0 ? ' hidden' : '') . '>' . Deler::varekort($p) . '</div>';
}
$h .= '</div><div style="display: flex; gap: 10px; margin-top: var(--space-5);">' . Deler::prikker(array_column($produkter, 'title'), 'but') . '</div></div>';
$h .= '</div></section>' . "\n";

$h .= '</div>' . "\n";
$h .= Deler::bunn(true);

// Rotasjonene til skriptet: tekstene i feltet som bytter. «knappA» og
// «knappB» staar ikke lenger her — en referansekunde har ingen knapper.
$rotJson = json_encode(array_map(static fn(array $r): array => [
    'stikktittel' => $r['stikktittel'], 'tittel' => $r['tittel'], 'tekst' => $r['tekst'], 'bilde' => $r['bilde'], 'alt' => $r['alt'],
    'logo' => $r['logo'] ?? '', 'lenke' => $r['lenke'] ?? '', 'lenkeTekst' => $r['lenkeTekst'] ?? '', 'undertekst' => $r['undertekst'] ?? '',
], $rot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

return [
    'kropp'  => $h,
    'aktiv'  => 'Forside',
    // Bildet i hjertet er det stoerste som tegnes paa PC (LCP). Det ligger
    // som bakgrunn i en div, saa nettleseren finner det foerst naar CSS-en
    // er lest — med mindre vi sier fra her. Bare paa PC: paa telefon er
    // hjertet skjult, og der skal ikke bildet lastes i det hele tatt.
    'hode'   => '<link rel="preload" as="image" fetchpriority="high" href="' . $e($heroBilde) . '" media="(min-width: 761px)">' . "\n",
    'skript' => 'window.lissomRot = ' . str_replace('</', '<\/', (string) $rotJson) . ';',
];
