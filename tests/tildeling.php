<?php
/**
 * «Gi tid» (bit T1): tabellen tildelinger og klassen Tildeling, mot databasen.
 *
 * Eieren, 2. oktober 2026: medlemmer kan faa 1 time, tilgang én uke eller
 * tilgang ut maaneden fra admin. Her sjekkes sluttdatoene (norsk tid, ogsaa
 * den 31. og over maanedsskiftet), at trukket og utloept tildeling ikke
 * teller, og at bryteren Vis/tildeling av gir null/0.
 *
 * Kontrolloeren 2. oktober: et medlem med tildelinger slettes ikke helt
 * (ON DELETE RESTRICT); «Slett» i admin anonymiserer, og radene staar igjen.
 * Det sjekkes mot det ekte endepunktet (en PHP-server) i en isolert testbase.
 * Ingen Vipps, ingen e-post, ingen SMS.
 *
 * Kjor:  php tests/tildeling.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);

require $rot . '/app/bootstrap.php';

$ferdig = false;
$bryterFoer = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/tildeling'");
$settBryter = static function (?string $verdi): void {
    if ($verdi === null) {
        DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/tildeling'");
    } else {
        DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/tildeling', :v)
                  ON DUPLICATE KEY UPDATE verdi = :v2", ['v' => $verdi, 'v2' => $verdi]);
    }
};
// Det som maa ryddes, ogsaa om testen stopper underveis.
$medlemmer = []; $servere = []; $admin = 0;
$logg = sys_get_temp_dir() . '/lissom-tildeling-' . bin2hex(random_bytes(4)) . '.log';
register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$servere, &$admin, $logg, $settBryter, $bryterFoer): void {
    foreach ($servere as $p) { proc_terminate($p); proc_close($p); }
    $settBryter($bryterFoer === null || $bryterFoer === false ? null : (string) $bryterFoer);
    foreach ($medlemmer as $m) {
        DB::kjor('DELETE FROM tildelinger WHERE member_id = :m', ['m' => $m]);
        DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'member' AND objekt_id = :m", ['m' => $m]);
        DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => $m]);
        DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $m]);
    }
    if ($admin) {
        DB::kjor('DELETE FROM audit_log WHERE member_id = :m', ['m' => $admin]);
        DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => $admin]);
        DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $admin]);
    }
    @unlink($logg);
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}
$kaster = static function (callable $f): string {
    try { $f(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
};

echo "\n── Gi tid (tildelinger) ─────────────────────────────────────\n";

// ── Migrasjonen ───────────────────────────────────────────────────────
sjekk('tabellen finnes (migrasjon 249)', DB::harTabell('tildelinger'));
$indeks = DB::alle("SHOW INDEX FROM tildelinger WHERE Key_name = 'ix_tildeling_medlem_til'");
sjekk('indeks paa (member_id, til)', count($indeks) === 2
    && $indeks[0]['Column_name'] === 'member_id' && $indeks[1]['Column_name'] === 'til');
foreach (['id', 'member_id', 'type', 'timer', 'fra', 'til', 'gitt_av', 'trukket_at', 'trukket_av', 'created_at'] as $k) {
    if (!DB::harKolonne('tildelinger', $k)) { sjekk("kolonnen $k finnes", false); }
}
$typeKol = (string) DB::verdi("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tildelinger' AND COLUMN_NAME = 'type'");
sjekk('type er ENUM(time, uke, maaned)', $typeKol === "enum('time','uke','maaned')", $typeKol);
$regel = (string) DB::verdi("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tildelinger' AND CONSTRAINT_NAME = 'fk_tildeling_medlem'");
sjekk('fremmednoekkelen er ON DELETE RESTRICT (ikke CASCADE)', $regel === 'RESTRICT', $regel);
$sql = (string) file_get_contents(__DIR__ . '/../db/migrations/249_tildelinger.sql');
sjekk('migrasjonen kan kjoeres to ganger',
    $kaster(static function () use ($sql): void { DB::kjor($sql); DB::kjor($sql); }) === '');

// ── Sluttdatoene (norsk tid) ──────────────────────────────────────────
$datoer = [
    ['uke',    '2026-10-02', '2026-10-08', 'uke: dag 1–7'],
    ['uke',    '2026-10-31', '2026-11-06', 'uke fra den 31.: over maanedsskiftet'],
    ['uke',    '2026-12-28', '2027-01-03', 'uke over nyttaar'],
    ['uke',    '2028-02-25', '2028-03-02', 'uke over skuddaarsdagen'],
    ['maaned', '2026-10-02', '2026-10-31', 'maaned: siste dag i maaneden'],
    ['maaned', '2026-10-31', '2026-10-31', 'maaned gitt den 31.: samme dag'],
    ['maaned', '2026-11-01', '2026-11-30', 'maaned med 30 dager'],
    ['maaned', '2026-02-10', '2026-02-28', 'februar'],
    ['maaned', '2028-02-10', '2028-02-29', 'februar i skuddaar'],
    ['time',   '2026-10-02', '2026-10-31', 'time: gjelder ut maaneden'],
    ['time',   '2026-01-31', '2026-01-31', 'time gitt den 31.: samme dag'],
];
foreach ($datoer as [$type, $fra, $vent, $navn]) {
    $fikk = Tildeling::sluttdato($type, $fra);
    sjekk("$navn ($fra → $vent)", $fikk === $vent, $fikk);
}
sjekk('ukjent type avvises', $kaster(static fn() => Tildeling::sluttdato('aar', '2026-10-02')) !== '');
sjekk('ugyldig dato avvises', $kaster(static fn() => Tildeling::sluttdato('uke', '2026-02-31')) !== '');
$oslo = (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
sjekk('idag() er norsk dato', Tildeling::idag() === $oslo, Tildeling::idag());

// ── Testmedlemmer ─────────────────────────────────────────────────────
$tag = 'td-' . bin2hex(random_bytes(3));
$nytt = static function () use ($tag, &$medlemmer): int {
    $id = DB::settInn('members', [
        'navn' => 'Tildeling Test', 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'telefon' => '+479' . random_int(1000000, 9999999),
        'rolle' => 'medlem', 'status' => 'aktiv', 'start_dato' => gmdate('Y-m-d'),
    ]);
    $medlemmer[] = $id;
    return $id;
};
$detaljer = static fn(string $handling, int $m): ?array => json_decode((string) DB::verdi(
    "SELECT detaljer FROM audit_log WHERE handling = :h AND objekt_type = 'member' AND objekt_id = :m ORDER BY id DESC LIMIT 1",
    ['h' => $handling, 'm' => $m]), true);
$a = $nytt();
$b = $nytt();

// ── Bryteren av (raden mangler) ───────────────────────────────────────
$settBryter(null);
sjekk('bryter mangler: av', !Tildeling::paa());
sjekk('bryter av: gi() avvises', $kaster(static fn() => Tildeling::gi($a, 'uke', null, '2026-10-02')) === 'Gi tid er skrudd av.');
sjekk('bryter av: ingen rad lagret', (int) DB::verdi('SELECT COUNT(*) FROM tildelinger WHERE member_id = :m', ['m' => $a]) === 0);

// ── Bryteren paa ──────────────────────────────────────────────────────
$settBryter('ja');
sjekk('bryter ja: paa', Tildeling::paa());

$uke = Tildeling::gi($a, 'uke', 7, '2026-10-31');
$rad = DB::en('SELECT * FROM tildelinger WHERE id = :i', ['i' => $uke]);
sjekk('uke lagret: fra 31.10, til 06.11, uten timer',
    $rad['fra'] === '2026-10-31' && $rad['til'] === '2026-11-06' && $rad['timer'] === null && $rad['trukket_at'] === null);
sjekk('uke: gjelder dag 1 (31.10)', Tildeling::aktivTilgang($a, '2026-10-31') !== null);
sjekk('uke: gjelder dag 7 (06.11)', (int) (Tildeling::aktivTilgang($a, '2026-11-06')['id'] ?? 0) === $uke);
sjekk('uke: utloept dag 8 (07.11)', Tildeling::aktivTilgang($a, '2026-11-07') === null);
sjekk('uke: ikke foer den startet (30.10)', Tildeling::aktivTilgang($a, '2026-10-30') === null);
sjekk('uke gir ingen ekstra timer', Tildeling::ekstraTimer($a, '2026-11-01') === 0);
sjekk('revidert: tildeling_gitt', (int) DB::verdi(
    "SELECT COUNT(*) FROM audit_log WHERE handling = 'tildeling_gitt' AND objekt_type = 'member' AND objekt_id = :m",
    ['m' => $a]) === 1);
$d = $detaljer('tildeling_gitt', $a);
sjekk('revisjonen sier hvem som ga (av = 7)', ($d['av'] ?? null) === 7 && ($d['tildeling'] ?? null) === $uke, json_encode($d));
sjekk('gitt_av lagret paa raden', (int) $rad['gitt_av'] === 7);

$t1 = Tildeling::gi($b, 'time', null, '2026-10-02');
$t2 = Tildeling::gi($b, 'time', null, '2026-10-15');
sjekk('time lagret med 1 time, til 31.10',
    DB::verdi('SELECT timer FROM tildelinger WHERE id = :i', ['i' => $t1]) == 1
    && DB::verdi('SELECT til FROM tildelinger WHERE id = :i', ['i' => $t1]) === '2026-10-31');
sjekk('to timer gitt: 2 ekstra timer 31.10', Tildeling::ekstraTimer($b, '2026-10-31') === 2, (string) Tildeling::ekstraTimer($b, '2026-10-31'));
sjekk('timene teller ikke foer de ble gitt', Tildeling::ekstraTimer($b, '2026-10-10') === 1, (string) Tildeling::ekstraTimer($b, '2026-10-10'));
sjekk('timene er utloept 01.11', Tildeling::ekstraTimer($b, '2026-11-01') === 0);
sjekk('time gir ingen tilgang', Tildeling::aktivTilgang($b, '2026-10-20') === null);
sjekk('ett medlems tid teller ikke for et annet', Tildeling::ekstraTimer($a, '2026-10-20') === 0);

// ── Trekk tilbake ─────────────────────────────────────────────────────
sjekk('trekk: gaar gjennom', Tildeling::trekk($t1, 42));
$trukket = DB::en('SELECT * FROM tildelinger WHERE id = :i', ['i' => $t1]);
sjekk('trekk: raden staar igjen med trukket_at og trukket_av',
    $trukket !== null && $trukket['trukket_at'] !== null && (int) $trukket['trukket_av'] === 42);
sjekk('trukket time teller ikke: 1 igjen', Tildeling::ekstraTimer($b, '2026-10-31') === 1, (string) Tildeling::ekstraTimer($b, '2026-10-31'));
sjekk('trekk to ganger: nei andre gang', !Tildeling::trekk($t1, 42));
sjekk('trekk av ukjent id: nei', !Tildeling::trekk(PHP_INT_MAX, 42));
sjekk('revidert: tildeling_trukket én gang', (int) DB::verdi(
    "SELECT COUNT(*) FROM audit_log WHERE handling = 'tildeling_trukket' AND objekt_id = :m", ['m' => $b]) === 1);
$d = $detaljer('tildeling_trukket', $b);
sjekk('revisjonen sier hvem som trakk (av = 42)', ($d['av'] ?? null) === 42 && ($d['tildeling'] ?? null) === $t1, json_encode($d));

$mnd = Tildeling::gi($b, 'maaned', null, '2026-10-02');
sjekk('maaned: gjelder 31.10', (int) (Tildeling::aktivTilgang($b, '2026-10-31')['id'] ?? 0) === $mnd);
sjekk('maaned: utloept 01.11', Tildeling::aktivTilgang($b, '2026-11-01') === null);
Tildeling::trekk($mnd, null);
sjekk('trukket maaned gir ingen tilgang', Tildeling::aktivTilgang($b, '2026-10-20') === null);

// Overlapp: den som varer lengst vises.
$kort = Tildeling::gi($a, 'uke', null, '2026-11-02');      // til 08.11
$lang = Tildeling::gi($a, 'maaned', null, '2026-11-02');   // til 30.11
sjekk('overlapp: den som varer lengst', (int) (Tildeling::aktivTilgang($a, '2026-11-03')['id'] ?? 0) === $lang);

// ── Bryteren av igjen, med tildelinger som gjelder ────────────────────
$settBryter('nei');
sjekk('bryter nei: aktivTilgang er null', Tildeling::aktivTilgang($a, '2026-11-03') === null);
sjekk('bryter nei: ekstraTimer er 0', Tildeling::ekstraTimer($b, '2026-10-31') === 0);
sjekk('bryter nei: gi() avvises', $kaster(static fn() => Tildeling::gi($a, 'time', null, '2026-10-02')) !== '');
$settBryter(null);
sjekk('bryter mangler: aktivTilgang er null', Tildeling::aktivTilgang($a, '2026-11-03') === null);
sjekk('bryter mangler: ekstraTimer er 0', Tildeling::ekstraTimer($b, '2026-10-31') === 0);

// ── Ukjent medlem og type ─────────────────────────────────────────────
$settBryter('ja');
sjekk('ukjent medlem avvises', $kaster(static fn() => Tildeling::gi(PHP_INT_MAX, 'uke', null)) === 'Fant ikke medlemmet.');
sjekk('ukjent type avvises i gi()', $kaster(static fn() => Tildeling::gi($a, 'aar', null)) !== '');

// ── Sletting: anonymiseres, tildelingene staar igjen ──────────────────
//
// ON DELETE RESTRICT: basen sier nei til aa slette et medlem med
// tildelinger, og «Slett» i admin (api/admin/medlemmer.php) anonymiserer.
echo "\n── Slett medlem med tildeling (ekte endepunkt) ──\n";
$c = $nytt();
$tc = Tildeling::gi($c, 'maaned', null);
Tildeling::trekk(Tildeling::gi($c, 'time', null), null); // ogsaa en trukket rad
$uten = $nytt(); // kontroll: uten noe som peker paa seg slettes medlemmet helt

$admin = DB::settInn('members', ['navn' => 'Tildeling Admin', 'epost' => $tag . '-admin@lissom.test', 'rolle' => 'admin']);
$token = bin2hex(random_bytes(32));
DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token), 'maate' => 'passord',
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
$s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
$port = (int) substr($adr, strrpos($adr, ':') + 1);
$p = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pipes, $rot);
fclose($pipes[0]); $servere[] = $p;
$klar = false;
for ($v = 0; $v < 40; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }
sjekk('HTTP-server klar', $klar);

$slett = static function (int $m) use ($port, $token): array {
    $k = curl_init('http://127.0.0.1:' . $port . '/api/admin/medlemmer.php');
    curl_setopt_array($k, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => json_encode(['handling' => 'slett', 'medlemId' => $m]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . Config::nettsted(),
            'Cookie: lissom_sesjon=' . $token]]);
    $svar = (string) curl_exec($k);
    $kode = (int) curl_getinfo($k, CURLINFO_RESPONSE_CODE);
    curl_close($k);
    return [$kode, json_decode($svar, true)];
};

[$kode, $svar] = $slett($c);
sjekk('slett svarer 200', $kode === 200 && ($svar['ok'] ?? false) === true, $kode . ' ' . json_encode($svar, JSON_UNESCAPED_UNICODE));
$etter = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $c]);
sjekk('medlemmet med tildeling er IKKE slettet (raden finnes)', $etter !== null);
sjekk('… men anonymisert', $etter !== null && $etter['anonymisert_at'] !== null
    && $etter['navn'] === 'Slettet medlem' && $etter['epost'] === null && $etter['telefon'] === null);
sjekk('… revidert som medlem_anonymisert', (int) DB::verdi(
    "SELECT COUNT(*) FROM audit_log WHERE handling = 'medlem_anonymisert' AND objekt_id = :m", ['m' => $c]) === 1);
sjekk('tildelingsradene staar igjen (2, ogsaa den trukne)',
    (int) DB::verdi('SELECT COUNT(*) FROM tildelinger WHERE member_id = :m', ['m' => $c]) === 2);
sjekk('… uendret', DB::verdi('SELECT trukket_at FROM tildelinger WHERE id = :i', ['i' => $tc]) === null);

[$kode, $svar] = $slett($uten);
sjekk('kontroll: medlem uten tildeling slettes helt', $kode === 200
    && DB::en('SELECT id FROM members WHERE id = :i', ['i' => $uten]) === null, $kode . ' ' . json_encode($svar, JSON_UNESCAPED_UNICODE));

$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
