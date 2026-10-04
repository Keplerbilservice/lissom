<?php
/**
 * Medlemskap og manedstrekk.
 *
 * Medlemskapet ble gjort opp direkte med verkstedet. «Fornyes 1. september»
 * sto som fast tekst paa Min side — en paastand om et trekk som ikke fantes.
 *
 * Naa: kunden godkjenner en avtale i Vipps én gang, og avtalen belastes hver
 * maaned av cron. Hvert trekk blir en helt vanlig rad i payments, saa det
 * dukker opp i omsetningen og i kjopshistorikken som alt annet.
 *
 * Trekket kjores av cron og ikke av et sidevisning: ellers ville det avhengt
 * av at noen tilfeldigvis var innom nettsiden den dagen.
 */

declare(strict_types=1);

final class Medlemskap
{
    /**
     * Saa mange dager for forfall ber vi Vipps om trekket.
     *
     * Vipps krever at et trekk paa en avtale opprettes paa forhaand, slik at
     * kunden ser det komme i appen for pengene gaar. Det er derfor pengene
     * ikke er inne samme dag som runden kjorer.
     *
     * Sto som tre. Eieren, 7. september 2026: «er det ikke om aa gjore aa faa
     * inn pengene saa fort som mulig», og da tallet var nede i to: «minimumskrav
     * ja, ikke maks».
     *
     * Han har rett: ett dogn er gulvet hos Vipps, ikke taket. Vipps sin egen
     * dokumentasjon sier at trekket maa opprettes minst én dag for forfall, saa
     * kunden rekker aa se det komme i appen. Tre var to dager mer enn noen ba
     * om, og hver av dem var penger som sto ute lenger enn nodvendig.
     *
     * TO TING AA VITE OM DEN DAGEN DENNE SKAL OPP IGJEN:
     *
     *   1. Dette er lest ut av et soekeresultat, ikke i originalen.
     *      developer.vippsmobilepay.com er sperret av utgangsfilteret i miljoet
     *      dette ble skrevet i. Samme kilde nevner at én dag kan kreve at
     *      salgsenheten staar paa en egen liste hos Vipps.
     *   2. Med ett dogn er det ingen slingringsmonn. Svikter runden den ene
     *      natta, er forfallet passert for neste runde rekker aa be om det.
     *
     * Avvises et trekk med en klage paa forfallsdatoen, er det denne linja som
     * skal tilbake til to. Grunnen staar i cron-e-posten — bin/cron.php skriver
     * den til stderr.
     */
    private const VARSEL_DAGER = 1;

    /**
     * Hvor mange dager foer trekkdatoen trekket bestilles hos Vipps. Vipps
     * krever minst én dag (VARSEL_DAGER) og viser kommende trekk i appen
     * opptil 35 dager foer. Tre dager gir forfall PAA trekkdatoen selv om
     * runden skulle staa over en natt eller to.
     */
    public const BESTILL_DAGER_FOR = 3;

    /**
     * Hvilken utgave av medlemsvilkaarene som gjelder naa.
     *
     * Lagres sammen med samtykket ved innmelding. Uten den vet vi at noen
     * huket av, men ikke hva de huket av PAA — og vilkaar som kan endres uten
     * spor er ikke verdt mye den dagen noen er uenig.
     *
     * Datoen er den teksten sist ble endret. Endres vilkaarene, settes denne
     * opp samtidig, saa en rad fra i fjor peker paa teksten som gjaldt i fjor.
     */
    public const VILKAAR_VERSJON = '2026-09-03';

    /**
     * Er det en ANNEN person enn den innloggede som fyller ut?
     *
     * 17. september 2026: Ellen meldte seg inn paa Mini 15 fra en nettleser
     * der Monica (admin) var logget inn. Kortet var fylt ut med Monicas
     * konto; Ellen skrev sin e-post og sitt nummer oppaa. Serveren tok det
     * som en rettelse av Monicas kontaktopplysninger, og la avtalen og
     * betalingen paa Monica. Retten opp for haand i basen samme kveld.
     *
     * Regelen: staar det en e-post OG et nummer som begge er andre enn
     * kontoens, er det ikke en rettelse — det er en annen person. Det samme
     * gjelder om e-posten eller nummeret alt tilhoerer et annet medlem.
     * Da skal ingenting skrives paa den innloggede kontoen, uansett om den
     * er admin eller et vanlig medlem. Eieren, 17. september: «fiks saa
     * dette ikke kan skje igjen, verken om man er logget inn som admin
     * eller et annet medlem».
     *
     * Én rettelse (bare ny e-post, eller bare nytt nummer) er fortsatt lov
     * — det var det feltene paa kortet ble laget for, 3. september.
     *
     * @param array<string,mixed> $medlem Den innloggede.
     * @return string|null Beskjeden til kunden, eller null naar det er henne.
     */
    public static function annenPerson(array $medlem, string $epost, string $telefon): ?string
    {
        $id      = (int) ($medlem['id'] ?? 0);
        $epost   = mb_strtolower(trim($epost));
        $siffer  = preg_replace('/[^0-9]/', '', $telefon) ?? '';
        $siffer  = strlen($siffer) >= 8 ? substr($siffer, -8) : '';
        $minE    = mb_strtolower(trim((string) ($medlem['epost'] ?? '')));
        $minT    = preg_replace('/[^0-9]/', '', (string) ($medlem['telefon'] ?? '')) ?? '';
        $minT    = strlen($minT) >= 8 ? substr($minT, -8) : '';

        $annenE = $epost !== '' && $epost !== $minE;
        $annenT = $siffer !== '' && $siffer !== $minT;

        $annen = $annenE && $annenT;
        if (!$annen && $annenE && $id > 0) {
            $annen = DB::en(
                'SELECT id FROM members WHERE LOWER(epost) = :e AND id <> :i AND anonymisert_at IS NULL LIMIT 1',
                ['e' => $epost, 'i' => $id]
            ) !== null;
        }
        if (!$annen && $annenT && $id > 0) {
            $annen = DB::en(
                "SELECT id FROM members
                  WHERE telefon IS NOT NULL AND id <> :i AND anonymisert_at IS NULL
                    AND RIGHT(REGEXP_REPLACE(telefon, '[^0-9]', ''), 8) = :t LIMIT 1",
                ['t' => $siffer, 'i' => $id]
            ) !== null;
        }
        if (!$annen) {
            return null;
        }
        $navn = trim((string) ($medlem['navn'] ?? ''));
        return 'Opplysningene hører ikke til kontoen du er logget inn på'
            . ($navn !== '' ? ' (' . $navn . ')' : '')
            . '. Er det ikke deg som skal bli medlem: logg ut, og meld deg inn på nytt fra medlemskapssiden.';
    }

    /**
     * En plan som kan VELGES naa.
     *
     * «aktiv = 0» betyr at planen er tatt ut av salg. Skal noen melde seg
     * inn, bytte til, eller kjope den, er det denne som gjelder.
     *
     * @return array<string,mixed>|null
     */
    public static function plan(string $navn): ?array
    {
        return DB::en('SELECT * FROM membership_plans WHERE navn = :n AND aktiv = 1', ['n' => $navn]);
    }

    /**
     * Planen et medlem ALT STAAR PAA — enten den selges eller ikke.
     *
     * ── Hvorfor denne finnes ──────────────────────────────────────────
     *
     * «aktiv = 0» sier at planen ikke kan kjopes mer. Den sier ingenting om
     * dem som alt staar paa den; de har den fortsatt, og timene, prisen,
     * bindinga og oppsigelsestida deres staar i den raden.
     *
     * Alt som beskrev et medlem kalte plan(), som bare leter blant aktive.
     * En avslaatt plan ble derfor ikke funnet — og «ikke funnet» ble tolket
     * som «ingen grense»:
     *
     *   timerFor()          null  →  «∞ · ingen timebegrensning» paa doera
     *   api/medlemskap.php  null  →  prisen falt tilbake paa Vipps-avtalens
     *   sluttdato()         null  →  én maaneds oppsigelse uansett hva
     *
     * Eieren, 5. september, med bilde av sitt eget kort: «Mini 15 · kr 1 790,-
     * · 15 timer i måneden» i tittelen og «∞ · ingen timebegrensning» rett
     * under. «Fri tilgang» sto med aktiv = 0; planen ble ikke funnet, timene
     * ble ubegrensede, og navnet falt tilbake paa en annen plan.
     *
     * Verkstedet skal kunne ta en plan ut av salg uten aa gi bort doegnaapen
     * tilgang til dem som staar paa den.
     *
     * @return array<string,mixed>|null
     */
    public static function planUansett(string $navn): ?array
    {
        return DB::en('SELECT * FROM membership_plans WHERE navn = :n', ['n' => $navn]);
    }

    // ── Proeveperioden (engangsplanen) ──────────────────────────────────
    //
    // Eieren, 28. september 2026: «prøv lissom må jo ha slutt dato», og «man
    // kan aldri få bruke prøv lissom mer enn 1 gang». Samme dag: den gjelder
    // for maaneden den kjoepes i, med sluttdato paa siste dag i maaneden —
    // samme system som de andre medlemskapene. Oppgraderer hen foer
    // maaneden er ute («Forny»), gjelder det nye alt denne maaneden. Timer
    // stemplet ut over de ti trekkes ikke fra det nye: eieren vurderer dem
    // selv, og ser dem paa medlemmet i admin (proveOverMin()).
    //
    // Johanna kjopte den 2. september. Avtaleraden fikk «binding_til» to
    // maaneder fram (regelen planen hadde da), medlemsraden fikk ingen
    // sluttdato, og Min side sa «Bundet til 2. nov.». Trykket hun «Forny»,
    // sa serveren «Du har alt et medlemskap» — en proeveperiode ble lest som
    // et loepende, bundet medlemskap. Reglene under er den ene kilden:
    //
    //   engangsplan  aldri bundet, aldri loepende, sluttdato = siste dag i
    //                maaneden den er kjoept
    //   erstattes    et nytt medlemskap avslutter den den dagen det blir aktivt
    //   én gang      den som har hatt den, faar ikke kjoepe den igjen

    /** Er planen en engangsplan (Prøv Lissom)? Ukjent plan er det ikke. */
    public static function erEngangs(string $planNavn): bool
    {
        $p = self::planUansett(trim($planNavn));
        return $p !== null && (int) ($p['engangs'] ?? 0) === 1;
    }

    /**
     * Siste dag en proeveperiode kjoept $fra (Y-m-d; uten: i dag) gjelder:
     * siste dag i maaneden, i norsk kalender.
     */
    public static function proveSlutt(?string $fra = null): string
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $dag = $fra !== null && $fra !== ''
            ? new DateTimeImmutable(substr($fra, 0, 10), $oslo)
            : new DateTimeImmutable('now', $oslo);
        return $dag->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * Datoen avtalen er bundet til, eller null.
     *
     * Planen gaar foran den lagrede datoen: «binding_til» settes én gang,
     * naar avtalen opprettes, og blir staaende om planen senere faar null
     * binding. En engangsplan er aldri bundet. Brukes av Min side, admin og
     * oppsigelsessperren, saa alle sier det samme.
     *
     * @param array<string,mixed> $avtale
     */
    public static function bindingTil(array $avtale): ?string
    {
        $til = $avtale['binding_til'] ?? null;
        if ($til === null || $til === '') {
            return null;
        }
        $plan = self::planUansett((string) $avtale['plan']);
        if ($plan !== null && ((int) ($plan['engangs'] ?? 0) === 1 || (int) ($plan['binding_mnd'] ?? 0) <= 0)) {
            return null;
        }
        return (string) $til;
    }

    /**
     * Har medlemmet hatt proeveperioden foer?
     *
     * Ja naar en engangsavtale er blitt aktiv, er betalt, eller naar
     * verkstedet har satt hen paa en engangsplan. Et forsoek som aldri ble
     * betalt, teller ikke.
     */
    public static function harHattProve(int $medlemId): bool
    {
        $avtaler = (int) DB::verdi(
            "SELECT COUNT(*) FROM subscriptions s
               JOIN membership_plans p ON p.navn = s.plan AND p.engangs = 1
              WHERE s.member_id = :m
                AND (s.status IN ('aktiv','utlopt')
                     OR (s.status = 'stoppet' AND s.slutter IS NOT NULL)
                     OR EXISTS (SELECT 1 FROM payments b
                                 WHERE b.subscription_id = s.id AND b.status = 'betalt'))",
            ['m' => $medlemId]
        );
        if ($avtaler > 0) {
            return true;
        }
        $m = DB::en('SELECT medlemskap_type, status FROM members WHERE id = :m', ['m' => $medlemId]);
        return $m !== null
            && in_array((string) $m['status'], ['aktiv', 'prove', 'pause', 'oppsagt'], true)
            && self::erEngangs((string) ($m['medlemskap_type'] ?? ''));
    }

    /**
     * Stopper et nytt kjoep av proeveperioden for den som har hatt den.
     *
     * @param array<string,mixed> $plan
     */
    private static function sperrProveIgjen(int $medlemId, array $plan): void
    {
        if ((int) ($plan['engangs'] ?? 0) === 1 && self::harHattProve($medlemId)) {
            throw new RuntimeException((string) $plan['navn'] . ' kan bare kjøpes én gang. Velg et annet medlemskap.');
        }
    }

    /**
     * Hindrer avtalen et nytt medlemskap? En loepende gjor det; en
     * proeveperiode gjor det ikke — den erstattes naar det nye blir aktivt.
     *
     * @param array<string,mixed>|null $fra
     */
    private static function hindrerNytt(?array $fra, string $nyPlan = ''): bool
    {
        // Eieren, 29. september 2026: «Oppgrader medlemskap» til et stoerre
        // medlemskap midt i maaneden. Det nye gjelder fra i dag, og det gamle
        // stopper naar det nye er betalt (erstattProve()). Et mindre, eller
        // det samme, hindres fortsatt — det er ingen vei rundt bindinga.
        return $fra !== null && $fra['status'] === 'aktiv' && !self::erEngangs((string) $fra['plan'])
            && !self::erStorre($nyPlan, (string) $fra['plan']);
    }

    /**
     * Er $ny et stoerre medlemskap enn $gammel? Flere timer i maaneden, og
     * ubegrenset er stoerst. En engangsplan er aldri stoerre.
     */
    public static function erStorre(string $ny, string $gammel): bool
    {
        $n = self::planUansett(trim($ny));
        $g = self::planUansett(trim($gammel));
        if ($n === null || $g === null || (int) ($n['engangs'] ?? 0) === 1 || $n['navn'] === $g['navn']) {
            return false;
        }
        if ($g['timer'] === null) {
            return false;
        }
        return $n['timer'] === null || (int) $n['timer'] > (int) $g['timer'];
    }

    /**
     * Første dag etter den betalte kalendermåneden (Y-m-d).
     * gjelder_fra bestemmer måneden; ellers brukes betalingsdatoen i Oslo.
     *
     * @param array<string,mixed> $betaling
     */
    public static function dekkerTil(array $betaling): string
    {
        $fra = trim((string) ($betaling['gjelder_fra'] ?? ''));
        if ($fra === '') {
            $fra = (new DateTimeImmutable((string) $betaling['created_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d');
        }
        return (new DateTimeImmutable($fra, new DateTimeZone('Europe/Oslo')))
            ->modify('first day of next month')->format('Y-m-d');
    }

    // ── Nytt medlemskap kjoept etter den 20. ────────────────────────────
    //
    // Eieren, 2. oktober 2026 (Johanna, Mini 15 kjoept 29. september): et
    // NYTT medlemskap som kjoepes den 21. eller senere, gjelder NESTE
    // kalendermaaned. Tilgangen gis med en gang — resten av maaneden er
    // gratis — og maanedstimene telles for neste maaned. Engangsplanen
    // (Prøv Lissom) gjelder fortsatt kjoepsmaaneden, og «Forny» og
    // oppgradering er ikke nye medlemskap.

    /** Siste dag i maaneden et nytt medlemskap teller for kjoepsmaaneden. */
    public const NYTT_SISTE_DAG = 20;

    /**
     * gjelder_fra for den foerste betalingen paa et NYTT medlemskap, eller
     * null naar betalingen skal telle som foer (kjoepsmaaneden).
     *
     * @param string|null $kjopt kjoepstidspunktet i UTC (databasens klokke);
     *                           null = naa
     * @param string|null $naa   naa i UTC, for testene; null = klokka
     */
    public static function gjelderFraNytt(int $medlemId, string $planNavn, ?string $kjopt = null, ?string $naa = null): ?string
    {
        $plan = self::planUansett(trim($planNavn));
        if ($plan === null || (int) ($plan['engangs'] ?? 0) === 1) {
            return null;
        }
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $kjop = ($kjopt === null || trim($kjopt) === '')
            ? new DateTimeImmutable($naa ?? 'now', $utc)
            : new DateTimeImmutable($kjopt, $utc);
        $kjopUtc = $kjop->setTimezone($utc)->format('Y-m-d H:i:s');
        $kjop = $kjop->setTimezone($oslo);
        if ((int) $kjop->format('j') <= self::NYTT_SISTE_DAG) {
            return null;
        }
        $fra = $kjop->modify('first day of next month')->format('Y-m-d');

        // Kommer den foerste betalingen foerst etter at den nye maaneden er
        // over, teller den maaneden den betales i — som foer.
        $idag = (new DateTimeImmutable($naa ?? 'now', $utc))->setTimezone($oslo)->format('Y-m-d');
        if ($idag >= self::dekkerTil(['gjelder_fra' => $fra])) {
            return null;
        }

        // Har hen alt en betalt periode som loeper (oppgradering, bytte), er
        // det ikke et nytt medlemskap. Proeveperioden teller ikke.
        if ($medlemId > 0 && self::harLoependePeriode($medlemId, $kjop->format('Y-m-d'), 0, $kjopUtc)) {
            return null;
        }
        return $fra;
    }

    /**
     * Har medlemmet en betalt periode paa et loepende medlemskap (ikke
     * engangsplan) som dekker $dato (Y-m-d, Oslo)?
     */
    private static function harLoependePeriode(int $medlemId, string $dato, int $utenId = 0, ?string $foer = null): bool
    {
        $fraKol = DB::harKolonne('payments', 'gjelder_fra') ? 'p.gjelder_fra' : 'NULL AS gjelder_fra';
        $utenTimepakke = DB::harTabell('timepakker')
            ? 'AND NOT EXISTS (SELECT 1 FROM timepakker tp WHERE tp.payment_id = p.id)' : '';
        // Bare betalinger som fantes da (foer kjoepet): en senere «Forny»
        // gjoer ikke et nytt medlemskap til et gammelt i ettertid.
        $foerSql = $foer !== null ? 'AND p.created_at < :f' : '';
        $param = ['m' => $medlemId, 'u' => $utenId];
        if ($foer !== null) {
            $param['f'] = $foer;
        }
        $rader = DB::alle(
            "SELECT p.created_at, {$fraKol}, mp.engangs
               FROM payments p
          LEFT JOIN subscriptions s ON s.id = p.subscription_id
          LEFT JOIN membership_plans mp ON mp.navn = s.plan
              WHERE p.member_id = :m AND p.formal = 'medlemskap' AND p.id <> :u
                AND p.status IN ('betalt','delvis_refundert') AND p.annullert_at IS NULL
                {$foerSql}
                {$utenTimepakke}",
            $param
        );
        foreach ($rader as $r) {
            if ((int) ($r['engangs'] ?? 0) === 1) {
                continue;
            }
            if (self::dekkerTil($r) > $dato) {
                return true;
            }
        }
        return false;
    }

    /**
     * L-12 (pengeflyt-revisjonen, eieren 2. oktober 2026): betalingen som alt
     * dekker kalendermaaneden $maaned (Y-m) for medlemmet, eller null.
     *
     * Teller betalte, ikke annullerte medlemsbetalinger (ikke timepakker, ikke
     * proeveperioden). En maaned er dekket naar betalingens periode er den
     * maaneden — eller naar den er kjoepsmaaneden til et NYTT medlemskap
     * kjoept etter den 20. (den foerste betalingen paa avtalen; resten av
     * maaneden er med). En «Forny» etter den 20. har samme form, men er ikke
     * den foerste paa avtalen, og dekker bare sin egen maaned.
     *
     * @param bool $utenTrekk true = bare betalinger utenom faste trekk
     *                        (verkstedet, «Forny»), til trekk()
     * @return array<string,mixed>|null
     */
    public static function betalingForMaaned(int $medlemId, string $maaned, bool $utenTrekk = false): ?array
    {
        $fraKol = DB::harKolonne('payments', 'gjelder_fra') ? 'p.gjelder_fra' : 'NULL AS gjelder_fra';
        $utenTimepakke = DB::harTabell('timepakker')
            ? 'AND NOT EXISTS (SELECT 1 FROM timepakker tp WHERE tp.payment_id = p.id)' : '';
        $rader = DB::alle(
            // Plantypen fra avtalen; mangler avtaleraden (meldt inn for haand,
            // betalt i verkstedet), fra medlemmets plan — saa Prøv Lissom uten
            // avtale ikke telles som en vanlig maaned (kontrolloeren, 2. oktober 2026).
            "SELECT p.id, p.type, p.subscription_id, p.created_at, {$fraKol},
                    CASE WHEN s.id IS NULL THEN mm.engangs ELSE mp.engangs END AS engangs
               FROM payments p
          LEFT JOIN subscriptions s ON s.id = p.subscription_id
          LEFT JOIN membership_plans mp ON mp.navn = s.plan
          LEFT JOIN members m ON m.id = p.member_id
          LEFT JOIN membership_plans mm ON mm.navn = m.medlemskap_type
              WHERE p.member_id = :m AND p.formal = 'medlemskap'
                AND p.status IN ('betalt','delvis_refundert') AND p.annullert_at IS NULL
                {$utenTimepakke}
              ORDER BY p.created_at, p.id",
            ['m' => $medlemId]
        );
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $sett = [];
        foreach ($rader as $r) {
            if ((int) ($r['engangs'] ?? 0) === 1) {
                continue;
            }
            $avtale = (int) ($r['subscription_id'] ?? 0);
            $forste = !isset($sett[$avtale]);
            $sett[$avtale] = true;
            if ($utenTrekk && (string) $r['type'] === 'recurring_charge') {
                continue;
            }
            $kjopt = (new DateTimeImmutable((string) $r['created_at'], $utc))->setTimezone($oslo)->format('Y-m-d');
            $start = trim((string) ($r['gjelder_fra'] ?? '')) ?: $kjopt;
            if (substr($start, 0, 7) === $maaned
                || ($forste && self::erForskuttert($r) && substr($kjopt, 0, 7) === $maaned)) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Er betalingen den foerste paa et nytt medlemskap kjoept etter den 20.?
     * Da gjelder den neste maaned (gjelder_fra), men gir tilgang fra
     * kjoepsdagen. Kjennes paa formen: gjelder_fra er den 1. i maaneden
     * etter betalingsdagen, og betalingsdagen er etter den 20. (Oslo).
     *
     * @param array<string,mixed> $betaling created_at + gjelder_fra
     */
    public static function erForskuttert(array $betaling): bool
    {
        $fra = trim((string) ($betaling['gjelder_fra'] ?? ''));
        $laget = trim((string) ($betaling['created_at'] ?? ''));
        if ($fra === '' || $laget === '') {
            return false;
        }
        $dag = (new DateTimeImmutable($laget, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'));
        return (int) $dag->format('j') > self::NYTT_SISTE_DAG
            && $dag->modify('first day of next month')->format('Y-m-d') === substr($fra, 0, 10);
    }

    // ── Alle betalte maaneder, ikke bare den siste ──────────────────────
    //
    // Pengehull 3 (betalingseksperten, 4. oktober 2026): skyldig ble regnet
    // fra den SISTE betalingen. Var februar ubetalt og mars betalt, forsvant
    // februargjelda. Naa ses alle betalte maaneder, og den foerste ubetalte
    // (som ikke er fritatt av en frys) er den som skyldes.

    /**
     * Betalte, ikke annullerte medlemsbetalinger (ikke timepakker), per
     * medlem, eldste foerst. Samme utvalg som betalingForMaaned().
     *
     * @param list<int> $medlemIder
     * @return array<int,list<array<string,mixed>>>
     */
    private static function betalteRader(array $medlemIder): array
    {
        $medlemIder = array_values(array_filter(array_map('intval', $medlemIder), static fn(int $i): bool => $i > 0));
        if ($medlemIder === []) {
            return [];
        }
        $inn = implode(',', $medlemIder);
        $fraKol = DB::harKolonne('payments', 'gjelder_fra') ? 'p.gjelder_fra' : 'NULL AS gjelder_fra';
        $maateKol = DB::harKolonne('payments', 'maate') ? 'p.maate' : 'NULL AS maate';
        $utenTimepakke = DB::harTabell('timepakker')
            ? 'AND NOT EXISTS (SELECT 1 FROM timepakker tp WHERE tp.payment_id = p.id)' : '';
        $ut = [];
        foreach (DB::alle(
            "SELECT p.id, p.member_id, p.type, p.subscription_id, p.created_at, p.belop_ore, {$maateKol}, {$fraKol},
                    CASE WHEN s.id IS NULL THEN mm.engangs ELSE mp.engangs END AS engangs
               FROM payments p
          LEFT JOIN subscriptions s ON s.id = p.subscription_id
          LEFT JOIN membership_plans mp ON mp.navn = s.plan
          LEFT JOIN members m ON m.id = p.member_id
          LEFT JOIN membership_plans mm ON mm.navn = m.medlemskap_type
              WHERE p.member_id IN ({$inn}) AND p.formal = 'medlemskap'
                AND p.status IN ('betalt','delvis_refundert') AND p.annullert_at IS NULL
                {$utenTimepakke}
              ORDER BY p.created_at, p.id"
        ) as $r) {
            $ut[(int) $r['member_id']][] = $r;
        }
        return $ut;
    }

    /**
     * Kalendermaanedene (Y-m) betalingene dekker, med samme regel som
     * betalingForMaaned(): perioden er gjelder_fra (ellers betalingsdagen), og
     * den foerste betalingen paa et nytt medlemskap kjoept etter den 20.
     * dekker ogsaa kjoepsmaaneden. Engangsplaner teller ikke.
     *
     * @param list<array<string,mixed>> $rader fra betalteRader()
     * @return array{maaneder: array<string,bool>, forste: ?string}
     */
    private static function dekkedeMaaneder(array $rader): array
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $sett = [];
        $mnd = [];
        foreach ($rader as $r) {
            if ((int) ($r['engangs'] ?? 0) === 1) {
                continue;
            }
            $avtale = (int) ($r['subscription_id'] ?? 0);
            $forste = !isset($sett[$avtale]);
            $sett[$avtale] = true;
            $kjopt = (new DateTimeImmutable((string) $r['created_at'], $utc))->setTimezone($oslo)->format('Y-m-d');
            $start = trim((string) ($r['gjelder_fra'] ?? '')) ?: $kjopt;
            $mnd[substr($start, 0, 7)] = true;
            if ($forste && self::erForskuttert($r)) {
                $mnd[substr($kjopt, 0, 7)] = true;
            }
        }
        ksort($mnd);
        return ['maaneder' => $mnd, 'forste' => $mnd === [] ? null : (string) array_key_first($mnd)];
    }

    /**
     * Maaneden (Y-m) skyldberegningen begynner i: den foerste betalte
     * maaneden, men ikke foer innmeldingen, ikke foer den nyeste avtalen ble
     * laget (et nytt medlemskap etter et opphold skal ikke arve hullet), og
     * hoeyst tolv maaneder tilbake.
     *
     * @param array<string,mixed>|null $avtale den nyeste subscriptions-raden
     */
    private static function skyldFra(string $forsteYm, ?string $startDato, ?array $avtale, string $idag): string
    {
        $fra = [$forsteYm, (new DateTimeImmutable($idag))->modify('first day of this month')->modify('-11 months')->format('Y-m')];
        $start = trim((string) $startDato);
        if ($start !== '') {
            $fra[] = substr($start, 0, 7);
        }
        $laget = trim((string) ($avtale['created_at'] ?? ''));
        if ($laget !== '') {
            $fra[] = (new DateTimeImmutable($laget, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m');
        }
        return max($fra);
    }

    /**
     * Foerste dag i den foerste maaneden fra $fraYm til og med maaneden $idag
     * ligger i, som verken er betalt eller fritatt av en frys. Ellers null.
     *
     * @param array<string,bool> $maaneder fra dekkedeMaaneder()
     */
    private static function forsteUbetalte(int $medlemId, string $fraYm, array $maaneder, string $idag): ?string
    {
        $d = new DateTimeImmutable(substr($fraYm, 0, 7) . '-01');
        for ($n = 0; $d->format('Y-m-d') <= $idag && $n < 24; $d = $d->modify('first day of next month'), $n++) {
            if (isset($maaneder[$d->format('Y-m')])) {
                continue;
            }
            if (!self::fritattMaaned($medlemId, $d->format('Y-m-d'))) {
                return $d->format('Y-m-d');
            }
        }
        return null;
    }

    /**
     * Ubetalt maaned foer eller mellom betalingene (pengehull 3), eller null.
     * Bare for et loepende medlemskap med minst én betalt maaned.
     *
     * @param array<string,mixed>      $medlem
     * @param array<string,mixed>|null $avtale den nyeste subscriptions-raden
     * @param array{maaneder: array<string,bool>, forste: ?string}|null $dekket
     */
    private static function ubetaltMellom(array $medlem, ?array $avtale, ?array $dekket, string $idag): ?string
    {
        $id = (int) ($medlem['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $dekket ??= self::dekkedeMaaneder(self::betalteRader([$id])[$id] ?? []);
        if ($dekket['forste'] === null || self::erEngangs((string) ($medlem['medlemskap_type'] ?? ''))) {
            return null;
        }
        // Naar avtalen ble laget: listene i admin henter avtalen uten
        // created_at, saa den ligger ogsaa i «_dekket» fra sisteBetalinger().
        // Samme svar i lista og paa medlemmet.
        $laget = $avtale['created_at'] ?? ($dekket['avtaleLaget'] ?? null);
        if ($laget === null && isset($avtale['id'])) {
            $laget = DB::verdi('SELECT created_at FROM subscriptions WHERE id = :i', ['i' => (int) $avtale['id']]);
        }
        return self::forsteUbetalte($id,
            self::skyldFra($dekket['forste'], isset($medlem['start_dato']) ? (string) $medlem['start_dato'] : null,
                $laget === null ? null : ['created_at' => (string) $laget], $idag),
            $dekket['maaneder'], $idag);
    }

    /**
     * Fra naar (UTC) maanedstimene skal telles denne maaneden. Normalt den
     * 1. i norsk tid; har medlemmet et nytt medlemskap kjoept etter den 20.
     * forrige maaned, telles timene fra kjoepet — de hoerer til denne
     * maaneden, ikke til en ekstra.
     */
    public static function timerTellesFra(int $medlemId, string $manedStart): string
    {
        if ($medlemId <= 0 || !DB::harKolonne('payments', 'gjelder_fra')) {
            return $manedStart;
        }
        $denne = (new DateTimeImmutable($manedStart, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d');
        $rader = DB::alle(
            "SELECT id, created_at, gjelder_fra FROM payments
              WHERE member_id = :m AND formal = 'medlemskap'
                AND status IN ('betalt','delvis_refundert') AND annullert_at IS NULL
                AND gjelder_fra = :fra AND created_at < :start",
            ['m' => $medlemId, 'fra' => $denne, 'start' => $manedStart]
        );
        $fra = $manedStart;
        foreach ($rader as $r) {
            if (!self::erForskuttert($r) || (string) $r['created_at'] >= $fra) {
                continue;
            }
            // Bare et NYTT medlemskap. En «Forny» betalt foer maanedsskiftet
            // har samme form, men da var forrige maaned alt betalt — og
            // timene der hoerer til den.
            $kjopt = (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d');
            if (self::harLoependePeriode($medlemId, $kjopt, (int) $r['id'], (string) $r['created_at'])) {
                continue;
            }
            $fra = (string) $r['created_at'];
        }
        return $fra;
    }

    /**
     * gjelder_fra for en betaling verkstedet registrerer for haand (Kassa,
     * «Ta betalt»): bare naar det er den foerste betalingen paa et nytt
     * medlemskap. Kjoepet er avtalen (startIVerkstedet) eller innmeldingen.
     */
    public static function gjelderFraForsteBetaling(int $medlemId): ?string
    {
        $m = DB::en('SELECT medlemskap_type, start_dato FROM members WHERE id = :i', ['i' => $medlemId]);
        if ($m === null) {
            return null;
        }
        $utenTimepakke = DB::harTabell('timepakker')
            ? 'AND NOT EXISTS (SELECT 1 FROM timepakker tp WHERE tp.payment_id = p.id)' : '';
        $avtale = DB::en(
            "SELECT id, plan, created_at FROM subscriptions
              WHERE member_id = :m AND status = 'aktiv' ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId]
        );
        if ($avtale !== null) {
            $for = (int) DB::verdi(
                "SELECT COUNT(*) FROM payments p
                  WHERE p.subscription_id = :s AND p.formal = 'medlemskap'
                    AND p.status IN ('betalt','delvis_refundert') AND p.annullert_at IS NULL
                    {$utenTimepakke}",
                ['s' => (int) $avtale['id']]
            );
            return $for > 0 ? null
                : self::gjelderFraNytt($medlemId, (string) $avtale['plan'], (string) $avtale['created_at']);
        }
        $start = trim((string) ($m['start_dato'] ?? ''));
        if ($start === '') {
            return null;
        }
        $for = (int) DB::verdi(
            "SELECT COUNT(*) FROM payments p
              WHERE p.member_id = :m AND p.formal = 'medlemskap'
                AND p.status IN ('betalt','delvis_refundert') AND p.annullert_at IS NULL
                AND p.created_at >= :start
                {$utenTimepakke}",
            ['m' => $medlemId, 'start' => substr($start, 0, 10) . ' 00:00:00']
        );
        return $for > 0 ? null
            : self::gjelderFraNytt($medlemId, (string) ($m['medlemskap_type'] ?? ''), substr($start, 0, 10) . ' 12:00:00');
    }

    // ── Prøv Lissom som er over ──────────────────────────────────────
    //
    // Eieren, 2. oktober 2026 (Ida, Prøv Lissom kjoept 7. september): en
    // proeveperiode som er over, er verken «Betalt» eller «Venter paa
    // betaling». Den skal staa som sluttet, ikke i lista over dem som skylder,
    // og ikke purres. Min side tilbyr medlemskap som for en som ikke er medlem.

    /**
     * Siste dag i proeveperioden (Y-m-d) naar medlemmet staar paa en
     * engangsplan som er over; ellers null.
     *
     * @param array<string,mixed> $medlem
     */
    public static function proveSluttet(array $medlem, ?string $idag = null): ?string
    {
        if (!in_array((string) ($medlem['status'] ?? ''), ['prove', 'aktiv', 'pause'], true)) {
            return null;
        }
        if (!self::erEngangs((string) ($medlem['medlemskap_type'] ?? ''))) {
            return null;
        }
        $slutt = trim((string) ($medlem['slutt_dato'] ?? ''));
        if ($slutt === '') {
            $start = trim((string) ($medlem['start_dato'] ?? ''));
            if ($start === '') {
                return null;
            }
            $slutt = self::proveSlutt($start);
        }
        $slutt = substr($slutt, 0, 10);
        $idag ??= (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
        return $idag > $slutt ? $slutt : null;
    }

    /**
     * Mangler medlemmet betaling? Nei for en proeveperiode som er over — den
     * er ferdig, ikke ubetalt. Samme regel for admin, Kassa og Min side.
     *
     * @param array<string,mixed> $medlem
     */
    public static function betalingMangler(array $medlem, bool $harTilgang): bool
    {
        // Fryst og stengt ute etter den betalte perioden: ikke ubetalt — trekket
        // for frysen hoppes over (eieren, 2. oktober 2026). Men bare naar det
        // ikke staar noe ubetalt: et trekk som feilet, eller en maaned frysen
        // dekker under 15 dager av, mangler fortsatt (kontrolloeren, samme dag).
        return !$harTilgang
            && in_array((string) ($medlem['status'] ?? ''), ['prove', 'aktiv', 'pause'], true)
            && self::proveSluttet($medlem) === null
            && (Frys::frystNaa($medlem) === null || self::betalingsstatusFor($medlem)['utestaaende']);
    }

    /** Betalt tilgang, uavhengig av om avtalen fortsatt står som aktiv. */
    public static function harBetaltPeriode(array $medlem, ?string $idag = null): bool
    {
        if (!in_array((string) ($medlem['status'] ?? ''), ['prove', 'aktiv', 'pause'], true)) {
            return false;
        }
        if (!empty($medlem['betaler_ikke'])) {
            return true;
        }
        $idag ??= (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
        $id = (int) ($medlem['id'] ?? 0);
        if ($id <= 0) return false;
        $plan = self::planUansett((string) ($medlem['medlemskap_type'] ?? ''));
        if ($plan === null) return false;
        $fraKol = DB::harKolonne('payments', 'gjelder_fra') ? 'p.gjelder_fra' : 'NULL AS gjelder_fra';
        $utenTimepakke = DB::harTabell('timepakker')
            ? 'AND NOT EXISTS (SELECT 1 FROM timepakker tp WHERE tp.payment_id = p.id)' : '';
        $betalinger = DB::alle(
            "SELECT p.created_at, {$fraKol} FROM payments p
             WHERE p.member_id = :m AND p.formal = 'medlemskap'
               AND p.status IN ('betalt','delvis_refundert') AND p.annullert_at IS NULL
               {$utenTimepakke}
             ORDER BY p.id DESC",
            ['m' => $id]
        );
        foreach ($betalinger as $betaling) {
            $fra = trim((string) ($betaling['gjelder_fra'] ?? ''));
            if ($fra === '') {
                $fra = (new DateTimeImmutable((string) $betaling['created_at'], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d');
            }
            if ((int) ($plan['engangs'] ?? 0) !== 1) {
                $fra = (new DateTimeImmutable($fra))->modify('first day of this month')->format('Y-m-d');
                // Nytt medlemskap kjoept etter den 20.: perioden er neste
                // maaned, men tilgangen gjelder fra kjoepsdagen (eieren,
                // 2. oktober 2026). Bare den formen — en annen betaling
                // fram i tid dekker fortsatt ikke et hull naa.
                if ($fra > $idag && self::erForskuttert($betaling)) {
                    $kjopt = (new DateTimeImmutable((string) $betaling['created_at'], new DateTimeZone('UTC')))
                        ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d');
                    if ($kjopt <= $idag) {
                        return true;
                    }
                }
            }
            if ($fra > $idag) continue;
            if ((int) ($plan['engangs'] ?? 0) === 1) {
                $til = (string) ($medlem['slutt_dato'] ?? '');
                if ($til === '') $til = self::proveSlutt($fra);
                if ($til !== '' && $idag <= $til) return true;
            } elseif ($idag < self::dekkerTil(['gjelder_fra' => $fra])) {
                return true;
            }
        }
        // Maaneden er fritatt av en frys (eieren, 2. oktober 2026): trekket
        // for en maaned frysen dekker minst 15 dager av, hoppes over, og
        // dagene i den maaneden som frysen ikke dekker, har medlemmet gratis
        // tilgang i. Frys 20.2–25.3: mars hoppes over, tilgang 26.–31.3.
        if (self::fritattTilgang($medlem, $idag)) {
            return true;
        }
        // Fast trekk som er bestilt og venter paa Vipps: medlemmet beholder
        // tilgangen til forfall + retryDays er passert (eieren, 2. oktober
        // 2026). Sperres naar Vipps sier FAILED, eller fristen gaar ut uten
        // CHARGED. Gjelder bare den som faktisk har et bestilt trekk.
        return self::trekkPaaVei($medlem, $idag) !== null;
    }

    /**
     * Er kalendermaaneden $dato ligger i fritatt av en frys? Samme regel som
     * trekkrunden (hoppOverPause): en godkjent — eller senere avsluttet —
     * frys dekker minst PAUSE_MIN_DAGER dager av den. Da trekkes den ikke,
     * og medlemmet skylder ingenting for den (eieren, 2. oktober 2026).
     */
    public static function fritattMaaned(int $medlemId, string $dato): bool
    {
        if ($medlemId <= 0) {
            return false;
        }
        if (self::pauseDager($medlemId, $dato) >= self::PAUSE_MIN_DAGER) {
            return true;
        }
        // Trekket for maaneden ble hoppet over av en frys som senere ble
        // avsluttet tidlig: maaneden er fortsatt fritatt (betalingseksperten,
        // 2. oktober 2026). Faktumet staar paa avtalen, saa det foelger med
        // naar medlemskapet flyttes.
        $avtaler = array_map('intval', array_column(
            DB::alle('SELECT id FROM subscriptions WHERE member_id = :m', ['m' => $medlemId]), 'id'));
        return self::hoppetOver($avtaler, (new DateTimeImmutable(substr($dato, 0, 10)))->format('Y-m'));
    }

    /** Handlingen i audit_log naar trekkrunden hopper over en maaned for en frys. */
    public const HOPPET_OVER = 'trekk_hoppet_over_frys';

    /**
     * Hoppet trekkrunden over maaneden $maaned (Y-m) paa en av avtalene?
     *
     * @param list<int> $avtaler
     */
    private static function hoppetOver(array $avtaler, string $maaned): bool
    {
        if ($avtaler === []) {
            return false;
        }
        $inn = implode(',', array_map('intval', $avtaler));
        return (int) DB::verdi(
            "SELECT COUNT(*) FROM audit_log
              WHERE handling = :h AND objekt_type = 'subscription' AND objekt_id IN ({$inn})
                AND JSON_UNQUOTE(JSON_EXTRACT(detaljer, '$.maaned')) = :mnd",
            ['h' => self::HOPPET_OVER, 'mnd' => $maaned]
        ) > 0;
    }

    /**
     * Foerste dag i maaneden som skyldes, eller null (betalingseksperten,
     * 2. oktober 2026). Et trekk som feilet gjelder sin maaned; ellers den
     * foerste maaneden etter siste betaling som ikke er fritatt av en frys.
     *
     * @param array<string,mixed> $medlem
     */
    public static function skyldigMaaned(array $medlem, ?string $idag = null): ?string
    {
        $id = (int) ($medlem['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $idag ??= (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
        $a = DB::en('SELECT id, siste_trekk, created_at FROM subscriptions WHERE member_id = :m ORDER BY id DESC LIMIT 1', ['m' => $id]);
        if ($a !== null) {
            $t = self::sisteTrekk([(int) $a['id']])[(int) $a['id']] ?? null;
            // Et trekk som henger paa «venter» etter forfall + retryDays er
            // forfalt (betalingsstatus()), og maaneden skyldes (kontrolloeren,
            // 2. oktober 2026).
            $henger = $t !== null && (string) $t['status'] === 'venter'
                && $idag > self::trekkFrist($t + ['siste_trekk' => $a['siste_trekk'] ?? null])['frist'];
            if ($t !== null && (in_array((string) $t['status'], ['feilet', 'avbrutt'], true) || $henger)) {
                $fra = trim((string) ($t['gjelder_fra'] ?? ''));
                if ($fra === '') {
                    $fra = (new DateTimeImmutable((string) $t['created_at'], new DateTimeZone('UTC')))
                        ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d');
                }
                return (new DateTimeImmutable(substr($fra, 0, 10)))->modify('first day of this month')->format('Y-m-d');
            }
        }
        // Alle betalte maaneder, ikke bare den siste (pengehull 3, 4. oktober
        // 2026): den foerste ubetalte maaneden som ikke er fritatt, skyldes.
        $rad = isset($medlem['medlemskap_type']) && array_key_exists('start_dato', $medlem) ? $medlem
            : (DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]) ?? $medlem);
        if (self::erEngangs((string) ($rad['medlemskap_type'] ?? ''))) {
            return null;
        }
        $dekket = self::dekkedeMaaneder(self::betalteRader([$id])[$id] ?? []);
        if ($dekket['forste'] === null) {
            // Aldri betalt (pengehull 1, 4. oktober 2026): det skyldes fra
            // maaneden verkstedet ellers ville tatt betalt for (Kassa: denne
            // maaneden, eller neste for et nytt medlemskap kjoept etter den
            // 20.) — med mindre en frys har fritatt den. Da staar medlemmet
            // ikke som skyldig (betalingsstatus()), og det er ingenting aa betale.
            $fra = self::gjelderFraForsteBetaling($id)
                ?? (new DateTimeImmutable($idag))->modify('first day of this month')->format('Y-m-d');
            return self::forsteUbetalte($id, substr($fra, 0, 7), [], $idag);
        }
        return self::ubetaltMellom($rad, $a, $dekket, $idag);
    }

    /**
     * Gratis tilgang i en fritatt maaned, paa dagene frysen ikke dekker
     * (foer den starter og etter den er over). Ikke paa en engangsplan (den
     * har sin egen periode), og ikke naar et trekk for maaneden feilet —
     * da staar maaneden ubetalt (kontrolloeren, 2. oktober 2026).
     *
     * @param array<string,mixed> $medlem
     */
    public static function fritattTilgang(array $medlem, string $idag): bool
    {
        $id = (int) ($medlem['id'] ?? 0);
        if ($id <= 0 || !Frys::klar() || !self::fritattMaaned($id, $idag)) {
            return false;
        }
        $plan = self::planUansett((string) ($medlem['medlemskap_type'] ?? ''));
        if ($plan === null || (int) ($plan['engangs'] ?? 0) === 1) {
            return false;
        }
        $dekket = (int) DB::verdi(
            "SELECT COUNT(*) FROM medlem_frys
              WHERE member_id = :m AND status = 'godkjent' AND fra_dato <= :d1 AND til_dato >= :d2",
            ['m' => $id, 'd1' => $idag, 'd2' => $idag]
        );
        if ($dekket > 0) {
            return false;
        }
        if (DB::harKolonne('payments', 'gjelder_fra')) {
            $start = (new DateTimeImmutable($idag))->modify('first day of this month')->format('Y-m-d');
            $slutt = (new DateTimeImmutable($idag))->modify('last day of this month')->format('Y-m-d');
            $feilet = (int) DB::verdi(
                "SELECT COUNT(*) FROM payments
                  WHERE member_id = :m AND formal = 'medlemskap' AND status IN ('feilet', 'avbrutt')
                    AND annullert_at IS NULL AND gjelder_fra BETWEEN :s AND :e",
                ['m' => $id, 's' => $start, 'e' => $slutt]
            );
            if ($feilet > 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Er alle kalendermaaneder fra $fra til og med maaneden $idag ligger i,
     * fritatt av en frys? Da skyldes ingenting for dem (betalingsstatus).
     */
    private static function alleFritatt(int $medlemId, string $fra, string $idag): bool
    {
        $d = (new DateTimeImmutable(substr($fra, 0, 10)))->modify('first day of this month');
        $slutt = (new DateTimeImmutable($idag))->modify('first day of this month');
        if ($d > $slutt) {
            return false;
        }
        for ($n = 0; $d <= $slutt && $n < 24; $d = $d->modify('first day of next month'), $n++) {
            if (!self::fritattMaaned($medlemId, $d->format('Y-m-d'))) {
                return false;
            }
        }
        return $d > $slutt;
    }

    /**
     * Betalingsstatusen for ett medlem, med det oppslaget medlemsruta i admin
     * bruker: den nyeste avtalen, den siste betalingen og det siste trekket.
     *
     * @param array<string,mixed> $medlem
     * @return array{tilstand:string,tekst:string,forfalt:bool,utestaaende:bool}
     */
    public static function betalingsstatusFor(array $medlem, ?string $idag = null): array
    {
        $id = (int) ($medlem['id'] ?? 0);
        $a = $id > 0 ? DB::en('SELECT * FROM subscriptions WHERE member_id = :m ORDER BY id DESC LIMIT 1', ['m' => $id]) : null;
        return self::betalingsstatus(
            $medlem,
            $a,
            $id > 0 ? (self::sisteBetalinger([$id])[$id] ?? null) : null,
            $a === null ? null : (self::sisteTrekk([(int) $a['id']])[(int) $a['id']] ?? null),
            $idag
        );
    }

    /**
     * Forfallet og siste dag Vipps proever et trekk (forfall + retryDays).
     *
     * Leses fra det som ble sendt (payments.trekk_foresporsel). En rad fra
     * foer det ble lagret, faar forfallet utledet som da: tidligst dagen
     * etter at trekket ble bestilt, og ikke foer trekkdatoen paa avtalen
     * (subscriptions.siste_trekk, satt da trekket ble bestilt).
     *
     * @param array<string,mixed> $rad payments-raden, gjerne med siste_trekk fra avtalen
     * @return array{due:string,frist:string}
     */
    public static function trekkFrist(array $rad): array
    {
        $lagret = json_decode((string) ($rad['trekk_foresporsel'] ?? ''), true);
        $kropp = null;
        if (is_array($lagret) && isset($lagret['forsok']) && is_array($lagret['forsok']) && $lagret['forsok'] !== []) {
            $siste = end($lagret['forsok']);
            $kropp = is_array($siste) ? ($siste['kropp'] ?? null) : null;
        } elseif (is_array($lagret) && isset($lagret['due'])) {
            $kropp = $lagret;
        }
        $oslo = new DateTimeZone('Europe/Oslo');
        if (is_array($kropp) && isset($kropp['due'])) {
            $due = (string) $kropp['due'];
            $retry = (int) ($kropp['retryDays'] ?? 5);
        } else {
            $bestilt = (new DateTimeImmutable((string) $rad['created_at'], new DateTimeZone('UTC')))
                ->setTimezone($oslo)->modify('+' . self::VARSEL_DAGER . ' days')->format('Y-m-d');
            $sisteTrekk = trim((string) ($rad['siste_trekk'] ?? ''));
            $fra = trim((string) ($rad['gjelder_fra'] ?? ''));
            $trekkdag = ($sisteTrekk !== '' && ($fra === '' || substr($sisteTrekk, 0, 7) === substr($fra, 0, 7)))
                ? $sisteTrekk : $fra;
            $due = max($bestilt, $trekkdag);
            $retry = (int) Vipps::trekkKropp(0, '', $due)['retryDays'];
        }
        $frist = (new DateTimeImmutable($due, $oslo))->modify('+' . $retry . ' days')->format('Y-m-d');
        return ['due' => $due, 'frist' => $frist];
    }

    /**
     * Et bestilt trekk som dekker perioden som loeper, og der Vipps
     * fortsatt kan trekke (i dag <= forfall + retryDays). Ellers null.
     *
     * @param array<string,mixed> $medlem
     * @return array{due:string,frist:string,betaling:int}|null
     */
    public static function trekkPaaVei(array $medlem, ?string $idag = null): ?array
    {
        $id = (int) ($medlem['id'] ?? 0);
        if ($id <= 0 || !DB::harKolonne('payments', 'gjelder_fra')) {
            return null;
        }
        $idag ??= (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
        $lagret = DB::harKolonne('payments', 'trekk_foresporsel') ? 'p.trekk_foresporsel' : 'NULL AS trekk_foresporsel';
        // De nyeste: et trekk for neste maaned kan alt vaere bestilt mens
        // trekket for denne fortsatt proeves.
        $rader = DB::alle(
            "SELECT p.id, p.created_at, p.gjelder_fra, {$lagret}, s.siste_trekk
               FROM payments p
               JOIN subscriptions s ON s.id = p.subscription_id
              WHERE p.member_id = :m AND p.formal = 'medlemskap' AND p.type = 'recurring_charge'
                AND p.status = 'venter' AND p.annullert_at IS NULL
                AND s.status = 'aktiv' AND COALESCE(s.vipps_agreement_id, '') <> ''
           ORDER BY p.id DESC LIMIT 3",
            ['m' => $id]
        );
        foreach ($rader as $rad) {
            $fra = trim((string) ($rad['gjelder_fra'] ?? ''));
            $start = $fra !== ''
                ? (new DateTimeImmutable($fra))->modify('first day of this month')->format('Y-m-d')
                : (new DateTimeImmutable((string) $rad['created_at'], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Europe/Oslo'))->modify('first day of this month')->format('Y-m-d');
            $f = self::trekkFrist($rad);
            // Fristen gjelder ogsaa over et maanedsskifte: Vipps proever et
            // trekk med forfall 31. oktober til og med 5. november.
            if ($start <= $idag && $idag <= $f['frist']) {
                return $f + ['betaling' => (int) $rad['id']];
            }
        }
        return null;
    }

    /**
     * Ingen ny periode eller fornyelse for en frosset periode (eieren, 2. oktober
     * 2026). Gjelder den som er stengt ute av en frys (Frys::frystNaa); har
     * medlemmet betalt for i dag, er det ikke stengt, og kan fornye som foer.
     *
     * Staar det noe ubetalt — et trekk som feilet, eller en maaned frysen
     * dekker under 15 dager av — kan det fortsatt betales (kontrolloeren og
     * betalingseksperten, 2. oktober 2026). Bare det som ikke skyldes, sperres.
     * Da gjelder betalingen maaneden som skyldes, ikke den fryste maaneden:
     * svaret er foerste dag i den maaneden.
     *
     * $nyPeriode: et nytt medlemskap eller en ny avtale (startAvtale,
     * startEngangs, startIVerkstedet) sperres alltid mens frysen stenger
     * medlemmet ute. Bare betaling av det utestaaende paa avtalen som alt
     * loeper (fornyPeriode) slipper gjennom.
     *
     * Fast trekk: eieren (2. oktober 2026) valgte aa forenkle. Et fryst
     * medlem med fast trekk betaler ikke det utestaaende selv — det betales i
     * verkstedet (Kassa/admin) eller tas av Vipps sitt nye forsoek. Da kan
     * egenbetalingen aldri kollidere med et trekk for den samme maaneden.
     *
     * @param array<string,mixed> $medlem
     * @return string|null gjelder_fra for det utestaaende, eller null naar medlemmet ikke er fryst
     */
    private static function sperrFryst(array $medlem, bool $nyPeriode, ?string $idag = null): ?string
    {
        $rad = isset($medlem['status']) ? $medlem
            : (DB::en('SELECT * FROM members WHERE id = :i', ['i' => (int) ($medlem['id'] ?? 0)]) ?? $medlem);
        $fryst = Frys::frystNaa($rad, $idag);
        if ($fryst === null) {
            return null;
        }
        if (!$nyPeriode && !self::harFastTrekk((int) ($rad['id'] ?? 0))
            && self::betalingsstatusFor($rad, $idag)['utestaaende']) {
            $skyldig = self::skyldigMaaned($rad, $idag);
            if ($skyldig !== null) {
                return $skyldig;
            }
        }
        throw new RuntimeException('Medlemskapet ditt er fryst til ' . Booking::norskDatoKort($fryst['til'])
            . '. Du kan forny medlemskapet igjen når frysen er over.');
    }

    /** Har medlemmet en loepende avtale med fast trekk i Vipps? */
    public static function harFastTrekk(int $medlemId): bool
    {
        return $medlemId > 0 && DB::verdi(
            "SELECT id FROM subscriptions
              WHERE member_id = :m AND status = 'aktiv'
                AND vipps_agreement_id IS NOT NULL AND vipps_agreement_id <> '' LIMIT 1",
            ['m' => $medlemId]
        ) !== null;
    }

    /**
     * «Forny» paa et medlemskap som gjores opp selv: én betaling i Vipps for
     * neste periode, paa avtalen som alt loeper. Ingen ny avtale.
     *
     * Eieren, 29. september 2026: perioden gjelder fra der forrige betaling
     * slutter. Er den forfalt, gjelder den fra i dag. Naar betalingen er i
     * havn, er det den som er «siste betaling», og betalingsstatus() regner
     * neste forfall av den.
     *
     * @param array<string,mixed> $medlem
     * @param array<string,mixed> $avtale den aktive avtalen
     * @return array{url:string,id:int,gjentakelse:bool}
     */
    public static function fornyPeriode(array $medlem, array $avtale): array
    {
        return self::fornyPeriodePaa($medlem, $avtale, null);
    }

    /**
     * Som fornyPeriode(), med dagen satt. $idagFor er bare for testene
     * (tests/frys-trekk.php); null er i dag.
     *
     * @param array<string,mixed> $medlem
     * @param array<string,mixed> $avtale
     * @return array{url:string,id:int,gjentakelse:bool}
     */
    public static function fornyPeriodePaa(array $medlem, array $avtale, ?string $idagFor): array
    {
        $medlemId = (int) $medlem['id'];
        $avtaleId = (int) $avtale['id'];
        $planNavn = (string) $avtale['plan'];
        $plan = self::planUansett($planNavn);
        if ($plan === null) {
            throw new RuntimeException('Ukjent medlemskap.');
        }
        $idag = $idagFor ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
        $pris = (int) $plan['pris_ore'];
        $referanse = Vipps::nyReferanse('MED');

        // Pengehull 2 (betalingseksperten, 4. oktober 2026): Kassa og «Forny»
        // kunne registrere den samme maaneden samtidig. Medlemmet laases —
        // samme laas som Kassa tar (api/admin/medlemmer.php, L-12) — og
        // perioden regnes og raden lagres under laasen. Kassa ser da raden
        // som er paa vei, og nekter; «Forny» ser det Kassa har registrert.
        $svar = DB::iTransaksjon(static function () use ($medlem, $avtaleId, $medlemId, $idag, $idagFor, $pris, $referanse): array {
            DB::en('SELECT id FROM members WHERE id = :i FOR UPDATE', ['i' => $medlemId]);
            // Fryst med noe utestaaende: betalingen gjelder maaneden som skyldes.
            $skyldig = self::sperrFryst($medlem, false, $idagFor);

            // Samme forsoek to ganger skal gi den samme betalingen, ikke to.
            $igjen = DB::en(
                "SELECT id FROM payments
                  WHERE subscription_id = :s AND formal = 'medlemskap' AND status IN ('opprettet','venter')
                    AND created_at > (UTC_TIMESTAMP() - INTERVAL 30 MINUTE)
                  ORDER BY id DESC LIMIT 1",
                ['s' => $avtaleId]
            );
            $url = trim((string) (DB::verdi('SELECT vipps_url FROM subscriptions WHERE id = :i', ['i' => $avtaleId]) ?? ''));
            if ($igjen !== null && $url !== '') {
                return ['url' => $url, 'id' => $avtaleId, 'gjentakelse' => true];
            }
            if ($igjen !== null) {
                // Det foerste trykket er fortsatt paa vei til Vipps.
                throw new RuntimeException('Betalingen pågår i Vipps. Prøv igjen om litt.');
            }

            // Perioden: en eldre maaned som skyldes, betales foerst (pengehull
            // 3). Ellers den foerste maaneden fra denne som ikke er betalt —
            // denne maaneden gjelder fra i dag, som foer.
            $denne = (new DateTimeImmutable($idag))->modify('first day of this month')->format('Y-m-d');
            $gjeld = $skyldig;
            if ($gjeld === null) {
                $rad = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $medlemId]) ?? $medlem;
                $eldre = self::skyldigMaaned($rad, $idag);
                $gjeld = $eldre !== null && $eldre < $denne ? $eldre : null;
            }
            if ($gjeld !== null) {
                $gjelderFra = $gjeld;
            } else {
                $dekket = self::dekkedeMaaneder(self::betalteRader([$medlemId])[$medlemId] ?? [])['maaneder'];
                $d = new DateTimeImmutable($denne);
                for ($n = 0; isset($dekket[$d->format('Y-m')]) && $n < 24; $n++) {
                    $d = $d->modify('first day of next month');
                }
                $gjelderFra = $d->format('Y-m-d') === $denne ? $idag : $d->format('Y-m-d');
            }

            $rad = [
                'vipps_reference' => $referanse,
                'type'            => 'epayment',
                'formal'          => 'medlemskap',
                'member_id'       => $medlemId,
                'subscription_id' => $avtaleId,
                'belop_ore'       => $pris,
                'status'          => 'opprettet',
                'idempotency_key' => Vipps::uuid(),
            ];
            if (DB::harKolonne('payments', 'gjelder_fra')) {
                $rad['gjelder_fra'] = $gjelderFra;
            }
            return ['betalingId' => DB::settInn('payments', $rad), 'gjelderFra' => $gjelderFra];
        });
        if (isset($svar['url'])) {
            return $svar;
        }
        $betalingId = (int) $svar['betalingId'];
        $gjelderFra = (string) $svar['gjelderFra'];

        try {
            $betaling = Vipps::opprettBetaling(
                $referanse,
                $pris,
                Vipps::beskrivelse('Medlemskap hos Lissom — ' . (string) $avtale['plan'], (string) ($medlem['navn'] ?? '')),
                Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
                $medlem['telefon'] ?? null
            );
        } catch (Throwable $e) {
            DB::oppdater('payments', ['status' => 'feilet'], ['id' => $betalingId]);
            logg_feil('Fikk ikke startet fornyelse for medlem ' . $medlemId, $e);
            throw new RuntimeException('Fikk ikke startet betalingen. Prøv igjen om litt.');
        }

        DB::oppdater('payments', ['status' => 'venter'], ['id' => $betalingId]);
        self::husk($avtaleId, (string) $betaling['url']);
        revider('medlemskap_fornyelse_startet', 'subscription', $avtaleId,
            ['plan' => (string) $avtale['plan'], 'gjelderFra' => $gjelderFra]);
        return ['url' => (string) $betaling['url'], 'id' => $avtaleId, 'gjentakelse' => false];
    }

    /**
     * Et nytt medlemskap er blitt aktivt: proeveperioden det erstatter,
     * avsluttes. Ingen refusjon. Kalles naar det nye blir AKTIVT, ikke naar
     * det startes — snur hun i Vipps, skal proeveperioden staa.
     *
     * Hver gammel avtale avsluttes én gang: «AND status = 'aktiv'» i samme
     * oppdatering, saa to runder samtidig ikke logger det to ganger.
     *
     * @return int antall avtaler som ble avsluttet
     */
    public static function erstattProve(int $medlemId, int $nyId, string $nyPlan): int
    {
        $gamle = DB::alle(
            "SELECT id, plan FROM subscriptions
              WHERE member_id = :m AND status = 'aktiv' AND id <> :n",
            ['m' => $medlemId, 'n' => $nyId]
        );
        $antall = 0;
        foreach ($gamle as $g) {
            if (!self::erEngangs((string) $g['plan'])) {
                // Et loepende medlemskap som er oppgradert (eieren, 29.
                // september 2026): det nye gjelder fra i dag, og det gamle
                // stopper i dag. Ingen refusjon. Timene som alt er stemplet
                // denne maaneden, teller paa det nye.
                self::stoppErstattet((int) $g['id'], $medlemId, (string) $g['plan'], $nyId, $nyPlan);
                continue;
            }
            // Timene paa proeveperioden, foer den avsluttes, til loggen i
            // admin. Det nye medlemskapet gjelder alt denne maaneden, og det
            // som ble stemplet paa proeveperioden teller ikke paa det — se
            // Stempling::proveFradrag(). Timene over vurderer eieren selv
            // (28. september 2026); de staar i proveOverMin().
            $rad = DB::en('SELECT * FROM members WHERE id = :m', ['m' => $medlemId]) ?? [];
            $bruktMin = Stempling::minutterDenneManeden($medlemId);
            // Taket paa proeveperioden: planens timer, pluss gavetimer og
            // dugnad. Medlemsraden kan alt staa paa det nye medlemskapet.
            $provePlan = self::planUansett((string) $g['plan']);
            $tak = $rad === [] || $provePlan === null || $provePlan['timer'] === null ? null
                : (int) $provePlan['timer'] + ((self::timerMedGaver($rad) ?? 0) - (self::timerFor($rad) ?? 0));
            $endret = DB::kjor(
                "UPDATE subscriptions
                    SET status = 'stoppet', sagt_opp_at = UTC_TIMESTAMP(),
                        slutter = CURDATE(), neste_trekk = NULL
                  WHERE id = :i AND status = 'aktiv'",
                ['i' => (int) $g['id']]
            )->rowCount();
            if ($endret === 1) {
                $antall++;
                revider('medlemskap_erstattet', 'member', $medlemId, [
                    'fra' => (string) $g['plan'], 'fraAvtale' => (int) $g['id'],
                    'til' => $nyPlan, 'tilAvtale' => $nyId, 'refusjon' => 'ingen',
                    'timerBrukt' => Stempling::timer($bruktMin),
                    'timerTak'   => $tak,
                    'timerOver'  => $tak === null ? null : Stempling::timer((int) max(0, $bruktMin - $tak * 60)),
                    'timerOverMin' => $tak === null ? 0 : (int) max(0, $bruktMin - $tak * 60),
                ]);
            }
        }
        // Det nye er ikke en proeveperiode: sluttdatoen hoerte til den gamle,
        // og ville ellers meldt hen ut den dagen.
        if ($antall > 0 && !self::erEngangs($nyPlan)) {
            DB::oppdater('members', ['slutt_dato' => null], ['id' => $medlemId]);
        }
        return $antall;
    }

    /**
     * Stopper et loepende medlemskap et nytt har erstattet. Har det fast
     * trekk, stoppes avtalen ogsaa i Vipps, saa den ikke trekkes igjen.
     */
    private static function stoppErstattet(int $gammelId, int $medlemId, string $gammelPlan, int $nyId, string $nyPlan): void
    {
        $g = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $gammelId]);
        if ($g === null) {
            return;
        }
        $endret = DB::kjor(
            "UPDATE subscriptions
                SET status = 'stoppet', sagt_opp_at = UTC_TIMESTAMP(),
                    slutter = CURDATE(), neste_trekk = NULL
              WHERE id = :i AND status = 'aktiv'",
            ['i' => $gammelId]
        )->rowCount();
        if ($endret !== 1) {
            return;
        }
        if (trim((string) ($g['vipps_agreement_id'] ?? '')) !== '') {
            try {
                Vipps::stoppAvtale((string) $g['vipps_agreement_id']);
            } catch (Throwable $e) {
                logg_feil('Fikk ikke stoppet avtale ' . $gammelId . ' i Vipps etter oppgradering', $e);
            }
        }
        revider('medlemskap_erstattet', 'member', $medlemId, [
            'fra' => $gammelPlan, 'fraAvtale' => $gammelId,
            'til' => $nyPlan, 'tilAvtale' => $nyId, 'refusjon' => 'ingen', 'oppgradert' => true,
        ]);
    }

    /**
     * Minutter stemplet ut over Prøv Lissom denne maaneden.
     *
     * Eieren, 28. september 2026: timene over de ti trekkes ikke av seg
     * selv. Admin viser dem paa medlemmet, og eieren trekker dem med
     * timetallet paa medlemmet om hen vil. Staar hen paa proeveperioden,
     * regnes de av det som er stemplet naa; er den erstattet denne maaneden,
     * staar tallet i endringsloggen fra byttet.
     *
     * @param array<string,mixed> $medlem
     */
    public static function proveOverMin(array $medlem, int $bruktMin): int
    {
        if (self::erEngangs((string) ($medlem['medlemskap_type'] ?? ''))) {
            $tak = self::timerMedGaver($medlem);
            return $tak === null ? 0 : (int) max(0, $bruktMin - $tak * 60);
        }
        $d = DB::verdi(
            "SELECT detaljer FROM audit_log
              WHERE handling = 'medlemskap_erstattet' AND objekt_type = 'member' AND objekt_id = :m
                AND created_at >= :fra
              ORDER BY id DESC LIMIT 1",
            ['m' => (int) $medlem['id'], 'fra' => Stempling::manedStart()]
        );
        $d = is_string($d) ? json_decode($d, true) : null;
        return is_array($d) ? (int) ($d['timerOverMin'] ?? 0) : 0;
    }

    /**
     * Faar dette medlemmet selge sine egne arbeider?
     *
     * Eieren, 22. september 2026: «Jeg vil at alle medlemskap skal faa denne
     * muligheten, men ikke proev lissom.»
     *
     * Regelen gaar ikke etter navn. Det sto «=== 'Aarsmedlemskap'» to steder
     * — i api/medlemssalg.php og i kanSelge() paa skjermen — og et navn er
     * det skjoreste vi har aa henge en rettighet paa. Da «30 timer» ble doept
     * om til «Basis 30» traff reglene som gikk etter navn ingen. Migrasjon
     * 034 sa det allerede: «Denne gaar ikke etter navn. Den ser paa hva
     * planen ER: er den engangs, er det proeveperioden.»
     *
     * Saa: et loepende medlemskap gir salg, en proevemaaned gjor det ikke.
     * «Proev Lissom» er den eneste planen med «engangs = 1». Legger
     * verkstedet inn et nytt medlemskap i morgen, foelger det regelen av seg
     * selv — ingen kode aa huske paa.
     *
     * Admin er innenfor som ellers. Eieren, 12. september 2026: «jeg faar
     * ikke solgt paa min side i allefall» — admin-kontoen staar ikke paa noen
     * plan, og da var doera lukket for den som skulle proeve den.
     *
     * Staar medlemmet paa en plan som ikke finnes i basen, er svaret nei.
     * Vi vet da ikke om den er engangs, og en rettighet skal ikke falle ut
     * av det vi ikke vet.
     *
     * @param array<string,mixed> $medlem
     */
    public static function kanSelge(array $medlem): bool
    {
        if ((string) ($medlem['rolle'] ?? '') === 'admin') {
            return true;
        }
        if (!er_aktivt_medlem($medlem)) {
            return false;
        }
        $plan = self::planUansett(trim((string) ($medlem['medlemskap_type'] ?? '')));
        return $plan !== null && (int) ($plan['engangs'] ?? 0) === 0;
    }

    /**
     * Alle tabellene som peker paa et medlem, lest av basen selv.
     *
     * Lista skrives ikke for haand. Den som legger til en tabell med
     * «member_id» neste gang skal ikke trenge aa huske hverken
     * sammenslaaingen av dubletter eller nullstillingen — begge leser denne.
     *
     * @return list<array{tabell:string,kolonne:string}>
     */
    public static function pekere(): array
    {
        $ut = [];
        foreach (DB::alle(
            "SELECT table_name AS t, column_name AS k
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND column_name IN ('member_id', 'registrert_av')
                AND table_name <> 'members'
           ORDER BY table_name, column_name"
        ) as $r) {
            $t = (string) $r['t'];
            $k = (string) $r['k'];
            // Navnene kommer fra basen, ikke fra en forespoersel — men de
            // settes inn i SQL som identifikatorer, saa de sjekkes likevel.
            if (preg_match('/^[a-z_]+$/', $t) && preg_match('/^[a-z_]+$/', $k)) {
                $ut[] = ['tabell' => $t, 'kolonne' => $k];
            }
        }
        return $ut;
    }

    /** @return list<array<string,mixed>> */
    public static function planer(): array
    {
        return DB::alle('SELECT * FROM membership_plans WHERE aktiv = 1 ORDER BY sortering');
    }

    /**
     * Punktlista paa kortet, ett punkt per linje.
     *
     * Verkstedet skriver den i et vanlig tekstfelt. Tomme linjer og
     * kulepunkter de har skrevet selv fjernes — kortet setter sin egen prikk.
     *
     * @return list<string>
     */
    /**
     * Maa dette medlemskapet ha fast trekk?
     *
     * Aarsmedlemskapet bindes i tolv maaneder, og da er det avtalen som er
     * hele grunnlaget. De andre lar medlemmet velge selv.
     *
     * Kolonna kom med migrasjon 081. Er den ikke kjort, krever ingen plan
     * fast trekk — altsaa som for.
     *
     * @param array<string,mixed> $plan
     */
    public static function kreverFastTrekk(array $plan): bool
    {
        return (int) ($plan['krever_fast_trekk'] ?? 0) === 1;
    }

    public static function punkter(?string $raa): array
    {
        $ut = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $raa) ?: [] as $linje) {
            $linje = trim((string) preg_replace('/^[\s\-\*\x{2022}\x{00b7}]+/u', '', $linje));
            if ($linje !== '') {
                $ut[] = $linje;
            }
        }
        return $ut;
    }

    /**
     * Hvor mange timer i maaneden dette medlemmet har.
     *
     * «timer_per_mnd» paa medlemmet ble aldri fylt ut noe sted — den ble bare
     * lest. Alle sto derfor med NULL, som betyr fri tilgang, og Min side viste
     * ingen timeoversikt til noen. Poenget med et 30-timers medlemskap er
     * nettopp de tretti timene.
     *
     * Planen bestemmer, medlemsraden overstyrer: setter verkstedet et eget
     * timetall paa én person, gjelder det foran planen. NULL fra planen (Fri
     * tilgang) betyr fortsatt ingen grense.
     */
    public static function timerFor(array $medlem): ?int
    {
        if ($medlem['timer_per_mnd'] !== null) {
            return (int) $medlem['timer_per_mnd'];
        }
        $type = trim((string) ($medlem['medlemskap_type'] ?? ''));
        if ($type === '') {
            return null;
        }
        $plan = self::planUansett($type);
        return $plan === null || $plan['timer'] === null ? null : (int) $plan['timer'];
    }

    /**
     * Gavetimer medlemmet har loest inn, og som fortsatt gjelder.
     *
     * En timegave gjor ingenting av seg selv: den skriver en rad i
     * «medlemsgave_bruk» og sender en beskjed til verkstedet. Eieren, 8.
     * september 2026: «jeg trykte paa loes inn gaven, saa fikk jeg en pop upp,
     * verkstedet har faatt beskjed, ta med gaven din neste gang. jeg vil jo at
     * den skal legges paa antall timer de har igjen paa medlemskapet sitt» —
     * og «de faar jo ikke noe fysisk».
     *
     * Bare gaver som fortsatt staar: en trukket gave gir ingen timer, og en
     * utloept heller ikke. Gaven gjelder ut maaneden, saa timene gjor det
     * ogsaa — som resten av timene.
     *
     * Datoen leses av gaven, ikke av innloesningen: «gyldig_til» settes av
     * oss i norsk tid, mens «created_at» paa bruken er databasens egen klokke.
     * En gave kan bare loeses inn mens den gjelder, saa de to sier det samme.
     */
    public static function gavetimer(int $medlemId): int
    {
        if (!DB::harTabell('medlemsgaver') || !DB::harTabell('medlemsgave_bruk')) {
            return 0;
        }
        return (int) DB::verdi(
            "SELECT COALESCE(SUM(g.timer), 0)
               FROM medlemsgave_bruk b
               JOIN medlemsgaver g ON g.id = b.gave_id
              WHERE b.member_id = :m
                AND g.type = 'timer'
                AND g.status = 'aktiv'
                AND g.gyldig_til >= :idag",
            ['m' => $medlemId,
             'idag' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d')]
        );
    }

    /**
     * Timetaket denne maaneden, med innloeste gavetimer lagt til.
     *
     * Ett sted, ikke to: Min side og medlemslista i admin leser den samme
     * regelen, saa de aldri kan si hver sitt om den samme personen.
     *
     * Fri tilgang blir staaende fri: har planen ingen grense, er det
     * ingenting aa legge timer til.
     */
    public static function timerMedGaver(array $medlem, bool $medPakke = true): int|float|null
    {
        $tak = self::timerFor($medlem);
        if ($tak === null) {
            return null;
        }
        // Dugnadstimer legges ogsaa til (eieren, 15. september 2026) — de
        // rundes til kvarter, saa taket kan bli 31,75. Se Dugnad::minutterTilgode().
        $dugnad = class_exists('Dugnad') ? Dugnad::minutterTilgode($medlem) : 0;
        // Kursholdertimer for den som faar «Timer» i stedet for lønn (eieren,
        // 26. september 2026). Se Kursholder::minutterTilgode().
        $dugnad += class_exists('Kursholder') ? Kursholder::minutterTilgode($medlem) : 0;
        // Timepakker (eieren, 28. september 2026): betalte pakketimer som ikke
        // er brukt i en tidligere maaned. Se Timepakke::tilgodeMin().
        if ($medPakke && class_exists('Timepakke')) {
            $dugnad += Timepakke::tilgodeMin((int) ($medlem['id'] ?? 0));
        }
        $sum = $tak + self::gavetimer((int) ($medlem['id'] ?? 0)) + $dugnad / 60;
        return $dugnad % 60 === 0 ? (int) $sum : round($sum, 2);
    }

    /**
     * Har medlemmet betalt for medlemskapet sitt?
     *
     * Skjermen viste «Fast trekk» eller «Gjor opp selv» — det er
     * betalingsMAATEN, ikke betalingen. Eieren, 2. september: «jeg kan ikke se
     * paa min side paa et medlem om det er betalt for medlemskapet eller
     * ikke». Tallene laa i basen hele tida; ingen slo dem opp.
     *
     * Regelen staar her og ikke i skjermene fordi tre steder spor om den:
     * medlemslista, kortet paa Oversikt og medlemsruta. Sto den tre steder,
     * kunne de svart hver sitt om den samme personen.
     *
     * @param array      $medlem  raden fra members
     * @param array|null $avtale  nyeste subscriptions-rad, eller null
     * @param array|null $siste   nyeste betalte medlemskapsbetaling, eller null
     * @param array|null $trekk   nyeste trekk paa avtalen uansett utfall, eller null
     * @return array{tilstand:string,tekst:string,forfalt:bool}
     *
     * tilstand er én av:
     *   fri         medlemmet skal ikke betale (haken i admin)
     *   betalt      det er gjort opp for perioden som loper
     *   bestilt     trekket er bedt om, men pengene har ikke flyttet seg enda
     *               — Vipps krever forvarsel, saa det tar noen dager
     *   forfalt     perioden er ute og det er ikke betalt
     *   venter      meldt inn, men foerste betaling er ikke kommet enda
     *   over        proeveperioden (engangsplan) er over — ingenting skyldes
     *   ingen       ikke medlem — ingenting aa betale for
     */
    public static function betalingsstatus(array $medlem, ?array $avtale, ?array $siste, ?array $trekk = null, ?string $idagFor = null): array
    {
        $b = self::betalingsstatusRaa($medlem, $avtale, $siste, $trekk, $idagFor);
        // Fryst, og ingenting aa betale (pengehull 1, 4. oktober 2026): staar
        // det noe utestaaende uten at det finnes en maaned som skyldes
        // (skyldigMaaned()), kunne verken Kassa eller Min side ta betalt for
        // det. Da er det heller ikke utestaaende: maanedene er fritatt av
        // frysen. Et trekk som feilet, eller en maaned frysen dekker under 15
        // dager av, gir en maaned som skyldes — og staar som foer.
        $medlemId = (int) ($medlem['id'] ?? 0);
        if ($b['utestaaende'] && $medlemId > 0 && Frys::klar()) {
            $idag = $idagFor ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
            $fryst = Frys::frystNaa($medlem, $idag);
            if ($fryst !== null && self::skyldigMaaned($medlem, $idag) === null) {
                return ['tilstand' => 'fryst', 'tekst' => 'Fryst til ' . Booking::norskDatoKort($fryst['til'] . ' 12:00:00'),
                        'forfalt' => false, 'utestaaende' => false];
            }
        }
        return $b;
    }

    /**
     * betalingsstatus() uten sluttsjekken for fryste medlemmer.
     *
     * @param array<string,mixed>      $medlem
     * @param array<string,mixed>|null $avtale
     * @param array<string,mixed>|null $siste
     * @param array<string,mixed>|null $trekk
     * @return array{tilstand:string,tekst:string,forfalt:bool,utestaaende:bool}
     */
    private static function betalingsstatusRaa(array $medlem, ?array $avtale, ?array $siste, ?array $trekk = null, ?string $idagFor = null): array
    {
        // To forskjellige spoersmaal, og de ble blandet:
        //
        //   forfalt      — pengene skulle vaert her, og er det ikke. Roedt.
        //   utestaaende  — pengene er ikke inne. Kan vaere helt i orden
        //                  (trekket er bestilt, forfallet er ikke naadd), men
        //                  verkstedet skal likevel se det.
        //
        // Eieren, 2. september: «verken hun eller Eirin kommer opp i kortet
        // ikke betalt paa oversikten, og det maa de jo, helt til pengene er
        // inne». Eirin sto med et trekk som ikke var forfalt enda, og falt
        // dermed ut av tellingen — enda ingen krone hadde kommet.
        //
        // «utestaaende» folger «forfalt» naar den ikke settes: det som er
        // forfalt er alltid ogsaa utestaaende.
        $ut = static fn(string $t, string $tekst, bool $forfalt = false, ?bool $ute = null): array
            => ['tilstand' => $t, 'tekst' => $tekst, 'forfalt' => $forfalt,
                'utestaaende' => $ute === null ? $forfalt : $ute];

        // Haken gaar foran alt. Et gratismedlem skal aldri lyse roedt.
        if (!empty($medlem['betaler_ikke'])) {
            $grunn = trim((string) ($medlem['betaler_ikke_grunn'] ?? ''));
            return $ut('fri', $grunn !== '' ? 'Fri — ' . $grunn : 'Betaler ikke');
        }

        $status = (string) ($medlem['status'] ?? 'ingen');
        if (!in_array($status, ['prove', 'aktiv', 'pause'], true)) {
            return $ut('ingen', '');
        }

        // $idagFor er bare for testene.
        $idag = $idagFor ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
        $kort = static fn(string $d): string => Booking::norskDatoKort($d . ' 12:00:00');

        // ── Proeveperioden er over ──────────────────────────────────────
        //
        // Eieren, 2. oktober 2026 (Ida): ikke «Betalt», ikke «Venter paa
        // betaling», men at den er sluttet. Ikke utestaaende — da staar hun
        // ikke i «Ikke betalt» i Kassa, og telles ikke som ubetalt.
        $sluttet = self::proveSluttet($medlem, $idag);
        if ($sluttet !== null) {
            $pNavn = (string) (self::planUansett((string) ($medlem['medlemskap_type'] ?? ''))['navn']
                ?? $medlem['medlemskap_type']);
            return $ut('over', $pNavn . ' sluttet ' . $kort($sluttet), false, false);
        }

        // ── Fritatt av en frys ──────────────────────────────────────────
        //
        // Eieren, 2. oktober 2026: maaneder en frys dekker minst 15 dager av,
        // trekkes ikke, og skyldes ikke. Er det ubetalte BARE slike maaneder,
        // staar medlemmet som «Fryst til <dato>», ikke forfalt og ikke
        // utestaaende. Alt annet — et trekk som feilet, en maaned frysen
        // dekker under 15 dager av — staar som foer, forfalt og betalbart
        // (kontrolloeren og betalingseksperten, samme dag). Kalles bare fra
        // grenene der noe ellers ville staatt ubetalt fra og med $fra.
        $medlemId = (int) ($medlem['id'] ?? 0);

        // ── En ubetalt maaned foer den siste betalte ──────────────────────
        //
        // Pengehull 3 (betalingseksperten, 4. oktober 2026): «betalt» ble
        // lest av den siste betalingen alene. Var februar ubetalt og mars
        // betalt, sto medlemmet som betalt, og februar forsvant. Sjekkes bare
        // der svaret ellers ville vaert «betalt» eller «fryst».
        $hull = static function () use ($medlem, $avtale, $siste, $idag): ?string {
            if ($siste === null) {
                return null;
            }
            return self::ubetaltMellom($medlem, $avtale,
                isset($siste['_dekket']) && is_array($siste['_dekket']) ? $siste['_dekket'] : null, $idag);
        };
        // «Forfalt <maaned> · sist betalt <dato>» — samme tekst som under.
        $hullSvar = static function (?string $mnd) use ($siste, $ut, $kort): ?array {
            return $mnd === null || $siste === null ? null
                : $ut('forfalt', 'Forfalt ' . $kort($mnd) . ' · sist betalt '
                    . $kort(substr((string) $siste['created_at'], 0, 10)), true);
        };

        $fritak = static function (string $fra) use ($medlem, $medlemId, $idag, $ut, $kort, $hull): ?array {
            if ($medlemId <= 0 || !Frys::klar() || !self::alleFritatt($medlemId, $fra, $idag)) {
                return null;
            }
            // En ubetalt maaned foer frysen skjules ikke av den.
            if ($hull() !== null) {
                return null;
            }
            $fryst = Frys::frystNaa($medlem, $idag);
            $til = $fryst !== null ? $fryst['til'] : (string) DB::verdi(
                "SELECT MAX(til_dato) FROM medlem_frys
                  WHERE member_id = :m AND status IN ('godkjent', 'avsluttet') AND fra_dato <= :d",
                ['m' => $medlemId, 'd' => $idag]
            );
            return $ut('fryst', $til !== '' ? 'Fryst til ' . $kort($til) : 'Fryst', false, false);
        };

        // ── Fast trekk i Vipps ──────────────────────────────────────────
        //
        // Her er det Vipps som trekker, og «neste_trekk» er fasiten paa om
        // perioden er dekket. Har dagen passert uten at «siste_trekk» fulgte
        // etter, gikk trekket ikke gjennom.
        //
        // Statusen maa vaere med. Et forsok som ble staaende paa «venter»
        // har ogsaa en avtale-id hos Vipps, men ingenting trekkes paa den —
        // den ble aldri godkjent. Merket sa likevel «Fast trekk». Eieren,
        // 3. september, om Eirin: «hun staar oppfort med fast trekk, men
        // ikke trukket». Se loepende().
        $fastTrekk = $avtale !== null
            && (string) ($avtale['status'] ?? '') === 'aktiv'
            && trim((string) ($avtale['vipps_agreement_id'] ?? '')) !== '';
        if ($fastTrekk) {
            $neste = (string) ($avtale['neste_trekk'] ?? '');
            $sist  = (string) ($avtale['siste_trekk'] ?? '');

            // ── Trekket, slik det faktisk gikk ──────────────────────────
            //
            // «siste_trekk» settes i det trekket BES OM. Vipps krever at
            // kunden varsles for et fast trekk, saa forfallet ligger noen
            // dager fram — og i mellomtida har ingen penger flyttet seg.
            // Leste vi bare den datoen, sto det «Betalt» om noe som bare var
            // bestilt, og det ble staaende ogsaa om trekket senere feilet.
            $tstatus = $trekk === null ? '' : (string) $trekk['status'];
            if ($tstatus === 'feilet' || $tstatus === 'avbrutt') {
                return $ut('forfalt', 'Trekket gikk ikke — prøvd '
                    . $kort(substr((string) $trekk['created_at'], 0, 10)), true);
            }
            if ($tstatus === 'opprettet' || $tstatus === 'venter') {
                // Eieren, 2. oktober 2026: medlemmet har tilgang mens trekket
                // er paa vei (se trekkPaaVei()), og skjermen skal si det —
                // ikke «sperret». Er fristen (forfall + retryDays) ute uten
                // svar, er det forfalt.
                $f = self::trekkFrist($trekk + ['siste_trekk' => $sist]);
                if ($tstatus === 'venter' && $idag > $f['frist']) {
                    return $ut('forfalt', 'Trekket gikk ikke · forfall ' . $kort($f['due']), true);
                }
                return $ut('bestilt', 'Trekk på vei · forfall ' . $kort($f['due']), false, true);
            }
            if ($tstatus === 'betalt' || $tstatus === 'delvis_refundert') {
                $betaltTil = self::dekkerTil($siste ?? $trekk);
                if ($betaltTil <= $idag) {
                    if (($f = $fritak($betaltTil)) !== null) {
                        return $f;
                    }
                    return $ut('forfalt', 'Inneværende måned er ikke betalt · sist trukket '
                        . $kort(substr((string) $trekk['created_at'], 0, 10)), true);
                }
                if (($h = $hullSvar($hull())) !== null) {
                    return $h;
                }
                return $ut('betalt', 'Trukket '
                    . $kort(substr((string) $trekk['created_at'], 0, 10))
                    . ($neste !== '' ? ' · neste ' . $kort($neste) : ''));
            }

            // Ingen trekk aa se paa enda. Da er datoene alt vi har.
            if ($neste !== '' && $neste < $idag) {
                return $ut('forfalt', 'Skulle vært trukket ' . $kort($neste), true);
            }
            if ($sist !== '') {
                if ($siste !== null && self::dekkerTil($siste) > $idag) {
                    if (($h = $hullSvar($hull())) !== null) {
                        return $h;
                    }
                    return $ut('betalt', 'Betalt ' . $kort(substr((string) $siste['created_at'], 0, 10))
                        . ($neste !== '' ? ' · neste ' . $kort($neste) : ''));
                }
                if ($siste !== null && ($f = $fritak(self::dekkerTil($siste))) !== null) {
                    return $f;
                }
                return $ut('venter', 'Ingen mottatt betaling for inneværende måned', true, true);
            }
            // Avtalen er godkjent i Vipps, men ingen krone har flyttet seg.
            // Ikke roedt — det er ikke noe galt — men det skal telles.
            return $ut('venter',
                $neste !== '' ? 'Trekkes ' . $kort($neste) : 'Venter på første trekk',
                false, true);
        }

        // ── Gjor opp selv ───────────────────────────────────────────────
        //
        // Ingen avtale aa spore. Da er den siste registrerte betalingen det
        // eneste vi har — den som huker av i Kassa skriver den inn.
        if ($siste === null) {
            // ── Hvorfor er det ikke betalt? ─────────────────────────────
            //
            // Eieren, 2. september, om et medlem som sto som ubetalt: «denne
            // staar som ubetalt, mens eposten du sendte meg sier dette ...
            // Betaling: gjor opp selv».
            //
            // De to sier ikke det samme. «Gjor opp selv» er MAATEN — hun
            // betaler én periode om gangen i Vipps i stedet for fast trekk.
            // «Ikke betalt» er at pengene ikke er kommet. Begge kan vaere
            // sanne samtidig, og det er nettopp det som er tilfellet her.
            //
            // Innmeldingen oppretter betalingen i Vipps med det samme. Ligger
            // den og henger paa «venter», rakk hun aldri aa fullfore den — og
            // det er noe helt annet enn at ingen har begynt. Merket sa «Ikke
            // betalt ennaa» i begge tilfeller, altsaa det samme som merket
            // over det, og ingenting om hva som faktisk skjedde.
            $tstatus = $trekk === null ? '' : (string) $trekk['status'];
            if ($tstatus === 'opprettet' || $tstatus === 'venter') {
                return $ut('venter', 'Betalingen ble startet i Vipps '
                    . $kort(substr((string) $trekk['created_at'], 0, 10))
                    . ', men aldri fullført', true);
            }
            if ($tstatus === 'feilet' || $tstatus === 'avbrutt') {
                return $ut('venter', 'Betalingen gikk ikke gjennom '
                    . $kort(substr((string) $trekk['created_at'], 0, 10)), true);
            }
            // ── Planen KREVER fast trekk, men det finnes ingen avtale ──
            //
            // Aarsmedlemskapet kan ikke gjores opp én maaned om gangen —
            // «krever_fast_trekk» staar paa planen, og kjopet paa nettsida
            // oppretter en Vipps-avtale kunden maa godkjenne i appen.
            //
            // Men startAvtale() kalles bare fra nettsida og innmeldinga.
            // Melder verkstedet inn noen fra admin, blir hun staaende som
            // aktiv uten avtale — og da staar det ingenting aa trekke paa.
            //
            // Eieren, 5. september: «ved årsavtale en annen vippsløsning enn
            // resten, men kunden får ingen beskjed om å godkjenne så vi får
            // ikke penger».
            //
            // Teksten sa «Ingen betaling registrert» — det samme som for en
            // plan som gjores opp selv. To helt ulike ting med samme ord.
            $plan = self::planUansett((string) ($medlem['medlemskap_type'] ?? ''));
            if ($plan !== null && self::kreverFastTrekk($plan)) {
                // «Mangler GODKJENT avtale», ikke «avtalen er ikke opprettet».
                //
                // Merket naas i to helt ulike tilfeller, og sa det samme om
                // begge: naar verkstedet meldte inn noen fra admin og aldri
                // trykket «Send Vipps-avtale», OG naar avtalen ER opprettet
                // og lenka sendt, men kunden ikke har trykket Godkjenn i
                // appen enda. Vakta over ser bare paa om avtalen er AKTIV,
                // og en avtale som venter paa godkjenning er ikke det — men
                // den finnes.
                //
                // Eieren, 5. september: «Hva mener du med at avtalen ikke er
                // opprettet? Det skal jo ikke gaa an». Han hadde rett: den
                // VAR opprettet for baade Eirin og Lene. Maalt i nettleseren
                // samme dag — rad med «vipps_agreement_id», godkjenningslenke
                // og status «venter» — og merket sa likevel at den ikke
                // fantes.
                //
                // Han valgte denne av fire: ett merke som er sant i begge
                // tilfeller. Den lover ikke lenger noe om HVORFOR avtalen
                // ikke trekker — bare at den ikke gjor det.
                return $ut('forfalt',
                    'Mangler godkjent Vipps-avtale — ' . $plan['navn']
                    . ' kan bare betales med fast trekk',
                    true);
            }
            $start = trim((string) ($medlem['start_dato'] ?? ''));
            return $ut('venter', $start !== ''
                ? 'Ingen betaling registrert · medlem siden ' . $kort($start)
                : 'Ingen betaling registrert', true);
        }
        $betaltDen = substr((string) $siste['created_at'], 0, 10);

        // Proveperioden betales én gang og loper til slutt_dato. Da er det
        // ikke noe mer aa betale, og den skal ikke forfalle hver maaned.
        $plan = self::planUansett((string) ($medlem['medlemskap_type'] ?? ''));
        if ($plan !== null && (int) ($plan['engangs'] ?? 0) === 1) {
            return $ut('betalt', 'Betalt ' . $kort($betaltDen));
        }

        // Gjør opp selv: betalingen dekker sin kalendermåned. En fornyelse
        // før månedsskiftet gjelder neste måned, uten å overføre månedstimer.
        $dekkerTil = self::dekkerTil($siste);
        if ($dekkerTil <= $idag) {
            if (($f = $fritak($dekkerTil)) !== null) {
                return $f;
            }
            return $ut('forfalt', 'Forfalt ' . $kort($dekkerTil)
                . ' · sist betalt ' . $kort($betaltDen), true);
        }
        if (($h = $hullSvar($hull())) !== null) {
            return $h;
        }
        return $ut('betalt', 'Betalt ' . $kort($betaltDen) . ' · neste ' . $kort($dekkerTil));
    }

    /**
     * Siste trekk per avtale — uansett hvordan det gikk.
     *
     * sisteBetalinger() under teller bare det som ER betalt. Til «har hun
     * betalt?» trengs ogsaa det som er BESTILT og ikke gjort opp enda, og det
     * som feilet. «subscriptions.siste_trekk» duger ikke: den settes i det
     * trekket bes om, ikke naar pengene kommer.
     *
     * Uten dette sa admin «BETALT · Trukket 2. september» i de tre-fire
     * dagene mellom bestilling og oppgjor — og fortsatte aa si det om trekket
     * senere feilet. Det er den samme forvekslingen medlemmet Eirin ble
     * utsatt for, bakt inn i verkstedets egen oversikt.
     *
     * @param int[] $abonnementIder
     * @return array<int,array<string,mixed>>
     */
    public static function sisteTrekk(array $abonnementIder): array
    {
        if ($abonnementIder === []) {
            return [];
        }
        $inn = implode(',', array_map('intval', $abonnementIder));
        $ut = [];
        // Forfallet og fristen i betalingsstatus() skal vaere de samme som
        // tilgangen og Vipps bruker: det lagrede innholdet (migrasjon 243)
        // og perioden (migrasjon 235), naar kolonnene finnes.
        $ekstra = (DB::harKolonne('payments', 'gjelder_fra') ? ', p.gjelder_fra' : '')
            . (DB::harKolonne('payments', 'trekk_foresporsel') ? ', p.trekk_foresporsel' : '');
        foreach (DB::alle(
            "SELECT p.subscription_id, p.status, p.created_at, p.belop_ore{$ekstra}
               FROM payments p
               JOIN (SELECT subscription_id, MAX(id) AS siste
                       FROM payments
                      WHERE formal = 'medlemskap'
                        AND annullert_at IS NULL
                        AND subscription_id IN ({$inn})
                   GROUP BY subscription_id) n ON n.siste = p.id"
        ) as $r) {
            $ut[(int) $r['subscription_id']] = $r;
        }
        return $ut;
    }

    /**
     * Siste betalte medlemskapsbetaling, per medlem.
     *
     * Ett oppslag for hele lista. Ett per medlem ville blitt fem hundre
     * sporringer paa medlemsskjermen.
     *
     * @param int[] $medlemIder
     * @return array<int,array<string,mixed>>
     */
    public static function sisteBetalinger(array $medlemIder): array
    {
        if ($medlemIder === []) {
            return [];
        }
        // Ett oppslag for hele lista: alle betalte medlemsbetalinger.
        //
        // «Siste» er betalingen for den SENESTE perioden, ikke den sist
        // registrerte (pengehull 3, 4. oktober 2026): betales en gammel
        // ubetalt maaned i verkstedet etter at en senere er betalt, skal den
        // ikke trekke «betalt til» tilbake. Lik periode: den sist registrerte.
        // «_dekket» er alle maanedene betalingene dekker, til betalingsstatus().
        $ut = [];
        $alle = self::betalteRader($medlemIder);
        // Nyeste avtale per medlem (samme som betalingsstatusFor() leser):
        // naar den ble laget, til skyldberegningen.
        $laget = [];
        if ($alle !== []) {
            $inn = implode(',', array_map('intval', array_keys($alle)));
            foreach (DB::alle(
                "SELECT s.member_id, s.created_at FROM subscriptions s
                   JOIN (SELECT member_id, MAX(id) AS siste FROM subscriptions
                          WHERE member_id IN ({$inn}) GROUP BY member_id) n ON n.siste = s.id"
            ) as $r) {
                $laget[(int) $r['member_id']] = (string) $r['created_at'];
            }
        }
        foreach ($alle as $medlemId => $rader) {
            $best = null;
            $bestNokkel = '';
            foreach ($rader as $r) {
                $periode = trim((string) ($r['gjelder_fra'] ?? '')) ?: substr((string) $r['created_at'], 0, 10);
                $nokkel = $periode . '#' . str_pad((string) (int) $r['id'], 12, '0', STR_PAD_LEFT);
                if ($best === null || $nokkel > $bestNokkel) {
                    $best = $r;
                    $bestNokkel = $nokkel;
                }
            }
            if ($best === null) {
                continue;
            }
            unset($best['engangs']);
            if (!DB::harKolonne('payments', 'gjelder_fra')) {
                unset($best['gjelder_fra']);
            }
            $best['_dekket'] = self::dekkedeMaaneder($rader) + ['avtaleLaget' => $laget[(int) $medlemId] ?? null];
            $ut[(int) $medlemId] = $best;
        }
        return $ut;
    }

    /**
     * Avtalen et medlem har i spill naa, eller null.
     *
     * «venter» er med: en avtale kunden nettopp er sendt til Vipps for aa
     * godkjenne, er den avtalen vi skal sporre Vipps om. Godkjenner hun i
     * appen uten aa komme tilbake til nettsida, er det denne raden Min side
     * finner naar hun trykker «sjekk».
     *
     * Merk: «i spill» er ikke det samme som «loeper». Til pris, plan og
     * «fast trekk» skal bare en avtale som faktisk trekker telle — se
     * loepende().
     */
    public static function avtale(int $medlemId): ?array
    {
        return DB::en(
            "SELECT * FROM subscriptions
              WHERE member_id = :m AND status IN ('venter','aktiv')
              ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId]
        );
    }

    /**
     * Avtalen som faktisk loeper, eller null.
     *
     * ── Et forsok som aldri ble godkjent er ingen avtale ──────────────
     *
     * «venter» settes naar vi sender kunden til Vipps. Godkjenner hun, blir
     * raden «aktiv» — det skjer i oppdaterFraVipps(), etter at vi har spurt
     * Vipps. Snur hun i doera, blir raden staaende paa «venter» for alltid.
     *
     * Den raden ble likevel lest som medlemmets avtale: den bestemte prisen
     * hun sto som skyldig, og hvilket medlemskap hun sto paa. Eirin forsokte
     * fast trekk, godkjente aldri, og satt igjen med en rad som fortsatte aa
     * si «Basis 30» og prisen paa den — ogsaa etter at medlemskapet hennes
     * ble byttet.
     *
     * Eieren, 4. september: «jeg byttet medlemskap for eirin, men det endrer
     * ikke pris», og «hun fikk jo aldri betalt eller har aldri godkjent saa
     * nullstill denne».
     */
    public static function loepende(int $medlemId): ?array
    {
        return DB::en(
            "SELECT * FROM subscriptions
              WHERE member_id = :m AND status = 'aktiv'
              ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId]
        );
    }

    /**
     * Setter medlemmet «oppsagt» — men bare naar ingen avtale loeper lenger.
     *
     * L-1 (pengeflyt-revisjonen, eieren 2. oktober 2026): et forlatt
     * oppgraderings- eller bytteforsoek gikk ut hos Vipps (EXPIRED etter ti
     * minutter), og medlemmet ble satt «oppsagt» selv om det gamle
     * medlemskapet fortsatt loep og ble trukket. Medlemsstatusen skal foelge
     * avtalen som faktisk loeper (loepende()), ikke et forsoek.
     *
     * @return bool om medlemmet ble satt «oppsagt»
     */
    private static function oppsagtOmIngenLoeper(int $medlemId): bool
    {
        if (self::loepende($medlemId) !== null) {
            return false;
        }
        DB::oppdater('members', ['status' => 'oppsagt'], ['id' => $medlemId]);
        return true;
    }

    /**
     * L-3: en medlemsbetaling er refundert i sin helhet.
     *
     * Var den den eneste betalingen paa en engangsavtale (Prøv Lissom, eller
     * første periode av et medlemskap uten fast trekk), stoppes avtalen i dag,
     * og medlemmet settes «oppsagt» om ingenting annet loeper. En fornyelse,
     * et trekk eller en avtale med fast trekk stoppes IKKE av seg selv — det
     * flagges i loggen, og perioden staar ubetalt (harBetaltPeriode() teller
     * ikke refunderte betalinger).
     *
     * @return string stoppet | flagget | uendret
     */
    public static function stoppEtterRefusjon(int $abonnementId, int $betalingId): string
    {
        $a = DB::en('SELECT * FROM subscriptions WHERE id = :i FOR UPDATE', ['i' => $abonnementId]);
        if ($a === null) {
            return 'uendret';
        }
        $type = (string) DB::verdi('SELECT type FROM payments WHERE id = :p', ['p' => $betalingId]);
        // Alle andre betalinger som noen gang gikk gjennom paa avtalen —
        // ogsaa de som er refundert senere. Ellers ble den siste av flere
        // refunderte fornyelser tatt for et engangskjoep (Codex runde 5).
        $andre = (int) DB::verdi(
            "SELECT COUNT(*) FROM payments
              WHERE subscription_id = :s AND id <> :p
                AND status IN ('betalt','delvis_refundert','refundert') AND annullert_at IS NULL",
            ['s' => $abonnementId, 'p' => $betalingId]
        );
        $harAvtale = trim((string) ($a['vipps_agreement_id'] ?? '')) !== '';
        if ($type === 'recurring_charge' || $harAvtale || $andre > 0) {
            revider('medlemskap_refundert_flagg', 'member', (int) $a['member_id'], [
                'avtale' => $abonnementId, 'betaling' => $betalingId, 'plan' => (string) $a['plan'],
                'grunn' => $type === 'recurring_charge' ? 'trekk' : ($harAvtale ? 'fast_trekk' : 'fornyelse'),
            ]);
            return 'flagget';
        }
        $n = DB::kjor(
            "UPDATE subscriptions
                SET status = 'stoppet', sagt_opp_at = UTC_TIMESTAMP(),
                    slutter = CURDATE(), neste_trekk = NULL
              WHERE id = :i AND status IN ('aktiv','venter')",
            ['i' => $abonnementId]
        )->rowCount();
        if ($n !== 1) {
            return 'uendret';
        }
        $ut = self::oppsagtOmIngenLoeper((int) $a['member_id']);
        revider('medlemskap_stoppet_ved_refusjon', 'member', (int) $a['member_id'], [
            'avtale' => $abonnementId, 'betaling' => $betalingId, 'plan' => (string) $a['plan'],
            'oppsagt' => $ut,
        ]);
        return 'stoppet';
    }

    /**
     * Starter en avtale i Vipps og lagrer den som «venter».
     *
     * Den blir ikke aktiv her. Det skjer forst naar kunden har godkjent i
     * Vipps og vi har spurt Vipps om status — vi stoler ikke paa at kunden
     * kom tilbake til riktig side.
     *
     * @return array{url:string,id:int}
     */
    /**
     * Et paagaaende innmeldingsforsoek paa den samme planen, eller null.
     *
     * Eieren, 2. september: e-posten «Nytt medlem» kom to ganger, i det samme
     * minuttet. api/bli-medlem.php hadde ingen vakt mot at det samme forsoeket
     * kom to ganger — vakta under slaar bare til paa en avtale som ER aktiv,
     * og en avtale som staar «venter» stopper ingenting. Andre gang lagde
     * derfor en avtale til i Vipps, en soknadsrad til, og alle varslene om
     * igjen. To avtaler er verre enn to e-poster: det er to trekk.
     *
     * Vinduet er kort med vilje. Fem minutter dekker et dobbeltklikk og en
     * tilbakeknapp fra Vipps. Lenger, og en som virkelig vil proeve paa nytt
     * ville sittet fast med en adresse som kanskje er utloept hos Vipps.
     *
     * Planen er med i oppslaget: bytter man medlemskap i mellomtida, er det
     * et annet forsoek, og da skal det opprettes paa nytt.
     *
     * @return array<string,mixed>|null
     */
    public static function paagaaendeForsok(int $medlemId, string $planNavn, bool $medAvtale): ?array
    {
        if (!DB::harKolonne('subscriptions', 'vipps_url')) {
            return null;
        }
        // ── Betalingsmaaten maa vaere med i oppslaget ────────────────
        //
        // Eieren, 3. september: «Eirin forsokte aa betale med vanlig vipps,
        // men hun fikk kun alternativet fast trekk. selv om hun valgte noe
        // annet i losningen vaart».
        //
        // Vakta sa bare medlem + plan + «venter» + under fem minutter. Den
        // sa ikke HVA slags forsok det var. Da traff en engangsbetaling
        // avtaleforsoket fra minuttet for, og fikk avtalens adresse tilbake:
        //
        //   1. hun trykker med «Fast trekk» — som sto forhaandsvalgt —
        //      og en avtale opprettes i Vipps
        //   2. hun gaar tilbake, velger «Betal i Vipps», trykker igjen
        //   3. startEngangs() finner avtalen fra punkt 1 og sender henne
        //      til den samme fast-trekk-skjermen
        //
        // I fem minutter kunne hun ikke komme til vanlig Vipps uansett hva
        // hun valgte. Skillet staar i «vipps_agreement_id»: en avtale har
        // en, en engangsbetaling har NULL — se startEngangs().
        $avtaleLedd = $medAvtale
            ? 'AND vipps_agreement_id IS NOT NULL'
            : 'AND vipps_agreement_id IS NULL';
        return DB::en(
            "SELECT * FROM subscriptions
              WHERE member_id = :m AND plan = :p AND status = 'venter'
                {$avtaleLedd}
                AND vipps_url IS NOT NULL AND vipps_url <> ''
                AND created_at >= (UTC_TIMESTAMP() - INTERVAL 5 MINUTE)
           ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId, 'p' => $planNavn]
        );
    }

    /**
     * Rydder bort forsoeket hun gikk fra da hun byttet betalingsmaate.
     *
     * Uten dette blir raden fra punkt 1 over liggende som «venter», med en
     * ekte avtale-id hos Vipps. Skjermen hun forlot er fortsatt gyldig:
     * godkjenner hun den senere — en fane som sto aapen, en lenke i
     * historikken — har hun et loepende trekk hun ikke ba om, ved siden av
     * engangsbetalingen hun faktisk valgte.
     *
     * Bare forsoek som staar «venter» roeres. En avtale som er aktiv er et
     * medlemskap, og det sies opp fra Min side — ikke her.
     *
     * Feiler Vipps, skal det ikke stoppe betalingen hun holder paa med. Da
     * er raden merket stoppet hos oss, og loggen sier hva som ikke gikk.
     */
    private static function avlysMotsattForsok(int $medlemId, string $planNavn, bool $medAvtale): void
    {
        $motsatt = self::paagaaendeForsok($medlemId, $planNavn, !$medAvtale);
        if ($motsatt === null) {
            return;
        }
        self::avlysForsok($motsatt);
    }

    /**
     * Stopper ETT forlatt forsoek — baade hos Vipps og hos oss.
     *
     * Dette sto inni avlysMotsattForsok(), som finner forsoeket paa sin egen
     * maate. Naa er finningen og stoppingen skilt, saa startAvtale() kan
     * stoppe et gammelt forsoek av SAMME slag uten en kopi av koden.
     */
    private static function avlysForsok(array $motsatt): void
    {
        $avtaleId = trim((string) ($motsatt['vipps_agreement_id'] ?? ''));
        if ($avtaleId !== '') {
            try {
                Vipps::stoppAvtale($avtaleId);
            } catch (Throwable $e) {
                logg_feil('Fikk ikke stoppet forlatt avtaleforsøk ' . $avtaleId, $e);
            }
        } else {
            // En engangsbetaling. Referansen ligger paa betalingsraden.
            $ref = (string) DB::verdi(
                'SELECT vipps_reference FROM payments
                  WHERE subscription_id = :s ORDER BY id DESC LIMIT 1',
                ['s' => (int) $motsatt['id']]
            );
            if ($ref !== '') {
                try {
                    Vipps::avbryt($ref);
                } catch (Throwable $e) {
                    logg_feil('Fikk ikke avbrutt forlatt betalingsforsøk ' . $ref, $e);
                }
                DB::oppdater('payments', ['status' => 'avbrutt'],
                    ['vipps_reference' => $ref]);
            }
        }

        DB::oppdater('subscriptions', ['status' => 'stoppet'], ['id' => (int) $motsatt['id']]);
    }

    /**
     * Ugodkjente avtaleforsoek som ikke lenger er ferske.
     *
     * paagaaendeForsok() ser bare fem minutter tilbake — den er laget for aa
     * fange to trykk paa rad, ikke for aa rydde. Er forsoeket eldre, lot
     * startAvtale() det bare ligge og laget en avtale til ved siden av.
     *
     * Eieren, 5. september: «Saa jeg kan be de sjekke vipps? Eller maa de
     * melde seg inn paa nytt?» — og han staar med to medlemmer som har en
     * ugodkjent avtale fra dager tilbake. Trykker han «Send Vipps-avtale»
     * paa dem, ville det ligget to gyldige lenker ute samtidig. Godkjenner
     * hun begge, blir det to rader med status «aktiv» — og tilTrekk() henter
     * begge. Da trekkes hun dobbelt.
     *
     * Ingen plan-begrensning her. Et medlem har ett medlemskap; ethvert
     * ugodkjent avtaleforsoek er en lenke som kan gi et trekk hun ikke ba om.
     *
     * @return list<array<string,mixed>>
     */
    private static function gamleAvtaleforsok(int $medlemId): array
    {
        return DB::alle(
            "SELECT * FROM subscriptions
              WHERE member_id = :m
                AND status = 'venter'
                AND vipps_agreement_id IS NOT NULL
                AND created_at < (UTC_TIMESTAMP() - INTERVAL 5 MINUTE)",
            ['m' => $medlemId]
        );
    }

    /** Lagrer adressen forsoeket godkjennes paa, om kolonna finnes. */
    private static function husk(int $abonnementId, string $url): void
    {
        if ($url !== '' && DB::harKolonne('subscriptions', 'vipps_url')) {
            DB::oppdater('subscriptions', ['vipps_url' => mb_substr($url, 0, 500)], ['id' => $abonnementId]);
        }
    }

    public static function startAvtale(array $medlem, string $planNavn): array
    {
        $plan = self::plan($planNavn);
        if ($plan === null) {
            throw new RuntimeException('Ukjent medlemskap.');
        }
        self::sperrFryst($medlem, true);   // ny periode: alltid sperret under frys

        // Har medlemmet en avtale fra for, skal den ikke bli staaende ved
        // siden av den nye. Da ville de blitt trukket to ganger.
        self::sperrProveIgjen((int) $medlem['id'], $plan);
        $fra = self::avtale((int) $medlem['id']);
        if (self::hindrerNytt($fra, $planNavn)) {
            throw new RuntimeException('Du har alt et medlemskap. Si det opp først, eller bytt fra Min side.');
        }

        // Det samme forsoeket to ganger skal gi den samme avtalen, ikke to.
        $igjen = self::paagaaendeForsok((int) $medlem['id'], $planNavn, true);
        if ($igjen !== null) {
            return ['url' => (string) $igjen['vipps_url'], 'id' => (int) $igjen['id'],
                    'gjentakelse' => true];
        }
        // Byttet hun fra «Betal i Vipps» til fast trekk, skal den forlatte
        // betalingen ikke bli staaende og kunne gjennomfores i tillegg.
        self::avlysMotsattForsok((int) $medlem['id'], $planNavn, true);

        // Og et gammelt avtaleforsoek hun aldri godkjente stoppes ogsaa. Uten
        // dette laa det to gyldige lenker ute samtidig, og godkjente hun
        // begge, ble hun trukket to ganger. Se gamleAvtaleforsok().
        foreach (self::gamleAvtaleforsok((int) $medlem['id']) as $gammelt) {
            self::avlysForsok($gammelt);
        }

        $vipps = Vipps::opprettAvtale(
            $planNavn,
            (int) $plan['pris_ore'],
            'Medlemskap hos Lissom Keramikk — ' . $planNavn,
            // Medlemsnummeret staar i returadressen. Kommer hun tilbake fra
            // Vipps uten aa vaere innlogget — en annen nettleser, en app som
            // aapner sin egen fane — visste retursida ellers ikke hvem hun
            // var, og gjorde ingenting. Da maatte hun vente paa trekkrunden.
            Config::nettsted() . '/api/vipps-avtale-retur.php?m=' . (int) $medlem['id'],
            $medlem['telefon'] ?? null,
            // Intervallet er planens, ikke alltid «maaned». Se opprettAvtale().
            (string) ($plan['intervall'] ?? 'maaned')
        );

        if ($vipps['avtaleId'] === '' || $vipps['url'] === '') {
            throw new RuntimeException('Vipps ga ikke noen avtale tilbake.');
        }

        $binding = (int) ($plan['engangs'] ?? 0) === 1 ? 0 : (int) $plan['binding_mnd'];

        $id = DB::settInn('subscriptions', [
            'member_id'          => (int) $medlem['id'],
            'plan'               => $planNavn,
            'pris_ore'           => (int) $plan['pris_ore'],
            'vipps_agreement_id' => $vipps['avtaleId'],
            'status'             => 'venter',
            'binding_til'        => $binding > 0
                ? (new DateTimeImmutable('now'))->modify('+' . $binding . ' months')->format('Y-m-d')
                : null,
        ]);

        self::husk((int) $id, (string) $vipps['url']);
        return ['url' => $vipps['url'], 'id' => $id, 'gjentakelse' => false];
    }

    /**
     * Medlemskap uten fast trekk: én betaling i Vipps for forste periode.
     *
     * Eieren: «de skal ha fast trekk eller haandtere selv». Velger medlemmet
     * aa haandtere selv, skal det likevel betales med det samme — det er bare
     * de senere periodene verkstedet krever inn for haand.
     *
     * Raden i «subscriptions» faar ingen avtale-id og ingen trekkdato. Da
     * rorer oppdaterFraVipps() den ikke, og tilTrekk() henter den aldri — det
     * kommer altsaa ingen automatiske trekk. Medlemskapet blir aktivt naar
     * betalingen er i havn, i Booking::markerBetalt().
     *
     * @return array{url:string,id:int}
     */
    public static function startEngangs(array $medlem, string $planNavn): array
    {
        $plan = self::plan($planNavn);
        if ($plan === null) {
            throw new RuntimeException('Ukjent medlemskap.');
        }
        self::sperrFryst($medlem, true);   // ny periode: alltid sperret under frys
        self::sperrProveIgjen((int) $medlem['id'], $plan);
        $fra = self::avtale((int) $medlem['id']);
        if (self::hindrerNytt($fra, $planNavn)) {
            throw new RuntimeException('Du har alt et medlemskap. Si det opp først, eller bytt fra Min side.');
        }

        // Samme vakt som i startAvtale(): det samme forsoeket to ganger skal
        // gi den samme betalingen, ikke to.
        $igjen = self::paagaaendeForsok((int) $medlem['id'], $planNavn, false);
        if ($igjen !== null) {
            return ['url' => (string) $igjen['vipps_url'], 'id' => (int) $igjen['id'],
                    'gjentakelse' => true];
        }
        // Og avtaleforsoeket hun gikk fra da hun valgte vanlig Vipps.
        self::avlysMotsattForsok((int) $medlem['id'], $planNavn, false);

        $binding = (int) ($plan['engangs'] ?? 0) === 1 ? 0 : (int) $plan['binding_mnd'];
        $id = DB::settInn('subscriptions', [
            'member_id'          => (int) $medlem['id'],
            'plan'               => $planNavn,
            'pris_ore'           => (int) $plan['pris_ore'],
            // NULL, ikke tom streng.
            //
            // Kolonna har UNIQUE KEY uq_subs_agreement. To rader med '' er
            // to like verdier, og den andre avvises:
            //
            //   SQLSTATE[23000]: Integrity constraint violation: 1062
            //   Duplicate entry '' for key 'uq_subs_agreement'
            //
            // NULL teller ikke som en verdi i en unik noekkel, saa flere
            // rader kan staa uten avtale — som er nettopp det som gjelder
            // her: en engangsbetaling har ingen avtale i Vipps.
            //
            // Feilen slo inn fra og med den ANDRE gangen noen betalte paa
            // denne maaten. Den forste raden gikk gjennom og ble staaende;
            // alle etter den traff den. Eieren, 2. september, da han provde
            // aa betale for medlemskapet sitt.
            //
            // Alle stedene som leser kolonna taaler NULL fra for: de gjor
            // enten «if ($avtale['vipps_agreement_id'])» eller
            // «trim((string) ($p['vipps_agreement_id'] ?? ''))».
            //
            // Migrasjon 125 setter den ene raden som alt staar med '' til
            // NULL, saa den slutter aa sperre.
            'vipps_agreement_id' => null,
            'status'             => 'venter',
            'binding_til'        => $binding > 0
                ? (new DateTimeImmutable('now'))->modify('+' . $binding . ' months')->format('Y-m-d')
                : null,
        ]);

        $referanse = Vipps::nyReferanse('MED');
        $forsteRad = [
            'vipps_reference' => $referanse,
            'type'            => 'epayment',
            'formal'          => 'medlemskap',
            'member_id'       => (int) $medlem['id'],
            'subscription_id' => $id,
            'belop_ore'       => (int) $plan['pris_ore'],
            'status'          => 'opprettet',
            // Denne manglet, og «payments.idempotency_key» er NOT NULL uten
            // standardverdi. Innsettingen kastet derfor hver eneste gang:
            // hele veien for medlemskap som betales én gang — «Prov Lissom»,
            // og alle som valgte «ordner selv» — endte i en databasefeil for
            // Vipps i det hele tatt ble kontaktet. Alle de andre stedene som
            // oppretter en betaling setter noekkelen; dette var det ene som
            // ikke gjorde det.
            'idempotency_key' => Vipps::uuid(),
        ];
        // Nytt medlemskap kjoept etter den 20.: betalingen gjelder neste
        // maaned (eieren, 2. oktober 2026). Se gjelderFraNytt().
        $gjelderFra = self::gjelderFraNytt((int) $medlem['id'], $planNavn);
        if ($gjelderFra !== null && DB::harKolonne('payments', 'gjelder_fra')) {
            $forsteRad['gjelder_fra'] = $gjelderFra;
        }
        $betalingId = DB::settInn('payments', $forsteRad);

        try {
            $betaling = Vipps::opprettBetaling(
                $referanse,
                (int) $plan['pris_ore'],
                Vipps::beskrivelse(
                    'Medlemskap hos Lissom — ' . $planNavn,
                    (string) ($medlem['navn'] ?? '')
                ),
                Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
                $medlem['telefon'] ?? null
            );
        } catch (Throwable $e) {
            // Betalingen kom aldri i gang. Da skal det ikke ligge igjen et
            // halvt medlemskap som ser ut som om noen venter paa aa betale.
            DB::oppdater('payments', ['status' => 'feilet'], ['id' => $betalingId]);
            DB::kjor('DELETE FROM subscriptions WHERE id = :i', ['i' => $id]);
            logg_feil('Fikk ikke startet medlemsbetaling for medlem ' . $medlem['id'], $e);
            throw new RuntimeException('Fikk ikke startet betalingen. Prøv igjen om litt.');
        }

        DB::oppdater('payments', ['status' => 'venter'], ['id' => $betalingId]);
        self::husk((int) $id, (string) $betaling['url']);
        return ['url' => $betaling['url'], 'id' => $id, 'gjentakelse' => false];
    }

    /**
     * Medlemskap som gjores opp i verkstedet.
     *
     * Eieren, 19. september 2026: «det maa gaa an aa bestille uten aa betale
     * med vipps, samme vilkaar, men at de betaler ved oppmoete, kontant eller
     * vipps» — og for medlemskap skal valget staa AV til noen slaar det paa.
     *
     * Samme rad som «jeg ordner det selv», med ett unntak: den er aktiv med
     * det samme, og ingen betaling er startet. Bindinga settes som ellers —
     * det er de samme vilkaarene, ikke et mildere sett.
     *
     * «neste_trekk» staar tom. Uten den henter tilTrekk() den aldri, og cron
     * kan ikke be Vipps om et trekk paa en avtale det ikke finnes fullmakt
     * for.
     *
     * Pengene: medlemmet staar som ubetalt til noen huker av i Kassa — se
     * betalingsstatus(), som uten avtale og uten betaling svarer «ingen
     * betaling registrert». Det er nettopp der verkstedet skal se hen.
     *
     * @param array<string,mixed> $medlem
     * @return array{id:int}
     */
    public static function startIVerkstedet(array $medlem, string $planNavn): array
    {
        $plan = self::plan($planNavn);
        if ($plan === null) {
            throw new RuntimeException('Fant ikke medlemskapet.');
        }
        self::sperrFryst($medlem, true);   // ny periode: alltid sperret under frys
        self::sperrProveIgjen((int) $medlem['id'], $plan);
        if (self::kreverFastTrekk($plan)) {
            throw new RuntimeException('Dette medlemskapet krever fast trekk i Vipps.');
        }
        if (!Oppmote::medlemskap()) {
            throw new RuntimeException('Dette medlemskapet må betales når du melder deg inn.');
        }

        $engangs = (int) ($plan['engangs'] ?? 0) === 1;
        $binding = (int) ($plan['engangs'] ?? 0) === 1 ? 0 : (int) $plan['binding_mnd'];
        $medlemId = (int) $medlem['id'];

        return DB::iTransaksjon(static function () use ($plan, $planNavn, $medlemId, $engangs, $binding): array {
            $id = DB::settInn('subscriptions', [
                'member_id'          => $medlemId,
                'plan'               => $planNavn,
                'pris_ore'           => (int) $plan['pris_ore'],
                // Ingen fullmakt i Vipps. NULL og ikke tom streng: kolonnen
                // er unik, og to tomme strenger er to like verdier.
                'vipps_agreement_id' => null,
                'status'             => 'aktiv',
                'neste_trekk'        => null,
                'binding_til'        => $binding > 0
                    ? (new DateTimeImmutable('now'))->modify('+' . $binding . ' months')->format('Y-m-d')
                    : null,
            ]);

            // Medlemskapet gjelder fra i dag. Har hen vaert medlem for,
            // roeres ikke startdatoen — «medlem siden mai» skal ikke bli
            // «siden i dag» fordi hen meldte seg inn paa nytt.
            $fra = DB::en('SELECT start_dato FROM members WHERE id = :m', ['m' => $medlemId]) ?? [];
            $felter = [
                'status'          => $engangs ? 'prove' : 'aktiv',
                'medlemskap_type' => $planNavn,
                'start_dato'      => ($fra['start_dato'] ?? null) ?: gmdate('Y-m-d'),
            ];
            if ($engangs) {
                $felter['slutt_dato'] = self::proveSlutt();
            }
            DB::oppdater('members', $felter, ['id' => $medlemId]);
            self::erstattProve($medlemId, (int) $id, $planNavn);

            return ['id' => (int) $id];
        });
    }

    /**
     * Slaar paa et medlemskap som er betalt med én betaling.
     *
     * Kalles fra Booking::markerBetalt(). Ingen trekkdato settes — det er
     * nettopp poenget med denne maaten: verkstedet krever inn de neste
     * periodene selv.
     */
    public static function betaltEngangs(int $abonnementId): void
    {
        $a = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $abonnementId]);
        if ($a === null || $a['status'] === 'aktiv') {
            return;
        }
        DB::oppdater('subscriptions', ['status' => 'aktiv', 'neste_trekk' => null], ['id' => $abonnementId]);

        // ── En proeveperiode maa ha en slutt ────────────────────────────
        //
        // «Prøv Lissom» er engangs: den betales én gang og varer en maaned.
        // Sluttdatoen ble bare satt naar verkstedet meldte noen inn fra
        // admin — se «slutt_dato» i api/admin/medlemmer.php, der det staar
        // svart paa hvitt at «uten sluttdato sto den som aktiv for alltid, og
        // Prøv Lissom ble et gratis medlemskap».
        //
        // Kjopte man den paa nettsida, gikk veien hit i stedet, og her ble
        // den aldri satt. Kunden betalte 990 kroner én gang og beholdt
        // verkstedet for alltid.
        //
        // Eieren, 5. september: «jeg får jo ikke inn pengene mine».
        //
        // Samme regel som i admin: siste dag i maaneden — se proveSlutt().
        $plan    = self::planUansett((string) $a['plan']);
        $engangs = $plan !== null && (int) ($plan['engangs'] ?? 0) === 1;
        $fra     = DB::en(
            'SELECT start_dato, slutt_dato FROM members WHERE id = :m',
            ['m' => (int) $a['member_id']]
        ) ?? [];

        $felter = [
            'status'          => 'aktiv',
            'medlemskap_type' => (string) $a['plan'],
            'start_dato'      => ($fra['start_dato'] ?? null) ?: gmdate('Y-m-d'),
        ];
        if ($engangs) {
            $felter['slutt_dato'] = self::proveSlutt();
        }
        DB::oppdater('members', $felter, ['id' => (int) $a['member_id']]);
        self::erstattProve((int) $a['member_id'], $abonnementId, (string) $a['plan']);

        // ── Kvitteringen ────────────────────────────────────────────────
        //
        // Her sto det ingenting. Betalingen gikk gjennom, medlemskapet ble
        // slaatt paa, og kunden fikk aldri et ord fra oss om at det var i
        // orden — bare Vipps' egen kvittering.
        //
        // Velkomstbrevet gaar naar betalingen STARTES, og sier hva som
        // gjenstaar. Dette er det andre halve: pengene er inne.
        //
        // Eieren, 5. september: «Hvilken info får de som kjøper et av de
        // andre medlemskapene».
        $m = DB::en(
            'SELECT navn, epost FROM members WHERE id = :i',
            ['i' => (int) $a['member_id']]
        );
        if ($m === null || trim((string) ($m['epost'] ?? '')) === '') {
            return;
        }
        // En engangsplan varer én maaned. Da skal brevet si naar den er ute,
        // ikke la medlemmet tro at den loeper videre av seg selv. Datoen ble
        // satt rett over.
        $slutt = $engangs
            ? DB::verdi('SELECT slutt_dato FROM members WHERE id = :i', ['i' => (int) $a['member_id']])
            : null;

        Varsel::mal('medlemskap_betalt', ['epost' => (string) $m['epost']], [
            'navn'   => (string) ($m['navn'] ?? ''),
            'type'   => (string) $a['plan'],
            'belop'  => Booking::kroner((int) $a['pris_ore']),
            'gyldig' => $slutt
                ? 'Gjelder ut ' . Booking::norskDatoKort((string) $slutt . ' 12:00:00') . '.'
                : 'Vi tar kontakt før neste periode.',
        ], 'subscription', $abonnementId);
    }

    /**
     * Spor Vipps om status og setter avtalen deretter.
     *
     * Kalles bade naar kunden kommer tilbake og fra cron. Den er trygg aa
     * kjore flere ganger.
     */
    public static function oppdaterFraVipps(array $avtale, ?string $naa = null): string
    {
        // $naa (UTC, 'Y-m-d H:i:s') er bare for testene; ellers klokka.
        $id = (string) $avtale['vipps_agreement_id'];
        if ($id === '') {
            return (string) $avtale['status'];
        }

        try {
            $svar = Vipps::hentAvtale($id);
        } catch (Throwable $e) {
            logg_feil('Fikk ikke hentet avtale ' . $id, $e);
            return (string) $avtale['status'];
        }

        $vippsStatus = strtoupper((string) ($svar['status'] ?? ''));

        $ny = match ($vippsStatus) {
            'ACTIVE'  => 'aktiv',
            'STOPPED' => 'stoppet',
            'EXPIRED' => 'utlopt',
            'PENDING' => 'venter',
            default   => 'avslaatt',
        };

        $endring = ['status' => $ny];

        // ── K1: statusen kommer fra Vipps ───────────────────────────────
        //
        // Her sto en sperre: laa det en soknad til behandling, ble medlemmet
        // staaende inaktivt selv om Vipps hadde godkjent avtalen. Meningen var
        // at verkstedet skulle kunne si nei uten at noen var trukket.
        //
        // Prisen var at et medlem kunne godkjenne i appen og likevel staa som
        // ikke-aktiv i admin, uten at noe sa hvorfor. Eieren, 7. september
        // 2026: «Jeg godtok avtalen i vipps og kom inn paa min side, men i
        // admin saa staar jeg ikke som aktiv, det maa jeg gjore».
        //
        // Sperra er borte. Sier Vipps ACTIVE, er medlemskapet aktivt.
        // Verkstedet kan fortsatt si nei — da sies avtalen opp, og da stoppes
        // den ogsaa hos Vipps. Se docs/AVTALETREKK.md, K1.
        // ── Foerste trekk er alt gjort naar avtalen blir aktiv ──────────
        //
        // Vipps tar det i det kunden sier ja — se «initialCharge» i
        // Vipps::opprettAvtale(). Her sto «neste_trekk = i dag», fra den gang
        // vi belastet foerste periode selv. Blir den staaende, plukker
        // trekkrunden avtalen opp samme natt og ber om ET TREKK TIL.
        //
        // Neste trekk er derfor en maaned fram, regnet av samme regel som
        // resten: dagen huskes, saa den 31. blir 28. i februar og 31. igjen
        // i mars.
        //
        // Nytt medlemskap kjoept etter den 20. (eieren, 2. oktober 2026):
        // foerste trekk gjelder neste maaned, saa neste trekk er maaneden
        // etter den — ellers ble den samme maaneden betalt to ganger.
        // Kjoepet er da avtalen ble opprettet, ikke da den ble godkjent.
        $forsteFra = null;
        if ($ny === 'aktiv' && $avtale['neste_trekk'] === null) {
            $forsteFra = self::gjelderFraNytt(
                (int) $avtale['member_id'],
                (string) $avtale['plan'],
                isset($avtale['created_at']) ? (string) $avtale['created_at'] : null,
                $naa
            );
            $idagDato = (new DateTimeImmutable($naa ?? 'now'))->format('Y-m-d');
            // Trekkdag (eieren, 2. oktober 2026): alle faste trekk den 1. En
            // ny avtale faar trekkdag 1, og neste trekk er den 1. i maaneden
            // etter perioden foerste trekk dekker.
            $endring['neste_trekk'] = self::nesteTrekkdato($forsteFra ?? $idagDato, self::TREKK_DAG);
            if (DB::harKolonne('subscriptions', 'trekk_dag')) {
                $endring['trekk_dag'] = self::TREKK_DAG;
            }
        }
        if ($ny !== 'aktiv') {
            $endring['neste_trekk'] = null;
        }

        DB::oppdater('subscriptions', $endring, ['id' => (int) $avtale['id']]);

        // ── Foerste trekk foeres hos oss ────────────────────────────────
        //
        // Vipps lager det selv naar kunden godkjenner — vi ber aldri om det,
        // og kjenner derfor ingen charge-id. Uten dette ville pengene ligget
        // hos Vipps uten aa staa i Kassa, i regnskapet eller paa medlemmet.
        //
        // Kjores runden to ganger, skal raden bare bli til én: noekkelen er
        // trekkets egen id hos Vipps.
        if ($ny === 'aktiv' && $avtale['neste_trekk'] === null) {
            self::foerForsteTrekk($avtale, $forsteFra);
        }

        // Medlemsstatusen folger avtalen. Uten dette ville noen betalt uten aa
        // faa tilgang, eller hatt tilgang uten aa betale.
        if ($ny === 'aktiv' && (string) $avtale['status'] !== 'aktiv') {
            self::erstattProve((int) $avtale['member_id'], (int) $avtale['id'], (string) $avtale['plan']);
        }
        if ($ny === 'aktiv') {
            DB::oppdater('members', [
                'status'          => 'aktiv',
                'medlemskap_type' => $avtale['plan'],
                'start_dato'      => DB::verdi('SELECT start_dato FROM members WHERE id = :m', ['m' => $avtale['member_id']])
                                      ?: gmdate('Y-m-d'),
            ], ['id' => (int) $avtale['member_id']]);
        } elseif (in_array($ny, ['stoppet', 'utlopt'], true) && (string) $avtale['status'] === 'aktiv') {
            // L-1: bare avtalen som loep, kan ta medlemskapet med seg — og
            // bare naar ingen annen loeper. Et forsoek som aldri ble godkjent
            // (EXPIRED, eller avslaatt i appen) endrer bare sin egen rad over.
            self::oppsagtOmIngenLoeper((int) $avtale['member_id']);
        }

        // Vervepremien. Avtalen er aktiv og foerste trekk tatt — kom vennen
        // fra en vervelenke, faar den som vervet timene sine naa. Trygg aa
        // kalle hver gang: premien gis én gang per venn. Feiler den, skal
        // ikke medlemskapet til vennen stoppe av det. Bare naar avtalen GAAR
        // over til aktiv — ikke hver gang en aktiv avtale sjekkes.
        if ($ny === 'aktiv' && (string) $avtale['status'] !== 'aktiv') {
            try {
                Verving::premier((int) $avtale['member_id'], (int) $avtale['id'], (string) $avtale['plan']);
            } catch (Throwable $e) {
                logg_feil('Vervepremien feilet for avtale ' . $avtale['id'], $e);
            }
        }

        return $ny;
    }

    /**
     * Slipper forste trekk paa en avtale som har ventet paa godkjenning.
     *
     * Soknaden oppretter avtalen med det samme, men holder trekket igjen til
     * verkstedet har sagt ja (se oppdaterFraVipps). Denne kalles av
     * godkjenningen: den sporr Vipps om avtalen faktisk er godkjent, og setter
     * forste trekk til i dag hvis den er det.
     *
     * @return array{status:string,avtale:?array<string,mixed>}
     */
    /**
     * Foerer trekket Vipps tok da kunden godkjente avtalen.
     *
     * «initialCharge» gjor at Vipps belaster med det samme — se
     * Vipps::opprettAvtale(). Trekket er deres, ikke vaart: vi har ingen
     * referanse og ingen rad. Den hentes her, én gang, naar avtalen gaar fra
     * «venter» til «aktiv».
     *
     * Noekkelen er charge-id-en fra Vipps. Kjorer dette to ganger — to faner,
     * to runder — blir det likevel én rad.
     */
    private static function foerForsteTrekk(array $avtale, ?string $gjelderFra = null, ?string $betaltTid = null): bool
    {
        $avtaleId = (string) ($avtale['vipps_agreement_id'] ?? '');
        if ($avtaleId === '') {
            return false;
        }

        // L-6: feiler oppslaget, proeves det igjen i trekkrunden
        // (foerManglendeForsteTrekk). Foer ble det bare logget, og siden
        // neste_trekk alt var satt, ble foerste betaling aldri foert.
        try {
            $trekk = Vipps::trekkPaaAvtale($avtaleId, true);
        } catch (Throwable $e) {
            logg_feil('Fikk ikke hentet foerste trekk paa avtale ' . $avtaleId
                . '. Proeves igjen i neste trekkrunde.', $e);
            return false;
        }
        if ($trekk === []) {
            logg_feil('Fant ingen trekk paa avtale ' . $avtaleId
                . ' da den ble aktiv. Foerste betaling staar ikke fort hos oss.');
            return false;
        }

        // Det eldste er det Vipps tok ved godkjenning.
        usort($trekk, static fn(array $a, array $b): int
            => strcmp((string) ($a['due'] ?? ''), (string) ($b['due'] ?? '')));
        $forste = $trekk[0];

        $trekkId = trim((string) ($forste['id'] ?? ''));
        if ($trekkId === '') {
            logg_feil('Trekket paa avtale ' . $avtaleId . ' kom uten id.');
            return false;
        }

        $nokkel = substr('init:' . $trekkId, 0, 64);
        // Staar trekket alt hos oss — med init-noekkelen, eller med samme
        // trekk-id paa en annen rad paa avtalen — foeres det ikke to ganger.
        if (DB::en(
            'SELECT id FROM payments
              WHERE idempotency_key = :k
                 OR (subscription_id = :s AND vipps_psp_ref = :t)
              LIMIT 1',
            ['k' => $nokkel, 's' => (int) $avtale['id'], 't' => $trekkId]
        ) !== null) {
            // Avklart: trekket staar alt hos oss. Ikke slaa det opp igjen.
            self::merkForsteTrekkSjekket((int) $avtale['id']);
            return false;
        }

        // Statusen er Vipps sin. «CHARGED» betyr at pengene er inne;
        // «PENDING» og «DUE» at de kommer; alt annet er ikke betalt.
        $status = strtoupper((string) ($forste['status'] ?? ''));
        $vaar = match ($status) {
            'CHARGED'                  => 'betalt',
            'RESERVED', 'DUE', 'PENDING', 'PROCESSING' => 'venter',
            'FAILED', 'CANCELLED'      => 'feilet',
            default                    => 'venter',
        };

        $rad = [
            'vipps_reference' => Vipps::nyReferanse('MED'),
            'vipps_psp_ref'   => $trekkId,
            'type'            => 'recurring_charge',
            'formal'          => 'medlemskap',
            'member_id'       => (int) $avtale['member_id'],
            'subscription_id' => (int) $avtale['id'],
            'belop_ore'       => (int) ($forste['amount'] ?? $avtale['pris_ore']),
            'status'          => $vaar,
            'idempotency_key' => $nokkel,
        ];
        // Nytt medlemskap kjoept etter den 20.: trekket gjelder neste maaned.
        if ($gjelderFra !== null && DB::harKolonne('payments', 'gjelder_fra')) {
            $rad['gjelder_fra'] = $gjelderFra;
        }
        // Etterfoert (L-6): betalingstiden er godkjenningen, ikke i dag.
        if ($betaltTid !== null && trim($betaltTid) !== '') {
            $rad['created_at'] = $betaltTid;
        }
        DB::settInn('payments', $rad);
        self::merkForsteTrekkSjekket((int) $avtale['id']);
        return true;
    }

    /**
     * L-6: foerste trekk er avklart (foert, eller staar alt paa en annen
     * rad). Da tas avtalen ut av utenForsteTrekk(), saa den ikke slaas opp
     * hver natt. Kolonnen kommer med migrasjon 247; foer den er kjoert, gjoer
     * dette ingenting.
     */
    private static function merkForsteTrekkSjekket(int $avtaleId): void
    {
        if (DB::harKolonne('subscriptions', 'forste_trekk_sjekket')) {
            DB::oppdater('subscriptions', ['forste_trekk_sjekket' => gmdate('Y-m-d H:i:s')], ['id' => $avtaleId]);
        }
    }

    /**
     * Foerste trekk ved godkjenning («initialCharge») kom med bc3162e,
     * 8. september 2026 kl. 01.03 norsk tid. Avtaler fra foer det fikk aldri
     * et init-trekk, og skal ikke slaas opp.
     */
    public const FORSTE_TREKK_VED_GODKJENNING_FRA = '2026-09-07 23:00:00';

    /**
     * L-6: aktive avtaler der foerste trekk (det Vipps tok ved godkjenning)
     * ikke staar hos oss. Skjer naar oppslaget feilet i oppdaterFraVipps():
     * da var neste_trekk alt satt, og ingen proevde igjen.
     *
     * Bare avtaler fra de siste 90 dagene, laget etter at foerste trekk ble
     * tatt ved godkjenning, med avtale-id, uten init-rad, og som ikke er
     * merket avklart (forste_trekk_sjekket, migrasjon 247). Eldste foerst og
     * uten grense: de avklarte faller ut, saa lista er kort.
     *
     * @return list<array<string,mixed>>
     */
    public static function utenForsteTrekk(): array
    {
        $ikkeSjekket = DB::harKolonne('subscriptions', 'forste_trekk_sjekket')
            ? 'AND s.forste_trekk_sjekket IS NULL' : '';
        return DB::alle(
            "SELECT s.* FROM subscriptions s
              WHERE s.status = 'aktiv'
                AND COALESCE(s.vipps_agreement_id, '') <> ''
                AND s.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)
                AND s.created_at >= :innfort
                {$ikkeSjekket}
                -- Foerste trekk er foert med init-noekkelen (foerForsteTrekk).
                -- En senere, ordinaer trekkrad betyr ikke at det er foert.
                AND NOT EXISTS (SELECT 1 FROM payments p
                                 WHERE p.subscription_id = s.id
                                   AND p.type = 'recurring_charge'
                                   AND p.idempotency_key LIKE 'init:%')
                -- Et trekk uten charge-id kan vaere det samme trekket; da
                -- venter vi til det er avklart (L-5), heller enn aa foere to.
                AND NOT EXISTS (SELECT 1 FROM payments p
                                 WHERE p.subscription_id = s.id
                                   AND p.type = 'recurring_charge'
                                   AND p.vipps_psp_ref IS NULL)
           ORDER BY s.id",
            ['innfort' => self::FORSTE_TREKK_VED_GODKJENNING_FRA]
        );
    }

    /**
     * Foerer foerste trekk paa avtalene fra utenForsteTrekk(). Trygg aa
     * kjoere flere ganger: noekkelen er trekkets id hos Vipps.
     *
     * @return int hvor mange som ble foert naa
     */
    public static function foerManglendeForsteTrekk(): int
    {
        $foert = 0;
        foreach (self::utenForsteTrekk() as $a) {
            // Betalingstiden er da avtalen ble godkjent (innen 10 minutter
            // etter opprettelsen), ikke dagen det etterfoeres: timerTellesFra()
            // og erForskuttert() leser created_at.
            if (self::foerForsteTrekk($a, self::forstePeriode($a), $a['created_at'] ?? null)) {
                $foert++;
            }
            usleep(200_000);
        }
        return $foert;
    }

    /**
     * gjelder_fra for et etterfoert foerste trekk: perioden trekket ble tatt
     * for, ikke dagen det etterfoeres (Codex 02.10).
     *
     * Regnes av det som ikke endrer seg: da avtalen ble opprettet. Vipps
     * lar en avtale staa «PENDING» i høyst 10 minutter (developer.
     * vippsmobilepay.com, Recurring API-guiden), og foerste trekk tas i det
     * kunden godkjenner — altsaa innen 10 minutter etter opprettelsen.
     * neste_trekk og senere rader kan flyttes av pauser og kan ikke brukes.
     *
     * Kjoept etter den 20.: samme regel som ved aktiveringen (neste maaned).
     * Ellers kjoepsdagen; dekkerTil() gir da kjoepsmaaneden, og Prøv Lissom
     * (engangs) teller fra dagen, som foer.
     */
    private static function forstePeriode(array $avtale): ?string
    {
        $kjopt = trim((string) ($avtale['created_at'] ?? ''));
        if ($kjopt === '') {
            return null;
        }
        return self::gjelderFraNytt((int) $avtale['member_id'], (string) $avtale['plan'], $kjopt, $kjopt)
            ?? (new DateTimeImmutable($kjopt, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d');
    }

    public static function slippForsteTrekk(int $medlemId): array
    {
        $a = DB::en(
            'SELECT * FROM subscriptions WHERE member_id = :m ORDER BY id DESC LIMIT 1',
            ['m' => $medlemId]
        );
        if ($a === null) {
            return ['status' => 'ingen', 'avtale' => null];
        }

        // Fasiten er Vipps, ikke raden vaar. Kunden kan ha godkjent uten aa
        // komme tilbake til nettsiden.
        $status = self::oppdaterFraVipps($a);
        if ($status !== 'aktiv') {
            return ['status' => $status, 'avtale' => $a];
        }

        // Datoen settes av oppdaterFraVipps() over, en maaned fram — foerste
        // trekk er alt gjort av Vipps. Sto den til «i dag» her, ville runden
        // bedt om et trekk til samme natt.
        $a = DB::en('SELECT * FROM subscriptions WHERE id = :id', ['id' => (int) $a['id']]);
        return ['status' => 'aktiv', 'avtale' => $a];
    }

    /**
     * Er engangsbetalingen for medlemskapet i havn?
     *
     * Eieren, 1. september: «Hun fikk medlemskap selv om betalingen ikke gikk
     * inn hva faen».
     *
     * Han hadde rett. Godkjenningen i admin sjekket bare avtalen i Vipps naar
     * sokeren hadde valgt fast trekk. Valgte hen «ordner selv», ble ingenting
     * sjekket i det hele tatt: ett trykk paa Godkjenn ga full tilgang, og
     * svaret paa skjermen sa «gjor opp selv for hver periode» — som om alt
     * var i orden. Ingen sto igjen med en beskjed om at foerste betaling
     * aldri kom.
     *
     * Denne er motstykket til slippForsteTrekk(), og folger samme regel:
     * fasiten er Vipps, ikke raden vaar. Kunden kan ha betalt uten aa komme
     * tilbake til nettsiden, og da skal ikke godkjenningen stoppes.
     *
     * Svarene:
     *   aktiv    betalingen er i havn
     *   ingen    det finnes ingen betaling aa se paa — en gammel soknad,
     *            fra for innmeldingen krevde noe. Da maa den kreves inn
     *            paa annen maate, og det skal staa i svaret.
     *   ellers   status paa betalingen slik den staar naa
     *
     * @return array{status:string,avtale:array<string,mixed>|null}
     */
    public static function engangsBetalt(int $medlemId): array
    {
        $a = DB::en(
            'SELECT * FROM subscriptions WHERE member_id = :m ORDER BY id DESC LIMIT 1',
            ['m' => $medlemId]
        );
        if ($a === null) {
            return ['status' => 'ingen', 'avtale' => null];
        }
        if ((string) $a['status'] === 'aktiv') {
            return ['status' => 'aktiv', 'avtale' => $a];
        }

        $betaling = DB::en(
            'SELECT * FROM payments WHERE subscription_id = :s ORDER BY id DESC LIMIT 1',
            ['s' => (int) $a['id']]
        );
        if ($betaling === null) {
            return ['status' => 'ingen', 'avtale' => $a];
        }
        // Er den alt bokfoert som betalt, men medlemskapet ikke slaatt paa,
        // retter vi det her framfor aa avvise en som har betalt.
        if ((string) $betaling['status'] === 'betalt') {
            self::betaltEngangs((int) $a['id']);
            return ['status' => 'aktiv', 'avtale' => $a];
        }

        // Sporr Vipps. Cron gjor det samme, men bare de forste to dognene og
        // tre av gangen — en soknad som blir liggende en uke ville ellers
        // blitt avvist selv om pengene kom.
        $ref = (string) ($betaling['vipps_reference'] ?? '');
        if ($ref !== '') {
            try {
                $svar = Vipps::hentBetaling($ref);
                $tilstand = strtoupper((string) ($svar['state'] ?? ''));
                if ($tilstand === 'AUTHORIZED') {
                    Vipps::anvendTilstand($ref, $svar, true);
                    $tilstand = 'CAPTURED';
                }
                if ($tilstand === 'CAPTURED') {
                    // AUTHORIZED ble nettopp trukket gjennom den samme
                    // avstemmingen; ved CAPTURED fra oppslaget brukes aggregate.
                    if (strtoupper((string) ($svar['state'] ?? '')) === 'CAPTURED') {
                        Vipps::anvendTilstand($ref, $svar, true);
                    }
                    return ['status' => 'aktiv', 'avtale' => $a];
                }
            } catch (Throwable $e) {
                // Naar vi ikke faar svar fra Vipps, vet vi ikke — og da skal
                // ingen slippes inn paa en antakelse.
                logg_feil('Fikk ikke sjekket medlemsbetaling for medlem ' . $medlemId, $e);
                return ['status' => 'ukjent', 'avtale' => $a];
            }
        }

        return ['status' => (string) $betaling['status'], 'avtale' => $a];
    }

    /**
     * Hvorfor et medlemskap ikke kan sies opp naa — eller null naar det kan.
     *
     * To regler:
     *
     *   Bindingstid. To maaneder fra innmelding, tolv paa aarsavtalen. Den
     *   staar i «binding_til», satt da avtalen ble opprettet.
     *
     *   Én oppsigelse om gangen. Er den alt sagt opp, staar sluttdatoen.
     *
     * @param array<string,mixed> $avtale
     */
    public static function hvorforIkkeSiOpp(array $avtale): ?string
    {
        if ($avtale['slutter'] !== null) {
            return 'Medlemskapet er alt sagt opp, og gjelder ut '
                . Booking::norskDatoKort((string) $avtale['slutter'] . ' 12:00:00') . '.';
        }
        // ── Planen gaar foran den lagrede datoen ──────────────────────
        //
        // «binding_til» settes én gang, naar avtalen opprettes. Endres
        // planens «binding_mnd» etterpaa — fra to maaneder til null — blir
        // datoen staaende i raden, og denne metoden sperret oppsigelsen paa
        // en binding som ikke lenger fantes.
        //
        // Eieren, 5. september: «Prøv Lissom har ingen binding». Planen staar
        // med binding_mnd = 0, men et medlem sto med «bundet til 2. november»
        // — og kunne dermed ikke si opp. Planen er avtalen.
        // Samme kilde som Min side og admin — se bindingTil(). En
        // engangsplan er aldri bundet.
        $plan = self::planUansett((string) $avtale['plan']);
        $binding = self::bindingTil($avtale);
        if ($binding !== null && (string) $binding >= gmdate('Y-m-d')) {
            $aar = $plan !== null && (int) $plan['binding_mnd'] >= 12;
            return ($aar
                ? 'Årsavtalen kan ikke sies opp før året er ute. Den løper til '
                : 'Medlemskapet er bundet til ')
                . Booking::norskDatoKort((string) $binding . ' 12:00:00')
                . '. Ta kontakt om noe har endret seg, så finner vi ut av det.';
        }
        return null;
    }

    /**
     * Siste dag et medlemskap gjelder naar det sies opp i dag.
     *
     * Eieren, 29. august: «settes til den siste dagen i maaneden man sier opp,
     * pluss oppsigelsestiden». Sier noen opp 14. september med én maaneds
     * oppsigelse, gjelder medlemskapet altsaa ut oktober — ikke til 14.
     * oktober.
     *
     * Den forrige regelen la maanedene rett paa dagen i dag. To som sa opp
     * samme maaned fikk da hver sin sluttdato, og trekket gikk et halvt
     * intervall inn i en maaned ingen hadde bedt om.
     *
     * Regnestykket gaar via den foerste i maaneden, ikke den siste: «siste
     * dag i denne maaneden» pluss én maaned gir 30. oktober naar man starter
     * paa 30. september, for PHP teller maaneder fra dagen. Foerste i
     * maaneden pluss (N+1) maaneder, minus én dag, treffer alltid den siste —
     * ogsaa i februar.
     *
     * Datoen regnes i norsk tid. Serveren staar i UTC, og en oppsigelse
     * levert 1. oktober klokka 00:30 norsk tid ville ellers telt som
     * september.
     */
    public static function sluttdato(array $avtale): string
    {
        $plan = self::planUansett((string) $avtale['plan']);
        $mnd = $plan === null ? 1 : max(0, (int) ($plan['oppsigelse_mnd'] ?? 1));
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))
            ->modify('first day of this month')
            ->modify('+' . ($mnd + 1) . ' months')
            ->modify('-1 day')
            ->format('Y-m-d');
    }

    /**
     * Sier opp — med oppsigelsestid.
     *
     * Her ble avtalen stoppet i Vipps med det samme, og medlemmet mistet
     * tilgangen samme sekund. Med én maaneds oppsigelsestid er det feil begge
     * veier: medlemmet har betalt for en maaned til, og verkstedet skal ha den
     * maaneden.
     *
     * Naa settes bare sluttdatoen. Avtalen loper videre, trekket gaar som for,
     * og cron stopper den den dagen den skal — se Medlemskap::tilAvslutning().
     */
    public static function siOpp(array $avtale): void
    {
        $hindring = self::hvorforIkkeSiOpp($avtale);
        if ($hindring !== null) {
            throw new RuntimeException($hindring);
        }
        DB::oppdater('subscriptions', [
            'sagt_opp_at' => gmdate('Y-m-d H:i:s'),
            'slutter'     => self::sluttdato($avtale),
        ], ['id' => (int) $avtale['id']]);
    }

    /**
     * Medlemskap der oppsigelsestida er ute. Kjores av cron.
     *
     * L-11 (pengeflyt-revisjonen, eieren 2. oktober 2026): «slutter» er den
     * siste dagen medlemmet har betalt for, og tilgangen gjelder ut den
     * dagen. Foer ble avtalen stoppet PAA sluttdagen (til og med dagens dato
     * i UTC) — medlemmet mistet den siste betalte dagen. Naa
     * stoppes den foerst dagen etter, regnet i norsk tid.
     *
     * @param string|null $idag Y-m-d i Oslo, for testene; null = i dag
     * @return list<array<string,mixed>>
     */
    public static function tilAvslutning(?string $idag = null): array
    {
        $idag ??= (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
        return DB::alle(
            "SELECT * FROM subscriptions
              WHERE slutter IS NOT NULL AND slutter < :idag
                AND status <> 'stoppet'",
            ['idag' => $idag]
        );
    }

    /** Stopper et medlemskap som har gaatt ut oppsigelsestida si. */
    public static function avslutt(array $avtale): void
    {
        if ($avtale['vipps_agreement_id']) {
            try {
                Vipps::stoppAvtale((string) $avtale['vipps_agreement_id']);
            } catch (Throwable $e) {
                logg_feil('Fikk ikke stoppet avtale ' . $avtale['id'] . ' i Vipps', $e);
            }
        }
        DB::oppdater('subscriptions', [
            'status'      => 'stoppet',
            'neste_trekk' => null,
        ], ['id' => (int) $avtale['id']]);
        // L-1: loeper en annen avtale (et nytt medlemskap), er hun ikke oppsagt.
        self::oppsagtOmIngenLoeper((int) $avtale['member_id']);
    }

    /**
     * Neste trekkdato, én maaned fram, med dagen i behold.
     *
     * «+1 month» regner ikke slik en kalender gjor. Maalt 7. september 2026:
     * 31. januar + 1 maaned = 3. mars, ikke 28. februar. Deretter 3. april,
     * 3. mai — datoen vandrer nedover aaret, og et medlem satt til den 31.
     * ender paa en helt annen dag.
     *
     * Eieren valgte «Siste dag i maaneden: 31. januar → 28. februar →
     * 31. mars. Ligger fast.» Derfor huskes dagen i «trekk_dag», og den
     * klippes bare der maaneden er for kort. Neste maaned staar den igjen
     * der den hoerer hjemme.
     *
     * Er «trekk_dag» tom — en avtale fra for migrasjon 149 — brukes dagen i
     * datoen som staar. Da oppfoerer den seg som den alltid har gjort, bare
     * uten aa vandre.
     */
    public static function nesteTrekkdato(string $fra, ?int $dag = null): string
    {
        $d = new DateTimeImmutable($fra, new DateTimeZone('UTC'));
        $onsket = $dag !== null && $dag >= 1 && $dag <= 31 ? $dag : (int) $d->format('j');

        // Foerste i maaneden foerst. Legger vi en maaned til den 31., renner
        // den over i maaneden etter — det er nettopp feilen vi retter.
        $maaned = $d->modify('first day of this month')->modify('+1 month');
        $sisteDag = (int) $maaned->format('t');

        return $maaned->setDate(
            (int) $maaned->format('Y'),
            (int) $maaned->format('n'),
            min($onsket, $sisteDag)
        )->format('Y-m-d');
    }

    /**
     * Avtaler som skal belastes naa. Kjores av cron.
     *
     * @return list<array<string,mixed>>
     */
    public static function tilTrekk(?string $idag = null): array
    {
        // Bestilles BESTILL_DAGER_FOR dager foer trekkdatoen, saa forfallet
        // kan vaere selve trekkdatoen (eieren, 2. oktober 2026: trekket skal
        // gaa PAA neste_trekk, ikke dagen etter). Vipps krever minst én dag.
        $senest = (new DateTimeImmutable($idag ?? 'now', new DateTimeZone('Europe/Oslo')))
            ->modify('+' . self::BESTILL_DAGER_FOR . ' days')->format('Y-m-d');
        return DB::alle(
            "SELECT s.*, m.navn, m.epost, m.telefon
               FROM subscriptions s
               JOIN members m ON m.id = s.member_id
              WHERE s.status = 'aktiv'
                AND s.neste_trekk IS NOT NULL
                AND s.neste_trekk <= :senest
                AND COALESCE(s.vipps_agreement_id, '') <> ''
                -- Er det sagt opp, trekkes det ikke for en periode som
                -- begynner etter siste dag.
                AND (s.slutter IS NULL OR s.neste_trekk <= s.slutter)
                AND m.anonymisert_at IS NULL",
            ['senest' => $senest]
        );
    }

    /** Oppført trekkdato er Vipps-forfall. Et forsinket forsøk får tidligst i morgen. */
    public static function trekkForfall(array $avtale, ?string $idag = null): string
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $minst = (new DateTimeImmutable($idag ?? 'now', $oslo))
            ->modify('+' . self::VARSEL_DAGER . ' days')->format('Y-m-d');
        return max($minst, (string) $avtale['neste_trekk']);
    }

    /** Teksten kunden ser paa trekket i Vipps. */
    private static function trekkBeskrivelse(array $avtale): string
    {
        // Navnet med, innenfor Vipps sine 45 tegn paa et trekk.
        // Uten det sa et maanedlig trekk bare hvilken plan det
        // gjaldt — og det er nettopp de trekkene det er flest av.
        return Vipps::beskrivelseInnenfor(
            Vipps::TREKK_BESKRIVELSE_MAKS,
            'Medlemskap ' . $avtale['plan'],
            (string) ($avtale['navn'] ?? '')
        );
    }

    /**
     * Innholdet Vipps faar for trekket.
     *
     * Et nytt forsoek bruker samme Idempotency-Key, og da maa innholdet vaere
     * det samme som foerste gang — ellers avviser Vipps det som
     * «idempotency-conflict», og et trekk som kanskje gikk, blir staaende
     * uten id. Foer ble «due» regnet ut paa nytt: proevde vi igjen neste
     * natt, var forfallet en annen dag.
     *
     * $tidligere er betalingsraden fra foerste forsoek. Har den lagret
     * innholdet, brukes det som det er. En rad fra foer kolonnen fantes faar
     * innholdet utledet av det som ble lagret da: beloepet paa raden og
     * forfallet slik det ble regnet ut den dagen raden ble skrevet.
     *
     * @param array<string,mixed>|null $tidligere
     * @return array<string,mixed>
     */
    public static function trekkForesporsel(array $avtale, ?array $tidligere = null, ?string $idag = null): array
    {
        $forsok = self::trekkForsok($avtale, $tidligere, '', $idag);
        return $forsok[count($forsok) - 1]['kropp'];
    }

    /** Flest noekler vi bruker paa ett trekk foer et menneske maa se paa det. */
    public const TREKK_MAKS_NOKLER = 5;

    /** Trekkdagen for alle faste trekk (eieren, 2. oktober 2026: den 1.). */
    public const TREKK_DAG = 1;

    /**
     * L-5: et trekk som har staatt paa «opprettet» uten charge-id saa lenge,
     * henger — kjoeringen som bestilte det, doede underveis. Det proeves
     * igjen med samme noekkel. Kortere enn dette kan en annen kjoering vaere
     * midt i kallet til Vipps.
     */
    public const TREKK_HENGER_MIN = 15;

    /** L-10: saa mange dager av kalendermaaneden en frys maa dekke for at trekket hoppes over. */
    public const PAUSE_MIN_DAGER = 15;

    /**
     * L-10: hvor mange dager av kalendermaaneden $dato ligger i, som er
     * dekket av en godkjent frys. Flere fryser telles sammen, uten dobbelt.
     *
     * En frys som er over, har Frys::gjenapneForfalte() satt til
     * «avsluttet» — etter til_dato. Den teller fortsatt, saa en forsinket
     * runde ikke tar etterbetaling for pausen (Codex 02.10). En frys
     * verkstedet avbrot underveis gjaldt bare til og med dagen foer den ble
     * avsluttet, i norsk dato (api/admin/frys.php beholder til_dato, men
     * updated_at er avslutningen).
     */
    public static function pauseDager(int $medlemId, string $dato): int
    {
        if (!Frys::klar()) {
            return 0;
        }
        $d = new DateTimeImmutable($dato);
        $start = $d->modify('first day of this month')->format('Y-m-d');
        $slutt = $d->modify('last day of this month')->format('Y-m-d');
        $rader = DB::alle(
            "SELECT status, fra_dato, til_dato, updated_at FROM medlem_frys
              WHERE member_id = :m AND status IN ('godkjent', 'avsluttet')
                AND fra_dato <= :slutt AND til_dato >= :start",
            ['m' => $medlemId, 'slutt' => $slutt, 'start' => $start]
        );
        $dager = [];
        foreach ($rader as $r) {
            $til = (string) $r['til_dato'];
            if ((string) $r['status'] === 'avsluttet') {
                // updated_at er UTC; dagen avslutningen skjedde regnes i
                // norsk tid. Pausen varte til og med dagen foer.
                $avsluttet = (new DateTimeImmutable((string) $r['updated_at'], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Europe/Oslo'))->modify('-1 day')->format('Y-m-d');
                $til = min($til, $avsluttet);
            }
            $fra = max((string) $r['fra_dato'], $start);
            $til = min($til, $slutt);
            for ($x = new DateTimeImmutable($fra); $x->format('Y-m-d') <= $til; $x = $x->modify('+1 day')) {
                $dager[$x->format('Y-m-d')] = true;
            }
        }
        return count($dager);
    }

    /**
     * L-10 (eieren, 2. oktober 2026; 15-dagersregelen valgt av koordinator
     * etter kontrollor samme dag): maaneden trekket gjelder, hoppes over naar
     * en godkjent frys dekker minst PAUSE_MIN_DAGER dager av den. Frys
     * 31.10–1.11 gir altsaa ikke gratis november; frys 2.11–30.11 gjoer det.
     * Bare trekket som behandles naa: neste trekk flyttes én maaned, og den
     * maaneden sjekkes naar den kommer — avbrytes pausen i mellomtiden,
     * trekkes den som vanlig (Codex 02.10). Det som hoppes over, tas aldri
     * igjen etterpaa, saa ingen trekkes dobbelt.
     *
     * @return string|null ny trekkdato, eller null naar trekket ikke hoppes over
     */
    private static function hoppOverPause(array $avtale): ?string
    {
        $gammel = (string) $avtale['neste_trekk'];
        if (self::pauseDager((int) $avtale['member_id'], $gammel) < self::PAUSE_MIN_DAGER) {
            return null;
        }
        $dag = isset($avtale['trekk_dag']) && $avtale['trekk_dag'] !== null ? (int) $avtale['trekk_dag'] : null;
        $ny = self::nesteTrekkdato($gammel, $dag);
        // Flyttingen og raden om at maaneden ble hoppet over skjer sammen,
        // i én transaksjon (kontrolloeren, 2. oktober 2026). Kalles den inne
        // i en annen, blir den en del av den — samme moenster som
        // Booking::gjorOppFullRefusjon().
        $arbeid = static function () use ($avtale, $gammel, $ny): void {
            // Bare om ingen andre har flyttet den i mellomtiden.
            $flyttet = DB::kjor(
                'UPDATE subscriptions SET neste_trekk = :ny WHERE id = :i AND neste_trekk = :gammel',
                ['ny' => $ny, 'i' => (int) $avtale['id'], 'gammel' => $gammel]
            )->rowCount();
            // At maaneden ble hoppet over, er et faktum (betalingseksperten,
            // 2. oktober 2026): avsluttes frysen tidlig etterpaa, er maaneden
            // fortsatt fritatt. Staar i revisjonsloggen paa avtalen (ingen ny
            // tabell), og leses av fritattMaaned(). Én rad per avtale og maaned.
            $maaned = (new DateTimeImmutable($gammel))->format('Y-m');
            if ($flyttet > 0 && !self::hoppetOver([(int) $avtale['id']], $maaned)) {
                DB::settInn('audit_log', [
                    'member_id'   => null,
                    'handling'    => self::HOPPET_OVER,
                    'objekt_type' => 'subscription',
                    'objekt_id'   => (int) $avtale['id'],
                    'detaljer'    => json_encode(['maaned' => $maaned, 'trekkdato' => $gammel, 'neste_trekk' => $ny,
                        'medlem' => (int) $avtale['member_id']], JSON_UNESCAPED_UNICODE),
                ]);
            }
        };
        DB::kobling()->inTransaction() ? $arbeid() : DB::iTransaksjon($arbeid);
        logg('Trekk hoppet over: medlemskapet er satt paa pause', [
            'avtale' => (int) $avtale['id'], 'trekkdato' => $gammel, 'neste_trekk' => $ny,
        ]);
        return $ny;
    }

    /**
     * L-5: tar et trekk som skal proeves paa nytt. Bare én kjoering faar det:
     * raden flyttes til «opprettet» bare om den fortsatt staar slik den ble
     * lest, uten charge-id.
     */
    private static function taForsok(array $rad): bool
    {
        return DB::kjor(
            "UPDATE payments SET status = 'opprettet', updated_at = UTC_TIMESTAMP()
              WHERE id = :i AND status = :s AND vipps_psp_ref IS NULL AND updated_at = :u",
            ['i' => (int) $rad['id'], 's' => (string) $rad['status'], 'u' => (string) $rad['updated_at']]
        )->rowCount() === 1;
    }

    /**
     * Alle forsoekene paa et trekk, eldste foerst: noekkelen og innholdet
     * som ble sendt med den. Det siste er det som gjelder naa.
     *
     * Lagres som {"forsok":[{"nokkel":…,"kropp":…},…]} i
     * payments.trekk_foresporsel. Et bart innhold (uten «forsok») og en rad
     * uten noe lagret gjelder grunnnoekkelen.
     *
     * @param array<string,mixed>|null $tidligere
     * @return list<array{nokkel:string,kropp:array<string,mixed>}>
     */
    public static function trekkForsok(array $avtale, ?array $tidligere, string $grunnNokkel, ?string $idag = null): array
    {
        if ($tidligere === null) {
            return [['nokkel' => $grunnNokkel, 'kropp' => Vipps::trekkKropp(
                (int) $avtale['pris_ore'],
                self::trekkBeskrivelse($avtale),
                self::trekkForfall($avtale, $idag)
            )]];
        }
        $lagret = json_decode((string) ($tidligere['trekk_foresporsel'] ?? ''), true);
        if (is_array($lagret) && isset($lagret['forsok']) && is_array($lagret['forsok']) && $lagret['forsok'] !== []) {
            $ut = [];
            foreach ($lagret['forsok'] as $f) {
                if (is_array($f) && isset($f['nokkel'], $f['kropp']['amount'], $f['kropp']['due']) && is_array($f['kropp'])) {
                    $ut[] = ['nokkel' => (string) $f['nokkel'], 'kropp' => $f['kropp']];
                }
            }
            if ($ut !== []) {
                return $ut;
            }
        }
        if (is_array($lagret) && isset($lagret['amount'], $lagret['description'], $lagret['due'])) {
            return [['nokkel' => $grunnNokkel, 'kropp' => $lagret]];
        }
        // Rad fra foer kolonnen fantes. created_at er UTC (DB setter
        // time_zone +00:00); forfallet ble regnet ut fra datoen i Oslo.
        $forsteDag = (new DateTimeImmutable((string) $tidligere['created_at'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d');
        return [['nokkel' => $grunnNokkel, 'kropp' => Vipps::trekkKropp(
            (int) $tidligere['belop_ore'],
            self::trekkBeskrivelse($avtale),
            self::trekkForfall($avtale, $forsteDag)
        )]];
    }

    /**
     * Finnes et av forsoekene alt som trekk hos Vipps?
     *
     * Et trekk er vaart naar forfall og beloep er det samme som i ett av
     * forsoekene vi sendte, og id-en ikke alt hoerer til en annen betaling
     * (et forsinket trekk for forrige maaned kan ha samme forfall og beloep).
     *
     * @param list<array<string,mixed>> $trekkListe fra Vipps::trekkPaaAvtale()
     * @param list<array{nokkel:string,kropp:array<string,mixed>}> $forsok
     * @param list<string> $kjenteIder trekk-id-er som alt staar paa andre betalinger
     * @return array<string,mixed>|null
     */
    public static function trekkSomFinnes(array $trekkListe, array $forsok, array $kjenteIder): ?array
    {
        foreach ($trekkListe as $t) {
            $id = (string) ($t['id'] ?? '');
            if ($id === '' || in_array($id, $kjenteIder, true)) {
                continue;
            }
            foreach ($forsok as $f) {
                if ((string) ($t['due'] ?? '') === (string) $f['kropp']['due']
                    && (int) ($t['amount'] ?? -1) === (int) $f['kropp']['amount']) {
                    return $t;
                }
            }
        }
        return null;
    }

    /**
     * Vipps avviste et nytt forsoek med det lagrede innholdet.
     *
     * Hadde Vipps laget trekket foerste gang, ville samme noekkel og samme
     * innhold gitt det samme svaret tilbake. Et nei betyr derfor trolig at
     * trekket aldri ble laget — men det sjekkes hos Vipps foer noe nytt bes
     * om: finnes trekket, brukes det. Finnes det ikke, bes det om med en ny
     * noekkel og et gyldig forfall (minst én dag fram). Aldri to trekk for
     * samme periode.
     *
     * @param list<array{nokkel:string,kropp:array<string,mixed>}> $forsok
     * @return array{0:string,1:list<array{nokkel:string,kropp:array<string,mixed>}>,2:string,3:string} [trekk-id, forsoek, forfall, Vipps-status for et gjenfunnet trekk]
     */
    private static function trekkEtterAvvisning(array $avtale, array $forsok, string $maaned, int $betalingId, ?string $idag): array
    {
        $avtaleId = (string) $avtale['vipps_agreement_id'];
        // Kaster hvis oppslaget feiler: et tomt svar fordi Vipps ikke svarte,
        // er ikke det samme som «ingen trekk».
        $liste = Vipps::trekkPaaAvtale($avtaleId, true);
        $kjente = array_map('strval', array_column(DB::alle(
            'SELECT vipps_psp_ref FROM payments
              WHERE subscription_id = :s AND id <> :b AND vipps_psp_ref IS NOT NULL',
            ['s' => (int) $avtale['id'], 'b' => $betalingId]
        ), 'vipps_psp_ref'));

        $funnet = self::trekkSomFinnes($liste, $forsok, $kjente);
        if ($funnet !== null) {
            logg('Trekket fantes alt hos Vipps; det brukes, ikke et nytt', [
                'avtale' => (int) $avtale['id'], 'trekk' => (string) $funnet['id'], 'status' => (string) ($funnet['status'] ?? ''),
            ]);
            return [(string) $funnet['id'], $forsok, (string) $funnet['due'], (string) ($funnet['status'] ?? '')];
        }

        if (count($forsok) >= self::TREKK_MAKS_NOKLER) {
            throw new RuntimeException('Trekket for avtale ' . $avtale['id'] . ' ble avvist ' . count($forsok)
                . ' ganger. Det maa sees paa for haand.');
        }

        $siste = $forsok[count($forsok) - 1]['kropp'];
        $ny = [
            'nokkel' => substr(hash('sha256', 'trekk:' . $avtale['id'] . ':' . $maaned . ':' . (count($forsok) + 1)), 0, 36),
            'kropp'  => Vipps::trekkKropp((int) $siste['amount'], (string) $siste['description'], self::trekkForfall($avtale, $idag)),
        ];
        $forsok[] = $ny;
        // Lagres FOER kallet, saa et nytt tapt svar proeves med denne noekkelen.
        DB::oppdater('payments', ['trekk_foresporsel' => self::trekkForsokJson($forsok)], ['id' => $betalingId]);

        $trekkId = Vipps::belastAvtale($avtaleId, $ny['kropp'], $ny['nokkel']);
        return [$trekkId, $forsok, (string) $ny['kropp']['due'], ''];
    }

    /**
     * L-12: et tidligere forsoek paa trekket (samme forfall og beloep som
     * sendt), slik det ligger hos Vipps — eller null. Kaster hvis Vipps ikke
     * svarer: «fikk ikke svar» er ikke «finnes ikke».
     *
     * @param list<array{nokkel:string,kropp:array<string,mixed>}> $forsok
     * @return array<string,mixed>|null
     */
    private static function forsokHosVipps(array $avtale, array $forsok, int $betalingId): ?array
    {
        $liste = Vipps::trekkPaaAvtale((string) $avtale['vipps_agreement_id'], true);
        $kjente = array_map('strval', array_column(DB::alle(
            'SELECT vipps_psp_ref FROM payments
              WHERE subscription_id = :s AND id <> :b AND vipps_psp_ref IS NOT NULL',
            ['s' => (int) $avtale['id'], 'b' => $betalingId]
        ), 'vipps_psp_ref'));
        return self::trekkSomFinnes($liste, $forsok, $kjente);
    }

    /** L-12: maaneden er betalt utenom trekket; neste trekk flyttes én maaned fram. */
    private static function flyttForbiBetaltMaaned(array $avtale): void
    {
        DB::oppdater('subscriptions', [
            'neste_trekk' => self::erEngangs((string) $avtale['plan']) ? null : self::nesteTrekkdato(
                (string) $avtale['neste_trekk'],
                isset($avtale['trekk_dag']) && $avtale['trekk_dag'] !== null ? (int) $avtale['trekk_dag'] : null
            ),
        ], ['id' => (int) $avtale['id']]);
    }

    /** @param list<array{nokkel:string,kropp:array<string,mixed>}> $forsok */
    private static function trekkForsokJson(array $forsok): string
    {
        return json_encode(['forsok' => $forsok], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Ber Vipps om ett trekk, og fører det som en betaling.
     *
     * Idempotensnokkelen bygges av avtalen og maaneden. Kjorer cron to ganger
     * samme natt, ber vi Vipps om det samme trekket — og Vipps gjor det én
     * gang.
     *
     * $idag er bare for testene (forsoek paa en senere dag).
     */
    public static function trekk(array $avtale, ?string $idag = null): string
    {
        $maaned = (new DateTimeImmutable((string) $avtale['neste_trekk']))->format('Y-m');
        $nokkel = substr(hash('sha256', 'trekk:' . $avtale['id'] . ':' . $maaned), 0, 36);

        // Er trekket alt fort, gjor vi ikke noe mer. Uten denne kunne en
        // halvveis kjoring gitt to rader i payments for samme maaned.
        $fra = DB::en(
            "SELECT * FROM payments WHERE subscription_id = :s AND idempotency_key = :k",
            ['s' => (int) $avtale['id'], 'k' => $nokkel]
        );

        // Et trekk Vipps aldri tok imot, proeves igjen. Kastet belastAvtale()
        // — nett nede, Vipps svarte 500 — ble raden «feilet» uten trekk-id.
        // Foer sto den der og sa «alt fort» hver natt, og neste_trekk ble
        // aldri flyttet: maaneden ble aldri krevd inn. Raden gjenbrukes med
        // samme noekkel, saa kom trekket likevel fram hos Vipps forrige gang,
        // gir Vipps det samme trekket tilbake — ikke et nytt.
        //
        // L-5: det samme gjelder en rad som ble staaende paa «opprettet» uten
        // charge-id — kjoeringen doede mellom raden og svaret fra Vipps. Foer
        // sa den «alt fort» hver natt, og runden hang paa den maaneden for
        // alltid. Etter TREKK_HENGER_MIN minutter proeves den igjen med samme
        // noekkel og samme innhold; er den nyere, kan en annen kjoering vaere
        // midt i kallet, og da venter vi.
        $paaNytt = null;
        $tidligere = null;
        if ($fra !== null && ($fra['vipps_psp_ref'] ?? null) === null
            && in_array((string) $fra['status'], ['feilet', 'opprettet'], true)) {
            if ((string) $fra['status'] === 'opprettet') {
                $gammel = DB::verdi(
                    'SELECT updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::TREKK_HENGER_MIN . ' MINUTE)
                       FROM payments WHERE id = :i',
                    ['i' => (int) $fra['id']]
                );
                if ((int) $gammel !== 1) {
                    return 'bestilles alt';
                }
            }
            // Bare én kjoering faar proeve igjen.
            if (!self::taForsok($fra)) {
                return 'bestilles alt';
            }
            $paaNytt = (int) $fra['id'];
            $tidligere = $fra;
            $fra = null;
        }
        if ($fra !== null) {
            return 'alt fort';
        }

        // L-10: maaneden hoppes over naar en godkjent frys dekker minst
        // PAUSE_MIN_DAGER dager av den. Bare et trekk som ikke er bestilt: et
        // forsoek som alt er sendt til Vipps, avklares ferdig (over og under),
        // saa det ikke blir et trekk hos Vipps som vi ikke foelger.
        if ($tidligere === null) {
            $etterPause = self::hoppOverPause($avtale);
            if ($etterPause !== null) {
                return 'hoppet over (pause), neste trekk ' . $etterPause;
            }
        }

        // Samme noekkel = samme innhold. Et nytt forsoek sender det som ble
        // sendt sist, med samme noekkel — ikke et forfall regnet ut paa nytt i dag.
        $forsok = self::trekkForsok($avtale, $tidligere, $nokkel, $idag);
        $gjeldende = $forsok[count($forsok) - 1];
        $kropp = $gjeldende['kropp'];
        // Runden skal bestille trekket foer trekkdatoen (tilTrekk()). Er den
        // for sent ute — sto den over i flere netter — blir forfallet i
        // morgen, og det skal synes i loggen.
        if ($tidligere === null && (string) $kropp['due'] > (string) $avtale['neste_trekk']) {
            logg('Trekket ble bestilt for sent; forfall flyttet', [
                'avtale' => (int) $avtale['id'], 'trekkdato' => (string) $avtale['neste_trekk'],
                'forfall' => (string) $kropp['due'],
            ]);
        }
        $periodeFra = (new DateTimeImmutable((string) $avtale['neste_trekk']))
            ->modify('first day of this month')->format('Y-m-d');
        // Kolonnen kommer med migrasjon 243. Foer den er kjoert, utledes
        // innholdet av raden (se trekkForsok()), og en ny noekkel tas aldri
        // i bruk — den kunne ikke blitt husket til neste natt.
        $harKolonne = DB::harKolonne('payments', 'trekk_foresporsel');
        $lagre = $harKolonne ? ['trekk_foresporsel' => self::trekkForsokJson($forsok)] : [];

        // L-12: maaneden er alt betalt utenom trekket (i verkstedet, «Forny»).
        // Medlemmet laases fra sjekken til trekkraden er satt inn — samme laas
        // som manuell medlemsbetaling i admin tar, og admin nekter naar et
        // trekk for maaneden er underveis. Da kan ingen av dem smette inn
        // mellom sjekken og raden (kontrolloeren, 2. oktober 2026).
        //
        // Hoppet gjelder bare et NYTT trekk. Finnes et forsoek fra foer
        // (opprettet/feilet uten charge-id), kan det ligge hos Vipps: det
        // avklares under (GET, ingen ny bestilling) foer noe hoppes over.
        [$utfall, $betalingId] = DB::iTransaksjon(static function () use (
            $avtale, $maaned, $tidligere, $paaNytt, $periodeFra, $lagre, $kropp, $nokkel
        ): array {
            DB::en('SELECT id FROM members WHERE id = :i FOR UPDATE', ['i' => (int) $avtale['member_id']]);
            $betaltFraFoer = self::betalingForMaaned((int) $avtale['member_id'], $maaned, true) !== null;
            if ($betaltFraFoer && $tidligere === null) {
                self::flyttForbiBetaltMaaned($avtale);
                return ['betalt', 0];
            }
            if ($paaNytt !== null) {
                DB::oppdater('payments', ['status' => 'opprettet', 'gjelder_fra' => $periodeFra] + $lagre, ['id' => $paaNytt]);
                return [$betaltFraFoer ? 'avklar' : 'bestill', $paaNytt];
            }
            return ['bestill', DB::settInn('payments', [
                'vipps_reference' => Vipps::nyReferanse('MED'),
                'type'            => 'recurring_charge',
                'formal'          => 'medlemskap',
                'member_id'       => (int) $avtale['member_id'],
                'subscription_id' => (int) $avtale['id'],
                'belop_ore'       => (int) $kropp['amount'],
                'status'          => 'opprettet',
                'idempotency_key' => $nokkel,
                'gjelder_fra'     => $periodeFra,
            ] + $lagre)];
        });
        if ($utfall === 'betalt') {
            logg('Trekk hoppet over: maaneden er alt betalt', ['avtale' => (int) $avtale['id'], 'maaned' => $maaned]);
            return 'betalt fra foer';
        }
        $betalingId = (int) $betalingId;

        $funnetStatus = '';
        try {
            try {
                if ($utfall === 'avklar') {
                    // L-12 med et forsoek fra foer: maaneden er betalt utenom
                    // trekket. Ingen ny bestilling — bare sjekk om forsoeket
                    // ligger hos Vipps (samme forfall og beloep som sendt).
                    $funnet = self::forsokHosVipps($avtale, $forsok, $betalingId);
                    $aapent = $funnet !== null
                        && !in_array(strtoupper((string) ($funnet['status'] ?? '')), ['FAILED', 'CANCELLED'], true);
                    if (!$aapent) {
                        // Ikke hos Vipps, eller der men ikke trukket: hopp over.
                        DB::oppdater('payments', [
                            'status'        => 'avbrutt',
                            'vipps_psp_ref' => $funnet !== null ? (string) $funnet['id'] : null,
                        ], ['id' => $betalingId]);
                        self::flyttForbiBetaltMaaned($avtale);
                        logg('Trekk hoppet over: maaneden er alt betalt, forsoeket er avklart hos Vipps', [
                            'avtale' => (int) $avtale['id'], 'maaned' => $maaned,
                            'trekk' => $funnet['id'] ?? null, 'status' => $funnet['status'] ?? 'finnes ikke',
                        ]);
                        return 'betalt fra foer (forsoeket er ikke trukket hos Vipps)';
                    }
                    // Forsoeket finnes og er (eller blir) trukket: det foeres —
                    // aldri et nytt — og maaneden er da betalt to ganger. Det maa
                    // gjoeres opp for haand.
                    logg_feil('Maaned ' . $maaned . ' for avtale ' . $avtale['id'] . ' er betalt utenom trekket, '
                        . 'og et tidligere trekk ligger ogsaa hos Vipps (' . $funnet['id'] . ', '
                        . ($funnet['status'] ?? '') . '). Avlys eller refunder det ene.');
                    $trekkId = (string) $funnet['id'];
                    $forfall = (string) ($funnet['due'] ?? $kropp['due']);
                    $funnetStatus = (string) ($funnet['status'] ?? '');
                } else {
                    $trekkId = Vipps::belastAvtale(
                        (string) $avtale['vipps_agreement_id'],
                        $kropp,
                        $gjeldende['nokkel']
                    );
                    $forfall = (string) $kropp['due'];
                }
            } catch (VippsAvvisteTrekk $e) {
                // Bare et nytt forsoek kan ha et trekk fra foer hos Vipps.
                if ($tidligere === null || !$harKolonne) {
                    throw $e;
                }
                [$trekkId, $forsok, $forfall, $funnetStatus] = self::trekkEtterAvvisning($avtale, $forsok, $maaned, $betalingId, $idag);
            }
        } catch (Throwable $e) {
            // Bare en rad uten charge-id blir «feilet»: har en annen kjoering
            // alt lagret trekket, skal den ikke merkes som feilet over den.
            DB::kjor(
                "UPDATE payments SET status = 'feilet' WHERE id = :i AND vipps_psp_ref IS NULL",
                ['i' => $betalingId]
            );
            logg_feil('Trekk feilet for avtale ' . $avtale['id'], $e);
            throw $e;
        }

        // Trekk-ID-en tas vare paa. Den ble kastet for, og da fantes det ingen
        // vei tilbake til Vipps for aa sporre hvordan det gikk: raden ble
        // staaende paa «venter» for alltid, og verken «Medlemskapet ditt er
        // fornyet» eller «Vi fikk ikke trukket betalingen» ble sendt til noen.
        // Uten charge-id kan trekket aldri foelges opp.
        //
        // trekkUtenSvar() krever «vipps_psp_ref IS NOT NULL». Svarte Vipps 201
        // uten en id, ble raden staaende paa «venter» for alltid: den kom
        // aldri med i statusrunden, og ingen kunne sporre hvordan det gikk.
        // Pengene kan godt ha flyttet seg.
        //
        // Raden merkes ikke «feilet» — Vipps sa 201, saa trekket finnes
        // trolig, og «feilet» ville invitert til aa kreve inn det samme
        // beloepet én gang til. Den staar som «venter», synlig i Kassa, og
        // feilloggen sier hva som mangler saa det kan finnes igjen hos Vipps.
        if ($trekkId === '') {
            logg_feil('Vipps ga ingen charge-id for trekk paa avtale '
                . $avtale['vipps_agreement_id'] . ' (' . Booking::kroner((int) $kropp['amount'])
                . ', betaling ' . $betalingId . '). Den kan ikke foelges opp automatisk.');
        }

        // Neste trekk en maaned fram. Er avtalen en proveperiode, er dette
        // det eneste trekket — da stopper vi den etterpaa.
        $plan = self::planUansett((string) $avtale['plan']);
        $engangs = $plan !== null && (int) $plan['engangs'] === 1;
        $nesteTrekk = $engangs ? null : self::nesteTrekkdato(
            (string) $avtale['neste_trekk'],
            isset($avtale['trekk_dag']) && $avtale['trekk_dag'] !== null
                ? (int) $avtale['trekk_dag'] : null
        );

        // L-5: charge-id og neste_trekk i samme transaksjon. Doede kjoeringen
        // mellom dem, sto trekket med id men neste_trekk uflyttet — eller
        // omvendt: da hang runden paa den maaneden.
        DB::iTransaksjon(static function () use ($betalingId, $trekkId, $avtale, $nesteTrekk): void {
            DB::oppdater('payments', [
                'status'        => 'venter',
                'vipps_psp_ref' => $trekkId !== '' ? $trekkId : null,
            ], ['id' => $betalingId]);
            DB::oppdater('subscriptions', [
                'siste_trekk' => (string) $avtale['neste_trekk'],
                'neste_trekk' => $nesteTrekk,
            ], ['id' => (int) $avtale['id']]);
        });

        // Et gjenfunnet trekk kan alt vaere avgjort hos Vipps (CHARGED,
        // FAILED, CANCELLED). Da faar raden den statusen med det samme —
        // et FAILED-trekk skal ikke gi tilgang til neste statusrunde — og
        // kunden faar ikke varselet om et trekk som «kommer».
        if (in_array(strtoupper($funnetStatus), ['CHARGED', 'FAILED', 'CANCELLED'], true) && $trekkId !== '') {
            return 'gjenfunnet trekk: ' . self::sjekkTrekk([
                'id'                 => $betalingId,
                'vipps_psp_ref'      => $trekkId,
                'vipps_agreement_id' => (string) $avtale['vipps_agreement_id'],
                'plan'               => (string) $avtale['plan'],
                'navn'               => (string) ($avtale['navn'] ?? ''),
                'epost'              => $avtale['epost'] ?? null,
                'telefon'            => $avtale['telefon'] ?? null,
            ]);
        }

        // Vipps krever at kunden vet om trekket for det skjer.
        if (!empty($avtale['epost'])) {
            Varsel::mal('medlemstrekk_varsel', ['epost' => (string) $avtale['epost']], [
                'navn'  => (string) $avtale['navn'],
                'belop' => Booking::kroner((int) $kropp['amount']),
                'plan'  => (string) $avtale['plan'],
                'dag'   => self::norskDag($forfall),
            ], 'medlemskap', $betalingId);
        }

        return 'bedt om trekk til ' . $forfall;
    }

    /**
     * Hvordan gikk trekket?
     *
     * Maanedstrekket er ikke en ePayment og kan ikke slaas opp med
     * hentBetaling(). Det ligger under avtalen sin, og maa hentes derfra.
     * Uten dette oppslaget sto hvert eneste maanedstrekk paa «venter» i
     * regnskapet, uansett om pengene kom inn eller ikke.
     *
     * Svarer Vipps at trekket er gjort, blir raden betalt og medlemmet faar
     * kvitteringen. Svarer den at det feilet, blir raden feilet og medlemmet
     * faar beskjed om aa aapne Vipps. Alt annet — trekket er bestilt, men ikke
     * forfalt enda — lar vi staa: det er ikke noe galt, det har bare ikke
     * skjedd enda.
     *
     * @param array<string,mixed> $p raden fra payments
     * @return string hva som ble gjort, for loggen
     */
    public static function sjekkTrekk(array $p): string
    {
        $trekkId = trim((string) ($p['vipps_psp_ref'] ?? ''));
        $avtaleId = trim((string) ($p['vipps_agreement_id'] ?? ''));
        if ($trekkId === '' || $avtaleId === '') {
            return 'mangler trekk-id';
        }

        $svar = Vipps::hentTrekk($avtaleId, $trekkId);
        $status = strtoupper((string) ($svar['status'] ?? ''));

        DB::oppdater('payments', [
            'siste_payload' => json_encode($svar, JSON_UNESCAPED_UNICODE),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ], ['id' => (int) $p['id']]);

        // Gikk pengene inn.
        if ($status === 'CHARGED') {
            DB::oppdater('payments', ['status' => 'betalt'], ['id' => (int) $p['id']]);
            if (!empty($p['epost'])) {
                Varsel::mal('medlemskap_fornyet', ['epost' => (string) $p['epost']], [
                    'navn'        => (string) ($p['navn'] ?? ''),
                    'abonnement'  => (string) ($p['plan'] ?? 'Medlemskapet'),
                ], 'medlemskap', (int) $p['id']);
            }
            return 'betalt';
        }

        // Gikk de ikke. Vipps proever selv i fem dager (retryDays er satt naar
        // trekket bestilles); staar det FAILED, er de dagene brukt opp. Da er
        // det medlemmet selv som maa aapne Vipps, og da maa hen faa vite det.
        if ($status === 'FAILED' || $status === 'CANCELLED') {
            DB::oppdater('payments', ['status' => $status === 'CANCELLED' ? 'avbrutt' : 'feilet'],
                         ['id' => (int) $p['id']]);
            if (!empty($p['epost']) || !empty($p['telefon'])) {
                Varsel::mal('betaling_feilet', [
                    'epost'   => $p['epost'] ?? null,
                    'telefon' => $p['telefon'] ?? null,
                ], [
                    'navn'       => (string) ($p['navn'] ?? ''),
                    'abonnement' => (string) ($p['plan'] ?? 'Medlemskapet'),
                ], 'medlemskap', (int) $p['id']);
            }
            return strtolower($status);
        }

        return 'venter (' . ($status !== '' ? $status : 'ukjent') . ')';
    }

    /**
     * Trekkene som ikke har fatt et svar enda.
     *
     * Trekket bes om noen dager fram i tid, saa det er normalt at et trekk
     * staar en uke for det gjor opp. Etter tretti dager gir vi opp aa sporre:
     * da har Vipps for lengst gitt opp aa proeve.
     *
     * @return list<array<string,mixed>>
     */
    public static function trekkUtenSvar(int $maks = 50): array
    {
        if (!DB::harKolonne('payments', 'vipps_psp_ref')) {
            return [];
        }
        return DB::alle(
            "SELECT p.*, s.vipps_agreement_id, s.plan, m.navn, m.epost, m.telefon
               FROM payments p
               JOIN subscriptions s ON s.id = p.subscription_id
          LEFT JOIN members m ON m.id = p.member_id
              WHERE p.type = 'recurring_charge'
                AND p.status IN ('opprettet', 'venter', 'autorisert')
                AND p.vipps_psp_ref IS NOT NULL
                AND p.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
           ORDER BY p.id
              LIMIT " . max(1, min(200, $maks))
        );
    }

    /**
     * Spor Vipps hvordan trekkene vi ba om gikk.
     *
     * Stod inne i trekkrunden, og gikk derfor bare én gang i dognet. Trekket
     * Vipps tar naar kunden godkjenner avtalen fores hos oss med status
     * «venter» — vi har ingen referanse paa det for vi sporr (se
     * foerForsteTrekk). Linja i admin sto dermed som ubetalt i opptil et
     * dogn etter at pengene faktisk var tatt.
     *
     * Eieren, 8. september 2026, med Lene paa traaden: «naa staar det i vipps
     * appen hennes ogsaa, eneste som ikke er oppdatert er paa medlemsiden,
     * staar fortsatt som ubetalt». Han valgte «hvert tiende minutt».
     *
     * Aa SPORRE er ikke aa TREKKE. Denne henter bare status, og radene faller
     * ut av «trekkUtenSvar» i det Vipps svarer — saa lista er normalt tom.
     * Selve trekkingen ligger fortsatt i runden, én gang i dognet.
     *
     * @return int hvor mange som fikk et endelig svar
     */
    public static function sjekkAlleTrekk(int $maks = 50, ?callable $skriv = null): int
    {
        $svart = 0;
        foreach (self::trekkUtenSvar($maks) as $p) {
            try {
                $utfall = self::sjekkTrekk($p);
                if ($skriv !== null) {
                    $skriv('  trekk ' . $p['id'] . ' (' . ($p['navn'] ?? '') . '): ' . $utfall);
                }
                if ($utfall === 'betalt' || $utfall === 'failed' || $utfall === 'cancelled') {
                    $svart++;
                }
            } catch (Throwable $e) {
                logg_feil('Statusoppslag feilet for trekk ' . $p['id'], $e);
            }
            // Ett halvt sekund per oppslag, som resten av runden: Vipps skal
            // ikke merke at vi sporr oftere.
            usleep(300_000);
        }
        return $svart;
    }

    /**
     * Hele trekkrunden: godkjenninger, purringer, trekk, avslutninger, svar.
     *
     * Denne stod som en «case» i bin/cron.php, og bin/cron.php var det eneste
     * som kjorte den. Jobben stod aldri i docs/OPPSETT.md — den lista har fem
     * jobber, og «medlemstrekk» er ikke én av dem. Da ble den aldri satt opp i
     * cPanel, og ingen ble noen gang trukket.
     *
     * Eieren, 5. september: «Eirin og Lene har ikke faatt opprettet noen avtale
     * i vipps, dette fungerer ikke». Maalt: avtalen VAR opprettet og godkjent.
     * Det som manglet var noen som kjorte denne runden.
     *
     * Naa staar den her, og to veier fører hit — cron om den settes opp, og
     * trafikken paa sida om den ikke er det (se Tikk::kjor). Én utgave, ikke
     * to: kjores begge, finner den andre «alt fort» paa idempotensnokkelen.
     *
     * Rekkefolgen er ikke tilfeldig. Godkjenningene foerst, saa purringene,
     * saa trekkene — en som nettopp ble aktivert skal trekkes i den samme
     * runden, ikke vente et dogn til.
     *
     * @param  ?callable $si  Skriver en linje per rad. Cron gir en; trafikken
     *                        gir ingen, for der er det ingen som ser paa.
     * @return array{sjekket:int,paaminnet:int,trukket:int,feilet:int,avsluttet:int,gjort_opp:int,forste_foert:int}
     */
    public static function kjorTrekkrunde(?callable $si = null): array
    {
        $skriv = $si ?? static function (string $t): void {};

        // ── Foerst: hvem er blitt godkjent siden sist ────────────────────
        //
        // Kunden kan ha godkjent avtalen i Vipps-appen uten aa komme tilbake
        // til nettsiden. Da staar raden vaar paa «venter» til vi sporr Vipps,
        // og det er dette som gjor det.
        //
        // Sju dager sto her en gang. Den grensa gjorde at en avtale som ble
        // liggende i aatte dager aldri ble sett paa igjen. Nitti dager i
        // stedet: ett oppslag per rad per runde, men en ubetalt avtale
        // koster mer.
        $venter = DB::alle(
            "SELECT * FROM subscriptions
              WHERE status = 'venter'
                AND vipps_agreement_id IS NOT NULL
                AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)"
        );
        foreach ($venter as $a) {
            self::oppdaterFraVipps($a);
            usleep(200_000);
        }

        // L-6: foerste trekk som ikke ble foert fordi oppslaget feilet da
        // avtalen ble aktiv, proeves igjen her.
        $forsteFoert = 0;
        try {
            $forsteFoert = self::foerManglendeForsteTrekk();
            if ($forsteFoert > 0) {
                $skriv('  foerste trekk foert paa nytt: ' . $forsteFoert);
            }
        } catch (Throwable $e) {
            logg_feil('Foerte ikke manglende foerste trekk', $e);
        }

        // ── Paaminnelsen: avtalen venter paa deg ────────────────────────
        //
        // Fast trekk i Vipps er en fullmakt kunden maa gi i appen. Lukker hun
        // sida foer hun har gjort det, er avtalen ikke gyldig — og lenka laa
        // bare i basen. Ingen fikk den, og ingen purret.
        //
        // Purringa paa godkjenningslenka sto her. Den lenka finnes ikke
        // lenger — eieren, 7. september 2026: «du skal rive ut og bygge
        // avtale vipssen paa nytt». Avtalen lages i det medlemmet trykker, og
        // hun sendes rett til Vipps. Er det ingen lenke som ligger ute, er
        // det heller ingenting aa purre paa.
        $paaminnet = 0;

        // ── Saa: trekk alle som er forfalt, de nettopp aktiverte med ─────
        $gjort = 0;
        $feilet = 0;
        foreach (self::tilTrekk() as $a) {
            try {
                $svar = self::trekk($a);
                $skriv('  ' . $a['navn'] . ' (' . $a['plan'] . '): ' . $svar);
                $gjort++;
            } catch (Throwable $e) {
                logg_feil('Medlemstrekk feilet for avtale ' . $a['id'], $e);
                $skriv('  ' . $a['navn'] . ': FEILET — ' . $e->getMessage());
                $feilet++;
            }
            usleep(300_000);
        }

        // Oppsigelsestida ute: her stoppes avtalen i Vipps og tilgangen tas
        // bort. Selve oppsigelsen setter bare sluttdatoen — medlemmet har
        // betalt for maaneden, og skal ha den.
        $avsluttet = 0;
        foreach (self::tilAvslutning() as $a) {
            self::avslutt($a);
            $avsluttet++;
            usleep(200_000);
        }

        // Hvordan gikk trekkene vi ba om?
        //
        // Trekket bes om noen dager fram i tid, saa svaret kommer ikke samme
        // runde. Her sporr vi om dem vi ikke har faatt svar paa enda. Foer
        // dette ble hver eneste rad staaende paa «venter» for alltid — og de
        // to malene «Medlemskapet ditt er fornyet» og «Vi fikk ikke trukket
        // betalingen» ble aldri sendt til noen.
        $svart = self::sjekkAlleTrekk(50, $skriv);

        if ($gjort > 0 || $feilet > 0 || $avsluttet > 0 || $svart > 0 || $forsteFoert > 0) {
            logg('Medlemstrekk kjort', ['trukket' => $gjort, 'feilet' => $feilet,
                                        'avsluttet' => $avsluttet, 'gjort_opp' => $svart, 'forste_foert' => $forsteFoert]);
        }

        return [
            'sjekket'   => count($venter),
            'paaminnet' => $paaminnet,
            'trukket'   => $gjort,
            'feilet'    => $feilet,
            'avsluttet' => $avsluttet,
            'gjort_opp' => $svart,
            'forste_foert' => $forsteFoert,
        ];
    }

    private static function norskDag(string $dato): string
    {
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];
        $d = new DateTimeImmutable($dato);
        return (int) $d->format('j') . '. ' . $mnd[(int) $d->format('n') - 1];
    }
}
