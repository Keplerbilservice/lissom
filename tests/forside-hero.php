<?php
declare(strict_types=1);

// Tegn den faktiske hero-malen uten database, betaling eller utsending.
final class Nett {
    public static array $felt = [];
    public static bool $kursvelger = true;
    public static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
    public static function innh(string $n): string { return self::$felt[$n] ?? ''; }
    public static function bryterPaa(string $n): bool { return self::$kursvelger; }
    public static function mobilSkjult(string $n): bool { return false; }
    public static function rot(): string { return dirname(__DIR__); }
}
final class Katalog {
    public static array $kurs = [];
    public static function offentlig(bool $full): array { return self::$kurs; }
}
require __DIR__ . '/../app/nett/deler.php';

function tegnHero(string $bilde): string {
    Nett::$felt = json_decode((string) file_get_contents(__DIR__ . '/../innhold-standard.json'), true);
    Nett::$felt['Forside/0/Hero-bilde'] = $bilde;
    $src = (string) file_get_contents(__DIR__ . '/../app/nett/sider/forside.php');
    $fra = strpos($src, '$heroBilde =');
    $til = strpos($src, '// ── Banneret under heroen');
    if ($fra === false || $til === false) { throw new RuntimeException('Fant ikke hero-malen'); }
    $e = [Nett::class, 'e']; $innh = [Nett::class, 'innh']; $h = '';
    eval(substr($src, $fra, $til - $fra));
    return $h;
}

if (($argv[1] ?? '') === '--html') {
    echo tegnHero('assets_photos_dreiekurs-smil.jpg');
    exit;
}
$feil = 0; $antall = 0;
function sjekk(bool $ok, string $navn): void {
    global $feil, $antall; $antall++;
    if (!$ok) { $feil++; echo "FEIL: {$navn}\n"; }
}
$h = tegnHero('assets_photos_dreiekurs-smil.jpg');
sjekk(str_contains($h, 'src="assets_hero-fellesskap-uten-briller-20261014.webp"'), 'Det tidligere standardbildet bruker valgt motiv med ny cacheadresse');
sjekk(str_contains($h, 'KI-generert motiv'), 'Nytt motiv har korrekt alternativtekst');
sjekk(!str_contains($h, 'heart-logo-mask'), 'Ingen gammel hjertemaske kutter gruppen');
sjekk(str_contains($h, 'class="lx-hero-mobilhjerte" aria-hidden="true"'), 'Det svake dekorhjertet finnes i heroen');
$css = (string) file_get_contents(__DIR__ . '/../nett.css');
sjekk(str_contains($css, '@media (max-width: 760px)') && str_contains($css, '.lx-hero-mobilhjerte > div'), 'Dekorhjertet er avgrenset til mobil-CSS');
sjekk(str_contains($h, 'href="/kurs"'), 'Kursknappen beholdes');
sjekk(str_contains($h, 'href="/kurs/paint-on-pots"'), 'Paint on Pots-lenken beholdes');
sjekk(str_contains($h, 'href="/bedrift?skjema=1"'), 'Gruppeskjemaet beholdes');
sjekk(str_contains($h, 'href="/kurs#kursvelger"'), 'Kursvelgeren beholdes');
sjekk(str_contains($h, '<em style="font-style: italic; font-weight: 600;">'), 'Kursiv overskriftsdel beholdes');
$eg = tegnHero('uploads_eget-foto.jpg');
sjekk(str_contains($eg, 'src="uploads_eget-foto.jpg"'), 'Et annet adminvalgt bilde overskrives ikke');
$tom = tegnHero('');
sjekk(!str_contains($tom, 'class="lx-hero-foto"'), 'Et bevisst tomt bildefelt forblir tomt');
$farlig = tegnHero('foto" onerror="alert(1)');
sjekk(!str_contains($farlig, ' onerror="'), 'Bildeverdien escapes i HTML');
Nett::$kursvelger = false;
sjekk(!str_contains(tegnHero(''), 'href="/kurs#kursvelger"'), 'Skjult kursvelger kommer ikke tilbake');
Nett::$kursvelger = true;
Katalog::$kurs = [['tittel' => 'Paint on Pots', 'datoer' => ['2026-11-01'], 'slug' => 'paint-on-pots-ny']];
sjekk(str_contains(tegnHero(''), 'href="/kurs/paint-on-pots-ny"'), 'Paint on Pots bruker fortsatt katalogens bookingrute');
echo "Hero: {$antall} kontroller, {$feil} feil\n";
exit($feil ? 1 : 0);
