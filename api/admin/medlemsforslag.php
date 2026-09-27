<?php
/**
 * Medlemsforslag til Instagram — verkstedets side.
 *
 *   GET                                  forslagene som venter, de siste
 *                                        behandlede, og malen
 *   POST handling=godkjenn { id, tekst? } legg ut paa @lissom_keramikk
 *   POST handling=avvis    { id }         ta det bort
 *   POST handling=mal      { tekst }      den faste linja i innlegget
 *   POST handling=ut-av-galleri { id }    ta bildet ut av galleriet
 *   POST handling=bruk-bilde { id, url }  bytt til det forbedrede bildet
 *
 * Galleriet paa forsida (eieren, 27. september 2026, migrasjon 225):
 * «godkjenn» tar { instagram, galleri } — minst én maa vaere satt. Bare
 * galleri: ingenting legges ut paa Instagram, og statusen blir «galleri».
 * Se app/lib/galleri.php.
 *
 * Eieren, 24. september 2026: «maa godkjennes av admin». Et godkjent
 * forslag legges ut med én gang — «Godkjenn og legg ut» er ett trykk, som i
 * skissen han sa GO til. Aldri fra en cron-jobb eller Autopilot: et innlegg
 * paa Lissoms konto er verkstedets trykk.
 *
 * «tekst» ved godkjenning er hele bildeteksten slik admin har redigert den.
 * Mangler den, bygges den av medlemmets tekst, malen og hashtaggene.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if (!Medlemsforslag::klar()) {
    Svar::feil('Migrasjon 207 er ikke kjørt. Trykk ⚙ Kjør oppdateringer.');
}

$tilUt = static function (array $r): array {
    return [
        'id'         => (int) $r['id'],
        'type'       => (string) $r['type'],
        'fil'        => '/api/bilde.php?forslag=' . rawurlencode((string) $r['fil']),
        'navn'       => (string) ($r['navn'] ?? ''),
        'instagram'  => (string) $r['instagram'],
        'tekst'      => (string) $r['tekst'],
        'hashtags'   => Medlemsforslag::hashtags((string) $r['hashtags']),
        'bildetekst' => $r['bildetekst'] !== null && $r['bildetekst'] !== ''
            ? (string) $r['bildetekst']
            : Medlemsforslag::bildetekst($r, Medlemsforslag::fornavn((string) ($r['navn'] ?? ''))),
        'status'     => (string) $r['status'],
        'galleri'    => (int) ($r['galleri'] ?? 0) === 1,
        'vistFra'    => (string) ($r['galleri_vist_fra'] ?? ''),
        'tittel'     => Galleri::tittel((string) $r['tekst']),
        'lenke'      => (string) $r['lenke'],
        'tid'        => (string) $r['created_at'],
    ];
};

$hent = static fn(int $id): ?array => DB::en(
    'SELECT f.*, m.navn FROM medlemsforslag f JOIN members m ON m.id = f.member_id WHERE f.id = :i',
    ['i' => $id]
);

if (Foresporsel::metode() === 'GET') {
    $venter = DB::alle(
        "SELECT f.*, m.navn FROM medlemsforslag f JOIN members m ON m.id = f.member_id
          WHERE f.status IN ('venter','godkjent')
          ORDER BY f.created_at"
    );
    $behandlet = DB::alle(
        "SELECT f.*, m.navn FROM medlemsforslag f JOIN members m ON m.id = f.member_id
          WHERE f.status IN ('publisert','avvist','galleri')
          ORDER BY f.behandlet_at DESC LIMIT 10"
    );
    // Bildene som staar i galleriet naa, og de som venter paa plass.
    $iGalleri = Galleri::klar() ? DB::alle(
        "SELECT f.*, m.navn FROM medlemsforslag f JOIN members m ON m.id = f.member_id
          WHERE f.galleri = 1 ORDER BY f.galleri_vist_fra IS NULL, f.galleri_vist_fra DESC, f.id DESC"
    ) : [];
    Svar::ok([
        'galleri'   => array_map($tilUt, $iGalleri),
        'paa'       => Medlemsforslag::paa(),
        'mal'       => Medlemsforslag::mal(),
        'venter'    => array_map($tilUt, $venter),
        'behandlet' => array_map($tilUt, $behandlet),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');

if ($handling === 'mal') {
    $tekst = trim(Foresporsel::tekst('tekst'));
    if ($tekst === '') {
        Svar::feil('Den faste teksten kan ikke være tom.');
    }
    DB::kjor(
        'INSERT INTO content_blocks (nokkel, verdi) VALUES (:n, :v)
         ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
        ['n' => 'Marked/Medlemsforslag mal', 'v' => mb_substr($tekst, 0, 300)]
    );
    revider('medlemsforslag_mal', null, null, ['tekst' => mb_substr($tekst, 0, 300)]);
    Svar::ok(['mal' => Medlemsforslag::mal()]);
}

$id = Foresporsel::heltall('id');
$rad = $hent($id);
if ($rad === null) {
    Svar::feil('Fant ikke forslaget.', 404);
}

if ($handling === 'avvis') {
    if (!in_array($rad['status'], ['venter', 'godkjent'], true)) {
        Svar::feil('Forslaget er alt behandlet.');
    }
    DB::kjor(
        "UPDATE medlemsforslag SET status = 'avvist', behandlet_av = :a, behandlet_at = UTC_TIMESTAMP()
          WHERE id = :i",
        ['a' => (int) $admin['id'], 'i' => $id]
    );
    // Fila trengs ikke lenger, og et avvist forslag skal ikke ligge igjen.
    Medlemsforslag::slettFil((string) $rad['fil']);
    revider('medlemsforslag_avvist', 'medlemsforslag', $id);
    Svar::ok(['beskjed' => 'Forslaget er avvist.']);
}

if ($handling === 'godkjenn') {
    if (!in_array($rad['status'], ['venter', 'godkjent'], true)) {
        Svar::feil('Forslaget er alt behandlet.');
    }
    if (Medlemsforslag::sti((string) $rad['fil']) === null) {
        Svar::feil('Fila til forslaget finnes ikke lenger.');
    }

    // Instagram, galleriet eller begge (eieren, 27. september 2026). Ingen
    // av dem er valgt paa forhaand i skjermen. En eldre skjerm som ikke
    // sender noen av feltene, godkjenner til Instagram som foer.
    $kropp = Foresporsel::kropp();
    $gammel = !array_key_exists('instagram', $kropp) && !array_key_exists('galleri', $kropp);
    $tilInsta = $gammel || Foresporsel::tekst('instagram') === '1';
    $tilGalleri = !$gammel && Foresporsel::tekst('galleri') === '1';
    if (!$tilInsta && !$tilGalleri) {
        Svar::feil('Velg Instagram, galleriet eller begge.');
    }
    if ($tilGalleri && ($rad['type'] !== 'bilde' || !Galleri::klar())) {
        Svar::feil(Galleri::klar() ? 'Bare bilder kan vises i galleriet.'
            : 'Migrasjon 225 er ikke kjørt. Trykk ⚙ Kjør oppdateringer.');
    }
    $galleriFelt = $tilGalleri
        ? ', galleri = 1, galleri_godkjent_at = COALESCE(galleri_godkjent_at, UTC_TIMESTAMP())' : '';

    // Bare galleriet: ingenting legges ut. Bildet faar plass paa forsida
    // naar det er ledig — se Galleri::fordel().
    if (!$tilInsta) {
        DB::kjor(
            "UPDATE medlemsforslag SET status = 'galleri', behandlet_av = :a, behandlet_at = UTC_TIMESTAMP(){$galleriFelt}
              WHERE id = :i",
            ['a' => (int) $admin['id'], 'i' => $id]
        );
        revider('medlemsforslag_galleri', 'medlemsforslag', $id);
        Svar::ok(['beskjed' => 'Lagt i galleriet.']);
    }

    $tekst = trim(Foresporsel::tekst('tekst'));
    if ($tekst === '') {
        $tekst = Medlemsforslag::bildetekst($rad, Medlemsforslag::fornavn((string) $rad['navn']));
    }

    // «godkjent» foerst: da aapner api/bilde.php fila, og Meta kan hente
    // den. Gaar publiseringen galt, settes den tilbake til «venter».
    DB::kjor(
        "UPDATE medlemsforslag SET status = 'godkjent', bildetekst = :t WHERE id = :i",
        ['t' => $tekst, 'i' => $id]
    );

    // En Reel hentes og kodes om hos Instagram; det kan ta et par minutter.
    @set_time_limit(300);

    $url = rtrim(Config::nettsted(), '/') . '/api/bilde.php?forslag=' . rawurlencode((string) $rad['fil']);
    try {
        $ut = Meta::publiserInstagram($url, $tekst);
    } catch (RuntimeException $e) {
        DB::kjor("UPDATE medlemsforslag SET status = 'venter' WHERE id = :i", ['i' => $id]);
        Svar::feil($e->getMessage());
    }

    DB::kjor(
        "UPDATE medlemsforslag
            SET status = 'publisert', lenke = :l, behandlet_av = :a, behandlet_at = UTC_TIMESTAMP(){$galleriFelt}
          WHERE id = :i",
        ['l' => (string) ($ut['lenke'] ?? ''), 'a' => (int) $admin['id'], 'i' => $id]
    );
    revider('medlemsforslag_publisert', 'medlemsforslag', $id, ['lenke' => (string) ($ut['lenke'] ?? '')]);
    Svar::ok(['beskjed' => $tilGalleri ? 'Lagt ut på Instagram og i galleriet.' : 'Lagt ut på Instagram.',
              'lenke'   => (string) ($ut['lenke'] ?? '')]);
}

// ── Ta et bilde ut av galleriet ──────────────────────────────────────────
// Det staar fortsatt paa Instagram hvis det ble lagt ut der. Plassen gaar
// til det som har ventet lengst (Galleri::fordel()).
if ($handling === 'ut-av-galleri') {
    if (!Galleri::klar()) {
        Svar::feil('Migrasjon 225 er ikke kjørt. Trykk ⚙ Kjør oppdateringer.');
    }
    DB::kjor('UPDATE medlemsforslag SET galleri = 0 WHERE id = :i', ['i' => $id]);
    revider('galleri_tatt_ut', 'medlemsforslag', $id);
    Svar::ok(['beskjed' => 'Tatt ut av galleriet.']);
}

// ── Bruk det forbedrede bildet ───────────────────────────────────────────
// «Forbedre bildet» (api/admin/gemini.php, handling=forbedreForslag) lager
// et nytt bilde i biblioteket. Admin ser foer og etter, og velger. Her
// byttes fila paa forslaget; medlemmets original blir staaende i
// «fil_original».
if ($handling === 'bruk-bilde') {
    if ($rad['type'] !== 'bilde' || $rad['status'] === 'avvist') {
        Svar::feil('Bildet kan ikke byttes på dette forslaget.');
    }
    if (preg_match('~^/?api/bilde\.php\?artikkel=([0-9a-f]{32}\.jpg)$~', Foresporsel::tekst('url'), $m) !== 1) {
        Svar::feil('Fant ikke det nye bildet.');
    }
    $fra = Bilder::sti($m[1], 'artikler');
    if ($fra === null) {
        Svar::feil('Fant ikke det nye bildet.');
    }
    $nyttNavn = bin2hex(random_bytes(16)) . '.jpg';
    if (!@copy($fra, Bilder::mappe('forslag') . '/' . $nyttNavn)) {
        Svar::feil('Fikk ikke lagret det nye bildet.');
    }
    DB::kjor(
        'UPDATE medlemsforslag SET fil_original = COALESCE(fil_original, fil), fil = :n WHERE id = :i',
        ['n' => $nyttNavn, 'i' => $id]
    );
    revider('medlemsforslag_bilde_forbedret', 'medlemsforslag', $id, ['ny' => $nyttNavn]);
    Svar::ok(['beskjed' => 'Det nye bildet er i bruk.',
              'fil' => '/api/bilde.php?forslag=' . rawurlencode($nyttNavn)]);
}

Svar::feil('Ukjent handling.');
