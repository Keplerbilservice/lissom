<?php
/**
 * Syntakssjekk av secrets.php.
 *
 * Er det en skrivefeil i nokkelfila, stopper PHP for den rekker aa si hva som
 * er galt — resultatet er en tom 500-side uten forklaring, og eneste spor er
 * feilloggen. Denne sida leser fila som tekst og peker paa linja.
 *
 * Den laster med vilje IKKE bootstrap.php, for den ville stoppet paa samme
 * feil. Derfor kan den heller ikke sporre databasen om hvem som spor.
 *
 * ── Hvem som slipper til ──────────────────────────────────────────────
 *
 * To niveauer, og skillet gaar ved hva svaret roper ut:
 *
 *   Er fila i stykker  → aapent. Da staar nettstedet, og eieren maa kunne
 *                        se hvilken linje det gjelder uten aa logge inn
 *                        noe sted. Svaret er bare et linjenummer.
 *   Er fila i orden    → krever cron_nokkel i adressen. Da virker
 *                        nettstedet, og oversikten over hva som er satt
 *                        opp er ikke noe en fremmed skal ha: den sier
 *                        hvilke tjenester vi bruker, om vi staar i test
 *                        eller produksjon, og hva som ikke er paa plass.
 *
 * Noekkelen leses rett ut av fila vi nettopp har lest, saa dette virker
 * ogsaa naar databasen er nede — det er nettopp da sida trengs.
 *
 * PHP sin egen feilmelding skrives aldri ut. Den siterer symbolet den
 * snublet i, og staar feilen inne i en verdi, er det verdien den siterer:
 * «unexpected identifier "fnutt"» der passordet var «passord med ' fnutt
 * inni». Linjenummeret sier alt eieren trenger, og roper ingenting.
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
    $ut(['ok' => false, 'problem' => 'Fant ikke secrets.php på serveren.']);
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
    // $e->getMessage() staar med vilje ikke her. Se toppen av fila.
    $ut([
        'ok'      => false,
        'problem' => 'Skrivefeil i secrets.php',
        'linje'   => $e->getLine(),
        'sjekk'   => [
            'Mangler det en fnutt rundt verdien?',
            'Mangler det komma på slutten av linja?',
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

// ── Herfra og ned krever noekkel ───────────────────────────────────────
//
// Fila er i orden, saa nettstedet virker og eieren kommer til admin. Da er
// det ingen grunn til at en fremmed skal faa vite hva som er satt opp.
$nokkel  = trim((string) ($verdier['cron_nokkel'] ?? ''));
$oppgitt = (string) ($_GET['nokkel'] ?? '');
if ($nokkel === '' || $oppgitt === '' || !hash_equals($nokkel, $oppgitt)) {
    http_response_code(404);
    $ut(['ok' => false, 'problem' => 'Fant ikke siden.']);
}

// Gruppert etter hva det faktisk slaar ut paa. En flat liste over noekler
// sier ikke hva som slutter aa virke naar en av dem mangler.
$grupper = [
    'database' => ['db_passord'],
    'vipps'    => ['vipps_msn', 'vipps_client_id', 'vipps_client_secret', 'vipps_sub_key'],
    'epost'    => ['smtp_vert', 'smtp_bruker', 'smtp_passord'],
    'sms'      => ['sveve_bruker', 'sveve_passord', 'gatewayapi_token'],
    'ai'       => ['claude_api_key'],
    'drift'    => ['cron_nokkel'],
];

$utfylt = [];
foreach ($grupper as $navn => $noekler) {
    foreach ($noekler as $k) {
        $utfylt[$navn][$k] = $fylt($k);
    }
}

// Hva som ikke virker akkurat naa, skrevet slik at det gir mening uten aa
// kjenne koden. SMS teller som satt opp naar én av leverandorene er fylt ut.
$mangler = [];
if (!$fylt('db_passord')) {
    $mangler[] = 'Uten db_passord kommer ikke nettstedet til databasen i det hele tatt.';
}
if (!$fylt('smtp_vert')) {
    $mangler[] = 'E-post går gjennom serverens egen mail(). Det virker ofte, men havner lett i søppelposten. '
        . 'Legg inn smtp_vert, smtp_bruker og smtp_passord.';
}
if (!$fylt('sveve_bruker') && !$fylt('gatewayapi_token')) {
    $mangler[] = 'SMS er ikke satt opp. Varsler som ellers gikk på SMS sendes nå som e-post i stedet, '
        . 'og har mottakeren ingen e-postadresse, får verkstedet beskjed med navn og nummer. '
        . 'Ingenting går tapt — men de kommer fram tregere enn en SMS ville gjort.';
}
if (!$fylt('claude_api_key')) {
    $mangler[] = 'AI-knappene under Markedsføring svarer ikke før claude_api_key er lagt inn.';
} elseif (!str_starts_with(trim((string) $verdier['claude_api_key']), 'sk-ant-')) {
    // Formen, ikke innholdet. En nokkel som er limt inn halvveis, eller tatt
    // fra feil tjeneste, ser ellers riktig ut helt til forste kall feiler.
    $mangler[] = 'claude_api_key ser ikke ut som en nøkkel fra console.anthropic.com — de begynner med «sk-ant-». '
        . 'Sjekk at hele nøkkelen kom med.';
}
if (!$fylt('cron_nokkel')) {
    $mangler[] = 'Uten cron_nokkel kan ikke de automatiske jobbene kjøre (påminnelser, kvitteringer, opprydding).';
}

$ut([
    'ok'         => true,
    'miljo'      => $verdier['miljo'] ?? '(ikke satt)',
    'vipps_base' => $verdier['vipps_base'] ?? '(ikke satt)',
    'utfylt'     => $utfylt,
    'mangler'    => $mangler,
]);
