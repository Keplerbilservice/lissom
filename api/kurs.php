<?php
/**
 * Kurskatalogen med ledige plasser. Aapent endepunkt — dette er offentlig
 * informasjon, det samme som staar paa kurssiden.
 *
 * Med ett unntak: samlinger merket «Kun for medlemmer» sendes bare til den
 * som er innlogget som medlem. De sto tidligere i den offentlige lista, saa
 * en medlemsfrokost var synlig for alle — bookbar var den riktignok ikke.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$erMedlem = ($m = Sesjon::medlem()) !== null && er_aktivt_medlem($m);

// Katalogen bygges i app/lib/katalog.php — serversidene tegner av den samme.
// Fokuspunktene: hvilken del av hvert bilde ramma skal sentreres paa.
Svar::json(['kurs' => Katalog::offentlig($erMedlem), 'rabatter' => Katalog::rabatter(), 'fokus' => Bilder::fokus()]);
