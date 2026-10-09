<?php
/**
 * Min side: plass paa en dato Lissom har avlyst (eieren, GO 9. oktober 2026).
 *
 *   POST handling=datoer { bookingId }          neste datoer paa SAMME kurs med plass
 *        → { datoer: [{ oktId, naar }] }
 *   POST handling=bytt   { bookingId, oktId }   flytter paameldingen dit
 *        → { naar, beskjed }
 *
 * «Få pengene tilbake» gaar gjennom api/avbestill.php, som gir alt tilbake
 * naar datoen er avlyst (uansett tid igjen) — samme refusjonsloeype som ellers.
 *
 * Bytte av dato: ingen ekstra kostnad og ingen ny betaling. Beloepet,
 * betalingen og rabatten foelger med som de er, ogsaa om den nye datoen har en
 * annen pris (det er Lissom som avlyste). Plassen kontrolleres under laas med
 * den samme regelen som flytting i admin (Booking::sjekkFlytting): paameldingen
 * maa staa som den ble lest, og datoen maa ha plass til alle paa den.
 * Datoene som tilbys er de samme som kan bookes: planlagt, kurset publisert,
 * ikke passert, og nok ledige plasser (Booking::ledigePlasserFlere).
 *
 * Bare den innloggede, og bare egne plasser. Kunden faar «Ny dato på kurset»
 * paa e-post, som naar verkstedet flytter (pamelding.php flytt).
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$medlem = krev_medlem();
Rate::sjekk('avlyst-plass', maks: 30, vindu: 3600, nokkel: (string) $medlem['id']);

$handling = Foresporsel::tekst('handling');
$bookingId = Foresporsel::heltall('bookingId');

/** Paameldingen, bare naar den er medlemmets egen, aktiv og paa en avlyst dato. */
$les = static function () use ($bookingId, $medlem): ?array {
    return DB::en(
        'SELECT b.id, b.antall, b.course_id, b.course_session_id, b.status, b.belop_ore,
                b.payment_id, b.betalt_maate,
                ' . (DB::harKolonne('bookings', 'rabatt_prosent') ? 'b.rabatt_prosent' : '0 AS rabatt_prosent') . ',
                COALESCE(m.navn, b.gjest_navn) AS navn,
                COALESCE(m.epost, b.gjest_epost) AS epost,
                cs.start_tid AS fra_tid, cs.status AS okt_status, c.tittel
           FROM bookings b
           JOIN course_sessions cs ON cs.id = b.course_session_id
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN members m ON m.id = b.member_id
          WHERE b.id = :i AND b.member_id = :m
            AND ' . Booking::aktivSql('b'),
        ['i' => $bookingId, 'm' => $medlem['id']]
    );
};

$b = $les();
if ($b === null) {
    Svar::feil('Fant ikke plassen din.', 404);
}
if ((string) $b['okt_status'] !== 'avlyst') {
    Svar::feil('Denne datoen er ikke avlyst.', 409);
}
$trenger = max(1, (int) $b['antall']);

// Neste datoer paa samme kurs som kan bookes, med plass til alle paa
// paameldingen.
$datoer = static function () use ($b, $trenger): array {
    $okter = DB::alle(
        "SELECT cs.id, cs.start_tid
           FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id
          WHERE cs.course_id = :k AND cs.status = 'planlagt' AND c.status = 'publisert'
            AND cs.start_tid > UTC_TIMESTAMP() AND cs.id <> :o
       ORDER BY cs.start_tid
          LIMIT 40",
        ['k' => (int) $b['course_id'], 'o' => (int) $b['course_session_id']]
    );
    $ledige = Booking::ledigePlasserFlere(array_map(static fn(array $o): int => (int) $o['id'], $okter));
    $ut = [];
    foreach ($okter as $o) {
        if (($ledige[(int) $o['id']] ?? 0) >= $trenger) {
            $ut[] = ['oktId' => (int) $o['id'], 'naar' => Booking::norskDato((string) $o['start_tid'])];
        }
        if (count($ut) >= 8) {
            break;
        }
    }
    return $ut;
};

if ($handling === 'datoer') {
    Svar::ok(['datoer' => $datoer()]);
}

if ($handling === 'bytt') {
    $tilOkt = Foresporsel::heltall('oktId');
    $okt = DB::en(
        "SELECT cs.id, cs.course_id, cs.start_tid, cs.status, c.status AS kurs_status, c.tittel
           FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id
          WHERE cs.id = :o",
        ['o' => $tilOkt]
    );
    if ($okt === null || (int) $okt['course_id'] !== (int) $b['course_id']
        || (string) $okt['status'] !== 'planlagt' || (string) $okt['kurs_status'] !== 'publisert'
        || strtotime((string) $okt['start_tid'] . ' UTC') <= time()
        || (int) $okt['id'] === (int) $b['course_session_id']) {
        Svar::feil('Denne datoen kan ikke velges.', 409);
    }

    try {
        DB::iTransaksjon(static function () use ($b, $tilOkt, $okt, $bookingId): void {
            // Samme kontroll som flytting i admin: paameldingen laases og maa
            // staa som den ble lest, og datoen maa ha plass (med laas).
            Booking::sjekkFlytting($bookingId, $b, $tilOkt);
            // Datoen maa fortsatt vaere avlyst — ble den gjenopprettet mens
            // kunden valgte, staar plassen der den sto.
            $status = (string) DB::verdi('SELECT status FROM course_sessions WHERE id = :o', ['o' => (int) $b['course_session_id']]);
            if ($status !== 'avlyst') {
                throw new RuntimeException('Denne datoen er ikke avlyst.', 409);
            }
            DB::oppdater('bookings', [
                'course_session_id' => $tilOkt,
                'course_id'         => (int) $okt['course_id'],
            ], ['id' => $bookingId]);
        });
    } catch (PDOException $e) {
        if (!in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
            throw $e;
        }
        Svar::feil('Påmeldingen ble endret i mellomtiden. Last siden på nytt.', 409);
    } catch (RuntimeException $e) {
        if ($e->getCode() !== 409) {
            throw $e;
        }
        Svar::feil($e->getMessage(), 409);
    }

    revider('pamelding_flyttet', 'booking', $bookingId, [
        'fra' => (int) $b['course_session_id'],
        'til' => $tilOkt,
        'via' => 'min_side_avlyst',
    ]);

    // Samme beskjed som naar verkstedet flytter (pamelding.php flytt).
    $tilTekst = Booking::norskDato((string) $okt['start_tid']);
    if (trim((string) ($b['epost'] ?? '')) !== '') {
        Varsel::mal('pamelding_flyttet', ['epost' => $b['epost']], [
            'navn'  => (string) ($b['navn'] ?: ''),
            'kurs'  => (string) $okt['tittel'],
            'fra'   => $b['fra_tid'] ? Booking::norskDato((string) $b['fra_tid']) : '',
            'til'   => $tilTekst,
            'lenke' => Config::nettsted() . '/min-side',
        ], 'booking', $bookingId);
    }

    Svar::ok(['naar' => $tilTekst, 'beskjed' => 'Plassen er flyttet til ' . $tilTekst . '.']);
}

Svar::feil('Ukjent handling.');
