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

// Er datoen kjent, er det den koen gjelder.
//
// Kolonnen har vaert lagret siden ventelista kom, men ingen leste den: bade
// koen, posisjonen og dublettsjekken gikk paa kurset. Da havnet den som
// ventet paa 12. september i samme ko som den som ventet paa 3. oktober, og
// «plass nummer 4» sa ingenting om hvilken kveld.
//
// Rader uten dato blir liggende som for og telles paa kurset. De er fra for
// dette og skal ikke flyttes.
$paaDato = $oktId > 0
    && DB::en('SELECT id FROM course_sessions WHERE id = :o AND course_id = :k',
              ['o' => $oktId, 'k' => $kursId]) !== null;

// Staar du der allerede, skal du ikke havne to ganger i koen.
$finnes = DB::en(
    "SELECT id, posisjon FROM waitlist
      WHERE course_id = :k AND status IN ('venter','varslet')
        AND " . ($paaDato ? 'course_session_id = :o' : 'course_session_id IS NULL') . "
        AND (epost = :e OR (telefon IS NOT NULL AND telefon = :t))
      LIMIT 1",
    ['k' => $kursId, 'e' => $epost, 't' => $telefon !== '' ? $telefon : null]
      + ($paaDato ? ['o' => $oktId] : [])
);

if ($finnes !== null) {
    Svar::ok([
        'posisjon' => (int) $finnes['posisjon'],
        'gjentakelse' => true,
        // Koen gjelder én kveld naar datoen er kjent. «Dette kurset» ville
        // vaert feil: du kan staa paa lista til 9. september og likevel melde
        // deg paa lista til 16.
        'beskjed' => $paaDato
            ? 'Du står allerede på ventelisten for denne datoen.'
            : 'Du står allerede på ventelisten for dette kurset.',
    ]);
}

$posisjon = 1 + (int) DB::verdi(
    "SELECT COUNT(*) FROM waitlist
      WHERE course_id = :k AND status IN ('venter','varslet')
        AND " . ($paaDato ? 'course_session_id = :o' : 'course_session_id IS NULL'),
    ['k' => $kursId] + ($paaDato ? ['o' => $oktId] : [])
);

$id = DB::settInn('waitlist', [
    'course_id'         => $kursId,
    'course_session_id' => $paaDato ? $oktId : null,
    'navn'              => $navn,
    'epost'             => $epost,
    'telefon'           => $telefon !== '' ? $telefon : null,
    'posisjon'          => $posisjon,
]);

$kurs = DB::en('SELECT tittel FROM courses WHERE id = :i', ['i' => $kursId]);

// Hvilken kveld hen staar og venter paa. «Plass nummer 4» sier ingenting uten
// den — og det er det forste folk lurer paa naar de faar e-posten.
$naar = $paaDato
    ? ' ' . Booking::norskDato((string) DB::verdi(
        'SELECT start_tid FROM course_sessions WHERE id = :o', ['o' => $oktId]))
    : '';

// Bekreftelse med en gang, saa ingen lurer paa om det gikk gjennom.
    Varsel::mal('venteliste_satt', ['epost' => $epost], [
        'navn'     => $navn,
        'kurs'     => (string) $kurs['tittel'],
        'dato'     => $naar,
        'posisjon' => (string) $posisjon,
    ], 'waitlist', $id);

Svar::ok(['posisjon' => $posisjon, 'kurs' => $kurs['tittel']]);
