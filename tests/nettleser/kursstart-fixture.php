<?php
/**
 * Testdata til tests/nyadmin-kursstart.mjs («Start kurset» i tre steg, bølge 2).
 *
 *   php tests/nettleser/kursstart-fixture.php seed
 *   php tests/nettleser/kursstart-fixture.php bryter '<json fra seed>' ja|nei
 *   php tests/nettleser/kursstart-fixture.php vipps '<json fra seed>' <betaling-status> [ja|nei for 400 på krav og QR]
 *   php tests/nettleser/kursstart-fixture.php avbestill '<json fra seed>' <navn, f.eks. nils>
 *   php tests/nettleser/kursstart-fixture.php inspect '<json fra seed>'
 *   php tests/nettleser/kursstart-fixture.php cleanup '<json fra seed>'
 *
 * Om ti dager: kurs «Dreiekurs» 18–21 med Ingrid (betalt), Marte, Olga og Per (ikke betalt, mobil)
 * og Nils (ikke betalt, uten mobil). Bryterne «Vis/kalenderark» og «Vis/kursstart3» settes på, og
 * det som sto der fra før (og styrefilene til den falske Vippsen) settes tilbake ved oppryddingen.
 * Alt merkes «KsTest-», og oppryddingen tar bare det. Ingen ekte Vipps, e-post eller SMS.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/testdatabase.php';
krev_testdatabase(dirname(__DIR__, 2));
require dirname(__DIR__, 2) . '/app/bootstrap.php';

$BRYTERE = ['Vis/kalenderark', 'Vis/kursstart3'];
$STYR = ['.betaling-status', '.krav-400', '.qr-400'];
$styrFil = static fn(string $n): string => dirname(__DIR__) . '/' . $n;
$bryter = static function (string $nokkel, ?string $verdi): void {
    if ($verdi === null) {
        DB::kjor('DELETE FROM content_blocks WHERE nokkel = :n', ['n' => $nokkel]);
        return;
    }
    DB::kjor('INSERT INTO content_blocks (nokkel, verdi) VALUES (:n, :v)
              ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)', ['n' => $nokkel, 'v' => $verdi]);
};

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $tag = 'KsTest-' . bin2hex(random_bytes(4));
    $for = [];
    foreach ($BRYTERE as $n) {
        $v = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => $n]);
        $for[$n] = $v === null || $v === false ? null : (string) $v;
    }
    $styrFor = [];
    foreach ($STYR as $n) { $styrFor[$n] = is_file($styrFil($n)) ? (string) file_get_contents($styrFil($n)) : null; }
    $admin = DB::settInn('members', ['navn' => $tag, 'epost' => $tag . '@e2e.lissom.test', 'rolle' => 'admin', 'status' => 'aktiv']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
    $oslo = new DateTimeZone('Europe/Oslo');
    $d = (new DateTimeImmutable('today', $oslo))->modify('+10 days')->format('Y-m-d');
    $iUtc = static fn(string $l): string => (new DateTimeImmutable($l, $oslo))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $kurs = DB::settInn('courses', ['slug' => strtolower($tag), 'tittel' => "$tag Dreiekurs", 'type' => 'kurs', 'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
    $okt = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $iUtc("$d 18:00"), 'slutt_tid' => $iUtc("$d 21:00"), 'kapasitet' => 8]);
    $b = [];
    foreach (['ingrid' => ['Ingrid Berg', 'betalt', '+4791000001'], 'marte' => ['Marte Sol', 'reservert', '+4791000002'],
              'nils' => ['Nils Utennummer', 'reservert', null], 'olga' => ['Olga Feil', 'reservert', '91000004'],
              'per' => ['Per Vipps', 'reservert', '91000005']] as $n => [$navn, $st, $tlf]) {
        $b[$n] = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $okt, 'gjest_navn' => $navn,
            // Nils mangler e-post (steg 1 «Deltakerne», 7. oktober 2026: «Trengs for kursbevis»).
            'gjest_epost' => $n === 'nils' ? null : $tag . '.' . $n . '@e2e.lissom.test', 'gjest_telefon' => $tlf, 'antall' => 1,
            'belop_ore' => 50000, 'status' => $st]);
    }
    // Ingrid er betalt med en ført betaling, så hun ikke står som «betalt før føringen».
    DB::iTransaksjon(static fn() => Booking::manuellBetaling($b['ingrid'], 50000, 'Kontant'));
    foreach ($BRYTERE as $n) { $bryter($n, 'ja'); }
    file_put_contents($styrFil('.betaling-status'), 'CREATED');
    @unlink($styrFil('.krav-400'));
    @unlink($styrFil('.qr-400'));
    echo json_encode(compact('tag', 'for', 'styrFor', 'admin', 'token', 'kurs', 'okt', 'b', 'd')); exit;
}

$s = json_decode($argv[2] ?? '{}', true);
if (!is_array($s) || !str_starts_with((string) ($s['tag'] ?? ''), 'KsTest-')) {
    throw new RuntimeException('Ukjent fixture');
}
$r = DB::en('SELECT tittel FROM courses WHERE id = :i', ['i' => (int) $s['kurs']]);
if ($r !== null && !str_contains((string) $r['tittel'], $s['tag'])) { throw new RuntimeException('Ukjent fixture'); }
$innB = implode(',', array_map('intval', array_values((array) $s['b'])) ?: [0]);
$okt = (int) $s['okt'];

if ($mode === 'bryter') {
    $bryter('Vis/kursstart3', ($argv[3] ?? '') === 'ja' ? 'ja' : 'nei');
    echo json_encode(['ok' => true]); exit;
}
if ($mode === 'vipps') {
    file_put_contents($styrFil('.betaling-status'), (string) ($argv[3] ?? 'CREATED'));
    // «ja» = Vipps sier 400 både til krav og til QR-koden.
    foreach (['.krav-400', '.qr-400'] as $n) {
        if (($argv[4] ?? 'nei') === 'ja') { file_put_contents($styrFil($n), 'ja'); } else { @unlink($styrFil($n)); }
    }
    echo json_encode(['ok' => true]); exit;
}
if ($mode === 'avbestill') {
    // Som en avbestilling fra et annet sted mens QR-koden står oppe.
    DB::kjor("UPDATE bookings SET status = 'avbestilt' WHERE id = :i AND id IN ({$innB})", ['i' => (int) ($s['b'][$argv[3] ?? ''] ?? 0)]);
    echo json_encode(['ok' => true]); exit;
}
if ($mode === 'inspect') {
    $ut = [];
    foreach ($s['b'] as $n => $id) {
        $ut[$n] = [
            'status' => (string) DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $id]),
            'epost'  => (string) DB::verdi('SELECT COALESCE(gjest_epost, \'\') FROM bookings WHERE id = :i', ['i' => $id]),
            'tlf'    => (string) DB::verdi('SELECT COALESCE(gjest_telefon, \'\') FROM bookings WHERE id = :i', ['i' => $id]),
            'sum'    => Booking::betalingerFor((int) $id)['sum'],
            'krav'   => array_map(static fn($r) => ['status' => $r['status'], 'belop' => (int) $r['belop_ore']], DB::alle(
                "SELECT status, belop_ore FROM payments WHERE booking_id = :b AND vipps_reference LIKE 'KS-%' ORDER BY id", ['b' => $id])),
            'kontant' => (int) DB::verdi("SELECT COUNT(*) FROM payments WHERE booking_id = :b AND type = 'manuell' AND maate = 'Kontant' AND status = 'betalt'", ['b' => $id]),
        ];
    }
    $varsler = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE (ref_type = 'booking' AND ref_id IN ({$innB}))
                                 OR (ref_type IN ('course_session','okt','beskjed-okt') AND ref_id = :o)", ['o' => $okt]);
    echo json_encode(['b' => $ut, 'varsler' => $varsler]); exit;
}
if ($mode === 'cleanup') {
    foreach ([
        "DELETE FROM vipps_webhook_events WHERE referanse IN (SELECT vipps_reference FROM payments WHERE booking_id IN ({$innB}))",
        "DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id IN ({$innB})",
        "DELETE FROM audit_log WHERE objekt_type = 'booking' AND objekt_id IN ({$innB})",
        "UPDATE bookings SET payment_id = NULL WHERE id IN ({$innB})",
        "DELETE FROM payments WHERE booking_id IN ({$innB})",
        "DELETE FROM bookings WHERE id IN ({$innB}) OR course_session_id = {$okt}",
    ] as $sql) { DB::kjor($sql); }
    DB::kjor('DELETE FROM course_sessions WHERE id = :i', ['i' => $okt]);
    DB::kjor('DELETE FROM courses WHERE id = :i', ['i' => (int) $s['kurs']]);
    DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => (int) $s['admin']]);
    DB::kjor("DELETE FROM audit_log WHERE member_id = :m", ['m' => (int) $s['admin']]);
    DB::kjor('DELETE FROM members WHERE id = :m', ['m' => (int) $s['admin']]);
    foreach ((array) $s['for'] as $n => $v) { $bryter((string) $n, $v === null ? null : (string) $v); }
    foreach ((array) $s['styrFor'] as $n => $v) {
        if ($v === null) { @unlink($styrFil((string) $n)); } else { file_put_contents($styrFil((string) $n), (string) $v); }
    }
    echo json_encode(['ok' => true]); exit;
}
throw new RuntimeException('Ukjent modus');
