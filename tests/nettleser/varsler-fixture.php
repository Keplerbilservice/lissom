<?php
/** Synthetic reminder/certificate fixtures. Requires the guarded local test DB. */
declare(strict_types=1);
require __DIR__ . '/testdatabase.php';
$settings = krev_testdatabase(dirname(__DIR__, 2));
require dirname(__DIR__, 2) . '/app/bootstrap.php';
krev_testdatabase_identitet($settings, DB::verdi('SELECT DATABASE()'));
if (Config::hent('send_i_test', false)) { throw new RuntimeException('Cron test must not send externally'); }
$mode = $argv[1] ?? ''; $s = json_decode($argv[2] ?? '{}', true) ?: [];
$pdo = DB::kobling();
if (in_array($mode, ['seed', 'cleanup'], true)) {
    $pdo->beginTransaction();
    register_shutdown_function(static function () use ($pdo): void {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
    });
}
if ($mode === 'seed') {
    $tag = 'e2e-varsler-' . bin2hex(random_bytes(5));
    $s = ['tag' => $tag, 'templates' => DB::alle("SELECT navn, aktiv, kanal FROM notification_templates WHERE navn IN ('kurspaaminnelse','anmeldelse')"),
        'settings' => DB::alle("SELECT nokkel,verdi FROM innstillinger WHERE nokkel IN ('anmeldelse_lenke','anmeldelse_timer')")];
    DB::kjor("UPDATE notification_templates SET aktiv = 1, kanal = 'epost' WHERE navn IN ('kurspaaminnelse','anmeldelse')");
    foreach (['anmeldelse_lenke' => 'https://example.test/anmeldelse', 'anmeldelse_timer' => '3'] as $k => $v) {
        DB::kjor('INSERT INTO innstillinger(nokkel,verdi) VALUES(:k,:v) ON DUPLICATE KEY UPDATE verdi=VALUES(verdi)', ['k' => $k, 'v' => $v]);
    }
    $s['course'] = DB::settInn('courses', ['slug' => $tag, 'tittel' => 'Nattkontroll Dreiekurs', 'type' => 'kurs', 'status' => 'publisert', 'pris_ore' => 10000, 'kapasitet' => 12]);
    foreach (['tomorrow' => 20, 'past' => -24, 'old' => -100, 'future' => 96] as $name => $hours) {
        $s[$name] = DB::settInn('course_sessions', ['course_id' => $s['course'], 'start_tid' => gmdate('Y-m-d H:i:s', time() + $hours * 3600),
            'slutt_tid' => gmdate('Y-m-d H:i:s', time() + ($hours + 2) * 3600), 'kapasitet' => 12]);
    }
    foreach ([['reminder','tomorrow','betalt',20,0], ['recent','tomorrow','betalt',2,0], ['unpaid','tomorrow','reservert',20,0],
        ['certificate','past','betalt',20,0], ['revoked','past','betalt',20,1], ['unpaidpast','past','reservert',20,0],
        ['old','old','betalt',20,0], ['future','future','betalt',20,0]] as [$name,$session,$status,$days,$revoked]) {
        $s['bookings'][$name] = DB::settInn('bookings', ['course_id' => $s['course'], 'course_session_id' => $s[$session], 'antall' => 1,
            'gjest_navn' => 'Nattkontroll ' . $name, 'gjest_epost' => $tag . '-' . $name . '@e2e.lissom.test', 'belop_ore' => 10000,
            'status' => $status, 'created_at' => gmdate('Y-m-d H:i:s', time() - $days * 86400), 'bevis_sperret' => $revoked]);
    }
} elseif ($mode === 'inspect') {
    $s['notifications'] = DB::alle('SELECT id,mal,kanal,mottaker,emne,tekst,html,status FROM notifications WHERE mottaker LIKE :tag ORDER BY id', ['tag' => $s['tag'] . '%@e2e.lissom.test']);
    $s['rows'] = DB::alle('SELECT id,bevis_kode,bevis_sperret FROM bookings WHERE course_id=:id', ['id' => $s['course']]);
    $s['reminderStamp'] = DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id=:id', ['id' => $s['tomorrow']]);
    $s['reviewStamp'] = DB::verdi('SELECT anmeldelse_sendt_at FROM course_sessions WHERE id=:id', ['id' => $s['past']]);
} elseif ($mode === 'revoke') {
    DB::oppdater('bookings', ['bevis_sperret' => 1], ['id' => $s['bookings']['certificate']]);
} elseif ($mode === 'deliver') {
    $port = (int) ($s['smtpPort'] ?? 0);
    if ($port < 1024 || $port > 65535 || !preg_match('/^e2e-varsler-[a-f0-9]{10}$/', $s['tag'] ?? '')) { throw new RuntimeException('Unknown local receiver'); }
    Config::last(array_replace($settings, ['send_i_test' => true, 'smtp_vert' => '127.0.0.1', 'smtp_port' => $port,
        'smtp_sikkerhet' => 'ingen', 'smtp_bruker' => 'smtp-test', 'smtp_passord' => 'smtp-test', 'epost_fra' => 'sender@example.test']));
    $pdo->beginTransaction();
    try {
        DB::kjor("UPDATE notifications SET send_etter=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE status='ko' AND mottaker NOT LIKE :tag", ['tag' => $s['tag'] . '%@e2e.lissom.test']);
        DB::kjor("UPDATE notifications SET send_etter=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE status='ko' AND mottaker LIKE :tag", ['tag' => $s['tag'] . '%@e2e.lissom.test']);
        $s['delivery'] = Utsending::tomKo(100);
    } finally { $pdo->rollBack(); }
} elseif ($mode === 'cleanup') {
    if (!preg_match('/^e2e-varsler-[a-f0-9]{10}$/', $s['tag'] ?? '')) { throw new RuntimeException('Unknown fixture'); }
    DB::kjor('DELETE FROM notifications WHERE mottaker LIKE :tag', ['tag' => $s['tag'] . '%@e2e.lissom.test']);
    DB::kjor('DELETE FROM bookings WHERE course_id=:id', ['id' => $s['course']]);
    DB::kjor('DELETE FROM course_sessions WHERE course_id=:id', ['id' => $s['course']]);
    DB::kjor('DELETE FROM courses WHERE id=:id AND slug=:slug', ['id' => $s['course'], 'slug' => $s['tag']]);
    foreach ($s['templates'] as $t) { DB::oppdater('notification_templates', ['aktiv' => $t['aktiv'], 'kanal' => $t['kanal']], ['navn' => $t['navn']]); }
    DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('anmeldelse_lenke','anmeldelse_timer')");
    foreach ($s['settings'] as $r) { DB::settInn('innstillinger', $r); }
} else { throw new RuntimeException('Unknown mode'); }
if ($pdo->inTransaction()) { $pdo->commit(); }
echo json_encode($s, JSON_UNESCAPED_UNICODE);
