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

// Planlagte artikler som har naadd tidspunktet sitt gaar ut her.
//
// Det gjores ikke av en cron-jobb: da ville tidspunktet avhengt av at det ble
// lagt inn en linje til i cPanel, og en artikkel som skulle ut klokka ni ville
// blitt liggende til noen husket det. Se app/lib/artikler.php.
Artikler::publiserForfalte();

$ut = static fn(array $a): array => [
    'id'       => (int) $a['id'],
    'tittel'   => $a['tittel'],
    'kategori' => $a['kategori'],
    'slug'     => $a['slug'],
    'ingress'  => $a['ingress'],
    'innhold'  => $a['innhold'],
    'bilde'    => $a['bilde'],
    // Bildene inne i teksten, med bildetekst, alt-tekst, plassering og
    // stoerrelse. Tom liste betyr en artikkel med bare tekst, som for.
    'bilder'   => Artikler::bilder((int) $a['id']),
    'dato'     => $a['dato'] ?: Booking::norskDatoKort((string) $a['updated_at']),
];

$slug = Foresporsel::tekst('slug');
if ($slug !== '') {
    $a = DB::en(
        "SELECT * FROM articles WHERE slug = :s AND status = 'publisert'",
        ['s' => mb_substr($slug, 0, 191)]
    );

    // Forhaandsvisning for den som skriver.
    //
    // «Publiser» var det forste stedet man saa hvordan artikkelen ble. Er du
    // logget inn som admin, kan du apne adressen for den er ute — samme side,
    // samme oppsett, med et merke om at den ikke ligger ute enda. Ingen andre
    // slipper til: dette er det eneste stedet en upublisert artikkel kommer
    // ut av basen, og det staar bak Sesjon::erAdmin().
    $utkast = false;
    if ($a === null && Sesjon::erAdmin()) {
        $a = DB::en('SELECT * FROM articles WHERE slug = :s', ['s' => mb_substr($slug, 0, 191)]);
        $utkast = $a !== null;
    }

    if ($a === null) {
        Svar::feil('Fant ikke artikkelen.', 404);
    }
    // En forhaandsvisning skal ikke ligge i noen mellomlagring — verken hos
    // leseren eller underveis. Neste gang kan den vaere endret, og neste
    // besokende er kanskje ikke admin.
    Svar::json(
        ['artikkel' => $ut($a) + ($utkast ? ['utkast' => true, 'utkastStatus' => (string) $a['status']] : [])],
        200,
        $utkast ? null : 300
    );
}

$rader = DB::alle("SELECT * FROM articles WHERE status = 'publisert' ORDER BY sortering, id DESC");
Svar::json([
    'artikler'   => array_map($ut, $rader),
    'kategorier' => array_values(array_filter(array_unique(array_column($rader, 'kategori')),
        static fn($k) => trim((string) $k) !== '')),
], 200, 300);
