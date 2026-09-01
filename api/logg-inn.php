<?php
/**
 * Innlogging med brukernavn og passord.
 *
 * For verkstedet. Kundene logger inn med Vipps som for — der slipper de aa
 * finne paa enda et passord, og vi slipper aa oppbevare det.
 *
 * Sikkerheten ligger i tre ting:
 *   1. Passordet lagres bare som hash fra password_hash().
 *   2. Samme feilmelding uansett om brukernavnet finnes eller ikke, og et
 *      falskt hash-oppslag naar det ikke finnes — ellers kunne svartiden
 *      rope ut hvilke brukernavn som er i bruk.
 *   3. Ratebegrensning per IP og per brukernavn.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$brukernavn = mb_strtolower(trim(Foresporsel::tekst('brukernavn')));
$passord    = (string) (Foresporsel::kropp()['passord'] ?? '');

Rate::sjekk('innlogging-ip', maks: 15, vindu: 900);
if ($brukernavn !== '') {
    Rate::sjekk('innlogging-bruker', maks: 8, vindu: 900, nokkel: $brukernavn);
}

$feil = static function (): never {
    // Aldri «ukjent bruker» kontra «feil passord». Da ville skjemaet vaere et
    // oppslagsverk over hvem som har konto.
    Svar::feil('Feil brukernavn eller passord.', 401);
};

if ($brukernavn === '' || $passord === '') {
    $feil();
}

$m = DB::en('SELECT * FROM members WHERE brukernavn = :b', ['b' => $brukernavn]);

if (!$m || ($m['passord_hash'] ?? '') === '') {
    // Bruk like lang tid som en ekte sjekk.
    password_verify($passord, '$2y$12$usannsynligsaltsomikkematcherxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
    logg('Mislykket innlogging', ['brukernavn' => $brukernavn]);
    $feil();
}

if (!password_verify($passord, (string) $m['passord_hash'])) {
    logg('Mislykket innlogging', ['brukernavn' => $brukernavn]);
    $feil();
}

// Standardalgoritmen i PHP kan bli sterkere med tida. Da fornyes hashen ved
// neste innlogging, mens vi likevel har passordet i hende.
if (password_needs_rehash((string) $m['passord_hash'], PASSWORD_DEFAULT)) {
    DB::oppdater('members', ['passord_hash' => password_hash($passord, PASSWORD_DEFAULT)], ['id' => $m['id']]);
}

Sesjon::opprett((int) $m['id'], 'passord');
DB::oppdater('members', ['siste_innlogging' => gmdate('Y-m-d H:i:s')], ['id' => $m['id']]);
revider('innlogging_passord', 'member', (int) $m['id']);

Svar::ok([
    'navn'    => (string) $m['navn'],
    'erAdmin' => Sesjon::erAdmin(),
    // Regnskapsfoereren ser OEkonomi og betalingene, ikke resten.
    'erRegnskap' => Sesjon::erRegnskap(),
]);
