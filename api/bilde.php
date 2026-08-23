<?php
/**
 * Serverer et opplastet bilde.
 *
 *   GET ?salg=<filnavn>       bilde til en vare i internbutikken
 *   GET ?artikkel=<filnavn>   bilde eieren har lastet opp til en artikkel
 *
 * Filene ligger utenfor det som publiseres, saa de maa gaa gjennom PHP. Det
 * er ikke bare en ulempe: her kan vi la vaere aa vise bildet til en vare som
 * ikke er godkjent ennaa.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

/**
 * Send fila og avslutt.
 *
 * Bildene endrer seg aldri — navnet er tilfeldig, saa en ny fil faar et nytt
 * navn. Da kan nettleseren beholde det i et aar uten aa sporre igjen.
 */
function lever(string $sti): never
{
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($sti));
    header('Cache-Control: public, max-age=604800, immutable');
    header('X-Content-Type-Options: nosniff');
    readfile($sti);
    exit;
}

// Bilder til artikler er aapne for alle — de staar paa nettsida uansett.
$artikkel = Foresporsel::tekst('artikkel');
if ($artikkel !== '') {
    $sti = Bilder::sti($artikkel, 'artikler');
    if ($sti === null) {
        Svar::feil('Fant ikke bildet.', 404);
    }
    lever($sti);
}

$navn = Foresporsel::tekst('salg');
$sti  = Bilder::sti($navn, 'medlemssalg');

if ($sti === null) {
    Svar::feil('Fant ikke bildet.', 404);
}

// Bilder til varer som venter paa godkjenning, er avvist eller tatt ned skal
// ikke ligge aapent. Eieren og selgeren selv far se dem.
$rad = DB::en('SELECT member_id, status FROM member_sales WHERE bilde = :b', ['b' => $navn]);
if ($rad !== null && $rad['status'] !== 'publisert') {
    $m = Sesjon::medlem();
    $egen = $m !== null && (int) $m['id'] === (int) $rad['member_id'];
    if (!$egen && !Sesjon::erAdmin()) {
        Svar::feil('Fant ikke bildet.', 404);
    }
}

lever($sti);
