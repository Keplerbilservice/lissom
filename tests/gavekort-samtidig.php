<?php
/**
 * L-4 fra pengeflyt-revisjonen (eieren, 2. oktober 2026): gavekort i admin
 * trekkes under laas, i samme transaksjon som betalingen lagres.
 *
 * To oppgjoer à 50 000 oere paa et kort med 50 000, samtidig, mot to
 * separate PHP-servere (ekte endepunkter, isolert testbase). Nøyaktig ett
 * skal lykkes; saldoen blir 0 og aldri negativ, og det finnes bare én
 * betaling med kortet. Kjores for kassa (api/admin/uttak.php, salg over
 * disk) og paamelding (api/admin/pamelding.php, legg til med gavekort).
 *
 * Ingen Vipps, ingen e-post. Kjor:  php tests/gavekort-samtidig.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

$servere = []; $porter = []; $kortene = []; $admin = 0; $kurs = 0; $okt = 0;
$tag = 'SAMT-' . strtoupper(bin2hex(random_bytes(3)));
$logg = sys_get_temp_dir() . '/lissom-samtidig-' . bin2hex(random_bytes(4)) . '.log';

function parallelt(array $kall): array
{
    $m = curl_multi_init(); $h = [];
    foreach ($kall as [$port, $sti, $data, $token]) {
        $c = curl_init('http://127.0.0.1:' . $port . $sti);
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . Config::nettsted(),
            'Cookie: lissom_sesjon=' . $token]]);
        curl_multi_add_handle($m, $c); $h[] = $c;
    }
    do { curl_multi_exec($m, $aktiv); if ($aktiv) { curl_multi_select($m, 0.05); } } while ($aktiv);
    $ut = [];
    foreach ($h as $c) {
        $ut[] = [curl_getinfo($c, CURLINFO_RESPONSE_CODE), json_decode((string) curl_multi_getcontent($c), true)];
        curl_multi_remove_handle($m, $c); curl_close($c);
    }
    curl_multi_close($m);
    return $ut;
}
function nyttKort(string $kode): int
{
    global $kortene;
    $k = DB::settInn('gift_cards', ['kode' => $kode, 'opprinnelig_ore' => 50000, 'saldo_ore' => 50000,
        'gyldig_til' => gmdate('Y-m-d', time() + 365 * 86400), 'status' => 'aktivt']);
    $kortene[] = $k; return $k;
}

try {
    $admin = DB::settInn('members', ['navn' => 'Samtidig Admin', 'epost' => strtolower($tag) . '@lissom.test', 'rolle' => 'admin']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token), 'maate' => 'passord',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $kurs = DB::settInn('courses', ['slug' => strtolower($tag), 'tittel' => 'Samtidig kurs', 'type' => 'kurs',
        'pris_ore' => 50000, 'kapasitet' => 40, 'status' => 'publisert']);
    $okt = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => gmdate('Y-m-d H:i:s', time() + 10 * 86400), 'kapasitet' => 40]);

    for ($i = 0; $i < 2; $i++) {
        $s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
        $port = (int) substr($adr, strrpos($adr, ':') + 1); $porter[] = $port;
        $p = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot],
            [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pipes, $rot);
        fclose($pipes[0]); $servere[] = $p;
        $klar = false; for ($v = 0; $v < 40; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }
        sjekk('HTTP-server ' . $i . ' klar', $klar);
    }

    foreach ([1, 2, 3] as $runde) {
        echo "\n── Kassa, runde $runde: to salg à 50000 paa kort med 50000 ──\n";
        $kode = $tag . '-K' . $runde; $k = nyttKort($kode);
        $kropp = ['handling' => 'delt', 'slag' => 'produkt', 'tittel' => 'Samtidig salg',
            'deler' => [['maate' => 'Gavekort', 'belop' => '500']], 'gavekortKode' => $kode];
        $svar = parallelt([[$porter[0], '/api/admin/uttak.php', $kropp, $token], [$porter[1], '/api/admin/uttak.php', $kropp, $token]]);
        $lyktes = count(array_filter($svar, static fn($s) => $s[0] === 200 && ($s[1]['ok'] ?? false) === true));
        $saldo = (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :k', ['k' => $k]);
        $bet = (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE gavekort_id = :k', ['k' => $k]);
        $uttak = (int) DB::verdi('SELECT COALESCE(SUM(belop_ore),0) FROM gift_card_uses WHERE gift_card_id = :k', ['k' => $k]);
        sjekk('nøyaktig ett salg lykkes', $lyktes === 1, json_encode(array_column($svar, 0)) . ' ' . json_encode(array_column($svar, 1), JSON_UNESCAPED_UNICODE));
        sjekk('saldo 0, aldri negativ; én betaling; uttak 50000', $saldo === 0 && $bet === 1 && $uttak === 50000, "saldo $saldo, betalinger $bet, uttak $uttak");
    }

    foreach ([1, 2, 3] as $runde) {
        echo "\n── Påmelding, runde $runde: to plasser à 50000 paa kort med 50000 ──\n";
        $kode = $tag . '-P' . $runde; $k = nyttKort($kode);
        $lag = static fn(string $navn): array => ['handling' => 'legg-til', 'oktId' => $okt, 'navn' => $navn, 'antall' => 1,
            'betaltMaate' => 'Gavekort', 'kode' => $kode];
        $svar = parallelt([[$porter[0], '/api/admin/pamelding.php', $lag("Samtidig A$runde"), $token],
                           [$porter[1], '/api/admin/pamelding.php', $lag("Samtidig B$runde"), $token]]);
        $lyktes = count(array_filter($svar, static fn($s) => $s[0] === 200 && ($s[1]['ok'] ?? false) === true));
        $saldo = (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :k', ['k' => $k]);
        $bet = (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE gavekort_id = :k', ['k' => $k]);
        $plasser = (int) DB::verdi("SELECT COUNT(*) FROM bookings WHERE course_session_id = :o AND gjest_navn IN (:a, :b)",
            ['o' => $okt, 'a' => "Samtidig A$runde", 'b' => "Samtidig B$runde"]);
        sjekk('nøyaktig én påmelding lykkes', $lyktes === 1, json_encode(array_column($svar, 0)) . ' ' . json_encode(array_column($svar, 1), JSON_UNESCAPED_UNICODE));
        sjekk('saldo 0, aldri negativ; én betaling; én plass lagt inn', $saldo === 0 && $bet === 1 && $plasser === 1, "saldo $saldo, betalinger $bet, plasser $plasser");
    }

    foreach ([1, 2, 3] as $runde) {
        echo "\n── Påmelding, dobbelttrykk $runde: «betalt med gavekort» to ganger på én plass ──\n";
        // Kortet har 100000, plassen koster 50000: saldoen alene stopper ikke
        // et dobbelt trekk — det maa sperren paa plassen gjore.
        $kode = $tag . '-D' . $runde;
        $k = nyttKort($kode);
        DB::oppdater('gift_cards', ['opprinnelig_ore' => 100000, 'saldo_ore' => 100000], ['id' => $k]);
        $bid = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $okt, 'gjest_navn' => "Dobbel $runde",
            'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert']);
        $kropp = ['handling' => 'status', 'id' => $bid, 'status' => 'betalt', 'maate' => 'Gavekort', 'kode' => $kode];
        $svar = parallelt([[$porter[0], '/api/admin/pamelding.php', $kropp, $token], [$porter[1], '/api/admin/pamelding.php', $kropp, $token]]);
        $lyktes = count(array_filter($svar, static fn($s) => $s[0] === 200 && ($s[1]['ok'] ?? false) === true));
        $saldo = (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :k', ['k' => $k]);
        $bet = (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE gavekort_id = :k', ['k' => $k]);
        sjekk('nøyaktig ett trykk lykkes', $lyktes === 1, json_encode(array_column($svar, 0)) . ' ' . json_encode(array_column($svar, 1), JSON_UNESCAPED_UNICODE));
        sjekk('ett trekk: saldo 50000, én betaling', $saldo === 50000 && $bet === 1, "saldo $saldo, betalinger $bet");
    }
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage() . ' @ ' . $e->getLine());
} finally {
    foreach ($servere as $p) { proc_terminate($p); proc_close($p); }
    foreach ($kortene as $k) {
        $ider = array_column(DB::alle('SELECT id FROM payments WHERE gavekort_id = :k', ['k' => $k]), 'id');
        DB::kjor('DELETE FROM gift_card_uses WHERE gift_card_id = :k', ['k' => $k]);
        foreach ($ider as $pid) {
            $o = DB::verdi('SELECT order_id FROM payments WHERE id = :p', ['p' => $pid]);
            DB::kjor('UPDATE bookings SET payment_id = NULL WHERE payment_id = :p', ['p' => $pid]);
            DB::kjor('UPDATE orders SET payment_id = NULL WHERE payment_id = :p', ['p' => $pid]);
            DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pid]);
            if ($o !== null) {
                DB::kjor('DELETE FROM order_lines WHERE order_id = :o', ['o' => $o]);
                DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'ordre' AND objekt_id = :o", ['o' => $o]);
                DB::kjor('DELETE FROM orders WHERE id = :o', ['o' => $o]);
            }
        }
        DB::kjor('DELETE FROM gift_cards WHERE id = :k', ['k' => $k]);
    }
    if ($okt) {
        foreach (array_column(DB::alle('SELECT id FROM bookings WHERE course_session_id = :o', ['o' => $okt]), 'id') as $b) {
            DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'booking' AND objekt_id = :b", ['b' => $b]);
        }
        DB::kjor('DELETE FROM bookings WHERE course_session_id = :o', ['o' => $okt]);
        DB::kjor('DELETE FROM course_sessions WHERE id = :o', ['o' => $okt]);
    }
    if ($kurs) { DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kurs]); }
    if ($admin) {
        DB::kjor('DELETE FROM audit_log WHERE member_id = :m', ['m' => $admin]);
        DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => $admin]);
        DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $admin]);
    }
    @unlink($logg);
}
echo "\n  $ok ok, $feil feil\n";
exit($feil === 0 ? 0 : 1);
