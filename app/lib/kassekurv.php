<?php
/**
 * Lissom Kasse på iPad: «I dag», kurven og dagens oppgjør (eieren,
 * 8. oktober 2026).
 *
 * Beløpene regnes her, fra basen: varepris, nivåpris, skyldig. Nettleseren
 * sender bare hva som er valgt. Hver del av kurven har en fast id
 * («booking:12», «ordre», «pop», «gavekort:0», «timepakke»), så iPaden og
 * serveren snakker om den samme delen selv om en annen del er betalt i
 * mellomtiden.
 *
 * Betalingen: app/lib/kasse.php. Tilgang og PIN: app/lib/kassetilgang.php.
 */

declare(strict_types=1);

final class KasseKurv
{
    public const MAKS_ORE = 10000000;
    /** Eierens regler for rabatt (8. oktober 2026). */
    public const PROSENT_BETALT = 'Prosent kan bare gis når hele beløpet betales nå.';
    public const PRIS_OG_RABATT = 'Prisen er endret – rabatt kan ikke gis i tillegg.';
    private const MAKS_LINJER = 50;

    // ── I dag ──────────────────────────────────────────────────────────

    /** @return array{0:string,1:string} start og slutt på dagen i dag (Oslo), i UTC */
    public static function dagen(): array
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

    public const VIPPS_PAAGAR = 'En Vipps-betaling pågår for denne påmeldingen. Vent til den er ferdig.';

    /**
     * Bookingen, når den er aktiv og økta ikke er avlyst. Uansett dato: bare
     * betalingsveien (person, kurven, betal, QR, status), så «Ta betalt» i ny
     * admin kan ta betalt for alle datoer (eieren 9. oktober 2026, fraAdmin()).
     * Alt annet i kassa bruker dagensBooking() (kontrolløren 9. oktober 2026).
     */
    public static function booking(int $bookingId): ?array
    {
        $pop = (DB::harKolonne('bookings', 'depositum_ore')
            ? 'b.depositum_ore, b.gjenstander_ore' : 'NULL AS depositum_ore, NULL AS gjenstander_ore')
            . (DB::harKolonne('bookings', 'kasse_rabatt_ore') ? ', b.kasse_rabatt_ore' : ', 0 AS kasse_rabatt_ore');
        return DB::en(
            "SELECT b.id, b.status, b.belop_ore, b.antall, b.member_id, b.course_id, b.course_session_id, {$pop},
                    COALESCE(m.navn, b.gjest_navn) AS navn,
                    COALESCE(NULLIF(m.epost, ''), b.gjest_epost) AS epost,
                    COALESCE(NULLIF(m.telefon, ''), b.gjest_telefon) AS telefon,
                    c.tittel, cs.start_tid
               FROM bookings b
               JOIN course_sessions cs ON cs.id = b.course_session_id AND cs.status <> 'avlyst'
               JOIN courses c ON c.id = b.course_id
          LEFT JOIN members m ON m.id = b.member_id
              WHERE b.id = :i AND b.status IN ('betalt', 'reservert')",
            ['i' => $bookingId]
        );
    }

    /** Bookingen, når den hører til en økt i dag (ikke avlyst) og er aktiv. */
    public static function dagensBooking(int $bookingId): ?array
    {
        $b = self::booking($bookingId);
        [$fra, $til] = self::dagen();
        return $b !== null && (string) $b['start_tid'] >= $fra && (string) $b['start_tid'] < $til ? $b : null;
    }

    /**
     * Pågår en Vipps-betaling for påmeldingen (opprettet/venter)? Samme vakt
     * som «Ta betalt» og «endre» i admin (Booking::vippsPaaVeiSql). Med
     * $utenKassa telles ikke kassa/kursstartens egne QR-koder (KS-…), som
     * KursstartKrav stopper eller gjenbruker selv.
     */
    public static function vippsPaaVei(int $bookingId, bool $utenKassa = false): bool
    {
        if ($utenKassa) {
            $bid = DB::harKolonne('payments', 'booking_id') ? ' OR p.booking_id = b.id' : '';
            return (int) DB::verdi(
                "SELECT EXISTS(SELECT 1 FROM payments p
                                WHERE (p.id = b.payment_id{$bid}) AND p.status IN ('opprettet', 'venter')
                                  AND COALESCE(p.vipps_reference, '') NOT LIKE 'KS-%')
                   FROM bookings b WHERE b.id = :i",
                ['i' => $bookingId]
            ) === 1;
        }
        return (int) DB::verdi('SELECT ' . Booking::vippsPaaVeiSql('b') . ' FROM bookings b WHERE b.id = :i', ['i' => $bookingId]) === 1;
    }

    /**
     * «Ta betalt» i ny admin (/kasse?booking=<id>, eieren 9. oktober 2026):
     * påmeldingen som skal i kurven, uansett dato. Samme regel som «Dagens
     * kurs» (deltakere(): skalBetale), og beløpet regnes som når personen
     * velges der (person(), deler()). Alt betalt, Vipps pågår eller ikke
     * funnet: en beskjed, og ingenting legges i kurven.
     *
     * @return array{bookingId:int, navn:string, oktId:int, idag:bool, skyldigOre:int}
     */
    public static function fraAdmin(int $bookingId): array
    {
        $b = $bookingId > 0 ? self::booking($bookingId) : null;
        $rad = null;
        if ($b !== null) {
            foreach (self::deltakere((int) $b['course_session_id']) as $r) {
                if ($r['bookingId'] === $bookingId) {
                    $rad = $r;
                    break;
                }
            }
        }
        if ($b === null || $rad === null) {
            throw new RuntimeException('Fant ikke påmeldingen.', 404);
        }
        if (!$rad['skalBetale']) {
            throw new RuntimeException('Påmeldingen er alt betalt.', 409);
        }
        if (self::vippsPaaVei($bookingId)) {
            throw new RuntimeException(self::VIPPS_PAAGAR, 409);
        }
        [$fra, $til] = self::dagen();
        $start = (string) $b['start_tid'];
        return [
            'bookingId'  => $bookingId,
            'navn'       => (string) $rad['navn'],
            'oktId'      => (int) $b['course_session_id'],
            'idag'       => $start >= $fra && $start < $til,
            'skyldigOre' => (int) $rad['skyldigOre'],
        ];
    }

    /** Er bookingen Paint on Pots med beløp ved booking (kan slås inn i kassa)? */
    public static function erPop(array $b): bool
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
        $kurs = [];
        $ledigeKurs = [];
        $ledige = $okter !== [] ? Booking::ledigePlasserFlere(array_map(static fn(array $o): int => (int) $o['id'], $okter)) : [];
        foreach ($okter as $o) {
            $rader = self::deltakere((int) $o['id']);
            $oktId = (int) $o['id'];
            $start = $klokke((string) $o['start_tid']);
            $erPop = Malebord::gjelder((int) $o['course_id']) && PopPris::erDepositum((int) $o['course_id']);
            $skalBetale = count(array_filter($rader, static fn(array $r): bool => $r['skalBetale']));
            // «Dagens kurs» (eieren 8. oktober 2026, oppsett A): bare kurs der
            // noen skal betale, sortert etter klokkeslett.
            if ($skalBetale > 0) {
                $kurs[] = ['oktId' => $oktId, 'tittel' => (string) $o['tittel'], 'kl' => $start, 'pop' => $erPop, 'skalBetale' => $skalBetale];
            }
            // «Ny kunde» → «Knytt til dagens kurs»: kurs med ledige plasser.
            if (($ledige[$oktId] ?? 0) > 0) {
                $ledigeKurs[] = ['oktId' => $oktId, 'tittel' => (string) $o['tittel'], 'kl' => $start, 'pop' => $erPop, 'ledige' => (int) $ledige[$oktId]];
            }
            if ($rader === []) {
                continue;
            }
            $slutt = $klokke($o['slutt_tid'] ?? null);
            $grupper[] = [
                'oktId'  => $oktId,
                'tittel' => (string) $o['tittel'] . ' ' . $start . ($slutt !== '' ? '–' . $slutt : ''),
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
            'kurs'      => $kurs,
            'ledigeKurs'=> $ledigeKurs,
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

    /**
     * Påmeldingene på én økt: den samme lista som Kalender i admin
     * (bookings på økta, aktive), med hva hver skylder. «skalBetale»: noe
     * står igjen, eller Paint on Pots der gjenstandene ikke er slått inn.
     *
     * @return list<array<string,mixed>>
     */
    public static function deltakere(int $oktId): array
    {
        $pop = DB::harKolonne('bookings', 'depositum_ore')
            ? 'b.depositum_ore, b.gjenstander_ore' : 'NULL AS depositum_ore, NULL AS gjenstander_ore';
        $rader = [];
        foreach (DB::alle(
            "SELECT b.id, b.status, b.belop_ore, b.antall, b.member_id, b.course_id, {$pop},
                    COALESCE(m.navn, b.gjest_navn) AS navn
               FROM bookings b
          LEFT JOIN members m ON m.id = b.member_id
              WHERE b.course_session_id = :s AND b.status IN ('betalt', 'reservert')
           ORDER BY b.id",
            ['s' => $oktId]
        ) as $b) {
            $id = (int) $b['id'];
            $erPop = self::erPop($b);
            $skyldig = KursstartKrav::skyldig($id, (int) $b['belop_ore'], (string) $b['status']);
            $betalt = (int) Booking::betalingerFor($id)['sum'];
            $antall = (int) $b['antall'];
            $vedBooking = 0;
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
                'bookingId'    => $id,
                'navn'         => (string) $b['navn'],
                'antall'       => $antall,
                'info'         => $info,
                'pop'          => $erPop,
                'skyldigOre'   => $skyldig,
                'skyldig'      => self::kr($skyldig),
                'depositumOre' => $vedBooking,
                'depositum'    => $vedBooking > 0 ? self::kr($vedBooking) : '',
                'skalBetale'   => $pille['tone'] === 'skylder',
                'pille'        => $pille,
            ];
        }
        return $rader;
    }

    /**
     * Ett av dagens kurs i kassa: de som ikke har betalt (eieren 8. oktober
     * 2026). iPaden spør på nytt hvert 15. sekund, så en påmelding eller en
     * betaling i admin vises uten å oppdatere.
     *
     * @return array<string,mixed>
     */
    public static function kurs(int $oktId): array
    {
        [$fra, $til] = self::dagen();
        $o = DB::en(
            "SELECT cs.id, cs.start_tid, cs.course_id, c.tittel
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :i AND cs.start_tid >= :fra AND cs.start_tid < :til AND cs.status <> 'avlyst'",
            ['i' => $oktId, 'fra' => $fra, 'til' => $til]
        );
        if ($o === null) {
            throw new RuntimeException('Fant ikke kurset i dag.', 404);
        }
        $rader = array_values(array_filter(self::deltakere($oktId), static fn(array $r): bool => $r['skalBetale']));
        return [
            'oktId'      => $oktId,
            'tittel'     => (string) $o['tittel'],
            'kl'         => (new DateTimeImmutable((string) $o['start_tid'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i'),
            'pop'        => Malebord::gjelder((int) $o['course_id']) && PopPris::erDepositum((int) $o['course_id']),
            'skalBetale' => count($rader),
            'rader'      => $rader,
        ];
    }

    /**
     * «+ Ny kunde» (eieren 8. oktober 2026): navn og telefon, og eventuelt en
     * plass på et av dagens kurs. Plassen er en vanlig påmelding (samme
     * regler som påmelding i admin: økta finnes og er ikke avlyst, og det må
     * være en ledig plass — sjekket med lås, så to iPader ikke får den
     * samme). Den står som «Ikke betalt» til den er betalt i kassa.
     *
     * @param array{id:int,navn:string} $person
     * @return array<string,mixed> betaleren skjermen bruker videre
     */
    public static function nyKunde(string $navn, string $telefonRaa, int $oktId, int $antall, array $person): array
    {
        $navn = trim(preg_replace('/\s+/u', ' ', $navn) ?? '');
        if ($navn === '' || mb_strlen($navn) > 191) {
            throw new RuntimeException('Skriv inn navnet.');
        }
        $telefon = trim($telefonRaa) === '' ? '' : normaliser_telefon($telefonRaa);
        if ($telefon !== '' && !preg_match('/^\+\d{8,15}$/', $telefon)) {
            throw new RuntimeException('Telefonnummeret ser ikke riktig ut.');
        }
        if ($oktId <= 0) {
            // «Bare kjøp»: et vanlig salg med navn og telefon på kvitteringen.
            return ['betaler' => ['navn' => $navn, 'telefon' => $telefon], 'navn' => $navn, 'telefon' => $telefon];
        }
        if ($antall < 1 || $antall > 20) {
            throw new RuntimeException('Velg antall plasser.');
        }
        [$fra, $til] = self::dagen();
        $prisKol = DB::harKolonne('course_sessions', 'pris_ore') ? 'COALESCE(cs.pris_ore, c.pris_ore)' : 'c.pris_ore';
        $okt = DB::en(
            "SELECT cs.id, cs.course_id, cs.start_tid, cs.slutt_tid, c.tittel, {$prisKol} AS pris_ore
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :i AND cs.status <> 'avlyst' AND cs.start_tid >= :fra AND cs.start_tid < :til",
            ['i' => $oktId, 'fra' => $fra, 'til' => $til]
        );
        if ($okt === null) {
            throw new RuntimeException('Fant ikke kurset i dag.', 404);
        }
        // Samme beløp som en plass lagt inn i admin uten beløp: Paint on Pots
        // med beløp ved booking = beløpet ved booking × antall, ellers prisen
        // på datoen × antall (api/admin/pamelding.php).
        $popPer = PopPris::depositumPerPerson((int) $okt['course_id']);
        $belop = ($popPer ?? (int) $okt['pris_ore']) * $antall;
        $popFelt = PopPris::bookingFelt((int) $okt['course_id'], $belop);
        // Ikke betalt: plassen holdes til økta er slutt (eller dagen er
        // slutt), så den ikke blir hengende.
        $holdTil = trim((string) ($okt['slutt_tid'] ?? '')) !== '' ? (string) $okt['slutt_tid'] : $til;
        try {
            $bookingId = DB::iTransaksjon(static function () use ($okt, $oktId, $navn, $telefon, $antall, $belop, $popFelt, $person, $holdTil): int {
                if (Booking::ledigePlasser($oktId, true) < $antall) {
                    throw new RuntimeException('Det er ikke nok ledige plasser på ' . $okt['tittel'] . '.', 409);
                }
                // Et dobbelttrykk lager ikke to plasser: samme navn og telefon
                // på samme økt, lagt inn de siste 2 minuttene.
                if (DB::en("SELECT id FROM bookings
                              WHERE course_session_id = :o AND gjest_navn = :n AND status <> 'avbestilt'
                                AND (gjest_telefon = :t OR (:t2 = '' AND gjest_telefon IS NULL))
                                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)",
                    ['o' => $oktId, 'n' => $navn, 't' => $telefon, 't2' => $telefon]) !== null) {
                    throw new RuntimeException($navn . ' står alt på dette kurset.', 409);
                }
                return DB::settInn('bookings', [
                    'course_id'         => (int) $okt['course_id'],
                    'course_session_id' => $oktId,
                    'member_id'         => null,
                    'gjest_navn'        => $navn,
                    'gjest_epost'       => null,
                    'gjest_telefon'     => $telefon !== '' ? $telefon : null,
                    'antall'            => $antall,
                    'belop_ore'         => $belop,
                    'status'            => 'reservert',
                    'betalt_maate'      => 'Ikke betalt',
                    'lagt_inn_av'       => (int) $person['id'],
                    'reservert_til'     => $holdTil,
                ] + $popFelt);
            });
        } catch (PDOException $e) {
            throw new RuntimeException('Fikk ikke lagt inn plassen. Prøv igjen.', 409);
        }
        revider('pamelding_lagt_inn', 'booking', $bookingId, [
            'navn' => $navn, 'okt' => $oktId, 'maate' => 'Ikke betalt', 'belop_ore' => $belop, 'kasse' => $person['id'],
        ]);
        return ['betaler' => ['bookingId' => $bookingId], 'bookingId' => $bookingId, 'navn' => $navn, 'telefon' => $telefon,
                'person' => self::person($bookingId)];
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
        $b = self::booking($bookingId);
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
            // «Endre» → «Betalte ikke» bare for påmeldinger i dag (Kasse::betalteIkke, dagensBooking()).
            $ut['kanEndre'] = self::betaltVedBooking($bet['rader']) !== [] && self::dagensBooking($bookingId) !== null;
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
    public static function betaltVedBooking(array $rader): array
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

    // ── Kurven ─────────────────────────────────────────────────────────

    /** Kroner slik de tastes («690», «690,50», «kr 690,-») til øre. null = ikke et tall. */
    public static function ore(mixed $raa): ?int
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
            $b = self::booking($bid);
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
        // «+ Ny kunde» → «Bare kjøp»: navn og telefon på kvitteringen. Er
        // nummeret til ett medlem hos oss, er det medlemmet (timepakke).
        $navn = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($betaler['navn'] ?? '')) ?? ''), 0, 191);
        $tlfRaa = trim((string) ($betaler['telefon'] ?? ''));
        $tlf = $tlfRaa === '' ? '' : normaliser_telefon($tlfRaa);
        if ($tlf !== '' && !preg_match('/^\+\d{8,15}$/', $tlf)) {
            throw new RuntimeException('Telefonnummeret ser ikke riktig ut.');
        }
        if ($navn === '' && $tlf === '') {
            return ['navn' => '', 'epost' => '', 'telefon' => '', 'medlemId' => null, 'bookingId' => null];
        }
        $m = $tlf !== '' ? self::medlemMedTelefon($tlf) : null;
        return ['navn' => $navn !== '' ? $navn : (string) ($m['navn'] ?? ''), 'epost' => trim((string) ($m['epost'] ?? '')),
                'telefon' => $tlf, 'medlemId' => $m !== null ? (int) $m['id'] : null, 'bookingId' => null];
    }

    /** Medlemmet med nummeret, når det er akkurat ett. */
    private static function medlemMedTelefon(string $tlf): ?array
    {
        $siste = substr(preg_replace('/\D/', '', $tlf) ?? '', -8);
        if (strlen($siste) < 8) {
            return null;
        }
        $treff = array_values(array_filter(DB::alle(
            "SELECT id, navn, epost, telefon FROM members
              WHERE anonymisert_at IS NULL AND telefon LIKE :t",
            ['t' => '%' . implode('%', str_split($siste)) . '%']
        ), static fn(array $m): bool => normaliser_telefon((string) $m['telefon']) === $tlf));
        return count($treff) === 1 ? $treff[0] : null;
    }

    /**
     * Kurven delt i det som registreres hver for seg, med beløpene regnet her.
     * Rekkefølgen er fast: påmeldingen, varene, Paint on Pots uten booking,
     * gavekortene, timepakken. Hver del har en fast id («booking:12», «ordre»,
     * «pop», «gavekort:0», «timepakke»), så en del som er betalt ikke flytter
     * på de andre.
     *
     * @param array<string,mixed> $kurv
     * @param array<string,mixed> $betaler fra betaler()
     * @return list<array<string,mixed>>
     */
    public static function deler(array $kurv, array $betaler, array $nokler = []): array
    {
        $deler = [];
        // Endret pris per linje (linjens nøkkel => ny pris i øre). Linjer som
        // ikke finnes i kurven, er en kurv som er endret.
        $priser = self::lesPriser($kurv['priser'] ?? null);
        $endringer = [];   // del-id => list av endrede linjer
        $prisTrekk = [];   // del-id => øre trukket fra med endret pris

        // ── Påmeldingen ───────────────────────────────────────────────
        $bid = (int) ($kurv['bookingId'] ?? 0);
        if ($bid > 0) {
            $b = self::booking($bid);
            if ($b === null) {
                throw new RuntimeException('Fant ikke påmeldingen i dag.');
            }
            $delId = 'booking:' . $bid;
            $endringer[$delId] = [];
            $prisTrekk[$delId] = 0;
            $pop = is_array($kurv['pop'] ?? null) ? array_values(array_filter($kurv['pop'], 'is_array')) : [];
            $pop = array_values(array_filter($pop, static fn(array $v): bool => (int) ($v['antall'] ?? 0) !== 0));
            $linjer = [];
            // Ute av kurven er en påmelding som er gjort opp: ingenting står
            // igjen, og (Paint on Pots) gjenstandene er slått inn slik kurven
            // sier. Det er tilfellet når den ble betalt med QR som første del
            // av kurven — da står endret pris og rabatt alt på påmeldingen, og
            // skal ikke regnes én gang til (kontrolløren 8. oktober 2026).
            // Prisene på linjene dens hører til den, og tas ut.
            $avgjort = false;
            if (self::erPop($b) && $pop !== []) {
                $f = PopPris::forhandsvis($bid, $pop);
                $tidligere = (int) ($b['kasse_rabatt_ore'] ?? 0);
                $skyldig = max(0, $f['sumOre'] - $tidligere - $f['betaltOre']);
                $avgjort = $skyldig === 0 && $b['gjenstander_ore'] !== null && (int) $b['gjenstander_ore'] === (int) $f['sumOre'];
            } else {
                $skyldig = KursstartKrav::skyldig($bid, (int) $b['belop_ore'], (string) $b['status']);
                $avgjort = $skyldig === 0;
            }
            if ($avgjort) {
                foreach (array_keys($priser) as $k) {
                    if ($k === $delId || str_starts_with($k, $delId . ':')) {
                        unset($priser[$k]);
                    }
                }
            } elseif (self::erPop($b) && $pop !== []) {
                // Én linje per gjenstand (nivå og gjenstand), med nøkkelen
                // prisendringen peker på. Har enhetene ulik pris (noen slått
                // inn før med en annen nivåpris), gjelder prisendringen
                // linjesummen, ikke en avrundet stykkpris.
                $gruppe = [];
                foreach ($f['linjer'] as $l) {
                    $k = (int) $l['nivaa_id'] . '|' . (string) ($l['gjenstand'] ?? '');
                    $gruppe[$k] ??= ['navn' => (string) $l['navn'], 'gjenstand' => (string) ($l['gjenstand'] ?? ''), 'antall' => 0, 'ore' => 0, 'priser' => []];
                    $gruppe[$k]['antall'] += (int) $l['antall'];
                    $gruppe[$k]['ore'] += (int) $l['pris_ore'] * (int) $l['antall'];
                    $gruppe[$k]['priser'][(int) $l['pris_ore']] = true;
                }
                $full = 0;
                foreach ($gruppe as $k => $g) {
                    $tekst = $g['navn'] . ($g['gjenstand'] !== '' ? ' · ' . $g['gjenstand'] : '');
                    $l = self::prisLinje($delId . ':' . $k, $tekst . ' × ' . $g['antall'], $tekst, $g['antall'], $g['ore'],
                        $priser, $endringer[$delId], $prisTrekk[$delId], count($g['priser']) === 1);
                    $full += $l['ore'];
                    $linjer[] = $l;
                }
                // Rabatt gitt i kassa tidligere (betalt) står.
                if ($tidligere !== 0) {
                    $linjer[] = ['tekst' => 'Rabatt', 'ore' => -$tidligere];
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
                $betalt = (int) $f['betaltOre'];
            } else {
                $pop = [];
                $betalt = (int) Booking::betalingerFor($bid)['sum'];
                $belop = (int) $b['belop_ore'];
                $antall = (int) $b['antall'];
                $tekst = (string) $b['tittel'] . ' · ' . $antall . ' '
                    . (self::erPop($b) ? ($antall === 1 ? 'person' : 'personer') : ($antall === 1 ? 'plass' : 'plasser'));
                // Prisen på kurslinja: det linja viser (hele beløpet, eller det som står igjen).
                if ($skyldig > 0 && $betalt > 0 && $belop - $betalt === $skyldig) {
                    $l = self::prisLinje($delId, $tekst, (string) $b['tittel'], 1, $belop, $priser, $endringer[$delId], $prisTrekk[$delId], false);
                    $linjer[] = $l;
                    $linjer[] = ['tekst' => 'Betalt ved booking', 'ore' => -$betalt];
                } else {
                    $l = self::prisLinje($delId, $tekst, (string) $b['tittel'], 1, $skyldig, $priser, $endringer[$delId], $prisTrekk[$delId], false);
                    $linjer[] = $l;
                }
                $full = $l['ore'];
            }
            if (!$avgjort && ($skyldig > 0 || $pop !== [])) {
                $etter = $skyldig - $prisTrekk[$delId];
                if ($etter < 0) {
                    throw new RuntimeException('Prisen kan ikke bli lavere enn det som alt er betalt. Penger tilbake er en refusjon.');
                }
                // fullOre: hele prisen for delen (etter endret pris, før
                // depositum og det som alt er betalt). Prosentrabatt regnes av den.
                $deler[] = ['id' => 'booking:' . $bid, 'type' => 'booking', 'bookingId' => $bid, 'pop' => $pop, 'sumOre' => $etter,
                            'medlemId' => $b['member_id'] !== null ? (int) $b['member_id'] : null,
                            'tittel' => (string) $b['navn'], 'linjer' => $linjer, 'fraOre' => $skyldig, 'fullOre' => max(0, $full), 'betaltOre' => $betalt,
                            'endret' => $endringer[$delId]];
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
            $ordre[] = ['produktId' => (int) $p['id'], 'tittel' => (string) $p['tittel'], 'antall' => $n, 'prisOre' => (int) $p['pris_ore'],
                        'nokkel' => 'vare:' . (int) $p['id']];
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
            $deler[] = ['id' => 'ordre'] + self::ordreDel('ordre', $ordre, 'Salg', $priser);
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
                             'prisOre' => (int) $nivaer[$id]['prisOre'], 'nokkel' => 'pop:' . $id];
            }
            if ($linjer === []) {
                throw new RuntimeException('Velg minst én gjenstand.');
            }
            $deler[] = ['id' => 'pop'] + self::ordreDel('booking', $linjer, 'Paint on Pots · ' . $gjester . ' ' . ($gjester === 1 ? 'person' : 'personer'), $priser)
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
        foreach (array_values($gave) as $gi => $raa) {
            $o = self::ore($raa);
            // Samme grenser som nettsida og kassa i admin.
            if ($o === null || $o < 10000 || $o > 2000000) {
                throw new RuntimeException('Velg et beløp mellom 100 og 20 000 kroner.');
            }
            $deler[] = ['id' => 'gavekort:' . $gi, 'type' => 'gavekort', 'sumOre' => $o, 'tittel' => 'Gavekort',
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
            $deler[] = ['id' => 'timepakke', 'type' => 'timepakke', 'medlemId' => $mid, 'sumOre' => $pris, 'tittel' => 'Timepakke',
                        'linjer' => [['tekst' => 'Timepakke · ' . Timepakke::timer() . ' timer', 'ore' => $pris]]];
        }

        if ($priser !== []) {
            // En pris på en linje som ikke er i kurven (lenger).
            throw new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
        }
        // Beløpet før endret pris, og hele prisen (påmeldingen har dem alt).
        foreach ($deler as &$d) {
            $d['fraOre'] ??= $d['sumOre'] + array_sum(array_map(static fn(array $l): int => ($l['fraOre'] ?? $l['ore']) - $l['ore'], $d['linjer']));
            $d['fullOre'] ??= $d['sumOre'];
        }
        unset($d);
        // Låste rabattandeler gjelder bare den samme kurven (signaturen): en
        // gammel nøkkel kan ikke brukes til å omgå reglene (kontrolløren).
        $sig = self::kurvSig($kurv);
        $deler = self::medRabatt($deler, $kurv['rabatt'] ?? null, KasseJustering::laaste($nokler, $sig));
        foreach ($deler as &$d) {
            $d['kurvSig'] = $sig;
        }
        unset($d);
        if (!KasseJustering::klar()) {
            foreach ($deler as $d) {
                if (isset($d['justering'])) {
                    throw new RuntimeException(KasseJustering::MANGLER, 503);
                }
            }
        }

        $sum = array_sum(array_column($deler, 'sumOre'));
        if ($sum > self::MAKS_ORE) {
            throw new RuntimeException('Beløpet må være under 100 000 kroner.');
        }
        return $deler;
    }

    /**
     * Signaturen til kurven: det som er valgt, endret pris og rabatt, i fast
     * form. Samme kurv gir samme signatur, også etter at en del er betalt.
     */
    public static function kurvSig(array $kurv): string
    {
        $liste = static fn(mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
        $pop = array_map(static fn(array $v): array => [(int) ($v['nivaaId'] ?? 0), trim((string) ($v['gjenstand'] ?? '')), (int) ($v['antall'] ?? 0)], $liste($kurv['pop'] ?? null));
        $varer = array_map(static fn(array $v): array => [(int) ($v['id'] ?? 0), (int) ($v['antall'] ?? 0)], $liste($kurv['varer'] ?? null));
        $popUten = array_map(static fn(array $v): array => [(int) ($v['nivaaId'] ?? 0), (int) ($v['antall'] ?? 0)], $liste($kurv['popUten'] ?? null));
        sort($pop);
        sort($varer);
        sort($popUten);
        $belop = static fn(mixed $v): array => is_array($v) ? array_map(static fn($x): ?int => self::ore($x), array_values($v)) : [];
        $priser = self::lesPriser($kurv['priser'] ?? null);
        ksort($priser);
        return hash('sha256', (string) json_encode([
            'b' => (int) ($kurv['bookingId'] ?? 0), 'pop' => $pop, 'v' => $varer, 'pu' => $popUten, 'pg' => (int) ($kurv['popGjester'] ?? 0),
            'f' => $belop($kurv['fritt'] ?? null), 'g' => $belop($kurv['gavekort'] ?? null), 't' => !empty($kurv['timepakke']),
            'p' => $priser, 'r' => self::lesRabatt($kurv['rabatt'] ?? null),
        ]));
    }

    /**
     * Varene i en ordre, med endret pris der kassa har satt en (stykkpris).
     *
     * @param list<array<string,mixed>> $linjer
     * @param array<string,int> $priser linjens nøkkel => ny stykkpris; det som brukes, tas ut
     */
    private static function ordreDel(string $formal, array $linjer, string $tittel, array &$priser): array
    {
        $sum = 0;
        $vis = [];
        $endret = [];
        foreach ($linjer as &$l) {
            $n = $l['nokkel'] ?? null;
            $linje = ['tekst' => $l['tittel'] . ($l['antall'] > 1 ? ' × ' . $l['antall'] : ''), 'ore' => $l['prisOre'] * $l['antall']];
            if ($n !== null) {
                $linje += ['nokkel' => $n, 'antall' => $l['antall'], 'enhetOre' => $l['prisOre'], 'perStk' => true];
                if (array_key_exists($n, $priser)) {
                    $ny = $priser[$n];
                    unset($priser[$n]);
                    if ($ny !== $l['prisOre']) {
                        $endret[] = ['tekst' => $l['tittel'], 'antall' => $l['antall'], 'fraOre' => $l['prisOre'], 'tilOre' => $ny];
                        $linje['fraOre'] = $linje['ore'];
                        $linje['fraEnhetOre'] = $l['prisOre'];
                        $linje['ore'] = $ny * $l['antall'];
                        $linje['enhetOre'] = $ny;
                        $l['prisOre'] = $ny;
                    }
                }
            }
            unset($l['nokkel']);
            $sum += $l['prisOre'] * $l['antall'];
            $vis[] = $linje;
        }
        unset($l);
        return ['type' => 'ordre', 'formal' => $formal, 'linjer' => $vis, 'varer' => $linjer, 'sumOre' => $sum, 'tittel' => $tittel,
                'endret' => $endret];
    }

    /**
     * En linje i påmeldingen som kan få endret pris. $perStk: prisen som
     * tastes er stykkprisen (gjenstander med samme pris); ellers er den
     * linjas beløp (kurset, eller gjenstander der enhetene har ulik pris —
     * da brukes aldri en avrundet stykkpris).
     *
     * @param array<string,int> $priser det som brukes, tas ut
     * @param list<array<string,mixed>> $endringer
     * @return array<string,mixed>
     */
    private static function prisLinje(string $nokkel, string $tekst, string $navn, int $antall, int $ore, array &$priser,
                                      array &$endringer, int &$trekk, bool $perStk): array
    {
        $perStk = $perStk && $antall > 0 && $ore % $antall === 0;
        $enhet = $perStk ? intdiv($ore, $antall) : $ore;
        $l = ['tekst' => $tekst, 'ore' => $ore, 'nokkel' => $nokkel, 'antall' => $antall, 'enhetOre' => $enhet, 'perStk' => $perStk];
        if (!array_key_exists($nokkel, $priser)) {
            return $l;
        }
        $nyPris = $priser[$nokkel];
        unset($priser[$nokkel]);
        $ny = $perStk ? $nyPris * $antall : $nyPris;
        if ($ny === $ore) {
            return $l;
        }
        $endringer[] = ['tekst' => $navn, 'antall' => $antall, 'fraOre' => $enhet, 'tilOre' => $nyPris];
        $trekk += $ore - $ny;
        return ['fraOre' => $ore, 'fraEnhetOre' => $enhet, 'ore' => $ny, 'enhetOre' => $nyPris] + $l;
    }

    /**
     * Ny pris per linje fra kurven: linjens nøkkel => kroner. Pris kan ikke
     * være under 0.
     *
     * @return array<string,int> øre
     */
    private static function lesPriser(mixed $raa): array
    {
        if (!is_array($raa) || $raa === []) {
            return [];
        }
        if (count($raa) > self::MAKS_LINJER) {
            throw new RuntimeException('For mange linjer i ett salg.');
        }
        $ut = [];
        foreach ($raa as $k => $v) {
            $k = (string) $k;
            if (!preg_match('/^(vare:\d+|pop:\d+|booking:\d+(:\d+\|.{0,100})?)$/su', $k)) {
                throw new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
            }
            $o = self::ore($v);
            if ($o === null || $o < 0) {
                throw new RuntimeException('Prisen må være 0 kroner eller mer.');
            }
            if ($o > self::MAKS_ORE) {
                throw new RuntimeException('Beløpet må være under 100 000 kroner.');
            }
            $ut[$k] = $o;
        }
        return $ut;
    }

    /**
     * Rabatt på hele kjøpet, i prosent eller kroner (gavekort og timepakke får
     * ikke rabatt). Hver del får sin andel som en egen linje («Rabatt 10 %»)
     * og en «justering» som lagres når delen betales (KasseJustering).
     *
     *   prosent  av hele prisen for delen (fullOre: før depositum og det som
     *            alt er betalt), men aldri mer enn det som står igjen å betale
     *   kroner   fordelt etter hele prisen, aldri mer enn det som står igjen
     *            per del; kan ikke være større enn det som står igjen i alt
     *   låst     er det tatt betalt for kurven før (en del betalt med QR),
     *            beholder hver del andelen den fikk da ($laaste, del-id => øre)
     *
     * @param list<array<string,mixed>> $deler
     * @param array<string,int> $laaste
     * @return list<array<string,mixed>>
     */
    private static function medRabatt(array $deler, mixed $raa, array $laaste = []): array
    {
        $r = self::lesRabatt($raa);
        $kan = static fn(array $d): bool => in_array($d['type'], ['booking', 'ordre'], true);
        $andel = [];
        if ($r !== null && $laaste === []) {
            // Eierens regler (8. oktober 2026), sjekket her, ikke bare på skjermen:
            //   B  endret pris og rabatt kan ikke brukes på samme kjøp
            //   A  prosent bare når hele beløpet betales nå (ingenting betalt:
            //      ikke depositum, delbetaling eller en QR som er betalt)
            foreach ($deler as $d) {
                if ($kan($d) && ($d['endret'] ?? []) !== []) {
                    throw new RuntimeException(self::PRIS_OG_RABATT);
                }
            }
            if ($r['prosent'] !== null) {
                foreach ($deler as $d) {
                    if ($kan($d) && (int) ($d['betaltOre'] ?? 0) > 0) {
                        throw new RuntimeException(self::PROSENT_BETALT);
                    }
                }
            }
        }
        if ($r !== null) {
            $tak = [];
            $full = [];
            foreach ($deler as $i => $d) {
                if ($kan($d)) {
                    $tak[$i] = max(0, (int) $d['sumOre']);
                    $full[$i] = max(0, (int) $d['fullOre']);
                }
            }
            $sumTak = array_sum($tak);
            if ($laaste !== []) {
                foreach ($tak as $i => $t) {
                    $a = $laaste[(string) $deler[$i]['id']] ?? 0;
                    if ($a < 0 || $a > $t) {
                        throw new RuntimeException('Kurven er endret. Se over den og prøv igjen.', 409);
                    }
                    $andel[$i] = $a;
                }
            } elseif ($sumTak <= 0) {
                throw new RuntimeException('Det er ingenting å gi rabatt på.');
            } elseif ($r['prosent'] !== null) {
                foreach ($tak as $i => $t) {
                    $andel[$i] = min((int) round($full[$i] * $r['prosent'] / 100), $t);
                }
            } else {
                $total = (int) $r['ore'];
                if ($total > $sumTak) {
                    throw new RuntimeException('Rabatten kan ikke være større enn ' . self::kr($sumTak) . '.');
                }
                $vekt = array_sum($full) > 0 ? $full : $tak;
                $sumVekt = array_sum($vekt);
                $fordelt = 0;
                foreach ($tak as $i => $t) {
                    $andel[$i] = min(intdiv($total * $vekt[$i], $sumVekt), $t);
                    $fordelt += $andel[$i];
                }
                // Øret som blir igjen (og det en del ikke hadde plass til) går
                // til de første delene som har plass.
                foreach ($tak as $i => $t) {
                    $mer = min($total - $fordelt, $t - $andel[$i]);
                    if ($mer > 0) {
                        $andel[$i] += $mer;
                        $fordelt += $mer;
                    }
                }
            }
        }
        foreach ($deler as $i => &$d) {
            if (!$kan($d)) {
                continue;
            }
            $a = $andel[$i] ?? 0;
            $endret = $d['endret'] ?? [];
            if ($a > 0) {
                $d['linjer'][] = ['tekst' => 'Rabatt ' . $r['tekst'], 'ore' => -$a, 'rabatt' => true];
                $d['sumOre'] -= $a;
            }
            $prisTrekk = (int) $d['fraOre'] - (int) $d['sumOre'] - $a;
            if ($a > 0 || $endret !== [] || $prisTrekk !== 0) {
                $d['justering'] = [
                    'fra'         => (int) $d['fraOre'],
                    'pris'        => $prisTrekk,
                    'rabatt'      => $a,
                    'rabattTekst' => $a > 0 ? $r['tekst'] : '',
                    'hvorfor'     => $a > 0 ? $r['hvorfor'] : '',
                    'linjer'      => $endret,
                ];
            }
            unset($d['endret']);
        }
        unset($d);
        return $deler;
    }

    /** @return array{prosent:?float, ore:?int, tekst:string, hvorfor:string}|null */
    private static function lesRabatt(mixed $raa): ?array
    {
        if (!is_array($raa) || $raa === []) {
            return null;
        }
        $hvorfor = mb_substr(trim((string) ($raa['hvorfor'] ?? '')), 0, 191);
        if (isset($raa['prosent']) && trim((string) $raa['prosent']) !== '') {
            $p = str_replace([',', ' ', '%'], ['.', '', ''], (string) $raa['prosent']);
            if (!is_numeric($p) || (float) $p <= 0 || (float) $p > 100) {
                throw new RuntimeException('Rabatten må være mellom 0 og 100 %.');
            }
            $p = round((float) $p, 2);
            $tekst = rtrim(rtrim(number_format($p, 2, ',', ''), '0'), ',') . ' %';
            return ['prosent' => $p, 'ore' => null, 'tekst' => $tekst, 'hvorfor' => $hvorfor];
        }
        if (isset($raa['kr']) && trim((string) $raa['kr']) !== '') {
            $o = self::ore($raa['kr']);
            if ($o === null || $o <= 0) {
                throw new RuntimeException('Skriv inn en rabatt over null.');
            }
            return ['prosent' => null, 'ore' => $o, 'tekst' => self::kr($o), 'hvorfor' => $hvorfor];
        }
        return null;
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
        $rabatt = null;
        foreach ($deler as $d) {
            $j = $d['justering'] ?? null;
            if ($j !== null && $j['rabatt'] > 0) {
                $rabatt = ['tekst' => 'Rabatt ' . $j['rabattTekst'], 'ore' => ($rabatt['ore'] ?? 0) + $j['rabatt'], 'hvorfor' => $j['hvorfor']];
            }
        }
        return [
            'deler' => array_map(static fn(array $d): array => [
                'id'     => $d['id'],
                'type'   => $d['type'],
                'tittel' => $d['tittel'],
                'sumOre' => $d['sumOre'],
                'sum'    => self::kr($d['sumOre']),
                'fraOre' => (int) ($d['fraOre'] ?? $d['sumOre']),
                'linjer' => array_map(static fn(array $l): array => $l + ['kr' => ($l['ore'] < 0 ? '−' : '') . self::kr(abs($l['ore']))], $d['linjer']),
            ], $deler),
            'sumOre' => $sum,
            'sum'    => self::kr($sum),
            'rabatt' => $rabatt === null ? null : $rabatt + ['kr' => '−' . self::kr($rabatt['ore'])],
            // Eierens regler: prosent bare når ingenting er betalt; endret
            // pris og rabatt ikke på samme kjøp (skjermen gjør knappene grå).
            'prosentKan' => array_filter($deler, static fn(array $d): bool => (int) ($d['betaltOre'] ?? 0) > 0) === [],
            'prisEndret' => array_filter($deler, static fn(array $d): bool => ($d['justering']['linjer'] ?? []) !== []) !== [],
        ];
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
        $endringer = KasseJustering::idag();
        return [
            // Endret pris og rabatt i kassa i dag: hvem, hva og hvorfor.
            'endringer'  => $endringer['rader'],
            'trukketOre' => $endringer['trukketOre'],
            'trukket'    => self::kr($endringer['trukketOre']),
            'rader'      => array_map(static fn(array $r): array => $r + ['kr' => self::kr($r['ore'])], $rader),
            'totalOre'   => $total,
            'total'      => self::kr($total),
            'kontantOre' => $sum['Kontant'],
            'kontant'    => self::kr($sum['Kontant']),
        ];
    }
}
