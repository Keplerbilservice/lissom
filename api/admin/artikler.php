<?php
/**
 * Kunnskapsbanken — artiklene og guidene.
 *
 *   GET                    alle artikler, med kategoriene som finnes
 *   POST handling=lagre    ny eller endret artikkel
 *   POST handling=status   publiser eller sett tilbake til kladd
 *   POST handling=bilde    bytt bildet paa en artikkel
 *   POST handling=slett    fjern en artikkel
 *
 * Artiklene laa fra for under Nyttig info. De samme radene brukes her; det
 * som er nytt er kategori, adresse og hvem som skrev dem. En artikkel som er
 * publisert vises baade under Nyttig info og under Nyheter.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

/** Lager en adresse av tittelen, og passer paa at to ikke blir like. */
$lagSlug = static function (string $tittel, ?int $unntaId = null): string {
    $s = mb_strtolower(trim($tittel));
    $s = strtr($s, ['æ' => 'ae', 'ø' => 'o', 'å' => 'a', 'Æ' => 'ae', 'Ø' => 'o', 'Å' => 'a']);
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s) ?? '', '-');
    if ($s === '') {
        $s = 'artikkel';
    }
    $grunn = $s;
    $n = 2;
    while (true) {
        $rad = DB::en('SELECT id FROM articles WHERE slug = :s', ['s' => $s]);
        if ($rad === null || ($unntaId !== null && (int) $rad['id'] === $unntaId)) {
            return $s;
        }
        $s = $grunn . '-' . $n++;
    }
};

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle('SELECT * FROM articles ORDER BY sortering, id DESC');
    Svar::json([
        'artikler' => array_map(static fn($a) => [
            'id'        => (int) $a['id'],
            'tittel'    => $a['tittel'],
            'kategori'  => $a['kategori'],
            'slug'      => $a['slug'],
            'fokusord'  => $a['fokus_ord'],
            'ingress'   => $a['ingress'],
            'innhold'   => $a['innhold'],
            'bilde'     => $a['bilde'],
            'status'    => $a['status'],
            'kilde'     => $a['kilde'],
            'dato'      => $a['dato'],
            'ord'       => str_word_count(strip_tags((string) $a['innhold'])),
            'endret'    => Booking::norskDatoKort((string) $a['updated_at']),
        ], $rader),
        // Kategoriene er ikke en fast liste — de vokser med det som skrives.
        'kategorier' => array_values(array_filter(array_unique(
            array_column($rader, 'kategori')
        ), static fn($k) => trim((string) $k) !== '')),
        'forslag' => ['Kurs', 'Medlemskap', 'Keramikk', 'Arrangement', 'Gavekort', 'Bedrift'],
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$kropp = Foresporsel::kropp();

switch (Foresporsel::tekst('handling')) {

    case 'lagre':
        $id     = (int) ($kropp['id'] ?? 0);
        $tittel = trim(mb_substr((string) ($kropp['tittel'] ?? ''), 0, 191));
        if ($tittel === '') {
            Svar::feil('Artikkelen må ha en tittel.');
        }
        $innhold = trim((string) ($kropp['innhold'] ?? ''));
        if (mb_strlen($innhold) > 100000) {
            Svar::feil('Artikkelen er for lang.');
        }

        $felter = [
            'tittel'    => $tittel,
            'kategori'  => mb_substr(trim((string) ($kropp['kategori'] ?? '')), 0, 64) ?: null,
            'fokus_ord' => mb_substr(trim((string) ($kropp['fokusord'] ?? '')), 0, 191) ?: null,
            'ingress'   => trim((string) ($kropp['ingress'] ?? '')) ?: null,
            'innhold'   => $innhold,
            'bilde'     => mb_substr(trim((string) ($kropp['bilde'] ?? '')), 0, 255) ?: null,
        ];

        if ($id > 0) {
            if (DB::en('SELECT id FROM articles WHERE id = :i', ['i' => $id]) === null) {
                Svar::feil('Fant ikke artikkelen.');
            }
            // Adressen foelger tittelen, men bare til artikkelen er publisert.
            // Etter det ville en ny adresse brutt lenker folk alt har delt.
            $naa = DB::en('SELECT status, slug FROM articles WHERE id = :i', ['i' => $id]);
            if ($naa['status'] !== 'publisert' || trim((string) $naa['slug']) === '') {
                $felter['slug'] = $lagSlug($tittel, $id);
            }
            DB::oppdater('articles', $felter, ['id' => $id]);
        } else {
            $felter['slug']   = $lagSlug($tittel);
            $felter['kilde']  = 'manuell';
            $felter['status'] = 'kladd';
            $id = DB::settInn('articles', $felter);
        }

        revider('artikkel_lagret', 'article', $id, ['tittel' => $tittel]);
        Svar::ok(['beskjed' => 'Artikkelen er lagret.', 'id' => $id,
                  'slug' => $felter['slug'] ?? null]);

    // Bildet for seg. «lagre» krever tittel og tekst, og aa sende hele
    // artikkelen fram og tilbake bare for aa bytte bilde er en unodig sjanse
    // til aa skrive over noe som ble endret imens.
    case 'bilde':
        $id = (int) ($kropp['id'] ?? 0);
        if (DB::en('SELECT id FROM articles WHERE id = :i', ['i' => $id]) === null) {
            Svar::feil('Fant ikke artikkelen.');
        }
        $bilde = mb_substr(trim((string) ($kropp['bilde'] ?? '')), 0, 255);
        // Tomt betyr «ingen egen» — da faller artikkelen tilbake paa
        // standardbildet, ikke paa en tom ramme.
        DB::oppdater('articles', ['bilde' => $bilde !== '' ? $bilde : null], ['id' => $id]);
        revider('artikkel_bilde', 'article', $id, ['bilde' => $bilde]);
        Svar::ok(['beskjed' => $bilde !== '' ? 'Bildet er byttet.' : 'Bildet er fjernet.']);

    case 'status':
        $id = (int) ($kropp['id'] ?? 0);
        $ny = (string) ($kropp['status'] ?? '');
        if (!in_array($ny, ['kladd', 'publisert'], true)) {
            Svar::feil('Ukjent status.');
        }
        $a = DB::en('SELECT id, tittel, slug, innhold FROM articles WHERE id = :i', ['i' => $id]);
        if ($a === null) {
            Svar::feil('Fant ikke artikkelen.');
        }
        // En tom artikkel skal ikke ut. Da staar det en overskrift paa
        // nettsida med ingenting under.
        if ($ny === 'publisert' && trim((string) $a['innhold']) === '') {
            Svar::feil('Artikkelen har ingen tekst ennå.');
        }
        if ($ny === 'publisert' && trim((string) $a['slug']) === '') {
            DB::oppdater('articles', ['slug' => $lagSlug((string) $a['tittel'], $id)], ['id' => $id]);
        }
        DB::oppdater('articles', ['status' => $ny], ['id' => $id]);
        revider('artikkel_status', 'article', $id, ['status' => $ny]);
        Svar::ok(['beskjed' => $ny === 'publisert'
            ? 'Artikkelen er ute på nettsiden.'
            : 'Artikkelen er satt tilbake til kladd.']);

    case 'slett':
        $id = (int) ($kropp['id'] ?? 0);
        if (DB::en('SELECT id FROM articles WHERE id = :i', ['i' => $id]) === null) {
            Svar::feil('Fant ikke artikkelen.');
        }
        DB::kjor('DELETE FROM articles WHERE id = :i', ['i' => $id]);
        revider('artikkel_slettet', 'article', $id);
        Svar::ok(['beskjed' => 'Artikkelen er slettet.']);

    default:
        Svar::feil('Ukjent handling.');
}
