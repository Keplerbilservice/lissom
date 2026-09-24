<?php
/**
 * Handlelista paa Min side.
 *
 *   GET                      varene som kan bestilles, og lista mi
 *   POST handling=legg       { produktId }        legg til, eller +1
 *   POST handling=antall     { linjeId, antall }  0 = ta bort linja
 *   POST handling=linje      { leverandorId, artikkelnr, navn, antall }
 *   POST handling=send       send lista til verkstedet
 *
 * «linje» er en vare medlemmet har funnet selv i nettbutikken til en av
 * leverandoerene admin har slaatt paa (leverandorer.vis_medlemmer). Eieren,
 * 24. september 2026: «egne felt, antall, artikkelnummer, varenavn».
 *
 * Eieren, 13. september 2026: «medlemmene maa kunne samle opp og trykk send».
 * Lista blir liggende aapen paa tvers av dager til medlemmet sender den; da
 * gaar linjene over til «sendt» og dukker opp samlet i admin.
 *
 * Dette er ikke internbutikkens kurv. Kurven selger det som ligger paa lager,
 * med betaling i kassa med en gang. Her staar ingen pris: den settes av
 * verkstedet naar bestillingen gjores, og kreves inn etterpaa.
 *
 * Alt svarer med det samme bildet som GET, saa skjermen alltid viser det som
 * staar i basen.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem = krev_aktivt_medlem();
$medlemId = (int) $medlem['id'];

// Foer migrasjon 184 er kjoert finnes ikke tabellen. Da er det ingen liste aa
// vise, og kortet staar tomt framfor aa feile.
$klar = DB::harTabell('handleliste_linjer') && DB::harKolonne('products', 'artikkelnr');

// Slaatt av av verkstedet? Da finnes ikke kortet for medlemmet.
$paa = (string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/handleliste'") !== 'nei';

// Leverandoer og artikkelnummer paa linja selv (migrasjon 208). Foer den er
// kjoert, er lista som foer: bare varer og oensker.
$harLev = $klar && DB::harKolonne('handleliste_linjer', 'leverandor_id')
    && DB::harKolonne('leverandorer', 'vis_medlemmer');

/** Lista mi, slik den staar naa. */
$mine = static function () use ($klar, $harLev, $medlemId): array {
    if (!$klar) {
        return [];
    }
    $rader = DB::alle(
        $harLev
            ? "SELECT h.id, h.antall, COALESCE(p.tittel, h.tekst) AS tittel,
                      COALESCE(NULLIF(p.artikkelnr, ''), h.artikkelnr) AS artikkelnr,
                      COALESCE(lp.navn, lh.navn, '') AS leverandor,
                      h.product_id IS NULL AS onske
                 FROM handleliste_linjer h
            LEFT JOIN products p ON p.id = h.product_id
            LEFT JOIN leverandorer lp ON lp.id = p.leverandor_id
            LEFT JOIN leverandorer lh ON lh.id = h.leverandor_id
                WHERE h.member_id = :m AND h.status = 'apen'
                ORDER BY h.id"
            : "SELECT h.id, h.antall, COALESCE(p.tittel, h.tekst) AS tittel, COALESCE(p.artikkelnr, '') AS artikkelnr,
                      '' AS leverandor, h.product_id IS NULL AS onske
                 FROM handleliste_linjer h
            LEFT JOIN products p ON p.id = h.product_id
                WHERE h.member_id = :m AND h.status = 'apen'
                ORDER BY h.id",
        ['m' => $medlemId]
    );
    return array_map(static fn($r) => [
        'id'     => (int) $r['id'],
        'navn'   => (string) $r['tittel'],
        'nummer' => (string) $r['artikkelnr'],
        'leverandor' => (string) $r['leverandor'],
        'antall' => (int) $r['antall'],
        // Skrevet av medlemmet selv, ikke en vare (migrasjon 191).
        'onske'  => (bool) $r['onske'],
    ], $rader);
};

if (Foresporsel::metode() === 'GET') {
    $varer = $klar && $paa ? DB::alle(
        "SELECT id, tittel, artikkelnr FROM products
          WHERE kan_bestilles = 1 AND status = 'publisert'
          ORDER BY tittel"
    ) : [];
    Svar::json([
        'paa'   => $klar && $paa,
        'varer' => array_map(static fn($v) => [
            'id'     => (int) $v['id'],
            'navn'   => (string) $v['tittel'],
            'nummer' => (string) $v['artikkelnr'],
        ], $varer),
        'mine'  => $mine(),
        // Leverandoerene admin har slaatt paa, med soeket i nettbutikken.
        'leverandorer' => $harLev && $paa ? array_map(static fn($l) => [
            'id'   => (int) $l['id'],
            'navn' => (string) $l['navn'],
            'sok'  => (string) $l['sok_url'],
        ], DB::alle('SELECT id, navn, sok_url FROM leverandorer WHERE vis_medlemmer = 1 AND aktiv = 1 ORDER BY navn')) : [],
        // Gebyrsatsen, saa medlemmet ser hva som kommer i tillegg. Samme
        // innstilling som admin setter under Handlelister.
        'gebyr' => (static function (): float {
            $p = (float) str_replace(',', '.', (string) DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'handleliste_gebyr_prosent'"));
            return $p > 0 && $p <= 100 ? $p : 0.0;
        })(),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (!$klar) {
    Svar::feil('Handlelista er ikke satt opp ennå. Kjør oppdateringen av databasen først.');
}
if (!$paa) {
    Svar::feil('Handlelista er ikke åpen nå. Ta kontakt med verkstedet.', 403);
}

$handling = Foresporsel::tekst('handling');

// Et oenske i fritekst. Eieren, 15. september 2026: «medlemmene skal legge
// inn oensker her». Ingen vare bak — teksten er hele linja.
if ($handling === 'onske') {
    $tekst = trim(mb_substr(Foresporsel::tekst('tekst'), 0, 191));
    if (mb_strlen($tekst) < 2) {
        Svar::feil('Skriv hva du ønsker deg — for eksempel «hvit steingods, 10 kg».');
    }
    if (!DB::harKolonne('handleliste_linjer', 'tekst')) {
        Svar::feil('Ønsker i fritekst krever oppdatering 191. Kjør oppdateringen først.');
    }
    DB::settInn('handleliste_linjer', ['member_id' => $medlemId, 'tekst' => $tekst]);
    Svar::ok(['mine' => $mine()]);
}

// En vare medlemmet har funnet selv hos en av leverandoerene. Eieren, 24.
// september 2026: «egne felt, antall, artikkelnummer, varenavn».
if ($handling === 'linje') {
    if (!$harLev) {
        Svar::feil('Handlelista må oppdateres først. Kjør oppdateringen av databasen.');
    }
    $lev = DB::en(
        'SELECT id FROM leverandorer WHERE id = :l AND vis_medlemmer = 1 AND aktiv = 1',
        ['l' => Foresporsel::heltall('leverandorId')]
    );
    if ($lev === null) {
        Svar::feil('Velg en leverandør.');
    }
    $navn = trim(mb_substr(Foresporsel::tekst('navn'), 0, 191));
    if (mb_strlen($navn) < 2) {
        Svar::feil('Skriv varenavnet.');
    }
    $nummer = trim(mb_substr(Foresporsel::tekst('artikkelnr'), 0, 64));
    $antall = max(1, min(99, Foresporsel::heltall('antall') ?: 1));
    DB::settInn('handleliste_linjer', [
        'member_id'     => $medlemId,
        'tekst'         => $navn,
        'leverandor_id' => (int) $lev['id'],
        'artikkelnr'    => $nummer,
        'antall'        => $antall,
    ]);
    Svar::ok(['mine' => $mine()]);
}

if ($handling === 'legg') {
    $produktId = Foresporsel::heltall('produktId');
    $vare = DB::en(
        "SELECT id FROM products WHERE id = :p AND kan_bestilles = 1 AND status = 'publisert'",
        ['p' => $produktId]
    );
    if ($vare === null) {
        Svar::feil('Denne varen kan ikke bestilles.');
    }
    // Samme vare to ganger blir én linje med hoyere antall, ikke to like
    // linjer under hverandre.
    $fra_for = DB::en(
        "SELECT id, antall FROM handleliste_linjer
          WHERE member_id = :m AND product_id = :p AND status = 'apen'",
        ['m' => $medlemId, 'p' => $produktId]
    );
    if ($fra_for !== null) {
        DB::oppdater('handleliste_linjer', ['antall' => min(99, (int) $fra_for['antall'] + 1)], ['id' => (int) $fra_for['id']]);
    } else {
        DB::settInn('handleliste_linjer', ['member_id' => $medlemId, 'product_id' => $produktId]);
    }
    Svar::ok(['mine' => $mine()]);
}

if ($handling === 'antall') {
    $linjeId = Foresporsel::heltall('linjeId');
    $antall  = Foresporsel::heltall('antall');
    $linje = DB::en(
        "SELECT id FROM handleliste_linjer WHERE id = :l AND member_id = :m AND status = 'apen'",
        ['l' => $linjeId, 'm' => $medlemId]
    );
    if ($linje === null) {
        Svar::feil('Fant ikke linja.');
    }
    if ($antall <= 0) {
        DB::kjor('DELETE FROM handleliste_linjer WHERE id = :l', ['l' => $linjeId]);
    } else {
        DB::oppdater('handleliste_linjer', ['antall' => min(99, $antall)], ['id' => $linjeId]);
    }
    Svar::ok(['mine' => $mine()]);
}

if ($handling === 'send') {
    $antallLinjer = (int) DB::verdi(
        "SELECT COUNT(*) FROM handleliste_linjer WHERE member_id = :m AND status = 'apen'",
        ['m' => $medlemId]
    );
    if ($antallLinjer === 0) {
        Svar::feil('Lista er tom.');
    }
    DB::kjor(
        "UPDATE handleliste_linjer SET status = 'sendt', sendt_at = NOW()
          WHERE member_id = :m AND status = 'apen'",
        ['m' => $medlemId]
    );
    revider('handleliste_sendt', 'member', $medlemId, ['linjer' => $antallLinjer]);
    Svar::ok(['mine' => $mine(), 'sendt' => $antallLinjer]);
}

Svar::feil('Ukjent handling.');
