<?php
/**
 * Bakgrunnsarbeid uten cron.
 *
 * Kvitteringer og betalingsoppslag ligger i ko og trenger noe som tommer den.
 * Cron-jobber i cPanel er den ryddige losningen, men de maa settes opp for
 * haand. Denne lar vanlig trafikk paa nettsiden gjore jobben i stedet.
 *
 * Slik unngaar vi at det gaar utover den som besoker siden:
 *   - hoyst en gang i minuttet, uansett hvor mange som er inne samtidig
 *   - kjorer ETTER at svaret er sendt til nettleseren
 *   - smaa porsjoner, og feil faar aldri velte forespoerselen
 *
 * Dette erstatter ikke cron helt: en side uten besokende blir aldri tikket.
 * Kvitteringer gaar likevel ut, for den som nettopp betalte ER trafikk.
 */

declare(strict_types=1);

final class Tikk
{
    /** Registrerer at arbeidet skal gjores naar svaret er levert. */
    public static function planlegg(): void
    {
        if (PHP_SAPI === 'cli') {
            return; // cron kjorer jobbene direkte
        }

        register_shutdown_function(static function (): void {
            // Slipp nettleseren fri forst der serveren stotter det, saa ingen
            // venter paa at vi sender e-post.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                self::kjor();
            } catch (Throwable $e) {
                logg_feil('Bakgrunnsarbeid feilet', $e);
            }
        });
    }

    private static function kjor(): void
    {
        // Forste forespoersel i hvert minuttvindu vinner. Resten gaar videre.
        if (!Rate::tillat('tikk', 1, 60, 'server')) {
            return;
        }

        [$sendt, $feilet] = Utsending::tomKo(10);
        if ($sendt > 0 || $feilet > 0) {
            logg('Varselko tomt av trafikk', ['sendt' => $sendt, 'feilet' => $feilet]);
        }

        self::sjekkHengendeBetalinger();
    }

    /**
     * Betalinger som har staatt og ventet en stund. Webhooken er kilden, men
     * kommer den ikke fram, ville kunden staatt som ubetalt i det uendelige.
     */
    private static function sjekkHengendeBetalinger(): void
    {
        $venter = DB::alle(
            "SELECT vipps_reference
               FROM payments
              WHERE status IN ('venter','autorisert')
                AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)
                AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE)
              ORDER BY id
              LIMIT 3"
        );

        foreach ($venter as $p) {
            $ref = (string) $p['vipps_reference'];
            try {
                $status = Vipps::hentBetaling($ref);
                $tilstand = strtoupper((string) ($status['state'] ?? ''));

                DB::kjor(
                    'UPDATE payments SET siste_payload = :p, updated_at = UTC_TIMESTAMP()
                      WHERE vipps_reference = :r',
                    ['p' => json_encode($status, JSON_UNESCAPED_UNICODE), 'r' => $ref]
                );

                if ($tilstand === 'AUTHORIZED') {
                    Vipps::trekk($ref, (int) ($status['aggregate']['authorizedAmount']['value'] ?? 0));
                    Booking::markerBetalt($ref);
                } elseif ($tilstand === 'CAPTURED') {
                    Booking::markerBetalt($ref);
                } elseif (in_array($tilstand, ['TERMINATED', 'ABORTED', 'EXPIRED'], true)) {
                    DB::kjor(
                        "UPDATE payments SET status = 'avbrutt' WHERE vipps_reference = :r AND status <> 'betalt'",
                        ['r' => $ref]
                    );
                }
            } catch (Throwable $e) {
                logg_feil('Kunne ikke sjekke betaling ' . $ref, $e);
            }
        }
    }
}
