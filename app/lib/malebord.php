<?php
/**
 * Paint on Pots: malebordet.
 *
 * Eieren, «ok, bygg det» 7. oktober 2026, og «totalt nytt opplegg»
 * 8. oktober 2026:
 *
 *   - Kunden velger dag, antall og ankomsttid (hvert kvarter) innenfor
 *     tidene satt i admin. Ingen faste 2-timersbolker.
 *   - Besoeket varer courses.plass_minutter (admin), fra ankomsten.
 *   - Med plassgrense (ressursen «Paint on Pots» under Ressurser) telles personer som er
 *     til stede SAMTIDIG gjennom hele besoeket (stoerste overlapp), ikke alle
 *     dagens bestillinger. PoP teller ikke mot verkstedplassene, og kurs og
 *     medlemmer teller ikke mot PoP.
 *   - «Ingen plassgrense» (ressursen slått av): ingen avvisning paa grunn
 *     av kapasitet.
 *   - Unntak per dato (pop_dager, migrasjon 258): fullt (fra et klokkeslett
 *     eller hele dagen), stengt («Ingen PoP») eller andre tider.
 *
 * Hvilke kurs: de som foelger aapningstidene (courses.folger_apningstid = 1).
 *
 * Bookinger som alt er gjort roeres aldri: et unntak eller faerre stoler gjoer
 * bare at nye ikke slipper inn. Oekter med bookinger blir staaende.
 *
 * Taaler at migrasjon 257/258 ikke er kjoert: da er alle dager som foer,
 * lengden er Apent::PLASS_MINUTTER og det er plassgrense.
 */

declare(strict_types=1);

final class Malebord
{
    /** Hoeyeste antall personer i én bestilling naar det ikke er plassgrense. */
    public const MAKS_ANTALL = 50;

    /** @var array<string, array{status:string, fra:?string, til:?string}|null> */
    private static array $dager = [];
    /** @var array<int, bool> */
    private static array $gjelder = [];

    /** Er tabellen der? (migrasjon 258) */
    public static function klar(): bool
    {
        return DB::harTabell('pop_dager');
    }

    /** Leses paa nytt etter en endring. */
    public static function glem(): void
    {
        self::$dager = [];
        self::$gjelder = [];
        self::$ressurs = [];
        Apent::glemMalebord();
    }

    /** Er dette et malebord-kurs (foelger aapningstidene)? */
    public static function gjelder(int $kursId): bool
    {
        if ($kursId <= 0 || !DB::harKolonne('courses', 'folger_apningstid')) {
            return false;
        }
        return self::$gjelder[$kursId] ??= (int) DB::verdi(
            'SELECT COALESCE(folger_apningstid, 0) FROM courses WHERE id = :i',
            ['i' => $kursId]
        ) === 1;
    }

    /**
     * Paint on Pots-kurset admin styrer: publisert og foelger aapningstidene.
     *
     * @return array<string,mixed>|null
     */
    public static function kurs(): ?array
    {
        if (!DB::harKolonne('courses', 'folger_apningstid')) {
            return null;
        }
        $grense = DB::harKolonne('courses', 'uten_plassgrense') ? 'COALESCE(uten_plassgrense, 0)' : '0';
        $k = DB::en(
            "SELECT id, tittel, kapasitet, pris_ore, {$grense} AS uten_grense FROM courses
              WHERE folger_apningstid = 1 AND status = 'publisert'
           ORDER BY (slug = 'paint-on-pots') DESC, id
              LIMIT 1"
        );
        if ($k === null) {
            return null;
        }
        $id = (int) $k['id'];
        return [
            'id'         => $id,
            'tittel'     => (string) $k['tittel'],
            'stoler'     => self::stoler($id),
            'utenGrense' => self::utenGrense($id),
            'ressurs'    => self::ressurs($id),
            'maksAntall' => self::maksAntall($id),
            'lengde'     => Apent::plassMinutter($id),
            'prisOre'    => (int) $k['pris_ore'],
            'ukeplan'    => Apent::ukeplan($id),
            'oppmote'    => Oppmote::kurs(),
            // Beloep ved booking som trekkes fra i verkstedet, fristen for
            // avbestilling og prisnivaaene (migrasjon 260, eieren 8. oktober 2026).
            'depositum'         => PopPris::erDepositum($id),
            'avbestillingTimer' => PopPris::avbestillingTimer($id),
            'nivaer'            => PopPris::nivaer(),
            'prisKlar'          => PopPris::klar() && DB::harKolonne('bookings', 'gjenstander_ore'),
            // Gjenstand → butikkvare, frivillig (migrasjon 262).
            'lagerKlar'         => PopPris::lagerKlar(),
            'koblinger'         => (object) PopPris::koblinger(),
            'varer'             => PopPris::varer(),
        ];
    }

    /** @var array<int, array{id:int,navn:string,antall:int,aktiv:bool,egen:bool}|null> */
    private static array $ressurs = [];

    /**
     * Kursets egen ressurs (eieren, 8. oktober 2026: «Paint on Pots» under
     * Ressurser, 20 plasser). «egen» = ingen andre kurs enn malebord-kurs
     * bruker den. Er ressursen delt med verkstedet (foer migrasjon 258), er
     * den ikke PoP sin, og da gjelder kursets eget plasstall.
     *
     * @return array{id:int,navn:string,antall:int,aktiv:bool,egen:bool}|null
     */
    public static function ressurs(int $kursId): ?array
    {
        if (array_key_exists($kursId, self::$ressurs)) {
            return self::$ressurs[$kursId];
        }
        $r = null;
        if (DB::harKolonne('courses', 'ressurs_id') && DB::harTabell('ressurser')) {
            $rad = DB::en(
                'SELECT r.id, r.navn, r.antall, r.aktiv FROM courses c
                   JOIN ressurser r ON r.id = c.ressurs_id WHERE c.id = :k',
                ['k' => $kursId]
            );
            if ($rad !== null) {
                $andre = (int) DB::verdi(
                    'SELECT COUNT(*) FROM courses WHERE ressurs_id = :r AND COALESCE(folger_apningstid, 0) = 0',
                    ['r' => (int) $rad['id']]
                );
                $r = [
                    'id'     => (int) $rad['id'],
                    'navn'   => (string) $rad['navn'],
                    'antall' => (int) $rad['antall'],
                    'aktiv'  => (int) $rad['aktiv'] === 1,
                    'egen'   => $andre === 0,
                ];
            }
        }
        return self::$ressurs[$kursId] = $r;
    }

    /**
     * Plassgrensen: personer til stede samtidig. Staar ett sted — paa
     * ressursen «Paint on Pots» under Ressurser. Foer ressursen finnes
     * (migrasjon 258 ikke kjoert), gjelder kursets eget plasstall.
     */
    public static function stoler(int $kursId): int
    {
        $r = self::ressurs($kursId);
        if ($r !== null && $r['egen']) {
            return max(0, $r['antall']);
        }
        return max(0, (int) DB::verdi('SELECT kapasitet FROM courses WHERE id = :i', ['i' => $kursId]));
    }

    /**
     * Ingen plassgrense: ressursen er slått av under Ressurser. Foer
     * ressursen finnes, gjelder «Ingen plassgrense» paa kurset (migrasjon 257).
     */
    public static function utenGrense(int $kursId): bool
    {
        $r = self::ressurs($kursId);
        if ($r !== null && $r['egen']) {
            return !$r['aktiv'] || $r['antall'] <= 0;
        }
        return Booking::utenPlassgrense($kursId);
    }

    /** Hoeyeste antall personer én bestilling kan ha. */
    public static function maksAntall(int $kursId): int
    {
        return self::utenGrense($kursId) ? self::MAKS_ANTALL : max(1, self::stoler($kursId));
    }

    /**
     * Dagens unntak, eller null.
     *
     * @return array{status:string, fra:?string, til:?string}|null  «HH:MM» norsk tid
     */
    public static function dag(string $dato): ?array
    {
        if (array_key_exists($dato, self::$dager)) {
            return self::$dager[$dato];
        }
        $svar = null;
        if (self::klar()) {
            $til = DB::harKolonne('pop_dager', 'til_tid') ? 'til_tid' : 'NULL AS til_tid';
            $r = DB::en("SELECT status, fra_tid, {$til} FROM pop_dager WHERE dato = :d", ['d' => $dato]);
            if ($r !== null) {
                $svar = self::rad($r);
            }
        }
        return self::$dager[$dato] = $svar;
    }

    /**
     * Alle unntak i et datospenn (ett oppslag).
     *
     * @return array<string, array{status:string, fra:?string, til:?string}>
     */
    public static function merker(string $fraDato, string $tilDato): array
    {
        if (!self::klar()) {
            return [];
        }
        $til = DB::harKolonne('pop_dager', 'til_tid') ? 'til_tid' : 'NULL AS til_tid';
        $ut = [];
        foreach (DB::alle(
            "SELECT dato, status, fra_tid, {$til} FROM pop_dager WHERE dato >= :f AND dato <= :t",
            ['f' => $fraDato, 't' => $tilDato]
        ) as $r) {
            $ut[(string) $r['dato']] = self::$dager[(string) $r['dato']] = self::rad($r);
        }
        return $ut;
    }

    /** @return array{status:string, fra:?string, til:?string} */
    private static function rad(array $r): array
    {
        return [
            'status' => (string) $r['status'],
            'fra'    => $r['fra_tid'] !== null ? substr((string) $r['fra_tid'], 0, 5) : null,
            'til'    => ($r['til_tid'] ?? null) !== null ? substr((string) $r['til_tid'], 0, 5) : null,
        ];
    }

    /** «Ingen PoP» denne dagen? */
    public static function stengt(string $dato): bool
    {
        return (self::dag($dato)['status'] ?? '') === 'stengt';
    }

    /**
     * Er en ankomst paa denne dagen og klokka sperret for nye bookinger
     * (fullt eller stengt)?
     *
     * @param string $klokke «HH:MM» norsk tid
     */
    public static function sperret(string $dato, string $klokke): bool
    {
        $d = self::dag($dato);
        if ($d === null || $d['status'] === 'tider') {
            return false;
        }
        if ($d['status'] === 'stengt' || $d['fra'] === null) {
            return true;
        }
        return $klokke >= $d['fra'];
    }

    /** Samme spoersmaal for et UTC-tidspunkt («Y-m-d H:i:s»). */
    public static function sperretUtc(string $startUtc): bool
    {
        $t = (new DateTimeImmutable($startUtc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Oslo'));
        return self::sperret($t->format('Y-m-d'), $t->format('H:i'));
    }

    // ── Hvem som sitter der samtidig ─────────────────────────────────────

    /**
     * Besoekene som er booket og overlapper tidsrommet: [start, slutt, personer]
     * som Unix-tid. Aktiv booking = betalt, eller reservert og ikke utgaatt
     * (samme regel som Booking), pluss det som er satt av for haand.
     *
     * @return list<array{0:int,1:int,2:int}>
     */
    public static function besok(int $kursId, string $fraUtc, string $tilUtc, int $utenBooking = 0): array
    {
        $min = Apent::plassMinutter($kursId);
        $ut = [];
        foreach (DB::alle(
            "SELECT cs.start_tid,
                    COALESCE(cs.slutt_tid, cs.start_tid + INTERVAL {$min} MINUTE) AS slutt,
                    COALESCE(cs.manuelt_opptatt, 0)
                    + COALESCE((SELECT SUM(b.antall) FROM bookings b
                                 WHERE b.course_session_id = cs.id
                                   AND b.id <> :u
                                   AND (b.status = 'betalt'
                                        OR (b.status = 'reservert'
                                            AND (b.reservert_til IS NULL
                                                 OR b.reservert_til > UTC_TIMESTAMP())))), 0) AS n
               FROM course_sessions cs
              WHERE cs.course_id = :k
                AND cs.status = 'planlagt'
                AND cs.start_tid < :til
                AND COALESCE(cs.slutt_tid, cs.start_tid + INTERVAL {$min} MINUTE) > :fra",
            ['k' => $kursId, 'fra' => $fraUtc, 'til' => $tilUtc, 'u' => $utenBooking]
        ) as $r) {
            if ((int) $r['n'] > 0) {
                $ut[] = [strtotime($r['start_tid'] . ' UTC'), strtotime($r['slutt'] . ' UTC'), (int) $r['n']];
            }
        }
        return $ut;
    }

    /**
     * Flest personer til stede samtidig i [a, b) (Unix-tid).
     *
     * @param list<array{0:int,1:int,2:int}> $besok
     */
    public static function topp(array $besok, int $a, int $b): int
    {
        $hendelser = [];
        foreach ($besok as [$s, $e, $n]) {
            $s = max($s, $a);
            $e = min($e, $b);
            if ($s < $e) {
                $hendelser[] = [$s, $n];
                $hendelser[] = [$e, -$n];
            }
        }
        // Slutt foer start paa samme tid: den som gaar 17:00 og den som
        // kommer 17:00 sitter ikke der samtidig.
        usort($hendelser, static fn($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
        $naa = 0;
        $topp = 0;
        foreach ($hendelser as [, $d]) {
            $naa += $d;
            $topp = max($topp, $naa);
        }
        return $topp;
    }

    /** Flest personer samtidig i tidsrommet (UTC «Y-m-d H:i:s»). */
    public static function opptatt(int $kursId, string $fraUtc, string $tilUtc, int $utenBooking = 0): int
    {
        return self::topp(
            self::besok($kursId, $fraUtc, $tilUtc, $utenBooking),
            strtotime($fraUtc . ' UTC'),
            strtotime($tilUtc . ' UTC')
        );
    }

    /**
     * Ledige stoler gjennom hele tidsrommet. Aldri under null.
     * Uten plassgrense: Booking::UTEN_GRENSE.
     */
    public static function ledige(int $kursId, string $fraUtc, string $tilUtc, int $utenBooking = 0): int
    {
        if (self::utenGrense($kursId)) {
            return Booking::UTEN_GRENSE;
        }
        return max(0, self::stoler($kursId) - self::opptatt($kursId, $fraUtc, $tilUtc, $utenBooking));
    }

    /**
     * Ankomsttidene en dag: hvert kvarter innenfor aapningstida der hele
     * besoeket faar plass og det er stoler til $antall.
     *
     * @return array{vindu:string, tider:list<array{tid:string,slutt:string,ledige:int}>, lengde:int}
     */
    public static function kvarter(int $kursId, string $dato, int $antall = 1, int $utenBooking = 0): array
    {
        $lengde = Apent::plassMinutter($kursId);
        $tomt = ['vindu' => '', 'tider' => [], 'lengde' => $lengde];
        $vinduer = Apent::apneVinduer($kursId)[$dato] ?? [];
        if ($vinduer === []) {
            return $tomt;
        }
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $naa  = new DateTimeImmutable('now', $oslo);
        $dagStart = new DateTimeImmutable($dato . ' 00:00', $oslo);
        $besok = self::besok(
            $kursId,
            $dagStart->setTimezone($utc)->format('Y-m-d H:i:s'),
            $dagStart->modify('+2 days')->setTimezone($utc)->format('Y-m-d H:i:s'),
            $utenBooking
        );
        $uten = self::utenGrense($kursId);
        $stoler = self::stoler($kursId);

        $tider = [];
        foreach ($vinduer as $v) {
            $start = new DateTimeImmutable($dato . ' ' . $v['fra'], $oslo);
            $slutt = new DateTimeImmutable($dato . ' ' . $v['til'], $oslo);
            if ($start <= $naa) {
                $start = Apent::nesteKvarter($naa);
            }
            while ($start < $slutt) {
                $til = $start->modify('+' . $lengde . ' minutes');
                if ($til > $slutt) {
                    break;
                }
                if (!self::sperret($dato, $start->format('H:i'))) {
                    $ledige = $uten ? Booking::UTEN_GRENSE
                        : max(0, $stoler - self::topp($besok, $start->getTimestamp(), $til->getTimestamp()));
                    if ($ledige >= $antall) {
                        $tider[] = ['tid' => $start->format('H:i'), 'slutt' => $til->format('H:i'), 'ledige' => $ledige];
                    }
                }
                $start = $start->modify('+15 minutes');
            }
        }
        return [
            'vindu'  => $vinduer[0]['fra'] . "\u{2013}" . $vinduer[count($vinduer) - 1]['til'],
            'tider'  => $tider,
            'lengde' => $lengde,
        ];
    }

    // ── Unntak per dato ──────────────────────────────────────────────────

    /**
     * Sett et unntak: «fullt» (fra = null betyr hele dagen), «stengt», eller
     * «tider» (fra og til).
     */
    public static function settDag(string $dato, string $status, ?string $fra, ?string $til, ?int $av): void
    {
        if (!in_array($status, ['fullt', 'stengt', 'tider'], true)) {
            throw new InvalidArgumentException('Ukjent status.');
        }
        // «Andre tider» staar paa hele kvarter, saa kundetidene kan bookes.
        if ($status === 'tider') {
            foreach ([$fra, $til] as $t) {
                if ($t === null || preg_match('/^([01]\d|2[0-3]):(00|15|30|45)$/', $t) !== 1) {
                    throw new InvalidArgumentException('Bruk hele kvarter.');
                }
            }
        }
        $felt = ['d' => $dato, 's' => $status, 'f' => $fra !== null ? $fra . ':00' : null, 'a' => $av];
        if (DB::harKolonne('pop_dager', 'til_tid')) {
            DB::kjor(
                'INSERT INTO pop_dager (dato, status, fra_tid, til_tid, satt_av, satt_tid)
                 VALUES (:d, :s, :f, :t, :a, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE status = VALUES(status), fra_tid = VALUES(fra_tid), til_tid = VALUES(til_tid),
                                         satt_av = VALUES(satt_av), satt_tid = VALUES(satt_tid)',
                $felt + ['t' => $til !== null ? $til . ':00' : null]
            );
        } else {
            DB::kjor(
                'INSERT INTO pop_dager (dato, status, fra_tid, satt_av, satt_tid)
                 VALUES (:d, :s, :f, :a, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE status = VALUES(status), fra_tid = VALUES(fra_tid),
                                         satt_av = VALUES(satt_av), satt_tid = VALUES(satt_tid)',
                $felt
            );
        }
        self::glem();
    }

    /** «Aapne igjen»: unntaket tas bort, og dagen er som vanlig. */
    public static function apne(string $dato): void
    {
        DB::kjor('DELETE FROM pop_dager WHERE dato = :d', ['d' => $dato]);
        self::glem();
    }

    // ── Admin ────────────────────────────────────────────────────────────

    /**
     * Reservasjonene en dag, én rad per bestilling: ankomst, slutt, kontakt,
     * antall og betalingsstatus.
     *
     * @return list<array<string,mixed>>
     */
    public static function reservasjoner(int $kursId, string $dato): array
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $fra  = (new DateTimeImmutable($dato . ' 00:00:00', $oslo))->setTimezone($utc);
        $til  = $fra->modify('+1 day');
        $min  = Apent::plassMinutter($kursId);
        $melding = DB::harKolonne('bookings', 'gjest_melding') ? 'b.gjest_melding' : 'NULL';
        $forskudd = DB::harKolonne('bookings', 'uten_forskudd') ? 'b.uten_forskudd' : '0';
        // Beloep ved booking og gjenstandene i kassa (migrasjon 260).
        $dep = DB::harKolonne('bookings', 'gjenstander_ore')
            ? 'b.depositum_ore, b.gjenstander_ore' : 'NULL AS depositum_ore, NULL AS gjenstander_ore';
        $ut = [];
        foreach (DB::alle(
            "SELECT b.id, b.antall, b.status, b.belop_ore, b.reservert_til, b.betalt_maate,
                    {$melding} AS melding, {$forskudd} AS uten_forskudd, b.allergier, {$dep},
                    COALESCE(m.navn, b.gjest_navn) AS navn,
                    COALESCE(m.epost, b.gjest_epost) AS epost,
                    COALESCE(m.telefon, b.gjest_telefon) AS telefon,
                    cs.id AS okt_id, cs.start_tid,
                    COALESCE(cs.slutt_tid, cs.start_tid + INTERVAL {$min} MINUTE) AS slutt_tid
               FROM bookings b
               JOIN course_sessions cs ON cs.id = b.course_session_id
          LEFT JOIN members m ON m.id = b.member_id
              WHERE cs.course_id = :k AND cs.status = 'planlagt'
                AND cs.start_tid >= :f AND cs.start_tid < :t
                AND b.status IN ('betalt','reservert','ikke_mott')
           ORDER BY cs.start_tid, b.id",
            ['k' => $kursId, 'f' => $fra->format('Y-m-d H:i:s'), 't' => $til->format('Y-m-d H:i:s')]
        ) as $r) {
            $bet = Booking::betalingerFor((int) $r['id']);
            $ut[] = [
                'bookingId' => (int) $r['id'],
                'oktId'     => (int) $r['okt_id'],
                'dato'      => $dato,
                'fra'       => (new DateTimeImmutable((string) $r['start_tid'], $utc))->setTimezone($oslo)->format('H:i'),
                'til'       => (new DateTimeImmutable((string) $r['slutt_tid'], $utc))->setTimezone($oslo)->format('H:i'),
                'navn'      => (string) $r['navn'],
                'epost'     => (string) ($r['epost'] ?? ''),
                'telefon'   => (string) ($r['telefon'] ?? ''),
                'antall'    => (int) $r['antall'],
                'melding'   => trim((string) ($r['melding'] ?? '')),
                'allergier' => trim((string) ($r['allergier'] ?? '')),
                'belopOre'  => (int) $r['belop_ore'],
                'betaltOre' => (int) $bet['sum'],
                'status'    => (string) $r['status'],
                'betaling'  => self::betalingsstatus($r, (int) $bet['sum']),
                'mott'      => (string) $r['status'] !== 'ikke_mott',
                'forStatus' => self::forStatus($r, $bet),
                // Paint on Pots med beloep ved booking: kassa kan slaa inn
                // gjenstandene. Eldre bookinger (uten depositum_ore) bruker
                // «Ta betalt» som foer.
                'depositumOre'   => $r['depositum_ore'] !== null ? (int) $r['depositum_ore'] : null,
                'gjenstanderOre' => $r['gjenstander_ore'] !== null ? (int) $r['gjenstander_ore'] : null,
                'kanKassa'       => $r['depositum_ore'] !== null && in_array((string) $r['status'], ['betalt', 'reservert'], true),
            ];
        }
        return $ut;
    }

    /**
     * Betalingen slik admin skal lese den. Ingenting staar som betalt foer
     * pengene er bekreftet eller ført som mottatt.
     */
    public static function betalingsstatus(array $r, int $betaltOre): string
    {
        $belop = (int) $r['belop_ore'];
        if ($belop <= 0) {
            return 'Gratis';
        }
        // Paint on Pots med beloep ved booking (eieren, 8. oktober 2026):
        // betalt ved booking, men gjenstandene er ikke slaatt inn ennaa =
        // «Delvis betalt». Foerst naar kassa har gjort opp, kan den bli
        // «Betalt».
        if (($r['depositum_ore'] ?? null) !== null && ($r['gjenstander_ore'] ?? null) === null && $betaltOre > 0) {
            return 'Delvis betalt';
        }
        if ($betaltOre >= $belop || ((string) $r['status'] === 'betalt' && Booking::maateGirPenger((string) ($r['betalt_maate'] ?? '')))) {
            return 'Betalt';
        }
        if ($betaltOre > 0) {
            return 'Delvis betalt';
        }
        if ((string) $r['status'] === 'reservert' && ($r['reservert_til'] ?? null) !== null) {
            return 'Reservert (venter på Vipps)';
        }
        return (int) ($r['uten_forskudd'] ?? 0) === 1 ? 'Betaler ved besøket' : 'Ikke betalt';
    }

    /** Statusen «Møtte» skal tilbake til (samme regel som kursstart3.php). */
    private static function forStatus(array $r, array $bet): string
    {
        $m = (string) ($r['betalt_maate'] ?? '');
        $gjortOpp = (int) $r['belop_ore'] <= 0
            || ($bet['rader'] !== [] && $bet['sum'] >= (int) $r['belop_ore'])
            || Booking::maateGirPenger($m) || in_array($m, ['Gratis', 'Gavekort'], true);
        return $gjortOpp ? 'betalt' : 'reservert';
    }

    /**
     * Tidsrommene en dag med «flest samtidig av stoler», for Oversikt.
     *
     * @return list<array{fra:string,til:string,booket:int,stoler:int,antall:int}>
     */
    public static function okterPaaDag(int $kursId, string $dato): array
    {
        $stoler = self::utenGrense($kursId) ? 0 : self::stoler($kursId);
        $per = [];
        foreach (self::reservasjoner($kursId, $dato) as $r) {
            if (!$r['mott']) {
                continue;
            }
            $n = $r['fra'] . '|' . $r['til'];
            $per[$n] ??= ['fra' => $r['fra'], 'til' => $r['til'], 'antall' => 0];
            $per[$n]['antall'] += $r['antall'];
        }
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $ut = [];
        foreach ($per as $p) {
            $a = (new DateTimeImmutable($dato . ' ' . $p['fra'], $oslo))->setTimezone($utc)->format('Y-m-d H:i:s');
            $b = (new DateTimeImmutable($dato . ' ' . $p['til'], $oslo))->setTimezone($utc)->format('Y-m-d H:i:s');
            $ut[] = $p + ['booket' => self::opptatt($kursId, $a, $b), 'stoler' => $stoler];
        }
        return $ut;
    }

    /**
     * Sju dager fra og med en mandag: apen / fullt / stengt / tider / ingen
     * (ingen = ikke aapent for PoP den dagen).
     *
     * @return list<array<string,mixed>>
     */
    public static function uke(int $kursId, string $mandag): array
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $raa  = Apent::vinduerForKurs($kursId);
        $apne = Apent::apneVinduer($kursId);
        $ut = [];
        $dag = new DateTimeImmutable($mandag, $oslo);
        for ($i = 0; $i < 7; $i++) {
            $d = $dag->modify('+' . $i . ' days')->format('Y-m-d');
            $merke = self::dag($d);
            $v = $apne[$d] ?? ($raa[$d] ?? []);
            $ut[] = [
                'dato'    => $d,
                'status'  => $merke['status'] ?? (isset($raa[$d]) ? 'apen' : 'ingen'),
                'fra'     => $merke['fra'] ?? null,
                'til'     => $merke['til'] ?? null,
                'vindu'   => $v !== [] ? $v[0]['fra'] . "\u{2013}" . $v[count($v) - 1]['til'] : '',
                'antall'  => array_sum(array_map(static fn($r) => $r['mott'] ? $r['antall'] : 0, self::reservasjoner($kursId, $d))),
            ];
        }
        return $ut;
    }

    /**
     * Datoene katalogen viser for et malebord-kurs: én per aapen dag, med
     * aapningstida som klokkeslett. Ingen faste bolker — kunden velger
     * ankomsttid etterpaa (api/tider.php).
     *
     * @return list<array<string,mixed>>
     */
    public static function katalogDager(int $kursId, int $plasser): array
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $DAG = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
        $MND = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august',
                'september', 'oktober', 'november', 'desember'];
        $ut = [];
        foreach (Apent::apneVinduer($kursId) as $dato => $vinduer) {
            if ($vinduer === []) {
                continue;
            }
            $tider = self::kvarter($kursId, (string) $dato, 1)['tider'];
            $fra = $vinduer[0]['fra'];
            $til = $vinduer[count($vinduer) - 1]['til'];
            $start = new DateTimeImmutable($dato . ' ' . $fra, $oslo);
            // En dag der siste ankomst har passert, staar ikke ute.
            if ($tider === [] && $start->format('Y-m-d') === (new DateTimeImmutable('now', $oslo))->format('Y-m-d')) {
                continue;
            }
            $dagNavn = $DAG[(int) $start->format('N') - 1] . ' ' . (int) $start->format('j') . '. ' . $MND[(int) $start->format('n') - 1];
            $ledige = $tider === [] ? 0 : max(array_map(static fn($t) => (int) $t['ledige'], $tider));
            $ut[] = [
                'oktId'       => 0,
                'dato'        => $dagNavn . ', ' . $fra . "\u{2013}" . $til,
                'dag'         => $dagNavn,
                'klokkeStart' => $fra,
                'klokke'      => $fra . "\u{2013}" . $til,
                'startUtc'    => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                'maaned'      => $start->format('Y-m'),
                'dagIso'      => (string) $dato,
                'ledige'      => $ledige,
                'solgt'       => 0,
                'sperret'     => false,
                'plasser'     => $plasser,
                'pris'        => null,
                'prisOre'     => null,
                'info'        => '',
                'samlinger'   => [],
            ];
        }
        return $ut;
    }
}
