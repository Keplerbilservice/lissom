<?php
/**
 * «nydatoer» i api/admin/kurs.php (kalenderplanen, bølge 3, 3. oktober 2026) over HTTP mot testserveren.
 *
 *   (a) bryteren «Vis/kalendergjenta» mangler eller er «nei»: 409, ingenting lagres
 *   (b) på: datoene legges inn med kursholder og plasser; stengt dag, dublett i lista,
 *       en dato som finnes, og en ugyldig tid hoppes over og meldes tilbake
 *   (c) én transaksjon: feiler én innlegging midt i, lagres ingen av datoene
 *   (d) «nydato» er uendret: ny dato, samme igjen (feil), ferieadvarsel og ferieOk,
 *       avlyst tom dato gjenbrukes, avlyst med påmeldte gir samme melding, flere dager blir samlinger,
 *       og den virker med bryteren av
 *
 * Kjøres av tests/kalender-gjenta.mjs via tests/nettleser/kjor.sh. Alt merkes «GjTest-» og ryddes.
 */
declare(strict_types=1);
require __DIR__ . '/nettleser/testdatabase.php';
krev_testdatabase(dirname(__DIR__));
require dirname(__DIR__) . '/app/bootstrap.php';

$adresse = (string) (getenv('E2E_ADRESSE') ?: '');
if ($adresse === '') {
    throw new RuntimeException('Krever E2E_ADRESSE (kjør via tests/nettleser/kjor.sh)');
}
$tag = 'GjTest-' . bin2hex(random_bytes(3));
$ferdig = false;
$kurs = 0; $admin = 0; $holder = 0; $stengtDato = null; $bIder = [];
$bryterFor = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/kalendergjenta'");
$bryterFor = $bryterFor === null || $bryterFor === false ? null : (string) $bryterFor;
$bryter = static function (?string $v): void {
    if ($v === null) { DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/kalendergjenta'"); return; }
    DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/kalendergjenta', :v)
              ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)", ['v' => $v]);
};
register_shutdown_function(static function () use (&$ferdig, &$kurs, &$admin, &$holder, &$stengtDato, &$bIder, $bryter, $bryterFor): void {
    try { DB::kjor('DROP TRIGGER IF EXISTS gj_test_feil'); } catch (Throwable $e) {}
    try { $bryter($bryterFor); } catch (Throwable $e) {}
    try {
        if ($bIder) { DB::kjor('DELETE FROM bookings WHERE id IN (' . implode(',', array_map('intval', $bIder)) . ')'); }
        if ($kurs) {
            DB::kjor('DELETE FROM okt_samlinger WHERE session_id IN (SELECT id FROM course_sessions WHERE course_id = :k)', ['k' => $kurs]);
            DB::kjor('DELETE FROM course_sessions WHERE course_id = :k', ['k' => $kurs]);
            DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kurs]);
        }
        if ($stengtDato) { DB::kjor('DELETE FROM apningstider WHERE dato = :d', ['d' => $stengtDato]); }
        if ($holder) { DB::kjor('DELETE FROM kursholdere WHERE id = :i', ['i' => $holder]); }
        if ($admin) {
            DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => $admin]);
            DB::kjor('DELETE FROM audit_log WHERE member_id = :m', ['m' => $admin]);
            DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $admin]);
        }
    } catch (Throwable $e) { echo "  FEIL  oppryddingen: " . $e->getMessage() . "\n"; }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

$admin = DB::settInn('members', ['navn' => $tag . ' admin', 'epost' => strtolower($tag) . '.admin@e2e.lissom.test', 'rolle' => 'admin', 'status' => 'aktiv']);
$token = bin2hex(random_bytes(32));
DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
$kall = static function (array $kropp) use ($adresse, $token): array {
    $u = parse_url($adresse);
    $c = curl_init($adresse . '/api/admin/kurs.php');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($kropp), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_RESOLVE => [$u['host'] . ':' . $u['port'] . ':127.0.0.1'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . $adresse, 'Cookie: lissom_sesjon=' . $token]]);
    $svar = (string) curl_exec($c);
    $s = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$s, json_decode($svar, true) ?: []];
};

$kurs = DB::settInn('courses', ['slug' => strtolower($tag), 'tittel' => "$tag Dreiekurs", 'type' => 'kurs', 'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
$holder = DB::settInn('kursholdere', ['navn' => "$tag Holder", 'aktiv' => 1]);
$oslo = new DateTimeZone('Europe/Oslo');
$dag = static fn(int $n): string => (new DateTimeImmutable('today', $oslo))->modify("+{$n} days")->format('Y-m-d');
$utc = static fn(string $l): string => (new DateTimeImmutable($l, $oslo))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$okter = static fn(): array => DB::alle('SELECT id, start_tid, slutt_tid, kapasitet, status, kursholder_id, ferie_ok FROM course_sessions WHERE course_id = :k ORDER BY start_tid', ['k' => $kurs]);
// Datoene ligger langt fram, så ingen andre testdata står på dem.
$d1 = $dag(200); $d2 = $dag(207); $d3 = $dag(214); $d4 = $dag(221); $d5 = $dag(228);
$stengtDato = $d2;
DB::kjor('DELETE FROM apningstider WHERE dato = :d', ['d' => $d2]);
DB::settInn('apningstider', ['dato' => $d2, 'stengt' => 1, 'merknad' => $tag]);

echo "\n── (a) bryteren av: 409 ──\n";
$bryter(null);
[$s, $r] = $kall(['handling' => 'nydatoer', 'kursId' => $kurs, 'datoer' => [['start' => "$d1 18:00", 'slutt' => "$d1 20:00"]]]);
sjekk('mangler raden: 409', $s === 409, $s . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE));
$bryter('nei');
[$s] = $kall(['handling' => 'nydatoer', 'kursId' => $kurs, 'datoer' => [['start' => "$d1 18:00", 'slutt' => "$d1 20:00"]]]);
sjekk('«nei»: 409', $s === 409, (string) $s);
sjekk('… ingenting lagret', $okter() === []);

echo "\n── (b) på: lagt inn og hoppet over ──\n";
$bryter('ja');
DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $utc("$d4 18:00"), 'slutt_tid' => $utc("$d4 20:00"), 'kapasitet' => 8]);
[$s, $r] = $kall(['handling' => 'nydatoer', 'kursId' => $kurs, 'kursholderId' => $holder, 'kapasitet' => 6, 'datoer' => [
    ['start' => "$d1 18:00", 'slutt' => "$d1 20:00"],
    ['start' => "$d2 18:00", 'slutt' => "$d2 20:00"],
    ['start' => "$d1 18:00", 'slutt' => "$d1 20:00"],
    ['start' => "$d3 18:00", 'slutt' => "$d3 20:00"],
    ['start' => "$d4 18:00", 'slutt' => "$d4 20:00"],
    ['start' => "$d5 18:00", 'slutt' => "$d5 17:00"],
    ['start' => 'tull', 'slutt' => ''],
]]);
sjekk('svarer 200', $s === 200, $s . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE));
$inn = array_map(static fn($x) => $x['start'], $r['lagtInn'] ?? []);
sjekk('lagt inn: første og tredje uke', $inn === ["$d1 18:00", "$d3 18:00"], json_encode($inn));
$grunner = array_map(static fn($x) => $x['start'] . '=' . $x['grunn'], $r['hoppet'] ?? []);
sjekk('hoppet over: stengt, dublett, finnes, ugyldig', $grunner === ["$d2 18:00=stengt", "$d1 18:00=dublett", "$d4 18:00=finnes", "$d5 18:00=ugyldig", 'tull=ugyldig'], json_encode($grunner, JSON_UNESCAPED_UNICODE));
$rader = $okter();
sjekk('tre økter i basen (to nye + den som fantes)', count($rader) === 3, (string) count($rader));
$ny = array_values(array_filter($rader, static fn($o) => in_array($o['start_tid'], [$utc("$d1 18:00"), $utc("$d3 18:00")], true)));
sjekk('… de nye har kursholder og plasser', count($ny) === 2 && array_reduce($ny, static fn($a, $o) => $a
    && (int) $o['kursholder_id'] === $holder && (int) $o['kapasitet'] === 6, true), json_encode($ny));
sjekk('… sluttida er lagret riktig', $ny[0]['slutt_tid'] === $utc("$d1 20:00"), (string) ($ny[0]['slutt_tid'] ?? ''));
sjekk('… ingen dato på den stengte dagen', !in_array($utc("$d2 18:00"), array_column($rader, 'start_tid'), true));
[$s, $r] = $kall(['handling' => 'nydatoer', 'kursId' => $kurs, 'kursholderId' => 999999, 'datoer' => [['start' => "$d5 18:00"]]]);
sjekk('ukjent kursholder stopper hele kallet', $s === 400 && count($okter()) === 3, $s . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE));
[$s] = $kall(['handling' => 'nydatoer', 'kursId' => $kurs, 'datoer' => []]);
sjekk('tom liste: 400', $s === 400, (string) $s);

echo "\n── (c) én transaksjon ──\n";
$feilTid = $utc("$d5 19:00");
DB::kobling()->exec("CREATE TRIGGER gj_test_feil BEFORE INSERT ON course_sessions FOR EACH ROW
    BEGIN IF NEW.course_id = {$kurs} AND NEW.start_tid = '{$feilTid}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'testfeil midt i'; END IF; END");
try {
    [$s] = $kall(['handling' => 'nydatoer', 'kursId' => $kurs, 'datoer' => [
        ['start' => $dag(235) . ' 18:00'], ['start' => "$d5 19:00"], ['start' => $dag(242) . ' 18:00'],
    ]]);
} finally {
    DB::kjor('DROP TRIGGER IF EXISTS gj_test_feil');
}
sjekk('feil midt i: kallet feiler', $s >= 500, (string) $s);
sjekk('… og den første datoen er rullet tilbake', count($okter()) === 3, (string) count($okter()));

echo "\n── (d) «nydato» er uendret ──\n";
$bryter(null);
$n1 = $dag(250);
[$s, $r] = $kall(['handling' => 'nydato', 'kursId' => $kurs, 'start' => "{$n1}T18:00", 'slutt' => "{$n1}T20:00", 'kapasitet' => 5]);
sjekk('ny dato med bryteren av: ok med oktId og naar', $s === 200 && ($r['oktId'] ?? 0) > 0 && ($r['naar'] ?? '') === Booking::norskDato($utc("$n1 18:00")), $s . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE));
$o1 = DB::en('SELECT * FROM course_sessions WHERE id = :i', ['i' => (int) ($r['oktId'] ?? 0)]) ?? [];
sjekk('… plasser 5 og kursholder fra kurset/standarden', (int) ($o1['kapasitet'] ?? 0) === 5
    && ($o1['kursholder_id'] === null ? Kursholder::forKurs($kurs) === null : (int) $o1['kursholder_id'] === Kursholder::forKurs($kurs)));
[$s, $r] = $kall(['handling' => 'nydato', 'kursId' => $kurs, 'start' => "{$n1}T18:00", 'slutt' => "{$n1}T20:00"]);
sjekk('samme igjen: samme feilmelding som før', $s === 400 && ($r['feil'] ?? '') === 'Kurset går alt på denne datoen og tida. Velg en annen.', $s . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE));
[$s, $r] = $kall(['handling' => 'nydato', 'kursId' => $kurs, 'start' => "{$d2}T10:00", 'slutt' => "{$d2}T12:00"]);
sjekk('stengt dag: ferieadvarsel 409', $s === 409 && ($r['ferieAdvarsel'] ?? false) === true, $s . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE));
[$s, $r] = $kall(['handling' => 'nydato', 'kursId' => $kurs, 'start' => "{$d2}T10:00", 'slutt' => "{$d2}T12:00", 'ferieOk' => 1]);
$fo = DB::en('SELECT ferie_ok FROM course_sessions WHERE id = :i', ['i' => (int) ($r['oktId'] ?? 0)]);
sjekk('… med ferieOk: lagret som unntak', $s === 200 && $fo !== null && (!Ferie::harUnntak() || (int) $fo['ferie_ok'] === 1), $s . ' ' . json_encode($fo));
// Avlyst og tom: plassen frigjøres.
$n2 = $dag(257);
$avlyst = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $utc("$n2 18:00"), 'kapasitet' => 8, 'status' => 'avlyst']);
[$s, $r] = $kall(['handling' => 'nydato', 'kursId' => $kurs, 'start' => "{$n2}T18:00", 'slutt' => "{$n2}T20:00"]);
sjekk('avlyst tom dato: den gamle slettes og en ny lages', $s === 200 && DB::en('SELECT id FROM course_sessions WHERE id = :i', ['i' => $avlyst]) === null && ($r['oktId'] ?? 0) > 0, $s . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE));
// Avlyst med påmeldte: samme melding som før.
$n3 = $dag(264);
$avlyst2 = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $utc("$n3 18:00"), 'kapasitet' => 8, 'status' => 'avlyst']);
$bIder[] = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $avlyst2, 'gjest_navn' => "$tag Pål", 'gjest_epost' => strtolower($tag) . '.pal@e2e.lissom.test', 'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert']);
[$s, $r] = $kall(['handling' => 'nydato', 'kursId' => $kurs, 'start' => "{$n3}T18:00", 'slutt' => "{$n3}T20:00"]);
sjekk('avlyst med påmeldte: samme melding som før', $s === 400 && ($r['feil'] ?? '') === 'Datoen ble avlyst, og påmeldingene står fortsatt på den. Datoen kan ikke lages på nytt før de er ute — velg et annet klokkeslett så lenge.', $s . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE));
// Flere dager: samlinger.
$n4 = $dag(271); $n4b = $dag(272);
[$s, $r] = $kall(['handling' => 'nydato', 'kursId' => $kurs, 'start' => "{$n4}T10:00", 'slutt' => "{$n4}T14:00", 'kursholderId' => $holder,
    'dager' => [['dato' => $n4b, 'fra' => '10:00', 'til' => '15:00']]]);
$sa = DB::alle('SELECT dato FROM okt_samlinger WHERE session_id = :s ORDER BY dato', ['s' => (int) ($r['oktId'] ?? 0)]);
$o4 = DB::en('SELECT kursholder_id FROM course_sessions WHERE id = :i', ['i' => (int) ($r['oktId'] ?? 0)]);
sjekk('flere dager: to samlinger og valgt kursholder', $s === 200 && array_column($sa, 'dato') === [$n4, $n4b] && (int) ($o4['kursholder_id'] ?? 0) === $holder, $s . ' ' . json_encode($sa));

$ferdig = true;
echo "\n  $ok av " . ($ok + $feil) . " kalender-gjenta-kontroller bestått\n";
exit($feil === 0 ? 0 : 1);
