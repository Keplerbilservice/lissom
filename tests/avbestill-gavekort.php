<?php
/**
 * L-2 fra pengeflyt-revisjonen (eieren, 2. oktober 2026): kunden avbestiller
 * en plass betalt helt eller delvis med gavekort. Gavekortdelen skal tilbake
 * paa kortet etter samme regel som Vipps-delen.
 *
 * Ekte api/avbestill.php over HTTP, isolert testbase, falsk Vipps
 * (tests/avbestill-fixture.php). Alle beloep i oere.
 *
 * Kjor:  php tests/avbestill-gavekort.php
 */
declare(strict_types=1);
require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__); $LISSOM_SECRETS = krev_testdatabase($rot);
require $rot . '/app/config.php'; require $rot . '/app/lib/db.php'; require $rot . '/app/lib/booking.php';
$mappe = sys_get_temp_dir() . '/lissom-avbgave-' . bin2hex(random_bytes(6));
mkdir($mappe); mkdir($mappe . '/api');
copy($rot . '/api/avbestill.php', $mappe . '/api/avbestill.php');
file_put_contents($mappe . '/api/_boot.php', '<?php require ' . var_export(__DIR__ . '/avbestill-fixture.php', true) . ';');
$styrFil = $mappe . '/styr.json'; file_put_contents($styrFil, '{}');
$server = null; $port = 0; $payments = []; $bookings = []; $members = []; $kortene = []; $okter = [];
$course = 0;
$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }
function kall(array $data, string $token): array
{
    global $port;
    $c = curl_init('http://127.0.0.1:' . $port . '/api/avbestill.php');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . Config::nettsted(), 'Cookie: lissom_sesjon=' . $token]]);
    $body = curl_exec($c); $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return [$status, json_decode((string) $body, true)];
}
function vippsKall(): int
{ global $styrFil; return is_file($styrFil . '.calls') ? count(file($styrFil . '.calls')) : 0; }
function saldo(int $k): int { return (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :k', ['k' => $k]); }
/** Et kort med $saldo, og en plass til $pris der $gave tas fra kortet og resten i Vipps. Trekket gjores som i markerBetalt(). */
function plass(int $mid, int $pris, int $gave, int $saldo, int $timerFram, int $kort = 0): array
{
    global $payments, $bookings, $kortene, $course, $okter;
    $okt = DB::settInn('course_sessions', ['course_id' => $course, 'start_tid' => gmdate('Y-m-d H:i:s', time() + $timerFram * 3600 + count($okter) * 60)]);
    $okter[] = $okt;
    $k = $kort;
    if ($k === 0) {
        $k = DB::settInn('gift_cards', ['kode' => 'AVBG-' . strtoupper(bin2hex(random_bytes(4))), 'opprinnelig_ore' => $saldo,
            'saldo_ore' => $saldo, 'gyldig_til' => gmdate('Y-m-d', time() + 365 * 86400), 'status' => 'aktivt']);
        $kortene[] = $k;
    }
    $pid = DB::settInn('payments', ['member_id' => $mid, 'vipps_reference' => 'AVBG-' . bin2hex(random_bytes(8)), 'type' => 'epayment',
        'formal' => 'booking', 'belop_ore' => $pris - $gave, 'status' => 'betalt', 'idempotency_key' => bin2hex(random_bytes(18)),
        'gavekort_id' => $k, 'gavekort_ore' => $gave]);
    $payments[] = $pid;
    $bid = DB::settInn('bookings', ['member_id' => $mid, 'course_id' => $course, 'course_session_id' => $okt, 'payment_id' => $pid,
        'antall' => 1, 'belop_ore' => $pris, 'status' => 'betalt']);
    $bookings[] = $bid;
    DB::oppdater('payments', ['booking_id' => $bid], ['id' => $pid]);
    Booking::trekkGavekort($pid);
    return [$bid, $pid, $k];
}
try {
    $course = DB::settInn('courses', ['slug' => 'avbg-' . bin2hex(random_bytes(6)), 'tittel' => 'Syntetisk kurs', 'type' => 'kurs',
        'pris_ore' => 100000, 'kapasitet' => 20, 'status' => 'publisert']);
    $mid = DB::settInn('members', ['navn' => 'Syntetisk Gavekort', 'epost' => 'avbg-' . bin2hex(random_bytes(6)) . '@example.invalid', 'rolle' => 'medlem']);
    $members[] = $mid; $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $mid, 'token_hash' => hash('sha256', $token), 'maate' => 'passord', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);

    $s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
    $port = (int) substr($adr, strrpos($adr, ':') + 1);
    $env = getenv(); $env['LISSOM_AVBESTILL_ROT'] = $rot; $env['LISSOM_AVBESTILL_STYR'] = $styrFil;
    $server = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $mappe],
        [0 => ['pipe', 'r'], 1 => ['file', $mappe . '/server.log', 'a'], 2 => ['file', $mappe . '/server.log', 'a']], $pipes, $rot, $env);
    fclose($pipes[0]);
    $klar = false; for ($i = 0; $i < 40; $i++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }
    sjekk('HTTP-server klar', $klar);

    echo "\n── avbestill_gavekort_delt ───────────────────────────────────\n";
    // Kurs 100000 (kr 1 000): 40000 fra kort med 50000, 60000 i Vipps. Ti dager foer.
    [$b, $p, $k] = plass($mid, 100000, 40000, 50000, 240);
    sjekk('foer: kortet trukket 40000 (saldo 10000)', saldo($k) === 10000);
    $foer = vippsKall();
    $r = kall(['bookingId' => $b], $token);
    sjekk('avbestilling ti dager foer gaar gjennom', $r[0] === 200, json_encode($r[1]));
    sjekk('… Vipps-delen 60000 refundert i ett kall', vippsKall() === $foer + 1
        && (int) DB::verdi('SELECT refundert_ore FROM payments WHERE id = :p', ['p' => $p]) === 60000);
    sjekk('… gavekortdelen 40000 tilbake (saldo 50000)', saldo($k) === 50000, (string) saldo($k));
    sjekk('… svaret sier 40000 tilbake paa kortet', (int) ($r[1]['gavekortTilbakeOre'] ?? -1) === 40000);
    sjekk('… uttaksraden staar paa 0 (sporet beholdt)', (int) DB::verdi(
        "SELECT belop_ore FROM gift_card_uses WHERE gift_card_id = :k AND ref_type = 'booking' AND ref_id = :b", ['k' => $k, 'b' => $b]) === 0);
    sjekk('… plassen og betalingen refundert', DB::verdi('SELECT status FROM bookings WHERE id = :b', ['b' => $b]) === 'refundert'
        && DB::verdi('SELECT status FROM payments WHERE id = :p', ['p' => $p]) === 'refundert');
    $r = kall(['bookingId' => $b], $token);
    sjekk('nytt forsoek: 409, saldo fortsatt 50000 (ikke 90000)', $r[0] === 409 && saldo($k) === 50000);

    echo "\n── avbestill_kun_gavekort ────────────────────────────────────\n";
    // Kurs 50000 (kr 500) betalt helt med kort paa 80000. Ti dager foer.
    [$b, $p, $k] = plass($mid, 50000, 50000, 80000, 240);
    sjekk('foer: kortet trukket 50000 (saldo 30000)', saldo($k) === 30000);
    $foer = vippsKall();
    $r = kall(['bookingId' => $b], $token);
    sjekk('avbestillingen gaar gjennom', $r[0] === 200, json_encode($r[1]));
    sjekk('… regelen er avbestillingsregelen, ikke «Ingenting var belastet»',
        !str_contains((string) ($r[1]['regel'] ?? ''), 'Ingenting') && str_contains((string) ($r[1]['regel'] ?? ''), 'full refusjon'),
        (string) ($r[1]['regel'] ?? ''));
    sjekk('… hele 50000 tilbake paa kortet (saldo 80000)', saldo($k) === 80000 && (int) ($r[1]['gavekortTilbakeOre'] ?? -1) === 50000);
    sjekk('… ingen Vipps-kall (0 kr i Vipps)', vippsKall() === $foer);
    sjekk('… plassen er avbestilt', DB::verdi('SELECT status FROM bookings WHERE id = :b', ['b' => $b]) === 'avbestilt');

    echo "\n── avbestill_kun_gavekort, sent ──────────────────────────────\n";
    // Samme, men ett doegn foer: regelen gir ingenting tilbake.
    [$b, $p, $k] = plass($mid, 50000, 50000, 80000, 24);
    $r = kall(['bookingId' => $b], $token);
    sjekk('ett doegn foer: avbestilt, ingenting tilbake (saldo 30000)', $r[0] === 200 && saldo($k) === 30000
        && (int) ($r[1]['gavekortTilbakeOre'] ?? -1) === 0);

    // Kontrolloeren: delt oppgjoer i Ta betalt — kontant 30000 + gavekort
    // 20000 paa en plass til 50000, i begge rekkefoelger. bookings.payment_id
    // peker paa den SISTE raden (som settBetaltStatus() gjor).
    foreach (['kontant foerst' => ['kontant', 'gave'], 'gavekort foerst' => ['gave', 'kontant']] as $navn => $rekke) {
        echo "\n── avbestill_delt_kontant_gavekort, $navn ─────────────────\n";
        $okt = DB::settInn('course_sessions', ['course_id' => $course, 'start_tid' => gmdate('Y-m-d H:i:s', time() + 240 * 3600 + count($okter) * 60)]);
        $okter[] = $okt;
        $k = DB::settInn('gift_cards', ['kode' => 'AVBG-' . strtoupper(bin2hex(random_bytes(4))), 'opprinnelig_ore' => 50000,
            'saldo_ore' => 50000, 'gyldig_til' => gmdate('Y-m-d', time() + 365 * 86400), 'status' => 'aktivt']);
        $kortene[] = $k;
        $b = DB::settInn('bookings', ['member_id' => $mid, 'course_id' => $course, 'course_session_id' => $okt,
            'antall' => 1, 'belop_ore' => 50000, 'status' => 'betalt']);
        $bookings[] = $b;
        $siste = 0;
        foreach ($rekke as $del) {
            $felt = ['member_id' => $mid, 'vipps_reference' => 'DELT-' . bin2hex(random_bytes(6)), 'type' => 'manuell',
                'formal' => 'booking', 'status' => 'betalt', 'idempotency_key' => bin2hex(random_bytes(18)), 'booking_id' => $b];
            $felt += $del === 'kontant'
                ? ['belop_ore' => 30000, 'maate' => 'Kontant']
                : ['belop_ore' => 0, 'maate' => 'Gavekort', 'gavekort_id' => $k, 'gavekort_ore' => 20000];
            $siste = DB::settInn('payments', $felt);
            $payments[] = $siste;
            if ($del === 'gave') {
                Booking::trekkGavekort($siste);
            }
        }
        DB::oppdater('bookings', ['payment_id' => $siste], ['id' => $b]);
        sjekk('foer: kortet trukket 20000 (saldo 30000)', saldo($k) === 30000);
        $foer = vippsKall();
        $r = kall(['bookingId' => $b], $token);
        sjekk('avbestilt ti dager foer', $r[0] === 200, json_encode($r[1], JSON_UNESCAPED_UNICODE));
        sjekk('… gavekortdelen 20000 tilbake (saldo 50000)', saldo($k) === 50000 && (int) ($r[1]['gavekortTilbakeOre'] ?? -1) === 20000,
            (string) saldo($k));
        sjekk('… kontantdelen 30000 merkes manuelt (kr 300 tilbake for haand)', ($r[1]['manuelt'] ?? false) === true
            && str_contains((string) ($r[1]['refunderes'] ?? ''), '300'), json_encode($r[1], JSON_UNESCAPED_UNICODE));
        sjekk('… ingen Vipps-kall, plassen avbestilt', vippsKall() === $foer
            && DB::verdi('SELECT status FROM bookings WHERE id = :b', ['b' => $b]) === 'avbestilt');
    }

    echo "\n── avbestilt, saa annullert (Codex P1) ───────────────────────\n";
    // Samme kort paa to plasser, samme beloep: 20000 hver fra et kort paa 100000.
    [$b1, $p1, $k] = plass($mid, 20000, 20000, 100000, 240);
    [$b2, $p2] = plass($mid, 20000, 20000, 100000, 240, $k);
    sjekk('foer: to uttak paa 20000 (saldo 60000)', saldo($k) === 60000);
    $r = kall(['bookingId' => $b1], $token);
    sjekk('plass 1 avbestilt: 20000 tilbake (saldo 80000)', $r[0] === 200 && saldo($k) === 80000);
    sjekk('annullering av samme betaling etterpaa gir 0 (ikke plass 2 sitt uttak)', Booking::angreGavekort($p1) === 0
        && saldo($k) === 80000);
    sjekk('… plass 2 sitt uttak paa 20000 staar urort', (int) DB::verdi(
        "SELECT belop_ore FROM gift_card_uses WHERE gift_card_id = :k AND ref_type = 'booking' AND ref_id = :b", ['k' => $k, 'b' => $b2]) === 20000);
    sjekk('annullering av plass 2 gir sine 20000 (saldo 100000)', Booking::angreGavekort($p2) === 20000 && saldo($k) === 100000);
    // Codex, runde 2: plass 2 registreres paa nytt med samme kort. Den nye
    // betalingen skal trekkes, selv om den annullerte sitt uttak staar paa 0.
    $p3 = DB::settInn('payments', ['member_id' => $mid, 'vipps_reference' => 'AVBG-' . bin2hex(random_bytes(8)), 'type' => 'manuell',
        'formal' => 'booking', 'belop_ore' => 0, 'status' => 'betalt', 'idempotency_key' => bin2hex(random_bytes(18)),
        'gavekort_id' => $k, 'gavekort_ore' => 20000, 'booking_id' => $b2]);
    $payments[] = $p3;
    Booking::trekkGavekort($p3);
    sjekk('ny betaling paa samme plass etter annullering trekkes (saldo 80000)', saldo($k) === 80000, (string) saldo($k));
    Booking::trekkGavekort($p3);
    sjekk('… men bare én gang (saldo 80000)', saldo($k) === 80000);
    sjekk('… og den gamle annullerte gir fortsatt 0', Booking::angreGavekort($p2) === 0 && saldo($k) === 80000);
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage() . ' @ ' . $e->getLine());
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($bookings as $b) {
        DB::kjor("DELETE FROM notifications WHERE ref_type='booking' AND ref_id=:b", ['b' => $b]);
        DB::kjor("DELETE FROM audit_log WHERE objekt_type='booking' AND objekt_id=:b", ['b' => $b]);
    }
    foreach ($payments as $p) {
        DB::kjor("DELETE FROM audit_log WHERE objekt_type='payment' AND objekt_id=:p", ['p' => $p]);
        DB::kjor('DELETE FROM payment_refunds WHERE payment_id=:p', ['p' => $p]);
        DB::kjor('UPDATE payments SET booking_id = NULL, gavekort_id = NULL WHERE id=:p', ['p' => $p]);
    }
    foreach ($bookings as $b) { DB::kjor('DELETE FROM bookings WHERE id=:b', ['b' => $b]); }
    foreach ($payments as $p) { DB::kjor('DELETE FROM payments WHERE id=:p', ['p' => $p]); }
    foreach ($kortene as $k) { DB::kjor('DELETE FROM gift_card_uses WHERE gift_card_id=:k', ['k' => $k]); DB::kjor('DELETE FROM gift_cards WHERE id=:k', ['k' => $k]); }
    foreach ($members as $m) { DB::kjor('DELETE FROM sessions WHERE member_id=:m', ['m' => $m]); DB::kjor('DELETE FROM audit_log WHERE member_id=:m', ['m' => $m]); DB::kjor('DELETE FROM members WHERE id=:m', ['m' => $m]); }
    foreach ($okter as $o) { DB::kjor('DELETE FROM course_sessions WHERE id=:i', ['i' => $o]); }
    if ($course) { DB::kjor('DELETE FROM courses WHERE id=:i', ['i' => $course]); }
    foreach (glob($mappe . '/api/*') ?: [] as $fil) { unlink($fil); } @rmdir($mappe . '/api');
    foreach (glob($mappe . '/*') ?: [] as $fil) { unlink($fil); } @rmdir($mappe);
}
echo "\n  $ok ok, $feil feil\n";
exit($feil === 0 ? 0 : 1);
