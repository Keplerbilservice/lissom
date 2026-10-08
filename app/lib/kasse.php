<?php
/**
 * Lissom Kasse på iPad.
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
 * Hver del av et kjøp har sin egen idempotensnøkkel fra iPaden. Den står i
 * payments.idempotency_key (unik), så et dobbelttrykk eller et nytt forsøk
 * aldri lager raden to ganger, og samme nøkkel går til Vipps.
 */

declare(strict_types=1);

final class Kasse
{
    /** Kassa låser seg etter så mange minutter uten bruk. */
    public const LAAS_MINUTTER = 5;

    /** PIN-forsøk per iPad per fem minutter. */
    public const PIN_FORSOK = 10;

    /** «Betalt på annen måte» og kontant, slik de føres på raden. */
    public const MAATER = ['Kontant', 'Vipps', 'Faktura'];

    /** Delene i «Del betalingen». Samme som delt oppgjør i admin. */
    public const DELMAATER = ['Kontant', 'Vipps', 'Gavekort'];

    /** Referansen på Vipps-QR fra kassa. */
    public const QR_PREFIKS = 'KQ';

    private const MAKS_ORE = 10000000;
    private const MAKS_LINJER = 50;

    // ── Bryter og tilgang ───────────────────────────────────────────────

    public static function paa(): bool
    {
        if (!DB::harTabell('content_blocks')) {
            return false;
        }
        $v = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => 'Vis/kasse']);
        return $v !== null && $v !== false && (string) $v === 'ja';
    }

    /** Er migrasjon 263 kjørt? */
    public static function klar(): bool
    {
        return DB::harKolonne('sessions', 'kasse_ulast_til') && DB::harKolonne('members', 'kasse_pin_hash');
    }

    /**
     * Kassekontoen (eller admin, som kan åpne kassa fra PC-en). Alle andre får
     * samme svar som en fremmed.
     *
     * @return array<string,mixed>
     */
    public static function krevKonto(): array
    {
        $m = Sesjon::medlem();
        if ($m === null) {
            Svar::feil('Du må være logget inn.', 401, ['loggInn' => true]);
        }
        if (!Sesjon::erKasse() && !Sesjon::erAdmin()) {
            logg('Avvist kasseforsøk', ['medlem' => $m['id']]);
            Svar::feil('Fant ikke siden.', 404);
        }
        if (!self::klar()) {
            Svar::feil('Kassa krever en oppdatering av databasen. Trykk ⚙ Kjør oppdateringer i admin.', 503);
        }
        if (!self::paa()) {
            Svar::feil('Kassa er ikke slått på.', 403, ['av' => true]);
        }
        return $m;
    }

    /**
     * Personen som har låst opp kassa. Låst = 423. Skyver låsen fem minutter
     * fram: kassa låser seg etter fem minutter UTEN bruk.
     *
     * @return array{id:int, navn:string}
     */
    public static function krevUlast(): array
    {
        self::krevKonto();
        $h = Sesjon::tokenHash();
        $rad = $h === null ? null : DB::en(
            'SELECT m.id, m.navn
               FROM sessions s
               JOIN members m ON m.id = s.kasse_person_id
              WHERE s.token_hash = :h
                AND s.kasse_ulast_til > UTC_TIMESTAMP()
                AND m.kasse_pin_hash IS NOT NULL
                AND m.anonymisert_at IS NULL',
            ['h' => $h]
        );
        if ($rad === null) {
            Svar::feil('Kassa er låst.', 423, ['laast' => true]);
        }
        DB::kjor(
            'UPDATE sessions SET kasse_ulast_til = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::LAAS_MINUTTER . ' MINUTE)
              WHERE token_hash = :h',
            ['h' => $h]
        );
        return ['id' => (int) $rad['id'], 'navn' => (string) $rad['navn']];
    }

    /**
     * Hvem som har låst opp, uten å skyve låsen. null = låst.
     *
     * @return array{id:int, navn:string}|null
     */
    public static function ulast(): ?array
    {
        $h = Sesjon::tokenHash();
        if ($h === null) {
            return null;
        }
        $rad = DB::en(
            'SELECT m.id, m.navn
               FROM sessions s
               JOIN members m ON m.id = s.kasse_person_id
              WHERE s.token_hash = :h AND s.kasse_ulast_til > UTC_TIMESTAMP()
                AND m.kasse_pin_hash IS NOT NULL AND m.anonymisert_at IS NULL',
            ['h' => $h]
        );
        return $rad === null ? null : ['id' => (int) $rad['id'], 'navn' => (string) $rad['navn']];
    }

    /**
     * Låser opp med PIN. Feil PIN svarer 400; for mange forsøk 429.
     *
     * @return array{id:int, navn:string}
     */
    public static function laasOpp(string $pin): array
    {
        self::krevKonto();
        $h = (string) Sesjon::tokenHash();
        Rate::sjekk('kasse-pin', self::PIN_FORSOK, 300, $h);
        Rate::sjekk('kasse-pin-ip', 30, 300);
        if (preg_match('/^\d{4}$/', $pin) !== 1) {
            Svar::feil('PIN-en er fire sifre.');
        }
        $person = self::finnPin($pin);
        if ($person === null) {
            logg('Feil PIN i kassa');
            Svar::feil('Feil PIN. Prøv igjen.', 400, ['feilPin' => true]);
        }
        DB::kjor(
            'UPDATE sessions
                SET kasse_person_id = :p,
                    kasse_ulast_til = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::LAAS_MINUTTER . ' MINUTE)
              WHERE token_hash = :h',
            ['p' => $person['id'], 'h' => $h]
        );
        revider('kasse_laast_opp', 'member', $person['id']);
        return $person;
    }

    public static function laas(): void
    {
        $h = Sesjon::tokenHash();
        if ($h !== null && self::klar()) {
            DB::kjor('UPDATE sessions SET kasse_person_id = NULL, kasse_ulast_til = NULL WHERE token_hash = :h', ['h' => $h]);
        }
    }

    /** @return array{id:int, navn:string}|null */
    private static function finnPin(string $pin, int $utenom = 0): ?array
    {
        foreach (DB::alle(
            "SELECT id, navn, kasse_pin_hash FROM members
              WHERE kasse_pin_hash IS NOT NULL AND anonymisert_at IS NULL AND rolle <> 'kasse' AND id <> :u",
            ['u' => $utenom]
        ) as $m) {
            if (password_verify($pin, (string) $m['kasse_pin_hash'])) {
                return ['id' => (int) $m['id'], 'navn' => (string) $m['navn']];
            }
        }
        return null;
    }

    /**
     * Setter (eller fjerner, med null) PIN-en til én person. Bare fra admin.
     * PIN-en må være unik: den forteller kassa hvem som står der.
     */
    public static function settPin(int $medlemId, ?string $pin): void
    {
        if (!self::klar()) {
            throw new RuntimeException('Kassa krever en oppdatering av databasen. Trykk ⚙ Kjør oppdateringer.');
        }
        $m = DB::en('SELECT id, rolle FROM members WHERE id = :i AND anonymisert_at IS NULL', ['i' => $medlemId]);
        if ($m === null) {
            throw new RuntimeException('Fant ikke brukeren.');
        }
        if ($pin === null) {
            DB::iTransaksjon(static function () use ($medlemId): void {
                DB::oppdater('members', ['kasse_pin_hash' => null], ['id' => $medlemId]);
                // Står personen i kassa nå, låses den.
                DB::kjor('UPDATE sessions SET kasse_person_id = NULL, kasse_ulast_til = NULL WHERE kasse_person_id = :m',
                    ['m' => $medlemId]);
            });
            return;
        }
        if ((string) $m['rolle'] === 'kasse') {
            throw new RuntimeException('Kassekontoen kan ikke ha PIN. Gi PIN til personene som står i kassa.');
        }
        if (preg_match('/^\d{4}$/', $pin) !== 1) {
            throw new RuntimeException('PIN-en må være fire sifre.');
        }
        if (self::finnPin($pin, $medlemId) !== null) {
            throw new RuntimeException('Den PIN-en bruker en annen. Velg en annen.');
        }
        DB::oppdater('members', ['kasse_pin_hash' => password_hash($pin, PASSWORD_DEFAULT)], ['id' => $medlemId]);
    }

    // ── I dag ──────────────────────────────────────────────────────────

    /** @return array{0:string,1:string} start og slutt på dagen i dag (Oslo), i UTC */
    private static function dagen(): array
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc = new DateTimeZone('UTC');
        $fra = new DateTimeImmutable('today', $oslo);
        return [$fra->setTimezone($utc)->format('Y-m-d H:i:s'), $fra->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /** «1 150 kr». Samme form som skissen og prisnivåene. */
    public static function kr(int $ore): string
    {
        return PopPris::kr($ore);
    }

    /** Bookingen, når den hører til en økt i dag og er aktiv. */
    private static function dagensBooking(int $bookingId): ?array
    {
        [$fra, $til] = self::dagen();
        $pop = DB::harKolonne('bookings', 'depositum_ore')
            ? 'b.depositum_ore, b.gjenstander_ore' : 'NULL AS depositum_ore, NULL AS gjenstander_ore';
        return DB::en(
            "SELECT b.id, b.status, b.belop_ore, b.antall, b.member_id, b.course_id, {$pop},
                    COALESCE(m.navn, b.gjest_navn) AS navn,
                    COALESCE(NULLIF(m.epost, ''), b.gjest_epost) AS epost,
                    COALESCE(NULLIF(m.telefon, ''), b.gjest_telefon) AS telefon,
                    c.tittel, cs.start_tid
               FROM bookings b
               JOIN course_sessions cs ON cs.id = b.course_session_id
               JOIN courses c ON c.id = b.course_id
          LEFT JOIN members m ON m.id = b.member_id
              WHERE b.id = :i AND b.status IN ('betalt', 'reservert')
                AND cs.start_tid >= :fra AND cs.start_tid < :til",
            ['i' => $bookingId, 'fra' => $fra, 'til' => $til]
        );
    }

    /** Er bookingen Paint on Pots med beløp ved booking (kan slås inn i kassa)? */
    private static function erPop(array $b): bool
    {
        return $b['depositum_ore'] !== null && Malebord::gjelder((int) $b['course_id']) && PopPris::klar();
    }

    /**
     * Alt «I dag»-skjermen trenger.
     *
     * @return array<string,mixed>
     */
    public static function idag(): array
    {
        [$fra, $til] = self::dagen();
        $okter = DB::alle(
            "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.course_id, c.tittel
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.start_tid >= :fra AND cs.start_tid < :til AND cs.status <> 'avlyst'
           ORDER BY cs.start_tid, cs.id",
            ['fra' => $fra, 'til' => $til]
        );
        $oslo = new DateTimeZone('Europe/Oslo');
        $klokke = static fn(?string $utc): string => $utc === null || $utc === ''
            ? '' : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($oslo)->format('H:i');

        $grupper = [];
        $pop = DB::harKolonne('bookings', 'depositum_ore')
            ? 'b.depositum_ore, b.gjenstander_ore' : 'NULL AS depositum_ore, NULL AS gjenstander_ore';
        foreach ($okter as $o) {
            $rader = [];
            foreach (DB::alle(
                "SELECT b.id, b.status, b.belop_ore, b.antall, b.member_id, b.course_id, {$pop},
                        COALESCE(m.navn, b.gjest_navn) AS navn
                   FROM bookings b
              LEFT JOIN members m ON m.id = b.member_id
                  WHERE b.course_session_id = :s AND b.status IN ('betalt', 'reservert')
               ORDER BY b.id",
                ['s' => (int) $o['id']]
            ) as $b) {
                $id = (int) $b['id'];
                $erPop = self::erPop($b);
                $skyldig = KursstartKrav::skyldig($id, (int) $b['belop_ore'], (string) $b['status']);
                $betalt = (int) Booking::betalingerFor($id)['sum'];
                $antall = (int) $b['antall'];
                if ($erPop) {
                    // Det som er betalt ved booking: aldri mer enn beløpet ved
                    // booking, selv når resten er tatt i kassa etterpå.
                    $vedBooking = min($betalt, (int) $b['depositum_ore']);
                    $info = $antall . ' ' . ($antall === 1 ? 'person' : 'personer')
                          . ($vedBooking > 0 ? ' · ' . self::kr($vedBooking) . ' betalt ved booking' : '');
                    $pille = $b['gjenstander_ore'] === null
                        ? ['tekst' => 'Gjenstander', 'tone' => 'skylder']
                        : ($skyldig > 0 ? ['tekst' => 'Skylder ' . self::kr($skyldig), 'tone' => 'skylder']
                                        : ['tekst' => 'Betalt', 'tone' => 'betalt']);
                } else {
                    $info = $antall . ' ' . ($antall === 1 ? 'plass' : 'plasser');
                    $pille = $skyldig > 0 ? ['tekst' => 'Skylder ' . self::kr($skyldig), 'tone' => 'skylder']
                                          : ['tekst' => 'Betalt', 'tone' => 'betalt'];
                }
                $rader[] = [
                    'bookingId'  => $id,
                    'navn'       => (string) $b['navn'],
                    'info'       => $info,
                    'pop'        => $erPop,
                    'skyldigOre' => $skyldig,
                    'pille'      => $pille,
                ];
            }
            if ($rader === []) {
                continue;
            }
            $slutt = $klokke($o['slutt_tid'] ?? null);
            $grupper[] = [
                'oktId'  => (int) $o['id'],
                'tittel' => (string) $o['tittel'] . ' ' . $klokke((string) $o['start_tid']) . ($slutt !== '' ? '–' . $slutt : ''),
                'rader'  => $rader,
            ];
        }

        $inne = [];
        foreach (Stempling::alleInne() as $r) {
            $min = Stempling::minutterDenneManeden((int) $r['id']);
            $inne[] = [
                'medlemId' => (int) $r['id'],
                'navn'     => (string) $r['navn'],
                'info'     => trim(($r['type'] !== '' ? $r['type'] . ' · ' : '') . Stempling::timer($min) . ' t brukt'),
            ];
        }

        $varer = [];
        foreach (DB::alle(
            "SELECT id, tittel, kategori, pris_ore, lager, status FROM products
              WHERE status <> 'kladd' AND pris_ore > 0
           ORDER BY kategori IS NULL, kategori, tittel"
        ) as $v) {
            $varer[] = [
                'id'       => (int) $v['id'],
                'tittel'   => (string) $v['tittel'],
                'kategori' => (string) ($v['kategori'] ?? ''),
                'prisOre'  => (int) $v['pris_ore'],
                'pris'     => self::kr((int) $v['pris_ore']),
                'lager'    => $v['lager'] === null ? null : (int) $v['lager'],
                'utsolgt'  => (string) $v['status'] === 'utsolgt' || ($v['lager'] !== null && (int) $v['lager'] <= 0),
            ];
        }

        return [
            'dato'      => self::norskDag(),
            'grupper'   => $grupper,
            'inne'      => $inne,
            'varer'     => $varer,
            'nivaer'    => array_map(static fn(array $n): array => [
                'id' => $n['id'], 'navn' => $n['navn'], 'prisOre' => $n['prisOre'], 'pris' => self::kr($n['prisOre']),
                'gjenstander' => $n['gjenstander'],
            ], PopPris::nivaer()),
            'timepakke' => ['timer' => Timepakke::timer(), 'prisOre' => Timepakke::prisOre(), 'pris' => self::kr(Timepakke::prisOre())],
            'oppgjor'   => self::oppgjor(),
        ];
    }

    /** «torsdag 8. oktober» */
    private static function norskDag(): string
    {
        $d = new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo'));
        $dager = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];
        return $dager[(int) $d->format('N') - 1] . ' ' . (int) $d->format('j') . '. ' . $mnd[(int) $d->format('n') - 1];
    }

    /**
     * Én person fra dagens liste: Paint on Pots-kassa (gjenstandene som er
     * slått inn, og det som er betalt ved booking), eller det som skyldes på
     * kurset.
     *
     * @return array<string,mixed>
     */
    public static function person(int $bookingId): array
    {
        $b = self::dagensBooking($bookingId);
        if ($b === null) {
            throw new RuntimeException('Fant ikke påmeldingen i dag.');
        }
        $bet = Booking::betalingerFor($bookingId);
        $ut = [
            'bookingId' => $bookingId,
            'navn'      => (string) $b['navn'],
            'tittel'    => (string) $b['tittel'],
            'antall'    => (int) $b['antall'],
            'pop'       => self::erPop($b),
            'betaltOre' => (int) $bet['sum'],
            'skyldigOre'=> KursstartKrav::skyldig($bookingId, (int) $b['belop_ore'], (string) $b['status']),
        ];
        if ($ut['pop']) {
            $k = PopPris::kassaData($bookingId);
            $lagret = [];
            foreach ($k['rader'] as $r) {
                $n = array_sum(array_column($r['lagret'], 'antall'));
                if ($n > 0) {
                    $lagret[] = ['nokkel' => $r['nokkel'], 'nivaaId' => $r['nivaaId'], 'gjenstand' => $r['gjenstand'],
                                 'nivaa' => $r['nivaa'], 'antall' => $n];
                }
            }
            $ut['lagret'] = $lagret;
            $ut['depositumOre'] = (int) $b['depositum_ore'];
            // «Betalt ved booking»-raden: aldri mer enn beløpet ved booking.
            $ut['vedBookingOre'] = min((int) $bet['sum'], (int) $b['depositum_ore']);
            $ut['perPersonOre'] = (int) $b['antall'] > 0 ? intdiv((int) $b['depositum_ore'], (int) $b['antall']) : 0;
            $ut['kanEndre'] = self::betaltVedBooking($bet['rader']) !== [];
        }
        return $ut;
    }

    /**
     * Radene som er «betalt ved booking» og kan merkes «Betalte ikke»:
     * manuelle, ikke annullerte, ført da plassen ble lagt inn i admin
     * (api/admin/pamelding.php skriver kommentaren «Beløp ved booking»).
     *
     * @param list<array<string,mixed>> $rader Booking::betalingerFor()['rader']
     * @return list<array<string,mixed>>
     */
    private static function betaltVedBooking(array $rader): array
    {
        return array_values(array_filter($rader, static fn(array $r): bool =>
            (string) $r['type'] === 'manuell' && $r['annullert_at'] === null
            && (string) $r['status'] === 'betalt' && (string) ($r['kommentar'] ?? '') === 'Beløp ved booking'));
    }

    /**
     * «Endre» → «Betalte ikke»: beløpet ved booking ble ført, men kom aldri.
     *
     * Raden slettes ikke. Den annulleres slik «Angre» i Ta betalt gjør
     * (api/admin/kursbetaling.php): status avbrutt, hvem og når, og en
     * kommentar. Da trekkes ingenting fra i kassa, og kunden betaler alt.
     * Kom beløpet med Vipps, gjøres ingenting her — det refunderes.
     */
    public static function betalteIkke(int $bookingId, int $personId): array
    {
        $b = self::dagensBooking($bookingId);
        if ($b === null || !self::erPop($b)) {
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
            $rader = self::betaltVedBooking($bet['rader']);
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

    // ── Kurven ─────────────────────────────────────────────────────────

    /** Kroner slik de tastes («690», «690,50», «kr 690,-») til øre. null = ikke et tall. */
    private static function ore(mixed $raa): ?int
    {
        $t = str_replace([' ', "\u{a0}", 'kr', ',-'], '', (string) $raa);
        $t = trim(str_replace(',', '.', $t));
        if ($t === '' || !is_numeric($t)) {
            return null;
        }
        return (int) round((float) $t * 100);
    }

    /**
     * Den som betaler: personen fra dagens liste (en påmelding eller et
     * medlem som er inne), eller kontantkunden (tom).
     *
     * @param array<string,mixed> $betaler
     * @return array{navn:string, epost:string, telefon:string, medlemId:?int, bookingId:?int}
     */
    public static function betaler(array $betaler): array
    {
        $bid = (int) ($betaler['bookingId'] ?? 0);
        $mid = (int) ($betaler['medlemId'] ?? 0);
        if ($bid > 0) {
            $b = self::dagensBooking($bid);
            if ($b === null) {
                throw new RuntimeException('Fant ikke personen i dagens liste.');
            }
            return ['navn' => (string) $b['navn'], 'epost' => trim((string) ($b['epost'] ?? '')),
                    'telefon' => trim((string) ($b['telefon'] ?? '')),
                    'medlemId' => $b['member_id'] !== null ? (int) $b['member_id'] : null, 'bookingId' => $bid];
        }
        if ($mid > 0) {
            if (!in_array($mid, array_column(Stempling::alleInne(), 'id'), true)) {
                throw new RuntimeException('Fant ikke personen i dagens liste.');
            }
            $m = DB::en('SELECT id, navn, epost, telefon FROM members WHERE id = :i AND anonymisert_at IS NULL', ['i' => $mid]);
            if ($m === null) {
                throw new RuntimeException('Fant ikke personen i dagens liste.');
            }
            return ['navn' => (string) $m['navn'], 'epost' => trim((string) ($m['epost'] ?? '')),
                    'telefon' => trim((string) ($m['telefon'] ?? '')), 'medlemId' => $mid, 'bookingId' => null];
        }
        return ['navn' => '', 'epost' => '', 'telefon' => '', 'medlemId' => null, 'bookingId' => null];
    }

    /**
     * Kurven delt i det som registreres hver for seg, med beløpene regnet her.
     * Rekkefølgen er fast: påmeldingen, varene, Paint on Pots uten booking,
     * gavekortene, timepakken.
     *
     * @param array<string,mixed> $kurv
     * @param array<string,mixed> $betaler fra betaler()
     * @return list<array<string,mixed>>
     */
    public static function deler(array $kurv, array $betaler): array
    {
        $deler = [];

        // ── Påmeldingen ───────────────────────────────────────────────
        $bid = (int) ($kurv['bookingId'] ?? 0);
        if ($bid > 0) {
            $b = self::dagensBooking($bid);
            if ($b === null) {
                throw new RuntimeException('Fant ikke påmeldingen i dag.');
            }
            $pop = is_array($kurv['pop'] ?? null) ? array_values(array_filter($kurv['pop'], 'is_array')) : [];
            $pop = array_values(array_filter($pop, static fn(array $v): bool => (int) ($v['antall'] ?? 0) !== 0));
            $linjer = [];
            if (self::erPop($b) && $pop !== []) {
                $f = PopPris::forhandsvis($bid, $pop);
                $gruppe = [];
                foreach ($f['linjer'] as $l) {
                    $k = $l['navn'] . '|' . $l['pris_ore'];
                    $gruppe[$k] = ['navn' => (string) $l['navn'], 'pris' => (int) $l['pris_ore'],
                                   'antall' => ($gruppe[$k]['antall'] ?? 0) + (int) $l['antall']];
                }
                foreach ($gruppe as $g) {
                    $linjer[] = ['tekst' => $g['navn'] . ' × ' . $g['antall'], 'ore' => $g['pris'] * $g['antall']];
                }
                // Betalt ved booking (aldri mer enn beløpet ved booking), og det
                // som alt er tatt i kassa for seg.
                $vedBooking = min($f['betaltOre'], (int) $b['depositum_ore']);
                if ($vedBooking > 0) {
                    $linjer[] = ['tekst' => 'Betalt ved booking', 'ore' => -$vedBooking];
                }
                if ($f['betaltOre'] > $vedBooking) {
                    $linjer[] = ['tekst' => 'Betalt', 'ore' => -($f['betaltOre'] - $vedBooking)];
                }
                $skyldig = $f['skyldigOre'];
            } else {
                $pop = [];
                $betalt = (int) Booking::betalingerFor($bid)['sum'];
                $belop = (int) $b['belop_ore'];
                $skyldig = KursstartKrav::skyldig($bid, $belop, (string) $b['status']);
                $antall = (int) $b['antall'];
                $tekst = (string) $b['tittel'] . ' · ' . $antall . ' '
                    . (self::erPop($b) ? ($antall === 1 ? 'person' : 'personer') : ($antall === 1 ? 'plass' : 'plasser'));
                if ($skyldig > 0 && $betalt > 0 && $belop - $betalt === $skyldig) {
                    $linjer[] = ['tekst' => $tekst, 'ore' => $belop];
                    $linjer[] = ['tekst' => 'Betalt ved booking', 'ore' => -$betalt];
                } else {
                    $linjer[] = ['tekst' => $tekst, 'ore' => $skyldig];
                }
            }
            if ($skyldig > 0 || $pop !== []) {
                $deler[] = ['type' => 'booking', 'bookingId' => $bid, 'pop' => $pop, 'sumOre' => $skyldig,
                            'medlemId' => $b['member_id'] !== null ? (int) $b['member_id'] : null,
                            'tittel' => (string) $b['navn'], 'linjer' => $linjer];
            }
        }

        // ── Varer og «Skriv beløp» ────────────────────────────────────
        $ordre = [];
        $varer = is_array($kurv['varer'] ?? null) ? $kurv['varer'] : [];
        if (count($varer) > self::MAKS_LINJER) {
            throw new RuntimeException('For mange varer i ett salg.');
        }
        $perVare = [];
        foreach ($varer as $v) {
            $id = is_array($v) ? (int) ($v['id'] ?? 0) : 0;
            $n = is_array($v) ? (int) ($v['antall'] ?? 0) : 0;
            if ($id <= 0 || $n <= 0) {
                continue;
            }
            if ($n > 999) {
                throw new RuntimeException('For mange av én vare.');
            }
            $perVare[$id] = ($perVare[$id] ?? 0) + $n;
        }
        foreach ($perVare as $id => $n) {
            $p = DB::en("SELECT id, tittel, pris_ore, lager, status FROM products WHERE id = :i AND status <> 'kladd'", ['i' => $id]);
            if ($p === null || (int) $p['pris_ore'] <= 0) {
                throw new RuntimeException('En av varene finnes ikke lenger. Last siden på nytt.');
            }
            if ((string) $p['status'] === 'utsolgt' || ($p['lager'] !== null && (int) $p['lager'] < $n)) {
                throw new RuntimeException('Det er bare ' . max(0, (int) ($p['lager'] ?? 0)) . ' igjen av «' . $p['tittel'] . '».');
            }
            $ordre[] = ['produktId' => (int) $p['id'], 'tittel' => (string) $p['tittel'], 'antall' => $n, 'prisOre' => (int) $p['pris_ore']];
        }
        $fritt = is_array($kurv['fritt'] ?? null) ? $kurv['fritt'] : [];
        if (count($fritt) > 10) {
            throw new RuntimeException('For mange beløp i ett salg.');
        }
        foreach ($fritt as $raa) {
            $o = self::ore($raa);
            if ($o === null || $o <= 0) {
                throw new RuntimeException('Skriv inn et beløp over null.');
            }
            if ($o > self::MAKS_ORE) {
                throw new RuntimeException('Beløpet må være under 100 000 kroner.');
            }
            // Samme tittel som «Fritt beløp» i kassa i admin (uttak.php, SLAG produkt).
            $ordre[] = ['produktId' => null, 'tittel' => 'Produkt — solgt i verkstedet', 'antall' => 1, 'prisOre' => $o];
        }
        if ($ordre !== []) {
            $deler[] = self::ordreDel('ordre', $ordre, 'Salg');
        }

        // ── Paint on Pots uten booking ────────────────────────────────
        $popUten = is_array($kurv['popUten'] ?? null) ? $kurv['popUten'] : [];
        $gjester = (int) ($kurv['popGjester'] ?? 0);
        if ($popUten !== [] || $gjester > 0) {
            if ($gjester < 1 || $gjester > 50) {
                throw new RuntimeException('Velg antall personer.');
            }
            $nivaer = array_column(PopPris::nivaer(), null, 'id');
            $linjer = [];
            foreach ($popUten as $v) {
                $id = is_array($v) ? (int) ($v['nivaaId'] ?? 0) : 0;
                $n = is_array($v) ? (int) ($v['antall'] ?? 0) : 0;
                if ($n <= 0) {
                    continue;
                }
                if (!isset($nivaer[$id])) {
                    throw new RuntimeException('Fant ikke prisnivået. Last siden på nytt.');
                }
                if ($n > PopPris::MAKS_PER_NIVAA) {
                    throw new RuntimeException('Antallet må være mellom 0 og ' . PopPris::MAKS_PER_NIVAA . '.');
                }
                $linjer[] = ['produktId' => null, 'tittel' => 'Paint on Pots · ' . $nivaer[$id]['navn'], 'antall' => $n,
                             'prisOre' => (int) $nivaer[$id]['prisOre']];
            }
            if ($linjer === []) {
                throw new RuntimeException('Velg minst én gjenstand.');
            }
            $deler[] = self::ordreDel('booking', $linjer, 'Paint on Pots · ' . $gjester . ' ' . ($gjester === 1 ? 'person' : 'personer'))
                + ['gjester' => $gjester];
        }

        // ── Gavekort ──────────────────────────────────────────────────
        $gave = is_array($kurv['gavekort'] ?? null) ? $kurv['gavekort'] : [];
        if (count($gave) > 5) {
            throw new RuntimeException('For mange gavekort i ett salg.');
        }
        if ($gave !== [] && !DB::harKolonne('gift_cards', 'opprinnelse')) {
            throw new RuntimeException('Gavekort i kassa krever en oppdatering av databasen. Trykk ⚙ Kjør oppdateringer.');
        }
        foreach ($gave as $raa) {
            $o = self::ore($raa);
            // Samme grenser som nettsida og kassa i admin.
            if ($o === null || $o < 10000 || $o > 2000000) {
                throw new RuntimeException('Velg et beløp mellom 100 og 20 000 kroner.');
            }
            $deler[] = ['type' => 'gavekort', 'sumOre' => $o, 'tittel' => 'Gavekort',
                        'linjer' => [['tekst' => 'Gavekort', 'ore' => $o]]];
        }

        // ── Timepakke ─────────────────────────────────────────────────
        if (!empty($kurv['timepakke'])) {
            $mid = (int) ($betaler['medlemId'] ?? 0);
            $m = $mid > 0 ? DB::en('SELECT * FROM members WHERE id = :i AND anonymisert_at IS NULL', ['i' => $mid]) : null;
            if ($m === null) {
                throw new RuntimeException('Velg medlemmet som skal ha timepakken.');
            }
            $grunn = Timepakke::hvorforIkke($m);
            if ($grunn !== '') {
                throw new RuntimeException($grunn);
            }
            $pris = Timepakke::prisOre();
            if ($pris <= 0) {
                throw new RuntimeException('Timepakken har ingen pris. Sett den i admin.');
            }
            $deler[] = ['type' => 'timepakke', 'medlemId' => $mid, 'sumOre' => $pris, 'tittel' => 'Timepakke',
                        'linjer' => [['tekst' => 'Timepakke · ' . Timepakke::timer() . ' timer', 'ore' => $pris]]];
        }

        $sum = array_sum(array_column($deler, 'sumOre'));
        if ($sum > self::MAKS_ORE) {
            throw new RuntimeException('Beløpet må være under 100 000 kroner.');
        }
        return $deler;
    }

    /** @param list<array<string,mixed>> $linjer */
    private static function ordreDel(string $formal, array $linjer, string $tittel): array
    {
        $sum = 0;
        $vis = [];
        foreach ($linjer as $l) {
            $sum += $l['prisOre'] * $l['antall'];
            $vis[] = ['tekst' => $l['tittel'] . ($l['antall'] > 1 ? ' × ' . $l['antall'] : ''), 'ore' => $l['prisOre'] * $l['antall']];
        }
        return ['type' => 'ordre', 'formal' => $formal, 'linjer' => $vis, 'varer' => $linjer, 'sumOre' => $sum, 'tittel' => $tittel];
    }

    /**
     * Kurven slik skjermen viser den: linjer, sum per del og totalen.
     *
     * @param list<array<string,mixed>> $deler
     * @return array<string,mixed>
     */
    public static function visning(array $deler): array
    {
        $sum = array_sum(array_column($deler, 'sumOre'));
        return [
            'deler' => array_map(static fn(array $d): array => [
                'type'   => $d['type'],
                'tittel' => $d['tittel'],
                'sumOre' => $d['sumOre'],
                'sum'    => self::kr($d['sumOre']),
                'linjer' => array_map(static fn(array $l): array => $l + ['kr' => ($l['ore'] < 0 ? '−' : '') . self::kr(abs($l['ore']))], $d['linjer']),
            ], $deler),
            'sumOre' => $sum,
            'sum'    => self::kr($sum),
        ];
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
     * @param list<string> $nokler én UUID per del, i samme rekkefølge som deler()
     * @param list<array{maate:string,ore:int,kode?:string}>|null $delt
     * @param array{id:int,navn:string} $person
     * @return array<string,mixed>
     */
    public static function betal(array $kurv, array $betalerInn, string $maate, array $nokler, int $forventetOre, array $person, ?array $delt = null): array
    {
        $betaler = self::betaler($betalerInn);
        $deler = self::deler($kurv, $betaler);
        self::sjekkKurv($deler, $nokler, $forventetOre);

        $betalinger = null;
        if ($delt !== null) {
            if (count($deler) !== 1 || !in_array($deler[0]['type'], ['booking', 'ordre'], true)) {
                throw new RuntimeException('Del betalingen gjelder ett kjøp om gangen: en påmelding eller varer.');
            }
            $betalinger = self::lesDelt($delt, (int) $deler[0]['sumOre']);
        } elseif (!in_array($maate, self::MAATER, true)) {
            throw new RuntimeException('Velg hvordan kunden betaler.');
        }

        $resultat = ['deler' => [], 'gavekort' => [], 'kvittering' => []];
        foreach ($deler as $i => $d) {
            $rader = $betalinger ?? [['maate' => $maate, 'ore' => (int) $d['sumOre']]];
            $nokkel = (string) $nokler[$i];
            switch ($d['type']) {
                case 'booking':
                    self::registrerBooking($d, $rader, $person, $nokkel);
                    $resultat['kvittering'][] = ['type' => 'booking', 'id' => (int) $d['bookingId']];
                    break;
                case 'ordre':
                    $ordreId = self::registrerOrdre($d, $betaler, $rader, $person, $nokkel);
                    $resultat['kvittering'][] = ['type' => 'ordre', 'id' => $ordreId];
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
        $sum = array_sum(array_column($deler, 'sumOre'));
        $maateTekst = $delt !== null ? 'Del betalingen' : ($maate === 'Kontant' ? 'Kontant' : ($maate === 'Faktura' ? 'Faktura' : 'Vipps-nummer'));
        revider('kassesalg_ipad', null, null, ['person' => $person['id'], 'sum' => $sum, 'maate' => $maateTekst, 'deler' => $resultat['deler']]);
        return $resultat + ['sumOre' => $sum, 'sum' => self::kr($sum), 'kvitteringValg' => self::kvitteringValg($betaler, $resultat['kvittering'])];
    }

    /** @param list<array<string,mixed>> $deler */
    private static function sjekkKurv(array $deler, array $nokler, int $forventetOre): void
    {
        if ($deler === []) {
            throw new RuntimeException('Kurven er tom.');
        }
        $sum = array_sum(array_column($deler, 'sumOre'));
        if ($sum !== $forventetOre) {
            throw new RuntimeException('Beløpet er endret til ' . self::kr($sum) . '. Se over kurven og prøv igjen.', 409);
        }
        if (count($nokler) !== count($deler)) {
            throw new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
        }
        foreach ($nokler as $n) {
            if (!is_string($n) || !self::gyldigNokkel($n)) {
                throw new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
            }
        }
        if (count(array_unique(array_map('strtolower', $nokler))) !== count($nokler)) {
            throw new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
        }
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
            $o = self::ore($d['belop'] ?? '');
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
                return;   // gjenstandene er slått inn, og ingenting står igjen
            }
            if ($viaVipps !== null && !PopPris::harRest($bid)) {
                throw new RuntimeException('Denne er betalt gjennom Vipps. Bruk refusjon under Økonomi hvis noe skal rettes.', 409);
            }
            if (array_sum(array_column($rader, 'ore')) !== $skyldig) {
                throw new RuntimeException('Beløpet er endret til ' . self::kr($skyldig) . '. Se over kurven og prøv igjen.', 409);
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
                            Booking::trekkGavekortEllerAvbryt((int) DB::settInn('payments', $felt));
                            continue;
                        }
                        Booking::manuellBetaling($bid, (int) $r['ore'], $r['maate'], $medlemId, $person['id'],
                            'Kassa', self::manuellNokkel($nokkel, $j));
                    }
                });
            } catch (PDOException $e) {
                if (self::erDuplikat($e)) {
                    return;   // et samtidig trykk rakk det først
                }
                throw $e;
            }
            Booking::settBetaltStatus($bid);
            revider('betaling_registrert', 'booking', $bid, ['belop_ore' => $skyldig, 'kasse' => $person['id'],
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
            return ['kode' => $kode, 'belop' => self::kr((int) $d['sumOre'])];
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
        return ['kode' => $kode, 'belop' => self::kr((int) $d['sumOre'])];
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
     * @return array<string,mixed> {betalt:bool, qr?:string, belop?:string, poll?:array}
     */
    public static function qr(array $kurv, array $betalerInn, int $del, array $nokler, int $forventetOre, array $person): array
    {
        $betaler = self::betaler($betalerInn);
        $deler = self::deler($kurv, $betaler);
        self::sjekkKurv($deler, $nokler, $forventetOre);
        if (!isset($deler[$del])) {
            throw new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
        }
        $d = $deler[$del];
        $nokkel = strtolower((string) $nokler[$del]);

        if ($d['type'] === 'booking') {
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
                return ['betalt' => true, 'poll' => ['referanse' => $ref]];
            }
            if ((string) $finnes['status'] === 'venter') {
                $t = Vipps::synkroniser($ref);
                if (in_array($t, ['AUTHORIZED', 'CAPTURED'], true)) {
                    return ['betalt' => true, 'poll' => ['referanse' => $ref]];
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

        $ref = Vipps::nyReferanse(self::QR_PREFIKS);
        $rydd = self::qrRader($d, $betaler, $ref, $nokkel, $person);
        return self::qrSend($ref, $sum, self::qrTekst($d), $nokkel, $rydd);
    }

    /**
     * Det kvitteringen gjelder for en QR-del: påmeldingen, eller ordren bak
     * referansen. Gavekort og timepakke har ingen kvittering av dette slaget.
     *
     * @param array<string,mixed> $svar fra qr()
     * @return array{type:string,id:int}|null
     */
    public static function qrMal(array $svar): ?array
    {
        $poll = $svar['poll'] ?? [];
        if ((int) ($poll['bookingId'] ?? 0) > 0) {
            return ['type' => 'booking', 'id' => (int) $poll['bookingId']];
        }
        $ref = (string) ($poll['referanse'] ?? '');
        $ordre = $ref !== '' ? DB::verdi('SELECT order_id FROM payments WHERE vipps_reference = :r', ['r' => $ref]) : null;
        return $ordre !== null && $ordre !== false ? ['type' => 'ordre', 'id' => (int) $ordre] : null;
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
                revider('vippsqr_laget', 'order', $ordreId, ['belop' => (int) $d['sumOre'], 'kasse' => $person['id']]);
                return static function () use ($ordreId, $betalingId): void {
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
        return ['betalt' => false, 'qr' => $bilde, 'belop' => self::kr($sum), 'poll' => ['referanse' => $ref]];
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
            return ['betalt' => true, 'poll' => ['bookingId' => $bid]];
        }
        $r = KursstartKrav::visQr($bid);
        if (($r['status'] ?? '') === 'betalt') {
            return ['betalt' => true, 'poll' => ['bookingId' => $bid]];
        }
        return ['betalt' => false, 'qr' => (string) ($r['qr'] ?? ''), 'belop' => self::kr(self::skyldigNaa($bid)),
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
            if (self::dagensBooking($bid) === null) {
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
                return ['status' => 'betalt', 'betalt' => true];
            }
            $siste = (string) (DB::verdi(
                "SELECT status FROM payments WHERE booking_id = :b AND type = 'epayment' AND vipps_reference LIKE 'KS-QR-%'
              ORDER BY id DESC LIMIT 1", ['b' => $bid]) ?? '');
            return ['status' => $siste !== '' ? $siste : 'venter', 'betalt' => false];
        }
        $ref = trim((string) ($poll['referanse'] ?? ''));
        if (!str_starts_with($ref, self::QR_PREFIKS . '-')) {
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
            $kode = DB::verdi("SELECT kode FROM gift_cards WHERE payment_id = :p AND status <> 'ubetalt'", ['p' => (int) $rad['id']]);
            if ($kode !== null && $kode !== false) {
                $ut['kode'] = (string) $kode;
            }
        }
        return $ut;
    }

    // ── Kvittering ─────────────────────────────────────────────────────

    /**
     * Hvilke kvitteringer som kan sendes: e-post når vi har en adresse, SMS
     * når malen sendes på SMS og SMS er satt opp. Kontantkunden har ingen.
     *
     * @param list<array{type:string,id:int}> $mal
     * @return array{epost:bool, sms:bool, mal:list<array{type:string,id:int}>}
     */
    public static function kvitteringValg(array $betaler, array $mal): array
    {
        $smsMal = (string) (DB::verdi("SELECT kanal FROM notification_templates WHERE navn = 'ordrebekreftelse' AND aktiv = 1") ?? '');
        $harBooking = in_array('booking', array_column($mal, 'type'), true);
        return [
            'epost' => $mal !== [] && $betaler['epost'] !== '' && filter_var($betaler['epost'], FILTER_VALIDATE_EMAIL) !== false,
            'sms'   => $harBooking && $betaler['telefon'] !== '' && Varsel::smsMulig() && in_array($smsMal, ['sms', 'epost_sms'], true),
            'mal'   => $mal,
        ];
    }

    /**
     * Sender kvitteringen den vanlige veien (som «Send kvittering på nytt» i
     * Penger): bekreftelsen for påmeldingen, ordrebekreftelsen for varene.
     * Bare for det som er registrert i kassa i dag.
     *
     * @param list<array<string,mixed>> $mal
     */
    public static function kvittering(array $mal, string $kanal): int
    {
        if (!in_array($kanal, ['epost', 'sms'], true)) {
            throw new RuntimeException('Velg e-post eller SMS.');
        }
        [$fra] = self::dagen();
        $sendt = 0;
        foreach (array_slice($mal, 0, 5) as $m) {
            $id = (int) ($m['id'] ?? 0);
            $type = (string) ($m['type'] ?? '');
            if ($type === 'booking') {
                $ok = DB::verdi(
                    "SELECT 1 FROM payments WHERE booking_id = :b AND created_at >= :fra AND status IN ('betalt','delvis_refundert')
                       AND annullert_at IS NULL LIMIT 1",
                    ['b' => $id, 'fra' => $fra]
                );
                if ($ok === null || $ok === false) {
                    throw new RuntimeException('Fant ingen betaling i dag å sende kvittering for.', 404);
                }
                Booking::sendBekreftelse($id, $kanal);
                $sendt++;
            } elseif ($type === 'ordre' && $kanal === 'epost') {
                $o = DB::en(
                    "SELECT id, kunde_epost FROM orders WHERE id = :i AND created_at >= :fra
                        AND (ordrenr LIKE 'D-%' OR ordrenr LIKE 'Q-%') AND status IN ('hentet','betalt')",
                    ['i' => $id, 'fra' => $fra]
                );
                if ($o === null || trim((string) ($o['kunde_epost'] ?? '')) === '') {
                    throw new RuntimeException('Fant ingen betaling i dag å sende kvittering for.', 404);
                }
                Booking::sendOrdrebekreftelse($id);
                $sendt++;
            }
        }
        if ($sendt === 0) {
            throw new RuntimeException('Fant ingen betaling i dag å sende kvittering for.', 404);
        }
        return $sendt;
    }

    // ── Dagens oppgjør ─────────────────────────────────────────────────

    /**
     * Hva som er tatt inn i dag, per betalingsmåte. Samme rader som Penger og
     * dagsoppgjøret i admin (Omsetning::rader()), netto etter refusjon.
     *
     *   Kontant              raden eller ordren sier Kontant
     *   Betalt på annen måte  ført for hånd med en annen måte (Vipps til
     *                        verkstedets nummer, faktura)
     *   Vipps                alt som gikk gjennom Vipps (QR, nettsida, trekk)
     *   Gavekort             det gavekort dekket (ingen penger inn i dag)
     *
     * @return array<string,mixed>
     */
    public static function oppgjor(): array
    {
        [$fra, $til] = self::dagen();
        $sum = ['Vipps' => 0, 'Kontant' => 0, 'Annen' => 0, 'Gavekort' => 0];
        foreach (Omsetning::rader($fra, $til) as $r) {
            $penger = (int) $r['belop_ore'] - (int) ($r['refundert_ore'] ?? 0);
            $sum['Gavekort'] += (int) ($r['gavekort_ore'] ?? 0);
            if ($penger === 0) {
                continue;
            }
            $m = (string) ($r['radmaate'] ?? '');
            if ($m === '') {
                $m = (string) ($r['betalt_maate'] ?? '');
            }
            if ($m === 'Kontant') {
                $sum['Kontant'] += $penger;
            } elseif ((string) ($r['type'] ?? '') === 'manuell') {
                $sum['Annen'] += $penger;
            } else {
                $sum['Vipps'] += $penger;
            }
        }
        $rader = [
            ['navn' => 'Vipps', 'ore' => $sum['Vipps']],
            ['navn' => 'Kontant', 'ore' => $sum['Kontant']],
            ['navn' => 'Betalt på annen måte', 'ore' => $sum['Annen']],
        ];
        if ($sum['Gavekort'] > 0) {
            $rader[] = ['navn' => 'Gavekort', 'ore' => $sum['Gavekort']];
        }
        $total = $sum['Vipps'] + $sum['Kontant'] + $sum['Annen'];
        return [
            'rader'      => array_map(static fn(array $r): array => $r + ['kr' => self::kr($r['ore'])], $rader),
            'totalOre'   => $total,
            'total'      => self::kr($total),
            'kontantOre' => $sum['Kontant'],
            'kontant'    => self::kr($sum['Kontant']),
        ];
    }
}
