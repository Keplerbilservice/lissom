<?php
/**
 * Testdata til nettlesertestene, og oppryddingen etterpaa.
 *
 *   php tests/nettleser/seed.php          → lager data, skriver JSON
 *   php tests/nettleser/seed.php --rydd   → fjerner alt med e2e-merket
 *
 * Alt faar e-post som slutter paa @e2e.lissom.test og kurs med slug som
 * begynner paa «e2e-», saa oppryddingen aldri kan ta noe annet. Innlogging
 * skjer med ferdige sesjoner (samme tabell som ekte innlogging) — testen
 * trenger ingen passord.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/testdatabase.php';
try {
    $testoppsett = krev_testdatabase(dirname(__DIR__, 2));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
require dirname(__DIR__, 2) . '/app/bootstrap.php';
// Kontroller også tilkoblingen appen faktisk bruker, før første write.
try {
    krev_testdatabase_identitet($testoppsett, DB::verdi('SELECT DATABASE()'));
} catch (Throwable $e) {
    fwrite(STDERR, "Appens testdatabase kunne ikke bekreftes.\n");
    exit(1);
}

$rydd = static function (): void {
    $m = array_column(DB::alle("SELECT id FROM members WHERE epost LIKE '%@e2e.lissom.test'"), 'id');
    $k = array_column(DB::alle("SELECT id FROM courses WHERE slug LIKE 'e2e-%'"), 'id');
    $in = static fn(array $a): string => $a ? implode(',', array_map('intval', $a)) : '0';
    $mi = $in($m); $ki = $in($k);
    $bok = array_column(DB::alle("SELECT id FROM bookings WHERE course_id IN ($ki) OR member_id IN ($mi)
        OR gjest_epost LIKE '%@e2e.lissom.test'"), 'id');
    $bi = $in($bok);
    $kort = array_column(DB::alle("SELECT id FROM gift_cards WHERE kode LIKE 'E2E-%'"), 'id');
    $gi = $in($kort);
    $pay = array_column(DB::alle("SELECT id FROM payments WHERE member_id IN ($mi) OR registrert_av IN ($mi)
        OR booking_id IN ($bi)"), 'id');
    $pay = array_merge($pay, array_column(DB::alle("SELECT payment_id AS id FROM bookings WHERE id IN ($bi) AND payment_id IS NOT NULL"), 'id'));
    $pi = $in(array_unique($pay));
    $prov = static function (string $sql): void { try { DB::kjor($sql); } catch (Throwable $e) { /* tabellen kan mangle */ } };
    $prov("UPDATE bookings SET payment_id = NULL WHERE id IN ($bi) OR payment_id IN ($pi)");
    $prov("UPDATE gift_cards SET payment_id = NULL WHERE id IN ($gi) OR payment_id IN ($pi)");
    $prov("UPDATE orders SET payment_id = NULL WHERE payment_id IN ($pi)");
    $prov("DELETE FROM gift_card_uses WHERE gift_card_id IN ($gi) OR booking_id IN ($bi)");
    $prov("DELETE FROM notifications WHERE mottaker LIKE '%@e2e.lissom.test' OR (ref_type = 'booking' AND ref_id IN ($bi))");
    $prov("DELETE FROM payments WHERE id IN ($pi)");
    $prov("DELETE FROM waitlist WHERE epost LIKE '%@e2e.lissom.test'");
    $prov("DELETE FROM bookings WHERE id IN ($bi)");
    $prov("DELETE FROM course_sessions WHERE course_id IN ($ki)");
    $prov("DELETE FROM courses WHERE id IN ($ki)");
    $prov("DELETE FROM gift_cards WHERE id IN ($gi)");
    $prov("DELETE FROM vervinger WHERE verver_id IN ($mi) OR venn_id IN ($mi)");
    $prov("DELETE FROM medlemsgave_bruk WHERE member_id IN ($mi)");
    $prov("DELETE FROM medlemsgaver WHERE member_id IN ($mi)");
    $prov("DELETE FROM medlemsforslag WHERE member_id IN ($mi)");
    $prov("DELETE FROM medlemsordrer WHERE medlem_id IN ($mi) OR epost LIKE '%@e2e.lissom.test'");
    $prov("DELETE FROM subscriptions WHERE member_id IN ($mi)");
    $prov("DELETE FROM sessions WHERE member_id IN ($mi)");
    $prov("DELETE FROM audit_log WHERE member_id IN ($mi)");
    $prov("DELETE FROM members WHERE id IN ($mi)");
};

if (in_array('--rydd', $argv, true)) {
    $rydd();
    echo "ryddet\n";
    exit;
}

$rydd();
DB::kjor('DELETE FROM rate_limits');

$tag = bin2hex(random_bytes(3));
$epost = static fn(string $hvem): string => "$hvem-$tag@e2e.lissom.test";
$sesjon = static function (int $id): string {
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['token_hash' => hash('sha256', $t), 'member_id' => $id,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
    return $t;
};
$dag = static fn(int $d, string $kl = '10:00:00'): string => gmdate('Y-m-d', time() + 86400 * $d) . ' ' . $kl;

$aar = (string) DB::verdi('SELECT navn FROM membership_plans WHERE binding_mnd >= 12 ORDER BY sortering LIMIT 1');

$admin = DB::settInn('members', ['navn' => 'E2E Admin', 'epost' => $epost('admin'), 'rolle' => 'admin', 'status' => 'aktiv']);
$medlem = DB::settInn('members', ['navn' => 'Kari E2E', 'epost' => $epost('kari'), 'telefon' => '+4790000001',
    'rolle' => 'medlem', 'status' => 'aktiv', 'medlemskap_type' => $aar]);

$kurs = DB::settInn('courses', ['slug' => "e2e-dreie-$tag", 'tittel' => 'E2E Dreiekurs', 'type' => 'kurs',
    'pris_ore' => 280000, 'kapasitet' => 8, 'status' => 'publisert']);
$annet = DB::settInn('courses', ['slug' => "e2e-annet-$tag", 'tittel' => 'E2E Annet kurs', 'type' => 'kurs',
    'pris_ore' => 90000, 'kapasitet' => 8, 'status' => 'publisert']);
$okter = [];
foreach ([-5, -1, 10, 17, 24] as $d) {
    $okter[$d] = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $dag($d), 'kapasitet' => 8]);
}
$annenOkt = DB::settInn('course_sessions', ['course_id' => $annet, 'start_tid' => $dag(12), 'kapasitet' => 8]);

$flytt = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $okter[10], 'antall' => 1,
    'gjest_navn' => 'Flytt Deltaker', 'gjest_epost' => $epost('flytt'), 'gjest_telefon' => '+4790000002',
    'belop_ore' => 280000, 'status' => 'betalt']);
$betal = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $okter[10], 'antall' => 1,
    'gjest_navn' => 'Delt Betaler', 'gjest_epost' => $epost('betaler'),
    'belop_ore' => 280000, 'status' => 'reservert']);

// Kurs som har vaert: den ene fem dager siden («Vil du fortsette med leire?»),
// den andre i gaar («Be om en anmeldelse»).
foreach ([-5 => 'fortsett', -1 => 'anmeldelse'] as $d => $hvem) {
    DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $okter[$d], 'antall' => 1,
        'gjest_navn' => 'Tidligere ' . $hvem, 'gjest_epost' => $epost($hvem),
        'belop_ore' => 280000, 'status' => 'betalt']);
}

// Kasse-kortet paa Oversikt viser de som har staatt lengst ubetalt. Med
// andre testers plasser i basen maa denne vaere eldst for aa komme med.
DB::kjor("UPDATE bookings SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 400 DAY) WHERE id = :i", ['i' => $betal]);

$gavekort = 'E2E-' . strtoupper($tag);
DB::settInn('gift_cards', ['kode' => $gavekort, 'opprinnelig_ore' => 100000, 'saldo_ore' => 100000,
    'gyldig_til' => gmdate('Y-m-d', time() + 86400 * 365), 'status' => 'aktivt', 'opprinnelse' => 'gitt']);

echo json_encode([
    'tag' => $tag,
    'admin' => ['id' => $admin, 'token' => $sesjon($admin)],
    'medlem' => ['id' => $medlem, 'token' => $sesjon($medlem), 'epost' => $epost('kari')],
    'kurs' => $kurs, 'annetKurs' => $annet,
    'okter' => ['for' => $okter[-5], 'a' => $okter[10], 'b' => $okter[17], 'c' => $okter[24], 'annet' => $annenOkt],
    'flytt' => $flytt, 'betal' => $betal, 'gavekort' => $gavekort, 'aarsplan' => $aar,
], JSON_UNESCAPED_UNICODE);
