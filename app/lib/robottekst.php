<?php
/**
 * Sidene slik de ser ut for det som ikke kjører skript.
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * Nettsida er én fil som dc-runtime bygger om til React i nettleseren. Fram
 * til det er ferdig staar teksten som «{{ heroTittel }}». Google kjoerer
 * skript og ser sida; ChatGPT-soek, Perplexity og Bing gjoer det ikke, eller
 * bare delvis. Maalt 11. september 2026 med OAI-SearchBot som avsender:
 * /kurs ga 584 plassholdere og ingen kurs. Det samme gjaldt de strukturerte
 * dataene (LocalBusiness, Course-lista): de lages i nettleseren og er
 * usynlige for alt annet.
 *
 * Eieren, 11. september 2026: «hva tenker du om seo og geo opplegget vi har
 * laget, er det bra nok?» — og GO paa forslaget: hovedteksten og de
 * strukturerte dataene tegnes ferdig paa serveren for de aapne sidene.
 *
 * ── Hva ───────────────────────────────────────────────────────────────
 *
 * For hver aapen side lages to ting, av det som alt ligger i basen og i
 * seo-kart.json — ingen ny tekst:
 *
 *   html  <main id="lissom-tekst">: overskrift, ingress, og det sida
 *         handler om (kursene med pris og neste dato, kursets datoer,
 *         medlemskapene, varene, spoersmaalene). Ligger i HTML-en fra foerste
 *         byte, i husets skrift, og fjernes av skriptet i lissom-2108.html
 *         naar den ekte skjermen staar — samme mekanikk som forsidetoppen.
 *         Ikke skjult tekst: den besoekende ser den i sekundet foer skriptet
 *         er ferdig, og Google ser det samme som den besoekende.
 *
 *   ld    JSON-LD til <head>: LocalBusiness, WebSite og WebPage paa alle
 *         sider; ItemList med Course paa forsida og /kurs; Course + én Event
 *         per kommende dato paa hvert kurs; Product paa hver vare; Offer per
 *         medlemskap; FAQPage paa Spoersmaal og svar. Samme merke som
 *         skriptet bruker (data-lissom-ld), saa nettleseren bytter det ut
 *         med sitt eget naar den er ferdig — ett sett om gangen.
 *
 * Kurslista og medlemskapene er de samme spoerringene som api/llms.php
 * bruker; de ligger her naa, og llms.php kaller dem.
 *
 * Alt her er pakket i try/catch der det snakker med basen: gaar noe galt,
 * sendes sida uten denne teksten — som foer. Nettsida skal aldri falle
 * fordi robotteksten ikke lot seg lage.
 */

declare(strict_types=1);

final class Robottekst
{
    public const ROT = 'https://lissom.no';

    private const MND = [1 => 'januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli',
                         'august', 'september', 'oktober', 'november', 'desember'];

    /** Stedene verkstedet betjener — samme liste som Component.OMRAADE. */
    private const OMRAADE = ['Tønsberg', 'Nøtterøy', 'Teie', 'Tjøme', 'Færder', 'Horten',
                             'Sandefjord', 'Revetal', 'Re', 'Andebu', 'Stokke', 'Vestfold'];

    /** Det verkstedet driver med — samme liste som Component.FAGOMRAADER. */
    private const FAG = ['keramikk', 'keramikkurs', 'dreiing på dreieskive', 'plateteknikk',
                         'håndbygging', 'glasering', 'brenning av keramikk',
                         'lage din egen keramikk', 'keramikk som hobby', 'Paint on Pots',
                         'Sip & Clay', 'Date Night med keramikk', 'medlemskap i keramikkverksted',
                         'bedriftsarrangement og teambuilding med leire'];

    /** Temaene som er events, ikke kurs — samme som kursNavAktiv i nettsida. */
    private const EVENT_TEMA = ['Events', 'Sip & Clay', 'Date Night', 'Paint on pots'];

    // ── Data ────────────────────────────────────────────────────────────

    /**
     * Kursene som ligger ute, med pris, neste dato og antall datoer.
     *
     * @return list<array{slug:string,tittel:string,pris_ore:int,beskrivelse:string,kort:string,tema:string,bilde:string,neste:?string,kommende:int}>
     */
    public static function kurs(): array
    {
        $utenDato = DB::harKolonne('courses', 'vis_uten_dato') ? 'c.vis_uten_dato' : '0 AS vis_uten_dato';
        $kort     = DB::harKolonne('courses', 'kort_beskrivelse') ? 'c.kort_beskrivelse' : 'NULL AS kort_beskrivelse';
        $rader = DB::alle(
            "SELECT c.slug, c.tittel, c.pris_ore, c.beskrivelse, c.tema, c.bilde, {$utenDato}, {$kort},
                    (SELECT MIN(cs.start_tid) FROM course_sessions cs
                      WHERE cs.course_id = c.id AND cs.status = 'planlagt'
                        AND cs.start_tid > UTC_TIMESTAMP()) AS neste,
                    (SELECT COUNT(*) FROM course_sessions cs2
                      WHERE cs2.course_id = c.id AND cs2.status = 'planlagt'
                        AND cs2.start_tid > UTC_TIMESTAMP()) AS kommende
               FROM courses c
              WHERE c.status = 'publisert'
                AND COALESCE(c.tema, '') <> 'Kun for medlemmer'
                AND c.slug IS NOT NULL AND c.slug <> ''
           ORDER BY c.tittel"
        );
        $ut = [];
        foreach ($rader as $k) {
            if ((int) $k['kommende'] === 0 && (int) ($k['vis_uten_dato'] ?? 0) === 0) {
                continue;
            }
            $ut[] = [
                'slug'        => (string) $k['slug'],
                'tittel'      => (string) $k['tittel'],
                'pris_ore'    => (int) $k['pris_ore'],
                'beskrivelse' => trim(strip_tags((string) ($k['beskrivelse'] ?? ''))),
                'kort'        => trim((string) ($k['kort_beskrivelse'] ?? '')),
                'tema'        => (string) ($k['tema'] ?? ''),
                'bilde'       => trim((string) ($k['bilde'] ?? '')),
                'neste'       => $k['neste'] === null ? null : (string) $k['neste'],
                'kommende'    => (int) $k['kommende'],
            ];
        }
        return $ut;
    }

    /**
     * Ett kurs med alle kommende datoer.
     *
     * @return ?array{slug:string,tittel:string,pris_ore:int,beskrivelse:string,kort:string,tema:string,bilde:string,datoer:list<array{start:string,slutt:?string}>,felt:array<string,string>}
     */
    public static function kursVedSlug(string $slug): ?array
    {
        $k = DB::en(
            "SELECT * FROM courses WHERE slug = :s AND status = 'publisert'
                AND COALESCE(tema, '') <> 'Kun for medlemmer'",
            ['s' => $slug]
        );
        if (!$k) {
            return null;
        }
        $datoer = [];
        foreach (DB::alle(
            "SELECT start_tid, slutt_tid FROM course_sessions
              WHERE course_id = :c AND status = 'planlagt' AND start_tid > UTC_TIMESTAMP()
           ORDER BY start_tid LIMIT 40",
            ['c' => (int) $k['id']]
        ) as $s) {
            $datoer[] = ['start' => (string) $s['start_tid'], 'slutt' => $s['slutt_tid'] === null ? null : (string) $s['slutt_tid']];
        }
        // Overskriftene er de som alt staar paa kurssida og i kursoppsettet —
        // ingen nye ord. «lager_du» har ikke lenger et felt i oppsettet, og
        // «tillegg» ingen overskrift; de tas ikke med.
        $felt = [];
        foreach (['nivaa_tekst' => 'Nivå', 'varighet_tekst' => 'Varighet', 'laerer' => 'Du lærer',
                  'med_hjem' => 'Dette får du med hjem', 'ferdig_tid' => 'Når er den ferdig'] as $kol => $navn) {
            $v = trim(strip_tags((string) ($k[$kol] ?? '')));
            if ($v !== '') {
                $felt[$navn] = $v;
            }
        }
        return [
            'slug'        => (string) $k['slug'],
            'tittel'      => (string) $k['tittel'],
            'pris_ore'    => (int) $k['pris_ore'],
            'beskrivelse' => trim(strip_tags((string) ($k['beskrivelse'] ?? ''))),
            'kort'        => trim((string) ($k['kort_beskrivelse'] ?? '')),
            'seo_meta'    => trim((string) ($k['seo_meta'] ?? '')),
            'tema'        => (string) ($k['tema'] ?? ''),
            'bilde'       => trim((string) ($k['bilde'] ?? '')),
            'datoer'      => $datoer,
            'felt'        => $felt,
        ];
    }

    /** @return list<array{navn:string,pris_ore:int,intervall:string,timer:?int}> */
    public static function medlemskap(): array
    {
        $ut = [];
        foreach (DB::alle(
            "SELECT navn, pris_ore, timer, intervall FROM membership_plans
              WHERE aktiv = 1 ORDER BY pris_ore"
        ) as $p) {
            $ut[] = [
                'navn'      => (string) $p['navn'],
                'pris_ore'  => (int) $p['pris_ore'],
                'intervall' => (string) ($p['intervall'] ?? 'maaned'),
                'timer'     => $p['timer'] === null ? null : (int) $p['timer'],
            ];
        }
        return $ut;
    }

    /** @return list<array{id:int,tittel:string,pris_ore:int,beskrivelse:string,bilde:string,utsolgt:bool}> */
    public static function varer(): array
    {
        $ut = [];
        foreach (DB::alle(
            "SELECT id, tittel, beskrivelse, bilde, pris_ore, lager, status FROM products
              WHERE status IN ('publisert', 'utsolgt') AND kun_medlemmer = 0
           ORDER BY tittel"
        ) as $v) {
            $ut[] = [
                'id'          => (int) $v['id'],
                'tittel'      => (string) $v['tittel'],
                'pris_ore'    => (int) $v['pris_ore'],
                'beskrivelse' => trim(strip_tags((string) ($v['beskrivelse'] ?? ''))),
                'bilde'       => trim((string) ($v['bilde'] ?? '')),
                'utsolgt'     => (string) $v['status'] === 'utsolgt' || ($v['lager'] !== null && (int) $v['lager'] <= 0),
            ];
        }
        return $ut;
    }

    /**
     * Spoersmaalene paa /sporsmal-og-svar: standardene fra seo-kart.json, med
     * det eieren har lagret under Nettsiden → Innhold over.
     *
     * @return list<array{q:string,a:string}>
     */
    public static function sporsmal(array $kart): array
    {
        $liste = [];
        foreach ((array) ($kart['sporsmal'] ?? []) as $s) {
            if (is_array($s) && isset($s['q'], $s['a'])) {
                $liste[] = ['q' => (string) $s['q'], 'a' => (string) $s['a']];
            }
        }
        if ($liste === []) {
            return [];
        }
        try {
            $lagret = [];
            foreach (DB::alle("SELECT nokkel, verdi FROM content_blocks WHERE nokkel LIKE 'Spørsmål og svar/%'") as $r) {
                $lagret[(string) $r['nokkel']] = (string) $r['verdi'];
            }
            foreach ($liste as $i => $s) {
                foreach ($lagret as $n => $v) {
                    if (str_ends_with($n, '/Spørsmål ' . ($i + 1)) && trim($v) !== '') { $liste[$i]['q'] = $v; }
                    if (str_ends_with($n, '/Svar ' . ($i + 1)) && trim($v) !== '') { $liste[$i]['a'] = $v; }
                }
            }
        } catch (Throwable) {
        }
        return $liste;
    }

    // ── Hjelpere ────────────────────────────────────────────────────────

    public static function kroner(int $ore): string
    {
        return 'kr. ' . number_format($ore / 100, 0, ',', ' ') . ',-';
    }

    /** «16. september» av et UTC-tidspunkt fra basen. */
    public static function dato(string $utc, bool $medKlokke = false): string
    {
        try {
            $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Oslo'));
        } catch (Throwable) {
            return '';
        }
        $s = (int) $d->format('j') . '. ' . self::MND[(int) $d->format('n')];
        if ($medKlokke) {
            $s .= ' kl. ' . $d->format('H:i');
        }
        return $s;
    }

    /** ISO 8601 med Oslo-tid, til Event-dataene. */
    private static function iso(string $utc): string
    {
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('c');
        } catch (Throwable) {
            return '';
        }
    }

    /** Foerste setning, maks 160 tegn — som i llms.txt. */
    public static function ingress(string $tekst): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $tekst));
        if ($t === '') {
            return '';
        }
        $punktum = mb_strpos($t, '. ');
        if ($punktum !== false && $punktum < 160) {
            return mb_substr($t, 0, $punktum + 1);
        }
        return mb_strlen($t) > 160 ? rtrim(mb_substr($t, 0, 158)) . ' …' : $t;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function bildeUrl(string $bilde): string
    {
        return $bilde === '' ? '' : self::ROT . '/' . ltrim($bilde, '/');
    }

    // ── Sida ────────────────────────────────────────────────────────────

    /**
     * Teksten og dataene for en adresse. Null for sider som ikke skal ha
     * det (admin, Min side, kassa, ukjente adresser).
     *
     * @param array $seo   det side.php fant: tittel, meta, canonical, h1 …
     * @param array $kart  seo-kart.json
     * @return ?array{html:string,ld:array}
     */
    public static function lag(string $adresse, array $seo, array $kart): ?array
    {
        $id = (string) ($kart['stier'][$adresse] ?? '');
        $erKurs = str_starts_with($adresse, '/kurs/');
        $erVare = str_starts_with($adresse, '/butikk/');
        if ($id === '' && !$erKurs && !$erVare) {
            return null;
        }
        if (strtolower((string) ($seo['index'] ?? 'Index')) === 'noindex') {
            return null;
        }

        $h1   = trim((string) ($seo['h1'] ?? ''));
        $meta = trim((string) ($seo['meta'] ?? ''));
        $tittel = trim((string) ($seo['tittel'] ?? ''));
        if ($h1 === '') {
            $h1 = trim((string) preg_replace('/\s*[|—-]\s*Lissom.*$/u', '', $tittel));
        }
        $canon = (string) ($seo['canonical'] ?? (self::ROT . $adresse));

        $deler = [];   // HTML-biter etter ingressen
        $ld    = self::grunnLd($canon, $tittel !== '' ? $tittel : $h1, $meta);

        try {
            if ($erKurs) {
                $slug = rawurldecode(substr($adresse, strlen('/kurs/')));
                $k = self::kursVedSlug($slug);
                if ($k === null) {
                    return null;
                }
                $h1 = $k['tittel'];
                $meta = $k['seo_meta'] !== '' ? $k['seo_meta'] : ($k['kort'] !== '' ? $k['kort'] : self::ingress($k['beskrivelse']));
                $deler[] = self::kursHtml($k);
                $ld[] = self::kursLd($k, $canon);
            } elseif ($erVare) {
                $vareId = Lenker::vareId($adresse);
                $v = null;
                foreach ($vareId === null ? [] : self::varer() as $x) {
                    if ($x['id'] === $vareId) { $v = $x; }
                }
                if ($v === null) {
                    return null;
                }
                $h1 = $v['tittel'];
                $meta = self::ingress($v['beskrivelse']);
                $deler[] = self::vareHtml($v);
                $ld[] = self::vareLd($v, $canon);
            } elseif ($id === 'forside' || $id === 'kurs' || $id === 'kursoversikt' || $id === 'kalender') {
                $kurs = self::kurs();
                $deler[] = self::kursListeHtml($kurs, 'Kurs og events');
                if ($id === 'forside') {
                    $deler[] = self::medlemskapHtml(self::medlemskap());
                }
                if ($kurs !== []) {
                    $ld[] = self::kursListeLd($kurs);
                }
            } elseif ($id === 'events' || $id === 'paintonpots') {
                $kurs = array_values(array_filter(self::kurs(), static function (array $k) use ($id): bool {
                    if ($id === 'paintonpots') {
                        return stripos($k['tittel'] . ' ' . $k['tema'], 'paint on pots') !== false;
                    }
                    return in_array($k['tema'], self::EVENT_TEMA, true);
                }));
                $deler[] = self::kursListeHtml($kurs, $id === 'paintonpots' ? 'Paint on Pots' : 'Events');
                if ($kurs !== []) {
                    $ld[] = self::kursListeLd($kurs);
                }
            } elseif ($id === 'medlemskap') {
                $planer = self::medlemskap();
                $deler[] = self::medlemskapHtml($planer);
                foreach ($planer as $p) {
                    $ld[] = self::medlemskapLd($p, $canon);
                }
            } elseif ($id === 'butikk' || $id === 'gavekort') {
                $varer = self::varer();
                if ($id === 'butikk') {
                    $deler[] = self::vareListeHtml($varer);
                    if ($varer !== []) {
                        $ld[] = self::vareListeLd($varer);
                    }
                }
            } elseif ($id === 'sporsmal') {
                $liste = self::sporsmal($kart);
                $deler[] = self::sporsmalHtml($liste);
                if ($liste !== []) {
                    $ld[] = self::sporsmalLd($liste, $canon);
                }
            }
        } catch (Throwable) {
            // Basen svarte ikke: overskrift og ingress gaar likevel.
        }

        $html = self::ramme($h1, $meta, implode("\n", $deler), $id === 'forside');
        return ['html' => $html, 'ld' => ['@context' => 'https://schema.org', '@graph' => $ld]];
    }

    // ── HTML ────────────────────────────────────────────────────────────

    private static function ramme(string $h1, string $meta, string $innhold, bool $forside): string
    {
        $e = [self::class, 'e'];
        // Paa forsida ligger den ferdigtegnede toppen (#lissom-topp) absolutt
        // over alt; teksten starter under den.
        $topp = $forside ? 'margin-top:100vh;' : '';
        return '<style>'
            . '#lissom-tekst{max-width:760px;margin:0 auto;padding:40px 24px 64px;font:var(--type-body,400 16px/1.6 "Alegreya Sans",sans-serif);color:var(--text-body,#2E1002);' . $topp . '}'
            . '#lissom-tekst h1,#lissom-tekst h2,#lissom-tekst h3{font-family:var(--font-display,"Bitter",serif);color:var(--text-heading,#4D1D12);line-height:1.15;margin:0 0 10px}'
            . '#lissom-tekst h1{font-size:34px}#lissom-tekst h2{font-size:22px;margin-top:34px}#lissom-tekst h3{font-size:18px;margin:18px 0 4px}'
            . '#lissom-tekst p{margin:0 0 12px}#lissom-tekst .lede{font-size:18px;color:var(--text-muted,#6F5D4C)}'
            . '#lissom-tekst .k{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--terracotta-600,#A2502B);margin:0 0 8px}'
            . '#lissom-tekst ul{padding-left:20px;margin:0 0 12px}#lissom-tekst li{margin:0 0 6px}'
            . '#lissom-tekst a{color:var(--lissom-brown,#4D1D12)}#lissom-tekst nav a{margin-right:14px}'
            . '#lissom-tekst .lite{font-size:14px;color:var(--text-muted,#6F5D4C)}'
            . '</style>' . "\n"
            . '<main id="lissom-tekst" data-robottekst="1">' . "\n"
            . '<p class="k">Lissom Keramikk &amp; Håndverk · Teie ved Tønsberg</p>' . "\n"
            . '<h1>' . $e($h1) . '</h1>' . "\n"
            . ($meta !== '' ? '<p class="lede">' . $e($meta) . '</p>' . "\n" : '')
            . $innhold . "\n"
            . '<h2>Finn fram</h2>' . "\n"
            . '<nav><a href="/kurs">Kurs og events</a> <a href="/events">Events</a> <a href="/medlemskap">Medlemskap</a> '
            . '<a href="/butikk">Butikk</a> <a href="/gavekort">Gavekort</a> <a href="/bedrift">Bedrift og event</a> '
            . '<a href="/om-oss">Om oss</a> <a href="/sporsmal-og-svar">Spørsmål og svar</a> <a href="/kontakt">Kontakt</a></nav>' . "\n"
            . '<p class="lite">Nordre Løkkevei 15, 3120 Nøtterøy · +47 94 13 46 01 · monica@lissom.no</p>' . "\n"
            . '</main>' . "\n";
    }

    private static function kursLinje(array $k): string
    {
        $bit = [];
        if ($k['pris_ore'] > 0) {
            $bit[] = self::kroner($k['pris_ore']);
        }
        if ($k['neste'] !== null) {
            $bit[] = 'neste ' . self::dato($k['neste']);
            if ($k['kommende'] > 1) {
                $bit[] = $k['kommende'] . ' datoer ute';
            }
        }
        $om = $k['kort'] !== '' ? $k['kort'] : self::ingress($k['beskrivelse']);
        if ($om !== '') {
            $bit[] = rtrim($om, '.');
        }
        return '<li><a href="/kurs/' . self::e(rawurlencode($k['slug'])) . '">' . self::e($k['tittel']) . '</a>'
             . ($bit ? ': ' . self::e(implode(' · ', $bit)) : '') . '</li>';
    }

    private static function kursListeHtml(array $kurs, string $overskrift): string
    {
        if ($kurs === []) {
            return '';
        }
        return '<h2>' . self::e($overskrift) . '</h2><ul>' . implode('', array_map([self::class, 'kursLinje'], $kurs)) . '</ul>';
    }

    private static function kursHtml(array $k): string
    {
        $ut = '';
        if ($k['pris_ore'] > 0) {
            $ut .= '<p><strong>Pris:</strong> ' . self::e(self::kroner($k['pris_ore'])) . ' per person. Leire, verktøy og brenning er inkludert.</p>';
        }
        if ($k['beskrivelse'] !== '') {
            $ut .= '<h2>Om kurset</h2>';
            foreach (preg_split('/\n{2,}|\r\n\r\n/u', $k['beskrivelse']) ?: [] as $avsnitt) {
                $avsnitt = trim($avsnitt);
                if ($avsnitt !== '') {
                    $ut .= '<p>' . self::e($avsnitt) . '</p>';
                }
            }
        }
        foreach ($k['felt'] as $navn => $verdi) {
            $ut .= '<h3>' . self::e($navn) . '</h3><p>' . self::e($verdi) . '</p>';
        }
        if ($k['datoer'] !== []) {
            $ut .= '<h2>Datoer</h2><ul>';
            foreach ($k['datoer'] as $d) {
                $ut .= '<li>' . self::e(self::dato($d['start'], true)) . '</li>';
            }
            $ut .= '</ul><p><a href="/kurs/' . self::e(rawurlencode($k['slug'])) . '">Book plass</a></p>';
        }
        return $ut;
    }

    private static function medlemskapHtml(array $planer): string
    {
        if ($planer === []) {
            return '';
        }
        $ut = '<h2>Medlemskap</h2><p>Fast plass i verkstedet på Teie, med egen hylle og dørkode. Leire, glasur og brenning er inkludert.</p><ul>';
        foreach ($planer as $p) {
            $bit = [];
            if ($p['pris_ore'] > 0) {
                $bit[] = self::kroner($p['pris_ore']) . ' per ' . ($p['intervall'] === 'aar' ? 'år' : 'måned');
            }
            $bit[] = $p['timer'] === null ? 'fri tilgang' : $p['timer'] . ' timer';
            $ut .= '<li><a href="/medlemskap">' . self::e($p['navn']) . '</a>: ' . self::e(implode(' · ', $bit)) . '</li>';
        }
        return $ut . '</ul>';
    }

    private static function vareListeHtml(array $varer): string
    {
        if ($varer === []) {
            return '';
        }
        $ut = '<h2>Håndlaget keramikk fra verkstedet</h2><ul>';
        foreach ($varer as $v) {
            $ut .= '<li><a href="' . self::e(Lenker::vare($v['id'], $v['tittel'])) . '">' . self::e($v['tittel']) . '</a>'
                 . ($v['pris_ore'] > 0 ? ': ' . self::e(self::kroner($v['pris_ore'])) : '')
                 . ($v['utsolgt'] ? ' (utsolgt)' : '') . '</li>';
        }
        return $ut . '</ul>';
    }

    private static function vareHtml(array $v): string
    {
        $ut = '';
        if ($v['pris_ore'] > 0) {
            $ut .= '<p><strong>Pris:</strong> ' . self::e(self::kroner($v['pris_ore'])) . ($v['utsolgt'] ? ' · utsolgt akkurat nå' : '') . '</p>';
        }
        if ($v['beskrivelse'] !== '') {
            $ut .= '<p>' . self::e($v['beskrivelse']) . '</p>';
        }
        return $ut . '<p>Hvert stykke er laget for hånd i verkstedet på Teie, så farge og form varierer litt.</p>';
    }

    private static function sporsmalHtml(array $liste): string
    {
        $ut = '';
        foreach ($liste as $s) {
            $ut .= '<h2>' . self::e($s['q']) . '</h2><p>' . self::e($s['a']) . '</p>';
        }
        return $ut;
    }

    // ── JSON-LD ─────────────────────────────────────────────────────────

    /** LocalBusiness, WebSite og WebPage — det samme som seoStrukturert() i nettsida. */
    private static function grunnLd(string $canon, string $tittel, string $meta): array
    {
        $rot = self::ROT;
        $sted = [
            '@type'       => 'LocalBusiness',
            '@id'         => $rot . '/#verksted',
            'name'        => 'Lissom Keramikk & Håndverk',
            'legalName'   => 'Lissom Keramikk & Håndverk AS',
            'url'         => $rot . '/',
            'telephone'   => '+4794134601',
            'email'       => 'monica@lissom.no',
            'description' => 'Keramikkverksted på Teie ved Tønsberg. Kurs i dreiing og plateteknikk, '
                           . 'events som Sip & Clay, Date Night og Paint on Pots, medlemskap med fast plass i verkstedet.',
            'address'     => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => 'Nordre Løkkevei 15',
                'postalCode'      => '3120',
                'addressLocality' => 'Nøtterøy',
                'addressRegion'   => 'Vestfold',
                'addressCountry'  => 'NO',
            ],
            // Org.nr. fra vilkaarene, koordinatene fra kartoppslag paa adressen
            // (59.246898, 10.415572 — Rosanes/Teie). Eieren, 11. september
            // 2026, SEO-instruksen. Aapningstid er med vilje ikke med: den
            // foelger kursene, og Bedriftsprofilen sier det Google viser.
            'vatID'       => 'NO938280819MVA',
            'taxID'       => '938280819',
            'geo'         => ['@type' => 'GeoCoordinates', 'latitude' => 59.246898, 'longitude' => 10.415572],
            'areaServed'  => array_map(static fn(string $n): array => ['@type' => 'Place', 'name' => $n], self::OMRAADE),
            'knowsAbout'  => self::FAG,
            'sameAs'      => self::some(),
            'image'       => $rot . '/assets_photos_kursrommet.jpg',
            'logo'        => $rot . '/logo-lockup.svg',
        ];
        $graf = [$sted, [
            '@type'      => 'WebSite',
            '@id'        => $rot . '/#nettsted',
            'url'        => $rot . '/',
            'name'       => 'Lissom Keramikk & Håndverk',
            'inLanguage' => 'nb-NO',
            'publisher'  => ['@id' => $rot . '/#verksted'],
        ]];
        $side = [
            '@type'      => 'WebPage',
            '@id'        => $canon . '#side',
            'url'        => $canon,
            'name'       => $tittel,
            'isPartOf'   => ['@id' => $rot . '/#nettsted'],
            'about'      => ['@id' => $rot . '/#verksted'],
            'inLanguage' => 'nb-NO',
        ];
        if ($meta !== '') {
            $side['description'] = $meta;
        }
        $graf[] = $side;
        return $graf;
    }

    /** Instagram og Facebook slik de er lagret i admin, ellers standardene. */
    private static function some(): array
    {
        $ig = 'https://instagram.com/lissom_keramikk';
        $fb = 'https://facebook.com/lissomkeramikk';
        // Samme oppslag som nettsida: feltene «Instagram» og «Facebook» under
        // Nettsiden → Innhold, uansett hvilken seksjon de ligger i.
        try {
            foreach (DB::alle("SELECT nokkel, verdi FROM content_blocks WHERE nokkel LIKE '%/Instagram' OR nokkel LIKE '%/Facebook'") as $r) {
                $v = trim((string) $r['verdi']);
                if ($v === '') {
                    continue;
                }
                if (str_ends_with((string) $r['nokkel'], '/Instagram')) {
                    $ig = 'https://instagram.com/' . trim(str_replace('@', '', $v));
                }
                if (str_ends_with((string) $r['nokkel'], '/Facebook')) {
                    $fb = 'https://facebook.com/' . trim(str_replace('facebook.com/', '', $v));
                }
            }
        } catch (Throwable) {
        }
        return [$ig, $fb];
    }

    private static function tilbud(int $ore, string $url, bool $tilgjengelig = true): array
    {
        return [
            '@type'         => 'Offer',
            'price'         => number_format($ore / 100, 2, '.', ''),
            'priceCurrency' => 'NOK',
            'url'           => $url,
            'availability'  => $tilgjengelig ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
        ];
    }

    private static function kursLdKort(array $k): array
    {
        $url = self::ROT . '/kurs/' . rawurlencode($k['slug']);
        $c = [
            '@type'    => 'Course',
            '@id'      => $url . '#kurs',
            'name'     => $k['tittel'],
            'url'      => $url,
            'provider' => ['@id' => self::ROT . '/#verksted'],
        ];
        $om = $k['kort'] !== '' ? $k['kort'] : self::ingress($k['beskrivelse']);
        if ($om !== '') {
            $c['description'] = $om;
        }
        if ($k['bilde'] !== '') {
            $c['image'] = self::bildeUrl($k['bilde']);
        }
        if ($k['pris_ore'] > 0) {
            $c['offers'] = self::tilbud($k['pris_ore'], $url);
        }
        return $c;
    }

    private static function kursListeLd(array $kurs): array
    {
        $i = 0;
        return [
            '@type'           => 'ItemList',
            '@id'             => self::ROT . '/kurs#liste',
            'name'            => 'Kurs og events hos Lissom Keramikk',
            'itemListElement' => array_map(function (array $k) use (&$i): array {
                return ['@type' => 'ListItem', 'position' => ++$i, 'item' => self::kursLdKort($k)];
            }, $kurs),
        ];
    }

    /** Course med én Event per kommende dato — det Google viser som egne datotreff. */
    private static function kursLd(array $k, string $canon): array
    {
        $c = self::kursLdKort($k);
        if ($k['beskrivelse'] !== '') {
            $c['description'] = self::ingress($k['beskrivelse']);
        }
        $hendelser = [];
        foreach ($k['datoer'] as $d) {
            $start = self::iso($d['start']);
            if ($start === '') {
                continue;
            }
            $h = [
                '@type'               => 'Event',
                'name'                => $k['tittel'],
                'startDate'           => $start,
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'eventStatus'         => 'https://schema.org/EventScheduled',
                'location'            => ['@id' => self::ROT . '/#verksted'],
                'organizer'           => ['@id' => self::ROT . '/#verksted'],
                'url'                 => $canon,
            ];
            if ($d['slutt'] !== null && self::iso($d['slutt']) !== '') {
                $h['endDate'] = self::iso($d['slutt']);
            }
            if ($k['bilde'] !== '') {
                $h['image'] = self::bildeUrl($k['bilde']);
            }
            if ($k['pris_ore'] > 0) {
                $h['offers'] = self::tilbud($k['pris_ore'], $canon);
            }
            $hendelser[] = $h;
        }
        if ($hendelser !== []) {
            $c['hasCourseInstance'] = array_map(static function (array $h): array {
                $h['@type'] = ['CourseInstance', 'Event'];
                $h['courseMode'] = 'Onsite';
                return $h;
            }, $hendelser);
        }
        return $c;
    }

    private static function medlemskapLd(array $p, string $canon): array
    {
        $navn = $p['navn'] . ($p['timer'] === null ? ' (fri tilgang)' : ' (' . $p['timer'] . ' timer)');
        $o = self::tilbud($p['pris_ore'], $canon);
        $o['name'] = $navn;
        $o['category'] = 'Medlemskap i keramikkverksted';
        $o['seller'] = ['@id' => self::ROT . '/#verksted'];
        return $o;
    }

    private static function vareLd(array $v, string $canon): array
    {
        $p = [
            '@type' => 'Product',
            '@id'   => $canon . '#vare',
            'name'  => $v['tittel'],
            'url'   => $canon,
            'brand' => ['@type' => 'Brand', 'name' => 'Lissom Keramikk & Håndverk'],
        ];
        if ($v['beskrivelse'] !== '') {
            $p['description'] = self::ingress($v['beskrivelse']);
        }
        if ($v['bilde'] !== '') {
            $p['image'] = self::bildeUrl($v['bilde']);
        }
        if ($v['pris_ore'] > 0) {
            $p['offers'] = self::tilbud($v['pris_ore'], $canon, !$v['utsolgt']);
        }
        return $p;
    }

    private static function vareListeLd(array $varer): array
    {
        $i = 0;
        return [
            '@type'           => 'ItemList',
            '@id'             => self::ROT . '/butikk#liste',
            'name'            => 'Håndlaget keramikk fra Lissom',
            'itemListElement' => array_map(function (array $v) use (&$i): array {
                $url = self::ROT . Lenker::vare($v['id'], $v['tittel']);
                return ['@type' => 'ListItem', 'position' => ++$i, 'url' => $url, 'name' => $v['tittel']];
            }, $varer),
        ];
    }

    private static function sporsmalLd(array $liste, string $canon): array
    {
        return [
            '@type'      => 'FAQPage',
            '@id'        => $canon . '#faq',
            'mainEntity' => array_map(static fn(array $s): array => [
                '@type'          => 'Question',
                'name'           => $s['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $s['a']],
            ], $liste),
        ];
    }
}
