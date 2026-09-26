<?php
/**
 * Hvem holder kurset.
 *
 * Fire steder i systemet lager kursdatoer: «ny dato» i admin, faste ukedager
 * (serier) og aapent verksted. Bare det forste satte kursholder.
 * De tre andre la datoene ut med tomt felt, og da sto de i kalenderen som
 * «Uten kursholder» — ogsaa naar verkstedet bare har én, og hun er standard.
 *
 * Eieren, 1. september: «lag din egen bolle dukker opp i kalenderen naa, uten
 * kursholder, hvordan er det mulig naar det kun er monica som er kursholder og
 * default?» — og: «det gjelder saa klart ogsaa paa alle paint on pots».
 *
 * Regelen staar her, ett sted, og gaar én vei:
 *
 *   1. Er det valgt en paa selve datoen, er det hen. Alltid.
 *   2. Ellers: den som staar paa kurset.
 *   3. Ellers: verkstedets standard.
 *   4. Finnes ingen av delene, staar datoen tom — som for.
 *
 * Punkt 1 hoerer hjemme der noen faktisk kan velge, altsaa i admin. De tre
 * andre stedene lager datoer uten aa spore noen, og starter paa punkt 2.
 */

declare(strict_types=1);

final class Kursholder
{
    /** Standarden hentes én gang per foresporsel — den spoerres i loekker. */
    private static ?int $standard = null;
    private static bool $standardHentet = false;

    /**
     * Kan datoen i det hele tatt baere en kursholder?
     *
     * Kolonnen kommer med migrasjon 085. Koden ligger ute noen minutter for
     * vedlikeholdet kjores, og skal ikke doe paa en kolonne som ikke er der.
     */
    public static function klar(): bool
    {
        return DB::harKolonne('course_sessions', 'kursholder_id');
    }

    /** Verkstedets standard, eller null naar ingen er merket. */
    public static function standard(): ?int
    {
        if (self::$standardHentet) {
            return self::$standard;
        }
        self::$standardHentet = true;
        self::$standard = null;

        if (DB::harTabell('kursholdere') && DB::harKolonne('kursholdere', 'standard')) {
            $id = DB::verdi('SELECT id FROM kursholdere WHERE standard = 1 AND aktiv = 1 LIMIT 1');
            if ($id !== null) {
                self::$standard = (int) $id;
            }
        }
        return self::$standard;
    }

    /**
     * Den som staar paa selve kurset, eller null.
     *
     * «Staar paa kurset» betyr en som fortsatt holder kurs. Her sto bare
     * oppslaget, uten aa sporre om det: pekte kurset paa en som hadde
     * sluttet, ble det navnet arvet videre til hver eneste nye dato, og
     * fallbacken til standarden slo aldri inn — feltet var jo ikke tomt.
     *
     * Kalenderen viser ingen som har sluttet (den ser etter «aktiv = 1»), saa
     * datoen sto der som «Uten kursholder» selv om det sto noen paa den.
     * Eieren, 1. september: «flere paint on pots kurs ligger paa kolonnen
     * uten kursholdere».
     */
    public static function paaKurset(int $kursId): ?int
    {
        if ($kursId <= 0 || !DB::harKolonne('courses', 'kursholder_id')) {
            return null;
        }
        $id = DB::verdi(
            'SELECT c.kursholder_id
               FROM courses c
               JOIN kursholdere k ON k.id = c.kursholder_id AND k.aktiv = 1
              WHERE c.id = :i',
            ['i' => $kursId]
        );
        return $id !== null ? (int) $id : null;
    }

    /**
     * Hvem en ny dato paa dette kurset skal staa paa.
     *
     * Null betyr «ingen» — da er det ingen paa kurset og ingen standard, og
     * datoen skal staa tom framfor aa faa en tilfeldig person paa seg.
     */
    public static function forKurs(int $kursId): ?int
    {
        return self::paaKurset($kursId) ?? self::standard();
    }

    // ── Kursholderen paa Min side, og timene ────────────────────────────
    //
    // Eieren, 26. september 2026 (GO paa skissen): kursholderen ser kursene
    // sine paa Min side og stempler der. Kursets lengde er forslaget; hen
    // bekrefter eller endrer. I admin velges «Lønn» (timelisten under
    // Økonomi) eller «Timer» (legges til verkstedtimene, som dugnad).
    // Migrasjon 221.

    /** Er timene i den nye formen (migrasjon 221)? */
    public static function timerKlar(): bool
    {
        return DB::harTabell('kursholder_timer') && DB::harKolonne('kursholder_timer', 'session_id');
    }

    /**
     * Kursholderen som hoerer til en innlogging: samme e-post.
     *
     * @param array<string,mixed> $medlem
     * @return array<string,mixed>|null
     */
    public static function forMedlem(array $medlem): ?array
    {
        $epost = mb_strtolower(trim((string) ($medlem['epost'] ?? '')));
        if ($epost === '' || !DB::harTabell('kursholdere')) {
            return null;
        }
        return DB::en(
            'SELECT * FROM kursholdere WHERE aktiv = 1 AND LOWER(TRIM(epost)) = :e ORDER BY id LIMIT 1',
            ['e' => $epost]
        );
    }

    /** Minutter en kursdato varer: samlingene lagt sammen, ellers start–slutt samme dag. */
    public static function minutterFor(int $oktId): ?int
    {
        if (DB::harTabell('okt_samlinger')) {
            $rader = DB::alle('SELECT fra, til FROM okt_samlinger WHERE session_id = :s', ['s' => $oktId]);
            if (count($rader) > 1) {
                $sum = 0;
                foreach ($rader as $r) {
                    if ($r['fra'] !== null && $r['til'] !== null) {
                        $sum += max(0, (int) ((strtotime('2000-01-01 ' . $r['til']) - strtotime('2000-01-01 ' . $r['fra'])) / 60));
                    }
                }
                return $sum > 0 ? $sum : null;
            }
        }
        $o = DB::en('SELECT start_tid, slutt_tid FROM course_sessions WHERE id = :s', ['s' => $oktId]);
        if ($o === null || $o['start_tid'] === null || $o['slutt_tid'] === null) {
            return null;
        }
        $a = strtotime((string) $o['start_tid'] . ' UTC');
        $b = strtotime((string) $o['slutt_tid'] . ' UTC');
        if ($b <= $a || $b - $a > 12 * 3600) {
            return null;
        }
        return (int) (($b - $a) / 60);
    }

    /**
     * Forslag for kursdatoer som er over: kursets lengde, «forslag».
     *
     * Lages for hver ferdig kursdato med en kursholder, de siste 60 dagene,
     * saa sant det ikke alt finnes en rad (stemplet eller foert for haand).
     */
    public static function lagForslag(?int $holderId = null): void
    {
        if (!self::timerKlar() || !self::klar()) {
            return;
        }
        $param = [];
        $hvem = '';
        if ($holderId !== null) {
            $hvem = ' AND cs.kursholder_id = :h';
            $param['h'] = $holderId;
        }
        $okter = DB::alle(
            "SELECT cs.id, cs.kursholder_id, cs.start_tid, c.tittel
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
               JOIN kursholdere k ON k.id = cs.kursholder_id AND k.aktiv = 1
          LEFT JOIN kursholder_timer t ON t.kursholder_id = cs.kursholder_id AND t.session_id = cs.id
              WHERE t.id IS NULL
                AND cs.status = 'planlagt'
                AND COALESCE(cs.slutt_tid, cs.start_tid) < UTC_TIMESTAMP()
                AND cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY){$hvem}",
            $param
        );
        $oslo = new DateTimeZone('Europe/Oslo');
        foreach ($okter as $o) {
            $min = self::minutterFor((int) $o['id']);
            if ($min === null) {
                continue;
            }
            $dato = (new DateTimeImmutable((string) $o['start_tid'], new DateTimeZone('UTC')))->setTimezone($oslo)->format('Y-m-d');
            try {
                DB::settInn('kursholder_timer', [
                    'kursholder_id' => (int) $o['kursholder_id'],
                    'session_id'    => (int) $o['id'],
                    'dato'          => $dato,
                    'timer'         => round($min / 60, 2),
                    'hva'           => mb_substr((string) $o['tittel'], 0, 96),
                    'status'        => 'forslag',
                    'kilde'         => 'lengde',
                ]);
            } catch (Throwable $e) {
                // To samtidige kall: raden kom inn fra det andre. Greit.
            }
        }
    }

    /**
     * Bekreftede kursholdertimer som legges til verkstedtimene denne maaneden
     * — for kursholdere med «Timer». I minutter, som dugnaden.
     *
     * @param array<string,mixed> $medlem
     */
    public static function minutterTilgode(array $medlem): int
    {
        if (!self::timerKlar() || !DB::harKolonne('kursholdere', 'betaling')) {
            return 0;
        }
        $h = self::forMedlem($medlem);
        if ($h === null || (string) ($h['betaling'] ?? '') !== 'timer') {
            return 0;
        }
        $mnd = (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-01');
        $timer = (float) (DB::verdi(
            "SELECT COALESCE(SUM(timer), 0) FROM kursholder_timer
              WHERE kursholder_id = :h AND status = 'bekreftet' AND dato >= :m",
            ['h' => (int) $h['id'], 'm' => $mnd]
        ) ?? 0);
        return (int) round($timer * 60);
    }

    /** Bare for provene: glem det som er hentet. */
    public static function glem(): void
    {
        self::$standard = null;
        self::$standardHentet = false;
    }
}
