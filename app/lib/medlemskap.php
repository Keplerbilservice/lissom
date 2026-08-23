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

        // Forste trekk settes naar avtalen blir aktiv. Vi trekker fra dagen
        // etter godkjenning, ikke fra den 1. — da slipper vi aa forklare
        // hvorfor noen betaler full pris for en halv maaned.
        if ($ny === 'aktiv' && $avtale['neste_trekk'] === null) {
            $endring['neste_trekk'] = (new DateTimeImmutable('now'))->format('Y-m-d');
        }
        if ($ny !== 'aktiv') {
            $endring['neste_trekk'] = null;
        }

        DB::oppdater('subscriptions', $endring, ['id' => (int) $avtale['id']]);

        // Medlemsstatusen folger avtalen. Uten dette ville noen betalt uten aa
        // faa tilgang, eller hatt tilgang uten aa betale.
        if ($ny === 'aktiv') {
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

    /** Sier opp. Avtalen stoppes i Vipps, saa ingen flere trekk kommer. */
    public static function siOpp(array $avtale): void
    {
        if ($avtale['vipps_agreement_id']) {
            Vipps::stoppAvtale((string) $avtale['vipps_agreement_id']);
        }
        DB::oppdater('subscriptions', [
            'status'      => 'stoppet',
            'neste_trekk' => null,
            'sagt_opp_at' => gmdate('Y-m-d H:i:s'),
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
