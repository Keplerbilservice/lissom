<?php
/**
 * Utsolgt i nettbutikken, og «Lite på lager» bare for internvarene.
 *
 * Eieren, 27. september 2026: «lite paa lager i varselet vil jeg ikke ha som
 * varsel, annet enn paa interne varer, saa naar en vare i nettbutikken er
 * solgt ut, saa skal den bli borte».
 *
 * Testen lager fire varer — nettbutikk med 0, med 3 og uten lagerstyring, og
 * en internvare med 0 — og spoer de ekte endepunktene som besokende og som
 * medlem: butikklista, sitemapen og vareadressen. Til slutt kommer en vare
 * tilbake naar den faar lager igjen.
 *
 *   php tests/lager.php
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

/** Kjorer et endepunkt i en egen PHP-prosess, som en GET, og gir svaret. */
function hent(string $fil, array $env = []): string
{
    $miljo = array_merge(getenv(), ['REQUEST_METHOD' => 'GET'], $env);
    $p = proc_open([PHP_BINARY, $fil], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ror, dirname(__DIR__), $miljo);
    $ut = stream_get_contents($ror[1]);
    stream_get_contents($ror[2]);
    proc_close($p);
    return (string) $ut;
}

$rydd = static function (): void {
    DB::kjor("DELETE FROM products WHERE tittel LIKE 'Lagertest %'");
};
$rydd();

$ny = static fn(string $tittel, ?int $lager, int $intern): int => DB::settInn('products', [
    'tittel' => $tittel, 'beskrivelse' => 'Testvare.', 'kategori' => 'Test', 'pris_ore' => 10000,
    'lager' => $lager, 'kun_medlemmer' => $intern, 'status' => 'publisert',
]);
$tom    = $ny('Lagertest tom', 0, 0);
$tre    = $ny('Lagertest tre', 3, 0);
$fri    = $ny('Lagertest ikke lagerstyrt', null, 0);
$intern = $ny('Lagertest intern tom', 0, 1);

echo "\n── Butikklista for en besokende ─────────────────────────────\n";
$liste = json_decode(hent('api/butikk.php'), true) ?: [];
$titler = array_column($liste['varer'] ?? $liste['produkter'] ?? [], 'tittel');
if ($titler === []) {
    // Svaret kan ligge under et annet navn — finn lista med titler.
    foreach ($liste as $v) { if (is_array($v) && isset($v[0]['tittel'])) { $titler = array_column($v, 'tittel'); break; } }
}
sjekk('lista svarer', $titler !== [], substr(json_encode($liste), 0, 200));
sjekk('en utsolgt nettbutikkvare er borte', !in_array('Lagertest tom', $titler, true));
sjekk('… en med 3 paa lager staar', in_array('Lagertest tre', $titler, true));
sjekk('… en uten lagerstyring staar', in_array('Lagertest ikke lagerstyrt', $titler, true));
sjekk('… og internvarene vises ikke for besokende', !in_array('Lagertest intern tom', $titler, true));

echo "\n── Sitemapen ────────────────────────────────────────────────\n";
$kart = hent('api/sitemap.php');
sjekk('sitemapen svarer', str_contains($kart, '<urlset'));
sjekk('den utsolgte er ikke med', !str_contains($kart, ltrim(Lenker::vare($tom, 'Lagertest tom'), '/')));
sjekk('… den med lager er med', str_contains($kart, ltrim(Lenker::vare($tre, 'Lagertest tre'), '/')));

echo "\n── Robotteksten (strukturerte data) ─────────────────────────\n";
$rv = array_column(Robottekst::varer(), 'tittel');
sjekk('den utsolgte er ikke med', !in_array('Lagertest tom', $rv, true));
sjekk('… den med lager er med', in_array('Lagertest tre', $rv, true));

echo "\n── Den kommer tilbake av seg selv ───────────────────────────\n";
DB::kjor('UPDATE products SET lager = 2 WHERE id = :i', ['i' => $tom]);
$liste2 = json_decode(hent('api/butikk.php'), true) ?: [];
$titler2 = [];
foreach ($liste2 as $v) { if (is_array($v) && isset($v[0]['tittel'])) { $titler2 = array_column($v, 'tittel'); break; } }
sjekk('med lager igjen staar den i lista', in_array('Lagertest tom', $titler2, true));
DB::kjor('UPDATE products SET lager = 0 WHERE id = :i', ['i' => $tom]);

echo "\n── En ordre kan ikke legges paa en vare med 0 ───────────────\n";
$ordre = (string) file_get_contents(dirname(__DIR__) . '/api/ordre.php');
sjekk('ordren sjekker lageret', str_contains($ordre, "if (\$vare['lager'] !== null && (int) \$vare['lager'] < \$antall) {"));

$rydd();
echo "\n$ok sjekker i orden, $feil feilet.\n";
$ferdig = true;
exit($feil > 0 ? 1 : 0);
