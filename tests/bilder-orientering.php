<?php
/** Målrettet regresjonstest for telefonbilder med EXIF-orientering. */
declare(strict_types=1);

define('APP_DIR', sys_get_temp_dir() . '/lissom-bilder-orientering-' . bin2hex(random_bytes(6)) . '/app');
require dirname(__DIR__) . '/app/lib/bilder.php';

$im = imagecreatetruecolor(40, 60);
$farger = [
    imagecolorallocate($im, 245, 20, 20),
    imagecolorallocate($im, 20, 245, 20),
    imagecolorallocate($im, 20, 20, 245),
    imagecolorallocate($im, 245, 245, 20),
];
imagefilledrectangle($im, 0, 0, 19, 29, $farger[0]);
imagefilledrectangle($im, 20, 0, 39, 29, $farger[1]);
imagefilledrectangle($im, 0, 30, 19, 59, $farger[2]);
imagefilledrectangle($im, 20, 30, 39, 59, $farger[3]);
ob_start();
imagejpeg($im, null, 95);
$jpeg = ob_get_clean();
imagedestroy($im);

// Rekkefølge: oppe venstre, oppe høyre, nede venstre, nede høyre.
$forventet = [
    1 => [0, 1, 2, 3], 2 => [1, 0, 3, 2],
    3 => [3, 2, 1, 0], 4 => [2, 3, 0, 1],
    5 => [0, 2, 1, 3], 6 => [2, 0, 3, 1],
    7 => [3, 1, 2, 0], 8 => [1, 3, 0, 2],
];
$feil = [];
try {
    foreach ($forventet as $retning => $hjørner) {
        foreach (['II', 'MM'] as $rekkefolge) {
        // TIFF: begge byteordener, én IFD-rad med Orientation (0x0112).
        $tiff = $rekkefolge === 'II'
            ? 'II' . pack('vV', 42, 8) . pack('v', 1)
                . pack('vvVv', 0x0112, 3, 1, $retning) . "\0\0" . pack('V', 0)
            : 'MM' . pack('nN', 42, 8) . pack('n', 1)
                . pack('nnNn', 0x0112, 3, 1, $retning) . "\0\0" . pack('N', 0);
        $app1 = "Exif\0\0" . $tiff;
        $data = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2)
            . $app1 . substr($jpeg, 2);
        $navn = Bilder::taImotData($data, 'test');
        $sti = Bilder::sti($navn, 'test');
        $ut = imagecreatefromjpeg($sti);
        $bredde = imagesx($ut);
        $hoyde = imagesy($ut);
        if (($retning >= 5 ? [60, 40] : [40, 60]) !== [$bredde, $hoyde]) {
            $feil[] = "Retning $retning ($rekkefolge): feil størrelse";
        }
        $posisjoner = [[5, 5], [$bredde - 6, 5], [5, $hoyde - 6], [$bredde - 6, $hoyde - 6]];
        foreach ($posisjoner as $i => [$x, $y]) {
            $rgb = imagecolorsforindex($ut, imagecolorat($ut, $x, $y));
            $referanse = imagecolorsforindex($ut, $farger[$hjørner[$i]]);
            if (abs($rgb['red'] - $referanse['red']) > 30
                || abs($rgb['green'] - $referanse['green']) > 30
                || abs($rgb['blue'] - $referanse['blue']) > 30) {
                $feil[] = "Retning $retning ($rekkefolge): feil hjørne $i";
            }
        }
        imagedestroy($ut);
        unlink($sti);
        }
    }
} finally {
    @rmdir(dirname(APP_DIR) . '/opplastinger/test');
    @rmdir(dirname(APP_DIR) . '/opplastinger');
    @rmdir(dirname(APP_DIR));
}
if ($feil) {
    fwrite(STDERR, implode("\n", $feil) . "\n");
    exit(1);
}
echo "EXIF-orientering 1–8, begge byteordener: OK\n";
