<?php
/**
 * Kassa på iPaden (Lissom Kasse, eieren 8. oktober 2026).
 *
 *   GET                                   «I dag»: dagens kurs (kurs, ledigeKurs),
 *                                         påmeldte, varer, prisnivåer, oppgjøret
 *   GET ?okt=<id>                         ett av dagens kurs: de som ikke har betalt
 *   POST handling=person   { bookingId }  én person fra lista (Paint on Pots)
 *   POST handling=nyKunde  { navn, telefon, oktId?, antall? }  ny kunde, eventuelt
 *                                         med en plass på et av dagens kurs
 *
 * Kurven kan ha «priser» { linjens nøkkel: kroner } og «rabatt» { prosent | kr,
 * hvorfor } (KasseKurv::deler, KasseJustering).
 *   POST handling=regn     { kurv, betaler }               kurven, regnet her
 *   POST handling=betal    { kurv, betaler, maate, nokler, forventet }
 *   POST handling=delt     { kurv, betaler, deler, nokler, forventet }
 *   POST handling=qr       { kurv, betaler, del, nokler, forventet }
 *   POST handling=status   { poll: {bookingId} | {referanse} }
 *   POST handling=kvitteringValg { betaler, betalinger }   etter Vipps-QR
 *   POST handling=betalteIkke { bookingId }               «Endre» → «Betalte ikke»
 *   POST handling=kvittering  { betalinger, kanal, betaler }
 *   POST handling=oppgjor                                 dagens oppgjør
 *
 * «nokler» og «forventet» er per del, med delens faste id som nøkkel
 * (KasseKurv::deler). Alt krever at kassa er låst opp med PIN
 * (KasseTilgang::krevUlast()). Beløpene regnes på serveren; se
 * app/lib/kasse.php og app/lib/kassekurv.php.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$person = KasseTilgang::krevUlast();

if (Foresporsel::metode() === 'GET') {
    // Ett av dagens kurs: de som ikke har betalt (iPaden spør hvert 15. sekund).
    if (isset($_GET['okt'])) {
        try {
            Svar::json(KasseKurv::kurs((int) $_GET['okt']));
        } catch (RuntimeException $e) {
            Svar::feil($e->getMessage(), (int) $e->getCode() === 404 ? 404 : 400);
        }
    }
    Svar::json(KasseKurv::idag() + ['person' => ['navn' => $person['navn']], 'laasMinutter' => KasseTilgang::LAAS_MINUTTER]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$kropp = Foresporsel::kropp();
$liste = static fn(string $felt): array => is_array($kropp[$felt] ?? null) ? $kropp[$felt] : [];
$handling = Foresporsel::tekst('handling');

try {
    switch ($handling) {
        case 'person':
            Svar::ok(KasseKurv::person(Foresporsel::heltall('bookingId')));

        case 'nyKunde':
            Svar::ok(KasseKurv::nyKunde(Foresporsel::tekst('navn'), Foresporsel::tekst('telefon'),
                Foresporsel::heltall('oktId'), Foresporsel::heltall('antall', 1), $person));

        case 'regn':
            $betaler = KasseKurv::betaler($liste('betaler'));
            Svar::ok(KasseKurv::visning(KasseKurv::deler($liste('kurv'), $betaler)));

        case 'betal':
            Svar::ok(Kasse::betal($liste('kurv'), $liste('betaler'), Foresporsel::tekst('maate'),
                $liste('nokler'), $liste('forventet'), $person));

        case 'delt':
            Svar::ok(Kasse::betal($liste('kurv'), $liste('betaler'), '',
                $liste('nokler'), $liste('forventet'), $person, array_values($liste('deler'))));

        case 'qr':
            Svar::ok(Kasse::qr($liste('kurv'), $liste('betaler'), Foresporsel::tekst('del'),
                $liste('nokler'), $liste('forventet'), $person));

        case 'status':
            Svar::ok(Kasse::status($liste('poll')));

        case 'kvitteringValg':
            // Etter Vipps-QR: hvilke kvitteringer som kan sendes for det som ble betalt.
            Svar::ok(Kasse::kvitteringValg(KasseKurv::betaler($liste('betaler')), array_values($liste('betalinger'))));

        case 'betalteIkke':
            $r = Kasse::betalteIkke(Foresporsel::heltall('bookingId'), $person['id']);
            Svar::ok($r + ['person' => KasseKurv::person(Foresporsel::heltall('bookingId'))]);

        case 'kvittering':
            Kasse::kvittering(array_values($liste('betalinger')), Foresporsel::tekst('kanal'),
                KasseKurv::betaler($liste('betaler')));
            Svar::ok(['beskjed' => 'Kvitteringen er sendt.']);

        case 'oppgjor':
            Svar::ok(KasseKurv::oppgjor());

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
