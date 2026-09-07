<?php
/**
 * Steg 1 av innmeldingen: ordren lages, foer noen forlater sida.
 *
 * Svarer med adressen kunden skal videre til — /meld-inn/<noekkel>. Alt som
 * betyr noe ligger da paa serveren. Nettleseren baerer bare noekkelen.
 *
 * ── Hvorfor dette ikke krever innlogging ────────────────────────────────
 *
 * Innmeldingen krevde foer at du var logget inn. Det ga to turer til Vipps —
 * en for aa logge inn, en for aa betale — og eieren maatte taste
 * telefonnummeret sitt to ganger, 7. september 2026: «naar jeg kommer til
 * Vipps, saa maa jeg taste telefonnummert paa nytt, selv om jeg tastet det i
 * bestillingsskjemaet?»
 *
 * Verre: mellom de to turene laa valget av medlemskap bare i nettleserens
 * minne, og forsvant det, ble det FOERSTE medlemskapet i lista kjopt i
 * stedet. Se app/lib/medlemsordre.php for hele historien.
 *
 * Naa er det én tur. Medlemsraden lages av ordren, og medlemskapet slaas
 * foerst paa naar Vipps sier at pengene er i havn.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

// Et forsoek koster oss en rad. Samme grense som innmeldingen hadde.
Rate::sjekk('medlemsordre', maks: 10, vindu: 3600);

// Er hun logget inn, vet vi hvem hun er alt. Ellers gaar det fint likevel.
$innlogget = Sesjon::medlem();

$type     = mb_substr(Foresporsel::tekst('type'), 0, 64);
$betaling = Foresporsel::tekst('betaling') === 'engang' ? 'engang' : 'trekk';
$navn     = mb_substr(Foresporsel::tekst('navn'), 0, 191);
$epost    = mb_substr(Foresporsel::tekst('epost'), 0, 191);
$telefon  = mb_substr(Foresporsel::tekst('telefon'), 0, 32);

// Det hun alt har fortalt oss trenger hun ikke skrive igjen.
if ($innlogget !== null) {
    if ($navn === '')    { $navn = (string) ($innlogget['navn'] ?? ''); }
    if ($epost === '')   { $epost = (string) ($innlogget['epost'] ?? ''); }
    if ($telefon === '') { $telefon = (string) ($innlogget['telefon'] ?? ''); }
}

// Navnet kreves IKKE her.
//
// Medlemskortet paa nettsida har e-post og telefon, ikke navn — og det skal
// det ikke trenge: kjenner vi deg ikke fra for, henter /meld-inn/<noekkel>
// navnet fra Vipps og sender deg rett tilbake hit. Se api/meld-inn.php.
//
// Krevde vi navnet her, ville innmeldingen stoppet med «Vi trenger navnet
// ditt» paa et skjema som ikke har feltet. Maalt i nettleseren 7. september
// 2026: nettopp det skjedde.
if (!filter_var(trim($epost), FILTER_VALIDATE_EMAIL)) {
    Svar::feil('Vi trenger en e-postadresse vi kan svare på.');
}
if (Foresporsel::tekst('vilkaar') !== 'ja') {
    Svar::feil('Du må godta medlemsvilkårene for å melde deg inn.');
}
if ($innlogget !== null && er_aktivt_medlem($innlogget)) {
    Svar::feil('Du er allerede medlem.');
}

// Planen. Medlemsordre::opprett() kaster om den mangler eller ikke finnes —
// den skal aldri falle tilbake paa noe.
try {
    $token = Medlemsordre::opprett($type, $betaling, [
        'navn'     => $navn,
        'epost'    => $epost,
        'telefon'  => $telefon,
        'erfaring' => Foresporsel::tekst('erfaring'),
        'melding'  => Foresporsel::tekst('melding'),
        'vilkaar'  => Medlemskap::VILKAAR_VERSJON,
    ]);
} catch (RuntimeException $e) {
    Svar::feil($e->getMessage());
}

$plan = Medlemskap::plan($type);

revider('medlemsordre_opprettet', 'member',
    $innlogget === null ? 0 : (int) $innlogget['id'],
    ['plan' => $type, 'betaling' => $betaling]);

Svar::ok([
    // Hit sendes hun. Serveren lager Vipps-avtalen naar adressen aapnes.
    'url'   => '/meld-inn/' . $token,
    'plan'  => $type,
    'pris'  => Booking::kroner((int) $plan['pris_ore']),
    // Skjermen skal kunne si hva den nettopp bestilte, med ord kunden
    // kjenner igjen fra kortet hun trykket paa.
    'trekk' => Medlemskap::kreverFastTrekk($plan),
]);
