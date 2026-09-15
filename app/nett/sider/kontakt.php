<?php
/** Kontakt — /kontakt. Skjermen fra lissom-2108.html, tegnet av Mal; aapningstidene som i nettsida. */

declare(strict_types=1);

$linjer = [];
$forklaring = 'Verkstedet er åpent når det går kurs. Butikken har egne tider.';
try {
    $dager = Apent::dager(Apent::DAGER_FRAM)['dager'] ?? [];
    $bemannet = Stempling::verkstedetBemannet();
    $idag = null; $neste = [];
    foreach ($dager as $d) {
        if (!empty($d['idag'])) { $idag = $d; } elseif (count($neste) < 2) { $neste[] = $d; }
    }
    if ($bemannet['apen'] ?? false) {
        $idag = ($idag ?? []) + ['stengt' => false];
        $idag['tid'] = 'Åpent nå';
        $idag['stengt'] = false;
        $forklaring = 'Noen er i verkstedet nå, så døra er åpen. Bare kom innom, så finner vi en plass til deg.';
    }
    if ($idag !== null && !empty($idag['stengt'])) { $linjer[] = ['dag' => 'I dag', 'tid' => (string) (($idag['merknad'] ?? '') ?: 'Stengt')]; }
    elseif ($idag !== null) { $linjer[] = ['dag' => 'I dag', 'tid' => (string) ($idag['tid'] ?? '')]; }
    else { $linjer[] = ['dag' => 'I dag', 'tid' => 'Etter avtale']; }
    foreach ($neste as $d) {
        $linjer[] = ['dag' => $d['dag'] . ' ' . $d['naar'], 'tid' => !empty($d['stengt']) ? (string) (($d['merknad'] ?? '') ?: 'Stengt') : (string) $d['tid']];
    }
} catch (Throwable) {
    $linjer = [['dag' => 'Verkstedet', 'tid' => 'Etter avtale'], ['dag' => 'Kurs og events', 'tid' => 'Se datoer under kurs']];
}
$linjer[] = ['dag' => 'Medlemmer', 'tid' => 'Døgnåpent, 24 timer'];
return [
    // Foresporselsskjemaet er en rute i appen: knappen aapner den der.
    'kropp' => Mal::tegn('Kontakt', ['apningstider' => $linjer, 'apningsforklaring' => $forklaring, 'sant' => true], ['goForesporsel' => '/kontakt?skjema=1']) . "\n" . Deler::bunn(false),
    'aktiv' => '',
];
