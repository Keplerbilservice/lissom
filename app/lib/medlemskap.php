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
    /** Sa mange dager for forfall ber vi Vipps om trekket. */
    private const VARSEL_DAGER = 3;

    /** @return array<string,mixed>|null */
    public static function plan(string $navn): ?array
    {
        return DB::en('SELECT * FROM membership_plans WHERE navn = :n AND aktiv = 1', ['n' => $navn]);
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
        $plan = self::plan($type);
        return $plan === null || $plan['timer'] === null ? null : (int) $plan['timer'];
    }

    /** Avtalen et medlem har naa, eller null. */
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
     * Starter en avtale i Vipps og lagrer den som «venter».
     *
     * Den blir ikke aktiv her. Det skjer forst naar kunden har godkjent i
     * Vipps og vi har spurt Vipps om status — vi stoler ikke paa at kunden
     * kom tilbake til riktig side.
     *
     * @return array{url:string,id:int}
     */
    public static function startAvtale(array $medlem, string $planNavn): array
    {
        $plan = self::plan($planNavn);
        if ($plan === null) {
            throw new RuntimeException('Ukjent medlemskap.');
        }

        // Har medlemmet en avtale fra for, skal den ikke bli staaende ved
        // siden av den nye. Da ville de blitt trukket to ganger.
        $fra = self::avtale((int) $medlem['id']);
        if ($fra !== null && $fra['status'] === 'aktiv') {
            throw new RuntimeException('Du har alt et medlemskap. Si det opp først, eller bytt fra Min side.');
        }

        $vipps = Vipps::opprettAvtale(
            $planNavn,
            (int) $plan['pris_ore'],
            'Medlemskap hos Lissom Keramikk — ' . $planNavn,
            Config::nettsted() . '/api/vipps-avtale-retur.php',
            $medlem['telefon'] ?? null
        );

        if ($vipps['avtaleId'] === '' || $vipps['url'] === '') {
            throw new RuntimeException('Vipps ga ikke noen avtale tilbake.');
        }

        $binding = (int) $plan['binding_mnd'];

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

        return ['url' => $vipps['url'], 'id' => $id];
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
        $fra = self::avtale((int) $medlem['id']);
        if ($fra !== null && $fra['status'] === 'aktiv') {
            throw new RuntimeException('Du har alt et medlemskap. Si det opp først, eller bytt fra Min side.');
        }

        $binding = (int) $plan['binding_mnd'];
        $id = DB::settInn('subscriptions', [
            'member_id'          => (int) $medlem['id'],
            'plan'               => $planNavn,
            'pris_ore'           => (int) $plan['pris_ore'],
            'vipps_agreement_id' => '',
            'status'             => 'venter',
            'binding_til'        => $binding > 0
                ? (new DateTimeImmutable('now'))->modify('+' . $binding . ' months')->format('Y-m-d')
                : null,
        ]);

        $referanse = Vipps::nyReferanse('MED');
        $betalingId = DB::settInn('payments', [
            'vipps_reference' => $referanse,
            'type'            => 'epayment',
            'formal'          => 'medlemskap',
            'member_id'       => (int) $medlem['id'],
            'subscription_id' => $id,
            'belop_ore'       => (int) $plan['pris_ore'],
            'status'          => 'opprettet',
        ]);

        try {
            $betaling = Vipps::opprettBetaling(
                $referanse,
                (int) $plan['pris_ore'],
                'Medlemskap hos Lissom — ' . $planNavn,
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
        return ['url' => $betaling['url'], 'id' => $id];
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
        DB::oppdater('members', [
            'status'          => 'aktiv',
            'medlemskap_type' => (string) $a['plan'],
            'start_dato'      => DB::verdi('SELECT start_dato FROM members WHERE id = :m', ['m' => (int) $a['member_id']])
                                  ?: gmdate('Y-m-d'),
        ], ['id' => (int) $a['member_id']]);
    }

    /**
     * Spor Vipps om status og setter avtalen deretter.
     *
     * Kalles bade naar kunden kommer tilbake og fra cron. Den er trygg aa
     * kjore flere ganger.
     */
    public static function oppdaterFraVipps(array $avtale): string
    {
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

        // Ligger det en soknad til behandling, holdes trekket igjen.
        //
        // Soknaden oppretter avtalen med det samme, saa ingen kommer inn uten
        // aa ha betalingen paa plass. Men verkstedet skal fortsatt kunne si
        // nei — og da skal ingen ha blitt trukket. Forste trekk slippes av
        // godkjenningen i admin, ikke av at kunden trykket ja i Vipps.
        // Bare soknader som faktisk venter paa en avtale. En gammel soknad
        // der medlemmet gjor opp selv skal ikke holde igjen noe.
        $harKol = DB::harKolonne('membership_applications', 'betaling');
        $venterSvar = (int) DB::verdi(
            "SELECT COUNT(*) FROM membership_applications
              WHERE member_id = :m AND status = 'venter'"
            . ($harKol ? " AND betaling = 'trekk'" : ''),
            ['m' => (int) $avtale['member_id']]
        ) > 0;

        // Forste trekk settes naar avtalen blir aktiv. Vi trekker fra dagen
        // etter godkjenning, ikke fra den 1. — da slipper vi aa forklare
        // hvorfor noen betaler full pris for en halv maaned.
        if ($ny === 'aktiv' && $avtale['neste_trekk'] === null && !$venterSvar) {
            $endring['neste_trekk'] = (new DateTimeImmutable('now'))->format('Y-m-d');
        }
        if ($ny !== 'aktiv') {
            $endring['neste_trekk'] = null;
        }

        DB::oppdater('subscriptions', $endring, ['id' => (int) $avtale['id']]);

        // Medlemsstatusen folger avtalen. Uten dette ville noen betalt uten aa
        // faa tilgang, eller hatt tilgang uten aa betale.
        if ($ny === 'aktiv' && !$venterSvar) {
            DB::oppdater('members', [
                'status'          => 'aktiv',
                'medlemskap_type' => $avtale['plan'],
                'start_dato'      => DB::verdi('SELECT start_dato FROM members WHERE id = :m', ['m' => $avtale['member_id']])
                                      ?: gmdate('Y-m-d'),
            ], ['id' => (int) $avtale['member_id']]);
        } elseif (in_array($ny, ['stoppet', 'utlopt'], true)) {
            DB::oppdater('members', ['status' => 'oppsagt'], ['id' => (int) $avtale['member_id']]);
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

        $a = DB::en('SELECT * FROM subscriptions WHERE id = :id', ['id' => (int) $a['id']]);
        if ($a !== null && $a['neste_trekk'] === null) {
            DB::oppdater('subscriptions', [
                'neste_trekk' => (new DateTimeImmutable('now'))->format('Y-m-d'),
            ], ['id' => (int) $a['id']]);
        }
        return ['status' => 'aktiv', 'avtale' => $a];
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
        $binding = $avtale['binding_til'] ?? null;
        if ($binding !== null && (string) $binding >= gmdate('Y-m-d')) {
            $plan = self::plan((string) $avtale['plan']);
            $aar = $plan !== null && (int) $plan['binding_mnd'] >= 12;
            return ($aar
                ? 'Årsavtalen kan ikke sies opp før året er ute. Den løper til '
                : 'Medlemskapet er bundet til ')
                . Booking::norskDatoKort((string) $binding . ' 12:00:00')
                . '. Ta kontakt om noe har endret seg, så finner vi ut av det.';
        }
        return null;
    }

    /** Siste dag et medlemskap gjelder naar det sies opp i dag. */
    public static function sluttdato(array $avtale): string
    {
        $plan = self::plan((string) $avtale['plan']);
        $mnd = $plan === null ? 1 : max(0, (int) ($plan['oppsigelse_mnd'] ?? 1));
        return (new DateTimeImmutable('now'))->modify('+' . $mnd . ' months')->format('Y-m-d');
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
     * @return list<array<string,mixed>>
     */
    public static function tilAvslutning(): array
    {
        return DB::alle(
            "SELECT * FROM subscriptions
              WHERE slutter IS NOT NULL AND slutter <= CURDATE()
                AND status <> 'stoppet'"
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
        DB::oppdater('members', ['status' => 'oppsagt'], ['id' => (int) $avtale['member_id']]);
    }

    /**
     * Avtaler som skal belastes naa. Kjores av cron.
     *
     * @return list<array<string,mixed>>
     */
    public static function tilTrekk(): array
    {
        return DB::alle(
            "SELECT s.*, m.navn, m.epost, m.telefon
               FROM subscriptions s
               JOIN members m ON m.id = s.member_id
              WHERE s.status = 'aktiv'
                AND s.neste_trekk IS NOT NULL
                AND s.neste_trekk <= CURDATE()
                -- Er det sagt opp, trekkes det ikke for en periode som
                -- begynner etter siste dag.
                AND (s.slutter IS NULL OR s.neste_trekk <= s.slutter)
                AND m.anonymisert_at IS NULL"
        );
    }

    /**
     * Ber Vipps om ett trekk, og fører det som en betaling.
     *
     * Idempotensnokkelen bygges av avtalen og maaneden. Kjorer cron to ganger
     * samme natt, ber vi Vipps om det samme trekket — og Vipps gjor det én
     * gang.
     */
    public static function trekk(array $avtale): string
    {
        $maaned = (new DateTimeImmutable((string) $avtale['neste_trekk']))->format('Y-m');
        $nokkel = substr(hash('sha256', 'trekk:' . $avtale['id'] . ':' . $maaned), 0, 36);

        // Er trekket alt fort, gjor vi ikke noe mer. Uten denne kunne en
        // halvveis kjoring gitt to rader i payments for samme maaned.
        $fra = DB::en(
            "SELECT id FROM payments WHERE subscription_id = :s AND idempotency_key = :k",
            ['s' => (int) $avtale['id'], 'k' => $nokkel]
        );
        if ($fra !== null) {
            return 'alt fort';
        }

        $forfall = (new DateTimeImmutable('now'))->modify('+' . self::VARSEL_DAGER . ' days')->format('Y-m-d');
        $referanse = Vipps::nyReferanse('MED');

        $betalingId = DB::settInn('payments', [
            'vipps_reference' => $referanse,
            'type'            => 'recurring_charge',
            'formal'          => 'medlemskap',
            'member_id'       => (int) $avtale['member_id'],
            'subscription_id' => (int) $avtale['id'],
            'belop_ore'       => (int) $avtale['pris_ore'],
            'status'          => 'opprettet',
            'idempotency_key' => $nokkel,
        ]);

        try {
            Vipps::belastAvtale(
                (string) $avtale['vipps_agreement_id'],
                (int) $avtale['pris_ore'],
                'Medlemskap ' . $avtale['plan'],
                $forfall,
                $nokkel
            );
        } catch (Throwable $e) {
            DB::oppdater('payments', ['status' => 'feilet'], ['id' => $betalingId]);
            logg_feil('Trekk feilet for avtale ' . $avtale['id'], $e);
            throw $e;
        }

        DB::oppdater('payments', ['status' => 'venter'], ['id' => $betalingId]);

        // Neste trekk en maaned fram. Er avtalen en proveperiode, er dette
        // det eneste trekket — da stopper vi den etterpaa.
        $plan = self::plan((string) $avtale['plan']);
        $engangs = $plan !== null && (int) $plan['engangs'] === 1;

        DB::oppdater('subscriptions', [
            'siste_trekk' => (string) $avtale['neste_trekk'],
            'neste_trekk' => $engangs ? null
                : (new DateTimeImmutable((string) $avtale['neste_trekk']))->modify('+1 month')->format('Y-m-d'),
        ], ['id' => (int) $avtale['id']]);

        // Vipps krever at kunden vet om trekket for det skjer.
        if (!empty($avtale['epost'])) {
            Varsel::epost(
                (string) $avtale['epost'],
                'Trekk for medlemskapet ditt',
                'Hei ' . $avtale['navn'] . "!\n\n"
                . Booking::kroner((int) $avtale['pris_ore']) . ' for medlemskapet «' . $avtale['plan']
                . '» trekkes i Vipps ' . self::norskDag($forfall) . ".\n\n"
                . "Du kan si opp når som helst fra Min side, eller i Vipps-appen.",
                'medlemskap',
                $betalingId
            );
        }

        return 'bedt om trekk til ' . $forfall;
    }

    private static function norskDag(string $dato): string
    {
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];
        $d = new DateTimeImmutable($dato);
        return (int) $d->format('j') . '. ' . $mnd[(int) $d->format('n') - 1];
    }
}
