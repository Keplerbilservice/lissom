<?php
/**
 * Kurssida — /kurs/<slug>, tegnet paa serveren.
 *
 * Portert fra skjermen «Booking» i lissom-2108.html: venstre spalte er
 * kurset (bilde, beskrivelse, fakta, seksjonene fra kursoppsettet, merker,
 * samlinger, sitat), hoeyre spalte er boksen med pris og datoene.
 *
 * Selve bookingen — velge tid, antall, navn, Vipps, venteliste — er appen,
 * og skal vaere det. Datoene her er lenker: trykker du en dag, aapner appen
 * kurset med dagen valgt (/kurs/<slug>?dag=…), og resten gaar som foer.
 * Appen ligger alt i bufferen naar man kommer saa langt (nett.js henter
 * den naar sida har ro).
 *
 * Returnerer null naar kurset ikke finnes — da tar appen over og svarer
 * «finner ikke sida».
 */

declare(strict_types=1);

$e = [Nett::class, 'e'];
$innh = [Nett::class, 'innh'];
$slug = Nett::$slug;

$kat = null;
foreach (Katalog::offentlig(false) as $k) {
    if ((string) ($k['slug'] ?? '') === $slug) {
        $kat = $k;
        break;
    }
}
if ($kat === null) {
    return null;
}
// Kortet: nivaa-ord, tema, status, pris slik lista viser dem.
$kort = null;
foreach (Kort::kurs() as $k) {
    if ($k['slug'] === $slug) {
        $kort = $k;
        break;
    }
}
if ($kort === null) {
    // Kurset finnes, men vises ikke i lista (ingen datoer, ikke «vis uten
    // dato»). Appen tegner det som foer.
    return null;
}

$tittel = (string) $kat['tittel'];
$datoer = $kat['datoer'] ?? [];
$kunKontakt = !empty($kort['kunKontakt']);
$fullbooket = ($kort['status'] ?? '') === 'Fullbooket';
$gratis = ($kort['price'] ?? '') === 'Gratis';
$erEvent = ($kort['level'] ?? '') === 'Event' || str_starts_with((string) ($kort['level'] ?? ''), 'Event');

// ── Venstre spalte ────────────────────────────────────────────────────────
$bilder = array_values(array_filter((array) ($kat['bilder'] ?? [])));
if ($bilder === []) {
    $bilder = [(string) $kort['image']];
}
$bildeAlt = trim((string) ($kat['bildeAlt'] ?? '')) ?: $tittel;

$h = '<div role="main" data-screen-label="Booking">' . "\n";
$h .= Deler::topp($erEvent ? 'Events' : 'Kurs');
$h .= '<section style="background: var(--clay-50); padding: var(--space-10) var(--space-8) var(--section-y);">'
    . '<div style="max-width: var(--width-content); margin: 0 auto;">'
    . '<div class="lx-split" style="display: grid; grid-template-columns: 1.5fr 1fr; gap: var(--space-10); align-items: start;">'
    . '<div>';
// Bildet. Er det flere, ligger de oppaa hverandre og bytter (nett.js).
$h .= '<div role="img" aria-label="' . $e($bildeAlt) . '" data-nett-karusell="' . (int) ($kat['sekunder'] ?? 5) . '" style="position: relative; width: 100%; aspect-ratio: 16 / 10; border-radius: var(--radius-lg); overflow: hidden;">';
foreach ($bilder as $i => $b) {
    $h .= '<div style="position: absolute; inset: 0; background-image: ' . Nett::cssUrl($b) . '; background-size: cover; background-position: ' . $e(Nett::fokus($b, 'center 32%')) . '; opacity: ' . ($i === 0 ? 1 : 0) . '; transition: opacity 1.2s ease;"></div>';
}
$h .= '</div>';
// Hero-bildet er LCP paa denne sida; si fra i hodet.
$hode = '<link rel="preload" as="image" href="' . $e($bilder[0]) . '">' . "\n";

$nivaa = (string) ($kat['nivaaTekst'] ?: $kort['level']);
$h .= '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin: var(--space-8) 0 var(--space-3);">' . $e($nivaa) . '</div>'
    . '<h1 style="margin: 0 0 var(--space-5); font-size: var(--text-4xl);">' . $e($tittel) . '</h1>';

// Beskrivelsen: ingress og avsnitt, som bOmAvsnitt i nettsida.
$raa = trim((string) ($kat['om'] ?? '')) ?: trim((string) ($kat['kortBeskrivelse'] ?? '')) ?: trim((string) ($kat['laerer'] ?? ''));
$deler = array_values(array_filter(array_map('trim', preg_split('/\r?\n+/', $raa) ?: [])));
$ingress = '';
if (count($deler) > 1 && $tittel !== '') {
    $f0 = $deler[0];
    if (str_starts_with($f0, $tittel) && mb_strlen($f0) < 130 && preg_match('/[.!?]$/', $f0) !== 1) {
        $halen = trim((string) preg_replace('/^\s*[–—-]\s*/u', '', mb_substr($f0, mb_strlen($tittel))));
        if ($halen !== '') {
            $ingress = mb_strtoupper(mb_substr($halen, 0, 1)) . mb_substr($halen, 1);
            array_shift($deler);
        }
    }
}
if ($ingress !== '') {
    $h .= '<p style="margin: 0 0 var(--space-6); font-family: var(--font-display); font-weight: 700; font-size: var(--text-xl); line-height: 1.35; color: var(--text-heading); max-width: 46ch; text-wrap: balance;">' . $e($ingress) . '</p>';
}
foreach ($deler as $i => $t) {
    $h .= '<p style="margin: 0 0 var(--space-5); color: ' . ($i === 0 ? 'var(--text-heading)' : 'var(--text-body)') . '; font-size: ' . ($i === 0 ? 'var(--text-lg)' : 'var(--text-base)') . '; line-height: ' . ($i === 0 ? '1.6' : '1.75') . '; max-width: 58ch; text-wrap: pretty;">' . $e($t) . '</p>';
}

// Passer for — bPasserFor.
$passerFor = ['Nybegynner' => 'Deg som aldri har prøvd leire før — ingen forkunnskaper nødvendig.', 'Event' => 'Alle — ingen erfaring nødvendig. Kom som du er, vi viser deg resten.'][$kort['level']] ?? 'Alle — ingen forkunnskaper nødvendig.';
$h .= '<p style="margin: 0 0 var(--space-6); font-size: var(--text-base); color: var(--text-heading); max-width: 56ch;"><strong>Passer for:</strong> ' . $e($passerFor) . '</p>';

// Faktaboksene — bFaktaRader.
$kortAv = static fn(string $t): string => trim((string) preg_split('/[.\n]/', $t)[0]);
$fakta = array_values(array_filter([
    ['Nivå', (string) ($kat['nivaaTekst'] ?? '')],
    ['Varighet', (string) ($kort['duration'] ?? '')],
    ['Du lærer', (string) ($kat['laererKort'] ?: $kortAv((string) ($kat['laerer'] ?? '')))],
    ['Med hjem', $kortAv((string) ($kat['medHjem'] ?? ''))],
], static fn(array $f): bool => $f[1] !== ''));
if ($fakta !== []) {
    $h .= '<div class="lx-cols4" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-3); margin: 0 0 var(--space-6); max-width: 56ch;">';
    foreach ($fakta as [$merke, $verdi]) {
        $h .= '<div style="background: var(--clay-100); border-radius: var(--radius-md); padding: var(--space-3) var(--space-4);"><div style="font-size: 12px; letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--text-muted); margin-bottom: 2px;">' . $e($merke) . '</div><div style="font-size: var(--text-sm); font-weight: 700; color: var(--text-heading);">' . $e($verdi) . '</div></div>';
    }
    $h .= '</div>';
}

// Punktene — bookingPunkter.
$punkter = (array) ($kat['punkter'] ?? []);
if ($punkter === []) {
    $punkter = $erEvent
        ? ['Ingen erfaring nødvendig — vi viser deg alt underveis.', 'Leire, materialer, glasering og brenning er inkludert.', 'Passer venninnekvelder, utdrikningslag, bedrifter og par.', 'Arbeidene brennes og er klare til henting etter to til fire uker.']
        : ['Leire, verktøy, glasur og brenning er inkludert.', 'Ingen forkunnskaper nødvendig.'];
}
$plasser = $kunKontakt ? 0 : (int) ($kat['plasser'] ?? 0);
if ($plasser > 0) {
    array_unshift($punkter, 'Maks ' . $plasser . ' deltakere.');
}
$h .= '<div style="display: flex; flex-direction: column; gap: 10px; max-width: 52ch;">';
foreach ($punkter as $p) {
    $h .= '<div style="display: flex; gap: 10px; align-items: flex-start; font-size: var(--text-base); color: var(--text-body);"><span style="color: var(--sage-600); font-weight: 700; flex: 0 0 auto;">✓</span><span>' . $e((string) $p) . '</span></div>';
}
$h .= '</div>';

// Seksjonene fra kursoppsettet — bSeksjoner.
foreach ([['Dette lærer du', (string) ($kat['laerer'] ?? '')], ['Dette får du med hjem', (string) ($kat['medHjem'] ?? '')], ['Når er den ferdig', (string) ($kat['ferdigTid'] ?? '')], ['Praktisk informasjon', (string) ($kat['praktisk'] ?? '')], ['Allergener og kommentarer', (string) ($kat['allergener'] ?? '')]] as [$st, $tekst]) {
    if (trim($tekst) === '') {
        continue;
    }
    $h .= '<div style="margin-top: var(--space-8); max-width: 60ch;"><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-2);">' . $e($st) . '</div>'
        . '<p style="margin: 0; font-size: var(--text-base); line-height: 1.7; color: var(--text-body); white-space: pre-wrap; text-wrap: pretty;">' . $e(trim($tekst)) . '</p></div>';
}

// Merkene — bMerker.
$MERKER = ['nybegynner' => 'Nybegynnere', 'litt' => 'Litt erfaring', 'erfaren' => 'Erfarne', 'alene' => 'Deg alene', 'par' => 'To sammen', 'venner' => 'Venner', 'familie' => 'Familie', 'firma' => 'Bedrift', 'barn' => 'Barn med voksen', 'dreiing' => 'Dreiing', 'handbygging' => 'Håndbygging', 'maling' => 'Maling', 'begge' => 'Dreiing og håndbygging', 'kort' => 'Under to timer', 'medium' => 'To til fire timer', 'lang' => 'Over fire timer'];
$merker = [];
foreach (['passerNivaa', 'passerHvem', 'metode', 'varighet'] as $felt) {
    foreach (explode(',', (string) ($kat[$felt] ?? '')) as $x) {
        $x = trim($x);
        if ($x !== '') {
            $merker[] = $MERKER[$x] ?? $x;
        }
    }
}
if ($merker !== []) {
    $h .= '<div style="margin-top: var(--space-8); max-width: 60ch;"><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">Passer for</div><div style="display: flex; gap: 8px; flex-wrap: wrap;">';
    foreach ($merker as $m) {
        $h .= '<span style="display: inline-flex; align-items: center; padding: 5px 12px; border-radius: var(--radius-pill); background: var(--clay-100); border: 1px solid var(--border-subtle); font-size: var(--text-sm); color: var(--text-heading);">' . $e($m) . '</span>';
    }
    $h .= '</div></div>';
}

// Samlingene paa foerste dato — bSamlinger.
$valgt = $datoer[0] ?? null;
$samlinger = (array) ($valgt['samlinger'] ?? []);
if (count($samlinger) > 1) {
    $h .= '<div style="margin-top: var(--space-8); max-width: 56ch;"><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-2);">' . count($samlinger) . ' samlinger</div>'
        . '<p style="margin: 0 0 var(--space-4); font-size: var(--text-sm); color: var(--text-body); text-wrap: pretty;">Du melder deg på hele kurset. Alle ' . count($samlinger) . ' samlingene er med i prisen.</p>'
        . '<div style="display: flex; flex-direction: column; gap: var(--space-3);">';
    foreach ($samlinger as $sa) {
        $h .= '<div style="background: var(--clay-100); border-radius: var(--radius-md); padding: var(--space-4) var(--space-5);"><div style="display: flex; align-items: baseline; gap: var(--space-3); flex-wrap: wrap;"><span style="font-size: 12px; font-weight: 700; letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); white-space: nowrap;">Samling ' . $e((string) ($sa['nummer'] ?? '')) . '</span><span style="font-size: var(--text-sm); font-weight: 700; color: var(--text-heading);">' . $e((string) ($sa['naar'] ?? '')) . '</span></div>'
            . ((string) ($sa['overskrift'] ?? '') !== '' ? '<div style="margin-top: 4px; font-family: var(--font-display); font-weight: 700; font-size: var(--text-base); color: var(--text-heading);">' . $e((string) $sa['overskrift']) . '</div>' : '')
            . ((string) ($sa['tekst'] ?? '') !== '' ? '<p style="margin: 6px 0 0; font-size: var(--text-sm); color: var(--text-body); text-wrap: pretty;">' . $e((string) $sa['tekst']) . '</p>' : '')
            . '</div>';
    }
    $h .= '</div></div>';
}
if ((string) ($valgt['info'] ?? '') !== '') {
    $h .= '<div style="margin-top: var(--space-6); border-left: 3px solid var(--terracotta-500); padding-left: var(--space-5); max-width: 56ch;"><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: 4px;">Om denne datoen</div><p style="margin: 0; font-size: var(--text-base); color: var(--text-body); text-wrap: pretty;">' . $e((string) $valgt['info']) . '</p></div>';
}
// Sitatet — bare naar verkstedet har lagt inn et.
$sitat = $innh('Kurs/9/Sitat');
if ($sitat !== '') {
    $h .= '<div style="margin-top: var(--space-8); border-left: 3px solid var(--lissom-yellow); padding-left: var(--space-5); max-width: 52ch;"><p style="margin: 0 0 var(--space-2); font-family: var(--font-display); font-weight: 700; font-size: var(--text-lg); line-height: 1.45; color: var(--text-heading); text-wrap: pretty;">' . $e($sitat) . '</p><div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--text-muted);">' . $e($innh('Kurs/9/Avsender')) . '</div></div>';
}
$h .= '</div>';

// ── Boksen: pris og datoene ───────────────────────────────────────────────
$fmt = static fn(float $n): string => 'kr. ' . number_format((int) round($n), 0, ',', ' ') . ',-';
$grunn = ($valgt !== null && isset($valgt['prisOre']) && $valgt['prisOre'] !== null) ? (int) $valgt['prisOre'] / 100 : (int) ($kat['prisOre'] ?? 0) / 100;
if (!empty($kat['gjenstandIKassa']) && !empty($kat['prisFraOre'])) {
    $pris = $fmt((int) $kat['prisFraOre'] / 100);
    $rabattTeaser = '';
} else {
    $pris = $gratis ? 'Gratis' : ($grunn > 0 ? $fmt($grunn) : (string) ($kort['price'] ?? ''));
    // «Ta med venner og faa opptil N % rabatt» — maksRabatt/nivaerFor.
    $erDreiing = ($kort['tema'] ?? '') === 'Dreiing' || str_contains(mb_strtolower($tittel), 'dreie');
    $maks = 0.0;
    foreach (Katalog::rabatter() as $r) {
        if ($r['gjelder'] === 'alle' || ($erDreiing && $r['gjelder'] === 'dreiing') || $r['gjelder'] === $slug) {
            $maks = max($maks, (float) $r['prosent']);
        }
    }
    $rabattTeaser = ($maks > 0 && !$gratis) ? 'Ta med venner og få opptil ' . rtrim(rtrim(number_format($maks, 1, ',', ''), '0'), ',') . ' % rabatt.' : '';
}
$prisNote = $gratis ? 'for medlemmer' : 'per person';
// Foerste dato staar valgt fra start, som i appen — med stor forbokstav.
$naar = $valgt !== null ? (string) ($valgt['dato'] ?? '') : 'Flere datoer';
if ($naar !== '') {
    $naar = mb_strtoupper(mb_substr($naar, 0, 1)) . mb_substr($naar, 1);
}
$oppsummering = implode(' · ', array_filter([$naar, (string) ($kort['duration'] ?? ''), (string) ($kat['nivaaTekst'] ?? '')]));

$h .= '<div style="background: var(--surface-card); border: 2px solid var(--lissom-brown); border-radius: var(--radius-lg); padding: var(--space-8); position: sticky; top: 104px;">'
    . '<div style="display: flex; align-items: baseline; gap: 8px 10px; margin-bottom: var(--space-6); flex-wrap: wrap;"><span style="font-family: var(--font-display); font-weight: 800; font-size: min(var(--text-4xl), 10vw); color: var(--text-heading); white-space: nowrap;" class="lx-pris">' . $e($pris) . '</span><span style="font-size: var(--text-sm); color: var(--text-muted);">' . $e($prisNote) . '</span></div>'
    . ($rabattTeaser !== '' ? '<div style="font-size: var(--text-sm); color: var(--terracotta-600); font-weight: 600; margin: -8px 0 var(--space-5);">' . $e($rabattTeaser) . '</div>' : '')
    . '<div style="font-size: var(--text-sm); color: var(--text-body); margin: -8px 0 var(--space-5);">' . $e($oppsummering) . '</div>';

$appHref = '/kurs/' . rawurlencode($slug);
if (!$fullbooket && !$kunKontakt) {
    // Dagene — bookingDager. Tre om gangen; resten bak ?alle=1.
    $dager = [];
    foreach ($datoer as $d) {
        $navn = (string) (($d['dag'] ?? '') ?: ($d['dato'] ?? ''));
        $ledig = Kort::ledigFor($d, (int) ($kat['plasser'] ?? 0));
        $dager[$navn] ??= ['dag' => $navn, 'tider' => []];
        $dager[$navn]['tider'][] = ['klokke' => (string) ($d['klokke'] ?? ''), 'full' => (int) ($d['ledige'] ?? 0) <= 0, 'plasser' => $ledig, 'samlinger' => count((array) ($d['samlinger'] ?? [])) > 1 ? count($d['samlinger']) . ' samlinger' : ''];
    }
    $dager = array_values($dager);
    $alle = isset($_GET['alle']);
    $synlige = $alle ? $dager : array_slice($dager, 0, 3);
    $skjult = count($dager) - count($synlige);
    $h .= '<div style="font: var(--type-label); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--text-heading); margin-bottom: var(--space-4);">Velg dato</div>'
        . '<div style="display: flex; flex-direction: column; gap: var(--space-3); margin-bottom: var(--space-6);">';
    foreach ($synlige as $i => $rad) {
        $full = array_reduce($rad['tider'], static fn(bool $c, array $t): bool => $c && $t['full'], true);
        $best = null;
        foreach ($rad['tider'] as $t) { if (!$t['full']) { $best = $t; break; } }
        $best ??= $rad['tider'][0];
        $under = count($rad['tider']) === 1 ? ($rad['tider'][0]['klokke'] ?: $rad['tider'][0]['samlinger']) : count($rad['tider']) . ' tider å velge mellom';
        $plasserTekst = $full ? 'Fullbooket' : ($best['plasser'] ?: 'Ledig');
        $prikk = $full ? 'var(--clay-400)' : (str_starts_with($best['plasser'], 'Få plasser') ? 'var(--terracotta-500)' : 'var(--sage-500)');
        // Den foerste dagen staar valgt, som i appen (valgt: i === 0).
        $valgtDag = $i === 0;
        $stil = 'appearance: none; cursor: ' . ($full ? 'not-allowed' : 'pointer') . '; text-align: left; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-radius: var(--radius-md); border: '
            . ($valgtDag ? '2px solid var(--lissom-brown)' : '1px solid var(--border-subtle)') . '; background: ' . ($valgtDag ? 'var(--lissom-brown)' : 'var(--surface-card)') . '; color: ' . ($valgtDag ? 'var(--clay-50)' : 'var(--text-heading)') . '; opacity: ' . ($full ? '0.45' : '1') . '; font-family: inherit; text-decoration: none;';
        $inni = '<span style="display: flex; flex-direction: column; align-items: flex-start; gap: 2px;"><span style="font-weight: 700; font-size: var(--text-base);">' . $e($rad['dag']) . '</span><span style="font-size: var(--text-xs); opacity: .75;">' . $e($under) . '</span></span>'
            . '<span style="font-size: var(--text-xs); font-weight: 700; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;"><span style="width: 7px; height: 7px; border-radius: 50%; background: ' . $prikk . '; flex: 0 0 auto;"></span>' . $e($plasserTekst) . '</span>';
        $h .= $full
            ? '<div style="' . $stil . '" aria-disabled="true">' . $inni . '</div>'
            : '<a href="' . $e($appHref . '?dag=' . rawurlencode($rad['dag'])) . '" style="' . $stil . '">' . $inni . '</a>';
    }
    if ($skjult > 0) {
        $h .= '<a href="' . $e($appHref . '?alle=1') . '" style="appearance: none; cursor: pointer; width: 100%; box-sizing: border-box; padding: 12px 16px; border-radius: var(--radius-md); min-height: 44px; border: 1px dashed var(--border-subtle); background: transparent; font: var(--type-body-sm); font-weight: 600; color: var(--lissom-brown); text-decoration: none; display: flex; align-items: center; justify-content: center;">' . ($skjult === 1 ? 'Vis én dato til' : 'Vis ' . $skjult . ' datoer til') . '</a>';
    }
    $h .= '</div>';
    $h .= Deler::knapp('Velg dato og book', ['href' => $appHref . '?book=1', 'size' => 'lg', 'full' => true])
        . '<p style="margin: var(--space-4) 0 0; font-size: var(--text-xs); color: var(--text-muted); text-align: center;">Du velger tid, antall og betaler med Vipps i neste steg.</p>';
}
if ($kunKontakt) {
    $h .= '<div style="background: var(--clay-100); border-radius: var(--radius-md); padding: var(--space-6); margin-bottom: var(--space-6);"><div style="font: var(--type-label); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">Etter avtale</div><p style="margin: 0; font-size: var(--text-sm); color: var(--text-body); text-wrap: pretty;">Dette setter vi opp når det passer dere. Send oss en melding med når dere tenker, så finner vi en kveld sammen.</p></div>'
        . Deler::knapp('Kontakt oss', ['href' => '/kontakt', 'size' => 'lg', 'full' => true])
        . '<p style="margin: var(--space-4) 0 0; font-size: var(--text-xs); color: var(--text-muted); text-align: center;">Du betaler ingenting nå. Vi svarer så fort vi kan.</p>';
}
if ($fullbooket) {
    $h .= '<div style="background: var(--clay-100); border-radius: var(--radius-md); padding: var(--space-6); margin-bottom: var(--space-6);"><div style="font: var(--type-label); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); margin-bottom: var(--space-3);">Fullbooket</div><p style="margin: 0; font-size: var(--text-sm); color: var(--text-body); text-wrap: pretty;">Alle plassene er tatt. Sett deg på ventelisten, så gir vi beskjed hvis noen melder seg av. Du betaler ingenting nå — først når plassen er din.</p></div>'
        . Deler::knapp('Sett meg på venteliste', ['href' => $appHref . '?venteliste=1', 'size' => 'lg', 'full' => true])
        . '<p style="margin: var(--space-4) 0 0; font-size: var(--text-xs); color: var(--text-muted); text-align: center;">Ingen betaling før plassen er bekreftet.</p>';
}
if (!$kunKontakt && !$gratis) {
    $h .= '<div style="height: 1px; background: var(--border-subtle); margin: var(--space-6) 0;"></div><div style="display: flex; gap: 10px; align-items: center; font-size: var(--text-sm); color: var(--text-muted);"><span style="width: 8px; height: 8px; border-radius: 50%; background: var(--sage-500);"></span>Du betaler trygt med Vipps.</div>';
}
$h .= '</div></div></div></section>' . "\n";
$h .= '</div>' . "\n";
$h .= Deler::bunn(true);

return [
    'kropp' => $h,
    'aktiv' => $erEvent ? 'Events' : 'Kurs',
    'hode'  => $hode,
];

