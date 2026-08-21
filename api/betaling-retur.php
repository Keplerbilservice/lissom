<?php
/**
 * Hit sendes kunden tilbake fra Vipps.
 *
 * Vi stoler ALDRI paa at kunden kom tilbake som bevis paa at det er betalt —
 * hen kan ha trykket avbryt, eller lukket appen. Vi sporr Vipps direkte.
 * Webhooken er den egentlige kilden; dette er sikkerhetsnettet naar den er treg.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$referanse = Foresporsel::tekst('ref');
$tilbake = static fn(string $utfall): never =>
    Svar::omdiriger(Config::nettsted() . '/#betaling=' . $utfall);

if ($referanse === '') {
    $tilbake('ukjent');
}

$betaling = DB::en('SELECT id, status FROM payments WHERE vipps_reference = :r', ['r' => $referanse]);
if ($betaling === null) {
    $tilbake('ukjent');
}

if ($betaling['status'] === 'betalt') {
    $tilbake('ok'); // webhooken rakk det først
}

try {
    $status = Vipps::hentBetaling($referanse);
} catch (Throwable $e) {
    logg_feil('Statusoppslag ved retur feilet for ' . $referanse, $e);
    // Betalingen kan likevel ha gaatt gjennom. Cron sjekker paa nytt om noen minutter.
    $tilbake('venter');
}

DB::oppdater('payments', [
    'siste_payload' => json_encode($status, JSON_UNESCAPED_UNICODE),
], ['id' => $betaling['id']]);

$tilstand = strtoupper((string) ($status['state'] ?? ''));

if ($tilstand === 'AUTHORIZED') {
    try {
        Vipps::trekk($referanse, (int) ($status['aggregate']['authorizedAmount']['value'] ?? 0));
    } catch (Throwable $e) {
        logg_feil('Trekk feilet ved retur for ' . $referanse, $e);
    }
    Booking::markerBetalt($referanse);
    $tilbake('ok');
}

if ($tilstand === 'TERMINATED' || $tilstand === 'ABORTED' || $tilstand === 'EXPIRED') {
    DB::oppdater('payments', ['status' => 'avbrutt'], ['id' => $betaling['id']]);
    $tilbake('avbrutt');
}

$tilbake('venter');
