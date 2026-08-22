<?php
/**
 * Innstempling i verkstedet.
 *
 *   GET                     status: er jeg inne, hvor lenge, hvor mange timer
 *                           er brukt denne maaneden, og hvem er der naa
 *   POST handling=inn|ut    stempler inn eller ut
 *   POST visMeg=ja|nei      om navnet skal vises for de andre medlemmene
 *
 * Krever aktivt medlemskap. Innstempling trekker timer fra abonnementet, og
 * den som ikke har et abonnement har ingen timer aa trekke fra.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem = krev_aktivt_medlem();
$id = (int) $medlem['id'];

// Okter som har staatt aapne for lenge lukkes for vi teller. Ellers ville
// noen som glemte aa stemple ut i forrige uke staatt som «i verkstedet naa».
Stempling::lukkGlemte();

// ------------------------------------------------------------------ skriving
if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();
    Rate::sjekk('stempling', maks: 40, vindu: 600, nokkel: (string) $id);

    $visMeg = Foresporsel::tekst('visMeg');
    if ($visMeg !== '') {
        DB::oppdater('members', ['vis_innstempling' => $visMeg === 'ja' ? 1 : 0], ['id' => $id]);
    }

    $handling = Foresporsel::tekst('handling');
    if ($handling === 'inn') {
        Stempling::inn($id);
        revider('stemplet_inn', 'member', $id);
    } elseif ($handling === 'ut') {
        $min = Stempling::ut($id);
        if ($min !== null) {
            revider('stemplet_ut', 'member', $id, ['minutter' => $min]);
        }
    } elseif ($visMeg === '') {
        Svar::feil('Ukjent handling.');
    }
}

// -------------------------------------------------------------------- lesing
$medlem = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]) ?? $medlem;

$apen = Stempling::apenOkt($id);
$brukt = Stempling::minutterDenneManeden($id);
$perMnd = Medlemskap::timerFor($medlem);
$inne = Stempling::inneNa();

$siden = null;
$saaLenge = null;
if ($apen !== null) {
    $inn = (new DateTimeImmutable((string) $apen['inn_tid'], new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Oslo'));
    $siden = $inn->format('H:i');
    $saaLenge = Stempling::varighet((int) round((time() - $inn->getTimestamp()) / 60));
}

Svar::json([
    'innstemplet' => $apen !== null,
    'siden'       => $siden,
    'saaLenge'    => $saaLenge,
    'visMeg'      => (bool) ($medlem['vis_innstempling'] ?? 1),
    'timer' => [
        'brukt'    => Stempling::timer($brukt),
        'bruktMin' => $brukt,
        // NULL betyr fri tilgang — hverken planen eller medlemsraden setter
        // en grense. Da er det ingenting aa telle ned mot.
        'perMnd'   => $perMnd,
        'igjen'    => $perMnd === null ? null : max(0, round(($perMnd * 60 - $brukt) / 60 * 10) / 10),
        'andel'    => $perMnd === null || $perMnd === 0 ? 0 : min(100, (int) round($brukt / ($perMnd * 60) * 100)),
    ],
    'inne' => [
        'antall'  => $inne['antall'],
        'skjulte' => $inne['skjulte'],
        'liste'   => $inne['synlige'],
    ],
]);
