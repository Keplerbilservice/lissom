<?php
/**
 * Tekstene eieren har endret i admin.
 *
 * Aapent med vilje: dette er innholdet paa nettsiden. Skulle det vaert bak
 * innlogging, ville besokende sett de gamle tekstene fra designfila mens
 * eieren saa sine egne endringer — to versjoner av samme side.
 *
 * Skriving skjer et annet sted, i api/admin/innhold.php, og krever admin.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$rader = DB::alle('SELECT nokkel, verdi FROM content_blocks');

$ut = [];
foreach ($rader as $r) {
    $ut[$r['nokkel']] = $r['verdi'];
}

// Tekstene endres sjelden. Ett minutts mellomlagring sparer databasen for et
// oppslag ved hvert eneste sidevisning, uten at en endring blir staaende lenge.
Svar::json(['innhold' => (object) $ut], 200, 60);
