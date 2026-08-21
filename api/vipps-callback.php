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

$medlemId = DB::iTransaksjon(static function () use ($profil): int {
    $medlem = DB::en('SELECT id, rolle FROM members WHERE vipps_sub = :s', ['s' => $profil['sub']]);

    // Kjenner vi ikke sub-en, kan personen likevel finnes fra før — f.eks. som
    // gjest på et kurs. Da knytter vi Vipps-kontoen til den raden i stedet for
    // å lage en dublett.
    if ($medlem === null && $profil['telefon'] !== '') {
        $medlem = DB::en(
            'SELECT id, rolle FROM members WHERE telefon = :t AND vipps_sub IS NULL AND anonymisert_at IS NULL LIMIT 1',
            ['t' => $profil['telefon']]
        );
    }

    $erAdminNummer = $profil['telefon'] !== ''
        && in_array($profil['telefon'], Config::adminNumre(), true);

    if ($medlem === null) {
        return DB::settInn('members', [
            'vipps_sub' => $profil['sub'],
            'navn'      => $profil['navn'],
            'epost'     => $profil['epost'] !== '' ? $profil['epost'] : null,
            'telefon'   => $profil['telefon'] !== '' ? $profil['telefon'] : null,
            'rolle'     => $erAdminNummer ? 'admin' : 'medlem',
        ]);
    }

    $endringer = [
        'vipps_sub' => $profil['sub'],
        'navn'      => $profil['navn'],
    ];
    if ($profil['epost'] !== '')   { $endringer['epost'] = $profil['epost']; }
    if ($profil['telefon'] !== '') { $endringer['telefon'] = $profil['telefon']; }
    // Admin-rollen settes kun oppover herfra. Å ta den bort gjøres i admin,
    // slik at et nummer som fjernes fra nødlista ikke mister tilgangen ved et uhell.
    if ($erAdminNummer && $medlem['rolle'] !== 'admin') { $endringer['rolle'] = 'admin'; }

    DB::oppdater('members', $endringer, ['id' => $medlem['id']]);
    return (int) $medlem['id'];
});

Sesjon::opprett($medlemId);
revider('logg_inn', 'member', $medlemId, ['kilde' => 'vipps']);

$retur = (string) $lagret['retur_url'];
Svar::omdiriger(Config::nettsted() . (str_starts_with($retur, '/') ? $retur : '/') . '#minside');
