<?php
/**
 * Kurskatalogen med ledige plasser. Aapent endepunkt — dette er offentlig
 * informasjon, det samme som staar paa kurssiden.
 *
 * Med ett unntak: samlinger merket «Kun for medlemmer» sendes bare til den
 * som er innlogget som medlem. De sto tidligere i den offentlige lista, saa
 * en medlemsfrokost var synlig for alle — bookbar var den riktignok ikke.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$erMedlem = ($m = Sesjon::medlem()) !== null && er_aktivt_medlem($m);
$hvor = $erMedlem ? '1' : "COALESCE(tema, '') <> 'Kun for medlemmer'";

$kurs = DB::alle(
    "SELECT id, slug, tittel, type, tema, pris_ore, kapasitet, beskrivelse
       FROM courses
      WHERE status = 'publisert' AND {$hvor}
      ORDER BY type, tittel"
);

$ut = [];
foreach ($kurs as $k) {
    $okter = DB::alle(
        "SELECT id, start_tid, slutt_tid
           FROM course_sessions
          WHERE course_id = :c
            AND status = 'planlagt'
            AND start_tid > UTC_TIMESTAMP()
          ORDER BY start_tid",
        ['c' => $k['id']]
    );

    $ut[] = [
        'id'      => (int) $k['id'],
        'slug'    => $k['slug'],
        'tittel'  => $k['tittel'],
        'type'    => $k['type'],
        'tema'    => $k['tema'],
        'pris'    => Booking::kroner((int) $k['pris_ore']),
        'prisOre' => (int) $k['pris_ore'],
        'om'      => $k['beskrivelse'],
        'datoer'  => array_map(static fn($o) => [
            'oktId'  => (int) $o['id'],
            'dato'     => Booking::norskPeriode((string) $o['start_tid'], $o['slutt_tid'] ?? null),
            // Raa starttid slik den staar i basen. Kalenderen trenger den for
            // aa sortere okter paa ukedag; norsk datotekst kan ikke regnes paa.
            'startUtc' => $o['start_tid'],
            'ledige'   => Booking::ledigePlasser((int) $o['id']),
        ], $okter),
    ];
}

// Rabattnivaaene folger med. Bookingsiden viste tidligere rabatter den fant
// paa selv, mens serveren trakk full pris — naa leser begge det samme.
$nivaer = array_map(static fn($r) => [
    'min'     => (int) $r['min_antall'],
    'prosent' => (float) $r['prosent'],
    'gjelder' => $r['gjelder'],
], DB::alle('SELECT min_antall, prosent, gjelder FROM discount_tiers WHERE aktiv = 1 ORDER BY min_antall'));

Svar::json(['kurs' => $ut, 'rabatter' => $nivaer]);
