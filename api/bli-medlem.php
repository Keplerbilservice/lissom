<?php
/**
 * Innmelding i medlemskap.
 *
 * Eieren: «jeg vil ikke ha soknad, men rett paa vipps betaling».
 *
 * Her sto det for et soknadsskjema: du sendte inn, verkstedet sa ja eller
 * nei i admin, og forst da apnet medlemsdelen. Ingen betalte noe underveis —
 * og siden cron bare henter avtaler som er aktive, kom det aldri penger fra
 * dem som ble tatt opp den veien. Ikke den maaneden, og ikke noen gang.
 *
 * Naa gaar innmeldingen rett til Vipps:
 *
 *   fast trekk  → avtale i Vipps, trekkes hver periode av seg selv
 *   ordner selv → én betaling naa; verkstedet krever inn de neste periodene
 *
 * Medlemmet velger. Planer med «krever_fast_trekk = 1» — i dag
 * aarsmedlemskapet, som bindes i tolv maaneder — gir ikke valget.
 *
 * Medlemskapet slaas paa naar pengene er i havn, ikke naar noen trykket ja:
 * avtalen gjennom Medlemskap::oppdaterFraVipps(), engangsbetalingen gjennom
 * Booking::markerBetalt(). Vi sporr Vipps; vi stoler ikke paa at kunden kom
 * tilbake til riktig side.
 *
 * GET  — status paa egen innmelding, og gamle soknader som ligger til svar.
 * POST — meld inn. Svarer med adressen til Vipps.
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

// Ligger det en gammel soknad fra tida for innmeldingen gikk om Vipps, skal
// den ikke stoppe noen fra aa melde seg inn og betale. Den lukkes her.
$gammel = DB::en(
    "SELECT id FROM membership_applications
      WHERE member_id = :m AND status = 'venter' ORDER BY id DESC LIMIT 1",
    ['m' => $medlem['id']]
);

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
$plan = $type === '' ? null : Medlemskap::plan($type);
if ($plan === null) {
    Svar::feil('Velg hvilket medlemskap du vil ha.');
}

// Fast trekk eller ikke.
//
// Krever planen fast trekk, er valget tatt — da er avtalen en forutsetning.
// Ellers bestemmer sokeren selv, og «trekk» er utgangspunktet.
$betaling = Foresporsel::tekst('betaling') === 'selv' ? 'selv' : 'trekk';
if (Medlemskap::kreverFastTrekk($plan)) {
    $betaling = 'trekk';
}

// Betalingsavtalen, for soknaden lagres.
//
// Gaar den ikke gjennom, skal det heller ikke ligge igjen en soknad — da
// ville den blitt godkjent senere uten at noe kunne trekkes.
try {
    $avtale = $betaling === 'trekk'
        ? Medlemskap::startAvtale($medlem, $type)
        : Medlemskap::startEngangs($medlem, $type);
} catch (RuntimeException $e) {
    Svar::feil($e->getMessage());
}

// Innmeldingen lagres fortsatt i «membership_applications», men ikke som noe
// som venter paa svar: den staar som godkjent med det samme. Tabellen er
// historikken over hvem som har meldt seg inn, med erfaring og melding — den
// staar der eieren leser den, og de gamle radene blir liggende urort.
$id = DB::settInn('membership_applications', [
    'member_id'    => $medlem['id'],
    'onsket_type'  => $type,
    'betaling'     => $betaling,
    'navn'         => $navn,
    'epost'        => $epost,
    'telefon'      => $telefon !== '' ? normaliser_telefon($telefon) : null,
    'erfaring'     => $erfaring !== '' ? $erfaring : null,
    'melding'      => $melding !== '' ? $melding : null,
    'status'       => 'godkjent',
    'behandlet_at' => gmdate('Y-m-d H:i:s'),
]);

// Og den gamle soknaden, om det laa en. Den er ikke lenger til behandling.
if ($gammel !== null) {
    DB::oppdater('membership_applications', [
        'status'       => 'godkjent',
        'behandlet_at' => gmdate('Y-m-d H:i:s'),
        'begrunnelse'  => 'Meldte seg inn og betalte selv.',
    ], ['id' => (int) $gammel['id']]);
}

// Sokeren far en kvittering, og verkstedet beskjed om at det ligger en soknad.
// Delt i to maler. Eieren, 1. september: «del i to maler» — da ser han hele
// teksten kunden faar, og kan skrive de to ulikt.
Varsel::mal($betaling === 'trekk' ? 'innmelding_fast_trekk' : 'innmelding_ordner_selv',
    ['epost' => $epost], [
        'navn' => $navn,
        'type' => $type,
    ], 'membership_application', $id);

// Beskjeden til verkstedet gaar paa e-post, og som SMS i tillegg naar det er
// satt opp. E-posten er den som alltid kommer fram — en soknad som blir
// liggende fordi ingen fikk vite om den, er verre enn en soknad for mye.
$betalingTekst = $betaling === 'trekk' ? 'fast trekk i Vipps' : 'gjør opp selv';

Varsel::malTilAdmin('intern_nytt_medlem', [
    'navn'     => $navn,
    'epost'    => $epost,
    'telefon'  => $telefon !== '' ? $telefon : '(ikke oppgitt)',
    'type'     => $type,
    'betaling' => $betalingTekst,
    'erfaring' => $erfaring !== '' ? "\nErfaring:\n" . $erfaring . "\n" : '',
    'melding'  => $melding !== '' ? "\nMelding:\n" . $melding . "\n" : '',
], 'membership_application', $id);

foreach (Config::adminNumre() as $nr) {
    Varsel::mal('intern_nytt_medlem_sms', ['telefon' => $nr], [
        'navn'     => $navn,
        'type'     => $type,
        'betaling' => $betalingTekst,
    ], 'membership_application', $id);
}

revider('medlemsinnmelding', 'membership_application', $id, ['type' => $type, 'betaling' => $betaling]);

// «url» sender sokeren til Vipps for aa godkjenne avtalen. Nettsida
// videresender dit med det samme.
// «url» sender sokeren til Vipps for aa godkjenne avtalen. Har hen valgt aa
// gjore opp selv, er det ingen avtale, og da blir hen staaende paa sida.
// «url» sender medlemmet til Vipps. Nettsida videresender dit med det samme;
// medlemskapet blir aktivt naar Vipps sier at pengene er i havn.
Svar::ok([
    'status'  => 'betaler',
    'url'     => $avtale['url'],
    'beskjed' => $betaling === 'trekk'
        ? 'Godkjenn betalingsavtalen i Vipps, så er du i gang.'
        : 'Betal i Vipps, så er du i gang.',
]);
