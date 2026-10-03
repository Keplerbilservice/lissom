<?php
/**
 * Testdata til tests/nyadmin-kalender-meny.mjs (resten av kalenderen: menyer, dra og slipp, sideliste, liste, dagsrapport).
 *
 *   php tests/nettleser/kalender-meny-fixture.php seed
 *   php tests/nettleser/kalender-meny-fixture.php bryter '<json fra seed>' ja|nei
 *   php tests/nettleser/kalender-meny-fixture.php inspect '<json fra seed>'
 *   php tests/nettleser/kalender-meny-fixture.php cleanup '<json fra seed>'
 *
 * d er mandagen om tre uker. Alfa (A1) d 10–12 hos H1 med Ingrid (betalt) og Marte (ikke betalt), Siri på ventelista;
 * Bravo (B1) d 18–20 hos H1 uten påmeldte; Alfa (A2) d+1 18–20 hos H1. Et notat d 09:00 og en innsjekk d.
 * Til mobilen: Tynn (T) om to dager 12–14 hos H2, 1 av 10, og Avlyst (V) om tre dager, og en innsjekk om to dager.
 * Bryterne «Vis/kalenderark» og «Vis/kalendermeny» settes på, «Vis/kalendergjenta» av; det som sto der settes tilbake.
 * Alt merkes «KalMenyTest-». Ingen varsler sendes.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/testdatabase.php';
krev_testdatabase(dirname(__DIR__, 2));
require dirname(__DIR__, 2) . '/app/bootstrap.php';

$BRYTERE = ['Vis/kalenderark' => 'ja', 'Vis/kalendermeny' => 'ja', 'Vis/kalendergjenta' => 'nei'];
$bryter = static function (string $n, ?string $v): void {
    if ($v === null) { DB::kjor('DELETE FROM content_blocks WHERE nokkel = :n', ['n' => $n]); return; }
    DB::kjor('INSERT INTO content_blocks (nokkel, verdi) VALUES (:n, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)', ['n' => $n, 'v' => $v]);
};
$oslo = new DateTimeZone('Europe/Oslo');
$utc = new DateTimeZone('UTC');
$iUtc = static fn(string $l): string => (new DateTimeImmutable($l, $oslo))->setTimezone($utc)->format('Y-m-d H:i:s');
$lokal = static fn(?string $u): string => $u === null ? '' : (new DateTimeImmutable($u, $utc))->setTimezone($oslo)->format('Y-m-d H:i');

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $tag = 'KalMenyTest-' . bin2hex(random_bytes(4));
    $for = [];
    foreach ($BRYTERE as $n => $_) {
        $v = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => $n]);
        $for[$n] = $v === null || $v === false ? null : (string) $v;
    }
    $admin = DB::settInn('members', ['navn' => $tag, 'epost' => $tag . '@e2e.lissom.test', 'rolle' => 'admin', 'status' => 'aktiv']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
    $d = (new DateTimeImmutable('today', $oslo))->modify('+21 days')->modify('monday this week')->format('Y-m-d');
    $d1 = (new DateTimeImmutable($d, $oslo))->modify('+1 day')->format('Y-m-d');
    $d2 = (new DateTimeImmutable($d, $oslo))->modify('+2 days')->format('Y-m-d');
    $t2 = (new DateTimeImmutable('today', $oslo))->modify('+2 days')->format('Y-m-d');
    $t3 = (new DateTimeImmutable('today', $oslo))->modify('+3 days')->format('Y-m-d');
    $h1 = DB::settInn('kursholdere', ['navn' => "$tag H1", 'aktiv' => 1, 'standard' => 0]);
    $h2 = DB::settInn('kursholdere', ['navn' => "$tag H2", 'aktiv' => 1, 'standard' => 0]);
    $kurs = [];
    foreach (['A' => ['Alfa', 'kurs'], 'B' => ['Bravo', 'event'], 'T' => ['Tynn', 'kurs'], 'V' => ['Avlyst', 'kurs']] as $k => [$navn, $type]) {
        $kurs[$k] = DB::settInn('courses', ['slug' => strtolower("$tag-$k"), 'tittel' => "$tag $navn", 'type' => $type, 'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
    }
    $okt = [
        'A1' => DB::settInn('course_sessions', ['course_id' => $kurs['A'], 'start_tid' => $iUtc("$d 10:00"), 'slutt_tid' => $iUtc("$d 12:00"), 'kapasitet' => 8, 'kursholder_id' => $h1]),
        'B1' => DB::settInn('course_sessions', ['course_id' => $kurs['B'], 'start_tid' => $iUtc("$d 18:00"), 'slutt_tid' => $iUtc("$d 20:00"), 'kapasitet' => 6, 'kursholder_id' => $h1]),
        'A2' => DB::settInn('course_sessions', ['course_id' => $kurs['A'], 'start_tid' => $iUtc("$d1 18:00"), 'slutt_tid' => $iUtc("$d1 20:00"), 'kapasitet' => 8, 'kursholder_id' => $h1]),
        'T'  => DB::settInn('course_sessions', ['course_id' => $kurs['T'], 'start_tid' => $iUtc("$t2 12:00"), 'slutt_tid' => $iUtc("$t2 14:00"), 'kapasitet' => 10, 'kursholder_id' => $h2]),
        'V'  => DB::settInn('course_sessions', ['course_id' => $kurs['V'], 'start_tid' => $iUtc("$t3 12:00"), 'slutt_tid' => $iUtc("$t3 14:00"), 'kapasitet' => 10, 'status' => 'avlyst']),
    ];
    $b = [
        'ingrid' => DB::settInn('bookings', ['course_id' => $kurs['A'], 'course_session_id' => $okt['A1'], 'gjest_navn' => 'Ingrid Berg', 'gjest_epost' => "$tag.ingrid@e2e.lissom.test", 'antall' => 1, 'belop_ore' => 50000, 'status' => 'betalt']),
        'marte'  => DB::settInn('bookings', ['course_id' => $kurs['A'], 'course_session_id' => $okt['A1'], 'gjest_navn' => 'Marte Sol', 'gjest_epost' => "$tag.marte@e2e.lissom.test", 'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert']),
        'tynn'   => DB::settInn('bookings', ['course_id' => $kurs['T'], 'course_session_id' => $okt['T'], 'gjest_navn' => 'Tor Tynn', 'gjest_epost' => "$tag.tor@e2e.lissom.test", 'antall' => 1, 'belop_ore' => 50000, 'status' => 'betalt']),
    ];
    $w = DB::settInn('waitlist', ['course_id' => $kurs['A'], 'course_session_id' => $okt['A1'], 'navn' => "$tag Siri", 'epost' => "$tag.siri@e2e.lissom.test", 'posisjon' => 1, 'status' => 'venter']);
    $notat = DB::settInn('kalender_notater', ['dato' => $d, 'fra' => '09:00:00', 'til' => '09:30:00', 'tekst' => "$tag Ovnen tømmes", 'skrevet_av' => $admin]);
    $inn = [
        DB::settInn('check_ins', ['member_id' => $admin, 'inn_tid' => $iUtc("$d 13:00"), 'ut_tid' => $iUtc("$d 15:00")]),
        DB::settInn('check_ins', ['member_id' => $admin, 'inn_tid' => $iUtc("$t2 15:00"), 'ut_tid' => $iUtc("$t2 16:00")]),
    ];
    foreach ($BRYTERE as $n => $v) { $bryter($n, $v); }
    echo json_encode(compact('tag', 'for', 'admin', 'token', 'h1', 'h2', 'kurs', 'okt', 'b', 'w', 'notat', 'inn', 'd', 'd1', 'd2', 't2', 't3')); exit;
}

$s = json_decode($argv[2] ?? '{}', true);
if (!is_array($s) || !str_starts_with((string) ($s['tag'] ?? ''), 'KalMenyTest-')) { throw new RuntimeException('Ukjent fixture'); }
$kursIder = array_map('intval', array_values((array) $s['kurs']));
foreach ($kursIder as $k) {
    $r = DB::en('SELECT tittel FROM courses WHERE id = :i', ['i' => $k]);
    if ($r !== null && !str_contains((string) $r['tittel'], $s['tag'])) { throw new RuntimeException('Ukjent fixture'); }
}
$innK = implode(',', $kursIder ?: [0]);

if ($mode === 'bryter') {
    $bryter('Vis/kalendermeny', ($argv[3] ?? '') === 'ja' ? 'ja' : 'nei');
    echo json_encode(['ok' => true]); exit;
}
if ($mode === 'inspect') {
    $okter = [];
    foreach (DB::alle("SELECT id, course_id, start_tid, slutt_tid, kursholder_id FROM course_sessions WHERE course_id IN ({$innK}) ORDER BY start_tid") as $o) {
        $okter[] = ['id' => (int) $o['id'], 'kurs' => (int) $o['course_id'], 'start' => $lokal((string) $o['start_tid']), 'slutt' => $lokal($o['slutt_tid']), 'holder' => $o['kursholder_id'] === null ? null : (int) $o['kursholder_id']];
    }
    $n = DB::en('SELECT dato, fra FROM kalender_notater WHERE id = :i', ['i' => $s['notat']]);
    echo json_encode([
        'okter'   => $okter,
        'ingrid'  => (int) DB::verdi('SELECT course_session_id FROM bookings WHERE id = :i', ['i' => $s['b']['ingrid']]),
        'notat'   => $n === null ? null : ['dato' => (string) $n['dato'], 'fra' => substr((string) $n['fra'], 0, 5)],
        'notater' => (int) DB::verdi('SELECT COUNT(*) FROM kalender_notater WHERE tekst LIKE :t', ['t' => $s['tag'] . '%']),
        'venter'  => (string) DB::verdi('SELECT status FROM waitlist WHERE id = :i', ['i' => $s['w']]),
        'varsler' => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE (ref_type = 'booking' AND ref_id IN (SELECT id FROM bookings WHERE course_id IN ({$innK})))
                                        OR (ref_type IN ('course_session','okt','beskjed-okt') AND ref_id IN (SELECT id FROM course_sessions WHERE course_id IN ({$innK})))
                                        OR (ref_type = 'waitlist' AND ref_id = :w)", ['w' => $s['w']]),
    ]); exit;
}
if ($mode === 'cleanup') {
    DB::kjor("DELETE FROM notifications WHERE (ref_type = 'booking' AND ref_id IN (SELECT id FROM bookings WHERE course_id IN ({$innK})))
              OR (ref_type IN ('course_session','okt','beskjed-okt') AND ref_id IN (SELECT id FROM course_sessions WHERE course_id IN ({$innK})))
              OR (ref_type = 'waitlist' AND ref_id = :w)", ['w' => $s['w']]);
    DB::kjor("DELETE FROM bookings WHERE course_id IN ({$innK})");
    DB::kjor("DELETE FROM waitlist WHERE id = :i OR course_id IN ({$innK})", ['i' => $s['w']]);
    DB::kjor("DELETE FROM okt_samlinger WHERE session_id IN (SELECT id FROM course_sessions WHERE course_id IN ({$innK}))");
    DB::kjor("DELETE FROM course_sessions WHERE course_id IN ({$innK})");
    DB::kjor("DELETE FROM courses WHERE id IN ({$innK}) AND tittel LIKE :t", ['t' => $s['tag'] . '%']);
    DB::kjor('DELETE FROM kursholdere WHERE id IN (:a, :b) AND navn LIKE :t', ['a' => $s['h1'], 'b' => $s['h2'], 't' => $s['tag'] . '%']);
    DB::kjor('DELETE FROM kalender_notater WHERE tekst LIKE :t', ['t' => $s['tag'] . '%']);
    DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $s['admin']]);
    DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => $s['admin']]);
    DB::kjor('DELETE FROM audit_log WHERE member_id = :m', ['m' => $s['admin']]);
    DB::kjor('DELETE FROM members WHERE id = :m AND navn = :t', ['m' => $s['admin'], 't' => $s['tag']]);
    foreach ((array) $s['for'] as $n => $v) { $bryter((string) $n, $v === null ? null : (string) $v); }
    echo json_encode(['ok' => true]); exit;
}
throw new RuntimeException('Ukjent modus');
