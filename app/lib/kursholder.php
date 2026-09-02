<?php
/**
 * Hvem holder kurset.
 *
 * Fire steder i systemet lager kursdatoer: «ny dato» i admin, faste ukedager
 * (serier) og aapent verksted. Bare det forste satte kursholder.
 * De tre andre la datoene ut med tomt felt, og da sto de i kalenderen som
 * «Uten kursholder» — ogsaa naar verkstedet bare har én, og hun er standard.
 *
 * Eieren, 1. september: «lag din egen bolle dukker opp i kalenderen naa, uten
 * kursholder, hvordan er det mulig naar det kun er monica som er kursholder og
 * default?» — og: «det gjelder saa klart ogsaa paa alle paint on pots».
 *
 * Regelen staar her, ett sted, og gaar én vei:
 *
 *   1. Er det valgt en paa selve datoen, er det hen. Alltid.
 *   2. Ellers: den som staar paa kurset.
 *   3. Ellers: verkstedets standard.
 *   4. Finnes ingen av delene, staar datoen tom — som for.
 *
 * Punkt 1 hoerer hjemme der noen faktisk kan velge, altsaa i admin. De tre
 * andre stedene lager datoer uten aa spore noen, og starter paa punkt 2.
 */

declare(strict_types=1);

final class Kursholder
{
    /** Standarden hentes én gang per foresporsel — den spoerres i loekker. */
    private static ?int $standard = null;
    private static bool $standardHentet = false;

    /**
     * Kan datoen i det hele tatt baere en kursholder?
     *
     * Kolonnen kommer med migrasjon 085. Koden ligger ute noen minutter for
     * vedlikeholdet kjores, og skal ikke doe paa en kolonne som ikke er der.
     */
    public static function klar(): bool
    {
        return DB::harKolonne('course_sessions', 'kursholder_id');
    }

    /** Verkstedets standard, eller null naar ingen er merket. */
    public static function standard(): ?int
    {
        if (self::$standardHentet) {
            return self::$standard;
        }
        self::$standardHentet = true;
        self::$standard = null;

        if (DB::harTabell('kursholdere') && DB::harKolonne('kursholdere', 'standard')) {
            $id = DB::verdi('SELECT id FROM kursholdere WHERE standard = 1 AND aktiv = 1 LIMIT 1');
            if ($id !== null) {
                self::$standard = (int) $id;
            }
        }
        return self::$standard;
    }

    /**
     * Den som staar paa selve kurset, eller null.
     *
     * «Staar paa kurset» betyr en som fortsatt holder kurs. Her sto bare
     * oppslaget, uten aa sporre om det: pekte kurset paa en som hadde
     * sluttet, ble det navnet arvet videre til hver eneste nye dato, og
     * fallbacken til standarden slo aldri inn — feltet var jo ikke tomt.
     *
     * Kalenderen viser ingen som har sluttet (den ser etter «aktiv = 1»), saa
     * datoen sto der som «Uten kursholder» selv om det sto noen paa den.
     * Eieren, 1. september: «flere paint on pots kurs ligger paa kolonnen
     * uten kursholdere».
     */
    public static function paaKurset(int $kursId): ?int
    {
        if ($kursId <= 0 || !DB::harKolonne('courses', 'kursholder_id')) {
            return null;
        }
        $id = DB::verdi(
            'SELECT c.kursholder_id
               FROM courses c
               JOIN kursholdere k ON k.id = c.kursholder_id AND k.aktiv = 1
              WHERE c.id = :i',
            ['i' => $kursId]
        );
        return $id !== null ? (int) $id : null;
    }

    /**
     * Hvem en ny dato paa dette kurset skal staa paa.
     *
     * Null betyr «ingen» — da er det ingen paa kurset og ingen standard, og
     * datoen skal staa tom framfor aa faa en tilfeldig person paa seg.
     */
    public static function forKurs(int $kursId): ?int
    {
        return self::paaKurset($kursId) ?? self::standard();
    }

    /** Bare for provene: glem det som er hentet. */
    public static function glem(): void
    {
        self::$standard = null;
        self::$standardHentet = false;
    }
}
