<?php
/**
 * «Ta betalt» i ny admin → kassa med personen og beløpet i kurven (eieren,
 * beslutning 9. oktober 2026).
 *
 * Ny admin åpner /kasse?booking=<id>. Kassa slår opp påmeldingen med
 * GET api/kasse/kasse.php?booking=<id> (kassetilgang og PIN), og kurven
 * regnes med de samme reglene som når personen velges under «Dagens kurs»
 * (KasseKurv::deltakere, person(), deler(); Paint on Pots fra PopPris).
 *
 *   1  dagens kurs: 800 kr i kurven, kontant, betalt i ny admin
 *   2  framtidig dato: 900 kr i kurven, kontant, betalt i ny admin
 *   3  delbetalt: 1 000 kr − 300 kr betalt = 700 kr, kontant, 1 000 kr totalt
 *   4  alt betalt: beskjed, ingenting i kurven
 *   5  Vipps pågår (QR fra kassa): beskjed; QR betalt → alt betalt, betalt i ny admin
 *   6  Paint on Pots fram i tid: 2 × Liten − 200 kr betalt ved booking
 *   7  ikke funnet og ikke tilgang (uten innlogging, medlem, låst kassa)
 *   8  kassa ellers uendret: «Dagens kurs» er bare i dag
 *
 * Ekte endepunkter (PHP-server) mot en isolert testbase, og
 * tests/falsk-vipps.mjs som Vipps. Ingen ekte betaling, e-post eller SMS.
 *   php tests/kasse-ta-betalt.php
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

$tag = 'TABET-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $kurs = []; $ferdig = false;
$bryterFor = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/kasse'");
$logg = sys_get_temp_dir() . '/lissom-tabetalt-' . bin2hex(random_bytes(4)) . '.log';
$ordreFor = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM orders');
$betalingFor = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM payments');

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, &$prosesser, $forStyr, $sett, $bryterFor, $ordreFor, $betalingFor): void {
    foreach ($forStyr as $n => $v) { $sett($n, $v); }
    foreach ($prosesser as $p) { if (is_resource($p)) { proc_terminate($p); } }
    try {
        if ($bryterFor === null) { DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/kasse'"); }
        else { DB::kjor("UPDATE content_blocks SET verdi = :v WHERE nokkel = 'Vis/kasse'", ['v' => (string) $bryterFor]); }
    } catch (Throwable $e) {}
    $k = implode(',', array_map('intval', $kurs ?: [0]));
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $b = implode(',', array_map('intval', array_column(DB::alle("SELECT id FROM bookings WHERE course_id IN ($k)"), 'id')) ?: [0]);
    $o = implode(',', array_map('intval', array_column(DB::alle('SELECT id FROM orders WHERE id > :i', ['i' => $ordreFor]), 'id')) ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id IN ($b)",
        "DELETE FROM notifications WHERE ref_type = 'order' AND ref_id IN ($o)",
        "DELETE FROM notifications WHERE ref_type = 'payment' AND ref_id > " . $betalingFor,
        "DELETE FROM pop_kasselinjer WHERE booking_id IN ($b)",
        "DELETE FROM kasse_justeringer WHERE booking_id IN ($b) OR order_id IN ($o) OR registrert_av IN ($m)",
        "UPDATE bookings SET payment_id = NULL WHERE id IN ($b)",
        "UPDATE orders SET payment_id = NULL WHERE id IN ($o)",
        "DELETE FROM order_lines WHERE order_id IN ($o)",
        "DELETE FROM gift_cards WHERE payment_id > " . $betalingFor,
        "DELETE FROM payments WHERE booking_id IN ($b) OR order_id IN ($o) OR registrert_av IN ($m) OR member_id IN ($m)",
        "DELETE FROM orders WHERE id IN ($o)",
        "DELETE FROM bookings WHERE id IN ($b)",
        "DELETE FROM course_sessions WHERE course_id IN ($k)",
        "DELETE FROM courses WHERE id IN ($k)",
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

/** @return array{0:int,1:mixed} status, json */
function kall(string $sti, ?array $data, string $token = ''): array
{
    global $port;
    $c = curl_init('http://127.0.0.1:' . $port . $sti);
    $hode = ['Origin: ' . Config::nettsted()];
    if ($token !== '') { $hode[] = 'Cookie: lissom_sesjon=' . $token; }
    $valg = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30];
    if ($data !== null) {
        $hode[] = 'Content-Type: application/json';
        $valg[CURLOPT_POST] = true;
        $valg[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    $valg[CURLOPT_HTTPHEADER] = $hode;
    curl_setopt_array($c, $valg);
    $raa = (string) curl_exec($c);
    $s = [(int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), json_decode($raa, true)];
    curl_close($c);
    return $s;
}
$K = '/api/kasse/kasse.php';
$P = '/api/kasse/pin.php';
$tekst = static fn(array $s): string => $s[0] . ' ' . json_encode($s[1], JSON_UNESCAPED_UNICODE);

$sesjon = static function (int $medlem): string {
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $medlem, 'token_hash' => hash('sha256', $t), 'maate' => 'passord',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3 * 3600)]);
    return $t;
};
$nyttMedlem = static function (array $felt) use (&$medlemmer, $tag): int {
    $id = DB::settInn('members', $felt + ['epost' => strtolower($tag) . '-' . bin2hex(random_bytes(3)) . '@lissom.test', 'status' => 'ingen']);
    $medlemmer[] = $id;
    return $id;
};
$vlogg = __DIR__ . '/.falsk-vipps.jsonl';

try {
    sjekk('HTTP-serveren klar', $klar);
    DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/kasse', 'ja') ON DUPLICATE KEY UPDATE verdi = 'ja'");

    // ── Testdata ───────────────────────────────────────────────────────
    $kasse = $nyttMedlem(['navn' => $tag . ' iPad', 'rolle' => 'kasse', 'brukernavn' => strtolower($tag) . '-ipad',
        'passord_hash' => password_hash('x-' . bin2hex(random_bytes(6)), PASSWORD_DEFAULT)]);
    $monica = $nyttMedlem(['navn' => $tag . ' Monica', 'rolle' => 'medlem', 'brukernavn' => strtolower($tag) . '-monica']);
    $admin = $nyttMedlem(['navn' => $tag . ' Admin', 'rolle' => 'admin', 'brukernavn' => strtolower($tag) . '-admin']);
    $vanlig = $nyttMedlem(['navn' => $tag . ' Medlem', 'rolle' => 'medlem']);
    $pinM = '3' . random_int(100, 999);
    KasseTilgang::settPin($monica, $pinM);
    $kasseToken = $sesjon($kasse);
    kall($P, ['handling' => 'pin', 'pin' => $pinM], $kasseToken);
    $adminToken = $sesjon($admin);

    $oslo = new DateTimeZone('Europe/Oslo');
    $utc = new DateTimeZone('UTC');
    $tid = static fn(string $t): string => (new DateTimeImmutable($t, $oslo))->setTimezone($utc)->format('Y-m-d H:i:s');
    $nyttKurs = static function (string $navn, int $pris, array $mer = []) use (&$kurs, $tag): int {
        $id = DB::settInn('courses', ['slug' => strtolower($tag) . '-' . bin2hex(random_bytes(3)), 'tittel' => $tag . ' ' . $navn,
            'type' => 'kurs', 'pris_ore' => $pris, 'kapasitet' => 10, 'status' => 'publisert'] + $mer);
        $kurs[] = $id;
        return $id;
    };
    $nyOkt = static fn(int $kursId, string $t): int => DB::settInn('course_sessions', ['course_id' => $kursId, 'start_tid' => $tid($t), 'status' => 'planlagt']);
    $nyBooking = static function (int $kursId, int $okt, string $navn, int $belop, string $status = 'reservert', array $mer = []) use ($tag): int {
        return DB::settInn('bookings', $mer + ['course_id' => $kursId, 'course_session_id' => $okt, 'gjest_navn' => $tag . ' ' . $navn,
            'gjest_epost' => strtolower($tag) . '-' . strtolower($navn) . '@example.com', 'antall' => 1, 'belop_ore' => $belop,
            'status' => $status, 'betalt_maate' => $status === 'betalt' ? 'Kontant' : 'Ikke betalt']);
    };
    // Det kassa gjør etter ?booking=: regner kurven med påmeldingen (som når personen velges under «Dagens kurs»).
    $kjop = static function (array $kurv, array $betaler) use ($K, &$kasseToken): array {
        $s = kall($K, ['handling' => 'regn', 'kurv' => $kurv, 'betaler' => $betaler], $kasseToken);
        $deler = $s[1]['deler'] ?? [];
        return ['kurv' => $kurv, 'betaler' => $betaler,
                'nokler' => array_combine(array_column($deler, 'id'), array_map(static fn() => Vipps::uuid(), $deler)) ?: [],
                'forventet' => array_combine(array_column($deler, 'id'), array_column($deler, 'sumOre')) ?: [],
                'regn' => $s];
    };
    $send = static fn(array $k): array => ['kurv' => $k['kurv'], 'betaler' => $k['betaler'], 'nokler' => $k['nokler'], 'forventet' => $k['forventet']];
    // Slik ny admin ser påmeldingen (Kurs-siden og I dag: kalender.php).
    $iAdmin = static function (int $oktId, int $bid, string $start) use ($adminToken, $oslo, $utc): ?array {
        $dato = (new DateTimeImmutable($start, $utc))->setTimezone($oslo)->format('Y-m-d');
        $kal = kall('/api/admin/kalender.php?fra=' . $dato . '&til=' . $dato, null, $adminToken);
        foreach (($kal[1]['hendelser'] ?? []) as $h) {
            if (($h['oktId'] ?? 0) === $oktId) {
                foreach ($h['deltakere'] ?? [] as $d) { if (($d['bookingId'] ?? 0) === $bid) { return $d; } }
            }
        }
        return null;
    };
    $start = static fn(int $okt): string => (string) DB::verdi('SELECT start_tid FROM course_sessions WHERE id = :i', ['i' => $okt]);
    $sum = static fn(int $bid): int => (int) Booking::betalingerFor($bid)['sum'];
    $status = static fn(int $bid): string => (string) DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $bid]);
    $ALT_BETALT = 'Påmeldingen er alt betalt.';
    $VIPPS = 'En Vipps-betaling pågår for denne påmeldingen. Vent til den er ferdig.';

    $idagKurs = $nyttKurs('I dag', 80000);
    $idagOkt = $nyOkt($idagKurs, 'today 18:00');
    $framKurs = $nyttKurs('Fram i tid', 90000);
    $framOkt = $nyOkt($framKurs, '+10 days 12:00');

    // ══ 1: dagens kurs ══════════════════════════════════════════════════
    echo "\n── 1: dagens kurs ──\n";
    $b1 = $nyBooking($idagKurs, $idagOkt, 'Idag', 80000);
    $s = kall($K . '?booking=' . $b1, null, $kasseToken);
    sjekk('?booking= (i dag): 200, personen, 800 kr igjen, i dag', $s[0] === 200 && ($s[1]['bookingId'] ?? 0) === $b1
        && ($s[1]['navn'] ?? '') === $tag . ' Idag' && ($s[1]['skyldigOre'] ?? 0) === 80000 && ($s[1]['idag'] ?? null) === true
        && ($s[1]['oktId'] ?? 0) === $idagOkt, $tekst($s));
    $k = $kjop(['bookingId' => $b1], ['bookingId' => $b1]);
    sjekk('… kurven: 800 kr', $k['regn'][0] === 200 && ($k['forventet']['booking:' . $b1] ?? 0) === 80000 && ($k['regn'][1]['sumOre'] ?? 0) === 80000, $tekst($k['regn']));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $d = $iAdmin($idagOkt, $b1, $start($idagOkt));
    sjekk('… kontant: påmeldingen betalt, 800 kr ført, «Betalt» i ny admin', $s[0] === 200 && $status($b1) === 'betalt' && $sum($b1) === 80000
        && ($d['status'] ?? '') === 'Betalt', $tekst($s) . ' ' . json_encode($d, JSON_UNESCAPED_UNICODE));
    $s = kall($K . '?booking=' . $b1, null, $kasseToken);
    sjekk('… ?booking= igjen: 409 «alt betalt»', $s[0] === 409 && ($s[1]['feil'] ?? '') === $ALT_BETALT, $tekst($s));

    // ══ 2: framtidig dato ═══════════════════════════════════════════════
    echo "\n── 2: framtidig dato ──\n";
    $b2 = $nyBooking($framKurs, $framOkt, 'Fram', 90000);
    $s = kall($K . '?booking=' . $b2, null, $kasseToken);
    sjekk('?booking= (om 10 dager): 200, 900 kr igjen, ikke i dag', $s[0] === 200 && ($s[1]['skyldigOre'] ?? 0) === 90000
        && ($s[1]['idag'] ?? null) === false && ($s[1]['oktId'] ?? 0) === $framOkt, $tekst($s));
    $s = kall($K, ['handling' => 'person', 'bookingId' => $b2], $kasseToken);
    sjekk('… personen i kassa (handling=person): 900 kr skyldig', $s[0] === 200 && ($s[1]['skyldigOre'] ?? 0) === 90000
        && ($s[1]['navn'] ?? '') === $tag . ' Fram', $tekst($s));
    $d = $iAdmin($framOkt, $b2, $start($framOkt));
    sjekk('… «Ikke betalt» i ny admin før betaling', ($d['status'] ?? '') === 'Ikke betalt', json_encode($d, JSON_UNESCAPED_UNICODE));
    $k = $kjop(['bookingId' => $b2], ['bookingId' => $b2]);
    sjekk('… kurven: 900 kr', ($k['forventet']['booking:' . $b2] ?? 0) === 90000 && ($k['regn'][1]['sumOre'] ?? 0) === 90000, $tekst($k['regn']));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $d = $iAdmin($framOkt, $b2, $start($framOkt));
    sjekk('… kontant: betalt, 900 kr ført, «Betalt» i ny admin', $s[0] === 200 && $status($b2) === 'betalt' && $sum($b2) === 90000
        && ($d['status'] ?? '') === 'Betalt', $tekst($s) . ' ' . json_encode($d, JSON_UNESCAPED_UNICODE));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    sjekk('… samme betaling sendt på nytt: ingen ny rad (fortsatt 900 kr)', $sum($b2) === 90000, $tekst($s));

    // ══ 3: delbetalt ════════════════════════════════════════════════════
    echo "\n── 3: delbetalt ──\n";
    $b3 = $nyBooking($framKurs, $framOkt, 'Delbetalt', 100000);
    Booking::manuellBetaling($b3, 30000, 'Kontant', null, null, 'Testdata');
    $s = kall($K . '?booking=' . $b3, null, $kasseToken);
    sjekk('?booking=: 1 000 kr − 300 kr betalt = 700 kr igjen', $s[0] === 200 && ($s[1]['skyldigOre'] ?? 0) === 70000, $tekst($s));
    $k = $kjop(['bookingId' => $b3], ['bookingId' => $b3]);
    sjekk('… kurven: 700 kr (resten)', ($k['forventet']['booking:' . $b3] ?? 0) === 70000 && ($k['regn'][1]['sumOre'] ?? 0) === 70000, $tekst($k['regn']));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $d = $iAdmin($framOkt, $b3, $start($framOkt));
    sjekk('… kontant 700 kr: betalt, 1 000 kr totalt (300 + 700), «Betalt» i ny admin', $s[0] === 200 && $status($b3) === 'betalt'
        && $sum($b3) === 100000 && ($d['status'] ?? '') === 'Betalt', $tekst($s) . ' ' . json_encode($d, JSON_UNESCAPED_UNICODE));

    // ══ 4: alt betalt ═══════════════════════════════════════════════════
    echo "\n── 4: alt betalt ──\n";
    $b4 = $nyBooking($framKurs, $framOkt, 'Betalt', 90000, 'betalt');
    Booking::manuellBetaling($b4, 90000, 'Vipps', null, null, 'Testdata');
    $for = (int) DB::verdi('SELECT COUNT(*) FROM payments');
    $s = kall($K . '?booking=' . $b4, null, $kasseToken);
    sjekk('?booking=: 409 «Påmeldingen er alt betalt.»', $s[0] === 409 && ($s[1]['feil'] ?? '') === $ALT_BETALT, $tekst($s));
    sjekk('… ingenting registrert', (int) DB::verdi('SELECT COUNT(*) FROM payments') === $for && $sum($b4) === 90000);

    // ══ 5: Vipps pågår ══════════════════════════════════════════════════
    echo "\n── 5: Vipps pågår ──\n";
    $sett('.betaling-status', 'CREATED');
    $b5 = $nyBooking($framKurs, $framOkt, 'Vipps', 90000);
    $k = $kjop(['bookingId' => $b5], ['bookingId' => $b5]);
    $s = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $b5] + $send($k), $kasseToken);
    sjekk('QR fra kassa for 900 kr (falsk Vipps)', $s[0] === 200 && ($s[1]['betalt'] ?? true) === false && ($s[1]['qr'] ?? '') !== '', $tekst($s));
    $s = kall($K . '?booking=' . $b5, null, $kasseToken);
    sjekk('… ?booking= mens QR-en venter: 409 «Vipps pågår»', $s[0] === 409 && ($s[1]['feil'] ?? '') === $VIPPS, $tekst($s));
    $s = kall($K . '?booking=' . $b5, null, $adminToken);
    sjekk('… (admin uten PIN: 423 låst)', $s[0] === 423, $tekst($s));
    $sett('.betaling-status', 'AUTHORIZED');
    DB::kjor("UPDATE payments SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE booking_id = :b AND type = 'epayment'", ['b' => $b5]);
    $s = kall($K, ['handling' => 'status', 'poll' => ['bookingId' => $b5]], $kasseToken);
    $d = $iAdmin($framOkt, $b5, $start($framOkt));
    sjekk('… QR betalt: påmeldingen betalt, 900 kr, «Betalt» i ny admin', ($s[1]['betalt'] ?? false) === true && $status($b5) === 'betalt'
        && $sum($b5) === 90000 && ($d['status'] ?? '') === 'Betalt', $tekst($s) . ' ' . json_encode($d, JSON_UNESCAPED_UNICODE));
    $s = kall($K . '?booking=' . $b5, null, $kasseToken);
    sjekk('… ?booking= etterpå: 409 «alt betalt»', $s[0] === 409 && ($s[1]['feil'] ?? '') === $ALT_BETALT, $tekst($s));
    // En Vipps-betaling fra nettsiden (opprettet) på en annen påmelding.
    $b5b = $nyBooking($framKurs, $framOkt, 'Nettvipps', 90000);
    $pV = DB::settInn('payments', ['vipps_reference' => 'TABET-' . bin2hex(random_bytes(6)), 'formal' => 'booking', 'belop_ore' => 90000,
        'status' => 'opprettet', 'idempotency_key' => Vipps::uuid(), 'booking_id' => $b5b]);
    DB::oppdater('bookings', ['payment_id' => $pV], ['id' => $b5b]);
    $s = kall($K . '?booking=' . $b5b, null, $kasseToken);
    sjekk('Vipps fra nettsiden (opprettet): 409 «Vipps pågår»', $s[0] === 409 && ($s[1]['feil'] ?? '') === $VIPPS, $tekst($s));
    $sett('.betaling-status', 'CREATED');

    // ══ 6: Paint on Pots fram i tid ═════════════════════════════════════
    echo "\n── 6: Paint on Pots fram i tid ──\n";
    $niv = array_column(PopPris::nivaer(), null, 'navn');
    $liten = (int) ($niv['Liten']['prisOre'] ?? 0);
    $popKurs = $nyttKurs('Paint on Pots', 10000, ['type' => 'event', 'folger_apningstid' => 1, 'depositum' => 1, 'avbestilling_timer' => 24]);
    $popOkt = $nyOkt($popKurs, '+5 days 16:00');
    $b6 = $nyBooking($popKurs, $popOkt, 'Pop', 20000, 'betalt', ['antall' => 2, 'depositum_ore' => 20000]);
    $p = Booking::manuellBetaling($b6, 20000, 'Kontant', null, null, 'Beløp ved booking');
    DB::oppdater('bookings', ['payment_id' => $p], ['id' => $b6]);
    $s = kall($K . '?booking=' . $b6, null, $kasseToken);
    sjekk('?booking=: 200 (gjenstandene er ikke slått inn)', $s[0] === 200 && ($s[1]['bookingId'] ?? 0) === $b6, $tekst($s));
    $s = kall($K, ['handling' => 'person', 'bookingId' => $b6], $kasseToken);
    sjekk('… personen: Paint on Pots, 200 kr betalt ved booking', $s[0] === 200 && ($s[1]['pop'] ?? false) === true
        && ($s[1]['vedBookingOre'] ?? 0) === 20000, $tekst($s));
    $kurvPop = ['bookingId' => $b6, 'pop' => [['nivaaId' => $niv['Liten']['id'] ?? 0, 'gjenstand' => '', 'antall' => 2]]];
    $k = $kjop($kurvPop, ['bookingId' => $b6]);
    $vent = 2 * $liten - 20000;
    sjekk('… kurven: 2 × Liten (' . ($liten / 100) . ' kr, PopPris) − 200 kr = ' . ($vent / 100) . ' kr', $liten > 0
        && ($k['forventet']['booking:' . $b6] ?? 0) === $vent, $tekst($k['regn']));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $gj = (int) DB::verdi('SELECT gjenstander_ore FROM bookings WHERE id = :i', ['i' => $b6]);
    $d = $iAdmin($popOkt, $b6, $start($popOkt));
    sjekk('… kontant: betalt, ' . (2 * $liten / 100) . ' kr totalt, gjenstandene slått inn, «Betalt» i ny admin', $s[0] === 200
        && $status($b6) === 'betalt' && $sum($b6) === 2 * $liten && $gj === 2 * $liten && ($d['status'] ?? '') === 'Betalt',
        $tekst($s) . ' ' . json_encode($d, JSON_UNESCAPED_UNICODE));
    $s = kall($K . '?booking=' . $b6, null, $kasseToken);
    sjekk('… ?booking= etterpå: 409 «alt betalt»', $s[0] === 409 && ($s[1]['feil'] ?? '') === $ALT_BETALT, $tekst($s));

    // ══ 7: ikke funnet og ikke tilgang ══════════════════════════════════
    echo "\n── 7: ikke funnet og ikke tilgang ──\n";
    $b7 = $nyBooking($framKurs, $framOkt, 'Tilgang', 90000);
    $bAvb = $nyBooking($framKurs, $framOkt, 'Avbestilt', 90000, 'avbestilt');
    foreach (['999999999' => 'finnes ikke', (string) $bAvb => 'avbestilt', '0' => '0', 'abc' => 'ikke et tall'] as $id => $hva) {
        $s = kall($K . '?booking=' . $id, null, $kasseToken);
        sjekk("?booking= ($hva): 404 «Fant ikke påmeldingen.»", $s[0] === 404 && ($s[1]['feil'] ?? '') === 'Fant ikke påmeldingen.', $tekst($s));
    }
    $s = kall($K . '?booking=' . $b7, null);
    sjekk('uten innlogging: 401, ingen opplysninger', $s[0] === 401 && !isset($s[1]['navn']), $tekst($s));
    $s = kall($K . '?booking=' . $b7, null, $sesjon($vanlig));
    sjekk('vanlig medlem: 404, ingen opplysninger', $s[0] === 404 && !isset($s[1]['navn']), $tekst($s));
    $s = kall($K . '?booking=' . $b7, null, $sesjon($kasse));
    sjekk('kassekontoen uten PIN (låst): 423, ingen opplysninger', $s[0] === 423 && !isset($s[1]['navn']), $tekst($s));
    $pinA = '4' . random_int(100, 999);
    KasseTilgang::settPin($admin, $pinA);
    kall($P, ['handling' => 'pin', 'pin' => $pinA], $adminToken);
    $s = kall($K . '?booking=' . $b7, null, $adminToken);
    sjekk('admin med PIN: 200, 900 kr', $s[0] === 200 && ($s[1]['skyldigOre'] ?? 0) === 90000, $tekst($s));
    sjekk('… ingenting er registrert på den', $sum($b7) === 0 && $status($b7) === 'reservert');

    // ══ 8: kassa ellers uendret ═════════════════════════════════════════
    echo "\n── 8: kassa ellers uendret ──\n";
    $b8 = $nyBooking($idagKurs, $idagOkt, 'Liste', 80000);
    $s = kall($K, null, $kasseToken);
    $mine = array_values(array_filter($s[1]['kurs'] ?? [], static fn($x) => str_starts_with((string) $x['tittel'], $tag)));
    sjekk('«Dagens kurs»: bare kurset i dag, ikke kursene fram i tid', $s[0] === 200 && array_column($mine, 'oktId') === [$idagOkt],
        json_encode($mine, JSON_UNESCAPED_UNICODE));
    $s = kall($K . '?okt=' . $framOkt, null, $kasseToken);
    sjekk('?okt= for et kurs fram i tid: 404 som før', $s[0] === 404, $tekst($s));
    $s = kall($K . '?okt=' . $idagOkt, null, $kasseToken);
    sjekk('?okt= for dagens kurs: den som ikke har betalt', $s[0] === 200 && array_column($s[1]['rader'] ?? [], 'bookingId') === [$b8], $tekst($s));

    // ══ 9: varer i kurven når «Ta betalt» kommer (eieren 09.10.2026) ═══
    // Skjermlogikk i admin-ny/kasse.js (nettleserkjøring: se overleveringen); her sjekkes koden.
    echo "\n── 9: varer i kurven først ──\n";
    $js = str_replace("\r\n", "\n", (string) file_get_contents($rot . '/admin-ny/kasse.js'));
    $fra = strpos($js, 'async function taFraAdmin()');
    $fn = $fra === false ? '' : substr($js, $fra, (int) strpos($js, "\n}\n", $fra) - $fra);
    sjekk('kassa spør «Det ligger varer i kurven» med «Ta dem med» og «Fjern dem»', str_contains($fn, "if(salg&&harVarer(salg)){")
        && str_contains($fn, "ark('Det ligger varer i kurven'") && str_contains($fn, "pille('Ta dem med'") && str_contains($fn, "pille('Fjern dem'"));
    sjekk('… «Fjern dem» tømmer kurven før personen legges inn; lukket uten valg = ingenting', str_contains($fn, "if(valg==='fjern'){stopPoll();salg=null;}")
        && str_contains($fn, 'if(!valg||ikkeNaa())return;')
        && strpos($fn, "salg=null;}") < strpos($fn, 'await nyttSalg('));
    sjekk('… «varer» = varer, skrevet beløp, gavekort, Paint on Pots uten booking, timepakke',
        str_contains($js, 'const harVarer=s=>s.varer.size>0||s.fritt.length>0||s.gavekort.length>0||s.popUten.size>0||!!s.timepakke;'));
    sjekk('… tom kurv: spørsmålet vises ikke (bare når harVarer)', substr_count($fn, "'Det ligger varer i kurven'") === 1
        && strpos($fn, 'if(salg&&harVarer(salg))') < strpos($fn, "'Det ligger varer i kurven'"));

    // ══ 10: kontrolløren (opus) 09.10.2026 ══════════════════════════════
    echo "\n── 10: kontrolløren 09.10 ──\n";
    $vippsPost = static fn(): int => count(array_filter(array_map(static fn($l) => json_decode($l, true), is_file($vlogg) ? file($vlogg) : []),
        static fn($x) => ($x['metode'] ?? '') === 'POST' && ($x['sti'] ?? '') === '/epayment/v1/payments'));
    // 1: kassa åpnet med personen, kunden starter Vipps på nettsiden, Monica tar kontant.
    $b10 = $nyBooking($framKurs, $framOkt, 'Dobbel', 90000);
    $s = kall($K . '?booking=' . $b10, null, $kasseToken);
    $k = $kjop(['bookingId' => $b10], ['bookingId' => $b10]);
    sjekk('1: kassa åpnet med personen: 900 kr i kurven', $s[0] === 200 && ($k['forventet']['booking:' . $b10] ?? 0) === 90000, $tekst($k['regn']));
    $pW = DB::settInn('payments', ['vipps_reference' => 'TABET-' . bin2hex(random_bytes(6)), 'formal' => 'booking', 'belop_ore' => 90000,
        'status' => 'opprettet', 'idempotency_key' => Vipps::uuid(), 'booking_id' => $b10]);
    DB::oppdater('bookings', ['payment_id' => $pW], ['id' => $b10]);
    $for = (int) DB::verdi('SELECT COUNT(*) FROM payments');
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    sjekk('1: … kunden starter Vipps på nettsiden, Monica tar kontant: 409 «Vipps pågår», ingen ny rad, fortsatt reservert',
        $s[0] === 409 && ($s[1]['feil'] ?? '') === $VIPPS && (int) DB::verdi('SELECT COUNT(*) FROM payments') === $for
        && $status($b10) === 'reservert' && $sum($b10) === 0, $tekst($s));
    $s = kall($K, ['handling' => 'delt', 'deler' => [['maate' => 'Kontant', 'belop' => '400'], ['maate' => 'Vipps', 'belop' => '500']]] + $send($k), $kasseToken);
    sjekk('1: … delt betaling: 409 «Vipps pågår», ingen ny rad', $s[0] === 409 && ($s[1]['feil'] ?? '') === $VIPPS
        && (int) DB::verdi('SELECT COUNT(*) FROM payments') === $for, $tekst($s));
    $fraV = $vippsPost();
    $s = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $b10] + $send($k), $kasseToken);
    sjekk('1: … Vipps-QR i kassa: 409 «Vipps pågår», ingen ny betaling hos Vipps', $s[0] === 409 && ($s[1]['feil'] ?? '') === $VIPPS
        && $vippsPost() === $fraV && (int) DB::verdi('SELECT COUNT(*) FROM payments') === $for, $tekst($s));
    DB::oppdater('payments', ['status' => 'avbrutt'], ['id' => $pW]);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    sjekk('1: … Vipps på nettsiden avbrutt: kontant 900 kr går, betalt', $s[0] === 200 && $status($b10) === 'betalt' && $sum($b10) === 90000, $tekst($s));
    // Kassas egen QR som venter stoppes fortsatt før kontant (ingen sperre mot seg selv).
    $sett('.betaling-status', 'CREATED');
    $b11 = $nyBooking($framKurs, $framOkt, 'Egenqr', 90000);
    $k = $kjop(['bookingId' => $b11], ['bookingId' => $b11]);
    $s1 = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $b11] + $send($k), $kasseToken);
    $s2 = kall($K, ['handling' => 'qr', 'del' => 'booking:' . $b11] + $send($k), $kasseToken);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    sjekk('1: … kassas egen QR: nytt trykk gir QR igjen, og kontant etterpå stopper QR-en og tar 900 kr', $s1[0] === 200 && $s2[0] === 200
        && $s[0] === 200 && $status($b11) === 'betalt' && $sum($b11) === 90000, $tekst($s1) . ' ' . $tekst($s2) . ' ' . $tekst($s));

    // 2: avlyst økt.
    $avlystOkt = $nyOkt($framKurs, '+12 days 12:00');
    $b12 = $nyBooking($framKurs, $avlystOkt, 'Avlyst', 90000);
    DB::oppdater('course_sessions', ['status' => 'avlyst'], ['id' => $avlystOkt]);
    $for = (int) DB::verdi('SELECT COUNT(*) FROM payments');
    $s = kall($K . '?booking=' . $b12, null, $kasseToken);
    sjekk('2: avlyst økt: ?booking= 404', $s[0] === 404 && ($s[1]['feil'] ?? '') === 'Fant ikke påmeldingen.', $tekst($s));
    $s = kall($K, ['handling' => 'person', 'bookingId' => $b12], $kasseToken);
    $r = kall($K, ['handling' => 'regn', 'kurv' => ['bookingId' => $b12], 'betaler' => ['bookingId' => $b12]], $kasseToken);
    $bt = kall($K, ['handling' => 'betal', 'maate' => 'Kontant', 'kurv' => ['bookingId' => $b12], 'betaler' => ['bookingId' => $b12],
        'nokler' => ['booking:' . $b12 => Vipps::uuid()], 'forventet' => ['booking:' . $b12 => 90000]], $kasseToken);
    sjekk('2: … person, kurv og betal avvises, ingenting registrert', $s[0] >= 400 && $r[0] >= 400 && $bt[0] >= 400
        && (int) DB::verdi('SELECT COUNT(*) FROM payments') === $for && $status($b12) === 'reservert', $tekst($s) . ' ' . $tekst($r) . ' ' . $tekst($bt));

    // 3: «Ta dem med» beholder timepakken (beholdVarer).
    $bv = '';
    if (preg_match('/^function beholdVarer\(g\)\{.*\}$/m', $js, $m)) { $bv = $m[0]; }
    sjekk('3: «Ta dem med» / ny person: timepakken blir med (beholdVarer)', str_contains($bv, 'salg.timepakke=g.timepakke;')
        && str_contains($bv, 'salg.varer=g.varer;') && str_contains($bv, 'salg.gavekort=g.gavekort;'), $bv);

    // 4: «Betalte ikke» (annullere beløp ved booking) bare for Paint on Pots i dag.
    $popFram = $nyBooking($popKurs, $popOkt, 'Popfram', 20000, 'betalt', ['antall' => 1, 'depositum_ore' => 20000]);
    $pF = Booking::manuellBetaling($popFram, 20000, 'Kontant', null, null, 'Beløp ved booking');
    DB::oppdater('bookings', ['payment_id' => $pF], ['id' => $popFram]);
    $s = kall($K, ['handling' => 'person', 'bookingId' => $popFram], $kasseToken);
    sjekk('4: Paint on Pots fram i tid: «Endre» vises ikke (kanEndre false)', $s[0] === 200 && ($s[1]['kanEndre'] ?? null) === false, $tekst($s));
    $s = kall($K, ['handling' => 'betalteIkke', 'bookingId' => $popFram], $kasseToken);
    $rad = DB::en('SELECT status, annullert_at FROM payments WHERE id = :i', ['i' => $pF]);
    sjekk('4: … «Betalte ikke» avvises, beløpet ved booking står (200 kr, ikke annullert)', $s[0] >= 400 && $rad['status'] === 'betalt'
        && $rad['annullert_at'] === null && $sum($popFram) === 20000, $tekst($s) . ' ' . json_encode($rad));
    $popIdagOkt = $nyOkt($popKurs, 'today 17:00');
    $popIdag = $nyBooking($popKurs, $popIdagOkt, 'Popidag', 20000, 'betalt', ['antall' => 1, 'depositum_ore' => 20000]);
    $pI = Booking::manuellBetaling($popIdag, 20000, 'Kontant', null, null, 'Beløp ved booking');
    DB::oppdater('bookings', ['payment_id' => $pI], ['id' => $popIdag]);
    $s = kall($K, ['handling' => 'person', 'bookingId' => $popIdag], $kasseToken);
    sjekk('4: … Paint on Pots i dag: «Endre» vises som før (kanEndre true)', $s[0] === 200 && ($s[1]['kanEndre'] ?? null) === true, $tekst($s));

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
echo "\n" . ($feil === 0 ? "$ok av $ok kontroller bestått (Ta betalt → kassa)\n" : "$feil av " . ($ok + $feil) . " kontroller FEILET (Ta betalt → kassa)\n");
exit($feil === 0 ? 0 : 1);
