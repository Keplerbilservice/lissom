<?php
/**
 * Kursveilederen — spoersmaalene slik de staar naa.
 *
 *   GET   alle aktive spoersmaal, med svarene sine
 *
 * Aapent endepunkt, som api/innhold.php: dette er teksten paa nettsida.
 * Skriving skjer i api/admin/veileder.php og krever admin.
 *
 * Er ikke migrasjon 066 kjoert, svarer vi med en tom liste. Da bruker
 * nettsida de tre spoersmaalene som ligger i koden, som foer — framfor en
 * veileder som ikke aapner seg.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

Svar::json([
    'klar'     => Veileder::klar(),
    'sporsmal' => Veileder::sporsmal(true),
], 200, 120);
