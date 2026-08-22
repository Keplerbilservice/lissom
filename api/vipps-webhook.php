<?php
/**
 * Vipps melder fra hit naar en betaling endrer tilstand.
 *
 * Dette er den egentlige kilden til om noe er betalt. Vipps kan sende samme
 * hendelse flere ganger, saa alt her taaler aa kjores om igjen.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');

$raa = file_get_contents('php://input') ?: '';
$data = json_decode($raa, true);

if (!is_array($data)) {
    Svar::feil('Ugyldig innhold.', 400);
}

// Signaturen bekreftes naar webhooken er registrert hos Vipps og hemmeligheten
// ligger i secrets.php. Uten den godtar vi kun aa notere hendelsen — vi lar
// aldri en usignert melding endre en betalingsstatus.
$hemmelighet = (string) Config::hent('vipps_webhook_secret', '');
$signert = false;

if ($hemmelighet !== '') {
    $oppgitt = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $ventet = 'HMAC-SHA256 ' . base64_encode(hash_hmac('sha256', $raa, $hemmelighet, true));
    $signert = $oppgitt !== '' && hash_equals($ventet, $oppgitt);

    if (!$signert) {
        logg_feil('Webhook med feil signatur avvist');
        Svar::feil('Ugyldig signatur.', 401);
    }
}

// Uten hemmelighet i secrets.php kan hvem som helst sende hit. Hendelsen far
// aldri endre en betaling — men den blir notert, og uten en grense kunne noen
// fylt tabellen med tusenvis av rader. Med hemmeligheten satt er signaturen
// alt som trengs, og da gjelder ingen grense: Vipps kan sende sa mange
// hendelser den vil.
if ($hemmelighet === '') {
    Rate::sjekk('webhook-usignert', maks: 60, vindu: 600);
}

$hendelsesId = (string) ($data['eventId'] ?? ($data['reference'] ?? '') . ':' . ($data['name'] ?? ''));
$referanse   = (string) ($data['reference'] ?? '');
$navn        = strtoupper((string) ($data['name'] ?? ''));

// Har vi sett denne for? Da er vi ferdige.
$sett = DB::en('SELECT event_id FROM vipps_webhook_events WHERE event_id = :e', ['e' => $hendelsesId]);
if ($sett !== null) {
    Svar::ok(['duplikat' => true]);
}

DB::settInn('vipps_webhook_events', [
    'event_id'  => mb_substr($hendelsesId, 0, 191),
    'type'      => mb_substr($navn, 0, 128),
    'referanse' => $referanse !== '' ? mb_substr($referanse, 0, 64) : null,
    'payload'   => $raa,
]);

if (!$signert) {
    logg('Webhook mottatt uten signaturkontroll — hemmelighet mangler i secrets.php');
    Svar::ok(['notert' => true]);
}

try {
    switch ($navn) {
        case 'AUTHORIZED':
            $status = Vipps::hentBetaling($referanse);
            Vipps::trekk($referanse, (int) ($status['aggregate']['authorizedAmount']['value'] ?? 0));
            Booking::markerBetalt($referanse);
            break;

        case 'CAPTURED':
            Booking::markerBetalt($referanse);
            break;

        case 'ABORTED':
        case 'EXPIRED':
        case 'TERMINATED':
            DB::kjor(
                "UPDATE payments SET status = 'avbrutt' WHERE vipps_reference = :r AND status <> 'betalt'",
                ['r' => $referanse]
            );
            break;

        case 'REFUNDED':
            DB::kjor(
                "UPDATE payments SET status = 'refundert' WHERE vipps_reference = :r",
                ['r' => $referanse]
            );
            break;
    }

    DB::oppdater('vipps_webhook_events', ['behandlet_at' => gmdate('Y-m-d H:i:s')], ['event_id' => $hendelsesId]);
} catch (Throwable $e) {
    logg_feil('Webhook-behandling feilet for ' . $referanse, $e);
    DB::oppdater('vipps_webhook_events', [
        'feilmelding' => mb_substr($e->getMessage(), 0, 500),
    ], ['event_id' => $hendelsesId]);
    // 200 uansett: Vipps skal ikke sende om igjen i det uendelige. Cron rydder opp.
}

Svar::ok();
