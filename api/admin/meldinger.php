<?php
/**
 * Meldingene i nye admin (/ny-admin › Innstillinger › Meldinger og Varsler).
 *
 *   GET                         hvor mange som er sendt per mal siste 30 dager,
 *                               og når de planlagte går ut
 *   POST handling=kanal         { navn, sms, epost }  SMS og/eller e-post på én mal
 *
 * Av/på og teksten lagres som før i api/admin/maler.php. Det eneste som ikke
 * hadde et sted å lagres fra skjermen, var kanalen — den står allerede på
 * malen (notification_templates.kanal: epost, sms eller epost_sms) og leses
 * av Varsel::mal(). Ingen ny tabell, ingen migrasjon.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

/**
 * Kanalene hver mal faktisk kan gaa paa, lest av kallene i koden (hvilke
 * mottakerfelt de sender med til Varsel::mal). Skjermen viser bare disse, og
 * lagringen nekter en kanal malen aldri sendes paa — ellers kunne en mal bli
 * staaende paa «bare SMS» uten at noen SMS noen gang gikk ut (testmesteren
 * 8. oktober 2026). Maler som ikke staar her, sendes bare paa e-post.
 *
 * @return list<string>
 */
function kanalerMulig(string $navn): array
{
    $begge = ['avbestilling', 'betaling_feilet', 'kurspaaminnelse', 'ordrebekreftelse', 'venteliste_ledig',
              'venteliste_tildelt', 'ferdig_brent', 'innmelding_fast_trekk', 'innmelding_ordner_selv',
              'kurs_avlyst'];
    // «pamelding_flyttet» staar ikke her: pamelding.php (flytting av én
    // person) sender den bare med e-post, og okt-varsel.php gjoer det samme.
    // Tilbys SMS, kunne malen staa paa «bare SMS» og aldri naa den som ble
    // flyttet enkeltvis (Codex 9. oktober 2026).
    $bareSms = ['foresporsel_svar_sms', 'kassekvittering_sms', 'kursbevis_sms', 'soknad_godkjent_sms', 'intern_nytt_medlem_sms'];
    if (in_array($navn, $bareSms, true)) {
        return ['sms'];
    }
    return in_array($navn, $begge, true) ? ['epost', 'sms'] : ['epost'];
}

if (!DB::harTabell('notification_templates')) {
    Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.', 503);
}

if (Foresporsel::metode() === 'GET') {
    $fra = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $telling = [];
    $sum = ['sms' => 0, 'epost' => 0];
    foreach (DB::alle(
        "SELECT mal, kanal, COUNT(*) AS n FROM notifications
          WHERE status = 'sendt' AND created_at >= :fra AND mal IS NOT NULL
          GROUP BY mal, kanal",
        ['fra' => $fra]
    ) as $r) {
        $k = (string) $r['kanal'] === 'sms' ? 'sms' : 'epost';
        $telling[(string) $r['mal']][$k] = (int) $r['n'];
        $sum[$k] += (int) $r['n'];
    }

    // Når de planlagte går ut. Leses fra det som faktisk styrer dem
    // (bin/cron.php og innstillingene), ikke skrevet på nytt et annet sted.
    $fortsett = max(1, min(14, (int) Config::hent('fortsett_dager', '3')));
    $naar = [
        'kurspaaminnelse' => 'Dagen før kurset, kl. 12 (SMS bare når kurset har SMS-påminnelse på)',
        'anmeldelse'      => 'Neste dag kl. 10',
        'kursbevis_sms'   => 'Neste dag kl. 10, sammen med anmeldelsen',
        'fortsett'        => $fortsett . ' dager etter kurset, om morgenen',
    ];

    $kanaler = [];
    foreach (DB::alle('SELECT navn FROM notification_templates') as $r) {
        $kanaler[(string) $r['navn']] = kanalerMulig((string) $r['navn']);
    }

    Svar::json([
        'kanaler'  => (object) $kanaler,
        'telling'  => (object) $telling,
        'sum'      => $sum,
        'naar'     => $naar,
        'smsMulig' => Varsel::smsMulig(),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (Foresporsel::tekst('handling') !== 'kanal') {
    Svar::feil('Ukjent handling.');
}

$navn = mb_substr(Foresporsel::tekst('navn'), 0, 64);
$mal  = $navn === '' ? null : DB::en('SELECT navn, kanal FROM notification_templates WHERE navn = :n', ['n' => $navn]);
if ($mal === null) {
    Svar::feil('Fant ikke meldingen.', 404);
}

$k    = Foresporsel::kropp();
$sms  = !empty($k['sms']);
$epost = !empty($k['epost']);
if (!$sms && !$epost) {
    Svar::feil('Minst én av SMS og e-post må være på. Skal meldingen ikke sendes, slå den av i stedet.');
}
$mulig = kanalerMulig($navn);
if (($sms && !in_array('sms', $mulig, true)) || ($epost && !in_array('epost', $mulig, true))) {
    Svar::feil(Maler::tittel($navn) . ' kan bare sendes på ' . (in_array('sms', $mulig, true) ? 'SMS' : 'e-post') . '.');
}
$kanal = $sms && $epost ? 'epost_sms' : ($sms ? 'sms' : 'epost');

DB::oppdater('notification_templates', ['kanal' => $kanal], ['navn' => $navn]);
revider('mal_kanal', 'mal', null, ['navn' => $navn, 'fra' => (string) $mal['kanal'], 'til' => $kanal]);

Svar::ok([
    'kanal'   => $kanal,
    'beskjed' => Maler::tittel($navn) . ': ' . ($kanal === 'epost_sms' ? 'SMS og e-post' : ($kanal === 'sms' ? 'bare SMS' : 'bare e-post')) . '.',
]);
