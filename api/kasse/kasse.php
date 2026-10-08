<?php
/**
 * Kassa på iPaden (Lissom Kasse, eieren 8. oktober 2026).
 *
 *   GET                                   «I dag»: påmeldte, medlemmer inne,
 *                                         varer, prisnivåer, dagens oppgjør
 *   POST handling=person   { bookingId }  én person fra lista (Paint on Pots)
 *   POST handling=regn     { kurv, betaler }               kurven, regnet her
 *   POST handling=betal    { kurv, betaler, maate, nokler, forventetOre }
 *   POST handling=delt     { kurv, betaler, deler, nokler, forventetOre }
 *   POST handling=qr       { kurv, betaler, del, nokler, forventetOre }
 *   POST handling=status   { poll: {bookingId} | {referanse} }
 *   POST handling=betalteIkke { bookingId }               «Endre» → «Betalte ikke»
 *   POST handling=kvittering  { mal, kanal }
 *   POST handling=oppgjor                                 dagens oppgjør
 *
 * Alt krever at kassa er låst opp med PIN (Kasse::krevUlast()). Beløpene
 * regnes på serveren; se app/lib/kasse.php.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$person = Kasse::krevUlast();

if (Foresporsel::metode() === 'GET') {
    Svar::json(Kasse::idag() + ['person' => ['navn' => $person['navn']], 'laasMinutter' => Kasse::LAAS_MINUTTER]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$kropp = Foresporsel::kropp();
$liste = static fn(string $felt): array => is_array($kropp[$felt] ?? null) ? $kropp[$felt] : [];
$handling = Foresporsel::tekst('handling');

try {
    switch ($handling) {
        case 'person':
            Svar::ok(Kasse::person(Foresporsel::heltall('bookingId')));

        case 'regn':
            $betaler = Kasse::betaler($liste('betaler'));
            Svar::ok(Kasse::visning(Kasse::deler($liste('kurv'), $betaler)));

        case 'betal':
            Svar::ok(Kasse::betal($liste('kurv'), $liste('betaler'), Foresporsel::tekst('maate'),
                array_values($liste('nokler')), Foresporsel::heltall('forventetOre', -1), $person));

        case 'delt':
            Svar::ok(Kasse::betal($liste('kurv'), $liste('betaler'), '',
                array_values($liste('nokler')), Foresporsel::heltall('forventetOre', -1), $person,
                array_values($liste('deler'))));

        case 'qr':
            $r = Kasse::qr($liste('kurv'), $liste('betaler'), Foresporsel::heltall('del'),
                array_values($liste('nokler')), Foresporsel::heltall('forventetOre', -1), $person);
            Svar::ok($r + ['mal' => Kasse::qrMal($r)]);

        case 'kvitteringValg':
            // Etter Vipps-QR: hvilke kvitteringer som kan sendes for det som ble betalt.
            $mal = array_values(array_filter($liste('mal'), static fn($m): bool => is_array($m)
                && in_array($m['type'] ?? '', ['booking', 'ordre'], true) && (int) ($m['id'] ?? 0) > 0));
            $mal = array_map(static fn(array $m): array => ['type' => (string) $m['type'], 'id' => (int) $m['id']], $mal);
            Svar::ok(Kasse::kvitteringValg(Kasse::betaler($liste('betaler')), $mal));

        case 'status':
            Svar::ok(Kasse::status($liste('poll')));

        case 'betalteIkke':
            $r = Kasse::betalteIkke(Foresporsel::heltall('bookingId'), $person['id']);
            Svar::ok($r + ['person' => Kasse::person(Foresporsel::heltall('bookingId'))]);

        case 'kvittering':
            Kasse::kvittering(array_values($liste('mal')), Foresporsel::tekst('kanal'));
            Svar::ok(['beskjed' => 'Kvitteringen er sendt.']);

        case 'oppgjor':
            Svar::ok(Kasse::oppgjor());

        default:
            Svar::feil('Ukjent handling.');
    }
} catch (RuntimeException $e) {
    $kode = (int) $e->getCode();
    // Vipps sa nei eller svarte ikke: et svar, ikke en feil hos oss (samme
    // som kursstarten, api/admin/kursstart3.php).
    if ($kode === KursstartKrav::VIPPS_NEI) {
        Svar::json(['ok' => false, 'feil' => $e->getMessage()]);
    }
    Svar::feil($e->getMessage(), $kode >= 400 && $kode < 600 ? $kode : 400);
}
