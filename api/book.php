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
// Kurs som foelger aapningstidene sender et klokkeslett, ikke en oekt.
//
// Eieren, 23. september 2026: «her vil jeg ikke ha faste piller som viser
// alle timene, men dato og tid». Paint on Pots har ingen ferdige oekter aa
// peke paa lenger — kunden velger et kvarter inne i den aapne tida, og raden
// lages i det oeyeblikket bookingen gaar gjennom.
$kursId  = Foresporsel::heltall('kursId');
$tidOslo = trim(Foresporsel::tekst('tid'));
$laget   = 0;
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

if ($oktId <= 0 && ($kursId <= 0 || $tidOslo === '')) {
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
// Ferie. Datoen er borte fra nettsida, men skjult er ikke det samme som
// stengt — en gammel fane eller en delt lenke kan sende okt-id-en hit lenge
// etter at dagen ble merket. Da skal den stoppes her.
// Klokkeslettet gjores om til en oekt foer resten av kontrollene: alt under
// — ferie, tema, pris, plass — leser oekta, og skal lese den samme enten
// kunden valgte en ferdig dato eller et kvarter.
//
// Raden lages her og ikke inne i Booking::reserverOgBetal(), som slaar opp
// oekta foer den aapner en transaksjon. Gaar noe galt etterpaa, ryddes den
// bort igjen nederst — en tom oekt ingen har booket har ingenting i
// kalenderen aa gjore.
if ($oktId <= 0) {
    try {
        $oktId = Apent::oktForTid($kursId, $tidOslo);
        $laget = $oktId;
        // Ryddes bort igjen om noe under svikter — ogsaa ved en fatal feil.
        //
        // Sperren staar i spoerringa, ikke i en variabel: raden tas bare naar
        // ingen har booket den. Rakk noen andre aa ta det samme kvarteret
        // mens dette sto paa, blir den staaende. En tom oekt ingen har
        // booket har derimot ingenting i kalenderen aa gjore — det var slik
        // drop-in druknet den.
        register_shutdown_function(static function () use ($laget): void {
            try {
                DB::kjor(
                    'DELETE FROM course_sessions
                      WHERE id = :i
                        AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.course_session_id = :i2)',
                    ['i' => $laget, 'i2' => $laget]
                );
            } catch (Throwable $e) {
                // Opprydding skal aldri velte et svar som alt er sendt.
            }
        });
    } catch (RuntimeException $e) {
        Svar::feil($e->getMessage());
    }
}

if (Ferie::skjult((array) DB::en(
    'SELECT start_tid' . (Ferie::harUnntak() ? ', ferie_ok' : '') . ' FROM course_sessions WHERE id = :id', ['id' => $oktId]
))) {
    Svar::feil('Verkstedet holder stengt denne dagen. Velg en annen dato.');
}

$tema = DB::verdi(
    'SELECT c.tema FROM course_sessions cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :id',
    ['id' => $oktId]
);
if ((string) $tema === 'Kun for medlemmer') {
    if ($medlem === null) {
        Svar::feil('Dette arrangementet er for medlemmer. Logg inn for å melde deg på.', 401, ['loggInn' => true]);
    }
    if (!er_aktivt_medlem($medlem)) {
        Svar::feil('Dette arrangementet er for medlemmer. Du melder deg inn fra Min side.', 403, ['ikkeMedlem' => true]);
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
        $allergier !== '' ? $allergier : null,
        // «Betal ved oppmoete». Kurset avgjor om det gaar — se
        // Booking::reserverOgBetal(), som avviser det paa et kurs som krever
        // betaling i forkant.
        Foresporsel::tekst('betaling') === 'oppmote',
        // Medlemsrabatt: fra sesjonen, bare for aktive medlemmer.
        Booking::faarMedlemsrabatt($medlem)
    );
} catch (RuntimeException $e) {
    // Meldingene herfra er skrevet for aa vises til kunden.
    Svar::feil($e->getMessage(), 409);
}

revider('booking_opprettet', 'booking', $r['bookingId'], ['okt' => $oktId, 'antall' => $antall]);

// Ferdig med en gang, uten en tur innom Vipps: et gratis medlemsarrangement,
// eller en plass som skal betales ved oppmoete.
if ($r['redirectUrl'] === '') {
    Svar::ok(['betaling' => false, 'bookingId' => $r['bookingId']]);
}

Svar::ok([
    'betaling'  => true,
    'url'       => $r['redirectUrl'],
    'referanse' => $r['referanse'],
]);
