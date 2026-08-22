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

        // Vi stoler ikke paa filnavn eller Content-Type. Bildet aapnes, og
        // klarer ikke GD det, er det ikke et bilde.
        $info = @getimagesize($sti);
        if ($info === false || !isset(self::TYPER[$info[2]])) {
            throw new RuntimeException('Filen må være JPG, PNG eller WEBP.');
        }

        // Et bilde paa 20 000 × 20 000 piksler er 1,2 GB i minnet uansett hvor
        // liten fila er. Stopp for vi aapner det.
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
        // Hvit bunn: PNG med gjennomsiktighet blir ellers svart som JPEG.
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
}
