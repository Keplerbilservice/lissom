<?php
/**
 * Artiklene og lenkene under Nyttig info.
 *
 * Aapent med vilje: dette er innholdet paa nettsiden. Skriving skjer et annet
 * sted, i api/admin/verksted.php, og krever admin.
 *
 * Oppskriftene er ikke med. De er for verkstedet, ikke for publikum.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$artikler = DB::alle("SELECT * FROM articles WHERE status = 'publisert' ORDER BY sortering, id DESC");
$lenker   = DB::alle('SELECT * FROM links ORDER BY sortering, navn');

Svar::json([
    'artikler' => array_map(static fn($a) => [
        'tittel'  => $a['tittel'],
        'dato'    => $a['dato'],
        'ingress' => $a['ingress'],
        'bilde'   => $a['bilde'],
        'innhold' => $a['innhold'],
    ], $artikler),
    'lenker' => array_map(static fn($l) => [
        'navn' => $l['navn'],
        'url'  => $l['url'],
        'om'   => $l['om'],
    ], $lenker),
], 200, 60);
