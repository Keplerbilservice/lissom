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
        if ($medLaas && DB::kobling()->inTransaction()) {
            DB::en('SELECT id FROM course_sessions WHERE id = :id FOR UPDATE', ['id' => $oktId]);
        }

        // Regnestykket staar ett sted, i ledigePlasserFlere. Sto det ogsaa
        // her, ville de to kunnet svare forskjellig — og den ene brukes til
        // aa vise «3 plasser igjen», den andre til aa selge den siste stolen.
        return self::ledigePlasserFlere([$oktId])[$oktId] ?? 0;
    }

    /**
     * Ledige plasser paa mange okter i én sporring.
     *
     * Katalogen viser hver eneste kursdato med «N plasser igjen». Ett kall per
     * dato ble tre sporringer per dato: 83 datoer ga 249 sporringer paa én
     * sidevisning, og tallet vokser med hver dato som legges ut. Etter at
     * Paint on Pots og drop-in begynte aa folge aapningstidene lages datoene
     * av seg selv, og da vokser det fort.
     *
     * Samme regnestykke som for, i ett svar:
     *
     *   kapasitet (oktas egen, ellers kursets)
     *   − plasser tatt utenfor nettsiden (manuelt_opptatt)
     *   − betalte og gyldige reservasjoner
     *
     * En reservasjon som er lagt inn for haand har ingen frist — den frigis
     * ikke av seg selv. Uten «reservert_til IS NULL» ville nettsiden solgt
     * plassen til noen som staar i verkstedets egen bok.
     *
     * @param list<int> $oktIder
     * @return array<int, int> oktId => ledige
     */
    public static function ledigePlasserFlere(array $oktIder): array
    {
        $ider = array_values(array_unique(array_map('intval', $oktIder)));
        if ($ider === []) {
            return [];
        }

        // Ingen navngitte parametre i en IN-liste: heltallene er allerede
        // castet med intval over, saa de kan staa i SQL-en.
        $inn = implode(',', $ider);

        $ut = [];
        foreach (DB::alle(
            "SELECT cs.id,
                    GREATEST(0,
                        COALESCE(cs.kapasitet, c.kapasitet)
                        - COALESCE(cs.manuelt_opptatt, 0)
                        - COALESCE((SELECT SUM(b.antall) FROM bookings b
                                     WHERE b.course_session_id = cs.id
                                       AND (b.status = 'betalt'
                                            OR (b.status = 'reservert'
                                                AND (b.reservert_til IS NULL
                                                     OR b.reservert_til > UTC_TIMESTAMP())))), 0)
                    ) AS ledige
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt' AND cs.id IN ({$inn})"
        ) as $r) {
            $ut[(int) $r['id']] = (int) $r['ledige'];
        }

        // En okt som ikke er «planlagt» — avlyst, eller som ikke finnes — har
        // ingen ledige plasser. Den staar ikke i svaret over, og skal svare 0
        // slik den gjorde da hvert kall sto for seg.
        foreach ($ider as $i) {
            $ut[$i] ??= 0;
        }

        return $ut;
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
        ?string $folgeMedlem = null,
        string $gavekortKode = '',
        ?string $allergier = null
    ): array {
        // Prisen paa datoen gaar foran kursets, naar den er satt. COALESCE
        // og ikke to sporringer: da er det ett sted prisen kommer fra, og
        // resten av metoden trenger ikke vite at det finnes to.
        $egenPris = DB::harKolonne('course_sessions', 'pris_ore')
            ? 'COALESCE(cs.pris_ore, c.pris_ore)' : 'c.pris_ore';
        // «Gjenstanden betales i verkstedet» (migrasjon 074). Et slikt kurs er
        // gratis med vilje — plassen koster ingenting, og kunden betaler det
        // hen maler naar hen staar der.
        $kassaFelt = DB::harKolonne('courses', 'gjenstand_i_kassa')
            ? 'c.gjenstand_i_kassa' : '0 AS gjenstand_i_kassa';
        $okt = DB::en(
            'SELECT cs.id, cs.course_id, cs.start_tid,
                    c.tittel, ' . $egenPris . ' AS pris_ore, c.type, c.tema, c.slug,
                    ' . $kassaFelt . '
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
        //
        // «Gratis» og «kun for medlemmer» var det samme her: null kroner
        // betydde medlemsarrangement, punktum. Det holdt saa lenge det eneste
        // gratis vi hadde var medlemssamlinger — men Paint on Pots er gratis
        // fordi plassen er gratis, og gjenstanden betales i kassa. Da ble
        // sida staaende med tre datoer og en booking som svarte «kun for
        // medlemmer» til alle som ikke var det.
        $gratis = (int) $okt['pris_ore'] === 0;
        $apentForAlle = (int) ($okt['gjenstand_i_kassa'] ?? 0) === 1;
        if ($gratis && !$apentForAlle && $medlemId === null) {
            throw new RuntimeException('Dette arrangementet er kun for medlemmer.');
        }

        // Prisen regnes her, ikke i nettleseren. Rabatten som vises paa
        // bookingsiden og den kunden trekkes er naa det samme tallet.
        $pris   = self::belopFor($okt, $antall);
        $belop  = $pris['netto'];
        $rabatt = $pris['rabatt'];
        $referanse = Vipps::nyReferanse();

        // Gavekortet. Feltet paa bookingsiden var ikke koblet til noe, saa en
        // kode kunne skrives inn uten at den ble brukt til noe.
        //
        // Beloepet legges paa betalingen og trekkes forst naar den er
        // bekreftet: en booking som blir liggende i Vipps skal ikke spise av
        // saldoen.
        $gavekortId = null;
        $gavekortOre = 0;
        if (!$gratis && trim($gavekortKode) !== '') {
            $kort = self::finnGavekort($gavekortKode);
            if ($kort === null) {
                throw new RuntimeException('Fant ikke gavekortet, eller det er brukt opp.');
            }
            $gavekortId = $kort['id'];
            $gavekortOre = min($kort['saldo_ore'], $belop);
        }
        $aBetale = $belop - $gavekortOre;

        // Reservasjonen foerst, i en kort transaksjon. Kallet til Vipps ligger
        // utenfor med vilje: et HTTP-kall kan ta tjue sekunder, og saa lenge
        // skal ingen databaselaas staa aapen.
        $reservasjon = DB::iTransaksjon(static function () use (
            $okt, $oktId, $antall, $navn, $epost, $telefon, $medlemId,
            $folgeMedlem, $gratis, $belop, $aBetale, $gavekortId, $gavekortOre, $rabatt, $referanse,
            $allergier
        ): array {
            // Plassen sjekkes en gang til inne i transaksjonen. Uten dette kunne
            // to samtidige bookinger begge se den siste plassen som ledig.
            if (self::ledigePlasser($oktId, true) < $antall) {
                throw new RuntimeException('Plassen ble tatt mens du fylte ut skjemaet.');
            }

            $paymentId = null;
            if (!$gratis) {
                $felt = [
                    'vipps_reference' => $referanse,
                    'type'            => 'epayment',
                    'formal'          => $okt['type'] === 'dropin' ? 'dropin' : 'booking',
                    'member_id'       => $medlemId,
                    // Det kunden faktisk skal betale. Beloepet paa bookingen
                    // er hele prisen — differansen er gavekortet.
                    'belop_ore'       => $aBetale,
                    'status'          => 'opprettet',
                    'idempotency_key' => Vipps::uuid(),
                ];
                if (DB::harKolonne('payments', 'gavekort_id')) {
                    $felt['gavekort_id'] = $gavekortId;
                    $felt['gavekort_ore'] = $gavekortOre;
                }
                $paymentId = DB::settInn('payments', $felt);
            }

            $felter = [
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
            ];

            // Kolonnen kommer med migrasjon 057. Er den ikke kjort, skal en
            // booking fortsatt gaa gjennom — vi mister opplysningen, ikke
            // plassen. Skjemaet sier fra om det samme.
            if ($allergier !== null && $allergier !== '' && DB::harKolonne('bookings', 'allergier')) {
                $felter['allergier'] = $allergier;
            }

            $bookingId = DB::settInn('bookings', $felter);

            // Betalingen peker tilbake paa paameldingen.
            //
            // Raden lages foer bookingen — den maa ha en referanse for kunden
            // sendes til Vipps — saa koblingen settes her. Uten den staar
            // betalingshistorikken tom paa en plass som faktisk er betalt,
            // og «Betaling» i Paameldte sier «ingen betaling registrert» om
            // penger som er kommet inn.
            if ($paymentId !== null && DB::harKolonne('payments', 'booking_id')) {
                DB::oppdater('payments', ['booking_id' => $bookingId], ['id' => $paymentId]);
            }

            return ['bookingId' => $bookingId, 'paymentId' => $paymentId];
        });

        if ($gratis) {
            self::sendBekreftelse($reservasjon['bookingId']);
            return ['redirectUrl' => '', 'referanse' => '', 'bookingId' => $reservasjon['bookingId']];
        }

        // Dekker gavekortet hele prisen, er det ingenting aa betale. Aa sende
        // noen til Vipps for null kroner er en omvei til en feilmelding.
        //
        // Under én krone gjelder det samme: Vipps godtar ikke beloepet, og
        // det finnes ingen vei videre. Da er resten dekket. Aa runde opp
        // ville vaert aa kreve mer enn kunden skylder.
        if ($aBetale < Vipps::MINSTE_BELOP_ORE) {
            self::markerBetalt($referanse);
            return ['redirectUrl' => '', 'referanse' => $referanse, 'bookingId' => $reservasjon['bookingId']];
        }

        try {
            $betaling = Vipps::opprettBetaling(
                $referanse,
                $aBetale,
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

            // Gavekortet trekkes her, ikke naar ordren ble opprettet. En
            // handlekurv som blir forlatt i Vipps skal ikke spise av saldoen.
            self::trekkGavekort((int) $betaling['id']);

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

            // Et medlemskap uten fast trekk. Betalingen peker paa raden i
            // «subscriptions», og medlemskapet slaas paa naar den er i havn.
            // Uten dette ble medlemmet staaende som «venter» selv om pengene
            // var betalt.
            $ab = DB::en(
                'SELECT subscription_id FROM payments WHERE id = :p AND subscription_id IS NOT NULL',
                ['p' => $betaling['id']]
            );
            if ($ab !== null) {
                Medlemskap::betaltEngangs((int) $ab['subscription_id']);
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
     * Finner et gavekort som kan brukes naa.
     *
     * Koden leses opp over telefon og skrives inn for haand, saa den taaler
     * smaa bokstaver og manglende bindestreker. Alt annet maa stemme:
     * kortet maa vaere aktivt, ha saldo, og ikke ha gaatt ut paa dato.
     *
     * @return array{id:int,kode:string,saldo_ore:int}|null
     */
    public static function finnGavekort(string $kode): ?array
    {
        $rent = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $kode) ?? '');
        if ($rent === '' || mb_strlen($rent) > 32) {
            return null;
        }
        // Koden lagres med bindestreker. Vi sammenligner uten, saa «lis abc
        // def ghj» og «LIS-ABC-DEF-GHJ» er samme kort.
        $rad = DB::en(
            "SELECT id, kode, saldo_ore, status, gyldig_til
               FROM gift_cards
              WHERE REPLACE(REPLACE(UPPER(kode), '-', ''), ' ', '') = :k
              LIMIT 1",
            ['k' => $rent]
        );
        if ($rad === null || $rad['status'] !== 'aktivt') {
            return null;
        }
        if ((int) $rad['saldo_ore'] <= 0) {
            return null;
        }
        if ($rad['gyldig_til'] !== null && $rad['gyldig_til'] < gmdate('Y-m-d')) {
            return null;
        }
        return ['id' => (int) $rad['id'], 'kode' => (string) $rad['kode'], 'saldo_ore' => (int) $rad['saldo_ore']];
    }

    /**
     * Trekker beloepet fra kortet, én gang.
     *
     * Kalles naar betalingen er bekreftet — ikke naar ordren opprettes. En
     * handlekurv som blir forlatt i Vipps skal ikke spise av saldoen.
     *
     * Trekket er betinget i selve SQL-en: gaar saldoen ned et annet sted i
     * mellomtiden, blir det null rader, og vi trekker ikke mer enn det som
     * faktisk staar der.
     */
    public static function trekkGavekort(int $paymentId): void
    {
        if (!DB::harKolonne('payments', 'gavekort_id')) {
            return;
        }
        $b = DB::en(
            'SELECT id, gavekort_id, gavekort_ore FROM payments WHERE id = :i',
            ['i' => $paymentId]
        );
        $kortId = (int) ($b['gavekort_id'] ?? 0);
        $belop  = (int) ($b['gavekort_ore'] ?? 0);
        if ($kortId <= 0 || $belop <= 0) {
            return;
        }
        // Hva ble kortet brukt paa? Sporet skal si det — «betaling nr. 7»
        // hjelper ingen som leter etter en ordre. ref_type er en enum fra
        // 001_init med akkurat disse tre verdiene.
        $refType = 'ordre';
        $refId = 0;
        $b = DB::en('SELECT id FROM bookings WHERE payment_id = :p', ['p' => $paymentId]);
        if ($b !== null) {
            $refType = 'booking';
            $refId = (int) $b['id'];
        } else {
            $o = DB::en('SELECT id FROM orders WHERE payment_id = :p', ['p' => $paymentId]);
            if ($o !== null) {
                $refId = (int) $o['id'];
            } else {
                $s = DB::en('SELECT id FROM subscriptions WHERE id = (SELECT subscription_id FROM payments WHERE id = :p)', ['p' => $paymentId]);
                if ($s !== null) {
                    $refType = 'medlemskap';
                    $refId = (int) $s['id'];
                }
            }
        }

        // Allerede trukket? Da staar bruken der fra for, og vi gjor ingenting.
        // Webhooken og returen kan begge komme, og begge kan komme flere
        // ganger.
        if (DB::harTabell('gift_card_uses') && $refId > 0) {
            $alt = DB::en(
                'SELECT id FROM gift_card_uses
                  WHERE gift_card_id = :k AND ref_type = :t AND ref_id = :r',
                ['k' => $kortId, 't' => $refType, 'r' => $refId]
            );
            if ($alt !== null) {
                return;
            }
        }

        $rader = DB::kjor(
            'UPDATE gift_cards SET saldo_ore = saldo_ore - :b
              WHERE id = :i AND saldo_ore >= :b2',
            ['b' => $belop, 'i' => $kortId, 'b2' => $belop]
        )->rowCount();
        if ($rader === 0) {
            logg('Gavekort hadde ikke daekning ved trekk', ['kort' => $kortId, 'belop' => $belop]);
            return;
        }

        if (DB::harTabell('gift_card_uses') && $refId > 0) {
            DB::settInn('gift_card_uses', [
                'gift_card_id' => $kortId,
                'belop_ore'    => $belop,
                'ref_type'     => $refType,
                'ref_id'       => $refId,
            ]);
        }

        // Tomt kort er brukt opp. Da skal det ikke ligge og se gyldig ut.
        DB::kjor("UPDATE gift_cards SET status = 'brukt' WHERE id = :i AND saldo_ore <= 0", ['i' => $kortId]);
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
            . "Oppgi koden når du bestiller, eller ta den med i verkstedet.\n\n"
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
                . "Gavekortet på {$belop} er sendt til {$k['mottaker_epost']}.\n"
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
            . "Vi gir beskjed når de står klare.\n\n"
            . "Nordre Løkkevei 15, 3120 Nøtterøy\n\n"
            . 'Hilsen Lissom Keramikk',
            'order',
            $ordreId
        );

        // En gave krever en handling i verkstedet: pakke inn og skrive
        // kortet. Haken og hilsenen naadde ikke serveren i det hele tatt for
        // 24. august, saa ingen fikk vite det. Varsles bare naar det faktisk
        // er en gave — ellers ville hver eneste bestilling gitt en e-post.
        if ((int) ($o['gave'] ?? 0) === 1) {
            Varsel::tilAdmin(
                'Gave skal pakkes inn — ' . $o['ordrenr'],
                'Bestilling ' . $o['ordrenr'] . ' fra ' . $o['kunde_navn'] . " er merket som gave.\n\n"
                . implode("\n", $liste) . "\n\n"
                . 'Hilsen på kortet: ' . (trim((string) ($o['gave_hilsen'] ?? '')) !== ''
                        ? '«' . $o['gave_hilsen'] . '»'
                        : '(ingen hilsen skrevet)'),
                'order',
                $ordreId
            );
        }
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
    /**
     * «Tirsdag 1. september, 10:00–20:00».
     *
     * Til aapne vinduer: Paint on Pots er lagt ut paa aapningstidene, og da
     * er det ikke et klokkeslett man moeter opp til, men en tid doeren staar
     * aapen. Sto bare starten, saa det ut som at man kom for sent 10:05.
     *
     * Gaar vinduet over midnatt, faller vi tilbake paa norskPeriode() — den
     * kan si «tirsdag 1. – onsdag 2.» slik den alltid har gjort.
     */
    public static function norskSpenn(string $start, string $slutt): string
    {
        $sone = new DateTimeZone('Europe/Oslo');
        $s = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone($sone);
        $t = (new DateTimeImmutable($slutt, new DateTimeZone('UTC')))->setTimezone($sone);
        if ($s->format('Y-m-d') !== $t->format('Y-m-d')) {
            return self::norskPeriode($start, $slutt);
        }
        return self::norskDato($start) . '–' . $t->format('H:i');
    }

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

    // ── Betaling registrert for haand ────────────────────────────────────
    //
    // Kom pengene kontant, paa faktura eller paa Vipps i verkstedet, sto det
    // en tekst i «betalt_maate» paa bookingen og ikke noe mer. Ingen rad i
    // payments. Da fantes det ikke noe svar paa hvem som registrerte
    // betalingen, naar, eller hvor mye — og en feilregistrering kunne bare
    // skrives over, ikke angres.
    //
    // Reglene staar her og ikke i endepunktet, slik resten av pengelogikken
    // gjor: da kan de testes, og da kan bare ett sted svare paa hva som er
    // betalt.

    /** Maatene en betaling kan komme paa naar den legges inn for haand. */
    public const MAATER = ['Kontant', 'Vipps i verkstedet', 'Faktura', 'Bankoverføring', 'Gratis'];

    /**
     * Betalingene som gjelder én paamelding, og summen av dem.
     *
     * Annullerte teller ikke i summen, men blir staaende i lista — det er
     * hele poenget med aa annullere framfor aa slette. Raden er et bilag.
     *
     * @return array{rader: list<array<string,mixed>>, sum: int}
     */
    public static function betalingerFor(int $bookingId): array
    {
        // Kolonnene kommer med migrasjon 084. Uten dem finnes det ingen
        // manuelle betalinger aa hente, og da skal dette svare tomt framfor
        // aa doe paa en kolonne som ikke er der.
        if (!DB::harKolonne('payments', 'booking_id')) {
            return ['rader' => [], 'sum' => 0];
        }

        // To veier inn til den samme raden, og det er med vilje.
        //
        // «payments.booking_id» kom med migrasjon 084 og er den vi vil ha.
        // Men «bookings.payment_id» har pekt paa Vipps-betalingen siden dag
        // én, og 084 fylte booking_id av den bare for de radene som fantes da
        // migrasjonen kjorte. En Vipps-betaling som kom til etterpaa, og for
        // rettelsen i reserverOgBetal, har fortsatt bare den gamle pekeren.
        //
        // Leser vi bare den nye, staar en betalt Vipps-plass som ubetalt her
        // — og det var noeyaktig det som skjedde. Vi leser begge.
        $rader = DB::alle(
            'SELECT p.id, p.vipps_reference, p.type, p.belop_ore, p.status, p.maate,
                    p.kommentar, p.annullert_at, p.created_at,
                    p.registrert_av, m.navn AS registrert_navn
               FROM payments p
          LEFT JOIN members m ON m.id = p.registrert_av
              WHERE p.booking_id = :b
                 OR p.id = (SELECT payment_id FROM bookings WHERE id = :b2)
           ORDER BY p.id',
            ['b' => $bookingId, 'b2' => $bookingId]
        );

        $sum = 0;
        foreach ($rader as $r) {
            if ($r['annullert_at'] === null
                && in_array((string) $r['status'], ['betalt', 'autorisert', 'delvis_refundert'], true)) {
                $sum += (int) $r['belop_ore'];
            }
        }

        return ['rader' => $rader, 'sum' => $sum];
    }

    /**
     * Setter status paa paameldingen etter det som faktisk er betalt.
     *
     * Ett sted, saa registrering og annullering aldri kan svare forskjellig:
     * paameldingen er betalt naar summen av betalingene som staar dekker
     * beloepet, og ikke ellers.
     *
     * Avbestilte og «ikke mott» roeres ikke. En som ikke kom, skal ikke bli
     * «reservert» igjen fordi noen annullerte en betaling.
     *
     * @return array{status: string, sum: int, skyldig: int}
     */
    public static function settBetaltStatus(int $bookingId): array
    {
        $b = DB::en('SELECT id, belop_ore, status FROM bookings WHERE id = :i', ['i' => $bookingId]);
        if ($b === null) {
            return ['status' => 'reservert', 'sum' => 0, 'skyldig' => 0];
        }

        $bet = self::betalingerFor($bookingId);

        // Den nyeste manuelle betalingen som fortsatt staar bestemmer hva som
        // vises som betalingsmaate. Bare manuelle: en Vipps-rad har ingen
        // «maate», og ville toemt feltet — og Paameldte viser uansett «Vipps»
        // naar det finnes en referanse.
        $siste = null;
        foreach ($bet['rader'] as $r) {
            if ((string) $r['type'] === 'manuell' && $r['annullert_at'] === null
                && (string) $r['status'] === 'betalt') {
                $siste = $r;
            }
        }

        $ny = (string) $b['status'];
        if (in_array($ny, ['betalt', 'reservert'], true)) {
            $ny = $bet['sum'] >= (int) $b['belop_ore'] ? 'betalt' : 'reservert';
        }

        // «payment_id» og «betalt_maate» roeres bare naar det finnes en
        // manuell betaling aa peke paa, eller naar den siste ble annullert.
        // Ellers ville en Vipps-booking mistet pekeren sin.
        $data = ['status' => $ny];
        if ($siste !== null) {
            $data['payment_id']   = (int) $siste['id'];
            $data['betalt_maate'] = $siste['maate'];
        } elseif (self::harManuell($bet['rader'])) {
            // Alle de manuelle er annullert. Da skal ikke pekeren staa igjen
            // paa en betaling som ikke gjelder.
            $data['payment_id']   = null;
            $data['betalt_maate'] = null;
        }

        DB::oppdater('bookings', $data, ['id' => $bookingId]);

        return [
            'status'  => $ny,
            'sum'     => $bet['sum'],
            'skyldig' => max(0, (int) $b['belop_ore'] - $bet['sum']),
        ];
    }

    /** @param list<array<string,mixed>> $rader */
    private static function harManuell(array $rader): bool
    {
        foreach ($rader as $r) {
            if ((string) $r['type'] === 'manuell') {
                return true;
            }
        }
        return false;
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
