<?php
/**
 * Helsesjekk.
 *
 * Svarer på ett spørsmål: henger alt sammen? PHP, hemmeligheter, database,
 * tabeller, nøkler. Bygget fordi oppsettet spenner over tre systemer, og fordi
 * «det virker ikke» er et vanskelig utgangspunkt for feilsøking.
 *
 * Krever nøkkelen fra secrets.php:
 *   https://ny.lissom.no/api/status.php?nokkel=...
 *
 * Uten riktig nøkkel svarer den 404. Da røper den ikke engang at den finnes.
 * Den viser aldri hemmeligheter — kun om de er fylt ut eller ikke.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

// To veier inn: noekkelen fra secrets.php, eller en innlogget admin.
//
// Noekkelen finnes for at helsesjekken skal virke naar innlogging er det som
// er i stykker. Er du allerede logget inn som admin, er det unodvendig aa lete
// etter den i ei fil paa serveren.
$nokkel  = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');
$medNokkel = $nokkel !== '' && $oppgitt !== '' && hash_equals($nokkel, $oppgitt);

if (!$medNokkel && !Sesjon::erAdmin()) {
    Svar::feil('Fant ikke siden.', 404);
}

/** Sier om en hemmelighet er fylt ut — aldri hva den er. */
$fylt = static fn(string $n): bool => trim((string) Config::hent($n, '')) !== '';

$svar = [
    'slapp_inn' => $medNokkel ? 'noekkel' : 'admin-innlogging',
    'cron_nokkel_satt' => $nokkel !== '',
    'tidspunkt' => gmdate('c'),
    'php'       => PHP_VERSION,
    'miljo'     => Config::miljo(),
    'nettsted'  => Config::nettsted(),
];

// --- Database -------------------------------------------------------------
try {
    $tabeller = DB::alle('SHOW TABLES');
    $navn = array_map(static fn($r) => (string) array_values($r)[0], $tabeller);
    sort($navn);

    $kjort = [];
    if (in_array('migrations', $navn, true)) {
        $kjort = array_column(DB::alle('SELECT fil FROM migrations ORDER BY fil'), 'fil');
    }

    $svar['database'] = [
        'kontakt'     => true,
        'versjon'     => DB::kobling()->getAttribute(PDO::ATTR_SERVER_VERSION),
        'tabeller'    => count($navn),
        'migrasjoner' => $kjort,
        // Tabellene koden faktisk bruker.
        //
        // Lista sjekket «checkins», som ble laget i 001_init og som ingenting
        // leser. Innstemplingen ligger i «check_ins», laget paa nytt i
        // migrasjon 016. Helsesjekken sa altsaa «alt i orden» selv om den
        // ekte tabellen manglet — og ville sagt «mangler» om noen ryddet bort
        // den doede.
        //
        // «hour_usage» og «gift_card_uses» sto her av samme grunn.
        // gift_card_uses er tatt i bruk igjen fra 24. august: den er sporet
        // over hva gavekort er brukt paa. hour_usage leses fortsatt ikke av
        // noe — timene regnes ut fra check_ins — men tabellen staar, og den
        // fjernes ikke uten at eieren har sagt fra.
        'mangler'     => array_values(array_diff([
            'members', 'sessions', 'login_states', 'courses', 'course_sessions',
            'payments', 'vipps_webhook_events', 'bookings', 'waitlist',
            'gift_cards', 'gift_card_uses', 'products', 'orders', 'order_lines',
            'member_sales', 'check_ins', 'content_blocks',
            'notification_templates', 'notifications', 'audit_log', 'rate_limits',
            'membership_applications', 'innstillinger', 'chat_meldinger',
            'foresporsel_svar', 'kursholdere', 'kursholder_timer',
            'medlemsgaver', 'medlemsgave_bruk',
        ], $navn)),
        // Tabeller ingen kode leser. De staar igjen fra 001_init, og fjernes
        // ikke uten beskjed: er det rader i dem paa den ekte tjeneren, er det
        // historikk.
        'ubrukte'     => array_values(array_intersect(['checkins', 'hour_usage'], $navn)),
    ];

    // Kolonner som kom med senere migrasjoner. Mangler de, er migreringen
    // ikke kjort ferdig — og da virker ikke det de horer til.
    $kolonne = static function (string $tabell, string $kolonne): bool {
        return (int) DB::verdi(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :k',
            ['t' => $tabell, 'k' => $kolonne]
        ) === 1;
    };
    $svar['database']['kolonner'] = [
        'courses.instruktor' => $kolonne('courses', 'instruktor'),
        'courses.type_har_workshop' => str_contains(
            (string) DB::verdi("SELECT column_type FROM information_schema.columns
                                 WHERE table_schema = DATABASE() AND table_name = 'courses'
                                   AND column_name = 'type'"),
            'workshop'
        ),
    ];

    if (in_array('notifications', $navn, true)) {
        // Varsler som blir liggende er den vanligste stille feilen: ingen
        // merker at e-posten ikke gaar ut for noen sporr hvorfor de ikke fikk
        // kvittering.
        $svar['varsler'] = [
            'venter'   => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'ko'"),
            'sendt'    => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'sendt'"),
            'feilet'   => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'feilet'"),
            'gitt_opp' => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'ko' AND forsok >= 5"),
            'maate'    => trim((string) Config::hent('smtp_vert', '')) !== '' ? 'SMTP' : 'serverens mail()',
        ];
    }

    if (in_array('notification_templates', $navn, true)) {
        $svar['database']['varselmaler'] =
            (int) DB::verdi('SELECT COUNT(*) FROM notification_templates');
    }
} catch (Throwable $e) {
    $svar['database'] = [
        'kontakt' => false,
        'feil'    => $e->getMessage(),
    ];
}

// --- Hva som gjenstår å fylle ut ------------------------------------------
// --- Vipps -----------------------------------------------------------------
//
// Returadressen maa staa ORDFOR ORD i Vipps-portalen, paa den salgsenheten
// innloggingen bruker. Staar den ikke der, avviser Vipps med
// «invalid_request ... redirect_uri». Den staar her for aa kunne kopieres
// rett inn — aa skrive den av for haand er den vanligste feilkilden.
$svar['vipps'] = [
    'miljo'        => Config::miljo(),
    'api'          => Config::vippsBase(),
    // To salgsenheter kan vaere i bruk: innlogging og betaling godkjennes
    // hver for seg hos Vipps, og kommer da med hvert sitt sett noekler.
    'salgsenhet'          => (string) Config::hent('vipps_msn', ''),
    'salgsenhet_betaling' => Vipps::betalingNokler()['msn'],
    'egne_betalingsnokler' => Vipps::egneBetalingsnokler(),
    'returadresse' => Vipps::returAdresse(),
    'merk'         => 'Returadressen må være registrert i Vipps-portalen på salgsenheten for '
                    . 'innlogging — nøyaktig slik den står her, uten skråstrek på slutten.',
];

$svar['nokler'] = [
    'vipps_msn'           => $fylt('vipps_msn'),
    'vipps_client_id'     => $fylt('vipps_client_id'),
    'vipps_client_secret' => $fylt('vipps_client_secret'),
    'vipps_sub_key'       => $fylt('vipps_sub_key'),
    // Tomme betyr «bruk de fire over», ikke «mangler».
    'vipps_betaling_msn'           => $fylt('vipps_betaling_msn'),
    'vipps_betaling_client_id'     => $fylt('vipps_betaling_client_id'),
    'vipps_betaling_client_secret' => $fylt('vipps_betaling_client_secret'),
    'vipps_betaling_sub_key'       => $fylt('vipps_betaling_sub_key'),
    'admin_telefon'       => Config::adminNumre() !== [],
    'sveve_sms'           => $fylt('sveve_bruker'),
    // AI-en i markedsforingen. Noekkelen kan ligge i to filer, og bare den
    // ene leses — da er «er den satt» og «hvilken fil» det samme sporsmaalet.
    'claude_api_key'      => $fylt('claude_api_key'),
];

// Hvilke secrets-filer som finnes. Den overste av dem er den som leses;
// ligger noekkelen i den andre, skjer det ingenting, og det er umulig aa se
// uten aa faa det sagt.
//
// Staar bare her, ikke i bootstrap.php: den kjorer ved hver eneste
// foresporsel, og en helsesjekk er ikke verdt aa roere den for.
$svar['secrets_filer'] = array_values(array_filter([
    dirname(APP_DIR) . '/../lissom-secrets/secrets.php',
    APP_DIR . '/secrets.php',
], 'is_file'));

// --- Filer som ma ligge paa plass -----------------------------------------
// Deployen har lagt dem ut, men det er verdt aa kunne se det uten aa gjette.
$rot = dirname(__DIR__);
$svar['filer'] = [];
foreach ([
    'assets_kursbevis-bunn.jpg', 'signatur-monica.png',
    'favicon.ico', 'favicon.svg', 'ds-fonts.css',
    'vendor/react-18.3.1.min.js', 'icons/shopping-cart.svg',
    'fonts/bitter-latin-700-normal.woff2',
] as $f) {
    $svar['filer'][$f] = is_file($rot . '/' . $f);
}

$svar['klar_for'] = [
    'innlogging' => ($svar['database']['kontakt'] ?? false)
        && $svar['nokler']['vipps_client_id']
        && $svar['nokler']['vipps_client_secret']
        && $svar['nokler']['vipps_sub_key'],
    'admin' => $svar['nokler']['admin_telefon'],
    'medlemssoknad' => ($svar['database']['mangler'] ?? ['x']) === []
        || !in_array('membership_applications', $svar['database']['mangler'] ?? [], true),
    'kursbevis' => ($svar['database']['kolonner']['courses.instruktor'] ?? false)
        && ($svar['filer']['assets_kursbevis-bunn.jpg'] ?? false)
        && ($svar['filer']['signatur-monica.png'] ?? false),
];

Svar::json($svar);
