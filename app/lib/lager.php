<?php
/**
 * «Bestill mer» — minimum og maksimum per vare i butikken.
 *
 * Eieren, 28. september 2026: «Legg inn antall leirer på lager, så man får
 * beskjed om at hun må bestille mer for dette er noe internbutikken selger»,
 * og saa: minimum og maksimum per vare, ikke én grense.
 *
 * products.lager fantes (NULL = ikke lagerstyrt). Migrasjon 233 la til
 *   lager_min   paa eller under dette: beskjed. NULL = ingen beskjed.
 *   lager_maks  hvor mange verkstedet vil ha. Beskjeden sier «bestill
 *               maks − lager». NULL = beskjeden sier ikke hvor mange.
 *
 * Naar et salg tar lageret fra over min til paa eller under, gaar malen
 * «intern_bestill_mer» til admin — én gang per kryssing, ikke for hvert salg
 * etter. Flisen «Bestill mer» paa Oversikt viser alle som staar paa eller
 * under min. Kundesiden vises ingenting av dette.
 */

declare(strict_types=1);

final class Lager
{
    public static function harGrense(): bool
    {
        return DB::harKolonne('products', 'lager_min');
    }

    // ── Nettbutikken og Internt (migrasjon 252, eieren 04.10.2026) ─────────
    //
    // To brytere per vare: i_nettbutikk (nettbutikken for alle) og
    // kun_medlemmer (Internt, internbutikken for medlemmene). Begge kan vaere
    // paa; lageret er felles. i_nettbutikk NULL — og foer migrasjonen er
    // kjoert — betyr det samme som foer: i nettbutikken naar kun_medlemmer = 0.

    public static function harNettbutikkBryter(): bool
    {
        return DB::harKolonne('products', 'i_nettbutikk');
    }

    /** SQL-vilkaaret «varen er i nettbutikken». $a er tabellaliaset med punktum, f.eks. 'p.'. */
    public static function iNettbutikkSql(string $a = ''): string
    {
        return self::harNettbutikkBryter()
            ? "({$a}i_nettbutikk = 1 OR ({$a}i_nettbutikk IS NULL AND {$a}kun_medlemmer = 0))"
            : "{$a}kun_medlemmer = 0";
    }

    /** Er denne raden i nettbutikken? */
    public static function iNettbutikk(array $v): bool
    {
        return isset($v['i_nettbutikk']) ? (int) $v['i_nettbutikk'] === 1 : (int) ($v['kun_medlemmer'] ?? 0) === 0;
    }

    /** Hvor mange som maa bestilles for aa naa maks. 0 naar maks mangler. */
    public static function aaBestille(int $lager, ?int $maks): int
    {
        return $maks === null ? 0 : max(0, $maks - $lager);
    }

    /** «Bestill mer: Leire (3 igjen, bestill 7)» — flisen og emnet. */
    public static function linje(string $vare, int $lager, ?int $maks): string
    {
        $n = self::aaBestille($lager, $maks);
        return 'Bestill mer: ' . $vare . ' (' . $lager . ' igjen' . ($n > 0 ? ', bestill ' . $n : '') . ')';
    }

    /**
     * Kalles rett etter at lageret er trukket (salg, kassa, «Ta ut leire»).
     * $trukket er antallet som gikk ut; lageret foer var lager + trukket.
     */
    public static function etterSalg(int $produktId, int $trukket): void
    {
        if ($trukket <= 0 || !self::harGrense()) {
            return;
        }
        $v = DB::en('SELECT * FROM products WHERE id = :i', ['i' => $produktId]);
        if ($v === null || $v['lager'] === null || $v['lager_min'] === null) {
            return;
        }
        $naa = (int) $v['lager'];
        $min = (int) $v['lager_min'];
        if ($naa <= $min && $naa + $trukket > $min) {
            self::underMin($v);
        }
    }

    /**
     * Kalles etter at varen er lagret for haand i admin (antall eller min
     * endret). Eieren, 04.10.2026: varsling ogsaa naar antallet endres for
     * haand. Samme regel som etterSalg: én gang, naar varen gaar fra over min
     * (eller ingen grense) til paa eller under.
     *
     * @param int|null $foer    lageret foer lagringen (null = ikke lagerstyrt)
     * @param int|null $minFoer min foer lagringen (null = ikke satt)
     */
    public static function etterEndring(int $produktId, ?int $foer, ?int $minFoer): void
    {
        if (!self::harGrense()) {
            return;
        }
        $v = DB::en('SELECT * FROM products WHERE id = :i', ['i' => $produktId]);
        if ($v === null || $v['lager'] === null || $v['lager_min'] === null) {
            return;
        }
        $varUnder = $foer !== null && $minFoer !== null && $foer <= $minFoer;
        if ((int) $v['lager'] <= (int) $v['lager_min'] && !$varUnder) {
            self::underMin($v);
        }
    }

    /**
     * Varen har naadd min: e-posten til admin, og (eieren 04.10.2026, GO)
     * varen legges i handlelista under leverandoeren som «Verkstedets lager»
     * med antallet som fyller opp til maks. Admin velger selv naar
     * bestillingen sendes («Bestill» i handlelista).
     *
     * @param array<string,mixed> $v raden fra products
     */
    private static function underMin(array $v): void
    {
        $naa = (int) $v['lager'];
        $maks = $v['lager_maks'] === null ? null : (int) $v['lager_maks'];
        Varsel::malTilAdmin('intern_bestill_mer', [
            'vare'     => (string) $v['tittel'],
            'antall'   => (string) $naa,
            'bestill'  => (string) self::aaBestille($naa, $maks),
            'linje'    => self::linje((string) $v['tittel'], $naa, $maks),
        ], 'product', (int) $v['id']);
        self::tilHandlelista($v);
    }

    /** Kan handlelista ta verkstedets egne linjer (migrasjon 253)? */
    public static function harVerkstedslinjer(): bool
    {
        if (!DB::harTabell('handleliste_linjer')) {
            return false;
        }
        $null = DB::verdi(
            "SELECT IS_NULLABLE FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'handleliste_linjer' AND column_name = 'member_id'"
        );
        return (string) $null === 'YES';
    }

    /**
     * Legger varen i handlelista som verkstedets egen linje (member_id NULL),
     * eller oppdaterer antallet paa den som alt ligger og ikke er bestilt.
     * Bare varer med leverandoer og maks: uten dem er det ingen aa bestille
     * fra, og ingen vet hvor mange.
     *
     * @param array<string,mixed> $v raden fra products
     */
    public static function tilHandlelista(array $v): void
    {
        $antall = self::aaBestille((int) $v['lager'], $v['lager_maks'] === null ? null : (int) $v['lager_maks']);
        if ($antall <= 0 || empty($v['leverandor_id']) || !self::harVerkstedslinjer()) {
            return;
        }
        $fins = DB::en(
            "SELECT id FROM handleliste_linjer
              WHERE member_id IS NULL AND product_id = :p AND status = 'sendt' AND bestilt_at IS NULL
              LIMIT 1",
            ['p' => (int) $v['id']]
        );
        if ($fins !== null) {
            DB::oppdater('handleliste_linjer', ['antall' => min(65535, $antall)], ['id' => (int) $fins['id']]);
            return;
        }
        // Prisen er 0: verkstedet krever ikke inn noe av seg selv, og en linje
        // uten pris ville stoppet «Bestill». Settes prisen paa varen i lista,
        // faar ogsaa denne linja den (frakten deles da riktig).
        DB::settInn('handleliste_linjer', [
            'member_id'  => null,
            'product_id' => (int) $v['id'],
            'antall'     => min(65535, $antall),
            'status'     => 'sendt',
            'pris_ore'   => 0,
            'sendt_at'   => gmdate('Y-m-d H:i:s'),
        ]);
        revider('handleliste_verkstedet', 'product', (int) $v['id'], ['antall' => $antall]);
    }

    /**
     * Leirene admin kan ta ut fra lageret («Ta ut leire», eieren 04.10.2026):
     * leirevarene som er lagerstyrt og ikke kladd.
     *
     * @return list<array{id:int,vare:string,antall:int}>
     */
    public static function leireListe(): array
    {
        if (!DB::harKolonne('products', 'leire')) {
            return [];
        }
        return array_map(static fn($v) => [
            'id'     => (int) $v['id'],
            'vare'   => (string) $v['tittel'],
            'antall' => (int) $v['lager'],
        ], DB::alle(
            "SELECT id, tittel, lager FROM products
              WHERE leire = 1 AND lager IS NOT NULL AND status <> 'kladd'
           ORDER BY tittel"
        ));
    }

    /**
     * Skal leiren skjules for dette medlemmet? Leire er inkludert i Prøv
     * Lissom (eieren, 29. september 2026): den som har den, ser ikke
     * leirevarene i medlemsbutikken og kan ikke kjoepe dem. Andre
     * medlemskap og kassa i admin paavirkes ikke.
     */
    public static function skjulLeire(?array $medlem): bool
    {
        if ($medlem === null || !DB::harKolonne('products', 'leire')) {
            return false;
        }
        return in_array((string) ($medlem['status'] ?? ''), ['prove', 'aktiv'], true)
            && Medlemskap::erEngangs((string) ($medlem['medlemskap_type'] ?? ''));
    }

    /**
     * Varene paa eller under min — flisen paa Oversikt.
     *
     * @return list<array{id:int,vare:string,antall:int,bestill:int,linje:string}>
     */
    public static function bestillMer(): array
    {
        if (!self::harGrense()) {
            return [];
        }
        return array_map(static function ($v): array {
            $maks = $v['lager_maks'] === null ? null : (int) $v['lager_maks'];
            return [
                'id'      => (int) $v['id'],
                'vare'    => (string) $v['tittel'],
                'antall'  => (int) $v['lager'],
                'bestill' => self::aaBestille((int) $v['lager'], $maks),
                'linje'   => self::linje((string) $v['tittel'], (int) $v['lager'], $maks),
                // Lite på lager i Butikk og på I dag: «2 igjen · min 5 · fyll til 20» (eieren 04.10.2026).
                'min'     => (int) $v['lager_min'],
                'maks'    => $maks,
            ];
        }, DB::alle(
            "SELECT id, tittel, lager, lager_min, lager_maks FROM products
              WHERE lager IS NOT NULL AND lager_min IS NOT NULL
                AND lager <= lager_min AND status <> 'kladd'
           ORDER BY lager, tittel"
        ));
    }
}
