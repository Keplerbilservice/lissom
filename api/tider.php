<?php
/**
 * Kvarterene man kan komme paa, en dag.
 *
 *   GET ?kursId=12&dato=2026-09-24&antall=2
 *
 * Eieren, 23. september 2026, om Paint on Pots: «kan vi vise dagene paa
 * kurset slik som i kalender, men ogsaa mulig aa booke tid i dette
 * mellomrommet? Her vil jeg ikke ha faste piller som viser alle timene, men
 * dato og tid, er det fult maa den foreslaa neste ledige».
 *
 * Foer dette var plassene ferdig utklipte oekter — 10:00, 11:30 — og lista
 * over dem laa i katalogen. Naa er hvert kvarter inne i den aapne tida et
 * mulig oppmoete, og det er for mange til aa ligge i katalogen for hver dag
 * framover. Skjermen spor om én dag om gangen, her.
 *
 * Aapent med vilje: det samme staar paa kurssida, og hvem som helst skal
 * kunne se naar det er ledig uten aa logge inn. Ingenting skrives — oekta
 * lages forst naar noen faktisk booker, i api/book.php.
 *
 * Svaret:
 *
 *   vindu         «10:00–13:00», eller tomt naar det er stengt
 *   tider         [{ tid: «10:45», ledige: 4 }, …] — bare de med plass
 *   forsteLedige  { dato, tid } naar dagen er full, ellers null
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

// Oppslaget er billig, men det gaar én spoerring per kvarter. Uten en grense
// kunne noen bedt om dag etter dag i en loekke.
Rate::sjekk('tider', maks: 120, vindu: 600);

$kursId = Foresporsel::heltall('kursId');
$dato   = trim(Foresporsel::tekst('dato'));
$antall = max(1, min(10, Foresporsel::heltall('antall', 1)));

if ($kursId <= 0) {
    Svar::feil('Mangler kurset.');
}
// Datoen skal se ut som en dato. Uten dette gaar hva som helst rett inn i
// DateTimeImmutable, som tolker «now» og «+3 days» like gjerne.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dato)) {
    Svar::feil('Mangler datoen.');
}

$svar = Apent::ledigeKvarter($kursId, $dato, $antall);

// Er dagen full — eller stengt — skal skjermen kunne si hva som er neste.
// Eieren: «er det fult maa den foreslaa neste ledige.»
$forste = $svar['tider'] === []
    ? Apent::forsteLedige($kursId, $dato, $antall)
    : null;

Svar::json([
    'vindu'        => $svar['vindu'],
    'tider'        => $svar['tider'],
    'forsteLedige' => $forste,
], 200, 60);
