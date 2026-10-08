<?php
/**
 * Paint on Pots: prisnivåene, beløpet ved booking og oppgjøret i kassa.
 *
 * Eieren, «ok, bygg det» 8. oktober 2026, til to fremvisninger
 * (GFD76vZ7iEiFgDiaPFf6hu og PU3RwTARY6oVYx6dbwaLwo):
 *
 *   - Fire prisnivåer (Liten, Mellom, Stor, Ekstra stor) med gjenstandene,
 *     redigerbare i admin. Siden viser dem i stedet for «fra 450 kr».
 *   - 100 kr per person betales med Vipps ved booking. Beløpet står på
 *     Paint on Pots-kurset (courses.pris_ore, courses.depositum = 1) og
 *     settes i admin. Det trekkes fra i verkstedet.
 *   - Avbestilling senest courses.avbestilling_timer før = refusjon via
 *     Vipps (api/avbestill.php). Senere, eller ikke møtt: beholdes.
 *   - I kassa slås gjenstandene inn. Bookingens beløp blir summen av dem, og
 *     det som alt er betalt trekkes fra av seg selv: «skyldig» er beløp minus
 *     betalt, samme regnestykke som «Ta betalt» bruker for alle påmeldinger.
 *     Selve pengene registreres der, med de vanlige knappene.
 *
 * Beløp i øre som heltall. Prisen hentes alltid fra basen, aldri fra det
 * nettleseren sender.
 *
 * Tåler at migrasjon 260 ikke er kjørt: da finnes ingen nivåer, ingen
 * kurs er depositum-kurs, og alt er som før.
 */

declare(strict_types=1);

final class PopPris
{
    /** Flest gjenstander av ett nivå på én booking. */
    public const MAKS_PER_NIVAA = 50;

    public static function klar(): bool
    {
        return DB::harTabell('pop_prisnivaer');
    }

    /**
     * Nivåene i rekkefølge.
     *
     * @return list<array{id:int,navn:string,prisOre:int,pris:string,gjenstander:string,aktiv:bool}>
     */
    public static function nivaer(bool $ogsaaAv = false): array
    {
        if (!self::klar()) {
            return [];
        }
        $ut = [];
        foreach (DB::alle(
            'SELECT id, navn, pris_ore, gjenstander, aktiv FROM pop_prisnivaer'
            . ($ogsaaAv ? '' : ' WHERE aktiv = 1')
            . ' ORDER BY rekkefolge, id'
        ) as $r) {
            $ut[] = [
                'id'          => (int) $r['id'],
                'navn'        => (string) $r['navn'],
                'prisOre'     => (int) $r['pris_ore'],
                'pris'        => self::kr((int) $r['pris_ore']),
                'gjenstander' => trim((string) $r['gjenstander']),
                'aktiv'       => (int) $r['aktiv'] === 1,
            ];
        }
        return $ut;
    }

    /**
     * Lagrer hele lista fra admin. Rader med id oppdateres, rader uten id
     * legges til, og rader som ikke er med slettes. Linjer som alt er slått
     * inn i kassa har sin egen kopi av navn og pris, så de røres ikke.
     *
     * @param list<array{id?:int|string,navn?:string,pris?:int|float|string,gjenstander?:string}> $rader
     */
    public static function lagreNivaer(array $rader): void
    {
        if (!self::klar()) {
            throw new RuntimeException('Kjør oppdateringene først (⚙ Kjør oppdateringer).');
        }
        $rene = [];
        foreach ($rader as $i => $r) {
            $navn = trim(mb_substr((string) ($r['navn'] ?? ''), 0, 60));
            $prisRaa = trim(str_replace([' ', "\u{a0}", 'kr', ',-'], '', (string) ($r['pris'] ?? '')));
            $prisRaa = str_replace(',', '.', $prisRaa);
            if ($navn === '' && $prisRaa === '') {
                continue;
            }
            if ($navn === '') {
                throw new RuntimeException('Hvert nivå må ha et navn.');
            }
            if (!is_numeric($prisRaa) || (float) $prisRaa <= 0 || (float) $prisRaa > 100000) {
                throw new RuntimeException('Prisen på «' . $navn . '» må være mellom 1 og 100 000 kroner.');
            }
            $rene[] = [
                'id'          => (int) ($r['id'] ?? 0),
                'navn'        => $navn,
                'pris_ore'    => (int) round((float) $prisRaa * 100),
                'gjenstander' => trim(mb_substr((string) ($r['gjenstander'] ?? ''), 0, 500)),
                'rekkefolge'  => $i + 1,
            ];
        }
        if ($rene === []) {
            throw new RuntimeException('Legg inn minst ett prisnivå.');
        }
        DB::iTransaksjon(static function () use ($rene): void {
            $beholdes = [];
            foreach ($rene as $r) {
                $id = $r['id'];
                unset($r['id']);
                if ($id > 0 && DB::verdi('SELECT id FROM pop_prisnivaer WHERE id = :i', ['i' => $id]) !== null) {
                    DB::oppdater('pop_prisnivaer', $r + ['aktiv' => 1], ['id' => $id]);
                } else {
                    $id = DB::settInn('pop_prisnivaer', $r + ['aktiv' => 1]);
                }
                $beholdes[] = (int) $id;
            }
            DB::kjor('DELETE FROM pop_prisnivaer WHERE id NOT IN (' . implode(',', $beholdes) . ')');
            // Koblingene til varer for nivåer som er tatt bort (migrasjon 262).
            if (DB::harTabell('pop_gjenstand_vare')) {
                DB::kjor('DELETE FROM pop_gjenstand_vare WHERE nivaa_id NOT IN (' . implode(',', $beholdes) . ')');
            }
        });
    }

    /** Er kursprisen et beløp ved booking som trekkes fra i verkstedet? */
    public static function erDepositum(int $kursId): bool
    {
        if ($kursId <= 0 || !DB::harKolonne('courses', 'depositum')) {
            return false;
        }
        return (int) DB::verdi('SELECT depositum FROM courses WHERE id = :i', ['i' => $kursId]) === 1;
    }

    /**
     * Beløpet per person ved booking når kurset har det (kursets pris, ikke en
     * egen pris på økta). null = vanlig kurs.
     */
    public static function depositumPerPerson(int $kursId): ?int
    {
        if (!self::erDepositum($kursId)) {
            return null;
        }
        return (int) DB::verdi('SELECT pris_ore FROM courses WHERE id = :i', ['i' => $kursId]);
    }

    /**
     * Feltene en ny booking på et kurs med beløp ved booking skal ha, uansett
     * hvor den lages (nettsida, admin, ventelista): beløpet ved booking og
     * fristen som gjelder for den. Da håndteres den likt i kassa og ved
     * avbestilling. Tom for vanlige kurs, og før migrasjon 260.
     *
     * @return array<string,int|null>
     */
    public static function bookingFelt(int $kursId, int $depositumOre): array
    {
        if (!self::erDepositum($kursId) || !DB::harKolonne('bookings', 'avbestilling_timer')) {
            return [];
        }
        return [
            'depositum_ore'      => max(0, $depositumOre),
            'avbestilling_timer' => self::avbestillingTimer($kursId),
        ];
    }

    /** Fristen for avbestilling med refusjon, i timer. null = vilkårenes 2 dager. */
    public static function avbestillingTimer(int $kursId): ?int
    {
        if ($kursId <= 0 || !DB::harKolonne('courses', 'avbestilling_timer')) {
            return null;
        }
        $t = DB::verdi('SELECT avbestilling_timer FROM courses WHERE id = :i', ['i' => $kursId]);
        return $t === null ? null : max(0, (int) $t);
    }

    /**
     * Fristen for én booking: den som gjaldt da den ble gjort (lovet i
     * bekreftelsen). Bare bookinger med beloep ved booking — eldre bookinger
     * og andre kurs faar null, og vilkaarenes 2 dager gjelder som foer.
     *
     * @param array<string,mixed> $b bookingraden (depositum_ore, avbestilling_timer, course_id)
     */
    public static function fristFor(array $b): ?int
    {
        if (($b['depositum_ore'] ?? null) === null) {
            return null;
        }
        if (($b['avbestilling_timer'] ?? null) !== null) {
            return max(0, (int) $b['avbestilling_timer']);
        }
        return self::avbestillingTimer((int) ($b['course_id'] ?? 0));
    }

    /** «500 kr», «1 000 kr» — slik fremvisningen skrev prisene. */
    public static function kr(int $ore): string
    {
        return number_format($ore / 100, 0, ',', "\u{a0}") . "\u{a0}kr";
    }

    /**
     * Prislista som én setning, til llms.txt og AI-svarene:
     * «Liten 500 kr (standard kopp, …), Mellom 700 kr (…) …».
     */
    public static function prisliste(): string
    {
        $deler = [];
        foreach (self::nivaer() as $n) {
            $deler[] = $n['navn'] . ' ' . str_replace("\u{a0}", ' ', $n['pris'])
                . ($n['gjenstander'] !== '' ? ' (' . $n['gjenstander'] . ')' : '');
        }
        return implode(', ', $deler);
    }

    // ── Bekreftelsen og avbestillingslenka ───────────────────────────────

    /**
     * «{betaling}» i bekreftelsen for en booking med beløp ved booking
     * (fremvisningen, steg 2 og 3). Tom for alle andre.
     *
     * @param array<string,mixed> $b bookingraden (depositum_ore, course_id)
     */
    public static function bekreftelse(array $b): string
    {
        $dep = (int) ($b['depositum_ore'] ?? 0);
        if ($dep <= 0) {
            return '';
        }
        // Det som faktisk er betalt (kontrollen 08.10), ikke det som skulle
        // betales: en plass lagt inn i admin uten betaling har ingenting å
        // trekke fra og ingenting å refundere.
        $betalt = (int) Booking::betalingerFor((int) $b['id'])['sum'];
        $lenke = self::avbestillLenke((int) $b['id']);
        if ($betalt <= 0) {
            return 'Prisen på gjenstandene betaler du i verkstedet.'
                . ($lenke !== null ? ' Avbestill her: ' . $lenke : '');
        }
        $kr = str_replace("\u{a0}", ' ', self::kr($betalt));
        $kroner = number_format($betalt / 100, 0, ',', ' ');
        $timer = self::fristFor($b) ?? 48;
        return 'Betalt: ' . $kr . '. Prisen på gjenstandene betaler du i verkstedet. De ' . $kroner
            . ' kronene trekkes fra. Avbestiller du senest ' . $timer . ' timer før, får du pengene tilbake. '
            . 'Møter du ikke, beholdes beløpet.'
            . ($lenke !== null ? ' Avbestill her: ' . $lenke : '');
    }

    /** Lenka i bekreftelsen. Koden lages første gang. */
    public static function avbestillLenke(int $bookingId): ?string
    {
        if (!DB::harKolonne('bookings', 'avbestill_kode')) {
            return null;
        }
        $kode = (string) (DB::verdi('SELECT avbestill_kode FROM bookings WHERE id = :i', ['i' => $bookingId]) ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $kode) !== 1) {
            $kode = bin2hex(random_bytes(16));
            DB::oppdater('bookings', ['avbestill_kode' => $kode], ['id' => $bookingId]);
        }
        return rtrim(Config::nettsted(), '/') . '/api/pop-avbestill.php?b=' . $bookingId . '&k=' . $kode;
    }

    /** Stemmer koden for bookingen? Konstant tid. */
    public static function kodeStemmer(int $bookingId, string $kode): bool
    {
        if ($bookingId <= 0 || preg_match('/^[a-f0-9]{32}$/', $kode) !== 1
            || !DB::harKolonne('bookings', 'avbestill_kode')) {
            return false;
        }
        $lagret = (string) (DB::verdi('SELECT avbestill_kode FROM bookings WHERE id = :i', ['i' => $bookingId]) ?? '');
        return $lagret !== '' && hash_equals($lagret, $kode);
    }

    // ── Gjenstander og lager (migrasjon 262) ─────────────────────────────

    /**
     * Gjenstandene i et nivå, slik admin skrev dem (skilt med komma).
     *
     * @return list<string>
     */
    public static function gjenstandsliste(string $tekst): array
    {
        $ut = [];
        foreach (explode(',', $tekst) as $g) {
            $g = trim(mb_substr(trim($g), 0, 100));
            if ($g !== '' && !in_array($g, $ut, true)) {
                $ut[] = $g;
            }
        }
        return $ut;
    }

    /** Kan gjenstander kobles til varer og trekke lager (migrasjon 262)? */
    public static function lagerKlar(): bool
    {
        return DB::harTabell('pop_gjenstand_vare') && DB::harKolonne('pop_kasselinjer', 'trukket');
    }

    /**
     * Koblingene: «nivå-id|gjenstand» => vare-id.
     *
     * @return array<string,int>
     */
    public static function koblinger(): array
    {
        if (!self::lagerKlar()) {
            return [];
        }
        $ut = [];
        foreach (DB::alle('SELECT nivaa_id, gjenstand, produkt_id FROM pop_gjenstand_vare') as $r) {
            $ut[(int) $r['nivaa_id'] . '|' . (string) $r['gjenstand']] = (int) $r['produkt_id'];
        }
        return $ut;
    }

    /**
     * Varene en gjenstand kan kobles til.
     *
     * @return list<array{id:int,tittel:string,lager:?int}>
     */
    public static function varer(): array
    {
        if (!self::lagerKlar()) {
            return [];
        }
        return array_map(static fn(array $v): array => [
            'id'     => (int) $v['id'],
            'tittel' => (string) $v['tittel'],
            'lager'  => $v['lager'] === null ? null : (int) $v['lager'],
        ], DB::alle("SELECT id, tittel, lager FROM products WHERE status <> 'kladd' ORDER BY tittel, id"));
    }

    /**
     * Lagrer koblingene fra admin. Lista fra admin er hele sannheten: en
     * kobling som ikke er med (eller står på 0) tas bort. Gjenstanden må stå i
     * nivået, og varen må finnes. Linjer som alt er slått inn beholder varen
     * de ble trukket fra.
     *
     * @param list<array{nivaaId?:int|string,gjenstand?:string,produktId?:int|string}> $rader
     */
    public static function lagreKoblinger(array $rader): void
    {
        if (!self::lagerKlar()) {
            throw new RuntimeException('Kjør oppdateringene først (⚙ Kjør oppdateringer).');
        }
        $nivaer = array_column(self::nivaer(), null, 'id');
        $rene = [];
        foreach ($rader as $r) {
            $id = (int) ($r['nivaaId'] ?? 0);
            $g = trim(mb_substr((string) ($r['gjenstand'] ?? ''), 0, 100));
            $vare = (int) ($r['produktId'] ?? 0);
            if ($vare <= 0) {
                continue;
            }
            if (!isset($nivaer[$id]) || !in_array($g, self::gjenstandsliste($nivaer[$id]['gjenstander']), true)) {
                throw new RuntimeException('Fant ikke gjenstanden. Last siden på nytt.');
            }
            if (DB::verdi('SELECT id FROM products WHERE id = :i', ['i' => $vare]) === null) {
                throw new RuntimeException('Fant ikke varen. Last siden på nytt.');
            }
            $rene[$id . '|' . $g] = ['nivaa_id' => $id, 'gjenstand' => $g, 'produkt_id' => $vare];
        }
        DB::iTransaksjon(static function () use ($rene): void {
            foreach (DB::alle('SELECT nivaa_id, gjenstand FROM pop_gjenstand_vare FOR UPDATE') as $r) {
                if (!isset($rene[(int) $r['nivaa_id'] . '|' . (string) $r['gjenstand']])) {
                    DB::kjor('DELETE FROM pop_gjenstand_vare WHERE nivaa_id = :n AND gjenstand = :g',
                        ['n' => (int) $r['nivaa_id'], 'g' => (string) $r['gjenstand']]);
                }
            }
            foreach ($rene as $r) {
                DB::kjor(
                    'INSERT INTO pop_gjenstand_vare (nivaa_id, gjenstand, produkt_id) VALUES (:n, :g, :p)
                     ON DUPLICATE KEY UPDATE produkt_id = VALUES(produkt_id)',
                    ['n' => $r['nivaa_id'], 'g' => $r['gjenstand'], 'p' => $r['produkt_id']]
                );
            }
        });
    }

    /**
     * Legger tilbake det som er trukket fra lageret for en booking (plassen
     * avbestilt i admin etter at gjenstandene er slått inn). Bare det som
     * faktisk ble trukket, og bare én gang: «trukket» settes til 0.
     */
    public static function leggTilbakeLager(int $bookingId): void
    {
        if (!self::lagerKlar()) {
            return;
        }
        // Kalles ogsaa inne i en refusjon (Booking::plasserEtterFullRefusjon),
        // som alt har en transaksjon aapen.
        $arbeid = static function () use ($bookingId): void {
            foreach (DB::alle(
                'SELECT id, produkt_id, trukket FROM pop_kasselinjer
                  WHERE booking_id = :b AND produkt_id IS NOT NULL AND trukket > 0 FOR UPDATE',
                ['b' => $bookingId]
            ) as $l) {
                DB::kjor('UPDATE products SET lager = lager + :a WHERE id = :p AND lager IS NOT NULL',
                    ['a' => (int) $l['trukket'], 'p' => (int) $l['produkt_id']]);
                DB::oppdater('pop_kasselinjer', ['trukket' => 0], ['id' => (int) $l['id']]);
            }
        };
        DB::kobling()->inTransaction() ? $arbeid() : DB::iTransaksjon($arbeid);
    }

    /**
     * Lageret etter et nytt valg i kassa: per vare sammenlignes det som alt er
     * trukket med det de nye linjene trenger. Mer = trekkes (aldri under null,
     * og «Bestill mer» via Lager::etterSalg), mindre = legges tilbake. Det som
     * faktisk er trukket fordeles på de nye linjene («trukket»).
     *
     * @param list<array{produkt_id:?int,trukket:int}> $gamle
     * @param list<array<string,mixed>> $nye linjene som skal lagres (endres)
     */
    private static function justerLager(array $gamle, array &$nye): void
    {
        $har = [];
        foreach ($gamle as $l) {
            if ($l['produkt_id'] !== null) {
                $har[(int) $l['produkt_id']] = ($har[(int) $l['produkt_id']] ?? 0) + (int) $l['trukket'];
            }
        }
        $vil = [];
        foreach ($nye as $l) {
            if (($l['produkt_id'] ?? null) !== null) {
                $vil[(int) $l['produkt_id']] = ($vil[(int) $l['produkt_id']] ?? 0) + (int) $l['antall'];
            }
        }
        $totalt = [];
        foreach (array_unique(array_merge(array_keys($har), array_keys($vil))) as $p) {
            $gammel = $har[$p] ?? 0;
            $onsket = $vil[$p] ?? 0;
            $rad = DB::en('SELECT lager FROM products WHERE id = :p FOR UPDATE', ['p' => $p]);
            if ($rad === null || $rad['lager'] === null) {
                $totalt[$p] = 0;
                continue;
            }
            if ($onsket > $gammel) {
                $ta = min($onsket - $gammel, max(0, (int) $rad['lager']));
                if ($ta > 0) {
                    DB::kjor('UPDATE products SET lager = lager - :a WHERE id = :p AND lager IS NOT NULL',
                        ['a' => $ta, 'p' => $p]);
                    Lager::etterSalg($p, $ta);
                }
                $totalt[$p] = $gammel + $ta;
            } elseif ($onsket < $gammel) {
                DB::kjor('UPDATE products SET lager = lager + :a WHERE id = :p AND lager IS NOT NULL',
                    ['a' => $gammel - $onsket, 'p' => $p]);
                $totalt[$p] = $onsket;
            } else {
                $totalt[$p] = $gammel;
            }
        }
        foreach ($nye as &$l) {
            $p = $l['produkt_id'] ?? null;
            if ($p === null) {
                $l['trukket'] = 0;
                continue;
            }
            $l['trukket'] = min((int) $l['antall'], $totalt[(int) $p] ?? 0);
            $totalt[(int) $p] = ($totalt[(int) $p] ?? 0) - $l['trukket'];
        }
        unset($l);
    }

    // ── Kassa ────────────────────────────────────────────────────────────

    /** «nivå-id|gjenstand». Et tall alene er hele nivået (uten gjenstand). */
    private static function nokkel(int|string $k): string
    {
        $k = (string) $k;
        return str_contains($k, '|') ? $k : $k . '|';
    }

    /**
     * Det kassa trenger for én booking: hvem, når, én rad per gjenstand i
     * hvert nivå, det som alt er slått inn, og hva som er betalt.
     *
     * @return array<string,mixed>
     */
    public static function kassaData(int $bookingId): array
    {
        $b = self::kassaBooking($bookingId, false);
        $bet = Booking::betalingerFor($bookingId);
        $oslo = new DateTimeZone('Europe/Oslo');
        $start = (new DateTimeImmutable((string) $b['start_tid'], new DateTimeZone('UTC')))->setTimezone($oslo);
        $lagret = self::lagredeLinjer($bookingId);
        $kobling = self::koblinger();
        // Én rad per gjenstand i hvert aktivt nivå (eller hele nivået når det
        // ikke har gjenstander). Det som er slått inn på en gjenstand eller et
        // nivå som ikke lenger finnes, står som egen rad: det kan beholdes
        // eller tas bort, men ikke økes.
        $rader = [];
        foreach (self::nivaer() as $n) {
            foreach (self::gjenstandsliste($n['gjenstander']) ?: [''] as $g) {
                $k = $n['id'] . '|' . $g;
                $rader[$k] = ['nokkel' => $k, 'nivaaId' => $n['id'], 'gjenstand' => $g, 'nivaa' => $n['navn'],
                              'prisOre' => $n['prisOre'], 'aktiv' => true, 'lager' => isset($kobling[$k])];
            }
        }
        foreach ($lagret as $k => $ls) {
            if (!isset($rader[$k])) {
                [$id, $g] = explode('|', $k, 2);
                $rader[$k] = ['nokkel' => $k, 'nivaaId' => (int) $id, 'gjenstand' => $g, 'nivaa' => $ls[0]['navn'],
                              'prisOre' => $ls[0]['prisOre'], 'aktiv' => false, 'lager' => false];
            }
            $rader[$k]['lagret'] = array_map(static fn(array $l): array => ['prisOre' => $l['prisOre'], 'antall' => $l['antall']], $ls);
        }
        return [
            'bookingId'      => $bookingId,
            'navn'           => (string) $b['navn'],
            'antall'         => (int) $b['antall'],
            'naar'           => Booking::norskDato((string) $b['start_tid']),
            'dato'           => $start->format('Y-m-d'),
            'rader'          => array_values(array_map(static fn(array $r): array => $r + ['lagret' => []], $rader)),
            'depositumOre'   => (int) $b['depositum_ore'],
            'betaltOre'      => (int) $bet['sum'],
            'belopOre'       => (int) $b['belop_ore'],
            'gjenstanderOre' => $b['gjenstander_ore'] !== null ? (int) $b['gjenstander_ore'] : null,
            'skyldigOre'     => max(0, (int) $b['belop_ore'] - (int) $bet['sum']),
        ];
    }

    /**
     * Slår inn gjenstandene på bookingen.
     *
     * Bookingens beløp blir summen av gjenstandene (aldri lavere enn det som
     * alt er betalt — penger tilbake er en refusjon, og den gjøres for seg).
     * Det som er betalt ved booking trekkes dermed fra av seg selv, og resten
     * står som «skyldig» under «Ta betalt». Kan gjøres om: linjene byttes ut,
     * og lageret for koblede gjenstander justeres med forskjellen.
     *
     * @param array<int|string,mixed> $valg [{nivaaId, gjenstand, antall}] — eller nivå-id => antall
     * @return array{sumOre:int, betaltOre:int, skyldigOre:int}
     */
    public static function kassa(int $bookingId, array $valg, ?int $adminId): array
    {
        $onsket = [];
        foreach ($valg as $k => $v) {
            if (is_array($v)) {
                $nk = (int) ($v['nivaaId'] ?? 0) . '|' . trim(mb_substr((string) ($v['gjenstand'] ?? ''), 0, 100));
                $n = (int) ($v['antall'] ?? 0);
            } else {
                $nk = self::nokkel($k);
                $n = (int) $v;
            }
            if ($n === 0) {
                continue;
            }
            if ($n < 0 || $n > self::MAKS_PER_NIVAA) {
                throw new RuntimeException('Antallet må være mellom 0 og ' . self::MAKS_PER_NIVAA . '.');
            }
            $onsket[$nk] = ($onsket[$nk] ?? 0) + $n;
        }
        if ($onsket === []) {
            throw new RuntimeException('Velg minst én gjenstand.');
        }

        return DB::iTransaksjon(static function () use ($bookingId, $onsket, $adminId): array {
            self::kassaBooking($bookingId, true);
            // Linjene som alt er slått inn, leses under låsen. De beholder
            // prisen de ble slått inn med (kontrollen 08.10): «Endre
            // gjenstander» skal ikke prise om det kunden alt har fått en sum
            // på. Bare nye gjenstander får dagens nivåpris.
            [$linjer, $sum] = self::regnLinjer(
                $onsket,
                array_column(self::nivaer(), null, 'id'),
                self::lagredeLinjer($bookingId)
            );
            // En betaling som fortsatt er på vei i Vipps kan sette bookingen
            // til «betalt» når den kommer (Booking::markerBetalt). Da ville
            // resten for gjenstandene forsvunnet. Vent til den er avklart.
            $aapen = (int) DB::verdi(
                "SELECT COUNT(*) FROM payments
                  WHERE status IN ('opprettet','venter','autorisert')
                    AND (booking_id = :b OR id = (SELECT payment_id FROM bookings WHERE id = :b2))",
                ['b' => $bookingId, 'b2' => $bookingId]
            );
            if ($aapen > 0) {
                throw new RuntimeException('Betalingen ved booking er ikke ferdig i Vipps ennå. Prøv igjen om litt.');
            }
            if (self::lagerKlar()) {
                // Lageret (migrasjon 262): koblingen slik den står nå, og det
                // som alt er trukket for bookingen.
                $kobling = self::koblinger();
                // Det som alt er slått inn beholder varen det ble trukket fra
                // (regnLinjer() tar med produkt_id fra de lagrede linjene).
                // Dagens kobling gjelder bare nye gjenstander.
                foreach ($linjer as &$l) {
                    if (!array_key_exists('produkt_id', $l)) {
                        $l['produkt_id'] = $kobling[$l['nivaa_id'] . '|' . (string) ($l['gjenstand'] ?? '')] ?? null;
                    }
                }
                unset($l);
                $gamle = array_map(static fn(array $r): array => [
                    'produkt_id' => $r['produkt_id'] !== null ? (int) $r['produkt_id'] : null,
                    'trukket'    => (int) $r['trukket'],
                ], DB::alle('SELECT produkt_id, trukket FROM pop_kasselinjer WHERE booking_id = :b FOR UPDATE', ['b' => $bookingId]));
                self::justerLager($gamle, $linjer);
            } else {
                foreach ($linjer as &$l) {
                    unset($l['gjenstand'], $l['produkt_id']);
                }
                unset($l);
            }
            DB::kjor('DELETE FROM pop_kasselinjer WHERE booking_id = :b', ['b' => $bookingId]);
            foreach ($linjer as $l) {
                DB::settInn('pop_kasselinjer', $l + ['booking_id' => $bookingId, 'registrert_av' => $adminId]);
            }
            $betalt = (int) Booking::betalingerFor($bookingId)['sum'];
            // reservert_til toemmes: en plass som er gjort opp i kassa skal
            // ikke slippes av cron fordi en Vipps-frist en gang sto paa den.
            DB::oppdater('bookings', [
                'belop_ore'       => max($sum, $betalt),
                'gjenstander_ore' => $sum,
                'reservert_til'   => null,
            ], ['id' => $bookingId]);
            $st = Booking::settBetaltStatus($bookingId);
            return ['sumOre' => $sum, 'betaltOre' => $betalt, 'skyldigOre' => (int) $st['skyldig']];
        });
    }

    /**
     * Linjene som er slått inn, per «nivå-id|gjenstand», i den rekkefølgen de
     * ble lagret.
     *
     * @return array<string, list<array{navn:string,prisOre:int,antall:int}>>
     */
    private static function lagredeLinjer(int $bookingId): array
    {
        $g = DB::harKolonne('pop_kasselinjer', 'produkt_id') ? 'gjenstand, produkt_id' : 'NULL AS gjenstand, NULL AS produkt_id';
        $ut = [];
        foreach (DB::alle(
            "SELECT nivaa_id, {$g}, navn, pris_ore, antall FROM pop_kasselinjer
              WHERE booking_id = :b AND nivaa_id IS NOT NULL ORDER BY id",
            ['b' => $bookingId]
        ) as $l) {
            $ut[(int) $l['nivaa_id'] . '|' . (string) ($l['gjenstand'] ?? '')][] =
                ['navn' => (string) $l['navn'], 'prisOre' => (int) $l['pris_ore'], 'antall' => (int) $l['antall'],
                 'produktId' => $l['produkt_id'] !== null ? (int) $l['produkt_id'] : null];
        }
        return $ut;
    }

    /**
     * Linjene og summen for et nytt valg. Per gjenstand brukes først de
     * lagrede linjene (med sin pris), og bare det som kommer i tillegg prises
     * med dagens nivåpris. En gjenstand eller et nivå som er tatt bort i admin
     * kan beholde eller redusere det som alt er slått inn, men ikke få flere.
     *
     * @param array<int|string,int> $onsket «nivå-id|gjenstand» (eller nivå-id) => antall
     * @param array<int,array<string,mixed>> $nivaer aktive nivåer per id
     * @param array<int|string, list<array{navn:string,prisOre:int,antall:int}>> $lagret
     * @return array{0: list<array<string,mixed>>, 1: int}
     */
    public static function regnLinjer(array $onsket, array $nivaer, array $lagret): array
    {
        $lagretN = [];
        foreach ($lagret as $k => $ls) {
            $lagretN[self::nokkel($k)] = $ls;
        }
        $linjer = [];
        $sum = 0;
        foreach ($onsket as $k => $n) {
            $k = self::nokkel($k);
            [$id, $g] = explode('|', $k, 2);
            $id = (int) $id;
            $igjen = $n;
            foreach ($lagretN[$k] ?? [] as $l) {
                if ($igjen <= 0) {
                    break;
                }
                $ta = min($igjen, $l['antall']);
                $linjer[] = ['nivaa_id' => $id, 'gjenstand' => $g === '' ? null : $g, 'navn' => $l['navn'],
                             'pris_ore' => $l['prisOre'], 'antall' => $ta]
                    // Varen den lagrede linja ble trukket fra, når den er kjent.
                    + (array_key_exists('produktId', $l) ? ['produkt_id' => $l['produktId']] : []);
                $sum += $l['prisOre'] * $ta;
                $igjen -= $ta;
            }
            if ($igjen > 0) {
                $liste = isset($nivaer[$id]) ? self::gjenstandsliste((string) ($nivaer[$id]['gjenstander'] ?? '')) : [];
                if (!isset($nivaer[$id]) || ($g !== '' && !in_array($g, $liste, true))) {
                    throw new RuntimeException('Fant ikke prisnivået. Last siden på nytt.');
                }
                $linjer[] = ['nivaa_id' => $id, 'gjenstand' => $g === '' ? null : $g, 'navn' => $nivaer[$id]['navn'],
                             'pris_ore' => $nivaer[$id]['prisOre'], 'antall' => $igjen];
                $sum += $nivaer[$id]['prisOre'] * $igjen;
            }
        }
        return [$linjer, $sum];
    }

    /**
     * Bookingen, og at den er en Paint on Pots-booking med beløp ved booking
     * som kan gjøres opp. Eldre bookinger (uten depositum_ore) røres ikke her.
     *
     * @return array<string,mixed>
     */
    private static function kassaBooking(int $bookingId, bool $laas): array
    {
        if (!self::klar() || !DB::harKolonne('bookings', 'gjenstander_ore')) {
            throw new RuntimeException('Kjør oppdateringene først (⚙ Kjør oppdateringer).');
        }
        $b = DB::en(
            'SELECT b.id, b.status, b.antall, b.belop_ore, b.depositum_ore, b.gjenstander_ore,
                    COALESCE(m.navn, b.gjest_navn) AS navn, cs.start_tid, cs.course_id
               FROM bookings b
               JOIN course_sessions cs ON cs.id = b.course_session_id
          LEFT JOIN members m ON m.id = b.member_id
              WHERE b.id = :i' . ($laas ? ' FOR UPDATE' : ''),
            ['i' => $bookingId]
        );
        if ($b === null || !Malebord::gjelder((int) $b['course_id'])) {
            throw new RuntimeException('Fant ikke Paint on Pots-bookingen.');
        }
        if ($b['depositum_ore'] === null) {
            throw new RuntimeException('Denne bookingen ble gjort før beløpet ved booking. Bruk «Ta betalt».');
        }
        if (!in_array((string) $b['status'], ['betalt', 'reservert'], true)) {
            throw new RuntimeException('Bookingen er ikke aktiv.');
        }
        return $b;
    }
}
