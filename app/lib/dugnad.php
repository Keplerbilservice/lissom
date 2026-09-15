<?php
/**
 * Dugnad: medlemmer som jobber i verkstedet og faar tida lagt til timene.
 *
 * Eieren, 15. september 2026: medlemmet spoer foerst («de maa gjerne skrive
 * hva de vil jobbe med i korte trekk»), Monica godkjenner, medlemmet
 * stempler inn og ut som dugnad, og tida godkjennes etterpaa foer den
 * legges til — rundet til naermeste kvarter. Ubrukte dugnadstimer kan tas
 * med til neste maaned (bryteren Vis/dugnadoverforing).
 *
 * Dugnadstid gaar aldri gjennom check_ins: den trekker ikke fra
 * medlemskapet, den legger til. Rada i «dugnad» baerer inn- og ut-tid selv.
 */

declare(strict_types=1);

final class Dugnad
{
    /** En dugnad som staar innstemplet lenger enn dette lukkes av seg selv. */
    private const MAKS_MINUTTER = 12 * 60;

    public static function klar(): bool
    {
        return DB::harTabell('dugnad');
    }

    /** Bryteren ⊙ Synlighet → Paa Min side → Dugnad. Mangler raden, er den paa. */
    public static function paa(): bool
    {
        return (string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/dugnad'") !== 'nei';
    }

    /** Skal ubrukte dugnadstimer tas med til neste maaned? Mangler raden, er den paa. */
    public static function overforing(): bool
    {
        return (string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/dugnadoverforing'") !== 'nei';
    }

    /** Rundet til naermeste kvarter (eieren, 15. september 2026). Minst ett. */
    public static function kvarter(int $minutter): int
    {
        return max(15, (int) (round($minutter / 15) * 15));
    }

    /**
     * Dugnader som har staatt «paagaar» altfor lenge lukkes, saa de ikke
     * blir staaende som innstemplet i ukevis. Tida sendes til godkjenning
     * med det som er registrert — verkstedet retter.
     */
    public static function lukkGlemte(): void
    {
        if (!self::klar()) {
            return;
        }
        DB::kjor(
            "UPDATE dugnad
                SET ut_tid = DATE_ADD(inn_tid, INTERVAL :maks MINUTE),
                    minutter = :maks2,
                    status = 'til_godkjenning'
              WHERE status = 'pagar' AND inn_tid < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :maks3 MINUTE)",
            ['maks' => self::MAKS_MINUTTER, 'maks2' => self::MAKS_MINUTTER, 'maks3' => self::MAKS_MINUTTER]
        );
    }

    /**
     * Den dugnaden medlemmet har «i gang» naa: den nyeste som ikke er
     * avsluttet (venter, godkjent, paagaar eller til godkjenning).
     *
     * @return array<string,mixed>|null
     */
    public static function aktiv(int $medlemId): ?array
    {
        if (!self::klar()) {
            return null;
        }
        return DB::en(
            "SELECT * FROM dugnad
              WHERE member_id = :m AND status IN ('venter','godkjent','pagar','til_godkjenning')
           ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId]
        );
    }

    /**
     * Dugnadsminutter medlemmet har til gode denne maaneden.
     *
     * Godkjent i maaneden, pluss det som sto ubrukt igjen fra maaneden foer
     * naar overforing er paa. «Ubrukt» regnes slik: taket for en maaned er
     * planen + gavetimer + dugnad; det medlemmet brukte ut over planen og
     * gavene, tok av dugnadstimene. Resten foelger med videre.
     *
     * Regnes maaned for maaned fra den foerste godkjente dugnaden, saa ingen
     * skjult saldo maa vedlikeholdes: alt kan leses ut av radene.
     */
    public static function minutterTilgode(array $medlem): int
    {
        if (!self::klar()) {
            return 0;
        }
        $id = (int) ($medlem['id'] ?? 0);
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc = new DateTimeZone('UTC');
        $naa = new DateTimeImmutable('now', $oslo);
        $denne = $naa->format('Y-m');

        // Godkjente minutter per maaned (norsk tid).
        $rader = DB::alle(
            "SELECT godkjent_at, godkjent_minutter FROM dugnad
              WHERE member_id = :m AND status = 'ferdig' AND godkjent_minutter IS NOT NULL",
            ['m' => $id]
        );
        if ($rader === []) {
            return 0;
        }
        $perMnd = [];
        foreach ($rader as $r) {
            $mnd = (new DateTimeImmutable((string) $r['godkjent_at'], $utc))->setTimezone($oslo)->format('Y-m');
            $perMnd[$mnd] = ($perMnd[$mnd] ?? 0) + (int) $r['godkjent_minutter'];
        }
        if (!self::overforing()) {
            return $perMnd[$denne] ?? 0;
        }

        // Med overforing: gaa fra foerste maaned med dugnad fram til naa.
        ksort($perMnd);
        $start = new DateTimeImmutable(array_key_first($perMnd) . '-01 00:00', $oslo);
        $plan = Medlemskap::timerFor($medlem);
        $tilgode = 0;
        for ($m = $start; $m->format('Y-m') < $denne; $m = $m->modify('+1 month')) {
            $mnd = $m->format('Y-m');
            $tilgode += $perMnd[$mnd] ?? 0;
            if ($plan === null) {
                // Fri tilgang: ingenting aa bruke av — alt foelger med.
                continue;
            }
            $fra = $m->setTimezone($utc)->format('Y-m-d H:i:s');
            $til = $m->modify('+1 month')->setTimezone($utc)->format('Y-m-d H:i:s');
            $brukt = (int) DB::verdi(
                'SELECT COALESCE(SUM(minutter), 0) FROM check_ins
                  WHERE member_id = :m AND ut_tid IS NOT NULL AND inn_tid >= :fra AND inn_tid < :til',
                ['m' => $id, 'fra' => $fra, 'til' => $til]
            );
            $gaver = self::gavetimerI($id, $m) * 60;
            $overPlan = max(0, $brukt - $plan * 60 - $gaver);
            $tilgode = max(0, $tilgode - $overPlan);
        }
        return $tilgode + ($perMnd[$denne] ?? 0);
    }

    /** Gavetimer som gjaldt i en tidligere maaned (gyldig_til i den maaneden). */
    private static function gavetimerI(int $medlemId, DateTimeImmutable $mnd): int
    {
        if (!DB::harTabell('medlemsgaver') || !DB::harTabell('medlemsgave_bruk')) {
            return 0;
        }
        return (int) DB::verdi(
            "SELECT COALESCE(SUM(g.timer), 0)
               FROM medlemsgave_bruk b JOIN medlemsgaver g ON g.id = b.gave_id
              WHERE b.member_id = :m AND g.type = 'timer' AND g.status = 'aktiv'
                AND g.gyldig_til >= :fra AND g.gyldig_til < :til",
            ['m' => $medlemId, 'fra' => $mnd->format('Y-m-01'), 'til' => $mnd->modify('+1 month')->format('Y-m-01')]
        );
    }

    /** Godkjente dugnader denne maaneden, til lista paa Min side. @return list<array<string,mixed>> */
    public static function ferdigeDenneManeden(int $medlemId): array
    {
        if (!self::klar()) {
            return [];
        }
        return DB::alle(
            "SELECT id, tekst, godkjent_minutter, godkjent_at FROM dugnad
              WHERE member_id = :m AND status = 'ferdig' AND godkjent_at >= :fra
           ORDER BY godkjent_at DESC",
            ['m' => $medlemId, 'fra' => Stempling::manedStart()]
        );
    }

    /** «1 t 25 min» */
    public static function varighet(int $min): string
    {
        $t = intdiv($min, 60);
        $m = $min % 60;
        return $t > 0 ? ($m > 0 ? $t . ' t ' . $m . ' min' : $t . ' t') : $m . ' min';
    }

    /**
     * Rada slik skjermene leser den.
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    public static function ut(array $d): array
    {
        $status = (string) $d['status'];
        $oslo = new DateTimeZone('Europe/Oslo');
        $kl = static fn(?string $utc): string => $utc === null ? ''
            : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($oslo)->format('H:i');
        $paagaarMin = $status === 'pagar' && $d['inn_tid'] !== null
            ? max(0, (int) ((time() - strtotime((string) $d['inn_tid'] . ' UTC')) / 60)) : 0;
        return [
            'id'        => (int) $d['id'],
            'status'    => $status,
            'tekst'     => (string) $d['tekst'],
            'svar'      => (string) ($d['svar'] ?? ''),
            'sendt'     => Booking::norskDatoKort((string) $d['created_at']),
            'svart'     => $d['svart_at'] !== null ? Booking::norskDatoKort((string) $d['svart_at']) : '',
            'inn'       => $kl($d['inn_tid']),
            'ut'        => $kl($d['ut_tid']),
            'dag'       => $d['inn_tid'] !== null ? Booking::norskDatoKort((string) $d['inn_tid']) : '',
            'minutter'  => $status === 'pagar' ? $paagaarMin : (int) ($d['minutter'] ?? 0),
            'varighet'  => self::varighet($status === 'pagar' ? $paagaarMin : (int) ($d['minutter'] ?? 0)),
            'godkjentMinutter' => $d['godkjent_minutter'] !== null ? (int) $d['godkjent_minutter'] : null,
            'godkjentTimer'    => $d['godkjent_minutter'] !== null ? Stempling::timer((int) $d['godkjent_minutter']) : '',
            'forslagTimer'     => Stempling::timer(self::kvarter((int) ($d['minutter'] ?? 0))),
        ];
    }
}
