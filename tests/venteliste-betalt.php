<?php
/**
 * Venteliste og betalt beløp i admin (Monica 06.11.2026, eieren sa ja).
 *
 *   A  Har en person på ventelisten booket kurset etter at hen sto seg på lista
 *      (samme e-post eller telefon, betalt/reservert), står hen ikke i
 *      ventelisten: venteliste.php, kalender.php (kurssiden) og «Plass ledig» i
 *      oversikt.php. Uten booking står hen der som før.
 *   B  Booker hen selv (Booking::reserverOgBetal), får ventelisteraden for
 *      kurset status «booket».
 *   C  «Betalt» viser betalt beløp: oversikt (Ny påmelding: betaltOre),
 *      kalender (betaltOre), medlemmets kurshistorikk («Betalt · 650 kr»), og
 *      skjermene i ny admin og kassa.
 *
 * Ekte endepunkter (php -S med tests/nettleser/ruter.php) mot en isolert testbase.
 * Ingenting sendes; Vipps er ikke satt opp, så betalingen startes aldri.
 *   php tests/venteliste-betalt.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
// Vipps peker til en lukket port her: ingen betaling startes, heller ikke mot testmiljøet.
putenv('LISSOM_VIPPS_BASE=http://127.0.0.1:9');
require $rot . '/app/bootstrap.php';

$s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
$port = (int) substr($adr, strrpos($adr, ':') + 1);
$logg = sys_get_temp_dir() . '/lissom-vlbetalt-' . bin2hex(random_bytes(4)) . '.log';
$server = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

$tag = 'VLB-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $kurs = []; $ferdig = false;

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, $server): void {
    if (is_resource($server)) { proc_terminate($server); }
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $k = implode(',', array_map('intval', $kurs ?: [0]));
    foreach ([
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
    $kid = DB::settInn('courses', ['slug' => strtolower($tag) . '-fat', 'tittel' => 'Store fat ' . $tag, 'type' => 'kurs',
        'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
    $kurs[] = $kid;
    $start = gmdate('Y-m-d H:00:00', time() + 3 * 86400);
    $okt = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $start, 'status' => 'planlagt', 'kapasitet' => 8]);
    $siden = gmdate('Y-m-d H:i:s', time() - 86400);
    $epA = strtolower($tag) . '-anita@example.com';
    $anita = DB::settInn('waitlist', ['course_id' => $kid, 'course_session_id' => $okt, 'navn' => 'Anita ' . $tag,
        'epost' => $epA, 'telefon' => '41111111', 'posisjon' => 1, 'status' => 'varslet', 'created_at' => $siden]);
    $per = DB::settInn('waitlist', ['course_id' => $kid, 'course_session_id' => $okt, 'navn' => 'Per ' . $tag,
        'epost' => strtolower($tag) . '-per@example.com', 'telefon' => '42222222', 'posisjon' => 2, 'status' => 'venter', 'created_at' => $siden]);
    // Anita har booket og betalt (som i live: raden ble stående «varslet»).
    $bA = DB::settInn('bookings', ['course_id' => $kid, 'course_session_id' => $okt, 'gjest_navn' => 'Anita ' . $tag,
        'gjest_epost' => $epA, 'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert']);
    $pA = DB::settInn('payments', ['booking_id' => $bA, 'vipps_reference' => $tag . '-P' . bin2hex(random_bytes(4)), 'type' => 'epayment',
        'formal' => 'booking', 'belop_ore' => 65000, 'status' => 'betalt', 'idempotency_key' => bin2hex(random_bytes(18))]);
    DB::oppdater('bookings', ['payment_id' => $pA, 'status' => 'betalt'], ['id' => $bA]);

    echo "\n── A Den som har booket står ikke i ventelisten ─────────────\n";
    $r = kall('/api/admin/venteliste.php', $t);
    $ider = array_map(static fn($w) => (int) $w['id'], $r[1]['venteliste'] ?? []);
    sjekk('venteliste.php svarer 200', $r[0] === 200, $vis($r));
    sjekk('venteliste.php: Anita er borte, Per står', !in_array($anita, $ider, true) && in_array($per, $ider, true), json_encode($ider));
    $fra = gmdate('Y-m-d', strtotime($start) - 86400); $til = gmdate('Y-m-d', strtotime($start) + 86400);
    $r = kall('/api/admin/kalender.php?fra=' . $fra . '&til=' . $til, $t);
    $h = array_values(array_filter($r[1]['hendelser'] ?? [], static fn($h) => (int) ($h['oktId'] ?? 0) === $okt))[0] ?? [];
    $ko = array_map(static fn($w) => (int) $w['id'], $h['venteliste'] ?? []);
    sjekk('kalender (kurssiden): Anita er borte, Per står', !in_array($anita, $ko, true) && in_array($per, $ko, true), json_encode($ko));
    $o = kall('/api/admin/oversikt.php', $t);
    $saker = array_map(static fn($s) => $s['type'] . ':' . $s['id'], $o[1]['maGjores'] ?? []);
    sjekk('oversikt: ingen «Plass ledig» for Anita, Per står', !in_array('venteliste:' . $anita, $saker, true) && in_array('venteliste:' . $per, $saker, true), json_encode($saker));
    sjekk('basen er ikke endret (Anita fortsatt «varslet»)', DB::verdi('SELECT status FROM waitlist WHERE id = :i', ['i' => $anita]) === 'varslet');

    echo "\n── C Betalt beløp ───────────────────────────────────────────\n";
    $d = null;
    foreach ($h['deltakere'] ?? [] as $x) { if ((int) $x['bookingId'] === $bA) { $d = $x; } }
    sjekk('kalender: deltakeren har betaltOre 65000', (int) ($d['betaltOre'] ?? 0) === 65000 && ($d['status'] ?? '') === 'Betalt', json_encode($d, JSON_UNESCAPED_UNICODE));
    $p = array_values(array_filter($o[1]['maGjores'] ?? [], static fn($s) => $s['type'] === 'pamelding' && in_array($bA, $s['ider'] ?? [], true)))[0] ?? null;
    sjekk('oversikt: Ny påmelding har betaltOre 65000', (int) ($p['betaltOre'] ?? 0) === 65000 && ($p['betalt'] ?? null) === true, json_encode($p, JSON_UNESCAPED_UNICODE));
    $mA = DB::settInn('members', ['navn' => 'Medlem ' . $tag, 'epost' => strtolower($tag) . '-m@example.com', 'status' => 'ingen']);
    $medlemmer[] = $mA;
    DB::oppdater('bookings', ['member_id' => $mA], ['id' => $bA]);
    $r = kall('/api/admin/medlemmer.php?person=' . $mA, $t);
    $hist = array_values(array_filter($r[1]['historikk'] ?? [], static fn($x) => (int) $x['id'] === $bA))[0] ?? null;
    sjekk('medlemmets kurshistorikk: «Betalt · 650 kr»', ($hist['status'] ?? '') === "Betalt · 650\u{a0}kr", $vis($r));
    DB::oppdater('bookings', ['member_id' => null], ['id' => $bA]);

    $rd = static fn(string $f): string => (string) file_get_contents($rot . '/' . $f);
    foreach (['ny-admin/idag.js', 'ny-admin/kurs.js', 'ny-admin/medlemmer.js', 'admin-ny/kasse.js'] as $f) {
        exec('node --check ' . escapeshellarg($rot . '/' . $f) . ' 2>&1', $u, $kd);
        sjekk('node --check ' . $f, $kd === 0);
    }
    sjekk('I dag: Ny påmelding «Betalt · beløp»', str_contains($rd('ny-admin/idag.js'), "`<span class=\"merke gronn\">Betalt\${s.betaltOre > 0 ? ' · ' + kr(s.betaltOre) : ''}</span>`"));
    sjekk('I dag: Start dagens kurs «Betalt · beløp»', str_contains($rd('ny-admin/idag.js'), "\${p.status === 'Betalt' && p.betaltOre > 0 ? ' · ' + kr(p.betaltOre) : ''}"));
    sjekk('Kurs: deltakerliste og utskrift', str_contains($rd('ny-admin/kurs.js'), 'Betalt · ${kr(d.betaltOre)}') && str_contains($rd('ny-admin/kurs.js'), "d.status === 'Betalt' && d.betaltOre > 0 ? ' · ' + kr(d.betaltOre)"));
    sjekk('Medlemmer: «Betalt · beløp» fra betaltOre', substr_count($rd('ny-admin/medlemmer.js'), 'm.betaltOre > 0') === 2 && str_contains($rd('api/admin/medlemmer.php'), "'betaltOre' => \$b['tilstand'] === 'betalt'"));
    sjekk('Kassa: «Har betalt» viser pillens tekst med beløp', str_contains($rd('admin-ny/kasse.js'), "text:r.pille&&r.pille.tekst||'Betalt'") && str_contains($rd('app/lib/kassekurv.php'), "\$betaltTekst = 'Betalt' . (\$betalt > 0 ? ' · ' . self::kr(\$betalt) : '');"));
    sjekk('Start dagens kurs: kursstart3 sender betaltOre', str_contains($rd('api/admin/kursstart3.php'), "'betaltOre'  => (int) DB::verdi('SELECT ' . Booking::betaltSql('b')"));

    echo "\n── B Booker selv: ventelisteraden blir «booket» ──────────────\n";
    try { Booking::reserverOgBetal($okt, 1, 'Per ' . $tag, strtolower($tag) . '-per@example.com', '42222222', null); }
    catch (Throwable $e) { echo '  (betalingen startes ikke i test: ' . $e->getMessage() . ")\n"; }
    sjekk('Per har fått status «booket»', DB::verdi('SELECT status FROM waitlist WHERE id = :i', ['i' => $per]) === 'booket');
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage());
}
$ferdig = true;
echo "\n$ok OK, $feil FEIL\n";
exit($feil === 0 ? 0 : 1);
