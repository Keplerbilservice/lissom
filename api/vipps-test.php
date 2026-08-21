<?php
/**
 * Provekall mot Vipps, med det ekte svaret i klartekst.
 *
 * Naar en betaling ikke lar seg starte, sier nettsiden bare «Fikk ikke startet
 * betalingen» — den skal ikke vise tekniske detaljer til kunder. Detaljene
 * havner i feilloggen, som er tungvint aa komme til. Denne sida gjor de samme
 * kallene og viser hva Vipps faktisk svarer.
 *
 *   https://ny.lissom.no/api/vipps-test.php?nokkel=...
 *
 * Krever samme nokkel som helsesjekken. Oppretter en betaling paa 1 ore for aa
 * se om det gaar, og avbryter den umiddelbart — ingen penger flyttes.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$nokkel = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');
if ($nokkel === '' || $oppgitt === '' || !hash_equals($nokkel, $oppgitt)) {
    Svar::feil('Fant ikke siden.', 404);
}

$svar = [
    'miljo'      => Config::miljo(),
    'vipps_base' => Config::vippsBase(),
    'msn'        => Config::hent('vipps_msn'),
    'retur_uri'  => Vipps::returAdresse(),
];

// --- Steg 1: adgangstoken -------------------------------------------------
$raa = http_post_form(
    Config::vippsBase() . '/accesstoken/get',
    [],
    [
        'client_id: ' . Config::krev('vipps_client_id'),
        'client_secret: ' . Config::krev('vipps_client_secret'),
        'Ocp-Apim-Subscription-Key: ' . Config::krev('vipps_sub_key'),
        'Merchant-Serial-Number: ' . Config::krev('vipps_msn'),
    ]
);

$svar['token'] = [
    'http' => $raa['status'],
    'ok'   => $raa['status'] === 200,
];

if ($raa['status'] !== 200) {
    // Svaret her inneholder ingen hemmeligheter — kun hvorfor det ble avvist.
    $svar['token']['svar'] = mb_substr($raa['kropp'], 0, 600);
    $svar['tolkning'] = 'Noklene godtas ikke. Sjekk at alle fire hoerer til '
        . 'samme salgsenhet, og at miljoet stemmer: produksjonsnokler virker '
        . 'bare mot api.vipps.no, testnokler bare mot apitest.vipps.no.';
    Svar::json($svar);
}

// --- Steg 2: opprett en betaling paa 1 øre --------------------------------
$referanse = Vipps::nyReferanse('TEST');

try {
    $betaling = Vipps::opprettBetaling(
        $referanse,
        1,
        'Teknisk test',
        Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse)
    );
    $svar['betaling'] = ['ok' => true, 'referanse' => $referanse, 'fikk_url' => $betaling['url'] !== ''];

    // Rydd opp med en gang — ingen skal kunne aapne denne og betale.
    try {
        Vipps::avbryt($referanse);
        $svar['betaling']['avbrutt'] = true;
    } catch (Throwable $e) {
        $svar['betaling']['avbrutt'] = false;
    }
} catch (Throwable $e) {
    // Det ekte svaret fra Vipps hentes en gang til, denne gangen uten aa
    // pakke det inn i en kundevennlig melding.
    $direkte = http_post_json(
        Config::vippsBase() . '/epayment/v1/payments',
        [
            'amount'             => ['currency' => 'NOK', 'value' => 1],
            'paymentMethod'      => ['type' => 'WALLET'],
            'reference'          => Vipps::nyReferanse('TEST'),
            'userFlow'           => 'WEB_REDIRECT',
            'returnUrl'          => Config::nettsted() . '/api/betaling-retur.php',
            'paymentDescription' => 'Teknisk test',
        ],
        [
            'Authorization: Bearer ' . Vipps::token(),
            'Ocp-Apim-Subscription-Key: ' . Config::krev('vipps_sub_key'),
            'Merchant-Serial-Number: ' . Config::krev('vipps_msn'),
            'Idempotency-Key: ' . Vipps::uuid(),
            'Vipps-System-Name: lissom',
            'Vipps-System-Version: 1.0',
        ]
    );

    $svar['betaling'] = [
        'ok'   => false,
        'http' => $direkte['status'],
        'svar' => mb_substr($direkte['kropp'], 0, 900),
    ];

    if ($direkte['status'] === 403) {
        $svar['tolkning'] = 'Salgsenheten har trolig ikke ePayment aktivert, '
            . 'eller abonnementsnokkelen gjelder et annet produkt.';
    } elseif ($direkte['status'] === 401) {
        $svar['tolkning'] = 'Tokenet godtas ikke for ePayment. Sjekk at '
            . 'Ocp-Apim-Subscription-Key hoerer til samme salgsenhet som MSN.';
    } elseif ($direkte['status'] === 400) {
        $svar['tolkning'] = 'Vipps avviste innholdet i forespoerselen. '
            . 'Feltet som klages paa staar i svaret over.';
    }
}

Svar::json($svar);
