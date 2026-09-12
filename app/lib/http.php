<?php
/**
 * Inn og ut av endepunktene: lese JSON-kropp, svare med JSON, sette
 * sikkerhetsheadere.
 */

declare(strict_types=1);

final class Svar
{
    /**
     * @param array<string,mixed>|list<mixed> $data
     * @param int|null $mellomlagreSekunder Antall sekunder svaret kan mellomlagres.
     *   Standard er null — ingen mellomlagring, som er riktig for alt som
     *   avhenger av hvem som spor. Kun aapne, upersonlige svar bor sette det.
     */
    public static function json(array $data, int $status = 200, ?int $mellomlagreSekunder = null): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header($mellomlagreSekunder === null
                ? 'Cache-Control: no-store'
                : 'Cache-Control: public, max-age=' . $mellomlagreSekunder);
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = []): never
    {
        self::json(['ok' => true] + $data);
    }

    /**
     * Svar foerst, jobb etterpaa.
     *
     * Eieren, 12. september 2026: «jeg kan klikke og faar velge, men den
     * laster ikke opp noen dokumenter, ingen feilmelding».
     *
     * Grunnen: opplastingen ba Claude lese de skannede arkene FOR den
     * svarte. Ett AI-kall har 120 sekunders tidsavbrudd, og tre av dem etter
     * hverandre er seks minutter. Nettleseren satt og ventet paa et svar som
     * aldri kom — og en foresporsel som henger gir ingen feilmelding, fordi
     * ingenting feilet. Det saa ut som om ingenting skjedde.
     *
     * Her sendes svaret ferdig, forbindelsen lukkes, og foerst DA gjores
     * resten. Da er skjermen oppdatert med én gang, uansett hvor lenge
     * etterarbeidet tar.
     *
     * «fastcgi_finish_request» finnes bare paa php-fpm. Finnes den ikke, er
     * det ingen maate aa slippe nettleseren fri paa, og da gjor vi ikke
     * etterarbeidet i det hele tatt — det er bedre at det venter til natta
     * enn at eieren sitter og ser paa en skjerm som ikke rikker seg.
     *
     * @param callable(): void $etterpaa kjores bare naar svaret er levert
     */
    public static function okOgFortsett(array $data, callable $etterpaa): never
    {
        if (!function_exists('fastcgi_finish_request')) {
            self::ok($data);
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
        }
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        fastcgi_finish_request();

        // Etterarbeidet skal aldri kunne velte noe: svaret er alt sendt, og
        // et kast her ville bare endt i feilloggen som en halv forespoersel.
        try {
            $etterpaa();
        } catch (Throwable $e) {
            logg_feil('Etterarbeidet etter svaret stoppet', $e);
        }
        exit;
    }

    /** Feil som skal vises til kunden. Aldri teknisk detalj her. */
    public static function feil(string $melding, int $status = 400, array $ekstra = []): never
    {
        self::json(['ok' => false, 'feil' => $melding] + $ekstra, $status);
    }

    public static function omdiriger(string $url, int $status = 302): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . $url);
        }
        exit;
    }
}

final class Foresporsel
{
    /** @var array<string,mixed>|null */
    private static ?array $kropp = null;

    public static function metode(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Avviser alt annet enn metodene som er listet opp. */
    public static function krevMetode(string ...$tillatte): void
    {
        if (!in_array(self::metode(), array_map('strtoupper', $tillatte), true)) {
            header('Allow: ' . implode(', ', $tillatte));
            Svar::feil('Metoden støttes ikke her.', 405);
        }
    }

    /** @return array<string,mixed> */
    public static function kropp(): array
    {
        if (self::$kropp !== null) {
            return self::$kropp;
        }
        $raa = file_get_contents('php://input');
        if ($raa === false || $raa === '') {
            return self::$kropp = $_POST;
        }
        $d = json_decode($raa, true);
        return self::$kropp = is_array($d) ? $d : $_POST;
    }

    public static function tekst(string $felt, string $standard = ''): string
    {
        $v = self::kropp()[$felt] ?? $_GET[$felt] ?? $standard;
        return is_scalar($v) ? trim((string) $v) : $standard;
    }

    public static function heltall(string $felt, int $standard = 0): int
    {
        $v = self::kropp()[$felt] ?? $_GET[$felt] ?? $standard;
        return is_numeric($v) ? (int) $v : $standard;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** IP pakket som binærverdi, klar for VARBINARY(16)-kolonnene. */
    public static function ipBinaer(): ?string
    {
        $p = @inet_pton(self::ip());
        return $p === false ? null : $p;
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /**
     * Enkelt CSRF-vern for skjemaposter fra vår egen side. Sesjonscookien er
     * SameSite=Lax, som stopper de fleste angrep, men Origin-sjekken tar resten.
     */
    public static function krevSammeOpphav(): void
    {
        if (self::metode() === 'GET') {
            return;
        }
        $opphav = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($opphav === '' && isset($_SERVER['HTTP_REFERER'])) {
            $deler = parse_url((string) $_SERVER['HTTP_REFERER']);
            if (isset($deler['scheme'], $deler['host'])) {
                $opphav = $deler['scheme'] . '://' . $deler['host']
                        . (isset($deler['port']) ? ':' . $deler['port'] : '');
            }
        }
        if ($opphav === '') {
            return; // Ingen nettleser sender forespørselen — f.eks. Vipps sin webhook.
        }
        // Adressen siden faktisk kjorer paa er alltid tillatt. Uten dette maa
        // lista i secrets.php holdes i takt med hvor nettstedet ligger, og da
        // avviser serveren sin egen nettside den dagen den flytter.
        $tillatte = array_map(
            static fn($u) => rtrim((string) $u, '/'),
            array_merge(
                [Config::nettsted()],
                (array) Config::hent('tillatte_opphav', [])
            )
        );
        if (!in_array(rtrim($opphav, '/'), $tillatte, true)) {
            logg('Avviste forespørsel fra ukjent opphav', ['opphav' => $opphav]);
            Svar::feil('Foresporselen kom fra et ukjent nettsted.', 403);
        }
    }
}
