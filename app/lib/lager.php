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
        self::leggIHandlelista($v, self::aaBestille((int) $v['lager'], $v['lager_maks'] === null ? null : (int) $v['lager_maks']));
    }

    /**
     * Samme linje med et valgt antall (/ny-admin › Varer › Handleliste, eieren 09.10.2026).
     *
     * @param array<string,mixed> $v raden fra products
     */
    public static function leggIHandlelista(array $v, int $antall): void
    {
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

    // ── Handlelista i /ny-admin › Varer (eieren 09.10.2026) ────────────────
    //
    // Verkstedets egne linjer (member_id NULL) som ikke er bestilt ennå —
    // de samme linjene som «Bestill mer» og varsling under min legger inn,
    // og som admin-ny › Handlelister sender med «Send bestilling».

    /**
     * @return list<array{linjeId:int,produktId:int,vare:string,antall:int,leverandor:string,lager:?int,min:?int,maks:?int}>
     */
    public static function verkstedLinjer(): array
    {
        if (!self::harVerkstedslinjer()) {
            return [];
        }
        $grense = self::harGrense() ? 'p.lager_min, p.lager_maks' : 'NULL AS lager_min, NULL AS lager_maks';
        return array_map(static fn($r) => [
            'linjeId'    => (int) $r['id'],
            'produktId'  => (int) $r['product_id'],
            'vare'       => (string) $r['tittel'],
            'antall'     => (int) $r['antall'],
            'leverandor' => (string) ($r['leverandor'] ?? ''),
            'lager'      => $r['lager'] === null ? null : (int) $r['lager'],
            'min'        => $r['lager_min'] === null ? null : (int) $r['lager_min'],
            'maks'       => $r['lager_maks'] === null ? null : (int) $r['lager_maks'],
        ], DB::alle(
            "SELECT h.id, h.product_id, h.antall, p.tittel, p.lager, {$grense}, l.navn AS leverandor
               FROM handleliste_linjer h
               JOIN products p ON p.id = h.product_id
          LEFT JOIN leverandorer l ON l.id = p.leverandor_id
              WHERE h.member_id IS NULL AND h.status = 'sendt' AND h.bestilt_at IS NULL
           ORDER BY p.tittel, h.id"
        ));
    }

    /** Fjerner én av verkstedets linjer som ikke er bestilt. Medlemmenes linjer røres ikke. */
    public static function fjernVerkstedslinje(int $linjeId): bool
    {
        if (!self::harVerkstedslinjer()) {
            return false;
        }
        return DB::kjor(
            "DELETE FROM handleliste_linjer
              WHERE id = :i AND member_id IS NULL AND status = 'sendt' AND bestilt_at IS NULL AND order_id IS NULL",
            ['i' => $linjeId]
        )->rowCount() === 1;
    }

    /**
     * «Handlet»: linja er kjøpt inn. Den settes ferdig (bestilt og kommet) og
     * antallet legges til lageret — i én transaksjon, og bare én gang.
     *
     * @return array{vare:string,antall:int,lager:?int}|null null = fant ikke linja
     */
    public static function handlet(int $linjeId): ?array
    {
        if (!self::harVerkstedslinjer()) {
            return null;
        }
        return DB::iTransaksjon(static function () use ($linjeId): ?array {
            $l = DB::en(
                "SELECT h.id, h.product_id, h.antall, p.tittel FROM handleliste_linjer h
                   JOIN products p ON p.id = h.product_id
                  WHERE h.id = :i AND h.member_id IS NULL AND h.status = 'sendt' AND h.bestilt_at IS NULL
                  FOR UPDATE",
                ['i' => $linjeId]
            );
            if ($l === null) {
                return null;
            }
            $sett = "status = 'ferdig', bestilt_at = NOW()" . (self::harLeireStatus() ? ', kommet_at = NOW()' : '');
            $endret = DB::kjor(
                "UPDATE handleliste_linjer SET {$sett}
                  WHERE id = :i AND member_id IS NULL AND status = 'sendt' AND bestilt_at IS NULL",
                ['i' => $linjeId]
            )->rowCount();
            if ($endret !== 1) {
                return null;
            }
            // Bare lagerstyrte varer får antallet lagt til (NULL = ikke lagerstyrt).
            DB::kjor('UPDATE products SET lager = lager + :n WHERE id = :p AND lager IS NOT NULL',
                ['n' => (int) $l['antall'], 'p' => (int) $l['product_id']]);
            $lager = DB::verdi('SELECT lager FROM products WHERE id = :p', ['p' => (int) $l['product_id']]);
            revider('handleliste_handlet', 'product', (int) $l['product_id'], ['linje' => $linjeId, 'antall' => (int) $l['antall']]);
            return ['vare' => (string) $l['tittel'], 'antall' => (int) $l['antall'], 'lager' => $lager === null ? null : (int) $lager];
        });
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

    // ── Leirebestillingen (eieren 08.10.2026) ─────────────────────────────
    //
    // Fristen for neste bestilling staar i innstillinger (leirebestilling_frist,
    // ÅÅÅÅ-MM-DD). Statusen per linje (Bestilt -> Kommet -> Hentet) krever
    // migrasjon 261; foer den er kjoert er alt som er bestilt «Bestilt».

    /** Er kolonnene for Kommet og Hentet paa plass (migrasjon 261)? */
    public static function harLeireStatus(): bool
    {
        return DB::harTabell('handleliste_linjer') && DB::harKolonne('handleliste_linjer', 'kommet_at')
            && DB::harKolonne('handleliste_linjer', 'hentet_at') && DB::harKolonne('handleliste_linjer', 'bestilling_nr');
    }

    /**
     * Fristen for neste leirebestilling.
     *
     * @return array{dato:string,tekst:string} tom dato naar ingen frist er satt
     */
    public static function leireFrist(): array
    {
        $dato = trim((string) (DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'leirebestilling_frist'") ?? ''));
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dato) ? DateTimeImmutable::createFromFormat('!Y-m-d', $dato) : false;
        if ($d === false) {
            return ['dato' => '', 'tekst' => ''];
        }
        $dager = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];
        return [
            'dato'  => $dato,
            'tekst' => $dager[(int) $d->format('N') - 1] . ' ' . (int) $d->format('j') . '. ' . $mnd[(int) $d->format('n') - 1],
        ];
    }

    /** Bildeadressen til en vare slik nettleseren trenger den ('' = ingen). */
    public static function bildeUrl(?string $bilde): string
    {
        $b = trim((string) $bilde);
        if ($b === '') {
            return '';
        }
        return preg_match('#^https?://#', $b) ? $b : '/' . ltrim($b, '/');
    }
}
