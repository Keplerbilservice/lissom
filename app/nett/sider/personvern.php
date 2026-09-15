<?php
/** Personvern — /personvern. Skjermen fra lissom-2108.html, tegnet av Mal. */

declare(strict_types=1);

$pv = [];
for ($i = 1; $i <= 10; $i++) {
    $pv[] = ['h' => Nett::innh('Personvern/' . $i . '/Overskrift'), 'p' => Nett::innh('Personvern/' . $i . '/Tekst')];
}
return [
    // «Ditt svar paa besoeksmaaling» tegnes, og nett.js viser den bare naar
    // noen har svart — svaret ligger i nettleseren, ikke paa serveren.
    'kropp' => Mal::tegn('Personvern', ['personvern' => $pv, 'visAnalyseValg' => true, 'analyseSvarTekst' => '', 'sant' => true], ['endreAnalyse' => 'js:endreAnalyse']) . "\n" . Deler::bunn(true),
    'aktiv' => '',
];
