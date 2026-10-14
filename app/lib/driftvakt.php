<?php
declare(strict_types=1);

/**
 * Stille, lesende driftsvakt for de delene som bare serveren kan se.
 *
 * Vakta retter aldri data og kontakter aldri Vipps. Den samler eksisterende
 * datasjekker med ko- og betalingskontroll, og legger bare et avvik i den
 * vanlige e-postkoen. Uendret avvik varsles hoeyest en gang i dognet.
 */
final class Driftvakt
{
    public const STANDARD_EPOST = 'joakim@kepler.no';

    /** @return list<string> */
    public static function finnAvvik(): array
    {
        $avvik = [];
        $data = Vaktdata::sjekk();
        foreach ($data['avvik'] as $tekst) {
            $avvik[] = (string) $tekst;
        }
        foreach ($data['funn'] as $funn) {
            $avvik[] = 'Datasjekk ' . ($funn['regel'] ?? 'ukjent') . ': '
                . trim((string) ($funn['navn'] ?? '')) . ' - ' . (string) ($funn['tekst'] ?? '');
        }

        $ko = (int) (DB::verdi(
            "SELECT COUNT(*) FROM notifications
              WHERE status = 'ko' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE)"
        ) ?? 0);
        if ($ko > 0) {
            $avvik[] = $ko . ' e-post/SMS har ligget i varselkoen i mer enn 20 minutter';
        }

        $feilet = (int) (DB::verdi(
            "SELECT COUNT(*) FROM notifications
              WHERE status = 'feilet' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
        ) ?? 0);
        if ($feilet > 0) {
            $avvik[] = $feilet . ' e-post/SMS har feilet siste doegn';
        }

        $betalinger = (int) (DB::verdi(
            "SELECT COUNT(*) FROM payments
              WHERE status IN ('opprettet','venter','autorisert')
                AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)"
        ) ?? 0);
        if ($betalinger > 0) {
            $avvik[] = $betalinger . ' betalinger har ventet i mer enn 30 minutter';
        }

        return array_values(array_unique($avvik));
    }

    /** @return array{avvik:list<string>,varslet:bool} */
    public static function kjor(): array
    {
        $avvik = self::finnAvvik();
        $fingeravtrykk = hash('sha256', implode("\n", $avvik));
        $forrige = (string) (DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'driftvakt_fingeravtrykk'") ?? '');
        $sist = (string) (DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'driftvakt_varslet_at'") ?? '');
        $gammelt = $sist === '' || strtotime($sist) === false || strtotime($sist) < time() - 86400;
        $varslet = false;

        if ($avvik !== [] && ($fingeravtrykk !== $forrige || $gammelt)) {
            $til = trim((string) Config::hent('vakt_epost', self::STANDARD_EPOST));
            $varslet = Varsel::mal('intern_driftavvik', ['epost' => $til], [
                'antall' => (string) count($avvik),
                'avvik' => '- ' . implode("\n- ", $avvik),
            ]) > 0;
            if ($varslet) {
                self::lagre('driftvakt_fingeravtrykk', $fingeravtrykk);
                self::lagre('driftvakt_varslet_at', gmdate('Y-m-d H:i:s'));
            }
        } elseif ($avvik === [] && $forrige !== '') {
            $til = trim((string) Config::hent('vakt_epost', self::STANDARD_EPOST));
            $varslet = Varsel::mal('intern_drift_normal', ['epost' => $til]) > 0;
            if ($varslet) {
                self::lagre('driftvakt_fingeravtrykk', '');
                self::lagre('driftvakt_varslet_at', gmdate('Y-m-d H:i:s'));
            }
        }

        return ['avvik' => $avvik, 'varslet' => $varslet];
    }

    private static function lagre(string $nokkel, string $verdi): void
    {
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v) '
            . 'ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
            ['n' => $nokkel, 'v' => $verdi]
        );
    }
}
