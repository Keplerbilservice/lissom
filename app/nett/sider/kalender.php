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

// ── Kurs som pågår ─────────────────────────────────────────────────────
//
// Eieren, 8. oktober 2026: «nå er vi i uke 41, men kalenderen viser kun fra
// uke 42, ergo de kan ikke se at vi har kurs i dag». Katalogen har bare
// datoer som ikke har startet — dag 2 av dreiekurset som startet i går, og
// et kurs som startet tidligere i dag, fantes ikke her. De legges inn som
// fulle (kan ikke bookes), på dagene de faktisk går.
$iDagOslo = $naa->setTime(0, 0);
$katalogIndeks = [];
foreach ($katalog as $i => $k) { $katalogIndeks[(int) ($k['id'] ?? 0)] = $i; }
$paagaar = DB::alle(
    "SELECT id, course_id, start_tid FROM course_sessions
      WHERE status = 'planlagt' AND start_tid <= UTC_TIMESTAMP()
        AND start_tid >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)"
);
$paagaarSamlinger = DB::harTabell('okt_samlinger')
    ? Samlinger::forOkter(array_map(static fn(array $o): int => (int) $o['id'], $paagaar)) : [];
foreach ($paagaar as $o) {
    $ki = $katalogIndeks[(int) $o['course_id']] ?? null;
    if ($ki === null) { continue; }
    $starter = [];
    foreach ($paagaarSamlinger[(int) $o['id']] ?? [] as $sm) {
        if ((string) ($sm['dato'] ?? '') < $iDagOslo->format('Y-m-d')) { continue; }
        $starter[] = new DateTimeImmutable($sm['dato'] . ' ' . ($sm['fra'] ?: '00:00'), $oslo);
    }
    if (($paagaarSamlinger[(int) $o['id']] ?? []) === []) {
        $start = (new DateTimeImmutable((string) $o['start_tid'], new DateTimeZone('UTC')))->setTimezone($oslo);
        if ($start >= $iDagOslo) { $starter[] = $start; }
    }
    foreach ($starter as $st) {
        $katalog[$ki]['datoer'][] = [
            'startUtc' => $st->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'ledige'   => 0,
            'dag'      => '',
        ];
    }
}

$uker = [];
$naaUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
foreach ($katalog as $k) {
    foreach ($k['datoer'] ?? [] as $o) {
        if (empty($o['startUtc'])) { continue; }
        $d = new DateTimeImmutable((string) $o['startUtc'], new DateTimeZone('UTC'));
        if ($d < $iDagOslo) { continue; }
        $u = (int) $d->setTimezone($oslo)->format('W');
        if ($u >= $ukeNaa && !in_array($u, $uker, true)) { $uker[] = $u; }
    }
}
// Denne uka står alltid med, og kalenderen åpner på den (eieren 8. oktober 2026).
if (!in_array($ukeNaa, $uker, true)) { $uker[] = $ukeNaa; }
sort($uker);
$sp = $_GET['uke'] ?? '';
$vist = is_string($sp) && ctype_digit($sp) && (int) $sp >= 1 && (int) $sp <= 53 ? (int) $sp : $ukeNaa;
$neste = null; $forrige = null;
foreach ($uker as $u) { if ($u > $vist && $neste === null) { $neste = $u; } if ($u < $vist) { $forrige = $u; } }

// Mandagen i uka — mandagIUke().
$aar = $vist < $ukeNaa ? (int) $naa->format('Y') + 1 : (int) $naa->format('Y');
$mandag = (new DateTimeImmutable('now', $oslo))->setISODate($aar, $vist, 1)->setTime(0, 0);
$sondag = $mandag->modify('+6 days');
$MND = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];
$a = $MND[(int) $mandag->format('n') - 1]; $b = $MND[(int) $sondag->format('n') - 1];
// Aaret staar alltid med: «Oktober 2026». Eieren, 7. oktober 2026: «man kan
// ikke se hvilken maaned vi er i».
$maaned = mb_strtoupper(mb_substr($a === $b ? $a : $a . ' – ' . $b, 0, 1)) . mb_substr($a === $b ? $a : $a . ' – ' . $b, 1)
    . ' ' . $sondag->format('Y');

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
// ── Paint on Pots som egen flis ────────────────────────────────────────
//
// Eieren, 8. oktober 2026: «vi bør også få vise at det er paint on pots i
// dag, men en flis», og samme dag: «det er endret til hver torsdag» — flisa
// står på hver dag Paint on Pots er åpent (dagene følger ukeplanen i admin),
// én flis per dag. Hvit med brun kant, så den skiller seg fra kursene.
$popDager = [];
if ($popKurs !== null) {
    $popKort = $kurs[$POP] ?? null;
    foreach ($popKurs['datoer'] ?? [] as $o) {
        $iso = (string) ($o['dagIso'] ?? '');
        if ($iso === '' || isset($popDager[$iso])) { continue; }
        $popDager[$iso] = [
            'tekst' => 'Paint on Pots' . ($iso === $naa->format('Y-m-d') ? ' i dag' : '') . ' · ' . (string) ($o['klokke'] ?? ''),
            'href'  => $popKort !== null ? $popKort['href'] : '/kurs/paint-on-pots',
        ];
    }
}
$popIDag = $popDager[$naa->format('Y-m-d')] ?? null;
$popStil = static fn(string $str, string $pad): string => 'appearance: none; width: 100%; text-align: left; cursor: pointer; font-family: inherit; font-size: ' . $str . '; line-height: 1.35; padding: ' . $pad . '; border-radius: var(--radius-sm); background: var(--surface-card); border: 2px solid var(--lissom-brown); color: var(--lissom-brown); font-weight: 700;';
foreach ($popDager as $iso => $p) {
    $dagP = new DateTimeImmutable($iso, $oslo);
    if ((int) $dagP->format('W') !== $vist || (int) $dagP->format('o') !== (int) $mandag->format('o')) { continue; }
    array_unshift($dager[(int) $dagP->format('N') - 1]['poster'], [
        'tekst' => '🎨 ' . $p['tekst'],
        'href'  => $p['href'],
        'stil'  => $popStil('12px', '7px 9px'),
    ]);
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
// Sittingene varer to timer og ligger rygg mot rygg — 10:00, 12:00 og
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
    // Eieren, 8. oktober 2026: tidene hentes fra de samme innstillingene som
    // bestillingen (Malebord / Apent). Her sto «onsdag og torsdag, 10–13 og
    // 17–20» og «Ingen booking nødvendig» skrevet inn for haand.
    $MND = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];
    $DAGNAVN = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
    $idag = $naa->format('Y-m-d');
    $iDag = Malebord::kvarter((int) $popKurs['id'], $idag, 1);
    if ($iDag['tider'] !== []) {
        $popTittel = 'Paint on Pots i dag.';
        $popTekst  = 'Åpent ' . $iDag['vindu'] . '. Book tid på forhånd.';
    } else {
        $popNeste = '';
        foreach ($popKurs['datoer'] ?? [] as $o) {
            if (($o['dagIso'] ?? '') === $idag || (int) ($o['ledige'] ?? 0) <= 0) {
                continue;
            }
            $d = new DateTimeImmutable((string) $o['dagIso'], $oslo);
            $popNeste = $DAGNAVN[(int) $d->format('N') - 1] . ' ' . $d->format('j') . '. '
                . $MND[(int) $d->format('n') - 1] . ', ' . (string) ($o['klokke'] ?? '');
            break;
        }
        $popFull = true;
        $popTittel = $iDag['vindu'] !== '' ? 'Paint on Pots er fullt i dag.' : 'Paint on Pots.';
        $popTekst = $popNeste !== '' ? 'Neste dag med ledig plass er ' . $popNeste . '.' : '';
    }
}

// ── Datoraden paa telefon ─────────────────────────────────────────────
//
// Eieren, 27. september 2026: «jeg vil at datene skal rulle, og ikke noe
// annet paa siden ingen hopping osv bare rulle datoer». Alle dagene fra i
// dag til uka med det siste kurset staar i én rad som ruller sideveis. Et
// trykk paa en dag med kurs viser kursene den dagen rett under raden
// (nett.js) — sida staar stille. Dager uten kurs kan ikke trykkes.
$MNDK = ['jan', 'feb', 'mar', 'apr', 'mai', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'des'];
$perDato = [];
foreach ($katalog as $k) {
    foreach ($k['datoer'] ?? [] as $o) {
        if (empty($o['startUtc'])) { continue; }
        $d = (new DateTimeImmutable((string) $o['startUtc'], new DateTimeZone('UTC')))->setTimezone($oslo);
        if ($d < $naa->setTime(0, 0)) { continue; }
        $full = (int) ($o['ledige'] ?? 0) <= 0;
        $kort = $kurs[(string) $k['slug']] ?? null;
        $perDato[$d->format('Y-m-d')][] = [
            't' => $d->format('H:i'),
            'tekst' => $k['tittel'] . ' · ' . $d->format('H:i'),
            'href'  => $kort !== null ? $kort['href'] . '?dag=' . rawurlencode((string) ($o['dag'] ?? '')) : '/kurs',
            'stil'  => 'appearance: none; border: none; width: 100%; text-align: left; cursor: pointer; font-family: inherit; font-size: 14px; line-height: 1.35; padding: 10px 12px; border-radius: var(--radius-sm); background: '
                . ($full ? 'var(--lissom-brown)' : 'var(--lissom-yellow)') . '; color: ' . ($full ? 'var(--clay-50)' : 'var(--lissom-brown)') . '; font-weight: 600;',
        ];
    }
}
foreach ($popDager as $iso => $p) {
    if ($iso < $naa->format('Y-m-d')) { continue; }
    $perDato[$iso][] = [
        't'     => '00:00',
        'tekst' => '🎨 ' . $p['tekst'],
        'href'  => $p['href'],
        'stil'  => $popStil('14px', '10px 12px'),
    ];
}
$rullDager = [];
$rullPanel = [];
$forsteMedKurs = null;
if ($perDato !== []) {
    $siste = new DateTimeImmutable(max(array_keys($perDato)), $oslo);
    $slutt = $siste->modify('sunday this week');
    $DAGLANG = ['Mandag', 'Tirsdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lørdag', 'Søndag'];
    for ($dag = $naa->setTime(0, 0); $dag <= $slutt; $dag = $dag->modify('+1 day')) {
        $nokkel = $dag->format('Y-m-d');
        $poster = $perDato[$nokkel] ?? [];
        usort($poster, static fn(array $a, array $b): int => strcmp($a['t'], $b['t']));
        $n = count($poster);
        if ($n > 0 && $forsteMedKurs === null) { $forsteMedKurs = $nokkel; }
        $valgt = $nokkel === $forsteMedKurs;
        $rullDager[] = [
            'nokkel' => $n > 0 ? $nokkel : '',
            'dagKort' => mb_substr($DAG[(int) $dag->format('N') - 1], 0, 2),
            'dato' => $dag->format('j'),
            // Maaneden staar paa den foerste dagen og paa den 1., saa man ser
            // hvor man er naar man ruller.
            'mnd' => ($rullDager === [] || $dag->format('j') === '1') ? $MNDK[(int) $dag->format('n') - 1] : '',
            'antall' => $n > 0 ? (string) $n : '',
            // Uka og maaneden dagen hoerer til. nett.js setter dem oeverst
            // naar raden rulles eller en dag velges, og pilene blar i raden.
            // Eieren, 7. oktober 2026: «det staar fortsatt uke 44 paa toppen».
            'uke' => (string) (int) $dag->format('W'),
            'ukeId' => $dag->format('o-W'),
            'mndAar' => mb_strtoupper(mb_substr($MND[(int) $dag->format('n') - 1], 0, 1)) . mb_substr($MND[(int) $dag->format('n') - 1], 1) . ' ' . $dag->format('Y'),
            'stil' => 'appearance: none; font-family: inherit; flex: none; width: 50px; scroll-snap-align: start; cursor: ' . ($n ? 'pointer' : 'default') . '; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 8px 2px 7px; border-radius: var(--radius-md); border: '
                . ($n ? '1px solid var(--lissom-brown)' : '1px solid var(--border-subtle)') . '; background: '
                . ($valgt ? 'var(--lissom-brown)' : ($n ? 'var(--lissom-yellow)' : 'var(--surface-card)')) . '; color: '
                . ($valgt ? 'var(--clay-50)' : ($n ? 'var(--lissom-brown)' : 'var(--text-muted)')) . ';',
            'prikkStil' => $n ? 'font-size: 10px; font-weight: 700; line-height: 1; background: ' . ($valgt ? 'var(--lissom-yellow); color: var(--lissom-brown)' : 'var(--lissom-brown); color: var(--clay-50)') . '; border-radius: var(--radius-pill); padding: 2px 6px;' : 'display: none;',
        ];
        if ($n > 0) {
            $rullPanel[] = [
                'nokkel' => $nokkel,
                'dag' => $DAGLANG[(int) $dag->format('N') - 1],
                'dato' => $dag->format('j') . '. ' . $MND[(int) $dag->format('n') - 1],
                'poster' => $poster,
                'stil' => 'background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: var(--space-4); display: ' . ($nokkel === $forsteMedKurs ? 'block' : 'none') . ';',
            ];
        }
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
        // Med datoraden staar ikke den gamle ukestripa paa telefon.
        'ukeStripStil' => $rullDager !== [] ? 'display: none;' : 'display: var(--nt-ukestrip, none); grid-template-columns: repeat(7, 1fr); gap: 4px; margin-bottom: var(--space-5);',
        'rullHar' => $rullDager !== [],
        'rullDager' => $rullDager,
        'rullPanel' => $rullPanel,
        // Bare paa telefon (--nt-ukestrip er «grid» der, ellers «none»).
        'rullStil' => 'display: var(--nt-ukestrip, none); grid-auto-flow: column; grid-auto-columns: 50px; gap: 6px; overflow-x: auto; overscroll-behavior-x: contain; scroll-snap-type: x proximity; -webkit-overflow-scrolling: touch; scrollbar-width: none; padding: 2px 2px 6px; margin: 0 0 var(--space-4);',
        'rullPanelStil' => 'display: var(--nt-ukestrip, none); margin-bottom: var(--space-5);',
        'kvLokketekst' => $lokketekst,
        // Stripa over rutenettet. Gul naar det er plass i dag, dempet ellers.
        'popTittel'    => $popTittel,
        'popTekst'     => $popTekst,
        // Fjernet (eieren, 8. oktober 2026: «fjern denne»). PoP-flisen i
        // rutenettet viser torsdagene.
        'popHarStripe' => false,
        'popLenke'     => 'Se hvordan det foregår',
        // Adressen staar blant VERDIENE og ikke blant lenkene: malen bruker
        // «href» her, og Mal::tegn() leter etter href-verdier i den forste
        // lista. Laa den i den andre, ble det href="" — stripa saa riktig ut
        // og var doed. Maalt 23. september 2026.
        'popStripeGaa' => '/kurs/paint-on-pots',
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
    ]) . "\n" . Deler::bunn(true),
    'aktiv' => 'Kalender',
];
