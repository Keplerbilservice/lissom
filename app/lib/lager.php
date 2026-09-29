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
     * Kalles rett etter at lageret er trukket. $trukket er antallet som
     * gikk ut; lageret foer salget var lager + trukket.
     */
    public static function etterSalg(int $produktId, int $trukket): void
    {
        if ($trukket <= 0 || !self::harGrense()) {
            return;
        }
        $v = DB::en('SELECT id, tittel, lager, lager_min, lager_maks FROM products WHERE id = :i', ['i' => $produktId]);
        if ($v === null || $v['lager'] === null || $v['lager_min'] === null) {
            return;
        }
        $naa = (int) $v['lager'];
        $min = (int) $v['lager_min'];
        $maks = $v['lager_maks'] === null ? null : (int) $v['lager_maks'];
        if ($naa <= $min && $naa + $trukket > $min) {
            Varsel::malTilAdmin('intern_bestill_mer', [
                'vare'     => (string) $v['tittel'],
                'antall'   => (string) $naa,
                'bestill'  => (string) self::aaBestille($naa, $maks),
                'linje'    => self::linje((string) $v['tittel'], $naa, $maks),
            ], 'product', (int) $v['id']);
        }
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
            ];
        }, DB::alle(
            "SELECT id, tittel, lager, lager_min, lager_maks FROM products
              WHERE lager IS NOT NULL AND lager_min IS NOT NULL
                AND lager <= lager_min AND status <> 'kladd'
           ORDER BY lager, tittel"
        ));
    }
}
