<?php
/**
 * Teksten inni en PDF.
 *
 * Eieren, 11. september 2026: «kan man ikke stille inn saa pdf opplastinger
 * kan inkluderes i ai soeket, slik at mer og mer kunnskap vil komme til?»
 * Spurt hvordan, valgte han «Server foerst, Claude hvis tom».
 *
 * Dette er serveren. Den er gratis og tar millisekunder, men den klarer bare
 * det som faktisk ER tekst i fila:
 *
 *   En PDF laget paa en PC — Word, Pages, «skriv ut til PDF» — har bokstavene
 *   liggende inni seg, og da henter vi dem ut her.
 *
 *   Et skannet ark er et BILDE av tekst. Der finnes ingen bokstaver aa hente,
 *   uansett hvor godt vi leter. Da svarer vi tomt, og Claude leser arket i
 *   stedet — se Dokumenter::lesMedAi().
 *
 * ── Hvorfor det er en kvalitetssjekk til slutt ────────────────────────
 *
 * En PDF kan lagre bokstavene som nummer i en font i stedet for som
 * bokstaver («Identity-H»). Henter vi dem ut raatt, faar vi noe som SER ut
 * som tekst — riktig lengde, riktig ordlengde — men er sproyt. Det er verre
 * enn ingenting: da tror bade vi og AI-en at dokumentet er lest.
 *
 * Derfor proever vi to veier, og maaler begge:
 *
 *   1. bytene som vanlige tegn (WinAnsi — det de aller fleste bruker)
 *   2. bytene slaatt opp i fontens egen oversettelsestabell (ToUnicode)
 *
 * Den som ser mest ut som norsk tekst vinner. Ser ingen av dem riktige ut,
 * sier vi at vi ikke fant noe — og Claude tar den.
 */

declare(strict_types=1);

final class Pdftekst
{
    /** Samme tak som feltet eieren limer inn i. Se api/admin/dokumenter.php. */
    public const MAKS_TEGN = 200000;

    /**
     * Kan serveren i det hele tatt pakke ut en PDF?
     *
     * Nesten alt innhold i en PDF er zlib-pakket. Uten zlib-utvidelsen faar
     * vi bare ut det som tilfeldigvis ligger upakket, og det er sjelden noe.
     * Da er det aerligere aa si nei med en gang og la Claude ta alle.
     */
    public static function kan(): bool
    {
        return function_exists('gzuncompress');
    }

    /**
     * Teksten i fila, eller '' naar det ikke er tekst aa hente.
     *
     * Kaster aldri. En PDF som er raar, halv, eller laget av et program vi
     * ikke kjenner, skal gi tomt svar — ikke stoppe en opplasting.
     */
    public static function les(string $sti): string
    {
        if (!self::kan() || !is_file($sti)) {
            return '';
        }
        $raa = @file_get_contents($sti);
        if (!is_string($raa) || !str_starts_with($raa, '%PDF')) {
            return '';
        }

        [$innhold, $tabeller] = self::stroemmer($raa);
        if ($innhold === []) {
            return '';
        }

        $kart = self::tilUnicode($tabeller);
        $biter = [];
        foreach ($innhold as $s) {
            $biter[] = self::sider($s);
        }

        $raatt  = self::sett($biter, null);
        $oversatt = $kart === [] ? '' : self::sett($biter, $kart);

        $best = self::poeng($oversatt) > self::poeng($raatt) ? $oversatt : $raatt;
        return self::ekte($best) ? mb_substr(self::ryddLinjer($best), 0, self::MAKS_TEGN) : '';
    }

    /**
     * Ser dette ut som tekst et menneske har skrevet?
     *
     * Ligger offentlig fordi den ogsaa er svaret paa «ble dette bra nok?»
     * etter at Claude har lest et ark.
     */
    public static function ekte(string $t): bool
    {
        return self::poeng($t) > 0;
    }

    /**
     * Andelen kjente tegn, ganget med lengden — null naar det ikke holder.
     *
     * Kjente tegn er bokstaver (ogsaa æ, ø, å), tall, mellomrom og vanlig
     * tegnsetting. Sproeyt fra en feiltolket font er fullt av alt annet.
     */
    private static function poeng(string $t): int
    {
        $t = trim($t);
        if ($t === '') {
            return 0;
        }
        $antall = mb_strlen($t);
        // Under dette er det ikke et dokument — det er en overskrift eller en
        // sidefot som tilfeldigvis lot seg lese.
        if ($antall < 40) {
            return 0;
        }
        $kjente = preg_match_all('/[\p{L}\p{N}\s.,;:!?()\[\]%\-–—\/\'"«»+=°&@*#]/u', $t);
        $andel  = $kjente / $antall;
        return $andel >= 0.80 ? (int) round($andel * $antall) : 0;
    }

    // ── Stroemmene ──────────────────────────────────────────────────────

    /**
     * Alle stroemmene i fila, pakket ut, delt i to hauger.
     *
     * Vi leser ikke krysstabellen. Den ligger ofte pakket selv i nyere
     * PDF-er, og for aa finne TEKST trenger vi den ikke: vi vil ha alt som
     * ser ut som en side, og alt som ser ut som en oversettelsestabell.
     *
     * @return array{0:list<string>,1:list<string>} sider, tabeller
     */
    private static function stroemmer(string $raa): array
    {
        $sider = [];
        $tabeller = [];
        $i = 0;
        $n = strlen($raa);

        while (($start = strpos($raa, 'stream', $i)) !== false) {
            $p = $start + 6;
            // Etter «stream» kommer CRLF eller LF. Ingenting annet er lov.
            if ($p < $n && $raa[$p] === "\r") {
                $p++;
            }
            if ($p < $n && $raa[$p] === "\n") {
                $p++;
            }
            $slutt = strpos($raa, 'endstream', $p);
            if ($slutt === false) {
                break;
            }
            $i = $slutt + 9;

            $data = substr($raa, $p, $slutt - $p);
            $ut = self::pakkUt($data);
            if ($ut === '') {
                continue;
            }
            if (str_contains($ut, 'beginbfchar') || str_contains($ut, 'beginbfrange')) {
                $tabeller[] = $ut;
            } elseif (str_contains($ut, 'Tj') || str_contains($ut, 'TJ')) {
                $sider[] = $ut;
            }
        }

        return [$sider, $tabeller];
    }

    /** zlib-pakket, upakket, eller noe vi ikke kan lese (da: ''). */
    private static function pakkUt(string $data): string
    {
        // Halene fra «endstream» kan ha med seg et linjeskift fra fila.
        $d = rtrim($data, "\r\n");
        if ($d === '') {
            return '';
        }
        // zlib begynner alltid paa 0x78. Er den der, er det verdt et forsoek.
        if ($d[0] === "\x78") {
            $ut = @gzuncompress($d);
            if (is_string($ut) && $ut !== '') {
                return $ut;
            }
        }
        // Noen skrivere legger paa et raatt deflate-lag uten hode.
        $ut = @gzinflate($d);
        if (is_string($ut) && $ut !== '') {
            return $ut;
        }
        // Upakket innhold finnes ogsaa — men bare tekst, ikke bilder.
        return preg_match('/[\x00-\x08\x0e-\x1f]/', substr($d, 0, 400)) === 1 ? '' : $d;
    }

    // ── Oversettelsestabellene ──────────────────────────────────────────

    /**
     * ToUnicode-tabellene slaatt sammen til én.
     *
     * Fontene har hver sin, og vi slaar dem sammen uten aa vite hvilken font
     * som gjelder hvor. Det er en forenkling, og den kan gi feil tegn i et
     * dokument med flere fonter som bruker de samme numrene. Sjekken til
     * slutt fanger det: blir det sproeyt, gaar dokumentet til Claude.
     *
     * @param  list<string> $tabeller
     * @return array<string,string> hex inn → tegn ut
     */
    private static function tilUnicode(array $tabeller): array
    {
        $kart = [];
        foreach ($tabeller as $t) {
            // <0041> <0042> ... enkeltvis
            if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $t, $m)) {
                foreach ($m[1] as $blokk) {
                    if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blokk, $par, PREG_SET_ORDER)) {
                        foreach ($par as $p) {
                            $kart[strtoupper($p[1])] = self::utf8($p[2]);
                        }
                    }
                }
            }
            // <0041> <005A> <0061> — et helt spenn paa én linje
            if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $t, $m2)) {
                foreach ($m2[1] as $blokk) {
                    if (preg_match_all(
                        '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/',
                        $blokk,
                        $tre,
                        PREG_SET_ORDER
                    )) {
                        foreach ($tre as $p) {
                            $fra = hexdec($p[1]);
                            $til = hexdec($p[2]);
                            $mal = hexdec($p[3]);
                            // Et spenn paa tusenvis er en tabell vi har lest
                            // feil. Da lar vi den vaere.
                            if ($til < $fra || $til - $fra > 4000) {
                                continue;
                            }
                            $bredde = strlen($p[1]);
                            for ($k = $fra; $k <= $til; $k++) {
                                $kart[strtoupper(str_pad(dechex($k), $bredde, '0', STR_PAD_LEFT))]
                                    = self::utf8(dechex($mal + ($k - $fra)));
                            }
                        }
                    }
                }
            }
        }
        return $kart;
    }

    /** «00E5» → «å». Flere tegn etter hverandre blir flere tegn. */
    private static function utf8(string $hex): string
    {
        $hex = strlen($hex) % 4 === 0 ? $hex : str_pad($hex, 4, '0', STR_PAD_LEFT);
        $ut = '';
        foreach (str_split($hex, 4) as $bit) {
            $kode = (int) hexdec($bit);
            if ($kode > 0 && $kode !== 0xFFFD) {
                $ut .= mb_chr($kode, 'UTF-8');
            }
        }
        return $ut;
    }

    // ── Sidene ──────────────────────────────────────────────────────────

    /**
     * Strengene paa én side, i rekkefoelge, med linjeskift der de hoerer.
     *
     * Vi tar vare paa selve BYTENE her og bestemmer oss ikke for hva de betyr
     * — det gjoer sett() etterpaa, én gang per tolkning.
     *
     * @return list<array{0:string,1:string}> ['s', bytes] eller ['br', '']
     */
    private static function sider(string $s): array
    {
        $ut = [];
        $i = 0;
        $n = strlen($s);
        $ord = '';
        // Tallene som staar foran operatoren. «7.2 0 Td» er to av dem.
        $tall = [];
        // Y-en fra forrige «Tm». null betyr at vi ikke har sett en ennaa.
        $sisteY = null;

        // ── Naar er det en ny linje? ────────────────────────────────────
        //
        // Her sto «Td gir linjeskift», punktum. Det var feil, og det viste
        // seg foerst 12. september paa en plakat: skriveren setter HVER
        // BOKSTAV for seg med sitt eget Td —
        //
        //     <0021> Tj  7.21 0 Td <0019> Tj  5.61 0 Td <0032> Tj
        //
        // — og da kom teksten ut med én bokstav per linje. «L» og «I» og «S»
        // under hverandre i stedet for «LISSOM».
        //
        // Td flytter skrivehodet med (tx, ty). Det er «ty» som betyr noe: er
        // den null, staar vi paa den samme linja og flytter oss bare
        // bortover. Mellomrommene mellom ordene er egne tegn i teksten, saa
        // de kommer med uansett.
        //
        // «Tm» setter posisjonen absolutt, med seks tall. Et nytt Tm med en
        // annen y er ogsaa en ny linje — slik gjor mange skrivere det.
        $avslutt = static function () use (&$ord, &$ut, &$tall, &$sisteY): void {
            if ($ord === '') {
                $tall = [];
                return;
            }
            $bryt = false;
            if ($ord === 'Td' || $ord === 'TD') {
                $ty = count($tall) >= 2 ? (float) $tall[count($tall) - 1] : 0.0;
                $bryt = abs($ty) > 0.01;
            } elseif ($ord === 'Tm') {
                $y = count($tall) >= 6 ? (float) $tall[count($tall) - 1] : null;
                $bryt = $y !== null && $sisteY !== null && abs($y - $sisteY) > 0.01;
                if ($y !== null) {
                    $sisteY = $y;
                }
            } elseif (in_array($ord, ['T*', 'ET', "'", '"'], true)) {
                $bryt = true;
            }
            if ($bryt) {
                $ut[] = ['br', ''];
            }
            $ord = '';
            $tall = [];
        };

        while ($i < $n) {
            $c = $s[$i];

            if ($c === '(') {
                $avslutt();
                [$bytes, $i] = self::streng($s, $i + 1);
                $ut[] = ['s', $bytes];
                continue;
            }
            if ($c === '<' && $i + 1 < $n && $s[$i + 1] !== '<') {
                $avslutt();
                $slutt = strpos($s, '>', $i + 1);
                if ($slutt === false) {
                    break;
                }
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $slutt - $i - 1)) ?? '';
                $ut[] = ['s', strlen($hex) % 2 === 0 ? (string) hex2bin($hex) : ''];
                $i = $slutt + 1;
                continue;
            }
            if ($c === '%') {
                $linje = strpos($s, "\n", $i);
                $i = $linje === false ? $n : $linje + 1;
                continue;
            }
            // Et tall: «7.2119904», «-.24», «0». Vi tar vare paa det til vi
            // ser hvilken operator det hoerer til.
            if ($c === '-' || $c === '+' || $c === '.' || ($c >= '0' && $c <= '9')) {
                $j = $i;
                while ($j < $n && (
                    $s[$j] === '-' || $s[$j] === '+' || $s[$j] === '.'
                    || ($s[$j] >= '0' && $s[$j] <= '9')
                )) {
                    $j++;
                }
                $ord = '';
                $tall[] = substr($s, $i, $j - $i);
                $i = $j;
                continue;
            }
            if (preg_match('/[\sa-zA-Z*\'"]/', $c) === 1) {
                if (trim($c) === '') {
                    // Mellomrom skiller tall fra tall. Er det et ord som er
                    // ferdig skrevet, er det en operator, og den avgjores naa.
                    if ($ord !== '') {
                        $avslutt();
                    }
                } else {
                    $ord .= $c;
                }
                $i++;
                continue;
            }
            $avslutt();
            $i++;
        }
        $avslutt();

        return $ut;
    }

    /**
     * Én «(...)»-streng, med escapene PDF bruker.
     *
     * @return array{0:string,1:int} bytene, og hvor vi stoppet
     */
    private static function streng(string $s, int $i): array
    {
        $n = strlen($s);
        $dybde = 1;
        $ut = '';
        $oktal = ['0', '1', '2', '3', '4', '5', '6', '7'];

        while ($i < $n) {
            $c = $s[$i];
            if ($c === '\\') {
                $neste = $i + 1 < $n ? $s[$i + 1] : '';
                if (in_array($neste, $oktal, true)) {
                    $tall = '';
                    $j = $i + 1;
                    while ($j < $n && strlen($tall) < 3 && in_array($s[$j], $oktal, true)) {
                        $tall .= $s[$j];
                        $j++;
                    }
                    $ut .= chr((int) octdec($tall) & 0xFF);
                    $i = $j;
                    continue;
                }
                $ut .= match ($neste) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0c",
                    // Linjeskift rett etter en bakstrek betyr «samme linje».
                    "\n", "\r" => '',
                    default => $neste,
                };
                $i += 2;
                continue;
            }
            if ($c === '(') {
                $dybde++;
            } elseif ($c === ')') {
                $dybde--;
                if ($dybde === 0) {
                    return [$ut, $i + 1];
                }
            }
            $ut .= $c;
            $i++;
        }
        return [$ut, $i];
    }

    // ── De to tolkningene ───────────────────────────────────────────────

    /**
     * Bitene satt sammen til tekst — enten raatt, eller gjennom tabellen.
     *
     * @param list<list<array{0:string,1:string}>> $biter
     * @param array<string,string>|null            $kart null = raatt
     */
    private static function sett(array $biter, ?array $kart): string
    {
        $linjer = [];
        foreach ($biter as $side) {
            $linje = '';
            foreach ($side as [$slag, $bytes]) {
                if ($slag === 'br') {
                    $linjer[] = $linje;
                    $linje = '';
                    continue;
                }
                $linje .= $kart === null ? self::winAnsi($bytes) : self::slaaOpp($bytes, $kart);
            }
            $linjer[] = $linje;
        }
        return implode("\n", $linjer);
    }

    /**
     * Byte for byte, slik de aller fleste PDF-er mener dem.
     *
     * WinAnsi er Latin-1 med tjue egne tegn mellom 0x80 og 0x9F. De tjue er
     * anfoerselstegn, tankestreker og lignende — det som gjoer at en norsk
     * tekst leses riktig i stedet for aa faa hull i seg.
     */
    private static function winAnsi(string $bytes): string
    {
        static $egne = [
            0x80 => '€', 0x82 => '‚', 0x83 => 'ƒ', 0x84 => '„', 0x85 => '…',
            0x86 => '†', 0x87 => '‡', 0x88 => 'ˆ', 0x89 => '‰', 0x8A => 'Š',
            0x8B => '‹', 0x8C => 'Œ', 0x8E => 'Ž', 0x91 => "\u{2018}", 0x92 => "\u{2019}",
            0x93 => '“', 0x94 => '”', 0x95 => '•', 0x96 => '–', 0x97 => '—',
            0x98 => '˜', 0x99 => '™', 0x9A => 'š', 0x9B => '›', 0x9C => 'œ',
            0x9E => 'ž', 0x9F => 'Ÿ',
        ];
        $ut = '';
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $b = ord($bytes[$i]);
            if ($b === 9 || $b === 10 || $b === 13) {
                $ut .= ' ';
            } elseif ($b < 32) {
                $ut .= '';
            } elseif ($b < 127) {
                $ut .= $bytes[$i];
            } elseif (isset($egne[$b])) {
                $ut .= $egne[$b];
            } elseif ($b >= 0xA0) {
                $ut .= mb_chr($b, 'UTF-8');
            }
        }
        return $ut;
    }

    /**
     * To byte om gangen, slaatt opp i fontens tabell.
     *
     * Naar tabellen er laget for ett byte, prover vi ett foerst. Treffer
     * ingen av delene, dropper vi tegnet — et tegn vi ikke vet hva er, skal
     * ikke gjettes.
     *
     * @param array<string,string> $kart
     */
    private static function slaaOpp(string $bytes, array $kart): string
    {
        $ut = '';
        $n = strlen($bytes);
        for ($i = 0; $i < $n;) {
            $ett = strtoupper(bin2hex($bytes[$i]));
            if (isset($kart[$ett])) {
                $ut .= $kart[$ett];
                $i++;
                continue;
            }
            if ($i + 1 < $n) {
                $to = strtoupper(bin2hex(substr($bytes, $i, 2)));
                if (isset($kart[$to])) {
                    $ut .= $kart[$to];
                    $i += 2;
                    continue;
                }
            }
            $i += $i + 1 < $n ? 2 : 1;
        }
        return $ut;
    }

    /** Tomme linjer og doble mellomrom bort — det er ord AI-en skal lese. */
    private static function ryddLinjer(string $t): string
    {
        $t = (string) preg_replace('/[ \t]+/', ' ', $t);
        $t = (string) preg_replace('/\n{3,}/', "\n\n", $t);
        $linjer = array_map('trim', explode("\n", $t));
        return trim(implode("\n", array_filter($linjer, static fn($l) => $l !== '')));
    }
}
