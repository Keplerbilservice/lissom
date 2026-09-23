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

// ── Paint on Pots staar oeverst, ikke i rutenettet ──────────────────────
//
// Eieren, 23. september 2026: «naar jeg er paa forsiden, og trykker meg inn
// paa kalender, saa ser jeg masse paint on pots, jeg vil ikke at de vises i
// kalenderen paa denne maaten, men jeg vil at den overste viser paint on
// pots tilgjengelig i dag».
//
// Det gaar fire ganger om dagen, to dager i uka. Maalt samme dag: 8 av
// 10–15 oppforinger i hver uke, og de ekte kursene druknet. Uke 44 hadde
// ti oppforinger, hvorav aatte var den samme drop-in-en.
//
// Det tas ogsaa ut av UKELISTA, ikke bare rutenettet. 22 av 56 uker hadde
// bare Paint on Pots — sto de igjen i pilene, kunne kunden bla seg inn i 22
// tomme uker, og det er darligere enn i dag.
//
// Og: eieren, om tidene — «dersom jeg ikke er saa opptatt av tider paa paint
// on pots, men at de maa komme i dette tidsrommet». Derfor slaas sittingene
// sammen til tidsrom i stripa: 10:00 og 11:30 blir «10–13».
$POP = 'paint-on-pots';
$popKurs = null;
foreach ($katalog as $k) {
    if (($k['slug'] ?? '') === $POP) {
        $popKurs = $k;
        break;
    }
}
$katalog = array_values(array_filter($katalog, static fn(array $k): bool => ($k['slug'] ?? '') !== $POP));
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

// ── Stripa: Paint on Pots i dag ────────────────────────────────────────
//
// Sittingene varer halvannen time og ligger rygg mot rygg — 10:00, 11:30 og
// 17:00, 18:30. To som henger sammen er ett tidsrom man stikker innom, ikke
// to avtaler man velger mellom.
//
// Plassene henger fortsatt paa hver sitting, tolv om gangen. Et tidsrom er
// derfor ledig saa lenge én sitting i det har plass, og fullt naar ingen
// har det. Uten det skillet ville sida invitert folk til en formiddag som
// er utsolgt: torsdag 24. september er 10:00 og 11:30 fulle mens 17:00 og
// 18:30 er aapne.
//
// Teksten staar i to felt og ikke som én streng med <b> i: Mal escaper alt
// som gaar gjennom «{{ }}», saa taggen ville vist seg som tekst paa sida.
$popTittel = '';
$popTekst  = '';
$popFull = false;
if ($popKurs !== null) {
    $klokke = static function (int $min): string {
        $t = intdiv($min, 60);
        return $min % 60 === 0 ? (string) $t : $t . '.' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT);
    };
    /** Sittingene paa én dag, slaatt sammen til tidsrom. */
    $tidsrom = static function (array $okter) use ($klokke): array {
        usort($okter, static fn(array $a, array $b): int => $a['m'] <=> $b['m']);
        $ut = [];
        foreach ($okter as $o) {
            $slutt = $o['m'] + 90;
            $siste = $ut === [] ? null : $ut[count($ut) - 1];
            if ($siste !== null && $o['m'] <= $siste['slutt']) {
                $ut[count($ut) - 1]['slutt'] = max($siste['slutt'], $slutt);
                $ut[count($ut) - 1]['ledig'] = $siste['ledig'] || $o['ledig'];
                continue;
            }
            $ut[] = ['start' => $o['m'], 'slutt' => $slutt, 'ledig' => $o['ledig']];
        }
        return array_map(static fn(array $v): array => [
            'tekst' => $klokke($v['start']) . '–' . $klokke($v['slutt']),
            'ledig' => $v['ledig'],
        ], $ut);
    };

    // Dagene framover, hver med sine sittinger. Det som er passert i dag
    // teller ikke — en stripe som byr paa klokka ti klokka tolv er feil.
    $perDag = [];
    foreach ($popKurs['datoer'] ?? [] as $o) {
        if (empty($o['startUtc'])) {
            continue;
        }
        $d = (new DateTimeImmutable((string) $o['startUtc'], new DateTimeZone('UTC')))->setTimezone($oslo);
        if ($d <= $naa) {
            continue;
        }
        $dag = $d->format('Y-m-d');
        $perDag[$dag][] = [
            'm'     => (int) $d->format('G') * 60 + (int) $d->format('i'),
            'ledig' => (int) ($o['ledige'] ?? 0) > 0,
            'd'     => $d,
        ];
    }
    ksort($perDag);

    $liste = static function (array $rom): string {
        $t = array_map(static fn(array $v): string => $v['tekst'], $rom);
        $sist = array_pop($t);
        return $t === [] ? $sist : implode(', ', $t) . ' eller ' . $sist;
    };
    $DAGNAVN = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];

    $idag = $naa->format('Y-m-d');
    if (isset($perDag[$idag])) {
        $rom    = $tidsrom($perDag[$idag]);
        $ledige = array_values(array_filter($rom, static fn(array $v): bool => $v['ledig']));
        $fulle  = array_values(array_filter($rom, static fn(array $v): bool => !$v['ledig']));
        if ($ledige !== []) {
            $popTittel = 'Paint on Pots i dag.';
            $popTekst  = 'Stikk innom mellom ' . $liste($ledige) . '.'
                . ($fulle !== []
                    ? ' (' . $liste($fulle) . ' er fullt.)'
                    : ' Ingen booking nødvendig.');
        }
    }

    if ($popTittel === '') {
        // Ikke i dag, eller utsolgt: si rytmen, og naar det er plass igjen.
        $neste = '';
        foreach ($perDag as $dag => $okter) {
            if ($dag === $idag) {
                continue;
            }
            $rom = array_values(array_filter($tidsrom($okter), static fn(array $v): bool => $v['ledig']));
            if ($rom !== []) {
                $d = $okter[0]['d'];
                $neste = $DAGNAVN[(int) $d->format('N') - 1] . ' ' . $d->format('j') . '. '
                       . $MND[(int) $d->format('n') - 1] . ', ' . $liste($rom);
                break;
            }
        }
        $popFull = true;
        $popTittel = isset($perDag[$idag])
            ? 'Paint on Pots er fullt i dag.'
            : 'Paint on Pots går onsdag og torsdag, 10–13 og 17–20.';
        $popTekst = $neste !== '' ? 'Neste dag med ledig plass er ' . $neste . '.' : '';
    }
}

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
        // Stripa over rutenettet. Gul naar det er plass i dag, dempet ellers.
        'popTittel'    => $popTittel,
        'popTekst'     => $popTekst,
        'popHarStripe' => $popTittel !== '',
        'popLenke'     => 'Se hvordan det foregår',
        'popStripeStil' => 'display: flex; align-items: flex-start; gap: var(--space-3); '
            . 'border-radius: var(--radius-md); padding: 13px 16px; margin-bottom: var(--space-5); '
            . 'text-decoration: none; background: '
            . ($popFull ? 'var(--surface-card); border: 1px solid var(--border-subtle); color: var(--text-body)'
                        : 'var(--lissom-yellow); color: var(--lissom-brown)') . ';',
        'sant' => true,
    ], [
        'ukeForrige' => $forrige !== null ? '/kalender?uke=' . $forrige : 'js:ingen',
        'ukeNeste'   => $neste !== null ? '/kalender?uke=' . $neste : 'js:ingen',
        'ukeTomGaa' => '/kurs', 'goKurs' => '/kurs', 'kvApne' => '/kurs#kursvelger',
        'popStripeGaa' => '/paint-on-pots',
    ]) . "\n" . Deler::bunn(true),
    'aktiv' => 'Kalender',
];
