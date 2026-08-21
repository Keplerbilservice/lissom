<?php
/**
 * Sesjoner.
 *
 * Erstatter den gamle `lissom_bruker`-cookien, som bare var base64 av navn og
 * e-post — lesbar og skrivbar for hvem som helst i nettleserkonsollen.
 *
 * Nå: et tilfeldig token i en HttpOnly-cookie. Databasen lagrer kun SHA-256 av
 * tokenet, så en lekket database gir ingen gyldige innlogginger.
 */

declare(strict_types=1);

final class Sesjon
{
    public const COOKIE = 'lissom_sesjon';
    // Tre timer uten aktivitet, saa er du ute. Min side viser betalinger,
    // kontaktopplysninger og medlemskap, og verkstedet har maskiner flere
    // deler paa. En sesjon som varer i ukevis paa en felles nettleser er en
    // reell risiko, ikke en teoretisk.
    //
    // Klokka nullstilles ved bruk: er du aktiv, blir du sittende.
    private const VARIGHET_TIMER = 3;

    /** @var array<string,mixed>|null|false false = ikke slått opp ennå */
    private static array|null|false $medlem = false;

    /** Oppretter sesjon og setter cookien. Returnerer tokenet. */
    public static function opprett(int $medlemId): string
    {
        $token = bin2hex(random_bytes(32));
        $utloper = new DateTimeImmutable('+' . self::VARIGHET_TIMER . ' hours', new DateTimeZone('UTC'));

        DB::settInn('sessions', [
            'token_hash' => hash('sha256', $token),
            'member_id'  => $medlemId,
            'expires_at' => $utloper->format('Y-m-d H:i:s'),
            'ip'         => Foresporsel::ipBinaer(),
            'user_agent' => Foresporsel::userAgent(),
        ]);

        self::settCookie($token, $utloper->getTimestamp());

        // setcookie() fyller ikke $_COOKIE i den samme forespørselen. Uten
        // dette ville alt som spør «hvem er innlogget?» rett etter innlogging
        // — for eksempel revisjonsloggen — fått «ingen».
        $_COOKIE[self::COOKIE] = $token;
        self::$medlem = false;

        return $token;
    }

    /** Medlemmet som er logget inn, eller null. */
    public static function medlem(): ?array
    {
        if (self::$medlem !== false) {
            return self::$medlem;
        }

        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || strlen($token) !== 64) {
            return self::$medlem = null;
        }

        $rad = DB::en(
            'SELECT m.*, s.token_hash
               FROM sessions s
               JOIN members m ON m.id = s.member_id
              WHERE s.token_hash = :h
                AND s.expires_at > UTC_TIMESTAMP()
                AND m.anonymisert_at IS NULL',
            ['h' => hash('sha256', $token)]
        );

        if ($rad === null) {
            return self::$medlem = null;
        }

        // Skyv utløpet framover, men høyst hvert femte minutt — ellers skriver
        // vi til databasen ved hvert eneste sidevisning. Fem minutter er kort
        // nok til at en aktiv bruker aldri faller ut av en tretimersfrist.
        DB::kjor(
            'UPDATE sessions
                SET siste_bruk = UTC_TIMESTAMP(),
                    expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :t HOUR)
              WHERE token_hash = :h
                AND siste_bruk < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)',
            ['t' => self::VARIGHET_TIMER, 'h' => $rad['token_hash']]
        );

        unset($rad['token_hash']);
        return self::$medlem = $rad;
    }

    public static function erInnlogget(): bool
    {
        return self::medlem() !== null;
    }

    public static function erAdmin(): bool
    {
        $m = self::medlem();
        if ($m === null) {
            return false;
        }
        if (($m['rolle'] ?? '') === 'admin') {
            return true;
        }
        // Nødluke: numre i secrets.php er alltid admin, også om databasen er tom.
        $tlf = normaliser_telefon((string) ($m['telefon'] ?? ''));
        return $tlf !== '' && in_array($tlf, Config::adminNumre(), true);
    }

    public static function avslutt(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($token) && strlen($token) === 64) {
            DB::kjor('DELETE FROM sessions WHERE token_hash = :h', ['h' => hash('sha256', $token)]);
        }
        self::settCookie('', time() - 3600);
        self::$medlem = null;
    }

    /** Logger ut medlemmet overalt — brukes ved oppsigelse og ved mistanke om misbruk. */
    public static function avsluttAlleFor(int $medlemId): void
    {
        DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => $medlemId]);
    }

    public static function ryddUtlopte(): int
    {
        return DB::kjor('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP()')->rowCount();
    }

    private static function settCookie(string $verdi, int $utloper): void
    {
        setcookie(self::COOKIE, $verdi, [
            'expires'  => $utloper,
            'path'     => '/',
            // HttpOnly: JavaScript kommer ikke til. Frontenden henter i stedet
            // profilen sin fra /api/me.
            'httponly' => true,
            'secure'   => !Config::erUtvikling(),
            'samesite' => 'Lax',
        ]);
    }
}
