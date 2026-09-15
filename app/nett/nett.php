<?php
/**
 * Serversidene — kundesidene tegnet ferdig paa serveren.
 *
 * ── Hvorfor ──────────────────────────────────────────────────────────────
 *
 * Nettsida var én app: ett program med alle skjermene (kunde, Min side,
 * admin) som maatte lastes, tolkes og startes foer noe var klikkbart. Maalt
 * 15. september 2026 (Lighthouse, simulert mobil): 65–68 poeng, ~1,2 s
 * blokkert tid bare paa oppstarten — paa hver eneste side.
 *
 * Eieren, samme dag: «hvorfor ikke gjoere dette skikkelig naa, den er jo
 * foreloepig paa en testserver, senere er det for sent. og jeg vil ha den
 * beste siden og den beste brukeropplevelsen».
 *
 * ── Hvordan ──────────────────────────────────────────────────────────────
 *
 * Kundesidene tegnes her, i PHP, av de samme dataene som API-ene sender
 * appen (Katalog, content_blocks, products …). Utseendet er det samme:
 * malene er portert fra lissom-2108.html med de samme inline-stilene, og
 * nett.css er lest ut av den samme fila (bin/nettcss.mjs). Appen beholdes
 * for det som er interaktivt — booking, kasse, Min side, admin — og hentes
 * i bakgrunnen mens man leser, saa «Book» svarer med en gang.
 *
 * side.php spoer Nett::kan() per adresse. Bare adressene i SIDER tegnes
 * her; resten gaar som foer. Gaar noe galt under tegningen, faller side.php
 * tilbake paa appen — en side som tegnes tregt er bedre enn en som ikke
 * tegnes.
 *
 * Se app/nett/deler.php (toppen, bunnen, knappene, kortene) og
 * app/nett/sider/ (én fil per side).
 */

declare(strict_types=1);

require_once __DIR__ . '/deler.php';
require_once __DIR__ . '/kort.php';

final class Nett
{
    /** Adressene som tegnes her, og fila under sider/ som tegner dem. */
    public const SIDER = [
        '/' => 'forside',
    ];

    /** Nettsidas rot — der lissom-2108.html, nett.css, ikonene og bildene ligger. */
    public static function rot(): string
    {
        return defined('NETT_ROT') ? (string) NETT_ROT : dirname(__DIR__, 2);
    }

    /** @var array<string,string>|null content_blocks, alt som er lagret */
    private static ?array $lagret = null;
    /** @var array<string,string>|null innhold-standard.json */
    private static ?array $standard = null;

    public static function kan(string $adresse): bool
    {
        return isset(self::SIDER[$adresse]) && is_file(__DIR__ . '/sider/' . self::SIDER[$adresse] . '.php');
    }

    /**
     * Hele sida som HTML, eller null om adressen ikke tegnes her.
     *
     * @param array<string,mixed> $seo  tittel, meta, canonical, og:… — som
     *   side.php har regnet dem ut (seo-kart.json + det eieren har lagret)
     * @param list<array<string,mixed>> $ld  JSON-LD-blokkene fra Robottekst
     */
    public static function tegn(string $adresse, array $seo, array $ld): ?string
    {
        if (!self::kan($adresse)) {
            return null;
        }
        $fil = __DIR__ . '/sider/' . self::SIDER[$adresse] . '.php';
        /** @var array{kropp:string,aktiv:string,overlay?:bool,skript?:string} $side */
        $side = (static function () use ($fil): array {
            return require $fil;
        })();

        return self::dokument($adresse, $seo, $ld, $side);
    }

    // ── Innholdet eieren redigerer ──────────────────────────────────────

    /** Alt i content_blocks som ikke er internt. Samme grense som api/innhold.php. */
    public static function lagret(): array
    {
        if (self::$lagret !== null) {
            return self::$lagret;
        }
        $ut = [];
        foreach (DB::alle('SELECT nokkel, verdi FROM content_blocks') as $r) {
            $n = (string) $r['nokkel'];
            if (str_starts_with($n, 'Min side/') || str_starts_with($n, 'Privat/')) {
                continue;
            }
            $ut[$n] = (string) $r['verdi'];
        }
        return self::$lagret = $ut;
    }

    /**
     * Teksten i et felt: det eieren har lagret, ellers standarden.
     *
     * «??» og ikke «||», som innh() i nettsida: toemmer eieren et felt med
     * vilje, skal teksten bort — ikke komme tilbake fra malen.
     */
    public static function innh(string $nokkel): string
    {
        $l = self::lagret();
        if (array_key_exists($nokkel, $l)) {
            return $l[$nokkel];
        }
        if (self::$standard === null) {
            $j = json_decode((string) @file_get_contents(self::rot() . '/innhold-standard.json'), true);
            self::$standard = is_array($j) ? $j : [];
        }
        return (string) (self::$standard[$nokkel] ?? '');
    }

    /** Bryterne under ⊙ Synlighet: «Vis/<navn>», mangler = paa. */
    public static function bryterPaa(string $nokkel): bool
    {
        return (self::lagret()['Vis/' . $nokkel] ?? '') !== 'nei';
    }

    /** Skjult paa mobil under Nettsiden → Mobilvisning: «Mobil/<navn>» = skjul. */
    public static function mobilSkjult(string $nokkel): bool
    {
        return (self::lagret()['Mobil/' . $nokkel] ?? '') === 'skjul';
    }

    /**
     * «12 datoer er satt opp framover, fordelt på 5 kurs og events.» —
     * kursTeller i nettsida. Teller katalogen, ikke kortene: et kurs uten
     * datoer i lista er fortsatt et kurs.
     */
    public static function kursTeller(array $kort): string
    {
        $kat = array_values(array_filter(Katalog::offentlig(false), static fn(array $k): bool => ($k['tema'] ?? '') !== 'Kun for medlemmer'));
        $datoer = 0;
        foreach ($kat as $k) {
            $datoer += count($k['datoer'] ?? []);
        }
        if ($datoer === 0) {
            return 'Se hva som er satt opp framover.';
        }
        return $datoer === 1
            ? 'Én dato er satt opp framover.'
            : $datoer . ' datoer er satt opp framover, fordelt på ' . count($kat) . (count($kat) === 1 ? ' kurs.' : ' kurs og events.');
    }

    // ── Verktoey ────────────────────────────────────────────────────────

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Verdien i en CSS-url(): apostrof og bakstrek maa ikke slippe ut. */
    public static function cssUrl(string $sti): string
    {
        return "url('" . str_replace(["\\", "'", '"', "\n", "\r"], ['\\\\', "\\'", '\\"', '', ''], $sti) . "')";
    }

    /**
     * Hvilke stoerrelser hvert bilde finnes i — window.__bildekart i
     * lissom-2108.html, lest herfra saa lista bare finnes ett sted.
     * @return array<string,list<int>>
     */
    public static function bildekart(): array
    {
        static $kart = null;
        if ($kart !== null) {
            return $kart;
        }
        $kart = [];
        $html = @file_get_contents(self::rot() . '/lissom-2108.html');
        if (is_string($html) && preg_match('~window\.__bildekart = (\{.*?\});~s', $html, $m) === 1) {
            $j = json_decode($m[1], true);
            if (is_array($j)) {
                $kart = $j;
            }
        }
        return $kart;
    }

    /** srcset for et bilde, som window.lissomSrcset() i nettsida. Tom om det ikke finnes utgaver. */
    public static function srcset(string $src): string
    {
        if ($src === '') {
            return '';
        }
        if (str_contains($src, 'api/bilde.php')) {
            $skille = str_contains($src, '?') ? '&' : '?';
            return $src . $skille . 'b=400 400w, ' . $src . $skille . 'b=800 800w, ' . $src . ' 1400w';
        }
        $rein = explode('?', (string) preg_replace('~^\.?/~', '', $src))[0];
        $br = self::bildekart()[$rein] ?? null;
        if (!is_array($br) || $br === []) {
            return '';
        }
        $deler = [];
        $siste = $br[count($br) - 1];
        foreach ($br as $b) {
            $deler[] = ($b === $siste ? $rein : substr($rein, 0, -4) . '-' . $b . '.jpg') . ' ' . $b . 'w';
        }
        return implode(', ', $deler);
    }

    public const SIZES_KORT = '(max-width: 760px) 92vw, (max-width: 1200px) 45vw, 400px';
    public const SIZES_STOR = '(max-width: 760px) 100vw, 700px';

    /** Fokuspunktet eieren har valgt for et bilde (bilde_fokus). */
    public static function fokus(string $fil, string $standard = '50% 50%'): string
    {
        static $alle = null;
        if ($alle === null) {
            $alle = Bilder::fokus();
        }
        return $alle[$fil] ?? $standard;
    }

    // ── Dokumentet ──────────────────────────────────────────────────────

    /** nett.css, lest én gang per foresporsel. */
    private static function css(): string
    {
        $css = @file_get_contents(self::rot() . '/nett.css');
        return is_string($css) ? $css : '';
    }

    /** Skriptet til serversidene — meny, samtykke, rotasjoner. */
    private static function skript(): string
    {
        $js = @file_get_contents(self::rot() . '/nett.js');
        return is_string($js) ? $js : '';
    }

    /**
     * @param array<string,mixed> $seo
     * @param list<array<string,mixed>> $ld
     * @param array{kropp:string,aktiv:string,overlay?:bool,skript?:string} $side
     */
    private static function dokument(string $adresse, array $seo, array $ld, array $side): string
    {
        $e = [self::class, 'e'];
        $rot = Config::nettsted();
        $tittel = (string) ($seo['tittel'] ?? 'Keramikkurs i Tønsberg og Vestfold | Lissom Keramikk');
        $meta   = (string) ($seo['meta'] ?? '');
        $canon  = (string) ($seo['canonical'] ?? '');
        $ogT    = (string) ($seo['ogTittel'] ?? $tittel);
        $ogB    = (string) ($seo['ogBeskrivelse'] ?? $meta);
        $ogBilde = (string) ($seo['delingsbilde'] ?? '');
        $egetBilde = $ogBilde !== '';
        if (!$egetBilde) {
            $ogBilde = $rot . '/delingsbilde.jpg';
        }
        $ogAlt = (string) ($seo['altTekst'] ?? 'Deltaker former en bolle på dreieskiva hos Lissom Keramikk i Tønsberg');
        $ikkeISoket = strtolower((string) ($seo['index'] ?? 'Index')) === 'noindex';

        $ldTekst = '';
        if ($ld !== []) {
            $j = json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($j)) {
                $ldTekst = '<script type="application/ld+json">' . str_replace('</', '<\/', $j) . "</script>\n";
            }
        }

        // Appen hentes i bakgrunnen naar sida er lest, saa «Book» og «Min
        // side» svarer med en gang. Lav prioritet: den skal ikke konkurrere
        // med bildene paa sida.
        $app = '/booking';

        $gaId = trim((string) (self::lagret()['Marked/GA-id'] ?? ''));
        $gtmId = trim((string) (self::lagret()['Marked/GTM-id'] ?? ''));
        $maal = json_encode(['ga' => $gaId, 'gtm' => $gtmId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="nb">' . "\n<head>\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="format-detection" content="telephone=no">' . "\n"
            . '<meta name="format-detection" content="date=no">' . "\n"
            . '<meta name="format-detection" content="address=no">' . "\n"
            . '<base href="/">' . "\n"
            . '<link rel="icon" href="/favicon.ico" sizes="any">' . "\n"
            . '<link rel="icon" type="image/svg+xml" href="/favicon.svg">' . "\n"
            . '<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">' . "\n"
            . '<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">' . "\n"
            . '<link rel="manifest" href="/site.webmanifest">' . "\n"
            . '<meta name="theme-color" content="#FBF6EE">' . "\n"
            . '<title>' . $e($tittel) . "</title>\n"
            . ($meta !== '' ? '<meta name="description" content="' . $e($meta) . '">' . "\n" : '')
            . '<meta name="robots" content="' . ($ikkeISoket ? 'noindex,nofollow' : 'index,follow') . '">' . "\n"
            . ($canon !== '' ? '<link rel="canonical" href="' . $e($canon) . '">' . "\n" : '')
            . '<meta property="og:type" content="website">' . "\n"
            . '<meta property="og:site_name" content="Lissom Keramikk &amp; Håndverk">' . "\n"
            . '<meta property="og:locale" content="nb_NO">' . "\n"
            . '<meta property="og:url" content="' . $e($canon !== '' ? $canon : $rot . '/') . '">' . "\n"
            . '<meta property="og:title" content="' . $e($ogT) . '">' . "\n"
            . ($ogB !== '' ? '<meta property="og:description" content="' . $e($ogB) . '">' . "\n" : '')
            . '<meta property="og:image" content="' . $e($ogBilde) . '">' . "\n"
            . ($egetBilde ? '' : '<meta property="og:image:width" content="1200">' . "\n"
                . '<meta property="og:image:height" content="675">' . "\n")
            . '<meta property="og:image:alt" content="' . $e($ogAlt) . '">' . "\n"
            . '<meta name="twitter:card" content="summary_large_image">' . "\n"
            . $ldTekst
            . '<link rel="preload" href="/fonts/bitter-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
            . '<link rel="preload" href="/fonts/alegreya-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
            . '<link rel="preload" href="/fonts/bitter-latin-600-italic.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
            . '<link rel="preload" href="/fonts/alegreya-sans-latin-700-normal.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
            . '<link rel="preload" href="/fonts/bitter-latin-700-normal.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
            . "<style>\n" . self::css() . "\n</style>\n"
            . '<link rel="prefetch" href="' . $e($app) . '" as="document">' . "\n"
            . "</head>\n<body>\n"
            . $side['kropp']
            . Deler::samtykke()
            . '<script>window.lissomMaal = ' . $maal . ";\n" . self::skript() . "\n" . (string) ($side['skript'] ?? '') . "</script>\n"
            . "</body>\n</html>\n";
    }
}
