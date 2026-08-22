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

$nokkel = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');

if ($nokkel === '' || $oppgitt === '' || !hash_equals($nokkel, $oppgitt)) {
    Svar::feil('Fant ikke siden.', 404);
}

/** Sier om en hemmelighet er fylt ut — aldri hva den er. */
$fylt = static fn(string $n): bool => trim((string) Config::hent($n, '')) !== '';

$svar = [
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
        'mangler'     => array_values(array_diff([
            'members', 'sessions', 'login_states', 'courses', 'course_sessions',
            'payments', 'vipps_webhook_events', 'bookings', 'waitlist',
            'gift_cards', 'gift_card_uses', 'products', 'orders', 'order_lines',
            'member_sales', 'checkins', 'hour_usage', 'content_blocks',
            'notification_templates', 'notifications', 'audit_log', 'rate_limits',
            'membership_applications',
        ], $navn)),
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
$svar['nokler'] = [
    'vipps_msn'           => $fylt('vipps_msn'),
    'vipps_client_id'     => $fylt('vipps_client_id'),
    'vipps_client_secret' => $fylt('vipps_client_secret'),
    'vipps_sub_key'       => $fylt('vipps_sub_key'),
    'admin_telefon'       => Config::adminNumre() !== [],
    'sveve_sms'           => $fylt('sveve_bruker'),
];

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
