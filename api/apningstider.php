<?php
/**
 * Naar verkstedet er aapent de neste dagene.
 *
 * Aapent med vilje: dette staar i bunnteksten paa nettsiden, og skal kunne
 * leses av hvem som helst.
 *
 * Regelen er enkel og staar ett sted:
 *
 *   1. Er det lagt inn en rad i apningstider for dagen, gjelder den. Punktum.
 *      Det er den manuelle overstyringen — en helligdag, en ferieuke, en dag
 *      verkstedet er stengt selv om det staar et kurs i kalenderen.
 *   2. Ellers: gaar det ett eller flere kurs den dagen, er verkstedet aapent
 *      fra det forste begynner til det siste slutter.
 *   3. Ellers staar dagen ute av lista. En dag uten noe er ikke en dag med
 *      aapningstid — den er en dag det ikke skjer noe.
 *
 * Avlyste datoer teller ikke. Kladder teller ikke. En dato uten tidspunkt
 * teller ikke — den kan ikke si noe om naar det er aapent.
 *
 * Merk hva dette betyr: verkstedet er aapent for dem som gaar paa kurset.
 * Det er ikke det samme som at butikken er aapen for alle. Teksten paa
 * nettsiden sier hvilken av delene det gjelder.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

/** Hvor mange dager fram vi svarer for. */
const DAGER_FRAM = 14;

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$naa  = new DateTimeImmutable('now', $oslo);
$idag = $naa->setTime(0, 0);
$slutt = $idag->modify('+' . DAGER_FRAM . ' days');

// ── Kursene ────────────────────────────────────────────────────────────────
//
// Bare det som faktisk gaar: planlagt, paa et kurs som er publisert, og med
// et tidspunkt. En drop-in ingen har meldt seg paa er en aapen doer og
// teller med — den er nettopp en aapningstid.
$okter = DB::alle(
    "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.fra_dropin_tid, c.tittel, c.tema, c.type,
            (SELECT COUNT(*) FROM bookings b
              WHERE b.course_session_id = cs.id
                AND b.status IN ('betalt','reservert')) AS pameldte
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
      WHERE cs.status = 'planlagt'
        AND c.status = 'publisert'
        AND cs.start_tid IS NOT NULL
        AND cs.start_tid >= :fra
        AND cs.start_tid < :til
   ORDER BY cs.start_tid",
    [
        'fra' => $idag->setTimezone($utc)->format('Y-m-d H:i:s'),
        'til' => $slutt->setTimezone($utc)->format('Y-m-d H:i:s'),
    ]
);

// ── Drop-in-oekter som ikke svarer til en aapningstid lenger ───────────────
//
// Drop-in-tidene lages av ukereglene den dagen noen trykker «Legg ut tidene»,
// og blir liggende. Endres reglene etterpaa, ryddes bare de framtidige som
// ingen har booket — og en oekt lagt inn for haand ryddes aldri.
//
// Da sa nettsiden «aapent til 19» av en oekt som ikke sto noe sted: skjermen
// viste reglene, basen hadde noe annet. Verkstedet lette etter et kurs som
// ikke fantes.
//
// Her gjelder reglene. En drop-in-oekt teller bare med naar den svarer til en
// aapningstid som staar oppe naa — eller naar noen faktisk har booket den,
// for da er doeren aapen uansett hva reglene sier.
$regler = [];
if (DB::harTabell('dropin_tider')) {
    foreach (DB::alle('SELECT ukedag, fra, til FROM dropin_tider WHERE aktiv = 1') as $r) {
        $regler[(int) $r['ukedag'] . ' ' . substr((string) $r['fra'], 0, 5)
              . '-' . substr((string) $r['til'], 0, 5)] = true;
    }
}

$okter = array_values(array_filter($okter, static function (array $o) use ($regler, $oslo, $utc): bool {
    $erDropin = (string) ($o['type'] ?? '') === 'dropin' || $o['fra_dropin_tid'] !== null;
    if (!$erDropin || (int) $o['pameldte'] > 0) {
        return true;
    }
    $start = (new DateTimeImmutable((string) $o['start_tid'], $utc))->setTimezone($oslo);
    $stopp = $o['slutt_tid'] !== null
        ? (new DateTimeImmutable((string) $o['slutt_tid'], $utc))->setTimezone($oslo)
        : null;
    $nokkel = $start->format('N') . ' ' . $start->format('H:i')
            . '-' . ($stopp !== null ? $stopp->format('H:i') : '');

    return isset($regler[$nokkel]);
}));

/** @var array<string, array{fra: string, til: string}> */
$avKurs = [];
/**
 * Hvilke oekter hver dag er regnet av.
 *
 * Uten dette staar det «10–19» i bunnteksten og ingen kan se hvor tallene
 * kommer fra. Verkstedet spurte 27. august hvilket kurs som gikk til 19:00
 * og fant det ikke — svaret laa bare i denne utregningen.
 *
 * @var array<string, array<int, array<string, mixed>>>
 */
$kilder = [];

foreach ($okter as $o) {
    $start = (new DateTimeImmutable((string) $o['start_tid'], $utc))->setTimezone($oslo);
    // Mangler sluttiden, regner vi tre timer. Da er det verdt aa si fra: en
    // oekt som begynner 16:00 uten sluttid gjor dagen aapen til 19:00, og
    // det er et tall ingen har skrevet inn.
    $antattSlutt = $o['slutt_tid'] === null;
    $stopp = $o['slutt_tid'] !== null
        ? (new DateTimeImmutable((string) $o['slutt_tid'], $utc))->setTimezone($oslo)
        : $start->modify('+3 hours');
    if ($stopp <= $start) {
        $stopp = $start->modify('+3 hours');
        $antattSlutt = true;
    }

    // Et kurs som gaar over to dager gjor begge dagene aapne. Vi gaar dag for
    // dag fra start til slutt, og klipper mot dognet.
    $dag = $start->setTime(0, 0);
    $sisteDag = $stopp->setTime(0, 0);
    while ($dag <= $sisteDag) {
        $nokkel = $dag->format('Y-m-d');
        $fra = $dag->format('Y-m-d') === $start->format('Y-m-d') ? $start->format('H:i') : '00:00';
        $til = $dag->format('Y-m-d') === $stopp->format('Y-m-d') ? $stopp->format('H:i') : '23:59';
        if (!isset($avKurs[$nokkel])) {
            $avKurs[$nokkel] = ['fra' => $fra, 'til' => $til];
        } else {
            $avKurs[$nokkel]['fra'] = min($avKurs[$nokkel]['fra'], $fra);
            $avKurs[$nokkel]['til'] = max($avKurs[$nokkel]['til'], $til);
        }
        // Hva slags oppforing det er, og hvor den settes.
        //
        // «Drop-in i verkstedet» er ikke et kurs man melder seg paa — det er
        // aapningstidene under Drop-in, lagt ut som bookbare oekter. Staar
        // det bare et kursnavn her, leter man etter et kurs som ikke finnes.
        $slag = 'Kursdato';
        $satt  = 'Kurs og medlemskap → kurset';
        if ((string) ($o['type'] ?? '') === 'dropin' || $o['fra_dropin_tid'] !== null) {
            $slag = 'Drop-in-tid';
            $satt = 'Kurs og medlemskap → Drop-in';
        } elseif ((string) ($o['tema'] ?? '') === 'Kun for medlemmer') {
            $slag = 'Intern samling';
            $satt = 'Medlemmer → Kurs';
        }

        $kilder[$nokkel][] = [
            'oktId'       => (int) $o['id'],
            'tittel'      => (string) $o['tittel'],
            'tema'        => (string) ($o['tema'] ?? ''),
            'slag'        => $slag,
            'satt'        => $satt,
            'fra'         => $fra,
            'til'         => $til,
            'antattSlutt' => $antattSlutt,
        ];
        $dag = $dag->modify('+1 day');
    }
}

// ── Overstyringene ─────────────────────────────────────────────────────────
$manuelt = [];
if (DB::harTabell('apningstider')) {
    foreach (DB::alle(
        'SELECT dato, stengt, fra, til, merknad FROM apningstider
          WHERE dato >= :fra AND dato < :til',
        ['fra' => $idag->format('Y-m-d'), 'til' => $slutt->format('Y-m-d')]
    ) as $r) {
        $manuelt[(string) $r['dato']] = $r;
    }
}

// ── Svaret ─────────────────────────────────────────────────────────────────
$DAG = [1 => 'Mandag', 2 => 'Tirsdag', 3 => 'Onsdag', 4 => 'Torsdag',
        5 => 'Fredag', 6 => 'Lørdag', 7 => 'Søndag'];
$MND = [1 => 'januar', 'februar', 'mars', 'april', 'mai', 'juni',
        'juli', 'august', 'september', 'oktober', 'november', 'desember'];

$ut = [];
for ($i = 0; $i < DAGER_FRAM; $i++) {
    $dag = $idag->modify('+' . $i . ' days');
    $nokkel = $dag->format('Y-m-d');
    $m = $manuelt[$nokkel] ?? null;
    $k = $avKurs[$nokkel] ?? null;

    // Overstyringen gaar foran. Er den ikke der, gjelder kursene.
    if ($m !== null) {
        $stengt = (int) $m['stengt'] === 1;
        $fra = $m['fra'] !== null ? substr((string) $m['fra'], 0, 5) : ($k['fra'] ?? null);
        $til = $m['til'] !== null ? substr((string) $m['til'], 0, 5) : ($k['til'] ?? null);
        // Stengt, eller satt uten tider og uten kurs: da er det ingenting
        // aa si om naar det er aapent.
        if (!$stengt && ($fra === null || $til === null)) {
            continue;
        }
    } elseif ($k !== null) {
        $stengt = false;
        $fra = $k['fra'];
        $til = $k['til'];
    } else {
        continue;   // ingen kurs, ingen overstyring — dagen staar ikke ute
    }

    $ut[] = [
        'dato'    => $nokkel,
        'dag'     => $DAG[(int) $dag->format('N')],
        'naar'    => (int) $dag->format('j') . '. ' . $MND[(int) $dag->format('n')],
        'idag'    => $i === 0,
        'stengt'  => $stengt,
        'fra'     => $stengt ? null : $fra,
        'til'     => $stengt ? null : $til,
        'tid'     => $stengt ? 'Stengt' : $fra . '–' . $til,
        'merknad' => (string) ($m['merknad'] ?? ''),
        // Sier hva aapningstida gjelder. Verkstedet er aapent for dem som
        // gaar paa kurset — det er ikke det samme som at butikken staar aapen
        // for alle som gaar forbi.
        'hva'     => $m !== null && $m['merknad'] ? (string) $m['merknad'] : 'Kurs og events',
        // Om dagen er satt for hand framfor regnet av kursene.
        'overstyrt' => $m !== null,
        // Oektene tallene kommer av, i den rekkefolgen de gaar. Tomt naar
        // dagen er satt for hand uten at det gaar noe.
        'okter'   => array_values(array_map(
            static fn(array $kilde) => [
                'tittel'      => $kilde['tittel'],
                'tema'        => $kilde['tema'],
                'slag'        => $kilde['slag'],
                'satt'        => $kilde['satt'],
                'naar'        => $kilde['fra'] . '–' . $kilde['til'],
                'antattSlutt' => $kilde['antattSlutt'],
            ],
            $kilder[$nokkel] ?? []
        )),
    ];
}

// ── Er verkstedet bemannet naa? ────────────────────────────────────────────
//
// Kalenderen sier hva som er avtalt. Innstemplinga sier hva som er sant.
// Staar verkstedet bemannet, er doeren aapen — ogsaa naar det ikke staar noe
// i kalenderen, og ogsaa etter at dagens kurs er ferdig.
$bemannet = Stempling::verkstedetBemannet();

if ($bemannet['apen']) {
    $idagNokkel = $idag->format('Y-m-d');
    $funnet = false;
    foreach ($ut as &$rad) {
        if ($rad['dato'] !== $idagNokkel) {
            continue;
        }
        $funnet = true;
        // «Aapent naa» gaar foran klokkeslettene: de sier naar det er satt
        // opp noe, ikke at doeren staar aapen.
        $rad['apenNa']   = true;
        $rad['apenSiden'] = $bemannet['siden'];
        $rad['planlagt'] = $rad['tid'];
        $rad['tid']      = 'Åpent nå';
        $rad['stengt']   = false;
    }
    unset($rad);

    // Ingen kurs i dag, men noen er der. Da staar dagen ikke i lista i det
    // hele tatt — og det er nettopp den dagen det er verdt aa si fra.
    if (!$funnet) {
        array_unshift($ut, [
            'dato'      => $idagNokkel,
            'dag'       => $DAG[(int) $idag->format('N')],
            'naar'      => (int) $idag->format('j') . '. ' . $MND[(int) $idag->format('n')],
            'idag'      => true,
            'stengt'    => false,
            'fra'       => $bemannet['siden'],
            'til'       => null,
            'tid'       => 'Åpent nå',
            'merknad'   => '',
            'hva'       => 'Åpen dør',
            'overstyrt' => false,
            'okter'     => [],
            'apenNa'    => true,
            'apenSiden' => $bemannet['siden'],
            'planlagt'  => 'Ingenting satt opp',
        ]);
    }
}

// Hvem som faar se hva tallene er regnet av.
//
// Lista rommer titlene paa de interne samlingene — «Glasurkveld for
// medlemmer» — og de staar ikke ute paa nettsiden. Selve klokkeslettene er
// offentlige som for; kildene bak dem er ikke.
$erAdmin = Sesjon::erAdmin();
if (!$erAdmin) {
    foreach ($ut as &$rad) {
        unset($rad['okter'], $rad['overstyrt']);
    }
    unset($rad);
}

// ── Lesbar utgave ──────────────────────────────────────────────────────────
//
// «Hvilket kurs gaar til 19 i dag?» er et sporsmaal som skal kunne besvares
// uten aa lete seg fram i admin. Med ?visning=tekst svarer denne adressen med
// hele regnestykket i klartekst: hva som staar ute, og hvilke oekter tallene
// kommer av — én linje per oekt, med hva slags oppforing det er og hvor den
// settes.
if (Foresporsel::tekst('visning') === 'tekst') {
    if (!$erAdmin) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Denne oversikten er for verkstedet. Logg inn som admin først.\n";
        exit;
    }
    $linjer = ['Åpningstidene på lissom.no, og hva de er regnet av.', ''];
    $linjer[] = $bemannet['apen']
        ? 'Verkstedet står innstemplet siden ' . $bemannet['siden'] . ' — nettsiden sier «Åpent nå».'
        : 'Ingen fra verkstedet er innstemplet nå.';
    $linjer[] = '';
    if ($ut === []) {
        $linjer[] = 'Ingen dager har åpningstid de neste ' . DAGER_FRAM . ' dagene.';
        $linjer[] = 'Det står ingen kursdatoer, drop-in-tider eller samlinger ute.';
    }
    foreach ($ut as $d) {
        $linjer[] = ($d['idag'] ? 'I DAG  ' : '       ')
            . $d['dag'] . ' ' . $d['naar'] . ':  ' . $d['tid']
            . ($d['overstyrt'] ? '   (satt for hånd)' : '');
        if ($d['okter'] === []) {
            $linjer[] = '           ingen økter — tiden er satt for hånd';
        }
        foreach ($d['okter'] as $o) {
            $bredde = static fn(string $t, int $n): string
                => $t . str_repeat(' ', max(1, $n - mb_strlen($t)));
            $linjer[] = '           ' . $bredde($o['naar'], 14) . $bredde($o['slag'], 17)
                . $o['tittel']
                . ($o['antattSlutt'] ? '   [sluttid mangler — regnet som tre timer]' : '')
                . '   → ' . $o['satt'];
        }
        $linjer[] = '';
    }
    $linjer[] = 'Regelen: verkstedet er åpent fra den første økta begynner til den';
    $linjer[] = 'siste slutter. Drop-in-tider teller med — det er en åpen dør.';
    $linjer[] = 'Avlyste datoer og upubliserte kurs teller ikke.';

    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", $linjer) . "\n";
    exit;
}

Svar::json([
    'dager'    => $ut,
    'dagerFram' => DAGER_FRAM,
    // Staar verkstedet bemannet akkurat naa? Da er doeren aapen, og drop-in
    // er en aapen doer — man kan komme innom uten aa booke.
    'apenNa'    => $bemannet['apen'],
    'apenSiden' => $bemannet['siden'],
    // Én setning som forklarer hva lista er. Uten den staar det «10–19» og
    // noen kommer for aa handle midt i et kurs.
    'forklaring' => 'Verkstedet er åpent når det går kurs. Butikken og drop-in '
                  . 'har egne tider — se drop-in-siden eller ta kontakt.',
]);
