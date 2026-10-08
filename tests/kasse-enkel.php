<?php
/**
 * Den enklere kassa på iPad (eieren, «ja» 8. oktober 2026, oppsett A).
 *
 *   A  Dagens kurs: bare kurs der noen skal betale, sortert etter klokkeslett
 *      (8 kurs samme dag); kurset åpnet viser bare de som ikke har betalt
 *      (samme påmeldinger som Kalender), Paint on Pots med depositum; betalt
 *      i kassa = betalt i admin og ute av lista.
 *   B  Ny kunde: «Bare kjøp» (navn og telefon på ordren) og «Knytt til
 *      dagens kurs» (påmelding på en ledig plass, med lås; fullt og
 *      dobbelttrykk avvises), og så betaling.
 *   C  Endret pris og rabatt: regnet og validert på serveren, lagret med
 *      opprinnelig pris, ny pris, rabatt, hvem og hvorfor; vises på
 *      kvitteringen og i Dagens oppgjør; fordelt på påmelding og varer;
 *      påmelding (kurs og Paint on Pots) settes ned og står som betalt;
 *      QR som ikke blir betalt og så kontant med ny rabatt trekker rabatten
 *      én gang; tåler at migrasjon 269 ikke er kjørt.
 *
 * Ekte endepunkter (to PHP-servere) mot en isolert testbase, og
 * tests/falsk-vipps.mjs som Vipps. Ingen ekte betaling, e-post eller SMS.
 *   php tests/kasse-enkel.php
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
        "DELETE FROM kasse_justeringer WHERE booking_id IN ($b) OR order_id IN ($o) OR registrert_av IN ($m)",
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
    sjekk('migrasjon 269 er kjørt (kasse_justeringer, bookings.kasse_rabatt_ore)', KasseJustering::klar());
    DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/kasse', 'ja') ON DUPLICATE KEY UPDATE verdi = 'ja'");

    // ── Testdata ───────────────────────────────────────────────────────
    $passord = 'kasse-test-' . bin2hex(random_bytes(4));
    $kasse = $nyttMedlem(['navn' => $tag . ' iPad', 'rolle' => 'kasse', 'brukernavn' => strtolower($tag) . '-ipad',
        'passord_hash' => password_hash($passord, PASSWORD_DEFAULT)]);
    $monica = $nyttMedlem(['navn' => $tag . ' Monica', 'rolle' => 'medlem', 'brukernavn' => strtolower($tag) . '-monica']);
    $pinM = '2' . random_int(100, 999);
    KasseTilgang::settPin($monica, $pinM);
    $kasseToken = $sesjon($kasse, 'passord', 720);
    kall($P, ['handling' => 'pin', 'pin' => $pinM], $kasseToken);

    $oslo = new DateTimeZone('Europe/Oslo');
    $utc = new DateTimeZone('UTC');
    $kl = static fn(string $t): string => (new DateTimeImmutable('today ' . $t, $oslo))->setTimezone($utc)->format('Y-m-d H:i:s');
    $nyttKurs = static function (string $navn, int $pris, int $kapasitet, array $mer = []) use (&$kurs, $tag): int {
        $id = DB::settInn('courses', ['slug' => strtolower($tag) . '-' . bin2hex(random_bytes(3)), 'tittel' => $tag . ' ' . $navn,
            'type' => 'kurs', 'pris_ore' => $pris, 'kapasitet' => $kapasitet, 'status' => 'publisert'] + $mer);
        $kurs[] = $id;
        return $id;
    };
    $nyOkt = static fn(int $kursId, string $t): int => DB::settInn('course_sessions', ['course_id' => $kursId, 'start_tid' => $kl($t), 'status' => 'planlagt']);
    $nyBooking = static function (int $kursId, int $okt, string $navn, int $belop, string $status = 'reservert', array $mer = []) use ($tag): int {
        return DB::settInn('bookings', $mer + ['course_id' => $kursId, 'course_session_id' => $okt, 'gjest_navn' => $tag . ' ' . $navn,
            'gjest_epost' => strtolower($tag) . '-' . strtolower($navn) . '@example.com', 'antall' => 1, 'belop_ore' => $belop,
            'status' => $status, 'betalt_maate' => $status === 'betalt' ? 'Kontant' : 'Ikke betalt']);
    };

    // Åtte kurs i dag, lagt inn hulter til bulter, hvert med én som skylder.
    $tider = ['19:30', '09:00', '21:00', '11:15', '17:00', '13:00', '20:00', '15:45'];
    $okter = [];
    $bookinger = [];
    foreach ($tider as $i => $t) {
        $kid = $nyttKurs('Kurs ' . $t, 100000 + $i * 1000, 8);
        $o = $nyOkt($kid, $t);
        $okter[$t] = $o;
        $bookinger[$t] = $nyBooking($kid, $o, 'Deltaker' . $i, 100000 + $i * 1000);
    }
    // Et niende der alle har betalt: vises ikke.
    $betaltKurs = $nyttKurs('Betalt', 50000, 8);
    $betaltOkt = $nyOkt($betaltKurs, '10:00');
    $bb = $nyBooking($betaltKurs, $betaltOkt, 'Ferdig', 50000, 'betalt');
    Booking::manuellBetaling($bb, 50000, 'Kontant', null, null, 'Testdata');
    // Paint on Pots med beløp ved booking (200 kr betalt kontant ved booking).
    $popKurs = $nyttKurs('Paint on Pots', 10000, 20, ['type' => 'event', 'folger_apningstid' => 1, 'depositum' => 1, 'avbestilling_timer' => 24]);
    $popOkt = $nyOkt($popKurs, '16:00');
    $sara = $nyBooking($popKurs, $popOkt, 'Sara', 20000, 'betalt', ['antall' => 2, 'depositum_ore' => 20000, 'gjest_telefon' => '+4799887766']);
    $p = Booking::manuellBetaling($sara, 20000, 'Kontant', null, null, 'Beløp ved booking');
    DB::oppdater('bookings', ['payment_id' => $p], ['id' => $sara]);
    // Et lite kurs med én ledig plass, til «Ny kunde».
    $liteKurs = $nyttKurs('Lite kurs', 80000, 2);
    $liteOkt = $nyOkt($liteKurs, '18:00');
    $fast = $nyBooking($liteKurs, $liteOkt, 'Fast', 80000, 'betalt');
    Booking::manuellBetaling($fast, 80000, 'Kontant');
    // Et kurs i morgen: ikke i kassa i dag.
    $imorgen = DB::settInn('course_sessions', ['course_id' => $liteKurs,
        'start_tid' => (new DateTimeImmutable('tomorrow 12:00', $oslo))->setTimezone($utc)->format('Y-m-d H:i:s'), 'status' => 'planlagt']);
    $produkt = DB::settInn('products', ['tittel' => $tag . ' Leire', 'pris_ore' => 29000, 'lager' => 9, 'status' => 'publisert', 'kategori' => 'Materialer']);
    $niv = array_column(PopPris::nivaer(), null, 'navn');

    $kjop = static function (array $kurv, array $betaler) use ($K, &$kasseToken): array {
        $s = kall($K, ['handling' => 'regn', 'kurv' => $kurv, 'betaler' => $betaler], $kasseToken);
        $deler = $s[1]['deler'] ?? [];
        return ['kurv' => $kurv, 'betaler' => $betaler,
                'nokler' => array_combine(array_column($deler, 'id'), array_map(static fn() => Vipps::uuid(), $deler)) ?: [],
                'forventet' => array_combine(array_column($deler, 'id'), array_column($deler, 'sumOre')) ?: [],
                'regn' => $s];
    };
    $send = static fn(array $k): array => ['kurv' => $k['kurv'], 'betaler' => $k['betaler'], 'nokler' => $k['nokler'], 'forventet' => $k['forventet']];
    $mine = static fn(array $liste): array => array_values(array_filter($liste, static fn(array $k): bool => str_starts_with((string) $k['tittel'], $tag)));
    $regn = static fn(array $kurv): array => kall($K, ['handling' => 'regn', 'kurv' => $kurv, 'betaler' => []], $kasseToken);

    // ══ A: dagens kurs ══════════════════════════════════════════════════
    echo "\n── A: dagens kurs og deltakerne ──\n";
    $s = kall($K, null, $kasseToken);
    $kursListe = $mine($s[1]['kurs'] ?? []);
    $tittler = array_column($kursListe, 'tittel');
    $klokke = array_column($kursListe, 'kl');
    $sortert = $klokke;
    sort($sortert);
    sjekk('8 kurs + Paint on Pots der noen skal betale (9), sortert etter klokkeslett', count($kursListe) === 9 && $klokke === $sortert
        && ($klokke[0] ?? '') === '09:00' && end($klokke) === '21:00', json_encode($kursListe, JSON_UNESCAPED_UNICODE));
    sjekk('kurs der alle har betalt, og kurset i morgen, vises ikke', !in_array($tag . ' Betalt', $tittler, true)
        && !in_array($tag . ' Lite kurs', $tittler, true));
    $popKort = array_values(array_filter($kursListe, static fn($k) => $k['oktId'] === $popOkt))[0] ?? null;
    sjekk('Paint on Pots: «pop» og 1 skal betale', $popKort !== null && $popKort['pop'] === true && $popKort['skalBetale'] === 1, json_encode($popKort));
    $ledige = array_column($mine($s[1]['ledigeKurs'] ?? []), 'ledige', 'oktId');
    sjekk('«Knytt til dagens kurs»: Lite kurs har 1 ledig plass', ($ledige[$liteOkt] ?? 0) === 1, json_encode($ledige));

    $s = kall($K . '?okt=' . $popOkt, null, $kasseToken);
    $sararad = $s[1]['rader'][0] ?? [];
    sjekk('kurset åpnet: Sara, 2 personer, 200 kr depositum', $s[0] === 200 && count($s[1]['rader'] ?? []) === 1
        && ($sararad['bookingId'] ?? 0) === $sara && ($sararad['depositumOre'] ?? 0) === 20000 && ($sararad['antall'] ?? 0) === 2, $tekst($s));
    $s = kall($K . '?okt=' . $betaltOkt, null, $kasseToken);
    sjekk('kurs der alle har betalt: tom liste', $s[0] === 200 && ($s[1]['rader'] ?? null) === [] && ($s[1]['skalBetale'] ?? -1) === 0, $tekst($s));
    $s = kall($K . '?okt=' . $imorgen, null, $kasseToken);
    sjekk('kurs i morgen: 404', $s[0] === 404, $tekst($s));

    // Betalt i kassa = betalt i admin, og ute av lista.
    $b0900 = $bookinger['09:00'];
    $k = $kjop(['bookingId' => $b0900], ['bookingId' => $b0900]);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    sjekk('09:00-deltakeren betaler 1 010 kr kontant: påmeldingen er betalt', $s[0] === 200 && ($k['forventet']['booking:' . $b0900] ?? 0) === 101000
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $b0900]) === 'betalt', $tekst($s));
    $s = kall($K . '?okt=' . $okter['09:00'], null, $kasseToken);
    sjekk('… og står ikke lenger i lista til kurset', ($s[1]['rader'] ?? null) === [], $tekst($s));
    $s = kall($K, null, $kasseToken);
    sjekk('… og kurset er borte fra «Dagens kurs» (8 igjen)', count($mine($s[1]['kurs'] ?? [])) === 8);

    // ══ B: ny kunde ═════════════════════════════════════════════════════
    echo "\n── B: ny kunde ──\n";
    $s = kall($K, ['handling' => 'nyKunde', 'navn' => '', 'telefon' => ''], $kasseToken);
    sjekk('uten navn: avvises', $s[0] === 400 && ($s[1]['feil'] ?? '') === 'Skriv inn navnet.', $tekst($s));
    $s = kall($K, ['handling' => 'nyKunde', 'navn' => $tag . ' Line', 'telefon' => '912 34 567'], $kasseToken);
    sjekk('«Bare kjøp»: navn og telefon (+4791234567) tilbake, ingen påmelding', $s[0] === 200
        && ($s[1]['betaler'] ?? null) === ['navn' => $tag . ' Line', 'telefon' => '+4791234567'], $tekst($s));
    $betalerLine = $s[1]['betaler'] ?? [];
    $k = $kjop(['varer' => [['id' => $produkt, 'antall' => 1]]], $betalerLine);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $o = DB::en('SELECT o.kunde_navn, o.kunde_telefon FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.idempotency_key = :k',
        ['k' => Kasse::radNokkel((string) ($k['nokler']['ordre'] ?? ''), 1)]);
    sjekk('… salget har navnet og telefonen på kvitteringen (ordren)', ($o['kunde_navn'] ?? '') === $tag . ' Line'
        && ($o['kunde_telefon'] ?? '') === '+4791234567', $tekst($s) . ' ' . json_encode($o));

    $s = kall($K, ['handling' => 'nyKunde', 'navn' => $tag . ' Ny', 'telefon' => '40000001', 'oktId' => $liteOkt], $kasseToken);
    $ny = (int) ($s[1]['bookingId'] ?? 0);
    $nb = DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => $ny]);
    sjekk('knyttet til Lite kurs: påmelding «reservert», 800 kr, lagt inn av Monica', $s[0] === 200 && $nb !== null
        && $nb['status'] === 'reservert' && (int) $nb['belop_ore'] === 80000 && (int) $nb['lagt_inn_av'] === $monica
        && $nb['gjest_telefon'] === '+4740000001' && ($s[1]['betaler'] ?? null) === ['bookingId' => $ny], $tekst($s));
    $s = kall($K, ['handling' => 'nyKunde', 'navn' => $tag . ' Nummer to', 'telefon' => '', 'oktId' => $liteOkt], $kasseToken);
    sjekk('kurset er nå fullt: neste avvises (409), ingen ny påmelding', $s[0] === 409
        && (int) DB::verdi("SELECT COUNT(*) FROM bookings WHERE course_session_id = :o AND status <> 'avbestilt'", ['o' => $liteOkt]) === 2, $tekst($s));
    $s = kall($K, ['handling' => 'nyKunde', 'navn' => $tag . ' Deltaker0', 'telefon' => '', 'oktId' => $okter['19:30']], $kasseToken);
    sjekk('samme navn på samme kurs (dobbelttrykk): avvises', $s[0] === 409 && str_contains((string) ($s[1]['feil'] ?? ''), 'står alt'), $tekst($s));
    $s = kall($K, ['handling' => 'nyKunde', 'navn' => $tag . ' Sen', 'telefon' => '', 'oktId' => $imorgen], $kasseToken);
    sjekk('kurs som ikke er i dag: avvises', $s[0] === 404, $tekst($s));
    $s = kall($K . '?okt=' . $liteOkt, null, $kasseToken);
    sjekk('den nye står i lista til kurset (samme påmeldinger som Kalender)', in_array($ny, array_column($s[1]['rader'] ?? [], 'bookingId'), true), $tekst($s));
    $k = $kjop(['bookingId' => $ny], ['bookingId' => $ny]);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    sjekk('… betaler 800 kr kontant: påmeldingen er betalt', $s[0] === 200 && ($k['forventet']['booking:' . $ny] ?? 0) === 80000
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $ny]) === 'betalt', $tekst($s));

    // ══ C: endret pris og rabatt ════════════════════════════════════════
    echo "\n── C: endret pris og rabatt ──\n";
    $kurvPris = ['varer' => [['id' => $produkt, 'antall' => 2]], 'priser' => ['vare:' . $produkt => '200'],
                 'rabatt' => ['prosent' => 10, 'hvorfor' => 'Fast kunde']];
    $k = $kjop($kurvPris, []);
    $d = $k['regn'][1]['deler'][0] ?? [];
    $linje = $d['linjer'][0] ?? [];
    sjekk('Leire 2 × 200 kr (endret fra 290) − 10 % = 360 kr', $k['regn'][0] === 200 && ($k['regn'][1]['sumOre'] ?? 0) === 36000
        && ($linje['ore'] ?? 0) === 40000 && ($linje['fraOre'] ?? 0) === 58000 && ($linje['enhetOre'] ?? 0) === 20000, $tekst($k['regn']));
    $rabattLinje = array_values(array_filter($d['linjer'] ?? [], static fn($l) => ($l['rabatt'] ?? false) === true))[0] ?? [];
    sjekk('… med linja «Rabatt 10 %» −40 kr, og rabatten i svaret', ($rabattLinje['tekst'] ?? '') === 'Rabatt 10 %'
        && ($rabattLinje['ore'] ?? 0) === -4000 && ($k['regn'][1]['rabatt']['ore'] ?? 0) === 4000 && ($k['regn'][1]['rabatt']['hvorfor'] ?? '') === 'Fast kunde',
        $tekst($k['regn']));
    $oppFor = $oppgjor();
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $ordre = DB::en('SELECT o.* FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.idempotency_key = :k',
        ['k' => Kasse::radNokkel((string) ($k['nokler']['ordre'] ?? ''), 1)]);
    $ol = DB::en('SELECT antall, pris_ore FROM order_lines WHERE order_id = :o', ['o' => (int) ($ordre['id'] ?? 0)]);
    $j = DB::en('SELECT * FROM kasse_justeringer WHERE order_id = :o', ['o' => (int) ($ordre['id'] ?? 0)]);
    sjekk('kontant: ordren er 360 kr, varelinja 2 × 200 kr', $s[0] === 200 && (int) ($ordre['sum_ore'] ?? 0) === 36000
        && (int) ($ol['pris_ore'] ?? 0) === 20000 && (int) ($ol['antall'] ?? 0) === 2, $tekst($s));
    sjekk('… lagret: fra 580 til 360 kr, pris −180, rabatt 40 «10 %», hvorfor og hvem (Monica)', $j !== null
        && (int) $j['opprinnelig_ore'] === 58000 && (int) $j['ny_ore'] === 36000 && (int) $j['pris_ore'] === 18000
        && (int) $j['rabatt_ore'] === 4000 && $j['rabatt_tekst'] === '10 %' && $j['hvorfor'] === 'Fast kunde' && (int) $j['registrert_av'] === $monica,
        json_encode($j, JSON_UNESCAPED_UNICODE));
    $forventetTekst = [$tag . ' Leire: pris endret fra ' . KasseKurv::kr(29000), 'Rabatt 10 % — −' . KasseKurv::kr(4000)];
    sjekk('… kvitteringen på skjermen viser endringene', ($s[1]['endringer'] ?? []) === $forventetTekst, $tekst($s));
    sjekk('… og e-postkvitteringen (varelinjene) får de samme linjene', KasseJustering::kvitteringslinjer((int) ($ordre['id'] ?? 0)) === $forventetTekst);
    $oppEtter = $oppgjor();
    $min = array_values(array_filter($oppEtter['endringer'] ?? [], static fn($e) => ($e['hvorfor'] ?? '') === 'Fast kunde' && ($e['hvem'] ?? '') === $tag . ' Monica'))[0] ?? null;
    sjekk('Dagens oppgjør: kontant +360 kr, og endringen med hvem og hvorfor', $oppEtter['kontantOre'] - $oppFor['kontantOre'] === 36000
        && $min !== null && $min['hvem'] === $tag . ' Monica' && $min['trukketOre'] === 22000 && $min['fra'] === KasseKurv::kr(58000) && $min['til'] === KasseKurv::kr(36000),
        json_encode($oppEtter['endringer'] ?? null, JSON_UNESCAPED_UNICODE));
    $antall = $antallBetalinger();
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    sjekk('samme nøkler en gang til: ingen ny betaling, ingen ny endring', $antallBetalinger() === $antall
        && (int) DB::verdi('SELECT COUNT(*) FROM kasse_justeringer WHERE nokkel = :k', ['k' => strtolower((string) $k['nokler']['ordre'])]) === 1, $tekst($s));

    // Grensene, på serveren.
    $v1 = ['varer' => [['id' => $produkt, 'antall' => 1]]];
    $s = $regn($v1 + ['rabatt' => ['kr' => '300']]);
    sjekk('rabatt større enn summen (300 av 290 kr): avvises', $s[0] === 400 && str_starts_with((string) ($s[1]['feil'] ?? ''), 'Rabatten kan ikke være større enn ' . KasseKurv::kr(29000)), $tekst($s));
    $s = $regn($v1 + ['rabatt' => ['prosent' => '150']]);
    sjekk('rabatt over 100 %: avvises', $s[0] === 400, $tekst($s));
    $s = $regn($v1 + ['priser' => ['vare:' . $produkt => '-5']]);
    sjekk('pris under 0: avvises', $s[0] === 400 && ($s[1]['feil'] ?? '') === 'Prisen må være 0 kroner eller mer.', $tekst($s));
    $s = $regn($v1 + ['priser' => ['vare:999999999' => '10']]);
    sjekk('pris på en linje som ikke er i kurven: 409', $s[0] === 409, $tekst($s));
    $s = $regn($v1 + ['priser' => ['annet' => '10']]);
    sjekk('pris med ukjent nøkkel: 409', $s[0] === 409, $tekst($s));
    $s = $regn($v1 + ['rabatt' => ['kr' => '290']]);
    sjekk('rabatt lik summen: 0 kr å betale', $s[0] === 200 && ($s[1]['sumOre'] ?? -1) === 0, $tekst($s));
    $s = $regn(['gavekort' => ['500'], 'rabatt' => ['prosent' => 10]]);
    sjekk('gavekort får ikke rabatt: «Det er ingenting å gi rabatt på.»', $s[0] === 400 && ($s[1]['feil'] ?? '') === 'Det er ingenting å gi rabatt på.', $tekst($s));

    // Rabatt på et kjøp med påmelding og vare: fordelt etter størrelse.
    $b1115 = $bookinger['11:15'];   // 1 030 kr
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['bookingId' => $b1115, 'varer' => [['id' => $produkt, 'antall' => 1]], 'rabatt' => ['kr' => '132']],
        'betaler' => ['bookingId' => $b1115]], $kasseToken);
    $dl = array_column($s[1]['deler'] ?? [], 'sumOre', 'id');
    sjekk('1 030 + 290 kr − 132 kr: 1 188 kr, fordelt 103 + 29 kr', $s[0] === 200 && ($s[1]['sumOre'] ?? 0) === 118800
        && ($dl['booking:' . $b1115] ?? 0) === 92700 && ($dl['ordre'] ?? 0) === 26100, $tekst($s));

    // Påmelding: endret pris på kurslinja og rabatt i kroner.
    $b1300 = $bookinger['13:00'];   // 1 050 kr
    $k = $kjop(['bookingId' => $b1300, 'priser' => ['booking:' . $b1300 => '900'], 'rabatt' => ['kr' => '100', 'hvorfor' => 'Kom sent']],
        ['bookingId' => $b1300]);
    sjekk('kurs 1 050 kr → 900 kr − 100 kr rabatt = 800 kr', ($k['forventet']['booking:' . $b1300] ?? 0) === 80000, $tekst($k['regn']));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $bb = DB::en('SELECT status, belop_ore, kasse_rabatt_ore FROM bookings WHERE id = :i', ['i' => $b1300]);
    $bet = Booking::betalingerFor($b1300);
    sjekk('… kontant 800 kr: påmeldingen er 800 kr og betalt i admin, 250 kr trukket i kassa', $s[0] === 200 && $bb['status'] === 'betalt'
        && (int) $bb['belop_ore'] === 80000 && (int) $bb['kasse_rabatt_ore'] === 25000 && $bet['sum'] === 80000, $tekst($s) . ' ' . json_encode($bb));

    // Paint on Pots: endret pris på en gjenstand og rabatt, først med QR som
    // ikke blir betalt, så en annen rabatt og kontant. Rabatten trekkes én gang.
    $sett('.betaling-status', 'CREATED');
    $kurvSara = ['bookingId' => $sara, 'pop' => [['nivaaId' => $niv['Liten']['id'], 'gjenstand' => '', 'antall' => 1],
        ['nivaaId' => $niv['Stor']['id'], 'gjenstand' => '', 'antall' => 1]]];
    $storNokkel = 'booking:' . $sara . ':' . $niv['Stor']['id'] . '|';
    $k = $kjop($kurvSara + ['priser' => [$storNokkel => '700'], 'rabatt' => ['prosent' => 10]], ['bookingId' => $sara]);
    $stor = array_values(array_filter($k['regn'][1]['deler'][0]['linjer'] ?? [], static fn($l) => ($l['nokkel'] ?? '') === $storNokkel))[0] ?? [];
    sjekk('Sara: Liten 500 + Stor 700 (fra 850) − 200 depositum = 1 000, − 10 % = 900 kr', ($k['forventet']['booking:' . $sara] ?? 0) === 90000
        && ($stor['fraOre'] ?? 0) === 85000 && ($stor['ore'] ?? 0) === 70000, $tekst($k['regn']));
    $linjerFor = is_file($vlogg) ? count(file($vlogg)) : 0;
    $s = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $sara] + $send($k), $kasseToken);
    $opprett = array_values(array_filter(array_map(static fn($l) => json_decode($l, true), array_slice(is_file($vlogg) ? file($vlogg) : [], $linjerFor)),
        static fn($x) => ($x['metode'] ?? '') === 'POST' && ($x['sti'] ?? '') === '/epayment/v1/payments'));
    sjekk('… QR: Vipps får 900 kr', $s[0] === 200 && count($opprett) === 1 && ($opprett[0]['kropp']['amount']['value'] ?? 0) === 90000, $tekst($s));
    // Tilbake, 20 % i stedet, kontant (nye nøkler).
    $k2 = $kjop($kurvSara + ['priser' => [$storNokkel => '700'], 'rabatt' => ['prosent' => 20, 'hvorfor' => 'Bursdag']], ['bookingId' => $sara]);
    sjekk('… Tilbake og 20 %: 1 000 − 200 = 800 kr (rabatten fra QR-en regnes ikke to ganger)', ($k2['forventet']['booking:' . $sara] ?? 0) === 80000,
        $tekst($k2['regn']));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k2), $kasseToken);
    $sb = DB::en('SELECT status, belop_ore, gjenstander_ore, kasse_rabatt_ore FROM bookings WHERE id = :i', ['i' => $sara]);
    $rader = DB::alle('SELECT nokkel, erstattet_at, rabatt_ore, pris_ore FROM kasse_justeringer WHERE booking_id = :b ORDER BY id', ['b' => $sara]);
    sjekk('… kontant 800 kr: QR-en stoppes, påmeldingen 1 000 kr (1 350 − 350) og betalt', $s[0] === 200 && $sb['status'] === 'betalt'
        && (int) $sb['belop_ore'] === 100000 && (int) $sb['gjenstander_ore'] === 135000 && (int) $sb['kasse_rabatt_ore'] === 35000
        && Booking::betalingerFor($sara)['sum'] === 100000, $tekst($s) . ' ' . json_encode($sb));
    sjekk('… den første endringen (QR-en) er erstattet, den nye står', count($rader) === 2 && $rader[0]['erstattet_at'] !== null
        && $rader[1]['erstattet_at'] === null && (int) $rader[1]['rabatt_ore'] === 20000 && (int) $rader[1]['pris_ore'] === 15000, json_encode($rader));
    // Gjenstandene slås inn på nytt i admin: rabatten står.
    PopPris::kassa($sara, [['nivaaId' => $niv['Liten']['id'], 'gjenstand' => '', 'antall' => 1], ['nivaaId' => $niv['Stor']['id'], 'gjenstand' => '', 'antall' => 1]], $monica);
    $sb = DB::en('SELECT status, belop_ore FROM bookings WHERE id = :i', ['i' => $sara]);
    sjekk('… gjenstandene slått inn på nytt i admin: fortsatt 1 000 kr og betalt', (int) $sb['belop_ore'] === 100000 && $sb['status'] === 'betalt', json_encode($sb));
    $oppg = $oppgjor();
    $saras = array_values(array_filter($oppg['endringer'], static fn($e) => $e['kunde'] === $tag . ' Sara'));
    sjekk('Dagens oppgjør: én endring for Sara (Bursdag), ikke QR-en som ikke ble betalt', count($saras) === 1 && $saras[0]['hvorfor'] === 'Bursdag',
        json_encode($saras, JSON_UNESCAPED_UNICODE));

    // Uten migrasjon 269: kassa virker, bare pris og rabatt sier fra.
    DB::kjor('RENAME TABLE kasse_justeringer TO kasse_justeringer_test_borte');
    try {
        $s = $regn($v1 + ['rabatt' => ['prosent' => 10]]);
        sjekk('uten migrasjon 269: rabatt gir «Trykk ⚙ Kjør oppdateringer» (503)', $s[0] === 503 && ($s[1]['feil'] ?? '') === KasseJustering::MANGLER, $tekst($s));
        $s = $regn($v1);
        sjekk('… og vanlig salg virker som før', $s[0] === 200 && ($s[1]['sumOre'] ?? 0) === 29000, $tekst($s));
        $s = kall($K, null, $kasseToken);
        sjekk('… og «I dag» virker', $s[0] === 200 && count($mine($s[1]['kurs'] ?? [])) > 0, $tekst($s));
    } finally {
        DB::kjor('RENAME TABLE kasse_justeringer_test_borte TO kasse_justeringer');
    }

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
echo "\n" . ($feil === 0 ? "$ok av $ok kontroller bestått (enkel kasse)\n" : "$feil av " . ($ok + $feil) . " kontroller FEILET (enkel kasse)\n");
exit($feil === 0 ? 0 : 1);
