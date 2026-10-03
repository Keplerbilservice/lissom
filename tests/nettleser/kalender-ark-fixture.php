<?php
/**
 * Testdata til tests/nyadmin-kalender-ark.mjs (kalenderen, bølge 1: økt-arket, merker og farger).
 *
 *   php tests/nettleser/kalender-ark-fixture.php seed
 *   php tests/nettleser/kalender-ark-fixture.php bryter '<json fra seed>' ja|nei
 *   php tests/nettleser/kalender-ark-fixture.php inspect '<json fra seed>'
 *   php tests/nettleser/kalender-ark-fixture.php cleanup '<json fra seed>'
 *
 * Om ti dager: kurs Alfa 18–21 hos kursholderen H med to påmeldte (Ingrid betalt og ny,
 * Marte ikke betalt med merknad) og Siri på ventelista, event Bravo 12–14 uten påmeldte,
 * og en råbrann 09–11. Dagen etter: Paint on Pots med to bookede tider (12:00 og 13:00)
 * og én tid uten booking (14:00, skal ikke vises). Bryteren «Vis/kalenderark» settes på,
 * og det som sto der fra før settes tilbake ved oppryddingen.
 * Alt merkes «KalArkTest-», og oppryddingen tar bare det. Ingen varsler sendes.
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
$bryter = static function (?string $verdi): void {
    if ($verdi === null) {
        DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/kalenderark'");
        return;
    }
    DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/kalenderark', :v)
              ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)", ['v' => $verdi]);
};

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $tag = 'KalArkTest-' . bin2hex(random_bytes(4));
    $for = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/kalenderark'");
    $for = $for === null || $for === false ? null : (string) $for;
    $admin = DB::settInn('members', ['navn' => $tag, 'epost' => $tag . '@e2e.lissom.test', 'rolle' => 'admin', 'status' => 'aktiv']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
    $h = DB::settInn('kursholdere', ['navn' => $tag . ' H', 'aktiv' => 1, 'standard' => 0]);
    $d = $dag(10); $d2 = $dag(11);
    $kurs = []; $okt = [];
    $kurs['A'] = DB::settInn('courses', ['slug' => strtolower($tag . '-a'), 'tittel' => "$tag Alfa", 'type' => 'kurs', 'pris_ore' => 50000, 'kapasitet' => 6, 'status' => 'publisert']);
    $okt['A'] = DB::settInn('course_sessions', ['course_id' => $kurs['A'], 'start_tid' => $iUtc("$d 18:00"), 'slutt_tid' => $iUtc("$d 21:00"), 'kapasitet' => 6, 'kursholder_id' => $h]);
    $kurs['B'] = DB::settInn('courses', ['slug' => strtolower($tag . '-b'), 'tittel' => "$tag Bravo", 'type' => 'event', 'pris_ore' => 40000, 'kapasitet' => 10, 'status' => 'publisert']);
    $okt['B'] = DB::settInn('course_sessions', ['course_id' => $kurs['B'], 'start_tid' => $iUtc("$d 12:00"), 'slutt_tid' => $iUtc("$d 14:00"), 'kapasitet' => 10]);
    $kurs['P'] = DB::settInn('courses', ['slug' => strtolower($tag . '-p'), 'tittel' => "Paint on Pots $tag", 'type' => 'kurs', 'pris_ore' => 20000, 'kapasitet' => 8, 'status' => 'publisert']);
    $pop = [];
    foreach (['12:00' => '12:30', '13:00' => '13:30', '14:00' => '14:30'] as $fra => $til) {
        $pop[$fra] = DB::settInn('course_sessions', ['course_id' => $kurs['P'], 'start_tid' => $iUtc("$d2 $fra"), 'slutt_tid' => $iUtc("$d2 $til"), 'kapasitet' => 8, 'fra_apningstid' => 1]);
    }
    $b = [];
    $b['ingrid'] = DB::settInn('bookings', ['course_id' => $kurs['A'], 'course_session_id' => $okt['A'], 'gjest_navn' => 'Ingrid Berg', 'gjest_epost' => $tag . '.ingrid@e2e.lissom.test', 'gjest_telefon' => '+4790000001', 'antall' => 1, 'belop_ore' => 50000, 'status' => 'betalt']);
    $b['marte'] = DB::settInn('bookings', ['course_id' => $kurs['A'], 'course_session_id' => $okt['A'], 'gjest_navn' => 'Marte Sol', 'gjest_epost' => $tag . '.marte@e2e.lissom.test', 'gjest_telefon' => '+4790000002', 'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert', 'allergier' => 'Allergisk mot latex', 'created_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
    $b['pop1'] = DB::settInn('bookings', ['course_id' => $kurs['P'], 'course_session_id' => $pop['12:00'], 'gjest_navn' => 'Pop En', 'antall' => 2, 'belop_ore' => 40000, 'status' => 'betalt']);
    $b['pop2'] = DB::settInn('bookings', ['course_id' => $kurs['P'], 'course_session_id' => $pop['13:00'], 'gjest_navn' => 'Pop To', 'antall' => 1, 'belop_ore' => 20000, 'status' => 'betalt']);
    $w = DB::settInn('waitlist', ['course_id' => $kurs['A'], 'course_session_id' => $okt['A'], 'navn' => 'Siri Dal', 'epost' => $tag . '.siri@e2e.lissom.test', 'posisjon' => 1, 'status' => 'venter']);
    $brenn = DB::settInn('brenninger', ['slag' => 'raabrann', 'ovn' => $tag . ' ovn', 'start_tid' => $iUtc("$d 09:00"), 'slutt_tid' => $iUtc("$d 11:00")]);
    $bryter('ja');
    echo json_encode(compact('tag', 'for', 'admin', 'token', 'h', 'kurs', 'okt', 'pop', 'b', 'w', 'brenn', 'd', 'd2')); exit;
}

$s = json_decode($argv[2] ?? '{}', true);
if (!is_array($s) || !str_starts_with((string) ($s['tag'] ?? ''), 'KalArkTest-')) {
    throw new RuntimeException('Ukjent fixture');
}
$kursIder = array_map('intval', array_values((array) ($s['kurs'] ?? [])));
foreach ($kursIder as $k) {
    $r = DB::en('SELECT tittel FROM courses WHERE id = :i', ['i' => $k]);
    if ($r !== null && !str_contains((string) $r['tittel'], $s['tag'])) throw new RuntimeException('Ukjent fixture');
}
$oktIder = array_merge(array_map('intval', array_values((array) $s['okt'])), array_map('intval', array_values((array) $s['pop'])));
$bIder = array_map('intval', array_values((array) $s['b']));
$innO = implode(',', $oktIder ?: [0]);
$innB = implode(',', $bIder ?: [0]);
$varslerSql = "SELECT COUNT(*) FROM notifications WHERE (ref_type = 'booking' AND ref_id IN ({$innB}))
               OR (ref_type = 'waitlist' AND ref_id = " . (int) $s['w'] . ")
               OR (ref_type IN ('beskjed-okt','course_session','okt') AND ref_id IN ({$innO}))";

if ($mode === 'bryter') {
    $bryter(($argv[3] ?? '') === 'ja' ? 'ja' : 'nei');
    echo json_encode(['ok' => true]); exit;
}

if ($mode === 'inspect') {
    $st = [];
    foreach ($s['b'] as $n => $id) $st[$n] = DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $id]);
    $bevis = DB::harKolonne('bookings', 'bevis_sperret')
        ? array_map(static fn($id) => (int) DB::verdi('SELECT bevis_sperret FROM bookings WHERE id = :i', ['i' => $id]), $s['b']) : [];
    echo json_encode([
        'status'    => $st,
        'bevis'     => $bevis,
        'kapasitet' => (int) DB::verdi('SELECT kapasitet FROM course_sessions WHERE id = :i', ['i' => $s['okt']['A']]),
        'visFullt'  => DB::harKolonne('course_sessions', 'vis_fullt') ? (int) DB::verdi('SELECT vis_fullt FROM course_sessions WHERE id = :i', ['i' => $s['okt']['A']]) : null,
        'timer'     => DB::alle('SELECT dato, timer, hva FROM kursholder_timer WHERE kursholder_id = :h', ['h' => $s['h']]),
        'venter'    => DB::verdi('SELECT status FROM waitlist WHERE id = :i', ['i' => $s['w']]),
        'varsler'   => (int) DB::verdi($varslerSql),
    ]); exit;
}

if ($mode === 'cleanup') {
    DB::kjor(str_replace('SELECT COUNT(*)', 'DELETE', $varslerSql));
    DB::kjor("DELETE FROM bookings WHERE id IN ({$innB}) OR course_session_id IN ({$innO})");
    DB::kjor('DELETE FROM waitlist WHERE id = :i OR course_session_id IN (' . $innO . ')', ['i' => $s['w']]);
    DB::kjor("DELETE FROM course_sessions WHERE id IN ({$innO})");
    foreach ($kursIder as $k) DB::kjor('DELETE FROM courses WHERE id = :k AND tittel LIKE :t', ['k' => $k, 't' => '%' . $s['tag'] . '%']);
    DB::kjor('DELETE FROM kursholder_timer WHERE kursholder_id = :h', ['h' => $s['h']]);
    DB::kjor('DELETE FROM kursholdere WHERE id = :h AND navn LIKE :t', ['h' => $s['h'], 't' => $s['tag'] . '%']);
    DB::kjor('DELETE FROM brenninger WHERE id = :i AND ovn LIKE :t', ['i' => $s['brenn'], 't' => $s['tag'] . '%']);
    if (DB::harTabell('admin_kortbruk')) DB::kjor('DELETE FROM admin_kortbruk WHERE member_id = :i', ['i' => $s['admin']]);
    DB::kjor('DELETE FROM sessions WHERE member_id = :i', ['i' => $s['admin']]);
    DB::kjor('DELETE FROM members WHERE id = :i AND navn = :t', ['i' => $s['admin'], 't' => $s['tag']]);
    $bryter(isset($s['for']) && is_string($s['for']) ? $s['for'] : null);
    echo json_encode(['ok' => true]); exit;
}
throw new RuntimeException('Ukjent handling');
