<?php
/**
 * Kjop av gavekort.
 *
 * Kortet opprettes som ubetalt for kunden sendes til Vipps, og faar kode og
 * gyldighet forst naar betalingen er bekreftet. Ellers kunne noen fatt en
 * gyldig kode ved aa starte en betaling og avbryte den.
 *
 * Gyldighet er tre aar, som staar i vilkaarene.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
Rate::sjekk('gavekort', maks: 10, vindu: 600);

$medlem = krev_medlem();

$belop = Foresporsel::heltall('belop');           // kroner
$mNavn = mb_substr(Foresporsel::tekst('mottakerNavn'), 0, 191);
$mEpost = mb_substr(Foresporsel::tekst('mottakerEpost'), 0, 191);
$hilsen = mb_substr(Foresporsel::tekst('hilsen'), 0, 500);

if ($belop < 100 || $belop > 20000) {
    Svar::feil('Velg et beløp mellom 100 og 20 000 kroner.');
}
if ($mEpost !== '' && !filter_var($mEpost, FILTER_VALIDATE_EMAIL)) {
    Svar::feil('Adressen til mottakeren ser ikke riktig ut.');
}

$referanse = Vipps::nyReferanse('GAV');

$opprettet = DB::iTransaksjon(static function () use ($belop, $mNavn, $mEpost, $hilsen, $medlem, $referanse): array {
    $paymentId = DB::settInn('payments', [
        'vipps_reference' => $referanse,
        'type'            => 'epayment',
        'formal'          => 'gavekort',
        'member_id'       => $medlem['id'],
        'belop_ore'       => $belop * 100,
        'status'          => 'opprettet',
        'idempotency_key' => Vipps::uuid(),
    ]);

    // Koden settes forst ved betaling. Her far kortet en midlertidig,
    // ubrukelig plassholder — kolonnen er paakrevd og unik.
    $kortId = DB::settInn('gift_cards', [
        'kode'            => 'UBETALT-' . strtoupper(bin2hex(random_bytes(6))),
        'opprinnelig_ore' => $belop * 100,
        'saldo_ore'       => 0,
        'gyldig_til'      => gmdate('Y-m-d', strtotime('+3 years')),
        'kjoper_navn'     => (string) $medlem['navn'],
        'kjoper_epost'    => (string) ($medlem['epost'] ?? ''),
        'mottaker_epost'  => $mEpost !== '' ? $mEpost : null,
        'hilsen'          => $hilsen !== '' ? $hilsen : null,
        'payment_id'      => $paymentId,
        'status'          => 'ubetalt',
    ]);

    return ['kortId' => $kortId, 'paymentId' => $paymentId];
});

try {
    $betaling = Vipps::opprettBetaling(
        $referanse,
        $belop * 100,
        'Lissom gavekort',
        Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
        (string) ($medlem['telefon'] ?? '')
    );
} catch (Throwable $e) {
    DB::oppdater('payments', ['status' => 'feilet'], ['id' => $opprettet['paymentId']]);
    DB::oppdater('gift_cards', ['status' => 'annullert'], ['id' => $opprettet['kortId']]);
    logg_feil('Kunne ikke starte betaling for gavekort ' . $opprettet['kortId'], $e);
    Svar::feil('Fikk ikke startet betalingen. Prov igjen om litt.', 502);
}

DB::oppdater('payments', ['status' => 'venter'], ['id' => $opprettet['paymentId']]);
revider('gavekort_opprettet', 'gift_card', $opprettet['kortId'], ['belop_ore' => $belop * 100]);

Svar::ok([
    'url'       => $betaling['url'],
    'referanse' => $referanse,
    'belop'     => Booking::kroner($belop * 100),
]);
