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
// ligger i secrets.php. Uten den avvises meldingen foer inboxen skrives.
$hemmelighet = (string) Config::hent('vipps_webhook_secret', '');
$signert = false;

// Uten verifisering skal hendelsen heller ikke kunne blokkere senere replay.
if ($hemmelighet === '') {
    Rate::sjekk('webhook-usignert', maks: 60, vindu: 600);
    Svar::feil('Ugyldig signatur.', 401);
}

if ($hemmelighet !== '') {
    // Vipps sitt format — se Vipps::webhookSignert(). Noen webhotell tar
    // Authorization ut av $_SERVER; da hentes den fra hodene direkte.
    $server = $_SERVER;
    if (empty($server['HTTP_AUTHORIZATION']) && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $n => $v) {
            if (strtolower((string) $n) === 'authorization') {
                $server['HTTP_AUTHORIZATION'] = (string) $v;
            }
        }
    }
    $signert = Vipps::webhookSignert($raa, $hemmelighet, $server);

    if (!$signert) {
        logg_feil('Webhook med feil signatur avvist');
        Svar::feil('Ugyldig signatur.', 401);
    }
}

$hendelsesId = (string) ($data['eventId'] ?? $data['pspReference'] ?? ($data['reference'] ?? '') . ':' . ($data['name'] ?? ''));
$referanse   = (string) ($data['reference'] ?? '');
$navn        = strtoupper((string) ($data['name'] ?? ''));

if ($hendelsesId === '' || mb_strlen($hendelsesId) > 191 || $referanse === '' || mb_strlen($referanse) > 64) {
    Svar::feil('Ugyldig innhold.', 400);
}

// Forbindelseslaas serialiserer replay uten en ytre transaksjon rundt HTTP
// eller Booking::markerBetalt(), som har sin egen transaksjon.
$laas = 'vipps-event:' . substr(hash('sha256', mb_strtolower($hendelsesId)), 0, 48);
if ((int) DB::verdi('SELECT GET_LOCK(:l, 5)', ['l' => $laas]) !== 1) {
    Svar::json(['ok' => false], 503);
}
$httpStatus = 200;
$svar = ['ok' => true];

// Hva tilstanden betyr, staar ett sted: Vipps::anvendTilstand(). Her sto
// den samme regelen én gang til, og cron hadde sin egen halve utgave — tre
// steder som kunne komme i utakt om ett av dem ble rettet.
//
// Aggregate trengs for aa skille delvis/full capture og refusjon. Hendelsens
// tilstand beholdes ved avstemming: et tregt oppslag maa ikke gjoere en
// CAPTURED-hendelse til AUTHORIZED og starte et nytt trekk.
try {
    DB::kjor(
        'INSERT INTO vipps_webhook_events (event_id, type, referanse, payload)
         VALUES (:e, :t, :r, :p) ON DUPLICATE KEY UPDATE event_id = event_id',
        ['e' => $hendelsesId, 't' => mb_substr($navn, 0, 128), 'r' => $referanse, 'p' => $raa]
    );
    $sett = DB::en('SELECT behandlet_at FROM vipps_webhook_events WHERE event_id = :e', ['e' => $hendelsesId]);
    if ($sett !== null && $sett['behandlet_at'] !== null) {
        $svar['duplikat'] = true;
    } else {
        // Tidligere usignerte/feilede rader er ikke ferdigbehandlet. Bruk den
        // verifiserte meldingen som kom naa, ikke det gamle lagrede innholdet.
        DB::oppdater('vipps_webhook_events', ['payload' => $raa, 'type' => mb_substr($navn, 0, 128), 'referanse' => $referanse], ['event_id' => $hendelsesId]);
        if (($data['success'] ?? true) !== false) {
            $status = $navn === 'AUTHORIZED' ? Vipps::hentBetaling($referanse) : ['state' => $navn];
            if (in_array($navn, ['CAPTURED', 'REFUNDED'], true)) {
                $psp = (string) ($data['pspReference'] ?? '');
                $status = $psp !== ''
                    ? Vipps::avstemHendelse($referanse, $psp, $navn)
                    : Vipps::hentBetaling($referanse);
                $status['state'] = $navn; // legacy eventId-meldinger beholder ogsaa hendelsens tilstand
                $status['hendelsesbelop_ore'] = (int) ($data['amount']['value'] ?? 0);
            }
            Vipps::anvendTilstand($referanse, $status, true);
        }

        DB::oppdater('vipps_webhook_events', ['behandlet_at' => gmdate('Y-m-d H:i:s'), 'feilmelding' => null], ['event_id' => $hendelsesId]);
    }
} catch (Throwable $e) {
    logg_feil('Webhook-behandling feilet for ' . $referanse, $e);
    try {
        DB::oppdater('vipps_webhook_events', [
            'feilmelding' => mb_substr($e->getMessage(), 0, 500),
        ], ['event_id' => $hendelsesId]);
    } catch (Throwable $lagringsfeil) {
        logg_feil('Kunne ikke lagre webhook-feil', $lagringsfeil);
    }
    $httpStatus = 503;
    $svar = ['ok' => false];
} finally {
    DB::verdi('SELECT RELEASE_LOCK(:l)', ['l' => $laas]);
}

Svar::json($svar, $httpStatus);
