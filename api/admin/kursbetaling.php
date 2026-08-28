<?php
/**
 * Betaling registrert for haand paa en paamelding.
 *
 *   GET  ?bookingId=5             betalingshistorikken for én paamelding
 *   POST handling=registrer       { bookingId, belop?, maate, kommentar? }
 *   POST handling=annuller        { betalingId, grunn? }
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * Kom pengene kontant, paa faktura eller paa Vipps i verkstedet, sto det en
 * tekst i «betalt_maate» paa bookingen og ikke noe mer. Ingen rad i payments.
 * Da fantes det ikke noe svar paa hvem som registrerte betalingen, naar, eller
 * hvor mye — og en feilregistrering kunne bare skrives over, ikke angres.
 *
 * Her lages en ekte betaling: «type = manuell», samme tabell som Vipps bruker,
 * med hvem, naar, maate og kommentar. De kan aldri forveksles: Vipps-radene
 * har alltid en «vipps_reference» som peker paa en betaling i Vipps, og disse
 * har en referanse som begynner paa MANUELL-.
 *
 * ── Regelen for status ────────────────────────────────────────────────
 *
 * Bookingen er betalt naar summen av betalingene som staar — de som ikke er
 * annullert — dekker beloepet. Ikke ellers. Den samme regelen gjelder etter en
 * registrering og etter en annullering, saa de to kan aldri komme i utakt.
 *
 * ── Angre ─────────────────────────────────────────────────────────────
 *
 * En feilregistrert betaling slettes ikke. Raden er et bilag. Den annulleres,
 * og da staar bade at den var der og at den ble trukket tilbake.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

// Reglene ligger i app/lib/booking.php, sammen med resten av pengelogikken.
// Her staar bare det som hoerer til en foresporsel: hvem som spor, hva de
// sendte, og hva de faar tilbake.

// ---------------------------------------------------------------- lesing
if (Foresporsel::metode() === 'GET') {
    $bookingId = Foresporsel::heltall('bookingId');
    $b = DB::en(
        'SELECT b.id, b.belop_ore, b.status, b.payment_id,
                COALESCE(m.navn, b.gjest_navn) AS navn
           FROM bookings b
      LEFT JOIN members m ON m.id = b.member_id
          WHERE b.id = :i',
        ['i' => $bookingId]
    );
    if ($b === null) {
        Svar::feil('Fant ikke påmeldingen.', 404);
    }

    $bet = Booking::betalingerFor($bookingId);
    $skyldig = max(0, (int) $b['belop_ore'] - $bet['sum']);

    Svar::json([
        'navn'      => (string) $b['navn'],
        'skalBetale'=> Booking::kroner((int) $b['belop_ore']),
        'betalt'    => Booking::kroner($bet['sum']),
        'skyldig'   => Booking::kroner($skyldig),
        'skyldigOre'=> $skyldig,
        'gjortOpp'  => $skyldig === 0,
        'maater'    => Booking::MAATER,
        'historikk' => array_map(static fn($r) => [
            'id'         => (int) $r['id'],
            'belop'      => Booking::kroner((int) $r['belop_ore']),
            // Vipps eller for haand — det skal aldri vaere tvil om hvilken.
            'manuell'    => (string) $r['type'] === 'manuell',
            'maate'      => (string) $r['type'] === 'manuell'
                              ? ((string) ($r['maate'] ?? '') ?: 'Ukjent')
                              : 'Vipps',
            'kommentar'  => (string) ($r['kommentar'] ?? ''),
            'av'         => (string) ($r['registrert_navn'] ?? ''),
            'naar'       => Booking::norskDato((string) $r['created_at']),
            'annullert'  => $r['annullert_at'] !== null,
            'annullertNaar' => $r['annullert_at'] !== null
                              ? Booking::norskDato((string) $r['annullert_at']) : '',
            // Bare manuelle betalinger som staar, kan annulleres herfra.
            // En Vipps-betaling refunderes under Okonomi.
            'kanAnnulleres' => (string) $r['type'] === 'manuell'
                              && $r['annullert_at'] === null
                              && (string) $r['status'] === 'betalt',
            'referanse'  => (string) $r['vipps_reference'],
        ], $bet['rader']),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

switch (Foresporsel::tekst('handling', 'registrer')) {

    // -------------------------------------------------------- registrer
    case 'registrer':
        $bookingId = Foresporsel::heltall('bookingId');
        $b = DB::en(
            'SELECT b.id, b.belop_ore, b.status, b.member_id, b.course_id,
                    COALESCE(m.navn, b.gjest_navn) AS navn
               FROM bookings b
          LEFT JOIN members m ON m.id = b.member_id
              WHERE b.id = :i',
            ['i' => $bookingId]
        );
        if ($b === null) {
            Svar::feil('Fant ikke påmeldingen.', 404);
        }
        if ((string) $b['status'] === 'avbestilt') {
            Svar::feil('Denne påmeldingen er avbestilt. Legg personen inn på nytt i stedet.');
        }

        // Er plassen alt gjort opp gjennom Vipps, skal ingen registrere den
        // en gang til for haand. Da ville beloepet staatt to ganger i
        // regnskapet, og ingen ville sett hvorfor.
        // Bade den nye koblingen og den gamle. «bookings.payment_id» har
        // pekt riktig hele tiden, og migrasjon 084 fyller booking_id av den —
        // men en rad som kommer til mellom to kall skal ogsaa fanges.
        $viaVipps = DB::en(
            "SELECT p.id FROM payments p
         LEFT JOIN bookings b ON b.payment_id = p.id
              WHERE (p.booking_id = :b OR b.id = :b2) AND p.type <> 'manuell'
                AND p.status IN ('autorisert','betalt','delvis_refundert')
              LIMIT 1",
            ['b' => $bookingId, 'b2' => $bookingId]
        );
        if ($viaVipps !== null) {
            Svar::feil('Denne er betalt gjennom Vipps. Bruk refusjon under Økonomi hvis noe skal rettes.');
        }

        $maate = Foresporsel::tekst('maate');
        if (!in_array($maate, Booking::MAATER, true)) {
            Svar::feil('Velg hvordan pengene kom inn.');
        }

        // Tomt beloep betyr «det som staar igjen». «Gratis» er null kroner
        // uansett hva som staar i feltet — ellers kunne en fribillett vist en
        // sum i regnskapet.
        $bet     = Booking::betalingerFor($bookingId);
        $skyldig = max(0, (int) $b['belop_ore'] - $bet['sum']);
        $raa     = trim(Foresporsel::tekst('belop'));

        if ($maate === 'Gratis') {
            $belop = 0;
        } elseif ($raa === '') {
            $belop = $skyldig;
        } else {
            $belop = max(0, (int) preg_replace('/[^\d]/', '', $raa)) * 100;
        }

        if ($belop > 10000000) {
            Svar::feil('Beløpet må være under 100 000 kroner.');
        }
        if ($belop === 0 && $maate !== 'Gratis') {
            Svar::feil($skyldig === 0
                ? 'Denne er alt gjort opp.'
                : 'Skriv beløpet, eller velg «Gratis».');
        }

        $kommentar = mb_substr(trim(Foresporsel::tekst('kommentar')), 0, 300);

        $betalingId = DB::iTransaksjon(static function () use ($b, $bookingId, $belop, $maate, $kommentar, $admin): int {
            return DB::settInn('payments', [
                // «MANUELL-» foran gjor det umulig aa forveksle raden med en
                // betaling som faktisk ligger i Vipps.
                'vipps_reference' => 'MANUELL-' . Vipps::nyReferanse('K'),
                'type'            => 'manuell',
                'formal'          => 'booking',
                'member_id'       => $b['member_id'] !== null ? (int) $b['member_id'] : null,
                'booking_id'      => $bookingId,
                'registrert_av'   => (int) $admin['id'],
                'maate'           => $maate,
                'kommentar'       => $kommentar !== '' ? $kommentar : null,
                'belop_ore'       => $belop,
                'status'          => 'betalt',
                'idempotency_key' => Vipps::uuid(),
            ]);
        });

        $etter = Booking::settBetaltStatus($bookingId);

        revider('betaling_registrert', 'booking', $bookingId, [
            'betaling' => $betalingId, 'belop_ore' => $belop, 'maate' => $maate,
        ]);

        $rest = max(0, (int) $b['belop_ore'] - $etter['sum']);
        Svar::ok([
            'betalingId' => $betalingId,
            'status'     => $etter['status'],
            'beskjed'    => $maate === 'Gratis'
                ? $b['navn'] . ' står som gratis. Det er registrert på deg.'
                : Booking::kroner($belop) . ' er registrert på ' . $b['navn'] . ' — ' . strtolower($maate) . '.'
                  . ($rest > 0 ? ' ' . Booking::kroner($rest) . ' står igjen.' : ''),
        ]);

    // --------------------------------------------------------- annuller
    case 'annuller':
        $betalingId = Foresporsel::heltall('betalingId');
        $p = DB::en('SELECT * FROM payments WHERE id = :i', ['i' => $betalingId]);
        if ($p === null) {
            Svar::feil('Fant ikke betalingen.', 404);
        }
        // Bare de som er lagt inn for haand. En betaling i Vipps annulleres
        // ikke med et tastetrykk hos oss — den refunderes, og det gjores
        // under Okonomi.
        if ((string) $p['type'] !== 'manuell') {
            Svar::feil('Dette er en Vipps-betaling. Den refunderes under Økonomi.');
        }
        if ($p['annullert_at'] !== null) {
            Svar::feil('Denne er alt annullert.');
        }
        if ($p['booking_id'] === null) {
            Svar::feil('Denne betalingen hører ikke til en påmelding.');
        }

        $grunn = mb_substr(trim(Foresporsel::tekst('grunn')), 0, 300);

        DB::oppdater('payments', [
            // Raden blir staaende. «avbrutt» er statusen for en betaling som
            // ikke gjelder lenger, og den finnes fra for.
            'status'       => 'avbrutt',
            'annullert_at' => gmdate('Y-m-d H:i:s'),
            'annullert_av' => (int) $admin['id'],
            'kommentar'    => $grunn !== ''
                ? trim((string) ($p['kommentar'] ?? '') . ' · Annullert: ' . $grunn)
                : $p['kommentar'],
        ], ['id' => $betalingId]);

        $bookingId = (int) $p['booking_id'];
        $etter = Booking::settBetaltStatus($bookingId);

        revider('betaling_annullert', 'booking', $bookingId, [
            'betaling' => $betalingId, 'belop_ore' => (int) $p['belop_ore'],
        ]);

        Svar::ok([
            'status'  => $etter['status'],
            'beskjed' => Booking::kroner((int) $p['belop_ore']) . ' er annullert. '
                       . ($etter['status'] === 'betalt'
                           ? 'Påmeldingen er fortsatt gjort opp av de andre betalingene.'
                           : 'Påmeldingen står som ubetalt igjen.'),
        ]);

    default:
        Svar::feil('Ukjent handling.');
}
