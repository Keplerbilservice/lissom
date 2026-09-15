<?php
/** Kursene vaare — /kursene-vare. Skjermen fra lissom-2108.html, tegnet av Mal; kortene som koKurs i nettsida. */

declare(strict_types=1);

$katalog = [];
foreach (Katalog::offentlig(false) as $k) {
    $katalog[(string) $k['slug']] = $k;
}
$ko = [];
foreach (Kort::kurs() as $k) {
    $kat = $katalog[$k['slug']] ?? [];
    $fakta = [];
    foreach ([['Varighet', (string) ($k['duration'] ?? '')], ['Tid', (string) ($kat['varighet'] ?? '')], ['Pris', (string) ($k['price'] ?? '')], ['Nivå', (string) ($kat['passerNivaa'] ?? '')], ['Passer for', (string) ($kat['passerHvem'] ?? '')], ['Teknikk', (string) ($kat['metode'] ?? '')]] as [$n, $v]) {
        if ($v !== '') {
            $fakta[] = ['navn' => $n, 'verdi' => $v];
        }
    }
    $punkter = (array) ($kat['punkter'] ?? []);
    $ko[] = [
        'tittel'    => $k['title'],
        'merke'     => $k['level'] ?: ($k['tema'] ?: 'Kurs'),
        'om'        => (string) (($kat['om'] ?? '') ?: 'Beskrivelsen av dette kurset er ikke skrevet ennå. Ta kontakt, så forteller vi gjerne om det.'),
        'harFakta'  => $fakta !== [],
        'fakta'     => $fakta,
        'harPunkter' => $punkter !== [],
        'punkter'   => $punkter,
        'status'    => (string) ($k['status'] ?? ''),
        'knapp'     => (string) ($k['cta'] ?? 'Book plass'),
        'href'      => $k['href'],
        'altTekst'  => $k['title'] . ' hos Lissom i Tønsberg',
        'bildeSti'  => $k['image'],
        'bildeStil' => 'width: 100%; aspect-ratio: 4 / 3; border-radius: var(--radius-lg); background-color: var(--clay-200); background-image: ' . ($k['image'] !== '' ? Nett::cssUrl($k['image']) : 'none') . '; background-size: cover; background-position: ' . Nett::fokus($k['image']) . ';',
    ];
}
$forste = $ko[0] ?? null;
return [
    'kropp' => Mal::tegn('Kursene våre', ['koKurs' => $ko, 'koTomt' => $ko === [], 'sant' => true], ['goKurs' => '/kurs', 'goKalender' => '/kalender']) . "\n" . Deler::bunn(true),
    'aktiv' => 'Kurs',
    // Det foerste kursbildet er det stoerste paa sida (LCP).
    'hode'  => $forste !== null && $forste['bildeSti'] !== '' ? '<link rel="preload" as="image" href="' . Nett::e($forste['bildeSti']) . '">' . "\n" : '',
];
