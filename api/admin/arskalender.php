<?php
/**
 * Aarskalenderen: aaret maaned for maaned.
 *
 *   GET  ?aar=2026          punktene for ett aar
 *   POST handling=legg-til  { aar, mnd, tekst }
 *   POST handling=endre     { id, tekst }
 *   POST handling=flytt     { id, mnd, aar? }   samme punkt, ny maaned
 *   POST handling=slett     { id }
 *
 * Eieren, 6. september: «det er ogsaa oenske om en aarskalender, som viser
 * kun maaned for maaned, og et felt der jeg kan skrive inn hva som skjer
 * denne maaneden, vil ogsaa kunne redigere og bytte og slette».
 *
 * Dette er ikke kurs og ikke datoer — det er notatene om aaret. Kursene
 * dukker ikke opp av seg selv: eieren valgte «Nei, bare det jeg skriver
 * selv», fordi en liste som fyller seg selv drukner det han ville huske.
 *
 * Intern. Ingenting herfra vises paa nettsiden.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

/** Punktene for ett aar, i maanedsrekkefolge. */
$les = static function (int $aar): array {
    return array_map(static function (array $r): array {
        return [
            'id'    => (int) $r['id'],
            'aar'   => (int) $r['aar'],
            'mnd'   => (int) $r['mnd'],
            'tekst' => (string) $r['tekst'],
        ];
    }, DB::alle(
        'SELECT id, aar, mnd, tekst FROM arskalender
          WHERE aar = :a ORDER BY mnd, sortering, id',
        ['a' => $aar]
    ));
};

// Aaret. Uten et tall staar vi i det aaret som gaar naa.
$aarNa = (int) date('Y');

if (Foresporsel::metode() === 'GET') {
    $aar = Foresporsel::heltall('aar');
    if ($aar < 2000 || $aar > 2100) {
        $aar = $aarNa;
    }
    Svar::json(['aar' => $aar, 'punkter' => $les($aar)]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');

// ── Legg inn et punkt ────────────────────────────────────────────────────
if ($handling === 'legg-til') {
    $aar   = Foresporsel::heltall('aar');
    $mnd   = Foresporsel::heltall('mnd');
    $tekst = mb_substr(trim(Foresporsel::tekst('tekst')), 0, 300);

    if ($aar < 2000 || $aar > 2100) {
        Svar::feil('Året ser ikke riktig ut.');
    }
    if ($mnd < 1 || $mnd > 12) {
        Svar::feil('Velg en måned.');
    }
    if ($tekst === '') {
        Svar::feil('Skriv hva som skjer denne måneden.');
    }

    // Bakerst i maaneden. Punktene staar slik de ble skrevet.
    $sist = (int) DB::verdi(
        'SELECT COALESCE(MAX(sortering), 0) FROM arskalender WHERE aar = :a AND mnd = :m',
        ['a' => $aar, 'm' => $mnd]
    );

    $id = DB::settInn('arskalender', [
        'aar'       => $aar,
        'mnd'       => $mnd,
        'tekst'     => $tekst,
        'sortering' => $sist + 1,
    ]);

    revider('arskalender_lagt_til', 'arskalender', $id, ['aar' => $aar, 'mnd' => $mnd]);
    Svar::ok(['id' => $id, 'aar' => $aar, 'punkter' => $les($aar), 'beskjed' => 'Punktet er lagt inn.']);
}

// De tre andre gjelder et punkt som alt finnes.
$id  = Foresporsel::heltall('id');
$rad = DB::en('SELECT id, aar, mnd, tekst FROM arskalender WHERE id = :i', ['i' => $id]);
if ($rad === null) {
    Svar::feil('Fant ikke punktet.', 404);
}
$aar = (int) $rad['aar'];

// ── Rett teksten ─────────────────────────────────────────────────────────
if ($handling === 'endre') {
    $tekst = mb_substr(trim(Foresporsel::tekst('tekst')), 0, 300);
    if ($tekst === '') {
        Svar::feil('Skriv hva som skjer denne måneden.');
    }
    DB::oppdater('arskalender', ['tekst' => $tekst, 'endret' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    revider('arskalender_endret', 'arskalender', $id, ['aar' => $aar, 'mnd' => (int) $rad['mnd']]);
    Svar::ok(['aar' => $aar, 'punkter' => $les($aar), 'beskjed' => 'Punktet er endret.']);
}

// ── Bytt maaned ──────────────────────────────────────────────────────────
//
// «vil ogsaa kunne redigere og bytte og slette». Punktet beholder teksten
// sin og legger seg bakerst i den nye maaneden.
if ($handling === 'flytt') {
    $mnd    = Foresporsel::heltall('mnd');
    $tilAar = Foresporsel::heltall('aar');
    if ($tilAar < 2000 || $tilAar > 2100) {
        $tilAar = $aar;
    }
    if ($mnd < 1 || $mnd > 12) {
        Svar::feil('Velg en måned.');
    }
    if ($mnd === (int) $rad['mnd'] && $tilAar === $aar) {
        Svar::feil('Punktet står allerede i den måneden.');
    }

    $sist = (int) DB::verdi(
        'SELECT COALESCE(MAX(sortering), 0) FROM arskalender WHERE aar = :a AND mnd = :m',
        ['a' => $tilAar, 'm' => $mnd]
    );
    DB::oppdater('arskalender', [
        'aar'       => $tilAar,
        'mnd'       => $mnd,
        'sortering' => $sist + 1,
        'endret'    => gmdate('Y-m-d H:i:s'),
    ], ['id' => $id]);

    revider('arskalender_flyttet', 'arskalender', $id,
            ['fra' => (int) $rad['mnd'], 'til' => $mnd, 'aar' => $tilAar]);
    Svar::ok(['aar' => $tilAar, 'punkter' => $les($tilAar), 'beskjed' => 'Punktet er flyttet.']);
}

// ── Slett ────────────────────────────────────────────────────────────────
if ($handling === 'slett') {
    DB::kjor('DELETE FROM arskalender WHERE id = :i', ['i' => $id]);
    revider('arskalender_slettet', 'arskalender', $id,
            ['aar' => $aar, 'mnd' => (int) $rad['mnd'], 'tekst' => $rad['tekst']]);
    Svar::ok(['aar' => $aar, 'punkter' => $les($aar), 'beskjed' => 'Punktet er slettet.']);
}

Svar::feil('Ukjent handling.');
