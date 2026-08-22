<?php
/**
 * Kunden er tilbake fra Vipps etter aa ha godkjent (eller avvist) en avtale.
 *
 * Vi tar ikke returen som bevis paa noe. Vi sporr Vipps om status, og sender
 * kunden videre til Min side. Kom hen aldri tilbake, tar cron det neste gang.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$medlem = Sesjon::medlem();
if ($medlem !== null) {
    $a = Medlemskap::avtale((int) $medlem['id']);
    if ($a !== null) {
        Medlemskap::oppdaterFraVipps($a);
    }
}

header('Location: ' . Config::nettsted() . '/min-side?avtale=1');
exit;
