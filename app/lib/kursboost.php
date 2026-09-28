<?php
/**
 * Kursboost som ferdig flyt.
 *
 * Eieren, 27. september 2026: «jeg har kursboost, som systemet foreslaar
 * selv, men det er ikke bilde eller video generator her, og jeg vet ikke hvor
 * det er tenkt aa bruke det heller». Pakken ble laget og ble liggende —
 * godkjenning sendte den ingen steder. Han sa GO paa skissen: bilde laget av
 * kursets egne bilder, tre forslag, og én knapp per del.
 *
 * Ingenting her er nytt maskineri. Bildet lages av Gemini::lagKursbilde(),
 * Instagram og Facebook gaar gjennom Meta, artikkelen lages via
 * Artikler::kladdFraUtkast() og publiseres med det samme (fra 27. september
 * 2026, se utfor()), nyhetsbrevet blir et vanlig utkast som sendes
 * fra Beskjeder, og meldingen til medlemmene gaar gjennom api/admin/beskjed.php.
 *
 * Det pakken selv holder rede paa, ligger i ai_utkast.data under «kb»:
 * bildeforslagene, hvilket som er valgt, og hvilke deler som er gjort. En
 * del som er gjort, kan ikke gjoeres igjen herfra — da maa det lages en ny.
 */

declare(strict_types=1);

final class Kursboost
{
    /** Delene i pakken, i den rekkefoelgen de staar paa skjermen. */
    public const DELER = ['instagram', 'facebook', 'artikkel', 'nyhetsbrev', 'medlemmer'];

    /** Hvor mange bildeforslag hver runde lager. */
    public const FORSLAG = 3;

    /**
     * Utkastet, hvis det er en kursboost.
     *
     * @return array<string,mixed>|null
     */
    public static function utkast(int $id): ?array
    {
        $u = DB::en("SELECT * FROM ai_utkast WHERE id = :i AND type = 'kursboost'", ['i' => $id]);
        if ($u === null) {
            return null;
        }
        $u['data'] = json_decode((string) ($u['data'] ?? '{}'), true) ?: [];
        return $u;
    }

    /** @param array<string,mixed> $data */
    private static function lagre(int $id, array $data): void
    {
        DB::oppdater('ai_utkast', ['data' => json_encode($data, JSON_UNESCAPED_UNICODE)], ['id' => $id]);
    }

    /**
     * Naar delen ble gjort, eller null.
     *
     * @return array<string,mixed>|null
     */
    public static function gjort(int $id, string $del): ?array
    {
        $u = self::utkast($id);
        $g = $u['data']['kb']['gjort'][$del] ?? null;
        return is_array($g) ? $g : null;
    }

    /** @param array<string,mixed> $resultat */
    public static function merk(int $id, string $del, array $resultat = []): void
    {
        $u = self::utkast($id);
        if ($u === null) {
            return;
        }
        $data = $u['data'];
        $data['kb']['gjort'][$del] = ['tid' => gmdate('c')] + $resultat;
        self::lagre($id, $data);
    }

    /**
     * Teksten hver del starter med — det AI-en skrev, eller det eieren
     * rettet til sist.
     *
     * @param array<string,mixed> $data
     */
    public static function tekst(array $data, string $del): string
    {
        $rettet = $data['kb']['tekst'][$del] ?? null;
        if (is_string($rettet)) {
            return $rettet;
        }
        $emneknagger = implode(' ', array_map(
            static fn($h) => '#' . ltrim(trim((string) $h), '#'),
            array_filter((array) ($data['hashtags'] ?? []))
        ));
        return match ($del) {
            'instagram'  => trim((string) ($data['instagram'] ?? '') . ($emneknagger !== '' ? "\n\n" . $emneknagger : '')),
            'facebook'   => (string) ($data['facebook'] ?? ''),
            'artikkel'   => (string) ($data['artikkel']['tekst'] ?? ''),
            'nyhetsbrev' => (string) ($data['epost']['tekst'] ?? ''),
            'medlemmer'  => (string) ($data['medlemmer'] ?? ''),
            default      => '',
        };
    }

    /**
     * Pakken slik skjermen trenger den.
     *
     * @param array<string,mixed> $u
     * @return array<string,mixed>
     */
    public static function pakke(array $u): array
    {
        $data = $u['data'];
        $deler = [];
        foreach (self::DELER as $del) {
            $deler[$del] = [
                'tekst' => self::tekst($data, $del),
                'gjort' => $data['kb']['gjort'][$del] ?? null,
            ];
        }
        return [
            'id'            => (int) $u['id'],
            'kurs'          => (string) ($u['kontekst'] ?? ''),
            'artikkelTittel' => (string) ($data['artikkel']['tittel'] ?? ''),
            'epostEmne'     => (string) ($data['epost']['emne'] ?? ''),
            'bilder'        => array_values((array) ($data['kb']['bilder'] ?? [])),
            'valgt'         => (string) ($data['kb']['valgt'] ?? ''),
            'deler'         => $deler,
        ];
    }

    /**
     * Kursets egne bilder, som raa byte. Maks tre.
     *
     * Kurset staar paa utkastet fra 27. september 2026. Eldre utkast har
     * bare navnet i «kontekst», og da slaas kurset opp paa tittelen.
     *
     * @param array<string,mixed> $u
     * @return list<string>
     */
    public static function kursbilder(array $u): array
    {
        $kursId = (int) ($u['data']['kursId'] ?? 0);
        $k = $kursId > 0
            ? DB::en('SELECT bilde, bilder FROM courses WHERE id = :i', ['i' => $kursId])
            : DB::en('SELECT bilde, bilder FROM courses WHERE tittel = :t LIMIT 1', ['t' => (string) ($u['kontekst'] ?? '')]);
        if ($k === null) {
            return [];
        }
        $navn = array_filter(array_merge(
            (array) (json_decode((string) ($k['bilder'] ?? ''), true) ?: []),
            [(string) ($k['bilde'] ?? '')]
        ));
        $ut = [];
        foreach (array_unique($navn) as $b) {
            $b = (string) $b;
            $sti = null;
            if (preg_match('~^api/bilde\.php\?artikkel=([0-9a-f]{32}\.jpg)$~', $b, $m) === 1) {
                $sti = Bilder::sti($m[1], 'artikler');
            } elseif (preg_match('~^design/underlogoer/[a-z0-9-]+/[a-z0-9-]+\.(png|jpe?g)$~', $b) === 1) {
                // Designmalene (Markedsføring › Designmaler), eieren 28.09.2026.
                $sti = is_file(dirname(__DIR__, 2) . '/' . $b) ? dirname(__DIR__, 2) . '/' . $b
                    : (is_file(dirname(__DIR__, 3) . '/public_html/' . $b) ? dirname(__DIR__, 3) . '/public_html/' . $b : null);
            } elseif (basename($b) === $b && preg_match('/\.(jpe?g|png|webp)$/i', $b) === 1) {
                // Nettsidas egne bilder ligger i rota ved siden av app/ lokalt,
                // og i public_html paa webhotellet.
                foreach ([dirname(__DIR__, 2) . '/' . $b, dirname(__DIR__, 3) . '/public_html/' . $b] as $mulig) {
                    if (is_file($mulig)) {
                        $sti = $mulig;
                        break;
                    }
                }
            }
            $raa = $sti !== null ? @file_get_contents($sti) : false;
            if (is_string($raa) && $raa !== '') {
                $ut[] = $raa;
            }
            if (count($ut) >= 3) {
                break;
            }
        }
        return $ut;
    }

    /**
     * Tre nye bildeforslag. Det gamle valget nullstilles: ingen forslag er
     * valgt paa forhaand.
     *
     * @param array<string,mixed> $u
     * @return array{bilder: list<string>, kostnadOre: int}
     */
    public static function lagBilder(array $u): array
    {
        $kursbilder = self::kursbilder($u);
        $kurs = (string) ($u['kontekst'] ?? 'kurset');
        $nye = [];
        $ore = 0;
        for ($i = 1; $i <= self::FORSLAG; $i++) {
            $b = Gemini::lagKursbilde($kursbilder, $kurs, $i);
            $nye[] = $b['url'];
            $ore += $b['kostnadOre'];
        }
        $data = $u['data'];
        $data['kb']['bilder'] = $nye;
        $data['kb']['valgt'] = '';
        self::lagre((int) $u['id'], $data);
        DB::kjor('UPDATE ai_utkast SET kostnad_ore = kostnad_ore + :o WHERE id = :i', ['o' => $ore, 'i' => (int) $u['id']]);
        return ['bilder' => $nye, 'kostnadOre' => $ore];
    }

    /**
     * Velg bildet som brukes i alle delene.
     *
     * Tre veier inn (eieren, 27. september 2026: «jeg vil også kunne velge
     * eller laste opp egne bilder»): et av Gemini-forslagene, et bilde fra
     * bildebiblioteket (det samme artiklene bruker), eller et eieren nettopp
     * lastet opp dit. Opplastingen gaar gjennom api/admin/bilder.php som
     * all annen opplasting, saa her kommer den inn som et bibliotekbilde.
     *
     * @param array<string,mixed> $u
     */
    public static function velgBilde(array $u, string $bilde): void
    {
        $data = $u['data'];
        if (!in_array($bilde, (array) ($data['kb']['bilder'] ?? []), true) && self::bildeSti($bilde) === null) {
            throw new RuntimeException('Velg ett av forslagene.');
        }
        $data['kb']['valgt'] = $bilde;
        self::lagre((int) $u['id'], $data);
    }

    /**
     * Hvor et bilde ligger paa disken, eller null.
     *
     * Bare de to formene bildebiblioteket selv lager: et opplastet bilde
     * («api/bilde.php?artikkel=<navn>.jpg») og et av nettsidas egne bilder
     * (et filnavn i rota). Alt annet avvises — ellers kunne en adresse
     * utenfra blitt lagt ut paa Instagram i verkstedets navn.
     */
    public static function bildeSti(string $bilde): ?string
    {
        if (preg_match('~^api/bilde\.php\?artikkel=([0-9a-f]{32}\.jpg)$~', $bilde, $m) === 1) {
            return Bilder::sti($m[1], 'artikler');
        }
        // Designmalene (Markedsføring › Designmaler), eieren 28.09.2026.
        if (preg_match('~^design/underlogoer/[a-z0-9-]+/[a-z0-9-]+\.(png|jpe?g)$~', $bilde) === 1) {
            foreach ([dirname(__DIR__, 2) . '/' . $bilde, dirname(__DIR__, 3) . '/public_html/' . $bilde] as $mulig) {
                if (is_file($mulig)) {
                    return $mulig;
                }
            }
            return null;
        }
        if (basename($bilde) === $bilde && preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $bilde) === 1) {
            foreach ([dirname(__DIR__, 2) . '/' . $bilde, dirname(__DIR__, 3) . '/public_html/' . $bilde] as $mulig) {
                if (is_file($mulig)) {
                    return $mulig;
                }
            }
        }
        return null;
    }

    /**
     * Bildet i Instagram-format, 4:5, klippet fra midten.
     *
     * Instagram tar ikke imot et bilde som er bredere enn 1,91:1 eller
     * smalere enn 4:5, og et liggende bibliotekbilde ble ellers avvist.
     * Utsnittet lagres i biblioteket én gang per bilde og brukes igjen.
     *
     * @param array<string,mixed> $u
     */
    public static function instagramBilde(array $u, string $bilde): string
    {
        $data = $u['data'];
        $lagret = $data['kb']['ig'][$bilde] ?? null;
        if (is_string($lagret) && self::bildeSti($lagret) !== null) {
            return $lagret;
        }
        $sti = self::bildeSti($bilde);
        $info = $sti !== null ? @getimagesize($sti) : false;
        if ($sti === null || $info === false) {
            return $bilde;
        }
        [$b, $h] = [(int) $info[0], (int) $info[1]];
        // Allerede 4:5 (eller svaert naer): brukes som det er.
        if ($h > 0 && abs($b / $h - 0.8) < 0.01) {
            return $bilde;
        }
        $les = match ($info[2]) {
            IMAGETYPE_PNG  => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default        => 'imagecreatefromjpeg',
        };
        $kilde = @$les($sti);
        if ($kilde === false) {
            return $bilde;
        }
        if ($b / $h > 0.8) { $nb = (int) round($h * 0.8); $nh = $h; } else { $nb = $b; $nh = (int) round($b / 0.8); }
        $x = (int) round(($b - $nb) / 2);
        $y = (int) round(($h - $nh) / 2);
        $ut = imagecreatetruecolor($nb, $nh);
        imagecopy($ut, $kilde, 0, 0, $x, $y, $nb, $nh);
        imagedestroy($kilde);
        ob_start();
        imagejpeg($ut, null, 88);
        $raa = (string) ob_get_clean();
        imagedestroy($ut);
        $navn = Bilder::taImotData($raa, 'artikler');
        $url = 'api/bilde.php?artikkel=' . $navn;
        $data['kb']['ig'][$bilde] = $url;
        self::lagre((int) $u['id'], $data);
        return $url;
    }

    /**
     * «Publiser»: Instagram, Facebook og artikkelen, med det samme bildet.
     *
     * Eieren, 27. september 2026: «poenget er jo at jeg bare skal kunne trykke
     * på publiser» — og han valgte at Publiser er de tre, mens e-posten og
     * meldingen til medlemmene har egne knapper. En del som alt er gjort,
     * hoppes over. En del som feiler, stopper ikke de andre: hver faar sitt
     * eget svar.
     *
     * @param array<string,mixed>  $u
     * @param array<string,string> $tekster rettet tekst per del
     * @return array<string, array{ok: bool, beskjed?: string, feil?: string, hoppetOver?: bool}>
     */
    public static function publiser(array $u, array $tekster): array
    {
        $id = (int) $u['id'];
        $svar = [];
        foreach (['instagram', 'facebook', 'artikkel'] as $del) {
            $naa = self::utkast($id);
            if ($naa === null) {
                break;
            }
            if (isset($naa['data']['kb']['gjort'][$del])) {
                $svar[$del] = ['ok' => true, 'hoppetOver' => true];
                continue;
            }
            try {
                $r = self::utfor($naa, $del, (string) ($tekster[$del] ?? ''));
                $svar[$del] = ['ok' => true, 'beskjed' => $r['beskjed']];
            } catch (Throwable $e) {
                $svar[$del] = ['ok' => false, 'feil' => $e instanceof RuntimeException
                    ? $e->getMessage() : 'Det gikk ikke. Prøv igjen.'];
                if (!$e instanceof RuntimeException) {
                    logg_feil('Kursboost: publisering av ' . $del, $e);
                }
            }
        }
        revider('kursboost_publiser', 'ai_utkast', $id, array_map(static fn($s) => $s['ok'] ? 'ok' : 'feil', $svar));
        return $svar;
    }

    /**
     * Gjoer én del: legger ut, lagrer kladd eller lager utkast.
     * «medlemmer» gaar ikke her — den sendes fra api/admin/beskjed.php.
     *
     * @param array<string,mixed> $u
     * @return array{beskjed: string, lenke?: string}
     */
    public static function utfor(array $u, string $del, string $tekst): array
    {
        $id = (int) $u['id'];
        if (!in_array($del, ['instagram', 'facebook', 'artikkel', 'nyhetsbrev'], true)) {
            throw new RuntimeException('Ukjent del.');
        }
        if (isset($u['data']['kb']['gjort'][$del])) {
            throw new RuntimeException('Denne delen er alt gjort. Lag en ny kursboost hvis den skal ut igjen.');
        }
        $tekst = trim($tekst) !== '' ? trim($tekst) : self::tekst($u['data'], $del);
        if ($tekst === '') {
            throw new RuntimeException('Delen har ingen tekst.');
        }
        // Teksten som faktisk ble brukt, lagres foer den gaar ut — da staar
        // det i basen det samme som folk leser.
        $data = $u['data'];
        $data['kb']['tekst'][$del] = $tekst;
        self::lagre($id, $data);
        $u['data'] = $data;

        $bilde = (string) ($data['kb']['valgt'] ?? '');
        $resultat = [];
        $beskjed = '';

        if ($del === 'instagram' || $del === 'facebook') {
            if ($bilde === '') {
                throw new RuntimeException($del === 'instagram'
                    ? 'Velg et bilde først. Instagram tar ikke imot innlegg uten.'
                    : 'Velg et bilde først.');
            }
            // Instagram faar 4:5-utsnittet; Facebook tar bildet som det er.
            $brukes = $del === 'instagram' ? self::instagramBilde($u, $bilde) : $bilde;
            $url = rtrim(Config::nettsted(), '/') . '/' . ltrim($brukes, '/');
            $ut = $del === 'instagram' ? Meta::publiserInstagram($url, $tekst) : Meta::publiserFacebook($url, $tekst);
            $resultat = ['innlegg' => (string) ($ut['id'] ?? ''), 'lenke' => (string) ($ut['lenke'] ?? '')];
            $beskjed = $del === 'instagram' ? 'Lagt ut på Instagram ✓' : 'Lagt ut på Facebook ✓';
        } elseif ($del === 'artikkel') {
            $tittel = (string) ($data['artikkel']['tittel'] ?? '') ?: (string) ($u['kontekst'] ?? 'Kurs');
            // Publisert, ikke kladd (eieren, 27. september 2026: «jeg må også
            // ha tilgang til å få plassert bilde rett i artikkel med en gang»).
            // Bildet er hovedbildet. Samme felter som «Publiser» i Nyheter.
            $kladd = Artikler::kladdFraUtkast($tittel, $tekst, ['bilde' => $bilde !== '' ? $bilde : null, 'kategori' => 'Kurs']);
            $felter = ['status' => 'publisert'];
            if (DB::harKolonne('articles', 'publisert_at')) {
                $felter['publisert_at'] = gmdate('Y-m-d H:i:s');
                $felter['publisert_av'] = (int) (Sesjon::medlem()['id'] ?? 0) ?: null;
            }
            if (DB::harKolonne('articles', 'planlagt_til')) {
                $felter['planlagt_til'] = null;
            }
            DB::oppdater('articles', $felter, ['id' => $kladd['id']]);
            $slug = (string) DB::verdi('SELECT slug FROM articles WHERE id = :i', ['i' => $kladd['id']]);
            $resultat = ['artikkelId' => $kladd['id'], 'tittel' => $kladd['tittel'],
                         'lenke' => $slug !== '' ? '/nyheter/' . $slug : ''];
            $beskjed = 'Publisert ✓';
        } else {
            // Et vanlig nyhetsbrevutkast. Det sendes fra Tilbud / nyhetsbrev
            // av eieren selv — herfra gaar det ingen e-post.
            $emne = (string) ($data['epost']['emne'] ?? '') ?: (string) ($u['kontekst'] ?? 'Nyhetsbrev');
            $nyId = DB::settInn('ai_utkast', [
                'type'        => 'nyhetsbrev',
                'tittel'      => mb_substr($emne, 0, 191),
                'tekst'       => $tekst,
                'data'        => json_encode(['bilde' => $bilde, 'fraKursboost' => $id], JSON_UNESCAPED_UNICODE),
                'kontekst'    => mb_substr((string) ($u['kontekst'] ?? ''), 0, 191),
                'kostnad_ore' => 0,
            ]);
            $resultat = ['utkastId' => (int) $nyId];
            $beskjed = 'Lagt som utkast i Tilbud / nyhetsbrev ✓';
        }

        self::merk($id, $del, $resultat);
        revider('kursboost_' . $del, 'ai_utkast', $id, $resultat);
        return ['beskjed' => $beskjed] + (isset($resultat['lenke']) && $resultat['lenke'] !== '' ? ['lenke' => $resultat['lenke']] : []);
    }
}
