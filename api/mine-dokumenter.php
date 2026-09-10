<?php
/**
 * Dokumentene medlemmet faar se.
 *
 *   GET   kortene som er slaatt paa, med filene i dem
 *
 * Nyttig info er en aapen side — api/nyttig.php er tilgjengelig uten
 * innlogging, med vilje, fordi det er innholdet paa nettsida. Dokumentene fra
 * verkstedet kan ikke ligge der: en kontrakt er ikke noe hvem som helst skal
 * kunne lese.
 *
 * Derfor sin egen vei, som krever innlogging. Kortet som ikke er slaatt paa
 * finnes ikke her — verken navnet eller antallet.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

krev_aktivt_medlem();

if (!Dokumenter::klar()) {
    Svar::json(['kategorier' => [], 'faq' => false]);
}

$kategorier = Dokumenter::kategorier(true);
$filer      = Dokumenter::dokumenter(null, true);

// Filene lagt under kortet sitt, saa skjermen slipper aa lete.
$perKort = [];
foreach ($filer as $f) {
    $perKort[$f['kategori']][] = [
        'id'        => $f['id'],
        'navn'      => $f['navn'],
        'type'      => $f['type'],
        'storrelse' => $f['storrelse'],
        'kanVises'  => Dokumenter::kanVises($f['mime']),
    ];
}

Svar::json([
    'kategorier' => array_map(static fn($k) => [
        'id'     => $k['id'],
        'navn'   => $k['navn'],
        'antall' => $k['antall'],
        'filer'  => $perKort[$k['id']] ?? [],
    ], $kategorier),
    'faq' => Dokumenter::faqForMedlem(),
]);
