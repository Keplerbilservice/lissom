<?php
declare(strict_types=1);

/**
 * Salgene i en periode, fra én kilde.
 *
 * Eieren, 29. september 2026: et kurs betalt i verkstedet (kr 2 800) sto i
 * dagsoppgjoret, men ikke i dagens omsetning paa Oversikt. De to regnet hver
 * sin vei. Naa leser begge herfra, og en test holder dem like.
 *
 * rader() gir én rad per betaling, med det dagsoppgjoret trenger for aa finne
 * konto og maate. perFormal() summerer inntekten per formaal: pengene inn
 * minus refusjon, pluss den delen et gavekort dekket.
 */
final class Omsetning
{
    /** @return list<array<string,mixed>> */
    public static function rader(string $fraUtc, string $tilUtc): array
    {
        $gavekortFelt = DB::harKolonne('payments', 'gavekort_ore')
            ? 'p.gavekort_ore' : '0 AS gavekort_ore';

        // Maaten paa selve betalingsraden. Kom med migrasjon 084 og settes paa alt
        // som slaas inn i kassa. Et delt oppgjor har én rad per del, og hver av dem
        // baerer sin egen maate — kontantdelen sin og Vipps-delen sin. Uten denne
        // ville begge blitt lest av «orders.betalt_maate», som staar én gang for hele
        // salget og da ikke kan si annet enn det samme om begge.
        $maateFelt = DB::harKolonne('payments', 'maate')
            ? 'p.maate AS radmaate' : 'NULL AS radmaate';

        // Var kortet kjopt eller gitt bort? Kom med migrasjon 134. De to har hver sin
        // motkonto, og uten kolonnen er alt kjopt, slik det var for.
        $opprinnelseFelt = DB::harKolonne('gift_cards', 'opprinnelse')
            ? 'g.opprinnelse' : "'kjopt' AS opprinnelse";
        $gavekortJoin = DB::harKolonne('payments', 'gavekort_id')
            ? 'LEFT JOIN gift_cards g ON g.id = p.gavekort_id' : '';
        if ($gavekortJoin === '') {
            $opprinnelseFelt = "'kjopt' AS opprinnelse";
        }

        // «orders.payment_id» peker bare paa hovedraden. Delene av et delt oppgjor
        // ville derfor mistet ordren sin i join-en under, og «betalt_maate» blitt
        // NULL paa dem. «payments.order_id» fra migrasjon 134 kobler alle radene, og
        // brukes naar den finnes.
        $ordreJoin = DB::harKolonne('payments', 'order_id')
            ? 'LEFT JOIN orders o ON o.id = p.order_id OR o.payment_id = p.id'
            : 'LEFT JOIN orders o ON o.payment_id = p.id';

        $rader = DB::alle(
            "SELECT p.id, p.formal, p.type, p.belop_ore, p.refundert_ore, p.created_at,
                    {$gavekortFelt}, {$maateFelt}, {$opprinnelseFelt}, o.betalt_maate
               FROM payments p
               {$ordreJoin}
               {$gavekortJoin}
              WHERE p.status IN ('betalt', 'delvis_refundert')
                AND p.created_at >= :fra AND p.created_at < :til
           ORDER BY p.created_at",
            ['fra' => $fraUtc, 'til' => $tilUtc]
        );

        // ── Paameldinger lagt inn for haand ────────────────────────────────────
        //
        // De lager ingen betalingsrad: de er gjort opp i verkstedet, med kontanter,
        // Vipps eller faktura, og pengene gikk aldri gjennom oss. Uten disse ville
        // bilaget vaert ufullstendig — en kursdeltaker som betalte kontant i doera
        // hadde ikke staatt noe sted i regnskapet.
        //
        // Bare betalte. «Betaler ved oppmoete» staar som reservert til den er gjort
        // opp.
        //
        // ── «Gratis» falt ikke ut av seg selv ────────────────────────────────
        //
        // Her sto det at «Gratis» er null kroner og faller ut paa «belop_ore > 0».
        // Det stemte ikke: naar en plass settes til Gratis, skrives maaten paa
        // bookingen — beloepet staar urort. Maalt 23. september 2026: booking 16
        // sto som Gratis paa kr 1 490, og dagsoppgjoret foerte de 1 490 som
        // inntekt. Okonomi gjorde det ikke, for det finnes ingen betalingsrad, og
        // de to rapportene sprikte med akkurat det beloepet.
        //
        // Derfor spoer vi paa maaten, ikke paa beloepet. Samme liste som
        // Booking::manuellBetaling() bruker, saa de to kan ikke komme i utakt.
        //
        // Fra 23. september 2026 lager begge veiene til «betalt» en betalingsrad,
        // saa dette er stien for det som ble foert for den datoen.
        $utenPenger = Booking::MAATER_UTEN_PENGER;
        $plass      = implode(',', array_fill(0, count($utenPenger), '?'));
        $manuelle = DB::alle(
            "SELECT b.id, b.belop_ore, b.betalt_maate, b.created_at
               FROM bookings b
              WHERE b.payment_id IS NULL
                AND b.status = 'betalt'
                AND b.belop_ore > 0
                AND (b.betalt_maate IS NULL OR b.betalt_maate NOT IN ({$plass}))
                AND b.created_at >= ? AND b.created_at < ?",
            array_merge($utenPenger, [$fraUtc, $tilUtc])
        );
        foreach ($manuelle as $m) {
            $rader[] = [
                'id'            => 'b' . $m['id'],
                'formal'        => 'booking',
                'type'          => 'manuell',
                'belop_ore'     => $m['belop_ore'],
                'refundert_ore' => 0,
                'created_at'    => $m['created_at'],
                'betalt_maate'  => $m['betalt_maate'],
            ];
        }

        return $rader;
    }

    // ── Mva ─────────────────────────────────────────────────────────────
    //
    // Eieren, 30. september 2026: «omsetning som vises, paa de kontoene som
    // har mva, saa vis uten mva og mva paa egen linje».
    //
    // Satsen kommer fra mva-koden i regnskapsoppsettet (Oekonomi › Regnskap,
    // regnskap_mva_*), den samme koden dagsoppgjoret skriver paa bilaget.
    // Kodene er Tripletex sine: 3 er hoey sats (25 %), 31 middels (15 %),
    // 33 lav (12 %). Alt annet — 6 (avgiftsfri, kursene), 5 (fritatt) og tom
    // (gavekort, som er gjeld) — har ingen mva, og da vises bare beloepet.

    /** Mva-kode → sats i prosent. Koder som ikke staar her, har ingen mva. */
    public const MVA_SATS = ['3' => 25, '31' => 15, '33' => 12];

    /** Formaal → innstillingen som har mva-koden. */
    public const MVA_KODE = [
        'booking'    => 'regnskap_mva_kurs',
        'medlemskap' => 'regnskap_mva_medlemskap',
        'ordre'      => 'regnskap_mva_butikk',
        'gavekort'   => 'regnskap_mva_gavekort',
    ];

    /** Mva-satsen (prosent) for et formaal, eller 0 naar det ikke har mva. */
    public static function mvaSats(string $formal): int
    {
        $n = self::MVA_KODE[$formal] ?? null;
        if ($n === null) {
            return 0;
        }
        $kode = trim((string) Config::hent($n, ''));
        return self::MVA_SATS[$kode] ?? 0;
    }

    /**
     * Brutto delt i beloep uten mva og mva, i oere. Mva-en er resten, saa
     * eks + mva er alltid noeyaktig brutto.
     *
     * @return array{eksOre:int, mvaOre:int, mvaSats:int}
     */
    public static function delMva(int $bruttoOre, int $sats): array
    {
        if ($sats <= 0) {
            return ['eksOre' => $bruttoOre, 'mvaOre' => 0, 'mvaSats' => 0];
        }
        $eks = (int) round($bruttoOre * 100 / (100 + $sats));
        return ['eksOre' => $eks, 'mvaOre' => $bruttoOre - $eks, 'mvaSats' => $sats];
    }

    /** delMva() med satsen fra oppsettet for formaalet. */
    public static function mvaFor(string $formal, int $bruttoOre): array
    {
        return self::delMva($bruttoOre, self::mvaSats($formal));
    }

    /**
     * Omsetningen: summen UTEN mva over formaalene, og mva-en for seg.
     *
     * Eieren, 30. september 2026: «kontoen total maa vaere alt uten mva, det
     * er dette som er omsetning». Mva-en er informasjon, ikke omsetning.
     * Delt per formaal foer summen, saa totalen er noeyaktig summen av
     * linjene som vises.
     *
     * @param array<string,int> $perFormal fra perFormal()
     * @return array{eksOre:int, mvaOre:int, bruttoOre:int}
     */
    public static function sumUtenMva(array $perFormal): array
    {
        $eks = 0;
        $mva = 0;
        foreach ($perFormal as $f => $ore) {
            $d = self::mvaFor((string) $f, (int) $ore);
            $eks += $d['eksOre'];
            $mva += $d['mvaOre'];
        }
        return ['eksOre' => $eks, 'mvaOre' => $mva, 'bruttoOre' => $eks + $mva];
    }

    /**
     * Inntekt per formaal i perioden, i oere. Tomme formaal er ikke med.
     *
     * @return array<string,int>
     */
    public static function perFormal(string $fraUtc, string $tilUtc): array
    {
        $ut = [];
        foreach (self::rader($fraUtc, $tilUtc) as $r) {
            $ore = (int) $r['belop_ore'] - (int) ($r['refundert_ore'] ?? 0) + (int) ($r['gavekort_ore'] ?? 0);
            if ($ore === 0) {
                continue;
            }
            $f = (string) $r['formal'];
            $ut[$f] = ($ut[$f] ?? 0) + $ore;
        }
        return $ut;
    }

    // ── Hvor pengene kommer fra ─────────────────────────────────────────
    //
    // Eieren, 8. oktober 2026: Penger viser omsetningen uten mva fordelt paa
    // seks kilder — Kurs, Paint on Pots, Medlemskap, Nettbutikk, Kasse i
    // verkstedet og Gavekort. Basen har bare fire formaal, saa to av dem
    // skilles ut her:
    //
    //   Paint on Pots: en booking paa kurset som foelger aapningstidene
    //     (courses.folger_apningstid, migrasjon 257) eller har adressen
    //     paint-on-pots — samme kjennetegn som Malebord::kurs().
    //   Kasse i verkstedet: ordrenummeret sier hvor salget ble slaatt inn.
    //     api/admin/uttak.php lager D- (over disk), Q- (Vipps-QR i kassa) og
    //     G- (gavekort i kassa); nettbutikken (api/ordre.php) lager B-. Det
    //     har staatt slik paa alle salg, saa eldre salg sorteres likt uten ny
    //     kolonne. T- («Ta med barn» paa Min side) er et tillegg til
    //     medlemskapet og regnes dit.
    //
    // Samme rader og samme beloep som perFormal() (Omsetning::rader), og mva
    // trekkes fra med satsen til formaalet (mvaFor), saa summen av kildene
    // er noeyaktig sumUtenMva() for perioden.

    /** Kildene i fast rekkefoelge. */
    public const KILDER = [
        'kurs'       => 'Kurs',
        'pop'        => 'Paint on Pots',
        'medlemskap' => 'Medlemskap',
        'nettbutikk' => 'Nettbutikk',
        'kasse'      => 'Kasse i verkstedet',
        'gavekort'   => 'Gavekort',
    ];

    /** Ordrenummer-prefiksene kassa i verkstedet bruker (api/admin/uttak.php). */
    public const KASSE_PREFIKS = ['D-', 'Q-', 'G-'];

    /** Kilden til én betaling, ut fra formaal, ordrenummer og om kurset er Paint on Pots. */
    public static function kildeFor(string $formal, ?string $ordrenr, bool $erPop, string $type = ''): string
    {
        if ($formal === 'medlemskap' || $formal === 'gavekort') {
            return $formal;
        }
        if ($formal === 'booking') {
            return $erPop ? 'pop' : 'kurs';
        }
        $nr = (string) $ordrenr;
        if (str_starts_with($nr, 'T-')) {
            return 'medlemskap';
        }
        foreach (self::KASSE_PREFIKS as $p) {
            if (str_starts_with($nr, $p)) {
                return 'kasse';
            }
        }
        // Uten ordre: en manuell rad er slaatt inn i verkstedet.
        if ($nr === '' && $type === 'manuell') {
            return 'kasse';
        }
        return 'nettbutikk';
    }

    /**
     * Omsetningen uten mva per kilde, med salgene bak.
     *
     * @return array{sumEksOre:int, kilder:list<array{nokkel:string,navn:string,eksOre:int,salg:list<array{dato:string,hva:string,eksOre:int}>}>}
     */
    public static function perKilde(string $fraUtc, string $tilUtc): array
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $rader = self::rader($fraUtc, $tilUtc);

        $betIder = [];
        $bookIder = [];
        foreach ($rader as $r) {
            if (is_string($r['id']) && str_starts_with($r['id'], 'b')) {
                $bookIder[] = (int) substr($r['id'], 1);
            } else {
                $betIder[] = (int) $r['id'];
            }
        }

        $popUttrykk = DB::harKolonne('courses', 'folger_apningstid')
            ? "(c.slug = 'paint-on-pots' OR COALESCE(c.folger_apningstid, 0) = 1)"
            : "(c.slug = 'paint-on-pots')";
        $ordreVilkaar = DB::harKolonne('payments', 'order_id')
            ? '(o.id = p.order_id OR o.payment_id = p.id)' : 'o.payment_id = p.id';

        $info = [];
        if ($betIder) {
            $plass = implode(',', array_fill(0, count($betIder), '?'));
            foreach (DB::alle(
                "SELECT p.id, p.type, m.navn AS medlem,
                        (SELECT o.ordrenr FROM orders o WHERE {$ordreVilkaar} LIMIT 1) AS ordrenr,
                        (SELECT o.kunde_navn FROM orders o WHERE {$ordreVilkaar} LIMIT 1) AS kunde,
                        (SELECT c.tittel FROM bookings b JOIN courses c ON c.id = b.course_id
                          WHERE b.payment_id = p.id LIMIT 1) AS kurs,
                        (SELECT {$popUttrykk} FROM bookings b JOIN courses c ON c.id = b.course_id
                          WHERE b.payment_id = p.id LIMIT 1) AS er_pop,
                        (SELECT COALESCE(mb.navn, b.gjest_navn) FROM bookings b LEFT JOIN members mb ON mb.id = b.member_id
                          WHERE b.payment_id = p.id LIMIT 1) AS deltaker
                   FROM payments p
              LEFT JOIN members m ON m.id = p.member_id
                  WHERE p.id IN ($plass)",
                $betIder
            ) as $i) {
                $info[(string) $i['id']] = $i;
            }
        }
        if ($bookIder) {
            $plass = implode(',', array_fill(0, count($bookIder), '?'));
            foreach (DB::alle(
                "SELECT b.id, c.tittel AS kurs, {$popUttrykk} AS er_pop, COALESCE(m.navn, b.gjest_navn) AS deltaker
                   FROM bookings b JOIN courses c ON c.id = b.course_id
              LEFT JOIN members m ON m.id = b.member_id
                  WHERE b.id IN ($plass)",
                $bookIder
            ) as $i) {
                $info['b' . $i['id']] = $i + ['type' => 'manuell'];
            }
        }

        $kilder = [];
        foreach (self::KILDER as $nokkel => $navn) {
            $kilder[$nokkel] = ['nokkel' => $nokkel, 'navn' => $navn, 'eksOre' => 0, 'bruttoOre' => 0, 'salg' => []];
        }
        $sum = 0;
        $brutto = 0;
        foreach ($rader as $r) {
            $ore = (int) $r['belop_ore'] - (int) ($r['refundert_ore'] ?? 0) + (int) ($r['gavekort_ore'] ?? 0);
            if ($ore === 0) {
                continue;
            }
            $formal = (string) $r['formal'];
            $i = $info[(string) $r['id']] ?? [];
            $kilde = self::kildeFor($formal, $i['ordrenr'] ?? null, (int) ($i['er_pop'] ?? 0) === 1,
                (string) ($i['type'] ?? $r['type'] ?? ''));
            $eks = self::mvaFor($formal, $ore)['eksOre'];
            $hva = (string) ($i['ordrenr'] ?? '') !== ''
                ? implode(' · ', array_filter(['Ordre ' . $i['ordrenr'], (string) ($i['kunde'] ?? '')]))
                : implode(' · ', array_filter([(string) ($i['kurs'] ?? ''), (string) (($i['deltaker'] ?? '') ?: ($i['medlem'] ?? ''))]));
            $maate = trim((string) ($r['radmaate'] ?? '')) ?: trim((string) ($r['betalt_maate'] ?? ''));
            $kilder[$kilde]['eksOre'] += $eks;
            $kilder[$kilde]['bruttoOre'] += $ore; // med mva (Maanedsrapport, 8. oktober 2026)
            $brutto += $ore;
            $kilder[$kilde]['salg'][] = [
                'dato'   => (new DateTimeImmutable((string) $r['created_at'], $utc))->setTimezone($oslo)->format('d.m. H:i'),
                'hva'    => implode(' · ', array_filter([$hva !== '' ? $hva : self::KILDER[$kilde], $maate])),
                'eksOre' => $eks,
            ];
            $sum += $eks;
        }
        return ['sumEksOre' => $sum, 'sumBruttoOre' => $brutto, 'kilder' => array_values($kilder)];
    }
}
