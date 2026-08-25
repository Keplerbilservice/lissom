<?php
/**
 * Retur fra Vipps: veksler koden inn i en profil, finner eller oppretter
 * medlemmet, og starter en sesjon.
 *
 * Erstatter api/callback.js. Forskjellen fra den gamle: profilen legges ikke i
 * en cookie brukeren selv kan skrive. Den lagres i databasen, og cookien
 * inneholder bare et tilfeldig token.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');
Rate::sjekk('login-retur', maks: 20, vindu: 300);

$kode  = Foresporsel::tekst('code');
$state = Foresporsel::tekst('state');

/** Sender brukeren tilbake til forsiden med en forklaring i adressefeltet. */
$avbryt = static function (string $grunn, string $kode = 'feilet'): never {
    logg('Innlogging avbrutt: ' . $grunn);
    Svar::omdiriger(Config::nettsted() . '/?innlogging=' . $kode);
};

if ($kode === '' || $state === '') {
    $avbryt('mangler code eller state', Foresporsel::tekst('error') !== '' ? 'avbrutt' : 'feilet');
}

// State skal finnes hos oss og ikke være utløpt. Dette er CSRF-vernet, og
// raden slettes med én gang så den ikke kan brukes to ganger.
$lagret = DB::en(
    'SELECT retur_url FROM login_states WHERE state = :s AND expires_at > UTC_TIMESTAMP()',
    ['s' => $state]
);
DB::kjor('DELETE FROM login_states WHERE state = :s', ['s' => $state]);

if ($lagret === null) {
    $avbryt('ukjent eller utløpt state');
}

try {
    $profil = Vipps::hentProfil($kode);
} catch (Throwable $e) {
    logg_feil('Vipps-innlogging feilet', $e);
    $avbryt('kunne ikke hente profil');
}

// Én person, én rad. Oppslaget ligger i Vipps-klassen fordi det kan proeves
// der — en OAuth-runde mot Vipps kan ikke kjores i en test, men oppslaget kan.
$medlemId = DB::iTransaksjon(static fn(): int => Vipps::medlemFraProfil($profil));

Sesjon::opprett($medlemId);
revider('logg_inn', 'member', $medlemId, ['kilde' => 'vipps']);

// Tilbake dit brukeren kom fra. Markoren #innlogget forteller frontenden at
// dette er en retur fra Vipps, saa den kan hente profilen og fjerne
// innloggingsskjermen — uten aa overstyre hvilken side man skal til.
//
// Tidligere sto det #minside her, og da havnet alle paa Min side uansett hva
// de holdt paa med da de logget inn.
$retur = (string) $lagret['retur_url'];
Svar::omdiriger(Config::nettsted() . (str_starts_with($retur, '/') ? $retur : '/') . '#innlogget');
