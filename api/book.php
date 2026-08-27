<?php
/**
 * Booker en plass og starter betaling i Vipps.
 *
 * Svarer med adressen brukeren skal sendes til. Frontenden gjor deretter
 * window.location.href = svaret — det er der Vipps overtar.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
Rate::sjekk('book', maks: 15, vindu: 600);

$oktId  = Foresporsel::heltall('oktId');
$antall = max(1, min(10, Foresporsel::heltall('antall', 1)));
$navn   = mb_substr(Foresporsel::tekst('navn'), 0, 191);
$epost  = mb_substr(Foresporsel::tekst('epost'), 0, 191);
$telefon= Foresporsel::tekst('telefon');
$folge  = mb_substr(Foresporsel::tekst('folgeMedlem'), 0, 191);
// Det deltakeren selv sier om allergier og annet arrangoren maa vite.
// Helseopplysninger: lagres paa bookingen, vises bare i admin, og brukes
// ikke til noe annet.
$allergier = trim(mb_substr(Foresporsel::tekst('allergier'), 0, 1000));

$medlem = Sesjon::medlem();

// Er du innlogget, bruker vi det vi allerede vet om deg framfor det skjemaet sier.
if ($medlem !== null) {
    $navn    = $navn !== '' ? $navn : (string) $medlem['navn'];
    $epost   = $epost !== '' ? $epost : (string) ($medlem['epost'] ?? '');
    $telefon = $telefon !== '' ? $telefon : (string) ($medlem['telefon'] ?? '');
}

if ($oktId <= 0) {
    Svar::feil('Velg en dato først.');
}
if ($navn === '') {
    Svar::feil('Vi trenger navnet ditt.');
}
if ($epost === '' || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
    Svar::feil('Vi trenger en gyldig e-postadresse — kvitteringen sendes dit.');
}
if (normaliser_telefon($telefon) === '') {
    Svar::feil('Vi trenger et mobilnummer.');
}
// Krysset av, men ikke skrevet noe: da vet verkstedet at det er noe, uten aa
// vite hva. Verre enn om boksen sto tom.
if (Foresporsel::tekst('harAllergier') === 'ja' && $allergier === '') {
    Svar::feil('Skriv hva vi må vite om — allergier, intoleranser eller annet.');
}

// Medlemsarrangementer er gratis og bare for medlemmer. Uten denne sjekken
// kunne hvem som helst booket dem ved aa sende okt-id-en rett til serveren —
// de vises ikke i den offentlige lista, men skjult er ikke det samme som
// stengt.
$tema = DB::verdi(
    'SELECT c.tema FROM course_sessions cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :id',
    ['id' => $oktId]
);
if ((string) $tema === 'Kun for medlemmer') {
    if ($medlem === null) {
        Svar::feil('Dette arrangementet er for medlemmer. Logg inn for å melde deg på.', 401, ['loggInn' => true]);
    }
    if (!er_aktivt_medlem($medlem)) {
        Svar::feil('Dette arrangementet er for medlemmer. Søk om medlemskap fra Min side.', 403, ['ikkeMedlem' => true]);
    }
}

try {
    $r = Booking::reserverOgBetal(
        $oktId,
        $antall,
        $navn,
        $epost,
        normaliser_telefon($telefon),
        $medlem === null ? null : (int) $medlem['id'],
        $folge !== '' ? $folge : null,
        // Gavekortet fra feltet paa bookingsiden. Det var ikke koblet til
        // noe, saa koden ble skrevet inn og kunden betalte full pris.
        Foresporsel::tekst('gavekort'),
        $allergier !== '' ? $allergier : null
    );
} catch (RuntimeException $e) {
    // Meldingene herfra er skrevet for aa vises til kunden.
    Svar::feil($e->getMessage(), 409);
}

revider('booking_opprettet', 'booking', $r['bookingId'], ['okt' => $oktId, 'antall' => $antall]);

// Gratis medlemsarrangement: ingen betaling, ferdig med en gang.
if ($r['redirectUrl'] === '') {
    Svar::ok(['betaling' => false, 'bookingId' => $r['bookingId']]);
}

Svar::ok([
    'betaling'  => true,
    'url'       => $r['redirectUrl'],
    'referanse' => $r['referanse'],
]);
