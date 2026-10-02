<?php
/**
 * «Gi tid» (bit T1): tabellen tildelinger og klassen Tildeling, mot databasen.
 *
 * Eieren, 2. oktober 2026: medlemmer kan faa 1 time, tilgang én uke eller
 * tilgang ut maaneden fra admin. Her sjekkes sluttdatoene (norsk tid, ogsaa
 * den 31. og over maanedsskiftet), at trukket og utloept tildeling ikke
 * teller, og at bryteren Vis/tildeling av gir null/0.
 *
 * Kjor:  php tests/tildeling.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

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
register_shutdown_function(static function () use (&$ferdig, $settBryter, $bryterFoer): void {
    $settBryter($bryterFoer === null || $bryterFoer === false ? null : (string) $bryterFoer);
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
$nytt = static function () use ($tag): int {
    return DB::settInn('members', [
        'navn' => 'Tildeling Test', 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'telefon' => '+479' . random_int(1000000, 9999999),
        'rolle' => 'medlem', 'status' => 'aktiv', 'start_dato' => gmdate('Y-m-d'),
    ]);
};
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

$uke = Tildeling::gi($a, 'uke', null, '2026-10-31');
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

// ── Rydd ──────────────────────────────────────────────────────────────
DB::kjor('DELETE FROM tildelinger WHERE member_id IN (:a, :b)', ['a' => $a, 'b' => $b]);
DB::kjor("DELETE FROM audit_log WHERE handling IN ('tildeling_gitt','tildeling_trukket') AND objekt_id IN (:a, :b)", ['a' => $a, 'b' => $b]);
DB::kjor('DELETE FROM members WHERE epost LIKE :t', ['t' => $tag . '%']);

$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
