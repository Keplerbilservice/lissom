<?php
/**
 * Messevisningen for bedrifter på iPad (eieren, GO 9. oktober 2026:
 * «legg dette ut på en link, med samme PIN som kassen»).
 *
 *   - /messe (messe.php) viser PIN-skjermen til iPaden har låst opp, og
 *     deretter visningen. Siden og visningen sendes bare fra serveren; ingen
 *     PIN i klientkoden.
 *   - PIN-en er kassens: personene under Brukere med kasse-PIN
 *     (KasseTilgang::personForPin). Ingen egen PIN.
 *   - Riktig PIN gir en informasjonskapsel i 30 dager. Den er opak (ingen
 *     medlems-id) og signert med app-hemmeligheten og PIN-hashen til personen
 *     som låste opp: endres eller fjernes PIN-en,
 *     eller mister personen tilgangen, må iPaden låses opp på nytt.
 *   - Skjemaet bak QR-koden er /bedrift/tilbud (bedrift-tilbud.php). Det
 *     sender til api/foresporsel.php som andre forespørsler.
 *   - Bryteren «Vis/messe» (content_blocks). Mangler raden, er den på;
 *     «nei» slår av både /messe og skjemaet.
 */

declare(strict_types=1);

final class Messe
{
    public const COOKIE = 'lissom_messe';
    public const DAGER = 30;

    /** PIN-forsøk per IP (IPv6: per /64) per fem minutter. */
    public const PIN_FORSOK = 10;

    /** PIN-forsøk fra alle til sammen per fem minutter. */
    public const PIN_FORSOK_ALLE = 30;

    /** Så mange feil PIN i timen (alle til sammen) stenger opplåsingen ut timen. */
    public const STENG_ETTER_FEIL = 50;

    public static function paa(): bool
    {
        try {
            if (!DB::harTabell('content_blocks')) {
                return true;
            }
            $v = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => 'Vis/messe']);
        } catch (Throwable $e) {
            return true;
        }
        return $v === null || $v === false || (string) $v !== 'nei';
    }

    /** Har denne iPaden låst opp visningen (gyldig, signert informasjonskapsel)? */
    public static function ulast(): bool
    {
        // Opak: utløp, tilfeldig nonce og signatur. Ingen medlems-id i klartekst
        // (kontrolløren 9. oktober 2026); personen finnes ved å prøve
        // signaturen mot hver PIN-hash (HMAC, billig — ikke password_verify).
        $raa = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^(\d{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/', $raa, $m) !== 1) {
            return false;
        }
        [$_, $utloper, $nonce, $sig] = $m;
        if ((int) $utloper < time() || !KasseTilgang::klar()) {
            return false;
        }
        foreach (DB::alle(
            'SELECT id, brukernavn, rolle, telefon, kasse_pin_hash FROM members
              WHERE kasse_pin_hash IS NOT NULL AND anonymisert_at IS NULL'
        ) as $rad) {
            if (hash_equals(self::signer($nonce, (int) $utloper, (string) $rad['kasse_pin_hash']), $sig)) {
                return KasseTilgang::harTilgang($rad);
            }
        }
        return false;
    }

    /**
     * Låser opp med kassens PIN og setter informasjonskapselen. Feil PIN
     * svarer 400; for mange forsøk 429.
     *
     * Grensene sjekkes FØR PIN-en prøves (password_verify mot alle med PIN
     * koster CPU): per IP (IPv6 per /64), samlet for alle, og en midlertidig
     * stengning etter mange feil i timen (kontrolløren 9. oktober 2026).
     */
    public static function laasOpp(string $pin): void
    {
        if (self::feilDenneTimen() >= self::STENG_ETTER_FEIL) {
            header('Retry-After: 3600');
            Svar::feil('For mange forsøk. Vent 60 minutter og prøv igjen.', 429);
        }
        Rate::sjekk('messe-pin', self::PIN_FORSOK, 300, self::ipNokkel());
        Rate::sjekk('messe-pin-alle', self::PIN_FORSOK_ALLE, 300, 'alle');

        $person = KasseTilgang::personForPin($pin);
        if ($person === null) {
            logg('Feil PIN på messevisningen', ['ip' => self::ipNokkel()]);
            if (!Rate::tillat('messe-pin-feil', self::STENG_ETTER_FEIL - 1, 3600, 'alle')) {
                logg_feil('Messevisningen er stengt for PIN i inntil en time: for mange feil PIN');
            }
            Svar::feil('Feil PIN – prøv igjen', 400, ['feilPin' => true]);
        }
        $utloper = time() + self::DAGER * 86400;
        $nonce = bin2hex(random_bytes(16));
        setcookie(self::COOKIE, $utloper . '.' . $nonce . '.' . self::signer($nonce, $utloper, $person['hash']), [
            'expires'  => $utloper,
            'path'     => '/',
            'httponly' => true,
            'secure'   => !Config::erUtvikling(),
            'samesite' => 'Lax',
        ]);
        revider('messe_laast_opp', 'member', $person['id']);
    }

    /** Feil PIN så langt i denne timen (alle iPader), uten å telle opp. */
    private static function feilDenneTimen(): int
    {
        $start = date('Y-m-d H:i:s', intdiv(time(), 3600) * 3600);
        return (int) DB::verdi('SELECT antall FROM rate_limits WHERE nokkel = :n AND vindu_start = :v',
            ['n' => 'messe-pin-feil:alle', 'v' => $start]);
    }

    /** IP-en grensen gjelder: IPv4 som den er, IPv6 per /64 (én kunde har gjerne hele /64). */
    private static function ipNokkel(): string
    {
        $ip = Foresporsel::ip();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $bin = inet_pton($ip);
            if ($bin !== false) {
                return bin2hex(substr($bin, 0, 8)) . '/64';
            }
        }
        return $ip;
    }

    /**
     * Nøkkelen er app-hemmeligheten pluss PIN-hashen: kapselen kan ikke lages
     * uten serverens hemmelighet, og den slutter å gjelde når PIN-en endres.
     * «app_hemmelighet» i secrets.php hvis den finnes, ellers avledet av
     * serverhemmelighetene som alltid finnes der (databasepassord, cron-nøkkel).
     */
    private static function signer(string $nonce, int $utloper, string $pinHash): string
    {
        $app = (string) Config::hent('app_hemmelighet', '');
        if ($app === '') {
            $app = hash('sha256', 'lissom-messe|' . (string) Config::hent('db_passord', '') . '|' . (string) Config::hent('cron_nokkel', ''));
        }
        return hash_hmac('sha256', 'messe|' . $nonce . '|' . $utloper, $app . '|' . $pinHash);
    }

    /** Svar for en side som ikke skal finnes (bryteren av). */
    public static function ikkeFunnet(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo 'Fant ikke siden.';
        exit;
    }
}
