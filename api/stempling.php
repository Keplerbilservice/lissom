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
        // Hva medlemmet skal bruke. Eieren, 30. august: «kunne det voere
        // lost om de booker inn og velger dreieskive, eller verkstedplass».
        // Uten valget gjetter regnestykket at de staar ved en skive.
        //
        // En ressurs som ikke finnes, eller er slaatt av, avvises ikke — da
        // ville innstemplinga mislyktes fordi noen slettet en ressurs mens
        // medlemmet sto med telefonen i haanda. Den lagres bare ikke.
        $rid = Foresporsel::heltall('ressursId');
        // Tabellen kom med migrasjon 103. Er den ikke der, lagres ingen
        // ressurs — innstemplinga skal ikke feile av den grunn.
        if ($rid > 0 && (!DB::harTabell('ressurser')
                || DB::en('SELECT id FROM ressurser WHERE id = :i AND aktiv = 1',
                          ['i' => $rid]) === null)) {
            $rid = 0;
        }
        Stempling::inn($id, $rid);
        revider('stemplet_inn', 'member', $id, ['ressurs' => $rid]);
    } elseif ($handling === 'ut') {
        $min = Stempling::ut($id);
        if ($min !== null) {
            revider('stemplet_ut', 'member', $id, ['minutter' => $min]);
        }
    } elseif ($visMeg === '') {
        Svar::feil('Ukjent handling.');
    }

    // Doeren aapnet eller lukket seg. Paint on Pots folger doeren, saa de
    // aapne plassene skal folge med med det samme — ikke ved neste tikk et
    // minutt senere, mens noen staar og ser paa sida.
    //
    // Feil her skal ikke gjore at innstemplinga mislykkes. Den er gjort.
    if ($handling === 'inn' || $handling === 'ut') {
        try {
            Apent::leggUtPaaApneTider();
        } catch (Throwable $e) {
            logg_feil('Kunne ikke oppdatere aapne plasser etter stempling', $e);
        }
    }
}

// -------------------------------------------------------------------- lesing
$medlem = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]) ?? $medlem;

$apen = Stempling::apenOkt($id);
$brukt = Stempling::minutterDenneManeden($id);
$perMnd = Medlemskap::timerFor($medlem);
$inne = Stempling::inneNa();

// Ressursene medlemmet kan velge mellom, og hva det valgte sist. Lista
// kommer herfra og ikke fra nettsida: legger verkstedet til en ressurs, skal
// brikkene folge med uten en ny utlegging.
$ressurser = [];
$valgtRessurs = 0;
try {
    $ressurser = array_map(static fn($r) => [
        'id'     => (int) $r['id'],
        'navn'   => (string) $r['navn'],
        'antall' => (int) $r['antall'],
    ], DB::alle('SELECT id, navn, antall FROM ressurser WHERE aktiv = 1 ORDER BY navn'));
    if (DB::harKolonne('check_ins', 'ressurs_id')) {
        $valgtRessurs = (int) (DB::verdi(
            'SELECT ressurs_id FROM check_ins WHERE member_id = :m AND ut_tid IS NULL
              ORDER BY id DESC LIMIT 1',
            ['m' => $id]
        ) ?? 0);
    }
} catch (Throwable $e) {
    $ressurser = [];
}

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
    // Hva medlemmet kan velge mellom, og hva det staar med naa.
    'ressurser'   => $ressurser,
    'ressursId'   => $valgtRessurs,
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
