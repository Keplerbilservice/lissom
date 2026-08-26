<?php
/**
 * Bildene deltakeren tar av keramikken sin.
 *
 *   GET  ?bookingId=12          mine bilder på den påmeldingen
 *   POST handling=last-opp      multipart, felt «bilde» + bookingId
 *   POST handling=slett         { bildeId }
 *
 * Verkstedet står med tjue ferdigbrente ting og skal finne ut hvem som har
 * laget hva. Et bilde deltakeren selv tok på kurset avgjør det på et sekund.
 *
 * Bildet knyttes til bookingen, ikke til personen: bookingen er deltakeren,
 * kurset og datoen i én rad, og den er alt autorisert. Har du ikke den
 * bookingen, finnes den ikke for deg — hver forespørsel sjekkes mot
 * member_id på serveren, ikke i skjermbildet.
 *
 * Filene går gjennom Bilder::taImot() som alt annet som lastes opp: tegnes om
 * med GD, skaleres til 1400 piksler, lagres som JPEG utenfor det som
 * publiseres. Ingen ny lagringsløsning.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem = krev_medlem();

if (!DB::harTabell('deltaker_bilder')) {
    Svar::feil('Bildeopplasting er ikke slått på ennå.');
}

/** Hvor mange bilder én deltaker kan legge på én påmelding. */
const MAKS_PER_BOOKING = 8;

/**
 * Bookingen, hvis den er din.
 *
 * Null betyr «finnes ikke for deg» — vi skiller ikke mellom «finnes ikke» og
 * «tilhører en annen». Det andre svaret ville fortalt en fremmed at
 * booking 412 finnes.
 */
function minBooking(int $id, int $medlemId): ?array
{
    return DB::en(
        "SELECT b.id, b.status, c.tittel, cs.start_tid
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
          WHERE b.id = :i AND b.member_id = :m
            AND b.status IN ('betalt', 'reservert')",
        ['i' => $id, 'm' => $medlemId]
    );
}

/** Bildene på én booking, slik deltakeren skal se dem. */
function mine(int $bookingId): array
{
    return array_map(
        static fn($b) => [
            'id'   => (int) $b['id'],
            'url'  => 'api/bilde.php?deltaker=' . $b['fil'],
            'naar' => Booking::norskDato((string) $b['created_at']),
            // Bare det du selv har lagt inn kan du ta bort igjen.
            'kanSlette' => $b['lastet_opp_av'] !== null,
        ],
        DB::alle('SELECT id, fil, lastet_opp_av, created_at FROM deltaker_bilder
                   WHERE booking_id = :b ORDER BY id',
                 ['b' => $bookingId])
    );
}

// ── Mine bilder ────────────────────────────────────────────────────────────
if (Foresporsel::metode() === 'GET') {
    $bookingId = Foresporsel::heltall('bookingId');
    if (minBooking($bookingId, (int) $medlem['id']) === null) {
        Svar::feil('Fant ikke påmeldingen.', 404);
    }
    Svar::json(['ok' => true, 'bilder' => mine($bookingId), 'maks' => MAKS_PER_BOOKING]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

// multipart: $_POST, ikke JSON-kroppen.
$handling = (string) ($_POST['handling'] ?? Foresporsel::tekst('handling'));

// ── Legg inn et bilde ──────────────────────────────────────────────────────
if ($handling === 'last-opp') {
    $bookingId = (int) ($_POST['bookingId'] ?? 0);
    $booking = minBooking($bookingId, (int) $medlem['id']);
    if ($booking === null) {
        Svar::feil('Fant ikke påmeldingen.', 404);
    }

    if (!isset($_FILES['bilde']) || ($_FILES['bilde']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        Svar::feil('Du må velge et bilde.');
    }

    $har = (int) DB::verdi('SELECT COUNT(*) FROM deltaker_bilder WHERE booking_id = :b', ['b' => $bookingId]);
    if ($har >= MAKS_PER_BOOKING) {
        Svar::feil('Du har ' . $har . ' bilder på dette kurset alt. Slett ett før du legger inn flere.');
    }

    try {
        $navn = Bilder::taImot($_FILES['bilde'], 'deltakere');
    } catch (RuntimeException $e) {
        // Teksten fra Bilder er skrevet for den som laster opp: «Bildet er for
        // stort. Maks 8 MB», «Filen må være JPG, PNG eller WEBP».
        Svar::feil($e->getMessage());
    }

    DB::settInn('deltaker_bilder', [
        'booking_id'    => $bookingId,
        'fil'           => $navn,
        'lastet_opp_av' => (int) $medlem['id'],
    ]);

    Svar::ok([
        'beskjed' => 'Bildet er lagt inn.',
        'bilder'  => mine($bookingId),
    ]);
}

// ── Ta et bilde bort igjen ─────────────────────────────────────────────────
if ($handling === 'slett') {
    $bildeId = (int) (Foresporsel::kropp()['bildeId'] ?? $_POST['bildeId'] ?? 0);
    $rad = DB::en(
        "SELECT db.id, db.fil, db.booking_id, db.lastet_opp_av
           FROM deltaker_bilder db
           JOIN bookings b ON b.id = db.booking_id
          WHERE db.id = :i AND b.member_id = :m",
        ['i' => $bildeId, 'm' => (int) $medlem['id']]
    );
    if ($rad === null) {
        Svar::feil('Fant ikke bildet.', 404);
    }
    // Et bilde verkstedet har lagt inn er deres, ikke ditt.
    if ($rad['lastet_opp_av'] === null) {
        Svar::feil('Dette bildet er lagt inn av verkstedet. Ta kontakt om det skal bort.');
    }

    DB::kjor('DELETE FROM deltaker_bilder WHERE id = :i', ['i' => $bildeId]);
    Bilder::slett((string) $rad['fil'], 'deltakere');

    Svar::ok([
        'beskjed' => 'Bildet er slettet.',
        'bilder'  => mine((int) $rad['booking_id']),
    ]);
}

Svar::feil('Ukjent handling.');
