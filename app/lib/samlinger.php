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
        return self::forOkter([$oktId])[$oktId] ?? [];
    }

    /**
     * Samlingene paa mange kursdatoer, i én sporring.
     *
     * Katalogen spurte én gang per dato. Tabellen har som regel ingen rader i
     * det hele tatt — de fleste kurs er ett moete — saa det var 83 sporringer
     * for aa faa 83 tomme svar.
     *
     * @param list<int> $oktIder
     * @return array<int, list<array<string, mixed>>> oktId => samlinger
     */
    public static function forOkter(array $oktIder): array
    {
        $ider = array_values(array_unique(array_map('intval', $oktIder)));
        if ($ider === [] || !DB::harTabell('okt_samlinger')) {
            return [];
        }

        // Heltallene er castet over, saa de kan staa i IN-lista.
        $inn = implode(',', $ider);

        $ut = [];
        foreach (DB::alle(
            "SELECT * FROM okt_samlinger WHERE session_id IN ({$inn}) ORDER BY session_id, nummer, dato"
        ) as $r) {
            $ut[(int) $r['session_id']][] = self::enSamling($r);
        }

        return $ut;
    }

    /**
     * Én samling, slik den vises.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function enSamling(array $r): array
    {
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

        // ── Okta spenner fra forste til siste dag ──────────────────────
        //
        // Datoen kunden ser kommer fra course_sessions.start_tid og
        // slutt_tid — se Booking::norskPeriode(). Samlingene laa i sin egen
        // tabell og rorte dem aldri, saa et kurs som var lagt inn over to
        // dager sto fortsatt som «onsdag 7. oktober, 17:00»: i datovelgeren,
        // paa kortet, i kalenderen, i kvitteringen og i ics-feeden.
        //
        // Eieren, 4. september, etter aa ha lagt inn 7. og 8. oktober: «den
        // viser fortsatt bare en dato».
        //
        // Dette er ikke en ny maate aa beskrive et flerdagerskurs paa — det
        // er den systemet alt bruker. Dreiekursene som laa i basen fra for
        // har nettopp start paa dag én og slutt paa dag to, og
        // aapningstidene deler spennet opp i de samme klokkeslettene hver
        // dag framfor aa gjore natta aapen (se app/lib/apent.php).
        //
        // Samlingene staar i lokal tid, slik verkstedet skriver dem. Radene
        // i course_sessions staar i UTC.
        self::speilOkt($oktId);

        // Okta merkes som endret.
        //
        // Samlingene ligger i sin egen tabell, saa «course_sessions
        // .updated_at» rorte seg ikke naar de ble rettet. Kalenderfeeden
        // leser den for SEQUENCE, og telefonen bruker SEQUENCE til aa avgjore
        // om en hendelse den alt har er endret. Uten dette kunne en samling
        // som ble flyttet bli staaende paa telefonen slik den var.
        //
        // Tida settes rett: «ON UPDATE CURRENT_TIMESTAMP» slaar ikke inn naar
        // ingen verdi faktisk endrer seg, saa aa skrive raden til seg selv
        // ville ikke gjort noe. Kolonnen kom med migrasjon 059, og feeden
        // taaler at den mangler — det gjor dette ogsaa.
        if (DB::harKolonne('course_sessions', 'updated_at')) {
            DB::kjor('UPDATE course_sessions SET updated_at = UTC_TIMESTAMP() WHERE id = :s',
                     ['s' => $oktId]);
        }

        return $nummer;
    }

    /**
     * Setter oektas start og slutt etter samlingene.
     *
     * Med samlinger: fra forste dag til siste. Klokkeslettet paa en samling
     * kan staa tomt — da beholder vi det oekta hadde, saa en dato som legges
     * inn uten tid ikke flytter kvelden.
     *
     * Uten samlinger: er okta et flerdagerskurs som naa er gjort om til én
     * dag, trekkes slutten tilbake til startdagen. Det gjelder bare den som
     * slutter SENERE paa dogneet enn den begynner — en ekte nattevakt,
     * 22:00 til 02:00, skal staa som den er.
     */
    private static function speilOkt(int $oktId): void
    {
        $okt = DB::en('SELECT start_tid, slutt_tid FROM course_sessions WHERE id = :s', ['s' => $oktId]);
        if ($okt === null) {
            return;
        }

        $utc  = new DateTimeZone('UTC');
        $oslo = new DateTimeZone('Europe/Oslo');
        $start = (new DateTimeImmutable((string) $okt['start_tid'], $utc))->setTimezone($oslo);
        $slutt = $okt['slutt_tid'] !== null
            ? (new DateTimeImmutable((string) $okt['slutt_tid'], $utc))->setTimezone($oslo)
            : null;

        $rader = DB::alle(
            'SELECT dato, fra, til FROM okt_samlinger WHERE session_id = :s ORDER BY nummer, dato',
            ['s' => $oktId]
        );

        if ($rader === []) {
            // Ingen samlinger igjen. Var dette et flerdagerskurs, blir det
            // én dag — ellers staar okta som den er.
            if ($slutt === null
                || $slutt->format('Y-m-d') === $start->format('Y-m-d')
                || $slutt->format('H:i:s') <= $start->format('H:i:s')) {
                return;
            }
            $ny = $start->setTime(
                (int) $slutt->format('H'), (int) $slutt->format('i'), (int) $slutt->format('s')
            );
            DB::oppdater('course_sessions',
                ['slutt_tid' => $ny->setTimezone($utc)->format('Y-m-d H:i:s')], ['id' => $oktId]);
            return;
        }

        $forste = $rader[0];
        $siste  = $rader[count($rader) - 1];

        // Klokkeslettet fra samlinga naar det staar der, ellers oektas eget.
        $sett = static function (array $rad, string $felt, DateTimeImmutable $fallback) use ($oslo): DateTimeImmutable {
            $klokke = $rad[$felt] !== null && $rad[$felt] !== ''
                ? substr((string) $rad[$felt], 0, 8)
                : $fallback->format('H:i:s');
            return new DateTimeImmutable((string) $rad['dato'] . ' ' . $klokke, $oslo);
        };

        $nyStart = $sett($forste, 'fra', $start);
        $nySlutt = $sett($siste, 'til', $slutt ?? $start->modify('+3 hours'));

        // En siste dag som ender for den begynner er en skrivefeil, ikke en
        // nattevakt over to dager. Da lar vi slutten vaere.
        if ($nySlutt <= $nyStart) {
            $nySlutt = $nyStart->modify('+3 hours');
        }

        DB::oppdater('course_sessions', [
            'start_tid' => $nyStart->setTimezone($utc)->format('Y-m-d H:i:s'),
            'slutt_tid' => $nySlutt->setTimezone($utc)->format('Y-m-d H:i:s'),
        ], ['id' => $oktId]);
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
