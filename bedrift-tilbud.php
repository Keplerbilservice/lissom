<?php
/**
 * lissom.no/bedrift/tilbud — skjemaet bak QR-koden på messevisningen
 * (eieren, GO 9. oktober 2026). Sender til api/foresporsel.php, så svaret
 * havner i «Må gjøres» i ny admin og som e-post til verkstedet, som andre
 * forespørsler. Bryteren «Vis/messe» slår det av. Se app/lib/messe.php.
 *
 * /bedrift er bedriftssiden på nettsida (indeksert), og røres ikke.
 */

declare(strict_types=1);

require __DIR__ . '/api/_boot.php';

if (!Messe::paa()) {
    Messe::ikkeFunnet();
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');
readfile(APP_DIR . '/nett/messe/bedrift.html');
