<?php
/**
 * Starter innloggingen: sender brukeren videre til Vipps.
 *
 * Erstatter api/login.js fra testoppsettet på Vercel.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');
Rate::sjekk('login-start', maks: 20, vindu: 300);

// Hvor brukeren skal tilbake til etter innlogging. Kun stier på vårt eget
// nettsted godtas — ellers kunne noen brukt oss til å sende folk hvor som helst.
$retur = Foresporsel::tekst('retur', '/');
if (!str_starts_with($retur, '/') || str_starts_with($retur, '//')) {
    $retur = '/';
}

$state = bin2hex(random_bytes(32));

try {
    Svar::omdiriger(Vipps::loginUrl($state, $retur));
} catch (Throwable $e) {
    logg_feil('Kunne ikke starte Vipps-innlogging', $e);
    Svar::omdiriger(Config::nettsted() . '/?innlogging=feilet');
}
