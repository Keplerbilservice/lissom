<?php
/**
 * Medlemsoppfølging (eieren 12. oktober 2026, «ja, fint» til visningen
 * «Medlemmer og oppfølging»).
 *
 *   2  Prøv Lissom slutter snart: én samlet SMS til admin-mobilen kl. 09
 *      (malen intern_prove_slutter_sms, «antall dager før», standard 5), og
 *      saken i Må gjøres på I dag til personen har valgt medlemskap eller
 *      perioden er over.
 *   3  SMS til medlemmer som ikke kommer (malen medlem_savner_deg_sms, AV fra
 *      start): én SMS etter N dager (standard 21) uten innstempling, ny først
 *      etter at de har vært innom igjen. Hverdager kl. 10–18, aldri samme dag
 *      som en annen SMS fra Lissom, ikke til Prøv Lissom og ikke til frys/pause.
 *      Bryter per medlem (members.paaminnelse_sms).
 *   4  SMS til prøvemedlemmet selv 3 dager før slutt (malen prove_slutter_sms,
 *      AV fra start).
 *   6  «X av Y ble medlem» for Prøv Lissom de siste 3 månedene.
 *   7  «Snakket med» med notat (tabellen medlem_oppfolging). Notatet står på
 *      medlemskortet, og saken forsvinner fra Må gjøres.
 *
 * Malens av/på er bryteren (Innstillinger › Varsler). Alt sendes gjennom
 * Varsel (køen i notifications), som holder SMS tilbake i testmiljøet.
 * Migrasjon 273 kan mangle: da sendes ingenting, og sakene vises uten notater.
 */

declare(strict_types=1);

final class Oppfolging
{
    public const STD_PROVE_DAGER = 5;
    public const STD_SAVNER_DAGER = 21;
    public const PROVE_MEDLEM_DAGER = 3;

    public const MAL_ADMIN = 'intern_prove_slutter_sms';
    public const MAL_PROVE = 'prove_slutter_sms';
    public const MAL_SAVNER = 'medlem_savner_deg_sms';

    /** Er migrasjon 273 kjørt? */
    public static function klar(): bool
    {
        return DB::harTabell('medlem_oppfolging') && DB::harKolonne('members', 'paaminnelse_sms');
    }

    private static function oslo(): DateTimeZone
    {
        return new DateTimeZone('Europe/Oslo');
    }

    // ── Innstillinger ────────────────────────────────────────────────────

    private static function les(string $nokkel): ?string
    {
        if (!DB::harTabell('innstillinger')) {
            return null;
        }
        $v = DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => $nokkel]);
        return $v === null ? null : (string) $v;
    }

    private static function skriv(string $nokkel, string $verdi, ?int $av = null): void
    {
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            [$nokkel, $verdi, $av]
        );
    }

    public static function proveDager(): int
    {
        $v = self::les('oppfolging_prove_dager');
        return is_numeric($v) ? max(1, min(28, (int) $v)) : self::STD_PROVE_DAGER;
    }

    public static function savnerDager(): int
    {
        $v = self::les('oppfolging_savner_dager');
        return is_numeric($v) ? max(1, min(365, (int) $v)) : self::STD_SAVNER_DAGER;
    }

    /** Mobilnummeret SMS-en til verkstedet går til (Innstillinger › Varsler). Tom = ingen SMS. */
    public static function adminMobil(): string
    {
        return trim((string) (self::les('oppfolging_admin_mobil') ?? ''));
    }

    /** @param array{proveDager?:int,savnerDager?:int,adminMobil?:string} $ny */
    public static function lagreInnstillinger(array $ny, ?int $av = null): void
    {
        if (isset($ny['proveDager'])) {
            self::skriv('oppfolging_prove_dager', (string) max(1, min(28, (int) $ny['proveDager'])), $av);
        }
        if (isset($ny['savnerDager'])) {
            self::skriv('oppfolging_savner_dager', (string) max(1, min(365, (int) $ny['savnerDager'])), $av);
        }
        if (array_key_exists('adminMobil', $ny)) {
            $raa = trim((string) $ny['adminMobil']);
            if ($raa !== '' && normaliser_telefon($raa) === '') {
                throw new InvalidArgumentException('Skriv et gyldig mobilnummer.');
            }
            self::skriv('oppfolging_admin_mobil', mb_substr($raa, 0, 32), $av);
        }
    }

    /** Er malen (bryteren) på? */
    public static function malPaa(string $navn): bool
    {
        return DB::harTabell('notification_templates')
            && (int) (DB::verdi('SELECT aktiv FROM notification_templates WHERE navn = :n', ['n' => $navn]) ?? 0) === 1;
    }

    // ── Hjelpere ─────────────────────────────────────────────────────────

    /** «90011222» → «900 11 222». Andre former står som de er. */
    public static function visTelefon(?string $raa): string
    {
        $t = normaliser_telefon((string) $raa);
        if (preg_match('/^\+47(\d{3})(\d{2})(\d{3})$/', $t, $m) === 1) {
            return $m[1] . ' ' . $m[2] . ' ' . $m[3];
        }
        return trim((string) $raa);
    }

    private const UKEDAG = ['søndag', 'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag'];
    private const MANED = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];

    public static function ukedag(string $dato): string
    {
        return self::UKEDAG[(int) (new DateTimeImmutable($dato, self::oslo()))->format('w')];
    }

    /** «fredag 31. oktober» */
    public static function langDato(string $dato): string
    {
        $d = new DateTimeImmutable($dato, self::oslo());
        return self::ukedag($dato) . ' ' . (int) $d->format('j') . '. ' . self::MANED[(int) $d->format('n') - 1];
    }

    /** «2. oktober» */
    public static function dagMaaned(string $dato): string
    {
        $d = new DateTimeImmutable($dato, self::oslo());
        return (int) $d->format('j') . '. ' . self::MANED[(int) $d->format('n') - 1];
    }

    /** «31.10» */
    public static function kortDato(string $dato): string
    {
        $d = new DateTimeImmutable($dato, self::oslo());
        return $d->format('d.m');
    }

    /** Starten av dagen i Oslo, som UTC-tid for created_at-kolonnene. */
    private static function dagStartUtc(DateTimeImmutable $naa): string
    {
        return $naa->setTimezone(self::oslo())->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** id → siste innstempling (Y-m-d H:i:s UTC). */
    private static function sisteInn(array $ider): array
    {
        if ($ider === []) {
            return [];
        }
        $ut = [];
        foreach (DB::alle(
            'SELECT member_id, MAX(inn_tid) AS sist FROM check_ins WHERE member_id IN ('
            . implode(',', array_map('intval', $ider)) . ') GROUP BY member_id'
        ) as $r) {
            $ut[(int) $r['member_id']] = (string) $r['sist'];
        }
        return $ut;
    }

    /** Medlemmer med en godkjent frys som dekker i dag. @return array<int,true> */
    private static function frosne(): array
    {
        if (!DB::harTabell('medlem_frys')) {
            return [];
        }
        return array_fill_keys(array_map('intval', array_column(DB::alle(
            "SELECT DISTINCT member_id FROM medlem_frys
              WHERE status = 'godkjent' AND fra_dato <= CURDATE() AND til_dato >= CURDATE()"
        ), 'member_id')), true);
    }

    /** Siste «Snakket med» per medlem for en sak. @return array<int,string> id → created_at */
    private static function sistSnakket(string $sak): array
    {
        if (!DB::harTabell('medlem_oppfolging')) {
            return [];
        }
        $ut = [];
        foreach (DB::alle(
            'SELECT member_id, MAX(created_at) AS sist FROM medlem_oppfolging WHERE sak = :s GROUP BY member_id',
            ['s' => $sak]
        ) as $r) {
            $ut[(int) $r['member_id']] = (string) $r['sist'];
        }
        return $ut;
    }

    // ── 2: Prøv Lissom slutter snart ────────────────────────────────────

    /**
     * Prøvemedlemmene som slutter innen N dager (i dag teller med), soonest
     * først. Ikke de som har valgt medlemskap (aktiv avtale på en løpende
     * plan), og ikke de det er «snakket med» i denne perioden.
     *
     * @return list<array{id:int,navn:string,telefon:string,epost:string,slutt:string,dagerIgjen:int,bruktMin:int,timer:int|float|null}>
     */
    public static function proveSlutterSnart(?int $dager = null, ?DateTimeImmutable $naa = null, bool $medSnakket = false): array
    {
        $dager ??= self::proveDager();
        $naa ??= new DateTimeImmutable('now', self::oslo());
        $idag = $naa->setTimezone(self::oslo())->format('Y-m-d');
        $engangs = array_column(DB::alle('SELECT navn FROM membership_plans WHERE engangs = 1'), 'navn');
        if ($engangs === []) {
            return [];
        }
        $rader = DB::alle(
            "SELECT m.* FROM members m
              WHERE m.status IN ('prove', 'aktiv') AND m.rolle <> 'admin'
                AND m.medlemskap_type IN (" . implode(',', array_fill(0, count($engangs), '?')) . ")
                AND NOT EXISTS (SELECT 1 FROM subscriptions s
                                  JOIN membership_plans p ON p.navn = s.plan AND p.engangs = 0
                                 WHERE s.member_id = m.id AND s.status = 'aktiv')",
            array_values($engangs)
        );
        $snakket = $medSnakket ? [] : self::sistSnakket('prove');
        $ut = [];
        foreach ($rader as $m) {
            $slutt = trim((string) ($m['slutt_dato'] ?? ''));
            if ($slutt === '') {
                $start = trim((string) ($m['start_dato'] ?? ''));
                if ($start === '') {
                    continue;
                }
                $slutt = Medlemskap::proveSlutt($start);
            }
            $slutt = substr($slutt, 0, 10);
            if ($slutt < $idag) {
                continue; // perioden er over (Medlemskap::proveSluttet)
            }
            $igjen = (int) (new DateTimeImmutable($idag, self::oslo()))->diff(new DateTimeImmutable($slutt, self::oslo()))->format('%a');
            if ($igjen > $dager) {
                continue;
            }
            $id = (int) $m['id'];
            // «Snakket med» i denne perioden (etter at prøven startet).
            $start = substr((string) ($m['start_dato'] ?? ''), 0, 10);
            if (isset($snakket[$id]) && substr($snakket[$id], 0, 10) >= $start) {
                continue;
            }
            $ut[] = [
                'id'         => $id,
                'navn'       => (string) $m['navn'],
                'telefon'    => (string) ($m['telefon'] ?? ''),
                'epost'      => (string) ($m['epost'] ?? ''),
                'slutt'      => $slutt,
                'dagerIgjen' => $igjen,
                'bruktMin'   => Stempling::minutterDenneManeden($id),
                'timer'      => Medlemskap::timerMedGaver($m),
            ];
        }
        usort($ut, static fn($a, $b) => strcmp($a['slutt'], $b['slutt']) ?: strcmp($a['navn'], $b['navn']));
        return $ut;
    }

    /**
     * Den samlede SMS-en til admin-mobilen kl. 09: de som er nye på lista
     * siden forrige SMS. Én per dag.
     *
     * @return int antall personer i SMS-en
     */
    public static function sendProveAdmin(?DateTimeImmutable $naa = null): int
    {
        $naa = ($naa ?? new DateTimeImmutable('now', self::oslo()))->setTimezone(self::oslo());
        $time = (int) $naa->format('G');
        if ($time < 9 || $time >= 12 || !self::malPaa(self::MAL_ADMIN) || !DB::harTabell('innstillinger')) {
            return 0;
        }
        $idag = $naa->format('Y-m-d');
        if (self::les('oppfolging_prove_admin_dag') === $idag) {
            return 0;
        }
        $mobil = self::adminMobil();
        if ($mobil === '' || normaliser_telefon($mobil) === '' || !Varsel::smsMulig()) {
            return 0;
        }
        $liste = self::proveSlutterSnart(null, $naa, true);
        $raa = self::les('oppfolging_prove_admin_meldt');
        $meldt = is_string($raa) ? (json_decode($raa, true) ?: []) : [];
        $nye = array_values(array_filter($liste, static fn($p) => !in_array($p['id'] . ':' . $p['slutt'], $meldt, true)));
        if ($nye === []) {
            return 0;
        }
        $datoer = array_values(array_unique(array_map(static fn($p) => self::kortDato($p['slutt']), $nye)));
        $sendt = Varsel::mal(self::MAL_ADMIN, ['telefon' => $mobil], [
            'dato'   => implode(' og ', $datoer),
            'antall' => (string) count($nye),
            'liste'  => implode(', ', array_map(
                static fn($p) => trim($p['navn'] . ' ' . self::visTelefon($p['telefon'])),
                $nye
            )),
        ], 'prove_slutter_admin', (int) $naa->format('Ymd'));
        if ($sendt <= 0) {
            return 0;
        }
        $alle = array_values(array_unique(array_merge(
            array_slice($meldt, -500),
            array_map(static fn($p) => $p['id'] . ':' . $p['slutt'], $nye)
        )));
        self::skriv('oppfolging_prove_admin_meldt', (string) json_encode($alle));
        self::skriv('oppfolging_prove_admin_dag', $idag);
        return count($nye);
    }

    // ── 4: SMS til prøvemedlemmet selv ───────────────────────────────────

    /** @return int antall SMS lagt i kø */
    public static function sendProveMedlem(?DateTimeImmutable $naa = null): int
    {
        $naa = ($naa ?? new DateTimeImmutable('now', self::oslo()))->setTimezone(self::oslo());
        $time = (int) $naa->format('G');
        if ($time < 10 || $time >= 18 || !self::malPaa(self::MAL_PROVE) || !Varsel::smsMulig()) {
            return 0;
        }
        $n = 0;
        foreach (self::proveSlutterSnart(self::PROVE_MEDLEM_DAGER, $naa, true) as $p) {
            if ($p['dagerIgjen'] < 1 || normaliser_telefon($p['telefon']) === '') {
                continue;
            }
            $start = (string) DB::verdi('SELECT start_dato FROM members WHERE id = :i', ['i' => $p['id']]);
            $fra = $start !== '' ? $start . ' 00:00:00' : '1970-01-01 00:00:00';
            if (DB::verdi(
                "SELECT id FROM notifications WHERE mal = :m AND ref_type = 'prove_slutter' AND ref_id = :i
                    AND status IN ('ko', 'sendt') AND created_at >= :fra LIMIT 1",
                ['m' => self::MAL_PROVE, 'i' => $p['id'], 'fra' => $fra]
            ) !== null) {
                continue;
            }
            $n += Varsel::mal(self::MAL_PROVE, ['navn' => $p['navn'], 'telefon' => $p['telefon']], [
                'navn' => $p['navn'],
                'dag'  => self::ukedag($p['slutt']),
                'dato' => self::kortDato($p['slutt']),
            ], 'prove_slutter', $p['id']) > 0 ? 1 : 0;
        }
        return $n;
    }

    // ── 3: SMS til medlemmer som ikke kommer ─────────────────────────────

    /** Siste påminnelse-SMS per medlem: id → [antall, sist (UTC)]. */
    public static function savnerSendt(array $ider = []): array
    {
        $hvor = $ider === [] ? '' : ' AND ref_id IN (' . implode(',', array_map('intval', $ider)) . ')';
        $ut = [];
        foreach (DB::alle(
            "SELECT ref_id, COUNT(*) AS n, MAX(created_at) AS sist FROM notifications
              WHERE ref_type = 'savner_deg' AND status IN ('ko', 'sendt'){$hvor}
              GROUP BY ref_id"
        ) as $r) {
            $ut[(int) $r['ref_id']] = ['antall' => (int) $r['n'], 'sist' => (string) $r['sist']];
        }
        return $ut;
    }

    /**
     * Medlemmene som skal ha påminnelsen nå (uten tidsvinduet).
     *
     * @return list<array<string,mixed>>
     */
    public static function savnerKandidater(?DateTimeImmutable $naa = null): array
    {
        if (!self::klar()) {
            return [];
        }
        $naa = ($naa ?? new DateTimeImmutable('now', self::oslo()))->setTimezone(self::oslo());
        $idag = new DateTimeImmutable($naa->format('Y-m-d'), self::oslo());
        $dager = self::savnerDager();
        $rader = DB::alle(
            "SELECT m.* FROM members m
               LEFT JOIN membership_plans p ON p.navn = m.medlemskap_type
              WHERE m.status = 'aktiv' AND m.rolle <> 'admin' AND m.paaminnelse_sms = 1
                AND COALESCE(p.engangs, 0) = 0
                AND m.telefon IS NOT NULL AND m.telefon <> ''"
        );
        $ider = array_map(static fn($m) => (int) $m['id'], $rader);
        $sist = self::sisteInn($ider);
        $sendt = self::savnerSendt($ider);
        $frosne = self::frosne();
        $dagStart = self::dagStartUtc($naa);
        $ut = [];
        foreach ($rader as $m) {
            $id = (int) $m['id'];
            if (isset($frosne[$id]) || normaliser_telefon((string) $m['telefon']) === '') {
                continue;
            }
            $fra = isset($sist[$id]) ? substr($sist[$id], 0, 10) : (string) ($m['start_dato'] ?? '');
            if ($fra === '') {
                continue;
            }
            $d = (int) (new DateTimeImmutable(substr($fra, 0, 10), self::oslo()))->diff($idag)->format('%r%a');
            if ($d < $dager) {
                continue;
            }
            // Én SMS per fravær: ny først etter at de har vært innom igjen.
            if (isset($sendt[$id]) && (!isset($sist[$id]) || $sist[$id] <= $sendt[$id]['sist'])) {
                continue;
            }
            // Aldri samme dag som en annen SMS fra Lissom.
            if (DB::verdi(
                "SELECT id FROM notifications WHERE kanal = 'sms' AND mottaker = :t
                    AND status IN ('ko', 'sendt') AND created_at >= :fra LIMIT 1",
                ['t' => normaliser_telefon((string) $m['telefon']), 'fra' => $dagStart]
            ) !== null) {
                continue;
            }
            $m['dager'] = $d;
            $ut[] = $m;
        }
        return $ut;
    }

    /** @return int antall SMS lagt i kø */
    public static function sendSavner(?DateTimeImmutable $naa = null): int
    {
        $naa = ($naa ?? new DateTimeImmutable('now', self::oslo()))->setTimezone(self::oslo());
        $time = (int) $naa->format('G');
        $ukedag = (int) $naa->format('N');
        if ($ukedag > 5 || $time < 10 || $time >= 18 || !self::malPaa(self::MAL_SAVNER) || !Varsel::smsMulig()) {
            return 0;
        }
        $n = 0;
        foreach (array_slice(self::savnerKandidater($naa), 0, 30) as $m) {
            $id = (int) $m['id'];
            $tak = Medlemskap::timerMedGaver($m);
            // Uten timetak (fri tilgang) kan «Du har {timer} igjen» ikke fylles
            // ut, og alt skal gå gjennom malen: de får ingen SMS (spørsmål til eieren).
            if ($tak === null) {
                continue;
            }
            $igjen = max(0, (int) round($tak * 60) - Stempling::minutterDenneManeden($id));
            $n += Varsel::mal(self::MAL_SAVNER, ['navn' => $m['navn'], 'telefon' => $m['telefon']], [
                'navn'  => (string) $m['navn'],
                'timer' => Stempling::timer($igjen) . ' ' . ($igjen === 60 ? 'time' : 'timer'),
            ], 'savner_deg', $id) > 0 ? 1 : 0;
        }
        return $n;
    }

    /** Alt det planlagte, fra cron (jobben «varsler», hvert 5. minutt). */
    public static function kjor(?DateTimeImmutable $naa = null): array
    {
        $ut = ['admin' => 0, 'prove' => 0, 'savner' => 0];
        if (!self::klar()) {
            return $ut;
        }
        foreach (['admin' => 'sendProveAdmin', 'prove' => 'sendProveMedlem', 'savner' => 'sendSavner'] as $k => $f) {
            try {
                $ut[$k] = self::$f($naa);
            } catch (Throwable $e) {
                logg_feil('Medlemsoppfølging: ' . $k, $e);
            }
        }
        return $ut;
    }

    // ── Lav aktivitet i Må gjøres ────────────────────────────────────────

    /**
     * Lav aktivitet (Aktivitet::lave, «Lav aktivitet til meg»-grensa), uten
     * frosne, uten Prøv Lissom og uten dem det er «snakket med» siden sist de
     * var innom.
     *
     * @return list<array{id:int,navn:string,telefon:string,epost:string,dager:int,sist:string}>
     */
    public static function lavAktivitet(): array
    {
        $liste = Aktivitet::lave();
        if ($liste === []) {
            return [];
        }
        $frosne = self::frosne();
        $snakket = self::sistSnakket('lav');
        $ider = array_map(static fn($m) => (int) $m['id'], $liste);
        $info = [];
        foreach (DB::alle(
            'SELECT m.id, m.start_dato, COALESCE(p.engangs, 0) AS engangs FROM members m
               LEFT JOIN membership_plans p ON p.navn = m.medlemskap_type
              WHERE m.id IN (' . implode(',', $ider) . ')'
        ) as $r) {
            $info[(int) $r['id']] = $r;
        }
        $ut = [];
        foreach ($liste as $m) {
            $id = (int) $m['id'];
            if (isset($frosne[$id]) || (int) ($info[$id]['engangs'] ?? 0) === 1) {
                continue;
            }
            $fra = $m['sist'] !== '' ? $m['sist'] : (string) ($info[$id]['start_dato'] ?? '');
            if (isset($snakket[$id]) && substr($snakket[$id], 0, 10) >= substr($fra, 0, 10)) {
                continue;
            }
            $ut[] = $m;
        }
        return $ut;
    }

    // ── 7: Snakket med ───────────────────────────────────────────────────

    public static function snakketMed(int $medlemId, string $sak, string $notat, ?int $av): int
    {
        if (!in_array($sak, ['prove', 'lav'], true)) {
            throw new InvalidArgumentException('Ukjent sak.');
        }
        return DB::settInn('medlem_oppfolging', [
            'member_id' => $medlemId,
            'sak'       => $sak,
            'notat'     => mb_substr(trim($notat), 0, 1000) ?: null,
            'av'        => $av,
        ]);
    }

    /** @return list<array{sak:string,notat:string,av:string,dato:string}> */
    public static function notater(int $medlemId): array
    {
        if (!DB::harTabell('medlem_oppfolging')) {
            return [];
        }
        return array_map(static fn($r) => [
            'sak'   => (string) $r['sak'],
            'notat' => (string) ($r['notat'] ?? ''),
            'av'    => (string) (preg_split('/\s+/u', trim((string) ($r['avnavn'] ?? '')))[0] ?? ''),
            'dato'  => (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->setTimezone(self::oslo())->format('d.m'),
        ], DB::alle(
            'SELECT o.sak, o.notat, o.created_at, a.navn AS avnavn FROM medlem_oppfolging o
               LEFT JOIN members a ON a.id = o.av
              WHERE o.member_id = :m ORDER BY o.created_at DESC, o.id DESC LIMIT 20',
            ['m' => $medlemId]
        ));
    }

    // ── 6: Ble prøvemedlemmene medlem? ───────────────────────────────────

    /**
     * Av dem som startet Prøv Lissom i vinduet: hvor mange står nå på et
     * løpende medlemskap. Vinduet er de siste 3 månedene; «forrige» er de
     * 3 månedene før det.
     *
     * @return array{ble:int,av:int,prosent:?int,forrige:?int}
     */
    public static function bleMedlem(?DateTimeImmutable $naa = null): array
    {
        $naa = ($naa ?? new DateTimeImmutable('now', self::oslo()))->setTimezone(self::oslo());
        $fra = $naa->modify('-3 months')->format('Y-m-d');
        $forFra = $naa->modify('-6 months')->format('Y-m-d');
        $tell = static function (string $fra, string $til): array {
            // Prøveperioder: en engangsavtale som ble aktiv/betalt, eller et
            // medlem som står på engangsplanen (satt for hånd).
            $ider = array_map('intval', array_column(DB::alle(
                "SELECT DISTINCT s.member_id FROM subscriptions s
                   JOIN membership_plans p ON p.navn = s.plan AND p.engangs = 1
                  WHERE DATE(s.created_at) >= :fra AND DATE(s.created_at) < :til
                    AND (s.status IN ('aktiv', 'utlopt', 'stoppet')
                         OR EXISTS (SELECT 1 FROM payments b WHERE b.subscription_id = s.id AND b.status = 'betalt'))
                 UNION
                 SELECT m.id FROM members m
                   JOIN membership_plans p ON p.navn = m.medlemskap_type AND p.engangs = 1
                  WHERE m.start_dato >= :fra2 AND m.start_dato < :til2",
                ['fra' => $fra, 'til' => $til, 'fra2' => $fra, 'til2' => $til]
            ), 'member_id'));
            $ider = array_values(array_unique($ider));
            if ($ider === []) {
                return [0, 0];
            }
            $ble = (int) DB::verdi(
                "SELECT COUNT(*) FROM members m
                   JOIN membership_plans p ON p.navn = m.medlemskap_type AND p.engangs = 0
                  WHERE m.id IN (" . implode(',', $ider) . ") AND m.status IN ('aktiv', 'pause')"
            );
            return [$ble, count($ider)];
        };
        $imorgen = $naa->modify('+1 day')->format('Y-m-d');
        [$ble, $av] = $tell($fra, $imorgen);
        [$fBle, $fAv] = $tell($forFra, $fra);
        return [
            'ble'     => $ble,
            'av'      => $av,
            'prosent' => $av > 0 ? (int) round($ble * 100 / $av) : null,
            'forrige' => $fAv > 0 ? (int) round($fBle * 100 / $fAv) : null,
        ];
    }
}
