<?php
/**
 * Plassene mine — bookinger og ventelisteoppforinger for den innloggede.
 *
 * Min side viste tidligere tre oppdiktede paameldinger til alle som logget
 * inn. Det er verre enn i admin: der ser bare eieren det, her ser kunden sin
 * egen side og tror det staar noe ekte.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');
$medlem = krev_medlem();

/**
 * Avbestillingsreglene fra vilkaarene, regnet ut fra hvor lenge det er igjen:
 * mer enn 14 dager gir full refusjon, 14–7 dager gir 50 %, naermere gir
 * ingenting. Vi regner det ut her framfor aa la kunden gjette.
 */
$frist = static function (?string $startUtc): array {
    if ($startUtc === null) {
        return ['Ta kontakt om du må avbestille.', true];
    }
    $dager = (strtotime($startUtc) - time()) / 86400;

    if ($dager > 14) {
        return ['Full refusjon fram til 14 dager før kursstart.', true];
    }
    if ($dager > 7) {
        return ['50 % refusjon fram til 7 dager før kursstart.', true];
    }
    if ($dager > 0) {
        return ['Nærmere enn 7 dager refunderes ikke, men du kan gi plassen til en annen.', false];
    }
    return ['Kurset har vært.', false];
};

/**
 * Har kurset vaert, og var det et kurs? Da finnes det et kursbevis aa hente.
 */
$kursbevis = static function (array $b, bool $betalt): ?string {
    if (!$betalt) {
        return null;
    }
    // Verkstedet kan ha trukket beviset. Da skal det heller ikke staa her —
    // ellers ligger lenken igjen paa Min side og svarer 404 naar den trykkes.
    if (!empty($b['bevis_sperret'])) {
        return null;
    }
    // Drop-in er ikke et kurs — det er halvannen time i verkstedet med ditt eget
    // arbeid, og det er ingenting aa bevise. Interne samlinger er kurs, og
    // de gir bevis som alle andre: et medlem som har vaert paa glasurkveld
    // har vaert paa kurs, selv om samlingen ikke sto i den aapne lista.
    if ((string) ($b['tema'] ?? '') === 'Drop-in') {
        return null;
    }
    $slutt = $b['slutt_tid'] ?: $b['start_tid'];
    if ($slutt === null || strtotime((string) $slutt) > time()) {
        return null;
    }
    return '/api/kursbevis.php?booking=' . (int) $b['id'];
};

// Kom med migrasjon 045.
$bevisFelt = DB::harKolonne('bookings', 'bevis_sperret') ? 'b.bevis_sperret,' : '';

/** Bildene deltakeren har lagt inn paa én paamelding. Kom med migrasjon 055. */
$bilder = static function (int $bookingId): array {
    if (!DB::harTabell('deltaker_bilder')) {
        return [];
    }
    return array_map(
        static fn($b) => [
            'id'        => (int) $b['id'],
            'url'       => 'api/bilde.php?deltaker=' . $b['fil'],
            'kanSlette' => $b['lastet_opp_av'] !== null,
        ],
        DB::alle('SELECT id, fil, lastet_opp_av FROM deltaker_bilder
                   WHERE booking_id = :b ORDER BY id', ['b' => $bookingId])
    );
};

$bookinger = DB::alle(
    "SELECT b.id, b.antall, b.status, b.belop_ore, b.created_at, {$bevisFelt}
            c.tittel, c.tema, cs.start_tid, cs.slutt_tid, p.vipps_reference
       FROM bookings b
       JOIN courses c ON c.id = b.course_id
  LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
  LEFT JOIN payments p ON p.id = b.payment_id
      WHERE b.member_id = :m
        AND b.status IN ('betalt','reservert')
      ORDER BY cs.start_tid IS NULL, cs.start_tid",
    ['m' => $medlem['id']]
);

$plasser = [];
foreach ($bookinger as $b) {
    [$fristTekst, $kanAvbestille] = $frist($b['start_tid']);
    $betalt = $b['status'] === 'betalt';

    $plasser[] = [
        'id'            => (int) $b['id'],
        'tittel'        => $b['tittel'],
        'naar'          => $b['start_tid'] ? Booking::norskDato((string) $b['start_tid']) : 'Dato kommer',
        'sum'           => Booking::kroner((int) $b['belop_ore'])
                            . ((int) $b['antall'] > 1 ? ' · ' . $b['antall'] . ' plasser' : ''),
        'status'        => $betalt ? 'Bekreftet' : 'Reservert — ikke betalt',
        'tone'          => $betalt ? 'success' : 'warning',
        'frist'         => $betalt ? $fristTekst : 'Reservasjonen frigis om betalingen ikke fullføres.',
        'kanAvbestille' => $betalt && $kanAvbestille,
        'referanse'     => $b['vipps_reference'],
        // Kursbeviset dukker opp av seg selv naar kurset er gjennomfort og
        // betalt. Drop-in er ikke et kurs, saa der gis det ikke bevis.
        'kursbevis'     => $kursbevis($b, $betalt),
        // Bildene deltakeren har tatt av keramikken sin. Verkstedet bruker dem
        // til aa finne ut hvem som har laget hva for de sier fra om henting.
        'bilder'        => $bilder((int) $b['id']),
    ];
}

// Ventelisteoppforinger vises sammen med plassene, saa man ser alt paa ett sted.
$venteliste = DB::alle(
    "SELECT w.posisjon, w.status, c.tittel
       FROM waitlist w
       JOIN courses c ON c.id = w.course_id
      WHERE w.status IN ('venter','varslet')
        AND (w.epost = :e OR (w.telefon IS NOT NULL AND w.telefon = :t))
      ORDER BY c.tittel",
    ['e' => $medlem['epost'] ?? '', 't' => $medlem['telefon'] ?? '']
);

foreach ($venteliste as $v) {
    $plasser[] = [
        'id'            => 0,
        'tittel'        => $v['tittel'],
        'naar'          => 'Ingen dato ennå',
        'sum'           => 'Ingenting belastet',
        'status'        => $v['status'] === 'varslet'
                            ? 'Ledig plass — book nå'
                            : 'Venteliste, nr. ' . $v['posisjon'],
        'tone'          => 'info',
        'frist'         => 'Du belastes først når en plass blir din.',
        'kanAvbestille' => false,
        'referanse'     => null,
        'kursbevis'     => null,
        'bilder'        => [],
    ];
}

Svar::json(['plasser' => $plasser]);
