<?php
/**
 * Syntakssjekk av secrets.php.
 *
 * Er det en skrivefeil i nokkelfila, stopper PHP for den rekker aa si hva som
 * er galt — resultatet er en tom 500-side uten forklaring, og eneste spor er
 * feilloggen. Denne sida leser fila som tekst og peker paa linja.
 *
 * Den laster med vilje IKKE bootstrap.php, for den ville stoppet paa samme
 * feil. Og den skriver aldri ut innholdet i fila — kun linjenummer og hva
 * slags feil det er.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$sokt = [];
$fil = null;
$mappe = __DIR__;

for ($n = 0; $n < 8 && $fil === null; $n++) {
    foreach ([
        $mappe . '/lissom-secrets/secrets.php',
        $mappe . '/lissom-app/app/secrets.php',
        $mappe . '/app/secrets.php',
    ] as $sti) {
        $sokt[] = $sti;
        if (is_file($sti)) { $fil = $sti; break; }
    }
    $opp = dirname($mappe);
    if ($opp === $mappe) break;
    $mappe = $opp;
}

$ut = static function (array $d): never {
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
};

if ($fil === null) {
    http_response_code(500);
    $ut(['ok' => false, 'problem' => 'Fant ikke secrets.php paa serveren.']);
}

$kilde = file_get_contents($fil);
if ($kilde === false) {
    http_response_code(500);
    $ut(['ok' => false, 'problem' => 'Kunne ikke lese secrets.php. Sjekk rettighetene.']);
}

try {
    // Kaster ParseError med linjenummer hvis fila ikke er gyldig PHP.
    token_get_all($kilde, TOKEN_PARSE);
} catch (ParseError $e) {
    http_response_code(500);
    $ut([
        'ok'      => false,
        'problem' => 'Skrivefeil i secrets.php',
        'linje'   => $e->getLine(),
        'hva'     => $e->getMessage(),
        'sjekk'   => [
            'Mangler det en fnutt rundt verdien?',
            'Mangler det komma paa slutten av linja?',
            'Er det to komma etter hverandre?',
            'Er det en fnutt inni selve verdien?',
        ],
    ]);
}

// Fila er gyldig PHP. Da sier vi hvilke nokler som er fylt ut — aldri hva de er.
$verdier = require $fil;
if (!is_array($verdier)) {
    http_response_code(500);
    $ut(['ok' => false, 'problem' => 'secrets.php returnerer ikke en liste. Mangler «return [» eller «];»?']);
}

$fylt = static fn(string $k): bool => trim((string) ($verdier[$k] ?? '')) !== '';

$ut([
    'ok'         => true,
    'miljo'      => $verdier['miljo'] ?? '(ikke satt)',
    'vipps_base' => $verdier['vipps_base'] ?? '(ikke satt)',
    'utfylt'     => [
        'db_passord'          => $fylt('db_passord'),
        'vipps_msn'           => $fylt('vipps_msn'),
        'vipps_client_id'     => $fylt('vipps_client_id'),
        'vipps_client_secret' => $fylt('vipps_client_secret'),
        'vipps_sub_key'       => $fylt('vipps_sub_key'),
        'cron_nokkel'         => $fylt('cron_nokkel'),
    ],
]);
