<?php
/**
 * Varene i butikken.
 *
 * Aapent endepunkt for det som er til salgs for alle. Internbutikkens varer
 * — leire, ekstra brenning — sendes kun til innloggede medlemmer, ellers
 * ville de dukket opp i den offentlige butikken.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$medlem = Sesjon::medlem();
$hvor = $medlem === null ? 'kun_medlemmer = 0' : '1';

$varer = DB::alle(
    "SELECT id, tittel, beskrivelse, bilde, kategori, pris_ore, lager, kun_medlemmer
       FROM products
      WHERE status = 'publisert' AND {$hvor}
      ORDER BY kun_medlemmer, kategori, tittel"
);

Svar::json(['varer' => array_map(static fn($v) => [
    'id'           => (int) $v['id'],
    // Varens egen adresse. Regnes her, ett sted, saa nettsida og serveren
    // ikke kan lage hver sin — det er serveren som svarer paa den.
    // Medlemsvarene faar ingen: de skal ikke ha en side noen kan lenke til.
    'sti'          => (int) $v['kun_medlemmer'] === 1
                        ? '' : Lenker::vare((int) $v['id'], (string) $v['tittel']),
    'tittel'       => $v['tittel'],
    'detalj'       => $v['beskrivelse'],
    'bilde'        => $v['bilde'],
    'kategori'     => $v['kategori'],
    'pris'         => Booking::kroner((int) $v['pris_ore']),
    'prisOre'      => (int) $v['pris_ore'],
    'utsolgt'      => $v['lager'] !== null && (int) $v['lager'] <= 0,
    'kunMedlemmer' => (bool) $v['kun_medlemmer'],
], $varer), 'fokus' => Bilder::fokus()]);
