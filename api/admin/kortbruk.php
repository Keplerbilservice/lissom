<?php
/**
 * Hva admin bruker dashboardet til.
 *
 *   GET                  mine tall: { kort: { antall, dagerSiden } }
 *   POST handling=bruk   { kort }   teller ett trykk
 *   POST handling=null   nullstill alt jeg har talt
 *
 * Eieren, 15. september 2026: «kan dashboard ogsaa laere? At det viser de
 * knappene her som brukes mest i hele admin?»
 *
 * Her telles bare. Rekkefoelgen avgjoeres paa skjermen, som ogsaa vet hvilke
 * kort som har et varsel akkurat naa — og de skal staa foerst uansett hva
 * tellingen sier.
 *
 * Tallet er per admin. Monica og eieren jobber ulikt og skal ikke dra
 * hverandres rekkefoelge med seg. At det ligger i basen og ikke i nettleseren
 * er det som gjor at telefonen og PC-en laerer det samme.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();
$medlemId = (int) $admin['id'];

// Foer migrasjon 195 er kjoert finnes ikke tabellen. Da teller ingenting, og
// dashboardet staar i sin egen faste rekkefoelge — ingen feil, bare uvitende.
$klar = DB::harTabell('admin_kortbruk');

/** Mine tall, slik skjermen trenger dem. */
$mine = static function () use ($klar, $medlemId): array {
    if (!$klar) {
        return [];
    }
    $ut = [];
    foreach (DB::alle(
        'SELECT kort, antall, TIMESTAMPDIFF(DAY, sist_brukt, NOW()) AS dager
           FROM admin_kortbruk WHERE member_id = :m',
        ['m' => $medlemId]
    ) as $r) {
        $ut[(string) $r['kort']] = [
            'antall'     => (int) $r['antall'],
            'dagerSiden' => max(0, (int) $r['dager']),
        ];
    }
    return $ut;
};

if (Foresporsel::metode() === 'GET') {
    Svar::json(['klar' => $klar, 'bruk' => $mine()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (!$klar) {
    // Ikke en feil verdt aa stoppe skjermen for: kortet ble trykket, og det
    // virket. Det er bare tellingen som ikke finnes ennaa.
    Svar::ok(['klar' => false, 'bruk' => []]);
}

$handling = Foresporsel::tekst('handling');

if ($handling === 'bruk') {
    $kort = mb_substr(trim(Foresporsel::tekst('kort')), 0, 64);
    // Noekkelen kommer fra skjermen. Tomt eller rart lagres ikke — da ville
    // tabellen fylles med rader ingen kan kjenne igjen.
    if ($kort === '' || !preg_match('/^[a-z0-9_]+$/', $kort)) {
        Svar::feil('Ukjent kort.');
    }
    DB::kjor(
        'INSERT INTO admin_kortbruk (member_id, kort, antall, sist_brukt)
              VALUES (:m, :k, 1, NOW())
         ON DUPLICATE KEY UPDATE antall = antall + 1, sist_brukt = NOW()',
        ['m' => $medlemId, 'k' => $kort]
    );
    Svar::ok(['bruk' => $mine()]);
}

if ($handling === 'null') {
    DB::kjor('DELETE FROM admin_kortbruk WHERE member_id = :m', ['m' => $medlemId]);
    revider('dashboard_nullstilt', 'member', $medlemId);
    Svar::ok(['bruk' => []]);
}

Svar::feil('Ukjent handling.');
