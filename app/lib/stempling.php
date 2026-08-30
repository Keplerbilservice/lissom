<?php
/**
 * Innstempling i verkstedet, og timene den trekker.
 *
 * Medlemskapet gir et antall timer i maaneden. En okt starter ved
 * innstempling og slutter ved utstempling; minuttene legges sammen per
 * kalendermaaned i norsk tid.
 *
 * To ting maa loses for at tallet skal vaere til aa stole paa:
 *
 *   1. Glemt utstempling. Noen gaar hjem uten aa stemple ut. Da ville okta
 *      staatt aapen i det uendelige og spist hele maaneden. Okter som har
 *      staatt lenger enn MAKS_OKT lukkes automatisk paa den lengden, og
 *      merkes, saa verkstedet ser at det skjedde.
 *
 *   2. Dobbel innstempling. Ett medlem kan bare ha én aapen okt. Den sperren
 *      ligger i transaksjonen, ikke i skjemaet.
 */

declare(strict_types=1);

final class Stempling
{
    /** Lengste okt som telles naar noen glemmer aa stemple ut. */
    private const MAKS_OKT_MIN = 6 * 60;

    /** Etter saa lenge regnes okta som glemt, og lukkes ved neste oppslag. */
    private const GLEMT_ETTER_MIN = 10 * 60;

    private static function oslo(): DateTimeZone
    {
        return new DateTimeZone('Europe/Oslo');
    }

    private static function naa(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Forste sekund av inneverende maaned, i UTC.
     *
     * Grensa maa folge norsk kalender: klokka 00.30 den forste er fortsatt
     * forrige maaned i UTC, og timene ville havnet feil.
     */
    public static function manedStart(): string
    {
        return (new DateTimeImmutable('now', self::oslo()))
            ->modify('first day of this month')->setTime(0, 0)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Lukker okter som har staatt aapne for lenge.
     *
     * Kalles for hvert oppslag. Uten dette ville «i verkstedet naa» vist folk
     * som gikk hjem i forrige uke.
     */
    public static function lukkGlemte(): int
    {
        return DB::kjor(
            'UPDATE check_ins
                SET ut_tid = DATE_ADD(inn_tid, INTERVAL :maks MINUTE),
                    minutter = :maks2,
                    auto_lukket = 1
              WHERE ut_tid IS NULL
                AND inn_tid < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :glemt MINUTE)',
            ['maks' => self::MAKS_OKT_MIN, 'maks2' => self::MAKS_OKT_MIN, 'glemt' => self::GLEMT_ETTER_MIN]
        )->rowCount();
    }

    /** Den aapne okta til et medlem, eller null. */
    public static function apenOkt(int $medlemId): ?array
    {
        return DB::en(
            'SELECT id, inn_tid FROM check_ins WHERE member_id = :m AND ut_tid IS NULL ORDER BY id DESC LIMIT 1',
            ['m' => $medlemId]
        );
    }

    /** Brukte minutter denne maaneden, inkludert okta som paagaar naa. */
    public static function minutterDenneManeden(int $medlemId): int
    {
        $fra = self::manedStart();

        $ferdige = (int) DB::verdi(
            'SELECT COALESCE(SUM(minutter), 0) FROM check_ins
              WHERE member_id = :m AND ut_tid IS NOT NULL AND inn_tid >= :fra',
            ['m' => $medlemId, 'fra' => $fra]
        );

        $paagaar = (int) DB::verdi(
            'SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, inn_tid, UTC_TIMESTAMP())), 0) FROM check_ins
              WHERE member_id = :m AND ut_tid IS NULL AND inn_tid >= :fra',
            ['m' => $medlemId, 'fra' => $fra]
        );

        return max(0, $ferdige + $paagaar);
    }

    /**
     * Stempler inn. Returnerer okt-id.
     *
     * Hele sjekken ligger inne i transaksjonen: to raske trykk etter hverandre
     * skal ikke gi to aapne okter.
     */
    /**
     * @param int $ressursId Hva medlemmet skal bruke — dreieskive eller
     *                       verkstedplass. 0 naar det ikke er oppgitt.
     *
     * Eieren, 30. august: «kunne det voere lost om de booker inn og velger
     * dreieskive, eller verkstedplass». For dette gjettet regnestykket at
     * enhver innstemplet sto ved en skive; naa sier medlemmet det selv.
     *
     * Staar man alt inne, endres valget. Det er billigere enn aa stemple ut
     * og inn igjen for aa flytte seg fra bordet til skiva.
     */
    public static function inn(int $medlemId, int $ressursId = 0): int
    {
        return DB::iTransaksjon(static function () use ($medlemId, $ressursId): int {
            $apen = DB::en(
                'SELECT id FROM check_ins WHERE member_id = :m AND ut_tid IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE',
                ['m' => $medlemId]
            );
            $har = DB::harKolonne('check_ins', 'ressurs_id');
            if ($apen !== null) {
                if ($har && $ressursId > 0) {
                    DB::oppdater('check_ins', ['ressurs_id' => $ressursId], ['id' => (int) $apen['id']]);
                }
                return (int) $apen['id'];
            }
            $rad = ['member_id' => $medlemId, 'inn_tid' => self::naa()];
            if ($har) {
                $rad['ressurs_id'] = $ressursId > 0 ? $ressursId : null;
            }
            return DB::settInn('check_ins', $rad);
        });
    }

    /** Stempler ut. Returnerer minutter, eller null om ingenting var aapent. */
    public static function ut(int $medlemId): ?int
    {
        return DB::iTransaksjon(static function () use ($medlemId): ?int {
            $apen = DB::en(
                'SELECT id, inn_tid FROM check_ins WHERE member_id = :m AND ut_tid IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE',
                ['m' => $medlemId]
            );
            if ($apen === null) {
                return null;
            }

            $naa = self::naa();
            $min = (int) round((strtotime($naa) - strtotime((string) $apen['inn_tid'])) / 60);
            $min = max(0, min($min, self::MAKS_OKT_MIN));

            DB::oppdater('check_ins', ['ut_tid' => $naa, 'minutter' => $min], ['id' => (int) $apen['id']]);
            return $min;
        });
    }

    /**
     * Hvem som er i verkstedet naa.
     *
     * Medlemmer som har slaatt av synlighet telles med, men navnet vises ikke
     * — de skal ikke forsvinne fra antallet, bare fra lista.
     */
    public static function inneNa(): array
    {
        $ressurs = DB::harKolonne('check_ins', 'ressurs_id')
            ? ', (SELECT r.navn FROM ressurser r WHERE r.id = c.ressurs_id) AS ressurs'
            : ", '' AS ressurs";
        $rader = DB::alle(
            "SELECT c.inn_tid, m.navn, m.vis_innstempling, m.medlemskap_type{$ressurs}
               FROM check_ins c
               JOIN members m ON m.id = c.member_id
              WHERE c.ut_tid IS NULL
              ORDER BY c.inn_tid"
        );

        $synlige = [];
        $skjulte = 0;

        foreach ($rader as $r) {
            if (!(int) $r['vis_innstempling']) {
                $skjulte++;
                continue;
            }
            $inn = (new DateTimeImmutable((string) $r['inn_tid'], new DateTimeZone('UTC')))
                ->setTimezone(self::oslo());
            $synlige[] = [
                'navn'    => (string) $r['navn'],
                'siden'   => $inn->format('H:i'),
                'type'    => (string) ($r['medlemskap_type'] ?? ''),
                'ressurs' => (string) ($r['ressurs'] ?? ''),
            ];
        }

        return ['synlige' => $synlige, 'skjulte' => $skjulte, 'antall' => count($rader)];
    }

    /**
     * Staar noen fra verkstedet innstemplet naa?
     *
     * Aapningstida paa nettsiden ble regnet av kalenderen alene — en paastand
     * om framtiden, som blir staaende som sannhet ogsaa naar den er feil.
     * Er verkstedet bemannet, er doeren aapen, og det er det som gjelder.
     *
     * Bare admin teller. Et medlem som staar og dreier alene, gjor ikke
     * verkstedet aapent for folk som kommer forbi.
     *
     * @return array{apen: bool, siden: string|null}
     */
    public static function verkstedetBemannet(): array
    {
        $rad = DB::en(
            "SELECT c.inn_tid
               FROM check_ins c
               JOIN members m ON m.id = c.member_id
              WHERE c.ut_tid IS NULL AND m.rolle = 'admin'
           ORDER BY c.inn_tid
              LIMIT 1"
        );
        if ($rad === null) {
            return ['apen' => false, 'siden' => null];
        }
        $inn = (new DateTimeImmutable((string) $rad['inn_tid'], new DateTimeZone('UTC')))
            ->setTimezone(self::oslo());

        return ['apen' => true, 'siden' => $inn->format('H:i')];
    }

    /** «1 t 20 min», «45 min», «—». */
    public static function varighet(int $minutter): string
    {
        if ($minutter <= 0) {
            return '0 min';
        }
        $t = intdiv($minutter, 60);
        $m = $minutter % 60;
        if ($t === 0) {
            return $m . ' min';
        }
        return $m === 0 ? $t . ' t' : $t . ' t ' . $m . ' min';
    }

    /** Timer med komma, slik nordmenn skriver dem: 11,5. */
    public static function timer(int $minutter): string
    {
        return str_replace('.', ',', (string) (round($minutter / 60 * 10) / 10));
    }
}
