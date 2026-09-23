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
require_once __DIR__ . '/mal.php';

final class Nett
{
    /** Adressene som tegnes her, og fila under sider/ som tegner dem. */
    public const SIDER = [
        '/'       => 'forside',
        '/kurs'   => 'kurs',
        '/events' => 'kurs',
        '/nyheter' => 'nyheter',
        '/nyttig-info' => 'nyttig',
        '/medlemskap' => 'medlemskap',
        '/om-oss' => 'omoss',
        '/sporsmal-og-svar' => 'sporsmal',
        '/personvern' => 'personvern',
        '/kursene-vare' => 'kursoversikt',
        '/paint-on-pots' => 'paintonpots',
        // Gavekortsida paa serveren fra 23. september 2026 (LCP 3,9 → 1,2 s
        // maalt med struping). ?kjop=1 gaar til appen, se Nett::kan().
        '/gavekort' => 'gavekort',
        '/kontakt' => 'kontakt',
        '/bedrift' => 'bedrift',
        '/vilkar' => 'vilkar',
        '/ferdigbrent' => 'ferdigbrent',
        '/kalender' => 'kalender',
        '/nyttig-info/brennetabell'   => 'plakat',
        '/nyttig-info/medlemsinfo'    => 'plakat',
        '/nyttig-info/trivselsregler' => 'plakat',
    ];

    /** Adressen som tegnes naa — for maler som tegner flere adresser. */
    public static string $adresse = '/';
    /** Kursets adresse (slug) naar adressen er /kurs/<slug>. */
    public static string $slug = '';
    /** Varens nummer naar adressen er /butikk/<id>-<navn>, ellers 0. */
    public static int $vareId = 0;

    /** Fila som tegner adressen, eller null. Kurssidene kjennes paa moensteret. */
    private static function fil(string $adresse): ?string
    {
        if (isset(self::SIDER[$adresse])) {
            return self::SIDER[$adresse];
        }
        if (preg_match('~^/kurs/([a-z0-9-]+)$~i', $adresse) === 1) {
            return 'kursside';
        }
        if (preg_match('~^/nyheter/([a-z0-9-]+)$~i', $adresse) === 1) {
            return 'nyheter';
        }
        // Butikken, og hver vare sin egen adresse. Tallet er det som
        // gjelder; navnet bak staar der for menneskene, som i appen.
        //
        // /butikk-ny sto her alene mens sida ble vist fram. Eieren saa den
        // 23. september 2026 og sa ja; da overtok den butikken. Testadressen
        // staar igjen saa neste endring kan vises fram paa samme maate.
        if (preg_match('~^/butikk(-ny)?(/\\d+(-[^/]*)?)?$~', $adresse) === 1) {
            return 'butikk';
        }
        return null;
    }

    /**
     * Sporringen som betyr noe for sida (filtrene paa kurssida), renset:
     * bare kjente noekler, i fast rekkefoelge. Brukes i bufferen ogsaa.
     * @return array<string,string>
     */
    public static function sporring(): array
    {
        $ut = [];
        // «kal» er maaneden kalenderen i datovelgeren paa kurssida viser
        // (?kal=2026-11). Sto den ikke her, ble sida hentet fra bufferen
        // uten maaned — maalt 21. september 2026 med forgjengeren ?mnd=.
        foreach (['tema', 'tid', 'kategori', 'uke', 'kal'] as $n) {
            $v = $_GET[$n] ?? '';
            if (is_string($v) && $v !== '' && mb_strlen($v) <= 40) {
                $ut[$n] = $v;
            }
        }
        return $ut;
    }

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
        // Kurssida sender folk inn i appen med ?dag=, ?alle=1, ?book=1 eller
        // ?venteliste=1 — da skal appen ha adressen, ikke serversida.
        // ?kjop=1 er gavekortsida som gaar videre til betalingen (nett.js).
        // ?vare=1 er «Legg i kurv» paa varesida: kurven bor i appen, og
        // nett.js har ingen. Serveren sier nei, og appen aapner varen.
        foreach (['dag', 'alle', 'book', 'venteliste', 'plan', 'skjema', 'kjop', 'vare'] as $n) {
            if (isset($_GET[$n])) {
                return false;
            }
        }
        $fil = self::fil($adresse);
        return $fil !== null && is_file(__DIR__ . '/sider/' . $fil . '.php');
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
        // Ferdig tegnet for kort tid siden? Da gaar den samme ut igjen.
        //
        // Sida spoer basen etter katalogen, aapningstidene, varene og
        // innholdet — 30–40 spoerringer. Maalt paa test.lissom.no 15.
        // september 2026: tid til foerste byte 150–860 ms, og 1,9 s i ett
        // maal paa PC. Appens HTML var statisk og gikk ut paa ~130 ms. Med
        // bufferen gaar de fleste besoek ut paa samme tid; det som er nytt i
        // basen (en plass som ble tatt, en tekst som ble endret) er ute
        // innen ett minutt.
        self::$adresse = $adresse;
        // Verkstedet leser aldri fra bufferen, og skriver aldri til den.

        // Sidene kan vise noe bare admin skal se — en artikkel som ligger
        // som kladd, aapnet med «Se hvordan den blir». Bufferen kjenner
        // bare adressen, ikke hvem som spurte, saa den ferdige sida ville
        // gaatt ut til alle som kom innom det neste minuttet. En kladd som
        // lekker ut fordi eieren forhaandsviste den er ikke en treghet, det
        // er en publisering ingen ba om.

        // Det koster ingenting: verkstedet er én bruker, og de 30-40
        // sporringene bufferen sparer gjelder de mange som ikke er logget
        // inn.
        $forAdmin = Sesjon::erAdmin();
        $buffer = $forAdmin
            ? null
            : self::bufferFil($adresse . '?' . http_build_query(self::sporring()));
        if ($buffer !== null && is_file($buffer) && filemtime($buffer) > time() - self::BUFFER_SEK) {
            $lest = @file_get_contents($buffer);
            if (is_string($lest) && $lest !== '') {
                return $lest;
            }
        }

        $fil = __DIR__ . '/sider/' . self::fil($adresse) . '.php';
        self::$slug = preg_match('~^/kurs/([a-z0-9-]+)$~i', $adresse, $m) === 1 ? $m[1] : '';
        self::$vareId = preg_match('~^/butikk(?:-ny)?/(\\d+)~', $adresse, $mv) === 1 ? (int) $mv[1] : 0;
        /** @var array{kropp:string,aktiv:string,hode?:string,skript?:string}|null $side */
        $side = (static function () use ($fil): ?array {
            return require $fil;
        })();
        // Malen fant ikke det den skulle tegne (et kurs som ikke finnes):
        // appen tar over, som foer, og svarer 404 der.
        if ($side === null) {
            return null;
        }

        $html = self::dokument($adresse, $seo, $ld, $side);
        if ($buffer !== null) {
            // Skriv til en midlertidig fil og bytt: en leser skal aldri faa
            // en halv fil.
            $tmp = $buffer . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $html) !== false) {
                @rename($tmp, $buffer);
            }
        }
        return $html;
    }

    /** Hvor lenge en tegnet side gaar ut igjen som den er. */
    public const BUFFER_SEK = 60;

    /** Fila sida bufres i, eller null naar det ikke finnes noe sted aa skrive. */
    private static function bufferFil(string $adresse): ?string
    {
        $mappe = sys_get_temp_dir();
        if (!is_dir($mappe) || !is_writable($mappe)) {
            return null;
        }
        // Miljoeet er med: test og produksjon kan dele tmp paa webhotellet.
        return $mappe . '/lissom-nett-' . hash('xxh128', Config::miljo() . '|' . Config::nettsted() . '|' . $adresse) . '.html';
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
        // Testsiden maaler ingenting — se api/innhold.php.
        if (Config::erUtvikling()) {
            unset($ut['Marked/GA-id'], $ut['Marked/GTM-id'], $ut['Marked/Meta-piksel']);
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

    // ── Artikkeltekst ───────────────────────────────────────────────────

    /**
     * Artikkelteksten som HTML — parseInnhold() i nettsida, med bildene
     * (artikkel_bilder) lagt etter avsnittet de peker paa, som nyLestBlokker.
     *
     * Et avsnitt (skilt med tom linje) med «# », «## », «- » eller «|»
     * tegnes som mellomtittel, punktliste eller tabell; et vanlig avsnitt
     * beholder linjeskiftene sine (pre-wrap), som foer.
     *
     * @param list<array{fil:string,etter:int,bildetekst:string,alt:string,plassering:string,storrelse:string}> $bilder
     */
    public static function artikkelBlokker(string $innhold, array $bilder = []): string
    {
        $e = [self::class, 'e'];
        $avsnitt = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $innhold) ?: [])));
        $ut = '';
        $bildeEtter = static function (int $n) use ($bilder, $e): string {
            $h = '';
            foreach ($bilder as $b) {
                if ((int) ($b['etter'] ?? 0) !== $n) {
                    continue;
                }
                $px = ['liten' => 200, 'medium' => 360, 'stor' => 560][$b['storrelse'] ?? ''] ?? 360;
                $figur = 'margin: 0 0 var(--space-6);';
                $figur .= match ($b['plassering'] ?? '') {
                    'venstre'   => ' float: left; width: ' . $px . 'px; max-width: 45%; margin-right: var(--space-6);',
                    'hoyre'     => ' float: right; width: ' . $px . 'px; max-width: 45%; margin-left: var(--space-6);',
                    'midtstilt' => ' width: ' . $px . 'px; max-width: 100%; margin-left: auto; margin-right: auto;',
                    default     => ' width: 100%;',
                };
                $h .= '<figure class="lx-artfigur" style="' . $figur . '"><img src="' . $e((string) $b['fil']) . '" alt="' . $e((string) ($b['alt'] ?? '')) . '" style="display: block; width: 100%; height: auto; border-radius: var(--radius-md);" loading="lazy" decoding="async">'
                    . ((string) ($b['bildetekst'] ?? '') !== '' ? '<figcaption style="margin-top: 8px; font-size: var(--text-sm); color: var(--text-muted); text-wrap: pretty;">' . $e((string) $b['bildetekst']) . '</figcaption>' : '')
                    . '</figure>';
            }
            return $h;
        };
        $ut .= $bildeEtter(0);
        foreach ($avsnitt as $i => $t) {
            $ut .= preg_match('/^(#{1,2} |- |\|)/m', $t) === 1
                ? self::tegnBlokker($t)
                : '<p style="margin: 0 0 var(--space-5); font-size: var(--text-lg); line-height: 1.75; color: var(--text-body); white-space: pre-wrap; text-wrap: pretty;">' . $e($t) . '</p>';
            $ut .= $bildeEtter($i + 1);
        }
        foreach ($bilder as $b) {
            if ((int) ($b['etter'] ?? 0) > count($avsnitt)) {
                $ut .= $bildeEtter((int) $b['etter']);
            }
        }
        return $ut;
    }

    /** parseInnhold() i nettsida, for ett avsnitt med formatmerker. */
    private static function tegnBlokker(string $tekst): string
    {
        $e = [self::class, 'e'];
        $ut = '';
        $liste = [];
        $tabell = [];
        $flush = static function () use (&$ut, &$liste, &$tabell, $e): void {
            if ($liste !== []) {
                $ut .= '<ul style="margin: 0 0 var(--space-5); padding: 0 0 0 var(--space-6); display: flex; flex-direction: column; gap: 6px; font-size: var(--text-lg); line-height: 1.6; color: var(--text-body);">';
                foreach ($liste as $p) {
                    $ut .= '<li>' . $e($p) . '</li>';
                }
                $ut .= '</ul>';
                $liste = [];
            }
            if ($tabell !== []) {
                $ut .= '<div style="max-width: 680px; border: 1px solid var(--border-subtle); border-radius: var(--radius-xl, 22px); overflow: hidden; background: var(--surface-card); box-shadow: var(--shadow-sm); margin: 0 0 var(--space-6);">';
                foreach ($tabell as $i => $celler) {
                    $hode = $i === 0;
                    $radStil = 'display: grid; grid-template-columns: 110px 150px 1fr; align-items: center; gap: var(--space-4); padding: ' . ($hode ? '12px 18px' : '9px 18px') . '; border-bottom: 1px solid var(--border-subtle); font-size: var(--text-base); font-weight: ' . ($hode ? '700' : '400') . '; background: ' . ($hode ? 'var(--lissom-brown)' : 'transparent') . '; color: ' . ($hode ? 'var(--clay-50)' : 'var(--text-body)') . ';';
                    $c1Stil = $hode ? '' : 'font-family: var(--font-display); font-weight: 800; font-size: var(--text-lg); color: var(--text-heading);';
                    $n = (int) preg_replace('/[^0-9]/', '', $celler[1] ?? '');
                    if ($hode || $n === 0) {
                        $chip = 'font-weight: inherit;';
                    } else {
                        $bg = $n < 1000 ? 'var(--lissom-yellow)' : ($n < 1100 ? 'var(--terracotta-400, #D98E63)' : ($n < 1240 ? 'var(--terracotta-600, #B4552D)' : 'var(--lissom-brown)'));
                        $fg = $n < 1000 ? 'var(--lissom-brown)' : 'var(--clay-50)';
                        $chip = 'display: inline-block; background: ' . $bg . '; color: ' . $fg . '; border-radius: var(--radius-pill, 999px); padding: 3px 14px; font-weight: 700; font-variant-numeric: tabular-nums;';
                    }
                    $c3Stil = $hode ? '' : 'color: var(--text-muted); font-size: var(--text-sm);';
                    $ut .= '<div style="' . $radStil . '"><span style="' . $c1Stil . '">' . $e($celler[0] ?? '') . '</span><span><span style="' . $chip . '">' . $e($celler[1] ?? '') . '</span></span><span style="' . $c3Stil . '">' . $e($celler[2] ?? '') . '</span></div>';
                }
                $ut .= '</div>';
                $tabell = [];
            }
        };
        foreach (explode("\n", $tekst) as $raa) {
            $l = trim($raa);
            if ($l === '') { $flush(); continue; }
            if (str_starts_with($l, '## ')) { $flush(); $ut .= '<h3 style="font-family: var(--font-display); font-weight: 700; font-size: var(--text-2xl); color: var(--text-heading); margin: var(--space-8) 0 var(--space-4);">' . $e(substr($l, 3)) . '</h3>'; continue; }
            if (str_starts_with($l, '# ')) { $flush(); $ut .= '<h2 style="margin: var(--space-10) 0 var(--space-5); font-size: var(--text-3xl);">' . $e(substr($l, 2)) . '</h2>'; continue; }
            if (str_starts_with($l, '|')) {
                $celler = array_values(array_filter(array_map('trim', explode('|', $l)), static fn(string $c): bool => $c !== ''));
                if ($celler !== [] && count(array_filter($celler, static fn(string $c): bool => preg_match('/^[-:\s]+$/', $c) === 1)) === count($celler)) { continue; }
                if ($liste !== []) { $flush(); }
                $tabell[] = $celler;
                continue;
            }
            if (str_starts_with($l, '- ')) { if ($tabell !== []) { $flush(); } $liste[] = substr($l, 2); continue; }
            $flush();
            if (str_ends_with($l, ':')) { $ut .= '<div style="font-weight: 700; color: var(--text-heading); font-size: var(--text-lg); margin: var(--space-6) 0 var(--space-2);">' . $e($l) . '</div>'; continue; }
            $ut .= '<p style="margin: 0 0 var(--space-5); font-size: var(--text-lg); line-height: 1.75; color: var(--text-body); text-wrap: pretty;">' . $e($l) . '</p>';
        }
        $flush();
        return $ut;
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

    /**
     * Maale-ID-ene som window.lissomMaal — ogsaa til guidene (guide.php),
     * som ellers ikke gaar gjennom dokument().
     */
    public static function maalJson(): string
    {
        $gaId = trim((string) (self::lagret()['Marked/GA-id'] ?? ''));
        $gtmId = trim((string) (self::lagret()['Marked/GTM-id'] ?? ''));
        $metaId = trim((string) (self::lagret()['Marked/Meta-piksel'] ?? ''));
        return (string) json_encode(['ga' => $gaId, 'gtm' => $gtmId, 'meta' => $metaId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

        $maal = self::maalJson();

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
            // Bare fontene over folden forhaandslastes: overskrifta (Bitter 800
            // + 600 kursiv) og ingressen (Alegreya Sans 400). De to andre
            // (Alegreya 700, Bitter 700) hentes naar CSS-en finner dem — de
            // konkurrerte med selve sida om linja paa treg 4G. PageSpeed 22.
            // september 2026; nett.css har font-display: swap fra samme dag.
            . '<link rel="preload" href="/fonts/bitter-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
            . '<link rel="preload" href="/fonts/alegreya-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
            . '<link rel="preload" href="/fonts/bitter-latin-600-italic.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
            . "<style>\n" . self::css() . "\n</style>\n"
            . (string) ($side['hode'] ?? '')
            . "</head>\n<body>\n"
            . $side['kropp']
            . Deler::samtykke()
            // ── Sidas egne data FOER det felles skriptet ─────────────
            //
            // Eieren, 20. september 2026: «er det slik at referanse
            // karusellen har stoppet aa rullere paa forsiden?» Den hadde
            // det, og hadde gjort det stille.
            //
            // nett.js leser «window.lissomRot» med det samme:
            //
            //     var rot = window.lissomRot || [];
            //     felt('rot', rot.length, 12000, ...);
            //
            // og felt() gir opp paa «antall < 2». Sto sidas eget skript
            // ETTER, var lista tom naar det ble lest — ingen klokke, ingen
            // feilmelding, og prikkene fikk aldri en klikk-haandterer
            // heller. Maalt paa lissom.no: feltet byttet ikke paa 48
            // sekunder, og 12 000 ms-klokka ble aldri satt.
            //
            // Produktkarusellen paa mobil overlevde fordi den teller
            // DOM-elementer i stedet for en variabel.
            //
            // bin/karusellsjekk.mjs passer paa rekkefoelgen.
            . '<script>window.lissomMaal = ' . $maal . ";\n" . (string) ($side['skript'] ?? '') . "\n" . self::skript() . "</script>\n"
            . "</body>\n</html>\n";
    }
}
