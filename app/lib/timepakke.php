<?php
/**
 * Timepakken: ekstra timer som ikke gaar ut.
 *
 * Eieren, 28. september 2026: «timepakke med 6 timer, la det koste 800,- og
 * kan kun kjoepes naar timene er brukt opp» — «denne gaar ikke ut og kan
 * overfoeres til neste maaned». Og: «de som har proevd lissom kan ikke kjoepe
 * timepakke».
 *
 * Slik regnes det:
 *  - En betalt pakke legger timene sine til taket (Medlemskap::timerMedGaver),
 *    saa «timer igjen» paa Min side og i admin teller dem med.
 *  - Pakketimene brukes ETTER maanedens timer: det som stemples over taket
 *    uten pakken, spiser av pakken.
 *  - Naar en maaned er over, skriver cron hvor mye av pakken som gikk med
 *    (timepakke_bruk). Resten foelger med til neste maaned.
 *  - Timer stemplet over taket foer kjoepet, trekkes fra pakken — det faller
 *    ut av regnestykket av seg selv, fordi pakken legges paa taket.
 *
 * Pris og antall timer staar i innstillingene (migrasjon 232) og settes i
 * admin. Pakken lagrer timetall og pris slik de var da den ble kjoept.
 */

declare(strict_types=1);

final class Timepakke
{
    public static function timer(): int
    {
        return max(1, (int) Config::hent('timepakke_timer', '6'));
    }

    public static function prisOre(): int
    {
        return max(0, (int) Config::hent('timepakke_pris_ore', '80000'));
    }

    private static function finnes(): bool
    {
        return DB::harTabell('timepakker') && DB::harTabell('timepakke_bruk');
    }

    /** Proeveperioden: Prøv Lissom kan ikke kjoepe timepakke. */
    public static function erProve(array $medlem): bool
    {
        return (string) ($medlem['status'] ?? '') === 'prove'
            || Medlemskap::erEngangs((string) ($medlem['medlemskap_type'] ?? ''));
    }

    /**
     * Pakkeminutter medlemmet har til gode ved inngangen til denne maaneden,
     * pluss pakker kjoept denne maaneden. Denne maanedens bruk er ikke
     * trukket — den ligger i det som er stemplet.
     */
    public static function tilgodeMin(int $medlemId): int
    {
        if ($medlemId <= 0 || !self::finnes()) {
            return 0;
        }
        $kjopt = (int) DB::verdi(
            "SELECT COALESCE(SUM(timer), 0) * 60 FROM timepakker
              WHERE member_id = :m AND status = 'betalt'",
            ['m' => $medlemId]
        );
        if ($kjopt === 0) {
            return 0;
        }
        $brukt = (int) DB::verdi(
            'SELECT COALESCE(SUM(minutter), 0) FROM timepakke_bruk WHERE member_id = :m',
            ['m' => $medlemId]
        );
        return max(0, $kjopt - $brukt);
    }

    /**
     * Kan medlemmet kjoepe en pakke naa? Tom streng = ja, ellers grunnen.
     * Samme regel paa serveren og paa Min side.
     */
    public static function hvorforIkke(array $medlem): string
    {
        if (!self::finnes()) {
            return 'Timepakker er ikke slått på ennå.';
        }
        if (self::erProve($medlem)) {
            return 'Timepakken gjelder ikke Prøv Lissom.';
        }
        $tak = Medlemskap::timerMedGaver($medlem);
        if ($tak === null) {
            return 'Medlemskapet ditt har ingen timebegrensning.';
        }
        $brukt = Stempling::minutterDenneManeden((int) $medlem['id']);
        if ($brukt < (int) round($tak * 60)) {
            return 'Timepakken kan kjøpes når timene er brukt opp.';
        }
        return '';
    }

    /**
     * Starter kjoepet i Vipps. Samme vei som et medlemskap som betales én
     * gang (Medlemskap::startEngangs): en rad i «payments» og en betaling i
     * Vipps. Pakken blir betalt i Booking::markerBetalt().
     *
     * @return array{url:string,id:int}
     */
    public static function start(array $medlem): array
    {
        $grunn = self::hvorforIkke($medlem);
        if ($grunn !== '') {
            throw new RuntimeException($grunn);
        }
        $mid = (int) $medlem['id'];
        $timer = self::timer();
        $pris = self::prisOre();
        $referanse = Vipps::nyReferanse('TP');
        $betalingId = DB::settInn('payments', [
            'vipps_reference' => $referanse,
            'type'            => 'epayment',
            'formal'          => 'medlemskap',
            'member_id'       => $mid,
            'belop_ore'       => $pris,
            'status'          => 'opprettet',
            'idempotency_key' => Vipps::uuid(),
        ] + (DB::harKolonne('payments', 'sporing')
            ? ['sporing' => Maaling::sporingFraNettleser() ?: null] // annonsen som førte hit (2. okt 2026)
            : []));
        $id = DB::settInn('timepakker', [
            'member_id'  => $mid,
            'timer'      => $timer,
            'pris_ore'   => $pris,
            'status'     => 'venter',
            'payment_id' => $betalingId,
        ]);
        try {
            $betaling = Vipps::opprettBetaling(
                $referanse,
                $pris,
                Vipps::beskrivelse('Timepakke hos Lissom — ' . $timer . ' timer', (string) ($medlem['navn'] ?? '')),
                Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
                $medlem['telefon'] ?? null
            );
        } catch (Throwable $e) {
            DB::oppdater('payments', ['status' => 'feilet'], ['id' => $betalingId]);
            DB::oppdater('timepakker', ['status' => 'avbrutt'], ['id' => $id]);
            logg_feil('Fikk ikke startet timepakke for medlem ' . $mid, $e);
            throw new RuntimeException('Fikk ikke startet betalingen. Prøv igjen om litt.');
        }
        DB::oppdater('payments', ['status' => 'venter'], ['id' => $betalingId]);
        return ['url' => (string) $betaling['url'], 'id' => $id];
    }

    /** Kalles fra Booking::markerBetalt(). Sann naar betalingen var en pakke. */
    public static function betalt(int $betalingId): bool
    {
        if (!self::finnes()) {
            return false;
        }
        $t = DB::en('SELECT id, member_id, status FROM timepakker WHERE payment_id = :p', ['p' => $betalingId]);
        if ($t === null) {
            return false;
        }
        if ($t['status'] !== 'betalt') {
            DB::oppdater('timepakker', ['status' => 'betalt', 'betalt_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $t['id']]);
            revider('timepakke_betalt', 'member', (int) $t['member_id'], ['timepakke' => (int) $t['id']]);
        }
        return true;
    }

    /**
     * Skriver pakkebruken for maaneder som er over. Én rad per medlem og
     * maaned; kjoeres igjen gjoer den ingenting.
     *
     * Bruken = det som ble stemplet over taket uten pakken, men aldri mer enn
     * pakken hadde igjen. Taket uten pakke regnes av planen, gavetimene og
     * dugnaden slik de staar naa — vi lagrer ikke hva de var den gangen.
     *
     * @return int antall rader skrevet
     */
    public static function lukkMaaneder(): int
    {
        if (!self::finnes()) {
            return 0;
        }
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc = new DateTimeZone('UTC');
        $denne = (new DateTimeImmutable('now', $oslo))->modify('first day of this month')->setTime(0, 0);
        $skrevet = 0;
        foreach (DB::alle(
            "SELECT member_id, MIN(betalt_at) AS forste FROM timepakker
              WHERE status = 'betalt' AND betalt_at IS NOT NULL GROUP BY member_id"
        ) as $r) {
            $mid = (int) $r['member_id'];
            $medlem = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $mid]);
            if ($medlem === null) {
                continue;
            }
            $mnd = (new DateTimeImmutable((string) $r['forste'], $utc))->setTimezone($oslo)
                ->modify('first day of this month')->setTime(0, 0);
            for (; $mnd < $denne; $mnd = $mnd->modify('+1 month')) {
                $navn = $mnd->format('Y-m');
                if (DB::verdi('SELECT 1 FROM timepakke_bruk WHERE member_id = :m AND maaned = :n',
                        ['m' => $mid, 'n' => $navn]) !== null) {
                    continue;
                }
                $fra = $mnd->setTimezone($utc)->format('Y-m-d H:i:s');
                $til = $mnd->modify('+1 month')->setTimezone($utc)->format('Y-m-d H:i:s');
                $stemplet = (int) DB::verdi(
                    'SELECT COALESCE(SUM(minutter), 0) FROM check_ins
                      WHERE member_id = :m AND ut_tid IS NOT NULL AND inn_tid >= :fra AND inn_tid < :til',
                    ['m' => $mid, 'fra' => $fra, 'til' => $til]
                );
                $kjopt = (int) DB::verdi(
                    "SELECT COALESCE(SUM(timer), 0) * 60 FROM timepakker
                      WHERE member_id = :m AND status = 'betalt' AND betalt_at < :til",
                    ['m' => $mid, 'til' => $til]
                );
                $bruktFor = (int) DB::verdi(
                    'SELECT COALESCE(SUM(minutter), 0) FROM timepakke_bruk WHERE member_id = :m AND maaned < :n',
                    ['m' => $mid, 'n' => $navn]
                );
                $tak = Medlemskap::timerMedGaver($medlem, false);
                $over = $tak === null ? 0 : max(0, $stemplet - (int) round($tak * 60));
                DB::kjor(
                    'INSERT IGNORE INTO timepakke_bruk (member_id, maaned, minutter) VALUES (:m, :n, :b)',
                    ['m' => $mid, 'n' => $navn, 'b' => min($over, max(0, $kjopt - $bruktFor))]
                );
                $skrevet++;
            }
        }
        return $skrevet;
    }

    /**
     * Pakkene til ett medlem, til admin.
     *
     * @return array{kjopt:list<array{dato:string,timer:int,pris:string}>,igjenMin:int}
     */
    public static function forAdmin(int $medlemId): array
    {
        if (!self::finnes()) {
            return ['kjopt' => [], 'igjenMin' => 0];
        }
        $kjopt = array_map(static fn(array $t): array => [
            'dato'  => Booking::norskDatoKort((string) $t['betalt_at']),
            'timer' => (int) $t['timer'],
            'pris'  => Booking::kroner((int) $t['pris_ore']),
        ], DB::alle(
            "SELECT timer, pris_ore, betalt_at FROM timepakker
              WHERE member_id = :m AND status = 'betalt' ORDER BY betalt_at DESC",
            ['m' => $medlemId]
        ));
        $igjen = 0;
        if ($kjopt !== []) {
            $medlem = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $medlemId]);
            $tak = $medlem === null ? null : Medlemskap::timerMedGaver($medlem, false);
            $over = $tak === null ? 0 : max(0, Stempling::minutterDenneManeden($medlemId) - (int) round($tak * 60));
            $igjen = max(0, self::tilgodeMin($medlemId) - $over);
        }
        return ['kjopt' => $kjopt, 'igjenMin' => $igjen];
    }

    /** Grunnene i vindu 4, i den rekkefølgen eieren ga dem. */
    public const GRUNNER = ['For dyrt', 'Ikke som forventet', 'Mangler utstyr'];

    public static function lagreSvar(int $medlemId, string $grunn, string $fritekst, string $vindu): void
    {
        if (!DB::harTabell('timer_svar')) {
            throw new RuntimeException('Svarene er ikke slått på ennå.');
        }
        $grunn = in_array($grunn, self::GRUNNER, true) ? $grunn : null;
        $fritekst = trim(mb_substr($fritekst, 0, 1000));
        DB::settInn('timer_svar', [
            'member_id' => $medlemId,
            'grunn'     => $grunn,
            'fritekst'  => $fritekst === '' ? null : $fritekst,
            'vindu'     => $vindu === 'prove' ? 'prove' : 'vanlig',
        ]);
    }

    /** Opptelling per grunn, til admin. @return array<string,int> */
    public static function svarTelling(): array
    {
        if (!DB::harTabell('timer_svar')) {
            return [];
        }
        $ut = array_fill_keys(self::GRUNNER, 0);
        $ut['Uten grunn'] = 0;
        foreach (DB::alle('SELECT grunn, COUNT(*) AS n FROM timer_svar GROUP BY grunn') as $r) {
            $ut[$r['grunn'] ?? 'Uten grunn'] = (int) $r['n'];
        }
        return $ut;
    }

    /** @return list<array{dato:string,grunn:string,tekst:string,vindu:string}> */
    public static function svarFor(int $medlemId): array
    {
        if (!DB::harTabell('timer_svar')) {
            return [];
        }
        return array_map(static fn(array $r): array => [
            'dato'  => Booking::norskDatoKort((string) $r['created_at']),
            'grunn' => (string) ($r['grunn'] ?? ''),
            'tekst' => (string) ($r['fritekst'] ?? ''),
            'vindu' => $r['vindu'] === 'prove' ? 'Prøv Lissom' : 'Medlemskap',
        ], DB::alle(
            'SELECT grunn, fritekst, vindu, created_at FROM timer_svar WHERE member_id = :m ORDER BY id DESC LIMIT 20',
            ['m' => $medlemId]
        ));
    }
}
