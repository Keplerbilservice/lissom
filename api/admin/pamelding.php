<?php
/**
 * Paameldinger lagt inn for haand.
 *
 *   POST handling=legg-til   { oktId, navn, epost, telefon, antall,
 *                              betaltMaate, belop, notat, varsle }
 *   POST handling=fjern      { id }
 *   POST handling=status     { id, status }   betalt | reservert | ikke_mott
 *
 * Ikke alle bestiller paa nett. Noen ringer, noen staar i doera. De maa staa
 * paa samme deltakerliste som alle andre — ellers foerer verkstedet to
 * lister, og den ene stemmer aldri.
 *
 * En manuell paamelding er en helt vanlig booking. Den har ingen betaling
 * knyttet til seg, men den opptar en plass, den teller i kapasiteten, og den
 * kommer med paa deltakerlista og i beskjedene.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$admin = krev_admin();

const MAATER = ['Kontant', 'Vipps i verkstedet', 'Faktura', 'Betaler ved oppmøte', 'Gratis'];

$handling = Foresporsel::tekst('handling', 'legg-til');
$id       = Foresporsel::heltall('id');

// ------------------------------------------------------------ fjern plass
//
// Raden slettes ikke. En avbestilt booking frigir plassen, men beholder
// sporet — hvem som var paameldt og naar det ble endret.
if ($handling === 'fjern') {
    $b = DB::en('SELECT id, gjest_navn, member_id, payment_id FROM bookings WHERE id = :i', ['i' => $id]);
    if ($b === null) {
        Svar::feil('Fant ikke paameldingen.');
    }
    if ($b['payment_id'] !== null) {
        Svar::feil('Denne er betalt gjennom Vipps. Bruk refusjon, ikke sletting.');
    }

    DB::oppdater('bookings', [
        'status'       => 'avbestilt',
        'avbestilt_at' => gmdate('Y-m-d H:i:s'),
    ], ['id' => $id]);

    revider('pamelding_fjernet', 'booking', $id, ['navn' => $b['gjest_navn']]);
    Svar::ok(['beskjed' => 'Plassen er frigitt.']);
}

// ---------------------------------------------------------- endre status
if ($handling === 'status') {
    $status = Foresporsel::tekst('status');
    if (!in_array($status, ['betalt', 'reservert', 'ikke_mott'], true)) {
        Svar::feil('Ukjent status.');
    }
    if (DB::en('SELECT id FROM bookings WHERE id = :i', ['i' => $id]) === null) {
        Svar::feil('Fant ikke paameldingen.');
    }

    DB::oppdater('bookings', [
        'status'        => $status,
        // En reservasjon lagt inn for haand skal ikke frigis av seg selv.
        // Verkstedet vet hvem det er, og rydder selv.
        'reservert_til' => null,
    ], ['id' => $id]);

    revider('pamelding_status', 'booking', $id, ['status' => $status]);
    Svar::ok(['beskjed' => 'Statusen er endret.']);
}

// -------------------------------------------------------------- legg til
$oktId  = Foresporsel::heltall('oktId');
$navn   = mb_substr(Foresporsel::tekst('navn'), 0, 191);
$antall = max(1, min(20, Foresporsel::heltall('antall', 1)));

if ($navn === '') {
    Svar::feil('Deltakeren maa ha et navn.');
}

$okt = DB::en(
    'SELECT cs.id, cs.course_id, cs.start_tid, c.tittel, c.pris_ore
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
      WHERE cs.id = :i AND cs.status <> :a',
    ['i' => $oktId, 'a' => 'avlyst']
);
if ($okt === null) {
    Svar::feil('Velg en dato som finnes.');
}

$epost   = mb_substr(Foresporsel::tekst('epost'), 0, 191);
$telefon = normaliser_telefon(Foresporsel::tekst('telefon'));

if ($epost !== '' && !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
    Svar::feil('E-postadressen ser ikke riktig ut.');
}

$maate = Foresporsel::tekst('betaltMaate');
if (!in_array($maate, MAATER, true)) {
    $maate = 'Kontant';
}

// Belopet: tomt felt betyr kursets ordinaere pris. «Gratis» er null kroner,
// uansett hva som staar i feltet — ellers ville en fribillett kunnet vise en
// sum i regnskapet.
$belopRaa = Foresporsel::tekst('belop');
$belop = $maate === 'Gratis'
    ? 0
    : ($belopRaa === '' ? (int) $okt['pris_ore'] * $antall : Foresporsel::heltall('belop') * 100);
if ($belop < 0 || $belop > 10000000) {
    Svar::feil('Belopet maa vaere mellom 0 og 100 000 kroner.');
}

// «Betaler ved oppmote» er ikke betalt enda. Resten er gjort opp.
$status = $maate === 'Betaler ved oppmøte' ? 'reservert' : 'betalt';

// Er noen alt paameldt med samme navn paa samme dato, er det trolig et
// dobbelttrykk. Vi legger ikke inn to.
$fra = DB::en(
    "SELECT id FROM bookings
      WHERE course_session_id = :o AND gjest_navn = :n AND status <> 'avbestilt'",
    ['o' => $oktId, 'n' => $navn]
);
if ($fra !== null) {
    Svar::feil($navn . ' staar alt paa denne datoen.');
}

$ledige = Booking::ledigePlasser($oktId);

$bookingId = DB::iTransaksjon(static function () use ($okt, $oktId, $navn, $epost, $telefon, $antall, $belop, $status, $maate, $admin): int {
    return DB::settInn('bookings', [
        'course_id'         => (int) $okt['course_id'],
        'course_session_id' => $oktId,
        'member_id'         => null,
        'gjest_navn'        => $navn,
        'gjest_epost'       => $epost !== '' ? $epost : null,
        'gjest_telefon'     => $telefon !== '' ? $telefon : null,
        'antall'            => $antall,
        'belop_ore'         => $belop,
        'status'            => $status,
        'betalt_maate'      => $maate,
        'lagt_inn_av'       => (int) $admin['id'],
        'notat'             => mb_substr(Foresporsel::tekst('notat'), 0, 255) ?: null,
        'reservert_til'     => null,
    ]);
});

// Bekreftelse sendes bare naar eieren ber om det, og bare naar vi har en
// adresse aa sende til. En som melder seg paa i doera venter ikke e-post.
$varslet = false;
if (Foresporsel::tekst('varsle') === 'ja' && $epost !== '') {
    Booking::sendBekreftelse($bookingId);
    $varslet = true;
}

revider('pamelding_lagt_inn', 'booking', $bookingId, [
    'navn' => $navn, 'okt' => $oktId, 'maate' => $maate, 'belop_ore' => $belop,
]);

// Eieren bestemmer over sitt eget rom. Vi stopper ikke en niende deltaker —
// men vi sier fra, saa det ikke skjer uten at noen ser det.
$advarsel = $antall > $ledige
    ? ' Merk: datoen er nå overbooket med ' . ($antall - $ledige) . '.'
    : '';

Svar::ok([
    'id'      => $bookingId,
    'beskjed' => $navn . ' er lagt til på ' . $okt['tittel'] . ' '
                . Booking::norskDato((string) $okt['start_tid']) . '.'
                . ($varslet ? ' Bekreftelse er sendt.' : '')
                . $advarsel,
]);
