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
    "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.fra_dropin_tid, c.tittel, c.tema, c.type
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

Svar::json([
    'dager'    => $ut,
    'dagerFram' => DAGER_FRAM,
    // Én setning som forklarer hva lista er. Uten den staar det «10–19» og
    // noen kommer for aa handle midt i et kurs.
    'forklaring' => 'Verkstedet er åpent når det går kurs. Butikken og drop-in '
                  . 'har egne tider — se drop-in-siden eller ta kontakt.',
]);
