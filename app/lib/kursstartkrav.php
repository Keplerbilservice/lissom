<?php
/**
 * Vipps-krav fra «Start kurset» (kalenderplanen, bølge 2).
 *
 * Eieren, 3. oktober 2026: Vipps-krav er aktivert hos Vipps igjen og skal
 * brukes i kursstarten. Det opphever «vippskrav skal slettes» (6. september)
 * for akkurat dette stedet. Kassa og handlelistene er ikke rørt.
 *
 * ── Hvordan pengene går ───────────────────────────────────────────────
 *
 *   send()          Lager en betalingsrad (KS-…, type epayment, formål
 *                   booking, booking_id = påmeldingen) og ber Vipps sende et
 *                   krav (PUSH_MESSAGE) til mobilen. Idempotensnøkkelen står
 *                   på raden, så et nytt forsøk er den samme opprettelsen.
 *                   Én påmelding har høyst ett krav som venter.
 *   webhook/cron    Vipps::anvendTilstand() trekker og kaller
 *                   Booking::markerBetalt(), som setter påmeldingen betalt
 *                   (grenen for KS- i markerBetalt: ingen ny bekreftelse).
 *   stoppVentende() Kalles før kontant registreres (kursbetaling.php).
 *                   Kravet avbrytes hos Vipps (bare hvis det ikke er
 *                   godkjent), og statusen hentes fra Vipps før vi stoler på
 *                   noe. Har kunden alt betalt, nektes kontanten.
 *   ikkeTrekk()     Siste sperre: kommer en godkjenning for et krav når
 *                   plassen alt er gjort opp på annen måte, slippes
 *                   reservasjonen i stedet for å trekkes.
 *
 * Bak bryteren «Vis/kursstart3» (content_blocks; mangler raden = av).
 * Ingen migrasjon: krever payments.booking_id (migrasjon 084), ellers svarer
 * send() at databasen må oppdateres.
 */

declare(strict_types=1);

final class KursstartKrav
{
    /** Referansen begynner med dette. Vipps::anvendTilstand() kjenner den igjen. */
    public const PREFIKS = 'KS';

    /** Kundeteksten i Vipps. Til godkjenning hos eieren (3. oktober 2026). */
    public const TEKST = 'Kurs — Lissom Keramikk';

    public static function paa(): bool
    {
        if (!DB::harTabell('content_blocks')) {
            return false;
        }
        $v = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => 'Vis/kursstart3']);
        return $v !== null && $v !== false && (string) $v === 'ja';
    }

    public static function erKrav(string $referanse): bool
    {
        return str_starts_with($referanse, self::PREFIKS . '-');
    }

    /**
     * Mobilnummeret slik Vipps vil ha det: landkode og sifre, uten pluss.
     * Åtte sifre er norsk. Alt annet som ikke ser ut som et nummer, er null.
     */
    public static function telefon(string $raa): ?string
    {
        $t = trim($raa);
        if ($t === '') {
            return null;
        }
        $pluss = str_starts_with($t, '+');
        $d = (string) preg_replace('/\D/', '', $t);
        if (!$pluss && str_starts_with($d, '00')) {
            $d = substr($d, 2);
            $pluss = true;
        }
        if (!$pluss && strlen($d) === 8) {
            $d = '47' . $d;
        }
        return preg_match('/^[1-9]\d{9,14}$/', $d) === 1 ? $d : null;
    }

    /** «Kurs — Lissom Keramikk · {kurs} {dato}», innenfor Vipps sine hundre tegn. */
    public static function beskrivelse(string $kurs, string $startUtc): string
    {
        $hale = $startUtc !== '' ? ' ' . Booking::norskDatoKort($startUtc) : '';
        $plass = Vipps::BESKRIVELSE_MAKS - mb_strlen($hale);
        return Vipps::beskrivelseInnenfor($plass, self::TEKST . ' · ' . trim($kurs)) . $hale;
    }

    private static function laas(int $bookingId): void
    {
        if ((int) DB::verdi('SELECT GET_LOCK(:l, 10)', ['l' => 'kurskrav:' . $bookingId]) !== 1) {
            throw new RuntimeException('Et annet krav for samme deltaker er i gang. Prøv igjen.', 409);
        }
    }

    private static function slipp(int $bookingId): void
    {
        DB::verdi('SELECT RELEASE_LOCK(:l)', ['l' => 'kurskrav:' . $bookingId]);
    }

    private static function revider(string $handling, int $bookingId, array $detaljer): void
    {
        try {
            revider($handling, 'booking', $bookingId, $detaljer);
        } catch (Throwable $e) {
            logg_feil('Revisjonslinja for ' . $handling . ' ble ikke skrevet', $e);
        }
    }

    /**
     * Det som står igjen å betale, i øre. Samme regel som «Ta betalt»
     * (kursbetaling.php): en plass merket betalt før betalingene ble ført
     * hver for seg, er gjort opp.
     */
    public static function skyldig(int $bookingId, int $belopOre, string $status): int
    {
        $bet = Booking::betalingerFor($bookingId);
        if ($bet['rader'] === [] && $status === 'betalt') {
            return 0;
        }
        return max(0, $belopOre - $bet['sum']);
    }

    /** Kravene som venter på én påmelding, eldste først. */
    private static function ventende(int $bookingId): array
    {
        return DB::alle(
            "SELECT id, vipps_reference, status, belop_ore, idempotency_key
               FROM payments
              WHERE booking_id = :b AND type = 'epayment' AND vipps_reference LIKE 'KS-%'
                AND status IN ('opprettet', 'venter')
           ORDER BY id",
            ['b' => $bookingId]
        );
    }

    /**
     * Kravet for hver påmelding, til skjermen: det nyeste som venter eller er betalt.
     *
     * @param list<int> $bookingIder
     * @return array<int, array{status:string, belop:string}>
     */
    public static function statusFor(array $bookingIder): array
    {
        $ider = array_values(array_filter(array_map('intval', $bookingIder)));
        if ($ider === [] || !DB::harKolonne('payments', 'booking_id')) {
            return [];
        }
        $ut = [];
        foreach (DB::alle(
            "SELECT booking_id, status, belop_ore FROM payments
              WHERE booking_id IN (" . implode(',', $ider) . ")
                AND type = 'epayment' AND vipps_reference LIKE 'KS-%'
                AND status IN ('opprettet', 'venter', 'autorisert', 'betalt')
           ORDER BY id"
        ) as $r) {
            $ut[(int) $r['booking_id']] = ['status' => (string) $r['status'], 'belop' => Booking::kroner((int) $r['belop_ore'])];
        }
        return $ut;
    }

    /**
     * Sender kravet. Kaster RuntimeException med en setning til skjermen.
     * Koden er HTTP-statusen svaret skal ha.
     *
     * @return array{beskjed:string, status:string, ny:bool}
     */
    public static function send(int $bookingId): array
    {
        if (!DB::harKolonne('payments', 'booking_id')) {
            throw new RuntimeException('Dette krever en oppdatering av databasen. Ta kontant inntil videre.', 503);
        }
        self::laas($bookingId);
        try {
            $b = DB::en(
                'SELECT b.id, b.status, b.belop_ore, b.member_id, b.course_session_id,
                        COALESCE(m.navn, b.gjest_navn) AS navn,
                        COALESCE(NULLIF(m.telefon, \'\'), b.gjest_telefon) AS telefon,
                        c.tittel, cs.start_tid
                   FROM bookings b
                   JOIN courses c ON c.id = b.course_id
              LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
              LEFT JOIN members m ON m.id = b.member_id
                  WHERE b.id = :i',
                ['i' => $bookingId]
            );
            if ($b === null) {
                throw new RuntimeException('Fant ikke påmeldingen.', 404);
            }
            $navn = (string) $b['navn'];
            if (!in_array((string) $b['status'], ['reservert', 'betalt'], true)) {
                throw new RuntimeException('Påmeldingen er ikke aktiv. Det sendes ikke krav.', 409);
            }

            // Et krav som alt er sendt: statusen hentes fra Vipps før noe nytt.
            $gjenbruk = null;
            foreach (self::ventende($bookingId) as $v) {
                $ref = (string) $v['vipps_reference'];
                if ((string) $v['status'] === 'opprettet') {
                    // Forrige forsøk fikk ikke svar. Samme referanse og samme
                    // nøkkel sendes igjen, så Vipps lager ikke et nytt krav.
                    $gjenbruk = $v;
                    continue;
                }
                $tilstand = Vipps::synkroniser($ref);
                if ($tilstand === '') {
                    throw new RuntimeException('Fikk ikke sjekket kravet som alt er sendt. Prøv igjen om litt.', 502);
                }
                if ($tilstand === 'CREATED') {
                    return ['beskjed' => 'Kravet er alt sendt til ' . $navn . '. Det venter i Vipps-appen.', 'status' => 'venter', 'ny' => false];
                }
                if (in_array($tilstand, ['AUTHORIZED', 'CAPTURED'], true)) {
                    return ['beskjed' => $navn . ' har betalt med Vipps.', 'status' => 'betalt', 'ny' => false];
                }
                // ABORTED, EXPIRED, TERMINATED: raden er satt avbrutt. Nytt krav under.
            }

            $viaVipps = DB::verdi(
                "SELECT p.id FROM payments p
              LEFT JOIN bookings b ON b.payment_id = p.id
                  WHERE (p.booking_id = :b OR b.id = :b2) AND p.type <> 'manuell'
                    AND p.status IN ('autorisert','betalt','delvis_refundert')
                  LIMIT 1",
                ['b' => $bookingId, 'b2' => $bookingId]
            );
            $skyldig = self::skyldig($bookingId, (int) $b['belop_ore'], (string) $b['status']);
            if ($skyldig === 0) {
                throw new RuntimeException('Denne er alt gjort opp.', 409);
            }
            if ($viaVipps !== null && $viaVipps !== false) {
                throw new RuntimeException('Denne er betalt gjennom Vipps. Bruk «Ta betalt» hvis noe står igjen.', 409);
            }
            if ($skyldig < Vipps::MINSTE_BELOP_ORE) {
                throw new RuntimeException('Det som står igjen er under én krone. Ta det som kontant.', 409);
            }
            $telefon = self::telefon((string) ($b['telefon'] ?? ''));
            if ($telefon === null) {
                throw new RuntimeException('Mangler mobilnummer for ' . $navn . '. Ta kontant, eller legg inn nummeret.', 409);
            }

            if ($gjenbruk !== null && (int) $gjenbruk['belop_ore'] !== $skyldig) {
                throw new RuntimeException('Et tidligere krav til ' . $navn . ' er uavklart hos Vipps. Prøv igjen om litt.', 409);
            }
            if ($gjenbruk !== null) {
                $referanse = (string) $gjenbruk['vipps_reference'];
                $nokkel = (string) $gjenbruk['idempotency_key'];
            } else {
                $referanse = Vipps::nyReferanse(self::PREFIKS);
                $nokkel = Vipps::uuid();
                DB::settInn('payments', [
                    'vipps_reference' => $referanse,
                    'type'            => 'epayment',
                    'formal'          => 'booking',
                    'member_id'       => $b['member_id'] !== null ? (int) $b['member_id'] : null,
                    'booking_id'      => $bookingId,
                    'belop_ore'       => $skyldig,
                    'status'          => 'opprettet',
                    'idempotency_key' => $nokkel,
                ]);
            }

            try {
                Vipps::opprettBetaling(
                    $referanse,
                    $skyldig,
                    self::beskrivelse((string) $b['tittel'], (string) ($b['start_tid'] ?? '')),
                    Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
                    $telefon,
                    true,
                    $nokkel
                );
            } catch (Throwable $e) {
                $kode = (int) $e->getCode();
                if ($kode >= 400 && $kode < 500) {
                    // Et klart nei fra Vipps: kravet finnes ikke der. Raden
                    // fjernes, så den ikke står som en betaling i historikken.
                    DB::kjor("DELETE FROM payments WHERE vipps_reference = :r AND status = 'opprettet'", ['r' => $referanse]);
                    self::revider('kursstart_krav_avvist', $bookingId, ['referanse' => $referanse, 'http' => $kode]);
                    throw new RuntimeException('Fikk ikke sendt Vipps-kravet. ' . $e->getMessage() . ' Ta betalt med kontant i stedet.', 502);
                }
                // Ingen svar: vi vet ikke om Vipps laget kravet. Raden blir
                // stående som «opprettet», og neste trykk sender det samme.
                logg_feil('Vipps-krav uten svar for booking ' . $bookingId, $e);
                throw new RuntimeException('Fikk ikke svar fra Vipps. Prøv igjen — kravet sendes ikke to ganger.', 502);
            }

            DB::kjor("UPDATE payments SET status = 'venter' WHERE vipps_reference = :r AND status = 'opprettet'", ['r' => $referanse]);
            self::revider('kursstart_krav', $bookingId, ['referanse' => $referanse, 'belop_ore' => $skyldig]);

            return [
                'beskjed' => 'Vipps-krav på ' . Booking::kroner($skyldig) . ' er sendt til ' . $navn
                           . '. Det står som betalt når det er godkjent.',
                'status'  => 'venter',
                'ny'      => true,
            ];
        } finally {
            self::slipp($bookingId);
        }
    }

    /**
     * Før kontant registreres: stopp krav som venter.
     *
     * Null = trygt å registrere. En tekst = ikke registrer, og vis teksten.
     */
    public static function stoppVentende(int $bookingId): ?string
    {
        if (!DB::harKolonne('payments', 'booking_id')) {
            return null;
        }
        if (self::ventende($bookingId) === []) {
            return null;
        }
        self::laas($bookingId);
        try {
            foreach (self::ventende($bookingId) as $v) {
                $ref = (string) $v['vipps_reference'];
                try {
                    $svar = Vipps::avbrytHvisIkkeGodkjent($ref);
                } catch (Throwable $e) {
                    logg_feil('Fikk ikke stoppet Vipps-krav ' . $ref, $e);
                    return 'Fikk ikke stoppet Vipps-kravet som venter. Prøv igjen om litt.';
                }
                // Et forsøk som aldri kom fram til Vipps finnes ikke der.
                if ((string) $v['status'] === 'opprettet' && $svar['status'] === 404) {
                    DB::kjor("UPDATE payments SET status = 'avbrutt' WHERE id = :i AND status = 'opprettet'", ['i' => (int) $v['id']]);
                    continue;
                }
                // Fasiten er statusen fra Vipps, ikke svaret på avbruddet.
                $tilstand = Vipps::synkroniser($ref);
                if (in_array($tilstand, ['AUTHORIZED', 'CAPTURED'], true)) {
                    return 'Kunden har alt betalt Vipps-kravet. Ikke ta kontant.';
                }
                if (!in_array($tilstand, ['TERMINATED', 'ABORTED', 'EXPIRED'], true)) {
                    return 'Fikk ikke stoppet Vipps-kravet som venter. Prøv igjen om litt.';
                }
                self::revider('kursstart_krav_stoppet', $bookingId, ['referanse' => $ref, 'tilstand' => $tilstand]);
            }
            return null;
        } finally {
            self::slipp($bookingId);
        }
    }

    /**
     * Skal en godkjenning for dette kravet slippes i stedet for å trekkes?
     *
     * Ja når kravet alt er stoppet hos oss, plassen er gjort opp på annen
     * måte mens kravet ventet, eller påmeldingen ikke er aktiv lenger. Da slippes reservasjonen hos Vipps og
     * raden settes avbrutt. Ingen krone trekkes to ganger.
     */
    public static function ikkeTrekk(string $referanse): bool
    {
        if (!DB::harKolonne('payments', 'booking_id')) {
            return false;
        }
        $p = DB::en('SELECT id, status, booking_id FROM payments WHERE vipps_reference = :r', ['r' => $referanse]);
        if ($p === null || $p['booking_id'] === null) {
            return false;
        }
        $bookingId = (int) $p['booking_id'];
        self::laas($bookingId);
        try {
            $p = DB::en('SELECT id, status FROM payments WHERE vipps_reference = :r', ['r' => $referanse]);
            if ($p === null || in_array((string) $p['status'], ['betalt', 'delvis_refundert', 'refundert'], true)) {
                return false;
            }
            $b = DB::en('SELECT belop_ore, status FROM bookings WHERE id = :i', ['i' => $bookingId]);
            $gjortOpp = $b !== null && self::skyldig($bookingId, (int) $b['belop_ore'], (string) $b['status']) === 0;
            // Avbestilt eller «møtte ikke» mens kravet ventet: ingen plass å betale for.
            $utenPlass = $b === null || !in_array((string) $b['status'], ['reservert', 'betalt'], true);
            if ((string) $p['status'] !== 'avbrutt' && !$gjortOpp && !$utenPlass) {
                return false;
            }
            Vipps::avbryt($referanse);
            DB::kjor("UPDATE payments SET status = 'avbrutt' WHERE id = :i", ['i' => (int) $p['id']]);
            logg('Vipps-krav sluppet: plassen var gjort opp eller borte', ['referanse' => $referanse, 'booking' => $bookingId]);
            self::revider('kursstart_krav_sluppet', $bookingId, ['referanse' => $referanse]);
            return true;
        } finally {
            self::slipp($bookingId);
        }
    }
}
