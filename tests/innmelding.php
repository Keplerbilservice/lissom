<?php
/**
 * Innmeldingen, ende til ende mot den falske Vippsen.
 *
 * Eieren meldte seg inn paa Aarsmedlemskap 7. september 2026 og fikk «Proev
 * Lissom, kr 990, 10 timer, 30 dager»:
 *
 *   «jeg kom inn med dette medlemskapet til tross for at jeg kjopte aars»
 *   «jeg fikk ingen avtale aa godkjenne i Vipps»
 *
 * Grunnen sto i skjermkoden:
 *
 *     return p.some(x => x.navn === v) ? v : (p[0] ? p[0].navn : '');
 *
 * Var valget borte, ble det FOERSTE medlemskapet i lista kjopt. Foerste plan
 * er «Proev Lissom», og den har ikke fast trekk — derfor ingen avtale, bare
 * en vanlig betaling. Det samme skjedde med Eirin.
 *
 * Denne testen foelger hele veien: ordren lages, planen leses fra raden, og
 * avtalen som opprettes er den kunden trykket paa.
 *
 * Start foerst:
 *   node tests/falsk-vipps.mjs &
 *   LISSOM_VIPPS_BASE=http://127.0.0.1:8125 php -S 127.0.0.1:8126 ekte-ruter.php &
 *
 * Kjor:  php tests/innmelding.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$BASE = getenv('LISSOM_TEST_BASE') ?: 'http://127.0.0.1:8126';
$ok = 0; $feil = 0;

function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

/** @return array{kode:int,hode:string,kropp:string} */
function hent(string $url, ?array $post = null): array {
    $c = curl_init($url);
    $valg = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 20,
    ];
    if ($post !== null) {
        $valg[CURLOPT_POST] = true;
        $valg[CURLOPT_POSTFIELDS] = json_encode($post, JSON_UNESCAPED_UNICODE);
        // Ingen Origin. Foresporsel::krevSammeOpphav() slipper gjennom det
        // som ikke kommer fra en nettleser, og maaler ellers mot
        // Config::nettsted() — som er en annen adresse enn testtjeneren.
        $valg[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($c, $valg);
    $r = (string) curl_exec($c);
    $kode = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    $len  = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    return ['kode' => $kode, 'hode' => substr($r, 0, $len), 'kropp' => substr($r, $len)];
}

echo "\n── Innmeldingen ─────────────────────────────────────────────\n";

DB::kjor('DELETE FROM rate_limits');

// ── Planen som ikke er den forste i lista ────────────────────────────
//
// Hele feilen var at «den forste» ble brukt naar valget var borte. Testen
// maa derfor bruke en plan som IKKE er den forste, ellers ville den gaatt
// gronn ogsaa med feilen inne.
$planer = Medlemskap::planer();
sjekk('finner minst to planer aa skille mellom', count($planer) >= 2);
$forste = (string) ($planer[0]['navn'] ?? '');
$maal = null;
foreach ($planer as $pl) {
    if (Medlemskap::kreverFastTrekk($pl) && (string) $pl['navn'] !== $forste) {
        $maal = $pl;
        break;
    }
}
sjekk('… og en med fast trekk som ikke staar forst', $maal !== null,
    'forst: ' . $forste);
if ($maal === null) {
    echo "\nKan ikke fortsette uten en slik plan.\n";
    exit(1);
}
$maalNavn = (string) $maal['navn'];

// ── Uten plan skal ingenting kunne kjopes ────────────────────────────
$r = hent($BASE . '/api/medlemsordre.php', [
    'type' => '', 'epost' => 'innmelding@lissom.test', 'vilkaar' => 'ja',
]);
$d = json_decode($r['kropp'], true);
sjekk('en ordre uten plan avvises', $r['kode'] >= 400 && ($d['ok'] ?? true) === false,
    (string) $r['kode']);
sjekk('… og sier hva som mangler',
    str_contains((string) ($d['feil'] ?? ''), 'Velg hvilket medlemskap'));

$foer = (int) DB::verdi('SELECT COUNT(*) FROM medlemsordrer');
$r = hent($BASE . '/api/medlemsordre.php', [
    'type' => 'Finnes Ikke', 'epost' => 'innmelding@lissom.test', 'vilkaar' => 'ja',
]);
sjekk('en ordre paa et ukjent medlemskap avvises', $r['kode'] >= 400);
sjekk('… og ingen rad ble lagd',
    (int) DB::verdi('SELECT COUNT(*) FROM medlemsordrer') === $foer);

// ── Ordren husker planen, prisen og at den krever trekk ──────────────
DB::kjor('DELETE FROM rate_limits');
$epost = 'innmelding-' . bin2hex(random_bytes(4)) . '@lissom.test';
$tlf   = '9' . random_int(1000000, 9999999);
$r = hent($BASE . '/api/medlemsordre.php', [
    'type' => $maalNavn, 'betaling' => 'engang',
    'navn' => 'Innmelding Testperson', 'epost' => $epost, 'telefon' => $tlf,
    'vilkaar' => 'ja',
]);
$d = json_decode($r['kropp'], true);
sjekk('ordren opprettes', ($d['ok'] ?? false) === true, (string) $r['kode']);
$url = (string) ($d['url'] ?? '');
sjekk('… og svarer med /meld-inn/<noekkel>', (bool) preg_match('#^/meld-inn/[a-f0-9]{32}$#', $url), $url);

$rad = DB::en('SELECT * FROM medlemsordrer ORDER BY id DESC LIMIT 1');
sjekk('… og raden staar med planen kunden trykket paa',
    (string) $rad['plan'] === $maalNavn, (string) $rad['plan']);
sjekk('… og med prisen slik den sto da',
    (int) $rad['pris_ore'] === (int) $maal['pris_ore']);
// «betaling: engang» ble sendt inn. Planen krever fast trekk, og da er det
// planen som gjelder — ikke onsket.
sjekk('… og fast trekk vinner over onsket om engangsbetaling',
    (string) $rad['betaling'] === 'trekk', (string) $rad['betaling']);

// ── Lenka lager avtalen paa ORDRENS plan ─────────────────────────────
DB::kjor('DELETE FROM rate_limits');
$r = hent($BASE . $url);
sjekk('lenka sender deg videre', $r['kode'] === 302, (string) $r['kode']);
sjekk('… til Vipps', (bool) preg_match('/^location:\s*\S+/mi', $r['hode']));

$avt = DB::en('SELECT * FROM subscriptions ORDER BY id DESC LIMIT 1');
sjekk('… og avtalen ble opprettet paa den planen, ikke den forste i lista',
    $avt !== null && (string) $avt['plan'] === $maalNavn,
    $avt === null ? 'ingen avtale' : (string) $avt['plan']);
sjekk('… og den staar og venter paa godkjenning',
    $avt !== null && (string) $avt['status'] === 'venter');
sjekk('… og har en avtale hos Vipps, ikke bare en betaling',
    $avt !== null && trim((string) ($avt['vipps_agreement_id'] ?? '')) !== '');

$rad2 = DB::en('SELECT * FROM medlemsordrer WHERE id = :i', ['i' => (int) $rad['id']]);
sjekk('… og ordren er merket som aapnet', (string) $rad2['status'] === 'apnet');
sjekk('… og knyttet til et medlem', (int) $rad2['medlem_id'] > 0);

// ── De som ikke stemmer ──────────────────────────────────────────────
DB::kjor('DELETE FROM rate_limits');
$r = hent($BASE . '/meld-inn/' . str_repeat('0', 32));
sjekk('en ukjent noekkel gir en side, ikke et krasj', $r['kode'] === 200, (string) $r['kode']);
sjekk('… som sier at innmeldingen ikke ble funnet',
    str_contains($r['kropp'], 'Vi fant ikke denne innmeldingen'));
sjekk('… og ikke skal i soket', str_contains(strtolower($r['hode']), 'noindex'));

DB::kjor('UPDATE medlemsordrer SET utloper = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR), status = \'ny\' WHERE id = :i',
    ['i' => (int) $rad['id']]);
DB::kjor('DELETE FROM rate_limits');
$r = hent($BASE . $url);
sjekk('en utloept innmelding sier fra', str_contains($r['kropp'], 'Innmeldingen har gått ut'));
sjekk('… og peker tilbake til medlemskapene', str_contains($r['kropp'], '/medlemskap'));

// ── Skjermen skal ikke gjette heller ─────────────────────────────────
$sida = file_get_contents(dirname(__DIR__) . '/lissom-2108.html');
// Den gamle linja staar igjen i kommentaren over metoden, med vilje — den
// er halve forklaringen. Derfor maales KODElinja, med innrykket sitt.
sjekk('skjermen faller ikke tilbake paa den forste planen',
    !str_contains($sida, "\n    return p.some(x => x.navn === v) ? v : (p[0]")
    && str_contains($sida, "\n    return p.some(x => x.navn === v) ? v : '';"));
sjekk('… og innmeldingen gaar via ordren',
    str_contains($sida, "fetch('/api/medlemsordre.php'"));
sjekk('… og stopper med en beskjed naar ingen plan er valgt',
    str_contains($sida, "bmFeil: 'Velg hvilket medlemskap du vil ha.'"));

echo "\n──────────────────────────────────────────────\n";
echo ($ok + $feil) . " sjekker, $ok gikk gjennom" . ($feil ? ", $feil feilet" : '') . "\n";
exit($feil > 0 ? 1 : 0);
