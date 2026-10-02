<?php
/**
 * Testdata til tests/nyadmin-samling.mjs.
 *
 *   php tests/nettleser/samling-fixture.php seed
 *   php tests/nettleser/samling-fixture.php inspect '<json fra seed>'
 *   php tests/nettleser/samling-fixture.php cleanup '<json fra seed>'
 *
 * Et todagerskurs med to samlinger, en passert og en kommende dato paa samme
 * kurs, en paamelding framover og en gammel paamelding som ligger utenfor
 * lista paa Paameldte (eldre enn 30 dager). Alt merkes «SamlingTest-», og
 * oppryddingen tar bare det.
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
    $tag = 'SamlingTest-' . bin2hex(random_bytes(4));
    $admin = DB::settInn('members', ['navn' => $tag, 'epost' => $tag . '@e2e.lissom.test', 'rolle' => 'admin', 'status' => 'aktiv']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
    $kurs = DB::settInn('courses', ['slug' => strtolower($tag), 'tittel' => $tag . ' Dreiekurs', 'type' => 'kurs',
        'pris_ore' => 100000, 'kapasitet' => 8, 'status' => 'publisert']);

    // Todagerskurset: dag 1 om ti dager, dag 2 dagen etter.
    $d1 = $dag(10); $d2 = $dag(11);
    $okt = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $iUtc("$d1 17:00"),
        'slutt_tid' => $iUtc("$d2 20:00"), 'kapasitet' => 8]);
    DB::settInn('okt_samlinger', ['session_id' => $okt, 'nummer' => 1, 'dato' => $d1, 'fra' => '17:00:00', 'til' => '20:00:00', 'overskrift' => 'Dag en']);
    DB::settInn('okt_samlinger', ['session_id' => $okt, 'nummer' => 2, 'dato' => $d2, 'fra' => '17:00:00', 'til' => '20:00:00', 'overskrift' => 'Dag to']);

    // En passert og en kommende dato paa samme kurs.
    $passert = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $iUtc($dag(-3) . ' 17:00'),
        'slutt_tid' => $iUtc($dag(-3) . ' 20:00'), 'kapasitet' => 8]);
    $kommende = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $iUtc($dag(20) . ' 17:00'),
        'slutt_tid' => $iUtc($dag(20) . ' 20:00'), 'kapasitet' => 8]);
    // Gammel dato, utenfor 30-dagersvinduet paa Paameldte.
    $gammel = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $iUtc($dag(-60) . ' 17:00'),
        'slutt_tid' => $iUtc($dag(-60) . ' 20:00'), 'kapasitet' => 8]);

    $booking = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $okt, 'gjest_navn' => $tag . ' Framover',
        'gjest_telefon' => '+4790000000', 'antall' => 1, 'belop_ore' => 100000, 'status' => 'betalt']);
    $gammelBooking = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $gammel, 'gjest_navn' => $tag . ' Gammel',
        'gjest_telefon' => '+4790000001', 'antall' => 1, 'belop_ore' => 100000, 'status' => 'betalt']);

    // En stengt feriedag om fjorten dager, til ferieadvarselen paa samling 2.
    $ferie = $dag(14);
    if (DB::en('SELECT id FROM apningstider WHERE dato = :d', ['d' => $ferie]) !== null) {
        throw new RuntimeException('Datoen for feriedagen er alt satt opp i testbasen.');
    }
    DB::settInn('apningstider', ['dato' => $ferie, 'stengt' => 1, 'merknad' => $tag]);

    echo json_encode(compact('tag', 'admin', 'token', 'kurs', 'okt', 'passert', 'kommende', 'gammel',
        'booking', 'gammelBooking', 'd1', 'd2', 'ferie')); exit;
}

$s = json_decode($argv[2] ?? '{}', true);
if (!is_array($s) || !str_starts_with((string) ($s['tag'] ?? ''), 'SamlingTest-')) {
    throw new RuntimeException('Ukjent fixture');
}
$k = DB::en('SELECT tittel FROM courses WHERE id = :i', ['i' => $s['kurs']]);
if ($k === null || !str_starts_with((string) $k['tittel'], $s['tag'])) {
    throw new RuntimeException('Ukjent fixture');
}
$okter = [(int) $s['okt'], (int) $s['passert'], (int) $s['kommende'], (int) $s['gammel']];
$inn = implode(',', $okter);
$bookinger = (string) (int) $s['booking'] . ',' . (int) $s['gammelBooking'];

if ($mode === 'inspect') {
    $o = DB::en('SELECT start_tid, slutt_tid, ferie_ok FROM course_sessions WHERE id = :i', ['i' => $s['okt']]);
    $iOslo = static fn(?string $u): ?string => $u === null ? null
        : (new DateTimeImmutable($u, $utc))->setTimezone($oslo)->format('Y-m-d H:i');
    echo json_encode([
        'start' => $iOslo($o['start_tid']),
        'slutt' => $iOslo($o['slutt_tid']),
        'ferieOk' => (int) $o['ferie_ok'],
        'samlinger' => array_map(static fn($r) => [$r['dato'], substr((string) $r['fra'], 0, 5), substr((string) $r['til'], 0, 5), $r['overskrift']],
            DB::alle('SELECT dato, fra, til, overskrift FROM okt_samlinger WHERE session_id = :i ORDER BY nummer', ['i' => $s['okt']])),
        // Ingen SMS eller e-post skal legges i koen av noe testen gjor.
        'varsler' => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE (ref_type = 'booking' AND ref_id IN ({$bookinger}))
                                        OR (ref_type IN ('course_session','okt') AND ref_id IN ({$inn}))"),
    ]); exit;
}

if ($mode === 'cleanup') {
    if (isset($s['ferie'])) {
        DB::kjor('DELETE FROM apningstider WHERE dato = :d AND merknad = :t', ['d' => $s['ferie'], 't' => $s['tag']]);
    }
    DB::kjor("DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id IN ({$bookinger})");
    DB::kjor("DELETE FROM bookings WHERE course_id = :k", ['k' => $s['kurs']]);
    if (DB::harTabell('okt_samlinger')) DB::kjor("DELETE FROM okt_samlinger WHERE session_id IN ({$inn})");
    DB::kjor("DELETE FROM course_sessions WHERE course_id = :k", ['k' => $s['kurs']]);
    DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $s['kurs']]);
    if (DB::harTabell('admin_kortbruk')) DB::kjor('DELETE FROM admin_kortbruk WHERE member_id = :i', ['i' => $s['admin']]);
    DB::kjor('DELETE FROM sessions WHERE member_id = :i', ['i' => $s['admin']]);
    DB::kjor('DELETE FROM members WHERE id = :i', ['i' => $s['admin']]);
    echo json_encode(['ok' => true]); exit;
}
throw new RuntimeException('Ukjent handling');
