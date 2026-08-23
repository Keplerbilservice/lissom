<?php
/**
 * Nyheter og guider — det som er publisert i kunnskapsbanken.
 *
 *   GET             alle publiserte, nyeste forst
 *   GET ?slug=...   én artikkel
 *
 * Aapent endepunkt. Kladd kommer aldri ut herfra.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$ut = static fn(array $a): array => [
    'id'       => (int) $a['id'],
    'tittel'   => $a['tittel'],
    'kategori' => $a['kategori'],
    'slug'     => $a['slug'],
    'ingress'  => $a['ingress'],
    'innhold'  => $a['innhold'],
    'bilde'    => $a['bilde'],
    'dato'     => $a['dato'] ?: Booking::norskDatoKort((string) $a['updated_at']),
];

$slug = Foresporsel::tekst('slug');
if ($slug !== '') {
    $a = DB::en(
        "SELECT * FROM articles WHERE slug = :s AND status = 'publisert'",
        ['s' => mb_substr($slug, 0, 191)]
    );
    if ($a === null) {
        Svar::feil('Fant ikke artikkelen.', 404);
    }
    Svar::json(['artikkel' => $ut($a)], 200, 300);
}

$rader = DB::alle("SELECT * FROM articles WHERE status = 'publisert' ORDER BY sortering, id DESC");
Svar::json([
    'artikler'   => array_map($ut, $rader),
    'kategorier' => array_values(array_filter(array_unique(array_column($rader, 'kategori')),
        static fn($k) => trim((string) $k) !== '')),
], 200, 300);
