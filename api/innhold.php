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

// Noen tekster hoerer hjemme bak innlogging: dorkoden og wifi-passordet til
// verkstedet staar under «Min side» i innholdsredigeringen, og de skal ikke
// ut av dette endepunktet — det er aapent, og maa vaere det.
//
// Sperren staar her, ikke i admin: skriver eieren noe internt i et felt vi
// ikke har tenkt paa, er det bedre at det blir liggende enn at det gaar ut.
$internt = static fn(string $n): bool =>
    str_starts_with($n, 'Min side/') || str_starts_with($n, 'Privat/');

$rader = DB::alle('SELECT nokkel, verdi FROM content_blocks');

$ut = [];
foreach ($rader as $r) {
    if ($internt((string) $r['nokkel'])) {
        continue;
    }
    $ut[$r['nokkel']] = $r['verdi'];
}

// Tekstene endres sjelden. Ett minutts mellomlagring sparer databasen for et
// oppslag ved hvert eneste sidevisning, uten at en endring blir staaende lenge.
Svar::json(['innhold' => (object) $ut], 200, 60);
