<?php
/** Ekte bootstrap/auth/Booking/Varsel, isolert DB og syntetisk Vipps over HTTP. */
declare(strict_types=1);
require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__); $LISSOM_SECRETS = krev_testdatabase($rot);
require $rot . '/app/config.php'; require $rot . '/app/lib/db.php';
$mappe = sys_get_temp_dir() . '/lissom-avbestill-' . bin2hex(random_bytes(6));
mkdir($mappe); mkdir($mappe . '/api'); mkdir($mappe . '/api/admin');
copy($rot . '/api/avbestill.php', $mappe . '/api/avbestill.php');
copy($rot . '/api/admin/betalinger.php', $mappe . '/api/admin/betalinger.php');
file_put_contents($mappe . '/api/_boot.php', '<?php require ' . var_export(__DIR__ . '/avbestill-fixture.php', true) . ';');
$styrFil = $mappe . '/styr.json'; file_put_contents($styrFil, '{}');
$servere = []; $porter = []; $payments = []; $bookings = []; $members = [];
$course = $session = 0; $trigger = 'avb_' . bin2hex(random_bytes(6));
$mal = DB::en("SELECT navn, aktiv FROM notification_templates WHERE navn='avbestilling'");
if ($mal === null) { throw new RuntimeException('Avbestillingsmal mangler i testbasen'); }
function sjekk(string $n, bool $ok): void
{ if (!$ok) { throw new RuntimeException('FEIL: ' . $n); } echo 'OK: ' . $n . "\n"; }
function styr(array $s): void { global $styrFil; file_put_contents($styrFil, json_encode($s)); }
function request(int $port, string $route, array $data, string $token): CurlHandle
{
    $c = curl_init('http://127.0.0.1:' . $port . $route);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . Config::nettsted(), 'Cookie: lissom_sesjon=' . $token]]);
    return $c;
}
function kall(string $route, array $data, string $token): array
{
    global $porter;
    $c = request($porter[0], $route, $data, $token); $body = curl_exec($c); $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return [$status, json_decode((string) $body, true)];
}
function bruker(string $role): array
{
    global $members;
    $id = DB::settInn('members', ['navn' => 'Syntetisk Avbestilling', 'epost' => 'avb-' . bin2hex(random_bytes(6)) . '@example.invalid', 'rolle' => $role]);
    $members[] = $id; $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $id, 'token_hash' => hash('sha256', $token), 'maate' => 'passord', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    return [$id, $token];
}
function booking(int $mid, int $partial = 0): array
{
    global $payments, $bookings, $course, $session;
    $ref = 'AVB-' . bin2hex(random_bytes(8));
    $pid = DB::settInn('payments', ['member_id' => $mid, 'vipps_reference' => $ref, 'type' => 'epayment', 'formal' => 'booking',
        'belop_ore' => 10000, 'refundert_ore' => $partial, 'status' => $partial ? 'delvis_refundert' : 'betalt', 'idempotency_key' => bin2hex(random_bytes(18))]);
    $payments[] = $pid;
    $bid = DB::settInn('bookings', ['member_id' => $mid, 'course_id' => $course, 'course_session_id' => $session, 'payment_id' => $pid,
        'antall' => 1, 'belop_ore' => 10000, 'status' => 'betalt']);
    $bookings[] = $bid; return [$bid, $pid, $ref];
}
function varsler(int $bid): array
{ return DB::alle("SELECT tekst, html, status FROM notifications WHERE ref_type='booking' AND ref_id=:b AND mal='avbestilling' AND kanal='epost'", ['b' => $bid]); }
function ledger(): array
{ global $styrFil; return is_file($styrFil . '.ledger') ? (json_decode((string) file_get_contents($styrFil . '.ledger'), true) ?: []) : []; }
try {
    DB::oppdater('notification_templates', ['aktiv' => 1], ['navn' => $mal['navn']]);
    $course = DB::settInn('courses', ['slug' => 'avb-' . bin2hex(random_bytes(6)), 'tittel' => 'Syntetisk kurs', 'type' => 'kurs', 'pris_ore' => 10000, 'kapasitet' => 20, 'status' => 'publisert']);
    $session = DB::settInn('course_sessions', ['course_id' => $course, 'start_tid' => gmdate('Y-m-d H:i:s', time() + 10 * 86400)]);
    [$mid, $token] = bruker('medlem'); [$admin, $adminToken] = bruker('admin');
    for ($i = 0; $i < 2; $i++) {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $adresse = stream_socket_get_name($s, false); fclose($s);
        $port = (int) substr($adresse, strrpos($adresse, ':') + 1); $porter[] = $port;
        $env = getenv(); $env['LISSOM_AVBESTILL_ROT'] = $rot; $env['LISSOM_AVBESTILL_STYR'] = $styrFil;
        $p = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $mappe],
            [0 => ['pipe', 'r'], 1 => ['file', $mappe . '/server-' . $i . '.log', 'a'], 2 => ['file', $mappe . '/server-' . $i . '.log', 'a']], $pipes, $rot, $env);
        if (!is_resource($p)) { throw new RuntimeException('Kunne ikke starte HTTP-server'); } fclose($pipes[0]); $servere[] = $p;
        $klar = false; for ($vent = 0; $vent < 40; $vent++) { $s = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1); if ($s) { fclose($s); $klar = true; break; } usleep(50000); }
        sjekk('HTTP-server ' . $i . ' klar', $klar);
    }
    [$b, $p, $ref] = booking($mid, 3000); $r = kall('/api/avbestill.php', ['bookingId' => $b], $token);
    sjekk('ekte kundeavbestilling gjennomfoeres', $r[0] === 200 && ($r[1]['manuelt'] ?? true) === false);
    sjekk('tidligere partial begrenser refund til rest', DB::verdi('SELECT amount_ore FROM payment_refunds WHERE payment_id=:p', ['p' => $p]) == 7000);
    sjekk('booking og payment fullstendig refundert', DB::verdi('SELECT status FROM bookings WHERE id=:b', ['b' => $b]) === 'refundert'
        && DB::verdi('SELECT status FROM payments WHERE id=:p', ['p' => $p]) === 'refundert');
    $v = varsler($b); sjekk('ett ekte avbestillingsvarsel i ko', count($v) === 1 && $v[0]['status'] === 'ko' && str_contains($v[0]['tekst'] . $v[0]['html'], 'Vipps'));
    $count = count(ledger()); sjekk('kunde-replay av avbestilt gir 409', kall('/api/avbestill.php', ['bookingId' => $b], $token)[0] === 409);
    sjekk('kunde-replay gir ingen ny refund/varsel', count(ledger()) === $count && count(varsler($b)) === 1);
    [$b, $p, $ref] = booking($mid); styr(['fail' => 'after']);
    $r = kall('/api/avbestill.php', ['bookingId' => $b], $token);
    sjekk('tapt leverandoersvar gir manuelt svar', $r[0] === 200 && ($r[1]['manuelt'] ?? false) === true && str_contains($r[1]['beskjed'] ?? '', 'manuelt'));
    sjekk('manual bevarer claim og pending journal', DB::verdi('SELECT status FROM bookings WHERE id=:b', ['b' => $b]) === 'avbestilt'
        && DB::verdi('SELECT status FROM payment_refunds WHERE payment_id=:p', ['p' => $p]) === 'pending');
    $v = varsler($b); sjekk('manual ko lover manuell oppfoelging', count($v) === 1 && str_contains($v[0]['tekst'] . $v[0]['html'], 'manuelt'));
    $count = count(ledger()); styr([]); $op = bin2hex(random_bytes(16));
    $r = kall('/api/admin/betalinger.php', ['referanse' => $ref, 'belop' => 100, 'operasjonId' => $op], $adminToken);
    sjekk('admin gjenopptar pending kundeoperasjon', $r[0] === 200 && count(ledger()) === $count);
    $r2 = kall('/api/admin/betalinger.php', ['referanse' => $ref, 'belop' => 100, 'operasjonId' => $op], $adminToken);
    sjekk('admin HTTP-replay beholder samme resultat', $r2[0] === 200 && $r2[1] === $r[1] && count(ledger()) === $count);
    [$b, $p, $ref] = booking($mid);
    DB::kjor("CREATE TRIGGER `$trigger` BEFORE INSERT ON payment_refunds FOR EACH ROW BEGIN IF NEW.payment_id = $p THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Syntetisk journalfeil'; END IF; END");
    $r = kall('/api/avbestill.php', ['bookingId' => $b], $token); DB::kjor("DROP TRIGGER `$trigger`");
    sjekk('journalfeil returnerer feil', $r[0] === 500);
    sjekk('journalfeil ruller tilbake bookingclaim', DB::verdi('SELECT status FROM bookings WHERE id=:b', ['b' => $b]) === 'betalt'
        && DB::verdi('SELECT avbestilt_at FROM bookings WHERE id=:b', ['b' => $b]) === null);
    sjekk('rollback gir ingen varsel eller journal', varsler($b) === [] && DB::en('SELECT id FROM payment_refunds WHERE payment_id=:p', ['p' => $p]) === null);
    [$b, $p, $ref] = booking($mid);
    DB::kjor("CREATE TRIGGER `$trigger` BEFORE UPDATE ON payments FOR EACH ROW BEGIN IF NEW.id = $p AND NEW.refundert_ore > OLD.refundert_ore THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Syntetisk lokal oppgjoersfeil'; END IF; END");
    $r = kall('/api/avbestill.php', ['bookingId' => $b], $token); DB::kjor("DROP TRIGGER `$trigger`");
    sjekk('DB-feil etter HTTP gir manual og pending', $r[0] === 200 && ($r[1]['manuelt'] ?? false) === true
        && DB::verdi('SELECT status FROM payment_refunds WHERE payment_id=:p', ['p' => $p]) === 'pending');
    $count = count(ledger()); $r = kall('/api/admin/betalinger.php', ['referanse' => $ref, 'belop' => 100, 'operasjonId' => bin2hex(random_bytes(16))], $adminToken);
    sjekk('retry etter lokal commitfeil gir ingen ny leverandoeroperasjon', $r[0] === 200 && count(ledger()) === $count);
    [$b, $p, $ref] = booking($mid); styr(['delay' => true]);
    $a = request($porter[0], '/api/avbestill.php', ['bookingId' => $b], $token);
    $bb = request($porter[1], '/api/avbestill.php', ['bookingId' => $b], $token);
    $m = curl_multi_init(); curl_multi_add_handle($m, $a); curl_multi_add_handle($m, $bb);
    do { curl_multi_exec($m, $aktiv); if ($aktiv) { curl_multi_select($m, 0.1); } } while ($aktiv);
    $status = [curl_getinfo($a, CURLINFO_RESPONSE_CODE), curl_getinfo($bb, CURLINFO_RESPONSE_CODE)]; sort($status);
    sjekk('parallelle kundeavbestillinger gir en suksess', $status[0] === 200 && in_array($status[1], [409, 500], true));
    sjekk('parallelle kall gir ett intent og ett varsel', DB::verdi('SELECT COUNT(*) FROM payment_refunds WHERE payment_id=:p', ['p' => $p]) == 1 && count(varsler($b)) === 1
        && count(array_filter(array_keys(ledger()), static fn($k) => str_starts_with($k, $ref . ':'))) === 1);
    curl_multi_remove_handle($m, $a); curl_multi_remove_handle($m, $bb); curl_close($a); curl_close($bb); curl_multi_close($m);
    styr([]); [$b, $p, $ref] = booking($mid);
    DB::oppdater('payments', ['status' => 'venter'], ['id' => $p]);
    $count = count(ledger()); $r = kall('/api/avbestill.php', ['bookingId' => $b], $token);
    sjekk('ubekreftet betaling gir manuelt svar uten Vipps-loefte', $r[0] === 200 && ($r[1]['manuelt'] ?? false) === true
        && !str_contains($r[1]['beskjed'] ?? '', 'på vei tilbake'));
    sjekk('ubekreftet betaling sender ingen refund', count(ledger()) === $count && DB::en('SELECT id FROM payment_refunds WHERE payment_id=:p', ['p' => $p]) === null);
    [$b, $p, $ref] = booking($mid); styr(['delay' => true]);
    $a = request($porter[0], '/api/avbestill.php', ['bookingId' => $b], $token);
    $bb = request($porter[1], '/api/admin/betalinger.php', ['referanse' => $ref, 'belop' => 100, 'operasjonId' => bin2hex(random_bytes(16))], $adminToken);
    $m = curl_multi_init(); curl_multi_add_handle($m, $a); curl_multi_add_handle($m, $bb);
    do { curl_multi_exec($m, $aktiv); if ($aktiv) { curl_multi_select($m, 0.1); } } while ($aktiv);
    $status = [curl_getinfo($a, CURLINFO_RESPONSE_CODE), curl_getinfo($bb, CURLINFO_RESPONSE_CODE)]; sort($status);
    sjekk('admin og kunde deler samme betalingslaas', $status[0] === 200 && in_array($status[1], [409, 500, 502], true));
    sjekk('admin/kunde-konkurranse sender ett journalintent', DB::verdi('SELECT COUNT(*) FROM payment_refunds WHERE payment_id=:p', ['p' => $p]) == 1
        && count(array_filter(array_keys(ledger()), static fn($k) => str_starts_with($k, $ref . ':'))) === 1);
    curl_multi_remove_handle($m, $a); curl_multi_remove_handle($m, $bb); curl_close($a); curl_close($bb); curl_multi_close($m);
} finally {
    foreach ($servere as $p) { proc_terminate($p); proc_close($p); }
    DB::kjor("DROP TRIGGER IF EXISTS `$trigger`");
    foreach ($bookings as $b) { DB::kjor("DELETE FROM notifications WHERE ref_type='booking' AND ref_id=:b", ['b' => $b]); DB::kjor("DELETE FROM audit_log WHERE objekt_type='booking' AND objekt_id=:b", ['b' => $b]); DB::kjor('DELETE FROM bookings WHERE id=:b', ['b' => $b]); }
    foreach ($payments as $p) { DB::kjor('DELETE FROM payment_refunds WHERE payment_id=:p', ['p' => $p]); DB::kjor('DELETE FROM payments WHERE id=:p', ['p' => $p]); }
    foreach ($members as $mid) { DB::kjor('DELETE FROM sessions WHERE member_id=:m', ['m' => $mid]); DB::kjor('DELETE FROM audit_log WHERE member_id=:m', ['m' => $mid]); DB::kjor('DELETE FROM members WHERE id=:m', ['m' => $mid]); }
    if ($session) { DB::kjor('DELETE FROM course_sessions WHERE id=:i', ['i' => $session]); }
    if ($course) { DB::kjor('DELETE FROM courses WHERE id=:i', ['i' => $course]); }
    DB::oppdater('notification_templates', ['aktiv' => $mal['aktiv']], ['navn' => $mal['navn']]);
    foreach (glob($mappe . '/api/admin/*') ?: [] as $fil) { unlink($fil); } rmdir($mappe . '/api/admin');
    foreach (glob($mappe . '/api/*') ?: [] as $fil) { unlink($fil); } rmdir($mappe . '/api');
    foreach (glob($mappe . '/*') ?: [] as $fil) { unlink($fil); } rmdir($mappe);
}
