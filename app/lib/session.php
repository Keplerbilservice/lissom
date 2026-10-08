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

    /**
     * Kassa på iPaden (eieren, 8. oktober 2026): logges inn én gang og holder
     * seg innlogget i 30 dager. Den som står i kassa låser opp med PIN, og
     * kassa låser seg selv etter 5 minutter uten bruk (Kasse::ulast()). Den
     * lange økta gir ingen tilgang til noe annet enn api/kasse/ — se
     * kasseSti() under.
     */
    public const KASSE_VARIGHET_TIMER = 720;

    /** @var array<string,mixed>|null|false false = ikke slått opp ennå */
    private static array|null|false $medlem = false;

    /** Timene en sesjon varer for denne rollen. */
    private static function varighetFor(string $rolle): int
    {
        return $rolle === 'kasse' ? self::KASSE_VARIGHET_TIMER : self::VARIGHET_TIMER;
    }

    /**
     * Får kassebrukeren lov til å være innlogget i dette skriptet?
     *
     * Kassebrukeren er en rad i members, og ville ellers vært «innlogget» i
     * alle endepunktene som spør Sesjon::medlem() — Min side, booking,
     * kjøp. Her er den bare synlig i api/kasse/ og i inn- og utloggingen.
     * Overalt ellers er den ingen, og får 401 eller 404 som en fremmed.
     */
    private static function kasseSti(): bool
    {
        $fil = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $navn = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        foreach ([$fil, $navn] as $s) {
            if ($s === '') {
                continue;
            }
            if (preg_match('~/api/kasse/[a-z-]+\.php$~', $s) === 1
                || preg_match('~/api/logg-(inn|ut)\.php$~', $s) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Oppretter sesjon og setter cookien. Returnerer tokenet.
     *
     * @param string $maate 'vipps' eller 'passord'. Adminpanelet krever
     *   passord, saa sesjonen maa huske hvilken vei man kom inn.
     */
    public static function opprett(int $medlemId, string $maate = 'vipps'): string
    {
        $token = bin2hex(random_bytes(32));
        $timer = self::varighetFor((string) (DB::verdi('SELECT rolle FROM members WHERE id = :i', ['i' => $medlemId]) ?? ''));
        $utloper = new DateTimeImmutable('+' . $timer . ' hours', new DateTimeZone('UTC'));

        $rad = [
            'token_hash' => hash('sha256', $token),
            'member_id'  => $medlemId,
            'expires_at' => $utloper->format('Y-m-d H:i:s'),
            'ip'         => Foresporsel::ipBinaer(),
            'user_agent' => Foresporsel::userAgent(),
        ];
        // Kolonna kommer med migrasjon 030. Er den ikke kjort ennaa, skal
        // innloggingen virke likevel — migrasjonene kjores fra adminpanelet,
        // og kommer man ikke inn, kommer de aldri til aa bli kjort.
        if (DB::harKolonne('sessions', 'maate')) {
            $rad['maate'] = $maate === 'passord' ? 'passord' : 'vipps';
        }
        DB::settInn('sessions', $rad);

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

        $maateFelt = DB::harKolonne('sessions', 'maate') ? ', s.maate AS innlogging_maate' : '';
        $rad = DB::en(
            'SELECT m.*, s.token_hash' . $maateFelt . '
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

        // Kassebrukeren finnes bare i kassa (se kasseSti()).
        $rolle = (string) ($rad['rolle'] ?? '');
        if ($rolle === 'kasse' && !self::kasseSti()) {
            return self::$medlem = null;
        }
        $timer = self::varighetFor($rolle);

        // Skyv utløpet framover, men høyst hvert femte minutt — ellers skriver
        // vi til databasen ved hvert eneste sidevisning. Fem minutter er kort
        // nok til at en aktiv bruker aldri faller ut av en tretimersfrist.
        $skjovet = DB::kjor(
            'UPDATE sessions
                SET siste_bruk = UTC_TIMESTAMP(),
                    expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :t HOUR)
              WHERE token_hash = :h
                AND siste_bruk < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)',
            ['t' => $timer, 'h' => $rad['token_hash']]
        );

        // ── Cookien maa skyves med ────────────────────────────────────
        //
        // Eieren, 17. september 2026: «jeg faar fortsatt denne jaevla
        // meldinga hver gang jeg skal logge meg inn» — «Du er logget ut.
        // Innloggingen varer i tre timer.»
        //
        // Raden over ble skjovet ved bruk, men cookien ble satt ÉN gang, ved
        // innlogging, med utloep tre timer fram. Nettleseren kastet den
        // altsaa presis tre timer etter innlogging, uansett hvor mye man
        // hadde brukt sida. «Tre timer uten aktivitet» var i praksis «tre
        // timer», og den som jobber en hel dag ble kastet ut midt i.
        //
        // Naa foelger cookien raden. Bare naar raden faktisk ble skjovet —
        // ellers skriver vi en header ved hvert eneste sidevisning.
        //
        // «headers_sent»: kallet kommer tidlig i alle endepunktene, men det
        // skal ikke koste en advarsel midt i et svar om noen kaller det sent.
        if ($skjovet->rowCount() > 0 && !headers_sent()) {
            self::settCookie($token, time() + $timer * 3600);
        }

        unset($rad['token_hash']);
        return self::$medlem = $rad;
    }

    public static function erInnlogget(): bool
    {
        return self::medlem() !== null;
    }

    /**
     * Har den innloggede adminrettigheter akkurat naa?
     *
     * Adminpanelet krever brukernavn og passord. Vipps beviser hvem du er,
     * men telefonen din ligger gjerne ulaast paa et bord — og et adminpanel
     * med kundedata og betalinger skal ikke staa aapent bak en app som
     * allerede er logget inn.
     *
     * Unntaket er en konto uten passord. Uten det ville den forste
     * administratoren aldri kommet inn for aa sette et — og en eier som
     * mister passordet ville vaert laast ute for godt. Da kommer man inn med
     * Vipps, og adminpanelet sier fra om at passordet mangler.
     */
    public static function erAdmin(): bool
    {
        $m = self::medlem();
        if ($m === null) {
            return false;
        }
        if (!self::kanVaereAdmin($m)) {
            return false;
        }
        if (trim((string) ($m['passord_hash'] ?? '')) === '') {
            return true;   // ingen passord satt ennaa — se over
        }
        // Vet vi ikke hvordan sesjonen ble til — migrasjon 030 er ikke kjort —
        // gjelder den gamle regelen. Ellers ville hele adminpanelet vaert
        // stengt, ogsaa for den som skal kjore migrasjonen.
        if (!array_key_exists('innlogging_maate', $m)) {
            return true;
        }
        return ($m['innlogging_maate'] ?? 'vipps') === 'passord';
    }

    /**
     * Regnskapsfoereren.
     *
     * Eieren, 1. september: «jeg oensker aa lage en bruker log in til min
     * regnskapsoerer». Hun ser OEkonomi og betalingene, og ingenting annet.
     *
     * Samme krav til innlogging som admin: rollen gjelder bare naar man kom
     * inn med passord. Da kan ikke en Vipps-innlogging paa samme nummer gi
     * tilgang til regnskapet.
     *
     * En admin er ogsaa «regnskap» — hun ser alt uansett, og da skal ikke
     * hvert endepunkt trenge to sjekker.
     */
    public static function erRegnskap(): bool
    {
        if (self::erAdmin()) {
            return true;
        }
        $m = self::medlem();
        if ($m === null || ($m['rolle'] ?? '') !== 'regnskap') {
            return false;
        }
        // Uten migrasjon 030 vet vi ikke hvordan sesjonen ble til. Da er det
        // riktigere aa si nei enn aa slippe inn paa et ukjent grunnlag.
        return ($m['innlogging_maate'] ?? '') === 'passord';
    }

    /**
     * Kassa på iPaden (rollen «kasse», migrasjon 263).
     *
     * Samme krav som regnskapet: rollen gjelder bare når man kom inn med
     * brukernavn og passord. Kassebrukeren er aldri admin og aldri
     * regnskap — den slipper bare inn i api/kasse/.
     */
    public static function erKasse(): bool
    {
        $m = self::medlem();
        return $m !== null
            && ($m['rolle'] ?? '') === 'kasse'
            && ($m['innlogging_maate'] ?? '') === 'passord';
    }

    /** SHA-256 av tokenet i cookien, eller null. Det sesjonsraden er lagret på. */
    public static function tokenHash(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        return is_string($token) && strlen($token) === 64 ? hash('sha256', $token) : null;
    }

    /** Er dette en konto som *kan* vaere admin, uavhengig av innloggingsmaate? */
    public static function kanVaereAdmin(array $m): bool
    {
        if (($m['rolle'] ?? '') === 'admin') {
            return true;
        }
        // Nødluke: numre i secrets.php er alltid admin, også om databasen er tom.
        $tlf = normaliser_telefon((string) ($m['telefon'] ?? ''));
        return $tlf !== '' && in_array($tlf, Config::adminNumre(), true);
    }

    /** Mangler denne kontoen et passord den burde hatt? */
    public static function adminUtenPassord(): bool
    {
        $m = self::medlem();
        return $m !== null
            && self::kanVaereAdmin($m)
            && trim((string) ($m['passord_hash'] ?? '')) === '';
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

    /**
     * Logger ut medlemmet paa alle andre nettlesere enn denne.
     *
     * Brukes naar passordet byttes. En sesjon noen har stjaalet, skal ikke
     * overleve at passordet blir skiftet — men den som bytter sitt eget
     * passord, skal ikke kastes ut av sin egen fane (Codex-gjennomgangen
     * 30.09.2026).
     */
    public static function avsluttAndreFor(int $medlemId): int
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        $hash = is_string($token) && strlen($token) === 64 ? hash('sha256', $token) : '';
        return DB::kjor(
            'DELETE FROM sessions WHERE member_id = :m AND token_hash <> :h',
            ['m' => $medlemId, 'h' => $hash]
        )->rowCount();
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
