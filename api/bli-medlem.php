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
 *
 * ── Betalingen ────────────────────────────────────────────────────────
 *
 * Soknaden oppretter ogsaa betalingsavtalen i Vipps, og svarer med adressen
 * sokeren skal godkjenne den paa.
 *
 * For gjorde den ikke det. Da var det to veier inn i medlemskapet:
 *
 *   medlemskapssida → «Opprett avtale i Vipps» → avtale → trekk hver maaned
 *   soknadsskjemaet → godkjent i admin → medlem, uten avtale, aldri trukket
 *
 * Den andre veien ga tilgang uten at det fantes noe aa trekke fra. Cron
 * henter avtaler som skal belastes; finnes det ingen avtale, kommer det
 * ingen penger — ikke den maaneden, og ikke noen gang.
 *
 * Avtalen belastes ikke her. Den er en fullmakt som ligger og venter, og
 * forste trekk slippes forst naar verkstedet har godkjent soknaden. Blir
 * soknaden avslatt, stoppes avtalen og ingen har betalt noe.
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

// Hvilket medlemskap. Maa vaere et som finnes: avtalen i Vipps opprettes
// med planens pris, og uten plan er det ingenting aa opprette.
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
if ($type === '' || Medlemskap::plan($type) === null) {
    Svar::feil('Velg hvilket medlemskap du søker om.');
}

// Betalingsavtalen, for soknaden lagres.
//
// Gaar den ikke gjennom, skal det heller ikke ligge igjen en soknad — da
// ville den blitt godkjent senere uten at noe kunne trekkes.
try {
    $avtale = Medlemskap::startAvtale($medlem, $type);
} catch (RuntimeException $e) {
    Svar::feil($e->getMessage());
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
    . "Ønsket medlemskap: {$type}\n\n"
    . "Du har godkjent en betalingsavtale i Vipps. Den blir ikke trukket nå — "
    . "første trekk kommer først når vi har sagt ja til søknaden, og du får "
    . "beskjed før det skjer. Sier vi nei, stopper vi avtalen, og du har ikke "
    . "betalt noe.\n\n"
    . "Hilsen Lissom Keramikk & Håndverk\nNordre Løkkevei 15, 3120 Nøtterøy"
);

// Beskjeden til verkstedet gaar paa e-post, og som SMS i tillegg naar det er
// satt opp. E-posten er den som alltid kommer fram — en soknad som blir
// liggende fordi ingen fikk vite om den, er verre enn en soknad for mye.
$kort = "Ny medlemssøknad fra {$navn}" . ($type !== '' ? " ({$type})" : '') . '. Se Admin → Medlemmer.';

Varsel::tilAdmin(
    'Ny medlemssøknad fra ' . $navn,
    "Det har kommet en ny medlemssøknad.\n\n"
    . "Navn: {$navn}\n"
    . 'E-post: ' . $epost . "\n"
    . 'Telefon: ' . ($telefon !== '' ? $telefon : '(ikke oppgitt)') . "\n"
    . ($type !== '' ? "Ønsket medlemskap: {$type}\n" : '')
    . ($erfaring !== '' ? "\nErfaring:\n{$erfaring}\n" : '')
    . ($melding !== '' ? "\nMelding:\n{$melding}\n" : '')
    . "\nSøknaden ligger under Admin → Medlemmer, og venter på svar.",
    'membership_application',
    $id
);

foreach (Config::adminNumre() as $nr) {
    Varsel::sms($nr, $kort);
}

revider('medlemssoknad_sendt', 'membership_application', $id, ['type' => $type]);

// «url» sender sokeren til Vipps for aa godkjenne avtalen. Nettsida
// videresender dit med det samme.
Svar::ok([
    'status'  => 'venter',
    'url'     => $avtale['url'],
    'beskjed' => 'Søknaden er sendt. Godkjenn betalingsavtalen i Vipps, '
               . 'så tar vi kontakt på e-post.',
]);
