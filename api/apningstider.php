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

// Regelen for naar det er aapent ligger i app/lib/apent.php. Den brukes ogsaa
// av bookingen — Paint on Pots settes opp paa de aapne vinduene — og to
// utgaver av naar doeren staar aapen er én for mye.
const DAGER_FRAM = Apent::DAGER_FRAM;

$oslo = new DateTimeZone('Europe/Oslo');
$idag = (new DateTimeImmutable('now', $oslo))->setTime(0, 0);

['dager' => $ut, 'kilder' => $kilder] = Apent::dager(DAGER_FRAM);

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
