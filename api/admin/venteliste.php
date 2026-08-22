<?php
/**
 * Ventelista.
 *
 *   GET                    hvem som venter, per kurs
 *   POST handling=varsle   gi beskjed om ledig plass  { id }
 *   POST handling=fjern    ta noen av lista           { id }
 *
 * Varsling sender SMS og e-post med en frist. Plassen holdes ikke av
 * automatisk — den forste som booker, faar den. Det staar ogsaa i meldingen,
 * saa ingen tror de har en reservasjon de ikke har.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle(
        "SELECT w.*, c.tittel, c.slug
           FROM waitlist w
           JOIN courses c ON c.id = w.course_id
          WHERE w.status IN ('venter','varslet')
          ORDER BY c.tittel, w.posisjon"
    );

    Svar::json(['venteliste' => array_map(static fn($w) => [
        'id'       => (int) $w['id'],
        'navn'     => $w['navn'],
        'epost'    => $w['epost'],
        'telefon'  => $w['telefon'],
        'kurs'     => $w['tittel'],
        'posisjon' => (int) $w['posisjon'],
        'status'   => $w['status'] === 'varslet' ? 'Varslet' : 'Venter',
        'varslet'  => $w['varslet_at'] ? Booking::norskDato((string) $w['varslet_at']) : null,
        'siden'    => Booking::norskDato((string) $w['created_at']),
    ], $rader)]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$id = Foresporsel::heltall('id');
$rad = DB::en(
    'SELECT w.*, c.tittel, c.slug FROM waitlist w JOIN courses c ON c.id = w.course_id WHERE w.id = :i',
    ['i' => $id]
);
if ($rad === null) {
    Svar::feil('Fant ikke oppforingen.', 404);
}

switch (Foresporsel::tekst('handling')) {

    case 'varsle':
        $frist = gmdate('Y-m-d H:i:s', time() + 24 * 3600);
        $lenke = Config::nettsted() . '/kurs';

        Varsel::mal('venteliste_ledig', [
            'epost'   => $rad['epost'],
            'telefon' => $rad['telefon'],
        ], [
            'navn'  => (string) $rad['navn'],
            'kurs'  => (string) $rad['tittel'],
            'dato'  => '',
            'lenke' => $lenke,
        ], 'waitlist', $id);

        DB::oppdater('waitlist', [
            'status'     => 'varslet',
            'varslet_at' => gmdate('Y-m-d H:i:s'),
            'frist_at'   => $frist,
        ], ['id' => $id]);

        revider('venteliste_varslet', 'waitlist', $id, ['kurs' => $rad['tittel']]);

        Svar::ok([
            'beskjed' => 'Beskjed lagt i kø til ' . $rad['navn']
                . '. Plassen holdes ikke av automatisk — første som booker, får den.',
        ]);

    case 'fjern':
        DB::oppdater('waitlist', ['status' => 'fjernet'], ['id' => $id]);
        revider('venteliste_fjernet', 'waitlist', $id);
        Svar::ok(['beskjed' => $rad['navn'] . ' er tatt av lista.']);

    default:
        Svar::feil('Ukjent handling.');
}
