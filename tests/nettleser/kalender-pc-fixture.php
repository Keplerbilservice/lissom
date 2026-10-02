<?php
/**
 * Testdata til tests/nyadmin-kalender-pc.mjs (K4, PC-kalenderen i admin-ny).
 *
 *   php tests/nettleser/kalender-pc-fixture.php seed
 *   php tests/nettleser/kalender-pc-fixture.php cleanup '<json fra seed>'
 *
 * Om tolv dager: Alfa (A) 17–20 og Bravo (B) 18–19 hos samme kursholder (overlapper), C 17–20
 * uten kursholder. Dagen etter: et todagerskurs D med samling 2 dagen etter
 * der igjen. To kursholdere: H1 holder A og B, H2 er standard og har ingenting.
 * Alt merkes «KalPcTest-», og oppryddingen tar bare det. Ingen varsler lages.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/testdatabase.php';
krev_testdatabase(dirname(__DIR__, 2));
require dirname(__DIR__, 2) . '/app/bootstrap.php';

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$dag  = static fn(int $n): string => (new DateTimeImmutable('today', $oslo))->modify("{$n} days")->format('Y-m-d');
$iUtc = static fn(string $lokal): string => (new DateTimeImmutable($lokal, $oslo))->setTimezone($utc)->format('Y-m-d H:i:s');

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $tag = 'KalPcTest-' . bin2hex(random_bytes(4));
    $admin = DB::settInn('members', ['navn' => $tag, 'epost' => $tag . '@e2e.lissom.test', 'rolle' => 'admin', 'status' => 'aktiv']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
    $h1 = DB::settInn('kursholdere', ['navn' => $tag . ' H1', 'aktiv' => 1, 'standard' => 0]);
    $h2 = DB::settInn('kursholdere', ['navn' => $tag . ' H2', 'aktiv' => 1, 'standard' => 1]);
    $d = $dag(12); $d1 = $dag(13); $d2 = $dag(14);
    $kurs = []; $okt = [];
    foreach (['A' => ["$d 17:00", "$d 20:00", $h1], 'B' => ["$d 18:00", "$d 19:00", $h1], 'C' => ["$d 17:00", "$d 20:00", null],
              'D' => ["$d1 10:00", "$d2 13:00", $h1]] as $n => [$fra, $til, $h]) {
        $kurs[$n] = DB::settInn('courses', ['slug' => strtolower($tag . '-' . $n), 'tittel' => "$tag " . ['A' => 'Alfa', 'B' => 'Bravo', 'C' => 'Charlie', 'D' => 'Delta'][$n], 'type' => 'kurs',
            'pris_ore' => 50000, 'kapasitet' => 6, 'status' => 'publisert']);
        $okt[$n] = DB::settInn('course_sessions', ['course_id' => $kurs[$n], 'start_tid' => $iUtc($fra), 'slutt_tid' => $iUtc($til),
            'kapasitet' => 6, 'kursholder_id' => $h]);
    }
    DB::settInn('okt_samlinger', ['session_id' => $okt['D'], 'nummer' => 1, 'dato' => $d1, 'fra' => '10:00:00', 'til' => '13:00:00', 'overskrift' => 'Dag en']);
    DB::settInn('okt_samlinger', ['session_id' => $okt['D'], 'nummer' => 2, 'dato' => $d2, 'fra' => '10:00:00', 'til' => '13:00:00', 'overskrift' => 'Dag to']);
    echo json_encode(compact('tag', 'admin', 'token', 'h1', 'h2', 'kurs', 'okt', 'd', 'd1', 'd2')); exit;
}

$s = json_decode($argv[2] ?? '{}', true);
if (!is_array($s) || !str_starts_with((string) ($s['tag'] ?? ''), 'KalPcTest-')) {
    throw new RuntimeException('Ukjent fixture');
}
$kursIder = array_map('intval', array_values((array) ($s['kurs'] ?? [])));
$oktIder = array_map('intval', array_values((array) ($s['okt'] ?? [])));
foreach ($kursIder as $k) {
    $r = DB::en('SELECT tittel FROM courses WHERE id = :i', ['i' => $k]);
    if ($r !== null && !str_starts_with((string) $r['tittel'], $s['tag'])) throw new RuntimeException('Ukjent fixture');
}

if ($mode === 'inspect') {
    $inn = implode(',', $oktIder ?: [0]);
    echo json_encode(['varsler' => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type IN ('course_session','okt') AND ref_id IN ({$inn})")]); exit;
}

if ($mode === 'cleanup') {
    if ($oktIder) {
        $inn = implode(',', $oktIder);
        DB::kjor("DELETE FROM okt_samlinger WHERE session_id IN ({$inn})");
        DB::kjor("DELETE FROM course_sessions WHERE id IN ({$inn})");
    }
    foreach ($kursIder as $k) DB::kjor('DELETE FROM courses WHERE id = :k AND tittel LIKE :t', ['k' => $k, 't' => $s['tag'] . '%']);
    DB::kjor('DELETE FROM kursholdere WHERE navn LIKE :t', ['t' => $s['tag'] . ' H%']);
    if (DB::harTabell('admin_kortbruk')) DB::kjor('DELETE FROM admin_kortbruk WHERE member_id = :i', ['i' => $s['admin']]);
    DB::kjor('DELETE FROM sessions WHERE member_id = :i', ['i' => $s['admin']]);
    DB::kjor('DELETE FROM members WHERE id = :i AND navn = :t', ['i' => $s['admin'], 't' => $s['tag']]);
    echo json_encode(['ok' => true]); exit;
}
throw new RuntimeException('Ukjent handling');
