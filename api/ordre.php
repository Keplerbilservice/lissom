<?php
/**
 * Butikkjop.
 *
 * Nettleseren sender hvilke varer og hvor mange — aldri hva de koster.
 * Summen regnes ut fra prisene i databasen, ellers kunne hvem som helst
 * endret belopet i utviklerverktoyet for betaling.
 *
 * Varene hentes i verkstedet. Ingen forsendelse.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
Rate::sjekk('ordre', maks: 10, vindu: 600);

$linjer = Foresporsel::kropp()['linjer'] ?? null;
if (!is_array($linjer) || $linjer === []) {
    Svar::feil('Handlekurven er tom.');
}
if (count($linjer) > 50) {
    Svar::feil('For mange varer i én bestilling.');
}

$medlem = Sesjon::medlem();
$navn    = mb_substr(Foresporsel::tekst('navn'), 0, 191);
$epost   = mb_substr(Foresporsel::tekst('epost'), 0, 191);
$telefon = normaliser_telefon(Foresporsel::tekst('telefon'));

if ($medlem !== null) {
    $navn    = $navn !== '' ? $navn : (string) $medlem['navn'];
    $epost   = $epost !== '' ? $epost : (string) ($medlem['epost'] ?? '');
    $telefon = $telefon !== '' ? $telefon : normaliser_telefon((string) ($medlem['telefon'] ?? ''));
}

if ($navn === '') {
    Svar::feil('Vi trenger navnet ditt.');
}
if ($epost === '' || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
    Svar::feil('Vi trenger en gyldig e-postadresse — kvitteringen sendes dit.');
}

// --- Sett sammen ordren fra databasens priser ----------------------------
$rader = [];
$sum = 0;

foreach ($linjer as $l) {
    $id = (int) ($l['id'] ?? 0);
    $antall = max(1, min(50, (int) ($l['antall'] ?? 1)));

    $vare = DB::en(
        "SELECT id, tittel, pris_ore, lager, kun_medlemmer
           FROM products WHERE id = :i AND status = 'publisert'",
        ['i' => $id]
    );
    if ($vare === null) {
        Svar::feil('En av varene finnes ikke lenger. Last siden på nytt.', 409);
    }
    // «Kun for medlemmer» betyr godkjent medlem — ikke bare innlogget.
    if ((int) $vare['kun_medlemmer'] === 1 && ($medlem === null || !er_aktivt_medlem($medlem))) {
        Svar::feil('En av varene er kun for medlemmer.', 403);
    }
    if ($vare['lager'] !== null && (int) $vare['lager'] < $antall) {
        Svar::feil('Vi har ikke nok igjen av «' . $vare['tittel'] . '».', 409);
    }

    $sum += (int) $vare['pris_ore'] * $antall;
    $rader[] = ['vare' => $vare, 'antall' => $antall];
}

if ($sum <= 0) {
    Svar::feil('Bestillingen har ingen sum.');
}

$referanse = Vipps::nyReferanse('LIS');
$ordrenr = 'B-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

// Ordren lagres for vi sender kunden til Vipps, slik at webhooken alltid har
// noe aa slaa opp i.
$opprettet = DB::iTransaksjon(static function () use ($rader, $sum, $navn, $epost, $telefon, $medlem, $referanse, $ordrenr): array {
    $paymentId = DB::settInn('payments', [
        'vipps_reference' => $referanse,
        'type'            => 'epayment',
        'formal'          => 'ordre',
        'member_id'       => $medlem['id'] ?? null,
        'belop_ore'       => $sum,
        'status'          => 'opprettet',
        'idempotency_key' => Vipps::uuid(),
    ]);

    $ordreId = DB::settInn('orders', [
        'ordrenr'       => $ordrenr,
        'member_id'     => $medlem['id'] ?? null,
        'kunde_navn'    => $navn,
        'kunde_epost'   => $epost,
        'kunde_telefon' => $telefon !== '' ? $telefon : null,
        'sum_ore'       => $sum,
        'status'        => 'ny',
        'payment_id'    => $paymentId,
    ]);

    foreach ($rader as $r) {
        DB::settInn('order_lines', [
            'order_id'   => $ordreId,
            'product_id' => $r['vare']['id'],
            // Tittelen kopieres inn: varen kan endre navn senere, men
            // kvitteringen skal vise hva kunden faktisk kjopte.
            'tittel'     => $r['vare']['tittel'],
            'antall'     => $r['antall'],
            'pris_ore'   => (int) $r['vare']['pris_ore'],
        ]);
    }

    return ['ordreId' => $ordreId, 'paymentId' => $paymentId];
});

try {
    $betaling = Vipps::opprettBetaling(
        $referanse,
        $sum,
        'Lissom — bestilling ' . $ordrenr,
        Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
        $telefon
    );
} catch (Throwable $e) {
    DB::oppdater('payments', ['status' => 'feilet'], ['id' => $opprettet['paymentId']]);
    DB::oppdater('orders', ['status' => 'kansellert'], ['id' => $opprettet['ordreId']]);
    logg_feil('Kunne ikke starte betaling for ordre ' . $ordrenr, $e);
    Svar::feil('Fikk ikke startet betalingen. Prøv igjen om litt.', 502);
}

DB::oppdater('payments', ['status' => 'venter'], ['id' => $opprettet['paymentId']]);
revider('ordre_opprettet', 'order', $opprettet['ordreId'], ['sum_ore' => $sum]);

Svar::ok([
    'url'       => $betaling['url'],
    'referanse' => $referanse,
    'ordrenr'   => $ordrenr,
    'sum'       => Booking::kroner($sum),
]);
