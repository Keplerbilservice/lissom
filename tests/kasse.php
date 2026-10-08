<?php
/**
 * Lissom Kasse på iPad (eieren, «ok, bygg det» 8. oktober 2026).
 *
 *   A  Tilgang: kassekontoen kommer bare til api/kasse/. Admin-API-ene og Min
 *      side ser den som ikke innlogget. Andre brukere får 404. Bryteren
 *      «Vis/kasse» av = 403. Innloggingen varer i 30 dager.
 *   B  PIN: feil PIN avvises, riktig låser opp, låsen går ut etter 5 minutter,
 *      «Lås» låser, PIN-en er unik, kassekontoen kan ikke ha PIN, fjernet
 *      tilgang (Brukere › Fjern tilgang) fjerner PIN-en og låser kassa, og for
 *      mange forsøk stoppes.
 *   C  Beløpene regnes på serveren: Paint on Pots (nivåpris fra basen, betalt
 *      ved booking trukket fra), varer (pris fra basen, ikke nettleseren),
 *      feil forventet beløp registrerer ingenting, samme nøkkel to ganger
 *      registrerer én gang, lageret.
 *   D  «Betalte ikke»: beløpet ved booking annulleres (ikke slettes) og
 *      trekkes ikke fra; Vipps-betalt nektes.
 *   E  Vipps-QR mot den falske Vippsen: én betaling per nøkkel, beløpet fra
 *      serveren, status hentes fra Vipps, lageret trekkes når pengene er
 *      inne, kontant etter en QR stopper QR-en først (også når kurven er
 *      endret etter «Tilbake»), påmelding med QR og så varer med QR går
 *      gjennom, og samtidige qr()/betal() for samme del gir ett oppgjør.
 *   F  Kvittering: e-post i kø for påmeldingen, ingen «ny påmelding» til
 *      verkstedet; SMS-kvitteringen (kassekvittering_sms) legges i kø med
 *      riktig beløp og måte. Ingenting sendes (ingen SMTP eller cron i testen).
 *   G  Dagens oppgjør øker med akkurat det som ble tatt inn.
 *
 * Ekte endepunkter (to PHP-servere) mot en isolert testbase, og
 * tests/falsk-vipps.mjs som Vipps. Ingen ekte betaling, e-post eller SMS.
 *   php tests/kasse.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
$oppsett = krev_testdatabase($rot);

$ledigPort = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
    return (int) substr($adr, strrpos($adr, ':') + 1);
};
$nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$prosesser = [];

// Falsk Vipps (node), og miljøet som peker dit. Settes før bootstrap.
$vippsPort = $ledigPort();
$prosesser[] = proc_open(['node', $rot . '/tests/falsk-vipps.mjs'], [0 => ['pipe', 'r'], 1 => ['file', $nul, 'a'], 2 => ['file', $nul, 'a']],
    $vp, $rot, array_merge(getenv(), ['FALSK_VIPPS_PORT' => (string) $vippsPort]));
fclose($vp[0]);
for ($v = 0; $v < 80; $v++) { $f = @fsockopen('127.0.0.1', $vippsPort, $e1, $e2, 0.1); if ($f) { fclose($f); break; } usleep(50000); }
putenv('LISSOM_VIPPS_BASE=http://127.0.0.1:' . $vippsPort);

require $rot . '/app/bootstrap.php';
if (!str_starts_with(Config::vippsBase(), 'http://127.0.0.1:')) {
    fwrite(STDERR, "Vipps-adressen er ikke den falske. Stopper.\n");
    exit(1);
}

$styr = static fn(string $n): string => __DIR__ . '/' . $n;
$forStyr = [];
foreach (['.betaling-status', '.qr-400'] as $n) {
    $forStyr[$n] = is_file($styr($n)) ? (string) file_get_contents($styr($n)) : null;
}
$sett = static function (string $n, ?string $v) use ($styr): void {
    if ($v === null) { @unlink($styr($n)); } else { file_put_contents($styr($n), $v); }
};

$tag = 'KASSE-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $kurs = []; $produkt = 0; $ferdig = false;
$bryterFor = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/kasse'");
// SMS-oppsettet i basen (innstillinger) settes bare for testen. Ingen cron
// kjører, så SMS-en blir liggende i køen og ryddes bort.
$smsFor = DB::harTabell('innstillinger')
    ? array_column(DB::alle("SELECT nokkel, verdi FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')"), 'verdi', 'nokkel')
    : [];
$logg = sys_get_temp_dir() . '/lissom-kasse-' . bin2hex(random_bytes(4)) . '.log';

// Ordrene og betalingene testen lager, er de med høyere id enn det som fantes nå (egen testbase).
$ordreFor = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM orders');
$betalingFor = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM payments');

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, &$produkt, &$prosesser, $forStyr, $sett, $bryterFor, $ordreFor, $betalingFor, $smsFor): void {
    foreach ($forStyr as $n => $v) { $sett($n, $v); }
    foreach ($prosesser as $p) { if (is_resource($p)) { proc_terminate($p); } }
    try {
        if ($bryterFor === null) { DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/kasse'"); }
        else { DB::kjor("UPDATE content_blocks SET verdi = :v WHERE nokkel = 'Vis/kasse'", ['v' => (string) $bryterFor]); }
        if (DB::harTabell('innstillinger')) {
            DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')");
            foreach ($smsFor as $n => $v) { DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v)', ['n' => $n, 'v' => $v]); }
        }
    } catch (Throwable $e) {}
    $k = implode(',', array_map('intval', $kurs ?: [0]));
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $bIder = array_map('intval', array_column(DB::alle("SELECT id FROM bookings WHERE course_id IN ($k)"), 'id'));
    $b = implode(',', $bIder ?: [0]);
    $oIder = array_map('intval', array_column(DB::alle('SELECT id FROM orders WHERE id > :i', ['i' => $ordreFor]), 'id'));
    $o = implode(',', $oIder ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id IN ($b)",
        "DELETE FROM notifications WHERE ref_type = 'order' AND ref_id IN ($o)",
        "DELETE FROM notifications WHERE ref_type = 'payment' AND ref_id > " . $betalingFor,
        "DELETE FROM pop_kasselinjer WHERE booking_id IN ($b)",
        "UPDATE bookings SET payment_id = NULL WHERE id IN ($b)",
        "UPDATE orders SET payment_id = NULL WHERE id IN ($o)",
        "DELETE FROM order_lines WHERE order_id IN ($o)",
        "DELETE FROM payments WHERE booking_id IN ($b) OR order_id IN ($o) OR registrert_av IN ($m) OR member_id IN ($m)",
        "DELETE FROM orders WHERE id IN ($o)",
        "DELETE FROM bookings WHERE id IN ($b)",
        "DELETE FROM course_sessions WHERE course_id IN ($k)",
        "DELETE FROM courses WHERE id IN ($k)",
        "DELETE FROM products WHERE id = " . (int) $produkt,
        "DELETE FROM check_ins WHERE member_id IN ($m)",
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m)",
        "DELETE FROM rate_limits WHERE nokkel LIKE 'kasse-pin%'",
        "DELETE FROM members WHERE id IN ($m)",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

// To servere mot samme base: én forespørsel hver gir ekte samtidighet (E).
$porter = [];
foreach ([0, 1] as $_) {
    $p = $ledigPort();
    $porter[] = $p;
    $prosesser[] = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $p, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
    fclose($pp[0]);
}
$klar = true;
foreach ($porter as $p) {
    $denne = false;
    for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.1); if ($f) { fclose($f); $denne = true; break; } usleep(50000); }
    $klar = $klar && $denne;
}
$port = $porter[0];

function forbered(string $sti, ?array $data, string $token, int $port): CurlHandle
{
    $c = curl_init('http://127.0.0.1:' . $port . $sti);
    $hode = ['Origin: ' . Config::nettsted()];
    if ($token !== '') { $hode[] = 'Cookie: lissom_sesjon=' . $token; }
    $valg = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true];
    if ($data !== null) {
        $hode[] = 'Content-Type: application/json';
        $valg[CURLOPT_POST] = true;
        $valg[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    $valg[CURLOPT_HTTPHEADER] = $hode;
    curl_setopt_array($c, $valg);
    return $c;
}
function svar(CurlHandle $c, string $raa): array
{
    $hl = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    return [(int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), json_decode(substr($raa, $hl), true), substr($raa, 0, $hl)];
}
/** @return array{0:int,1:mixed,2:string} status, json, rå */
function kall(string $sti, ?array $data, string $token = ''): array
{
    global $port;
    $c = forbered($sti, $data, $token, $port);
    $raa = (string) curl_exec($c);
    $s = svar($c, $raa);
    curl_close($c);
    return $s;
}
/** To kall samtidig, hvert til sin server. */
function samtidig(array $a, array $b, string $token): array
{
    global $porter;
    $m = curl_multi_init();
    $h = [forbered($a[0], $a[1], $token, $porter[0]), forbered($b[0], $b[1], $token, $porter[1])];
    foreach ($h as $c) { curl_multi_add_handle($m, $c); }
    do { curl_multi_exec($m, $aktiv); if ($aktiv) { curl_multi_select($m, 0.05); } } while ($aktiv);
    $ut = [];
    foreach ($h as $c) { $ut[] = svar($c, (string) curl_multi_getcontent($c)); curl_multi_remove_handle($m, $c); curl_close($c); }
    curl_multi_close($m);
    return $ut;
}
$K = '/api/kasse/kasse.php';
$P = '/api/kasse/pin.php';
$tekst = static fn(array $s): string => $s[0] . ' ' . json_encode($s[1], JSON_UNESCAPED_UNICODE);

$sesjon = static function (int $medlem, string $maate = 'passord', int $timer = 3): string {
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $medlem, 'token_hash' => hash('sha256', $t), 'maate' => $maate,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + $timer * 3600)]);
    return $t;
};
$nyttMedlem = static function (array $felt) use (&$medlemmer, $tag): int {
    $id = DB::settInn('members', $felt + ['epost' => strtolower($tag) . '-' . bin2hex(random_bytes(3)) . '@lissom.test', 'status' => 'ingen']);
    $medlemmer[] = $id;
    return $id;
};
$antallBetalinger = static fn(): int => (int) DB::verdi('SELECT COUNT(*) FROM payments');
$oppgjor = static fn(): array => KasseKurv::oppgjor();
$vlogg = __DIR__ . '/.falsk-vipps.jsonl';
$vippsKall = static function (callable $hva) use ($vlogg): array {
    return is_file($vlogg) ? array_values(array_filter(array_map(static fn($l) => json_decode($l, true), file($vlogg)), $hva)) : [];
};

try {
    sjekk('HTTP-serverne klare', $klar);
    sjekk('migrasjon 263 er kjørt', KasseTilgang::klar());
    sjekk('migrasjon 264 er kjørt (kassekvittering_sms)',
        DB::verdi("SELECT kanal FROM notification_templates WHERE navn = 'kassekvittering_sms'") === 'sms');
    DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/kasse', 'ja') ON DUPLICATE KEY UPDATE verdi = 'ja'");

    // ── Testdata ───────────────────────────────────────────────────────
    $passord = 'kasse-test-' . bin2hex(random_bytes(4));
    $kasse = $nyttMedlem(['navn' => $tag . ' iPad', 'rolle' => 'kasse', 'brukernavn' => strtolower($tag) . '-ipad',
        'passord_hash' => password_hash($passord, PASSWORD_DEFAULT)]);
    $monica = $nyttMedlem(['navn' => $tag . ' Monica', 'rolle' => 'medlem', 'brukernavn' => strtolower($tag) . '-monica']);
    $anne = $nyttMedlem(['navn' => $tag . ' Anne', 'rolle' => 'medlem', 'brukernavn' => strtolower($tag) . '-anne']);
    $per = $nyttMedlem(['navn' => $tag . ' Per', 'rolle' => 'medlem', 'brukernavn' => strtolower($tag) . '-per']);
    $vanlig = $nyttMedlem(['navn' => $tag . ' Vanlig', 'rolle' => 'medlem']);
    $admin = $nyttMedlem(['navn' => $tag . ' Admin', 'rolle' => 'admin']);
    $pinM = '1' . random_int(100, 999);
    $pinA = '5' . random_int(100, 999);
    $pinP = '8' . random_int(100, 999);
    KasseTilgang::settPin($monica, $pinM);
    KasseTilgang::settPin($anne, $pinA);
    KasseTilgang::settPin($per, $pinP);

    $oslo = new DateTimeZone('Europe/Oslo');
    $start = (new DateTimeImmutable('today 12:00', $oslo))->setTimezone(new DateTimeZone('UTC'));
    $popKurs = DB::settInn('courses', ['slug' => strtolower($tag) . '-pop', 'tittel' => $tag . ' Paint on Pots', 'type' => 'event',
        'pris_ore' => 10000, 'kapasitet' => 20, 'status' => 'publisert', 'folger_apningstid' => 1, 'depositum' => 1, 'avbestilling_timer' => 24]);
    $kurs[] = $popKurs;
    $vanligKurs = DB::settInn('courses', ['slug' => strtolower($tag) . '-kurs', 'tittel' => $tag . ' Dreiekurs', 'type' => 'kurs',
        'pris_ore' => 149000, 'kapasitet' => 8, 'status' => 'publisert']);
    $kurs[] = $vanligKurs;
    $popOkt = DB::settInn('course_sessions', ['course_id' => $popKurs, 'start_tid' => $start->format('Y-m-d H:i:s'),
        'slutt_tid' => $start->modify('+3 hours')->format('Y-m-d H:i:s'), 'status' => 'planlagt']);
    $kursOkt = DB::settInn('course_sessions', ['course_id' => $vanligKurs, 'start_tid' => $start->modify('+5 hours')->format('Y-m-d H:i:s'),
        'status' => 'planlagt']);
    $popBooking = static function (string $navn, string $betaling) use ($popKurs, $popOkt, $tag): int {
        $b = DB::settInn('bookings', ['course_id' => $popKurs, 'course_session_id' => $popOkt, 'gjest_navn' => $tag . ' ' . $navn,
            'gjest_epost' => strtolower($tag) . '-' . strtolower($navn) . '@example.com', 'gjest_telefon' => '+4799887766',
            'antall' => 2, 'belop_ore' => 20000, 'status' => 'betalt', 'depositum_ore' => 20000]);
        if ($betaling === 'vipps') {
            $p = DB::settInn('payments', ['vipps_reference' => $tag . '-DEP-' . $b, 'type' => 'epayment', 'formal' => 'booking',
                'belop_ore' => 20000, 'status' => 'betalt', 'idempotency_key' => Vipps::uuid(), 'booking_id' => $b]);
        } else {
            // Som api/admin/pamelding.php: plassen lagt inn i admin, «Kontant».
            $p = Booking::manuellBetaling($b, 20000, 'Kontant', null, null, 'Beløp ved booking');
        }
        DB::oppdater('bookings', ['payment_id' => $p], ['id' => $b]);
        return $b;
    };
    $kari = $popBooking('Kari', 'kontant');
    $ola = $popBooking('Ola', 'kontant');
    $vippsGjest = $popBooking('Vipps', 'vipps');
    $kursBooking = DB::settInn('bookings', ['course_id' => $vanligKurs, 'course_session_id' => $kursOkt, 'gjest_navn' => $tag . ' Torhild',
        'gjest_epost' => strtolower($tag) . '-torhild@example.com', 'antall' => 1, 'belop_ore' => 149000, 'status' => 'reservert',
        'betalt_maate' => 'Ikke betalt']);
    $produkt = DB::settInn('products', ['tittel' => $tag . ' Leire', 'pris_ore' => 29000, 'lager' => 3, 'status' => 'publisert', 'kategori' => 'Materialer']);
    $niv = array_column(PopPris::nivaer(), null, 'navn');
    sjekk('prisnivåene finnes (Liten 500, Stor 850)', ($niv['Liten']['prisOre'] ?? 0) === 50000 && ($niv['Stor']['prisOre'] ?? 0) === 85000);

    $kasseToken = $sesjon($kasse, 'passord', 720);
    $vanligToken = $sesjon($vanlig, 'vipps');
    $adminToken = $sesjon($admin, 'passord');

    /**
     * Kurven regnet av serveren, med nøkler og forventet beløp per del slik
     * iPaden sender dem.
     */
    $kjop = static function (array $kurv, array $betaler) use ($K, &$kasseToken): array {
        $s = kall($K, ['handling' => 'regn', 'kurv' => $kurv, 'betaler' => $betaler], $kasseToken);
        $deler = $s[1]['deler'] ?? [];
        return ['kurv' => $kurv, 'betaler' => $betaler,
                'nokler' => array_combine(array_column($deler, 'id'), array_map(static fn() => Vipps::uuid(), $deler)) ?: [],
                'forventet' => array_combine(array_column($deler, 'id'), array_column($deler, 'sumOre')) ?: [],
                'regn' => $s];
    };
    $send = static fn(array $k): array => ['kurv' => $k['kurv'], 'betaler' => $k['betaler'], 'nokler' => $k['nokler'], 'forventet' => $k['forventet']];

    // ══ A: Tilgang ══════════════════════════════════════════════════════
    echo "\n── A: tilgang ──\n";
    $s = kall('/api/logg-inn.php', ['brukernavn' => strtolower($tag) . '-ipad', 'passord' => $passord]);
    sjekk('kassekontoen logger inn: erKasse, ikke admin eller regnskap', $s[0] === 200 && ($s[1]['erKasse'] ?? null) === true
        && ($s[1]['erAdmin'] ?? null) === false && ($s[1]['erRegnskap'] ?? null) === false, $tekst($s));
    $utloper = DB::verdi('SELECT TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), MAX(expires_at)) FROM sessions WHERE member_id = :m', ['m' => $kasse]);
    sjekk('innloggingen varer i 30 dager', (int) $utloper >= 715, (string) $utloper);
    foreach (['/api/admin/oversikt.php', '/api/admin/arbeidsrom.php', '/api/admin/uttak.php', '/api/admin/kursbetaling.php?bookingId=' . $kari,
              '/api/admin/brukere.php', '/api/admin/betalinger.php', '/api/admin/dagsoppgjor.php'] as $sti) {
        $s = kall($sti, null, $kasseToken);
        sjekk('kassekontoen kommer ikke inn i ' . $sti, in_array($s[0], [401, 404], true), $tekst($s));
    }
    $s = kall('/api/admin/kursbetaling.php', ['handling' => 'registrer', 'bookingId' => $kari, 'maate' => 'Kontant'], $kasseToken);
    sjekk('… og kan ikke registrere betaling i admin', in_array($s[0], [401, 404], true), $tekst($s));
    $s = kall('/api/meg.php', null, $kasseToken);
    sjekk('Min side ser kassekontoen som ikke innlogget', $s[0] === 200 && ($s[1]['innlogget'] ?? null) === false, $tekst($s));
    $s = kall($P, null, $vanligToken);
    sjekk('et vanlig medlem får 404 i kassa', $s[0] === 404, $tekst($s));
    $s = kall($P, null);
    sjekk('ikke innlogget får 401', $s[0] === 401, $tekst($s));
    $s = kall($K, null, $kasseToken);
    sjekk('kassa er låst før PIN (423)', $s[0] === 423 && ($s[1]['laast'] ?? false) === true, $tekst($s));
    DB::kjor("UPDATE content_blocks SET verdi = 'nei' WHERE nokkel = 'Vis/kasse'");
    $s = kall($P, null, $kasseToken);
    sjekk('bryteren av: 403 «Kassa er ikke slått på.»', $s[0] === 403 && ($s[1]['feil'] ?? '') === 'Kassa er ikke slått på.', $tekst($s));
    DB::kjor("UPDATE content_blocks SET verdi = 'ja' WHERE nokkel = 'Vis/kasse'");

    // ══ B: PIN ══════════════════════════════════════════════════════════
    echo "\n── B: PIN ──\n";
    $feilPin = '0' . random_int(100, 999);
    $s = kall($P, ['handling' => 'pin', 'pin' => $feilPin], $kasseToken);
    sjekk('feil PIN avvises', $s[0] === 400 && ($s[1]['feilPin'] ?? false) === true, $tekst($s));
    $s = kall($P, ['handling' => 'pin', 'pin' => $pinM], $kasseToken);
    sjekk('riktig PIN låser opp som Monica', $s[0] === 200 && ($s[1]['person']['navn'] ?? '') === $tag . ' Monica', $tekst($s));
    $s = kall($K, null, $kasseToken);
    $grupper = array_column($s[1]['grupper'] ?? [], 'rader');
    $rader = array_merge(...($grupper ?: [[]]));
    $karirad = array_values(array_filter($rader, static fn($r) => $r['bookingId'] === $kari))[0] ?? null;
    sjekk('«I dag» viser dagens påmeldte, Kari med «Gjenstander»', $s[0] === 200 && $karirad !== null
        && $karirad['pille']['tekst'] === 'Gjenstander' && str_contains($karirad['info'], '200'), $tekst($s));
    $torhild = array_values(array_filter($rader, static fn($r) => $r['bookingId'] === $kursBooking))[0] ?? null;
    sjekk('… og Torhild som skylder 1 490 kr', $torhild !== null && $torhild['skyldigOre'] === 149000, json_encode($torhild, JSON_UNESCAPED_UNICODE));
    DB::kjor('UPDATE sessions SET kasse_ulast_til = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE token_hash = :h', ['h' => hash('sha256', $kasseToken)]);
    $s = kall($K, null, $kasseToken);
    sjekk('etter 5 minutter uten bruk er kassa låst igjen', $s[0] === 423, $tekst($s));
    kall($P, ['handling' => 'pin', 'pin' => $pinA], $kasseToken);
    $s = kall($K, null, $kasseToken);
    sjekk('Anne låser opp med sin PIN', $s[0] === 200 && ($s[1]['person']['navn'] ?? '') === $tag . ' Anne', $tekst($s));
    kall($P, ['handling' => 'laas'], $kasseToken);
    $s = kall($K, null, $kasseToken);
    sjekk('«Lås» låser kassa', $s[0] === 423, $tekst($s));
    $f = '';
    try { KasseTilgang::settPin($anne, $pinM); } catch (RuntimeException $e) { $f = $e->getMessage(); }
    sjekk('samme PIN for to personer nektes', $f === 'Den PIN-en bruker en annen. Velg en annen.', $f);
    $f = '';
    try { KasseTilgang::settPin($kasse, '1234'); } catch (RuntimeException $e) { $f = $e->getMessage(); }
    sjekk('kassekontoen kan ikke ha PIN', str_starts_with($f, 'Kassekontoen kan ikke ha PIN'), $f);
    $f = '';
    try { KasseTilgang::settPin($vanlig, '4321'); } catch (RuntimeException $e) { $f = $e->getMessage(); }
    sjekk('et medlem uten innlogging kan ikke få PIN', $f === 'Bare brukere med innlogging kan ha kasse-PIN.', $f);
    sjekk('PIN-en lagres bare som hash', !str_contains((string) DB::verdi('SELECT kasse_pin_hash FROM members WHERE id = :i', ['i' => $monica]), $pinM));

    // Fjern tilgang under Brukere: PIN-en går, og kassa låses der Per står.
    kall($P, ['handling' => 'pin', 'pin' => $pinP], $kasseToken);
    $s = kall($K, null, $kasseToken);
    sjekk('Per låser opp', $s[0] === 200 && ($s[1]['person']['navn'] ?? '') === $tag . ' Per', $tekst($s));
    DB::settInn('payments', ['vipps_reference' => $tag . '-HIST-' . $per, 'type' => 'manuell', 'formal' => 'medlemskap', 'member_id' => $per,
        'belop_ore' => 100, 'status' => 'betalt', 'idempotency_key' => Vipps::uuid()]);   // historikk: raden blir stående
    $s = kall('/api/admin/brukere.php', ['handling' => 'slett', 'id' => $per], $adminToken);
    sjekk('admin fjerner tilgangen til Per', $s[0] === 200, $tekst($s));
    sjekk('… kasse-PIN-en er fjernet', DB::verdi('SELECT kasse_pin_hash FROM members WHERE id = :i', ['i' => $per]) === null);
    $s = kall($K, null, $kasseToken);
    sjekk('… kassa der Per sto, er låst (423)', $s[0] === 423, $tekst($s));
    $s = kall($P, ['handling' => 'pin', 'pin' => $pinP], $kasseToken);
    sjekk('… og PIN-en hans avvises', $s[0] === 400, $tekst($s));
    // Også om PIN-en skulle stå igjen: uten tilgang slipper den ikke inn.
    DB::oppdater('members', ['kasse_pin_hash' => password_hash($pinP, PASSWORD_DEFAULT)], ['id' => $per]);
    $s = kall($P, ['handling' => 'pin', 'pin' => $pinP], $kasseToken);
    sjekk('PIN uten tilgang (ikke under Brukere) avvises', $s[0] === 400, $tekst($s));
    DB::oppdater('members', ['kasse_pin_hash' => null], ['id' => $per]);
    kall($P, ['handling' => 'pin', 'pin' => $pinM], $kasseToken);

    // ══ C: beløpene regnes på serveren ═══════════════════════════════════
    echo "\n── C: beløpene regnes på serveren ──\n";
    $kurvKari = ['bookingId' => $kari, 'pop' => [['nivaaId' => $niv['Liten']['id'], 'gjenstand' => '', 'antall' => 1],
        ['nivaaId' => $niv['Stor']['id'], 'gjenstand' => '', 'antall' => 1]]];
    $kk = $kjop($kurvKari, ['bookingId' => $kari]);
    $s = $kk['regn'];
    sjekk('Kari: Liten 500 + Stor 850 − 200 betalt ved booking = 1 150 kr', $s[0] === 200 && ($s[1]['sumOre'] ?? 0) === 115000
        && ($s[1]['deler'][0]['id'] ?? '') === 'booking:' . $kari, $tekst($s));
    $linjer = array_column($s[1]['deler'][0]['linjer'] ?? [], 'ore', 'tekst');
    sjekk('… med linja «Betalt ved booking» −200 kr', ($linjer['Betalt ved booking'] ?? 0) === -20000, json_encode($linjer, JSON_UNESCAPED_UNICODE));
    $for = $antallBetalinger();
    $feilBelop = $send($kk);
    $feilBelop['forventet'] = ['booking:' . $kari => 100];
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $feilBelop, $kasseToken);
    sjekk('feil forventet beløp (nettleseren sier 1 kr): 409, ingenting registrert', $s[0] === 409 && $antallBetalinger() === $for
        && DB::verdi('SELECT gjenstander_ore FROM bookings WHERE id = :i', ['i' => $kari]) === null, $tekst($s));
    $oppFor = $oppgjor();
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($kk), $kasseToken);
    $b = DB::en('SELECT status, belop_ore, gjenstander_ore FROM bookings WHERE id = :i', ['i' => $kari]);
    $rad = DB::en("SELECT * FROM payments WHERE booking_id = :b AND type = 'manuell' AND annullert_at IS NULL ORDER BY id DESC LIMIT 1", ['b' => $kari]);
    sjekk('Kontant 1 150 kr: én manuell rad på 115000 øre, registrert av Monica', $s[0] === 200 && (int) $rad['belop_ore'] === 115000
        && $rad['maate'] === 'Kontant' && (int) $rad['registrert_av'] === $monica, $tekst($s) . ' ' . json_encode($rad));
    sjekk('… gjenstandene slått inn (1 350 kr) og bookingen betalt', (int) $b['gjenstander_ore'] === 135000
        && (int) $b['belop_ore'] === 135000 && $b['status'] === 'betalt', json_encode($b));
    sjekk('… svaret har betalingsraden og kvittering på e-post (Kari har adresse)',
        ($s[1]['betalinger'] ?? []) === [(int) $rad['id']] && ($s[1]['kvitteringValg']['epost'] ?? false) === true, $tekst($s));
    sjekk('… ingen SMS-kvittering når SMS ikke er satt opp', ($s[1]['kvitteringValg']['sms'] ?? true) === false, $tekst($s));
    $kariBetalinger = $s[1]['betalinger'] ?? [];
    $antall = $antallBetalinger();
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($kk), $kasseToken);
    sjekk('samme nøkler en gang til: ingen ny betaling', $antallBetalinger() === $antall, $tekst($s));
    $oppEtter = $oppgjor();
    sjekk('Dagens oppgjør: kontant +1 150 kr', $oppEtter['kontantOre'] - $oppFor['kontantOre'] === 115000,
        ($oppEtter['kontantOre'] - $oppFor['kontantOre']) . '');

    $kurvVare = ['varer' => [['id' => $produkt, 'antall' => 2, 'prisOre' => 100]]];
    $kv = $kjop($kurvVare, []);
    sjekk('varer: prisen fra basen (2 × 290 = 580 kr), ikke nettleserens 1 kr', ($kv['regn'][1]['sumOre'] ?? 0) === 58000
        && ($kv['regn'][1]['deler'][0]['id'] ?? '') === 'ordre', $tekst($kv['regn']));
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['varer' => [['id' => $produkt, 'antall' => 5]]], 'betaler' => []], $kasseToken);
    sjekk('mer enn lageret (5 av 3) avvises', $s[0] === 400 && str_contains((string) ($s[1]['feil'] ?? ''), 'bare 3 igjen'), $tekst($s));
    $oppFor = $oppgjor();
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Vipps'] + $send($kv), $kasseToken);
    $ordre = DB::en("SELECT o.* FROM orders o JOIN payments p ON p.order_id = o.id WHERE p.idempotency_key = :k",
        ['k' => Kasse::radNokkel($kv['nokler']['ordre'], 1)]);
    sjekk('«Vipps-nummer»: én ordre D- på 580 kr, kontantkunde = «Salg over disk»', $s[0] === 200 && $ordre !== null
        && str_starts_with((string) $ordre['ordrenr'], 'D-') && (int) $ordre['sum_ore'] === 58000 && $ordre['kunde_navn'] === 'Salg over disk',
        $tekst($s) . ' ' . json_encode($ordre));
    sjekk('… lageret 3 → 1', (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $produkt]) === 1);
    $oppEtter = $oppgjor();
    $annen = static fn(array $o): int => (int) (array_column($o['rader'], 'ore', 'navn')['Betalt på annen måte'] ?? 0);
    sjekk('Dagens oppgjør: «Betalt på annen måte» +580 kr', $annen($oppEtter) - $annen($oppFor) === 58000);

    $s = kall($K, ['handling' => 'regn', 'kurv' => ['timepakke' => true], 'betaler' => []], $kasseToken);
    sjekk('timepakke uten medlem avvises', $s[0] === 400 && ($s[1]['feil'] ?? '') === 'Velg medlemmet som skal ha timepakken.', $tekst($s));
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['bookingId' => $kari], 'betaler' => ['medlemId' => $monica]], $kasseToken);
    sjekk('et medlem som ikke er inne, kan ikke velges som betaler', $s[0] === 400, $tekst($s));

    // ══ D: «Betalte ikke» ═══════════════════════════════════════════════
    echo "\n── D: «Betalte ikke» ──\n";
    $s = kall($K, ['handling' => 'person', 'bookingId' => $ola], $kasseToken);
    sjekk('Ola: 200 kr betalt ved booking, «Endre» kan brukes', ($s[1]['betaltOre'] ?? 0) === 20000 && ($s[1]['kanEndre'] ?? false) === true, $tekst($s));
    $depId = (int) DB::verdi('SELECT payment_id FROM bookings WHERE id = :i', ['i' => $ola]);
    $s = kall($K, ['handling' => 'betalteIkke', 'bookingId' => $ola], $kasseToken);
    $dep = DB::en('SELECT status, annullert_at, annullert_av, kommentar FROM payments WHERE id = :i', ['i' => $depId]);
    sjekk('beløpet ved booking annulleres, ikke slettes', $s[0] === 200 && $dep !== null && $dep['status'] === 'avbrutt'
        && $dep['annullert_at'] !== null && (int) $dep['annullert_av'] === $monica && str_contains((string) $dep['kommentar'], 'betalte ikke'),
        $tekst($s) . ' ' . json_encode($dep));
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['bookingId' => $ola, 'pop' => [['nivaaId' => $niv['Liten']['id'], 'antall' => 1]]],
        'betaler' => ['bookingId' => $ola]], $kasseToken);
    sjekk('… og ingenting trekkes fra: Liten = 500 kr å betale', ($s[1]['sumOre'] ?? 0) === 50000, $tekst($s));
    $s = kall($K, ['handling' => 'betalteIkke', 'bookingId' => $vippsGjest], $kasseToken);
    sjekk('betalt med Vipps ved booking: «Betalte ikke» nektes', $s[0] === 409 && str_contains((string) ($s[1]['feil'] ?? ''), 'Vipps'), $tekst($s));

    // ══ E: Vipps-QR ═════════════════════════════════════════════════════
    echo "\n── E: Vipps-QR ──\n";
    $linjerFor = is_file($vlogg) ? count(file($vlogg)) : 0;
    $sett('.betaling-status', 'CREATED');
    DB::oppdater('products', ['lager' => 3], ['id' => $produkt]);
    $kq = $kjop(['varer' => [['id' => $produkt, 'antall' => 1]]], []);
    $q = ['handling' => 'qr', 'del' => 'ordre'] + $send($kq);
    $s = kall($K, $q, $kasseToken);
    $ref = (string) ($s[1]['poll']['referanse'] ?? '');
    $p = DB::en('SELECT * FROM payments WHERE vipps_reference = :r', ['r' => $ref]);
    sjekk('QR for varen: KQ-referanse for iPaden, bilde, betaling «venter» på 290 kr med nøkkelen fra iPaden', $s[0] === 200
        && preg_match('/^KQ-[0-9a-f]{8}-\d{6}-/', $ref) === 1 && str_starts_with((string) ($s[1]['qr'] ?? ''), 'data:image/svg+xml')
        && $p !== null && $p['status'] === 'venter' && (int) $p['belop_ore'] === 29000 && $p['idempotency_key'] === strtolower($kq['nokler']['ordre']),
        $tekst($s));
    $opprett = array_values(array_filter(array_map(static fn($l) => json_decode($l, true), array_slice(file($vlogg), $linjerFor)),
        static fn($k) => ($k['metode'] ?? '') === 'POST' && ($k['sti'] ?? '') === '/epayment/v1/payments' && ($k['kropp']['reference'] ?? '') === $ref));
    sjekk('… Vipps fikk userFlow QR, 29000 øre og samme Idempotency-Key', count($opprett) === 1
        && ($opprett[0]['kropp']['userFlow'] ?? '') === 'QR' && ($opprett[0]['kropp']['amount']['value'] ?? 0) === 29000
        && ($opprett[0]['nokkel'] ?? '') === strtolower($kq['nokler']['ordre']), json_encode($opprett));
    $for = $antallBetalinger();
    $s = kall($K, $q, $kasseToken);
    sjekk('samme QR igjen (dobbelttrykk): samme referanse, ingen ny betaling', ($s[1]['poll']['referanse'] ?? '') === $ref && $antallBetalinger() === $for, $tekst($s));
    sjekk('lageret er urørt mens QR-en venter', (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $produkt]) === 3);
    $sett('.betaling-status', 'AUTHORIZED');
    DB::kjor('UPDATE payments SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE vipps_reference = :r', ['r' => $ref]);
    $s = kall($K, ['handling' => 'status', 'poll' => ['referanse' => $ref]], $kasseToken);
    $o = DB::en('SELECT status FROM orders WHERE payment_id = :p', ['p' => (int) $p['id']]);
    sjekk('status hentes fra Vipps: betalt, ordren betalt, betalingsraden i svaret', $s[0] === 200 && ($s[1]['betalt'] ?? false) === true
        && ($o['status'] ?? '') === 'betalt' && ($s[1]['betalingId'] ?? 0) === (int) $p['id'], $tekst($s));
    sjekk('… og lageret trekkes når pengene er inne (3 → 2)', (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $produkt]) === 2);

    // Samme kurv: QR vises, «Tilbake», Kontant (nøklene beholdes).
    $sett('.betaling-status', 'CREATED');
    $k4 = $kjop(['varer' => [['id' => $produkt, 'antall' => 1]]], []);
    $s = kall($K, ['handling' => 'qr', 'del' => 'ordre'] + $send($k4), $kasseToken);
    $ref4 = (string) ($s[1]['poll']['referanse'] ?? '');
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k4), $kasseToken);
    $p4 = DB::en('SELECT id, status FROM payments WHERE vipps_reference = :r', ['r' => $ref4]);
    $o4 = DB::en('SELECT status FROM orders WHERE payment_id = :p', ['p' => (int) $p4['id']]);
    $avbrutt = $vippsKall(static fn($k) => ($k['sti'] ?? '') === '/epayment/v1/payments/' . $ref4 . '/cancel' && ($k['kropp']['cancelTransactionOnly'] ?? false) === true);
    $manuell = DB::en('SELECT o.ordrenr, o.sum_ore FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.idempotency_key = :k',
        ['k' => Kasse::radNokkel($k4['nokler']['ordre'], 1)]);
    sjekk('QR → Tilbake → Kontant (samme kurv): QR-en stoppes hos Vipps og QR-ordren kanselleres', $s[0] === 200
        && count($avbrutt) === 1 && $p4['status'] === 'avbrutt' && ($o4['status'] ?? '') === 'kansellert', $tekst($s) . ' ' . json_encode([$p4, $o4]));
    sjekk('… og kontanten står som én D-ordre på 290 kr', $manuell !== null && str_starts_with((string) $manuell['ordrenr'], 'D-')
        && (int) $manuell['sum_ore'] === 29000, json_encode($manuell));

    // Kurven endres etter «Tilbake» (nye nøkler): den gamle QR-en stoppes likevel.
    $k5 = $kjop(['varer' => [['id' => $produkt, 'antall' => 1]]], []);
    $s = kall($K, ['handling' => 'qr', 'del' => 'ordre'] + $send($k5), $kasseToken);
    $ref5 = (string) ($s[1]['poll']['referanse'] ?? '');
    $k6 = $kjop(['fritt' => ['150']], []);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k6), $kasseToken);
    $p5 = DB::en('SELECT id, status FROM payments WHERE vipps_reference = :r', ['r' => $ref5]);
    $o5 = DB::en('SELECT status FROM orders WHERE payment_id = :p', ['p' => (int) $p5['id']]);
    sjekk('QR → Tilbake → endret kurv → Kontant: den gamle QR-en stoppes og ordren kanselleres (ingen dobbel betaling)',
        $s[0] === 200 && $p5['status'] === 'avbrutt' && ($o5['status'] ?? '') === 'kansellert'
        && count($vippsKall(static fn($k) => ($k['sti'] ?? '') === '/epayment/v1/payments/' . $ref5 . '/cancel')) === 1,
        $tekst($s) . ' ' . json_encode([$p5, $o5]));
    // … og har kunden rukket å betale den gamle, tas det ikke betalt en gang til.
    $k7 = $kjop(['varer' => [['id' => $produkt, 'antall' => 1]]], []);
    $s = kall($K, ['handling' => 'qr', 'del' => 'ordre'] + $send($k7), $kasseToken);
    $ref7 = (string) ($s[1]['poll']['referanse'] ?? '');
    $sett('.betaling-status', 'AUTHORIZED');
    $for = $antallBetalinger();
    $k8 = $kjop(['fritt' => ['200']], []);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k8), $kasseToken);
    sjekk('… betalte kunden den gamle QR-en: 409, kontanten registreres ikke', $s[0] === 409 && $antallBetalinger() === $for
        && DB::verdi('SELECT status FROM payments WHERE vipps_reference = :r', ['r' => $ref7]) === 'betalt', $tekst($s));

    // Påmelding med QR, så varene med QR i samme kurv.
    $sett('.betaling-status', 'CREATED');
    DB::oppdater('products', ['lager' => 3], ['id' => $produkt]);
    $kt = $kjop(['bookingId' => $kursBooking, 'varer' => [['id' => $produkt, 'antall' => 1]]], ['bookingId' => $kursBooking]);
    sjekk('Torhild + leire: to deler, 1 490 + 290 kr', ($kt['forventet']['booking:' . $kursBooking] ?? 0) === 149000
        && ($kt['forventet']['ordre'] ?? 0) === 29000, json_encode($kt['forventet']));
    $s = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $kursBooking] + $send($kt), $kasseToken);
    $ks = DB::en("SELECT * FROM payments WHERE booking_id = :b AND vipps_reference LIKE 'KS-QR-%'", ['b' => $kursBooking]);
    sjekk('Torhild: KS-QR på 1 490 kr (KursstartKrav::visQr)', $s[0] === 200 && $ks !== null && (int) $ks['belop_ore'] === 149000
        && $ks['status'] === 'venter' && ($s[1]['poll']['bookingId'] ?? 0) === $kursBooking, $tekst($s));
    $sett('.betaling-status', 'AUTHORIZED');
    DB::kjor('UPDATE payments SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = :i', ['i' => (int) $ks['id']]);
    $s = kall($K, ['handling' => 'status', 'poll' => ['bookingId' => $kursBooking]], $kasseToken);
    sjekk('… betalt når Vipps sier ja, og påmeldingen er betalt', ($s[1]['betalt'] ?? false) === true
        && ($s[1]['betalingId'] ?? 0) === (int) $ks['id']
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $kursBooking]) === 'betalt', $tekst($s));
    $sett('.betaling-status', 'CREATED');
    $s = kall($K, ['handling' => 'qr', 'del' => 'ordre'] + $send($kt), $kasseToken);
    sjekk('… så varene med QR i samme kurv: går gjennom (ikke 409), 290 kr', $s[0] === 200 && ($s[1]['betalt'] ?? true) === false
        && ($s[1]['belop'] ?? '') === KasseKurv::kr(29000), $tekst($s));
    $s = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $kursBooking] + $send($kt), $kasseToken);
    sjekk('… og påmeldingen svarer betalt om den spørres igjen', $s[0] === 200 && ($s[1]['betalt'] ?? false) === true, $tekst($s));

    // Paint on Pots med beløp ved booking via Vipps: resten kan tas med QR.
    $kv2 = $kjop(['bookingId' => $vippsGjest, 'pop' => [['nivaaId' => $niv['Liten']['id'], 'antall' => 2]]], ['bookingId' => $vippsGjest]);
    $s = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $vippsGjest] + $send($kv2), $kasseToken);
    $ks = DB::en("SELECT * FROM payments WHERE booking_id = :b AND vipps_reference LIKE 'KS-QR-%'", ['b' => $vippsGjest]);
    sjekk('Vipps-depositum: QR for resten, 2 × Liten − 200 = 800 kr', $s[0] === 200 && $ks !== null && (int) $ks['belop_ore'] === 80000, $tekst($s));

    // Samtidige trykk: qr() og betal() for samme del, hver til sin server.
    $sett('.betaling-status', 'CREATED');
    $ks2 = $kjop(['fritt' => ['333']], []);
    $svar2 = samtidig([$K, ['handling' => 'qr', 'del' => 'ordre'] + $send($ks2)], [$K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($ks2)], $kasseToken);
    $n0 = strtolower($ks2['nokler']['ordre']);
    $radene = DB::alle('SELECT type, status FROM payments WHERE idempotency_key IN (:a, :b)', ['a' => $n0, 'b' => Kasse::radNokkel($n0, 1)]);
    $betalt = array_filter($radene, static fn($r) => in_array($r['status'], ['betalt', 'autorisert'], true));
    $venter = array_filter($radene, static fn($r) => in_array($r['status'], ['opprettet', 'venter'], true));
    sjekk('samtidige qr() og betal() for samme del: nøyaktig ett oppgjør, ingen QR igjen som venter',
        count($betalt) === 1 && count($venter) === 0, json_encode([$svar2[0][0], $svar2[1][0], $radene]));

    // Øktlåsen: en QR som lages for én kurv mens kontant tas for en annen,
    // blir aldri merket avbrutt før den er laget hos Vipps (da kunne kunden
    // betalt en kode vi trodde var stoppet).
    $sett('.betaling-status', 'CREATED');
    $kqA = $kjop(['fritt' => ['410']], []);
    $kqB = $kjop(['fritt' => ['420']], []);
    $linjerFor = is_file($vlogg) ? count(file($vlogg)) : 0;
    $svar3 = samtidig([$K, ['handling' => 'qr', 'del' => 'ordre'] + $send($kqA)], [$K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($kqB)], $kasseToken);
    $qrRad = DB::en('SELECT vipps_reference, status FROM payments WHERE idempotency_key = :k', ['k' => strtolower($kqA['nokler']['ordre'])]);
    $nyeKall = array_map(static fn($l) => json_decode($l, true), array_slice(is_file($vlogg) ? file($vlogg) : [], $linjerFor));
    $opprettet = $avbrutt = -1;
    foreach ($nyeKall as $i => $k) {
        if (($k['metode'] ?? '') === 'POST' && ($k['sti'] ?? '') === '/epayment/v1/payments' && ($k['kropp']['reference'] ?? '') === ($qrRad['vipps_reference'] ?? '-')) { $opprettet = $i; }
        if (($k['sti'] ?? '') === '/epayment/v1/payments/' . ($qrRad['vipps_reference'] ?? '-') . '/cancel') { $avbrutt = $i; }
    }
    $iOrden = $qrRad === null
        || ($qrRad['status'] === 'venter' && $opprettet >= 0)
        || ($qrRad['status'] === 'avbrutt' && ($opprettet === -1 || $avbrutt > $opprettet));
    sjekk('QR og kontant samtidig (to kurver): QR-en er enten ventende og laget, eller stoppet etter at den ble laget',
        $iOrden && $svar3[1][0] === 200, json_encode([$svar3[0][0], $svar3[1][0], $qrRad, $opprettet, $avbrutt]));

    // ══ F: kvittering ═══════════════════════════════════════════════════
    echo "\n── F: kvittering ──\n";
    $for = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = 'booking' AND ref_id = :b", ['b' => $kari]);
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'epost', 'betalinger' => $kariBetalinger, 'betaler' => ['bookingId' => $kari]], $kasseToken);
    $nye = array_slice(DB::alle("SELECT kanal, mottaker, mal FROM notifications WHERE ref_type = 'booking' AND ref_id = :b ORDER BY id", ['b' => $kari]), $for);
    sjekk('kvittering på e-post: én e-post i kø til Kari, ingen beskjed til verkstedet', $s[0] === 200 && count($nye) === 1
        && $nye[0]['kanal'] === 'epost' && str_contains((string) $nye[0]['mottaker'], '-kari@example.com'), $tekst($s) . ' ' . json_encode($nye));
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'epost', 'betalinger' => [$depId], 'betaler' => ['bookingId' => $ola]], $kasseToken);
    sjekk('kvittering for noe som ikke er betalt i kassa i dag, nektes', $s[0] === 404, $tekst($s));
    // SMS: settes opp bare for testen (ingenting sendes, køen ryddes).
    foreach (['sveve_bruker' => 'kassetest', 'sveve_passord' => 'kassetest', 'sms_leverandor' => 'sveve'] as $n => $v) {
        DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)', ['n' => $n, 'v' => $v]);
    }
    $s = kall($K, ['handling' => 'kvitteringValg', 'betaler' => ['bookingId' => $kari], 'betalinger' => $kariBetalinger], $kasseToken);
    sjekk('med SMS satt opp og mobil: «Kvittering på SMS» kan velges', ($s[1]['sms'] ?? false) === true, $tekst($s));
    $s = kall($K, ['handling' => 'kvitteringValg', 'betaler' => ['bookingId' => $ola], 'betalinger' => $kariBetalinger], $kasseToken);
    sjekk('… men ikke for Karis betaling når en annen kunde er valgt', ($s[1]['sms'] ?? true) === false, $tekst($s));
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'sms', 'betalinger' => $kariBetalinger, 'betaler' => ['bookingId' => $ola]], $kasseToken);
    sjekk('Karis beløp til Olas mobil avvises: «Kvitteringen hører ikke til denne kunden.»', $s[0] === 409
        && ($s[1]['feil'] ?? '') === 'Kvitteringen hører ikke til denne kunden.'
        && DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'kassekvittering_sms' AND ref_type = 'payment' AND ref_id = :p",
            ['p' => (int) ($kariBetalinger[0] ?? 0)]) == 0, $tekst($s));
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'sms', 'betalinger' => $kariBetalinger, 'betaler' => ['bookingId' => $kari]], $kasseToken);
    $sms = DB::en("SELECT kanal, mottaker, mal, tekst FROM notifications WHERE ref_type = 'payment' AND ref_id = :p AND mal = 'kassekvittering_sms'",
        ['p' => (int) ($kariBetalinger[0] ?? 0)]);
    $d = new DateTimeImmutable('now', $oslo);
    $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];
    $ventet = 'Takk for handelen hos Lissom! Betalt 1' . "\u{a0}" . '150' . "\u{a0}" . 'kr med kontant ' . (int) $d->format('j') . '. '
        . $mnd[(int) $d->format('n') - 1] . '. Hilsen oss i Lissom';
    sjekk('SMS-kvitteringen ligger i kø med beløp, måte og dato', $s[0] === 200 && $sms !== null && $sms['kanal'] === 'sms'
        && normaliser_telefon((string) $sms['mottaker']) === normaliser_telefon('+4799887766')
        && str_replace([' ', "\u{a0}"], ' ', trim((string) $sms['tekst'])) === str_replace([' ', "\u{a0}"], ' ', $ventet),
        $tekst($s) . ' ' . json_encode($sms, JSON_UNESCAPED_UNICODE));
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'sms', 'betalinger' => $kariBetalinger, 'betaler' => ['bookingId' => $kari]], $kasseToken);
    sjekk('SMS-kvittering nr. 2 for samme betaling avvises: «Kvittering på SMS er alt sendt for dette kjøpet.»', $s[0] === 409
        && ($s[1]['feil'] ?? '') === 'Kvittering på SMS er alt sendt for dette kjøpet.'
        && DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'kassekvittering_sms' AND ref_type = 'payment' AND ref_id = :p",
            ['p' => (int) ($kariBetalinger[0] ?? 0)]) == 1, $tekst($s));
    $s = kall($K, ['handling' => 'kvitteringValg', 'betaler' => ['bookingId' => $kari], 'betalinger' => $kariBetalinger], $kasseToken);
    sjekk('… og knappen vises ikke lenger for den', ($s[1]['sms'] ?? true) === false, $tekst($s));
    $s = kall($K, ['handling' => 'kvitteringValg', 'betaler' => [], 'betalinger' => $kariBetalinger], $kasseToken);
    sjekk('kontantkunden får ingen SMS- eller e-postkvittering', ($s[1]['sms'] ?? true) === false && ($s[1]['epost'] ?? true) === false, $tekst($s));

    // ══ B (til slutt): for mange PIN-forsøk ═════════════════════════════
    echo "\n── B: for mange PIN-forsøk ──\n";
    $siste = 0;
    for ($i = 0; $i < KasseTilgang::PIN_FORSOK + 1; $i++) {
        $siste = kall($P, ['handling' => 'pin', 'pin' => $feilPin], $kasseToken)[0];
    }
    sjekk('etter ' . KasseTilgang::PIN_FORSOK . ' forsøk: 429', $siste === 429, (string) $siste);

    $ferdig = true;
} catch (Throwable $e) {
    echo "  FEIL  unntak: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    $feil++;
    $ferdig = true;
}

if (is_file($logg) && preg_match('/(Fatal error|Uncaught)/', (string) file_get_contents($logg))) {
    echo "  FEIL  serverloggen har feil:\n" . substr((string) file_get_contents($logg), 0, 3000) . "\n";
    $feil++;
}
echo "\n" . ($feil === 0 ? "$ok av $ok kasse-kontroller bestått\n" : "$feil av " . ($ok + $feil) . " kasse-kontroller FEILET\n");
exit($feil === 0 ? 0 : 1);
