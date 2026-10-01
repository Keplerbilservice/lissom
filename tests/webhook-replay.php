<?php
/** Faktisk endpoint over to lokale HTTP-servere med ekte DB og syntetisk Vipps. */
declare(strict_types=1);
require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
$oppsett = krev_testdatabase($rot);
final class Config
{
    public static array $s;
    public static function hent(string $n, mixed $d = null): mixed { return self::$s[$n] ?? $d; }
    public static function krev(string $n): string { return (string) self::$s[$n]; }
}
Config::$s = $oppsett;
require $rot . '/app/lib/db.php';
$mappe = sys_get_temp_dir() . '/lissom-webhook-' . bin2hex(random_bytes(6));
mkdir($mappe); mkdir($mappe . '/api');
copy($rot . '/api/vipps-webhook.php', $mappe . '/api/vipps-webhook.php');
file_put_contents($mappe . '/api/_boot.php', '<?php require ' . var_export(__DIR__ . '/webhook-fixture.php', true) . ';');
$styrFil = $mappe . '/styr.json';
$secret = 'syntetisk-webhook-secret';
$basis = ['secret' => $secret, 'events' => [], 'payment' => ['state' => 'AUTHORIZED',
    'aggregate' => ['authorizedAmount' => ['value' => 100], 'capturedAmount' => ['value' => 100]]]];
function styr(array $s): void { global $styrFil; file_put_contents($styrFil, json_encode($s, JSON_THROW_ON_ERROR)); }
styr($basis);
$servere = []; $porter = []; $betalinger = []; $eventer = [];
function sjekk(string $n, bool $ok): void
{ if (!$ok) { throw new RuntimeException('FEIL: ' . $n); } echo 'OK: ' . $n . "\n"; }
function request(int $port, array $data, bool $riktig = true): CurlHandle
{
    global $secret;
    $kropp = json_encode($data, JSON_THROW_ON_ERROR);
    $hash = base64_encode(hash('sha256', $kropp, true));
    $dato = gmdate('D, d M Y H:i:s') . ' GMT';
    $signatur = base64_encode(hash_hmac('sha256', "POST\n/api/vipps-webhook.php\n" . $dato . ';127.0.0.1:' . $port . ';' . $hash, $riktig ? $secret : 'feil', true));
    $c = curl_init('http://127.0.0.1:' . $port . '/api/vipps-webhook.php');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $kropp, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-ms-date: ' . $dato,
            'x-ms-content-sha256: ' . $hash, 'Authorization: HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=' . $signatur]]);
    return $c;
}
function kall(array $data, bool $riktig = true): array
{
    global $porter;
    $c = request($porter[0], $data, $riktig);
    $body = curl_exec($c); $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return [$status, json_decode((string) $body, true)];
}
function betaling(): string
{
    global $betalinger;
    $ref = 'WH-' . bin2hex(random_bytes(8));
    $betalinger[] = DB::settInn('payments', ['vipps_reference' => $ref, 'belop_ore' => 100, 'formal' => 'ordre',
        'status' => 'venter', 'idempotency_key' => bin2hex(random_bytes(18))]);
    return $ref;
}
function event(string $ref, string $name, string $psp, int $ore = 100, bool $success = true): array
{
    global $eventer;
    $eventer[] = $psp;
    return ['msn' => '123456', 'reference' => $ref, 'name' => $name, 'pspReference' => $psp,
        'amount' => ['value' => $ore, 'currency' => 'NOK'], 'success' => $success, 'timestamp' => gmdate('c')];
}
try {
    for ($i = 0; $i < 2; $i++) {
        $sokkel = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $adresse = stream_socket_get_name($sokkel, false); fclose($sokkel);
        $port = (int) substr($adresse, strrpos($adresse, ':') + 1); $porter[] = $port;
        $env = getenv(); $env['LISSOM_WEBHOOK_ROT'] = $rot; $env['LISSOM_WEBHOOK_STYR'] = $styrFil;
        $p = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $mappe],
            [0 => ['pipe', 'r'], 1 => ['file', $mappe . '/server-' . $i . '.log', 'a'], 2 => ['file', $mappe . '/server-' . $i . '.log', 'a']], $pipes, $rot, $env);
        if (!is_resource($p)) { throw new RuntimeException('Server startet ikke'); }
        fclose($pipes[0]); $servere[] = $p;
        $klar = false;
        for ($vent = 0; $vent < 40; $vent++) { $s = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1); if ($s) { fclose($s); $klar = true; break; } usleep(50000); }
        sjekk('lokal HTTP-server ' . $i . ' svarer', $klar);
    }
    $ref = betaling(); $e = event($ref, 'AUTHORIZED', 'wrong-' . $ref);
    sjekk('feil HMAC avvises', kall($e, false)[0] === 401);
    sjekk('feil HMAC skriver ingen inbox', DB::en('SELECT event_id FROM vipps_webhook_events WHERE event_id=:e', ['e' => $e['pspReference']]) === null);
    styr(array_replace($basis, ['secret' => '']));
    sjekk('manglende hemmelighet avvises', kall($e)[0] === 401);
    sjekk('usignert modus skriver ingen inbox', DB::en('SELECT event_id FROM vipps_webhook_events WHERE event_id=:e', ['e' => $e['pspReference']]) === null);
    styr($basis);
    DB::settInn('vipps_webhook_events', ['event_id' => $e['pspReference'], 'type' => 'AUTHORIZED', 'referanse' => $ref, 'payload' => '{"usignert":true}']);
    sjekk('signert replay over gammel usignert inbox', kall($e)[0] === 200);
    sjekk('replay markerer faktisk behandlet', DB::verdi('SELECT behandlet_at FROM vipps_webhook_events WHERE event_id=:e', ['e' => $e['pspReference']]) !== null);
    $ref = betaling(); $e = event($ref, 'AUTHORIZED', 'retry-' . $ref);
    styr(array_replace($basis, ['fail' => true]));
    sjekk('behandlingsfeil gir 503', kall($e)[0] === 503);
    sjekk('feil blir ikke ferdigbehandlet', DB::verdi('SELECT behandlet_at FROM vipps_webhook_events WHERE event_id=:e', ['e' => $e['pspReference']]) === null);
    styr($basis);
    sjekk('samme ID kan fullfoere etter feil', kall($e)[0] === 200);
    sjekk('vellykket replay nullstiller feil', DB::verdi('SELECT feilmelding FROM vipps_webhook_events WHERE event_id=:e', ['e' => $e['pspReference']]) === null);
    $ref = betaling(); $del = event($ref, 'CAPTURED', 'partial-' . $ref, 60);
    styr(array_replace($basis, ['events' => [$del]]));
    sjekk('offisiell delcapture gir 503 framfor fullt betalt', kall($del)[0] === 503);
    sjekk('delcapture beholder uoppgjort betalingsstatus', DB::verdi('SELECT status FROM payments WHERE vipps_reference=:r', ['r' => $ref]) === 'venter');
    $rest = event($ref, 'CAPTURED', 'rest-' . $ref, 40);
    styr(array_replace($basis, ['events' => [$del, $rest]]));
    sjekk('full capture etter delvis gir korrekt betalt', kall($rest)[0] === 200
        && DB::verdi('SELECT status FROM payments WHERE vipps_reference=:r', ['r' => $ref]) === 'betalt');
    sjekk('delcapture replay fullfoeres naar total er oppgjort', kall($del)[0] === 200);
    $ref = betaling(); $feilet = event($ref, 'CAPTURED', 'failed-' . $ref, 100, false);
    sjekk('feilet operasjon kan kvitteres', kall($feilet)[0] === 200);
    sjekk('success=false endrer ikke betalingsstatus', DB::verdi('SELECT status FROM payments WHERE vipps_reference=:r', ['r' => $ref]) === 'venter');
    $c = event($ref, 'CAPTURED', 'capture-' . $ref);
    $r1 = event($ref, 'REFUNDED', 'r1-' . $ref, 40);
    styr(array_replace($basis, ['events' => [$c, $r1]]));
    sjekk('offisiell partial REFUNDED godtas', kall($r1)[0] === 200);
    sjekk('partial har riktig status/saldo', DB::verdi('SELECT status FROM payments WHERE vipps_reference=:r', ['r' => $ref]) === 'delvis_refundert'
        && (int) DB::verdi('SELECT refundert_ore FROM payments WHERE vipps_reference=:r', ['r' => $ref]) === 40);
    $r2 = event($ref, 'REFUNDED', 'r2-' . $ref, 60);
    sjekk('usynlig psp event krever replay', kall($r2)[0] === 503);
    styr(array_replace($basis, ['events' => [$c, $r1, $r2]]));
    sjekk('ny psp-ID behandles selv med samme reference/name', kall($r2)[0] === 200);
    sjekk('full refusjon etter to psp-events', (int) DB::verdi('SELECT refundert_ore FROM payments WHERE vipps_reference=:r', ['r' => $ref]) === 100);
    $ref = betaling(); $e = event($ref, 'AUTHORIZED', 'parallel-' . $ref);
    styr(array_replace($basis, ['delay' => true]));
    file_put_contents($styrFil . '.calls', '');
    $m = curl_multi_init(); $a = request($porter[0], $e); $b = request($porter[1], $e);
    curl_multi_add_handle($m, $a); curl_multi_add_handle($m, $b);
    do { curl_multi_exec($m, $aktiv); if ($aktiv) { curl_multi_select($m, 0.1); } } while ($aktiv);
    $ja = json_decode((string) curl_multi_getcontent($a), true); $jb = json_decode((string) curl_multi_getcontent($b), true);
    sjekk('parallelle HTTP-kall begge lykkes', curl_getinfo($a, CURLINFO_RESPONSE_CODE) === 200 && curl_getinfo($b, CURLINFO_RESPONSE_CODE) === 200);
    sjekk('ett parallelt kall er deduplisert', (int) ($ja['duplikat'] ?? false) + (int) ($jb['duplikat'] ?? false) === 1);
    sjekk('bare én faktisk behandling ved parallelle servere', count(file($styrFil . '.calls', FILE_IGNORE_NEW_LINES)) === 1);
    curl_multi_remove_handle($m, $a); curl_multi_remove_handle($m, $b); curl_close($a); curl_close($b); curl_multi_close($m);
} finally {
    foreach ($servere as $p) { proc_terminate($p); proc_close($p); }
    foreach (array_unique($eventer) as $e) { DB::kjor('DELETE FROM vipps_webhook_events WHERE event_id=:e', ['e' => $e]); }
    foreach ($betalinger as $id) { DB::kjor('DELETE FROM payments WHERE id=:i', ['i' => $id]); }
    foreach (glob($mappe . '/api/*') ?: [] as $fil) { unlink($fil); } rmdir($mappe . '/api');
    foreach (glob($mappe . '/*') ?: [] as $fil) { unlink($fil); } rmdir($mappe);
}
