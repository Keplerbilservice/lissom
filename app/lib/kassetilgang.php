<?php
/**
 * Lissom Kasse på iPad: tilgang og PIN (eieren, 8. oktober 2026).
 *
 *   - iPaden logges inn med rollen «kasse» (api/logg-inn.php) og holder seg
 *     innlogget i 30 dager (Sesjon::KASSE_VARIGHET_TIMER). Kassebrukeren finnes
 *     bare i api/kasse/ (Sesjon::kasseSti()).
 *   - Den som står i kassa låser opp med en 4-sifret PIN (members.kasse_pin_hash,
 *     satt av admin under Brukere). Bare personer som står under Brukere
 *     (innlogging, admin, regnskap eller nødluke-nummer) kan ha og bruke en
 *     PIN. Fjernes tilgangen, fjernes PIN-en og kassa låses (fjernFor()).
 *   - Opplåsingen står på sesjonsraden og går ut etter 5 minutter uten bruk.
 *   - Bryteren «Vis/kasse» (content_blocks). Mangler raden, er kassa av.
 *
 * Betalingen: app/lib/kasse.php. Kurven og «I dag»: app/lib/kassekurv.php.
 */

declare(strict_types=1);

final class KasseTilgang
{
    /** Kassa låser seg etter så mange minutter uten bruk. */
    public const LAAS_MINUTTER = 5;

    /** PIN-forsøk per iPad per fem minutter. */
    public const PIN_FORSOK = 10;

    public static function paa(): bool
    {
        if (!DB::harTabell('content_blocks')) {
            return false;
        }
        $v = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => 'Vis/kasse']);
        return $v !== null && $v !== false && (string) $v === 'ja';
    }

    /** Er migrasjon 263 kjørt? */
    public static function klar(): bool
    {
        return DB::harKolonne('sessions', 'kasse_ulast_til') && DB::harKolonne('members', 'kasse_pin_hash');
    }

    /**
     * Står personen under Brukere (og har dermed lov til å ha kasse-PIN)?
     * Samme utvalg som lista i api/admin/brukere.php: innlogging med
     * brukernavn, admin, regnskap, eller nødluke-nummer. Aldri kassekontoen.
     *
     * @param array<string,mixed> $m members-raden (brukernavn, rolle, telefon)
     */
    public static function harTilgang(array $m): bool
    {
        $rolle = (string) ($m['rolle'] ?? '');
        if ($rolle === 'kasse') {
            return false;
        }
        if (trim((string) ($m['brukernavn'] ?? '')) !== '' || in_array($rolle, ['admin', 'regnskap'], true)) {
            return true;
        }
        $tlf = normaliser_telefon((string) ($m['telefon'] ?? ''));
        return $tlf !== '' && in_array($tlf, Config::adminNumre(), true);
    }

    /**
     * Kassekontoen (eller admin, som kan åpne kassa fra PC-en). Alle andre får
     * samme svar som en fremmed.
     *
     * @return array<string,mixed>
     */
    public static function krevKonto(): array
    {
        $m = Sesjon::medlem();
        if ($m === null) {
            Svar::feil('Du må være logget inn.', 401, ['loggInn' => true]);
        }
        if (!Sesjon::erKasse() && !Sesjon::erAdmin()) {
            logg('Avvist kasseforsøk', ['medlem' => $m['id']]);
            Svar::feil('Fant ikke siden.', 404);
        }
        if (!self::klar()) {
            Svar::feil('Kassa krever en oppdatering av databasen. Trykk ⚙ Kjør oppdateringer i admin.', 503);
        }
        if (!self::paa()) {
            Svar::feil('Kassa er ikke slått på.', 403, ['av' => true]);
        }
        return $m;
    }

    /**
     * Personen som har låst opp kassa. Låst = 423. Skyver låsen fem minutter
     * fram: kassa låser seg etter fem minutter UTEN bruk.
     *
     * @return array{id:int, navn:string}
     */
    public static function krevUlast(): array
    {
        self::krevKonto();
        $p = self::ulast();
        if ($p === null) {
            Svar::feil('Kassa er låst.', 423, ['laast' => true]);
        }
        DB::kjor(
            'UPDATE sessions SET kasse_ulast_til = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::LAAS_MINUTTER . ' MINUTE)
              WHERE token_hash = :h',
            ['h' => (string) Sesjon::tokenHash()]
        );
        return $p;
    }

    /**
     * Hvem som har låst opp, uten å skyve låsen. null = låst — også når
     * personen har mistet PIN-en eller tilgangen siden.
     *
     * @return array{id:int, navn:string}|null
     */
    public static function ulast(): ?array
    {
        $h = Sesjon::tokenHash();
        if ($h === null) {
            return null;
        }
        $rad = DB::en(
            'SELECT m.id, m.navn, m.brukernavn, m.rolle, m.telefon
               FROM sessions s
               JOIN members m ON m.id = s.kasse_person_id
              WHERE s.token_hash = :h AND s.kasse_ulast_til > UTC_TIMESTAMP()
                AND m.kasse_pin_hash IS NOT NULL AND m.anonymisert_at IS NULL',
            ['h' => $h]
        );
        if ($rad === null || !self::harTilgang($rad)) {
            return null;
        }
        return ['id' => (int) $rad['id'], 'navn' => (string) $rad['navn']];
    }

    /**
     * Låser opp med PIN. Feil PIN svarer 400; for mange forsøk 429.
     *
     * @return array{id:int, navn:string}
     */
    public static function laasOpp(string $pin): array
    {
        self::krevKonto();
        $h = (string) Sesjon::tokenHash();
        Rate::sjekk('kasse-pin', self::PIN_FORSOK, 300, $h);
        Rate::sjekk('kasse-pin-ip', 30, 300);
        if (preg_match('/^\d{4}$/', $pin) !== 1) {
            Svar::feil('PIN-en er fire sifre.');
        }
        $person = self::finnPin($pin);
        if ($person === null) {
            logg('Feil PIN i kassa');
            Svar::feil('Feil PIN. Prøv igjen.', 400, ['feilPin' => true]);
        }
        DB::kjor(
            'UPDATE sessions
                SET kasse_person_id = :p,
                    kasse_ulast_til = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::LAAS_MINUTTER . ' MINUTE)
              WHERE token_hash = :h',
            ['p' => $person['id'], 'h' => $h]
        );
        revider('kasse_laast_opp', 'member', $person['id']);
        return $person;
    }

    /**
     * Personen med denne PIN-en — samme kontroll som kassa (finnPin), men uten
     * kassekontoen og uten å låse opp kassa. Messevisningen (app/lib/messe.php,
     * eieren 9. oktober 2026: «samme PIN som kassen»). null = feil PIN, feil
     * format eller migrasjon 263 ikke kjørt. «hash» er PIN-hashen som faktisk
     * ble sjekket (samme spørring), så messekapselen signeres med den.
     *
     * @return array{id:int, navn:string, hash:string}|null
     */
    public static function personForPin(string $pin): ?array
    {
        if (!self::klar() || preg_match('/^\d{4}$/', $pin) !== 1) {
            return null;
        }
        return self::finnPin($pin, 0, true);
    }

    public static function laas(): void
    {
        $h = Sesjon::tokenHash();
        if ($h !== null && self::klar()) {
            DB::kjor('UPDATE sessions SET kasse_person_id = NULL, kasse_ulast_til = NULL WHERE token_hash = :h', ['h' => $h]);
        }
    }

    /**
     * Personen med denne PIN-en, blant dem som fortsatt har tilgang.
     *
     * @return array{id:int, navn:string, hash?:string}|null
     */
    private static function finnPin(string $pin, int $utenom = 0, bool $medHash = false): ?array
    {
        foreach (DB::alle(
            'SELECT id, navn, brukernavn, rolle, telefon, kasse_pin_hash FROM members
              WHERE kasse_pin_hash IS NOT NULL AND anonymisert_at IS NULL AND id <> :u',
            ['u' => $utenom]
        ) as $m) {
            if (self::harTilgang($m) && password_verify($pin, (string) $m['kasse_pin_hash'])) {
                $p = ['id' => (int) $m['id'], 'navn' => (string) $m['navn']];
                return $medHash ? $p + ['hash' => (string) $m['kasse_pin_hash']] : $p;
            }
        }
        return null;
    }

    /**
     * Fjerner PIN-en og låser kassa der personen står nå. Kalles når PIN-en
     * fjernes, og når tilgangen fjernes under Brukere (api/admin/brukere.php).
     * Tåler at migrasjon 263 ikke er kjørt.
     */
    public static function fjernFor(int $medlemId): void
    {
        if (!self::klar()) {
            return;
        }
        DB::oppdater('members', ['kasse_pin_hash' => null], ['id' => $medlemId]);
        DB::kjor('UPDATE sessions SET kasse_person_id = NULL, kasse_ulast_til = NULL WHERE kasse_person_id = :m',
            ['m' => $medlemId]);
    }

    /**
     * Setter (eller fjerner, med null) PIN-en til én person. Bare fra admin.
     * PIN-en må være unik: den forteller kassa hvem som står der.
     */
    public static function settPin(int $medlemId, ?string $pin): void
    {
        if (!self::klar()) {
            throw new RuntimeException('Kassa krever en oppdatering av databasen. Trykk ⚙ Kjør oppdateringer.');
        }
        $m = DB::en('SELECT id, rolle, brukernavn, telefon FROM members WHERE id = :i AND anonymisert_at IS NULL', ['i' => $medlemId]);
        if ($m === null) {
            throw new RuntimeException('Fant ikke brukeren.');
        }
        if ($pin === null) {
            self::fjernFor($medlemId);
            return;
        }
        if ((string) $m['rolle'] === 'kasse') {
            throw new RuntimeException('Kassekontoen kan ikke ha PIN. Gi PIN til personene som står i kassa.');
        }
        if (!self::harTilgang($m)) {
            throw new RuntimeException('Bare brukere med innlogging kan ha kasse-PIN.');
        }
        if (preg_match('/^\d{4}$/', $pin) !== 1) {
            throw new RuntimeException('PIN-en må være fire sifre.');
        }
        if (self::finnPin($pin, $medlemId) !== null) {
            throw new RuntimeException('Den PIN-en bruker en annen. Velg en annen.');
        }
        DB::oppdater('members', ['kasse_pin_hash' => password_hash($pin, PASSWORD_DEFAULT)], ['id' => $medlemId]);
    }
}
