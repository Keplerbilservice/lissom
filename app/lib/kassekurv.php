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

    /** Bookingen, når den hører til en økt i dag og er aktiv. */
    public static function dagensBooking(int $bookingId): ?array
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
     * gavekortene, timepakken. Hver del har en fast id («booking:12», «ordre»,
     * «pop», «gavekort:0», «timepakke»), så en del som er betalt ikke flytter
     * på de andre.
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
                $deler[] = ['id' => 'booking:' . $bid, 'type' => 'booking', 'bookingId' => $bid, 'pop' => $pop, 'sumOre' => $skyldig,
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
            $deler[] = ['id' => 'ordre'] + self::ordreDel('ordre', $ordre, 'Salg');
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
            $deler[] = ['id' => 'pop'] + self::ordreDel('booking', $linjer, 'Paint on Pots · ' . $gjester . ' ' . ($gjester === 1 ? 'person' : 'personer'))
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
                'id'     => $d['id'],
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
