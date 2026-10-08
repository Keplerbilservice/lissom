<?php
/**
 * Må gjøres, idé 3, 4, 5 og 7 (eieren 08.10.2026).
 *
 *   1. Migrasjon 266 kan kjøres to ganger og lager tabellene/kolonnen.
 *   2. API-et har bryteren «Vis/magjoresmer», grensene med standard 50 % / 21 / 21,
 *      skjul i 7 dager og tåler at migrasjonen ikke er kjørt.
 *   3. Admin-ny bruker sakene (ramme.js) og «Merk ferdig» (Brenninger).
 *
 * Kjor:  php tests/ma-gjores-mer.php
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
$rot = dirname(__DIR__);

echo "\n── Migrasjon 266 ────────────────────────────────────────────\n";
$sql = (string) file_get_contents($rot . '/db/migrations/266_ma_gjores_skjul.sql');
$kjor = static function () use ($sql): ?string {
    try { DB::kobling()->exec($sql); return null; } catch (Throwable $e) { return $e->getMessage(); }
};
sjekk('første kjøring', ($f = $kjor()) === null, (string) $f);
sjekk('andre kjøring', ($f = $kjor()) === null, (string) $f);
sjekk('ma_gjores_skjul finnes', DB::harTabell('ma_gjores_skjul'));
sjekk('brenning_okter finnes', DB::harTabell('brenning_okter'));
sjekk('brenninger.ferdig_at finnes', DB::harKolonne('brenninger', 'ferdig_at'));
sjekk('bare tillegg (ingen DROP/MODIFY)', preg_match('/\b(DROP|MODIFY|DELETE|UPDATE)\b/i', $sql) !== 1);

echo "\n── API ──────────────────────────────────────────────────────\n";
$api = (string) file_get_contents($rot . '/api/admin/ma-gjores-mer.php');
exec('php -l ' . escapeshellarg($rot . '/api/admin/ma-gjores-mer.php') . ' 2>&1', $ut, $kode);
sjekk('php -l', $kode === 0, implode(' ', $ut));
sjekk('bryter Vis/magjoresmer', str_contains($api, "nokkel = 'Vis/magjoresmer'"));
sjekk('standardgrenser 50/21/21', str_contains($api, 'STD_ANDEL = 50') && str_contains($api, 'STD_DAGER = 21') && str_contains($api, 'STD_INNOM = 21'));
sjekk('skjul i 7 dager', str_contains($api, 'SKJUL_DAGER = 7'));
sjekk('tåler manglende migrasjon', str_contains($api, "DB::harTabell('ma_gjores_skjul')") && str_contains($api, "DB::harKolonne('brenninger', 'ferdig_at')"));
sjekk('omsetning fra Omsetning', str_contains($api, 'Omsetning::sumUtenMva(Omsetning::perFormal('));
sjekk('frosne utelates', str_contains($api, "status = 'godkjent' AND fra_dato <= CURDATE() AND til_dato >= CURDATE()"));

echo "\n── Skjul lagres og utløper ──────────────────────────────────\n";
DB::kjor("DELETE FROM ma_gjores_skjul WHERE nokkel = 'tregt:999999999'");
DB::kjor("INSERT INTO ma_gjores_skjul (nokkel, skjult_til) VALUES ('tregt:999999999', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))");
sjekk('skjult nå', DB::verdi("SELECT COUNT(*) FROM ma_gjores_skjul WHERE nokkel = 'tregt:999999999' AND skjult_til > UTC_TIMESTAMP()") == 1);
DB::kjor("UPDATE ma_gjores_skjul SET skjult_til = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE nokkel = 'tregt:999999999'");
sjekk('vises igjen etter fristen', DB::verdi("SELECT COUNT(*) FROM ma_gjores_skjul WHERE nokkel = 'tregt:999999999' AND skjult_til > UTC_TIMESTAMP()") == 0);
DB::kjor("DELETE FROM ma_gjores_skjul WHERE nokkel = 'tregt:999999999'");

echo "\n── Admin-ny ─────────────────────────────────────────────────\n";
$js = (string) file_get_contents($rot . '/admin-ny/ma-gjores-mer.js');
$ramme = (string) file_get_contents($rot . '/admin-ny/ramme.js');
foreach (['node --check admin-ny/ma-gjores-mer.js', 'node --check admin-ny/ramme.js'] as $k) {
    exec('cd ' . escapeshellarg($rot) . ' && ' . $k . ' 2>&1', $u2, $kd);
    sjekk($k, $kd === 0);
}
sjekk('ramme.js henter sakene', str_contains($ramme, "hentMer()") && str_contains($ramme, "...merHandlinger(s,"));
sjekk('mandag over Må gjøres', str_contains($ramme, "mandag?etikett('Mandag'):null,mandag,etikett('Må gjøres')"));
sjekk('hilsen-forslaget', str_contains($js, 'Hei! Vi savner deg på verkstedet. Ovnen er varm og det er god plass på torsdager.'));
sjekk('hilsen krever «Er du sikker?»', str_contains($js, "confirm('Er du sikker?'"));
sjekk('Send til medlemmene sender ikke selv', str_contains($js, "location.hash='#beskjeder?'"));

$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
