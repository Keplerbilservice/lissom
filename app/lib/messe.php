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
 *   - Riktig PIN gir en informasjonskapsel i 30 dager. Den er signert med
 *     PIN-hashen til personen som låste opp: endres eller fjernes PIN-en,
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

    /** PIN-forsøk per IP per fem minutter. */
    public const PIN_FORSOK = 10;

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
        $raa = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^(\d{1,12})\.(\d{10})\.([a-f0-9]{64})$/', $raa, $m) !== 1) {
            return false;
        }
        [$_, $id, $utloper, $sig] = $m;
        if ((int) $utloper < time() || !KasseTilgang::klar()) {
            return false;
        }
        $rad = DB::en(
            'SELECT id, brukernavn, rolle, telefon, kasse_pin_hash FROM members
              WHERE id = :i AND kasse_pin_hash IS NOT NULL AND anonymisert_at IS NULL',
            ['i' => (int) $id]
        );
        if ($rad === null || !KasseTilgang::harTilgang($rad)) {
            return false;
        }
        return hash_equals(self::signer((int) $id, (int) $utloper, (string) $rad['kasse_pin_hash']), $sig);
    }

    /**
     * Låser opp med kassens PIN og setter informasjonskapselen. Feil PIN
     * svarer 400; for mange forsøk 429.
     */
    public static function laasOpp(string $pin): void
    {
        Rate::sjekk('messe-pin', self::PIN_FORSOK, 300);
        $person = KasseTilgang::personForPin($pin);
        if ($person === null) {
            logg('Feil PIN på messevisningen');
            Svar::feil('Feil PIN – prøv igjen', 400, ['feilPin' => true]);
        }
        $hash = (string) DB::verdi('SELECT kasse_pin_hash FROM members WHERE id = :i', ['i' => $person['id']]);
        $utloper = time() + self::DAGER * 86400;
        setcookie(self::COOKIE, $person['id'] . '.' . $utloper . '.' . self::signer($person['id'], $utloper, $hash), [
            'expires'  => $utloper,
            'path'     => '/',
            'httponly' => true,
            'secure'   => !Config::erUtvikling(),
            'samesite' => 'Lax',
        ]);
        revider('messe_laast_opp', 'member', $person['id']);
    }

    private static function signer(int $id, int $utloper, string $pinHash): string
    {
        return hash_hmac('sha256', 'messe|' . $id . '|' . $utloper, $pinHash);
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
