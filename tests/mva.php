<?php
/**
 * Omsetningen uten mva, og mva-en paa egen linje.
 *
 * Eieren, 30. september 2026: «omsetning som vises, paa de kontoene som har
 * mva, saa vis uten mva og mva paa egen linje» — og saa: «kontoen total maa
 * vaere alt uten mva, det er dette som er omsetning».
 *
 *   1. delMva(): i oere er brutto = eks + mva for hver sats, og 0 % gir ingen mva.
 *   2. Satsen kommer fra mva-koden i regnskapsoppsettet (3 = 25 %, 6 = ingen).
 *   3. sumUtenMva() er noeyaktig summen av linjene, og eks + mva = brutto.
 *   4. Med en ekte betaling i basen: Oversikt, Oekonomi og dagsoppgjoret
 *      regner fra samme kilde og gir samme tall.
 *   5. Det nye admin: hurtigvalgene lagres per admin og bare kjente valg.
 *
 * Kjor:  php tests/mva.php
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

// ── 1. Delingen ───────────────────────────────────────────────────────
foreach ([25, 15, 12] as $sats) {
    $alle = true;
    for ($ore = 0; $ore <= 300000; $ore += 137) {
        $d = Omsetning::delMva($ore, $sats);
        if ($d['eksOre'] + $d['mvaOre'] !== $ore || $d['mvaSats'] !== $sats || $d['mvaOre'] < 0) { $alle = false; break; }
    }
    sjekk("brutto = eks + mva i oere for $sats % (0–3000 kr)", $alle);
}
$d = Omsetning::delMva(179000, 25);
sjekk('kr 1 790 med 25 % er kr 1 432 + kr 358', $d['eksOre'] === 143200 && $d['mvaOre'] === 35800, json_encode($d));
$d = Omsetning::delMva(280000, 0);
sjekk('uten mva er beloepet uendret og mva 0', $d === ['eksOre' => 280000, 'mvaOre' => 0, 'mvaSats' => 0]);

// ── 2. Satsen fra oppsettet ───────────────────────────────────────────
$kode = static fn(string $n): string => trim((string) Config::hent($n, ''));
sjekk('medlemskap har mva-kode 3 → 25 %', $kode('regnskap_mva_medlemskap') !== '3' || Omsetning::mvaSats('medlemskap') === 25,
    $kode('regnskap_mva_medlemskap'));
sjekk('kurs med kode 6 har ingen mva', $kode('regnskap_mva_kurs') !== '6' || Omsetning::mvaSats('booking') === 0);
sjekk('gavekort (gjeld) har ingen mva', Omsetning::mvaSats('gavekort') === 0);
sjekk('ukjent formaal har ingen mva', Omsetning::mvaSats('noe-annet') === 0);

// ── 3. Summen ─────────────────────────────────────────────────────────
$per = ['booking' => 840000, 'medlemskap' => 179000, 'ordre' => 12345, 'gavekort' => 50000];
$s = Omsetning::sumUtenMva($per);
$linjer = 0; $mva = 0;
foreach ($per as $f => $o) { $m = Omsetning::mvaFor($f, $o); $linjer += $m['eksOre']; $mva += $m['mvaOre']; }
sjekk('omsetningen uten mva er summen av linjene uten mva', $s['eksOre'] === $linjer && $s['mvaOre'] === $mva);
sjekk('eks + mva = det som ble innbetalt', $s['bruttoOre'] === array_sum($per) && $s['eksOre'] + $s['mvaOre'] === array_sum($per));

// ── 4. Samme kilde overalt (koden) ────────────────────────────────────
$les = static fn(string $f): string => (string) file_get_contents(dirname(__DIR__) . '/' . $f);
$ov = $les('api/admin/oversikt.php');
$okn = $les('api/admin/okonomi.php');
$dg = $les('api/admin/dagsoppgjor.php');
sjekk('Oversikt deler hver linje med Omsetning::mvaFor', str_contains($ov, 'Omsetning::mvaFor($nokkel, $etter[$nokkel])'));
sjekk('Oversikt gir omsetningen uten mva (idagEksOre / manedEksOre)', str_contains($ov, "'idagEksOre'") && str_contains($ov, "'manedEksOre'"));
sjekk('… og beholder de gamle feltene med mva (idagOre / manedOre)', str_contains($ov, "'idagOre'    => \$betaltIdag") && str_contains($ov, "'manedOre'   => \$betaltMnd"));
sjekk('Oekonomi gir omsetningEks og mva per konto', str_contains($okn, "'omsetningEks'") && str_contains($okn, 'Omsetning::mvaFor($nokkel, $perFormal[$nokkel])'));
sjekk('Dagsoppgjoret har mva per linje fra samme kilde', str_contains($dg, 'Omsetning::mvaFor((string) $formal, (int) $ore)'));

// ── 4b. Med en ekte betaling: tallene er like ─────────────────────────
$tag = 'mva-' . bin2hex(random_bytes(3));
$fra = gmdate('Y-m-d H:i:s', time() - 60);
$til = gmdate('Y-m-d H:i:s', time() + 3600);
$for = Omsetning::perFormal($fra, $til);
$pay = DB::settInn('payments', ['belop_ore' => 179000, 'status' => 'betalt', 'type' => 'manuell',
    'formal' => 'medlemskap', 'vipps_reference' => 'V-' . $tag, 'idempotency_key' => 'i-' . $tag]);
$etter = Omsetning::perFormal($fra, $til);
sjekk('betalingen kommer med i kilden', (($etter['medlemskap'] ?? 0) - ($for['medlemskap'] ?? 0)) === 179000);
$sE = Omsetning::sumUtenMva($etter); $sF = Omsetning::sumUtenMva($for);
$sats = Omsetning::mvaSats('medlemskap');
$forventet = Omsetning::delMva(179000, $sats);
sjekk('omsetningen uten mva oeker med beloepet uten mva',
    ($sE['eksOre'] - $sF['eksOre']) === $forventet['eksOre'] || $sats !== Omsetning::mvaSats('medlemskap'),
    ($sE['eksOre'] - $sF['eksOre']) . ' mot ' . $forventet['eksOre']);
DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pay]);

// ── 5. Det nye admin: hurtigvalg ──────────────────────────────────────
$a2 = $les('api/admin/admin2.php');
sjekk('admin2.php krever admin', str_contains($a2, '$admin = krev_admin();'));
sjekk('hurtigvalgene lagres per admin-bruker', str_contains($a2, "'admin2_hurtigvalg_' . \$id"));
sjekk('bare kjente hurtigvalg lagres', str_contains($a2, 'in_array($v, ADMIN2_HURTIGVALG, true)'));
sjekk('standard er de fire faste', str_contains($a2, "const ADMIN2_STANDARD = ['startkurs', 'tabetalt', 'nykursdato', 'dagsoppgjor'];"));
sjekk('pilla i det gamle admin er av fra start (bare «ja» er på)', str_contains($a2, "'prove'      => \$bryter('admin2') === 'ja'"));

$ferdig = true;
echo "\n  $ok ok, $feil feil\n";
exit($feil === 0 ? 0 : 1);
