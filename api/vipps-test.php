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
 * Krever samme nokkel som helsesjekken. Oppretter en betaling paa én krone for
 * aa se om det gaar, og avbryter den umiddelbart — ingen penger flyttes.
 *
 * Én krone, ikke ett ore: Vipps godtar ikke beloep under 100 ore, og svarer
 * da 400 med «Invalid amount» og en tekst om desimaler. Den teksten peker
 * feil vei, og det kostet en runde aa finne ut av.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

// Noekkelen eller en innlogget admin — samme ordning som helsesjekken.
// Denne oppretter en betaling paa én krone mot ekte Vipps, saa den skal ikke
// kunne utloses av en lenke noen andre lager: kallet maa komme fra en adresse
// du selv har aapnet.
$nokkel = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');
$medNokkel = $nokkel !== '' && $oppgitt !== '' && hash_equals($nokkel, $oppgitt);
$fraEgenHand = fra_egen_side();

if (!$medNokkel && !(Sesjon::erAdmin() && $fraEgenHand)) {
    Svar::feil('Fant ikke siden.', 404);
}

// Betalingen kan ha sitt eget sett noekler, paa sin egen salgsenhet.
// Innlogging og betaling er to produkter hos Vipps, og godkjennes hver for
// seg — da kommer de ofte med hvert sitt sett.
$nokler = Vipps::betalingNokler();

$svar = [
    'miljo'         => Config::miljo(),
    'vipps_base'    => Config::vippsBase(),
    'msn'           => $nokler['msn'],
    'msn_innlogging' => (string) Config::hent('vipps_msn', ''),
    'egne_nokler'   => Vipps::egneBetalingsnokler(),
    'retur_uri'     => Vipps::returAdresse(),
];

// --- Steg 1: adgangstoken -------------------------------------------------
//
// Naar nettet ikke naar fram, kaster kallet. Denne sida er den man aapner
// nettopp naar noe er galt, og da skal den svare med hva som er galt — ikke
// med en feilmelding fra innmaten.
$nettfeil = '';
try {
    $raa = http_post_form(
        Config::vippsBase() . '/accesstoken/get',
        [],
        [
            'client_id: ' . $nokler['client_id'],
            'client_secret: ' . $nokler['client_secret'],
            'Ocp-Apim-Subscription-Key: ' . $nokler['sub_key'],
            'Merchant-Serial-Number: ' . $nokler['msn'],
        ]
    );
} catch (Throwable $e) {
    $raa = ['status' => 0, 'kropp' => ''];
    $nettfeil = $e->getMessage();
}

$svar['token'] = [
    'http' => $raa['status'],
    'ok'   => $raa['status'] === 200,
];
if ($nettfeil !== '') {
    $svar['token']['nettfeil'] = $nettfeil;
}

if ($raa['status'] !== 200) {
    // Svaret her inneholder ingen hemmeligheter — kun hvorfor det ble avvist.
    $svar['token']['svar'] = mb_substr($raa['kropp'], 0, 600);
    $svar['tolkning'] = $nettfeil !== ''
        ? 'Serveren fikk ikke kontakt med Vipps: ' . $nettfeil
        : 'Nøklene godtas ikke. Sjekk at alle fire hører til '
          . 'samme salgsenhet, og at miljøet stemmer: produksjonsnøkler virker '
          . 'bare mot api.vipps.no, testnøkler bare mot apitest.vipps.no.';
    // Ingen vits i aa proeve betaling og avtaler uten et token. Fasiten
    // lenger nede skrives likevel — den som staar i admin skal faa vite
    // hvorfor, ikke bare at ingenting skjedde.
    $svar['betaling'] = ['ok' => false, 'hoppet_over' => true];
    $svar['recurring'] = ['ok' => false, 'hoppet_over' => true,
                          'tolkning' => 'Ikke prøvd — nøklene ble avvist først.'];
}

// --- Steg 2: opprett en betaling paa én krone -----------------------------
//
// Bare naar tokenet gikk gjennom. Uten et token sier de neste kallene
// ingenting nytt — de ville feilet av samme grunn.
if ($raa['status'] === 200) {
    $referanse = Vipps::nyReferanse('TEST');

    try {
        $betaling = Vipps::opprettBetaling(
            $referanse,
            Vipps::MINSTE_BELOP_ORE,
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
                'amount'             => ['currency' => 'NOK', 'value' => Vipps::MINSTE_BELOP_ORE],
                'paymentMethod'      => ['type' => 'WALLET'],
                'reference'          => Vipps::nyReferanse('TEST'),
                'userFlow'           => 'WEB_REDIRECT',
                'returnUrl'          => Config::nettsted() . '/api/betaling-retur.php',
                'paymentDescription' => 'Teknisk test',
            ],
            [
                'Authorization: Bearer ' . Vipps::token(),
                'Ocp-Apim-Subscription-Key: ' . $nokler['sub_key'],
                'Merchant-Serial-Number: ' . $nokler['msn'],
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
                . 'Ocp-Apim-Subscription-Key hører til samme salgsenhet som MSN.';
        } elseif ($direkte['status'] === 400) {
            $svar['tolkning'] = 'Vipps avviste innholdet i forespørselen. '
                . 'Feltet som klages på står i svaret over.';
        }
    }

    // --- Steg 3: Recurring ------------------------------------------------
    //
    // Medlemskapene trekkes gjennom Recurring, som er et eget produkt hos
    // Vipps og maa godkjennes for seg. Vi ber om lista over avtaler framfor
    // aa opprette en: aa opprette en avtale sender en foresporsel til en
    // ekte telefon.
    $avtaler = http_get_json(
        Config::vippsBase() . '/recurring/v3/agreements?status=ACTIVE&pageSize=1',
        [
            'Authorization: Bearer ' . Vipps::token(),
            'Ocp-Apim-Subscription-Key: ' . $nokler['sub_key'],
            'Merchant-Serial-Number: ' . $nokler['msn'],
            'Vipps-System-Name: lissom',
            'Vipps-System-Version: 1.0',
        ]
    );

    $svar['recurring'] = [
        'http' => $avtaler['status'],
        'ok'   => $avtaler['status'] === 200,
    ];
    if ($avtaler['status'] !== 200) {
        $svar['recurring']['svar'] = mb_substr($avtaler['kropp'], 0, 600);
        $svar['recurring']['tolkning'] = $avtaler['status'] === 403
            ? 'Recurring er ikke aktivert på salgsenheten. Medlemskap kan ikke trekkes automatisk før det er på plass.'
            : ($avtaler['status'] === 401
                ? 'Tokenet godtas ikke for Recurring. Sjekk at abonnementsnøkkelen gjelder samme salgsenhet.'
                : 'Vipps svarte ' . $avtaler['status'] . ' på avtalelista.');
    }
}

// --- Kort fasit, i klartekst ----------------------------------------------
//
// Skjermen i admin viser disse linjene som de er. Den som staar der skal
// slippe aa lese HTTP-koder for aa vite om det virker.
$svar['kort'] = [
    [
        'hva'  => 'Miljø',
        'ok'   => Config::miljo() === 'produksjon',
        'sier' => Config::miljo() === 'produksjon'
            ? 'Produksjon — ekte betalinger mot ' . Config::vippsBase()
            : 'Test — ingen ekte penger. Sett miljø til produksjon i secrets.php når dere er klare.',
    ],
    [
        'hva'  => 'Nøkler for betaling',
        'ok'   => ($svar['token']['ok'] ?? false) === true,
        // Salgsenheten staar her uansett om det gikk eller ikke. Er det to
        // sett i bruk, er «hvilket ble proevd» det foerste man vil vite naar
        // svaret er nei.
        'sier' => 'Salgsenhet ' . $nokler['msn']
            . (Vipps::egneBetalingsnokler()
                ? ' — eget sett for betaling, atskilt fra innloggingen. '
                : ' — samme sett som innloggingen bruker. ')
            . (($svar['token']['ok'] ?? false)
                ? 'Vipps godtar nøklene.'
                : ($nettfeil !== ''
                    ? 'Serveren fikk ikke kontakt med Vipps i det hele tatt: ' . $nettfeil
                    : 'Vipps godtar ikke nøklene.')),
    ],
    [
        'hva'  => 'Betaling for kurs',
        'ok'   => ($svar['betaling']['ok'] ?? false) === true,
        'sier' => ($svar['betaling']['ok'] ?? false)
            ? 'Virker. En prøvebetaling på én krone ble opprettet og avbrutt igjen.'
            : 'Virker ikke. ' . (string) ($svar['tolkning'] ?? 'Se svaret fra Vipps under.'),
    ],
    [
        'hva'  => 'Trekk for medlemskap',
        'ok'   => ($svar['recurring']['ok'] ?? false) === true,
        'sier' => ($svar['recurring']['ok'] ?? false)
            ? 'Virker. Avtalene kan opprettes og trekkes.'
            : (string) ($svar['recurring']['tolkning'] ?? 'Virker ikke.'),
    ],
];
// Innlogging med Vipps er et eget produkt, paa sitt eget sett. Den staar
// nederst fordi den ikke stopper betalingen — men den bor ikke vaere glemt.
$svar['kort'][] = [
    'hva'  => 'Innlogging med Vipps',
    'ok'   => trim((string) Config::hent('vipps_client_id', '')) !== '',
    'sier' => trim((string) Config::hent('vipps_client_id', '')) !== ''
        ? (Vipps::egneBetalingsnokler()
            ? 'Eget sett, salgsenhet ' . (string) Config::hent('vipps_msn', '') . '. Retur går til ' . Vipps::returAdresse()
            : 'Samme sett som betalingen. Retur går til ' . Vipps::returAdresse())
        : 'Ingen nøkler satt. Da kan ingen logge inn med Vipps.',
];

$svar['alt_ok'] = !in_array(false, array_column($svar['kort'], 'ok'), true);

Svar::json($svar);
