<?php
/**
 * Serverer et opplastet bilde.
 *
 *   GET ?salg=<filnavn>       bilde til en vare i internbutikken
 *   GET ?artikkel=<filnavn>   bilde eieren har lastet opp til en artikkel
 *   GET ?feil=<filnavn>       skjermbilde fra en feilmelding (bare admin)
 *   GET ...&b=400|800         mindre utgave, laget og lagret ved forste kall
 *
 * Filene ligger utenfor det som publiseres, saa de maa gaa gjennom PHP. Det
 * er ikke bare en ulempe: her kan vi la vaere aa vise bildet til en vare som
 * ikke er godkjent ennaa.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

/**
 * Bildet i den stoerrelsen og det formatet som trengs.
 *
 * Et produktkort viser bildet i 370 piksler, men fikk originalen paa 1400 —
 * ti ganger for mange piksler, hver gang. «?b=400» gir en mindre utgave, og
 * er nettleseren glad i webp faar den det ogsaa.
 *
 * Utgavene regnes ut én gang og legges ved siden av originalen. Neste
 * forespoersel leser fila.
 *
 * Bare faste bredder tas imot. Uten det kunne hvem som helst be om tusen
 * ulike stoerrelser og fylle disken.
 */
function avledet(string $sti, int $bredde, bool $webp): string
{
    if ($bredde <= 0 && !$webp) {
        return $sti;
    }
    if (!function_exists('imagecreatefromjpeg')) {
        return $sti;
    }

    $mappe = Bilder::mappe('avledet');
    $navn  = pathinfo($sti, PATHINFO_FILENAME)
           . ($bredde > 0 ? '-' . $bredde : '')
           . ($webp ? '.webp' : '.jpg');
    $mal = $mappe . '/' . $navn;

    if (is_file($mal) && filemtime($mal) >= filemtime($sti)) {
        return $mal;
    }

    $im = @imagecreatefromjpeg($sti);
    if ($im === false) {
        return $sti;
    }
    if ($bredde > 0 && imagesx($im) > $bredde) {
        $ny = imagescale($im, $bredde);
        if ($ny !== false) {
            imagedestroy($im);
            $im = $ny;
        }
    }
    $ok = $webp ? @imagewebp($im, $mal, 78) : @imagejpeg($im, $mal, 80);
    imagedestroy($im);

    return $ok && is_file($mal) ? $mal : $sti;
}

/**
 * Send fila og avslutt.
 *
 * Bildene endrer seg aldri — navnet er tilfeldig, saa en ny fil faar et nytt
 * navn. Da kan nettleseren beholde det i et aar uten aa sporre igjen.
 */
function lever(string $sti): never
{
    // Bredden er valgfri, og bare disse tallene tas imot — de samme som
    // filene i rota har.
    $bredde = (int) ($_GET['b'] ?? 0);
    if (!in_array($bredde, [400, 800], true)) {
        $bredde = 0;
    }
    $webp = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'image/webp')
            && function_exists('imagewebp');

    $sti = avledet($sti, $bredde, $webp);

    // «Vary: Accept» maa staa: uten den kan en mellomlagring gi webp til en
    // nettleser som ikke leser det.
    header('Vary: Accept');
    header('Content-Type: ' . (str_ends_with($sti, '.webp') ? 'image/webp' : 'image/jpeg'));
    header('Content-Length: ' . filesize($sti));
    // Navnet er en hash av innholdet — en ny fil faar et nytt navn. Da kan
    // nettleseren beholde den i et aar uten aa sporre igjen. Sto paa sju
    // dager, og bildene ble hentet paa nytt hver uke uten grunn.
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    readfile($sti);
    exit;
}

// ── Bilder deltakeren har tatt av keramikken sin ───────────────────────────
//
// Disse er private. Bare deltakeren selv og verkstedet skal se dem — et bilde
// av noen andres skaal er ikke noe en fremmed skal kunne be om.
//
// Filnavnet er 32 tilfeldige tegn, men det holder ikke som sperre: den som
// har sett ett navn skal ikke kunne bruke det etter at plassen er avbestilt,
// og en lenke som lekker skal ikke aapne noe. Derfor sjekkes eierskapet mot
// bookingen paa hver forespoersel.
$deltaker = Foresporsel::tekst('deltaker');
if ($deltaker !== '') {
    $sti = Bilder::sti($deltaker, 'deltakere');
    if ($sti === null || !DB::harTabell('deltaker_bilder')) {
        Svar::feil('Fant ikke bildet.', 404);
    }
    $eier = DB::en(
        'SELECT b.member_id FROM deltaker_bilder db
           JOIN bookings b ON b.id = db.booking_id
          WHERE db.fil = :f',
        ['f' => $deltaker]
    );
    $m = Sesjon::medlem();
    $egen = $eier !== null && $m !== null
         && $eier['member_id'] !== null
         && (int) $eier['member_id'] === (int) $m['id'];
    if (!$egen && !Sesjon::erAdmin()) {
        Svar::feil('Fant ikke bildet.', 404);
    }
    lever($sti);
}

// ── Skjermbildet som fulgte en feilmelding ────────────────────────────────
//
// Bare verkstedet. Et skjermbilde tatt i admin kan vise hva som helst —
// deltakerlister, e-postadresser, en halvferdig ordre — og den som meldte fra
// gjorde det til verkstedet, ikke til alle som gjetter et filnavn.
$feil = Foresporsel::tekst('feil');
if ($feil !== '') {
    $sti = Bilder::sti($feil, 'feilrapporter');
    if ($sti === null || !Sesjon::erAdmin()) {
        Svar::feil('Fant ikke bildet.', 404);
    }
    lever($sti);
}

// Bilder til artikler er aapne for alle — de staar paa nettsida uansett.
$artikkel = Foresporsel::tekst('artikkel');
if ($artikkel !== '') {
    $sti = Bilder::sti($artikkel, 'artikler');
    if ($sti === null) {
        Svar::feil('Fant ikke bildet.', 404);
    }
    lever($sti);
}

$navn = Foresporsel::tekst('salg');
$sti  = Bilder::sti($navn, 'medlemssalg');

if ($sti === null) {
    Svar::feil('Fant ikke bildet.', 404);
}

// Bilder til varer som venter paa godkjenning, er avvist eller tatt ned skal
// ikke ligge aapent. Eieren og selgeren selv far se dem.
$rad = DB::en('SELECT member_id, status FROM member_sales WHERE bilde = :b', ['b' => $navn]);
if ($rad !== null && $rad['status'] !== 'publisert') {
    $m = Sesjon::medlem();
    $egen = $m !== null && (int) $m['id'] === (int) $rad['member_id'];
    if (!$egen && !Sesjon::erAdmin()) {
        Svar::feil('Fant ikke bildet.', 404);
    }
}

lever($sti);
