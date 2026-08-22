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

// Innlogget er ikke det samme som medlem. Vipps Login sier hvem noen er;
// medlemskapet er noe verkstedet godkjenner. Frontenden trenger begge deler
// for aa vite hva den skal vise paa Min side.
$soknad = DB::en(
    'SELECT status FROM membership_applications WHERE member_id = :m ORDER BY id DESC LIMIT 1',
    ['m' => $m['id']]
);

Svar::json([
    'innlogget'      => true,
    'erAdmin'        => Sesjon::erAdmin(),
    'erMedlem'       => er_aktivt_medlem($m),
    'soknadStatus'   => $soknad ? (string) $soknad['status'] : null,
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
