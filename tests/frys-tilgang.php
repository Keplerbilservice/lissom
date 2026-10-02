<?php
/**
 * Fryst medlemskap stenger innstempling og medlemstid — mot databasen og
 * endepunktene.
 *
 * Eieren, 2. oktober 2026 (funnet av Codex): et medlem med godkjent frys og
 * en betalt periode kom inn og kunne stemple inn og booke medlemstid, fordi
 * tilgangen bare sjekket betalingen. Min side sa «AKTIVT» og «Stemple inn».
 * Reglene som testes:
 *
 *   1. Fryst = godkjent frys som dekker i dag (Frys::frystNaa), ogsaa foer
 *      members.status er satt til «pause» — men foerst naar den betalte
 *      perioden er over (eieren, 2. oktober 2026): dager som er betalt for,
 *      har medlemmet alltid tilgang i. Et gratismedlem er fryst med en gang.
 *      En frys som er over, en som ikke
 *      har startet, en som venter paa svar og en som er avsluttet, fryser
 *      ikke. «pause» satt for haand uten frys bak seg er IKKE fryst, og har
 *      tilgang som foer (kontrolloeren, 2. oktober 2026).
 *   6. «Flytt medlemskap» i admin tar frysen med: det nye medlemmet er fryst
 *      til samme dato, og aapnes igjen naar frysen er over.
 *   2. POST /api/stempling.php handling=inn avvises (403, fryst) — ingen
 *      oekt lagres. Stemple ut virker for den som staar inne.
 *   3. POST /api/book.php paa «Kun for medlemmer» avvises (403, fryst).
 *   4. Min side virker ellers: meg, stempling (GET), frys og plassene svarer
 *      200, og meg.php sender «fryst» med sluttdatoen. Kurs betalt for seg
 *      staar som foer. Dorkoden og wifi (internInfo) sendes ikke til et
 *      fryst medlem (eieren, 2. oktober 2026), men kommer tilbake etterpaa.
 *   5. Naar frysen er over (ogsaa foer statusen er satt tilbake), stempler
 *      medlemmet inn som foer.
 *
 * Starter sin egen php -S mot tests/nettleser/ruter.php. Sender ingen SMS,
 * e-post eller betaling.
 *
 * Kjor:  php tests/frys-tilgang.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
krev_testdatabase(dirname(__DIR__));
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/betalt-fixture.php';

$ferdig = false;
$server = null;
$rydd = [];
$kurs = 0;
// Dorkode og wifi: testverdier settes bare naar feltene mangler, og fjernes
// igjen etterpaa. Det som staar der fra foer, roeres ikke.
$privatFoer = [];
foreach (['Privat/dorkode', 'Privat/wifi'] as $n) {
    $privatFoer[$n] = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => $n]);
    if ($privatFoer[$n] === null) {
        DB::kjor('INSERT INTO content_blocks (nokkel, verdi) VALUES (:n, :v)', ['n' => $n, 'v' => 'TEST-' . substr($n, 7)]);
    }
}
register_shutdown_function(static function () use (&$ferdig, &$server, &$rydd, &$kurs, $privatFoer): void {
    foreach ($privatFoer as $n => $v) {
        if ($v === null) {
            try { DB::kjor('DELETE FROM content_blocks WHERE nokkel = :n', ['n' => $n]); } catch (Throwable $e) {}
        }
    }
    if ($kurs > 0) {
        foreach (["DELETE FROM bookings WHERE course_id = :k", "DELETE FROM course_sessions WHERE course_id = :k", "DELETE FROM courses WHERE id = :k"] as $sql) {
            try { DB::kjor($sql, ["k" => $kurs]); } catch (Throwable $e) {}
        }
    }
    foreach ($rydd as $id) {
        foreach (['check_ins', 'medlem_frys', 'payments', 'subscriptions', 'sessions', 'bookings'] as $t) {
            try { DB::kjor("DELETE FROM {$t} WHERE member_id = :m", ['m' => $id]); } catch (Throwable $e) {}
        }
        try { DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $id]); } catch (Throwable $e) {}
    }
    if (is_resource($server)) { proc_terminate($server); }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

$oslo = new DateTimeZone('Europe/Oslo');
$idag = (new DateTimeImmutable('now', $oslo))->format('Y-m-d');
$dag = static fn(int $n): string => (new DateTimeImmutable($idag))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');

$plan = (string) DB::verdi("SELECT navn FROM membership_plans WHERE aktiv = 1 AND engangs = 0 AND krever_fast_trekk = 0 AND timer IS NOT NULL ORDER BY timer LIMIT 1");
$tag = 'frys-' . bin2hex(random_bytes(3));
// Forrige maaned: den betalte perioden er over i dag (1. i denne maaneden
// er forfall). Eieren, 2. oktober 2026: stengingen starter foerst da.
$forrigeMnd = (new DateTimeImmutable($idag))->modify('first day of previous month')->format('Y-m-d');
$nytt = static function (string $status, bool $betaltNaa = true) use ($tag, $plan, &$rydd, $forrigeMnd): int {
    $id = DB::settInn('members', [
        'navn' => 'Frystest ' . $tag, 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'telefon' => '+479' . random_int(1000000, 9999999), 'rolle' => 'medlem', 'status' => $status,
        'medlemskap_type' => $plan, 'start_dato' => gmdate('Y-m-01'),
    ]);
    $rydd[] = $id;
    test_betalt_medlem($id);
    if (!$betaltNaa) {
        DB::kjor('UPDATE payments SET gjelder_fra = :f WHERE member_id = :m', ['f' => $forrigeMnd, 'm' => $id]);
    }
    return $id;
};
$frys = static fn(int $m, string $fra, string $til, string $status = 'godkjent'): int => DB::settInn('medlem_frys', [
    'member_id' => $m, 'fra_dato' => $fra, 'til_dato' => $til, 'status' => $status, 'status_for' => 'aktiv',
]);
$rad = static fn(int $id): array => (array) DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);

// ── 1. Hvem er fryst ─────────────────────────────────────────────────────
echo "\n── Frys::frystNaa ───────────────────────────────────────────\n";
sjekk('planen finnes', $plan !== '');

$a = $nytt('aktiv');
sjekk('betalt, ingen frys: ikke fryst', Frys::frystNaa($rad($a)) === null);
sjekk('… og har tilgang', er_aktivt_medlem($rad($a)));

$p = $nytt('pause');
$frys($p, $dag(-10), $dag(60));
sjekk('godkjent frys, men betalt for i dag: IKKE fryst (stengingen venter til perioden er over)',
    Frys::frystNaa($rad($p)) === null, json_encode(Frys::frystNaa($rad($p))));
sjekk('… og har tilgang', er_aktivt_medlem($rad($p)));
$forfall = Medlemskap::dekkerTil(['gjelder_fra' => $idag]);
$sisteBetalt = (new DateTimeImmutable($forfall))->modify('-1 day')->format('Y-m-d');
sjekk("… fortsatt ikke fryst siste betalte dag ($sisteBetalt)", Frys::frystNaa($rad($p), $sisteBetalt) === null);
sjekk("… men fryst fra forfallsdagen ($forfall)", Frys::frystNaa($rad($p), $forfall) === ['til' => $dag(60)]);

$b = $nytt('aktiv', false);
$frys($b, $dag(-3), $dag(20));
sjekk('godkjent frys i dag, perioden er over, status ennaa «aktiv»: fryst til sluttdatoen',
    Frys::frystNaa($rad($b)) === ['til' => $dag(20)], json_encode(Frys::frystNaa($rad($b))));

$c = $nytt('pause', false);
$frys($c, $dag(-10), $dag(15));
sjekk('status «pause», godkjent frys, perioden er over: fryst til sluttdatoen', Frys::frystNaa($rad($c)) === ['til' => $dag(15)]);

$fri = $nytt('pause');
DB::oppdater('members', ['betaler_ikke' => 1], ['id' => $fri]);
DB::kjor('DELETE FROM payments WHERE member_id = :m', ['m' => $fri]);
$frys($fri, $dag(-1), $dag(10));
sjekk('gratismedlem med godkjent frys: fryst (har ikke betalt for dagene)', Frys::frystNaa($rad($fri)) === ['til' => $dag(10)]);

$d = $nytt('pause');
$frys($d, $dag(-30), $dag(-1));
sjekk('frysen var over i gaar, status ikke satt tilbake ennaa: ikke fryst', Frys::frystNaa($rad($d)) === null);

$e = $nytt('aktiv', false);
$frys($e, $dag(1), $dag(30));
sjekk('godkjent frys som starter i morgen: ikke fryst i dag', Frys::frystNaa($rad($e)) === null);
sjekk('… men fryst den dagen den starter', Frys::frystNaa($rad($e), $dag(1)) === ['til' => $dag(30)]);
sjekk('… og ikke dagen etter at den er over', Frys::frystNaa($rad($e), $dag(31)) === null);

$f = $nytt('aktiv');
$frys($f, $dag(-2), $dag(20), 'sokt');
sjekk('soknad som venter paa svar: ikke fryst', Frys::frystNaa($rad($f)) === null);

$g = $nytt('aktiv');
$frys($g, $dag(-5), $dag(20), 'avsluttet');
sjekk('frys avsluttet av verkstedet: ikke fryst', Frys::frystNaa($rad($g)) === null);

$h = $nytt('pause');
sjekk('«pause» satt for haand uten frys: ikke fryst', Frys::frystNaa($rad($h)) === null);
sjekk('… og har tilgang som foer', er_aktivt_medlem($rad($h)));

sjekk('rabatten gis ikke til et fryst medlem (som foer)', !Booking::faarMedlemsrabatt($rad($c)));

// ── 2–5. Endepunktene ────────────────────────────────────────────────────
echo "\n── Endepunktene ─────────────────────────────────────────────\n";
$port = random_int(18200, 18900);
$rot = dirname(__DIR__);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/frys-tilgang-php.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/frys-tilgang-php.log', 'a']],
    $ror, $rot);
for ($i = 0; $i < 40; $i++) {
    if (@fsockopen('127.0.0.1', $port, $en, $to, 0.2)) { break; }
    usleep(150000);
}
$token = static function (int $m): string {
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['token_hash' => hash('sha256', $t), 'member_id' => $m,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    return $t;
};
$kall = static function (string $sti, string $tok, ?array $kropp = null) use ($port): array {
    $hode = "Cookie: " . Sesjon::COOKIE . "=$tok\r\nAccept: application/json\r\n";
    $opt = ['method' => $kropp === null ? 'GET' : 'POST', 'ignore_errors' => true, 'timeout' => 20, 'header' => $hode];
    if ($kropp !== null) {
        $opt['header'] .= "Content-Type: application/json\r\n";
        $opt['content'] = json_encode($kropp);
    }
    $svar = @file_get_contents("http://127.0.0.1:$port$sti", false, stream_context_create(['http' => $opt]));
    $status = 0;
    foreach ($http_response_header ?? [] as $l) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $l, $mm)) { $status = (int) $mm[1]; }
    }
    return ['status' => $status, 'd' => json_decode((string) $svar, true)];
};
$okter = static fn(int $m): int => (int) DB::verdi('SELECT COUNT(*) FROM check_ins WHERE member_id = :m', ['m' => $m]);

$tc = $token($c);
$r = $kall('/api/stempling.php', $tc, ['handling' => 'inn']);
sjekk('fryst: «Stemple inn» avvises med 403', $r['status'] === 403, json_encode($r));
sjekk('… merket fryst, med sluttdatoen', ($r['d']['fryst'] ?? null) === true && ($r['d']['frystTil'] ?? null) === $dag(15));
sjekk('… ingen oekt lagret', $okter($c) === 0);

$r = $kall('/api/stempling.php', $tc);
sjekk('fryst: stemplingsstatusen leses fortsatt (200)', $r['status'] === 200, (string) $r['status']);
$r = $kall('/api/meg.php', $tc);
sjekk('fryst: meg.php svarer 200 med «fryst» og sluttdatoen', $r['status'] === 200 && ($r['d']['fryst']['til'] ?? null) === $dag(15),
    json_encode($r['d']['fryst'] ?? null));
sjekk('… og datoen som tekst', ($r['d']['fryst']['tilTekst'] ?? '') === Booking::norskDatoKort($dag(15)));
sjekk('fryst: ingen dørkode eller wifi i meg.php', ((array) ($r['d']['internInfo'] ?? [])) === [],
    json_encode($r['d']['internInfo'] ?? null));
$r = $kall('/api/mine-plasser.php', $tc);
sjekk('fryst: kursplassene svarer 200', $r['status'] === 200, (string) $r['status']);

$ta = $token($a);
$r = $kall('/api/meg.php', $ta);
sjekk('ikke fryst: meg.php har ikke feltet «fryst»', $r['status'] === 200 && !array_key_exists('fryst', (array) $r['d']));
$kode = (string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Privat/dorkode'");
sjekk('ikke fryst: dørkoden og wifi kommer som før', ($r['d']['internInfo']['dorkode'] ?? null) === $kode
    && array_key_exists('wifi', (array) ($r['d']['internInfo'] ?? [])), json_encode(array_keys((array) ($r['d']['internInfo'] ?? []))));
$r = $kall('/api/meg.php', $token($b));
sjekk('fryst foer statusen er satt: heller ingen dørkode', ((array) ($r['d']['internInfo'] ?? [])) === []);
$r = $kall('/api/meg.php', $token($d));
sjekk('frysen er over: dørkoden er tilbake', ($r['d']['internInfo']['dorkode'] ?? null) === $kode);

// Godkjent frys, men betalt for i dag: alt som foer (eieren, 2. oktober 2026).
$tp = $token($p);
$r = $kall('/api/meg.php', $tp);
sjekk('betalt periode med frys: ingen «fryst», dørkoden kommer', !array_key_exists('fryst', (array) $r['d'])
    && ($r['d']['internInfo']['dorkode'] ?? null) === $kode, json_encode(array_keys((array) $r['d'])));
$r = $kall('/api/stempling.php', $tp, ['handling' => 'inn']);
sjekk('… og stempler inn i dagene som er betalt', $r['status'] === 200 && $okter($p) === 1, json_encode($r));
$kall('/api/stempling.php', $tp, ['handling' => 'ut']);
// Gratismedlem med frys: stengt
$r = $kall('/api/stempling.php', $token($fri), ['handling' => 'inn']);
sjekk('gratismedlem med frys: innstempling avvises (fryst)', $r['status'] === 403 && ($r['d']['fryst'] ?? null) === true, json_encode($r));

// Medlemstid
$kurs = DB::settInn('courses', ['slug' => "frys-medlem-$tag", 'tittel' => 'Frystest medlemstid', 'type' => 'kurs',
    'tema' => 'Kun for medlemmer', 'pris_ore' => 0, 'kapasitet' => 8, 'status' => 'publisert']);
$okt = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => gmdate('Y-m-d 10:00:00', time() + 86400 * 9), 'kapasitet' => 8]);
$r = $kall('/api/book.php', $tc, ['oktId' => $okt, 'antall' => 1]);
sjekk('fryst: medlemstid avvises med 403', $r['status'] === 403 && ($r['d']['fryst'] ?? null) === true, json_encode($r));
sjekk('… ingen paamelding lagret', (int) DB::verdi('SELECT COUNT(*) FROM bookings WHERE course_session_id = :o', ['o' => $okt]) === 0);
$tb = $token($b);
$r = $kall('/api/book.php', $tb, ['oktId' => $okt, 'antall' => 1]);
sjekk('fryst foer statusen er satt: medlemstid avvises ogsaa', $r['status'] === 403 && ($r['d']['fryst'] ?? null) === true, json_encode($r));
$r = $kall('/api/stempling.php', $tb, ['handling' => 'inn']);
sjekk('… og innstempling avvises ogsaa', $r['status'] === 403 && $okter($b) === 0, json_encode($r));

// Stemple ut er aldri sperret
DB::settInn('check_ins', ['member_id' => $c, 'inn_tid' => gmdate('Y-m-d H:i:s', time() - 1800)]);
$r = $kall('/api/stempling.php', $tc, ['handling' => 'ut']);
sjekk('fryst, men staar inne: «Stemple ut» virker', $r['status'] === 200
    && (int) DB::verdi('SELECT COUNT(*) FROM check_ins WHERE member_id = :m AND ut_tid IS NULL', ['m' => $c]) === 0, json_encode($r));

// Perioden etter frysen
$td = $token($d);
$r = $kall('/api/stempling.php', $td, ['handling' => 'inn']);
sjekk('frysen er over (status fortsatt «pause»): stempler inn som foer', $r['status'] === 200 && $okter($d) === 1, json_encode($r));
$kall('/api/stempling.php', $td, ['handling' => 'ut']);
$r = $kall('/api/stempling.php', $ta, ['handling' => 'inn']);
sjekk('aldri fryst: stempler inn som foer', $r['status'] === 200 && $okter($a) === 1, json_encode($r));
$kall('/api/stempling.php', $ta, ['handling' => 'ut']);

// «pause» satt for haand, uten frys: som foer
$th = $token($h);
$r = $kall('/api/stempling.php', $th, ['handling' => 'inn']);
sjekk('«pause» for haand uten frys: stempler inn som foer', $r['status'] === 200 && $okter($h) === 1, json_encode($r));
$kall('/api/stempling.php', $th, ['handling' => 'ut']);
$r = $kall('/api/meg.php', $th);
sjekk('… og faar dørkoden som foer, uten «fryst»', ($r['d']['internInfo']['dorkode'] ?? null) === $kode
    && !array_key_exists('fryst', (array) $r['d']));

// ── 6. Flytt medlemskap tar frysen med ───────────────────────────────────
echo "\n── Flytt medlemskap ─────────────────────────────────────────\n";
$admin = DB::settInn('members', ['navn' => 'Frystest admin ' . $tag, 'epost' => $tag . '-admin@lissom.test',
    'telefon' => '+479' . random_int(1000000, 9999999), 'rolle' => 'admin', 'status' => 'ingen']);
$rydd[] = $admin;
$fra = DB::settInn('members', ['navn' => 'Frystest fra ' . $tag, 'epost' => $tag . '-fra@lissom.test',
    'telefon' => '+479' . random_int(1000000, 9999999), 'rolle' => 'medlem', 'status' => 'pause',
    'medlemskap_type' => $plan, 'start_dato' => gmdate('Y-m-01')]);
$rydd[] = $fra;
$tilM = DB::settInn('members', ['navn' => 'Frystest til ' . $tag, 'epost' => $tag . '-til@lissom.test',
    'telefon' => '+479' . random_int(1000000, 9999999), 'rolle' => 'medlem', 'status' => 'ingen']);
$rydd[] = $tilM;
$pris = (int) DB::verdi('SELECT pris_ore FROM membership_plans WHERE navn = :n', ['n' => $plan]);
$avt = DB::settInn('subscriptions', ['member_id' => $fra, 'plan' => $plan, 'pris_ore' => $pris, 'status' => 'aktiv']);
DB::settInn('payments', ['member_id' => $fra, 'subscription_id' => $avt, 'formal' => 'medlemskap', 'type' => 'epayment',
    'status' => 'betalt', 'belop_ore' => $pris, 'gjelder_fra' => $forrigeMnd, 'vipps_reference' => 'TEST-' . bin2hex(random_bytes(12)),
    'idempotency_key' => Vipps::uuid()]);
$fid = $frys($fra, $dag(-3), $dag(15));
DB::settInn('medlem_frys', ['member_id' => $fra, 'fra_dato' => $dag(-200), 'til_dato' => $dag(-180), 'status' => 'avsluttet', 'status_for' => 'aktiv']);
// Avsluttet frys som overlapper denne maaneden: kan fortsatt hoppe over et trekk.
$denneMnd = (new DateTimeImmutable($idag))->modify('first day of this month')->format('Y-m-d');
$fidAvsl = DB::settInn('medlem_frys', ['member_id' => $fra, 'fra_dato' => $forrigeMnd, 'til_dato' => $denneMnd, 'status' => 'avsluttet', 'status_for' => 'aktiv']);
sjekk('foer flyttingen: den gamle raden er fryst', Frys::frystNaa($rad($fra)) === ['til' => $dag(15)]);
$r = $kall('/api/admin/medlemmer.php', $token($admin), ['handling' => 'flytt-medlemskap', 'fra' => $fra, 'til' => $tilM]);
sjekk('flyttingen gaar gjennom', $r['status'] === 200, json_encode($r));
sjekk('det nye medlemmet staar som «pause»', (string) $rad($tilM)['status'] === 'pause');
sjekk('… og er fryst til samme dato', Frys::frystNaa($rad($tilM)) === ['til' => $dag(15)], json_encode(Frys::frystNaa($rad($tilM))));
sjekk('… frysen er flyttet, den gamle historikken ble igjen',
    (int) DB::verdi('SELECT member_id FROM medlem_frys WHERE id = :i', ['i' => $fid]) === $tilM
    && (int) DB::verdi("SELECT COUNT(*) FROM medlem_frys WHERE member_id = :m AND status = 'avsluttet'", ['m' => $fra]) === 1);
sjekk('… og den avsluttede frysen som overlapper denne maaneden ble med',
    (int) DB::verdi('SELECT member_id FROM medlem_frys WHERE id = :i', ['i' => $fidAvsl]) === $tilM);
sjekk('… og den gamle raden er ikke fryst', Frys::frystNaa($rad($fra)) === null);
$tt = $token($tilM);
$r = $kall('/api/stempling.php', $tt, ['handling' => 'inn']);
sjekk('det nye medlemmet kan ikke stemple inn mens frysen gjelder', $r['status'] === 403 && ($r['d']['fryst'] ?? null) === true, json_encode($r));
// Frysen er over: gjenaapningen finner raden paa det nye medlemmet.
DB::oppdater('medlem_frys', ['fra_dato' => $dag(-20), 'til_dato' => $dag(-1)], ['id' => $fid]);
Frys::gjenapneForfalte();
sjekk('naar frysen er over, aapnes det nye medlemmet igjen («aktiv»)', (string) $rad($tilM)['status'] === 'aktiv');
sjekk('… frysen staar som avsluttet', (string) DB::verdi('SELECT status FROM medlem_frys WHERE id = :i', ['i' => $fid]) === 'avsluttet');
sjekk('… og er ikke fryst lenger', Frys::frystNaa($rad($tilM)) === null);
// Trekket for denne maaneden kommer (som Vipps ville gjort det).
DB::settInn('payments', ['member_id' => $tilM, 'subscription_id' => $avt, 'formal' => 'medlemskap', 'type' => 'recurring_charge',
    'status' => 'betalt', 'belop_ore' => $pris, 'gjelder_fra' => $denneMnd, 'vipps_reference' => 'TEST-' . bin2hex(random_bytes(12)),
    'idempotency_key' => Vipps::uuid()]);
$r = $kall('/api/stempling.php', $tt, ['handling' => 'inn']);
sjekk('… og stempler inn som foer', $r['status'] === 200 && $okter($tilM) === 1, json_encode($r));
$kall('/api/stempling.php', $tt, ['handling' => 'ut']);

echo "\n$ok bestått, $feil feilet\n";
$ferdig = true;
exit($feil === 0 ? 0 : 1);
