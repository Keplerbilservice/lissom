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

    /**
     * Kan et kort ligge inni et annet? Migrasjon 157.
     *
     * Koden legges ut foer eieren trykker «Kjør oppdateringer». I mellomtida
     * finnes ikke kolonnene, og da maa alt her virke som foer — uten
     * underkort, men uten feil.
     */
    public static function harKort(): bool
    {
        return self::klar() && DB::harKolonne('verksted_kategorier', 'forelder_id');
    }

    /**
     * Forelderen hektet paa, som «p». Et underkort er synlig naar forelderen
     * er slaatt paa — eller det selv er det. Tom foer migrasjon 157.
     */
    private static function forelderJoin(): string
    {
        return self::harKort()
            ? 'LEFT JOIN verksted_kategorier p ON p.id = k.forelder_id'
            : '';
    }

    /**
     * «Kortet er slaatt paa for medlemmer», med forelderen tatt hensyn til.
     *
     * Et underkort (en mal) er paa naar «Keramikk maler» er paa, ELLER naar
     * malen selv er slaatt paa. Eieren, 11. september 2026: «jeg vil ogsaa
     * kunne dele en og en mal med min side medlemmer». Foer var det bare
     * forelderens bryter som gjaldt.
     */
    private static function synligSql(): string
    {
        return self::harKort()
            ? '(k.vis_medlem = 1 OR IFNULL(p.vis_medlem, 0) = 1)'
            : 'k.vis_medlem = 1';
    }

    /** Det samme som ett tall (1/0), til feltlister. */
    private static function synligFelt(): string
    {
        return self::harKort()
            ? 'GREATEST(k.vis_medlem, IFNULL(p.vis_medlem, 0))'
            : 'k.vis_medlem';
    }

    /** Mappa filene ligger i. Ved siden av bildene, utenfor det som publiseres. */
    public static function mappe(): string
    {
        return Bilder::mappe('dokumenter');
    }

    /**
     * Kortene, med hvor mange dokumenter hvert av dem har.
     *
     * Alle kortene i én flat liste, ogsaa underkortene (migrasjon 157) —
     * «forelder» sier hvilket kort et underkort ligger inni. Skjermen setter
     * dem sammen; her er det bare rader. «antall» er dokumentene i kortet
     * selv, ikke i underkortene.
     *
     * @param bool $bareMedlem Medlemssida vil bare ha dem som er slaatt paa.
     * @return list<array<string,mixed>>
     */
    public static function kategorier(bool $bareMedlem = false): array
    {
        if (!self::klar()) {
            return [];
        }
        $medKort = self::harKort();
        // Til medlemmet: det som er slaatt paa — og et hovedkort som selv er
        // av, men har en mal som er paa. Uten det sto malen uten kortet sitt
        // rundt seg, og skjermen fant den ikke.
        $hvor = '';
        if ($bareMedlem) {
            $hvor = 'WHERE ' . self::synligSql() . ($medKort
                ? ' OR EXISTS (SELECT 1 FROM verksted_kategorier b
                                 WHERE b.forelder_id = k.id AND b.vis_medlem = 1)'
                : '');
        }
        $join    = self::forelderJoin();
        $felt    = $medKort
            ? 'k.forelder_id, k.under, k.bilde, k.vis_medlem AS egen, ' . self::synligFelt() . ' AS synlig'
            : "NULL AS forelder_id, '' AS under, NULL AS bilde, k.vis_medlem AS egen, k.vis_medlem AS synlig";
        return array_map(static fn($k) => [
            'id'        => (int) $k['id'],
            'slug'      => (string) $k['slug'],
            'navn'      => (string) $k['navn'],
            'under'     => (string) $k['under'],
            'forelder'  => $k['forelder_id'] === null ? null : (int) $k['forelder_id'],
            'harBilde'  => (string) ($k['bilde'] ?? '') !== '',
            // Slik medlemmet ser det: paa naar kortet eller forelderen er paa.
            'visMedlem' => ((int) $k['synlig']) === 1,
            // Kortets egen bryter, slik den staar i admin.
            'egenVis'   => ((int) $k['egen']) === 1,
            'antall'    => (int) $k['antall'],
        ], DB::alle(
            "SELECT k.id, k.slug, k.navn, k.sortering, {$felt},
                    (SELECT COUNT(*) FROM verksted_dokumenter d
                      WHERE d.kategori_id = k.id) AS antall
               FROM verksted_kategorier k
               {$join}
               {$hvor}
              ORDER BY k.sortering, k.id"
        ));
    }

    /**
     * Ett kort, med forelderens bryter tatt hensyn til. Til bildet paa
     * kortet, som serveres av api/dokument.php.
     */
    public static function kort(int $id): ?array
    {
        if (!self::klar()) {
            return null;
        }
        $felt = self::harKort()
            ? 'k.bilde, ' . self::synligFelt() . ' AS synlig'
            : 'NULL AS bilde, k.vis_medlem AS synlig';
        $k = DB::en(
            "SELECT k.id, k.navn, {$felt}
               FROM verksted_kategorier k
               " . self::forelderJoin() . '
              WHERE k.id = :i',
            ['i' => $id]
        );
        return $k ?: null;
    }

    /** Stien paa disken til bildet paa et kort fra kort(). Tom uten bilde. */
    public static function bildeSti(array $kort): string
    {
        $b = (string) ($kort['bilde'] ?? '');
        return $b === '' ? '' : self::mappe() . '/' . $b;
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
            $hvor[] = self::synligSql();
        }
        $der = $hvor === [] ? '' : 'WHERE ' . implode(' AND ', $hvor);
        $join = self::forelderJoin();

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
               {$join}
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
        // vis_medlem er forelderens naar dokumentet ligger i et underkort —
        // det er den bryteren api/dokument.php sjekker.
        $vis = self::synligFelt();
        $d = DB::en(
            "SELECT d.*, k.slug AS kategori_slug, k.navn AS kategori_navn,
                    {$vis} AS vis_medlem
               FROM verksted_dokumenter d
               JOIN verksted_kategorier k ON k.id = d.kategori_id
               " . self::forelderJoin() . '
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
        // Kom det fra importpakka, skal det ikke komme tilbake ved neste
        // «Kjør oppdateringer».
        self::huskSlettetKilde((string) ($d['kilde'] ?? ''));
        return true;
    }

    /**
     * Soek i kunnskapen: dokumentnavn, kortnavn og teksten.
     *
     * Eieren, 11. september 2026 (GO): kunnskapstreff i soekefeltet paa
     * nettsida og i soekefeltet i kalender admin. Et medlem faar bare det
     * som ligger i kort som er slaatt paa; admin faar alt. Utlogget kommer
     * ikke hit — api/kunnskap-sok.php krever innlogging.
     *
     * Navnetreff foerst, saa treff i teksten. Under hvert treff staar den
     * foerste linja i dokumentet der ordet forekommer, saa man ser hva
     * treffet gjelder foer man aapner: «Trekke hanker — Hanken sprekker i
     * festene: …». Soeket gjoeres her og ikke i basen, fordi teksten er
     * 56 000 ord til sammen: LIKE over det er like raskt, og linja rundt
     * treffet maa uansett finnes i PHP.
     *
     * @return list<array{id:int,navn:string,kort:string,utdrag:string}>
     */
    public static function sok(string $ord, bool $bareMedlem, int $maks = 6): array
    {
        return self::sokMedForslag($ord, $bareMedlem, $maks)['treff'];
    }

    /**
     * Soeket, med slingringsmonn for feilstaving.
     *
     * Eieren, 11. september 2026 (GO): «jeg vil ikke måtte treffe helt når
     * jeg spør om noe, kanskje den kan si, mente du dette??». Gir det
     * skrevne ingen treff, byttes hvert ord som ikke finnes i dokumentene
     * ut med det naermeste ordet som gjoer det (se naermeste()), soeket
     * gjoeres paa nytt med det, og forslaget sendes med som «menteDu» saa
     * skjermen kan vise «Mente du «sentrering»?» over treffene.
     *
     * @return array{treff:list<array{id:int,navn:string,kort:string,utdrag:string}>,menteDu:?string}
     */
    public static function sokMedForslag(string $ord, bool $bareMedlem, int $maks = 6): array
    {
        $ord = mb_strtolower(trim($ord));
        if ($ord === '' || mb_strlen($ord) < 2 || !self::klar()) {
            return ['treff' => [], 'menteDu' => null];
        }
        $rader = self::sokRader($bareMedlem);
        $treff = self::sokI($rader, $ord, $maks);
        if ($treff !== []) {
            return ['treff' => $treff, 'menteDu' => null];
        }
        $rettet = self::rettOrd($ord, self::ordliste($rader));
        if ($rettet === null) {
            return ['treff' => [], 'menteDu' => null];
        }
        return ['treff' => self::sokI($rader, $rettet, $maks), 'menteDu' => $rettet];
    }

    /** Dokumentene soeket leter i: navn, kort og tekst, filtrert paa hvem som spor. */
    private static function sokRader(bool $bareMedlem): array
    {
        $medKort = self::harKort();
        $hvor = $bareMedlem ? 'WHERE ' . self::synligSql() : '';
        // «Mugge · Monteringsguide» i et underkort — 57 guider heter
        // «Monteringsguide», og kortnavnet sier hvilken.
        $etikett = $medKort
            ? "IF(p.id IS NULL, d.originalnavn, CONCAT(k.navn, ' · ', d.originalnavn))"
            : 'd.originalnavn';
        $kortNavn = $medKort ? 'IF(p.id IS NULL, k.navn, p.navn)' : 'k.navn';
        $sorter = $medKort ? 'IFNULL(p.sortering, k.sortering), k.sortering' : 'k.sortering';

        return DB::alle(
            "SELECT d.id, d.tekst, {$etikett} AS etikett, {$kortNavn} AS kort
               FROM verksted_dokumenter d
               JOIN verksted_kategorier k ON k.id = d.kategori_id
               " . self::forelderJoin() . "
               {$hvor}
              ORDER BY {$sorter}, d.opprettet DESC"
        );
    }

    /** Selve soeket: navnetreff foerst, saa treff i teksten. */
    private static function sokI(array $rader, string $ord, int $maks): array
    {
        $iNavn = [];
        $iTekst = [];
        foreach ($rader as $r) {
            $navn = (string) $r['etikett'];
            $kort = (string) $r['kort'];
            $treff = ['id' => (int) $r['id'], 'navn' => $navn, 'kort' => $kort, 'utdrag' => ''];
            if (mb_stripos($navn . ' ' . $kort, $ord) !== false) {
                $iNavn[] = $treff;
                continue;
            }
            $tekst = (string) ($r['tekst'] ?? '');
            if ($tekst === '' || mb_stripos($tekst, $ord) === false) {
                continue;
            }
            $treff['utdrag'] = self::linjeMed($tekst, $ord);
            $iTekst[] = $treff;
        }
        return array_slice(array_merge($iNavn, $iTekst), 0, $maks);
    }

    /**
     * Alle ord paa fire bokstaver eller mer i navn, kort og tekst — det
     * «Mente du» kan foreslaa. Bare ord som faktisk staar i dokumentene,
     * saa forslaget alltid gir treff.
     *
     * @return array<string,true>
     */
    public static function ordliste(array $rader): array
    {
        $liste = [];
        foreach ($rader as $r) {
            $alt = ($r['etikett'] ?? $r['navn'] ?? '') . ' ' . ($r['kort'] ?? $r['kategori'] ?? '') . ' ' . ($r['tekst'] ?? '');
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($alt)) ?: [] as $w) {
                if (mb_strlen($w) >= 4) {
                    $liste[$w] = true;
                }
            }
        }
        return $liste;
    }

    /**
     * Det skrevne med hvert ukjente ord byttet ut med det naermeste kjente.
     * Null naar ingenting ble byttet, eller et ord ikke har noe i naerheten.
     */
    public static function rettOrd(string $tekst, array $ordliste): ?string
    {
        $byttet = false;
        $ut = [];
        foreach (preg_split('/\s+/u', trim($tekst)) ?: [] as $w) {
            $n = self::naermeste($w, $ordliste);
            if ($n === null) {
                return null;
            }
            if ($n !== $w) {
                $byttet = true;
            }
            $ut[] = $n;
        }
        return $byttet ? implode(' ', $ut) : null;
    }

    /**
     * Ordet selv om det finnes, ellers det naermeste i lista:
     *   - et ord som begynner slik («sentrer» → «sentrering»), korteste vinner
     *   - ellers én bokstav feil for ord paa 5–8 bokstaver, to for lengre.
     * Ord under fire bokstaver rettes ikke — der er alt like naert alt.
     * Levenshtein regner bytes; æøå gjoeres om til ett tegn foerst saa en
     * feil i «kjæle» teller som én, ikke to.
     */
    public static function naermeste(string $ord, array $ordliste): ?string
    {
        $ord = mb_strtolower($ord);
        if (isset($ordliste[$ord]) || mb_strlen($ord) < 4) {
            return $ord;
        }
        $start = null;
        foreach ($ordliste as $w => $_) {
            if (str_starts_with((string) $w, $ord) && ($start === null || mb_strlen((string) $w) < mb_strlen($start))) {
                $start = (string) $w;
            }
        }
        if ($start !== null) {
            return $start;
        }
        $lengde = mb_strlen($ord);
        $tak = $lengde <= 4 ? 0 : ($lengde <= 8 ? 1 : 2);
        if ($tak === 0) {
            return null;
        }
        $a = self::ascii($ord);
        $beste = null;
        $besteAvstand = $tak + 1;
        $besteFelles = -1;
        foreach ($ordliste as $w => $_) {
            $w = (string) $w;
            if (abs(mb_strlen($w) - $lengde) > $tak) {
                continue;
            }
            $d = levenshtein($a, self::ascii($w));
            if ($d > $besteAvstand) {
                continue;
            }
            // Like naer: det som begynner likt vinner («hankk» → «hank», ikke
            // «hakk»), deretter det korteste.
            $felles = self::fellesStart($ord, $w);
            if ($d < $besteAvstand || $felles > $besteFelles
                || ($felles === $besteFelles && $beste !== null && mb_strlen($w) < mb_strlen($beste))) {
                $beste = $w;
                $besteAvstand = $d;
                $besteFelles = $felles;
            }
        }
        return $beste;
    }

    /** Hvor mange bokstaver to ord har felles fra starten. */
    private static function fellesStart(string $a, string $b): int
    {
        $n = min(mb_strlen($a), mb_strlen($b));
        for ($i = 0; $i < $n; $i++) {
            if (mb_substr($a, $i, 1) !== mb_substr($b, $i, 1)) {
                return $i;
            }
        }
        return $n;
    }

    /** æøå som ett tegn hver, til levenshtein(). */
    private static function ascii(string $s): string
    {
        return strtr($s, ['æ' => '{', 'ø' => '|', 'å' => '}', 'é' => 'e', 'ü' => 'u', 'ö' => '|', 'ä' => '{']);
    }

    /** Den foerste linja i teksten som inneholder ordet, kuttet til én linje paa skjermen. */
    private static function linjeMed(string $tekst, string $ord): string
    {
        foreach (preg_split('/\R/u', $tekst) ?: [] as $linje) {
            $linje = trim($linje);
            if ($linje === '' || mb_stripos($linje, $ord) === false) {
                continue;
            }
            // Starter et stykke foer ordet naar linja er lang, saa ordet er med.
            $pos = mb_stripos($linje, $ord);
            if ($pos > 60) {
                $linje = '… ' . mb_substr($linje, $pos - 40);
            }
            return mb_strlen($linje) > 110 ? mb_substr($linje, 0, 108) . ' …' : $linje;
        }
        return '';
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
        $medKort = self::harKort();
        $hvor  = ["d.tekst IS NOT NULL", "d.tekst <> ''"];
        $param = [];
        if ($bareMedlem) {
            $hvor[] = self::synligSql();
        }
        if ($kategorier !== []) {
            // Velger man «Keramikk maler», er det malene inni som menes.
            // To sett parametre for den samme lista: PDO lar ikke ett navn
            // brukes to steder i samme setning.
            $inn = [];
            $inn2 = [];
            foreach (array_values($kategorier) as $i => $k) {
                $inn[]  = ':k' . $i;
                $inn2[] = ':f' . $i;
                $param['k' . $i] = (int) $k;
                if ($medKort) {
                    $param['f' . $i] = (int) $k;
                }
            }
            $hvor[] = $medKort
                ? '(k.id IN (' . implode(', ', $inn) . ') OR k.forelder_id IN (' . implode(', ', $inn2) . '))'
                : 'k.id IN (' . implode(', ', $inn) . ')';
        }
        $der = 'WHERE ' . implode(' AND ', $hvor);
        // Modellen skal kunne si hvor svaret sto: «Keramikk maler · Fuglekasse».
        $kortNavn = $medKort
            ? "IF(p.id IS NULL, k.navn, CONCAT(p.navn, ' · ', k.navn))"
            : 'k.navn';
        $sorter = $medKort ? 'IFNULL(p.sortering, k.sortering), k.sortering' : 'k.sortering';

        // «navn» er det modellen ser og det som staar under svaret. I et
        // underkort faar det malnavnet foran: 57 guider heter
        // «Monteringsguide», og «Fuglekasse · Monteringsguide» sier hvilken.
        $etikett = $medKort
            ? "IF(p.id IS NULL, d.originalnavn, CONCAT(k.navn, ' · ', d.originalnavn))"
            : 'd.originalnavn';

        return array_map(static fn($d) => [
            'kategori' => (string) $d['kategori_navn'],
            'navn'     => (string) $d['etikett'],
            'tekst'    => (string) $d['tekst'],
        ], DB::alle(
            "SELECT d.originalnavn, d.tekst, {$kortNavn} AS kategori_navn, {$etikett} AS etikett
               FROM verksted_dokumenter d
               JOIN verksted_kategorier k ON k.id = d.kategori_id
               " . self::forelderJoin() . "
               {$der}
              ORDER BY {$sorter}, d.opprettet DESC",
            $param
        ));
    }

    /**
     * Det modellen faar lese: de dokumentene som ligner mest paa spoersmaalet
     * foerst, og bare saa mange som faar plass.
     *
     * Fram til 11. september 2026 gikk dokumentene inn i kortenes rekkefoelge
     * og ble kuttet paa 200 000 tegn. Med fem haandboeker og 57
     * monteringsguider (rundt 300 000 tegn) ble de siste aldri lest — spurte
     * man om en mal langt nede i lista, fantes den ikke for modellen.
     *
     * Ingen ny AI-kobling for aa velge: ordene i spoersmaalet telles i hvert
     * dokument, og treff i navnet eller kortet teller mer enn treff i
     * teksten. Det som ikke treffer noe, kommer etter — det er fortsatt med
     * om det er plass. Rekkefoelgen mellom like treff er den gamle.
     *
     * Dokumentene legges til ett og ett til taket er brukt, og det som ikke
     * faar plass utelates helt — ikke kuttet midt i, for da staar en halv
     * oppskrift der som om den var hel.
     *
     * @param list<array{kategori:string,navn:string,tekst:string}> $kilder fra kunnskap()
     * @return array{tekst:string,kilder:list<array{kategori:string,navn:string,tekst:string}>}
     */
    public static function utvalg(array $kilder, string $sporsmal, int $maks = 200000): array
    {
        $ord = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($sporsmal)) ?: [] as $o) {
            // Korte ord («og», «en», «på») treffer alt og sier ingenting.
            if (mb_strlen($o) >= 3) {
                $ord[$o] = true;
            }
        }
        $ord = array_keys($ord);
        // Slingringsmonn (eieren, 11. september 2026, GO): et ord som ikke
        // staar i noe dokument byttes med det naermeste som gjoer det, saa
        // «sentering» sorterer sentreringsarkene oeverst likevel.
        if ($ord !== []) {
            $liste = self::ordliste($kilder);
            $ord = array_values(array_unique(array_map(
                static fn(string $o): string => self::naermeste($o, $liste) ?? $o, $ord
            )));
        }

        $poeng = static function (array $k) use ($ord): int {
            $navn  = mb_strtolower($k['kategori'] . ' ' . $k['navn']);
            // Ordene i navnet hver for seg, saa «vindspillet» i spoersmaalet
            // treffer «Vindspill» i navnet — norsk boeyer i enden av ordet.
            $navnOrd = array_filter(
                preg_split('/[^\p{L}\p{N}]+/u', $navn) ?: [],
                static fn($w) => mb_strlen($w) >= 4
            );
            $tekst = mb_strtolower($k['tekst']);
            $sum   = 0;
            foreach ($ord as $o) {
                foreach ($navnOrd as $w) {
                    if (str_starts_with($o, $w) || str_starts_with($w, $o)) {
                        $sum += 20;
                        break;
                    }
                }
                // Tak per ord: et langt dokument skal ikke vinne bare ved aa
                // vaere langt.
                $sum += min(10, mb_substr_count($tekst, $o));
            }
            return $sum;
        };

        $rekkefolge = [];
        foreach (array_values($kilder) as $i => $k) {
            $rekkefolge[] = ['i' => $i, 'p' => $poeng($k)];
        }
        usort($rekkefolge, static fn($a, $b) => ($b['p'] <=> $a['p']) ?: ($a['i'] <=> $b['i']));

        $kilder  = array_values($kilder);
        $biter   = [];
        $brukt   = 0;
        $medtatt = [];
        foreach ($rekkefolge as $r) {
            $k = $kilder[$r['i']];
            $bit = '--- DOKUMENT ' . (count($biter) + 1) . ' ---' . "\n"
                 . 'Kort: ' . $k['kategori'] . "\n"
                 . 'Navn: ' . $k['navn'] . "\n\n"
                 . $k['tekst'];
            $lengde = mb_strlen($bit) + 2;
            if ($brukt + $lengde > $maks) {
                // Det foerste dokumentet skal alltid med, om saa avkortet.
                if ($biter === []) {
                    $biter[]   = mb_substr($bit, 0, $maks);
                    $medtatt[] = $k;
                    $brukt     = $maks;
                }
                continue;
            }
            $biter[]   = $bit;
            $brukt    += $lengde;
            $medtatt[] = $k;
        }

        return ['tekst' => implode("\n\n", $biter), 'kilder' => $medtatt];
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

    // ────────────────────────────────────────────────────────── import ──

    /**
     * Mappa importpakka ligger i, eller null naar den ikke er der.
     *
     * Deploy-jobben legger db/dokumenter/ ved siden av app-koden, som
     * lissom-app/dokumenter-import/. Lokalt ligger den der den er i repoet.
     */
    public static function importMappe(): ?string
    {
        foreach ([dirname(APP_DIR) . '/dokumenter-import', dirname(APP_DIR) . '/db/dokumenter'] as $m) {
            if (is_file($m . '/manifest.json')) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Legger dokumentene fra importpakka inn i kortene.
     *
     * Eieren, 11. september 2026: haandboekene og de 71 keramikkmalene fra
     * mappa «Lissom opplasting» skal inn i kortene — haandboekene i hvert
     * sitt kort, og hver mal som sitt eget kort under «Keramikk maler».
     *
     * Claude har ikke tilgang til webhotellet, og admin-opplastingen flater
     * ut mapper. Derfor gaar det denne veien: bin/dokumentpakke.mjs legger
     * filene i repoet med et manifest, deploy-jobben legger dem ut, og
     * «Kjør oppdateringer» kaller hit.
     *
     * Trygg aa kjoere om igjen: hvert dokument huskes paa «kilde» (stien i
     * manifestet), og hvert malkort paa slug. Det som alt er inne, hoppes
     * over. Sletter eieren et importert dokument i admin, er raden borte —
     * og da ville neste import lagt det tilbake. Derfor huskes slettede
     * kilder for seg, se slett() og slettedeKilder().
     *
     * Filene kopieres, ikke flyttes: importpakka speiles av deploy-jobben,
     * og en fil som mangler der ville blitt lagt tilbake ved neste utrulling.
     *
     * Teksten AI-en leser («tekst» i manifestet, en .txt ved siden av fila)
     * legges inn sammen med dokumentet — og paa dokumenter som alt er inne
     * uten tekst. Eieren, 11. september 2026: «Spør verkstedet» svarte
     * «Dette står ikke i dokumentene» om alt, fordi en PDF er en binaerfil
     * for modellen. Tekst eieren har skrevet selv roeres ikke.
     *
     * Et malkort som ikke lenger staar i manifestet fjernes, med filene sine
     * — men bare naar alt i det kom fra pakka. Eieren, 11. september 2026:
     * «dersom det mangler maler, saa vil jeg at disse slettes og ikke vises
     * i admin».
     *
     * @return array{kort:int,dokumenter:int,tekster:int,fjernet:int,hoppet:int,feil:list<string>}
     */
    public static function importer(): array
    {
        $ut = ['kort' => 0, 'dokumenter' => 0, 'tekster' => 0, 'byttet' => 0, 'fjernet' => 0, 'hoppet' => 0, 'feil' => []];
        $mappe = self::importMappe();
        if ($mappe === null || !self::harKort() || !DB::harKolonne('verksted_dokumenter', 'kilde')) {
            return $ut;
        }
        // 260 filer og 140 MB skal kopieres. Maalt: 30 sekunder holdt ikke
        // som CGI. Stopper det likevel, er det trygt aa trykke en gang til —
        // det som kom inn, hoppes over neste gang.
        @set_time_limit(600);

        $manifest = json_decode((string) file_get_contents($mappe . '/manifest.json'), true);
        if (!is_array($manifest)) {
            $ut['feil'][] = 'manifest.json kunne ikke leses.';
            return $ut;
        }

        $kortVedSlug = [];
        foreach (DB::alle('SELECT id, slug FROM verksted_kategorier') as $k) {
            $kortVedSlug[(string) $k['slug']] = (int) $k['id'];
        }
        // Det som alt er inne, med om det har tekst — saa teksten kan legges
        // paa i etterkant uten aa roere fila.
        $inne = [];
        foreach (DB::alle("SELECT id, kilde, filnavn, storrelse, (tekst IS NOT NULL AND tekst <> '') AS harTekst
                             FROM verksted_dokumenter WHERE kilde IS NOT NULL") as $r) {
            $inne[(string) $r['kilde']] = [
                'id'        => (int) $r['id'],
                'harTekst'  => ((int) $r['harTekst']) === 1,
                'filnavn'   => (string) $r['filnavn'],
                'storrelse' => (int) $r['storrelse'],
            ];
        }
        $slettet = self::slettedeKilder();

        // Teksten fra pakka, eller tom.
        $tekstFra = static function (array $d) use ($mappe): string {
            $sti = (string) ($d['tekst'] ?? '');
            if ($sti === '' || str_contains($sti, '..') || str_starts_with($sti, '/') || str_contains($sti, ':')) {
                return '';
            }
            $t = @file_get_contents($mappe . '/' . $sti);
            return $t === false ? '' : mb_substr(trim($t), 0, 200000);
        };

        // Ett dokument inn, om det ikke alt er der — og teksten paa, om den
        // mangler.
        $leggInn = function (array $d, int $kategoriId) use (&$ut, &$inne, $slettet, $mappe, $tekstFra): void {
            $kilde = (string) ($d['fil'] ?? '');
            if ($kilde === '' || isset($slettet[$kilde])) {
                $ut['hoppet']++;
                return;
            }
            if (isset($inne[$kilde])) {
                // Ny utgave av en fil som alt er inne: samme kilde, annen
                // stoerrelse. Eieren, 11. september 2026: handbok.css fikk
                // en layoutfiks og «skal overskrive den gamle» — da er de
                // 26 PDF-ene laget paa nytt, og de maa faa byttet fila si
                // uten aa bli nye rader (bryter, kort og id staar).
                $fra = $mappe . '/' . $kilde;
                $byttetNaa = false;
                if (is_file($fra) && (int) filesize($fra) !== $inne[$kilde]['storrelse']) {
                    $til = self::mappe() . '/' . $inne[$kilde]['filnavn'];
                    if (@copy($fra, $til)) {
                        @chmod($til, 0644);
                        DB::oppdater('verksted_dokumenter', ['storrelse' => (int) filesize($til)], ['id' => $inne[$kilde]['id']]);
                        $inne[$kilde]['storrelse'] = (int) filesize($til);
                        $ut['byttet']++;
                        $byttetNaa = true;
                    } else {
                        $ut['feil'][] = $kilde . ': fikk ikke byttet fila.';
                    }
                }
                $t = $tekstFra($d);
                if ($t !== '' && (!$inne[$kilde]['harTekst'] || $byttetNaa)) {
                    DB::oppdater('verksted_dokumenter', ['tekst' => $t], ['id' => $inne[$kilde]['id']]);
                    $inne[$kilde]['harTekst'] = true;
                    $ut['tekster']++;
                }
                $ut['hoppet']++;
                return;
            }
            try {
                $t  = $tekstFra($d);
                $id = self::kopierInn($mappe, $kilde, $kategoriId, (string) ($d['navn'] ?? ''), $t);
                $inne[$kilde] = ['id' => $id, 'harTekst' => $t !== ''];
                $ut['dokumenter']++;
                if ($t !== '') {
                    $ut['tekster']++;
                }
            } catch (RuntimeException $e) {
                $ut['feil'][] = $kilde . ': ' . $e->getMessage();
            }
        };

        // Haandboekene, rett i hovedkortet sitt.
        foreach ((array) ($manifest['dokumenter'] ?? []) as $d) {
            $kortId = $kortVedSlug[(string) ($d['kort'] ?? '')] ?? null;
            if ($kortId === null) {
                $ut['feil'][] = ($d['fil'] ?? '?') . ': fant ikke kortet «' . ($d['kort'] ?? '') . '».';
                continue;
            }
            $leggInn($d, $kortId);
        }

        // Malene: ett underkort hver, under «Keramikk maler».
        $forelder = $kortVedSlug['maler'] ?? null;
        if ($forelder === null && ($manifest['maler'] ?? []) !== []) {
            $ut['feil'][] = 'Fant ikke kortet «Keramikk maler».';
            return $ut;
        }
        foreach ((array) ($manifest['maler'] ?? []) as $m) {
            $slug = (string) ($m['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $id = $kortVedSlug[$slug] ?? null;
            if ($id === null) {
                $bilde = null;
                $bildeKilde = (string) ($m['bilde'] ?? '');
                if ($bildeKilde !== '') {
                    try {
                        $bilde = self::kopierFil($mappe, $bildeKilde);
                    } catch (RuntimeException $e) {
                        $ut['feil'][] = $bildeKilde . ': ' . $e->getMessage();
                    }
                }
                $id = DB::settInn('verksted_kategorier', [
                    'forelder_id' => $forelder,
                    'slug'        => $slug,
                    'navn'        => mb_substr((string) ($m['navn'] ?? $slug), 0, 191),
                    'under'       => mb_substr((string) ($m['under'] ?? ''), 0, 191),
                    'bilde'       => $bilde,
                    // Underkortene ligger etter hovedkortene, i manifestets
                    // rekkefoelge. Hovedkortene har 1–6.
                    'sortering'   => 100 + (int) ($m['sortering'] ?? 0),
                    'vis_medlem'  => 0,
                ]);
                $kortVedSlug[$slug] = $id;
                $ut['kort']++;
            }
            // Baklengs, fordi lista sorteres nyeste foerst: da staar «Mal»
            // oeverst i kortet og «Steg 10» nederst, slik manifestet har dem.
            foreach (array_reverse((array) ($m['dokumenter'] ?? [])) as $d) {
                $leggInn($d, $id);
            }
        }

        // Malkort som er tatt ut av pakka. Bare underkort under «Keramikk
        // maler», og bare naar alt i kortet kom fra pakka — har eieren lastet
        // opp noe eget der, staar kortet.
        if ($forelder !== null) {
            $iPakka = array_flip(array_map(
                static fn($m) => (string) ($m['slug'] ?? ''), (array) ($manifest['maler'] ?? [])
            ));
            foreach (DB::alle('SELECT id, slug, bilde FROM verksted_kategorier WHERE forelder_id = :f', ['f' => $forelder]) as $k) {
                if (isset($iPakka[(string) $k['slug']])) {
                    continue;
                }
                $egne = (int) DB::verdi(
                    'SELECT COUNT(*) FROM verksted_dokumenter WHERE kategori_id = :k AND kilde IS NULL',
                    ['k' => (int) $k['id']]
                );
                if ($egne > 0) {
                    continue;
                }
                foreach (DB::alle('SELECT id FROM verksted_dokumenter WHERE kategori_id = :k', ['k' => (int) $k['id']]) as $d) {
                    self::slett((int) $d['id']);
                }
                if ((string) ($k['bilde'] ?? '') !== '') {
                    @unlink(self::mappe() . '/' . (string) $k['bilde']);
                }
                DB::kjor('DELETE FROM verksted_kategorier WHERE id = :i', ['i' => (int) $k['id']]);
                $ut['fjernet']++;
            }
        }

        return $ut;
    }

    /**
     * Kopierer én fil fra importpakka inn i dokumentmappa, med et navn vi
     * lager selv. Typen leses ut av innholdet, som ved opplasting.
     *
     * @return string filnavnet i dokumentmappa
     */
    private static function kopierFil(string $mappe, string $kilde): string
    {
        // Ingen «..» og ingen absolutt sti: manifestet er vaart, men fila
        // det peker paa skal uansett ligge inni pakka.
        if (str_contains($kilde, '..') || str_starts_with($kilde, '/') || str_contains($kilde, ':')) {
            throw new RuntimeException('Ugyldig sti.');
        }
        $fra = $mappe . '/' . $kilde;
        if (!is_file($fra)) {
            throw new RuntimeException('Fant ikke fila i importpakka.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) ($finfo->file($fra) ?: '');
        if (!isset(self::TYPER[$mime])) {
            throw new RuntimeException('Filen må være PDF, Word eller bilde.');
        }
        $navn = bin2hex(random_bytes(16)) . '.' . self::TYPER[$mime];
        if (!@copy($fra, self::mappe() . '/' . $navn)) {
            throw new RuntimeException('Fikk ikke lagret filen.');
        }
        @chmod(self::mappe() . '/' . $navn, 0644);
        return $navn;
    }

    /** kopierFil() pluss raden i basen, med teksten AI-en leser om det er en. */
    private static function kopierInn(string $mappe, string $kilde, int $kategoriId, string $navn, string $tekst = ''): int
    {
        $filnavn = self::kopierFil($mappe, $kilde);
        $sti = self::mappe() . '/' . $filnavn;
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return DB::settInn('verksted_dokumenter', [
            'kategori_id'   => $kategoriId,
            'filnavn'       => $filnavn,
            'originalnavn'  => self::rentNavn($navn !== '' ? $navn : basename($kilde)),
            'mime'          => (string) ($finfo->file($sti) ?: ''),
            'storrelse'     => (int) filesize($sti),
            'lastet_opp_av' => null,
            'kilde'         => $kilde,
            'tekst'         => $tekst === '' ? null : $tekst,
        ]);
    }

    /**
     * Kildene eieren har slettet, saa importen ikke legger dem tilbake.
     *
     * Ligger i innstillinger som én tekst med linjeskift, ikke i en egen
     * tabell: det er en liste som sjelden vokser, og «innstillinger» finnes
     * alt paa alle installasjoner.
     *
     * @return array<string,true>
     */
    private static function slettedeKilder(): array
    {
        if (!DB::harTabell('innstillinger')) {
            return [];
        }
        $tekst = (string) (DB::verdi(
            "SELECT verdi FROM innstillinger WHERE nokkel = 'verksted_import_slettet'"
        ) ?? '');
        $ut = [];
        foreach (preg_split('/\R/', $tekst) ?: [] as $l) {
            if (trim($l) !== '') {
                $ut[trim($l)] = true;
            }
        }
        return $ut;
    }

    /** Husk at denne kilden er slettet med vilje. Kalles fra slett(). */
    private static function huskSlettetKilde(string $kilde): void
    {
        if ($kilde === '' || !DB::harTabell('innstillinger')) {
            return;
        }
        $alle = self::slettedeKilder();
        $alle[$kilde] = true;
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
            ['verksted_import_slettet', implode("\n", array_keys($alle))]
        );
    }
}
