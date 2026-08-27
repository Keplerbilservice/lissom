<?php
/**
 * Bilder medlemmene laster opp.
 *
 * Filene ligger UTENFOR det som publiseres. Utrullingen speiler repoet til
 * public_html og sletter det som ikke finnes lokalt — la bildene der, ville
 * de forsvunnet ved neste push. De ligger derfor ved siden av app/ og bin/,
 * i en mappe ingen utrullingsjobb rorer, og serveres gjennom api/bilde.php.
 *
 * Alt som kommer inn tegnes om med GD og lagres som JPEG. Det gjor tre ting
 * paa én gang: filen kan ikke inneholde noe annet enn et bilde, exif med
 * posisjonsdata forsvinner, og stoerrelsen blir hanterlig.
 */

declare(strict_types=1);

final class Bilder
{
    /** Lengste kant. Et produktbilde trenger ikke mer. */
    private const MAKS_KANT = 1400;

    /** Storste fil vi tar imot for omtegning. */
    private const MAKS_BYTES = 8 * 1024 * 1024;

    private const TYPER = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];

    public static function mappe(string $under = ''): string
    {
        $rot = dirname(APP_DIR) . '/opplastinger';
        $sti = $under === '' ? $rot : $rot . '/' . $under;
        if (!is_dir($sti)) {
            @mkdir($sti, 0755, true);
        }
        return $sti;
    }

    /**
     * Tar imot én opplastet fil og lagrer den som JPEG.
     *
     * @return string filnavnet, til lagring i basen
     * @throws RuntimeException med en tekst som kan vises til den som lastet opp
     */
    public static function taImot(array $fil, string $under): string
    {
        if (!isset($fil['error']) || $fil['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($fil['error'] ?? -1) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Bildet er for stort.',
                UPLOAD_ERR_NO_FILE => 'Du må velge et bilde.',
                default => 'Bildet kom ikke fram. Prøv igjen.',
            });
        }
        if (($fil['size'] ?? 0) > self::MAKS_BYTES) {
            throw new RuntimeException('Bildet er for stort. Maks 8 MB.');
        }

        $sti = $fil['tmp_name'] ?? '';
        if ($sti === '' || !is_uploaded_file($sti)) {
            throw new RuntimeException('Fant ikke bildet.');
        }

        // Vi stoler ikke paa filnavn eller Content-Type. Bildet aapnes i
        // fraFil(), og klarer ikke GD det, er det ikke et bilde.
        return self::fraFil($sti, $under);
    }

    /**
     * Lagrer et bilde vi selv har hentet, framfor en opplasting.
     *
     * taImot() krever is_uploaded_file() — og det er riktig for noe som kommer
     * fra en nettleser. Et bilde vi har lisensiert og lastet ned fra
     * Shutterstock kommer ikke den veien, og skal likevel gjennom nøyaktig
     * samme kontroll: at det er et bilde, at det ikke er urimelig stort, og at
     * det skaleres til 1400 piksler som alt annet.
     *
     * @param string $data selve bildet
     * @return string filnavnet det fikk
     */
    public static function taImotData(string $data, string $under): string
    {
        if (strlen($data) > self::MAKS_BYTES * 4) {
            throw new RuntimeException('Bildet er for stort.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'lissom');
        if ($tmp === false || file_put_contents($tmp, $data) === false) {
            throw new RuntimeException('Fikk ikke lagret bildet midlertidig.');
        }
        try {
            return self::fraFil($tmp, $under);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Selve arbeidet: les, kontroller, skaler, lagre.
     *
     * Sto inne i taImot() og kunne bare naas gjennom en opplasting.
     */
    private static function fraFil(string $sti, string $under): string
    {
        $info = @getimagesize($sti);
        if ($info === false || !isset(self::TYPER[$info[2]])) {
            throw new RuntimeException('Filen må være JPG, PNG eller WEBP.');
        }
        if ($info[0] * $info[1] > 40_000_000) {
            throw new RuntimeException('Bildet har for mange piksler.');
        }

        $les = self::TYPER[$info[2]];
        $kilde = @$les($sti);
        if ($kilde === false) {
            throw new RuntimeException('Bildet kunne ikke leses.');
        }

        [$b, $h] = [imagesx($kilde), imagesy($kilde)];
        $skala = min(1.0, self::MAKS_KANT / max($b, $h));
        $nb = max(1, (int) round($b * $skala));
        $nh = max(1, (int) round($h * $skala));

        $ut = imagecreatetruecolor($nb, $nh);
        imagefill($ut, 0, 0, imagecolorallocate($ut, 255, 255, 255));
        imagecopyresampled($ut, $kilde, 0, 0, 0, 0, $nb, $nh, $b, $h);
        imagedestroy($kilde);

        $navn = bin2hex(random_bytes(16)) . '.jpg';
        $mal = self::mappe($under) . '/' . $navn;

        if (!imagejpeg($ut, $mal, 82)) {
            imagedestroy($ut);
            throw new RuntimeException('Bildet kunne ikke lagres.');
        }
        imagedestroy($ut);
        @chmod($mal, 0644);

        return $navn;
    }

    /** Full sti til et lagret bilde, eller null om navnet ikke er vaart. */
    public static function sti(string $navn, string $under): ?string
    {
        // Bare navnene vi selv lager. Uten dette kunne «../../app/secrets.php»
        // vaert et gyldig bildenavn.
        if (!preg_match('/^[0-9a-f]{32}\.jpg$/', $navn)) {
            return null;
        }
        $sti = self::mappe($under) . '/' . $navn;
        return is_file($sti) ? $sti : null;
    }

    public static function slett(string $navn, string $under): void
    {
        $sti = self::sti($navn, $under);
        if ($sti !== null) {
            @unlink($sti);
        }
    }

    // ── Hvilken del av bildet som skal vises ────────────────────────────
    //
    // Rammene har faste former — kurskortene 16:10, butikken 1:1 — og et
    // bilde som ikke har den formen blir beskaaret fra midten. Velger du et
    // portrett, faar du en hals.
    //
    // Fokuspunktet beskjaerer ingenting. Originalen ligger urort, og punktet
    // sier bare hvor ramma skal sentreres. Da kan valget gjores om igjen.

    /**
     * Fokuspunktene, som filnavn => «50% 30%».
     *
     * @return array<string,string>
     */
    public static function fokus(): array
    {
        if (!DB::harTabell('bilde_fokus')) {
            return [];
        }
        $ut = [];
        foreach (DB::alle('SELECT fil, fokus FROM bilde_fokus') as $r) {
            $ut[(string) $r['fil']] = (string) $r['fokus'];
        }
        return $ut;
    }

    /**
     * Lagrer et fokuspunkt. «50% 50%» er midten og lagres ikke — da staar
     * bildet som nettleseren ville vist det uansett, og raden er stoy.
     */
    public static function settFokus(string $fil, string $fokus): void
    {
        if (!DB::harTabell('bilde_fokus')) {
            throw new RuntimeException('Fokuspunkt krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
        }
        $fil = mb_substr(trim($fil), 0, 191);
        if ($fil === '') {
            throw new RuntimeException('Mangler bildet.');
        }
        // To prosenttall, ikke noe annet. Verdien gaar rett inn i en
        // stilregel, og da skal den ikke kunne inneholde noe vi ikke har
        // sett paa.
        if (!preg_match('/^\d{1,3}% \d{1,3}%$/', $fokus)) {
            throw new RuntimeException('Ugyldig fokuspunkt.');
        }
        if ($fokus === '50% 50%') {
            DB::kjor('DELETE FROM bilde_fokus WHERE fil = :f', ['f' => $fil]);
            return;
        }
        DB::kjor(
            'INSERT INTO bilde_fokus (fil, fokus) VALUES (:f, :p)
             ON DUPLICATE KEY UPDATE fokus = VALUES(fokus)',
            ['f' => $fil, 'p' => $fokus]
        );
    }
}
