<?php
/**
 * Ende-til-ende-test av hele pengekjeden.
 *
 * ── Hvorfor denne finnes ────────────────────────────────────────────────
 *
 * tests/backend.php leser KODEN som tekst. Den kan si at riktige ord staar i
 * riktige filer — men den kan ikke se at to skjermer sier hver sin ting om
 * det samme medlemmet. 5. september sto det «BETALT» i medlemslista og
 * «Trekket er bestilt · venter paa Vipps» i Kassa, om den samme raden, den
 * samme dagen. Alle 2062 sjekkene var groenne.
 *
 * Eieren: «Ene stedet staar det betalt, andre staar det ikke betalt».
 *
 * Denne testen leser ikke kode. Den kjoerer ekte kjoep gjennom de ekte
 * endepunktene, mot en falsk Vipps som skriver ned hva vi ba om, og krever at
 * svaret er det samme uansett hvilken skjerm man staar paa.
 *
 * ── Slik kjoeres den ────────────────────────────────────────────────────
 *
 *   1. node tests/falsk-vipps.mjs &
 *   2. LISSOM_VIPPS_BASE=http://127.0.0.1:8125 php -S 127.0.0.1:8124 ekte-ruter.php &
 *   3. php tests/pengekjede.php
 *
 * Uten steg 2 gaar testen mot ekte Vipps. Den nekter aa kjoere da.
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$ADRESSE = getenv('LISSOM_TEST_URL') ?: 'http://127.0.0.1:8124';
$FALSK   = getenv('LISSOM_VIPPS_BASE') ?: 'http://127.0.0.1:8125';
$LOGG    = __DIR__ . '/.falsk-vipps.jsonl';

$ok = 0;
$feil = [];

function sjekk(string $hva, bool $bra, string $detalj = ''): void
{
    global $ok, $feil;
    if ($bra) { $ok++; echo "  ✓ {$hva}\n"; return; }
    $feil[] = $hva . ($detalj !== '' ? ' — ' . $detalj : '');
    echo "  ✗ {$hva}" . ($detalj !== '' ? " — {$detalj}" : '') . "\n";
}

function bolk(string $t): void { echo "\n== {$t} ==\n"; }

/** Ett HTTP-kall mot nettsida. Null kropp = GET. */
function kall(string $sti, ?array $kropp = null, string $kjeks = ''): array
{
    global $ADRESSE;
    $c = curl_init($ADRESSE . $sti);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_PROXY          => '',
        CURLOPT_TIMEOUT        => 30,
    ]);
    $h = ['Accept: application/json'];
    if ($kropp !== null) {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($kropp, JSON_UNESCAPED_UNICODE));
        $h[] = 'Content-Type: application/json';
        $h[] = 'Origin: ' . $ADRESSE;
    }
    if ($kjeks !== '') { $h[] = 'Cookie: ' . $kjeks; }
    curl_setopt($c, CURLOPT_HTTPHEADER, $h);
    $raa = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    $delt = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);

    $hoder = substr($raa, 0, $delt);
    $body  = substr($raa, $delt);
    $nyKjeks = $kjeks;
    if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $hoder, $m)) {
        $nyKjeks = implode('; ', $m[1]);
    }
    return ['http' => $status, 'svar' => json_decode($body, true), 'raa' => $body, 'kjeks' => $nyKjeks];
}

/** Kallene til Vipps siden et gitt punkt. */
function vippsSiden(int $fra): array
{
    global $LOGG;
    if (!is_file($LOGG)) { return []; }
    $l = array_values(array_filter(explode("\n", (string) file_get_contents($LOGG))));
    return array_map(
        static fn(string $r): array => json_decode($r, true) ?: [],
        array_slice($l, $fra)
    );
}
function vippsAntall(): int
{
    global $LOGG;
    if (!is_file($LOGG)) { return 0; }
    return count(array_filter(explode("\n", (string) file_get_contents($LOGG))));
}

/** Sier til den falske Vipps hva den skal svare. */
function vippsSvarer(string $fil, string $verdi): void
{
    $sti = __DIR__ . '/' . $fil;
    if ($verdi === '') { @unlink($sti); return; }
    file_put_contents($sti, $verdi);
}

// ── Vakt: gaar vi mot en ekte Vipps, skal ingenting kjoere ────────────────
echo "Pengekjeden, ende til ende\n";
echo str_repeat('─', 46) . "\n";

$vFra = vippsAntall();
DB::kjor('DELETE FROM rate_limits');
$innlogg = kall('/api/logg-inn.php', ['brukernavn' => 'test', 'passord' => 'Testpassord1!']);
if (($innlogg['svar']['ok'] ?? false) !== true) {
    echo "  ✗ fikk ikke logget inn som «test». Kjorer serveren?\n";
    exit(1);
}
$ADMIN = $innlogg['kjeks'];

// Serveren maa peke paa den falske Vipps. Et kjop mot ekte Vipps fra en test
// er ikke et uhell vi vil ha.
// Et hvilket som helst kall som maa innom Vipps duger. «token» er det
// billigste: ingen penger, ingen rader, bare et sporsmaal om adgang.
try { Vipps::token(); } catch (Throwable $e) { /* svaret betyr ingenting her */ }
if (vippsAntall() === $vFra) {
    // Ingen spor i loggen. Da gikk kallet et annet sted.
    echo "\n  ✗ serveren snakker ikke med den falske Vipps.\n";
    echo "    Start den slik:\n";
    echo "      node tests/falsk-vipps.mjs &\n";
    echo "      LISSOM_VIPPS_BASE={$FALSK} php -S 127.0.0.1:8124 ekte-ruter.php &\n";
    exit(1);
}
echo "  ✓ serveren snakker med den falske Vipps\n";

// ── Rydder bort det forrige kjoringen laget ──────────────────────────────
$EPOSTER = "'kjede.aar@test.local','kjede.selv@test.local','kjede.kurs@test.local'";
DB::kjor("DELETE p FROM payments p JOIN members m ON m.id = p.member_id WHERE m.epost IN ({$EPOSTER})");
DB::kjor("DELETE s FROM subscriptions s JOIN members m ON m.id = s.member_id WHERE m.epost IN ({$EPOSTER})");
DB::kjor("DELETE a FROM membership_applications a JOIN members m ON m.id = a.member_id WHERE m.epost IN ({$EPOSTER})");
DB::kjor("DELETE FROM bookings WHERE gjest_epost IN ({$EPOSTER})");
DB::kjor("DELETE FROM members WHERE epost IN ({$EPOSTER})");
vippsSvarer('.avtale-status', '');
vippsSvarer('.trekk-status', '');
vippsSvarer('.betaling-status', '');

/** Planen som krever fast trekk. */
$planFast = DB::en("SELECT navn, pris_ore, intervall FROM membership_plans WHERE krever_fast_trekk = 1 LIMIT 1");
if ($planFast === null) {
    echo "  ✗ ingen plan med «krever_fast_trekk» i basen — testen kan ikke kjore\n";
    exit(1);
}

/**
 * Lager en fersk bruker og logger den inn.
 *
 * api/bli-medlem.php krever en innlogget bruker — slik gaar det ogsaa i
 * virkeligheten: man lager konto forst, og melder seg inn etterpaa.
 */
function nyBruker(string $navn, string $epost, string $telefon): string
{
    $bruker = 'kjede' . substr(md5($epost), 0, 8);
    DB::kjor('DELETE FROM members WHERE epost = :e OR brukernavn = :b',
             ['e' => $epost, 'b' => $bruker]);
    DB::settInn('members', [
        'navn' => $navn, 'epost' => $epost, 'telefon' => $telefon,
        'brukernavn' => $bruker,
        'passord_hash' => password_hash('Testpassord1!', PASSWORD_DEFAULT),
        'status' => 'ingen',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    DB::kjor('DELETE FROM rate_limits');
    $r = kall('/api/logg-inn.php', ['brukernavn' => $bruker, 'passord' => 'Testpassord1!']);
    if (($r['svar']['ok'] ?? false) !== true) {
        throw new RuntimeException('Fikk ikke logget inn som ' . $bruker);
    }
    return $r['kjeks'];
}

/**
 * Holder trekkrunden unna mens vi maaler.
 *
 * Siden 5. september kjorer trafikken paa sida trekkrunden, hoyst én gang i
 * dognet. Testen sletter «rate_limits» for aa komme forbi innloggingsgrensa —
 * og slo dermed av den sperra ogsaa. Da kjorte runden MELLOM de to skjermene
 * vi sammenligner, og endret raden underveis: Kassa saa den for, lista etter.
 *
 * Det ville sett ut som at skjermene sa hver sin ting. De gjorde ikke det.
 */
function sperrTrekkrunden(): void
{
    DB::kjor('DELETE FROM rate_limits');
    DB::kjor(
        'INSERT INTO rate_limits (nokkel, vindu_start, antall) VALUES (:n, :v, 99)
         ON DUPLICATE KEY UPDATE antall = 99',
        ['n' => 'medlemstrekk:server',
         'v' => gmdate('Y-m-d H:i:s', intdiv(time(), 86400) * 86400)]
    );
}

/** Alt vi vet om ett medlem, som de to skjermene ser det. */
function tilstand(string $epost): array
{
    global $ADMIN;
    $tom = ['finnes' => false, 'medlem' => null, 'avtale' => null,
            'betalinger' => [], 'kassa' => null, 'liste' => null, 'listeTilstand' => null];
    $m = DB::en('SELECT * FROM members WHERE epost = :e', ['e' => $epost]);
    if ($m === null) { return $tom; }
    $a = DB::en('SELECT * FROM subscriptions WHERE member_id = :m ORDER BY id DESC LIMIT 1',
                ['m' => (int) $m['id']]);
    $b = $a === null ? [] : DB::alle(
        'SELECT status, belop_ore, type FROM payments WHERE subscription_id = :s ORDER BY id',
        ['s' => (int) $a['id']]);

    // Slik KASSA ser det (api/admin/oversikt.php → ubetalte)
    sperrTrekkrunden();
    $kassa = null;
    foreach ((kall('/api/admin/oversikt.php', null, $ADMIN)['svar']['ubetalte'] ?? []) as $r) {
        if (($r['navn'] ?? '') === ($m['navn'] ?? '')) { $kassa = $r['naar']; }
    }
    // Slik MEDLEMSLISTA ser det (api/admin/medlemmer.php)
    sperrTrekkrunden();
    $liste = null; $listeTilstand = null;
    foreach ((kall('/api/admin/medlemmer.php', null, $ADMIN)['svar']['medlemmer'] ?? []) as $r) {
        if (($r['epost'] ?? '') === $epost) {
            $liste = $r['betalingTekst'] ?? null;
            $listeTilstand = $r['betaling'] ?? null;
        }
    }
    return [
        'finnes' => true,
        'medlem' => $m,
        'avtale' => $a,
        'betalinger' => $b,
        'kassa' => $kassa,
        'liste' => $liste,
        'listeTilstand' => $listeTilstand,
    ];
}

// ─────────────────────────────────────────────────────────────────────────
bolk('1. Aarsmedlemskap: avtalen opprettes og venter paa kunden');
// ─────────────────────────────────────────────────────────────────────────

$kjeksAar = nyBruker('Kjede Aar', 'kjede.aar@test.local', '4790000101');
$vFra = vippsAntall();
DB::kjor('DELETE FROM rate_limits');
$r = kall('/api/bli-medlem.php', [
    'navn' => 'Kjede Aar', 'epost' => 'kjede.aar@test.local', 'telefon' => '4790000101',
    'type' => (string) $planFast['navn'], 'betaling' => 'trekk', 'vilkaar' => 'ja',
], $kjeksAar);
sjekk('innmeldingen gaar gjennom', ($r['svar']['ok'] ?? false) === true,
      'HTTP ' . $r['http'] . ' ' . mb_substr($r['raa'], 0, 160));

$kall = vippsSiden($vFra);
$avtaleKall = null;
foreach ($kall as $k) {
    if (($k['sti'] ?? '') === '/recurring/v3/agreements' && ($k['metode'] ?? '') === 'POST') {
        $avtaleKall = $k['kropp'] ?? [];
    }
}
sjekk('… og vi ber Vipps om en avtale', $avtaleKall !== null);
sjekk('… paa prisen som staar i basen',
    (int) ($avtaleKall['pricing']['amount'] ?? 0) === (int) $planFast['pris_ore'],
    'ba om ' . ($avtaleKall['pricing']['amount'] ?? '—') . ', planen koster ' . $planFast['pris_ore']);

// Intervallet skal foelge planen. Kolonna «intervall» kan staa paa «aar».
$ventetIntervall = ((string) ($planFast['intervall'] ?? 'maaned')) === 'aar' ? 'YEAR' : 'MONTH';
sjekk('… og med intervallet planen sier',
    strtoupper((string) ($avtaleKall['interval']['unit'] ?? '')) === $ventetIntervall,
    'planen staar paa «' . $planFast['intervall'] . '», Vipps fikk «'
        . ($avtaleKall['interval']['unit'] ?? '—') . '»');

$t = tilstand('kjede.aar@test.local');
sjekk('avtalen staar «venter» til hun har godkjent',
    ($t['avtale']['status'] ?? '') === 'venter', 'status: ' . ($t['avtale']['status'] ?? '—'));
sjekk('… og ingen er trukket enda', $t['betalinger'] === []);
sjekk('… og godkjenningslenka er tatt vare paa',
    trim((string) ($t['avtale']['vipps_url'] ?? '')) !== '',
    'uten den kan ingen purring sende den paa nytt');

// ─────────────────────────────────────────────────────────────────────────
bolk('2. Hun godkjenner i appen — og lukker sida');
// ─────────────────────────────────────────────────────────────────────────

vippsSvarer('.avtale-status', 'ACTIVE');
$t = tilstand('kjede.aar@test.local');
sjekk('ingenting skjer av seg selv',
    ($t['avtale']['status'] ?? '') === 'venter',
    'godkjenningen i appen naar oss bare naar noen sporr Vipps');

$vFra = vippsAntall();
$runde = Medlemskap::kjorTrekkrunde();
$t = tilstand('kjede.aar@test.local');
sjekk('trekkrunden oppdager godkjenningen',
    ($t['avtale']['status'] ?? '') === 'aktiv', 'status: ' . ($t['avtale']['status'] ?? '—'));
sjekk('… og trekker i den samme runden',
    count($t['betalinger']) === 1,
    count($t['betalinger']) . ' betalingsrader — hun skal ikke vente et dogn til');
sjekk('… paa riktig beloep',
    (int) ($t['betalinger'][0]['belop_ore'] ?? 0) === (int) $planFast['pris_ore']);

$belast = null;
foreach (vippsSiden($vFra) as $k) {
    if (str_ends_with((string) ($k['sti'] ?? ''), '/charges') && ($k['metode'] ?? '') === 'POST') {
        $belast = $k['kropp'] ?? [];
    }
}
sjekk('… og Vipps faar et forfall fram i tid',
    $belast !== null && ($belast['due'] ?? '') > gmdate('Y-m-d'),
    'Vipps krever at kunden varsles for trekket');

// ─────────────────────────────────────────────────────────────────────────
bolk('3. De to skjermene sier det samme, i hver eneste tilstand');
// ─────────────────────────────────────────────────────────────────────────
//
// Dette er feilen fra 5. september: medlemslista sa «BETALT» mens Kassa sa
// «venter paa Vipps», om den samme raden. Naa kjores hver tilstand igjennom
// og begge skjermene leses.

$avtaleId = (int) ($t['avtale']['id'] ?? 0);

foreach ([
    ['venter', 'bestilt', true,  'trekket er bestilt, pengene er ikke inne'],
    ['feilet', 'forfalt', true,  'trekket gikk ikke'],
    ['betalt', 'betalt',  false, 'pengene er inne'],
] as [$radStatus, $ventet, $skalStaaIKassa, $hva]) {
    DB::kjor('UPDATE payments SET status = :s WHERE subscription_id = :a',
             ['s' => $radStatus, 'a' => $avtaleId]);
    $t = tilstand('kjede.aar@test.local');

    sjekk("betalingsraden «{$radStatus}» → medlemslista sier «{$ventet}»",
        $t['listeTilstand'] === $ventet,
        'lista sa «' . $t['listeTilstand'] . '»: ' . $t['liste']);

    sjekk("… og Kassa " . ($skalStaaIKassa ? 'har henne i lista' : 'har henne ikke'),
        $skalStaaIKassa ? $t['kassa'] !== null : $t['kassa'] === null,
        $hva);

    if ($skalStaaIKassa) {
        sjekk('… og begge sier ordrett det samme',
            $t['kassa'] === $t['liste'],
            'Kassa: «' . $t['kassa'] . '»   lista: «' . $t['liste'] . '»');
    }
}

// ─────────────────────────────────────────────────────────────────────────
bolk('4. Trekket gjores opp — og kunden faar beskjed');
// ─────────────────────────────────────────────────────────────────────────

DB::kjor("UPDATE payments SET status = 'venter' WHERE subscription_id = :a", ['a' => $avtaleId]);
$nFra = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM notifications');
vippsSvarer('.trekk-status', 'CHARGED');
Medlemskap::kjorTrekkrunde();

$rad = DB::en('SELECT status FROM payments WHERE subscription_id = :a ORDER BY id DESC LIMIT 1',
              ['a' => $avtaleId]);
sjekk('CHARGED gjor raden betalt', ($rad['status'] ?? '') === 'betalt',
      'raden staar «' . ($rad['status'] ?? '—') . '»');
$maler = DB::alle('SELECT mal FROM notifications WHERE id > :n', ['n' => $nFra]);
sjekk('… og kvitteringen gaar ut',
    in_array('medlemskap_fornyet', array_column($maler, 'mal'), true),
    'malene som ble sendt: ' . (implode(', ', array_column($maler, 'mal')) ?: 'ingen'));

DB::kjor("UPDATE payments SET status = 'venter' WHERE subscription_id = :a", ['a' => $avtaleId]);
$nFra = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM notifications');
vippsSvarer('.trekk-status', 'FAILED');
Medlemskap::kjorTrekkrunde();

$rad = DB::en('SELECT status FROM payments WHERE subscription_id = :a ORDER BY id DESC LIMIT 1',
              ['a' => $avtaleId]);
sjekk('FAILED gjor raden feilet', ($rad['status'] ?? '') === 'feilet',
      'raden staar «' . ($rad['status'] ?? '—') . '»');
$maler = DB::alle('SELECT mal FROM notifications WHERE id > :n', ['n' => $nFra]);
sjekk('… og medlemmet faar vite det',
    in_array('betaling_feilet', array_column($maler, 'mal'), true),
    'malene som ble sendt: ' . (implode(', ', array_column($maler, 'mal')) ?: 'ingen'));
vippsSvarer('.trekk-status', '');

// ─────────────────────────────────────────────────────────────────────────
bolk('5. To trekk skal aldri bli tre');
// ─────────────────────────────────────────────────────────────────────────

DB::kjor('DELETE FROM payments WHERE subscription_id = :a', ['a' => $avtaleId]);
DB::kjor('UPDATE subscriptions SET neste_trekk = CURDATE() WHERE id = :a', ['a' => $avtaleId]);
Medlemskap::kjorTrekkrunde();
$etter1 = (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE subscription_id = :a', ['a' => $avtaleId]);
DB::kjor('UPDATE subscriptions SET neste_trekk = CURDATE() WHERE id = :a', ['a' => $avtaleId]);
Medlemskap::kjorTrekkrunde();
$etter2 = (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE subscription_id = :a', ['a' => $avtaleId]);
sjekk('to runder samme maaned gir ett trekk', $etter1 === 1 && $etter2 === 1,
      "forste runde: {$etter1}, andre: {$etter2}");

// ─────────────────────────────────────────────────────────────────────────
bolk('6. «Send Vipps-avtale» lager ikke en avtale nummer to');
// ─────────────────────────────────────────────────────────────────────────

// Hun har IKKE godkjent denne gangen — ellers ville trekkrunden aktivert den
// nye avtalen med det samme, og da maalte vi noe annet enn det vi spor om.
vippsSvarer('.avtale-status', 'PENDING');
DB::kjor("UPDATE subscriptions SET status = 'venter',
            created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY) WHERE id = :a", ['a' => $avtaleId]);
$mid = (int) DB::verdi("SELECT id FROM members WHERE epost = 'kjede.aar@test.local'");
sperrTrekkrunden();
$r = kall('/api/admin/medlemmer.php', ['handling' => 'send-avtale', 'medlemId' => $mid], $ADMIN);
sjekk('knappen svarer greit', ($r['svar']['ok'] ?? false) === true,
      mb_substr((string) ($r['svar']['feil'] ?? $r['raa']), 0, 140));

$rader = DB::alle('SELECT status FROM subscriptions WHERE member_id = :m ORDER BY id', ['m' => $mid]);
$ventende = count(array_filter($rader, static fn($x) => $x['status'] === 'venter'));
sjekk('… og bare én lenke kan godkjennes', $ventende === 1,
      $ventende . ' rader staar «venter» — hver av dem er en lenke som kan gi et trekk');

// ─────────────────────────────────────────────────────────────────────────
bolk('7. Medlemskap som gjores opp selv');
// ─────────────────────────────────────────────────────────────────────────

$planSelv = DB::en("SELECT navn, pris_ore FROM membership_plans
                     WHERE krever_fast_trekk = 0 AND aktiv = 1 ORDER BY sortering LIMIT 1");
if ($planSelv !== null) {
    $kjeksSelv = nyBruker('Kjede Selv', 'kjede.selv@test.local', '4790000102');
    $vFra = vippsAntall();
    DB::kjor('DELETE FROM rate_limits');
    $r = kall('/api/bli-medlem.php', [
        'navn' => 'Kjede Selv', 'epost' => 'kjede.selv@test.local', 'telefon' => '4790000102',
        'type' => (string) $planSelv['navn'], 'betaling' => 'selv', 'vilkaar' => 'ja',
    ], $kjeksSelv);
    sjekk('innmeldingen gaar gjennom', ($r['svar']['ok'] ?? false) === true,
          mb_substr((string) ($r['svar']['feil'] ?? $r['raa']), 0, 140));

    $sett = array_map(static fn($k) => ($k['metode'] ?? '') . ' ' . ($k['sti'] ?? ''), vippsSiden($vFra));
    sjekk('… og den gaar som en vanlig betaling, ikke en avtale',
        in_array('POST /epayment/v1/payments', $sett, true)
        && !in_array('POST /recurring/v3/agreements', $sett, true),
        implode(' · ', $sett) ?: 'ingen kall');

    $t = tilstand('kjede.selv@test.local');
    sjekk('… og raden faar ingen avtale-id',
        ($t['avtale']['vipps_agreement_id'] ?? null) === null,
        'en avtale-id her ville gitt automatiske trekk hun ikke ba om');
}

// ─────────────────────────────────────────────────────────────────────────
bolk('8. Beloep under Vipps sitt minstebeloep');
// ─────────────────────────────────────────────────────────────────────────

try {
    Vipps::opprettBetaling('TEST-NULL', 0, 'Ingenting', 'https://x.test/retur');
    sjekk('null kroner slipper ikke gjennom til Vipps', false, 'kallet gikk gjennom');
} catch (Throwable $e) {
    sjekk('null kroner slipper ikke gjennom til Vipps', true);
}

// ─────────────────────────────────────────────────────────────────────────
bolk('9. Hun kommer tilbake fra Vipps uten aa vaere innlogget');
// ─────────────────────────────────────────────────────────────────────────
//
// Vipps-appen aapner sin egen nettleser. Da har hun ingen sesjon hos oss, og
// retursida gjorde tidligere INGENTING — avtalen ble liggende «venter» til
// trekkrunden spurte, opptil et dogn senere.

$mid = (int) DB::verdi("SELECT id FROM members WHERE epost = 'kjede.aar@test.local'");
$sid = (int) DB::verdi('SELECT MAX(id) FROM subscriptions WHERE member_id = :m', ['m' => $mid]);
DB::kjor("UPDATE subscriptions SET status = 'venter' WHERE id = :s", ['s' => $sid]);
vippsSvarer('.avtale-status', 'ACTIVE');
sperrTrekkrunden();

// Ingen kjeks. Bare medlemsnummeret i adressen, slik Vipps sender henne.
$r = kall('/api/vipps-avtale-retur.php?m=' . $mid);
$etter = DB::en('SELECT status FROM subscriptions WHERE id = :s', ['s' => $sid]);
sjekk('avtalen blir aktiv med det samme, uten innlogging',
    ($etter['status'] ?? '') === 'aktiv',
    'status: ' . ($etter['status'] ?? '—') . ' — HTTP ' . $r['http']);

// Uten medlemsnummeret vet sida fortsatt ingenting. Da er det runden som tar den.
DB::kjor("UPDATE subscriptions SET status = 'venter' WHERE id = :s", ['s' => $sid]);
sperrTrekkrunden();
kall('/api/vipps-avtale-retur.php');
$etter = DB::en('SELECT status FROM subscriptions WHERE id = :s', ['s' => $sid]);
sjekk('… og uten nummeret roeres ingenting',
    ($etter['status'] ?? '') === 'venter',
    'en tom retur skal ikke kunne endre en tilfeldig avtale');
vippsSvarer('.avtale-status', '');

// ─────────────────────────────────────────────────────────────────────────
bolk('10. Vipps svarer 201 uten charge-id');
// ─────────────────────────────────────────────────────────────────────────
//
// Da finnes trekket trolig hos Vipps, men vi har ingenting aa sporre med.
// trekkUtenSvar() krever «vipps_psp_ref IS NOT NULL», saa raden ble usynlig
// for statusrunden — den sto «venter» for alltid uten at noen visste hvorfor.

DB::kjor('DELETE FROM payments WHERE subscription_id = :s', ['s' => $sid]);
DB::kjor("UPDATE subscriptions SET status = 'aktiv', neste_trekk = CURDATE() WHERE id = :s",
         ['s' => $sid]);
vippsSvarer('.trekk-uten-id', 'ja');
Medlemskap::kjorTrekkrunde();
vippsSvarer('.trekk-uten-id', '');

$rad = DB::en('SELECT status, vipps_psp_ref FROM payments WHERE subscription_id = :s
               ORDER BY id DESC LIMIT 1', ['s' => $sid]);
sjekk('raden merkes ikke «feilet» — trekket finnes trolig',
    ($rad['status'] ?? '') === 'venter',
    '«feilet» ville invitert til aa kreve inn det samme beloepet én gang til');
sjekk('… men den er synlig for deg i Kassa',
    ($rad['status'] ?? '') === 'venter' && ($rad['vipps_psp_ref'] ?? null) === null);

// Den skal staa i feilloggen, saa trekket kan finnes igjen hos Vipps for
// haand. logg_feil() skriver til error_log — her fanges den ved aa kjore
// runden om igjen med feilloggen midlertidig lagt i en fil vi kan lese.
$loggfil = __DIR__ . '/.feillogg';
@unlink($loggfil);
$forrige = (string) ini_get('error_log');
ini_set('error_log', $loggfil);
DB::kjor('DELETE FROM payments WHERE subscription_id = :s', ['s' => $sid]);
DB::kjor("UPDATE subscriptions SET neste_trekk = CURDATE() WHERE id = :s", ['s' => $sid]);
vippsSvarer('.trekk-uten-id', 'ja');
Medlemskap::kjorTrekkrunde();
vippsSvarer('.trekk-uten-id', '');
ini_set('error_log', $forrige);
$logget = is_file($loggfil) ? (string) file_get_contents($loggfil) : '';
@unlink($loggfil);
sjekk('… og feilloggen sier hva som mangler',
    str_contains($logget, 'ingen charge-id'),
    'loggen sa: ' . mb_substr(trim($logget), 0, 140));

// ─────────────────────────────────────────────────────────────────────────
bolk('11. En betaling som henger i mer enn en uke');
// ─────────────────────────────────────────────────────────────────────────
//
// Grensa i cron var sju dager. En betaling som hang i aatte ble aldri sett
// paa igjen: den sto «venter» for alltid, og pengene kunne staa reservert.

DB::kjor("DELETE FROM payments WHERE vipps_reference = 'TEST-GAMMEL'");
DB::settInn('payments', [
    'vipps_reference' => 'TEST-GAMMEL', 'type' => 'epayment', 'formal' => 'booking',
    'belop_ore' => 69000, 'status' => 'venter',
    'idempotency_key' => 'test-gammel-' . time(),
    'created_at' => gmdate('Y-m-d H:i:s', time() - 10 * 86400),
    'updated_at' => gmdate('Y-m-d H:i:s', time() - 10 * 86400),
]);
$vFra = vippsAntall();
shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && LISSOM_VIPPS_BASE='
    . escapeshellarg($FALSK) . ' php bin/cron.php betalinger 2>&1');
$spurt = false;
foreach (vippsSiden($vFra) as $k) {
    if (str_contains((string) ($k['sti'] ?? ''), 'TEST-GAMMEL')) { $spurt = true; }
}
sjekk('en ti dager gammel betaling blir fortsatt sjekket', $spurt,
    'med sju dagers grense ble den aldri sett paa igjen');
DB::kjor("DELETE FROM payments WHERE vipps_reference = 'TEST-GAMMEL'");

// ── Rydder ───────────────────────────────────────────────────────────────
DB::kjor("DELETE p FROM payments p JOIN members m ON m.id = p.member_id WHERE m.epost IN ({$EPOSTER})");
DB::kjor("DELETE s FROM subscriptions s JOIN members m ON m.id = s.member_id WHERE m.epost IN ({$EPOSTER})");
DB::kjor("DELETE a FROM membership_applications a JOIN members m ON m.id = a.member_id WHERE m.epost IN ({$EPOSTER})");
DB::kjor("DELETE FROM bookings WHERE gjest_epost IN ({$EPOSTER})");
DB::kjor("DELETE FROM members WHERE epost IN ({$EPOSTER})");
vippsSvarer('.avtale-status', '');
vippsSvarer('.trekk-status', '');
vippsSvarer('.betaling-status', '');

echo "\n" . str_repeat('─', 46) . "\n";
echo $ok . ' av ' . ($ok + count($feil)) . " sjekker gikk gjennom\n";
if ($feil) { echo "\nFEIL:\n - " . implode("\n - ", $feil) . "\n"; exit(1); }
