<?php
/**
 * Bildesøk hos Shutterstock, rett i billedvelgeren.
 *
 *   GET  ?test=1                  virker nøkkelen?
 *   GET  ?sok=keramikk&side=1     søk, med miniatyrer
 *   POST handling=hent { id }     lisensier, last ned, legg i biblioteket
 *
 * Nøkkelen ligger i secrets.php og forlater aldri serveren. Nettleseren
 * snakker med oss, vi snakker med Shutterstock — ellers ville nøkkelen ligget
 * i JavaScript der hvem som helst kunne lese den.
 *
 * To ting koster ulikt hos Shutterstock, og det er verdt å vite forskjellen:
 *
 *   Søk og miniatyrer   gratis, følger med nøkkelen
 *   Lisensiering        krever abonnement med API-tilgang
 *
 * Derfor er de to skilt her. Søket virker med én gang du har en nøkkel; å
 * hente et bilde svarer med hva Shutterstock faktisk sa hvis abonnementet
 * ikke rekker.
 *
 * Miniatyrene er forhåndsvisninger med vannmerke. De lagres aldri og legges
 * aldri ut — bare det lisensierte bildet gjør det. Å bruke en forhåndsvisning
 * på nettsiden ville vært et brudd på lisensen.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

// http_kall() kaster naar tilkoblingen ikke gaar gjennom — og uten dette blir
// «webhotellet naar ikke ut paa nettet» til en 500 med filsti og linjenummer
// i svaret. Eieren sitter da igjen med «app/lib/nett.php:96» framfor hva som
// er galt.
set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof RuntimeException) {
        logg('Shutterstock-kall stoppet', ['feil' => $e->getMessage()]);
        Svar::feil($e->getMessage(), 400);
    }
    logg_feil('Uventet feil i Shutterstock-kall', $e);
    Svar::feil('Noe gikk galt. Prøv igjen, eller si fra hvis det gjentar seg.', 500);
});

/** Endepunktene. Samlet her, så de er ett sted å rette hvis de flyttes. */
const SS_SOK      = 'https://api.shutterstock.com/v2/images/search';
const SS_LISENS   = 'https://api.shutterstock.com/v2/images/licenses';

// To måter å bevise hvem vi er, og begge virker.
//
// Shutterstock gir deg tre verdier på appsiden: en forbrukernøkkel, et
// forbrukerpassord, og et personlig token. Tokenet er det enkleste, men det
// er ikke alltid lett å finne — og da skal ikke nøkkel og passord være
// verdiløse. De to gjør nøyaktig samme nytte, med HTTP Basic.
$token   = trim((string) Config::hent('shutterstock_token', ''));
$nokkel  = trim((string) Config::hent('shutterstock_nokkel', ''));
$passord = trim((string) Config::hent('shutterstock_passord', ''));

if ($token === '' && ($nokkel === '' || $passord === '')) {
    Svar::feil(
        'Shutterstock er ikke koblet til ennå. Legg inn shutterstock_token i '
        . 'secrets.php — eller shutterstock_nokkel og shutterstock_passord, '
        . 'som er forbrukernøkkelen og forbrukerpassordet fra appen din på '
        . 'developers.shutterstock.com.',
        400
    );
}

/**
 * Tokenet fra tilkoblingen — Lissom sin egen konto hos Shutterstock.
 *
 * Det ligger i basen, ikke i secrets.php, fordi det byttes ut naar det gaar
 * ut. Det settes av shutterstock-kobling.php og er tomt til noen har trykket
 * «Koble til Shutterstock».
 *
 * Hvorfor det trengs: aa soeke spoer om biblioteket, og der holder det at
 * appen viser hvem den er. Aa lisensiere trekker paa et abonnement, og et
 * abonnement tilhoerer et menneske — ikke en app. En noekkel fra appsida kan
 * derfor soeke saa mye den vil og likevel bli avvist paa nedlasting.
 */
$kundetoken = '';
try {
    if (DB::harTabell('innstillinger')) {
        $kundetoken = trim((string) (DB::verdi(
            "SELECT verdi FROM innstillinger WHERE nokkel = 'shutterstock_kunde_token'"
        ) ?? ''));
    }
} catch (Throwable $e) {
    $kundetoken = '';
}

/** Hodet som beviser hvem vi er. Kundetokenet foerst, saa appens egen noekkel. */
$auth = $kundetoken !== ''
    ? 'Authorization: Bearer ' . $kundetoken
    : ($token !== ''
        ? 'Authorization: Bearer ' . $token
        : 'Authorization: Basic ' . base64_encode($nokkel . ':' . $passord));

// ── Miniatyrene gaar gjennom oss ───────────────────────────────────────────
//
// Sikkerhetsregelen i .htaccess sier at bilder bare kan komme fra vaar egen
// tjener: «img-src 'self' data: blob:». En adresse hos image.shutterstock.com
// blir dermed stoppet av nettleseren, uten en eneste feilmelding paa sida.
// Det er derfor soeket ga tjuefire ruter og ingen bilder: rutene var der,
// bildene ble blokkert.
//
// To veier ut. Enten aapne regelen for Shutterstock sine tjenere paa hele
// nettstedet, eller hente bildet hit og sende det videre. Vi gjoer det siste:
// regelen for de besoekende staar urort, og det virker uansett hvilken av
// tjenerne deres adressen peker paa.
//
// Adressene signeres foer de forlater oss, og signaturen sjekkes naar de kommer
// tilbake. Uten det ville dette vaert et hull der hvem som helst med
// admintilgang kunne faatt tjeneren til aa hente hva som helst fra nettet.
$signeringsnokkel = hash('sha256', 'miniatyr|' . $token . '|' . $nokkel . '|' . $passord);

/** Adressen slik nettleseren skal se den: vaar egen, og signert. */
$signer = static function (string $url) use ($signeringsnokkel): string {
    return 'api/admin/shutterstock.php'
         . '?mini=' . rtrim(strtr(base64_encode($url), '+/', '-_'), '=')
         . '&sig=' . hash_hmac('sha256', $url, $signeringsnokkel);
};

if (Foresporsel::metode() === 'GET' && Foresporsel::tekst('mini') !== '') {
    $url = (string) base64_decode(strtr(Foresporsel::tekst('mini'), '-_', '+/'), true);
    if ($url === '' || !hash_equals(
            hash_hmac('sha256', $url, $signeringsnokkel),
            Foresporsel::tekst('sig'))) {
        Svar::feil('Ugyldig bildeadresse.', 403);
    }

    // Egen henting framfor http_kall(): miniatyradressene deres svarer ofte
    // med en omdirigering, og http_kall foelger dem ikke. Her trengs ogsaa
    // innholdstypen, som http_kall ikke gir tilbake.
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['User-Agent: Lissom/1.0 (+https://lissom.no)'],
    ]);
    $data = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($status !== 200 || !is_string($data) || $data === '') {
        logg('Fikk ikke miniatyren fra Shutterstock', ['status' => $status]);
        http_response_code(502);
        exit;
    }
    if (!preg_match('~^image/(jpeg|png|webp|gif)~', $type)) {
        $type = 'image/jpeg';
    }

    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($data));
    // Privat: dette er forhaandsvisninger med vannmerke, og de skal ikke
    // ligge i noen mellomlagring utenfor nettleseren til den innloggede.
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $data;
    exit;
}

/**
 * Ett kall mot Shutterstock.
 *
 * Feilene deres kommer som JSON med «message» eller «errors». Uten dette blir
 * en avvist nøkkel til «noe gikk galt», og den som skal rette det vet ikke om
 * problemet er nøkkelen, abonnementet eller nettet.
 *
 * @return array{status:int, json:array}
 */
$kall = static function (string $url, ?array $kropp = null) use ($auth): array {
    $svar = http_kall(
        $url,
        $kropp === null ? 'GET' : 'POST',
        $kropp === null ? null : json_encode($kropp, JSON_UNESCAPED_UNICODE),
        array_filter([
            $auth,
            'Accept: application/json',
            // Shutterstock avviser kall uten dette: «User-Agent header
            // required». De vil vite hvem som ringer.
            'User-Agent: Lissom/1.0 (+https://lissom.no)',
            $kropp === null ? null : 'Content-Type: application/json',
        ]),
        30
    );
    $json = json_decode($svar['kropp'], true);
    return ['status' => $svar['status'], 'json' => is_array($json) ? $json : []];
};

/** Feilteksten Shutterstock ga, oversatt til noe eieren kan gjøre noe med. */
$feiltekst = static function (int $status, array $json): string {
    $detalj = (string) ($json['message'] ?? '');
    if ($detalj === '' && isset($json['errors'][0]['message'])) {
        $detalj = (string) $json['errors'][0]['message'];
    }
    return match (true) {
        $status === 401 || $status === 403
            => 'Shutterstock godtok ikke nøkkelen. Sjekk shutterstock_token — eller '
               . 'shutterstock_nokkel og shutterstock_passord — i secrets.php.'
               . ($detalj !== '' ? ' (' . $detalj . ')' : ''),
        $status === 429
            => 'For mange søk på kort tid. Vent litt og prøv igjen.',
        $status >= 500
            => 'Shutterstock svarer ikke akkurat nå. Prøv igjen om litt.',
        default
            => 'Shutterstock svarte ikke som ventet'
               . ($detalj !== '' ? ': ' . $detalj : ' (kode ' . $status . ').'),
    };
};

// ── Virker nøkkelen? ───────────────────────────────────────────────────────
//
// Et søk etter ett bilde er det billigste spørsmålet vi kan stille. Svarer
// det, er nøkkelen god.
if (Foresporsel::metode() === 'GET' && Foresporsel::tekst('test') === '1') {
    $r = $kall(SS_SOK . '?per_page=1&query=ceramics&view=full');
    if ($r['status'] !== 200) {
        Svar::feil($feiltekst($r['status'], $r['json']), 400);
    }

    // Søket er én ting, nedlasting en annen — og de svarer ikke på det samme
    // stedet. At søket virker sier ingenting om at et bilde kan hentes.
    //
    // To ting må stemme før nedlasting går: nøkkelen må ha rettigheten
    // «licenses.create», og kontoen må ha et abonnement som dekker det.
    // Mangler én av dem, svarer Shutterstock 403 først når du trykker på et
    // bilde — og feilmeldingen sier ikke hvilken. Derfor spør vi her, mens
    // det ennå går an å rette det.
    $konto = $kall('https://api.shutterstock.com/v2/user/access_token');
    $rettigheter = $konto['status'] === 200 ? (array) ($konto['json']['scopes'] ?? []) : [];
    $kanLisensiere = in_array('licenses.create', $rettigheter, true);

    $abo = $kall('https://api.shutterstock.com/v2/user/subscriptions');
    $abonnementer = $abo['status'] === 200 ? (array) ($abo['json']['data'] ?? []) : [];

    $linjer = ['Søket virker.'];
    if ($kundetoken !== '') {
        $linjer[] = 'Kontoen din er koblet til.';
    } else {
        // Returadressen maa staa registrert paa appen hos Shutterstock foer
        // tilkoblingen gaar gjennom. Den staar her, saa den kan kopieres.
        $linjer[] = 'Kontoen din er ikke koblet til ennå. Før du trykker «Koble til '
                  . 'Shutterstock» må denne adressen stå som returadresse på appen din '
                  . 'hos dem: ' . rtrim((string) Config::hent('nettsted', ''), '/')
                  . '/api/admin/shutterstock-kobling.php';
    }
    if ($konto['status'] !== 200) {
        $linjer[] = 'Fikk ikke lest hva nøkkelen har lov til '
                  . '(' . $konto['status'] . '). Bruker du forbrukernøkkel og '
                  . 'passord framfor et token, er det ventet.';
    } elseif ($kanLisensiere) {
        $linjer[] = 'Nøkkelen har rettigheten «licenses.create» — den kan laste ned.';
    } else {
        // Lista over hva noekkelen faktisk har staar her. Uten den er svaret
        // «noe mangler», og den som skal rette det ser ikke hva som er der.
        $linjer[] = 'Nøkkelen mangler rettigheten «licenses.create», og da blir '
                  . 'nedlasting avvist uansett abonnement. Lag tokenet på nytt på '
                  . 'developers.shutterstock.com og huk av for den rettigheten.';
        $linjer[] = $rettigheter === []
            ? 'Nøkkelen har ingen rettigheter oppført.'
            : 'Den har: ' . implode(', ', array_map('strval', $rettigheter)) . '.';
    }
    if ($abo['status'] !== 200) {
        $linjer[] = 'Fikk ikke lest abonnementet (' . $abo['status'] . ').';
    } elseif ($abonnementer === []) {
        $linjer[] = 'Kontoen har ingen abonnement API-et kan se. Da går søk, '
                  . 'men ikke nedlasting.';
    } else {
        $navn = [];
        foreach ($abonnementer as $a) {
            $navn[] = (string) ($a['license'] ?? ($a['id'] ?? '?'));
        }
        $linjer[] = count($abonnementer) . ' abonnement: ' . implode(', ', $navn) . '.';
    }

    Svar::ok([
        'beskjed'        => implode(' ', $linjer),
        'kanLisensiere'  => $kanLisensiere,
        'rettigheter'    => $rettigheter,
        'abonnementer'   => count($abonnementer),
    ]);
}

// ── Søk ────────────────────────────────────────────────────────────────────
if (Foresporsel::metode() === 'GET') {
    $sok = trim(Foresporsel::tekst('sok'));
    if ($sok === '') {
        Svar::feil('Skriv hva du leter etter.');
    }
    $side = max(1, min(20, Foresporsel::heltall('side', 1)));

    // Norsk soekespraak, med en vei tilbake.
    //
    // Biblioteket er merket paa engelsk, og «keramikk» gir null treff uten at
    // vi sier hvilket spraak ordet er paa. Shutterstock oversetter selv naar
    // «language» er satt — det er det nettsida deres gjor.
    //
    // Koden for norsk bokmaal er «nb». Jeg gjettet paa «no» foerst, og da
    // svarte de «Validation failed» paa hele kallet: soeket sluttet aa virke
    // fordi jeg proevde aa gjore det bedre. Derfor denne veien tilbake — er
    // koden ikke god, soeker vi uten, framfor aa gi eieren en feilmelding.
    $parametre = [
        'query'      => mb_substr($sok, 0, 120),
        'per_page'   => 24,
        'page'       => $side,
        'image_type' => 'photo',
        'safe'       => 'true',
        'sort'       => 'popular',
        // «minimal» er standarden, og da foelger ikke «assets» med — altsaa
        // ingen miniatyrer aa vise. «full» gir dem.
        'view'       => 'full',
    ];

    $r = $kall(SS_SOK . '?' . http_build_query($parametre + ['language' => 'nb']));

    // 400 fra Shutterstock paa et soek er nesten alltid en parameter de ikke
    // kjenner. Da er det spraakkoden, og da soeker vi uten den.
    if ($r['status'] === 400) {
        logg('Shutterstock avviste spraakkoden, soeker uten', ['sok' => $sok]);
        $r = $kall(SS_SOK . '?' . http_build_query($parametre));
    }

    if ($r['status'] !== 200) {
        Svar::feil($feiltekst($r['status'], $r['json']), 400);
    }

    $raa = $r['json']['data'] ?? [];
    $treff = [];
    foreach ($raa as $bilde) {
        // Forhaandsvisningen. Feltnavnene varierer mellom bildetyper og
        // abonnement, saa vi leter etter den foerste adressen som finnes
        // framfor aa gjette paa navnene. Ei fast liste ville gjort at hvert
        // treff falt ut, og soeket sett tomt ut selv med hundre treff.
        $mini = '';
        foreach (($bilde['assets'] ?? []) as $del) {
            if (is_array($del) && !empty($del['url']) && is_string($del['url'])) {
                $mini = $del['url'];
                break;
            }
        }
        if ($mini === '') {
            continue;
        }
        $treff[] = [
            'id'    => (string) ($bilde['id'] ?? ''),
            'mini'  => $signer($mini),
            'tekst' => mb_substr((string) ($bilde['description'] ?? ''), 0, 120),
        ];
    }

    // Kom det treff uten at vi fant en eneste forhaandsvisning, er det noe
    // annet enn «ingen treff» — og da skal det staa noe annet.
    if ($raa !== [] && $treff === []) {
        logg('Shutterstock ga treff uten forhaandsvisninger', [
            'antall' => count($raa),
            'felter' => array_keys((array) ($raa[0]['assets'] ?? [])),
        ]);
        Svar::feil(
            'Shutterstock ga ' . count($raa) . ' treff, men ingen bilder å vise. '
            . 'Abonnementet gir kanskje ikke forhåndsvisninger gjennom API-et.',
            400
        );
    }

    Svar::json([
        'ok'     => true,
        'treff'  => $treff,
        'side'   => $side,
        'flere'  => count($treff) === 24,
        'totalt' => (int) ($r['json']['total_count'] ?? 0),
    ]);
}

// ── Hent bildet ────────────────────────────────────────────────────────────
Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (Foresporsel::tekst('handling') !== 'hent') {
    Svar::feil('Ukjent handling.');
}

$id = preg_replace('/\D+/', '', (string) (Foresporsel::kropp()['id'] ?? ''));
if ($id === '') {
    Svar::feil('Mangler bildet.');
}

// 1) Lisensier. Det er dette som koster, og det er dette abonnementet må
//    dekke. Uten API-tilgang svarer Shutterstock her, ikke på søket.
$lisens = $kall(SS_LISENS, ['images' => [['image_id' => $id]]]);
if ($lisens['status'] !== 200 && $lisens['status'] !== 201) {
    logg('Shutterstock avviste lisensieringen', ['status' => $lisens['status'], 'id' => $id]);

    if ($lisens['status'] !== 403 && $lisens['status'] !== 402) {
        Svar::feil($feiltekst($lisens['status'], $lisens['json']), 400);
    }

    // Et avslag her betyr én av to ting, og de har hver sin løsning. Framfor
    // å be eieren trykke på en knapp til for å finne ut hvilken, spør vi med
    // det samme. Svaret koster ett kall og sparer en runde fram og tilbake.
    $rettigheter = [];
    $konto = $kall('https://api.shutterstock.com/v2/user/access_token');
    if ($konto['status'] === 200) {
        $rettigheter = (array) ($konto['json']['scopes'] ?? []);
    }
    $abo = $kall('https://api.shutterstock.com/v2/user/subscriptions');
    $abonnementer = $abo['status'] === 200 ? (array) ($abo['json']['data'] ?? []) : [];

    $manglerRettighet = $rettigheter !== [] && !in_array('licenses.create', $rettigheter, true);
    $manglerAbonnement = $abo['status'] === 200 && $abonnementer === [];

    if ($manglerRettighet) {
        $beskjed = 'Nøkkelen mangler rettigheten «licenses.create», og da avvises '
                 . 'nedlasting uansett abonnement. Det er ikke noe galt med kontoen '
                 . 'din — en nøkkel fra appsida får ikke lov til å bruke abonnementet '
                 . 'ditt før du selv har sagt ja én gang. Trykk «Koble til '
                 . 'Shutterstock» under søkefeltet.';
    } elseif ($manglerAbonnement) {
        $beskjed = 'Kontoen har ingen abonnement API-et kan bruke. Da går søk, men '
                 . 'ikke nedlasting. Last ned bildet på shutterstock.com og legg det '
                 . 'inn med «Last opp eget bilde» — eller si fra, så legger jeg inn '
                 . 'en gratis bildekilde ved siden av.';
    } else {
        $beskjed = 'Shutterstock avviste nedlastingen'
                 . (!empty($lisens['json']['message']) ? ': ' . $lisens['json']['message'] : '.')
                 . ' Har du ikke koblet til kontoen din ennå, gjør det med «Koble til '
                 . 'Shutterstock» under søkefeltet. Inntil videre kan bildet lastes '
                 . 'ned på shutterstock.com og legges inn med «Last opp eget bilde».';
    }

    Svar::feil($beskjed, 400);
}

$last = $lisens['json']['data'][0]['download']['url'] ?? '';
if (!is_string($last) || $last === '') {
    // Lisensen kan vaere gitt uten at nedlastingsadressen foelger med — da er
    // bildet betalt for, og det skal staa i svaret at det er skjedd.
    logg('Shutterstock ga lisens uten nedlastingsadresse', ['id' => $id]);
    Svar::feil('Shutterstock ga lisens, men ingen nedlastingsadresse. Bildet er lisensiert — hent det på shutterstock.com.', 400);
}

// 2) Hent selve bildet.
$fil = http_kall($last, 'GET', null, [], 60);
if ($fil['status'] !== 200 || $fil['kropp'] === '') {
    Svar::feil('Bildet ble lisensiert, men nedlastingen feilet. Prøv igjen — du blir ikke belastet på nytt.', 400);
}

// 3) Legg det i biblioteket, gjennom samme kontroll som en opplasting.
try {
    $navn = Bilder::taImotData($fil['kropp'], 'artikler');
} catch (RuntimeException $e) {
    Svar::feil($e->getMessage(), 400);
}

revider('shutterstock_hentet', 'bilde', null, ['bilde_id' => $id, 'fil' => $navn]);

Svar::ok([
    'url'     => 'api/bilde.php?artikkel=' . $navn,
    'navn'    => $navn,
    'beskjed' => 'Bildet er lisensiert og lagt i biblioteket.',
]);
