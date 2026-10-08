<?php
/**
 * Lissom Kasse på iPad (eieren, «ok, bygg det» 8. oktober 2026).
 *
 *   A  Tilgang: kassekontoen kommer bare til api/kasse/. Admin-API-ene og Min
 *      side ser den som ikke innlogget. Andre brukere får 404. Bryteren
 *      «Vis/kasse» av = 403. Innloggingen varer i 30 dager.
 *   B  PIN: feil PIN avvises, riktig låser opp, låsen går ut etter 5 minutter,
 *      «Lås» låser, PIN-en er unik, kassekontoen kan ikke ha PIN, og for
 *      mange forsøk stoppes.
 *   C  Beløpene regnes på serveren: Paint on Pots (nivåpris fra basen, betalt
 *      ved booking trukket fra), varer (pris fra basen, ikke nettleseren),
 *      feil forventet beløp registrerer ingenting, samme nøkkel to ganger
 *      registrerer én gang, lageret.
 *   D  «Betalte ikke»: beløpet ved booking annulleres (ikke slettes) og
 *      trekkes ikke fra; Vipps-betalt nektes.
 *   E  Vipps-QR mot den falske Vippsen: én betaling per nøkkel, beløpet fra
 *      serveren, status hentes fra Vipps, lageret trekkes når pengene er
 *      inne, kontant etter en QR stopper QR-en først, påmeldingen betales med
 *      KursstartKrav (også resten etter Vipps-depositum).
 *   F  Kvittering: e-post i kø for påmeldingen, ingen «ny påmelding» til
 *      verkstedet. Ingenting sendes (ingen SMTP i testoppsettet).
 *   G  Dagens oppgjør øker med akkurat det som ble tatt inn.
 *
 * Ekte endepunkter (PHP-server) mot en isolert testbase, og tests/falsk-vipps.mjs
 * som Vipps. Ingen ekte betaling, e-post eller SMS.   php tests/kasse.php
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
$logg = sys_get_temp_dir() . '/lissom-kasse-' . bin2hex(random_bytes(4)) . '.log';

// Ordrene testen lager, er de med høyere id enn det som fantes nå (egen testbase).
$ordreFor = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM orders');

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, &$produkt, &$prosesser, $forStyr, $sett, $bryterFor, $ordreFor): void {
    foreach ($forStyr as $n => $v) { $sett($n, $v); }
    foreach ($prosesser as $p) { if (is_resource($p)) { proc_terminate($p); } }
    try {
        if ($bryterFor === null) { DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/kasse'"); }
        else { DB::kjor("UPDATE content_blocks SET verdi = :v WHERE nokkel = 'Vis/kasse'", ['v' => (string) $bryterFor]); }
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

$port = $ledigPort();
$prosesser[] = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

/** @return array{0:int,1:mixed,2:string} status, json, rå */
function kall(string $sti, ?array $data, string $token = '', string $metode = ''): array
{
    global $port;
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
    $raa = (string) curl_exec($c);
    $kode = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $hl = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    return [$kode, json_decode(substr($raa, $hl), true), substr($raa, 0, $hl)];
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
$nokkel = static fn(): string => Vipps::uuid();
$oppgjor = static fn(): array => Kasse::oppgjor();

try {
    sjekk('HTTP-server klar', $klar);
    sjekk('migrasjon 263 er kjørt', Kasse::klar());
    DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/kasse', 'ja') ON DUPLICATE KEY UPDATE verdi = 'ja'");

    // ── Testdata ───────────────────────────────────────────────────────
    $passord = 'kasse-test-' . bin2hex(random_bytes(4));
    $kasse = $nyttMedlem(['navn' => $tag . ' iPad', 'rolle' => 'kasse', 'brukernavn' => strtolower($tag) . '-ipad',
        'passord_hash' => password_hash($passord, PASSWORD_DEFAULT)]);
    $monica = $nyttMedlem(['navn' => $tag . ' Monica', 'rolle' => 'medlem']);
    $anne = $nyttMedlem(['navn' => $tag . ' Anne', 'rolle' => 'medlem']);
    $vanlig = $nyttMedlem(['navn' => $tag . ' Vanlig', 'rolle' => 'medlem']);
    $pinM = (string) random_int(1000, 4999);
    $pinA = (string) random_int(5000, 9999);
    Kasse::settPin($monica, $pinM);
    Kasse::settPin($anne, $pinA);

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
            'gjest_epost' => strtolower($tag) . '-' . strtolower($navn) . '@example.com', 'antall' => 2, 'belop_ore' => 20000,
            'status' => 'betalt', 'depositum_ore' => 20000]);
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
    $feilPin = $pinM === '0000' ? '0001' : str_pad((string) (((int) $pinM + 1) % 10000), 4, '0', STR_PAD_LEFT);
    if ($feilPin === $pinA) { $feilPin = '0000'; }
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
    try { Kasse::settPin($anne, $pinM); } catch (RuntimeException $e) { $f = $e->getMessage(); }
    sjekk('samme PIN for to personer nektes', $f === 'Den PIN-en bruker en annen. Velg en annen.', $f);
    $f = '';
    try { Kasse::settPin($kasse, '1234'); } catch (RuntimeException $e) { $f = $e->getMessage(); }
    sjekk('kassekontoen kan ikke ha PIN', str_starts_with($f, 'Kassekontoen kan ikke ha PIN'), $f);
    sjekk('PIN-en lagres bare som hash', !str_contains((string) DB::verdi('SELECT kasse_pin_hash FROM members WHERE id = :i', ['i' => $monica]), $pinM));
    kall($P, ['handling' => 'pin', 'pin' => $pinM], $kasseToken);

    // ══ C: beløpene regnes på serveren ═══════════════════════════════════
    echo "\n── C: beløpene regnes på serveren ──\n";
    $kurvKari = ['bookingId' => $kari, 'pop' => [['nivaaId' => $niv['Liten']['id'], 'gjenstand' => '', 'antall' => 1],
        ['nivaaId' => $niv['Stor']['id'], 'gjenstand' => '', 'antall' => 1]]];
    $s = kall($K, ['handling' => 'regn', 'kurv' => $kurvKari, 'betaler' => ['bookingId' => $kari]], $kasseToken);
    sjekk('Kari: Liten 500 + Stor 850 − 200 betalt ved booking = 1 150 kr', $s[0] === 200 && ($s[1]['sumOre'] ?? 0) === 115000, $tekst($s));
    $linjer = array_column($s[1]['deler'][0]['linjer'] ?? [], 'ore', 'tekst');
    sjekk('… med linja «Betalt ved booking» −200 kr', ($linjer['Betalt ved booking'] ?? 0) === -20000, json_encode($linjer, JSON_UNESCAPED_UNICODE));
    $for = $antallBetalinger();
    $n1 = $nokkel();
    $s = kall($K, ['handling' => 'betal', 'kurv' => $kurvKari, 'betaler' => ['bookingId' => $kari], 'maate' => 'Kontant',
        'nokler' => [$n1], 'forventetOre' => 100], $kasseToken);
    sjekk('feil forventet beløp (nettleseren sier 1 kr): 409, ingenting registrert', $s[0] === 409 && $antallBetalinger() === $for
        && DB::verdi('SELECT gjenstander_ore FROM bookings WHERE id = :i', ['i' => $kari]) === null, $tekst($s));
    $oppFor = $oppgjor();
    $s = kall($K, ['handling' => 'betal', 'kurv' => $kurvKari, 'betaler' => ['bookingId' => $kari], 'maate' => 'Kontant',
        'nokler' => [$n1], 'forventetOre' => 115000], $kasseToken);
    $b = DB::en('SELECT status, belop_ore, gjenstander_ore FROM bookings WHERE id = :i', ['i' => $kari]);
    $rad = DB::en("SELECT * FROM payments WHERE booking_id = :b AND type = 'manuell' AND annullert_at IS NULL ORDER BY id DESC LIMIT 1", ['b' => $kari]);
    sjekk('Kontant 1 150 kr: én manuell rad på 115000 øre, registrert av Monica', $s[0] === 200 && (int) $rad['belop_ore'] === 115000
        && $rad['maate'] === 'Kontant' && (int) $rad['registrert_av'] === $monica, $tekst($s) . ' ' . json_encode($rad));
    sjekk('… gjenstandene slått inn (1 350 kr) og bookingen betalt', (int) $b['gjenstander_ore'] === 135000
        && (int) $b['belop_ore'] === 135000 && $b['status'] === 'betalt', json_encode($b));
    sjekk('… svaret har kvittering på e-post (Kari har adresse)', ($s[1]['kvitteringValg']['epost'] ?? false) === true, $tekst($s));
    $antall = $antallBetalinger();
    $s = kall($K, ['handling' => 'betal', 'kurv' => $kurvKari, 'betaler' => ['bookingId' => $kari], 'maate' => 'Kontant',
        'nokler' => [$n1], 'forventetOre' => 115000], $kasseToken);
    sjekk('samme nøkkel en gang til: ingen ny betaling (eller 409 fordi det er betalt)', $antallBetalinger() === $antall, $tekst($s));
    $oppEtter = $oppgjor();
    sjekk('Dagens oppgjør: kontant +1 150 kr', $oppEtter['kontantOre'] - $oppFor['kontantOre'] === 115000,
        ($oppEtter['kontantOre'] - $oppFor['kontantOre']) . '');

    $kurvVare = ['varer' => [['id' => $produkt, 'antall' => 2, 'prisOre' => 100]]];
    $s = kall($K, ['handling' => 'regn', 'kurv' => $kurvVare, 'betaler' => []], $kasseToken);
    sjekk('varer: prisen fra basen (2 × 290 = 580 kr), ikke nettleserens 1 kr', ($s[1]['sumOre'] ?? 0) === 58000, $tekst($s));
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['varer' => [['id' => $produkt, 'antall' => 5]]], 'betaler' => []], $kasseToken);
    sjekk('mer enn lageret (5 av 3) avvises', $s[0] === 400 && str_contains((string) ($s[1]['feil'] ?? ''), 'bare 3 igjen'), $tekst($s));
    $oppFor = $oppgjor();
    $n2 = $nokkel();
    $s = kall($K, ['handling' => 'betal', 'kurv' => $kurvVare, 'betaler' => [], 'maate' => 'Vipps', 'nokler' => [$n2], 'forventetOre' => 58000], $kasseToken);
    $ordre = DB::en("SELECT o.* FROM orders o JOIN payments p ON p.order_id = o.id WHERE p.idempotency_key = :k", ['k' => Kasse::radNokkel($n2, 1)]);
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
    $vlogg = __DIR__ . '/.falsk-vipps.jsonl';
    $linjerFor = is_file($vlogg) ? count(file($vlogg)) : 0;
    $sett('.betaling-status', 'CREATED');
    DB::oppdater('products', ['lager' => 3], ['id' => $produkt]);
    $kurvQ = ['varer' => [['id' => $produkt, 'antall' => 1]]];
    $n3 = $nokkel();
    $q = ['handling' => 'qr', 'kurv' => $kurvQ, 'betaler' => [], 'del' => 0, 'nokler' => [$n3], 'forventetOre' => 29000];
    $s = kall($K, $q, $kasseToken);
    $ref = (string) ($s[1]['poll']['referanse'] ?? '');
    $p = DB::en('SELECT * FROM payments WHERE vipps_reference = :r', ['r' => $ref]);
    sjekk('QR for varen: KQ-referanse, bilde, betaling «venter» på 290 kr med nøkkelen fra iPaden', $s[0] === 200
        && str_starts_with($ref, 'KQ-') && str_starts_with((string) ($s[1]['qr'] ?? ''), 'data:image/svg+xml') && $p !== null
        && $p['status'] === 'venter' && (int) $p['belop_ore'] === 29000 && $p['idempotency_key'] === strtolower($n3), $tekst($s));
    $kallVipps = array_values(array_filter(array_map(static fn($l) => json_decode($l, true), array_slice(file($vlogg), $linjerFor)),
        static fn($k) => ($k['metode'] ?? '') === 'POST' && ($k['sti'] ?? '') === '/epayment/v1/payments' && ($k['kropp']['reference'] ?? '') === $ref));
    sjekk('… Vipps fikk userFlow QR, 29000 øre og samme Idempotency-Key', count($kallVipps) === 1
        && ($kallVipps[0]['kropp']['userFlow'] ?? '') === 'QR' && ($kallVipps[0]['kropp']['amount']['value'] ?? 0) === 29000
        && ($kallVipps[0]['nokkel'] ?? '') === strtolower($n3), json_encode($kallVipps));
    $for = $antallBetalinger();
    $s = kall($K, $q, $kasseToken);
    sjekk('samme QR igjen (dobbelttrykk): samme referanse, ingen ny betaling', ($s[1]['poll']['referanse'] ?? '') === $ref && $antallBetalinger() === $for, $tekst($s));
    sjekk('lageret er urørt mens QR-en venter', (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $produkt]) === 3);
    $sett('.betaling-status', 'AUTHORIZED');
    DB::kjor('UPDATE payments SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE vipps_reference = :r', ['r' => $ref]);
    $s = kall($K, ['handling' => 'status', 'poll' => ['referanse' => $ref]], $kasseToken);
    $o = DB::en('SELECT status FROM orders WHERE payment_id = :p', ['p' => (int) $p['id']]);
    sjekk('status hentes fra Vipps: betalt, ordren betalt', $s[0] === 200 && ($s[1]['betalt'] ?? false) === true && ($o['status'] ?? '') === 'betalt', $tekst($s));
    sjekk('… og lageret trekkes når pengene er inne (3 → 2)', (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $produkt]) === 2);

    // Kontant etter en QR som venter: QR-en stoppes først.
    $sett('.betaling-status', 'CREATED');
    $n4 = $nokkel();
    $s = kall($K, ['handling' => 'qr', 'kurv' => $kurvQ, 'betaler' => [], 'del' => 0, 'nokler' => [$n4], 'forventetOre' => 29000], $kasseToken);
    $ref4 = (string) ($s[1]['poll']['referanse'] ?? '');
    $s = kall($K, ['handling' => 'betal', 'kurv' => $kurvQ, 'betaler' => [], 'maate' => 'Kontant', 'nokler' => [$n4], 'forventetOre' => 29000], $kasseToken);
    $p4 = DB::en('SELECT id, status FROM payments WHERE vipps_reference = :r', ['r' => $ref4]);
    $o4 = DB::en('SELECT status FROM orders WHERE payment_id = :p', ['p' => (int) $p4['id']]);
    $avbrutt = array_filter(array_map(static fn($l) => json_decode($l, true), file($vlogg)),
        static fn($k) => ($k['sti'] ?? '') === '/epayment/v1/payments/' . $ref4 . '/cancel' && ($k['kropp']['cancelTransactionOnly'] ?? false) === true);
    $manuell = DB::en('SELECT o.ordrenr, o.sum_ore FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.idempotency_key = :k',
        ['k' => Kasse::radNokkel($n4, 1)]);
    sjekk('kontant etter QR: QR-en stoppes hos Vipps (cancelTransactionOnly) og QR-ordren kanselleres', $s[0] === 200
        && count($avbrutt) === 1 && $p4['status'] === 'avbrutt' && ($o4['status'] ?? '') === 'kansellert', $tekst($s) . ' ' . json_encode([$p4, $o4]));
    sjekk('… og kontanten står som én D-ordre på 290 kr', $manuell !== null && str_starts_with((string) $manuell['ordrenr'], 'D-')
        && (int) $manuell['sum_ore'] === 29000, json_encode($manuell));

    // Påmeldingen: QR for det som står igjen (KursstartKrav).
    $sett('.betaling-status', 'CREATED');
    $kurvT = ['bookingId' => $kursBooking];
    $s = kall($K, ['handling' => 'qr', 'kurv' => $kurvT, 'betaler' => ['bookingId' => $kursBooking], 'del' => 0,
        'nokler' => [$nokkel()], 'forventetOre' => 149000], $kasseToken);
    $ks = DB::en("SELECT * FROM payments WHERE booking_id = :b AND vipps_reference LIKE 'KS-QR-%'", ['b' => $kursBooking]);
    sjekk('Torhild: KS-QR på 1 490 kr (KursstartKrav::visQr)', $s[0] === 200 && $ks !== null && (int) $ks['belop_ore'] === 149000
        && $ks['status'] === 'venter' && ($s[1]['poll']['bookingId'] ?? 0) === $kursBooking, $tekst($s));
    $sett('.betaling-status', 'AUTHORIZED');
    DB::kjor('UPDATE payments SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = :i', ['i' => (int) $ks['id']]);
    $s = kall($K, ['handling' => 'status', 'poll' => ['bookingId' => $kursBooking]], $kasseToken);
    sjekk('… betalt når Vipps sier ja, og påmeldingen er betalt', ($s[1]['betalt'] ?? false) === true
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $kursBooking]) === 'betalt', $tekst($s));

    // Paint on Pots med beløp ved booking via Vipps: resten kan tas med QR.
    $sett('.betaling-status', 'CREATED');
    $kurvV = ['bookingId' => $vippsGjest, 'pop' => [['nivaaId' => $niv['Liten']['id'], 'antall' => 2]]];
    $s = kall($K, ['handling' => 'qr', 'kurv' => $kurvV, 'betaler' => ['bookingId' => $vippsGjest], 'del' => 0,
        'nokler' => [$nokkel()], 'forventetOre' => 80000], $kasseToken);
    $ks = DB::en("SELECT * FROM payments WHERE booking_id = :b AND vipps_reference LIKE 'KS-QR-%'", ['b' => $vippsGjest]);
    sjekk('Vipps-depositum: QR for resten, 2 × Liten − 200 = 800 kr', $s[0] === 200 && $ks !== null && (int) $ks['belop_ore'] === 80000, $tekst($s));

    // ══ F: kvittering ═══════════════════════════════════════════════════
    echo "\n── F: kvittering ──\n";
    $for = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = 'booking' AND ref_id = :b", ['b' => $kari]);
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'epost', 'mal' => [['type' => 'booking', 'id' => $kari]]], $kasseToken);
    $nye = DB::alle("SELECT kanal, mottaker, mal FROM notifications WHERE ref_type = 'booking' AND ref_id = :b ORDER BY id", ['b' => $kari]);
    $nye = array_slice($nye, $for);
    sjekk('kvittering på e-post: én e-post i kø til Kari, ingen beskjed til verkstedet', $s[0] === 200 && count($nye) === 1
        && $nye[0]['kanal'] === 'epost' && str_contains((string) $nye[0]['mottaker'], '-kari@example.com'), $tekst($s) . ' ' . json_encode($nye));
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'epost', 'mal' => [['type' => 'booking', 'id' => $vippsGjest + 999999]]], $kasseToken);
    sjekk('kvittering for noe som ikke er betalt i kassa i dag, nektes', $s[0] === 404, $tekst($s));

    // ══ B (til slutt): for mange PIN-forsøk ═════════════════════════════
    echo "\n── B: for mange PIN-forsøk ──\n";
    $siste = 0;
    for ($i = 0; $i < Kasse::PIN_FORSOK + 1; $i++) {
        $siste = kall($P, ['handling' => 'pin', 'pin' => $feilPin], $kasseToken)[0];
    }
    sjekk('etter ' . Kasse::PIN_FORSOK . ' forsøk: 429', $siste === 429, (string) $siste);

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
