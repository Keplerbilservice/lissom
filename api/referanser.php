<?php
/**
 * Referansekundene, slik de vises paa forsida.
 *
 * Aapent med vilje: dette staar paa nettsida og skal kunne leses av hvem som
 * helst. Skriving skjer i api/admin/referanser.php og krever admin.
 *
 * Bare kunder som baade er slaatt paa og har sagt ja. Samtykket er et eget
 * felt fordi det er en annen ting enn «vis denne naa»: en kunde kan trekke
 * samtykket, og da skal kortet vekk selv om noen glemte aa slaa det av.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

if (!DB::harTabell('referansekunder')) {
    Svar::json(['kunder' => []]);
}

$rader = DB::alle(
    'SELECT id, navn, bilde, tekst, sitat, sitat_av, lenke
       FROM referansekunder
      WHERE aktiv = 1 AND samtykke = 1
   ORDER BY sortering, navn'
);

Svar::json([
    'kunder' => array_map(static fn($r) => [
        'id'      => (int) $r['id'],
        'navn'    => (string) $r['navn'],
        // Filnavnet, slik kursbildene ogsaa oppgis. Nettsida setter det
        // sammen selv, og faar da de mindre utgavene paa kjopet.
        'bilde'   => (string) ($r['bilde'] ?? ''),
        'tekst'   => (string) ($r['tekst'] ?? ''),
        'sitat'   => (string) ($r['sitat'] ?? ''),
        'sitatAv' => (string) ($r['sitat_av'] ?? ''),
        'lenke'   => (string) ($r['lenke'] ?? ''),
    ], $rader),
]);
