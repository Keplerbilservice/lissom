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
    public static function ledigePlasser(int $oktId, bool $medLaas = false): int
    {
        // Med $medLaas laases okta for resten av transaksjonen. Uten den leser
        // to samtidige bookinger det samme oyeblikksbildet — begge ser den
        // siste plassen ledig, og begge far den. Kontrollen inne i
        // transaksjonen hjelper ikke naar lesningen ikke tar laas.
        //
        // Utenfor en transaksjon gir FOR UPDATE ingen mening; visningen av
        // «3 plasser igjen» skal heller ikke laase noe.
        $laas = $medLaas && DB::kobling()->inTransaction() ? ' FOR UPDATE' : '';

        $okt = DB::en(
            'SELECT cs.id, cs.manuelt_opptatt, COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :id AND cs.status = \'planlagt\'' . $laas,
            ['id' => $oktId]
        );
        if ($okt === null) {
            return 0;
        }

        // En reservasjon som er lagt inn for haand har ingen frist — den
        // frigis ikke av seg selv. Uten «reservert_til IS NULL» ville
        // nettsiden solgt plassen til noen som staar i verkstedets egen bok.
        $opptatt = (int) DB::verdi(
            "SELECT COALESCE(SUM(antall), 0)
               FROM bookings
              WHERE course_session_id = :id
                AND (status = 'betalt'
                     OR (status = 'reservert'
                         AND (reservert_til IS NULL OR reservert_til > UTC_TIMESTAMP())))",
            ['id' => $oktId]
        );

        // Plasser som er tatt utenfor nettsiden — paamelding i verkstedet, paa
        // telefon eller Instagram. De finnes ikke som bookinger, men de er
        // opptatt, og maa trekkes fra her. Ellers ville nettsiden solgt dem.
        $opptatt += (int) ($okt['manuelt_opptatt'] ?? 0);

        return max(0, (int) $okt['kapasitet'] - $opptatt);
    }

    /**
     * Grupperabatten som gjelder for et kurs med et gitt antall plasser.
     *
     * Bookingsiden viste rabatten, men serveren regnet full pris — kunden saa
     * én sum og ble trukket en annen. Utregningen maa skje her, der belopet
     * faktisk settes; nettleseren skal aldri sende hva noe koster.
     *
     * Flere nivaaer kan treffe samtidig. Da gjelder det beste for kunden.
     */
    public static function rabattProsent(array $kurs, int $antall): float
    {
        // Medlemskap og drop-in er ikke gruppekjop.
        $tema = (string) ($kurs['tema'] ?? '');
        $type = (string) ($kurs['type'] ?? '');
        if ($tema === 'Medlemskap' || $type === 'dropin' || $antall < 2) {
            return 0.0;
        }

        $dreiing = $tema === 'Dreiing'
            || str_contains(mb_strtolower((string) ($kurs['tittel'] ?? '')), 'dreie');

        $gjelder = ['alle', (string) ($kurs['slug'] ?? '')];
        if ($dreiing) {
            $gjelder[] = 'dreiing';
        }

        $plassholdere = implode(',', array_map(static fn($i) => ':g' . $i, array_keys($gjelder)));
        $param = ['a' => $antall];
        foreach ($gjelder as $i => $g) {
            $param['g' . $i] = $g;
        }

        $best = DB::verdi(
            "SELECT MAX(prosent) FROM discount_tiers
              WHERE aktiv = 1 AND min_antall <= :a AND gjelder IN ({$plassholdere})",
            $param
        );

        return max(0.0, min(100.0, (float) ($best ?? 0)));
    }

    /** Belopet for en booking, etter grupperabatt. Alltid hele ore. */
    public static function belopFor(array $kurs, int $antall): array
    {
        $brutto = (int) $kurs['pris_ore'] * $antall;
        $rabatt = self::rabattProsent($kurs, $antall);
        $netto  = (int) round($brutto * (1 - $rabatt / 100));

        return ['brutto' => $brutto, 'rabatt' => $rabatt, 'netto' => $netto];
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

        // Prisen regnes her, ikke i nettleseren. Rabatten som vises paa
        // bookingsiden og den kunden trekkes er naa det samme tallet.
        $pris   = self::belopFor($okt, $antall);
        $belop  = $pris['netto'];
        $rabatt = $pris['rabatt'];
        $referanse = Vipps::nyReferanse();

        // Reservasjonen foerst, i en kort transaksjon. Kallet til Vipps ligger
        // utenfor med vilje: et HTTP-kall kan ta tjue sekunder, og saa lenge
        // skal ingen databaselaas staa aapen.
        $reservasjon = DB::iTransaksjon(static function () use (
            $okt, $oktId, $antall, $navn, $epost, $telefon, $medlemId,
            $folgeMedlem, $gratis, $belop, $rabatt, $referanse
        ): array {
            // Plassen sjekkes en gang til inne i transaksjonen. Uten dette kunne
            // to samtidige bookinger begge se den siste plassen som ledig.
            if (self::ledigePlasser($oktId, true) < $antall) {
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
                // Rabatten lagres med bookingen. Endres nivaaene senere, skal
                // en gammel kvittering fortsatt kunne forklares.
                'rabatt_prosent'    => $rabatt,
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
            // Betalingen kom aldri i gang, saa plassen frigis med det samme.
            // For sto den som reservert til cron ryddet etter tjue minutter —
            // og i de tjue minuttene sa sida «utsolgt» til alle andre, for en
            // betaling som aldri fantes. Er dette den siste plassen paa et
            // populaert kurs, er det tjue minutter for mye.
            DB::oppdater('payments', ['status' => 'feilet'], ['id' => $reservasjon['paymentId']]);
            DB::oppdater('bookings', ['status' => 'avbestilt'], ['id' => $reservasjon['bookingId']]);
            logg_feil('Kunne ikke starte betaling for booking ' . $reservasjon['bookingId'], $e);
            throw new RuntimeException('Fikk ikke startet betalingen. Prøv igjen om litt.');
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

            $kort = DB::en('SELECT id FROM gift_cards WHERE payment_id = :p', ['p' => $betaling['id']]);
            if ($kort !== null) {
                self::aktiverGavekort((int) $kort['id']);
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

    /**
     * Gir gavekortet en kode og gjor det gyldig.
     *
     * Koden settes forst her, ikke ved kjop: ellers kunne noen faatt en
     * gyldig kode ved aa starte en betaling og avbryte den.
     */
    public static function aktiverGavekort(int $kortId): void
    {
        $k = DB::en('SELECT * FROM gift_cards WHERE id = :i', ['i' => $kortId]);
        if ($k === null || $k['status'] !== 'ubetalt') {
            return; // allerede aktivert, eller ukjent
        }

        // Koden skal kunne leses opp over telefon. Derfor ingen tegn som
        // forveksles: verken 0/O, 1/I/L eller 5/S.
        $tegn = 'ABCDEFGHJKMNPQRTUVWXYZ2346789';
        do {
            $kode = 'LIS-';
            for ($i = 0; $i < 9; $i++) {
                if ($i > 0 && $i % 3 === 0) {
                    $kode .= '-';
                }
                $kode .= $tegn[random_int(0, strlen($tegn) - 1)];
            }
        } while (DB::en('SELECT id FROM gift_cards WHERE kode = :k', ['k' => $kode]) !== null);

        DB::oppdater('gift_cards', [
            'kode'      => $kode,
            'saldo_ore' => (int) $k['opprinnelig_ore'],
            'status'    => 'aktivt',
        ], ['id' => $kortId]);

        $belop = self::kroner((int) $k['opprinnelig_ore']);
        $gyldig = self::norskDatoKort((string) $k['gyldig_til']);

        // Til mottakeren, om det er oppgitt en. Ellers til kjoperen.
        $til = $k['mottaker_epost'] ?: $k['kjoper_epost'];
        $hilsen = $k['hilsen'] ? "\n\n«" . $k['hilsen'] . "»\n— " . $k['kjoper_navn'] : '';

        Varsel::epost(
            (string) $til,
            'Gavekort til Lissom Keramikk',
            "Hei!\n\n"
            . "Du har fått et gavekort på {$belop} til Lissom Keramikk."
            . $hilsen . "\n\n"
            . "Koden er: {$kode}\n"
            . "Gyldig til {$gyldig}.\n\n"
            . "Gavekortet kan brukes på kurs, events, medlemskap og verkstedtid. "
            . "Oppgi koden naar du bestiller, eller ta den med i verkstedet.\n\n"
            . 'Hilsen Lissom Keramikk',
            'gift_card',
            $kortId
        );

        // Kjoperen far ogsaa beskjed om at kortet er sendt.
        if ($k['mottaker_epost'] && $k['kjoper_epost'] && $k['mottaker_epost'] !== $k['kjoper_epost']) {
            Varsel::epost(
                (string) $k['kjoper_epost'],
                'Gavekortet er sendt',
                "Hei " . $k['kjoper_navn'] . "!\n\n"
                . "Gavekortet paa {$belop} er sendt til {$k['mottaker_epost']}.\n"
                . "Koden er {$kode}, gyldig til {$gyldig}.\n\n"
                . 'Hilsen Lissom Keramikk',
                'gift_card',
                $kortId
            );
        }
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

    /**
     * 2029-08-21 → «21. august 2029».
     *
     * PHPs date() gir engelske maanedsnavn uansett hva serveren staar til, og
     * «21. August 2029» paa et norsk gavekort ser ut som en feil.
     */
    public static function norskDatoKort(string $dato): string
    {
        // Tidspunkt lagres i UTC. Uten omregningen ville et kurs som slutter
        // like for midnatt norsk tid faatt gaarsdagens dato paa kursbeviset.
        $d = (new DateTimeImmutable($dato, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'));
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];

        return sprintf('%d. %s %d', (int) $d->format('j'), $mnd[(int) $d->format('n') - 1], (int) $d->format('Y'));
    }

    /**
     * Som norskDato, men tar med sluttdagen naar okten gaar over flere dager:
     * «onsdag 9. – torsdag 10. september, 17:00».
     *
     * Dreiekurset gaar to kvelder og er én paamelding. Sto bare startdagen,
     * kunne man tro man booket en enkeltkveld.
     */
    public static function norskPeriode(string $start, ?string $slutt): string
    {
        $fra = self::norskDato($start);
        if ($slutt === null || $slutt === '') {
            return $fra;
        }

        $sone = new DateTimeZone('Europe/Oslo');
        $s = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone($sone);
        $t = (new DateTimeImmutable($slutt, new DateTimeZone('UTC')))->setTimezone($sone);
        if ($s->format('Y-m-d') === $t->format('Y-m-d')) {
            return $fra;
        }

        $dager = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];

        return sprintf(
            '%s %d. – %s %d. %s, %s',
            $dager[(int) $s->format('N') - 1],
            (int) $s->format('j'),
            $dager[(int) $t->format('N') - 1],
            (int) $t->format('j'),
            $mnd[(int) $t->format('n') - 1],
            $s->format('H:i')
        );
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
