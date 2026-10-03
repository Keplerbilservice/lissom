<?php
/**
 * Testdata til tests/nyadmin-kalender-gjenta.mjs («Ny kursdato» med gjentakelse og «Dupliser til neste uke», bølge 3).
 *
 *   php tests/nettleser/kalender-gjenta-fixture.php seed
 *   php tests/nettleser/kalender-gjenta-fixture.php bryter '<json fra seed>' ja|nei
 *   php tests/nettleser/kalender-gjenta-fixture.php inspect '<json fra seed>'
 *   php tests/nettleser/kalender-gjenta-fixture.php cleanup '<json fra seed>'
 *
 * Et kurs «Dreiekurs» med én økt (d, 18–20) og en kursholder. Første dag for gjentakelsen er d0 (en mandag
 * langt fram); uka etter (d0 + 7) er stengt. Bryterne «Vis/kalenderark» og «Vis/kalendergjenta» settes på,
 * og det som sto der fra før settes tilbake ved oppryddingen. Alt merkes «GjUiTest-».
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/testdatabase.php';
krev_testdatabase(dirname(__DIR__, 2));
require dirname(__DIR__, 2) . '/app/bootstrap.php';

$BRYTERE = ['Vis/kalenderark', 'Vis/kalendergjenta'];
$bryter = static function (string $nokkel, ?string $verdi): void {
    if ($verdi === null) {
        DB::kjor('DELETE FROM content_blocks WHERE nokkel = :n', ['n' => $nokkel]);
        return;
    }
    DB::kjor('INSERT INTO content_blocks (nokkel, verdi) VALUES (:n, :v)
              ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)', ['n' => $nokkel, 'v' => $verdi]);
};
$oslo = new DateTimeZone('Europe/Oslo');
$iUtc = static fn(string $l): string => (new DateTimeImmutable($l, $oslo))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $tag = 'GjUiTest-' . bin2hex(random_bytes(4));
    $for = [];
    foreach ($BRYTERE as $n) {
        $v = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => $n]);
        $for[$n] = $v === null || $v === false ? null : (string) $v;
    }
    $admin = DB::settInn('members', ['navn' => $tag, 'epost' => $tag . '@e2e.lissom.test', 'rolle' => 'admin', 'status' => 'aktiv']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
    $d = (new DateTimeImmutable('today', $oslo))->modify('+10 days')->format('Y-m-d');
    $d0 = (new DateTimeImmutable('today', $oslo))->modify('+300 days')->modify('monday this week')->format('Y-m-d');
    $stengt = (new DateTimeImmutable($d0, $oslo))->modify('+7 days')->format('Y-m-d');
    DB::kjor('DELETE FROM apningstider WHERE dato = :d', ['d' => $stengt]);
    DB::settInn('apningstider', ['dato' => $stengt, 'stengt' => 1, 'merknad' => $tag]);
    $kurs = DB::settInn('courses', ['slug' => strtolower($tag), 'tittel' => "$tag Dreiekurs", 'type' => 'kurs', 'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
    $holder = DB::settInn('kursholdere', ['navn' => "$tag Holder", 'aktiv' => 1]);
    $okt = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $iUtc("$d 18:00"), 'slutt_tid' => $iUtc("$d 20:00"), 'kapasitet' => 7, 'kursholder_id' => $holder]);
    foreach ($BRYTERE as $n) { $bryter($n, 'ja'); }
    echo json_encode(compact('tag', 'for', 'admin', 'token', 'kurs', 'okt', 'holder', 'd', 'd0', 'stengt')); exit;
}

$s = json_decode($argv[2] ?? '{}', true);
if (!is_array($s) || !str_starts_with((string) ($s['tag'] ?? ''), 'GjUiTest-')) {
    throw new RuntimeException('Ukjent fixture');
}
$r = DB::en('SELECT tittel FROM courses WHERE id = :i', ['i' => (int) $s['kurs']]);
if ($r !== null && !str_contains((string) $r['tittel'], $s['tag'])) { throw new RuntimeException('Ukjent fixture'); }
$kurs = (int) $s['kurs'];

if ($mode === 'bryter') {
    $bryter('Vis/kalendergjenta', ($argv[3] ?? '') === 'ja' ? 'ja' : 'nei');
    echo json_encode(['ok' => true]); exit;
}
if ($mode === 'inspect') {
    $okter = array_map(static function (array $o) use ($oslo): array {
        $t = (new DateTimeImmutable((string) $o['start_tid'], new DateTimeZone('UTC')))->setTimezone($oslo);
        $sl = $o['slutt_tid'] !== null ? (new DateTimeImmutable((string) $o['slutt_tid'], new DateTimeZone('UTC')))->setTimezone($oslo)->format('H:i') : '';
        return ['start' => $t->format('Y-m-d H:i'), 'slutt' => $sl, 'holder' => $o['kursholder_id'] === null ? null : (int) $o['kursholder_id'], 'kap' => $o['kapasitet'] === null ? null : (int) $o['kapasitet']];
    }, DB::alle('SELECT start_tid, slutt_tid, kursholder_id, kapasitet FROM course_sessions WHERE course_id = :k ORDER BY start_tid', ['k' => $kurs]));
    $varsler = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type IN ('course_session','okt') AND ref_id IN (SELECT id FROM course_sessions WHERE course_id = :k)", ['k' => $kurs]);
    echo json_encode(['okter' => $okter, 'varsler' => $varsler]); exit;
}
if ($mode === 'cleanup') {
    DB::kjor('DELETE FROM okt_samlinger WHERE session_id IN (SELECT id FROM course_sessions WHERE course_id = :k)', ['k' => $kurs]);
    DB::kjor('DELETE FROM course_sessions WHERE course_id = :k', ['k' => $kurs]);
    DB::kjor('DELETE FROM courses WHERE id = :i', ['i' => $kurs]);
    DB::kjor('DELETE FROM kursholdere WHERE id = :i', ['i' => (int) $s['holder']]);
    DB::kjor('DELETE FROM apningstider WHERE dato = :d AND merknad = :m', ['d' => (string) $s['stengt'], 'm' => (string) $s['tag']]);
    DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => (int) $s['admin']]);
    DB::kjor('DELETE FROM audit_log WHERE member_id = :m', ['m' => (int) $s['admin']]);
    DB::kjor('DELETE FROM members WHERE id = :m', ['m' => (int) $s['admin']]);
    foreach ((array) $s['for'] as $n => $v) { $bryter((string) $n, $v === null ? null : (string) $v); }
    echo json_encode(['ok' => true]); exit;
}
throw new RuntimeException('Ukjent modus');
