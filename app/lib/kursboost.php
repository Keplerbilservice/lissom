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
 * Instagram og Facebook gaar gjennom Meta, artikkelen blir kladd via
 * Artikler::kladdFraUtkast(), nyhetsbrevet blir et vanlig utkast som sendes
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

    /** @param array<string,mixed> $u */
    public static function velgBilde(array $u, string $bilde): void
    {
        $data = $u['data'];
        if (!in_array($bilde, (array) ($data['kb']['bilder'] ?? []), true)) {
            throw new RuntimeException('Velg ett av forslagene.');
        }
        $data['kb']['valgt'] = $bilde;
        self::lagre((int) $u['id'], $data);
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
            $url = rtrim(Config::nettsted(), '/') . '/' . ltrim($bilde, '/');
            $ut = $del === 'instagram' ? Meta::publiserInstagram($url, $tekst) : Meta::publiserFacebook($url, $tekst);
            $resultat = ['innlegg' => (string) ($ut['id'] ?? ''), 'lenke' => (string) ($ut['lenke'] ?? '')];
            $beskjed = $del === 'instagram' ? 'Lagt ut på Instagram ✓' : 'Lagt ut på Facebook ✓';
        } elseif ($del === 'artikkel') {
            $tittel = (string) ($data['artikkel']['tittel'] ?? '') ?: (string) ($u['kontekst'] ?? 'Kurs');
            $kladd = Artikler::kladdFraUtkast($tittel, $tekst, ['bilde' => $bilde !== '' ? $bilde : null, 'kategori' => 'Kurs']);
            $resultat = ['artikkelId' => $kladd['id'], 'tittel' => $kladd['tittel']];
            $beskjed = 'Lagret som kladd i Nyheter ✓';
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
