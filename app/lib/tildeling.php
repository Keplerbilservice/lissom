<?php
/**
 * «Gi tid»: tid verkstedet gir et medlem fra admin.
 *
 * Eieren, 2. oktober 2026: medlemmer kan faa 1 time, tilgang én uke eller
 * tilgang ut maaneden. Lagres i tabellen tildelinger (migrasjon 249).
 *
 * Sluttdatoene er norsk tid (Europe/Oslo):
 *   uke     i dag + 6 dager — dag 1–7 gjelder
 *   maaned  siste dag i maaneden
 *   time    gjelder ut maaneden (siste dag i maaneden)
 *
 * En tildeling slettes aldri. trekk() setter trukket_at/trukket_av, og en
 * trukket eller utloept tildeling teller ikke.
 *
 * Bryteren Vis/tildeling (⊙ Synlighet) stopper alt: er den av — og den er av
 * naar raden mangler — gir aktivTilgang() null, ekstraTimer() 0, og gi()
 * avvises. Bit T1: ingenting annet leser dette ennaa.
 */

declare(strict_types=1);

final class Tildeling
{
    public const TYPER = ['time', 'uke', 'maaned'];

    /** Bryteren ⊙ Synlighet → Vis/tildeling. Mangler raden, er den AV. */
    public static function paa(): bool
    {
        return (string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/tildeling'") === 'ja';
    }

    private static function finnes(): bool
    {
        return DB::harTabell('tildelinger');
    }

    private static function klar(): bool
    {
        return self::paa() && self::finnes();
    }

    /** Dagens dato i norsk tid, Y-m-d. */
    public static function idag(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
    }

    /** Siste dag tildelingen gjelder, gitt type og foerste dag (Y-m-d). */
    public static function sluttdato(string $type, string $idag): string
    {
        $dag = DateTimeImmutable::createFromFormat('!Y-m-d', $idag, new DateTimeZone('Europe/Oslo'));
        if ($dag === false || $dag->format('Y-m-d') !== $idag) {
            throw new InvalidArgumentException('Ugyldig dato: ' . $idag);
        }
        return match ($type) {
            'uke'            => $dag->modify('+6 days')->format('Y-m-d'),
            'maaned', 'time' => $dag->format('Y-m-t'),
            default          => throw new InvalidArgumentException('Ukjent type: ' . $type),
        };
    }

    /**
     * Tilgangen (uke eller maaned) som gjelder for medlemmet i dag, eller
     * null. Gjelder flere, gis den som varer lengst.
     */
    public static function aktivTilgang(int $memberId, ?string $idag = null): ?array
    {
        if ($memberId <= 0 || !self::klar()) {
            return null;
        }
        $idag ??= self::idag();
        return DB::en(
            "SELECT * FROM tildelinger
              WHERE member_id = :m AND type IN ('uke','maaned')
                AND trukket_at IS NULL AND fra <= :d1 AND til >= :d2
           ORDER BY til DESC, id DESC
              LIMIT 1",
            ['m' => $memberId, 'd1' => $idag, 'd2' => $idag]
        );
    }

    /** Ekstra timer gitt med «1 time» som gjelder i dag. 0 naar ingen. */
    public static function ekstraTimer(int $memberId, ?string $idag = null): int
    {
        if ($memberId <= 0 || !self::klar()) {
            return 0;
        }
        $idag ??= self::idag();
        return (int) DB::verdi(
            "SELECT COALESCE(SUM(timer), 0) FROM tildelinger
              WHERE member_id = :m AND type = 'time'
                AND trukket_at IS NULL AND fra <= :d1 AND til >= :d2",
            ['m' => $memberId, 'd1' => $idag, 'd2' => $idag]
        );
    }

    /**
     * Gi tid. Returnerer id-en paa den nye raden.
     *
     * @throws RuntimeException naar bryteren er av, tabellen mangler eller medlemmet ikke finnes
     */
    public static function gi(int $memberId, string $type, ?int $gittAv, ?string $idag = null): int
    {
        if (!self::paa()) {
            throw new RuntimeException('Gi tid er skrudd av.');
        }
        if (!self::finnes()) {
            throw new RuntimeException('Gi tid krever en oppdatering av databasen.');
        }
        if (!in_array($type, self::TYPER, true)) {
            throw new InvalidArgumentException('Ukjent type: ' . $type);
        }
        if ($memberId <= 0 || DB::en('SELECT id FROM members WHERE id = :i', ['i' => $memberId]) === null) {
            throw new RuntimeException('Fant ikke medlemmet.');
        }
        $idag ??= self::idag();
        $til = self::sluttdato($type, $idag);
        $id = DB::settInn('tildelinger', [
            'member_id' => $memberId,
            'type'      => $type,
            'timer'     => $type === 'time' ? 1 : null,
            'fra'       => $idag,
            'til'       => $til,
            'gitt_av'   => $gittAv,
        ]);
        revider('tildeling_gitt', 'member', $memberId, ['tildeling' => $id, 'type' => $type, 'til' => $til, 'av' => $gittAv]);
        return $id;
    }

    /**
     * Trekk tilbake. Raden slettes ikke; trukket_at/trukket_av settes.
     * Returnerer false naar den ikke finnes eller alt er trukket.
     */
    public static function trekk(int $id, ?int $trukketAv): bool
    {
        if ($id <= 0 || !self::finnes()) {
            return false;
        }
        $rad = DB::en('SELECT id, member_id FROM tildelinger WHERE id = :i', ['i' => $id]);
        if ($rad === null) {
            return false;
        }
        $endret = DB::kjor(
            'UPDATE tildelinger SET trukket_at = :n, trukket_av = :a WHERE id = :i AND trukket_at IS NULL',
            ['n' => gmdate('Y-m-d H:i:s'), 'a' => $trukketAv, 'i' => $id]
        )->rowCount();
        if ($endret === 0) {
            return false;
        }
        revider('tildeling_trukket', 'member', (int) $rad['member_id'], ['tildeling' => $id, 'av' => $trukketAv]);
        return true;
    }
}
