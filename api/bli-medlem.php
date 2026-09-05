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

// ── Vilkaarene ─────────────────────────────────────────────────────────
//
// Eieren, 2. september: «er det mulig aa legge til godta vilkaar for man faar
// kjopt et medlemskap?»
//
// Kravet staar her og ikke bare i skjemaet. Haken i nettleseren er en
// hoeflighet mot den som fyller ut; det er dette kallet som avgjor om noen
// blir medlem, og en knapp som er graa kan klikkes av andre enn nettleseren.
$vilkaar = Foresporsel::tekst('vilkaar') === 'ja';
if (!$vilkaar) {
    Svar::feil('Du må godta medlemsvilkårene for å melde deg inn.');
}

// ── Fast trekk finnes bare paa aarsavtalen ─────────────────────────────
//
// Eieren, 3. september: «jeg vil ikke ha dette alternativet paa noen andre
// steder enn paa aarsavtalen».
//
// Her leste vi «betaling» fra kallet. Foerst ble alt som ikke sa «selv» til
// trekk; saa snudde vi det, saa bare et uttrykkelig «trekk» ga trekk. Begge
// deler lot en loepende avtale bli opprettet paa et medlemskap som ikke skal
// ha en — det var bare vanskeligere aa treffe.
//
// Naa avgjor planen alene. Feltet leses ikke lenger: krever planen fast
// trekk, blir det trekk; ellers vanlig Vipps. En gammel fane, et kall som
// sender «trekk», en pille som ble staaende igjen i en cache — ingenting av
// det kan lage en avtale mer.
//
// «krever_fast_trekk» staar i basen, satt av migrasjon 081 paa alt med tolv
// maaneders bindingstid. I dag er det bare aarsmedlemskapet. En engangsplan
// kan uansett ikke ha fast trekk, og faar det ikke her heller.
$betaling = Medlemskap::kreverFastTrekk($plan) ? 'trekk' : 'selv';

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

// ── Det samme forsoeket to ganger ──────────────────────────────────────
//
// Eieren, 2. september: e-posten «Nytt medlem: Anniken Johnsgaard» kom to
// ganger, og «Varsel maa sendes for haand» like saa — begge 20:42.
//
// Her sto ingen vakt. Kom kallet to ganger — et dobbeltklikk, tilbakeknappen
// fra Vipps, et nettverk som proevde paa nytt — gikk begge gjennom, og det
// andre lagde en avtale til i Vipps, en soknadsrad til og alle varslene om
// igjen. To avtaler er verre enn to e-poster: det er to trekk.
//
// startAvtale() og startEngangs() svarer naa med det foerste forsoeket naar
// det er under fem minutter gammelt. Da skal ingenting av det under skje én
// gang til: soekeren sendes videre til den samme adressen i Vipps, og
// verkstedet faar ikke beskjed om et medlem det alt har faatt beskjed om.
if (!empty($avtale['gjentakelse'])) {
    revider('medlemsinnmelding_gjentatt', 'subscription', (int) $avtale['id'], ['type' => $type]);
    Svar::ok([
        'status'  => 'betaler',
        'url'     => $avtale['url'],
        'beskjed' => 'Du er på vei til Vipps.',
    ]);
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
] + (DB::harKolonne('membership_applications', 'vilkaar_godtatt_at') ? [
    // Naar vilkaarene ble godtatt, og hvilken utgave som gjaldt da. En hake
    // som bare laaser opp en knapp er ikke noe bevis; dette er det.
    'vilkaar_godtatt_at' => gmdate('Y-m-d H:i:s'),
    'vilkaar_versjon'    => Medlemskap::VILKAAR_VERSJON,
] : []));

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
// Lenka til Vipps maa vaere MED i e-posten naar det er fast trekk.
//
// Avtalen er ikke gyldig for medlemmet har godkjent den i appen. Rekker hun
// det ikke der og da — nettet ryker, telefonen ligger i en annen jakke, hun
// lukker fana — laa lenka bare i basen, og ingen fikk den. Eieren,
// 5. september: «eposten de som bestiller årsmedlemskap får forteller
// ingenting om at de må godkjenne», og «jeg får jo ikke inn pengene mine».
Varsel::mal($betaling === 'trekk' ? 'innmelding_fast_trekk' : 'innmelding_ordner_selv',
    ['epost' => $epost], [
        'navn'  => $navn,
        'type'  => $type,
        'lenke' => (string) ($avtale['url'] ?? '') !== ''
            ? (string) $avtale['url']
            : Config::nettsted() . '/min-side',
    ], 'membership_application', $id);

// Beskjeden til verkstedet gaar paa e-post, og som SMS i tillegg naar det er
// satt opp. E-posten er den som alltid kommer fram — en soknad som blir
// liggende fordi ingen fikk vite om den, er verre enn en soknad for mye.
// Betalings MAATEN, ikke en betaling. «Gjor opp selv» ble lest som «hun har
// ordnet det» — eieren, 2. september: «denne staar som ubetalt, mens eposten
// du sendte meg sier ... Betaling: gjor opp selv». Begge var sanne: hun
// betaler én periode om gangen, og hadde ikke betalt enda.
$betalingTekst = $betaling === 'trekk'
    ? 'fast trekk i Vipps'
    : 'betaler i Vipps én periode om gangen';

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
    // ── Naar gaar pengene? ─────────────────────────────────────────────
    //
    // Medlemmet Eirin, 2. september: «Jeg betalte med vipps i gaar via siden
    // her. Saa ut til aa fungere greit. Men pengene er fremdeles paa min
    // konto.»
    //
    // Her sto bare «saa er du i gang». Fast trekk i Vipps er en fullmakt, ikke
    // en betaling: trekket bes om av cron, og Vipps krever at kunden varsles
    // for det skjer — saa forfallet ligger tre dager fram. En som nettopp har
    // vaert gjennom Vipps leser «du er i gang» som «jeg har betalt», sjekker
    // kontoen, og skriver til verkstedet.
    'beskjed' => $betaling === 'trekk'
        ? 'Godkjenn betalingsavtalen i Vipps, så er du i gang. '
          . 'Første trekk kommer om noen dager — du får en e-post fra oss først.'
        : 'Betal i Vipps, så er du i gang.',
]);
