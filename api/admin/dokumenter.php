<?php
/**
 * Dokumentkortene i verkstedet.
 *
 *   GET                          kortene, dokumentene og bryterne
 *   POST handling=last-opp       { kategori } + fila i «dokument»
 *   POST handling=slett          { id }
 *   POST handling=tekst          { id, tekst }        teksten AI-en kan lese
 *   POST handling=veksle         { id }               vis kortet for medlemmer
 *   POST handling=veksle-faq                          vis «Spor verkstedet»
 *
 * Eieren, 10. september 2026: seks kort — kontrakter, keramikk maler,
 * engober, glassering, brenning og dekorasjonsteknikker — der han kan lagre,
 * aapne, skrive ut og laste opp. Og: «vil det vaere mulig aa faa denne paa
 * medlemsiden ogsaa? at jeg kan velge i admin, vis paa medlemsiden?»
 *
 * Bryteren staar derfor paa kortet, ikke paa dokumentet. Alt er av til han
 * slaar det paa selv — ogsaa kontraktene, som nok bor bli staaende av.
 *
 * Selve filene serveres av api/dokument.php. Her lastes de bare opp.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if (!Dokumenter::klar()) {
    Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
}

/** Alt skjermen trenger, regnet ett sted. */
$hent = static fn(): array => [
    'kategorier' => Dokumenter::kategorier(),
    'dokumenter' => Dokumenter::dokumenter(),
    'faqMedlem'  => Dokumenter::faqForMedlem(),
    'ai'         => AI::status(),
    'maksMb'     => Dokumenter::maksMb(),
];

if (Foresporsel::metode() === 'GET') {
    Svar::json($hent());
}

Foresporsel::krevMetode('POST');

// En fil som sprenger serverens «post_max_size» kommer fram HELT TOM: ingen
// $_POST, ingen $_FILES, og ingen feilkode aa lese. Uten dette svarte
// skjermen «Du må velge en fil» paa en fil som var altfor stor — og den som
// lastet opp lette etter en fil som laa der hele tiden.
//
// Staar for opphavssjekken, som leser $_POST og derfor heller ikke har noe
// aa gaa paa.
if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    Svar::feil('Filen er for stor. Maks ' . Dokumenter::maksMb() . ' MB.');
}

Foresporsel::krevSammeOpphav();

// Multipart, ikke JSON — filer kan ikke sendes som JSON.
$handling = (string) ($_POST['handling'] ?? Foresporsel::tekst('handling'));

switch ($handling) {

    // ------------------------------------------------------------ last opp
    case 'last-opp':
        $kategoriId = (int) ($_POST['kategori'] ?? Foresporsel::heltall('kategori'));
        $kategori = DB::en('SELECT * FROM verksted_kategorier WHERE id = :i', ['i' => $kategoriId]);
        if (!$kategori) {
            Svar::feil('Fant ikke kortet.');
        }
        if (!isset($_FILES['dokument']) || ($_FILES['dokument']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Svar::feil('Du må velge en fil.');
        }
        try {
            $id = Dokumenter::taImot($_FILES['dokument'], $kategoriId, (int) $admin['id']);
        } catch (RuntimeException $e) {
            Svar::feil($e->getMessage());
        }
        revider('dokument_lastet_opp', 'dokument', $id, [
            'kategori' => (string) $kategori['navn'],
            'navn'     => (string) ($_FILES['dokument']['name'] ?? ''),
        ]);
        Svar::ok(['beskjed' => 'Filen er lastet opp.'] + $hent());

    // --------------------------------------------------------------- slett
    case 'slett':
        $id = (int) ($_POST['id'] ?? Foresporsel::heltall('id'));
        $dok = Dokumenter::en($id);
        if ($dok === null) {
            Svar::feil('Fant ikke dokumentet.');
        }
        Dokumenter::slett($id);
        revider('dokument_slettet', 'dokument', $id, [
            'kategori' => (string) $dok['kategori_navn'],
            'navn'     => (string) $dok['originalnavn'],
        ]);
        Svar::ok(['beskjed' => 'Dokumentet er slettet.'] + $hent());

    // ---------------------------------------------------------------- tekst
    //
    // AI-en leser tekst, ikke filer. En PDF er en binaerfil, og et skannet ark
    // har ingen tekst i seg i det hele tatt — derfor limes den inn her.
    case 'tekst':
        $id = (int) ($_POST['id'] ?? Foresporsel::heltall('id'));
        if (Dokumenter::en($id) === null) {
            Svar::feil('Fant ikke dokumentet.');
        }
        $tekst = (string) ($_POST['tekst'] ?? Foresporsel::tekst('tekst'));
        if (mb_strlen($tekst) > 200000) {
            Svar::feil('Teksten er for lang. Del den i to dokumenter.');
        }
        DB::oppdater('verksted_dokumenter', ['tekst' => $tekst], ['id' => $id]);
        Svar::ok(['beskjed' => 'Teksten er lagret.'] + $hent());

    // --------------------------------------------------------------- veksle
    case 'veksle':
        $id = (int) ($_POST['id'] ?? Foresporsel::heltall('id'));
        $k = DB::en('SELECT * FROM verksted_kategorier WHERE id = :i', ['i' => $id]);
        if (!$k) {
            Svar::feil('Fant ikke kortet.');
        }
        $ny = ((int) $k['vis_medlem']) === 1 ? 0 : 1;
        DB::oppdater('verksted_kategorier', ['vis_medlem' => $ny], ['id' => $id]);
        revider('dokumentkort_synlighet', 'kategori', $id, [
            'kort' => (string) $k['navn'],
            'vis'  => $ny === 1 ? 'medlemmer' : 'skjult',
        ]);
        Svar::ok($hent());

    // ----------------------------------------------------------- veksle-faq
    case 'veksle-faq':
        if (!DB::harTabell('innstillinger')) {
            Svar::feil('Dette krever en oppdatering av databasen.');
        }
        $ny = Dokumenter::faqForMedlem() ? '0' : '1';
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            ['verksted_faq_medlem', $ny, (int) $admin['id']]
        );
        Config::glemBasen();
        revider('faq_synlighet', 'innstilling', null, [
            'vis' => $ny === '1' ? 'medlemmer' : 'skjult',
        ]);
        Svar::ok($hent());
}

Svar::feil('Ukjent handling.');
