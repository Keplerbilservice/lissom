<?php
/**
 * Dokumentene i verkstedet.
 *
 * Bilder gaar gjennom Bilder::taImot(), som tegner alt om til JPEG. Det er
 * riktig for et produktbilde: da kan filen ikke inneholde noe annet enn et
 * bilde. Men en kontrakt er ikke et bilde, og en PDF som tegnes om til JPEG
 * er ikke lenger en kontrakt.
 *
 * Derfor denne. Filen lagres slik den kom, og tryggheten ligger tre andre
 * steder i stedet:
 *
 *   1. Bare seks typer slipper inn, og typen leses ut av selve FILA med
 *      finfo — ikke av filnavnet, og ikke av det nettleseren paastaar.
 *   2. Filen faar et navn vi lager selv, uten noe fra det som ble lastet opp.
 *      Ingen «.php», ingen «../», ingenting.
 *   3. Den ligger utenfor det som publiseres, og serveres bare gjennom
 *      api/dokument.php — som sjekker hvem som spor for den leverer noe.
 */

declare(strict_types=1);

final class Dokumenter
{
    /**
     * Storste fil vi tar imot — lest av serveren, ikke skrevet av her.
     *
     * Eieren, 10. september 2026: hev taket «saa langt serveren tillater».
     *
     * Vi hever det i .user.ini og .htaccess, men webhotellet kan ignorere
     * begge. Et tall skrevet av her ville da lyve: skjermen sa «maks 20 MB»
     * mens serveren stoppet paa 2, og den som lastet opp fikk ingen forklaring
     * som stemte. Derfor spor vi PHP hva som faktisk gjelder.
     *
     * PHP har to tak og det laveste vinner: fila for seg, og hele
     * forespoerselen. Det siste maa ha rom til feltene rundt fila, derfor
     * en halv megabyte fratrukket.
     */
    public static function maksBytes(): int
    {
        $tall = static function (string $verdi): int {
            $v = trim($verdi);
            if ($v === '') {
                return 0;
            }
            $siste = strtolower($v[strlen($v) - 1]);
            $n = (int) $v;
            return match ($siste) {
                'g' => $n * 1024 * 1024 * 1024,
                'm' => $n * 1024 * 1024,
                'k' => $n * 1024,
                default => $n,
            };
        };

        $tak = [];
        $opp = $tall((string) ini_get('upload_max_filesize'));
        if ($opp > 0) {
            $tak[] = $opp;
        }
        $post = $tall((string) ini_get('post_max_size'));
        if ($post > 0) {
            $tak[] = $post - 512 * 1024;
        }
        if ($tak === []) {
            return 64 * 1024 * 1024;
        }
        // Aldri under én megabyte: da er noe galt med oppsettet, og en
        // opplasting som ALLTID feiler er verre enn en som feiler paa store
        // filer.
        return max(1024 * 1024, min($tak));
    }

    /** Det samme i hele megabyte, til teksten paa skjermen. */
    public static function maksMb(): int
    {
        return (int) floor(self::maksBytes() / 1024 / 1024);
    }

    /**
     * Det som slipper inn, og hva filen da skal hete.
     *
     * Word er med i to utgaver: den nye (docx) og den gamle (doc). Begge
     * ligger i skuffer verkstedet allerede har.
     */
    private const TYPER = [
        'application/pdf'  => 'pdf',
        'image/jpeg'       => 'jpg',
        'image/png'        => 'png',
        'image/webp'       => 'webp',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
    ];

    /** Er tabellene der? Migrasjon 154. */
    public static function klar(): bool
    {
        return DB::harTabell('verksted_kategorier') && DB::harTabell('verksted_dokumenter');
    }

    /** Mappa filene ligger i. Ved siden av bildene, utenfor det som publiseres. */
    public static function mappe(): string
    {
        return Bilder::mappe('dokumenter');
    }

    /**
     * Kortene, med hvor mange dokumenter hvert av dem har.
     *
     * @param bool $bareMedlem Medlemssida vil bare ha dem som er slaatt paa.
     * @return list<array<string,mixed>>
     */
    public static function kategorier(bool $bareMedlem = false): array
    {
        if (!self::klar()) {
            return [];
        }
        $hvor = $bareMedlem ? 'WHERE k.vis_medlem = 1' : '';
        return array_map(static fn($k) => [
            'id'        => (int) $k['id'],
            'slug'      => (string) $k['slug'],
            'navn'      => (string) $k['navn'],
            'visMedlem' => (bool) $k['vis_medlem'],
            'antall'    => (int) $k['antall'],
        ], DB::alle(
            "SELECT k.*, (SELECT COUNT(*) FROM verksted_dokumenter d
                           WHERE d.kategori_id = k.id) AS antall
               FROM verksted_kategorier k
               {$hvor}
              ORDER BY k.sortering, k.id"
        ));
    }

    /**
     * Dokumentene i ett eller alle kort.
     *
     * Selve teksten AI-en leser blir ikke med — den kan vaere lang, og lista
     * trenger bare aa vite OM den er lagt inn.
     *
     * @return list<array<string,mixed>>
     */
    public static function dokumenter(?int $kategoriId = null, bool $bareMedlem = false): array
    {
        if (!self::klar()) {
            return [];
        }
        $hvor  = [];
        $param = [];
        if ($kategoriId !== null) {
            $hvor[] = 'd.kategori_id = :k';
            $param['k'] = $kategoriId;
        }
        if ($bareMedlem) {
            $hvor[] = 'k.vis_medlem = 1';
        }
        $der = $hvor === [] ? '' : 'WHERE ' . implode(' AND ', $hvor);

        return array_map(static fn($d) => [
            'id'        => (int) $d['id'],
            'kategori'  => (int) $d['kategori_id'],
            'navn'      => (string) $d['originalnavn'],
            'type'      => self::etikett((string) $d['mime']),
            'mime'      => (string) $d['mime'],
            'storrelse' => (int) $d['storrelse'],
            'harTekst'  => trim((string) ($d['tekst'] ?? '')) !== '',
            'opprettet' => (string) $d['opprettet'],
        ], DB::alle(
            "SELECT d.id, d.kategori_id, d.originalnavn, d.mime, d.storrelse,
                    d.tekst, d.opprettet
               FROM verksted_dokumenter d
               JOIN verksted_kategorier k ON k.id = d.kategori_id
               {$der}
              ORDER BY d.opprettet DESC, d.id DESC",
            $param
        ));
    }

    /** Ett dokument med alt, ogsaa stien paa disken. */
    public static function en(int $id): ?array
    {
        if (!self::klar()) {
            return null;
        }
        $d = DB::en(
            'SELECT d.*, k.slug AS kategori_slug, k.navn AS kategori_navn, k.vis_medlem
               FROM verksted_dokumenter d
               JOIN verksted_kategorier k ON k.id = d.kategori_id
              WHERE d.id = :i',
            ['i' => $id]
        );
        return $d ?: null;
    }

    /** Stien paa disken til en rad fra en(). */
    public static function sti(array $dok): string
    {
        return self::mappe() . '/' . (string) $dok['filnavn'];
    }

    /**
     * Kortnavnet som staar paa filikonet: PDF, DOCX, JPG.
     *
     * Regnes ut av mime og ikke av filnavnet, saa den som lastet opp
     * «avtale.pdf.txt» ikke faar den til aa se ut som en PDF.
     */
    public static function etikett(string $mime): string
    {
        return strtoupper(self::TYPER[$mime] ?? 'FIL');
    }

    /** Kan denne typen vises i nettleseren, eller maa den lastes ned? */
    public static function kanVises(string $mime): bool
    {
        return $mime === 'application/pdf' || str_starts_with($mime, 'image/');
    }

    /**
     * Tar imot én opplastet fil.
     *
     * @return int id-en til den nye raden
     * @throws RuntimeException med en tekst som kan vises til den som lastet opp
     */
    public static function taImot(array $fil, int $kategoriId, ?int $medlemId): int
    {
        self::sjekkOpplasting($fil);
        $tmp = (string) ($fil['tmp_name'] ?? '');

        // Typen leses ut av innholdet. Filnavnet og Content-Type kommer fra
        // nettleseren, og kan si hva som helst.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) ($finfo->file($tmp) ?: '');
        if (!isset(self::TYPER[$mime])) {
            throw new RuntimeException('Filen må være PDF, Word eller bilde.');
        }

        $mappe = self::mappe();
        $navn  = bin2hex(random_bytes(16)) . '.' . self::TYPER[$mime];
        if (!@move_uploaded_file($tmp, $mappe . '/' . $navn)) {
            throw new RuntimeException('Fikk ikke lagret filen.');
        }
        @chmod($mappe . '/' . $navn, 0644);

        return DB::settInn('verksted_dokumenter', [
            'kategori_id'   => $kategoriId,
            'filnavn'       => $navn,
            'originalnavn'  => self::rentNavn((string) ($fil['name'] ?? '')),
            'mime'          => $mime,
            'storrelse'     => (int) ($fil['size'] ?? 0),
            'lastet_opp_av' => $medlemId,
        ]);
    }

    /**
     * Kan serveren pakke ut en zip?
     *
     * PHP sin zip-utvidelse er ikke gitt paa et delt webhotell. Mangler den,
     * skal kortet si fra i klartekst — ikke feile stille paa en fil eieren
     * nettopp brukte fem minutter paa aa laste opp.
     */
    public static function zipKlar(): bool
    {
        return class_exists('ZipArchive');
    }

    /** Zip slik nettleserne melder den. De er ikke enige med hverandre. */
    private const ZIP_TYPER = [
        'application/zip',
        'application/x-zip-compressed',
        'multipart/x-zip',
    ];

    /**
     * Tar imot én opplastet fil — og pakker den ut om det er en zip.
     *
     * Eieren, 10. september 2026: «jeg vil bare slippe en zippet stor mappe
     * her». Én mappe inn, alle dokumentene ut, i riktig kort.
     *
     * @return array{lagt:int,hoppet:int} hvor mange som gikk inn, og hvor
     *         mange som ble hoppet over fordi de ikke er dokumenter
     */
    public static function taImotEn(array $fil, int $kategoriId, ?int $medlemId): array
    {
        self::sjekkOpplasting($fil);

        $tmp = (string) ($fil['tmp_name'] ?? '');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) ($finfo->file($tmp) ?: '');

        if (in_array($mime, self::ZIP_TYPER, true)) {
            return self::pakkUt($tmp, $kategoriId, $medlemId);
        }

        self::taImot($fil, $kategoriId, $medlemId);
        return ['lagt' => 1, 'hoppet' => 0];
    }

    /**
     * Pakker ut en zip og legger hver fil i kortet.
     *
     * Hver fil inne i zip-en gaar gjennom noeyaktig samme kontroll som en
     * vanlig opplasting: typen leses ut av innholdet, og navnet paa disken
     * lager vi selv. At vi lager navnet er ogsaa det som gjor at en oppdiktet
     * sti inne i zip-en — «../../app/config.php» — ikke kan gaa noe sted: den
     * blir aldri brukt til aa skrive.
     *
     * @return array{lagt:int,hoppet:int}
     */
    private static function pakkUt(string $sti, int $kategoriId, ?int $medlemId): array
    {
        if (!self::zipKlar()) {
            throw new RuntimeException(
                'Serveren kan ikke pakke ut zip-filer. Last opp dokumentene hver for seg.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($sti) !== true) {
            throw new RuntimeException('Zip-fila kunne ikke åpnes.');
        }

        $mappe = self::mappe();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $maks  = self::maksBytes();
        $lagt  = 0;
        $hoppet = 0;
        $sumUt = 0;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                // Et tak paa antall og paa samlet stoerrelse. En liten zip kan
                // pakkes ut til noe som fyller disken — det er ikke en fil vi
                // vil ha, uansett hva den inneholder.
                if ($lagt + $hoppet >= self::ZIP_MAKS_FILER || $sumUt > self::ZIP_MAKS_UT) {
                    break;
                }

                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $inne = (string) $stat['name'];

                // Mapper, og det Mac legger ved siden av filene.
                if (str_ends_with($inne, '/') || str_starts_with($inne, '__MACOSX/')) {
                    continue;
                }
                $kort = basename(str_replace('\\', '/', $inne));
                if ($kort === '' || str_starts_with($kort, '.')) {
                    continue;
                }
                if (($stat['size'] ?? 0) > $maks) {
                    $hoppet++;
                    continue;
                }

                // Ut i en midlertidig fil foerst. Da kan finfo lese den, og
                // ingenting havner i mappa for vi vet hva det er.
                $strom = $zip->getStream($inne);
                if ($strom === false) {
                    $hoppet++;
                    continue;
                }
                $mid = tempnam(sys_get_temp_dir(), 'lissomzip');
                if ($mid === false) {
                    fclose($strom);
                    $hoppet++;
                    continue;
                }
                $ut = fopen($mid, 'wb');
                $bytes = $ut === false ? 0 : (int) stream_copy_to_stream($strom, $ut, $maks + 1);
                fclose($strom);
                if ($ut !== false) {
                    fclose($ut);
                }
                $sumUt += $bytes;

                $mime = (string) ($finfo->file($mid) ?: '');
                if ($bytes <= 0 || $bytes > $maks || !isset(self::TYPER[$mime])) {
                    @unlink($mid);
                    $hoppet++;
                    continue;
                }

                $navn = bin2hex(random_bytes(16)) . '.' . self::TYPER[$mime];
                if (!@rename($mid, $mappe . '/' . $navn)) {
                    @unlink($mid);
                    $hoppet++;
                    continue;
                }
                @chmod($mappe . '/' . $navn, 0644);

                DB::settInn('verksted_dokumenter', [
                    'kategori_id'   => $kategoriId,
                    'filnavn'       => $navn,
                    'originalnavn'  => self::rentNavn($kort),
                    'mime'          => $mime,
                    'storrelse'     => $bytes,
                    'lastet_opp_av' => $medlemId,
                ]);
                $lagt++;
            }
        } finally {
            $zip->close();
        }

        if ($lagt === 0) {
            throw new RuntimeException('Zip-fila inneholdt ingen filer vi kan ta imot.');
        }
        return ['lagt' => $lagt, 'hoppet' => $hoppet];
    }

    /** Hoyeste antall filer vi tar ut av én zip. */
    private const ZIP_MAKS_FILER = 200;

    /** Hoyeste samlede stoerrelse ut av én zip. */
    private const ZIP_MAKS_UT = 500 * 1024 * 1024;

    /**
     * Det som gaar galt for vi i det hele tatt har en fil aa se paa.
     *
     * Sto inne i taImot(). Zip-veien trenger det samme, og to utgaver av den
     * samme sjekken er én for mange.
     */
    private static function sjekkOpplasting(array $fil): void
    {
        if (!isset($fil['error']) || $fil['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($fil['error'] ?? -1) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE
                    => 'Filen er for stor. Maks ' . self::maksMb() . ' MB.',
                UPLOAD_ERR_NO_FILE => 'Du må velge en fil.',
                default => 'Filen kom ikke fram. Prøv igjen.',
            });
        }
        if (($fil['size'] ?? 0) > self::maksBytes()) {
            throw new RuntimeException('Filen er for stor. Maks ' . self::maksMb() . ' MB.');
        }
        $tmp = (string) ($fil['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Fant ikke filen.');
        }
    }

    /**
     * Deler PHP sin flerfil-form opp i én rad per fil.
     *
     * Med «multiple» kommer $_FILES som EN rad med lister i hvert felt —
     * ['name' => [a, b], 'size' => [1, 2], ...] — ikke som en liste med rader.
     * Uten dette faar taImot() en «name» som er en liste, og lagrer et
     * dokument som heter «Array».
     *
     * @return list<array<string,mixed>>
     */
    public static function delOpp(array $felt): array
    {
        if (!is_array($felt['name'] ?? null)) {
            return [$felt];
        }
        $ut = [];
        foreach (array_keys($felt['name']) as $i) {
            $ut[] = [
                'name'     => $felt['name'][$i] ?? '',
                'type'     => $felt['type'][$i] ?? '',
                'tmp_name' => $felt['tmp_name'][$i] ?? '',
                'error'    => $felt['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $felt['size'][$i] ?? 0,
            ];
        }
        return $ut;
    }

    /**
     * Navnet slik det skal staa i lista.
     *
     * Endelsen bort — den staar alt paa filikonet — og ingenting som kan
     * gjore vondt naar det senere vises paa en skjerm.
     */
    private static function rentNavn(string $navn): string
    {
        $n = trim(preg_replace('/\s+/u', ' ', $navn) ?? $navn);
        $n = preg_replace('/\.(pdf|jpe?g|png|webp|docx?)$/i', '', $n) ?? $n;
        $n = str_replace(["\0", "\r", "\n"], '', $n);
        if (mb_strlen($n) > 120) {
            $n = mb_substr($n, 0, 120);
        }
        return $n === '' ? 'Uten navn' : $n;
    }

    /** Sletter raden og filen. Filen forst er feil: da kan raden bli staaende. */
    public static function slett(int $id): bool
    {
        $d = self::en($id);
        if ($d === null) {
            return false;
        }
        DB::kjor('DELETE FROM verksted_dokumenter WHERE id = :i', ['i' => $id]);
        @unlink(self::sti($d));
        return true;
    }

    /**
     * Teksten AI-en kan lese, fra de kortene den faar se.
     *
     * @param bool $bareMedlem Spor et medlem, er det bare kortene som er
     *                         slaatt paa for medlemmer som gjelder.
     * @param list<int> $kategorier Tomt betyr alle den har lov til.
     * @return list<array{kategori:string,navn:string,tekst:string}>
     */
    public static function kunnskap(bool $bareMedlem, array $kategorier = []): array
    {
        if (!self::klar()) {
            return [];
        }
        $hvor  = ["d.tekst IS NOT NULL", "d.tekst <> ''"];
        $param = [];
        if ($bareMedlem) {
            $hvor[] = 'k.vis_medlem = 1';
        }
        if ($kategorier !== []) {
            $inn = [];
            foreach (array_values($kategorier) as $i => $k) {
                $inn[] = ':k' . $i;
                $param['k' . $i] = (int) $k;
            }
            $hvor[] = 'k.id IN (' . implode(', ', $inn) . ')';
        }
        $der = 'WHERE ' . implode(' AND ', $hvor);

        return array_map(static fn($d) => [
            'kategori' => (string) $d['kategori_navn'],
            'navn'     => (string) $d['originalnavn'],
            'tekst'    => (string) $d['tekst'],
        ], DB::alle(
            "SELECT d.originalnavn, d.tekst, k.navn AS kategori_navn
               FROM verksted_dokumenter d
               JOIN verksted_kategorier k ON k.id = d.kategori_id
               {$der}
              ORDER BY k.sortering, d.opprettet DESC",
            $param
        ));
    }

    /**
     * Er «Spør verkstedet» slaatt paa for medlemmer?
     *
     * Ligger i innstillinger og ikke i en egen tabell — det er ett av og paa,
     * ikke en liste. Leses direkte, fordi Config bare henter de noeklene som
     * staar i sin egen liste.
     */
    public static function faqForMedlem(): bool
    {
        if (!DB::harTabell('innstillinger')) {
            return false;
        }
        return (string) (DB::verdi(
            "SELECT verdi FROM innstillinger WHERE nokkel = 'verksted_faq_medlem'"
        ) ?? '') === '1';
    }
}
