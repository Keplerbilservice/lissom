<?php
/**
 * Frys av medlemskap.
 *
 * Medlemmet soker om en pause, verkstedet svarer, og perioden staar med
 * start og slutt. Naar slutten er passert, aapner medlemskapet seg igjen av
 * seg selv — det skjer her, ikke i en cron-jobb, av samme grunn som
 * artiklene publiseres naar noen ber om dem: en linje i cPanel som ingen har
 * lagt inn, er en funksjon som ikke virker.
 *
 * Vipps-avtalen stoppes ikke herfra. Trekkrunden hopper over en maaned en
 * godkjent frys dekker minst 15 dager av (Medlemskap::hoppOverPause), og
 * trekkene fortsetter av seg selv etterpaa (L-10, 2. oktober 2026).
 */

declare(strict_types=1);

final class Frys
{
    /** Lengst mulig frys, i dager. */
    public const MAKS_DAGER = 186;

    public static function klar(): bool
    {
        return DB::harTabell('medlem_frys');
    }

    /**
     * Aapner medlemskapene der frysen er over.
     *
     * Kalles fra endepunktene som leser frys. Kjorer den to ganger samtidig,
     * gjor den andre ingenting: betingelsen i WHERE er allerede falsk.
     */
    public static function gjenapneForfalte(): void
    {
        if (!self::klar()) {
            return;
        }
        $ferdige = DB::alle(
            "SELECT id, member_id, status_for FROM medlem_frys
              WHERE status = 'godkjent' AND til_dato < CURDATE()"
        );
        foreach ($ferdige as $f) {
            // Tilbake til den statusen medlemmet hadde for frysen. Sto det
            // som «oppsagt» eller «ingen» da, skal det ikke bli aktivt av at
            // en frys tok slutt.
            $tilbake = in_array((string) ($f['status_for'] ?? ''), ['prove', 'aktiv'], true)
                ? (string) $f['status_for'] : 'aktiv';
            $naa = DB::en('SELECT status FROM members WHERE id = :i', ['i' => (int) $f['member_id']]);
            if ($naa !== null && (string) $naa['status'] === 'pause') {
                DB::oppdater('members', ['status' => $tilbake], ['id' => (int) $f['member_id']]);
            }
            DB::oppdater('medlem_frys', ['status' => 'avsluttet'], ['id' => (int) $f['id']]);
        }
    }

    /**
     * Setter medlemskapet i pause naar en godkjent frys naar startdagen sin.
     *
     * En frys kan soekes om paa forhaand — «jeg er bortreist hele juli» — og
     * godkjennes i mai. Da skal ikke medlemskapet stenge seg i mai. Pausen
     * settes den dagen frysen begynner, og aapnes igjen av gjenapneForfalte()
     * naar den er over.
     */
    public static function startForfalte(): void
    {
        if (!self::klar()) {
            return;
        }
        // Bare et medlemskap som loeper, kan settes paa pause. Er medlemmet
        // sagt opp mellom godkjenningen og startdagen, skal frysen ikke gjoere
        // det til «pause» — da ville gjenapneForfalte() aapnet det igjen som
        // aktivt naar perioden var over (Codex-gjennomgangen 30.09.2026).
        $begynt = DB::alle(
            "SELECT f.id, f.member_id, m.status AS medlem_status
               FROM medlem_frys f
               JOIN members m ON m.id = f.member_id
              WHERE f.status = 'godkjent'
                AND f.fra_dato <= CURDATE() AND f.til_dato >= CURDATE()
                AND m.status IN ('aktiv', 'prove')"
        );
        foreach ($begynt as $f) {
            // Statusen akkurat naa er den medlemmet skal tilbake til.
            DB::oppdater('medlem_frys', ['status_for' => (string) $f['medlem_status']], ['id' => (int) $f['id']]);
            DB::oppdater('members', ['status' => 'pause'], ['id' => (int) $f['member_id']]);
        }
    }

    /** Begge veier paa én gang. Kalles der frys leses. */
    public static function ajour(): void
    {
        self::startForfalte();
        self::gjenapneForfalte();
    }

    /**
     * Frysen som gjelder naa for et medlem, eller null.
     *
     * «Gjelder naa» er enten en soknad som venter paa svar, eller en godkjent
     * periode som ikke er over. En avslaatt eller ferdig frys er historikk.
     *
     * @return array<string,mixed>|null
     */
    public static function gjeldende(int $medlemId): ?array
    {
        if (!self::klar()) {
            return null;
        }
        return DB::en(
            "SELECT * FROM medlem_frys
              WHERE member_id = :m
                AND (status = 'sokt' OR (status = 'godkjent' AND til_dato >= CURDATE()))
           ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId]
        );
    }

    /**
     * Er medlemmet fryst i dag? Gir ['til' => Y-m-d|null], ellers null.
     *
     * Eieren, 2. oktober 2026: et fryst medlem med en betalt periode kom inn
     * og kunne stemple inn og booke medlemstid, fordi tilgangen bare sjekket
     * betalingen. Fryst er en godkjent frys som dekker dagen i dag — uansett
     * om members.status alt er satt til «pause» (startForfalte() kjoeres
     * bare der frys leses). Er frysen over, er medlemmet ikke fryst, selv om
     * statusen ikke er satt tilbake ennaa: perioden etter frysen skal virke
     * som foer. Og: stengingen starter foerst naar den betalte perioden er
     * over (se under). «pause» satt for haand, uten en godkjent frys bak seg, er
     * ikke fryst og beholder oppfoerselen fra foer (kontrolloeren, 2. oktober
     * 2026).
     *
     * @param array<string,mixed> $medlem
     * @return array{til:string}|null
     */
    public static function frystNaa(array $medlem, ?string $idag = null): ?array
    {
        $id = (int) ($medlem['id'] ?? 0);
        if ($id <= 0 || !self::klar()) {
            return null;
        }
        $idag ??= (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
        $til = DB::verdi(
            "SELECT MAX(til_dato) FROM medlem_frys
              WHERE member_id = :m AND status = 'godkjent'
                AND fra_dato <= :d1 AND til_dato >= :d2",
            ['m' => $id, 'd1' => $idag, 'd2' => $idag]
        );
        if ($til === null || (string) $til === '') {
            return null;
        }
        // Eieren, 2. oktober 2026: stengingen starter foerst naar den betalte
        // perioden er over. Dager medlemmet har betalt for, har det alltid
        // tilgang i — ogsaa med en godkjent frys. Samme periodelogikk som
        // tilgangen (Medlemskap::harBetaltPeriode). Et gratismedlem
        // (betaler_ikke) har ikke betalt for noen dager, og er fryst.
        $betalt = $medlem;
        $betalt['betaler_ikke'] = 0;
        if (Medlemskap::harBetaltPeriode($betalt, $idag)) {
            return null;
        }
        return ['til' => substr((string) $til, 0, 10)];
    }

    /** @return list<array<string,mixed>> */
    public static function forMedlem(int $medlemId): array
    {
        if (!self::klar()) {
            return [];
        }
        return DB::alle(
            'SELECT * FROM medlem_frys WHERE member_id = :m ORDER BY id DESC LIMIT 20',
            ['m' => $medlemId]
        );
    }

    /** Raden slik den skal se ut for den som leser den. */
    public static function ut(array $f): array
    {
        return [
            'id'          => (int) $f['id'],
            'fra'         => Booking::norskDatoKort((string) $f['fra_dato']),
            'til'         => Booking::norskDatoKort((string) $f['til_dato']),
            'fraIso'      => (string) $f['fra_dato'],
            'tilIso'      => (string) $f['til_dato'],
            'dager'       => self::dager((string) $f['fra_dato'], (string) $f['til_dato']),
            'begrunnelse' => (string) ($f['begrunnelse'] ?? ''),
            'status'      => (string) $f['status'],
            'merke'       => self::merke((string) $f['status']),
            'svar'        => (string) ($f['svar'] ?? ''),
            'sokt'        => Booking::norskDatoKort((string) $f['created_at']),
        ];
    }

    public static function dager(string $fra, string $til): int
    {
        $a = new DateTimeImmutable($fra);
        $b = new DateTimeImmutable($til);
        return (int) $a->diff($b)->days + 1;
    }

    public static function merke(string $status): string
    {
        return [
            'sokt'      => 'Venter på svar',
            'godkjent'  => 'Godkjent',
            'avslatt'   => 'Ikke godkjent',
            'trukket'   => 'Trukket tilbake',
            'avsluttet' => 'Avsluttet',
        ][$status] ?? $status;
    }
}
