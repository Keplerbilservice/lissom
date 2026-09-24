<?php
/**
 * Medlemsforslag til Instagram — verkstedets side.
 *
 *   GET                                  forslagene som venter, de siste
 *                                        behandlede, og malen
 *   POST handling=godkjenn { id, tekst? } legg ut paa @lissom_keramikk
 *   POST handling=avvis    { id }         ta det bort
 *   POST handling=mal      { tekst }      den faste linja i innlegget
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
          WHERE f.status IN ('publisert','avvist')
          ORDER BY f.behandlet_at DESC LIMIT 10"
    );
    Svar::ok([
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
            SET status = 'publisert', lenke = :l, behandlet_av = :a, behandlet_at = UTC_TIMESTAMP()
          WHERE id = :i",
        ['l' => (string) ($ut['lenke'] ?? ''), 'a' => (int) $admin['id'], 'i' => $id]
    );
    revider('medlemsforslag_publisert', 'medlemsforslag', $id, ['lenke' => (string) ($ut['lenke'] ?? '')]);
    Svar::ok(['beskjed' => 'Lagt ut på Instagram.', 'lenke' => (string) ($ut['lenke'] ?? '')]);
}

Svar::feil('Ukjent handling.');
