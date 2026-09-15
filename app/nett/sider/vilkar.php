<?php
/** Salgsvilkaar — /vilkar. Skjermen «Vilkår» fra lissom-2108.html, tegnet av Mal; punktene fra vilkar.json (bin/innholdkart.mjs). */

declare(strict_types=1);

$vilkar = json_decode((string) @file_get_contents(Nett::rot() . "/vilkar.json"), true);
return [
    'kropp' => Mal::tegn('Vilkår', ['salgsvilkar' => is_array($vilkar) ? $vilkar : [], 'salgsvilkarSlutt' => 'Ved bestilling, påmelding eller registrering av medlemskap bekrefter kunden at disse vilkårene er lest og akseptert.', 'sant' => true], []) . "\n" . Deler::bunn(true),
    'aktiv' => '',
];
