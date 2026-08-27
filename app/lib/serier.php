<?php
/**
 * Kurs som gaar fast: hver uke, annenhver uke, eller samme dato hver maaned.
 *
 * Reglene ligger i kurs_serier. Denne legger ut oktene framover, og kalles
 * baade fra admin (naar en regel settes opp) og fra cron (som fyller paa).
 *
 * Aa legge ut oktene i det oyeblikket regelen lages er ikke nok: etter aatte
 * uker ville det vaert tomt igjen, og kurset forsvunnet fra nettsida uten at
 * noen sa fra.
 *
 * To tall som lett forveksles:
 *   uker_fram   hvor langt fram datoene skal ligge ute til enhver tid. Et
 *               vindu som flytter seg med dagen i dag.
 *   antall      hvor mange det skal bli til slutt, og saa stopper den.
 *               Tomt betyr «til noen tar regelen bort».
 */

declare(strict_types=1);

final class Serier
{
    /**
     * Legg ut oktene som mangler framover.
     *
     * @param int|null $kursId begrens til ett kurs, eller null for alle
     * @return int antall okter som ble lagt til
     */
    public static function fyllPaa(?int $kursId = null): int
    {
        // Tabellen kommer med migrasjon 029. Er den ikke kjort, finnes det
        // ingen faste ukedager aa fylle paa — og cron skal ikke stoppe paa det.
        if (!DB::harTabell('kurs_serier')) {
            return 0;
        }

        $regler = $kursId === null
            ? DB::alle('SELECT s.*, c.kapasitet AS kurs_kapasitet
                          FROM kurs_serier s JOIN courses c ON c.id = s.course_id
                         WHERE s.aktiv = 1 AND c.status <> :a', ['a' => 'avlyst'])
            : DB::alle('SELECT s.*, c.kapasitet AS kurs_kapasitet
                          FROM kurs_serier s JOIN courses c ON c.id = s.course_id
                         WHERE s.aktiv = 1 AND s.course_id = :c', ['c' => $kursId]);

        if ($regler === []) {
            return 0;
        }

        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $naa  = new DateTimeImmutable('now', $oslo);
        $harSerieId = DB::harKolonne('course_sessions', 'serie_id');
        $laget = 0;

        foreach ($regler as $r) {
            $uker = max(1, min(52, (int) $r['uker_fram']));
            [$tf, $mf] = array_map('intval', explode(':', (string) $r['fra']));
            [$tt, $mt] = array_map('intval', explode(':', (string) $r['til']));

            // «10 ganger» maa telles paa tvers av kjoringer: cron gaar hver
            // natt, og regelen skal ikke starte paa nytt hver gang.
            $tak = isset($r['antall']) && $r['antall'] !== null ? (int) $r['antall'] : 0;
            $alt = 0;
            if ($tak > 0 && $harSerieId) {
                $alt = (int) DB::verdi(
                    'SELECT COUNT(*) FROM course_sessions WHERE serie_id = :s',
                    ['s' => (int) $r['id']]
                );
                if ($alt >= $tak) {
                    continue;   // regelen har gjort sitt
                }
            }

            foreach (self::datoene($r, $naa, $uker) as $dag) {
                if ($tak > 0 && $alt >= $tak) {
                    break;
                }
                $start = $dag->setTime($tf, $mf);
                if ($start <= $naa) {
                    continue;   // i dag, men klokka er passert
                }
                $slutt = $dag->setTime($tt, $mt);
                // Slutter det etter midnatt, hoerer sluttiden til neste dogn.
                if ($slutt <= $start) {
                    $slutt = $slutt->modify('+1 day');
                }

                $felter = [
                    'c' => (int) $r['course_id'],
                    's' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'e' => $slutt->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'k' => $r['kapasitet'] !== null ? (int) $r['kapasitet'] : null,
                ];

                // INSERT IGNORE mot den unike noekkelen (kurs, starttid):
                // en okt som alt ligger der — ogsaa en noen har booket — skal
                // ikke lages paa nytt eller overskrives.
                if ($harSerieId) {
                    $felter['r'] = (int) $r['id'];
                    $ny = DB::kjor(
                        'INSERT IGNORE INTO course_sessions (course_id, serie_id, start_tid, slutt_tid, kapasitet)
                         VALUES (:c, :r, :s, :e, :k)',
                        $felter
                    )->rowCount();
                } else {
                    $ny = DB::kjor(
                        'INSERT IGNORE INTO course_sessions (course_id, start_tid, slutt_tid, kapasitet)
                         VALUES (:c, :s, :e, :k)',
                        $felter
                    )->rowCount();
                }

                $laget += $ny;
                // Laa datoen der fra for, teller den likevel med i «10
                // ganger» — ellers ville regelen lagt ut ti nye i tillegg til
                // dem som alt sto der.
                $alt++;
            }
        }

        return $laget;
    }

    /**
     * Dagene regelen treffer innenfor vinduet, i rekkefolge.
     *
     * Bare datoene. Klokkeslettet legges paa av den som kaller.
     *
     * @return list<DateTimeImmutable>
     */
    private static function datoene(array $r, DateTimeImmutable $naa, int $uker): array
    {
        $monster = (string) ($r['monster'] ?? 'ukentlig');
        $ut = [];

        // Regelen lager ingenting for den dagen den begynner. Setter du opp
        // en dato 3. september og ber om ukentlig, skal ikke torsdagen i
        // denne uka komme med — den er ikke en av de gangene du bestilte.
        $fra = null;
        if (($r['start_dato'] ?? null) !== null && (string) $r['start_dato'] !== '') {
            try {
                $fra = (new DateTimeImmutable((string) $r['start_dato'], $naa->getTimezone()))->setTime(0, 0);
            } catch (Throwable) {
                $fra = null;
            }
        }
        $etter = static fn(DateTimeImmutable $d): bool => $fra === null || $d->setTime(0, 0) >= $fra;

        if ($monster === 'manedlig') {
            // Samme dato hver maned. Den 31. finnes ikke i februar — da
            // hopper vi over maneden framfor aa flytte kurset til 1. mars.
            $dag = max(1, min(31, (int) ($r['dag_i_maaned'] ?: 1)));
            $slutt = $naa->modify('+' . ($uker * 7) . ' days');
            $maaned = $naa->modify('first day of this month')->setTime(0, 0);
            while ($maaned <= $slutt) {
                $siste = (int) $maaned->format('t');
                if ($dag <= $siste) {
                    $d = $maaned->setDate((int) $maaned->format('Y'), (int) $maaned->format('n'), $dag);
                    if ($d <= $slutt && $etter($d)) {
                        $ut[] = $d;
                    }
                }
                $maaned = $maaned->modify('first day of next month');
            }
            return $ut;
        }

        // Ukentlig og annenhver: samme ukedag. Forskjellen er om annenhver
        // uke hoppes over, og det maales fra et fast holdepunkt slik at
        // svaret ikke endrer seg med dagen cron kjorer.
        $ukedag = (int) $r['ukedag'];
        if ($ukedag < 1 || $ukedag > 7) {
            return [];
        }
        $anker = null;
        if ($monster === 'annenhver') {
            $tekst = (string) ($r['start_dato'] ?? '') ?: (string) ($r['created_at'] ?? '');
            $anker = $tekst !== ''
                ? (new DateTimeImmutable($tekst, $naa->getTimezone()))->setTime(0, 0)
                : $naa->setTime(0, 0);
            // Selve ankeret flyttes til den ukedagen regelen gjelder, slik at
            // uketellingen begynner paa en dag regelen faktisk treffer.
            while ((int) $anker->format('N') !== $ukedag) {
                $anker = $anker->modify('+1 day');
            }
        }

        for ($d = 0; $d <= $uker * 7; $d++) {
            $dag = $naa->modify('+' . $d . ' days');
            if ((int) $dag->format('N') !== $ukedag) {
                continue;
            }
            if ($anker !== null) {
                $uke = (int) floor((int) $anker->diff($dag->setTime(0, 0))->format('%r%a') / 7);
                if ($uke % 2 !== 0) {
                    continue;
                }
            }
            if (!$etter($dag)) {
                continue;
            }
            $ut[] = $dag;
        }
        return $ut;
    }

    /** Reglene for ett kurs, slik admin viser dem. */
    public static function forKurs(int $kursId): array
    {
        if (!DB::harTabell('kurs_serier')) {
            return [];
        }
        $DAG = [1 => 'Mandag', 2 => 'Tirsdag', 3 => 'Onsdag', 4 => 'Torsdag',
                5 => 'Fredag', 6 => 'Lørdag', 7 => 'Søndag'];

        $harSerieId = DB::harKolonne('course_sessions', 'serie_id');

        return array_map(static function (array $r) use ($DAG, $harSerieId): array {
            $fra = substr((string) $r['fra'], 0, 5);
            $til = substr((string) $r['til'], 0, 5);
            $monster = (string) ($r['monster'] ?? 'ukentlig');
            $antall = isset($r['antall']) && $r['antall'] !== null ? (int) $r['antall'] : 0;

            // Teksten skal kunne leses hoyt: «Torsdager 17:00–20:00,
            // annenhver uke · 4 av 10 lagt ut».
            $naar = $monster === 'manedlig'
                ? 'Den ' . (int) $r['dag_i_maaned'] . '. hver måned ' . $fra . '–' . $til
                : ($DAG[(int) $r['ukedag']] ?? '?') . 'er ' . $fra . '–' . $til
                  . ($monster === 'annenhver' ? ', annenhver uke' : '');

            $lagt = 0;
            if ($antall > 0 && $harSerieId) {
                $lagt = (int) DB::verdi('SELECT COUNT(*) FROM course_sessions WHERE serie_id = :s',
                                        ['s' => (int) $r['id']]);
                $naar .= ' · ' . min($lagt, $antall) . ' av ' . $antall . ' lagt ut';
            }

            return [
                'id'         => (int) $r['id'],
                'monster'    => $monster,
                'ukedag'     => (int) $r['ukedag'],
                'dagIMaaned' => (int) ($r['dag_i_maaned'] ?? 0),
                'fra'        => $fra,
                'til'        => $til,
                'ukerFram'   => (int) $r['uker_fram'],
                'antall'     => $antall,
                'lagtUt'     => $lagt,
                'ferdig'     => $antall > 0 && $lagt >= $antall,
                'aktiv'      => (int) $r['aktiv'] === 1,
                'tekst'      => $naar,
            ];
        // Kolonnene kommer med migrasjon 056. Er den ikke kjort enda, skal
        // kurslista i admin virke som for framfor aa svare med en SQL-feil.
        }, DB::alle('SELECT * FROM kurs_serier WHERE course_id = :c ORDER BY '
                    . (DB::harKolonne('kurs_serier', 'monster') ? 'monster, ukedag, dag_i_maaned, fra' : 'ukedag, fra'),
                    ['c' => $kursId]));
    }
}
