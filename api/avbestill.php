<?php
/**
 * Avbestilling av egen plass.
 *
 * Reglene staar i vilkaarene, og regnes ut her framfor aa overlates til
 * kunden: mer enn 14 dager for kursstart gir full refusjon, 14 til 7 dager
 * gir halv, naermere enn 7 dager gir ingen. Drop-in foelger et dogn.
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
} elseif ($timerIgjen === null) {
    $andel = 1.0;
    $regel = 'Kurset har ingen fastsatt dato, saa hele beloepet refunderes.';
} elseif ($b['type'] === 'dropin') {
    $andel = $timerIgjen > 24 ? 1.0 : 0.0;
    $regel = $timerIgjen > 24
        ? 'Drop-in avbestilt mer enn et dogn for: full refusjon.'
        : 'Drop-in avbestilt naermere enn et dogn for: ingen refusjon.';
} elseif ($timerIgjen > 14 * 24) {
    $andel = 1.0;
    $regel = 'Avbestilt mer enn 14 dager for kursstart: full refusjon.';
} elseif ($timerIgjen > 7 * 24) {
    $andel = 0.5;
    $regel = 'Avbestilt mellom 14 og 7 dager for kursstart: 50 % refusjon.';
} else {
    $andel = 0.0;
    $regel = 'Avbestilt naermere enn 7 dager for kursstart: ingen refusjon. '
           . 'Du kan gi plassen til en annen — ta kontakt, saa ordner vi det.';
}

$refunderes = (int) round($betalt * $andel);
$refundert = false;
$manuelt = false;

// --- Selve refusjonen -----------------------------------------------------
if ($refunderes > 0 && $b['vipps_reference'] && $b['betalingsstatus'] === 'betalt') {
    try {
        Vipps::refunder((string) $b['vipps_reference'], $refunderes);
        DB::oppdater('payments', [
            'refundert_ore' => (int) $b['refundert_ore'] + $refunderes,
            'status'        => $refunderes >= $betalt ? 'refundert' : 'delvis_refundert',
        ], ['id' => $b['pid']]);
        $refundert = true;
    } catch (Throwable $e) {
        // Avbestillingen staar uansett. Pengene ordnes for haand framfor aa
        // late som ingenting skjedde — kunden har jo mistet plassen.
        logg_feil('Refusjon feilet ved avbestilling av booking ' . $bookingId, $e);
        $manuelt = true;
    }
}

DB::oppdater('bookings', [
    'status'       => $refundert ? 'refundert' : 'avbestilt',
    'avbestilt_at' => gmdate('Y-m-d H:i:s'),
], ['id' => $bookingId]);

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
    'belop' => $refunderes > 0 ? Booking::kroner($refunderes) : 'ingen refusjon',
], 'booking', $bookingId);

Svar::ok([
    'regel'      => $regel,
    'refunderes' => Booking::kroner($refunderes),
    'manuelt'    => $manuelt,
    'beskjed'    => $manuelt
        ? 'Plassen er avbestilt. Refusjonen maatte vi ta manuelt — du hoerer fra oss i lopet av kort tid.'
        : ($refunderes > 0
            ? 'Plassen er avbestilt. Pengene er paa vei tilbake til Vipps, vanligvis innen tre virkedager.'
            : 'Plassen er avbestilt.'),
]);
