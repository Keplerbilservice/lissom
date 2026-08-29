<?php
/**
 * Betal i verkstedet.
 *
 * Bak den faste QR-koden som henger ved disken. Kunden skanner, kommer hit,
 * velger hva det gjelder, skriver beloepet og betaler med Vipps.
 *
 *   POST { slag, belop, navn? }  ->  { url }
 *
 * ── Hvorfor beloepet kommer fra nettleseren ───────────────────────────
 *
 * Overalt ellers regnes summen ut av databasen, aldri av det som sendes inn
 * — se api/ordre.php. Det er fordi kunden der betaler for noe med en fast
 * pris, og den prisen skal ikke kunne settes ned i utviklerverktoyet.
 *
 * Her er det motsatt: det finnes ingen pris aa jukse med. Kunden bestemmer
 * selv hva hun skal betale — det er en kasse, ikke en handlekurv. Verste
 * tilfelle er at noen lager betalinger som aldri fullfores, og mot det
 * hjelper en grense per IP, ikke en pris fra basen.
 *
 * ── Hvorfor ikke Vipps sin egen faste QR ─────────────────────────────
 *
 * Vipps-portalen kan lage en fast kode, men den vil ha en landingsside med
 * Hurtigkasse. Hurtigkasse er Vipps Checkout, et annet produkt enn ePayment
 * som nettsida bruker. Denne sida gjor det samme med det vi alt har.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
Rate::sjekk('betal', maks: 10, vindu: 600);

const BETAL_SLAG = [
    'kurs'       => ['formal' => 'booking',    'tittel' => 'Kurs — betalt i verkstedet'],
    'medlemskap' => ['formal' => 'medlemskap', 'tittel' => 'Medlemskap — betalt i verkstedet'],
    'produkt'    => ['formal' => 'ordre',      'tittel' => 'Produkt — betalt i verkstedet'],
];

$kropp = Foresporsel::kropp();

$slag = (string) ($kropp['slag'] ?? 'produkt');
if (!isset(BETAL_SLAG[$slag])) {
    Svar::feil('Velg om det er kurs, medlemskap eller noe fra butikken.');
}

// «450», «450,50», «kr. 450,-» — folk skriver det de skriver.
$raa = str_replace([' ', "\u{a0}", 'kr', '.', ',-'], '', (string) ($kropp['belop'] ?? ''));
$raa = str_replace(',', '.', trim($raa));
if ($raa === '' || !is_numeric($raa)) {
    Svar::feil('Skriv inn beløpet du skal betale.');
}
$sum = (int) round((float) $raa * 100);
if ($sum < Vipps::MINSTE_BELOP_ORE) {
    Svar::feil('Beløpet må være minst én krone.');
}
if ($sum > 10000000) {
    Svar::feil('Beløpet må være under 100 000 kroner. Ta kontakt, så ordner vi det.');
}

$navn = mb_substr(trim((string) ($kropp['navn'] ?? '')), 0, 191);

$ordrenr   = 'B-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
$referanse = Vipps::nyReferanse('BET');
$formal    = BETAL_SLAG[$slag]['formal'];
$tittel    = BETAL_SLAG[$slag]['tittel'];

$ordreId = DB::iTransaksjon(static function () use ($sum, $navn, $ordrenr, $formal, $tittel, $referanse): int {
    $betalingId = DB::settInn('payments', [
        'vipps_reference' => $referanse,
        'type'            => 'epayment',
        'formal'          => $formal,
        'belop_ore'       => $sum,
        'status'          => 'opprettet',
        'idempotency_key' => Vipps::uuid(),
    ]);
    // «ny» til pengene er inne. Booking::markerBetalt() setter den til
    // «betalt» naar webhooken kommer — samme vei som alt annet.
    $id = DB::settInn('orders', [
        'ordrenr'      => $ordrenr,
        'kunde_navn'   => $navn !== '' ? $navn : 'Betalt med QR',
        'sum_ore'      => $sum,
        'status'       => 'ny',
        'betalt_maate' => 'Vipps',
        'payment_id'   => $betalingId,
    ]);
    DB::settInn('order_lines', [
        'order_id' => $id, 'product_id' => null,
        'tittel' => $tittel, 'antall' => 1, 'pris_ore' => $sum,
    ]);
    return $id;
});

try {
    $svar = Vipps::opprettBetaling(
        $referanse,
        $sum,
        $tittel . ' — Lissom Keramikk',
        Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse)
    );
} catch (Throwable $e) {
    // Ingen betaling, ingen ordre. Ellers staar det igjen noe som ser ut som
    // om noen skylder oss penger.
    DB::kjor('DELETE FROM order_lines WHERE order_id = :o', ['o' => $ordreId]);
    $pid = DB::verdi('SELECT payment_id FROM orders WHERE id = :o', ['o' => $ordreId]);
    DB::kjor('DELETE FROM orders WHERE id = :o', ['o' => $ordreId]);
    if ($pid) { DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pid]); }
    logg_feil('Fikk ikke startet betaling fra /betal for ordre ' . $ordrenr, $e);
    Svar::feil('Fikk ikke startet betalingen. ' . $e->getMessage());
}

$url = trim((string) ($svar['url'] ?? ''));
if ($url === '') {
    DB::kjor('DELETE FROM order_lines WHERE order_id = :o', ['o' => $ordreId]);
    $pid = DB::verdi('SELECT payment_id FROM orders WHERE id = :o', ['o' => $ordreId]);
    DB::kjor('DELETE FROM orders WHERE id = :o', ['o' => $ordreId]);
    if ($pid) { DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pid]); }
    Svar::feil('Vipps ga ingen betalingsadresse. Prøv igjen.');
}

DB::oppdater('payments', ['status' => 'venter'], ['vipps_reference' => $referanse]);

Svar::ok(['url' => $url, 'referanse' => $referanse]);
