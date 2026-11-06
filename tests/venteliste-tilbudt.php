<?php
/**
 * Tilbudt ventelisteplass og betalt beløp (Monica 06.11.2026).
 *
 *   A  oversikt.php: en ventelisterad som er varslet står ikke i Må gjøres
 *      (teller = false, type venteliste_tilbudt) og viser hva varselkøen sier:
 *      «Tilbudt plass <dato> – beskjed sendt (e-post), SMS feilet».
 *      Mens fristen løper, foreslås ikke samme dato til nestemann.
 *      En rad som venter på en annen dato med plass, står fortsatt i Må gjøres.
 *   B  kalender.php: deltakeren har betaltOre = faktisk betalt (betalingen),
 *      ikke listepris; kurs.js viser «Betalt · <beløp>».
 *
 * Ekte endepunkter (php -S med tests/nettleser/ruter.php) mot en isolert testbase. Ingenting sendes.
 *   php tests/venteliste-tilbudt.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
$port = (int) substr($adr, strrpos($adr, ':') + 1);
$logg = sys_get_temp_dir() . '/lissom-vltilbudt-' . bin2hex(random_bytes(4)) . '.log';
$server = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

$tag = 'VLT-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $kurs = []; $ferdig = false;

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, $server): void {
    if (is_resource($server)) { proc_terminate($server); }
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $k = implode(',', array_map('intval', $kurs ?: [0]));
    foreach ([
        "DELETE FROM notifications WHERE ref_type = 'waitlist' AND ref_id IN (SELECT id FROM waitlist WHERE course_id IN ($k))",
        "DELETE FROM waitlist WHERE course_id IN ($k)",
        "UPDATE bookings SET payment_id = NULL WHERE course_id IN ($k)",
        "DELETE FROM payments WHERE booking_id IN (SELECT id FROM bookings WHERE course_id IN ($k))",
        "DELETE FROM bookings WHERE course_id IN ($k)",
        "DELETE FROM course_sessions WHERE course_id IN ($k)",
        "DELETE FROM courses WHERE id IN ($k)",
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m)",
        "DELETE FROM members WHERE id IN ($m)",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $x) { echo '  (opprydding: ' . $x->getMessage() . ")\n"; }
    }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

/** @return array{0:int,1:mixed} */
function kall(string $sti, string $token): array
{
    global $port;
    $c = curl_init('http://127.0.0.1:' . $port . $sti);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Origin: ' . Config::nettsted(), 'Cookie: lissom_sesjon=' . $token]]);
    $raa = (string) curl_exec($c);
    $kode = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$kode, json_decode($raa, true) ?? $raa];
}
$vis = static fn(array $r): string => $r[0] . ' ' . mb_substr(is_string($r[1]) ? $r[1] : json_encode($r[1], JSON_UNESCAPED_UNICODE), 0, 300);

try {
    sjekk('HTTP-serveren klar', $klar);
    $admin = DB::settInn('members', ['navn' => $tag . ' Monica', 'rolle' => 'admin', 'brukernavn' => strtolower($tag) . '-admin',
        'epost' => strtolower($tag) . '-admin@example.com', 'status' => 'ingen']);
    $medlemmer[] = $admin;
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $t), 'maate' => 'passord',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);

    $kid = DB::settInn('courses', ['slug' => strtolower($tag) . '-pop', 'tittel' => 'Paint on Pots ' . $tag, 'type' => 'kurs',
        'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
    $kurs[] = $kid;
    $start = gmdate('Y-m-d H:00:00', time() + 72 * 3600);
    $okt1 = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $start, 'status' => 'planlagt', 'kapasitet' => 8]);
    $okt2 = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => gmdate('Y-m-d H:00:00', time() + 96 * 3600), 'status' => 'planlagt', 'kapasitet' => 8]);

    // Kari ble tilbudt plassen for en time siden: e-post sendt, SMS feilet.
    $varslet = gmdate('Y-m-d H:i:s', time() - 3600);
    $kari = DB::settInn('waitlist', ['course_id' => $kid, 'course_session_id' => $okt1, 'navn' => 'Kari ' . $tag,
        'epost' => strtolower($tag) . '-kari@example.com', 'telefon' => '41234567', 'posisjon' => 1, 'status' => 'varslet',
        'varslet_at' => $varslet, 'frist_at' => gmdate('Y-m-d H:i:s', time() + 23 * 3600)]);
    DB::settInn('notifications', ['mal' => 'venteliste_ledig', 'kanal' => 'epost', 'mottaker' => strtolower($tag) . '-kari@example.com',
        'tekst' => 'test', 'status' => 'sendt', 'ref_type' => 'waitlist', 'ref_id' => $kari, 'created_at' => $varslet, 'sendt_at' => $varslet]);
    DB::settInn('notifications', ['mal' => 'venteliste_ledig', 'kanal' => 'sms', 'mottaker' => '+4741234567',
        'tekst' => 'test', 'status' => 'feilet', 'ref_type' => 'waitlist', 'ref_id' => $kari, 'created_at' => $varslet]);
    // Per står bak Kari på samme dato, og Ola venter på en annen dato.
    $per = DB::settInn('waitlist', ['course_id' => $kid, 'course_session_id' => $okt1, 'navn' => 'Per ' . $tag,
        'epost' => strtolower($tag) . '-per@example.com', 'posisjon' => 2, 'status' => 'venter']);
    $ola = DB::settInn('waitlist', ['course_id' => $kid, 'course_session_id' => $okt2, 'navn' => 'Ola ' . $tag,
        'epost' => strtolower($tag) . '-ola@example.com', 'posisjon' => 1, 'status' => 'venter']);

    echo "\n── A Tilbudt plass i oversikten ─────────────────────────────\n";
    $o = kall('/api/admin/oversikt.php', $t);
    sjekk('oversikt svarer 200', $o[0] === 200 && is_array($o[1]['maGjores'] ?? null), $vis($o));
    $saker = $o[1]['maGjores'] ?? [];
    $finn = static function (string $type, int $id) use (&$saker): ?array { return array_values(array_filter($saker, static fn($s) => ($s['type'] ?? '') === $type && (int) $s['id'] === $id))[0] ?? null; };
    $k = $finn('venteliste_tilbudt', $kari);
    sjekk('Kari står som tilbudt plass', $k !== null, json_encode($saker, JSON_UNESCAPED_UNICODE));
    sjekk('teller ikke i Må gjøres', $k !== null && $k['teller'] === false);
    sjekk('ikke lenger «Plass ledig» for Kari', $finn('venteliste', $kari) === null);
    $oslo = (new DateTimeImmutable($varslet, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Oslo'));
    $venter = 'Tilbudt plass ' . $oslo->format('j.n.') . ' kl. ' . $oslo->format('H.i') . ' – beskjed sendt (e-post), SMS feilet';
    sjekk('teksten sier dato og beskjedstatus', ($k['under'] ?? '') === $venter, (string) ($k['under'] ?? ''));
    sjekk('merket er «Beskjed sendt» grønn', ($k['varselMerke'] ?? '') === 'Beskjed sendt' && ($k['varselFarge'] ?? '') === 'gronn');
    sjekk('raden åpner datoen', (int) ($k['oktId'] ?? 0) === $okt1);
    sjekk('Per foreslås ikke mens Karis frist løper', $finn('venteliste', $per) === null);
    sjekk('Ola (annen dato) står fortsatt i Må gjøres', ($finn('venteliste', $ola)['teller'] ?? null) === true);

    // Ingen beskjed til kunden registrert.
    DB::kjor("DELETE FROM notifications WHERE ref_type = 'waitlist' AND ref_id = :i", ['i' => $kari]);
    $o = kall('/api/admin/oversikt.php', $t);
    $saker = $o[1]['maGjores'] ?? [];
    $k = $finn('venteliste_tilbudt', $kari);
    sjekk('uten varsel: «Ikke sendt» rød', ($k['varselMerke'] ?? '') === 'Ikke sendt' && ($k['varselFarge'] ?? '') === 'rod', json_encode($k, JSON_UNESCAPED_UNICODE));

    // Fristen er ute: Per kan få tilbudet.
    DB::oppdater('waitlist', ['frist_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $kari]);
    $o = kall('/api/admin/oversikt.php', $t);
    $saker = $o[1]['maGjores'] ?? [];
    sjekk('etter fristen foreslås Per', $finn('venteliste', $per) !== null);

    echo "\n── B Betalt beløp på deltakerlisten ─────────────────────────\n";
    $bid = DB::settInn('bookings', ['course_id' => $kid, 'course_session_id' => $okt1, 'gjest_navn' => 'Betalt ' . $tag,
        'gjest_epost' => strtolower($tag) . '-b@example.com', 'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert']);
    $pid = DB::settInn('payments', ['booking_id' => $bid, 'vipps_reference' => $tag . '-P' . bin2hex(random_bytes(4)), 'type' => 'epayment',
        'formal' => 'booking', 'belop_ore' => 65000, 'status' => 'betalt', 'idempotency_key' => bin2hex(random_bytes(18))]);
    DB::oppdater('bookings', ['payment_id' => $pid, 'status' => 'betalt'], ['id' => $bid]);
    $dag = substr($start, 0, 10);
    $r = kall('/api/admin/kalender.php?fra=' . gmdate('Y-m-d', strtotime($start) - 86400) . '&til=' . gmdate('Y-m-d', strtotime($start) + 86400), $t);
    $d = null;
    foreach ($r[1]['hendelser'] ?? [] as $h) { foreach ($h['deltakere'] ?? [] as $x) { if ((int) $x['bookingId'] === $bid) { $d = $x; } } }
    sjekk('kalender svarer med deltakeren', $d !== null, $vis($r));
    sjekk('betaltOre = betalt beløp (650 kr), ikke listepris', (int) ($d['betaltOre'] ?? 0) === 65000 && ($d['status'] ?? '') === 'Betalt', json_encode($d, JSON_UNESCAPED_UNICODE));

    exec('node --check ' . escapeshellarg($rot . '/ny-admin/kurs.js') . ' 2>&1', $u1, $k1);
    exec('node --check ' . escapeshellarg($rot . '/ny-admin/idag.js') . ' 2>&1', $u2, $k2);
    sjekk('node --check kurs.js og idag.js', $k1 === 0 && $k2 === 0);
    $kursJs = (string) file_get_contents($rot . '/ny-admin/kurs.js');
    $idagJs = (string) file_get_contents($rot . '/ny-admin/idag.js');
    sjekk('kurs.js viser «Betalt · beløp» fra betaltOre', str_contains($kursJs, '`<span class="merke gronn">Betalt · ${kr(d.betaltOre)}</span>`'));
    sjekk('idag.js viser tilbudt plass under Til info', str_contains($idagJs, "s.type === 'venteliste_tilbudt' ? radTilbudt(s)"));
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage());
}
$ferdig = true;
echo "\n$ok OK, $feil FEIL\n";
exit($feil === 0 ? 0 : 1);
