<?php
/**
 * Kurskalender — /kalender, tegnet paa serveren. Skjermen fra
 * lissom-2108.html via Mal; uka som ukeFraKatalog() i nettsida.
 *
 * Uka er en adresse: /kalender?uke=40. Pilene er lenker til forrige/neste
 * uke med noe i. En oppfoering gaar til kurset i appen med dagen valgt.
 * Det appen gjorde etter skjermbredden (ukestripa paa telefon, tomme dager
 * skjult) gjoeres her med CSS-variabler i nett-egen.css.
 */

declare(strict_types=1);

$oslo = new DateTimeZone('Europe/Oslo');
$naa = new DateTimeImmutable('now', $oslo);
$ukeNaa = (int) $naa->format('W');

// Ukene med noe i, fra naa — okterEtterUke().
$kurs = [];
foreach (Kort::kurs() as $k) {
    $kurs[$k['slug']] = $k;
}
$katalog = array_values(array_filter(Katalog::offentlig(false), static fn(array $k): bool => ($k['tema'] ?? '') !== 'Kun for medlemmer'));
$uker = [];
$naaUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
foreach ($katalog as $k) {
    foreach ($k['datoer'] ?? [] as $o) {
        if (empty($o['startUtc'])) { continue; }
        $d = new DateTimeImmutable((string) $o['startUtc'], new DateTimeZone('UTC'));
        if ($d < $naaUtc) { continue; }
        $u = (int) $d->setTimezone($oslo)->format('W');
        if ($u >= $ukeNaa && !in_array($u, $uker, true)) { $uker[] = $u; }
    }
}
sort($uker);
$sp = $_GET['uke'] ?? '';
$vist = is_string($sp) && ctype_digit($sp) && (int) $sp >= 1 && (int) $sp <= 53 ? (int) $sp : ($uker[0] ?? $ukeNaa);
$neste = null; $forrige = null;
foreach ($uker as $u) { if ($u > $vist && $neste === null) { $neste = $u; } if ($u < $vist) { $forrige = $u; } }

// Mandagen i uka — mandagIUke().
$aar = $vist < $ukeNaa ? (int) $naa->format('Y') + 1 : (int) $naa->format('Y');
$mandag = (new DateTimeImmutable('now', $oslo))->setISODate($aar, $vist, 1)->setTime(0, 0);
$sondag = $mandag->modify('+6 days');
$MND = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];
$a = $MND[(int) $mandag->format('n') - 1]; $b = $MND[(int) $sondag->format('n') - 1];
$maaned = mb_strtoupper(mb_substr($a === $b ? $a : $a . ' – ' . $b, 0, 1)) . mb_substr($a === $b ? $a : $a . ' – ' . $b, 1)
    . (($mandag->format('Y') !== $naa->format('Y') || $sondag->format('Y') !== $naa->format('Y')) ? ' ' . $sondag->format('Y') : '');

// Dagene — ukeFraKatalog().
$DAG = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'];
$dager = [];
foreach ($DAG as $i => $navn) {
    $dager[] = ['dag' => $navn, 'dato' => $mandag->modify('+' . $i . ' days')->format('j'), 'poster' => []];
}
foreach ($katalog as $k) {
    foreach ($k['datoer'] ?? [] as $o) {
        if (empty($o['startUtc'])) { continue; }
        $d = (new DateTimeImmutable((string) $o['startUtc'], new DateTimeZone('UTC')))->setTimezone($oslo);
        if ((int) $d->format('W') !== $vist) { continue; }
        $i = (int) $d->format('N') - 1;
        $full = (int) ($o['ledige'] ?? 0) <= 0;
        $kort = $kurs[(string) $k['slug']] ?? null;
        $dager[$i]['poster'][] = [
            'tekst' => $k['tittel'] . ' · ' . $d->format('H:i'),
            'href'  => $kort !== null ? $kort['href'] . '?dag=' . rawurlencode((string) ($o['dag'] ?? '')) : '/kurs',
            'stil'  => 'appearance: none; border: none; width: 100%; text-align: left; cursor: pointer; font-family: inherit; font-size: 12px; line-height: 1.35; padding: 7px 9px; border-radius: var(--radius-sm); background: '
                . ($full ? 'var(--lissom-brown)' : 'var(--lissom-yellow)') . '; color: ' . ($full ? 'var(--clay-50)' : 'var(--lissom-brown)') . '; font-weight: 600; transition: transform var(--duration-fast) var(--ease-clay);',
        ];
    }
}
foreach ($dager as $i => &$d) {
    $n = count($d['poster']);
    $d['erTom'] = $n === 0;
    $d['tomTekst'] = 'Ingen kurs i dag';
    $d['dagKort'] = mb_substr($d['dag'], 0, 2);
    $d['antall'] = $n > 0 ? (string) $n : '';
    $d['anker'] = 'ukedag-' . $i;
    $d['href'] = $n > 0 ? '#ukedag-' . $i : '';
    $d['stripStil'] = 'appearance: none; font-family: inherit; cursor: ' . ($n ? 'pointer' : 'default') . '; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 8px 2px 7px; min-width: 0; border-radius: var(--radius-md); border: '
        . ($n ? '1px solid var(--lissom-brown)' : '1px solid var(--border-subtle)') . '; background: ' . ($n ? 'var(--lissom-yellow)' : 'var(--surface-card)') . '; color: ' . ($n ? 'var(--lissom-brown)' : 'var(--text-muted)') . '; text-decoration: none;';
    $d['stripPrikkStil'] = $n ? 'font-size: 10px; font-weight: 700; line-height: 1; background: var(--lissom-brown); color: var(--clay-50); border-radius: var(--radius-pill); padding: 2px 6px;' : 'display: none;';
    // Paa telefon: ingen minstehoeyde, og en tom dag skjules — se nett-egen.css.
    $d['kortStil'] = 'background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: var(--space-4); min-height: var(--nt-dagmin, 170px); flex-direction: column; display: ' . ($n ? 'flex' : 'var(--nt-tomdag, flex)') . ';';
}
unset($d);

// «Svar paa tre korte spoersmaal …» — kvLokketekst i nettsida, av kursveilederen.
$lokketekst = 'Svar på noen korte spørsmål, så foreslår vi kurset for deg.';
try {
    $n = count(array_filter(Veileder::sporsmal(true), static fn(array $q): bool => empty($q['visNarId']) && empty($q['vis_nar_id'])));
    $ord = [1 => 'ett', 2 => 'to', 3 => 'tre', 4 => 'fire', 5 => 'fem', 6 => 'seks'][$n] ?? (string) $n;
    $lokketekst = 'Svar på ' . $ord . ($n === 1 ? ' kort spørsmål' : ' korte spørsmål') . ', så foreslår vi kurset for deg.';
} catch (Throwable) {
    // Da staar reserveteksten.
}

$pil = static fn(bool $aktiv): string => 'appearance: none; width: 40px; height: 40px; border-radius: 50%; cursor: ' . ($aktiv ? 'pointer' : 'default') . '; border: 2px solid ' . ($aktiv ? 'var(--lissom-brown)' : 'var(--border-subtle)') . '; background: transparent; color: ' . ($aktiv ? 'var(--lissom-brown)' : 'var(--text-muted)') . '; font-size: 20px; line-height: 1; opacity: ' . ($aktiv ? '1' : '0.5') . ';';

return [
    'kropp' => Mal::tegn('Kurskalender', [
        'ukeNr' => $vist, 'ukeMaaned' => $maaned, 'uke' => $dager,
        'ukePilStilV' => $pil($forrige !== null), 'ukePilStilH' => $pil($neste !== null),
        'ukeTom' => $uker === [], 'ukeTomTekst' => 'Ingen kursdatoer er lagt ut ennå. Ta kontakt, så finner vi en tid.', 'ukeTomKnapp' => 'Se alle kurs',
        'ukeAntall' => count($uker) > 1 ? count($uker) . ' uker med kurs framover' : '',
        'ukeStripStil' => 'display: var(--nt-ukestrip, none); grid-template-columns: repeat(7, 1fr); gap: 4px; margin-bottom: var(--space-5);',
        'kvLokketekst' => $lokketekst,
        'sant' => true,
    ], [
        'ukeForrige' => $forrige !== null ? '/kalender?uke=' . $forrige : 'js:ingen',
        'ukeNeste'   => $neste !== null ? '/kalender?uke=' . $neste : 'js:ingen',
        'ukeTomGaa' => '/kurs', 'goKurs' => '/kurs', 'kvApne' => '/kurs#kursvelger',
    ]) . "\n" . Deler::bunn(true),
    'aktiv' => 'Kalender',
];
