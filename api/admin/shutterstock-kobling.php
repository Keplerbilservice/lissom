<?php
/**
 * Koble Lissom-kontoen hos Shutterstock til nettsiden.
 *
 *   GET  ?start=1                gir adressen du skal sendes til
 *   GET  ?code=…&state=…         Shutterstock sender deg hit tilbake
 *   POST handling=koble_fra      glem tokenet igjen
 *
 * Hvorfor dette trengs i tillegg til nøkkelen som alt ligger i secrets.php:
 *
 * Å søke og å laste ned er to ulike ting hos Shutterstock. Søket spør om
 * biblioteket, og der holder det at appen vår viser hvem den er. Å lisensiere
 * et bilde trekker på et abonnement, og et abonnement tilhører et menneske —
 * ikke en app. Derfor må Lissom selv si ja én gang, i Shutterstock sin egen
 * innlogging, og det er det som skjer her.
 *
 * Det er denne flyten API-utforskeren deres kaller «customer_accessCode
 * (OAuth2, authorizationCode)». Forbrukernøkkelen er client_id og
 * forbrukerpassordet er client_secret — samme to verdiene, andre navn.
 *
 * Tokenet som kommer tilbake lagres i basen, ikke i secrets.php: det byttes
 * ut når det går ut, og en fil på tjeneren skal ikke måtte redigeres for det.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

// ── Noen har aapnet adressen selv ──────────────────────────────────────────
//
// Returadressen ser ut som en lenke, og da blir den limt inn i nettleseren
// for aa se om den virker. Foer sto det {"feil":"Mangler koden fra
// Shutterstock."} — teknisk riktig og til ingen nytte.
//
// Dette staar foer innloggingssjekken med vilje: Shutterstock kan selv slaa
// paa adressen naar den registreres, og da skal de faa et svar, ikke en 401.
// Sida viser ingenting som ikke allerede staar i adressefeltet.
if (Foresporsel::metode() === 'GET'
    && Foresporsel::tekst('code') === ''
    && Foresporsel::tekst('error') === ''
    && Foresporsel::tekst('start') !== '1') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="no"><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Returadresse for Shutterstock</title>'
       . '<style>body{font:16px/1.6 system-ui,sans-serif;max-width:34em;'
       . 'margin:12vh auto;padding:0 1.5rem;color:#2b2320}'
       . 'h1{font-size:1.25rem;margin:0 0 1rem}p{margin:0 0 1rem}'
       . 'a{color:#a4552f}</style>'
       . '<h1>Dette er returadressen, ikke en side å besøke</h1>'
       . '<p>Adressen hører til tilkoblingen mellom Lissom og Shutterstock. '
       . 'Den skal stå som <strong>Callback URL</strong> på appen din hos '
       . 'Shutterstock, og det er de som sender deg hit — ikke du selv.</p>'
       . '<p>Skal du koble til, gjør du det inne i admin: åpne et kurs, gå til '
       . 'bildene, og trykk <strong>Koble til Shutterstock</strong> under '
       . 'søkefeltet.</p>'
       . '<p><a href="/admin">Til admin</a></p>';
    exit;
}

krev_admin();

// Naar webhotellet ikke naar ut paa nettet, kaster http_kall(). Uten dette
// blir det til en 500 med filsti og linjenummer i svaret, og den som skal
// rette det sitter igjen med «app/lib/nett.php:96» framfor hva som er galt.
set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof RuntimeException) {
        logg('Shutterstock-tilkobling stoppet', ['feil' => $e->getMessage()]);
        Svar::feil($e->getMessage(), 400);
    }
    logg_feil('Uventet feil i Shutterstock-tilkoblingen', $e);
    Svar::feil('Noe gikk galt. Prøv igjen, eller si fra hvis det gjentar seg.', 500);
});

const SS_AUTORISER = 'https://accounts.shutterstock.com/oauth/authorize';
const SS_TOKEN     = 'https://api.shutterstock.com/v2/oauth/access_token';

/**
 * Rettighetene vi ber om.
 *
 * «licenses.create» er den som gjør nedlasting mulig, og den eneste grunnen
 * til at denne fila finnes. De to andre lar oss vise hva som alt er lisensiert
 * framfor å betale for det samme bildet to ganger.
 */
const SS_RETTIGHETER = 'licenses.create licenses.view purchases.view';

$nokkel  = trim((string) Config::hent('shutterstock_nokkel', ''));
$passord = trim((string) Config::hent('shutterstock_passord', ''));

/** Adressen Shutterstock sender deg tilbake til. Må stå likt hos dem. */
$retur = rtrim((string) Config::hent('nettsted', ''), '/')
       . '/api/admin/shutterstock-kobling.php';

/** Innstillinger leses og skrives direkte her, ikke gjennom Config. */
$lagre = static function (string $n, ?string $v): void {
    if ($v === null) {
        DB::kjor('DELETE FROM innstillinger WHERE nokkel = :n', ['n' => $n]);
        return;
    }
    DB::kjor(
        'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (:n, :v, :a)
         ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
        ['n' => $n, 'v' => $v, 'a' => (Sesjon::medlem()['id'] ?? null)]
    );
};
$les = static fn (string $n): string =>
    (string) (DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => $n]) ?? '');

// ── Koble fra ──────────────────────────────────────────────────────────────
if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();
    if (Foresporsel::tekst('handling') !== 'koble_fra') {
        Svar::feil('Ukjent handling.');
    }
    $lagre('shutterstock_kunde_token', null);
    $lagre('shutterstock_kunde_utloper', null);
    revider('shutterstock_koblet_fra', 'innstilling', null, []);
    Svar::ok(['beskjed' => 'Koblingen til Shutterstock-kontoen er fjernet. Søket virker fortsatt.']);
}

if ($nokkel === '' || $passord === '') {
    Svar::feil(
        'Mangler forbrukernøkkel og forbrukerpassord. De ligger på appen din på '
        . 'developers.shutterstock.com, og heter client_id og client_secret i '
        . 'API-utforskeren. Legg dem inn som shutterstock_nokkel og '
        . 'shutterstock_passord i secrets.php.',
        400
    );
}

// ── Start ──────────────────────────────────────────────────────────────────
//
// «state» er en engangsverdi som følger med ut og skal komme likt tilbake.
// Uten den kunne hvem som helst sendt deg til returadressen vår med sin egen
// kode, og da hadde nettsiden lastet ned bilder på en fremmeds regning.
if (Foresporsel::tekst('start') === '1') {
    $state = bin2hex(random_bytes(16));
    $lagre('shutterstock_oauth_state', $state);
    Svar::ok([
        'url' => SS_AUTORISER . '?' . http_build_query([
            'client_id'     => $nokkel,
            'redirect_uri'  => $retur,
            'response_type' => 'code',
            'scope'         => SS_RETTIGHETER,
            'state'         => $state,
        ]),
        'retur' => $retur,
    ]);
}

// ── Tilbake fra Shutterstock ───────────────────────────────────────────────
$kode = Foresporsel::tekst('code');
if ($kode === '') {
    // Sa de nei, eller gikk noe galt, kommer det en feil framfor en kode.
    $avvist = Foresporsel::tekst('error');
    Svar::feil($avvist !== ''
        ? 'Shutterstock avbrøt tilkoblingen: ' . $avvist
        : 'Mangler koden fra Shutterstock.', 400);
}

$ventet = $les('shutterstock_oauth_state');
if ($ventet === '' || !hash_equals($ventet, Foresporsel::tekst('state'))) {
    logg('Shutterstock kom tilbake med feil state');
    Svar::feil('Tilkoblingen kunne ikke bekreftes. Start på nytt fra billedvelgeren.', 400);
}
$lagre('shutterstock_oauth_state', null);

// Koden byttes i et token. Den er ferskvare og virker én gang.
$svar = http_kall(SS_TOKEN, 'POST', http_build_query([
    'client_id'     => $nokkel,
    'client_secret' => $passord,
    'code'          => $kode,
    'grant_type'    => 'authorization_code',
    'redirect_uri'  => $retur,
]), [
    'Content-Type: application/x-www-form-urlencoded',
    'Accept: application/json',
    'User-Agent: Lissom/1.0 (+https://lissom.no)',
], 30);

$json = json_decode($svar['kropp'], true);
$token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';

if ($svar['status'] !== 200 || $token === '') {
    logg('Fikk ikke token fra Shutterstock', ['status' => $svar['status']]);
    Svar::feil(
        'Shutterstock ga ikke et token tilbake'
        . (is_array($json) && !empty($json['message']) ? ': ' . $json['message'] : '.')
        . ' Sjekk at returadressen ' . $retur . ' står registrert på appen din hos dem.',
        400
    );
}

$lagre('shutterstock_kunde_token', $token);
if (is_array($json) && !empty($json['expires_in'])) {
    $lagre('shutterstock_kunde_utloper',
           gmdate('Y-m-d H:i:s', time() + (int) $json['expires_in']));
}
revider('shutterstock_koblet_til', 'innstilling', null, []);

// Nettleseren står på en API-adresse nå. Den skal tilbake dit arbeidet var.
header('Location: /admin?shutterstock=koblet');
http_response_code(302);
