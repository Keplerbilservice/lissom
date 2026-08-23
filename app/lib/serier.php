<?php
/**
 * Kurs som gaar fast, samme ukedag uke etter uke.
 *
 * Reglene ligger i kurs_serier. Denne legger ut oktene framover, og kalles
 * baade fra admin (naar en regel settes opp) og fra cron (som fyller paa).
 *
 * Aa legge ut oktene i det oyeblikket regelen lages er ikke nok: etter aatte
 * uker ville det vaert tomt igjen, og kurset forsvunnet fra nettsida uten at
 * noen sa fra.
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
        $laget = 0;

        foreach ($regler as $r) {
            $uker = max(1, min(52, (int) $r['uker_fram']));
            [$tf, $mf] = array_map('intval', explode(':', (string) $r['fra']));
            [$tt, $mt] = array_map('intval', explode(':', (string) $r['til']));

            for ($d = 0; $d <= $uker * 7; $d++) {
                $dag = $naa->modify('+' . $d . ' days');
                if ((int) $dag->format('N') !== (int) $r['ukedag']) {
                    continue;
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

                // INSERT IGNORE mot den unike noekkelen (kurs, starttid):
                // en okt som alt ligger der — ogsaa en noen har booket — skal
                // ikke lages paa nytt eller overskrives.
                $laget += DB::kjor(
                    'INSERT IGNORE INTO course_sessions (course_id, start_tid, slutt_tid, kapasitet)
                     VALUES (:c, :s, :e, :k)',
                    [
                        'c' => (int) $r['course_id'],
                        's' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                        'e' => $slutt->setTimezone($utc)->format('Y-m-d H:i:s'),
                        'k' => $r['kapasitet'] !== null ? (int) $r['kapasitet'] : null,
                    ]
                )->rowCount();
            }
        }

        return $laget;
    }

    /** Reglene for ett kurs, slik admin viser dem. */
    public static function forKurs(int $kursId): array
    {
        if (!DB::harTabell('kurs_serier')) {
            return [];
        }
        $DAG = [1 => 'Mandag', 2 => 'Tirsdag', 3 => 'Onsdag', 4 => 'Torsdag',
                5 => 'Fredag', 6 => 'Lørdag', 7 => 'Søndag'];

        return array_map(static function (array $r) use ($DAG): array {
            $fra = substr((string) $r['fra'], 0, 5);
            $til = substr((string) $r['til'], 0, 5);
            return [
                'id'        => (int) $r['id'],
                'ukedag'    => (int) $r['ukedag'],
                'fra'       => $fra,
                'til'       => $til,
                'ukerFram'  => (int) $r['uker_fram'],
                'aktiv'     => (int) $r['aktiv'] === 1,
                'tekst'     => ($DAG[(int) $r['ukedag']] ?? '?') . 'er ' . $fra . '–' . $til,
            ];
        }, DB::alle('SELECT * FROM kurs_serier WHERE course_id = :c ORDER BY ukedag, fra', ['c' => $kursId]));
    }
}
