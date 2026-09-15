<?php
/**
 * Delene serversidene er bygd av: toppen, bunnen, knappene, kortene.
 *
 * Alt her er portert fra lissom-2108.html og ds-bundle.js, med de samme
 * inline-stilene. Det er ikke pynt: stilblokka i nett.css (lest ut av
 * nettsida) treffer paa nettopp de stilene — «button[style*="min-height:
 * 52px"]», «header img[data-logo-cup]» — saa serversidene og appen faar
 * samme justering paa telefon. Endres en stil her, maa den endres i
 * ds-bundle.js ogsaa, til appskjermene for kundesidene er tatt bort.
 *
 * Hover-stilene («style-hover» i appen, satt av dc-runtime) heter
 * data-hover her og settes av nett.js.
 */

declare(strict_types=1);

final class Deler
{
    private static function e(string $s): string
    {
        return Nett::e($s);
    }

    // ── Ikon ────────────────────────────────────────────────────────────

    /**
     * Lucide-ikonet, inline, som Icon i ds-bundle.js — svg-en fra /icons
     * med stroke-width og 100 % maal, i et span med stoerrelsen.
     */
    public static function ikon(string $navn, int $size = 20, float $strek = 1.75, string $ekstraStil = ''): string
    {
        static $cache = [];
        if (!isset($cache[$navn])) {
            $svg = @file_get_contents(Nett::rot() . '/icons/' . basename($navn) . '.svg');
            $cache[$navn] = is_string($svg) ? preg_replace('~<!--.*?-->\s*~s', '', $svg) : '';
        }
        $svg = str_replace('<svg', '<svg width="100%" height="100%" stroke-width="' . $strek . '"', (string) $cache[$navn]);
        return '<span role="presentation" aria-hidden="true" style="display: inline-flex; width: ' . $size . 'px; height: ' . $size
            . 'px; flex: 0 0 auto; color: currentColor; stroke-width: ' . $strek . ';' . $ekstraStil . '">' . $svg . '</span>';
    }

    // ── Knapp ───────────────────────────────────────────────────────────

    private const KNAPP_STR = [
        'sm' => ['padding' => '8px 16px',  'font' => 'var(--text-xs)',   'gap' => 6,  'ikon' => 15, 'min' => 34],
        'md' => ['padding' => '11px 22px', 'font' => 'var(--text-sm)',   'gap' => 8,  'ikon' => 17, 'min' => 44],
        'lg' => ['padding' => '15px 30px', 'font' => 'var(--text-base)', 'gap' => 10, 'ikon' => 20, 'min' => 52],
    ];
    private const KNAPP_UTG = [
        'primary'   => 'background: var(--lissom-yellow); color: var(--lissom-brown); border: 2px solid var(--lissom-yellow);',
        'secondary' => 'background: transparent; color: var(--lissom-brown); border: 2px solid var(--lissom-brown);',
        'ink'       => 'background: var(--lissom-brown); color: var(--clay-50); border: 2px solid var(--lissom-brown);',
        'ghost'     => 'background: transparent; color: var(--text-body); border: 2px solid transparent;',
    ];
    private const KNAPP_SVEV = [
        'primary'   => 'background: var(--yellow-500); border-color: var(--yellow-500);',
        'secondary' => 'background: var(--lissom-brown); color: var(--clay-50);',
        'ink'       => 'background: var(--brown-700); border-color: var(--brown-700);',
        'ghost'     => 'background: var(--clay-100);',
    ];

    /**
     * Button i ds-bundle.js. Alltid en <button>, ogsaa naar den gaar et sted
     * (data-href, nett.js foelger den): nett.css sikter paa «button[style*=…]»
     * naar den klipper knappene paa telefon, og en <a> faar en annen
     * linjehoeyde. Maalt 15. september 2026: <a> ble 59 px der knappen er 54.
     *
     * @param array{href?:string,variant?:string,size?:string,icon?:string,iconAfter?:string,full?:bool,style?:string,attr?:string} $o
     */
    public static function knapp(string $tekst, array $o = []): string
    {
        $s = self::KNAPP_STR[$o['size'] ?? 'md'] ?? self::KNAPP_STR['md'];
        $v = $o['variant'] ?? 'primary';
        $full = !empty($o['full']);
        $stil = 'display: ' . ($full ? 'flex' : 'inline-flex') . ';' . ($full ? ' width: 100%;' : '')
            . ' align-items: center; justify-content: center; gap: ' . $s['gap'] . 'px; padding: ' . $s['padding']
            . '; min-height: ' . $s['min'] . 'px; font-size: ' . $s['font'] . '; font-family: var(--font-sans); font-weight: 700;'
            . ' letter-spacing: var(--tracking-caps); text-transform: uppercase; border-radius: var(--radius-pill); cursor: pointer;'
            . ' text-decoration: none; opacity: 1; white-space: nowrap; transition: background var(--duration-base) var(--ease-clay),'
            . ' color var(--duration-base) var(--ease-clay), transform var(--duration-fast) var(--ease-clay); transform: none; '
            . (self::KNAPP_UTG[$v] ?? self::KNAPP_UTG['primary']) . ($o['style'] ?? '');
        $inni = (!empty($o['icon']) ? self::ikon($o['icon'], $s['ikon']) : '')
            . self::e($tekst)
            . (!empty($o['iconAfter']) ? self::ikon($o['iconAfter'], $s['ikon']) : '');
        $hover = ' data-hover="' . self::e(self::KNAPP_SVEV[$v] ?? '') . '"';
        $attr = isset($o['attr']) ? ' ' . $o['attr'] : '';
        if (isset($o['href'])) {
            $attr .= ' data-href="' . self::e($o['href']) . '"';
        }
        return '<button type="button" style="' . $stil . '"' . $hover . $attr . '>' . $inni . '</button>';
    }

    // ── Toppen ──────────────────────────────────────────────────────────

    /** Lenkene i toppen — navLinks i nettsida. */
    private const LENKER = [
        ['Forside', '/'], ['Kurs', '/kurs'], ['Events', '/events'], ['Medlemskap', '/medlemskap'],
        ['Butikk', '/butikk'], ['Om oss', '/om-oss'], ['Kalender', '/kalender'],
    ];

    /**
     * NavBar i ds-bundle.js. Komponenten tegner ULIKT DOM etter bredden —
     * tre utgaver, valgt med media queries (samme grep som
     * bin/forhaandstegn.mjs): tight under 1150, compact 1150–1399, full fra
     * 1400. Alle tre ligger i sida; bare den som passer vises.
     *
     * @param string $aktiv  navnet paa lenka som lyser («Forside», «Kurs» …)
     * @param bool   $overlay  paa forsida: gjennomsiktig over heroen til man ruller
     */
    public static function topp(string $aktiv, bool $overlay = false): string
    {
        $ut = '<style>#lissom-topp>[data-topp]{display:none}@media (max-width:1149px){#lissom-topp>[data-topp="tight"]{display:contents}}'
            . '@media (min-width:1150px) and (max-width:1399px){#lissom-topp>[data-topp="compact"]{display:contents}}'
            . '@media (min-width:1400px){#lissom-topp>[data-topp="full"]{display:contents}}</style>' . "\n"
            . '<div id="lissom-topp">' . "\n";
        foreach (['tight', 'compact', 'full'] as $v) {
            $ut .= '<div data-topp="' . $v . '">' . self::toppUtgave($v, $aktiv, $overlay) . "</div>\n";
        }
        return $ut . "</div>\n";
    }

    private static function toppUtgave(string $utgave, string $aktiv, bool $overlay): string
    {
        $compact = $utgave !== 'full';
        $tight = $utgave === 'tight';
        // Hoeyden appen maaler — se margin-bottom i NavBar. nett.css klipper
        // toppen til 100 px paa alle bredder (header > div:first-child), saa
        // det er det appen maaler, og det heroen skal ligge under.
        $hodeH = 100;
        $cupH = $compact ? ($tight ? 34 : 48) : 76;
        $ordH = $compact ? ($tight ? 20 : 28) : 42;

        $h = '<header data-nett-topp="' . ($overlay ? 'overlay' : 'gul') . '" style="position: sticky; top: 0; z-index: 40; margin-bottom: ' . ($overlay ? -$hodeH . 'px' : '0') . ';'
            . ' background: ' . ($overlay ? 'transparent' : 'var(--lissom-yellow)') . '; box-shadow: none;'
            . ' border-bottom: ' . ($overlay ? 'none' : '1px solid rgba(77,29,18,.15)') . ';'
            . ' transition: ' . ($overlay ? 'background .3s ease, box-shadow .3s ease' : 'none') . ';">';
        $h .= '<div style="max-width: var(--width-wide); margin: 0 auto; padding: 0 var(--space-8); min-height: ' . ($compact ? 108 : 168)
            . 'px; display: flex; flex-wrap: nowrap; align-items: center; gap: ' . ($compact ? 'var(--space-4)' : 'var(--space-6)') . '; row-gap: 0;">';
        // Logoen: koppen over ordmerket.
        $h .= '<a href="/" style="display: flex; opacity: 1; transition: opacity .2s ease;" aria-label="Lissom — til forsiden">'
            . '<span data-nett-logo style="display: flex; flex-direction: column; align-items: center; gap: ' . ($compact ? 4 : 8) . 'px;">'
            . '<span style="display: flex; flex-direction: column; align-items: center; height: ' . $cupH . 'px;">'
            . '<img src="mark-cup-top.svg" alt="" width="192" height="88" data-logo-cup="true" style="height: ' . round($cupH * 0.786, 3) . 'px; width: auto; display: block; transition: transform 1600ms linear; transform-origin: 50% 50%;">'
            . '<img src="mark-cup-saucer.svg" alt="" width="192" height="24" style="height: ' . round($cupH * 0.214, 3) . 'px; width: auto; display: block;">'
            . '</span>'
            . '<img src="wordmark-lissom.svg" alt="lissom keramikk &amp; håndverk" width="425" height="86" style="height: ' . $ordH . 'px; width: auto; display: block;">'
            . '</span></a>';
        // Menyen.
        $h .= '<nav style="display: ' . ($tight ? 'none' : 'flex') . '; align-items: center; justify-content: center; gap: '
            . ($compact ? '4px clamp(8px, 1.2vw, 20px)' : 'var(--space-4) clamp(var(--space-3), 1.6vw, var(--space-8))')
            . '; margin: 0 auto; min-width: 0; flex: 1; overflow: hidden; flex-wrap: nowrap;">';
        $lenker = self::LENKER;
        $lenker[] = ['Handlekurv', '/kasse'];
        foreach ($lenker as [$navn, $href]) {
            $paa = $navn === $aktiv;
            $farge = $paa ? 'var(--lissom-brown)' : 'var(--brown-500)';
            $h .= '<a href="' . $href . '" style="font-family: var(--font-sans); font-weight: 700; font-size: '
                . ($compact ? 'clamp(11px, 1.15vw, 15px)' : 'clamp(13px, 1.1vw, 19px)')
                . '; letter-spacing: var(--tracking-caps); text-transform: uppercase; text-decoration: none; white-space: nowrap; color: ' . $farge
                . '; padding-bottom: 7px; background-image: linear-gradient(var(--lissom-brown), var(--lissom-brown)); background-repeat: no-repeat;'
                . ' background-position: left bottom; background-size: ' . ($paa ? '100% 2px' : '0% 2px')
                . '; transition: background-size .28s var(--ease-clay, ease), color .2s ease;" data-hover="background-size: 100% 2px; color: var(--lissom-brown);"'
                . ($paa ? ' aria-current="page"' : '') . '>';
            if ($navn === 'Handlekurv') {
                $h .= '<span title="Handlekurv" style="display: inline-flex; align-items: center; gap: 4px; vertical-align: middle;">'
                    . self::ikon('shopping-cart', 36) . '</span>';
            } else {
                $h .= self::e($navn);
            }
            $h .= '</a>';
        }
        $h .= '</nav>';
        // Hoeyre: meny-knappen (tight), soek (om skrudd paa) og «Kontakt oss».
        $h .= '<div style="display: flex; align-items: center; gap: var(--space-5); margin-left: ' . ($tight ? 'auto' : 'var(--space-6)') . ';">';
        if ($tight) {
            $strek = 'width: 24px; height: 3px; border-radius: 2px; background: var(--lissom-brown); transition: transform .2s ease, opacity .2s ease; transform: none; opacity: 1;';
            $h .= '<button type="button" aria-label="Meny" aria-expanded="false" data-nett-meny style="appearance: none; background: transparent; border: none; cursor: pointer; display: flex; flex-direction: column; gap: 5px; padding: 10px;">'
                . '<span style="' . $strek . '"></span><span style="' . $strek . '"></span><span style="' . $strek . '"></span></button>';
        }
        if (Nett::bryterPaa('sok')) {
            // En <button>: nett.css skjuler «header > div > div > button[aria-label="Søk"]» paa telefon.
            $h .= '<button type="button" data-href="/kurs?sok=1" aria-label="Søk" style="width: 44px; height: 44px; display: inline-grid; place-items: center; border-radius: var(--radius-pill); cursor: pointer; opacity: 1; background: transparent; color: var(--text-body); border: 2px solid transparent; transition: background var(--duration-base) var(--ease-clay); zoom: ' . ($compact ? 1 : 1.35) . ';" data-hover="background: var(--clay-100);">'
                . self::ikon('search', 19) . '</button>';
        }
        // En <button>, ikke en lenke: nett.css sikter paa «header button[…]»
        // naar den gjoer «Kontakt oss» mindre. nett.js foelger data-href.
        $h .= self::knapp('Kontakt oss', ['href' => '/kontakt', 'variant' => 'ink', 'size' => $compact ? 'md' : 'lg', 'style' => ' zoom: ' . ($compact ? 1 : 1.35) . ';']);
        $h .= '</div></div>';
        // Menyen som folder seg ut paa telefon. Skjult til knappen trykkes.
        if ($tight) {
            $h .= '<nav data-nett-mobilmeny hidden style="display: none; flex-direction: column; align-items: stretch; background: #fff; border-top: 1px solid rgba(77,29,18,.12); box-shadow: 0 12px 24px rgba(77,29,18,.14); padding: var(--space-4) var(--space-8) var(--space-6); animation: dsNavFade .22s ease;">';
            $mobil = self::LENKER;
            $mobil[] = ['Min side', '/min-side'];
            foreach ($mobil as [$navn, $href]) {
                $paa = $navn === $aktiv;
                $h .= '<a href="' . $href . '" style="font-family: var(--font-sans); font-weight: 700; font-size: 16px; letter-spacing: var(--tracking-caps); text-transform: uppercase; text-decoration: none; color: '
                    . ($paa ? 'var(--lissom-brown)' : 'var(--brown-500)') . '; padding: 16px 4px; min-height: 44px; display: flex; align-items: center; border-bottom: 1px solid rgba(77,29,18,.08);">'
                    . self::e($navn) . '</a>';
            }
            $h .= '</nav>';
        }
        return $h . '</header>';
    }

    // ── Kurskort ────────────────────────────────────────────────────────

    /**
     * CourseCard i ds-bundle.js, med Card og Media, som lenke til kurset.
     *
     * @param array{title:string,level?:string,text?:string,duration?:string,price?:string,status?:string,date?:string,image?:string,imageAlt?:string,cta?:string,href:string} $k
     */
    public static function kurskort(array $k, string $ekstraStil = ''): string
    {
        $e = [self::class, 'e'];
        $href = (string) $k['href'];
        // Hele kortet kan trykkes (data-href, som onBook i appen); tittelen
        // er en ekte lenke, saa kurset har en lenke robotene kan foelge.
        $h = '<div data-href="' . $e($href) . '" data-nett-kort style="border-radius: var(--radius-xl, 22px); padding: 0; overflow: hidden; box-shadow: var(--shadow-sm); transform: none;'
            . ' transition: box-shadow var(--duration-base) var(--ease-clay), transform var(--duration-base) var(--ease-clay); cursor: pointer;'
            . ' background: var(--surface-card); border: 1px solid var(--border-subtle); display: flex; flex-direction: column;' . $ekstraStil . '"'
            . ' data-hover="box-shadow: var(--shadow-md); transform: translateY(-2px);">';
        // Bildet.
        $h .= '<div style="position: relative;">';
        $bilde = (string) ($k['image'] ?? '');
        if ($bilde !== '') {
            $ss = Nett::srcset($bilde);
            $h .= '<img src="' . $e($bilde) . '"' . ($ss !== '' ? ' srcset="' . $e($ss) . '" sizes="' . Nett::SIZES_KORT . '"' : '')
                . ' alt="' . $e((string) ($k['imageAlt'] ?? '') !== '' ? (string) $k['imageAlt'] : (string) $k['title']) . '"'
                // Det foerste kortet paa sida er gjerne det stoerste som tegnes
                // (LCP): det hentes med en gang; resten venter til de trengs.
                . (!empty($k['eager']) ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async"'
                . ' style="width: 100%; aspect-ratio: 16 / 10; height: auto; object-fit: cover; display: block;">';
        } else {
            $h .= '<div style="aspect-ratio: 16 / 10; background: var(--clay-200); display: grid; place-items: center; color: var(--clay-400); font-family: var(--font-sans); font-weight: 700; font-size: 11px; letter-spacing: var(--tracking-micro);">FOTO</div>';
        }
        $status = (string) ($k['status'] ?? '');
        if ($status !== '') {
            $haster = preg_match('/igjen|få plasser/i', $status) === 1;
            $h .= '<span style="position: absolute; top: 14px; left: 14px; display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 999px; font-family: var(--font-sans); font-weight: 700; font-size: 13px; letter-spacing: var(--tracking-caps); text-transform: uppercase; white-space: nowrap; background: '
                . ($haster ? 'var(--lissom-yellow)' : 'var(--lissom-brown)') . '; color: ' . ($haster ? 'var(--lissom-brown)' : 'var(--clay-50)') . '; box-shadow: 0 3px 14px rgba(46,16,2,.3);">'
                . '<span style="width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto; background: ' . ($haster ? 'var(--lissom-brown)' : 'var(--sage-500)') . ';"></span>' . $e($status) . '</span>';
        }
        $h .= '</div>';
        // Teksten.
        $h .= '<div style="padding: var(--card-pad); display: flex; flex-direction: column; gap: var(--space-2); flex: 1;">';
        $level = (string) ($k['level'] ?? '');
        $h .= '<div style="font: var(--type-eyebrow); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--terracotta-600); height: 18px; line-height: 18px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis;">' . ($level !== '' ? $e($level) : '&nbsp;') . '</div>';
        $h .= '<h3 style="font: var(--type-h3); color: var(--text-heading); margin: 0; line-height: 1.2; height: 1.2em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><a href="' . $e($href) . '" style="color: inherit; text-decoration: none;">' . $e((string) $k['title']) . '</a></h3>';
        $tekst = (string) ($k['text'] ?? '');
        $dato = (string) ($k['date'] ?? '');
        $varighet = (string) ($k['duration'] ?? '');
        $h .= '<div style="min-height: 40px; height: ' . ($tekst !== '' ? 'auto' : '40px') . '; overflow: hidden;">';
        if ($dato !== '' || $varighet !== '') {
            $h .= '<div style="display: flex; gap: var(--space-4); color: var(--text-muted); font-size: var(--text-sm); flex-wrap: wrap;">';
            if ($dato !== '') {
                $h .= '<span style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">' . self::ikon('calendar', 15) . $e($dato) . '</span>';
            }
            if ($varighet !== '') {
                $h .= '<span style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">' . self::ikon('clock', 15) . $e($varighet) . '</span>';
            }
            $h .= '</div>';
        }
        if ($tekst !== '') {
            $h .= '<p style="margin: 0; font-size: var(--text-sm); line-height: 1.4; color: var(--text-muted); display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; text-wrap: pretty;">' . $e($tekst) . '</p>';
        }
        $h .= '</div>';
        $h .= '<div style="margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); padding-top: var(--space-2); flex-wrap: nowrap; height: 44px; box-sizing: border-box; overflow: hidden;">'
            . '<span style="font-family: var(--font-display); font-weight: 800; font-size: var(--text-xl); color: var(--text-heading); white-space: nowrap;">' . $e((string) ($k['price'] ?? '')) . '</span>'
            . self::knapp((string) ($k['cta'] ?? 'Book plass'), ['size' => 'sm', 'attr' => 'tabindex="-1"'])
            . '</div></div></div>';
        return $h;
    }

    // ── Varekort ────────────────────────────────────────────────────────

    /**
     * Varekortet paa forsida og i butikken — samme markup som i nettsida.
     * @param array{title:string,tekst:string,pris:string,image:string,fokus:string,cta:string,href:string} $p
     */
    public static function varekort(array $p, string $ekstraStil = ''): string
    {
        $e = [self::class, 'e'];
        return '<div class="lx-varekort" style="background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); overflow: hidden; display: flex; flex-direction: column; box-shadow: var(--shadow-sm); transition: box-shadow var(--duration-base) var(--ease-clay), transform var(--duration-base) var(--ease-clay);' . $ekstraStil . '">'
            . '<a href="' . $e($p['href']) . '" aria-label="Se mer om ' . $e($p['title']) . '" style="display: block; width: 100%; aspect-ratio: 1 / 1; background-color: var(--clay-200); background-image: ' . Nett::cssUrl($p['image']) . '; background-size: cover; background-position: ' . $e($p['fokus']) . '; cursor: pointer;"></a>'
            . '<div style="padding: var(--space-5); display: flex; flex-direction: column; flex: 1; gap: 6px;">'
            . '<a href="' . $e($p['href']) . '" style="font-family: var(--font-display); font-weight: 700; font-size: var(--text-lg); color: var(--text-heading); cursor: pointer; text-decoration: none;">' . $e($p['title']) . '</a>'
            . '<p class="lx-varetekst" style="margin: 0; font-size: var(--text-sm); line-height: 1.5; color: var(--text-body); text-wrap: pretty;">' . $e($p['tekst']) . '</p>'
            . '<div class="lx-varebunn" style="margin-top: auto; padding-top: var(--space-3); display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); flex-wrap: wrap;">'
            . '<span style="font-family: var(--font-display); font-weight: 700; font-size: var(--text-base); color: var(--text-heading);">' . $e($p['pris']) . '</span>'
            . self::knapp($p['cta'], ['size' => 'sm', 'href' => $p['href']])
            . '</div></div></div>';
    }

    // ── Prikkene under et felt som bytter ───────────────────────────────

    /** tonePrikker() i nettsida. Den foerste lyser; nett.js flytter lyset. */
    public static function prikker(array $navn, string $gruppe): string
    {
        $h = '';
        foreach ($navn as $i => $n) {
            $paa = $i === 0;
            $farge = $paa ? 'var(--lissom-brown)' : 'var(--border-subtle)';
            $h .= '<button type="button" data-nett-prikk="' . self::e($gruppe) . '" data-nr="' . $i . '" aria-label="Vis ' . self::e($n) . '" style="appearance: none; cursor: pointer; border: none; background: transparent; padding: 7px 4px; width: '
                . ($paa ? 34 : 18) . 'px; height: 24px; border-radius: 999px; background-image: linear-gradient(' . $farge . ', ' . $farge
                . '); background-clip: content-box; background-origin: content-box; background-repeat: no-repeat; background-size: 100% 10px; background-position: center; transition: background-image .3s ease;"></button>';
        }
        return $h;
    }

    // ── Bunnen ──────────────────────────────────────────────────────────

    /**
     * Footeren, som i nettsida. Aapningstidene regnes av Apent og
     * stemplingen — samme som /api/apningstider.php gir appen.
     */
    public static function bunn(bool $oppfordring = true): string
    {
        $e = [self::class, 'e'];
        $innh = [Nett::class, 'innh'];

        // Adressen: gata paa én linje, postnummeret paa neste.
        $hel = trim($innh('Footer/1/Adresse'));
        $komma = strpos($hel, ',');
        $adr1 = $komma === false ? $hel : trim(substr($hel, 0, $komma));
        $adr2 = $komma === false ? '' : trim(substr($hel, $komma + 1));

        // Aapningstidene — som ftApning* i nettsida.
        $apning1 = $innh('Footer/2/Linje 1');
        $apning2 = $innh('Footer/2/Linje 2');
        $apningNeste = '';
        try {
            $dager = Apent::dager(Apent::DAGER_FRAM)['dager'] ?? [];
            $bemannet = Stempling::verkstedetBemannet();
            $idag = null;
            foreach ($dager as $d) {
                if (!empty($d['idag'])) { $idag = $d; break; }
            }
            if ($bemannet['apen'] ?? false) {
                if ($idag !== null) {
                    $idag['stengt'] = false;
                    $idag['tid'] = 'Åpent nå';
                } else {
                    $idag = ['stengt' => false, 'tid' => 'Åpent nå', 'merknad' => ''];
                }
            }
            if ($idag !== null) {
                $apning1 = !empty($idag['stengt'])
                    ? 'I dag: ' . ((string) ($idag['merknad'] ?? '') !== '' ? $idag['merknad'] : 'stengt')
                    : 'I dag: ' . (string) ($idag['tid'] ?? '') . ((string) ($idag['merknad'] ?? '') !== '' ? ' · ' . $idag['merknad'] : '');
            } else {
                $apning1 = 'Ingen planlagte kurs i dag — verkstedet er åpent etter avtale';
            }
            if ($bemannet['apen'] ?? false) {
                $apning2 = 'Det er noen i verkstedet nå — bare kom innom';
            }
            foreach ($dager as $d) {
                if (empty($d['idag']) && empty($d['stengt'])) {
                    $apningNeste = 'Neste: ' . mb_strtolower((string) $d['dag']) . ' ' . $d['naar'] . ', ' . $d['tid'];
                    break;
                }
            }
        } catch (Throwable) {
            // Da staar det eieren har skrevet under Innhold.
        }

        $lagret = Nett::lagret();
        $hent = static function (string $felt, string $reserve) use ($lagret): string {
            $suffiks = '/' . $felt;
            foreach ($lagret as $k => $v) {
                if (strlen($k) > strlen($suffiks) && str_ends_with($k, $suffiks) && trim($v) !== '') {
                    return trim($v);
                }
            }
            return $reserve;
        };
        $insta = trim(str_replace('@', '', $hent('Instagram', '@lissom_keramikk')));

        $h = '<footer data-theme="ink" style="background: var(--lissom-brown); padding: var(--space-14) var(--space-8) var(--space-6); position: relative; overflow: hidden;">'
            . '<img src="mark-heart.png" alt="" aria-hidden="true" style="position: absolute; right: -160px; top: -80px; width: 1300px; opacity: 0.05; transform: rotate(-8deg); filter: invert(1); pointer-events: none; -webkit-mask-image: linear-gradient(215deg, black 25%, transparent 80%); mask-image: linear-gradient(215deg, black 25%, transparent 80%);" loading="lazy" decoding="async" width="887" height="856">'
            . '<div style="max-width: var(--width-content); margin: 0 auto; position: relative;">';
        if ($oppfordring) {
            $h .= '<div style="padding-bottom: var(--space-10); border-bottom: 1px solid rgba(244,235,222,.18);">'
                . '<div style="font-family: var(--font-display); font-weight: 800; font-size: clamp(32px, 2.8vw, 52px); line-height: 1.05; color: var(--clay-50); max-width: 18ch; text-wrap: balance; margin: 0 0 var(--space-6);">Vil du prøve <em style="font-style: italic; color: var(--lissom-yellow);">keramikk?</em></div>'
                . '<div class="lx-band" style="display: flex; align-items: center; justify-content: space-between; gap: var(--space-8); flex-wrap: wrap;">'
                . '<a href="/kontakt" style="appearance: none; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; border: 2px solid var(--lissom-brown); background: var(--lissom-yellow); color: var(--lissom-brown); border-radius: var(--radius-pill); padding: 16px 32px; font: var(--type-body); font-size: var(--text-base); font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; text-decoration: none; transition: all .2s var(--ease-clay, ease);" data-hover="background: var(--yellow-500, #E6B800);">' . $e($innh('Footer/0/Knapp')) . ' <span aria-hidden="true">→</span></a>'
                . '<p style="margin: 0; font-size: var(--text-lg); line-height: 1.6; color: var(--clay-300); max-width: 42ch; text-wrap: pretty;">' . $e($innh('Footer/0/Undertekst')) . '</p>'
                . '</div></div>';
        }
        $h .= '<div class="lx-footcols" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-8) var(--space-10); align-items: start; padding: var(--space-10) 0;">';
        // Besoek oss.
        $h .= '<div><div style="font: var(--type-label); font-size: 12px; letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--lissom-yellow); margin-bottom: var(--space-5);">Besøk oss</div>'
            . '<div style="display: flex; flex-direction: column; gap: 2px; align-items: flex-start; font-size: var(--text-base); line-height: 1.45; color: var(--clay-200);">'
            . '<a href="https://maps.google.com/?q=Nordre+L%C3%B8kkevei+15,+3120+N%C3%B8tter%C3%B8y" target="_blank" rel="noopener" style="color: var(--clay-200); text-decoration: none; transition: color .18s ease; padding: 3px 0;" data-hover="color: var(--lissom-yellow);">'
            . '<span style="display: block; white-space: nowrap;">' . $e($adr1) . '</span>'
            . ($adr2 !== '' ? '<span style="display: block;">' . $e($adr2) . '</span>' : '')
            . '</a>'
            . '<a href="tel:+4794134601" style="color: var(--clay-200); text-decoration: none; transition: color .18s ease; padding: 3px 0;" data-hover="color: var(--lissom-yellow);">' . $e($innh('Footer/1/Telefon')) . '</a>'
            . '<a href="mailto:monica@lissom.no" style="color: var(--clay-200); text-decoration: none; transition: color .18s ease; padding: 3px 0;" data-hover="color: var(--lissom-yellow);">' . $e($innh('Footer/1/E-post')) . '</a>'
            . '<div style="margin-top: 10px; font-size: var(--text-sm); line-height: 1.45; color: var(--clay-300); display: flex; flex-direction: column;">'
            . '<span style="font: var(--type-label); font-size: 11px; letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--lissom-yellow); margin-bottom: 2px;">' . $e($innh('Footer/2/Overskrift')) . '</span>'
            . '<span>' . $e($apning1) . '</span>'
            . ($apningNeste !== '' ? '<span>' . $e($apningNeste) . '</span>' : '')
            . '<span>' . $e($apning2) . '</span>'
            . '</div></div></div>';
        // Lissom.
        $h .= '<div><div style="font: var(--type-label); font-size: 12px; letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--lissom-yellow); margin-bottom: var(--space-5);">Lissom</div>'
            . '<div class="lx-footlinks" style="display: flex; flex-direction: column; gap: 10px; align-items: flex-start;">';
        foreach ([['Om oss', '/om-oss'], ['Nyheter', '/nyheter'], ['Klar til henting', '/ferdigbrent'], ['Spørsmål og svar', '/sporsmal-og-svar'], ['Personvern', '/personvern'], ['Salgsvilkår', '/vilkar']] as [$navn, $href]) {
            $h .= '<a href="' . $href . '" style="appearance: none; background: transparent; border: none; cursor: pointer; padding: 0; text-align: left; font-family: var(--font-sans); font-size: var(--text-base); color: var(--clay-200); text-decoration: none; transition: color .18s ease;" data-hover="color: var(--lissom-yellow);">' . $e($navn) . '</a>';
        }
        $h .= '</div></div>';
        // Foelg oss.
        $h .= '<div><div style="font: var(--type-label); font-size: 12px; letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--lissom-yellow); margin-bottom: var(--space-5);">Følg oss</div>'
            . '<a href="https://instagram.com/' . $e($insta) . '" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 12px; margin-bottom: var(--space-5); color: var(--clay-100); text-decoration: none; transition: color .18s ease;" data-hover="color: var(--lissom-yellow);">'
            . '<span style="width: 44px; height: 44px; flex: none; border-radius: 999px; border: 1px solid rgba(244,235,222,.35); display: inline-flex; align-items: center; justify-content: center;">' . self::ikon('instagram', 20) . '</span>'
            . '<span style="font-size: var(--text-base); font-weight: 600;">@' . $e($insta) . '</span></a>'
            . '<div style="display: inline-flex; align-items: center; gap: 8px; font: var(--type-label); font-size: 11px; letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--clay-300);"><img src="mark-heart.png" alt="" style="height: 15px; width: auto; filter: invert(0.92) sepia(0.14) saturate(1.4) hue-rotate(-15deg);" loading="lazy" decoding="async" width="887" height="856">' . $e($innh('Footer/3/Merkelinje')) . '</div>'
            . '</div></div>';
        // Nederste rad.
        $pille = 'appearance: none; background: transparent; border: 1px solid rgba(244,235,222,.35); border-radius: var(--radius-pill); cursor: pointer; padding: 8px 10px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; flex: 1 1 0; min-width: 0; min-height: 42px; text-align: center; line-height: 1.25; font: var(--type-label); font-size: 12px; letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--clay-200); text-decoration: none; transition: color .18s ease, border-color .18s ease;';
        $h .= '<div style="border-top: 1px solid rgba(244,235,222,.18); padding-top: var(--space-5); display: flex; align-items: center; justify-content: space-between; gap: var(--space-6); flex-wrap: wrap;">'
            . '<div style="display: flex; gap: var(--space-3) var(--space-6); flex-wrap: wrap; font-size: 12px; letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--clay-300);">'
            . '<span>&copy; ' . date('Y') . ' ' . $e($innh('Footer/3/Firmanavn')) . '</span>'
            . '<span style="font-weight: 700; font-size: 13px;">' . $e($innh('Footer/3/Org. nr.')) . '</span>'
            . '<span style="text-transform: none; letter-spacing: 0; opacity: .68; display: inline-flex; align-items: center; gap: 6px;">EDB system av<svg viewBox="0 0 290 74" width="100" height="26" role="img" aria-label="Joika Design" style="display: block;"><defs><linearGradient id="jdToning" gradientUnits="userSpaceOnUse" x1="150" y1="4" x2="150" y2="70"><stop offset="0" stop-color="currentColor" stop-opacity=".82"></stop><stop offset=".42" stop-color="currentColor" stop-opacity=".38"></stop><stop offset=".66" stop-color="currentColor" stop-opacity=".12"></stop><stop offset="1" stop-color="currentColor" stop-opacity=".05"></stop></linearGradient></defs><g fill="none" stroke="url(#jdToning)" stroke-linejoin="round" stroke-linecap="round"><path stroke-width="3.4" d="M74 59 L54 27 L110 47 L97 9 L150 40 L204 4 L226 59"></path><rect x="70" y="57" width="160" height="13" rx="6.5" stroke-width="3.4"></rect></g><text x="145" y="54" text-anchor="middle" font-family="Georgia, \'Times New Roman\', serif" font-size="31" font-weight="700" letter-spacing="0.4" fill="currentColor">Joika Design</text></svg></span>'
            . '</div>'
            . '<div class="lx-bunnpiller" style="display: flex; align-items: stretch; gap: var(--space-3); flex: 1 1 auto; max-width: 460px;">'
            . '<a href="/min-side" class="lx-bunnpille" style="' . $pille . '" data-hover="color: var(--lissom-yellow); border-color: var(--lissom-yellow);">' . self::ikon('user', 14) . 'Min side</a>'
            . '<a href="/admin" class="lx-bunnpille" style="' . $pille . '" data-hover="color: var(--lissom-yellow); border-color: var(--lissom-yellow);">' . self::ikon('lock', 14) . 'Admin</a>'
            . '</div></div>';
        return $h . '</div></footer>';
    }

    // ── Samtykke til besoeksmaaling ─────────────────────────────────────

    /** Boksen nederst. Skjult til nett.js ser at ingen har svart. */
    public static function samtykke(): string
    {
        return '<div data-nett-samtykke hidden style="position: fixed; left: 0; right: 0; bottom: 0; z-index: 70; background: var(--lissom-brown); color: var(--clay-100); padding: var(--space-5) var(--space-6); box-shadow: 0 -6px 24px rgba(31, 17, 12, .22);">'
            . '<div style="max-width: 980px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: var(--space-5); flex-wrap: wrap;">'
            . '<p style="margin: 0; font-size: var(--text-sm); line-height: 1.6; max-width: 62ch; color: var(--clay-100);">Vi vil gjerne se hvor mange som besøker nettsiden, og hvilke sider de leser. Det krever informasjonskapsler fra Google Analytics. Sier du nei, virker alt akkurat som før — vi teller bare ikke besøket.</p>'
            . '<div style="display: flex; gap: var(--space-3); flex-wrap: wrap;">'
            . self::knapp('Ja, det er greit', ['size' => 'sm', 'attr' => 'data-nett-samtykke-ja'])
            . self::knapp('Nei takk', ['size' => 'sm', 'variant' => 'ink', 'attr' => 'data-nett-samtykke-nei'])
            . '</div></div></div>' . "\n";
    }
}
