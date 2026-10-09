<?php
/**
 * lissom.no/messe — messevisningen for bedrifter på iPad (eieren, GO 9. oktober 2026).
 *
 * PIN-skjermen til iPaden har låst opp med kassens PIN (api/messe.php), så
 * visningen. Begge sidene ligger utenfor webroten (app/nett/messe/) og sendes
 * bare herfra. Se app/lib/messe.php.
 */

declare(strict_types=1);

require __DIR__ . '/api/_boot.php';

if (!Messe::paa()) {
    Messe::ikkeFunnet();
}

$fil = APP_DIR . '/nett/messe/' . (Messe::ulast() ? 'visning.html' : 'pin.html');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
readfile($fil);
