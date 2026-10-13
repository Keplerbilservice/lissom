<?php
/**
 * Tekstpakken fra 13. oktober 2026 (migrasjon 277 og malene i kursmal.php).
 *
 * Hentetid «noen uker» paa kurs der man former leire, «tre uker» paa Paint on
 * Pots, og merket «Nybegynner» paa dreiekurset.
 *
 * Kjor:  php tests/tekstpakke.php
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

echo "\n── Tekstpakken 13. oktober ──────────────────────────────────\n";

$dreie = Kursmal::forKurs(['tittel' => 'Dreiekurs', 'tema' => 'Dreiing']);
sjekk('dreiekurs: noen uker', str_contains((string) $dreie['ferdigTid'], 'henting etter noen uker'));
sjekk('dreiekurs: Med hjem', str_starts_with((string) $dreie['medHjem'], 'Det du lager på dreieskiven, ferdig glasert og brent.'));
$pop = Kursmal::forKurs(['tittel' => 'Paint on Pots', 'tema' => 'Paint on pots']);
sjekk('Paint on Pots: tre uker', str_contains((string) $pop['ferdigTid'], 'henting etter tre uker'));
$popEvents = Kursmal::forKurs(['tittel' => 'Paint on Pots', 'tema' => 'Events']);
sjekk('Paint on Pots med Events-malen: tre uker', str_contains((string) $popEvents['ferdigTid'], 'henting etter tre uker'));
$sip = Kursmal::forKurs(['tittel' => 'Sip & Clay', 'tema' => 'Events']);
sjekk('Sip & Clay: noen uker', str_contains((string) $sip['ferdigTid'], 'henting etter noen uker'));

// Merket: migrasjon 277 setter nivaa_tekst paa dreiekurset. Kurssida viser
// $kat['nivaaTekst'] i merket, med text-transform: uppercase → NYBEGYNNER.
$harDreie = DB::verdi("SELECT COUNT(*) FROM courses WHERE slug = 'dreiekurs'");
if ((int) $harDreie > 0) {
    sjekk('dreiekurset har nivaa_tekst «Nybegynner»',
        DB::verdi("SELECT nivaa_tekst FROM courses WHERE slug = 'dreiekurs'") === 'Nybegynner');
    foreach (Katalog::offentlig(false) as $k) {
        if ((string) ($k['slug'] ?? '') === 'dreiekurs') {
            sjekk('katalogen gir merket «Nybegynner»', ($k['nivaaTekst'] ?? '') === 'Nybegynner', (string) ($k['nivaaTekst'] ?? ''));
        }
    }
} else {
    echo "  --    ingen kurs med slug dreiekurs i testbasen; merket sjekkes ikke her\n";
}
$kilde = (string) file_get_contents(__DIR__ . '/../app/nett/sider/kursside.php');
sjekk('merket tegnes med store bokstaver', str_contains($kilde, '$nivaa = (string) ($kat[\'nivaaTekst\']') && str_contains($kilde, 'text-transform: uppercase'));

$ferdig = true;
echo "\n  {$ok} ok, {$feil} feil\n";
exit($feil > 0 ? 1 : 0);
