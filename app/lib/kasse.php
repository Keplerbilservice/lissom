<?php
/**
 * Lissom Kasse på iPad: betalingen (kontant, annen måte, delt, Vipps-QR),
 * «Betalte ikke» og kvitteringen. Tilgang og PIN: app/lib/kassetilgang.php
 * (KasseTilgang). «I dag», kurven og oppgjøret: app/lib/kassekurv.php
 * (KasseKurv).
 *
 * Eieren, «ok, bygg det» 8. oktober 2026, til skissen Rm1Casf4CpVv8i5zwyfecX:
 * en egen kasse som står i verkstedet hele tiden, med egen innlogging og PIN,
 * som viser bare det som trengs for å ta betalt.
 *
 * ── Tilgang ───────────────────────────────────────────────────────────
 *
 *   - iPaden logges inn én gang med rollen «kasse» (brukernavn og passord,
 *     api/logg-inn.php). Økta varer i 30 dager (Sesjon::KASSE_VARIGHET_TIMER).
 *   - Kassebrukeren finnes bare i api/kasse/. Alle andre endepunkter ser den
 *     som ikke innlogget (Sesjon::kasseSti()), og krev_admin()/krev_regnskap()
 *     slipper den aldri inn.
 *   - Den som står i kassa låser opp med en 4-sifret PIN (members.kasse_pin_hash,
 *     satt av admin under Brukere). Opplåsingen står på sesjonsraden og går ut
 *     etter 5 minutter uten bruk. Det er serveren som avgjør, ikke iPaden.
 *   - Bryteren «Vis/kasse» (content_blocks). Mangler raden, er kassa av.
 *
 * ── Pengene ───────────────────────────────────────────────────────────
 *
 * Ingen nye pengetabeller. Kassa registrerer gjennom de samme radene og
 * funksjonene som admin:
 *
 *   påmelding    Booking::manuellBetaling() + settBetaltStatus(), med låsen og
 *                stoppVentende() fra «Ta betalt» (kursbetaling.php). Vipps-QR:
 *                KursstartKrav::visQr().
 *   Paint on Pots PopPris::kassa() slår inn gjenstandene; det som er betalt ved
 *                booking trekkes fra av seg selv.
 *   varer        En ordre med betaling, som kassa i admin (api/admin/uttak.php):
 *                D- for kontant og annen måte, Q- for Vipps-QR.
 *   gavekort     Som «Utsted gavekort» i admin: payments + gift_cards, aktivert
 *                med Booking::aktiverGavekort().
 *   timepakke    payments + timepakker, som Timepakke::start(). QR-betalingen
 *                gjøres opp av Booking::markerBetalt() → Timepakke::betalt().
 *
 * Beløpene regnes her, på serveren, fra basen: varepris, nivåpris, skyldig.
 * Nettleseren sender hva som er valgt og beløpet den viste; stemmer ikke det
 * med det serveren regner ut, registreres ingenting.
 *
 * Hver del av et kjøp har sin egen idempotensnøkkel fra iPaden, den samme så
 * lenge kurven er den samme. Den står i payments.idempotency_key (unik), så
 * et dobbelttrykk eller et nytt forsøk aldri lager raden to ganger, og samme
 * nøkkel går til Vipps. qr() og betal() for samme del holder en lås per
 * nøkkel. Før kontant eller annen måte, og før en ny QR-kode, stoppes alle
 * QR-koder denne iPaden har vist som fortsatt venter (referansen KQ-<økt>-…),
 * så kunden aldri kan betale et kjøp to ganger.
 */

declare(strict_types=1);

final class Kasse
{
    /** «Betalt på annen måte» og kontant, slik de føres på raden. */
    public const MAATER = ['Kontant', 'Vipps', 'Faktura'];

    /** Delene i «Del betalingen». Samme som delt oppgjør i admin. */
    public const DELMAATER = ['Kontant', 'Vipps', 'Gavekort'];

    /** Referansen på Vipps-QR fra kassa. */
    public const QR_PREFIKS = 'KQ';

    public static function betalteIkke(int $bookingId, int $personId): array
    {
        $b = KasseKurv::booking($bookingId);
        if ($b === null || !KasseKurv::erPop($b)) {
            throw new RuntimeException('Fant ikke Paint on Pots-bookingen i dag.');
        }
        KursstartKrav::laas($bookingId);
        try {
            $bet = Booking::betalingerFor($bookingId);
            foreach ($bet['rader'] as $r) {
                if ((string) $r['type'] !== 'manuell' && $r['annullert_at'] === null
                    && in_array((string) $r['status'], ['autorisert', 'betalt', 'delvis_refundert'], true)) {
                    throw new RuntimeException('Beløpet ved booking er betalt med Vipps. Det refunderes under Penger i admin.', 409);
                }
            }
            $rader = KasseKurv::betaltVedBooking($bet['rader']);
            if ($rader === []) {
                throw new RuntimeException('Det er ikke ført noe beløp ved booking som kan tas bort.', 409);
            }
            $sum = 0;
            foreach ($rader as $r) {
                DB::oppdater('payments', [
                    'status'       => 'avbrutt',
                    'annullert_at' => gmdate('Y-m-d H:i:s'),
                    'annullert_av' => $personId,
                    'kommentar'    => mb_substr(trim((string) ($r['kommentar'] ?? '') . ' · Annullert: betalte ikke ved booking (kassa)'), 0, 300),
                ], ['id' => (int) $r['id']]);
                Booking::angreGavekort((int) $r['id']);
                $sum += (int) $r['belop_ore'];
            }
            Booking::settBetaltStatus($bookingId);
            revider('betaling_annullert', 'booking', $bookingId, [
                'betalinger' => array_map(static fn(array $r): int => (int) $r['id'], $rader),
                'belop_ore' => $sum, 'grunn' => 'betalte ikke ved booking', 'kasse' => $personId,
            ]);
            return ['annullertOre' => $sum];
        } finally {
            KursstartKrav::slipp($bookingId);
        }
    }


    // ── Idempotens ─────────────────────────────────────────────────────

    /** Gyldig nøkkel fra iPaden (UUID). */
    public static function gyldigNokkel(string $n): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $n) === 1;
    }

    /**
     * Nøkkelen for rad nr. $rad i en del: avledet av delens nøkkel, så den er
     * den samme ved et nytt forsøk. Rad 0 er delens egen nøkkel.
     */
    public static function radNokkel(string $nokkel, int $rad): string
    {
        $nokkel = strtolower($nokkel);
        if ($rad === 0) {
            return $nokkel;
        }
        $h = hash('sha256', $nokkel . '|' . $rad);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-' . dechex(8 + (hexdec($h[16]) & 3)) . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    /** Betalingsraden med nøkkelen, eller null. */
    private static function radMed(string $nokkel): ?array
    {
        return DB::en('SELECT id, vipps_reference, type, status, belop_ore, order_id, booking_id FROM payments WHERE idempotency_key = :k',
            ['k' => strtolower($nokkel)]);
    }

    /**
     * Nøklene: Vipps-QR for en del bruker delens nøkkel (rad 0). Kontant,
     * annen måte og delt betaling bruker rad 1, 2, … — så en QR-kode som ble
     * vist først og så avbrutt, aldri står i veien for kontanten.
     */
    private static function manuellNokkel(string $nokkel, int $rad = 0): string
    {
        return self::radNokkel($nokkel, 1 + $rad);
    }

    /**
     * Før kontant eller annen måte: en QR-kode for den samme delen som venter,
     * stoppes hos Vipps først, og statusen hentes fra Vipps før vi stoler på
     * noe (samme regel som kursstarten, KursstartKrav::stopp). Ellers kunne
     * kunden skannet den gamle koden etter at kontanten var tatt.
     *
     * @return string «ingen» (ingen QR), «stoppet», eller «betalt» (kunden
     *                rakk å betale med Vipps — da er delen gjort opp)
     */
    private static function stoppQr(string $nokkel): string
    {
        $rad = self::radMed($nokkel);
        if ($rad === null || (string) $rad['type'] !== 'epayment') {
            return 'ingen';
        }
        $status = (string) $rad['status'];
        if (in_array($status, ['betalt', 'autorisert', 'delvis_refundert'], true)) {
            return 'betalt';
        }
        if (!in_array($status, ['opprettet', 'venter'], true)) {
            return 'ingen';
        }
        $ref = (string) $rad['vipps_reference'];
        try {
            $svar = Vipps::avbrytHvisIkkeGodkjent($ref);
        } catch (Throwable $e) {
            logg_feil('Fikk ikke stoppet Vipps-QR fra kassa ' . $ref, $e);
            throw new RuntimeException('Fikk ikke stoppet Vipps-betalingen som venter. Prøv igjen om litt.', KursstartKrav::VIPPS_NEI);
        }
        if ($status === 'opprettet' && $svar['status'] === 404) {
            // Forsøket kom aldri fram til Vipps.
            DB::kjor("UPDATE payments SET status = 'avbrutt' WHERE id = :i AND status = 'opprettet'", ['i' => (int) $rad['id']]);
        } else {
            $t = Vipps::synkroniser($ref);
            if (in_array($t, ['AUTHORIZED', 'CAPTURED'], true)) {
                return 'betalt';
            }
            if (!in_array($t, ['TERMINATED', 'ABORTED', 'EXPIRED'], true)) {
                throw new RuntimeException('Fikk ikke stoppet Vipps-betalingen som venter. Prøv igjen om litt.', KursstartKrav::VIPPS_NEI);
            }
        }
        // Det QR-koden gjaldt, står ikke lenger og venter på penger.
        $pid = (int) $rad['id'];
        DB::kjor("UPDATE orders SET status = 'kansellert' WHERE payment_id = :p AND status = 'ny'", ['p' => $pid]);
        if (DB::harTabell('timepakker')) {
            DB::kjor("UPDATE timepakker SET status = 'avbrutt' WHERE payment_id = :p AND status = 'venter'", ['p' => $pid]);
        }
        revider('kasse_qr_stoppet', 'payment', $pid, ['referanse' => $ref]);
        return 'stoppet';
    }

    /** Var unntaket et brudd på en unik nøkkel (dobbelttrykk som kom samtidig)? */
    private static function erDuplikat(Throwable $e): bool
    {
        return $e instanceof PDOException && (string) $e->getCode() === '23000';
    }

    // ── Ta betalt: kontant og annen måte ───────────────────────────────

    /**
     * Registrerer hele kurven med én måte (Kontant, Vipps til verkstedets
     * nummer eller Faktura), eller som «Del betalingen» når $delt er gitt.
     *
     * @param array<string,mixed> $kurv
     * @param array<string,mixed> $betalerInn
     * @param array<string,mixed> $nokler    del-id => UUID fra iPaden (samme så lenge kurven er den samme)
     * @param array<string,mixed> $forventet del-id => beløpet skjermen viste, i øre
     * @param list<array{maate:string,ore:int,kode?:string}>|null $delt
     * @param array{id:int,navn:string} $person
     * @return array<string,mixed>
     */
    public static function betal(array $kurv, array $betalerInn, string $maate, array $nokler, array $forventet, array $person, ?array $delt = null): array
    {
        $betaler = KasseKurv::betaler($betalerInn);
        $deler = self::sjekkKurv(KasseKurv::deler($kurv, $betaler, $nokler), $nokler, $forventet);

        $betalinger = null;
        if ($delt !== null) {
            if (count($deler) !== 1 || !in_array($deler[0]['type'], ['booking', 'ordre'], true)) {
                throw new RuntimeException('Del betalingen gjelder ett kjøp om gangen: en påmelding eller varer.');
            }
            $betalinger = self::lesDelt($delt, (int) $deler[0]['sumOre']);
        } elseif (!in_array($maate, self::MAATER, true)) {
            throw new RuntimeException('Velg hvordan kunden betaler.');
        }

        $nokkelListe = array_column($deler, 'nokkel');
        $laast = [];
        $oktLaast = false;
        $resultat = ['deler' => [], 'gavekort' => []];
        try {
            foreach ($nokkelListe as $n) {
                self::laasDel($n);
                $laast[] = $n;
            }
            // Øktlåsen etter dellåsene, samme rekkefølge som qr(): en QR-kode
            // som er under opprettelse, blir ferdig før den kan stoppes.
            self::laasOkt();
            $oktLaast = true;
            // Endret pris og rabatt låses per del første gang det tas betalt
            // (rører ingen påmelding eller ordre før betalingen er registrert).
            KasseJustering::laas($deler, $person);
            // QR-koder fra denne iPaden som venter for en annen kurv (for
            // eksempel før «Tilbake»), stoppes hos Vipps før kontanten tas.
            self::stoppOktensQr($nokkelListe);
            foreach ($deler as $d) {
                $rader = $betalinger ?? [['maate' => $maate, 'ore' => (int) $d['sumOre']]];
                $nokkel = (string) $d['nokkel'];
                switch ($d['type']) {
                    case 'booking':
                        self::registrerBooking($d, $rader, $person, $nokkel);
                        break;
                    case 'ordre':
                        self::registrerOrdre($d, $betaler, $rader, $person, $nokkel);
                        break;
                    case 'gavekort':
                        $resultat['gavekort'][] = self::registrerGavekort($d, $betaler, $rader[0]['maate'], $person, $nokkel);
                        break;
                    case 'timepakke':
                        self::registrerTimepakke($d, $rader[0]['maate'], $person, $nokkel);
                        break;
                }
                $resultat['deler'][] = $d['type'];
            }
        } finally {
            if ($oktLaast) {
                self::slippOkt();
            }
            foreach ($laast as $n) {
                self::slippDel($n);
            }
        }
        $sum = array_sum(array_column($deler, 'sumOre'));
        $maateTekst = $delt !== null ? 'Del betalingen' : ($maate === 'Kontant' ? 'Kontant' : ($maate === 'Faktura' ? 'Faktura' : 'Vipps-nummer'));
        revider('kassesalg_ipad', null, null, ['person' => $person['id'], 'sum' => $sum, 'maate' => $maateTekst, 'deler' => $resultat['deler']]);
        $ider = self::betalingIder($deler);
        return $resultat + ['sumOre' => $sum, 'sum' => KasseKurv::kr($sum), 'betalinger' => $ider,
            'endringer' => KasseJustering::kvitteringFor($deler),
            'kvitteringValg' => self::kvitteringValg($betaler, $ider)];
    }

    /**
     * Kurven slik serveren regner den, mot det skjermen viste, del for del.
     *
     * Delene har faste id-er (KasseKurv::deler). En påmelding som er betalt
     * med QR siden skjermen regnet, står med 0 igjen (eller faller ut) — den
     * er gjort opp og tas ut, mens de andre delene sjekkes som før. Alt annet
     * som ikke stemmer, er en kurv som er endret: 409, ingenting registreres.
     *
     * @param list<array<string,mixed>> $deler
     * @param array<string,mixed> $nokler
     * @param array<string,mixed> $forventet
     * @return list<array<string,mixed>> delene som skal gjøres opp, med «nokkel»
     */
    private static function sjekkKurv(array $deler, array $nokler, array $forventet): array
    {
        $endret = static fn(): RuntimeException => new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
        if ($deler === [] && $forventet === []) {
            throw new RuntimeException('Kurven er tom.');
        }
        $perId = [];
        foreach ($deler as $d) {
            $perId[(string) $d['id']] = $d;
        }
        foreach ($forventet as $id => $ore) {
            $id = (string) $id;
            if (isset($perId[$id])) {
                continue;
            }
            // En påmelding som er betalt siden, faller ut av kurven.
            if (!str_starts_with($id, 'booking:') || self::skyldigNaa((int) substr($id, 8)) !== 0) {
                throw $endret();
            }
        }
        $ut = [];
        foreach ($perId as $id => $d) {
            if (!array_key_exists($id, $forventet)) {
                throw $endret();
            }
            $n = $nokler[$id] ?? null;
            if (!is_string($n) || !self::gyldigNokkel($n)) {
                throw $endret();
            }
            if ((int) $d['sumOre'] !== (int) $forventet[$id]) {
                if ($d['type'] === 'booking' && (int) $d['sumOre'] === 0) {
                    continue;   // betalt siden skjermen regnet
                }
                $sum = array_sum(array_column($deler, 'sumOre'));
                throw new RuntimeException('Beløpet er endret til ' . KasseKurv::kr($sum) . '. Se over kurven og prøv igjen.', 409);
            }
            $ut[] = $d + ['nokkel' => strtolower($n)];
        }
        $alle = array_map(static fn($n): string => strtolower((string) $n), array_values($nokler));
        if (count(array_unique($alle)) !== count($alle)) {
            throw $endret();
        }
        return $ut;
    }

    /** Låsen per del (GET_LOCK på nøkkelen): qr() og betal() for samme del går aldri samtidig. */
    private static function laasDel(string $nokkel): void
    {
        if ((int) DB::verdi('SELECT GET_LOCK(:l, 10)', ['l' => 'kasse:' . strtolower($nokkel)]) !== 1) {
            throw new RuntimeException('Et annet trykk for det samme kjøpet er i gang. Prøv igjen.', 409);
        }
    }

    private static function slippDel(string $nokkel): void
    {
        DB::verdi('SELECT RELEASE_LOCK(:l)', ['l' => 'kasse:' . strtolower($nokkel)]);
    }

    /**
     * Referansen på QR-koder fra denne iPaden: «KQ-» og åtte tegn som hører
     * til innloggingen. Da kan kassa finne alle QR-kodene den har vist.
     */
    private static function oktPrefiks(): string
    {
        return self::QR_PREFIKS . '-' . self::oktId();
    }

    /**
     * Stopper alle QR-koder fra denne iPaden som fortsatt venter, unntatt
     * delene i kurven som tas betalt for nå (de håndteres del for del). Har
     * kunden rukket å betale en av dem, registreres ingenting: da er det
     * betalt for et kjøp som ble endret, og det må sjekkes først.
     *
     * @param list<string> $unntatt
     */
    private static function stoppOktensQr(array $unntatt): void
    {
        $unntatt = array_map('strtolower', $unntatt);
        foreach (DB::alle(
            "SELECT idempotency_key FROM payments
              WHERE vipps_reference LIKE :p AND type = 'epayment' AND status IN ('opprettet', 'venter')",
            ['p' => self::oktPrefiks() . '-%']
        ) as $r) {
            $k = strtolower((string) $r['idempotency_key']);
            if (in_array($k, $unntatt, true)) {
                continue;
            }
            if (self::stoppQr($k) === 'betalt') {
                throw new RuntimeException('En Vipps-betaling fra kassa ble nettopp betalt. Sjekk Dagens oppgjør før du tar betalt på nytt.', 409);
            }
        }
    }

    /**
     * Betalingsradene delene ble gjort opp med (kontant, annen måte, delt, QR),
     * til kvitteringen.
     *
     * @param list<array<string,mixed>> $deler
     * @return list<int>
     */
    private static function betalingIder(array $deler): array
    {
        [$fra] = KasseKurv::dagen();
        $ider = [];
        foreach ($deler as $d) {
            $n = (string) $d['nokkel'];
            $param = ['k0' => $n];
            for ($j = 0; $j < 5; $j++) {
                $param['k' . ($j + 1)] = self::manuellNokkel($n, $j);
            }
            foreach (DB::alle(
                "SELECT id FROM payments WHERE idempotency_key IN (:k0, :k1, :k2, :k3, :k4, :k5)
                    AND status IN ('betalt', 'delvis_refundert') AND annullert_at IS NULL",
                $param
            ) as $r) {
                $ider[] = (int) $r['id'];
            }
            if ($d['type'] === 'booking') {
                foreach (DB::alle(
                    "SELECT id FROM payments WHERE booking_id = :b AND vipps_reference LIKE 'KS-QR-%'
                        AND status IN ('betalt', 'delvis_refundert') AND created_at >= :fra",
                    ['b' => (int) $d['bookingId'], 'fra' => $fra]
                ) as $r) {
                    $ider[] = (int) $r['id'];
                }
            }
        }
        return array_values(array_unique($ider));
    }

    /**
     * «Del betalingen»: Kontant, Vipps (mottatt på verkstedets nummer) og
     * gavekort. Summen må være akkurat det som skal betales.
     *
     * @return list<array{maate:string,ore:int,kort?:array}>
     */
    private static function lesDelt(array $delt, int $sum): array
    {
        if ($delt === [] || count($delt) > 5) {
            throw new RuntimeException('Legg inn mellom én og fem deler.');
        }
        $ut = [];
        $total = 0;
        $kort = null;
        foreach ($delt as $d) {
            $m = is_array($d) ? (string) ($d['maate'] ?? '') : '';
            if (!in_array($m, self::DELMAATER, true)) {
                throw new RuntimeException('Velg betalingsmåte på hver del.');
            }
            $o = KasseKurv::ore($d['belop'] ?? '');
            if ($o === null || $o <= 0) {
                continue;
            }
            $rad = ['maate' => $m, 'ore' => $o];
            if ($m === 'Gavekort') {
                if ($kort !== null) {
                    throw new RuntimeException('Bare ett gavekort per betaling.');
                }
                $kort = Booking::finnGavekort((string) ($d['kode'] ?? ''));
                if ($kort === null) {
                    throw new RuntimeException('Fant ikke gavekortet. Sjekk koden — den kan være brukt opp eller gått ut på dato.');
                }
                if ($o > $kort['saldo_ore']) {
                    throw new RuntimeException('Gavekortet har bare ' . Booking::kroner($kort['saldo_ore'])
                        . ' igjen. Sett ned beløpet på gavekortdelen.');
                }
                $rad['kort'] = $kort;
            }
            $total += $o;
            $ut[] = $rad;
        }
        if (count($ut) < 2) {
            throw new RuntimeException('Fyll inn minst to deler. Bruk vanlig betalingsregistrering for én betalingsmåte.');
        }
        if ($total !== $sum) {
            throw new RuntimeException('Delene er til sammen ' . Booking::kroner($total) . ', men det står '
                . Booking::kroner($sum) . ' igjen å betale.');
        }
        return $ut;
    }

    /**
     * Påmeldingen: gjenstandene slås inn (Paint on Pots), og det som står igjen
     * registreres som i «Ta betalt» (api/admin/kursbetaling.php).
     *
     * @param list<array{maate:string,ore:int,kort?:array}> $rader
     */
    private static function registrerBooking(array $d, array $rader, array $person, string $nokkel): void
    {
        $bid = (int) $d['bookingId'];
        if (self::radMed(self::manuellNokkel($nokkel)) !== null) {
            return;   // alt registrert (nytt forsøk med samme nøkkel)
        }
        KursstartKrav::laas($bid);
        try {
            $stopp = KursstartKrav::stoppVentende($bid);
            if ($stopp !== null) {
                throw new RuntimeException($stopp, 409);
            }
            if ($d['pop'] !== []) {
                PopPris::kassa($bid, $d['pop'], $person['id']);
            }
            $b = DB::en('SELECT id, belop_ore, status, member_id FROM bookings WHERE id = :i', ['i' => $bid]);
            if ($b === null || !in_array((string) $b['status'], ['betalt', 'reservert'], true)) {
                throw new RuntimeException('Påmeldingen er ikke aktiv.', 409);
            }
            $viaVipps = DB::en(
                "SELECT p.id FROM payments p
             LEFT JOIN bookings b ON b.payment_id = p.id
                 WHERE (p.booking_id = :b OR b.id = :b2) AND p.type <> 'manuell'
                   AND p.status IN ('autorisert','betalt','delvis_refundert')
                 LIMIT 1",
                ['b' => $bid, 'b2' => $bid]
            );
            $skyldig = KursstartKrav::skyldig($bid, (int) $b['belop_ore'], (string) $b['status']);
            if ($skyldig === 0) {
                Booking::settBetaltStatus($bid);
                return;   // gjenstandene er slått inn, og ingenting står igjen
            }
            // Endret pris og rabatt (låst for delen, KasseJustering::laas):
            // trekkes fra her, og skrives til påmeldingen i samme transaksjon
            // som betalingen — ikke før.
            $trekk = KasseJustering::trekkFor($nokkel);
            $etter = $skyldig - $trekk;
            if ($etter < 0) {
                throw new RuntimeException('Beløpet er endret. Se over kurven og prøv igjen.', 409);
            }
            if ($etter === 0) {
                // Rabatten dekker alt: ingen betaling, bare endringen.
                DB::iTransaksjon(static fn(): int => KasseJustering::brukBooking($bid, $nokkel, null));
                Booking::settBetaltStatus($bid);
                return;
            }
            if ($viaVipps !== null && !PopPris::harRest($bid)) {
                throw new RuntimeException('Denne er betalt gjennom Vipps. Bruk refusjon under Økonomi hvis noe skal rettes.', 409);
            }
            if (array_sum(array_column($rader, 'ore')) !== $etter) {
                throw new RuntimeException('Beløpet er endret til ' . KasseKurv::kr($etter) . '. Se over kurven og prøv igjen.', 409);
            }
            $medlemId = $b['member_id'] !== null ? (int) $b['member_id'] : null;
            $kort = null;
            foreach ($rader as $r) {
                if ($r['maate'] === 'Gavekort') {
                    $kort = $r['kort'];
                }
            }
            try {
                DB::iTransaksjon(static function () use ($rader, $bid, $medlemId, $person, $nokkel, $kort): void {
                    $forste = null;
                    if ($kort !== null) {
                        Booking::laasKort([(int) $kort['id']]);
                    }
                    foreach ($rader as $j => $r) {
                        if ($r['maate'] === 'Gavekort') {
                            if (!Booking::gavekortDekker((int) $kort['id'], (int) $r['ore'])) {
                                throw new RuntimeException(Booking::GAVEKORT_AVVIST, 409);
                            }
                            $felt = [
                                'vipps_reference' => 'GAVE-' . strtoupper(bin2hex(random_bytes(4))),
                                'type'            => 'manuell',
                                'formal'          => 'booking',
                                'member_id'       => $medlemId,
                                'maate'           => 'Gavekort',
                                'belop_ore'       => 0,
                                'gavekort_id'     => $kort['id'],
                                'gavekort_ore'    => $r['ore'],
                                'status'          => 'betalt',
                                'booking_id'      => $bid,
                                'registrert_av'   => $person['id'],
                                'idempotency_key' => self::manuellNokkel($nokkel, $j),
                            ];
                            $id = (int) DB::settInn('payments', $felt);
                            $forste ??= $id;
                            Booking::trekkGavekortEllerAvbryt($id);
                            continue;
                        }
                        $id = Booking::manuellBetaling($bid, (int) $r['ore'], $r['maate'], $medlemId, $person['id'],
                            'Kassa', self::manuellNokkel($nokkel, $j));
                        $forste ??= $id;
                    }
                    // Endret pris og rabatt skrives til påmeldingen her, i samme
                    // transaksjon som betalingen.
                    KasseJustering::brukBooking($bid, $nokkel, $forste);
                });
            } catch (PDOException $e) {
                if (self::erDuplikat($e)) {
                    return;   // et samtidig trykk rakk det først
                }
                throw $e;
            }
            Booking::settBetaltStatus($bid);
            revider('betaling_registrert', 'booking', $bid, ['belop_ore' => $etter, 'trukket_ore' => $trekk, 'kasse' => $person['id'],
                'maate' => implode(' + ', array_column($rader, 'maate'))]);
        } finally {
            KursstartKrav::slipp($bid);
        }
    }

    /**
     * Varene (eller Paint on Pots uten booking) som én ordre, slik kassa i
     * admin fører et salg over disk (uttak.php): D-nummer, status «hentet»,
     * én betalingsrad per del med order_id, varene ut av lageret.
     *
     * @param list<array{maate:string,ore:int,kort?:array}> $rader
     */
    private static function registrerOrdre(array $d, array $betaler, array $rader, array $person, string $nokkel): int
    {
        if (self::stoppQr($nokkel) === 'betalt') {
            return (int) (self::radMed($nokkel)['order_id'] ?? 0);   // betalt med Vipps-QR før kontanten
        }
        $finnes = self::radMed(self::manuellNokkel($nokkel));
        if ($finnes !== null) {
            return (int) $finnes['order_id'];
        }
        if (array_sum(array_column($rader, 'ore')) !== (int) $d['sumOre']) {
            throw new RuntimeException('Beløpet er endret. Se over kurven og prøv igjen.', 409);
        }
        $ordrenr = 'D-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $maateTekst = mb_substr(implode(' + ', array_column($rader, 'maate')), 0, 32);
        $kort = null;
        foreach ($rader as $r) {
            if ($r['maate'] === 'Gavekort') {
                $kort = $r['kort'];
            }
        }
        try {
            return (int) DB::iTransaksjon(static function () use ($d, $betaler, $rader, $person, $nokkel, $ordrenr, $maateTekst, $kort): int {
                if ($kort !== null) {
                    Booking::laasKort([(int) $kort['id']]);
                }
                foreach ($d['varer'] as $v) {
                    if ($v['produktId'] === null) {
                        continue;
                    }
                    $lager = DB::verdi('SELECT lager FROM products WHERE id = :i FOR UPDATE', ['i' => $v['produktId']]);
                    if ($lager !== null && $lager !== false && (int) $lager < $v['antall']) {
                        throw new RuntimeException('Det er bare ' . (int) $lager . ' igjen av «' . $v['tittel'] . '».', 409);
                    }
                }
                $kunde = $betaler['navn'] !== '' ? $betaler['navn'] : 'Salg over disk';
                if ($d['formal'] === 'booking' && $betaler['navn'] === '') {
                    $kunde = (string) $d['tittel'];
                }
                $ordreId = DB::settInn('orders', [
                    'ordrenr'       => $ordrenr,
                    'member_id'     => $betaler['medlemId'],
                    'kunde_navn'    => mb_substr($kunde, 0, 191),
                    'kunde_epost'   => $betaler['epost'] !== '' ? mb_substr($betaler['epost'], 0, 191) : null,
                    'kunde_telefon' => $betaler['telefon'] !== '' ? mb_substr($betaler['telefon'], 0, 32) : null,
                    'sum_ore'       => (int) $d['sumOre'],
                    'status'        => 'hentet',
                    'betalt_maate'  => $maateTekst,
                    'payment_id'    => null,
                ]);
                $ider = [];
                $pengerad = null;
                $gaveRad = null;
                foreach ($rader as $j => $r) {
                    $erGave = $r['maate'] === 'Gavekort';
                    $felt = [
                        'vipps_reference' => 'KASSE-' . $ordrenr . (count($rader) > 1 ? '-' . ($j + 1) : ''),
                        'type'            => 'manuell',
                        'formal'          => $d['formal'],
                        'order_id'        => $ordreId,
                        'maate'           => $r['maate'],
                        'belop_ore'       => $erGave ? 0 : (int) $r['ore'],
                        'status'          => 'betalt',
                        'registrert_av'   => $person['id'],
                        'idempotency_key' => self::manuellNokkel($nokkel, $j),
                    ];
                    if ($erGave) {
                        $felt['gavekort_id'] = $kort['id'];
                        $felt['gavekort_ore'] = (int) $r['ore'];
                    }
                    $id = DB::settInn('payments', $felt);
                    $ider[] = $id;
                    if ($erGave) {
                        $gaveRad = $id;
                    } elseif ($pengerad === null) {
                        $pengerad = $id;
                    }
                }
                DB::oppdater('orders', ['payment_id' => $pengerad ?? $ider[0]], ['id' => $ordreId]);
                foreach ($d['varer'] as $v) {
                    DB::settInn('order_lines', [
                        'order_id'   => $ordreId,
                        'product_id' => $v['produktId'],
                        'tittel'     => mb_substr((string) $v['tittel'], 0, 191),
                        'antall'     => (int) $v['antall'],
                        'pris_ore'   => (int) $v['prisOre'],
                    ]);
                    if ($v['produktId'] !== null) {
                        DB::kjor('UPDATE products SET lager = GREATEST(0, lager - :a) WHERE id = :p AND lager IS NOT NULL',
                            ['a' => (int) $v['antall'], 'p' => (int) $v['produktId']]);
                        Lager::etterSalg((int) $v['produktId'], (int) $v['antall']);
                    }
                }
                if ($gaveRad !== null) {
                    Booking::trekkGavekortEllerAvbryt((int) $gaveRad);
                }
                KasseJustering::settOrdre($d, $nokkel, $ordreId, $person, $pengerad ?? $ider[0], true);
                revider('kassesalg_registrert', 'ordre', $ordreId, ['ordrenr' => $ordrenr, 'sum' => (int) $d['sumOre'],
                    'maate' => $maateTekst, 'kasse' => $person['id']]);
                return $ordreId;
            });
        } catch (PDOException $e) {
            if (self::erDuplikat($e)) {
                return (int) (self::radMed(self::manuellNokkel($nokkel))['order_id'] ?? 0);
            }
            throw $e;
        }
    }

    /**
     * Gavekort solgt i kassa: som «Utsted gavekort» i admin (uttak.php,
     * opprinnelse «kjopt»). Koden vises på skjermen.
     *
     * @return array{kode:string, belop:string}
     */
    private static function registrerGavekort(array $d, array $betaler, string $maate, array $person, string $nokkel): array
    {
        if (!DB::harKolonne('gift_cards', 'opprinnelse')) {
            throw new RuntimeException('Gavekort i kassa krever en oppdatering av databasen. Trykk ⚙ Kjør oppdateringer.', 503);
        }
        if (self::stoppQr($nokkel) === 'betalt') {
            // Betalt med Vipps-QR før kontanten: koden er kortet bak QR-en.
            $kode = (string) DB::verdi('SELECT kode FROM gift_cards WHERE payment_id = :p',
                ['p' => (int) (self::radMed($nokkel)['id'] ?? 0)]);
            return ['kode' => $kode, 'belop' => KasseKurv::kr((int) $d['sumOre'])];
        }
        $nokkel = self::manuellNokkel($nokkel);
        $finnes = self::radMed($nokkel);
        if ($finnes === null) {
            $ordrenr = 'G-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            try {
                $kortId = (int) DB::iTransaksjon(static function () use ($d, $betaler, $maate, $person, $nokkel, $ordrenr): int {
                    $betalingId = DB::settInn('payments', [
                        'vipps_reference' => 'KASSE-' . $ordrenr,
                        'type'            => 'manuell',
                        'formal'          => 'gavekort',
                        'maate'           => $maate,
                        'belop_ore'       => (int) $d['sumOre'],
                        'status'          => 'betalt',
                        'registrert_av'   => $person['id'],
                        'idempotency_key' => strtolower($nokkel),
                    ]);
                    return DB::settInn('gift_cards', [
                        'kode'            => 'UBETALT-' . strtoupper(bin2hex(random_bytes(6))),
                        'opprinnelig_ore' => (int) $d['sumOre'],
                        'saldo_ore'       => 0,
                        'gyldig_til'      => gmdate('Y-m-d', strtotime('+3 years')),
                        'kjoper_navn'     => $betaler['navn'] !== '' ? mb_substr($betaler['navn'], 0, 191) : 'Lissom Keramikk',
                        'kjoper_epost'    => null,
                        'mottaker_epost'  => null,
                        'hilsen'          => null,
                        'payment_id'      => $betalingId,
                        'status'          => 'ubetalt',
                        'opprinnelse'     => 'kjopt',
                        'utstedt_av'      => $person['id'],
                    ]);
                });
            } catch (PDOException $e) {
                if (!self::erDuplikat($e)) {
                    throw $e;
                }
                $kortId = 0;
            }
            if ($kortId > 0) {
                Booking::aktiverGavekort($kortId, false);
                revider('gavekort_utstedt', 'gift_card', $kortId, ['belop' => (int) $d['sumOre'], 'opprinnelse' => 'kjopt',
                    'maate' => $maate, 'kasse' => $person['id']]);
            }
            $finnes = self::radMed($nokkel);
        }
        $kode = (string) DB::verdi('SELECT kode FROM gift_cards WHERE payment_id = :p', ['p' => (int) ($finnes['id'] ?? 0)]);
        return ['kode' => $kode, 'belop' => KasseKurv::kr((int) $d['sumOre'])];
    }

    /** Timepakke betalt i kassa: betalingen og pakken i samme transaksjon. */
    private static function registrerTimepakke(array $d, string $maate, array $person, string $nokkel): void
    {
        if (self::stoppQr($nokkel) === 'betalt') {
            return;
        }
        $nokkel = self::manuellNokkel($nokkel);
        if (self::radMed($nokkel) !== null) {
            return;
        }
        try {
            DB::iTransaksjon(static function () use ($d, $maate, $person, $nokkel): void {
                $betalingId = DB::settInn('payments', [
                    'vipps_reference' => 'KASSE-' . Vipps::nyReferanse('TP'),
                    'type'            => 'manuell',
                    'formal'          => 'medlemskap',
                    'member_id'       => (int) $d['medlemId'],
                    'maate'           => $maate,
                    'belop_ore'       => (int) $d['sumOre'],
                    'status'          => 'betalt',
                    'registrert_av'   => $person['id'],
                    'idempotency_key' => strtolower($nokkel),
                ]);
                $id = DB::settInn('timepakker', [
                    'member_id'  => (int) $d['medlemId'],
                    'timer'      => Timepakke::timer(),
                    'pris_ore'   => (int) $d['sumOre'],
                    'status'     => 'betalt',
                    'payment_id' => $betalingId,
                    'betalt_at'  => gmdate('Y-m-d H:i:s'),
                ]);
                revider('timepakke_betalt', 'member', (int) $d['medlemId'], ['timepakke' => $id, 'kasse' => $person['id'], 'maate' => $maate]);
            });
        } catch (PDOException $e) {
            if (!self::erDuplikat($e)) {
                throw $e;
            }
        }
    }

    // ── Ta betalt: Vipps-QR ────────────────────────────────────────────

    /**
     * QR-koden for én del av kurven. Delene betales én etter én; skjermen
     * spør etter neste når den forrige er betalt.
     *
     * Delen pekes ut med sin faste id («booking:12», «ordre», …). En
     * påmelding som alt er betalt, svarer betalt.
     *
     * @return array<string,mixed> {betalt:bool, qr?:string, belop?:string, poll?:array, betalingId?:int}
     */
    public static function qr(array $kurv, array $betalerInn, string $delId, array $nokler, array $forventet, array $person): array
    {
        $betaler = KasseKurv::betaler($betalerInn);
        $deler = self::sjekkKurv(KasseKurv::deler($kurv, $betaler, $nokler), $nokler, $forventet);
        $d = null;
        foreach ($deler as $x) {
            if ((string) $x['id'] === $delId) {
                $d = $x;
            }
        }
        if ($d === null) {
            if (str_starts_with($delId, 'booking:') && array_key_exists($delId, $forventet)) {
                $bid = (int) substr($delId, 8);
                return ['betalt' => true, 'poll' => ['bookingId' => $bid], 'betalingId' => self::ksQrBetalt($bid)];
            }
            throw new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
        }
        $nokkel = (string) $d['nokkel'];
        self::laasDel($nokkel);
        try {
            // Øktlåsen holdes mens QR-en opprettes (og andre stoppes): da kan
            // ikke betal() for en annen del merke den avbrutt midt i, før den
            // er laget hos Vipps (kontrolløren 8. oktober 2026).
            self::laasOkt();
            try {
                // Endret pris og rabatt låses per del (rører ingenting ennå).
                KasseJustering::laas($deler, $person);
                return self::qrDel($d, $deler, $betaler, $nokkel, $person);
            } finally {
                self::slippOkt();
            }
        } finally {
            self::slippDel($nokkel);
        }
    }

    /** Én lås per iPad-økt rundt opprettelse og stopp av QR-koder. */
    private static function laasOkt(): void
    {
        if ((int) DB::verdi('SELECT GET_LOCK(:l, 20)', ['l' => 'kasse-okt:' . self::oktId()]) !== 1) {
            throw new RuntimeException('Et annet trykk for det samme kjøpet er i gang. Prøv igjen.', 409);
        }
    }

    private static function slippOkt(): void
    {
        DB::verdi('SELECT RELEASE_LOCK(:l)', ['l' => 'kasse-okt:' . self::oktId()]);
    }

    /** Åtte tegn som hører til innloggingen på iPaden. */
    private static function oktId(): string
    {
        return substr(hash('sha256', (string) Sesjon::tokenHash()), 0, 8);
    }

    /** Den nyeste betalte KS-QR-betalingen på påmeldingen i dag, eller null. */
    private static function ksQrBetalt(int $bid): ?int
    {
        [$fra] = KasseKurv::dagen();
        $id = DB::verdi(
            "SELECT id FROM payments WHERE booking_id = :b AND vipps_reference LIKE 'KS-QR-%'
                AND status IN ('betalt', 'delvis_refundert') AND created_at >= :fra ORDER BY id DESC LIMIT 1",
            ['b' => $bid, 'fra' => $fra]
        );
        return $id === null || $id === false ? null : (int) $id;
    }

    /** @param list<array<string,mixed>> $deler */
    private static function qrDel(array $d, array $deler, array $betaler, string $nokkel, array $person): array
    {
        if ($d['type'] === 'booking') {
            self::stoppOktensQr(array_column($deler, 'nokkel'));
            return self::qrBooking($d, $person);
        }

        $sum = (int) $d['sumOre'];
        if ($sum < Vipps::MINSTE_BELOP_ORE) {
            throw new RuntimeException('Det som står igjen er under én krone. Ta det som kontant.', 409);
        }
        if (self::radMed(self::manuellNokkel($nokkel)) !== null) {
            throw new RuntimeException('Denne er alt registrert.', 409);
        }
        $finnes = self::radMed($nokkel);
        if ($finnes !== null) {
            $ref = (string) $finnes['vipps_reference'];
            if ((int) $finnes['belop_ore'] !== $sum) {
                throw new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
            }
            if (in_array((string) $finnes['status'], ['betalt', 'autorisert', 'delvis_refundert'], true)) {
                return ['betalt' => true, 'poll' => ['referanse' => $ref], 'betalingId' => (int) $finnes['id']];
            }
            if ((string) $finnes['status'] === 'venter') {
                $t = Vipps::synkroniser($ref);
                if (in_array($t, ['AUTHORIZED', 'CAPTURED'], true)) {
                    return ['betalt' => true, 'poll' => ['referanse' => $ref], 'betalingId' => (int) $finnes['id']];
                }
                if ($t === 'CREATED') {
                    $p = json_decode((string) (DB::verdi('SELECT siste_payload FROM payments WHERE vipps_reference = :r', ['r' => $ref]) ?? ''), true);
                    $url = is_array($p) ? trim((string) ($p['redirectUrl'] ?? '')) : '';
                    if ($url !== '') {
                        return self::qrSvar($url, $sum, $ref);
                    }
                }
                throw new RuntimeException('QR-koden er utløpt. Trykk «Vipps» på nytt.', 410);
            }
            if ((string) $finnes['status'] !== 'opprettet') {
                throw new RuntimeException('QR-koden er utløpt. Trykk «Vipps» på nytt.', 410);
            }
            // «opprettet»: forrige forsøk fikk ikke svar. Samme referanse og
            // samme nøkkel sendes igjen, så Vipps lager ikke en ny betaling.
            return self::qrSend($ref, $sum, self::qrTekst($d), $nokkel, null);
        }

        // En ny QR-kode: koder fra denne iPaden som venter for en annen kurv,
        // stoppes først, så kunden aldri har to å betale.
        self::stoppOktensQr(array_column($deler, 'nokkel'));
        $ref = Vipps::nyReferanse(self::oktPrefiks());
        $rydd = self::qrRader($d, $betaler, $ref, $nokkel, $person);
        return self::qrSend($ref, $sum, self::qrTekst($d), $nokkel, $rydd);
    }

    /** Kundeteksten i Vipps. Samme som kassa i admin og kursstarten. */
    private static function qrTekst(array $d): string
    {
        return match ($d['type']) {
            'gavekort'  => Vipps::beskrivelse('Gavekort — Lissom Keramikk'),
            'timepakke' => Vipps::beskrivelse('Timepakke hos Lissom — ' . Timepakke::timer() . ' timer'),
            default     => Vipps::beskrivelse(($d['formal'] === 'booking' ? 'Kurs' : 'Produkt') . ' — solgt i verkstedet — Lissom Keramikk'),
        };
    }

    /**
     * Radene for en QR-del, laget før Vipps spørres: betalingen («opprettet»)
     * og det den gjelder. Gir tilbake det som må ryddes om Vipps sier nei.
     *
     * @return callable(): void
     */
    private static function qrRader(array $d, array $betaler, string $ref, string $nokkel, array $person): callable
    {
        return DB::iTransaksjon(static function () use ($d, $betaler, $ref, $nokkel, $person): callable {
            $felt = [
                'vipps_reference' => $ref,
                'type'            => 'epayment',
                'belop_ore'       => (int) $d['sumOre'],
                'status'          => 'opprettet',
                'idempotency_key' => $nokkel,
            ];
            if ($d['type'] === 'ordre') {
                $betalingId = DB::settInn('payments', ['idempotency_key' => $nokkel] + $felt + ['formal' => $d['formal']]);
                $kunde = $betaler['navn'] !== '' ? $betaler['navn'] : ($d['formal'] === 'booking' ? (string) $d['tittel'] : 'Vipps-QR');
                $ordreId = DB::settInn('orders', [
                    'ordrenr'       => 'Q-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
                    'member_id'     => $betaler['medlemId'],
                    'kunde_navn'    => mb_substr($kunde, 0, 191),
                    'kunde_epost'   => $betaler['epost'] !== '' ? mb_substr($betaler['epost'], 0, 191) : null,
                    'kunde_telefon' => $betaler['telefon'] !== '' ? mb_substr($betaler['telefon'], 0, 32) : null,
                    'sum_ore'       => (int) $d['sumOre'],
                    'status'        => 'ny',
                    'betalt_maate'  => 'Vipps',
                    'payment_id'    => $betalingId,
                ]);
                DB::oppdater('payments', ['order_id' => $ordreId], ['id' => $betalingId]);
                foreach ($d['varer'] as $v) {
                    DB::settInn('order_lines', [
                        'order_id' => $ordreId, 'product_id' => $v['produktId'],
                        'tittel' => mb_substr((string) $v['tittel'], 0, 191), 'antall' => (int) $v['antall'], 'pris_ore' => (int) $v['prisOre'],
                    ]);
                }
                KasseJustering::settOrdre($d, $nokkel, $ordreId, $person, $betalingId, false);
                revider('vippsqr_laget', 'order', $ordreId, ['belop' => (int) $d['sumOre'], 'kasse' => $person['id']]);
                return static function () use ($ordreId, $betalingId, $nokkel): void {
                    KasseJustering::fjernQr($nokkel);
                    DB::kjor('DELETE FROM order_lines WHERE order_id = :o', ['o' => $ordreId]);
                    DB::kjor('DELETE FROM orders WHERE id = :o', ['o' => $ordreId]);
                    DB::kjor("DELETE FROM payments WHERE id = :p AND status = 'opprettet'", ['p' => $betalingId]);
                };
            }
            if ($d['type'] === 'gavekort') {
                $betalingId = DB::settInn('payments', ['idempotency_key' => $nokkel] + $felt + ['formal' => 'gavekort']);
                $kortId = DB::settInn('gift_cards', [
                    'kode'            => 'UBETALT-' . strtoupper(bin2hex(random_bytes(6))),
                    'opprinnelig_ore' => (int) $d['sumOre'],
                    'saldo_ore'       => 0,
                    'gyldig_til'      => gmdate('Y-m-d', strtotime('+3 years')),
                    'kjoper_navn'     => $betaler['navn'] !== '' ? mb_substr($betaler['navn'], 0, 191) : 'Lissom Keramikk',
                    'payment_id'      => $betalingId,
                    'status'          => 'ubetalt',
                    'opprinnelse'     => 'kjopt',
                    'utstedt_av'      => $person['id'],
                ]);
                return static function () use ($kortId, $betalingId): void {
                    DB::kjor("DELETE FROM gift_cards WHERE id = :k AND status = 'ubetalt'", ['k' => $kortId]);
                    DB::kjor("DELETE FROM payments WHERE id = :p AND status = 'opprettet'", ['p' => $betalingId]);
                };
            }
            // Timepakke: samme rader som Timepakke::start().
            $betalingId = DB::settInn('payments', ['idempotency_key' => $nokkel] + $felt
                + ['formal' => 'medlemskap', 'member_id' => (int) $d['medlemId']]);
            $pakkeId = DB::settInn('timepakker', [
                'member_id' => (int) $d['medlemId'], 'timer' => Timepakke::timer(), 'pris_ore' => (int) $d['sumOre'],
                'status' => 'venter', 'payment_id' => $betalingId,
            ]);
            return static function () use ($pakkeId, $betalingId): void {
                DB::kjor("DELETE FROM timepakker WHERE id = :t AND status = 'venter'", ['t' => $pakkeId]);
                DB::kjor("DELETE FROM payments WHERE id = :p AND status = 'opprettet'", ['p' => $betalingId]);
            };
        });
    }

    /** Ber Vipps om betalingen (userFlow QR) og gir tilbake bildet. */
    private static function qrSend(string $ref, int $sum, string $tekst, string $nokkel, ?callable $rydd): array
    {
        try {
            $svar = Vipps::opprettBetaling(
                $ref, $sum, $tekst,
                Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($ref),
                null, false, $nokkel, true
            );
        } catch (Throwable $e) {
            $kode = (int) $e->getCode();
            if ($kode >= 400 && $kode < 500 && $rydd !== null) {
                // Et klart nei på første forsøk: betalingen finnes ikke hos
                // Vipps. Radene fjernes, så ingenting står igjen.
                $rydd();
                throw new RuntimeException(KursstartKrav::avvistTekst($kode, $e->getMessage(), true), KursstartKrav::VIPPS_NEI);
            }
            if ($kode >= 400 && $kode < 500) {
                throw new RuntimeException(KursstartKrav::avvistTekst($kode, $e->getMessage(), true), KursstartKrav::VIPPS_NEI);
            }
            // Ingen svar: raden blir stående «opprettet», og neste trykk
            // sender det samme med samme nøkkel.
            logg_feil('Vipps-QR fra kassa uten svar: ' . $ref, $e);
            throw new RuntimeException('Fikk ikke svar fra Vipps. Prøv igjen — det lages ikke to betalinger.', KursstartKrav::VIPPS_NEI);
        }
        DB::kjor("UPDATE payments SET status = 'venter' WHERE vipps_reference = :r AND status = 'opprettet'", ['r' => $ref]);
        return self::qrSvar((string) ($svar['url'] ?? ''), $sum, $ref);
    }

    private static function qrSvar(string $url, int $sum, string $ref): array
    {
        try {
            $bilde = Vipps::qrBilde($url);
        } catch (Throwable $e) {
            logg_feil('QR-bildet kom ikke fram', $e);
            throw new RuntimeException('QR-koden er laget, men bildet kom ikke fram. Trykk «Vipps» igjen.', KursstartKrav::VIPPS_NEI);
        }
        return ['betalt' => false, 'qr' => $bilde, 'belop' => KasseKurv::kr($sum), 'poll' => ['referanse' => $ref]];
    }

    /** Påmeldingen: gjenstandene slås inn, og KursstartKrav::visQr() lager koden. */
    private static function qrBooking(array $d, array $person): array
    {
        $bid = (int) $d['bookingId'];
        if ($d['pop'] !== []) {
            $naa = DB::verdi('SELECT gjenstander_ore FROM bookings WHERE id = :i', ['i' => $bid]);
            // Er gjenstandene alt slått inn med samme sum, er det et nytt
            // trykk på samme kurv: ingenting å lagre på nytt.
            if ($naa === null || $naa === false || (int) $naa !== (int) PopPris::forhandsvis($bid, $d['pop'])['sumOre']) {
                // En QR-kode som venter stoppes hos Vipps først (statusen hentes);
                // kassa() venter ellers på den.
                $stopp = KursstartKrav::stoppVentende($bid);
                if ($stopp !== null) {
                    throw new RuntimeException($stopp, 409);
                }
                PopPris::kassa($bid, $d['pop'], $person['id']);
            }
        }
        $b = DB::en('SELECT belop_ore, status FROM bookings WHERE id = :i', ['i' => $bid]);
        if ($b !== null && KursstartKrav::skyldig($bid, (int) $b['belop_ore'], (string) $b['status']) === 0) {
            return ['betalt' => true, 'poll' => ['bookingId' => $bid], 'betalingId' => self::ksQrBetalt($bid)];
        }
        // Endret pris og rabatt: Vipps får beløpet etter rabatt, men
        // påmeldingen røres ikke før QR-en er bekreftet betalt (raden kobles
        // til betalingen, og Booking::markerBetalt() setter den på).
        $nokkel = (string) $d['nokkel'];
        $trekk = KasseJustering::trekkFor($nokkel);
        $skyldig = self::skyldigNaa($bid);
        $belop = $skyldig - $trekk;
        if ($trekk !== 0 && $belop !== (int) $d['sumOre']) {
            throw new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
        }
        if ($belop < Vipps::MINSTE_BELOP_ORE) {
            throw new RuntimeException('Det som står igjen er under én krone. Ta det som kontant.', 409);
        }
        $r = $trekk === 0 ? KursstartKrav::visQr($bid)
            : KursstartKrav::visQr($bid, $belop, static fn(int $betalingId) => KasseJustering::kobleQr($nokkel, $bid, $betalingId));
        if (($r['status'] ?? '') === 'betalt') {
            return ['betalt' => true, 'poll' => ['bookingId' => $bid], 'betalingId' => self::ksQrBetalt($bid)];
        }
        return ['betalt' => false, 'qr' => (string) ($r['qr'] ?? ''), 'belop' => KasseKurv::kr($belop),
                'poll' => ['bookingId' => $bid]];
    }

    private static function skyldigNaa(int $bid): int
    {
        $b = DB::en('SELECT belop_ore, status FROM bookings WHERE id = :i', ['i' => $bid]);
        return $b === null ? 0 : KursstartKrav::skyldig($bid, (int) $b['belop_ore'], (string) $b['status']);
    }

    /**
     * Er QR-betalingen inne? Statusen hentes fra Vipps (ikke oftere enn hvert
     * femte sekund), samme behandling som webhooken og cron.
     *
     * @param array<string,mixed> $poll {bookingId} eller {referanse}
     * @return array{status:string, betalt:bool, kode?:string}
     */
    public static function status(array $poll): array
    {
        $bid = (int) ($poll['bookingId'] ?? 0);
        if ($bid > 0) {
            if (KasseKurv::booking($bid) === null) {
                throw new RuntimeException('Fant ikke påmeldingen.', 404);
            }
            foreach (DB::alle(
                "SELECT vipps_reference FROM payments
                  WHERE booking_id = :b AND type = 'epayment' AND vipps_reference LIKE 'KS-%' AND status = 'venter'
                    AND updated_at < DATE_SUB(GREATEST(NOW(), UTC_TIMESTAMP()), INTERVAL 5 SECOND)
                  LIMIT 3",
                ['b' => $bid]
            ) as $v) {
                Vipps::synkroniser((string) $v['vipps_reference']);
            }
            if (self::skyldigNaa($bid) === 0) {
                return ['status' => 'betalt', 'betalt' => true, 'betalingId' => self::ksQrBetalt($bid)];
            }
            $siste = (string) (DB::verdi(
                "SELECT status FROM payments WHERE booking_id = :b AND type = 'epayment' AND vipps_reference LIKE 'KS-QR-%'
              ORDER BY id DESC LIMIT 1", ['b' => $bid]) ?? '');
            return ['status' => $siste !== '' ? $siste : 'venter', 'betalt' => false];
        }
        $ref = trim((string) ($poll['referanse'] ?? ''));
        // Bare QR-koder denne iPaden har vist.
        if (!str_starts_with($ref, self::oktPrefiks() . '-')) {
            throw new RuntimeException('Fant ikke betalingen.', 404);
        }
        $rad = DB::en('SELECT id, status, updated_at FROM payments WHERE vipps_reference = :r', ['r' => $ref]);
        if ($rad === null) {
            throw new RuntimeException('Fant ikke betalingen.', 404);
        }
        if ((string) $rad['status'] === 'venter') {
            $gammel = (int) DB::verdi(
                'SELECT updated_at < DATE_SUB(GREATEST(NOW(), UTC_TIMESTAMP()), INTERVAL 5 SECOND) FROM payments WHERE id = :i',
                ['i' => (int) $rad['id']]
            );
            if ($gammel === 1) {
                Vipps::synkroniser($ref);
                $rad = DB::en('SELECT id, status FROM payments WHERE vipps_reference = :r', ['r' => $ref]);
            }
        }
        $status = (string) $rad['status'];
        $ut = ['status' => $status, 'betalt' => in_array($status, ['betalt', 'delvis_refundert'], true)];
        if ($ut['betalt']) {
            $ut['betalingId'] = (int) $rad['id'];
            $kode = DB::verdi("SELECT kode FROM gift_cards WHERE payment_id = :p AND status <> 'ubetalt'", ['p' => (int) $rad['id']]);
            if ($kode !== null && $kode !== false) {
                $ut['kode'] = (string) $kode;
            }
        }
        return $ut;
    }

    // ── Kvittering ─────────────────────────────────────────────────────

    /**
     * Betalingsradene kvitteringen gjelder: betalt i dag, ikke annullert, og
     * tatt i kassa (registrert av noen, eller Vipps-QR fra kassa/kursstarten).
     *
     * @param list<mixed> $ider
     * @return list<array<string,mixed>>
     */
    private static function kvitteringRader(array $ider): array
    {
        [$fra] = KasseKurv::dagen();
        $ut = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $ider))), 0, 20) as $id) {
            $r = DB::en(
                "SELECT id, type, maate, belop_ore, refundert_ore, gavekort_ore, booking_id, order_id, member_id
                   FROM payments
                  WHERE id = :i AND created_at >= :fra AND status IN ('betalt', 'delvis_refundert')
                    AND annullert_at IS NULL
                    AND (registrert_av IS NOT NULL OR vipps_reference LIKE 'KQ-%' OR vipps_reference LIKE 'KS-QR-%')",
                ['i' => $id, 'fra' => $fra]
            );
            if ($r !== null) {
                $ut[] = $r;
            }
        }
        return $ut;
    }

    /**
     * Hører betalingen til den som betaler? Påmeldingen er betalerens egen,
     * betalingen står på medlemmet, eller ordren ble laget for medlemmet eller
     * med det samme mobilnummeret. Ellers kunne kunde As beløp gått til kunde
     * Bs mobil (kontrolløren 8. oktober 2026).
     *
     * @param array<string,mixed> $r rad fra kvitteringRader()
     * @param array<string,mixed> $betaler fra KasseKurv::betaler()
     */
    private static function tilhorer(array $r, array $betaler): bool
    {
        $bid = (int) ($betaler['bookingId'] ?? 0);
        $mid = (int) ($betaler['medlemId'] ?? 0);
        $tlf = normaliser_telefon((string) ($betaler['telefon'] ?? ''));
        if ($r['booking_id'] !== null) {
            return $bid > 0 && (int) $r['booking_id'] === $bid;
        }
        if ($r['member_id'] !== null && $mid > 0 && (int) $r['member_id'] === $mid) {
            return true;
        }
        if ($r['order_id'] !== null) {
            $o = DB::en('SELECT member_id, kunde_telefon FROM orders WHERE id = :i', ['i' => (int) $r['order_id']]);
            if ($o === null) {
                return false;
            }
            if ($mid > 0 && (int) ($o['member_id'] ?? 0) === $mid) {
                return true;
            }
            return $tlf !== '' && normaliser_telefon((string) ($o['kunde_telefon'] ?? '')) === $tlf;
        }
        return false;
    }

    /** Er det alt sendt en SMS-kvittering for en av betalingene? */
    private static function smsSendt(array $rader): bool
    {
        $ider = array_map(static fn(array $r): int => (int) $r['id'], $rader);
        return $ider !== [] && DB::verdi(
            "SELECT 1 FROM audit_log WHERE handling = 'kassekvittering_sms' AND objekt_type = 'payment'
                AND objekt_id IN (" . implode(',', $ider) . ') LIMIT 1'
        ) !== null;
    }

    /** Er SMS-kvitteringen (kassekvittering_sms) på, og kan SMS sendes? */
    private static function smsKvitteringPaa(): bool
    {
        return Varsel::smsMulig()
            && (int) (DB::verdi("SELECT aktiv FROM notification_templates WHERE navn = 'kassekvittering_sms'") ?? 0) === 1;
    }

    /**
     * Hvilke kvitteringer som kan sendes: e-post når betaleren har en adresse
     * og kjøpet har en påmelding eller ordre, SMS når betaleren har mobil og
     * SMS er satt opp. Kontantkunden har ingen.
     *
     * @param list<mixed> $ider betalingsradene fra betal() / status()
     * @return array{epost:bool, sms:bool, betalinger:list<int>}
     */
    public static function kvitteringValg(array $betaler, array $ider): array
    {
        $rader = self::kvitteringRader($ider);
        $harEpostDel = false;
        $alleHans = $rader !== [];
        foreach ($rader as $r) {
            if ($r['booking_id'] !== null || $r['order_id'] !== null) {
                $harEpostDel = true;
            }
            $alleHans = $alleHans && self::tilhorer($r, $betaler);
        }
        return [
            'epost'      => $harEpostDel && $betaler['epost'] !== '' && filter_var($betaler['epost'], FILTER_VALIDATE_EMAIL) !== false,
            'sms'        => $alleHans && normaliser_telefon((string) $betaler['telefon']) !== '' && self::smsKvitteringPaa()
                            && !self::smsSendt($rader),
            'betalinger' => array_map(static fn(array $r): int => (int) $r['id'], $rader),
        ];
    }

    /** «Vipps», «kontant», «faktura», «gavekort» eller «delt betaling». */
    private static function maateTekst(array $rader): string
    {
        $m = [];
        foreach ($rader as $r) {
            $maate = (string) ($r['maate'] ?? '');
            $m[] = (string) $r['type'] !== 'manuell' ? 'Vipps' : match ($maate) {
                'Kontant'  => 'kontant',
                'Faktura'  => 'faktura',
                'Gavekort' => 'gavekort',
                default    => 'Vipps',
            };
        }
        $m = array_values(array_unique($m));
        return count($m) === 1 ? $m[0] : 'delt betaling';
    }

    /**
     * Sender kvitteringen.
     *
     *   e-post  den vanlige veien (som «Send kvittering på nytt» i Penger):
     *           bekreftelsen for påmeldingen, ordrebekreftelsen for varene
     *   sms     den korte kvitteringen kassekvittering_sms (eieren, 8. oktober
     *           2026): «Takk for handelen hos Lissom! Betalt {belop} med
     *           {maate} {dato}. Hilsen oss i Lissom»
     *
     * Bare for det som er betalt i kassa i dag, og bare til den som betalte.
     *
     * @param list<mixed> $ider
     * @param array<string,mixed> $betaler fra KasseKurv::betaler()
     */
    public static function kvittering(array $ider, string $kanal, array $betaler): int
    {
        if (!in_array($kanal, ['epost', 'sms'], true)) {
            throw new RuntimeException('Velg e-post eller SMS.');
        }
        $rader = self::kvitteringRader($ider);
        if ($rader === []) {
            throw new RuntimeException('Fant ingen betaling i dag å sende kvittering for.', 404);
        }
        if ($kanal === 'sms') {
            $tlf = normaliser_telefon((string) $betaler['telefon']);
            if ($tlf === '' || !self::smsKvitteringPaa()) {
                throw new RuntimeException('Kvittering på SMS kan ikke sendes til denne kunden.', 409);
            }
            foreach ($rader as $r) {
                if (!self::tilhorer($r, $betaler)) {
                    throw new RuntimeException('Kvitteringen hører ikke til denne kunden.', 409);
                }
            }
            // Én SMS-kvittering per betaling. Låsen gjør at to trykk samtidig
            // ikke begge kommer forbi sjekken.
            if ((int) DB::verdi("SELECT GET_LOCK('kasse-sms', 10)") !== 1) {
                throw new RuntimeException('Et annet trykk for det samme kjøpet er i gang. Prøv igjen.', 409);
            }
            try {
                if (self::smsSendt($rader)) {
                    throw new RuntimeException('Kvittering på SMS er alt sendt for dette kjøpet.', 409);
                }
                $sum = 0;
                foreach ($rader as $r) {
                    $sum += max(0, (int) $r['belop_ore'] - (int) $r['refundert_ore']) + (int) $r['gavekort_ore'];
                }
                $d = new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo'));
                $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];
                Varsel::mal('kassekvittering_sms', ['telefon' => $tlf], [
                    'belop' => KasseKurv::kr($sum),
                    'maate' => self::maateTekst($rader),
                    'dato'  => (int) $d->format('j') . '. ' . $mnd[(int) $d->format('n') - 1],
                ], 'payment', (int) $rader[0]['id']);
                foreach ($rader as $r) {
                    revider('kassekvittering_sms', 'payment', (int) $r['id']);
                }
            } finally {
                DB::verdi("SELECT RELEASE_LOCK('kasse-sms')");
            }
            return 1;
        }
        $sendt = 0;
        $bookinger = array_unique(array_filter(array_map(static fn(array $r): int => (int) $r['booking_id'], $rader)));
        $ordrer = array_unique(array_filter(array_map(static fn(array $r): int => (int) $r['order_id'], $rader)));
        foreach ($bookinger as $b) {
            Booking::sendBekreftelse($b, 'epost');
            $sendt++;
        }
        foreach ($ordrer as $o) {
            if (trim((string) (DB::verdi('SELECT kunde_epost FROM orders WHERE id = :i', ['i' => $o]) ?? '')) !== '') {
                Booking::sendOrdrebekreftelse($o);
                $sendt++;
            }
        }
        if ($sendt === 0) {
            throw new RuntimeException('Fant ingen betaling i dag å sende kvittering for.', 404);
        }
        return $sendt;
    }
}
