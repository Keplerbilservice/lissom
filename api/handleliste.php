<?php
/**
 * Handlelista paa Min side.
 *
 *   GET                      varene som kan bestilles, og lista mi
 *   POST handling=legg       { produktId }        legg til, eller +1
 *   POST handling=antall     { linjeId, antall }  0 = ta bort linja
 *   POST handling=send       send lista til verkstedet
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

/** Lista mi, slik den staar naa. */
$mine = static function () use ($klar, $medlemId): array {
    if (!$klar) {
        return [];
    }
    $rader = DB::alle(
        "SELECT h.id, h.antall, p.tittel, p.artikkelnr
           FROM handleliste_linjer h
           JOIN products p ON p.id = h.product_id
          WHERE h.member_id = :m AND h.status = 'apen'
          ORDER BY h.id",
        ['m' => $medlemId]
    );
    return array_map(static fn($r) => [
        'id'     => (int) $r['id'],
        'navn'   => (string) $r['tittel'],
        'nummer' => (string) $r['artikkelnr'],
        'antall' => (int) $r['antall'],
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
