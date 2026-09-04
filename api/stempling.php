<?php
/**
 * Innstempling i verkstedet.
 *
 *   GET                     status: er jeg inne, hvor lenge, hvor mange timer
 *                           er brukt denne maaneden, og hvem er der naa
 *   POST handling=inn|ut    stempler inn eller ut
 *   POST handling=glemt     { tid }  klokkeslettet man faktisk gikk, «14:30»
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
    } elseif ($handling === 'glemt') {
        // ── Glemt aa stemple ut ─────────────────────────────────────────
        //
        // Eieren, 2. september: «Naar et medlem glemmer aa stemple ut, kan vi
        // legge til knappen glemt aa stemple ut. Og mulighet aa legge til
        // klokkeslett naar de faktisk gikk.»
        //
        // Klokkeslettet kommer som «14:30» i norsk tid. Regnestykket ligger i
        // Stempling — verkstedet kan gjore det samme fra medlemsruta, og to
        // kopier av det ville skilt lag.
        $klokke = trim(Foresporsel::tekst('tid'));
        $svar = Stempling::rettUtKlokke($id, $klokke);
        if (!$svar['ok']) {
            Svar::feil((string) ($svar['feil'] ?? 'Fikk ikke rettet økta.'));
        }
        revider('stempling_rettet', 'member', $id, [
            'okt' => $svar['id'] ?? 0, 'tid' => $klokke, 'minutter' => $svar['minutter'] ?? 0,
        ]);
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
$siste = Stempling::sisteOkt($id);
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

// ── De tre siste hele maanedene ────────────────────────────────────────
//
// Til oppgraderingsforslaget. Gaar medlemmet tomt maaned etter maaned, er det
// planen som er for liten — ikke maaneden som var travel. Ett enkelt tall
// sier ikke det; tre gjor.
//
// Maalt mot dagens grense, ikke mot den som gjaldt den gangen. Vi lagrer ikke
// hvilken plan medlemmet hadde i juni, og aa late som om vi vet det ville
// vaert verre enn aa si det rett ut: dette er «saa mye brukte du», holdt opp
// mot «saa mye faar du naa».
//
// Fri tilgang har ingen grense, og da er det ingenting aa foreslaa. Da sender
// vi tom liste framfor tre rader som aldri kan bety noe.
$historikk = [];
if ($perMnd !== null) {
    for ($i = 3; $i >= 1; $i--) {
        $min = Stempling::minutterIManed($id, $i);
        $historikk[] = [
            'maaned'   => Stempling::manedNavn($i),
            'timer'    => Stempling::timer($min),
            'timerMin' => $min,
            // «Naadde taket» og ikke «brukte opp»: en maaned der medlemmet
            // brukte alt er ikke det samme som en der hen ville brukt mer.
            'naaddeTaket' => $min >= $perMnd * 60,
        ];
    }
}

Svar::json([
    'innstemplet' => $apen !== null,
    'historikk'   => $historikk,
    'siden'       => $siden,
    'saaLenge'    => $saaLenge,
    'visMeg'      => (bool) ($medlem['vis_innstempling'] ?? 1),
    // Hva medlemmet kan velge mellom, og hva det staar med naa.
    'ressurser'   => $ressurser,
    'ressursId'   => $valgtRessurs,
    // Hvilken plan timene ble regnet etter.
    //
    // Medlemskap::timerFor() leser «members.timer_per_mnd» forst, saa
    // «members.medlemskap_type». Skjermen navngir det samme medlemskapet, og
    // da kan de to aldri si hver sin ting — men et eget timetall satt paa
    // medlemmet gaar foran planen, og det maa skjermen kunne se.
    'plan' => [
        'navn'         => trim((string) ($medlem['medlemskap_type'] ?? '')),
        'egetTimetall' => $medlem['timer_per_mnd'] !== null,
    ],
    'timer' => [
        'brukt'    => Stempling::timer($brukt),
        'bruktMin' => $brukt,
        // NULL betyr fri tilgang — hverken planen eller medlemsraden setter
        // en grense. Da er det ingenting aa telle ned mot.
        'perMnd'   => $perMnd,
        'igjen'    => $perMnd === null ? null : max(0, round(($perMnd * 60 - $brukt) / 60 * 10) / 10),
        'andel'    => $perMnd === null || $perMnd === 0 ? 0 : min(100, (int) round($brukt / ($perMnd * 60) * 100)),
    ],
    // ── Den siste oekta ─────────────────────────────────────────────────
    //
    // «Glemt aa stemple ut» staar bare naar det er noe aa rette: en oekt som
    // gaar naa, eller en som tok slutt det siste doegnet. Er den eldre, er
    // det verkstedet som maa inn — se Stempling::sisteOkt().
    'siste' => $siste === null || !$siste['kanRettes'] ? null : [
        'id'   => $siste['id'],
        'auto' => $siste['auto'],
        'apen' => $siste['ut_tid'] === null,
        // Dagen og klokkeslettene i norsk tid, saa skjermen kan si «du
        // stemplet inn 10:15 i dag» uten aa regne om selv.
        'dag'  => Booking::norskDatoKort($siste['inn_tid']),
        'inn'  => (new DateTimeImmutable($siste['inn_tid'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i'),
        'ut'   => $siste['ut_tid'] === null ? '' :
            (new DateTimeImmutable($siste['ut_tid'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i'),
    ],
    'inne' => [
        'antall'  => $inne['antall'],
        'skjulte' => $inne['skjulte'],
        'liste'   => $inne['synlige'],
    ],
]);
