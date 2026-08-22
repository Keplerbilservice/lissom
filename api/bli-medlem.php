<?php
/**
 * Soknad om medlemskap.
 *
 * Vipps Login sier hvem noen er. Det er ikke det samme som at de skal inn i
 * verkstedet. Den som vil bli medlem sender en soknad her; verkstedet
 * godkjenner den i admin, og forst da apner medlemsdelen av Min side.
 *
 * GET  — status paa egen soknad (venter, godkjent, avslatt eller ingen).
 * POST — send inn soknad. En om gangen; en ny soknad mens en venter avvises.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem = krev_medlem();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = DB::en(
        'SELECT id, onsket_type, status, begrunnelse, created_at
           FROM membership_applications
          WHERE member_id = :m
       ORDER BY id DESC LIMIT 1',
        ['m' => $medlem['id']]
    );
    Svar::json([
        'erMedlem' => er_aktivt_medlem($medlem),
        'soknad'   => $s ?: null,
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
Rate::sjekk('bli-medlem', maks: 5, vindu: 3600);

if (er_aktivt_medlem($medlem)) {
    Svar::feil('Du er allerede medlem.');
}

$venter = DB::verdi(
    'SELECT COUNT(*) FROM membership_applications WHERE member_id = :m AND status = :s',
    ['m' => $medlem['id'], 's' => 'venter']
);
if ((int) $venter > 0) {
    Svar::feil('Du har allerede en søknad til behandling. Vi tar kontakt.');
}

$type     = mb_substr(Foresporsel::tekst('type'), 0, 64);
$navn     = mb_substr(Foresporsel::tekst('navn'), 0, 191);
$epost    = mb_substr(Foresporsel::tekst('epost'), 0, 191);
$telefon  = mb_substr(Foresporsel::tekst('telefon'), 0, 32);
$erfaring = mb_substr(Foresporsel::tekst('erfaring'), 0, 1000);
$melding  = mb_substr(Foresporsel::tekst('melding'), 0, 1000);

// Navnet og nummeret kommer fra Vipps hvis sokeren ikke skriver noe selv.
if ($navn === '')    { $navn = (string) $medlem['navn']; }
if ($telefon === '') { $telefon = (string) ($medlem['telefon'] ?? ''); }
if ($epost === '')   { $epost = (string) ($medlem['epost'] ?? ''); }

if ($navn === '') {
    Svar::feil('Vi trenger navnet ditt.');
}
if ($epost === '' || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
    Svar::feil('Vi trenger en e-postadresse vi kan svare på.');
}

$id = DB::settInn('membership_applications', [
    'member_id'   => $medlem['id'],
    'onsket_type' => $type !== '' ? $type : null,
    'navn'        => $navn,
    'epost'       => $epost,
    'telefon'     => $telefon !== '' ? normaliser_telefon($telefon) : null,
    'erfaring'    => $erfaring !== '' ? $erfaring : null,
    'melding'     => $melding !== '' ? $melding : null,
    'status'      => 'venter',
]);

// Sokeren far en kvittering, og verkstedet beskjed om at det ligger en soknad.
Varsel::epost(
    $epost,
    'Vi har fått søknaden din',
    "Hei {$navn},\n\nTakk for at du vil bli medlem hos Lissom. Vi ser på søknaden din "
    . "og gir deg beskjed så snart vi har tatt stilling til den.\n\n"
    . ($type !== '' ? "Ønsket medlemskap: {$type}\n\n" : '')
    . "Hilsen Lissom Keramikk & Håndverk\nNordre Løkkevei 15, 3120 Nøtterøy"
);

foreach (Config::adminNumre() as $nr) {
    Varsel::sms($nr, "Ny medlemssøknad fra {$navn}" . ($type !== '' ? " ({$type})" : '') . '. Se Admin → Medlemmer.');
}

revider('medlemssoknad_sendt', 'membership_application', $id, ['type' => $type]);

Svar::ok([
    'status'  => 'venter',
    'beskjed' => 'Søknaden er sendt. Vi tar kontakt på e-post.',
]);
