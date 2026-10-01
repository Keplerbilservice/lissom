<?php
/**
 * Avbestilling av egen plass.
 *
 * Reglene staar i vilkaarene, og regnes ut her framfor aa overlates til
 * kunden: mer enn to dager for kursstart gir full refusjon, naermere enn det
 * gir ingen.
 *
 * Eieren, 3. september, med kursvilkaarene sine: «Ved avbestilling mer enn 2
 * dager for kursstart refunderes kursavgiften fullt ut. Ved avbestilling
 * mindre enn 2 dager for kursstart refunderes ikke kursavgiften.» Og:
 * «jeg vil at mine regler skal gjelde».
 *
 * Foer sto det tre trinn her — alt over fjorten dager, halvparten mellom
 * fjorten og sju, ingenting naermere. Det var strengere enn vilkaarene sier
 * naa: en som avbestilte fem dager for fikk ingenting, der teksten lover alt
 * tilbake. Regelen og teksten maa si det samme, ellers lover nettsida noe
 * kassa ikke gjor.
 *
 * Beloepet regnes alltid ut fra det som faktisk ble betalt, aldri fra noe
 * nettleseren sender.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$medlem = krev_medlem();
Rate::sjekk('avbestill', maks: 10, vindu: 3600, nokkel: (string) $medlem['id']);

$bookingId = Foresporsel::heltall('bookingId');

$b = DB::en(
    'SELECT b.*, c.tittel, c.type, cs.start_tid, p.vipps_reference, p.belop_ore AS betalt_ore,
            p.refundert_ore, p.status AS betalingsstatus, p.id AS pid
       FROM bookings b
       JOIN courses c ON c.id = b.course_id
  LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
  LEFT JOIN payments p ON p.id = b.payment_id
      WHERE b.id = :i AND b.member_id = :m',
    ['i' => $bookingId, 'm' => $medlem['id']]
);

if ($b === null) {
    Svar::feil('Fant ikke plassen din.', 404);
}
if ($b['status'] === 'avbestilt' || $b['status'] === 'refundert') {
    Svar::feil('Denne plassen er allerede avbestilt.', 409);
}

// --- Hvor mye skal tilbake? ----------------------------------------------
$betalt = (int) ($b['betalt_ore'] ?? 0);
$timerIgjen = $b['start_tid'] ? (strtotime((string) $b['start_tid']) - time()) / 3600 : null;

if ($betalt === 0) {
    $andel = 0.0;
    $regel = 'Ingenting var belastet.';
} else {
    // Selve regelen staar i Booking::avbestillingsregel(). Den samme brukes
    // av api/mine-plasser.php, som forteller kunden hva hen faar — sto den to
    // steder, kunne de si hver sitt om de samme pengene.
    $r = Booking::avbestillingsregel($timerIgjen);
    $andel = $r['andel'];
    $regel = $r['regel'];
}

$refunderes = (int) round($betalt * $andel);
$refundert = false;
$manuelt = false;

$harClaim = false;
$claim = static function () use ($bookingId, $medlem, $b, &$harClaim): void {
    $endret = DB::kjor(
    "UPDATE bookings SET status = 'avbestilt', avbestilt_at = UTC_TIMESTAMP()
      WHERE id = :i AND member_id = :m AND status = :s AND avbestilt_at IS NULL",
    ['i' => $bookingId, 'm' => $medlem['id'], 's' => $b['status']]
    )->rowCount();
    if ($endret !== 1) {
        throw new RuntimeException('Denne plassen er allerede avbestilt.', 409);
    }
    $harClaim = true;
};

// --- Selve refusjonen -----------------------------------------------------
$viaVipps = $refunderes > 0 && $b['vipps_reference']
    && in_array($b['betalingsstatus'], ['betalt', 'delvis_refundert'], true);
if ($viaVipps) {
    try {
        // Bookingclaim og varig refusjonsintent committes i samme transaksjon.
        $resultat = Booking::refunderBetaling((int) $b['pid'], $refunderes, null, $claim);
        $refunderes = $resultat['belop'];
        $refundert = $resultat['gjenstaar'] === 0;
        $manuelt = !$refundert;
    } catch (Throwable $e) {
        if (!$harClaim && $e instanceof RuntimeException && $e->getMessage() === 'Refusjon behandles allerede.') {
            Svar::feil('Noe gikk galt. Prøv igjen, eller ta kontakt med oss.', 409);
        }
        if ($e->getCode() === 409) {
            Svar::feil('Denne plassen er allerede avbestilt.', 409);
        }
        if (!$harClaim) { throw $e; }
        $avbestilt = DB::verdi('SELECT avbestilt_at FROM bookings WHERE id = :i', ['i' => $bookingId]);
        if ($avbestilt === null) { throw $e; }
        // Avbestillingen staar uansett. Pengene ordnes for haand framfor aa
        // late som ingenting skjedde — kunden har jo mistet plassen.
        logg_feil('Refusjon feilet ved avbestilling av booking ' . $bookingId, $e);
        $manuelt = true;
    }
} elseif ($refunderes > 0) {
    // Ingen bekreftet Vipps-refusjon: bruk den eksisterende manuell-teksten.
    $manuelt = true;
}
if (!$viaVipps) {
    try { $claim(); }
    catch (RuntimeException $e) { Svar::feil('Denne plassen er allerede avbestilt.', 409); }
}

if ($refundert) {
    DB::oppdater('bookings', ['status' => 'refundert'], ['id' => $bookingId]);
}

revider('avbestilling', 'booking', $bookingId, [
    'refundert_ore' => $refundert ? $refunderes : 0,
    'manuelt'       => $manuelt,
]);

Varsel::mal('avbestilling', [
    'epost'   => $medlem['epost'],
    'telefon' => $medlem['telefon'],
], [
    'navn'  => (string) $medlem['navn'],
    'kurs'  => (string) $b['tittel'] . ($b['start_tid'] ? ' — ' . Booking::norskDato((string) $b['start_tid']) : ''),
    // Refusjonen staar bare i e-posten naar den faktisk skjer. Tomme felt
    // fjerner avsnittet og radene (Varsel::oppsett()).
    'belop'        => $refunderes > 0 ? Booking::kroner($refunderes) : '',
    'refusjon'     => $refundert
        ? 'Pengene er på vei tilbake til deg på Vipps.'
        : ($manuelt ? 'Refusjonen tar vi manuelt — du hører fra oss i løpet av kort tid.' : ''),
    'refusjonstid' => $refundert ? 'Vanligvis innen tre virkedager' : '',
], 'booking', $bookingId);

Svar::ok([
    'regel'      => $regel,
    'refunderes' => Booking::kroner($refunderes),
    'manuelt'    => $manuelt,
    'beskjed'    => $manuelt
        ? 'Plassen er avbestilt. Refusjonen måtte vi ta manuelt — du hører fra oss i løpet av kort tid.'
        : ($refundert
            ? 'Plassen er avbestilt. Pengene er på vei tilbake til Vipps, vanligvis innen tre virkedager.'
            : 'Plassen er avbestilt.'),
]);
