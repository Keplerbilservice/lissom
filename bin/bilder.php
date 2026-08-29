<?php
/**
 * Lager de mindre utgavene av fotografiene.
 *
 *   php bin/bilder.php
 *
 * Et kort viser bildet i 254 til 400 piksler. Uten mindre utgaver lastet
 * nettleseren ned originalen paa 1200 og kastet det meste av den.
 *
 * For hvert foto lages «-400» og «-800», og en webp-tvilling til hver.
 * Apache velger format ut fra Accept-headeren (se .htaccess), nettleseren
 * velger stoerrelse ut fra srcset. Lista over hvilke utgaver som finnes
 * ligger i lissom-2108.html som window.__bildekart, og skrives ut her —
 * den maa oppdateres naar det kommer nye bilder.
 *
 * Skriptet hopper over logoer, ikoner og signaturer: de vises smaa fra for.
 */

declare(strict_types=1);

const BREDDER = [400, 800];
// «delingsbilde» staar her fordi den ikke skal ha en webp-tvilling. Den
// hentes av Facebook, LinkedIn, Slack og WhatsApp, og finnes det en
// «delingsbilde.jpg.webp» ved siden av, kan .htaccess servere den paa
// .jpg-adressen — og da viser flere av dem ingenting.
const HOPP = ['mark-', 'logo-', 'wordmark', 'favicon', 'icon-', 'apple-touch',
              'joika', 'e-post-', 'lissom-signatur', 'heart-logo', 'signatur-',
              'assets_kursbevis', 'delingsbilde'];

$rot = dirname(__DIR__);
$laget = 0;
$kart = [];

foreach (glob($rot . '/*.jpg') ?: [] as $sti) {
    $navn = basename($sti);
    foreach (HOPP as $h) {
        if (str_starts_with($navn, $h)) {
            continue 2;
        }
    }
    // Utgavene selv skal ikke faa utgaver.
    //
    // Bare de breddene dette skriptet selv lager. Monsteret var «-\d+.jpg»,
    // og det traff ogsaa ekte originalnavn: «uploads_shutterstock_2767913113-1
    // .jpg», «assets_datenight-1.jpg» og «assets_datenight-3.jpg» ble hoppet
    // over, fikk aldri mindre utgaver, og sto ikke i bildekartet.
    //
    // Det var synlig paa forsida. Events-bildet i rotasjonen er det ene av de
    // seks uten mindre utgaver, og «flytt()» lot da srcset-en fra det forrige
    // bildet staa igjen — Keplers kopper dukket opp paa «En kveld de kommer
    // til aa snakke om». Selve srcset-feilen er rettet i lissom-2108.html; her
    // rettes grunnen til at bildet manglet utgaver i det hele tatt.
    if (preg_match('/-(' . implode('|', BREDDER) . ')\.jpg$/', $navn)) {
        continue;
    }

    [$bredde] = getimagesize($sti) ?: [0];
    if ($bredde <= 0) {
        continue;
    }

    $har = [];
    foreach (BREDDER as $b) {
        if ($bredde <= $b) {
            continue;
        }
        $ut = substr($sti, 0, -4) . '-' . $b . '.jpg';
        $har[] = $b;
        if (is_file($ut) && filemtime($ut) >= filemtime($sti)) {
            continue;
        }
        $im = @imagecreatefromjpeg($sti);
        if ($im === false) {
            continue;
        }
        $liten = imagescale($im, $b);
        imagedestroy($im);
        if ($liten === false) {
            continue;
        }
        imagejpeg($liten, $ut, 80);
        imagewebp($liten, $ut . '.webp', 78);
        imagedestroy($liten);
        $laget += 2;
    }

    // Webp-tvillingen til originalen.
    //
    // Skriptet laget den bare for de nedskalerte utgavene. De fleste
    // originalene hadde likevel en «.jpg.webp» ved siden av seg — laget en
    // gang for haand — men ikke alle: «assets_photos_butikken.jpg» og
    // «uploads_kepler-kopp.jpg» sto igjen som ren jpeg, og .htaccess hadde
    // ingenting aa servere. Butikkbildet paa forsida ble derfor sendt som
    // 69 kB jpeg til nettlesere som gjerne ville hatt 45 kB webp.
    //
    // Verre enn de to kilobytene: det neste bildet noen laster opp ville
    // havnet i samme hull. Naa lages den her, saa lista ikke kan sprike.
    // «delingsbilde» staar i HOPP og roeres ikke — den skal vaere ren jpeg.
    $tvilling = $sti . '.webp';
    if (!is_file($tvilling) || filemtime($tvilling) < filemtime($sti)) {
        $im = @imagecreatefromjpeg($sti);
        if ($im !== false) {
            imagewebp($im, $tvilling, 78);
            imagedestroy($im);
            $laget++;
        }
    }

    $har[] = $bredde;
    $kart[$navn] = $har;
}

printf(
    "%d filer laget.\n\nLim inn i lissom-2108.html som window.__bildekart:\n%s\n",
    $laget,
    json_encode($kart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
