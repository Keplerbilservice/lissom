<?php
/**
 * Butikken — /butikk og /butikk/<id>-<navn>, tegnet paa serveren.
 *
 * Butikken var den siste kundesida som ikke ble tegnet her. Varen fikk
 * tittel, beskrivelse og canonical i hodet fra side.php, men selve sida var
 * app-skallet paa 1,2 MB: innholdet kom foerst naar skriptet hadde kjort og
 * hentet /api/butikk.php. Til en robot som ikke venter paa det, sto sida med
 * et tomt <h1>{{ bTittel }}</h1> fra bookingskjermen og lenker som
 * href="{{ l.url }}".
 *
 * Maalt i Search Console 23. september 2026: alle tjue varene sto som
 * «Oppdaget – ikke indeksert». Ingen av dem var i soket.
 *
 * Her staar varen i HTML-en — navn, bilde, pris, beskrivelse, kategori og
 * lagerstatus — som paa kurssidene.
 *
 * Kurven bor i appen, og nett.js har ingen. Derfor gaar «Legg i kurv» til
 * den samme adressen med ?vare=1: Nett::kan() sier nei til den, appen
 * overtar, og varen aapner seg slik den alltid har gjort.
 *
 *   /butikk                    alle varene
 *   /butikk?kategori=Kopper    ett utvalg — filteret er en ekte adresse
 *   /butikk/26-vase            varen, med butikken under
 */

declare(strict_types=1);

$e = [Nett::class, 'e'];
$innh = [Nett::class, 'innh'];
$sp = Nett::sporring();

// Testadressen mens sida vises fram. Lenkene paa sida foelger den man kom
// inn paa, saa hele butikken kan gaas gjennom uten aa bytte utgave midtveis.
$base = str_starts_with(Nett::$adresse, '/butikk-ny') ? '/butikk-ny' : '/butikk';
$vareId = Nett::$vareId;

// Medlemsvarene — leire, ekstra brenning — er verkstedets interne hylle.
// De skal ikke ha en side i soket. Samme grense som api/butikk.php setter.
$varer = DB::alle(
    "SELECT id, tittel, beskrivelse, bilde, kategori, pris_ore, lager
       FROM products
      WHERE status = 'publisert' AND kun_medlemmer = 0
      ORDER BY kategori, tittel"
);

$bildeFor = static fn(array $v): string => (string) ($v['bilde'] ?: 'uploads_butikken.jpg');
$utsolgt  = static fn(array $v): bool => $v['lager'] !== null && (int) $v['lager'] <= 0;
$adressen = static function (array $v) use ($base): string {
    $sti = Lenker::vare((int) $v['id'], (string) $v['tittel']);
    return $base === '/butikk' ? $sti : $base . substr($sti, strlen('/butikk'));
};

// Varen som er valgt, om adressen er en vares egen.
$valgt = null;
foreach ($varer as $v) {
    if ((int) $v['id'] === $vareId) {
        $valgt = $v;
        break;
    }
}

// Kategoriene som faktisk har noe i seg. Filteret er en adresse, ikke en
// tilstand, saa et utvalg kan deles og bokmerkes — som paa kurssida.
$kategorier = [];
foreach ($varer as $v) {
    $k = trim((string) ($v['kategori'] ?? ''));
    if ($k !== '' && !in_array($k, $kategorier, true)) {
        $kategorier[] = $k;
    }
}
sort($kategorier);
$filter = isset($sp['kategori']) && in_array($sp['kategori'], $kategorier, true) ? $sp['kategori'] : null;
$vist = $filter === null ? $varer : array_values(array_filter(
    $varer,
    static fn(array $v): bool => trim((string) ($v['kategori'] ?? '')) === $filter
));

$h = '<div role="main" data-screen-label="Butikk">' . "\n";
$h .= Deler::topp('Butikk');

// Pille som lenke — samme som Tag i ds-bundle.js, og samme som kurssida.
$tag = static function (string $navn, bool $paa, string $href) use ($e): string {
    return '<a href="' . $e($href) . '" style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: var(--radius-pill); font-family: var(--font-sans); font-size: var(--text-sm); font-weight: 500; cursor: pointer; text-decoration: none; border: 1px solid '
        . ($paa ? 'var(--lissom-brown)' : 'var(--border-default)') . '; background: ' . ($paa ? 'var(--lissom-brown)' : 'transparent') . '; color: ' . ($paa ? 'var(--clay-50)' : 'var(--text-body)')
        . '; transform: none; transition: var(--transition-base);"' . ($paa ? ' aria-current="true"' : ' data-hover="border-color: var(--lissom-brown); background: var(--clay-100); color: var(--lissom-brown); transform: translateY(-1px);"') . '>' . $e($navn) . '</a>';
};

$hode = '';

// ── Varen, naar adressen er dens egen ──────────────────────────────────
if ($valgt !== null) {
    $navn  = (string) $valgt['tittel'];
    $bilde = $bildeFor($valgt);
    $kat   = trim((string) ($valgt['kategori'] ?? ''));
    $tomt  = $utsolgt($valgt);
    $alt   = $navn . ', håndlaget keramikk fra Lissom i Tønsberg';
    $ss    = Nett::srcset($bilde);
    // Bildet er LCP paa varesida.
    $hode  = '<link rel="preload" as="image" fetchpriority="high" href="' . $e($bilde) . '"'
        . ($ss !== '' ? ' imagesrcset="' . $e($ss) . '" imagesizes="' . Nett::SIZES_STOR . '"' : '') . '>' . "\n";
    // Merknaden sier noe om glasuren, ikke om levering — som i appen.
    $merknad = $kat === 'Matsikret' ? 'Matsikret glasur — tåler oppvaskmaskin'
        : ($kat === 'Kunstobjekt' ? 'Dekorglasur — ment som pynt, ikke til mat' : '');

    $h .= '<section style="background: var(--clay-50); padding: var(--section-y) var(--space-8) var(--space-10);">'
        . '<div class="lx-cols2" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-10); align-items: start;">';
    // Bildet.
    $h .= '<img src="' . $e($bilde) . '"' . ($ss !== '' ? ' srcset="' . $e($ss) . '" sizes="' . Nett::SIZES_STOR . '"' : '')
        . ' alt="' . $e($alt) . '" fetchpriority="high" decoding="async" width="700" height="700"'
        . ' style="width: 100%; aspect-ratio: 1 / 1; object-fit: cover; object-position: ' . $e(Nett::fokus($bilde)) . '; border-radius: var(--radius-lg); background: var(--clay-200);">';
    // Teksten.
    $h .= '<div>';
    if ($kat !== '') {
        $h .= '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($kat) . '</div>';
    }
    $h .= '<h1 style="margin: 0 0 var(--space-4); font-size: var(--text-4xl);">' . $e($navn) . '</h1>'
        . '<div style="font-family: var(--font-display); font-weight: 800; font-size: var(--text-2xl); color: var(--text-heading); margin-bottom: var(--space-5);">' . $e(Booking::kroner((int) $valgt['pris_ore'])) . '</div>';
    $tekst = trim((string) ($valgt['beskrivelse'] ?? ''));
    if ($tekst !== '') {
        $h .= '<p style="margin: 0 0 var(--space-5); font-size: var(--text-base); line-height: 1.65; color: var(--text-body); text-wrap: pretty;">' . $e($tekst) . '</p>';
    }
    $h .= '<div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: var(--space-6); font-size: var(--text-sm); color: var(--text-body);">';
    foreach (array_filter([
        $merknad,
        'Dreid for hånd i verkstedet på Teie — farge og form varierer litt fra bildet',
        'Hent i butikken på Teie, eller få den sendt. Betal med Vipps.',
    ]) as $linje) {
        $h .= '<div style="display: flex; gap: 10px; align-items: flex-start;"><span style="color: var(--sage-600); font-weight: 700;">✓</span><span>' . $e($linje) . '</span></div>';
    }
    $h .= '</div>';
    $h .= '<div style="display: flex; align-items: center; gap: var(--space-4); flex-wrap: wrap;">';
    // ?vare=1 gaar til appen, der kurven er.
    $h .= $tomt
        ? '<span style="font: var(--type-body); font-weight: 700; color: var(--text-muted);">Utsolgt</span>'
        : Deler::knapp('Legg i kurv', ['size' => 'lg', 'icon' => 'shopping-bag', 'href' => $adressen($valgt) . '?vare=1']);
    $h .= '<a href="' . $e($base . ($filter !== null ? '?' . http_build_query(['kategori' => $filter]) : '')) . '" style="appearance: none; background: transparent; border: none; cursor: pointer; padding: 0; font: var(--type-body-sm); font-weight: 600; color: var(--text-muted); text-decoration: underline; text-underline-offset: 3px;">Tilbake til butikken</a>';
    $h .= '</div></div></div></section>' . "\n";
}

// ── Butikken ───────────────────────────────────────────────────────────
$h .= '<section style="background: var(--clay-50); padding: ' . ($valgt !== null ? '0' : 'var(--section-y)') . ' var(--space-8) var(--space-10);">'
    . '<div style="max-width: var(--width-content); margin: 0 auto;">';
if ($valgt === null) {
    $h .= '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e($innh('Butikk/0/Kicker')) . '</div>'
        . '<h1 style="margin: 0 0 var(--space-4); font-size: var(--text-5xl);">' . $e($innh('Butikk/0/Overskrift')) . '</h1>'
        . '<p style="margin: 0 0 var(--space-8); color: var(--text-body); font-size: var(--text-lg); max-width: 52ch; text-wrap: pretty;">' . $e($innh('Butikk/0/Ingress')) . '</p>'
        . '<div class="lx-cols2" style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-6); margin-bottom: var(--space-8);">'
        . '<div style="background: var(--surface-card); border: 2px solid var(--lissom-brown); border-radius: var(--radius-lg); padding: var(--space-6);">'
        . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-2);">' . $e($innh('Butikk/1/Overskrift')) . '</div>'
        . '<p style="margin: 0; font-size: var(--text-sm); color: var(--text-body);">' . $e($innh('Butikk/1/Tekst')) . '</p>'
        . '</div></div>';
} else {
    $h .= '<h2 style="margin: 0 0 var(--space-6); font-size: var(--text-3xl);">Flere varer i butikken</h2>';
}
// Filtrene.
if ($kategorier !== []) {
    $h .= '<div style="display: flex; gap: 8px; flex-wrap: wrap;">' . $tag('Alt', $filter === null, $base);
    foreach ($kategorier as $k) {
        $h .= $tag($k, $filter === $k, $base . '?' . http_build_query(['kategori' => $k]));
    }
    $h .= '</div>';
}
$h .= '</div></section>' . "\n";

// Kortene.
$h .= '<section style="background: var(--clay-50); padding: var(--space-8) var(--space-8) var(--section-y);">'
    . '<div class="lx-kortgrid lx-varegrid" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-8);">';
foreach ($vist as $i => $v) {
    // Varen som alt staar oeverst skal ikke staa i lista under ogsaa.
    if ($valgt !== null && (int) $v['id'] === $vareId) {
        continue;
    }
    $bilde = $bildeFor($v);
    if ($valgt === null && $i === 0 && $hode === '') {
        $ss = Nett::srcset($bilde);
        $hode = '<link rel="preload" as="image" fetchpriority="high" href="' . $e($bilde) . '"'
            . ($ss !== '' ? ' imagesrcset="' . $e($ss) . '" imagesizes="' . Nett::SIZES_KORT . '"' : '') . '>' . "\n";
    }
    $h .= Deler::varekort([
        'title' => (string) $v['tittel'],
        'tekst' => (string) ($v['beskrivelse'] ?: 'Håndlaget i verkstedet på Teie.'),
        'pris'  => $utsolgt($v) ? 'Utsolgt' : Booking::kroner((int) $v['pris_ore']),
        'image' => $bilde,
        'fokus' => Nett::fokus($bilde),
        'cta'   => 'Se mer',
        'href'  => $adressen($v),
    ]);
}
$h .= '</div></section>' . "\n";

// Spesialbestillinger.
$h .= '<section data-theme="sun" style="background: var(--lissom-yellow); padding: var(--section-y) var(--space-8);">'
    . '<div class="lx-split" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: 1.1fr 1fr; gap: var(--space-12); align-items: start;">'
    . '<div><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--brown-500); margin-bottom: var(--space-4);">' . $e($innh('Butikk/3/Kicker')) . '</div>'
    . '<h2 style="color: var(--lissom-brown); margin: 0 0 var(--space-6); font-size: clamp(32px, 2.8vw, 48px); line-height: 1.05; text-wrap: balance;">Vil du gi noe <em style="font-style: italic; font-weight: 700;">helt eget?</em></h2>'
    . Deler::knapp($innh('Butikk/3/Knapp'), ['variant' => 'ink', 'size' => 'lg', 'iconAfter' => 'arrow-right', 'lenke' => true, 'href' => '/kontakt'])
    . '</div>'
    . '<div style="display: flex; flex-direction: column; gap: var(--space-5); font-size: var(--text-lg); line-height: 1.65; color: var(--brown-500);">'
    . '<p style="margin: 0; text-wrap: pretty;">Vi tar imot spesialbestillinger — et fat med navn og dato til bryllupet, en jubileumsgave, et servise. Fortell oss om anledningen, så foreslår vi noe som passer.</p>'
    . '</div></div></section>' . "\n";

// Praktisk info.
$pi = [
    [$innh('Butikk/4/Punkt 1') ?: 'Hent på Teie', 'Nordre Løkkevei 15, 3120 Nøtterøy.'],
    [$innh('Butikk/4/Punkt 2') ?: 'Betaling med Vipps', 'Du betaler i kassen på nett med Vipps.'],
    [$innh('Butikk/4/Punkt 3') ?: 'Hvert stykke er unikt', 'Alt er dreid for hånd, så farge og form varierer litt fra bildet.'],
];
$h .= '<section style="background: var(--clay-100); padding: var(--section-y) var(--space-8);">'
    . '<div class="lx-cols4" style="max-width: var(--width-content); margin: 0 auto; display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-8);">';
foreach ($pi as [$t, $x]) {
    $h .= '<div><h3 style="font-family: var(--font-display); font-weight: 700; font-size: var(--text-lg); color: var(--text-heading); margin: 0 0 var(--space-2);">' . $e($t) . '</h3>'
        . '<p style="margin: 0; font-size: var(--text-sm); color: var(--text-muted); text-wrap: pretty;">' . $e($x) . '</p></div>';
}
$h .= '</div></section>' . "\n";

$h .= '</div>' . "\n" . Deler::bunn(true);

return ['kropp' => $h, 'aktiv' => 'Butikk', 'hode' => $hode];
