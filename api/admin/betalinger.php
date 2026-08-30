<?php
/**
 * Betalingene, med mulighet for refusjon.
 *
 *   GET   viser lista
 *   POST  refunderer  { referanse, belop }  — belop i kroner, tomt = alt
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (Foresporsel::metode() === 'GET') {
    $betalinger = DB::alle(
        "SELECT p.id, p.vipps_reference, p.formal, p.belop_ore, p.refundert_ore,
                p.status, p.created_at, m.navn AS medlem
           FROM payments p
      LEFT JOIN members m ON m.id = p.member_id
          ORDER BY p.id DESC
          LIMIT 200"
    );

    Svar::json(['betalinger' => array_map(static fn($p) => [
        'id'         => (int) $p['id'],
        'referanse'  => $p['vipps_reference'],
        'formal'     => $p['formal'],
        'belop'      => Booking::kroner((int) $p['belop_ore']),
        'belopOre'   => (int) $p['belop_ore'],
        'refundert'  => (int) $p['refundert_ore'] > 0 ? Booking::kroner((int) $p['refundert_ore']) : null,
        // Raa tall ogsaa, saa skjermen kan regne ut hva som staar igjen aa
        // refundere uten aa tolke «kr. 1 490,-» tilbake til et tall.
        'refundertOre' => (int) $p['refundert_ore'],
        'status'     => $p['status'],
        'medlem'     => $p['medlem'],
        'tidspunkt'  => Booking::norskDato((string) $p['created_at']),
    ], $betalinger)]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$referanse = Foresporsel::tekst('referanse');
$betaling = DB::en('SELECT * FROM payments WHERE vipps_reference = :r', ['r' => $referanse]);

if ($betaling === null) {
    Svar::feil('Fant ikke betalingen.', 404);
}
if ($betaling['status'] !== 'betalt' && $betaling['status'] !== 'delvis_refundert') {
    Svar::feil('Denne betalingen kan ikke refunderes — den er ikke gjennomfort.', 409);
}

$maks = (int) $betaling['belop_ore'] - (int) $betaling['refundert_ore'];
$onsket = Foresporsel::heltall('belop') * 100;   // kroner inn, ore ut
$belop = $onsket > 0 ? min($onsket, $maks) : $maks;

if ($belop <= 0) {
    Svar::feil('Hele beløpet er allerede refundert.', 409);
}

try {
    Vipps::refunder($referanse, $belop);
} catch (Throwable $e) {
    logg_feil('Refusjon feilet for ' . $referanse, $e);
    Svar::feil('Vipps godtok ikke refusjonen. Prøv igjen, eller sjekk i portalen.', 502);
}

$nyRefundert = (int) $betaling['refundert_ore'] + $belop;
DB::oppdater('payments', [
    'refundert_ore' => $nyRefundert,
    'status'        => $nyRefundert >= (int) $betaling['belop_ore'] ? 'refundert' : 'delvis_refundert',
], ['id' => $betaling['id']]);

// Booking foelger betalingen — men bare naar hele beloepet er sendt tilbake.
//
// Her sto oppdateringen uten betingelse. En delrefusjon etter vilkaarene (50 %
// inntil sju dager for) ville da satt plassen som refundert: deltakeren falt
// ut av lista, og stolen ble ledig igjen — selv om hen fortsatt skulle komme.
// Det er delrefusjonens hele poeng at plassen ikke gis fra seg gratis.
if ($nyRefundert >= (int) $betaling['belop_ore']) {
    DB::kjor(
        "UPDATE bookings SET status = 'refundert' WHERE payment_id = :p",
        ['p' => $betaling['id']]
    );
}

revider('refusjon', 'payment', (int) $betaling['id'], ['belop_ore' => $belop]);

Svar::ok([
    'refundert' => Booking::kroner($belop),
    'gjenstaar' => Booking::kroner($maks - $belop),
    // Raa tall ogsaa: skjermen skal ikke maatte sammenligne «kr. 0,-» som
    // tekst for aa vite om det staar noe igjen.
    'gjenstaarOre' => $maks - $belop,
]);
