<?php
/**
 * «Klar til henting».
 *
 *   GET                      kursdatoer som er gjennomført
 *   POST handling=meld       si fra at arbeidene er ferdige  { oktId }
 *   POST handling=angre      ta meldingen ned igjen          { oktId }
 *
 * Ett trykk gjør to ting: sender e-post til alle som var på den datoen, og
 * legger ut en linje på lissom.no/ferdigbrent. Linja står i tre uker og
 * forsvinner av seg selv — det er så lenge verkstedet oppbevarer arbeidene.
 *
 * Varselmalen «ferdig_brent» har ligget i basen siden migrasjon 002 uten at
 * noe noen gang sendte den. Det var ingen knapp. Her er den.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (!DB::harKolonne('course_sessions', 'hentemelding_at')) {
    Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet under Oversikt først.');
}

/** Hvor lenge arbeidene oppbevares, og dermed hvor lenge meldingen står. */
const UKER_OPPBEVARING = 3;

// ── Gjennomførte kursdatoer ────────────────────────────────────────────────
if (Foresporsel::metode() === 'GET') {
    // Bare det som faktisk er ferdig, og ikke for lenge siden. Et kurs fra i
    // fjor hører ikke hjemme i en liste over noe som skal gjøres i dag.
    $okter = DB::alle(
        "SELECT cs.id, cs.start_tid, cs.hentemelding_at, c.tittel, c.tema
           FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id
          WHERE cs.status = 'planlagt'
            AND COALESCE(cs.slutt_tid, cs.start_tid) < UTC_TIMESTAMP()
            AND cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 16 WEEK)
            AND c.type <> 'dropin'
       ORDER BY cs.start_tid DESC"
    );

    $ut = [];
    foreach ($okter as $o) {
        $antall = (int) DB::verdi(
            "SELECT COALESCE(SUM(antall), 0) FROM bookings
              WHERE course_session_id = :o AND status IN ('betalt', 'reservert')",
            ['o' => $o['id']]
        );
        // En dato ingen kom på har ingen keramikk aa hente.
        if ($antall === 0) {
            continue;
        }
        $ut[] = [
            'oktId'    => (int) $o['id'],
            'tittel'   => $o['tittel'],
            'tema'     => $o['tema'],
            'naar'     => Booking::norskDato((string) $o['start_tid']),
            'deltakere' => $antall,
            'meldt'    => $o['hentemelding_at'] !== null,
            'meldtNaar' => $o['hentemelding_at']
                ? Booking::norskDato((string) $o['hentemelding_at'])
                : null,
        ];
    }

    Svar::json(['ok' => true, 'okter' => $ut, 'uker' => UKER_OPPBEVARING]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$oktId = Foresporsel::heltall('oktId');
$okt = DB::en(
    "SELECT cs.id, cs.start_tid, cs.hentemelding_at, c.tittel
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
      WHERE cs.id = :i",
    ['i' => $oktId]
);
if ($okt === null) {
    Svar::feil('Fant ikke datoen.', 404);
}

$handling = Foresporsel::tekst('handling');

// ── Ta meldingen ned igjen ─────────────────────────────────────────────────
//
// Trykket du feil kurs, skal linja bort med det samme. E-postene som alt er
// sendt kan vi ikke hente tilbake — det staar i svaret.
if ($handling === 'angre') {
    DB::kjor('UPDATE course_sessions SET hentemelding_at = NULL, hentemelding_av = NULL WHERE id = :i',
             ['i' => $oktId]);
    revider('hentemelding_fjernet', 'course_session', $oktId, ['kurs' => $okt['tittel']]);
    Svar::ok(['beskjed' => 'Meldingen er tatt ned fra nettsiden. E-post som alt er sendt, står som sendt.']);
}

if ($handling !== 'meld') {
    Svar::feil('Ukjent handling.');
}

if ($okt['hentemelding_at'] !== null) {
    Svar::feil('Det er alt meldt fra om denne datoen.');
}

// ── Si fra ─────────────────────────────────────────────────────────────────
$naar = Booking::norskDato((string) $okt['start_tid']);

$mottakere = DB::alle(
    "SELECT COALESCE(m.navn, b.gjest_navn) AS navn,
            COALESCE(m.epost, b.gjest_epost) AS epost,
            COALESCE(m.telefon, b.gjest_telefon) AS telefon
       FROM bookings b
  LEFT JOIN members m ON m.id = b.member_id
      WHERE b.course_session_id = :o AND b.status IN ('betalt', 'reservert')",
    ['o' => $oktId]
);

$sendt = 0;
$uten = 0;
foreach ($mottakere as $mot) {
    if (empty($mot['epost']) && empty($mot['telefon'])) {
        $uten++;
        continue;
    }
    Varsel::mal('ferdig_brent', [
        'navn'    => $mot['navn'] ?: 'du',
        'epost'   => $mot['epost'] ?? null,
        'telefon' => $mot['telefon'] ?? null,
    ], [
        'navn' => $mot['navn'] ?: 'du',
        'kurs' => $okt['tittel'],
        'dato' => $naar,
    ], 'ferdig-brent', $oktId);
    $sendt++;
}

DB::kjor(
    'UPDATE course_sessions SET hentemelding_at = UTC_TIMESTAMP(), hentemelding_av = :a WHERE id = :i',
    ['a' => (Sesjon::medlem()['id'] ?? null), 'i' => $oktId]
);
revider('hentemelding', 'course_session', $oktId, ['kurs' => $okt['tittel'], 'sendt' => $sendt]);

$beskjed = $sendt === 1
    ? 'Én deltaker har fått beskjed.'
    : $sendt . ' deltakere har fått beskjed.';
if ($uten > 0) {
    $beskjed .= ' ' . $uten . ($uten === 1 ? ' står' : ' står') . ' uten e-post og telefon og må kontaktes selv.';
}
$beskjed .= ' Meldingen står på lissom.no/ferdigbrent i ' . UKER_OPPBEVARING . ' uker.';

Svar::ok(['beskjed' => $beskjed, 'sendt' => $sendt]);
