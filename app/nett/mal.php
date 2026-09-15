<?php
/**
 * Malene fra nettsida, tegnet paa serveren.
 *
 * De fleste kundesidene — Om oss, Personvern, Spoersmaal og svar, Kursene
 * vaare — er nesten bare tekst: HTML med inline-stiler, noen «{{ felt }}»
 * fra Innhold, en sc-if her og en sc-for der, og knapper som gaar et sted.
 * Aa skrive dem om for haand én gang til (som forsida, kurs og
 * medlemskap ble) er dobbelt arbeid, og to utgaver som glir fra hverandre.
 *
 * Denne klassen leser skjermen rett ut av lissom-2108.html og tegner den
 * med verdier sida gir den:
 *
 *   {{ felt }}                 → verdien fra $verdier, eller Nett::innh()
 *   <sc-if value="{{ x }}">    → tegnes naar verdien er sann
 *   <sc-for list="{{ l }}" as="r">  → én gang per rad; {{ r.felt }} inni
 *   <x-import …Button>         → Deler::knapp(), on-click → adresse
 *   <x-import …NavBar>         → Deler::topp()
 *   <x-import …CourseCard>     → Deler::kurskort()
 *   <x-import …Tag>            → en pille som lenke
 *   <x-import …Icon>           → Deler::ikon()
 *   onClick="{{ h }}"          → data-href fra $handlinger (nett.js foelger)
 *   style="{{ s }}"            → et stilobjekt (array) som CSS
 *   style-hover / data-src / hint-* → data-hover / src / bort
 *
 * Endres skjermen i lissom-2108.html, foelger serversida med av seg selv.
 * Skjermer med skjema og tilstand (booking, kasse, Min side) egner seg ikke
 * — de er appen.
 */

declare(strict_types=1);

final class Mal
{
    /** @var array<string,string> skjermene, lest én gang per foresporsel */
    private static array $skjermer = [];

    /**
     * Skjermen med navnet (data-screen-label) fra lissom-2108.html, som
     * markup — fra <div role="main"> til og med </div> som lukker den.
     */
    public static function skjerm(string $navn): string
    {
        if (isset(self::$skjermer[$navn])) {
            return self::$skjermer[$navn];
        }
        $html = @file_get_contents(Nett::rot() . '/lissom-2108.html');
        if (!is_string($html)) {
            throw new RuntimeException('Fant ikke lissom-2108.html');
        }
        $merke = 'data-screen-label="' . $navn . '"';
        $start = strpos($html, $merke);
        if ($start === false) {
            throw new RuntimeException('Fant ikke skjermen «' . $navn . '»');
        }
        $start = strrpos(substr($html, 0, $start), '<div ');
        if ($start === false) {
            throw new RuntimeException('Skjermen «' . $navn . '» begynner ikke med <div');
        }
        // Slutten: skjermen ligger i <sc-if value="{{ erX }}"> … </sc-if>;
        // neste skjerm begynner med en ny <sc-if value="{{ er…. Vi tar til
        // den, og klipper bort den lukkende </sc-if>.
        $slutt = strpos($html, '<sc-if value="{{ er', $start + 1);
        $bit = $slutt === false ? substr($html, $start) : substr($html, $start, $slutt - $start);
        $bit = preg_replace('~\s*</sc-if>\s*$~', '', rtrim($bit)) ?? $bit;
        return self::$skjermer[$navn] = $bit;
    }

    /**
     * Tegner skjermen.
     *
     * @param array<string,mixed> $verdier    felt → verdi; mangler feltet, spoer vi Innhold
     * @param array<string,string> $handlinger  onClick-navn → adresse
     */
    public static function tegn(string $navn, array $verdier = [], array $handlinger = []): string
    {
        $tre = self::parse(self::skjerm($navn));
        return self::render($tre, [$verdier], $verdier, $handlinger);
    }

    // ── Parseren ────────────────────────────────────────────────────────

    /**
     * Treet: tekstbiter, if-noder og for-noder.
     * @return list<array{t:string,v?:string,l?:string,as?:string,barn?:list<mixed>}>
     */
    private static function parse(string $html): array
    {
        $deler = preg_split('~(<sc-if [^>]*>|</sc-if>|<sc-for [^>]*>|</sc-for>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $rot = ['barn' => []];
        $stabel = [&$rot];
        foreach ($deler as $d) {
            $topp = &$stabel[count($stabel) - 1];
            if (preg_match('~^<sc-if value="\{\{ ([^}]+) \}\}"~', $d, $m) === 1) {
                $node = ['t' => 'if', 'v' => trim($m[1]), 'barn' => []];
                $topp['barn'][] = $node;
                $stabel[] = &$topp['barn'][count($topp['barn']) - 1];
            } elseif (preg_match('~^<sc-for list="\{\{ ([^}]+) \}\}" as="([^"]+)"~', $d, $m) === 1) {
                $node = ['t' => 'for', 'l' => trim($m[1]), 'as' => $m[2], 'barn' => []];
                $topp['barn'][] = $node;
                $stabel[] = &$topp['barn'][count($topp['barn']) - 1];
            } elseif ($d === '</sc-if>' || $d === '</sc-for>') {
                array_pop($stabel);
            } else {
                $topp['barn'][] = ['t' => 'tekst', 'v' => $d];
            }
            unset($topp);
        }
        return $rot['barn'];
    }

    // ── Verdiene ────────────────────────────────────────────────────────

    /**
     * Verdien bak «a.b.c»: foerst i loekkeradene (innerst foerst), saa i
     * sidas verdier, saa Innhold. Ukjent → tom streng.
     * @param list<mixed> $ramme  loekkevariablene, innerst sist
     */
    private static function verdi(string $uttrykk, array $ramme, array $verdier)
    {
        $deler = explode('.', trim($uttrykk));
        $hode = array_shift($deler);
        $funnet = false;
        $v = null;
        for ($i = count($ramme) - 1; $i >= 0; $i--) {
            if (is_array($ramme[$i]) && array_key_exists($hode, $ramme[$i])) {
                $v = $ramme[$i][$hode];
                $funnet = true;
                break;
            }
        }
        if (!$funnet) {
            if (array_key_exists($hode, $verdier)) {
                $v = $verdier[$hode];
            } else {
                // «ooTittel: this.innh('Om oss/0/Overskrift')» i nettsida:
                // navnet slaas opp i Innhold via det samme kartet.
                $kart = self::innhKart();
                $v = Nett::innh($kart[$hode] ?? $hode);
            }
        }
        foreach ($deler as $d) {
            if (is_array($v) && array_key_exists($d, $v)) {
                $v = $v[$d];
            } else {
                return '';
            }
        }
        return $v;
    }

    /**
     * Feltnavn → Innhold-noekkel, lest ut av renderVals i lissom-2108.html:
     * hver linje paa formen «navn: this.innh('Side/N/Felt'),».
     * @return array<string,string>
     */
    private static function innhKart(): array
    {
        static $kart = null;
        if ($kart !== null) {
            return $kart;
        }
        $kart = [];
        $html = @file_get_contents(Nett::rot() . '/lissom-2108.html');
        if (is_string($html) && preg_match_all("~^\s+(\w+): this\.innh\('([^']+)'\),?\s*$~m", $html, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $x) {
                $kart[$x[1]] = $x[2];
            }
        }
        return $kart;
    }

    /** Et stilobjekt (camelCase → kebab-case) som CSS. En streng gaar rett ut. */
    public static function css($stil): string
    {
        if (is_string($stil)) {
            return $stil;
        }
        if (!is_array($stil)) {
            return '';
        }
        $ut = [];
        foreach ($stil as $k => $v) {
            if ($v === null || $v === '' || $v === false) {
                continue;
            }
            $navn = strtolower((string) preg_replace('/([A-Z])/', '-$1', (string) $k));
            if (str_starts_with($navn, 'webkit-') || str_starts_with($navn, 'moz-')) {
                $navn = '-' . $navn;
            }
            if (is_int($v) || is_float($v)) {
                $v = in_array($navn, ['opacity', 'z-index', 'flex', 'font-weight', 'line-height', 'zoom', 'order', 'flex-grow', 'flex-shrink'], true) ? (string) $v : $v . 'px';
            }
            $ut[] = $navn . ': ' . $v;
        }
        return implode('; ', $ut) . ($ut !== [] ? ';' : '');
    }

    // ── Tegneren ────────────────────────────────────────────────────────

    private static function render(array $noder, array $ramme, array $verdier, array $handlinger): string
    {
        $ut = '';
        foreach ($noder as $n) {
            if ($n['t'] === 'tekst') {
                $ut .= self::tekst($n['v'], $ramme, $verdier, $handlinger);
            } elseif ($n['t'] === 'if') {
                if (self::sann(self::verdi($n['v'], $ramme, $verdier))) {
                    $ut .= self::render($n['barn'], $ramme, $verdier, $handlinger);
                }
            } elseif ($n['t'] === 'for') {
                $liste = self::verdi($n['l'], $ramme, $verdier);
                if (is_array($liste)) {
                    foreach ($liste as $rad) {
                        $ny = $ramme;
                        $ny[] = [$n['as'] => $rad];
                        $ut .= self::render($n['barn'], $ny, $verdier, $handlinger);
                    }
                }
            }
        }
        return $ut;
    }

    private static function sann($v): bool
    {
        if (is_array($v)) {
            return $v !== [];
        }
        return (bool) $v && $v !== '0' && $v !== 'false';
    }

    /** En tekstbit: x-import, onClick, style-hover, data-src, hint-*, {{ }}. */
    private static function tekst(string $html, array $ramme, array $verdier, array $handlinger): string
    {
        $e = [Nett::class, 'e'];
        $v = fn(string $u) => self::verdi($u, $ramme, $verdier);
        $s = fn(string $u): string => (string) self::verdi($u, $ramme, $verdier);

        // Komponentene.
        $html = (string) preg_replace_callback('~<x-import component-from-global-scope="LissomDesignSystem_8402ac\.(\w+)"([^>]*)>(.*?)</x-import>~s', function (array $m) use ($v, $s, $handlinger, $e): string {
            $attr = self::attributter($m[2]);
            $a = static fn(string $n) => $attr[$n] ?? null;
            $verdiAv = static function (?string $raa) use ($v, $s) {
                if ($raa === null) { return null; }
                return preg_match('~^\{\{ (.+) \}\}$~', $raa, $mm) === 1 ? $v(trim($mm[1])) : $raa;
            };
            $tekstAv = fn(?string $raa): string => (string) ($verdiAv($raa) ?? '');
            $href = static function (?string $raa) use ($handlinger): ?string {
                if ($raa === null || preg_match('~^\{\{ (.+) \}\}$~', $raa, $mm) !== 1) { return null; }
                $navn = trim($mm[1]);
                if (isset($handlinger[$navn])) { return $handlinger[$navn]; }
                // «k.book» → handlingen for lista: «k.book» eller «book».
                $siste = substr($navn, (int) strrpos($navn, '.') + 1);
                return $handlinger[$siste] ?? null;
            };
            $inni = trim((string) preg_replace_callback('~\{\{ ([^}]+) \}\}~', fn(array $mm): string => $s(trim($mm[1])), $m[3]));
            switch ($m[1]) {
                case 'NavBar':
                    return Deler::topp($tekstAv($a('active')), ($a('overlay') ?? '') === '{{ true }}');
                case 'Button':
                    $o = ['variant' => $a('variant') ?? 'primary', 'size' => $a('size') ?? 'md'];
                    if ($a('icon')) { $o['icon'] = $a('icon'); }
                    if ($a('icon-after')) { $o['iconAfter'] = $a('icon-after'); }
                    if ($a('full-width')) { $o['full'] = true; }
                    $h = $href($a('on-click'));
                    if ($h !== null) { $o['href'] = $h; }
                    if ($a('style') && preg_match('~^\{\{ (.+) \}\}$~', $a('style'), $mm) === 1) { $o['style'] = ' ' . self::css($v(trim($mm[1]))); }
                    return Deler::knapp($inni, $o);
                case 'CourseCard':
                    $k = ['title' => $tekstAv($a('title')), 'level' => $tekstAv($a('level')), 'text' => $tekstAv($a('text')), 'duration' => $tekstAv($a('duration')), 'price' => $tekstAv($a('price')), 'status' => $tekstAv($a('status')), 'date' => $tekstAv($a('date')), 'image' => $tekstAv($a('image')), 'imageAlt' => $tekstAv($a('image-alt')), 'cta' => $tekstAv($a('cta-label')) ?: 'Book plass', 'href' => $href($a('on-book')) ?? '#'];
                    // Kortet kan ha sin egen adresse i rada: {{ k.href }}.
                    if ($a('on-book') && preg_match('~^\{\{ (\w+)\.~', $a('on-book'), $mm) === 1) {
                        $rad = $v($mm[1]);
                        if (is_array($rad) && isset($rad['href'])) { $k['href'] = (string) $rad['href']; }
                    }
                    return Deler::kurskort($k);
                case 'Tag':
                    $valgt = ($verdiAv($a('selected')) ?? false) ? true : false;
                    $h = $href($a('on-click')) ?? '#';
                    if ($a('on-click') && preg_match('~^\{\{ (\w+)\.~', $a('on-click'), $mm) === 1) {
                        $rad = $v($mm[1]);
                        if (is_array($rad) && isset($rad['href'])) { $h = (string) $rad['href']; }
                    }
                    return '<a href="' . $e($h) . '" style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: var(--radius-pill); font-family: var(--font-sans); font-size: var(--text-sm); font-weight: 500; cursor: pointer; text-decoration: none; border: 1px solid '
                        . ($valgt ? 'var(--lissom-brown)' : 'var(--border-default)') . '; background: ' . ($valgt ? 'var(--lissom-brown)' : 'transparent') . '; color: ' . ($valgt ? 'var(--clay-50)' : 'var(--text-body)') . '; transform: none; transition: var(--transition-base);">' . $e($inni) . '</a>';
                case 'Icon':
                    $size = $verdiAv($a('size'));
                    return Deler::ikon((string) $a('name'), is_numeric($size) ? (int) $size : 20);
                default:
                    return '';
            }
        }, $html);

        // Attributtene paa vanlige elementer.
        $html = (string) preg_replace_callback('~<(\w+)((?:\s+[\w:-]+(?:="[^"]*")?)*)\s*(/?)>~', function (array $m) use ($v, $s, $handlinger, $e): string {
            $attr = $m[2];
            if ($attr === '') { return $m[0]; }
            // onClick → data-href, eller bort.
            $attr = (string) preg_replace_callback('~\s+onClick="\{\{ ([^}]+) \}\}"~', function (array $mm) use ($handlinger, $v): string {
                $navn = trim($mm[1]);
                $h = $handlinger[$navn] ?? null;
                if ($h === null && str_contains($navn, '.')) {
                    [$rad, $felt] = explode('.', $navn, 2);
                    $r = $v($rad);
                    if (is_array($r) && isset($r['href'])) { $h = (string) $r['href']; }
                    else { $h = $handlinger[$felt] ?? null; }
                }
                if ($h === null) { return ''; }
                // «js:navn»: nett.js gjoer noe paa sida, i stedet for aa gaa et sted.
                return str_starts_with($h, 'js:') ? ' data-nett-handling="' . Nett::e(substr($h, 3)) . '"' : ' data-href="' . Nett::e($h) . '"';
            }, $attr);
            $attr = (string) preg_replace('~\s+on(?:MouseMove|MouseLeave|MouseEnter|TouchStart|TouchEnd|TouchCancel|Change|Input|KeyDown|Submit)="[^"]*"~', '', $attr);
            $attr = (string) preg_replace('~\s+hint-[\w-]+="[^"]*"~', '', $attr);
            $attr = str_replace([' style-hover="', ' data-src="'], [' data-hover="', ' src="'], $attr);
            // style="{{ x }}" → stilobjektet som CSS.
            $attr = (string) preg_replace_callback('~ style="\{\{ ([^}]+) \}\}"~', fn(array $mm): string => ' style="' . Nett::e(self::css($v(trim($mm[1])))) . '"', $attr);
            return '<' . $m[1] . $attr . ($m[3] !== '' ? ' /' : '') . '>';
        }, $html);

        // Resten av feltene.
        return (string) preg_replace_callback('~\{\{ ([^}]+) \}\}~', fn(array $mm): string => Nett::e($s(trim($mm[1]))), $html);
    }

    /** @return array<string,string> */
    private static function attributter(string $raa): array
    {
        $ut = [];
        if (preg_match_all('~([\w:-]+)="([^"]*)"~', $raa, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $x) {
                $ut[$x[1]] = $x[2];
            }
        }
        return $ut;
    }
}
