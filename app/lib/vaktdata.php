<?php
declare(strict_types=1);

/**
 * Datasjekkene vakta kjoerer hver morgen — se api/vakt-data.php.
 *
 * Eieren, 29. september 2026: «hvorfor fanges det ikke opp?» — Johanna sto
 * med Prøv Lissom etter at hun hadde betalt Mini 15, og et kurs betalt i
 * verkstedet manglet i dagens omsetning. Her staar reglene, saa testene og
 * vakta leser de samme.
 */
final class Vaktdata
{
    /** @return array{medlemmer:int,salg:int,avvik:list<string>} */
    public static function sjekk(): array
    {
        $avvik = [];

        // ── Medlemmene ─────────────────────────────────────────────────────────
        $aktive = [];
        foreach (DB::alle(
            "SELECT s.member_id, s.id, s.plan, m.navn, m.medlemskap_type
               FROM subscriptions s
               JOIN members m ON m.id = s.member_id
              WHERE s.status = 'aktiv' AND m.anonymisert_at IS NULL"
        ) as $r) {
            $aktive[(int) $r['member_id']][] = $r;
        }
        foreach ($aktive as $id => $rader) {
            $navn = (string) $rader[0]['navn'];
            if (count($rader) > 1) {
                $avvik[] = 'Medlem ' . $id . ' (' . $navn . ') har ' . count($rader) . ' aktive avtaler: '
                    . implode(', ', array_map(static fn($r) => '#' . $r['id'] . ' ' . $r['plan'], $rader));
            }
            $vist = trim((string) ($rader[0]['medlemskap_type'] ?? ''));
            $planer = array_map(static fn($r) => trim((string) $r['plan']), $rader);
            if (!in_array($vist, $planer, true)) {
                $avvik[] = 'Medlem ' . $id . ' (' . $navn . ') vises som «' . ($vist ?: 'ingen') . '», men den aktive avtalen er «'
                    . implode('», «', $planer) . '»';
            }
        }

        // ── Salgene i dag og i gaar ────────────────────────────────────────────
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $idag = (new DateTimeImmutable('now', $oslo))->setTime(0, 0);
        $fra  = $idag->modify('-1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $til  = $idag->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

        $rader  = Omsetning::rader($fra, $til);
        $betId  = [];
        $manuel = [];
        foreach ($rader as $r) {
            if (is_string($r['id']) && str_starts_with($r['id'], 'b')) {
                $manuel[(int) substr($r['id'], 1)] = true;
            } else {
                $betId[(int) $r['id']] = true;
            }
        }

        $uten = Booking::MAATER_UTEN_PENGER;
        $plass = implode(',', array_fill(0, count($uten), '?'));
        foreach (DB::alle(
            "SELECT b.id, b.payment_id, b.belop_ore, c.tittel
               FROM bookings b
               JOIN courses c ON c.id = b.course_id
              WHERE b.status = 'betalt' AND b.belop_ore > 0
                AND (b.betalt_maate IS NULL OR b.betalt_maate NOT IN ({$plass}))
                AND b.created_at >= ? AND b.created_at < ?",
            array_merge($uten, [$fra, $til])
        ) as $b) {
            $med = $b['payment_id'] !== null ? isset($betId[(int) $b['payment_id']]) : isset($manuel[(int) $b['id']]);
            if (!$med) {
                $avvik[] = 'Betalt påmelding ' . $b['id'] . ' (' . $b['tittel'] . ', ' . Booking::kroner((int) $b['belop_ore'])
                    . ') er ikke med i omsetningen';
            }
        }

        return [
            'medlemmer' => count($aktive),
            'salg'      => count($rader),
            'avvik'     => $avvik,
            'funn'      => self::regler(),
        ];
    }

    // ══ Reglene L4–L13 ════════════════════════════════════════════════════
    //
    // Eieren, 2. oktober 2026: ja til daglig datasjekk. Reglene under leser
    // bare — de skriver aldri til basen, og de spoer aldri Vipps. Hvert funn
    // har bare navn, intern id, beloep, dato og regel: aldri e-post, telefon,
    // adresse eller notater.
    //
    // «avvik» over staar som foer (bin/vakt.mjs leser dem); disse kommer i
    // «funn».

    /** Regelnavn => metoden som kjoerer den. @var array<string,string> */
    private const REGLER = [
        'status_uenig'                => 'statusUenig',
        'forste_betaling_kort_periode' => 'forsteBetalingKortPeriode',
        'prove_utlopt'                => 'proveUtlopt',
        'trekk_henger'                => 'trekkHenger',
        'dobbel_betaling_periode'     => 'dobbelBetalingPeriode',
        'betalt_ikke_aktiv'           => 'betaltIkkeAktiv',
        'mangler_avtale'              => 'manglerAvtale',
        'autorisert_ikke_trukket'     => 'autorisertIkkeTrukket',
        'booking_betaling_uenig'      => 'bookingBetalingUenig',
        'gavekort_saldo'              => 'gavekortSaldo',
    ];

    /**
     * Alle reglene. En regel som krasjer, stopper ikke de andre — den blir
     * selv et funn, saa vakta ser at noe ikke ble sjekket.
     *
     * @return list<array{regel:string,id:int,navn:string,tekst:string}>
     */
    public static function regler(): array
    {
        $funn = [];
        foreach (self::REGLER as $regel => $metode) {
            try {
                foreach (self::$metode() as $f) {
                    $funn[] = $f;
                }
            } catch (Throwable $e) {
                logg_feil('Vaktdata: regelen ' . $regel . ' feilet', $e);
                $funn[] = self::funn($regel, 0, '', 'Regelen kunne ikke kjøres');
            }
        }
        return $funn;
    }

    /** @return array{regel:string,id:int,navn:string,tekst:string} */
    private static function funn(string $regel, int $id, string $navn, string $tekst): array
    {
        return ['regel' => $regel, 'id' => $id, 'navn' => $navn, 'tekst' => $tekst];
    }

    private static function oslo(): DateTimeZone
    {
        return new DateTimeZone('Europe/Oslo');
    }

    private static function idag(): string
    {
        return (new DateTimeImmutable('now', self::oslo()))->format('Y-m-d');
    }

    /** Datoen (Y-m-d, Oslo) et UTC-tidspunkt fra basen faller paa. */
    private static function osloDato(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(self::oslo())->format('Y-m-d');
    }

    /**
     * Hele virkedager (man–fre) fra dagen etter $utc til og med i dag.
     * Helligdager regnes som virkedager — det gir heller ett funn for mye
     * enn ett for lite.
     */
    public static function virkedagerSiden(string $utc, ?string $idag = null): int
    {
        $dag = new DateTimeImmutable(self::osloDato($utc), self::oslo());
        $slutt = new DateTimeImmutable($idag ?? self::idag(), self::oslo());
        $n = 0;
        while ($dag < $slutt) {
            $dag = $dag->modify('+1 day');
            if ((int) $dag->format('N') <= 5) {
                $n++;
            }
        }
        return $n;
    }

    /** Uten timepakker: de er ikke betaling for selve medlemskapet. */
    private static function utenTimepakke(string $alias = 'p'): string
    {
        return DB::harTabell('timepakker')
            ? "AND NOT EXISTS (SELECT 1 FROM timepakker tp WHERE tp.payment_id = {$alias}.id)"
            : '';
    }

    private static function gjelderFra(string $alias = 'p'): string
    {
        return DB::harKolonne('payments', 'gjelder_fra') ? "{$alias}.gjelder_fra" : 'NULL AS gjelder_fra';
    }

    /** Er planen paa betalingen en engangsplan (Prøv Lissom)? */
    private static function erEngangsPlan(?string $plan): bool
    {
        $p = $plan === null || trim($plan) === '' ? null : Medlemskap::planUansett(trim($plan));
        return $p !== null && (int) ($p['engangs'] ?? 0) === 1;
    }

    // ── L4 status_uenig ──────────────────────────────────────────────────
    //
    // harBetaltPeriode() (tilgangen) og betalingsstatus() (merket i admin)
    // skal si det samme om samme medlem. «bestilt» er et trekk paa vei, og
    // regnes ikke som uenighet — det fanges av L7 om det blir haengende.
    private static function statusUenig(): array
    {
        $medlemmer = DB::alle(
            "SELECT id, navn, status, medlemskap_type, start_dato, slutt_dato, betaler_ikke, betaler_ikke_grunn
               FROM members
              WHERE status IN ('prove','aktiv','pause') AND anonymisert_at IS NULL"
        );
        if ($medlemmer === []) {
            return [];
        }
        $ider = array_map(static fn(array $m): int => (int) $m['id'], $medlemmer);
        $siste = Medlemskap::sisteBetalinger($ider);
        $avtaler = [];
        $inn = implode(',', $ider);
        foreach (DB::alle(
            "SELECT s.id, s.member_id, s.plan, s.pris_ore, s.vipps_agreement_id,
                    s.neste_trekk, s.siste_trekk, s.status
               FROM subscriptions s
               JOIN (SELECT member_id, MAX(id) AS siste FROM subscriptions
                      WHERE member_id IN ({$inn}) GROUP BY member_id) n ON n.siste = s.id"
        ) as $r) {
            $avtaler[(int) $r['member_id']] = $r;
        }
        $trekkene = Medlemskap::sisteTrekk(array_map(static fn(array $a): int => (int) $a['id'], $avtaler));

        $ut = [];
        foreach ($medlemmer as $m) {
            $id = (int) $m['id'];
            $a = $avtaler[$id] ?? null;
            $b = Medlemskap::betalingsstatus($m, $a, $siste[$id] ?? null,
                $a === null ? null : ($trekkene[(int) $a['id']] ?? null));
            if ($b['tilstand'] === 'bestilt') {
                continue;
            }
            $merket = in_array($b['tilstand'], ['betalt', 'fri'], true);
            $tilgang = Medlemskap::harBetaltPeriode($m);
            if ($merket !== $tilgang) {
                $ut[] = self::funn('status_uenig', $id, (string) $m['navn'],
                    'Admin viser «' . ($merket ? 'betalt' : $b['tilstand']) . '», men tilgangen sier '
                    . ($tilgang ? 'betalt periode' : 'ingen betalt periode'));
            }
        }
        return $ut;
    }

    // ── L5 forste_betaling_kort_periode ──────────────────────────────────
    //
    // Foerste medlemsbetaling uten gjelder_fra, betalt i maanedens siste sju
    // dager: da dekker den bare dagene ut maaneden. Saken 29. september:
    // nytt medlemskap ble telt for september. Engangsplanen (Prøv Lissom)
    // gjelder ut kjoepsmaaneden med vilje og er utenfor. Bare de siste fem
    // ukene, saa gamle saker ikke ligger roedt for alltid.
    private static function forsteBetalingKortPeriode(): array
    {
        $idag = self::idag();
        $grense = (new DateTimeImmutable($idag, self::oslo()))->modify('-35 days')->format('Y-m-d');
        $ut = [];
        foreach (DB::alle(
            "SELECT p.id, p.member_id, p.belop_ore, p.created_at, " . self::gjelderFra() . ",
                    m.navn, m.medlemskap_type, s.plan
               FROM payments p
               JOIN members m ON m.id = p.member_id AND m.anonymisert_at IS NULL
               LEFT JOIN subscriptions s ON s.id = p.subscription_id
              WHERE p.formal = 'medlemskap' AND p.status IN ('betalt','delvis_refundert')
                AND p.annullert_at IS NULL " . self::utenTimepakke() . "
                AND p.id = (SELECT MIN(p2.id) FROM payments p2
                             WHERE p2.member_id = p.member_id AND p2.formal = 'medlemskap'
                               AND p2.status IN ('betalt','delvis_refundert') AND p2.annullert_at IS NULL
                               " . self::utenTimepakke('p2') . ")"
        ) as $p) {
            if (trim((string) ($p['gjelder_fra'] ?? '')) !== '') {
                continue;
            }
            if (self::erEngangsPlan($p['plan'] ?? $p['medlemskap_type'])) {
                continue;
            }
            $betalt = self::osloDato((string) $p['created_at']);
            $til = Medlemskap::dekkerTil($p);
            if ($til < $grense) {
                continue;
            }
            $dagerIgjen = (int) (new DateTimeImmutable($betalt, self::oslo()))
                ->diff(new DateTimeImmutable($til, self::oslo()))->days;
            $sisteUke = (int) (new DateTimeImmutable($betalt, self::oslo()))->format('j')
                > (int) (new DateTimeImmutable($betalt, self::oslo()))->format('t') - 7;
            if ($sisteUke && $dagerIgjen <= 7) {
                $ut[] = self::funn('forste_betaling_kort_periode', (int) $p['member_id'], (string) $p['navn'],
                    'Første betaling ' . $p['id'] . ' (' . Booking::kroner((int) $p['belop_ore']) . ') ' . $betalt
                    . ' dekker bare til ' . $til . ' — ' . $dagerIgjen . ' dager');
            }
        }
        return $ut;
    }

    // ── L6 prove_utlopt ──────────────────────────────────────────────────
    private static function proveUtlopt(): array
    {
        $idag = self::idag();
        $ut = [];
        foreach (DB::alle(
            "SELECT id, navn, start_dato, slutt_dato FROM members
              WHERE status = 'prove' AND anonymisert_at IS NULL"
        ) as $m) {
            $slutt = trim((string) ($m['slutt_dato'] ?? ''));
            if ($slutt === '') {
                $start = trim((string) ($m['start_dato'] ?? ''));
                if ($start === '') {
                    continue;
                }
                $slutt = Medlemskap::proveSlutt($start);
            }
            if ($slutt < $idag) {
                $ut[] = self::funn('prove_utlopt', (int) $m['id'], (string) $m['navn'],
                    'Står fortsatt på prøve, men prøveperioden sluttet ' . $slutt);
            }
        }
        return $ut;
    }

    // ── L7 trekk_henger ──────────────────────────────────────────────────
    private static function trekkHenger(): array
    {
        $ut = [];
        foreach (DB::alle(
            "SELECT p.id, p.member_id, p.belop_ore, p.status, p.created_at, m.navn
               FROM payments p
               LEFT JOIN members m ON m.id = p.member_id
              WHERE p.type = 'recurring_charge' AND p.status IN ('opprettet','venter')
                AND p.annullert_at IS NULL
                AND p.created_at < UTC_TIMESTAMP() - INTERVAL 5 DAY"
        ) as $p) {
            $dager = self::virkedagerSiden((string) $p['created_at']);
            if ($dager > 5) {
                $ut[] = self::funn('trekk_henger', (int) $p['id'], (string) ($p['navn'] ?? ''),
                    'Trekk ' . $p['id'] . ' (' . Booking::kroner((int) $p['belop_ore']) . ') har stått «'
                    . $p['status'] . '» siden ' . self::osloDato((string) $p['created_at'])
                    . ' — ' . $dager . ' virkedager');
            }
        }
        return $ut;
    }

    // ── L8 dobbel_betaling_periode ───────────────────────────────────────
    //
    // To betalte medlemskapsbetalinger for samme medlem og samme maaned.
    // Timepakker er utenfor, og det er Prøv Lissom ogsaa: et nytt medlemskap
    // erstatter den og gjelder alt samme maaned (Medlemskap::erstattProve).
    // Bare de siste 90 dagene.
    private static function dobbelBetalingPeriode(): array
    {
        $grupper = [];
        foreach (DB::alle(
            "SELECT p.id, p.member_id, p.belop_ore, p.created_at, " . self::gjelderFra() . ",
                    m.navn, m.medlemskap_type, s.plan
               FROM payments p
               JOIN members m ON m.id = p.member_id AND m.anonymisert_at IS NULL
               LEFT JOIN subscriptions s ON s.id = p.subscription_id
              WHERE p.formal = 'medlemskap' AND p.status IN ('betalt','delvis_refundert')
                AND p.annullert_at IS NULL " . self::utenTimepakke() . "
                AND p.created_at >= UTC_TIMESTAMP() - INTERVAL 90 DAY
              ORDER BY p.id"
        ) as $p) {
            if (self::erEngangsPlan($p['plan'] ?? null)) {
                continue;
            }
            $fra = trim((string) ($p['gjelder_fra'] ?? ''));
            $mnd = substr($fra !== '' ? $fra : self::osloDato((string) $p['created_at']), 0, 7);
            $grupper[(int) $p['member_id'] . '|' . $mnd][] = $p;
        }
        $ut = [];
        foreach ($grupper as $nokkel => $rader) {
            if (count($rader) < 2) {
                continue;
            }
            $mnd = explode('|', $nokkel)[1];
            $ut[] = self::funn('dobbel_betaling_periode', (int) $rader[0]['member_id'], (string) $rader[0]['navn'],
                count($rader) . ' betalinger for ' . $mnd . ': '
                . implode(', ', array_map(static fn(array $r): string => '#' . $r['id'] . ' '
                    . Booking::kroner((int) $r['belop_ore']) . ' ' . self::osloDato((string) $r['created_at']), $rader)));
        }
        return $ut;
    }

    // ── L9 betalt_ikke_aktiv ─────────────────────────────────────────────
    //
    // Betalt de siste 35 dagene, men medlemmet staar ikke som prove, aktiv
    // eller pause. En som har sagt opp og fortsatt har betalt tid igjen, er
    // i orden.
    private static function betaltIkkeAktiv(): array
    {
        $idag = self::idag();
        $ut = [];
        $sett = [];
        foreach (DB::alle(
            "SELECT p.id, p.member_id, p.belop_ore, p.created_at, " . self::gjelderFra() . ",
                    m.navn, m.status, m.slutt_dato, s.plan
               FROM payments p
               JOIN members m ON m.id = p.member_id AND m.anonymisert_at IS NULL
               LEFT JOIN subscriptions s ON s.id = p.subscription_id
              WHERE p.formal = 'medlemskap' AND p.status IN ('betalt','delvis_refundert')
                AND p.annullert_at IS NULL " . self::utenTimepakke() . "
                AND p.created_at >= UTC_TIMESTAMP() - INTERVAL 35 DAY
                AND m.status NOT IN ('prove','aktiv','pause')
              ORDER BY p.id DESC"
        ) as $p) {
            $id = (int) $p['member_id'];
            if (isset($sett[$id])) {
                continue;
            }
            $sett[$id] = true;
            if ((string) $p['status'] === 'oppsagt') {
                $til = self::erEngangsPlan($p['plan'] ?? null)
                    ? (trim((string) ($p['slutt_dato'] ?? '')) ?: Medlemskap::proveSlutt(self::osloDato((string) $p['created_at'])))
                    : Medlemskap::dekkerTil($p);
                // dekkerTil er foerste dag ETTER perioden; proveSlutt er siste dag I den.
                $igjen = self::erEngangsPlan($p['plan'] ?? null) ? $til >= $idag : $til > $idag;
                if ($igjen) {
                    continue;
                }
            }
            $ut[] = self::funn('betalt_ikke_aktiv', $id, (string) $p['navn'],
                'Betalt ' . Booking::kroner((int) $p['belop_ore']) . ' ' . self::osloDato((string) $p['created_at'])
                . ' (betaling ' . $p['id'] . '), men står som «' . $p['status'] . '»');
        }
        return $ut;
    }

    // ── L10 mangler_avtale ───────────────────────────────────────────────
    private static function manglerAvtale(): array
    {
        $ut = [];
        foreach (DB::alle(
            "SELECT m.id, m.navn, m.medlemskap_type FROM members m
              WHERE m.status = 'aktiv' AND m.anonymisert_at IS NULL AND m.betaler_ikke = 0
                AND NOT EXISTS (SELECT 1 FROM subscriptions s
                                 WHERE s.member_id = m.id AND s.status = 'aktiv'
                                   AND s.vipps_agreement_id IS NOT NULL AND TRIM(s.vipps_agreement_id) <> '')"
        ) as $m) {
            $plan = Medlemskap::planUansett((string) ($m['medlemskap_type'] ?? ''));
            if ($plan !== null && Medlemskap::kreverFastTrekk($plan)) {
                $ut[] = self::funn('mangler_avtale', (int) $m['id'], (string) $m['navn'],
                    $plan['navn'] . ' krever fast trekk, men det finnes ingen aktiv Vipps-avtale');
            }
        }
        return $ut;
    }

    // ── L11 autorisert_ikke_trukket ──────────────────────────────────────
    private static function autorisertIkkeTrukket(): array
    {
        $ut = [];
        foreach (DB::alle(
            "SELECT p.id, p.belop_ore, p.formal, p.created_at,
                    COALESCE(m.navn, (SELECT b.gjest_navn FROM bookings b WHERE b.payment_id = p.id LIMIT 1), '') AS navn
               FROM payments p
               LEFT JOIN members m ON m.id = p.member_id
              WHERE p.status = 'autorisert' AND p.annullert_at IS NULL
                AND p.created_at < UTC_TIMESTAMP() - INTERVAL 3 DAY"
        ) as $p) {
            $dager = self::virkedagerSiden((string) $p['created_at']);
            if ($dager > 3) {
                $ut[] = self::funn('autorisert_ikke_trukket', (int) $p['id'], (string) $p['navn'],
                    'Betaling ' . $p['id'] . ' (' . $p['formal'] . ', ' . Booking::kroner((int) $p['belop_ore'])
                    . ') autorisert ' . self::osloDato((string) $p['created_at']) . ', ikke trukket etter '
                    . $dager . ' virkedager');
            }
        }
        return $ut;
    }

    // ── L12 booking_betaling_uenig ───────────────────────────────────────
    //
    // Betalingen er betalt, men paameldingen staar fortsatt som reservert —
    // eller paameldingen staar som betalt, men ingen betaling paa den er
    // betalt. En paamelding kan ha flere betalinger (delt betaling); det
    // holder at én er betalt. Manuelle paameldinger uten betaling er utenfor.
    private static function bookingBetalingUenig(): array
    {
        $ut = [];
        $ok = "'autorisert','betalt','delvis_refundert'";
        foreach (DB::alle(
            "SELECT DISTINCT b.id, b.gjest_navn, b.belop_ore, b.status, m.navn
               FROM bookings b
               LEFT JOIN members m ON m.id = b.member_id
               JOIN payments p ON (p.id = b.payment_id OR p.booking_id = b.id)
              WHERE b.status = 'reservert' AND p.formal = 'booking'
                AND p.status IN ({$ok}) AND p.annullert_at IS NULL"
        ) as $b) {
            $ut[] = self::funn('booking_betaling_uenig', (int) $b['id'], (string) ($b['navn'] ?? $b['gjest_navn'] ?? ''),
                'Påmelding ' . $b['id'] . ' (' . Booking::kroner((int) $b['belop_ore'])
                . ') står som reservert, men betalingen er betalt');
        }
        foreach (DB::alle(
            "SELECT b.id, b.gjest_navn, b.belop_ore, m.navn
               FROM bookings b
               LEFT JOIN members m ON m.id = b.member_id
              WHERE b.status = 'betalt' AND b.payment_id IS NOT NULL AND b.belop_ore > 0
                AND NOT EXISTS (SELECT 1 FROM payments p
                                 WHERE (p.id = b.payment_id OR p.booking_id = b.id)
                                   AND p.status IN ({$ok}) AND p.annullert_at IS NULL)"
        ) as $b) {
            $ut[] = self::funn('booking_betaling_uenig', (int) $b['id'], (string) ($b['navn'] ?? $b['gjest_navn'] ?? ''),
                'Påmelding ' . $b['id'] . ' (' . Booking::kroner((int) $b['belop_ore'])
                . ') står som betalt, men ingen betaling på den er betalt');
        }
        return $ut;
    }

    // ── L13 gavekort_saldo ───────────────────────────────────────────────
    //
    // Saldoen skal vaere opprinnelig minus uttakene. Et ubetalt kort har
    // saldo 0 til det aktiveres, og annullerte/utloepte kan vaere nullet —
    // de er utenfor. Et aktivt kort skal ha en betalt betaling bak seg (et
    // kort verkstedet ga bort, har ingen betaling og er i orden).
    private static function gavekortSaldo(): array
    {
        $ut = [];
        $uttak = DB::harTabell('gift_card_uses')
            ? '(SELECT COALESCE(SUM(u.belop_ore), 0) FROM gift_card_uses u WHERE u.gift_card_id = g.id)'
            : '0';
        foreach (DB::alle(
            "SELECT g.id, g.kjoper_navn, g.opprinnelig_ore, g.saldo_ore, {$uttak} AS uttak
               FROM gift_cards g
              WHERE g.status IN ('aktivt','brukt')"
        ) as $g) {
            $venter = (int) $g['opprinnelig_ore'] - (int) $g['uttak'];
            if ($venter !== (int) $g['saldo_ore']) {
                $ut[] = self::funn('gavekort_saldo', (int) $g['id'], (string) ($g['kjoper_navn'] ?? ''),
                    'Gavekort ' . $g['id'] . ': saldo ' . Booking::kroner((int) $g['saldo_ore'])
                    . ', men opprinnelig ' . Booking::kroner((int) $g['opprinnelig_ore'])
                    . ' minus uttak ' . Booking::kroner((int) $g['uttak']) . ' er ' . Booking::kroner(max(0, $venter)));
            }
        }
        foreach (DB::alle(
            "SELECT g.id, g.kjoper_navn, g.opprinnelig_ore, p.id AS betaling, p.status AS bstatus
               FROM gift_cards g
               JOIN payments p ON p.id = g.payment_id
              WHERE g.status = 'aktivt'
                AND (p.status NOT IN ('autorisert','betalt','delvis_refundert') OR p.annullert_at IS NOT NULL)"
        ) as $g) {
            $ut[] = self::funn('gavekort_saldo', (int) $g['id'], (string) ($g['kjoper_navn'] ?? ''),
                'Gavekort ' . $g['id'] . ' (' . Booking::kroner((int) $g['opprinnelig_ore'])
                . ') er aktivt, men betaling ' . $g['betaling'] . ' er «' . $g['bstatus'] . '»');
        }
        return $ut;
    }
}
