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
 *      kvitteringen og i Dagens oppgjør. Eierens regler: prosent bare når
 *      ingenting er betalt (A), endret pris og rabatt ikke på samme kjøp (B).
 *      Kontrolløren (STOPP 96ba278): rabattandelen låses per del (kurs med
 *      QR, så varene med QR / kontant), påmeldingen settes ned først når
 *      betalingen er registrert / QR-en betalt (ubetalt QR og Vipps-nei
 *      etterlater ingen rabatt), ulik stykkpris gir pris på linjesummen,
 *      dobbelttrykk = samme navn og telefon innen 2 minutter. Tåler at
 *      migrasjon 269 ikke er kjørt.
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
    sjekk('… plassen holdes til dagens slutt (ikke betalt), så den ikke blir hengende', $nb !== null && $nb['reservert_til'] !== null
        && $nb['reservert_til'] > gmdate('Y-m-d H:i:s'), json_encode($nb['reservert_til'] ?? null));
    $dobbel = ['handling' => 'nyKunde', 'navn' => $tag . ' Dobbel', 'telefon' => '45000001', 'oktId' => $okter['19:30']];
    $s1 = kall($K, $dobbel, $kasseToken);
    $s = kall($K, $dobbel, $kasseToken);
    sjekk('samme navn og telefon på samme kurs innen 2 minutter (dobbelttrykk): avvises', $s1[0] === 200 && $s[0] === 409
        && str_contains((string) ($s[1]['feil'] ?? ''), 'står alt'), $tekst($s));
    $s = kall($K, ['telefon' => '45000002'] + $dobbel, $kasseToken);
    sjekk('… samme navn, annen telefon: egen plass', $s[0] === 200, $tekst($s));
    DB::kjor('UPDATE bookings SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id = :i', ['i' => (int) ($s1[1]['bookingId'] ?? 0)]);
    $s = kall($K, $dobbel, $kasseToken);
    sjekk('… samme navn og telefon etter 10 minutter: ny plass (ikke et dobbelttrykk)', $s[0] === 200, $tekst($s));
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
    DB::oppdater('products', ['lager' => 20], ['id' => $produkt]);
    $glass = DB::settInn('products', ['tittel' => $tag . ' Glass', 'pris_ore' => 10000, 'lager' => 20, 'status' => 'publisert', 'kategori' => 'Materialer']);
    $ordreFra = static fn(array $k): ?array => DB::en('SELECT o.* FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.idempotency_key = :k',
        ['k' => Kasse::radNokkel((string) ($k['nokler']['ordre'] ?? ''), 1)]);
    $mineEndringer = static fn(): array => array_values(array_filter($oppgjor()['endringer'] ?? [], static fn($e) => ($e['hvem'] ?? '') === $tag . ' Monica'));

    // Endret pris (uten rabatt).
    $k = $kjop(['varer' => [['id' => $produkt, 'antall' => 2]], 'priser' => ['vare:' . $produkt => '200']], []);
    $linje = $k['regn'][1]['deler'][0]['linjer'][0] ?? [];
    sjekk('Leire 2 × 200 kr (endret fra 290) = 400 kr, stykkpris', $k['regn'][0] === 200 && ($k['regn'][1]['sumOre'] ?? 0) === 40000
        && ($linje['fraOre'] ?? 0) === 58000 && ($linje['enhetOre'] ?? 0) === 20000 && ($linje['perStk'] ?? false) === true
        && ($k['regn'][1]['prisEndret'] ?? false) === true, $tekst($k['regn']));
    $oppFor = $oppgjor();
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $ordre = $ordreFra($k);
    $ol = DB::en('SELECT antall, pris_ore FROM order_lines WHERE order_id = :o', ['o' => (int) ($ordre['id'] ?? 0)]);
    $j = DB::en('SELECT * FROM kasse_justeringer WHERE order_id = :o', ['o' => (int) ($ordre['id'] ?? 0)]);
    sjekk('kontant: ordren er 400 kr, varelinja 2 × 200 kr', $s[0] === 200 && (int) ($ordre['sum_ore'] ?? 0) === 40000
        && (int) ($ol['pris_ore'] ?? 0) === 20000 && (int) ($ol['antall'] ?? 0) === 2, $tekst($s));
    sjekk('… lagret og gjort: fra 580 til 400 kr, pris −180, hvem (Monica)', $j !== null && $j['gjort_at'] !== null
        && (int) $j['opprinnelig_ore'] === 58000 && (int) $j['ny_ore'] === 40000 && (int) $j['pris_ore'] === 18000
        && (int) $j['rabatt_ore'] === 0 && (int) $j['registrert_av'] === $monica, json_encode($j, JSON_UNESCAPED_UNICODE));
    $tekstPris = [$tag . ' Leire: pris endret fra ' . KasseKurv::kr(29000)];
    sjekk('… kvitteringen på skjermen og på e-post viser endringen', ($s[1]['endringer'] ?? []) === $tekstPris
        && KasseJustering::kvitteringslinjer((int) ($ordre['id'] ?? 0)) === $tekstPris, $tekst($s));
    sjekk('… Dagens oppgjør: kontant +400 kr', $oppgjor()['kontantOre'] - $oppFor['kontantOre'] === 40000);

    // Rabatt 10 % (uten endret pris).
    $k = $kjop(['varer' => [['id' => $produkt, 'antall' => 2]], 'rabatt' => ['prosent' => 10, 'hvorfor' => 'Fast kunde']], []);
    $d = $k['regn'][1]['deler'][0] ?? [];
    $rabattLinje = array_values(array_filter($d['linjer'] ?? [], static fn($l) => ($l['rabatt'] ?? false) === true))[0] ?? [];
    sjekk('Leire 2 × 290 = 580 kr − 10 % = 522 kr, linja «Rabatt 10 %» −58 kr', ($k['regn'][1]['sumOre'] ?? 0) === 52200
        && ($rabattLinje['tekst'] ?? '') === 'Rabatt 10 %' && ($rabattLinje['ore'] ?? 0) === -5800
        && ($k['regn'][1]['rabatt']['hvorfor'] ?? '') === 'Fast kunde', $tekst($k['regn']));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $ordre = $ordreFra($k);
    $j = DB::en('SELECT * FROM kasse_justeringer WHERE order_id = :o', ['o' => (int) ($ordre['id'] ?? 0)]);
    sjekk('kontant: ordren 522 kr; rabatt 58 kr «10 %», hvorfor og hvem lagret', $s[0] === 200 && (int) ($ordre['sum_ore'] ?? 0) === 52200
        && $j !== null && (int) $j['rabatt_ore'] === 5800 && $j['rabatt_tekst'] === '10 %' && $j['hvorfor'] === 'Fast kunde'
        && ($s[1]['endringer'] ?? []) === ['Rabatt 10 % — −' . KasseKurv::kr(5800)], $tekst($s));
    $min = array_values(array_filter($mineEndringer(), static fn($e) => $e['hvorfor'] === 'Fast kunde'))[0] ?? null;
    sjekk('… Dagens oppgjør viser endringen med hvem og hvorfor', $min !== null && $min['trukketOre'] === 5800
        && $min['fra'] === KasseKurv::kr(58000) && $min['til'] === KasseKurv::kr(52200), json_encode($mineEndringer(), JSON_UNESCAPED_UNICODE));
    $antall = $antallBetalinger();
    kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    sjekk('samme nøkler en gang til: ingen ny betaling, ingen ny endring', $antallBetalinger() === $antall
        && (int) DB::verdi('SELECT COUNT(*) FROM kasse_justeringer WHERE nokkel = :k', ['k' => strtolower((string) $k['nokler']['ordre'])]) === 1);

    // Eierens regel B: endret pris og rabatt ikke på samme kjøp.
    $s = $regn(['varer' => [['id' => $produkt, 'antall' => 1]], 'priser' => ['vare:' . $produkt => '200'], 'rabatt' => ['kr' => '10']]);
    sjekk('regel B: endret pris + rabatt avvises på serveren', $s[0] === 400 && ($s[1]['feil'] ?? '') === KasseKurv::PRIS_OG_RABATT, $tekst($s));
    $s = $regn(['varer' => [['id' => $produkt, 'antall' => 1]], 'priser' => ['vare:' . $produkt => '290'], 'rabatt' => ['kr' => '10']]);
    sjekk('… en «pris» lik den gamle er ingen endring: rabatten går', $s[0] === 200 && ($s[1]['sumOre'] ?? 0) === 28000, $tekst($s));

    // Eierens regel A: prosent bare når ingenting er betalt.
    $b2100 = $bookinger['21:00'];   // 1 020 kr
    Booking::manuellBetaling($b2100, 30000, 'Kontant', null, null, 'Delbetaling (test)');
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['bookingId' => $b2100], 'betaler' => ['bookingId' => $b2100]], $kasseToken);
    sjekk('regel A: 300 kr alt betalt → «prosentKan» er nei', $s[0] === 200 && ($s[1]['prosentKan'] ?? true) === false && ($s[1]['sumOre'] ?? 0) === 72000, $tekst($s));
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['bookingId' => $b2100, 'rabatt' => ['prosent' => 10]], 'betaler' => ['bookingId' => $b2100]], $kasseToken);
    sjekk('… 10 % avvises på serveren', $s[0] === 400 && ($s[1]['feil'] ?? '') === KasseKurv::PROSENT_BETALT, $tekst($s));
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['bookingId' => $sara, 'pop' => [['nivaaId' => $niv['Liten']['id'], 'gjenstand' => '', 'antall' => 1]],
        'rabatt' => ['prosent' => 10]], 'betaler' => ['bookingId' => $sara]], $kasseToken);
    sjekk('… også med depositum (Paint on Pots)', $s[0] === 400 && ($s[1]['feil'] ?? '') === KasseKurv::PROSENT_BETALT, $tekst($s));
    $k = $kjop(['bookingId' => $b2100, 'rabatt' => ['kr' => '100', 'hvorfor' => 'Kom sent']], ['bookingId' => $b2100]);
    sjekk('… kroner går: 1 020 − 300 betalt − 100 = 620 kr', ($k['forventet']['booking:' . $b2100] ?? 0) === 62000, $tekst($k['regn']));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $bb = DB::en('SELECT status, belop_ore, kasse_rabatt_ore FROM bookings WHERE id = :i', ['i' => $b2100]);
    sjekk('… kontant 620 kr: påmeldingen 920 kr, betalt i admin, 100 kr trukket i kassa', $s[0] === 200 && $bb['status'] === 'betalt'
        && (int) $bb['belop_ore'] === 92000 && (int) $bb['kasse_rabatt_ore'] === 10000 && Booking::betalingerFor($b2100)['sum'] === 92000,
        $tekst($s) . ' ' . json_encode($bb));

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
    $b1115 = $bookinger['11:15'];   // 1 030 kr
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['bookingId' => $b1115, 'varer' => [['id' => $produkt, 'antall' => 1]], 'rabatt' => ['kr' => '132']],
        'betaler' => ['bookingId' => $b1115]], $kasseToken);
    $dl = array_column($s[1]['deler'] ?? [], 'sumOre', 'id');
    sjekk('1 030 + 290 kr − 132 kr: 1 188 kr, fordelt 103 + 29 kr', $s[0] === 200 && ($s[1]['sumOre'] ?? 0) === 118800
        && ($dl['booking:' . $b1115] ?? 0) === 92700 && ($dl['ordre'] ?? 0) === 26100, $tekst($s));

    // Kontrollør funn 1: kurs 1 000 + varer 100, rabatt 110 kr. Kurset betales
    // med QR; varene beholder sin andel (10 kr), både med QR og kontant.
    $qrKurs = $nyttKurs('QR-kurs', 100000, 8);
    $qrOkt = $nyOkt($qrKurs, '12:30');
    $kvitt = static function (int $bid) use ($sett, $K, &$kasseToken): array {
        $sett('.betaling-status', 'AUTHORIZED');
        DB::kjor("UPDATE payments SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE booking_id = :b AND type = 'epayment'", ['b' => $bid]);
        return kall($K, ['handling' => 'status', 'poll' => ['bookingId' => $bid]], $kasseToken);
    };
    $vippsBelop = static function (int $fra, string $ref = '') use ($vlogg): array {
        $l = array_map(static fn($x) => json_decode($x, true), array_slice(is_file($vlogg) ? file($vlogg) : [], $fra));
        return array_values(array_map(static fn($x) => (int) ($x['kropp']['amount']['value'] ?? 0), array_filter($l,
            static fn($x) => ($x['metode'] ?? '') === 'POST' && ($x['sti'] ?? '') === '/epayment/v1/payments'
                && ($ref === '' || ($x['kropp']['reference'] ?? '') === $ref))));
    };
    foreach (['QR', 'kontant'] as $nr => $resten) {
        $sett('.betaling-status', 'CREATED');
        $bq = $nyBooking($qrKurs, $qrOkt, 'Funn1-' . $resten, 100000);
        $kurv = ['bookingId' => $bq, 'varer' => [['id' => $glass, 'antall' => 1]], 'rabatt' => ['kr' => '110']];
        $k = $kjop($kurv, ['bookingId' => $bq]);
        sjekk("funn 1 ($resten): 1 000 + 100 − 110 kr = 900 + 90 kr", ($k['forventet']['booking:' . $bq] ?? 0) === 90000
            && ($k['forventet']['ordre'] ?? 0) === 9000, $tekst($k['regn']));
        $fra = is_file($vlogg) ? count(file($vlogg)) : 0;
        $s = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $bq] + $send($k), $kasseToken);
        $bb = DB::en('SELECT belop_ore, kasse_rabatt_ore FROM bookings WHERE id = :i', ['i' => $bq]);
        sjekk("… QR for kurset: Vipps får 900 kr, påmeldingen er urørt (1 000 kr) til den er betalt", $s[0] === 200 && $vippsBelop($fra) === [90000]
            && (int) $bb['belop_ore'] === 100000 && (int) $bb['kasse_rabatt_ore'] === 0, $tekst($s) . ' ' . json_encode($bb));
        $s = $kvitt($bq);
        $bb = DB::en('SELECT status, belop_ore, kasse_rabatt_ore FROM bookings WHERE id = :i', ['i' => $bq]);
        sjekk("… QR betalt: påmeldingen 900 kr og betalt, 100 kr trukket", ($s[1]['betalt'] ?? false) === true && $bb['status'] === 'betalt'
            && (int) $bb['belop_ore'] === 90000 && (int) $bb['kasse_rabatt_ore'] === 10000, $tekst($s) . ' ' . json_encode($bb));
        $sett('.betaling-status', 'CREATED');
        if ($resten === 'QR') {
            $fra = is_file($vlogg) ? count(file($vlogg)) : 0;
            $s = kall($K, ['handling' => 'qr', 'del' => 'ordre'] + $send($k), $kasseToken);
            $ref = (string) ($s[1]['poll']['referanse'] ?? '');
            sjekk('… varene med QR: Vipps får 90 kr (andelen står)', $s[0] === 200 && $vippsBelop($fra, $ref) === [9000], $tekst($s));
            $sett('.betaling-status', 'AUTHORIZED');
            DB::kjor('UPDATE payments SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE vipps_reference = :r', ['r' => $ref]);
            $s = kall($K, ['handling' => 'status', 'poll' => ['referanse' => $ref]], $kasseToken);
            $o = DB::en('SELECT o.sum_ore, o.status FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.vipps_reference = :r', ['r' => $ref]);
            $jo = DB::en('SELECT gjort_at, rabatt_ore FROM kasse_justeringer WHERE nokkel = :k', ['k' => strtolower($k['nokler']['ordre'])]);
            sjekk('… betalt: ordren 90 kr, endringen gjort', ($s[1]['betalt'] ?? false) === true && (int) ($o['sum_ore'] ?? 0) === 9000
                && ($o['status'] ?? '') === 'betalt' && $jo !== null && $jo['gjort_at'] !== null && (int) $jo['rabatt_ore'] === 1000, $tekst($s));
        } else {
            $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
            $o = $ordreFra($k);
            sjekk('… varene kontant: 90 kr (andelen står, ingen 409)', $s[0] === 200 && (int) ($o['sum_ore'] ?? 0) === 9000, $tekst($s));
        }
    }

    // Kontrollør funn 3: en QR som ikke blir betalt, eller som Vipps sier nei
    // til, etterlater ingen rabatt.
    $sett('.betaling-status', 'CREATED');
    $bu = $nyBooking($qrKurs, $qrOkt, 'Ubetalt', 100000);
    $k = $kjop(['bookingId' => $bu, 'rabatt' => ['kr' => '100']], ['bookingId' => $bu]);
    $s = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $bu] + $send($k), $kasseToken);
    $bb = DB::en('SELECT belop_ore, kasse_rabatt_ore FROM bookings WHERE id = :i', ['i' => $bu]);
    sjekk('funn 3: QR med rabatt vises, ikke betalt: påmeldingen urørt (1 000 kr, ingen rabatt)', $s[0] === 200
        && (int) $bb['belop_ore'] === 100000 && (int) $bb['kasse_rabatt_ore'] === 0, $tekst($s) . ' ' . json_encode($bb));
    $k2 = $kjop(['bookingId' => $bu], ['bookingId' => $bu]);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k2), $kasseToken);
    $bb = DB::en('SELECT status, belop_ore, kasse_rabatt_ore FROM bookings WHERE id = :i', ['i' => $bu]);
    sjekk('… forlatt og så tatt kontant uten rabatt: 1 000 kr, ingen rabatt igjen', $s[0] === 200 && ($k2['forventet']['booking:' . $bu] ?? 0) === 100000
        && $bb['status'] === 'betalt' && (int) $bb['belop_ore'] === 100000 && (int) $bb['kasse_rabatt_ore'] === 0, $tekst($s) . ' ' . json_encode($bb));
    $bn = $nyBooking($qrKurs, $qrOkt, 'Vipps-nei', 100000);
    $k = $kjop(['bookingId' => $bn, 'rabatt' => ['kr' => '100']], ['bookingId' => $bn]);
    $sett('.qr-400', 'ja');
    $s = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $bn] + $send($k), $kasseToken);
    $sett('.qr-400', null);
    $bb = DB::en('SELECT belop_ore, kasse_rabatt_ore FROM bookings WHERE id = :i', ['i' => $bn]);
    sjekk('… Vipps sier nei til QR-en: ingen rabatt på påmeldingen', ($s[1]['ok'] ?? true) === false
        && (int) $bb['belop_ore'] === 100000 && (int) $bb['kasse_rabatt_ore'] === 0, $tekst($s) . ' ' . json_encode($bb));
    sjekk('… og ingen av dem står i Dagens oppgjør', array_filter($mineEndringer(), static fn($e) => in_array($e['kunde'], [$tag . ' Ubetalt', $tag . ' Vipps-nei'], true)) === []);

    // Paint on Pots: endret pris på Stor med QR som ikke blir betalt, så
    // «Tilbake» og rabatt i kroner i stedet, kontant.
    $sett('.betaling-status', 'CREATED');
    $kurvSara = ['bookingId' => $sara, 'pop' => [['nivaaId' => $niv['Liten']['id'], 'gjenstand' => '', 'antall' => 1],
        ['nivaaId' => $niv['Stor']['id'], 'gjenstand' => '', 'antall' => 1]]];
    $storNokkel = 'booking:' . $sara . ':' . $niv['Stor']['id'] . '|';
    $k = $kjop($kurvSara + ['priser' => [$storNokkel => '700']], ['bookingId' => $sara]);
    $stor = array_values(array_filter($k['regn'][1]['deler'][0]['linjer'] ?? [], static fn($l) => ($l['nokkel'] ?? '') === $storNokkel))[0] ?? [];
    sjekk('Sara: Liten 500 + Stor 700 (fra 850) − 200 depositum = 1 000 kr', ($k['forventet']['booking:' . $sara] ?? 0) === 100000
        && ($stor['fraOre'] ?? 0) === 85000 && ($stor['ore'] ?? 0) === 70000, $tekst($k['regn']));
    $fra = is_file($vlogg) ? count(file($vlogg)) : 0;
    $s = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $sara] + $send($k), $kasseToken);
    sjekk('… QR: Vipps får 1 000 kr; påmeldingen har ingen rabatt ennå', $s[0] === 200 && $vippsBelop($fra) === [100000]
        && (int) DB::verdi('SELECT kasse_rabatt_ore FROM bookings WHERE id = :i', ['i' => $sara]) === 0, $tekst($s));
    $k2 = $kjop($kurvSara + ['rabatt' => ['kr' => '150', 'hvorfor' => 'Bursdag']], ['bookingId' => $sara]);
    sjekk('… Tilbake, rabatt 150 kr i stedet: 1 150 − 150 = 1 000 kr', ($k2['forventet']['booking:' . $sara] ?? 0) === 100000, $tekst($k2['regn']));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k2), $kasseToken);
    $sb = DB::en('SELECT status, belop_ore, gjenstander_ore, kasse_rabatt_ore FROM bookings WHERE id = :i', ['i' => $sara]);
    $rader = DB::alle('SELECT nokkel, gjort_at, rabatt_ore, pris_ore FROM kasse_justeringer WHERE booking_id = :b ORDER BY id', ['b' => $sara]);
    sjekk('… kontant 1 000 kr: QR-en stoppes, påmeldingen 1 200 kr (1 350 − 150) og betalt', $s[0] === 200 && $sb['status'] === 'betalt'
        && (int) $sb['belop_ore'] === 120000 && (int) $sb['gjenstander_ore'] === 135000 && (int) $sb['kasse_rabatt_ore'] === 15000
        && Booking::betalingerFor($sara)['sum'] === 120000, $tekst($s) . ' ' . json_encode($sb));
    sjekk('… prisendringen fra QR-en ble aldri gjort; bare rabatten står', count($rader) === 2 && $rader[0]['gjort_at'] === null
        && $rader[1]['gjort_at'] !== null && (int) $rader[1]['rabatt_ore'] === 15000 && (int) $rader[1]['pris_ore'] === 0, json_encode($rader));
    PopPris::kassa($sara, [['nivaaId' => $niv['Liten']['id'], 'gjenstand' => '', 'antall' => 1], ['nivaaId' => $niv['Stor']['id'], 'gjenstand' => '', 'antall' => 1]], $monica);
    $sb = DB::en('SELECT status, belop_ore FROM bookings WHERE id = :i', ['i' => $sara]);
    sjekk('… gjenstandene slått inn på nytt i admin: fortsatt 1 200 kr og betalt', (int) $sb['belop_ore'] === 120000 && $sb['status'] === 'betalt', json_encode($sb));
    $saras = array_values(array_filter($mineEndringer(), static fn($e) => $e['kunde'] === $tag . ' Sara'));
    sjekk('Dagens oppgjør: én endring for Sara (Bursdag), ikke QR-en som ikke ble betalt', count($saras) === 1 && $saras[0]['hvorfor'] === 'Bursdag',
        json_encode($saras, JSON_UNESCAPED_UNICODE));

    // Kontrollør funn 6: gjenstander med ulik pris (én slått inn før til 500,
    // nivået koster nå 550): ingen avrundet stykkpris; prisen gjelder linjesummen.
    $mia = $nyBooking($popKurs, $popOkt, 'Mia', 10000, 'betalt', ['antall' => 1, 'depositum_ore' => 10000]);
    DB::oppdater('bookings', ['payment_id' => Booking::manuellBetaling($mia, 10000, 'Kontant', null, null, 'Beløp ved booking')], ['id' => $mia]);
    PopPris::kassa($mia, [['nivaaId' => $niv['Liten']['id'], 'gjenstand' => '', 'antall' => 1]], $monica);
    DB::oppdater('pop_prisnivaer', ['pris_ore' => 55000], ['id' => $niv['Liten']['id']]);
    try {
        $kurvMia = ['bookingId' => $mia, 'pop' => [['nivaaId' => $niv['Liten']['id'], 'gjenstand' => '', 'antall' => 2]]];
        $miaNokkel = 'booking:' . $mia . ':' . $niv['Liten']['id'] . '|';
        $s = kall($K, ['handling' => 'regn', 'kurv' => $kurvMia, 'betaler' => ['bookingId' => $mia]], $kasseToken);
        $l = $s[1]['deler'][0]['linjer'][0] ?? [];
        sjekk('funn 6: Liten × 2 til 500 + 550: linja 1 050 kr, ingen stykkpris (perStk nei)', $s[0] === 200 && ($l['ore'] ?? 0) === 105000
            && ($l['perStk'] ?? true) === false && ($l['enhetOre'] ?? 0) === 105000, $tekst($s));
        $s = kall($K, ['handling' => 'regn', 'kurv' => $kurvMia + ['priser' => [$miaNokkel => '900']], 'betaler' => ['bookingId' => $mia]], $kasseToken);
        $l = $s[1]['deler'][0]['linjer'][0] ?? [];
        sjekk('… pris 900 gjelder linjesummen: 900 kr (fra 1 050), 900 − 100 = 800 kr', $s[0] === 200 && ($l['ore'] ?? 0) === 90000
            && ($l['fraOre'] ?? 0) === 105000 && ($s[1]['sumOre'] ?? 0) === 80000, $tekst($s));
    } finally {
        DB::oppdater('pop_prisnivaer', ['pris_ore' => 50000], ['id' => $niv['Liten']['id']]);
    }

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
