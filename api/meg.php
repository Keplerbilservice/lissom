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

// Dorkoden og wifi-passordet vises bare for den som faktisk er medlem.
// De laa som fast tekst i designfila — «4 7 1 2» — og var dermed enten feil,
// eller den ekte koden aapent i kildekoden til nettsiden.
$internInfo = [];
if (er_aktivt_medlem($m)) {
    foreach (DB::alle("SELECT nokkel, verdi FROM content_blocks WHERE nokkel LIKE 'Privat/%'") as $r) {
        $internInfo[substr((string) $r['nokkel'], 7)] = (string) $r['verdi'];
    }
}

Svar::json([
    'innlogget'      => true,
    'internInfo'     => (object) $internInfo,
    'erAdmin'        => Sesjon::erAdmin(),
    // Regnskapsfoereren ser OEkonomi og betalingene, ikke resten.
    'erRegnskap'     => Sesjon::erRegnskap(),
    // Kontoen er en administratorkonto, men innloggingen holder ikke:
    // adminpanelet krever brukernavn og passord. Uten dette forsvinner
    // admin-lenka uten forklaring, og det ser ut som om tilgangen er borte.
    'adminKreverPassord' => !Sesjon::erAdmin() && Sesjon::kanVaereAdmin($m),
    // Sluppet inn fordi det ikke finnes noe passord aa kreve. Da skal det
    // staa tydelig at det bor settes.
    'adminUtenPassord'   => Sesjon::adminUtenPassord(),
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
