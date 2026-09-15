<?php
/**
 * Nyheter og guider — /nyheter og /nyheter/<slug>, tegnet paa serveren.
 *
 * Portert fra skjermen «Nyheter» i lissom-2108.html: lista med kortene, og
 * artikkelen i full lengde under toppen naar en er aapen. Kategorifilteret
 * er en adresse (/nyheter?kategori=Kurs). Teksten tegnes som parseInnhold()
 * i nettsida: «# » mellomtittel, «## » undertittel, «- » punkt, «|» tabell,
 * linje som slutter med «:» er en etikett, tom linje skiller avsnitt.
 * Bildene i artikkelen ligger etter avsnittet de peker paa (artikkel_bilder).
 *
 * Returnerer null naar artikkelen ikke finnes — appen svarer 404 som foer.
 */

declare(strict_types=1);

$e = [Nett::class, 'e'];
$slug = preg_match('~^/nyheter/([a-z0-9\-]+)$~i', Nett::$adresse, $m) === 1 ? $m[1] : '';

Artikler::publiserForfalte();
$rader = DB::alle("SELECT * FROM articles WHERE status = 'publisert' ORDER BY sortering, id DESC");
$dato = static fn(array $a): string => (string) ($a['dato'] ?: Booking::norskDatoKort((string) $a['updated_at']));

$lest = null;
if ($slug !== '') {
    foreach ($rader as $a) {
        if ((string) $a['slug'] === $slug) {
            $lest = $a;
            break;
        }
    }
    if ($lest === null) {
        return null;
    }
}

$kategorier = array_values(array_filter(array_unique(array_map(static fn(array $a): string => trim((string) $a['kategori']), $rader))));
$sp = Nett::sporring();
$filter = isset($sp['kategori']) && in_array($sp['kategori'], $kategorier, true) ? $sp['kategori'] : 'Alle';
$liste = array_values(array_filter($rader, static fn(array $a): bool => $filter === 'Alle' || trim((string) $a['kategori']) === $filter));

$h = '<div role="main" data-screen-label="Nyheter">' . "\n";
$h .= Deler::topp('');
$h .= '<section style="background: var(--clay-50); padding: var(--section-y) var(--space-8);">'
    . '<div style="max-width: var(--width-content); margin: 0 auto;">'
    . '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">Fra verkstedet</div>'
    . '<h1 style="margin: 0 0 var(--space-5); font-size: var(--text-5xl);">Nyheter og guider</h1>'
    . '<p style="margin: 0 0 var(--space-8); color: var(--text-body); font-size: var(--text-lg); max-width: 60ch; text-wrap: pretty;">Det vi skriver om leire, brenning, glasur og livet i verkstedet.</p>';
if ($kategorier !== []) {
    $h .= '<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: var(--space-8);">';
    foreach (array_merge(['Alle'], $kategorier) as $navn) {
        $paa = $filter === $navn;
        $h .= '<a href="' . $e('/nyheter' . ($navn === 'Alle' ? '' : '?kategori=' . rawurlencode($navn))) . '" style="appearance: none; cursor: pointer; border-radius: var(--radius-pill); padding: 9px 16px; font: var(--type-body-sm); font-weight: 600; text-decoration: none; border: 1px solid '
            . ($paa ? 'var(--lissom-brown)' : 'var(--border-subtle)') . '; background: ' . ($paa ? 'var(--lissom-brown)' : 'var(--surface-card)') . '; color: ' . ($paa ? 'var(--clay-50)' : 'var(--text-heading)') . ';"' . ($paa ? ' aria-current="true"' : '') . '>' . $e($navn) . '</a>';
    }
    $h .= '</div>';
}
if ($lest === null && $liste !== []) {
    $h .= '<div class="lx-cols4" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-6);">';
    foreach ($liste as $a) {
        $href = '/nyheter/' . rawurlencode((string) $a['slug']);
        $h .= '<a href="' . $e($href) . '" style="appearance: none; cursor: pointer; text-align: left; background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 0; overflow: hidden; display: flex; flex-direction: column; text-decoration: none; color: inherit;">'
            . ((string) ($a['bilde'] ?? '') !== '' ? '<div style="height: 180px; background-image: ' . Nett::cssUrl((string) $a['bilde']) . '; background-size: cover; background-position: center;"></div>' : '')
            . '<div style="padding: var(--space-6);">'
            . (trim((string) $a['kategori']) !== '' ? '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: 6px;">' . $e(trim((string) $a['kategori'])) . '</div>' : '')
            . '<div style="font-family: var(--font-display); font-weight: 700; font-size: var(--text-xl); color: var(--text-heading); line-height: 1.25; text-wrap: balance;">' . $e((string) $a['tittel']) . '</div>'
            . '<p style="margin: var(--space-3) 0 0; font-size: var(--text-sm); color: var(--text-body); text-wrap: pretty;">' . $e((string) ($a['ingress'] ?? '')) . '</p>'
            . '<div style="font-size: var(--text-xs); color: var(--text-muted); margin-top: var(--space-4);">' . $e($dato($a)) . '</div>'
            . '</div></a>';
    }
    $h .= '</div>';
} elseif ($lest === null) {
    $h .= '<div style="background: var(--surface-card); border: 1px dashed var(--lissom-brown); border-radius: var(--radius-lg); padding: var(--space-12); text-align: center;">'
        . '<img src="mark-cup.svg" alt="" aria-hidden="true" style="width: 56px; margin: 0 auto var(--space-5); display: block; opacity: .6;" loading="lazy" decoding="async" width="192" height="112">'
        . '<div style="font-family: var(--font-display); font-weight: 700; font-size: var(--text-xl); color: var(--text-heading); margin-bottom: var(--space-2);">Ingenting her ennå</div>'
        . '<p style="margin: 0; font-size: var(--text-base); color: var(--text-muted);">Vi skriver på noe. Kom tilbake om litt.</p></div>';
}
$h .= '</div></section>' . "\n";

$hode = '';
if ($lest !== null) {
    $bilde = (string) ($lest['bilde'] ?? '');
    $bildeTekst = (string) ($lest['bilde_tekst'] ?? '');
    $bildeAlt = (string) ($lest['bilde_alt'] ?? '') ?: ($bildeTekst ?: ((string) $lest['tittel'] ?: 'Bilde til artikkelen'));
    $h .= '<section style="background: var(--surface-card); padding: 0 var(--space-8) var(--section-y);"><div style="max-width: 720px; margin: 0 auto;">';
    if (trim((string) $lest['kategori']) !== '') {
        $h .= '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">' . $e(trim((string) $lest['kategori'])) . '</div>';
    }
    $h .= '<h1 style="margin: 0 0 var(--space-4); font-size: var(--text-4xl); text-wrap: balance;">' . $e((string) $lest['tittel']) . '</h1>'
        . '<div style="font-size: var(--text-sm); color: var(--text-muted); margin-bottom: var(--space-6);">' . $e($dato($lest)) . '</div>';
    if ($bilde !== '') {
        $ss = Nett::srcset($bilde);
        $hode = '<link rel="preload" as="image" fetchpriority="high" href="' . $e($bilde) . '"' . ($ss !== '' ? ' imagesrcset="' . $e($ss) . '" imagesizes="' . Nett::SIZES_STOR . '"' : '') . '>' . "\n";
        $h .= '<figure style="margin: 0 0 var(--space-8);"><img src="' . $e($bilde) . '"' . ($ss !== '' ? ' srcset="' . $e($ss) . '" sizes="' . Nett::SIZES_STOR . '"' : '') . ' alt="' . $e($bildeAlt) . '" style="width: 100%; max-height: 460px; object-fit: cover; object-position: ' . $e(Nett::fokus($bilde)) . '; border-radius: var(--radius-lg); display: block;" decoding="async">'
            . ($bildeTekst !== '' ? '<figcaption style="margin: 10px 0 0; font-size: var(--text-sm); line-height: 1.5; color: var(--text-muted); text-wrap: pretty;">' . $e($bildeTekst) . '</figcaption>' : '')
            . '</figure>';
    }
    $h .= Nett::artikkelBlokker((string) $lest['innhold'], Artikler::bilder((int) $lest['id']));
    $h .= '<div style="clear: both;"></div></div></section>' . "\n";
}

$h .= '</div>' . "\n";
$h .= Deler::bunn(true);

return [
    'kropp' => $h,
    'aktiv' => '',
    'hode'  => $hode,
];
