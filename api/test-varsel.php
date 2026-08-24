<?php
/**
 * Prov utsending, og se hva som faktisk skjer.
 *
 *   /api/test-varsel.php?epost=din@adresse.no
 *   /api/test-varsel.php?sms=+4790000000
 *
 * «E-posten kom ikke fram» er et daarlig utgangspunkt for feilsoking. Denne
 * sender én melding med det oppsettet som gjelder, uten aa gaa veien om koen,
 * og forteller om den ble levert, hvilken vei den gikk, og hva som eventuelt
 * gikk galt.
 *
 * Krever noekkelen eller en innlogget admin.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$nokkel = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');
$medNokkel = $nokkel !== '' && $oppgitt !== '' && hash_equals($nokkel, $oppgitt);
$fraEgenHand = fra_egen_side();

if (!$medNokkel && !(Sesjon::erAdmin() && $fraEgenHand)) {
    Svar::feil('Fant ikke siden.', 404);
}

$fylt = static fn(string $n): bool => trim((string) Config::hent($n, '')) !== '';

/**
 * Fortell om formen paa en hemmelighet, aldri hva den er.
 *
 * «Passordet er riktig» og «passordet i fila er riktig» er to forskjellige
 * ting. Lengden avslorer en avkuttet innliming, mellomrom i kanten avslorer
 * en kopiering som tok med for mye, og apostrof eller bakoverstrek avslorer
 * et passord som maa skrives annerledes i PHP.
 */
$form = static function (string $nokkel): array {
    $v = (string) Config::hent($nokkel, '');
    return [
        'lengde'            => mb_strlen($v),
        'mellomrom_i_kant'  => $v !== trim($v),
        'trenger_escaping'  => preg_match('/[\\\\\']/', $v) === 1,
        'kontrolltegn'      => preg_match('/[\x00-\x1F]/', $v) === 1,
    ];
};

$svar = [
    'epost_oppsett' => [
        'maate'          => $fylt('smtp_vert') ? 'SMTP' : 'serverens mail()',
        'smtp_vert'      => (string) Config::hent('smtp_vert', ''),
        'smtp_port'      => (int) Config::hent('smtp_port', 587),
        'smtp_bruker'    => $fylt('smtp_bruker'),
        'smtp_passord'   => $fylt('smtp_passord'),
        'smtp_sikkerhet' => (string) Config::hent('smtp_sikkerhet', 'starttls'),
        'avsender'       => (string) Config::hent('epost_fra', 'post@lissom.no'),
        'svar_til'       => (string) Config::hent('epost_svar_til', (string) Config::hent('epost_fra', 'post@lissom.no')),
        // Form, ikke innhold. Stemmer ikke lengden med det du tastet, er det
        // fila som er feil — ikke passordet ditt.
        'brukernavn_form' => $form('smtp_bruker'),
        'passord_form'    => $form('smtp_passord'),
    ],
    'sms_oppsett' => [
        'leverandor'        => mb_strtolower((string) Config::hent('sms_leverandor', 'sveve')),
        'sveve_bruker'      => $fylt('sveve_bruker'),
        'sveve_passord'     => $fylt('sveve_passord'),
        'gatewayapi_token'  => $fylt('gatewayapi_token'),
        'avsender'          => (string) Config::hent('sms_avsender', 'Lissom'),
    ],
];

// Feilmeldinga blir liggende igjen paa rada selv om neste forsok gikk bra.
// Uten status ser gamle, loste feil ut som ferske problemer — og da leter man
// etter noe som allerede er fikset.
$feilListe = static fn (): array => array_map(static fn (array $r): array => [
    'kanal'    => $r['kanal'],
    'mottaker' => $r['mottaker'],
    'feil'     => $r['feilmelding'],
    'forsok'   => (int) $r['forsok'],
    'status'   => (string) $r['status'],
    'nar'      => (string) $r['created_at'],
    'lost'     => $r['status'] === 'sendt',
], DB::alle(
    "SELECT kanal, mottaker, feilmelding, forsok, status, created_at
       FROM notifications
      WHERE feilmelding IS NOT NULL
   ORDER BY id DESC LIMIT 5"
));

$svar['siste_feil'] = $feilListe();

$tilEpost = Foresporsel::tekst('epost');

// «meg» sender til den innloggede sin egen adresse. Det er den vanligste
// hensikten, og da slipper man aa skrive adressen inn i en URL.
if ($tilEpost === 'meg') {
    $jeg = Sesjon::medlem();
    $tilEpost = (string) ($jeg['epost'] ?? '');
    if ($tilEpost === '') {
        Svar::feil('Du har ingen e-postadresse registrert. Skriv den inn i adressen i stedet: ?epost=din@adresse.no');
    }
}

if ($tilEpost !== '') {
    // Plassholderen fra dokumentasjonen. Den er blitt sendt inn to ganger,
    // og feilen ser ut som et oppsettsproblem naar den ikke er det.
    if (str_ends_with(mb_strtolower($tilEpost), '@adresse.no')) {
        Svar::feil('«din@adresse.no» er bare et eksempel. Skriv inn din egen adresse, eller bruk ?epost=meg.');
    }
    if (!filter_var($tilEpost, FILTER_VALIDATE_EMAIL)) {
        Svar::feil('Adressen ser ikke riktig ut.');
    }
    $id = Varsel::epost(
        $tilEpost,
        'Testmelding fra lissom.no',
        "Dette er en testmelding sendt fra oppsettet på " . Config::nettsted() . ".\n\n"
        . "Kom den fram, virker e-postutsendingen.\n\n"
        . "Sendt " . gmdate('c') . " UTC."
    );
    // Send den med en gang framfor aa vente paa koen.
    $resultat = Utsending::tomKo(5);
    $rad = DB::en('SELECT status, forsok, feilmelding FROM notifications WHERE id = :id', ['id' => $id]);
    $svar['epost_test'] = [
        'til'    => $tilEpost,
        'status' => $rad['status'] ?? 'ukjent',
        'feil'   => $rad['feilmelding'] ?? null,
        'koen'   => $resultat,
    ];

    // «Leverandoren svarte med feil» sier ingenting til den som skal fikse
    // det. Naar utsendingen gaar gjennom serverens egen mail() — altsaa uten
    // SMTP satt opp — er det nesten alltid det som er aarsaken, og da skal
    // svaret si hva som mangler i stedet for hva som gikk galt.
    if (($rad['status'] ?? '') !== 'sendt') {
        $svar['epost_test']['hva_na'] = trim((string) Config::hent('smtp_vert', '')) === ''
            ? 'Utsendingen går gjennom serverens egen mail(), og den kom ikke fram. '
              . 'Fyll inn e-postkontoen under Markedsføring → E-post og SMS i admin — '
              . 'verdiene står under kontodetaljer for e-postkontoen hos webhotellet.'
            : 'SMTP er satt opp mot ' . Config::hent('smtp_vert', '') . '. Sjekk at brukernavnet er '
              . 'hele e-postadressen, at passordet stemmer, og at porten passer med sikkerheten '
              . '(587 med starttls, 465 med ssl).';
    } elseif (trim((string) Config::hent('smtp_vert', '')) === '') {
        // Den kom fram, men gjennom mail(). Det virker ofte — helt til noen
        // sjekker SPF, og da havner alt i soppelposten.
        $svar['epost_test']['merk'] = 'Meldingen gikk gjennom serverens egen mail(), ikke SMTP. '
            . 'Det virker, men mange mottakere legger slik post i søppelposten. '
            . 'Settes smtp_vert opp mot e-postkontoen deres, kommer den fram som den skal.';
    }
}

$tilSms = Foresporsel::tekst('sms');
if ($tilSms !== '') {
    $nr = normaliser_telefon($tilSms);
    if ($nr === '') {
        Svar::feil('Nummeret ser ikke riktig ut.');
    }
    $id = Varsel::sms($nr, 'Testmelding fra lissom.no. Kom denne fram, virker SMS-utsendingen.');
    if ($id === 0) {
        $svar['sms_test'] = ['til' => $nr, 'status' => 'ikke sendt', 'feil' => 'Sveve-innloggingen mangler. Fyll den inn under Markedsføring → E-post og SMS i admin.'];
    } else {
        $resultat = Utsending::tomKo(5);
        $rad = DB::en('SELECT status, forsok, feilmelding FROM notifications WHERE id = :id', ['id' => $id]);
        $svar['sms_test'] = [
            'til'    => $nr,
            'status' => $rad['status'] ?? 'ukjent',
            'feil'   => $rad['feilmelding'] ?? null,
            'koen'   => $resultat,
        ];
    }
}

// Koetallene hentes til slutt, slik at de viser tilstanden etter testen og
// ikke for. Sto de over, sa de alltid «null feil» rett etter en feilet test.
$svar['ko'] = [
    'venter'   => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'ko'"),
    'sendt'    => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'sendt'"),
    'feilet'   => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'feilet'"),
    'gitt_opp' => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'ko' AND forsok >= 5"),
];
$svar['siste_feil'] = $feilListe();

if ($tilEpost === '' && $tilSms === '') {
    $svar['hvordan'] = 'Legg til &epost=meg for å sende en test til din egen adresse, eller &sms=+4790000000 for SMS.';
}

Svar::json($svar);
