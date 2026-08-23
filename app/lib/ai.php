<?php
/**
 * Claude API — teksten AI-en skriver for markedsforingen.
 *
 * Kaller https://api.anthropic.com/v1/messages direkte med curl. Ingen
 * pakkebehandler paa webhotellet, saa ingen SDK; det er ogsaa greit, for vi
 * trenger bare ett endepunkt.
 *
 * To ting er viktigere her enn andre steder i denne kodebasen:
 *
 *   1. Ingenting oppdiktes. Er noekkelen ikke satt, sier vi det — vi lager
 *      ikke en «eksempeltekst» som ser ut som noe AI-en skrev.
 *   2. Hvert kall koster penger. Derfor logges hvert eneste kall med tokens
 *      og anslaatt kostnad, og det finnes et tak per maaned som eieren setter
 *      selv. Naar taket er naadd, stopper kallene.
 */

declare(strict_types=1);

final class AI
{
    /** Modellen vi bruker. Bytter du den, husk prisene under. */
    public const MODELL = 'claude-opus-5';

    /** Dollar per million tokens for modellen over. */
    private const PRIS_INN_USD  = 5.00;
    private const PRIS_UT_USD   = 25.00;

    /**
     * Kroner per dollar, til kostnadsanslaget.
     *
     * Ingen kurs hentes; tallet er et anslag som skal gi eieren en folelse av
     * storrelsesorden, ikke et regnskapstall. Alle steder det vises staar det
     * «ca.».
     */
    private const KRONER_PER_DOLLAR = 11.0;

    /** Tak per maaned i kroner, om eieren ikke har satt sitt eget. */
    private const TAK_STANDARD_KR = 300;

    /**
     * Hva det siste kallet kostet, i ore.
     *
     * sporJson() gir fra seg selve svaret, og da forsvinner kostnaden. Den
     * som lagrer utkastet trenger den — ellers staar alle utkast med null,
     * og forbruksoversikten stemmer ikke med loggen.
     */
    private static int $sisteOre = 0;

    public static function sisteKostnad(): int
    {
        return self::$sisteOre;
    }

    public static function noekkel(): string
    {
        return trim((string) Config::hent('claude_api_key', ''));
    }

    public static function tilgjengelig(): bool
    {
        return self::noekkel() !== '';
    }

    /** Taket eieren har satt, i kroner. */
    public static function tak(): int
    {
        $lagret = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Marked/AI-tak'");
        $tall = (int) preg_replace('/\D+/', '', (string) $lagret);
        return $tall > 0 ? $tall : self::TAK_STANDARD_KR;
    }

    /** Brukt hittil denne maaneden, i ore. Regnes i norsk tid. */
    public static function bruktDenneMaaneden(): int
    {
        $oslo  = new DateTimeZone('Europe/Oslo');
        $start = (new DateTimeImmutable('now', $oslo))->modify('first day of this month')->setTime(0, 0);
        return (int) DB::verdi(
            'SELECT COALESCE(SUM(kostnad_ore), 0) FROM ai_logg WHERE created_at >= :fra',
            ['fra' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Status til skjermen: er AI-en klar, og hvor mye er brukt?
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        $tak   = self::tak();
        $brukt = self::bruktDenneMaaneden();
        return [
            'klar'       => self::tilgjengelig(),
            'modell'     => self::MODELL,
            'tak'        => Booking::kroner($tak * 100),
            'takOre'     => $tak * 100,
            'brukt'      => Booking::kroner($brukt),
            'bruktOre'   => $brukt,
            'andel'      => $tak > 0 ? min(100, (int) round($brukt / ($tak * 100) * 100)) : 0,
            'overTaket'  => $brukt >= $tak * 100,
            'kall'       => (int) DB::verdi('SELECT COUNT(*) FROM ai_logg'),
            // Uten noekkel er det ingenting aa lure paa — si hva som mangler.
            'mangler'    => self::tilgjengelig()
                ? null
                : 'Legg inn claude_api_key i secrets.php. Nøkkelen lages på console.anthropic.com.',
        ];
    }

    /**
     * Ett kall til modellen.
     *
     * @param  string $system   rollen — hvem skriver, og for hvem
     * @param  string $bruker   selve oppgaven
     * @param  string $formal   hva dette gjelder, til loggen
     * @return array{tekst:string,kostnadOre:int,tokensInn:int,tokensUt:int}
     * @throws RuntimeException med en tekst som kan vises til eieren
     */
    public static function spor(string $system, string $bruker, string $formal, int $maksTokens = 8000): array
    {
        $noekkel = self::noekkel();
        if ($noekkel === '') {
            throw new RuntimeException(
                'AI-en er ikke koblet til ennå. Legg inn claude_api_key i secrets.php, '
                . 'så virker knappene med én gang.'
            );
        }

        $tak = self::tak();
        if (self::bruktDenneMaaneden() >= $tak * 100) {
            throw new RuntimeException(
                'Taket på ' . Booking::kroner($tak * 100) . ' for denne måneden er nådd. '
                . 'Du kan heve det under Markedsføring → Innstillinger.'
            );
        }

        $kropp = [
            'model'      => self::MODELL,
            'max_tokens' => $maksTokens,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $bruker]],
        ];

        $svar = http_kall(
            'https://api.anthropic.com/v1/messages',
            'POST',
            json_encode($kropp, JSON_UNESCAPED_UNICODE),
            [
                'Content-Type: application/json',
                'x-api-key: ' . $noekkel,
                'anthropic-version: 2023-06-01',
            ],
            // Modellen kan tenke lenge paa en lang artikkel. Tjue sekunder,
            // som er standarden ellers i huset, er altfor kort her.
            120
        );

        $json = json_decode($svar['kropp'], true);

        if ($svar['status'] !== 200) {
            $type = $json['error']['type'] ?? '';
            $melding = (string) ($json['error']['message'] ?? 'Ukjent feil');
            self::logg($formal, 0, 0, 0, false, $type . ': ' . $melding);

            // Oversett de tre som faktisk skjer, til noe eieren kan gjore noe med.
            throw new RuntimeException(match (true) {
                $svar['status'] === 401 => 'Nøkkelen ble ikke godtatt. Sjekk claude_api_key i secrets.php.',
                $svar['status'] === 429 => 'For mange kall på kort tid. Vent et minutt og prøv igjen.',
                $svar['status'] >= 500  => 'Anthropic svarer ikke akkurat nå. Prøv igjen om litt.',
                str_contains($melding, 'credit') || str_contains($melding, 'billing')
                    => 'Kontoen hos Anthropic har ikke dekning. Fyll på under Billing på console.anthropic.com.',
                default => 'AI-en svarte ikke: ' . $melding,
            });
        }

        // Svaret er en liste med blokker. Vi vil ha teksten, og bare den.
        $tekst = '';
        foreach (($json['content'] ?? []) as $blokk) {
            if (($blokk['type'] ?? '') === 'text') {
                $tekst .= $blokk['text'];
            }
        }
        $tekst = trim($tekst);

        $inn = (int) ($json['usage']['input_tokens'] ?? 0);
        $ut  = (int) ($json['usage']['output_tokens'] ?? 0);
        $ore = self::kostnadOre($inn, $ut);

        self::logg($formal, $inn, $ut, $ore, true, null);
        self::$sisteOre = $ore;

        if ($tekst === '') {
            throw new RuntimeException('AI-en svarte tomt. Prøv igjen.');
        }

        return ['tekst' => $tekst, 'kostnadOre' => $ore, 'tokensInn' => $inn, 'tokensUt' => $ut];
    }

    /**
     * Som spor(), men ber om JSON tilbake og gir det som liste.
     *
     * Modellen far beskjed om aa svare med bare JSON. Skulle den likevel
     * ramme det inn i ```json ... ```, klipper vi det bort — det er billigere
     * enn aa kalle en gang til.
     *
     * @return array<mixed>
     */
    public static function sporJson(string $system, string $bruker, string $formal, int $maksTokens = 8000): array
    {
        $r = self::spor(
            $system . "\n\nSvar med gyldig JSON og ingenting annet. Ingen forklaring, ingen kodeblokk.",
            $bruker,
            $formal,
            $maksTokens
        );

        $t = trim($r['tekst']);
        if (str_starts_with($t, '```')) {
            $t = preg_replace('/^```[a-z]*\s*|\s*```$/', '', $t) ?? $t;
        }

        $data = json_decode(trim($t), true);
        if (!is_array($data)) {
            throw new RuntimeException('AI-en svarte noe vi ikke klarte å lese. Prøv igjen.');
        }
        return $data;
    }

    /** Anslaatt kostnad i ore for et kall. */
    public static function kostnadOre(int $inn, int $ut): int
    {
        $usd = ($inn / 1_000_000) * self::PRIS_INN_USD + ($ut / 1_000_000) * self::PRIS_UT_USD;
        return (int) round($usd * self::KRONER_PER_DOLLAR * 100);
    }

    private static function logg(string $formal, int $inn, int $ut, int $ore, bool $ok, ?string $feil): void
    {
        DB::settInn('ai_logg', [
            'formal'      => mb_substr($formal, 0, 64),
            'modell'      => self::MODELL,
            'tokens_inn'  => $inn,
            'tokens_ut'   => $ut,
            'kostnad_ore' => $ore,
            'ok'          => $ok ? 1 : 0,
            'feil'        => $feil === null ? null : mb_substr($feil, 0, 500),
        ]);
    }

    /**
     * Fakta om verkstedet som foelger med hvert kall.
     *
     * Uten dette skriver modellen generisk keramikktekst som kunne handlet om
     * hvilket som helst verksted. Med det skriver den om Lissom.
     */
    public static function omLissom(): string
    {
        $kurs = DB::alle(
            "SELECT tittel, tema, pris_ore, type FROM courses WHERE status = 'publisert' ORDER BY type, tittel"
        );
        $linjer = [];
        foreach ($kurs as $k) {
            $linjer[] = '- ' . $k['tittel'] . ' (' . $k['type'] . ', ' . Booking::kroner((int) $k['pris_ore']) . ')';
        }

        $planer = [];
        foreach (Medlemskap::planer() as $p) {
            $planer[] = '- ' . $p['navn'] . ': ' . Booking::kroner((int) $p['pris_ore'])
                . ($p['timer'] === null ? ', fri tilgang' : ', ' . $p['timer'] . ' timer i måneden');
        }

        return "Om verkstedet du skriver for:\n"
            . "Lissom Keramikk & Håndverk AS, Nordre Løkkevei 15, 3120 Nøtterøy (Teie, rett utenfor Tønsberg).\n"
            . "Telefon +47 94 13 46 01, e-post post@lissom.no, Instagram @lissom_keramikk.\n"
            . "Et kreativt fristed for skaperglede, håndverk og fellesskap. Ingen forkunnskaper nødvendig.\n"
            . "Leire, verktøy og brenning er inkludert i kursene.\n\n"
            . "Kurs og events vi holder:\n" . (implode("\n", $linjer) ?: '- (ingen lagt ut ennå)') . "\n\n"
            . "Medlemskap:\n" . (implode("\n", $planer) ?: '- (ingen lagt inn)') . "\n";
    }

    /** Skrivereglene. Samme stemme uansett hvilken knapp som ble trykket. */
    public static function stemme(): string
    {
        return "Slik skal du skrive:\n"
            . "- Norsk bokmål. Naturlig, varmt og konkret — som et lite verksted som kjenner kundene sine.\n"
            . "- Aldri salgsspråk eller floskler. Ikke «unik opplevelse», ikke «ta kontakt i dag!», ikke utropstegn på rekke.\n"
            . "- Skriv om det som faktisk skjer i verkstedet: leira, hendene, ovnen, folkene.\n"
            . "- Ingen påstander du ikke har dekning for i fakta over. Finn aldri på priser, datoer eller antall.\n"
            . "- Er noe uklart, skriv rundt det framfor å gjette.\n";
    }
}
