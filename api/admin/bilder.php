<?php
/**
 * Bildene som kan brukes i artikler.
 *
 *   GET                        alle bilder som finnes aa velge mellom
 *   POST handling=last-opp     ta imot et eget bilde (multipart, felt «bilde»)
 *   POST handling=slett        fjern et opplastet bilde
 *
 * To slags bilder ligger her. De som foelger med nettsida er filer i rota —
 * verkstedsbilder og innkjopte bilder — og de endres bare naar sida legges
 * ut paa nytt. De som lastes opp herfra havner utenfor det som publiseres,
 * og serveres gjennom api/bilde.php.
 *
 * Grunnen til at de ikke ligger sammen: alt som publiseres blir overskrevet
 * ved neste utlegging. Et bilde eieren lastet opp ville forsvunnet.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

/** Bildene som foelger med nettsida, lest fra mappa framfor en liste her. */
/**
 * Bildene som foelger med nettsida.
 *
 * Hvert bilde ligger i tre stoerrelser paa disk: originalen, en paa 800 og en
 * paa 400 piksler. Lista tok med alle tre, og da sto det samme bildet tre
 * ganger etter hverandre i velgeren — 116 ruter der det egentlig var under
 * foerti bilder. Umulig aa finne noe i, og paa mobil ble ruta seks tusen
 * piksler hoy.
 *
 * Naa staar bildet én gang. Miniatyren peker paa 400-versjonen der den finnes
 * — velgeren lastet foer originalene i full stoerrelse, alle sammen, hver gang
 * ruta ble aapnet.
 */
$medfolgende = static function (): array {
    $rot = dirname(__DIR__, 2);
    $ut  = [];
    foreach (['assets_photos_*.jpg', 'assets_datenight*.jpg', 'uploads_*.jpg'] as $monster) {
        foreach (glob($rot . '/' . $monster) ?: [] as $sti) {
            $navn = basename($sti);
            // Logoer og signaturer er ikke bilder til en artikkel.
            if (str_contains($navn, 'logo') || str_contains($navn, 'signatur')) {
                continue;
            }
            // «-400» og «-800» er den samme fila, mindre. De hoerer ikke
            // hjemme som egne valg.
            if (preg_match('/-(400|800)\.jpg$/', $navn)) {
                continue;
            }
            $liten = preg_replace('/\.jpg$/', '-400.jpg', $navn);
            $ut[$navn] = [
                'url'  => $navn,
                // Det velgeren viser. Originalen kan vaere flere megabyte, og
                // en rute paa 84 piksler trenger ikke det.
                'mini' => is_file($rot . '/' . $liten) ? $liten : $navn,
                'egen' => false,
            ];
        }
    }
    ksort($ut);
    return array_values($ut);
};

/** Bildene eieren selv har lastet opp. Nyeste forst — de er mest aktuelle. */
$egne = static function (): array {
    $mappe = Bilder::mappe('artikler');
    $filer = glob($mappe . '/*.jpg') ?: [];
    usort($filer, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    return array_map(static fn($sti) => [
        'url'  => 'api/bilde.php?artikkel=' . basename($sti),
        // Opplastede bilder skaleres ned naar de tas imot, saa originalen er
        // allerede liten nok til en miniatyr.
        'mini' => 'api/bilde.php?artikkel=' . basename($sti),
        'navn' => basename($sti),
        'egen' => true,
    ], $filer);
};

if (Foresporsel::metode() === 'GET') {
    Svar::json(['egne' => $egne(), 'medfolgende' => $medfolgende()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

// Multipart, ikke JSON — filer kan ikke sendes som JSON. Handlingen leses
// derfor fra skjemaet og fra adressen.
$handling = (string) ($_POST['handling'] ?? Foresporsel::tekst('handling'));

switch ($handling) {

    case 'last-opp':
        if (!isset($_FILES['bilde']) || ($_FILES['bilde']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Svar::feil('Du må velge et bilde.');
        }
        try {
            $navn = Bilder::taImot($_FILES['bilde'], 'artikler');
        } catch (RuntimeException $e) {
            Svar::feil($e->getMessage());
        }
        revider('bilde_lastet_opp', 'bilde', null, ['navn' => $navn]);
        Svar::ok([
            'beskjed' => 'Bildet er lastet opp.',
            'url'     => 'api/bilde.php?artikkel=' . $navn,
            'navn'    => $navn,
        ]);

    case 'slett':
        $navn = (string) ($_POST['navn'] ?? '');
        if (Bilder::sti($navn, 'artikler') === null) {
            Svar::feil('Fant ikke bildet.');
        }
        // Et bilde som staar i en artikkel skal ikke kunne forsvinne under
        // foettene paa den. Da staar det en tom ramme paa nettsida.
        $url = 'api/bilde.php?artikkel=' . $navn;
        $brukt = DB::alle('SELECT tittel FROM articles WHERE bilde = :b', ['b' => $url]);
        if ($brukt !== []) {
            Svar::feil('Bildet er i bruk i «' . $brukt[0]['tittel'] . '». Bytt bilde der først.');
        }
        Bilder::slett($navn, 'artikler');
        revider('bilde_slettet', 'bilde', null, ['navn' => $navn]);
        Svar::ok(['beskjed' => 'Bildet er slettet.']);

    default:
        Svar::feil('Ukjent handling.');
}
