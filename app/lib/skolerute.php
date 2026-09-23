<?php
/**
 * Skoleruta i Vestfold — naar kursene kolliderer med skoleferien.
 *
 * ── Hvorfor ──────────────────────────────────────────────────────────
 *
 * Eieren, 23. september 2026: «kan vi faa en slags varsel naar vi planlegger
 * kurs i skoleferiene her i vestfold? husk den maa vaere live siden feriene
 * varierer aar fra aar».
 *
 * Han hadde grunn til aa spoerre. Samme dag ba han om et dreiekurs 5.–6.
 * oktober — som er foerste og andre dag av hoestferien. Og kalenderen vi la
 * ut samme dag hadde fire oekter i ferieuker uten at noen saa det.
 *
 * ── «Live» ───────────────────────────────────────────────────────────
 *
 * Skoleruta er ikke et API. Den er en nettside fylket vedlikeholder, med to
 * skoleaar om gangen. Levende betyr derfor: vi henter den av oss selv med
 * jevne mellomrom, og den fornyer seg aar for aar uten at noen roerer kode.
 *
 * Den henter ikke ved hvert oppslag. Datoene er bestemt et aar i forveien og
 * endrer seg nesten aldri; ett kall i uka er rikelig, og et kurssoppsett som
 * venter paa fylkets nettside hver gang det tegnes er ubrukelig.
 *
 * ── Naar hentingen feiler ────────────────────────────────────────────
 *
 * Fylket legger om sida, bytter adresse, eller er nede. Da beholdes forrige
 * gode svar, og «hentet» staar uendret — saa skjermen kan si hvor gammelt
 * det er. Tom skjerm uten forklaring er verre enn gamle datoer med dato paa.
 *
 * ── Varsel, ikke sperre ──────────────────────────────────────────────
 *
 * Et kurs i skoleferien er ikke feil. Barne- og familiekurs gaar ofte bedre
 * da. Derfor svarer denne klassen paa «hvilken ferie er dette», og lar den
 * som planlegger bestemme.
 */

declare(strict_types=1);

final class Skolerute
{
    private const KILDE = 'https://www.vestfoldfylke.no/no/skoler/kompetansebyggeren/'
                        . 'meny/elev-ved-kompetansebyggeren/skolestart-og-skoleruta/skoleruta/';

    /** Hvor lenge et hentet svar regnes som ferskt. */
    private const DAGER_FERSK = 7;

    private const MAANEDER = [
        'januar' => 1, 'februar' => 2, 'mars' => 3, 'april' => 4,
        'mai' => 5, 'juni' => 6, 'juli' => 7, 'august' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
    ];

    /** @var list<array{navn: string, fra: string, til: string, skoleaar: string}>|null */
    private static ?array $bufret = null;

    // ── Oppslaget skjermene bruker ───────────────────────────────────

    /**
     * Hvilken ferie en dato faller i, om noen.
     *
     * @return array{navn: string, fra: string, til: string, skoleaar: string}|null
     */
    public static function ferieFor(string $iso): ?array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) !== 1) {
            return null;
        }
        foreach (self::perioder() as $p) {
            if ($iso >= $p['fra'] && $iso <= $p['til']) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Setningen som staar i skjermen. Tom naar datoen er en vanlig dag.
     */
    public static function varsel(string $iso): string
    {
        $f = self::ferieFor($iso);
        if ($f === null) {
            return '';
        }
        return self::norsk($iso) . ' er i ' . mb_strtolower($f['navn'])
             . ' i Vestfold (' . self::norsk($f['fra']) . '–' . self::norsk($f['til']) . ').';
    }

    /**
     * Alle periodene: det hentede, med eierens egne lagt oppaa.
     *
     * @return list<array{navn: string, fra: string, til: string, skoleaar: string}>
     */
    public static function perioder(): array
    {
        if (self::$bufret !== null) {
            return self::$bufret;
        }

        $raa = (string) Config::hent('skolerute_data', '');
        $ut  = [];
        if ($raa !== '') {
            $j = json_decode($raa, true);
            if (is_array($j)) {
                foreach ($j as $p) {
                    if (self::gyldig($p)) {
                        $ut[] = self::rens($p);
                    }
                }
            }
        }

        // Eierens egne. De legges til, og kan fjerne en hentet periode ved aa
        // ha samme navn og skoleaar — saa en ferie fylket fører feil kan
        // rettes uten aa vente paa dem.
        $egne = json_decode((string) Config::hent('skolerute_egne', ''), true);
        if (is_array($egne)) {
            foreach ($egne as $p) {
                if (!self::gyldig($p)) {
                    continue;
                }
                $p = self::rens($p);
                $ut = array_values(array_filter(
                    $ut,
                    static fn(array $g): bool => !($g['navn'] === $p['navn'] && $g['skoleaar'] === $p['skoleaar'])
                ));
                if (($p['fra'] ?? '') !== '' && ($p['til'] ?? '') !== '') {
                    $ut[] = $p;
                }
            }
        }

        usort($ut, static fn(array $a, array $b): int => $a['fra'] <=> $b['fra']);
        return self::$bufret = $ut;
    }

    /** Glem bufferet — etter en henting eller en endring. */
    public static function glem(): void
    {
        self::$bufret = null;
    }

    /**
     * Hva skjermen trenger for aa si hvordan det staar til.
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        $hentet = trim((string) Config::hent('skolerute_hentet', ''));
        $p = self::perioder();
        $aar = array_values(array_unique(array_map(
            static fn(array $r): string => $r['skoleaar'],
            $p
        )));
        sort($aar);

        return [
            'perioder'  => count($p),
            'skoleaar'  => $aar,
            'hentet'    => $hentet,
            'fersk'     => $hentet !== '' && $hentet >= gmdate('Y-m-d', time() - self::DAGER_FERSK * 86400),
            'kilde'     => self::KILDE,
            'sisteDato' => $p === [] ? '' : $p[count($p) - 1]['til'],
        ];
    }

    // ── Hentingen ────────────────────────────────────────────────────

    /** Skal vi hente naa? Kalles fra jobben. */
    public static function borHente(): bool
    {
        $hentet = trim((string) Config::hent('skolerute_hentet', ''));
        return $hentet === '' || $hentet < gmdate('Y-m-d', time() - self::DAGER_FERSK * 86400);
    }

    /**
     * Henter og lagrer. Feiler den, staar forrige svar urort.
     *
     * @return array{ok: bool, perioder: int, feil: string}
     */
    public static function hent(): array
    {
        try {
            $svar = http_kall(self::KILDE, 'GET', null, [
                'User-Agent: LissomBot/1.0 (+https://lissom.no)',
                'Accept: text/html',
            ], 20);
        } catch (Throwable $e) {
            return ['ok' => false, 'perioder' => 0, 'feil' => $e->getMessage()];
        }

        if ((int) $svar['status'] !== 200) {
            return ['ok' => false, 'perioder' => 0, 'feil' => 'Fylket svarte ' . $svar['status'] . '.'];
        }

        $perioder = self::tolk((string) $svar['kropp']);
        if ($perioder === []) {
            // Sida svarte, men vi kjente den ikke igjen. Da er den lagt om,
            // og det er en beskjed til et menneske — ikke noe aa lagre.
            return ['ok' => false, 'perioder' => 0,
                    'feil' => 'Fant ingen ferier på siden. Den er trolig lagt om.'];
        }

        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi) VALUES (?, ?), (?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
            ['skolerute_data', json_encode($perioder, JSON_UNESCAPED_UNICODE),
             'skolerute_hentet', gmdate('Y-m-d')]
        );
        Config::glemBasen();
        self::glem();

        return ['ok' => true, 'perioder' => count($perioder), 'feil' => ''];
    }

    /**
     * Leser periodene ut av sida.
     *
     * Staar for seg og tar imot HTML som tekst, saa den kan proeves mot en
     * lagret kopi naar fylket legger om — uten aa gaa paa nettet.
     *
     * Sida er bygget slik: en overskrift «2026/2027», og under den én linje
     * per punkt. Aarstallet staar aldri paa datoen, bare dag og maaned, saa
     * det utledes av skoleaaret: august–desember er foerste aar, januar–juli
     * det andre.
     *
     * @return list<array{navn: string, fra: string, til: string, skoleaar: string}>
     */
    public static function tolk(string $html): array
    {
        $tekst = html_entity_decode(
            preg_replace('/<[^>]*>/', "\n", $html) ?? '',
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $linjer = array_values(array_filter(array_map(
            static fn(string $l): string => trim(preg_replace('/\s+/u', ' ', $l) ?? ''),
            explode("\n", $tekst)
        ), static fn(string $l): bool => $l !== ''));

        // Del i blokker, én per skoleaar.
        $blokker = [];
        $naa = null;
        foreach ($linjer as $l) {
            if (preg_match('~^(\d{4})\s*/\s*(\d{4})$~', $l, $m) === 1) {
                $naa = $m[1] . '/' . $m[2];
                $blokker[$naa] = $blokker[$naa] ?? [];
                continue;
            }
            if ($naa !== null) {
                $blokker[$naa][] = $l;
            }
        }

        // ── Linjer som hoerer sammen, settes sammen ──────────────────
        //
        // Hoestferien staar over to linjer paa sida:
        //
        //   «Høstferie: uke 41»
        //   «(f.o.m. mandag 6. oktober t.o.m. fredag 10. oktober)»
        //
        // Foerste linje har ingen datoer og andre linje intet navn. Hver for
        // seg er de ubrukelige; sammen er de hele opplysningen. En linje som
        // begynner med parentes eller med «f.o.m.» hoerer til den over.
        foreach ($blokker as $aar => $rader) {
            $slaatt = [];
            foreach ($rader as $l) {
                if ($slaatt !== [] && preg_match('/^[(]|^f\.o\.m\./iu', $l) === 1) {
                    $slaatt[count($slaatt) - 1] .= ' ' . $l;
                    continue;
                }
                $slaatt[] = $l;
            }
            $blokker[$aar] = $slaatt;
        }

        // ── Skolestart for alle aarene, foer noe annet ───────────────
        //
        // Sommerferien slutter naar NESTE skoleaar begynner, og det aaret
        // staar lenger ned paa sida. Leses startene underveis, er neste aar
        // ukjent naar sommeren for inneverende regnes ut — og alle
        // sommerferier ble «anslag» selv om svaret sto rett under.
        $skolestart = [];
        foreach ($blokker as $skoleaar => $rader) {
            [$a1, $a2] = array_map('intval', explode('/', $skoleaar));
            foreach ($rader as $l) {
                if (!str_contains(mb_strtolower($l), 'skolestart')) {
                    continue;
                }
                $d = self::datoerI($l, static function (int $dag, string $maaned) use ($a1, $a2): string {
                    $m = self::MAANEDER[mb_strtolower($maaned)] ?? 0;
                    return $m === 0 ? '' : sprintf('%04d-%02d-%02d', $m >= 8 ? $a1 : $a2, $m, $dag);
                });
                if ($d !== []) {
                    $skolestart[$skoleaar] = $d[0];
                    break;
                }
            }
        }

        $ut = [];
        foreach ($blokker as $skoleaar => $rader) {
            [$aar1, $aar2] = array_map('intval', explode('/', $skoleaar));
            $dato = static function (int $dag, string $maaned) use ($aar1, $aar2): string {
                $m = self::MAANEDER[mb_strtolower($maaned)] ?? 0;
                if ($m === 0) {
                    return '';
                }
                return sprintf('%04d-%02d-%02d', $m >= 8 ? $aar1 : $aar2, $m, $dag);
            };

            $paaskeFra = '';
            $julFra = '';
            $sisteSkoledag = '';

            foreach ($rader as $l) {
                $lav = mb_strtolower($l);
                $datoer = self::datoerI($l, $dato);

                if (str_contains($lav, 'skolestart')) {
                    continue;   // lest i forhaandsrunden over
                }
                if (str_contains($lav, 'høstferie') && count($datoer) >= 2) {
                    $ut[] = ['navn' => 'Høstferie', 'fra' => $datoer[0], 'til' => $datoer[1],
                             'skoleaar' => $skoleaar];
                    continue;
                }
                if (str_contains($lav, 'vinterferie') && count($datoer) >= 2) {
                    $ut[] = ['navn' => 'Vinterferie', 'fra' => $datoer[0], 'til' => $datoer[1],
                             'skoleaar' => $skoleaar];
                    continue;
                }
                if (str_contains($lav, 'påskeferie') && $datoer !== []) {
                    // Ett aar staar hele paasken paa én linje, et annet aar er
                    // den delt over to fordi den krysser maanedsskiftet.
                    if (count($datoer) >= 2) {
                        $ut[] = ['navn' => 'Påskeferie', 'fra' => $datoer[0], 'til' => $datoer[1],
                                 'skoleaar' => $skoleaar];
                        $paaskeFra = '';
                    } elseif (str_contains($lav, 'f.o.m.')) {
                        $paaskeFra = $datoer[0];
                    } elseif (str_contains($lav, 't.o.m.') && $paaskeFra !== '') {
                        $ut[] = ['navn' => 'Påskeferie', 'fra' => $paaskeFra, 'til' => $datoer[0],
                                 'skoleaar' => $skoleaar];
                        $paaskeFra = '';
                    }
                    continue;
                }
                if (str_contains($lav, 'før jul') && $datoer !== []) {
                    $julFra = self::pluss($datoer[0], 1);
                    continue;
                }
                if (str_contains($lav, 'etter nyttår') && $datoer !== [] && $julFra !== '') {
                    $ut[] = ['navn' => 'Juleferie', 'fra' => $julFra,
                             'til' => self::pluss($datoer[0], -1), 'skoleaar' => $skoleaar];
                    $julFra = '';
                    continue;
                }
                if (str_contains($lav, 'siste skoledag') && !str_contains($lav, 'jul') && $datoer !== []) {
                    $sisteSkoledag = $datoer[0];
                }
            }

            if ($sisteSkoledag !== '') {
                // Sommerferien slutter naar neste skoleaar begynner. Er det
                // aaret ikke publisert ennaa, settes slutten til 15. august —
                // et anslag, og det staar i navnet at det er det.
                $neste = ($aar2) . '/' . ($aar2 + 1);
                $slutt = $skolestart[$neste] ?? '';
                $ut[] = [
                    'navn'     => $slutt === '' ? 'Sommerferie (anslag)' : 'Sommerferie',
                    'fra'      => self::pluss($sisteSkoledag, 1),
                    'til'      => $slutt === '' ? sprintf('%04d-08-15', $aar2) : self::pluss($slutt, -1),
                    'skoleaar' => $skoleaar,
                ];
            }
        }

        usort($ut, static fn(array $a, array $b): int => $a['fra'] <=> $b['fra']);
        return $ut;
    }

    // ── Smaating ─────────────────────────────────────────────────────

    /**
     * Datoene i én linje, i den rekkefoelgen de staar.
     *
     * @return list<string>
     */
    private static function datoerI(string $linje, callable $dato): array
    {
        $navn = implode('|', array_keys(self::MAANEDER));
        if (preg_match_all('/(\d{1,2})\.\s*(' . $navn . ')/iu', $linje, $treff, PREG_SET_ORDER) === false) {
            return [];
        }
        $ut = [];
        foreach ($treff as $t) {
            $d = $dato((int) $t[1], $t[2]);
            if ($d !== '') {
                $ut[] = $d;
            }
        }
        return $ut;
    }

    private static function pluss(string $iso, int $dager): string
    {
        $t = strtotime($iso . ' ' . ($dager >= 0 ? '+' : '-') . abs($dager) . ' day');
        return $t === false ? $iso : date('Y-m-d', $t);
    }

    private static function norsk(string $iso): string
    {
        $t = strtotime($iso);
        if ($t === false) {
            return $iso;
        }
        $m = array_keys(self::MAANEDER);
        return (int) date('j', $t) . '. ' . $m[(int) date('n', $t) - 1];
    }

    /** @param mixed $p */
    private static function gyldig($p): bool
    {
        return is_array($p)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['fra'] ?? '')) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['til'] ?? '')) === 1
            && trim((string) ($p['navn'] ?? '')) !== ''
            && (string) ($p['fra'] ?? '') <= (string) ($p['til'] ?? '');
    }

    /**
     * @param array<string,mixed> $p
     * @return array{navn: string, fra: string, til: string, skoleaar: string}
     */
    private static function rens(array $p): array
    {
        return [
            'navn'     => mb_substr(trim((string) $p['navn']), 0, 40),
            'fra'      => (string) $p['fra'],
            'til'      => (string) $p['til'],
            'skoleaar' => mb_substr(trim((string) ($p['skoleaar'] ?? '')), 0, 9),
        ];
    }
}
