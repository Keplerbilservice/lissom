<?php
/**
 * «Bestill mer» og «Lav aktivitet», mot databasen.
 *
 * Eieren, 28. september 2026: minimum og maksimum per vare, beskjed til
 * admin paa eller under minimum med «bestill maks − lager». Og medlemmer
 * som ikke har vaert innom paa 14 dager (dagene settes i admin), med en
 * daglig e-post til admin om de nye — ingenting til medlemmene.
 *
 *   php tests/daglig.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$ferdig = false;
register_shutdown_function(static function () use (&$ferdig): void {
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

$tag = 'dg-' . bin2hex(random_bytes(3));

echo "\n── Bestill mer ──────────────────────────────────────────────\n";

sjekk('kolonnene finnes (migrasjon 233)', DB::harKolonne('products', 'lager_min') && DB::harKolonne('products', 'lager_maks'));
sjekk('malene finnes og er paa', (int) DB::verdi(
    "SELECT COUNT(*) FROM notification_templates WHERE navn IN ('intern_bestill_mer', 'intern_lav_aktivitet') AND aktiv = 1") === 2);
sjekk('linja med maks', Lager::linje('Leire', 3, 10) === 'Bestill mer: Leire (3 igjen, bestill 7)', Lager::linje('Leire', 3, 10));
sjekk('linja uten maks', Lager::linje('Leire', 3, null) === 'Bestill mer: Leire (3 igjen)');
sjekk('aldri negativt aa bestille', Lager::aaBestille(12, 10) === 0);

$vare = 'Leire ' . $tag;
$pid = DB::settInn('products', [
    'tittel' => $vare, 'pris_ore' => 25000, 'mva_prosent' => 25, 'lager' => 6,
    'lager_min' => 4, 'lager_maks' => 10, 'kun_medlemmer' => 1, 'status' => 'publisert',
]);
$beskjeder = static fn(): int => (int) DB::verdi(
    'SELECT COUNT(*) FROM notifications WHERE emne = :e', ['e' => 'Bestill mer: ' . $vare . ' (4 igjen, bestill 6)']);

// Salg 6 → 5: over minimum, ingen beskjed.
DB::kjor('UPDATE products SET lager = lager - 1 WHERE id = :p', ['p' => $pid]);
Lager::etterSalg($pid, 1);
sjekk('over minimum: ingen beskjed', $beskjeder() === 0);
sjekk('over minimum: ikke paa flisen', !in_array($pid, array_column(Lager::bestillMer(), 'id'), true));

// Salg 5 → 4: paa minimum, beskjed med «bestill 6».
DB::kjor('UPDATE products SET lager = lager - 1 WHERE id = :p', ['p' => $pid]);
Lager::etterSalg($pid, 1);
sjekk('paa minimum: én beskjed til admin', $beskjeder() >= 1, (string) $beskjeder());
$n = $beskjeder();
$rad = array_values(array_filter(Lager::bestillMer(), static fn($v) => $v['id'] === $pid))[0] ?? null;
sjekk('paa minimum: paa flisen med riktig linje', $rad !== null && $rad['linje'] === 'Bestill mer: ' . $vare . ' (4 igjen, bestill 6)',
    json_encode($rad, JSON_UNESCAPED_UNICODE));

// Salg 4 → 3: allerede under, ingen ny beskjed.
DB::kjor('UPDATE products SET lager = lager - 1 WHERE id = :p', ['p' => $pid]);
Lager::etterSalg($pid, 1);
sjekk('under minimum: ingen ny beskjed for hvert salg', (int) DB::verdi(
    'SELECT COUNT(*) FROM notifications WHERE emne LIKE :e', ['e' => 'Bestill mer: ' . $vare . '%']) === $n);

// Uten minimum: aldri beskjed.
DB::kjor('UPDATE products SET lager = 5, lager_min = NULL WHERE id = :p', ['p' => $pid]);
DB::kjor('UPDATE products SET lager = 0 WHERE id = :p', ['p' => $pid]);
Lager::etterSalg($pid, 5);
sjekk('uten minimum: ingen beskjed og ikke paa flisen', !in_array($pid, array_column(Lager::bestillMer(), 'id'), true)
    && (int) DB::verdi('SELECT COUNT(*) FROM notifications WHERE emne LIKE :e', ['e' => 'Bestill mer: ' . $vare . '%']) === $n);

// Kassa trekker lageret og gir beskjeden (Booking::trekkLager brukes av nettordrene).
DB::kjor('UPDATE products SET lager = 5, lager_min = 4, lager_maks = 10 WHERE id = :p', ['p' => $pid]);
$oid = DB::settInn('orders', [
    'ordrenr' => 'T' . strtoupper(bin2hex(random_bytes(4))), 'status' => 'betalt',
    'sum_ore' => 25000, 'kunde_navn' => 'Test', 'kunde_epost' => $tag . '@lissom.test',
]);
DB::settInn('order_lines', ['order_id' => $oid, 'product_id' => $pid, 'tittel' => $vare, 'antall' => 2, 'pris_ore' => 25000]);
Booking::trekkLager($oid);
sjekk('nettordre trekker lageret', (int) DB::verdi('SELECT lager FROM products WHERE id = :p', ['p' => $pid]) === 3);
sjekk('nettordre under minimum gir beskjed', (int) DB::verdi(
    'SELECT COUNT(*) FROM notifications WHERE emne = :e', ['e' => 'Bestill mer: ' . $vare . ' (3 igjen, bestill 7)']) === 1);

echo "\n── Lav aktivitet ────────────────────────────────────────────\n";

sjekk('standard 14 dager', Aktivitet::dager() === 14 || DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'lav_aktivitet_dager'") !== null);
$nytt = static function (string $status, string $start) use ($tag): int {
    return DB::settInn('members', [
        'navn' => 'Aktivitet ' . bin2hex(random_bytes(2)), 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'telefon' => '+479' . random_int(1000000, 9999999),
        'rolle' => 'medlem', 'status' => $status, 'medlemskap_type' => 'Test', 'start_dato' => $start,
    ]);
};
$dagerSiden = static fn(int $d): string => (new DateTimeImmutable('today', new DateTimeZone('Europe/Oslo')))->modify("-$d days")->format('Y-m-d');
$stemple = static fn(int $id, int $d) => DB::settInn('check_ins', [
    'member_id' => $id, 'inn_tid' => $dagerSiden($d) . ' 10:00:00', 'ut_tid' => $dagerSiden($d) . ' 12:00:00', 'minutter' => 120,
]);

$gammel = Aktivitet::dager();
Aktivitet::settDager(14);
$lenge   = $nytt('aktiv', $dagerSiden(60)); $stemple($lenge, 20);
$nylig   = $nytt('aktiv', $dagerSiden(60)); $stemple($nylig, 3);
$aldri   = $nytt('prove', $dagerSiden(15));
$fersk   = $nytt('aktiv', $dagerSiden(5));
$sluttet = $nytt('oppsagt', $dagerSiden(90));

$ids = static fn(): array => array_column(Aktivitet::lave(), 'dager', 'id');
$l = $ids();
sjekk('20 dager siden sist: paa lista, med 20 dager', ($l[$lenge] ?? null) === 20, json_encode($l[$lenge] ?? null));
sjekk('innom for 3 dager siden: ikke paa lista', !isset($l[$nylig]));
sjekk('aldri stemplet, medlem i 15 dager: paa lista', ($l[$aldri] ?? null) === 15);
sjekk('medlem i 5 dager: ikke paa lista', !isset($l[$fersk]));
sjekk('sluttet: ikke paa lista', !isset($l[$sluttet]));

Aktivitet::settDager(25);
$l = $ids();
sjekk('dagene settes i admin: 25 gir ikke 20-dagers', !isset($l[$lenge]) && Aktivitet::dager() === 25);
Aktivitet::settDager(14);

// Daglig e-post: bare de nye, og ikke de samme i morgen.
DB::kjor("DELETE FROM innstillinger WHERE nokkel = 'lav_aktivitet_meldt'");
$foer = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE emne LIKE 'Lav aktivitet:%'");
$nye = Aktivitet::meldNye();
sjekk('foerste dag: de paa lista meldes', $nye >= 2, (string) $nye);
sjekk('foerste dag: én e-post til admin', (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE emne LIKE 'Lav aktivitet:%'") > $foer);
sjekk('e-posten nevner medlemmet', (int) DB::verdi(
    "SELECT COUNT(*) FROM notifications WHERE emne LIKE 'Lav aktivitet:%' AND tekst LIKE :n",
    ['n' => '%' . DB::verdi('SELECT navn FROM members WHERE id = :i', ['i' => $lenge]) . '%']) >= 1);
sjekk('neste dag: ingen nye, ingen ny e-post', Aktivitet::meldNye() === 0);
sjekk('ingenting sendt til medlemmene', (int) DB::verdi(
    'SELECT COUNT(*) FROM notifications WHERE mottaker LIKE :t', ['t' => $tag . '%']) === 0);

// Malen av: ingen e-post, og ingen merkes som meldt.
DB::kjor("UPDATE notification_templates SET aktiv = 0 WHERE navn = 'intern_lav_aktivitet'");
DB::kjor("DELETE FROM innstillinger WHERE nokkel = 'lav_aktivitet_meldt'");
sjekk('malen av: ingen e-post', Aktivitet::meldNye() === 0);
DB::kjor("UPDATE notification_templates SET aktiv = 1 WHERE navn = 'intern_lav_aktivitet'");
sjekk('malen paa igjen: de kommer med', Aktivitet::meldNye() >= 2);
Aktivitet::settDager($gammel);

// ── Rydd ──────────────────────────────────────────────────────────────
DB::kjor("DELETE FROM check_ins WHERE member_id IN ($lenge, $nylig, $aldri, $fersk, $sluttet)");
DB::kjor("DELETE FROM members WHERE epost LIKE :t", ['t' => $tag . '%']);
DB::kjor('DELETE FROM order_lines WHERE order_id = :o', ['o' => $oid]);
DB::kjor('DELETE FROM orders WHERE id = :o', ['o' => $oid]);
DB::kjor('DELETE FROM products WHERE id = :p', ['p' => $pid]);

$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
