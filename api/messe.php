<?php
/**
 * Messevisningen på iPaden: lås opp med kassens PIN (eieren, 9. oktober 2026).
 *
 *   POST { pin }   riktig PIN gir informasjonskapselen (30 dager), og /messe
 *                  viser visningen. Se app/lib/messe.php.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

if (!Messe::paa()) {
    Svar::feil('Fant ikke siden.', 404);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (!KasseTilgang::klar()) {
    Svar::feil('Kassa krever en oppdatering av databasen. Trykk ⚙ Kjør oppdateringer i admin.', 503);
}

Messe::laasOpp(Foresporsel::tekst('pin'));
Svar::ok();
