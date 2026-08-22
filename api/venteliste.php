<?php
/**
 * Sett meg paa venteliste.
 *
 * Aapent endepunkt — den som star paa venteliste har ikke nodvendigvis en
 * konto, og skal ikke tvinges gjennom innlogging for aa vise interesse.
 * Ingen betaling skjer her.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
Rate::sjekk('venteliste', maks: 10, vindu: 3600);

$kursId  = Foresporsel::heltall('kursId');
$oktId   = Foresporsel::heltall('oktId');
$navn    = mb_substr(Foresporsel::tekst('navn'), 0, 191);
$epost   = mb_substr(Foresporsel::tekst('epost'), 0, 191);
$telefon = normaliser_telefon(Foresporsel::tekst('telefon'));

// Er du innlogget, kjenner vi deg allerede.
$medlem = Sesjon::medlem();
if ($medlem !== null) {
    $navn    = $navn !== '' ? $navn : (string) $medlem['navn'];
    $epost   = $epost !== '' ? $epost : (string) ($medlem['epost'] ?? '');
    $telefon = $telefon !== '' ? $telefon : normaliser_telefon((string) ($medlem['telefon'] ?? ''));
}

// Kurset kan oppgis direkte, eller utledes fra en oekt.
if ($kursId <= 0 && $oktId > 0) {
    $kursId = (int) DB::verdi('SELECT course_id FROM course_sessions WHERE id = :o', ['o' => $oktId]);
}

if ($kursId <= 0 || DB::en("SELECT id FROM courses WHERE id = :i AND status = 'publisert'", ['i' => $kursId]) === null) {
    Svar::feil('Fant ikke kurset.');
}
if ($navn === '') {
    Svar::feil('Vi trenger navnet ditt.');
}
if ($epost === '' || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
    Svar::feil('Vi trenger en gyldig e-postadresse — det er dit vi gir beskjed.');
}

// Staar du der allerede, skal du ikke havne to ganger i koen.
$finnes = DB::en(
    "SELECT id, posisjon FROM waitlist
      WHERE course_id = :k AND status IN ('venter','varslet')
        AND (epost = :e OR (telefon IS NOT NULL AND telefon = :t))
      LIMIT 1",
    ['k' => $kursId, 'e' => $epost, 't' => $telefon !== '' ? $telefon : null]
);

if ($finnes !== null) {
    Svar::ok([
        'posisjon' => (int) $finnes['posisjon'],
        'gjentakelse' => true,
        'beskjed' => 'Du står allerede på ventelisten for dette kurset.',
    ]);
}

$posisjon = 1 + (int) DB::verdi(
    "SELECT COUNT(*) FROM waitlist WHERE course_id = :k AND status IN ('venter','varslet')",
    ['k' => $kursId]
);

$id = DB::settInn('waitlist', [
    'course_id'         => $kursId,
    'course_session_id' => $oktId > 0 ? $oktId : null,
    'navn'              => $navn,
    'epost'             => $epost,
    'telefon'           => $telefon !== '' ? $telefon : null,
    'posisjon'          => $posisjon,
]);

$kurs = DB::en('SELECT tittel FROM courses WHERE id = :i', ['i' => $kursId]);

// Bekreftelse med en gang, saa ingen lurer paa om det gikk gjennom.
Varsel::epost(
    $epost,
    'Du står på ventelisten hos Lissom',
    "Hei {$navn}!\n\n"
    . "Du er satt på ventelisten for {$kurs['tittel']}, som plass nummer {$posisjon}.\n\n"
    . "Blir det ledig, gir vi beskjed på e-post og SMS. Du betaler ingenting "
    . "før plassen er bekreftet.\n\n"
    . "Hilsen Lissom Keramikk",
    'waitlist',
    $id
);

Svar::ok(['posisjon' => $posisjon, 'kurs' => $kurs['tittel']]);
