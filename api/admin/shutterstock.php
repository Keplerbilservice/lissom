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

/** Hodet som beviser hvem vi er. Token først; ellers nøkkel og passord. */
$auth = $token !== ''
    ? 'Authorization: Bearer ' . $token
    : 'Authorization: Basic ' . base64_encode($nokkel . ':' . $passord);

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
    Svar::ok(['beskjed' => 'Shutterstock svarer. Søket er klart til bruk.']);
}

// ── Søk ────────────────────────────────────────────────────────────────────
if (Foresporsel::metode() === 'GET') {
    $sok = trim(Foresporsel::tekst('sok'));
    if ($sok === '') {
        Svar::feil('Skriv hva du leter etter.');
    }
    $side = max(1, min(20, Foresporsel::heltall('side', 1)));

    $r = $kall(SS_SOK . '?' . http_build_query([
        'query'      => mb_substr($sok, 0, 120),
        'per_page'   => 24,
        'page'       => $side,
        'image_type' => 'photo',
        'safe'       => 'true',
        'sort'       => 'popular',
        // «minimal» er standarden, og da foelger ikke «assets» med — altsaa
        // ingen miniatyrer aa vise. «full» gir dem.
        'view'       => 'full',
    ]));
    if ($r['status'] !== 200) {
        Svar::feil($feiltekst($r['status'], $r['json']), 400);
    }

    $treff = [];
    foreach (($r['json']['data'] ?? []) as $bilde) {
        // Forhaandsvisningen. Feltnavnene varierer litt mellom bildetypene,
        // saa vi tar den foerste som finnes framfor aa hoppe over treffet.
        $a = $bilde['assets'] ?? [];
        $mini = $a['large_thumb']['url']
            ?? $a['huge_thumb']['url']
            ?? $a['preview']['url']
            ?? $a['small_thumb']['url']
            ?? '';
        if ($mini === '') {
            continue;
        }
        $treff[] = [
            'id'    => (string) ($bilde['id'] ?? ''),
            'mini'  => $mini,
            'tekst' => mb_substr((string) ($bilde['description'] ?? ''), 0, 120),
        ];
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
    Svar::feil(
        $lisens['status'] === 403 || $lisens['status'] === 402
            ? 'Abonnementet hos Shutterstock dekker ikke nedlasting gjennom API-et. '
              . 'Søket virker, men bildet må lastes ned på shutterstock.com og legges inn manuelt.'
            : $feiltekst($lisens['status'], $lisens['json']),
        400
    );
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
