<?php
/**
 * Tillegg paa medlemskapet: «Ta med barn».
 *
 * Eieren, 15. september 2026. Ett barn (opptil 12 aar) per tillegg, per
 * kalendermaaned, betalt med Vipps foer det gjelder. Vilkaarene aksepteres
 * ved kjoepet; barnets navn og alder skrives inn saa verkstedet vet hvem.
 *
 * Kjoepet er en vanlig ordre (orders + payments). Rada i medlem_tillegg
 * sier hva ordren gjaldt og staar «venter» til Booking::markerBetalt() sier
 * fra — da blir den «aktiv». Prisen ligger i innstillinger.
 */

declare(strict_types=1);

final class Tillegg
{
    public const MAKS_ALDER = 12;

    /** Fra denne dagen i maaneden kan neste maaned kjoepes paa forskudd. */
    private const FORSKUDD_FRA_DAG = 25;

    public static function klar(): bool
    {
        return DB::harTabell('medlem_tillegg');
    }

    /** Bryteren ⊙ Synlighet → Paa Min side → Ta med barn. Mangler raden, er den paa. */
    public static function paa(): bool
    {
        return (string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/tilleggbarn'") !== 'nei';
    }

    public static function prisOre(): int
    {
        if (!DB::harTabell('innstillinger')) {
            return 0;
        }
        return (int) DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'tillegg_barn_pris_ore'");
    }

    private static function oslo(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo'));
    }

    /** «2026-09» */
    public static function maanedNaa(): string
    {
        return self::oslo()->format('Y-m');
    }

    /** «september» / «september 2027» naar det er et annet aar. */
    public static function maanedNavn(string $maaned): string
    {
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];
        [$aar, $m] = array_map('intval', explode('-', $maaned));
        $navn = $mnd[max(0, min(11, $m - 1))];
        return (int) self::oslo()->format('Y') === $aar ? $navn : $navn . ' ' . $aar;
    }

    /** «sep» */
    public static function maanedKort(string $maaned): string
    {
        return mb_substr(self::maanedNavn($maaned), 0, 3);
    }

    /**
     * Maanedene som kan kjoepes naa: denne, og fra den 25. ogsaa neste.
     * @return list<string>
     */
    public static function kjopbare(): array
    {
        $naa = self::oslo();
        $ut = [$naa->format('Y-m')];
        if ((int) $naa->format('j') >= self::FORSKUDD_FRA_DAG) {
            $ut[] = $naa->modify('first day of next month')->format('Y-m');
        }
        return $ut;
    }

    /**
     * Det aktive tillegget for en maaned, eller null.
     * @return array<string,mixed>|null
     */
    public static function aktivt(int $medlemId, ?string $maaned = null): ?array
    {
        if (!self::klar()) {
            return null;
        }
        return DB::en(
            "SELECT * FROM medlem_tillegg WHERE member_id = :m AND maaned = :mnd AND status = 'aktiv' ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId, 'mnd' => $maaned ?? self::maanedNaa()]
        );
    }

    /**
     * Aktive tillegg for alle medlemmer denne maaneden, til medlemslista.
     * @return array<int,array<string,mixed>> member_id => rad
     */
    public static function aktiveNaa(): array
    {
        if (!self::klar()) {
            return [];
        }
        $ut = [];
        foreach (DB::alle(
            "SELECT * FROM medlem_tillegg WHERE status = 'aktiv' AND maaned >= :mnd ORDER BY maaned",
            ['mnd' => self::maanedNaa()]
        ) as $r) {
            $ut[(int) $r['member_id']] ??= $r;
        }
        return $ut;
    }

    /**
     * Betalingen kom inn: tillegget paa ordren blir aktivt.
     * Kalles fra Booking::markerBetalt(), inne i transaksjonen.
     */
    public static function aktiverForOrdre(int $ordreId): void
    {
        if (!self::klar()) {
            return;
        }
        DB::kjor(
            "UPDATE medlem_tillegg SET status = 'aktiv', betalt_at = UTC_TIMESTAMP()
              WHERE order_id = :o AND status = 'venter'",
            ['o' => $ordreId]
        );
    }

    /** Ordren ble avbrutt eller feilet: rada staar ikke som venter lenger. */
    public static function avbrytForOrdre(int $ordreId): void
    {
        if (!self::klar()) {
            return;
        }
        DB::kjor("UPDATE medlem_tillegg SET status = 'avbrutt' WHERE order_id = :o AND status = 'venter'", ['o' => $ordreId]);
    }

    /**
     * Rada slik skjermene leser den.
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    public static function ut(array $t): array
    {
        return [
            'id'        => (int) $t['id'],
            'maaned'    => (string) $t['maaned'],
            'maanedNavn' => self::maanedNavn((string) $t['maaned']),
            'maanedKort' => self::maanedKort((string) $t['maaned']),
            'barnNavn'  => (string) ($t['barn_navn'] ?? ''),
            'barnAlder' => $t['barn_alder'] === null ? null : (int) $t['barn_alder'],
            'status'    => (string) $t['status'],
            'pris'      => Booking::kroner((int) $t['pris_ore']),
            'betalt'    => $t['betalt_at'] !== null ? Booking::norskDatoKort((string) $t['betalt_at']) : '',
            'vilkaar'   => Booking::norskDatoKort((string) $t['vilkaar_akseptert_at']),
        ];
    }
}
