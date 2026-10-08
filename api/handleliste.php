<?php
/**
 * Handlelista paa Min side.
 *
 *   GET                      varene som kan bestilles, og lista mi
 *   POST handling=legg       { produktId }        legg til, eller +1
 *   POST handling=antall     { linjeId, antall }  0 = ta bort linja
 *   POST handling=linje      { leverandorId, artikkelnr, navn, antall }
 *   POST handling=send       send lista til verkstedet
 *   POST handling=leire      { produktId, antall } leira i neste bestilling (0 = ta bort)
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

/**
 * Leira (eieren 08.10.2026): leirene medlemmet kan bestille (leire og «Kan
 * bestilles», publisert), med bilde, pris og hvor mange hun har med i neste
 * bestilling, fristen, og statusen paa det som er bestilt: Bestilt, Kommet
 * eller Hentet (migrasjon 260). Hentet vises i 30 dager.
 */
$leire = static function () use ($klar, $paa, $medlemId): array {
    $tom = ['leire' => [], 'leireFrist' => ['dato' => '', 'tekst' => ''], 'leireStatus' => []];
    if (!$klar || !$paa || !DB::harKolonne('products', 'leire')) {
        return $tom;
    }
    $mine = [];
    foreach (DB::alle(
        "SELECT product_id, SUM(antall) AS antall FROM handleliste_linjer
          WHERE member_id = :m AND status = 'sendt' AND bestilt_at IS NULL AND product_id IS NOT NULL
          GROUP BY product_id",
        ['m' => $medlemId]
    ) as $r) {
        $mine[(int) $r['product_id']] = (int) $r['antall'];
    }
    $varer = DB::alle(
        "SELECT id, tittel, bilde, pris_ore FROM products
          WHERE leire = 1 AND kan_bestilles = 1 AND status = 'publisert'
          ORDER BY tittel"
    );
    $harStatus = Lager::harLeireStatus();
    $kol = $harStatus ? 'h.kommet_at, h.hentet_at' : 'NULL AS kommet_at, NULL AS hentet_at';
    $status = DB::alle(
        "SELECT h.antall, h.status, {$kol}, p.tittel
           FROM handleliste_linjer h
           JOIN products p ON p.id = h.product_id AND p.leire = 1
          WHERE h.member_id = :m AND h.status = 'ferdig' AND h.bestilt_at IS NOT NULL
            AND h.bestilt_at >= NOW() - INTERVAL 120 DAY"
            . ($harStatus ? ' AND (h.hentet_at IS NULL OR h.hentet_at >= NOW() - INTERVAL 30 DAY)' : '')
            . ' ORDER BY h.bestilt_at DESC, h.id',
        ['m' => $medlemId]
    );
    return [
        'leire' => array_map(static fn($v) => [
            'id'     => (int) $v['id'],
            'navn'   => (string) $v['tittel'],
            'bilde'  => Lager::bildeUrl($v['bilde'] ?? null),
            'pris'   => Booking::kroner((int) $v['pris_ore']),
            'antall' => $mine[(int) $v['id']] ?? 0,
        ], $varer),
        'leireFrist'  => Lager::leireFrist(),
        'leireStatus' => array_map(static fn($r) => [
            'navn'   => (string) $r['tittel'],
            'antall' => (int) $r['antall'],
            'status' => $r['hentet_at'] !== null ? 'hentet' : ($r['kommet_at'] !== null ? 'kommet' : 'bestilt'),
        ], $status),
    ];
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
    ] + $leire() + [
        // Andelen min av frakten fra Pakke-Express (migrasjon 237), naar
        // bestillingen er priset. Null naar det ikke er noe aa vise.
        'frakt' => (static function () use ($klar, $paa, $medlemId): ?string {
            if (!$klar || !$paa || !Frakt::klar()) {
                return null;
            }
            $ore = Frakt::andelFor($medlemId);
            if ($ore <= 0) {
                return null;
            }
            // Med oere naar det har oere, som i oppgjoret i admin.
            return $ore % 100 === 0 ? Booking::kroner($ore) : "kr.\u{a0}" . number_format($ore / 100, 2, ',', "\u{a0}");
        })(),
        // Leverandoerene admin har slaatt paa, med soeket i nettbutikken,
        // fraktsatsene og bestillingsrutinen (migrasjon 210).
        'leverandorer' => $harLev && $paa ? array_map(static function ($l) {
            $satser = [];
            foreach ((array) json_decode((string) $l['frakt_satser'], true) as $s) {
                if ((int) ($s['kg'] ?? 0) > 0) {
                    $satser[] = ['kg' => (int) $s['kg'], 'ore' => (int) ($s['ore'] ?? 0)];
                }
            }
            usort($satser, static fn($a, $b) => $a['kg'] <=> $b['kg']);
            return [
                'id'     => (int) $l['id'],
                'navn'   => (string) $l['navn'],
                'sok'    => (string) $l['sok_url'],
                'satser' => $satser,
                'rutine' => (string) $l['bestillingsrutine'],
            ];
        }, DB::alle('SELECT id, navn, sok_url, '
            . (DB::harKolonne('leverandorer', 'frakt_satser') ? 'frakt_satser, bestillingsrutine' : 'NULL AS frakt_satser, NULL AS bestillingsrutine')
            . ' FROM leverandorer WHERE vis_medlemmer = 1 AND aktiv = 1 ORDER BY navn')) : [],
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

// Leira (eieren 08.10.2026): antallet medlemmet velger gaar rett inn i neste
// bestilling — linja er «sendt» med varens pris, saa den staar i samlebestillingen
// i admin og faar Vipps-kravet som de andre. 0 tar den bort. Er kravet sendt,
// eller bestillingen gaatt til leverandoeren, endres den ikke herfra.
if ($handling === 'leire') {
    if (!DB::harKolonne('products', 'leire')) {
        Svar::feil('Leira krever oppdatering av databasen først.');
    }
    $produktId = Foresporsel::heltall('produktId');
    $antall = max(0, min(99, Foresporsel::heltall('antall')));
    $vare = DB::en(
        "SELECT id, pris_ore FROM products
          WHERE id = :p AND leire = 1 AND kan_bestilles = 1 AND status = 'publisert'",
        ['p' => $produktId]
    );
    if ($vare === null) {
        Svar::feil('Denne leira kan ikke bestilles.');
    }
    $linje = DB::en(
        "SELECT id, order_id FROM handleliste_linjer
          WHERE member_id = :m AND product_id = :p AND status = 'sendt' AND bestilt_at IS NULL
          ORDER BY order_id IS NULL, id LIMIT 1",
        ['m' => $medlemId, 'p' => $produktId]
    );
    if ($linje !== null && $linje['order_id'] !== null) {
        Svar::feil('Leira er allerede krevd inn. Ta kontakt med verkstedet for å endre.');
    }
    if ($linje === null && $antall > 0) {
        DB::settInn('handleliste_linjer', [
            'member_id'  => $medlemId,
            'product_id' => $produktId,
            'antall'     => $antall,
            'status'     => 'sendt',
            'pris_ore'   => (int) $vare['pris_ore'],
            'sendt_at'   => gmdate('Y-m-d H:i:s'),
        ]);
    } elseif ($linje !== null && $antall > 0) {
        DB::oppdater('handleliste_linjer', ['antall' => $antall, 'pris_ore' => (int) $vare['pris_ore']], ['id' => (int) $linje['id']]);
    } elseif ($linje !== null) {
        DB::kjor('DELETE FROM handleliste_linjer WHERE id = :l AND order_id IS NULL', ['l' => (int) $linje['id']]);
    }
    revider('leire_valgt', 'product', $produktId, ['medlem' => $medlemId, 'antall' => $antall]);
    Svar::ok(['mine' => $mine()] + $leire());
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
