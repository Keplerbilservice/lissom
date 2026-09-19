<?php
/**
 * «Betal ved oppmoete» — én bryter per sted, og ingen andre steder.
 *
 * Eieren, 19. september 2026: «det maa gaa an aa bestille uten aa betale med
 * vipps, samme vilkaar, men at de betaler ved oppmoete, kontant eller vipps»
 * — og da det ble spurt hvor bryterne skulle bo: «Vi har jo alle som skal
 * vise og skru av og paa paa synlighet», «Ja, og flytt hakene dit ogsaa»,
 * «Alt styres kun fra Synlighet».
 *
 * Foer dette laa valget i tre kolonner — courses.uten_forskudd,
 * products.uten_forskudd, membership_plans.uten_forskudd — med hver sin hake
 * i admin. Naa er det tre rader i content_blocks, og de leses herfra. Ett
 * sted, saa skjermen og serveren ikke kan si hver sin ting.
 *
 * Standarden foelger kolonnene den erstatter: paa for kurs og varer, av for
 * medlemskap. Medlemskap krever 'ja', ikke «alt annet enn nei» — mangler
 * raden, skal et medlemskap ikke kunne begynne aa loepe ubetalt.
 */

declare(strict_types=1);

final class Oppmote
{
    /** @var array<string,string> Lest én gang per forespoersel. */
    private static array $bufret = [];

    private static function verdi(string $navn): string
    {
        if (!array_key_exists($navn, self::$bufret)) {
            self::$bufret[$navn] = (string) DB::verdi(
                'SELECT verdi FROM content_blocks WHERE nokkel = :n',
                ['n' => 'Vis/oppmote' . $navn]
            );
        }
        return self::$bufret[$navn];
    }

    /**
     * Glem det som er lest.
     *
     * Bufferet lever én forespoersel, og det holder i drift. Proevene i
     * tests/backend.php kjorer alt i samme prosess, og maa kunne skru
     * bryteren og se svaret endre seg.
     */
    public static function glem(): void
    {
        self::$bufret = [];
    }

    /** ⊙ Synlighet → Betal ved oppmøte → Kurs og arrangementer. Mangler raden: paa. */
    public static function kurs(): bool
    {
        return self::verdi('kurs') !== 'nei';
    }

    /** ⊙ Synlighet → Betal ved oppmøte → Butikken. Mangler raden: paa. */
    public static function butikk(): bool
    {
        return self::verdi('butikk') !== 'nei';
    }

    /** ⊙ Synlighet → Betal ved oppmøte → Medlemskap. Mangler raden: AV. */
    public static function medlemskap(): bool
    {
        return self::verdi('medlemskap') === 'ja';
    }
}
