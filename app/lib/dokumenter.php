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
    /** Storste fil vi tar imot. */
    public const MAKS_BYTES = 20 * 1024 * 1024;

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
        if (!isset($fil['error']) || $fil['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($fil['error'] ?? -1) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Filen er for stor. Maks 20 MB.',
                UPLOAD_ERR_NO_FILE => 'Du må velge en fil.',
                default => 'Filen kom ikke fram. Prøv igjen.',
            });
        }
        if (($fil['size'] ?? 0) > self::MAKS_BYTES) {
            throw new RuntimeException('Filen er for stor. Maks 20 MB.');
        }

        $tmp = (string) ($fil['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Fant ikke filen.');
        }

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
