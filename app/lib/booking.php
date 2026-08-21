<?php
/**
 * Booking av kurs, events og drop-in.
 *
 * Prinsippet gjennom hele fila: nettleseren sier HVA som skal bookes, aldri
 * HVA DET KOSTER. Prisen slås opp i databasen hver gang. Ellers kunne hvem som
 * helst endret beløpet i utviklerverktøyet før betaling.
 */

declare(strict_types=1);

final class Booking
{
    /** Ubetalte reservasjoner holdes så lenge, så plassen ikke låses av folk som ombestemte seg. */
    private const RESERVASJON_MINUTTER = 20;

    /**
     * Hvor mange plasser er igjen på en økt?
     *
     * Reserverte plasser teller med — ellers kunne to personer betalt for den
     * siste plassen samtidig.
     */
    public static function ledigePlasser(int $oktId): int
    {
        $okt = DB::en(
            'SELECT cs.id, COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :id AND cs.status = \'planlagt\'',
            ['id' => $oktId]
        );
        if ($okt === null) {
            return 0;
        }

        $opptatt = (int) DB::verdi(
            "SELECT COALESCE(SUM(antall), 0)
               FROM bookings
              WHERE course_session_id = :id
                AND (status = 'betalt'
                     OR (status = 'reservert' AND reservert_til > UTC_TIMESTAMP()))",
            ['id' => $oktId]
        );

        return max(0, (int) $okt['kapasitet'] - $opptatt);
    }

    /**
     * Oppretter en reservasjon og starter betaling i Vipps.
     *
     * @return array{redirectUrl:string,referanse:string,bookingId:int}
     */
    public static function reserverOgBetal(
        int $oktId,
        int $antall,
        string $navn,
        string $epost,
        string $telefon,
        ?int $medlemId,
        ?string $folgeMedlem = null
    ): array {
        $okt = DB::en(
            'SELECT cs.id, cs.course_id, cs.start_tid,
                    c.tittel, c.pris_ore, c.type, c.tema, c.slug
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :id
                AND cs.status = \'planlagt\'
                AND c.status = \'publisert\'',
            ['id' => $oktId]
        );

        if ($okt === null) {
            throw new RuntimeException('Denne datoen kan ikke bookes.');
        }
        if ($okt['start_tid'] < gmdate('Y-m-d H:i:s')) {
            throw new RuntimeException('Denne datoen har vært.');
        }
        if (self::ledigePlasser($oktId) < $antall) {
            throw new RuntimeException('Det er ikke nok ledige plasser igjen.');
        }

        // Medlemsarrangementer er gratis, men krever aktivt medlemskap.
        $gratis = (int) $okt['pris_ore'] === 0;
        if ($gratis && $medlemId === null) {
            throw new RuntimeException('Dette arrangementet er kun for medlemmer.');
        }

        $belop = (int) $okt['pris_ore'] * $antall;
        $referanse = Vipps::nyReferanse();

        // Reservasjonen foerst, i en kort transaksjon. Kallet til Vipps ligger
        // utenfor med vilje: et HTTP-kall kan ta tjue sekunder, og saa lenge
        // skal ingen databaselaas staa aapen.
        $reservasjon = DB::iTransaksjon(static function () use (
            $okt, $oktId, $antall, $navn, $epost, $telefon, $medlemId,
            $folgeMedlem, $gratis, $belop, $referanse
        ): array {
            // Plassen sjekkes en gang til inne i transaksjonen. Uten dette kunne
            // to samtidige bookinger begge se den siste plassen som ledig.
            if (self::ledigePlasser($oktId) < $antall) {
                throw new RuntimeException('Plassen ble tatt mens du fylte ut skjemaet.');
            }

            $paymentId = null;
            if (!$gratis) {
                $paymentId = DB::settInn('payments', [
                    'vipps_reference' => $referanse,
                    'type'            => 'epayment',
                    'formal'          => $okt['type'] === 'dropin' ? 'dropin' : 'booking',
                    'member_id'       => $medlemId,
                    'belop_ore'       => $belop,
                    'status'          => 'opprettet',
                    'idempotency_key' => Vipps::uuid(),
                ]);
            }

            $bookingId = DB::settInn('bookings', [
                'course_id'         => $okt['course_id'],
                'course_session_id' => $oktId,
                'member_id'         => $medlemId,
                'gjest_navn'        => $navn,
                'gjest_epost'       => $epost !== '' ? $epost : null,
                'gjest_telefon'     => $telefon !== '' ? $telefon : null,
                'antall'            => $antall,
                'belop_ore'         => $belop,
                'status'            => $gratis ? 'betalt' : 'reservert',
                'payment_id'        => $paymentId,
                'folge_medlem'      => $folgeMedlem,
                'reservert_til'     => $gratis ? null
                    : gmdate('Y-m-d H:i:s', time() + self::RESERVASJON_MINUTTER * 60),
            ]);

            return ['bookingId' => $bookingId, 'paymentId' => $paymentId];
        });

        if ($gratis) {
            self::sendBekreftelse($reservasjon['bookingId']);
            return ['redirectUrl' => '', 'referanse' => '', 'bookingId' => $reservasjon['bookingId']];
        }

        try {
            $betaling = Vipps::opprettBetaling(
                $referanse,
                $belop,
                mb_substr($okt['tittel'], 0, 100),
                Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
                $telefon
            );
        } catch (Throwable $e) {
            // Reservasjonen staar igjen som ubetalt og frigis av cron etter
            // tjue minutter. Vi trenger ikke rydde her.
            DB::oppdater('payments', ['status' => 'feilet'], ['id' => $reservasjon['paymentId']]);
            logg_feil('Kunne ikke starte betaling for booking ' . $reservasjon['bookingId'], $e);
            throw new RuntimeException('Fikk ikke startet betalingen. Prov igjen om litt.');
        }

        DB::oppdater('payments', ['status' => 'venter'], ['id' => $reservasjon['paymentId']]);

        return [
            'redirectUrl' => $betaling['url'],
            'referanse'   => $referanse,
            'bookingId'   => $reservasjon['bookingId'],
        ];
    }

    /**
     * Markerer en booking som betalt. Kalles fra webhook og fra returen —
     * begge kan komme først, og begge kan komme flere ganger.
     */
    public static function markerBetalt(string $referanse): bool
    {
        return (bool) DB::iTransaksjon(static function () use ($referanse): bool {
            $betaling = DB::en(
                'SELECT id, status, belop_ore FROM payments WHERE vipps_reference = :r FOR UPDATE',
                ['r' => $referanse]
            );
            if ($betaling === null || $betaling['status'] === 'betalt') {
                return false; // ukjent, eller allerede håndtert
            }

            DB::oppdater('payments', ['status' => 'betalt'], ['id' => $betaling['id']]);

            // En betaling hoerer enten til en booking eller en butikkordre.
            // Begge kommer inn her, fra webhook og fra returen.
            $booking = DB::en('SELECT id FROM bookings WHERE payment_id = :p', ['p' => $betaling['id']]);
            if ($booking !== null) {
                DB::oppdater('bookings', [
                    'status'        => 'betalt',
                    'reservert_til' => null,
                ], ['id' => $booking['id']]);

                self::sendBekreftelse((int) $booking['id']);
                return true;
            }

            $ordre = DB::en('SELECT id FROM orders WHERE payment_id = :p', ['p' => $betaling['id']]);
            if ($ordre !== null) {
                DB::oppdater('orders', ['status' => 'betalt'], ['id' => $ordre['id']]);
                self::sendOrdrebekreftelse((int) $ordre['id']);
                return true;
            }

            return false;
        });
    }

    /** Legger kvitteringen i varselkøen. Cron sender den. */
    public static function sendBekreftelse(int $bookingId): void
    {
        $b = DB::en(
            'SELECT b.*, c.tittel, cs.start_tid,
                    m.navn AS m_navn, m.epost AS m_epost, m.telefon AS m_telefon
               FROM bookings b
               JOIN courses c ON c.id = b.course_id
          LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
          LEFT JOIN members m ON m.id = b.member_id
              WHERE b.id = :id',
            ['id' => $bookingId]
        );
        if ($b === null) {
            return;
        }

        Varsel::mal('ordrebekreftelse', [
            'epost'   => $b['m_epost'] ?? $b['gjest_epost'],
            'telefon' => $b['m_telefon'] ?? $b['gjest_telefon'],
        ], [
            'navn'  => (string) ($b['m_navn'] ?: $b['gjest_navn']),
            'ordre' => (string) $b['tittel'] . ($b['start_tid'] ? ' — ' . self::norskDato((string) $b['start_tid']) : ''),
            'belop' => self::kroner((int) $b['belop_ore']),
        ], 'booking', $bookingId);
    }

    /** Kvittering for butikkjop, med beskjed om henting. */
    public static function sendOrdrebekreftelse(int $ordreId): void
    {
        $o = DB::en('SELECT * FROM orders WHERE id = :i', ['i' => $ordreId]);
        if ($o === null) {
            return;
        }

        $linjer = DB::alle(
            'SELECT tittel, antall, pris_ore FROM order_lines WHERE order_id = :o ORDER BY id',
            ['o' => $ordreId]
        );

        $liste = [];
        foreach ($linjer as $l) {
            $liste[] = sprintf('%d × %s — %s', $l['antall'], $l['tittel'],
                self::kroner((int) $l['pris_ore'] * (int) $l['antall']));
        }

        Varsel::epost(
            (string) $o['kunde_epost'],
            'Takk for bestillingen hos Lissom!',
            "Hei " . $o['kunde_navn'] . "!\n\n"
            . "Vi har mottatt bestillingen din ({$o['ordrenr']}).\n\n"
            . implode("\n", $liste) . "\n\n"
            . 'Til sammen: ' . self::kroner((int) $o['sum_ore']) . "\n\n"
            . "Varene er klare til henting i verkstedet innen to virkedager. "
            . "Vi gir beskjed naar de staar klare.\n\n"
            . "Nordre Lokkevei 15, 3120 Notteroy\n\n"
            . 'Hilsen Lissom Keramikk',
            'order',
            $ordreId
        );
    }

    public static function kroner(int $ore): string
    {
        return 'kr. ' . number_format($ore / 100, 0, ',', ' ') . ',-';
    }

    /** 2026-09-02 15:30:00 UTC → «onsdag 2. september, 17:30» */
    public static function norskDato(string $utc): string
    {
        $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'));

        $dager = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];

        return sprintf(
            '%s %d. %s, %s',
            $dager[(int) $d->format('N') - 1],
            (int) $d->format('j'),
            $mnd[(int) $d->format('n') - 1],
            $d->format('H:i')
        );
    }
}
