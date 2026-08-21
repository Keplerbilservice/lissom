<?php
/**
 * Hvem er innlogget?
 *
 * Frontenden kaller denne ved oppstart i stedet for å lese cookien selv —
 * cookien er HttpOnly og utilgjengelig for JavaScript.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$m = Sesjon::medlem();

if ($m === null) {
    Svar::json(['innlogget' => false]);
}

Svar::json([
    'innlogget' => true,
    'erAdmin'   => Sesjon::erAdmin(),
    'medlem'    => [
        'id'        => (int) $m['id'],
        'navn'      => (string) $m['navn'],
        'epost'     => $m['epost'],
        'telefon'   => $m['telefon'],
        'medlemskap'=> $m['medlemskap_type'],
        'status'    => (string) $m['status'],
        'startDato' => $m['start_dato'],
    ],
]);
