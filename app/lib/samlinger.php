<?php
/**
 * Samlingene i et flerdagerskurs.
 *
 * Én kursdato er én paamelding. Gaar kurset over flere dager, staar dagene
 * her — med hver sin dato, sitt klokkeslett, sin overskrift og sin tekst.
 *
 * Tabellen kommer med migrasjon 058. Uten den er et kurs ett moete, som for.
 */

declare(strict_types=1);

final class Samlinger
{
    /** Samlingene paa én kursdato, i rekkefolge. */
    public static function forOkt(int $oktId): array
    {
        if (!DB::harTabell('okt_samlinger')) {
            return [];
        }
        return array_map(static function (array $r): array {
            $fra = $r['fra'] !== null ? substr((string) $r['fra'], 0, 5) : '';
            $til = $r['til'] !== null ? substr((string) $r['til'], 0, 5) : '';
            return [
                'id'         => (int) $r['id'],
                'nummer'     => (int) $r['nummer'],
                'dato'       => (string) $r['dato'],
                'fra'        => $fra,
                'til'        => $til,
                'overskrift' => (string) ($r['overskrift'] ?? ''),
                'tekst'      => (string) ($r['tekst'] ?? ''),
                // Ferdig skrevet, slik den vises: «Samling 1 · onsdag 9.
                // september, 17:00–20:00».
                'naar'       => self::norskDag((string) $r['dato'])
                                . ($fra !== '' ? ', ' . $fra . ($til !== '' ? '–' . $til : '') : ''),
            ];
        }, DB::alle('SELECT * FROM okt_samlinger WHERE session_id = :s ORDER BY nummer, dato',
                    ['s' => $oktId]));
    }

    /**
     * Erstatter samlingene paa én dato med lista som kommer inn.
     *
     * Hele lista om gangen, ikke én og én: skjemaet viser alle samlingene
     * samtidig, og da er det lista som er sannheten. Ellers matte vi holde
     * styr paa hvilke rader som ble fjernet i skjemaet, og det er nettopp
     * den bokforingen som gaar galt.
     *
     * @param array<int, array<string, mixed>> $samlinger
     * @return int hvor mange som ble lagret
     */
    public static function lagre(int $oktId, array $samlinger): int
    {
        if (!DB::harTabell('okt_samlinger')) {
            return 0;
        }

        DB::kjor('DELETE FROM okt_samlinger WHERE session_id = :s', ['s' => $oktId]);

        $nummer = 0;
        foreach ($samlinger as $s) {
            $dato = trim((string) ($s['dato'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dato) !== 1) {
                continue;   // en samling uten dato er ikke en samling
            }
            $klokke = static function ($t): ?string {
                $t = trim((string) $t);
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1 ? $t . ':00' : null;
            };
            DB::settInn('okt_samlinger', [
                'session_id' => $oktId,
                'nummer'     => ++$nummer,
                'dato'       => $dato,
                'fra'        => $klokke($s['fra'] ?? ''),
                'til'        => $klokke($s['til'] ?? ''),
                'overskrift' => mb_substr(trim((string) ($s['overskrift'] ?? '')), 0, 191) ?: null,
                'tekst'      => trim((string) ($s['tekst'] ?? '')) ?: null,
            ]);
        }
        return $nummer;
    }

    /** «onsdag 9. september», paa norsk. */
    private static function norskDag(string $dato): string
    {
        static $DAG = ['Sun' => 'søndag', 'Mon' => 'mandag', 'Tue' => 'tirsdag', 'Wed' => 'onsdag',
                       'Thu' => 'torsdag', 'Fri' => 'fredag', 'Sat' => 'lørdag'];
        static $MND = [1 => 'januar', 'februar', 'mars', 'april', 'mai', 'juni',
                       'juli', 'august', 'september', 'oktober', 'november', 'desember'];
        try {
            $d = new DateTimeImmutable($dato);
        } catch (Throwable) {
            return $dato;
        }
        return ($DAG[$d->format('D')] ?? '') . ' ' . (int) $d->format('j') . '. ' . ($MND[(int) $d->format('n')] ?? '');
    }
}
