<?php
/**
 * Ferie — dagene verkstedet holder stengt.
 *
 * ── Hvorfor det ikke ble en egen tabell ───────────────────────────────
 *
 * Jeg begynte aa lage en «ferie»-tabell, og maatte rive den igjen:
 * «apningstider» med «stengt = 1» er noeyaktig det samme. Migrasjon 060 sier
 * det selv — «en helligdag, en ferieuke, en dag verkstedet er stengt selv om
 * det staar et kurs i kalenderen». To tabeller for den samme dagen ville
 * betydd to steder aa se etter, og to steder aa ta feil.
 *
 * Det som *var* nytt, er hva en stengt dag gjor: for gjaldt den bare
 * aapningstidene i bunnteksten. Naa skjuler den ogsaa kursdatoene paa den
 * dagen — fra nettsida og fra bookingen. Oektene roeres ikke: aapnes dagen
 * igjen, er alt tilbake slik det var.
 *
 * ── Hvorfor sammenlikningen skjer i PHP ──────────────────────────────
 *
 * course_sessions.start_tid staar i UTC; en stengt dag er en dato i Oslo. Aa
 * sammenlikne dem i SQL krever CONVERT_TZ, og den trenger tidssonetabeller
 * som ofte ikke er lastet paa et delt webhotell — da svarer den NULL, og hver
 * eneste kursdato ville forsvunnet uten et ord. Konverteringen gjores derfor
 * her, med den samme DateTimeZone resten av koden bruker.
 */

declare(strict_types=1);

final class Ferie
{
    /** @var list<string>|null 'Y-m-d' i norsk tid. Leses én gang per foresporsel. */
    private static ?array $dager = null;

    /** @return list<string> */
    public static function dager(): array
    {
        if (self::$dager !== null) {
            return self::$dager;
        }
        self::$dager = [];
        try {
            if (DB::harTabell('apningstider')) {
                self::$dager = array_map(
                    static fn(array $r): string => (string) $r['dato'],
                    DB::alle('SELECT dato FROM apningstider WHERE stengt = 1 ORDER BY dato')
                );
            }
        } catch (Throwable $e) {
            // Uten tabellen er ingen dager stengt, og alt er som for.
        }
        return self::$dager;
    }

    /** Kalles etter lagring, saa neste oppslag i samme foresporsel ser det nye. */
    public static function glem(): void
    {
        self::$dager = null;
    }

    /** Er denne datoen — 'Y-m-d' i norsk tid — stengt? */
    public static function harDato(string $dato): bool
    {
        return in_array($dato, self::dager(), true);
    }

    /**
     * Ligger dette tidspunktet paa en stengt dag?
     *
     * @param string|null $utcTid «2026-09-01 08:00:00», slik det staar i basen.
     */
    public static function stengt(?string $utcTid): bool
    {
        if ($utcTid === null || $utcTid === '' || self::dager() === []) {
            return false;
        }
        try {
            $d = (new DateTimeImmutable($utcTid, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Oslo'))
                ->format('Y-m-d');
        } catch (Throwable $e) {
            return false;
        }
        return self::harDato($d);
    }

    /**
     * Silen: behold det som ikke ligger paa en stengt dag.
     *
     * @param list<array<string,mixed>> $rader
     * @param string                    $felt  kolonnen med starttidspunktet
     * @return list<array<string,mixed>>
     */
    public static function utenom(array $rader, string $felt = 'start_tid'): array
    {
        if (self::dager() === []) {
            return $rader;
        }
        return array_values(array_filter(
            $rader,
            static fn(array $r): bool => !self::stengt(isset($r[$felt]) ? (string) $r[$felt] : null)
        ));
    }
}
