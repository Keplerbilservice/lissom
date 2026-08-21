<?php
/**
 * Ratebegrensning uten Redis — vi teller i databasen, i faste tidsvinduer.
 * Godt nok til å stoppe noen som hamrer på innlogging eller betaling.
 */

declare(strict_types=1);

final class Rate
{
    /**
     * Teller ett forsøk. Svarer 429 hvis grensen er passert.
     *
     * @param string $handling  f.eks. 'login' eller 'betaling'
     * @param int    $maks      antall tillatte forsøk i vinduet
     * @param int    $vindu     vinduets lengde i sekunder
     */
    public static function sjekk(string $handling, int $maks = 10, int $vindu = 300, ?string $nokkel = null): void
    {
        if (!self::tillat($handling, $maks, $vindu, $nokkel)) {
            $minutter = max(1, (int) ceil($vindu / 60));
            header('Retry-After: ' . $vindu);
            Svar::feil("For mange forsøk. Vent {$minutter} minutter og prøv igjen.", 429);
        }
    }

    /** Som sjekk(), men returnerer false i stedet for å svare. */
    public static function tillat(string $handling, int $maks = 10, int $vindu = 300, ?string $nokkel = null): bool
    {
        $nokkel ??= Foresporsel::ip();
        $id = mb_substr($handling . ':' . $nokkel, 0, 160);
        $start = date('Y-m-d H:i:s', intdiv(time(), $vindu) * $vindu);

        DB::kjor(
            'INSERT INTO rate_limits (nokkel, vindu_start, antall)
                  VALUES (:n, :v, 1)
             ON DUPLICATE KEY UPDATE antall = antall + 1',
            ['n' => $id, 'v' => $start]
        );

        $antall = (int) DB::verdi(
            'SELECT antall FROM rate_limits WHERE nokkel = :n AND vindu_start = :v',
            ['n' => $id, 'v' => $start]
        );

        if ($antall > $maks) {
            logg('Ratebegrensning slo inn', ['handling' => $handling, 'antall' => $antall]);
            return false;
        }
        return true;
    }

    public static function rydd(): int
    {
        return DB::kjor(
            'DELETE FROM rate_limits WHERE vindu_start < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)'
        )->rowCount();
    }
}
