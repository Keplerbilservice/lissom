<?php
/**
 * Morgenmeldingen til Monica: dagens kurs og kalendernotater paa SMS.
 *
 * Eieren, 13. oktober 2026: «sms paaminnelse hver morgen kl 07:30 som sendes
 * til monica, her er det dagens kurs og paameldingen, og det hun evnt har lagt
 * inn i kalenderen, er det ikke noe, saa staar det ikke noe, lag den med en
 * soet og hyggelig tone». Teksten ble vist ordrett og godkjent samme dag, og
 * staar i malene intern_morgenmelding og intern_morgenmelding_tom (migrasjon
 * 276), saa den kan endres og slaas av under Tekst maler.
 *
 * Nummeret er telefonfeltet paa Monicas egen bruker (Brukere i admin) —
 * eieren valgte det framfor secrets.php. Ingen ny innstilling.
 *
 * Gaar i «varsler»-jobben, som kjoerer hvert femte minutt — ingen ny linje i
 * cPanel. Fra 07:30 norsk tid (sommer og vinter) gaar den én gang i doegnet,
 * merket i innstillinger.morgenmelding_sendt. Etter 10:00 gaar den ikke: en
 * jobb som har staatt stille skal ikke sende «god morgen» midt paa dagen.
 *
 * Eieren, 2. november 2026 («ja, sms er godkjent» paa visningen
 * ELZJvxhMw3AnGcuhmfB7mW): i Monicas melding staar kursholderen i parentes
 * bak kurs som holdes av en annen enn henne. Og den som holder kurs i dag
 * (ikke Monica) faar sin egen SMS med bare sine kurs — malen
 * intern_morgenmelding_kursholder (migrasjon 283), samme morgen og samme
 * én-gang-i-doegnet-merke. Kursholderen er den samme som i admin (regelen i
 * Kursholder: datoen, ellers kurset, ellers verkstedets standard), og
 * nummeret er telefonfeltet under Kursholdere.
 */
declare(strict_types=1);

final class Morgenmelding
{
    private const NOKKEL = 'morgenmelding_sendt';

    /** Kjoeres fra bin/cron.php varsler. */
    public static function kjor(callable $si, ?DateTimeImmutable $naa = null): void
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $naa = ($naa ?? new DateTimeImmutable('now'))->setTimezone($oslo);
        $klokka = $naa->format('H:i');
        if ($klokka < '07:30' || $klokka >= '10:00') {
            return;
        }
        $dato = $naa->format('Y-m-d');
        if ((string) (DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => self::NOKKEL]) ?? '') === $dato) {
            return;
        }
        // Meldingen bygges foer dagen merkes: feiler oppslaget, proever neste
        // kjoering igjen.
        [$kurs, $notater] = self::dagen($naa);

        // Saa merkes dagen: to kjoeringer samtidig gir én melding.
        DB::kjor("INSERT IGNORE INTO innstillinger (nokkel, verdi) VALUES (:n, '')", ['n' => self::NOKKEL]);
        $tatt = DB::kjor(
            "UPDATE innstillinger SET verdi = :d1 WHERE nokkel = :n AND COALESCE(verdi, '') <> :d2",
            ['d1' => $dato, 'd2' => $dato, 'n' => self::NOKKEL]
        )->rowCount();
        if ($tatt === 0) {
            return;
        }

        $tlf = self::nummer();
        if ($tlf === '') {
            logg('Morgenmeldingen ble ikke sendt — Monica har ikke telefonnummer under Brukere');
            $si('Morgenmelding: fant ikke Monicas nummer. Ingenting sendt.');
        } else {
            // Malene staar under Tekst maler og kan endres og slaas av der
            // (migrasjon 276). Er malen av, gaar ingenting.
            [$mal, $felter] = self::mal($kurs, $notater);
            $n = Varsel::mal($mal, ['navn' => 'Monica', 'telefon' => $tlf], $felter, 'morgenmelding');
            $si('Morgenmelding: ' . ($n > 0 ? 'lagt i kø' : 'ikke sendt (malen er av, eller SMS er ikke satt opp)') . '.');
        }

        // Kursholderne som holder kurs i dag, hver med sine kurs. Samme merke
        // som over: dagen er alt tatt, saa en ny kjoering sender ingen av dem
        // igjen. Malen (migrasjon 283) kan endres og slaas av under Tekst maler.
        foreach (self::kursholdere($kurs) as $h) {
            $n = Varsel::mal(
                'intern_morgenmelding_kursholder',
                ['navn' => $h['navn'], 'telefon' => $h['telefon']],
                ['navn' => $h['fornavn'], 'dagen' => self::linjer($h['kurs'], false)],
                'morgenmelding'
            );
            $si('Morgenmelding til ' . $h['fornavn'] . ': '
                . ($n > 0 ? 'lagt i kø' : 'ikke sendt (malen er av, eller SMS er ikke satt opp)') . '.');
        }
    }

    /** Er kursholderen Monica selv? Samme navneregel som nummer(). */
    private static function erMonica(string $navn): bool
    {
        return str_starts_with(mb_strtolower(trim($navn)), 'monica');
    }

    /** Fornavnet: det foerste ordet i navnet. */
    private static function fornavn(string $navn): string
    {
        return (string) (preg_split('/\s+/u', trim($navn))[0] ?? '');
    }

    /**
     * Kursholderne (ikke Monica) som holder kurs i dag, med telefon og
     * aktiv = 1, hver med sine egne kurs.
     *
     * @param list<array<string, mixed>> $kurs fra dagen()
     * @return list<array{navn: string, fornavn: string, telefon: string, kurs: list<array<string, mixed>>}>
     */
    public static function kursholdere(array $kurs): array
    {
        $per = [];
        foreach ($kurs as $k) {
            $id = (int) ($k['holder_id'] ?? 0);
            if ($id > 0 && empty($k['holder_monica'])) {
                $per[$id][] = $k;
            }
        }
        if ($per === [] || !DB::harTabell('kursholdere')) {
            return [];
        }
        $ider = array_keys($per);
        $ph = [];
        $par = [];
        foreach ($ider as $i => $id) {
            $ph[] = ':h' . $i;
            $par['h' . $i] = $id;
        }
        $ut = [];
        foreach (DB::alle(
            'SELECT id, navn, telefon FROM kursholdere
              WHERE id IN (' . implode(',', $ph) . ") AND aktiv = 1
                AND telefon IS NOT NULL AND telefon <> ''
              ORDER BY navn, id",
            $par
        ) as $r) {
            $tlf = normaliser_telefon((string) $r['telefon']);
            if ($tlf === '' || self::erMonica((string) $r['navn'])) {
                continue;
            }
            $ut[] = [
                'navn' => trim((string) $r['navn']),
                'fornavn' => self::fornavn((string) $r['navn']),
                'telefon' => $tlf,
                'kurs' => $per[(int) $r['id']],
            ];
        }
        return $ut;
    }

    /** Telefonnummeret paa Monicas bruker, eller '' om det mangler. */
    public static function nummer(): string
    {
        $tlf = DB::verdi(
            "SELECT telefon FROM members
              WHERE rolle = 'admin' AND navn LIKE 'Monica%'
                AND telefon IS NOT NULL AND telefon <> ''
              ORDER BY id LIMIT 1"
        );
        return normaliser_telefon((string) ($tlf ?? ''));
    }

    /**
     * Dagens kurs og notater.
     *
     * Paameldte telles som i Paameldte i admin: betalte plasser, pluss
     * reserverte som ikke har gaatt ut. Avlyste datoer er ikke med, og heller
     * ikke en aapen plass ingen har booket (Apent::skjulUtenBooking).
     *
     * @return array{0: list<array{tid: string, tittel: string, antall: int}>, 1: list<array{tid: string, tekst: string}>}
     */
    public static function dagen(DateTimeImmutable $naa): array
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc = new DateTimeZone('UTC');
        $dag = $naa->setTimezone($oslo)->setTime(0, 0);
        $fra = $dag->setTimezone($utc)->format('Y-m-d H:i:s');
        $til = $dag->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

        // Kursholderen som i admin (Kursholder): den paa datoen, ellers den
        // paa kurset, ellers verkstedets standard — bare de som er aktive.
        // Kolonnene kom med migrasjon 085; uten dem staar ingen holder.
        $harDato = Kursholder::klar();
        $harKurs = DB::harKolonne('courses', 'kursholder_id');
        $holderKol = ($harDato ? ', hs.id AS holder_dato' : ', NULL AS holder_dato')
            . ($harKurs ? ', hk.id AS holder_kurs' : ', NULL AS holder_kurs');
        $holderBli = ($harDato ? ' LEFT JOIN kursholdere hs ON hs.id = cs.kursholder_id AND hs.aktiv = 1' : '')
            . ($harKurs ? ' LEFT JOIN kursholdere hk ON hk.id = c.kursholder_id AND hk.aktiv = 1' : '');

        $rader = DB::alle(
            "SELECT cs.start_tid, c.tittel {$holderKol},
                    (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
                      WHERE b.course_session_id = cs.id
                        AND (b.status = 'betalt'
                             OR (b.status = 'reservert'
                                 AND (b.reservert_til IS NULL OR b.reservert_til > UTC_TIMESTAMP())))) AS antall
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
               {$holderBli}
              WHERE cs.status <> 'avlyst'
                AND cs.start_tid >= :fra AND cs.start_tid < :til
                AND " . Apent::skjulUtenBooking('cs') . '
              ORDER BY cs.start_tid, c.tittel',
            ['fra' => $fra, 'til' => $til]
        );
        $standard = ($harDato || $harKurs) ? Kursholder::standard() : null;
        $kurs = [];
        $ider = [];
        foreach ($rader as $r) {
            $holder = $r['holder_dato'] ?? $r['holder_kurs'] ?? $standard;
            $holder = $holder !== null ? (int) $holder : 0;
            if ($holder > 0) {
                $ider[$holder] = true;
            }
            $kurs[] = [
                'tid' => (new DateTimeImmutable((string) $r['start_tid'], $utc))->setTimezone($oslo)->format('H:i'),
                'tittel' => trim((string) $r['tittel']),
                'antall' => (int) $r['antall'],
                'holder_id' => $holder,
                'holder' => '',
                'holder_monica' => false,
            ];
        }
        // Navnene. Er det Monica, staar det ikke noe i parentes.
        if ($ider !== []) {
            $navn = [];
            foreach (array_keys($ider) as $id) {
                $navn[$id] = (string) (DB::verdi('SELECT navn FROM kursholdere WHERE id = :i', ['i' => $id]) ?? '');
            }
            foreach ($kurs as &$k) {
                $n = $navn[$k['holder_id']] ?? '';
                if ($n === '') {
                    continue;
                }
                $k['holder_monica'] = self::erMonica($n);
                $k['holder'] = $k['holder_monica'] ? '' : self::fornavn($n);
            }
            unset($k);
        }

        $notater = [];
        if (DB::harTabell('kalender_notater')) {
            foreach (DB::alle(
                'SELECT fra, tekst FROM kalender_notater WHERE dato = :d ORDER BY fra, id',
                ['d' => $dag->format('Y-m-d')]
            ) as $n) {
                $tekst = trim((string) preg_replace('/\s+/u', ' ', (string) $n['tekst']));
                if ($tekst !== '') {
                    $notater[] = ['tid' => substr((string) $n['fra'], 0, 5), 'tekst' => $tekst];
                }
            }
        }
        return [$kurs, $notater];
    }

    /**
     * Hvilken mal, og lista som flettes inn som {dagen}. Teksten rundt staar
     * i malene (migrasjon 276), godkjent av eieren 13. oktober 2026. Er det
     * ikke noe, staar det ikke noe: uten kurs faller «Kurs i dag» bort, uten
     * notater «I kalenderen», og uten noen av dem gaar den korte malen.
     *
     * @param list<array{tid: string, tittel: string, antall: int}> $kurs
     * @param list<array{tid: string, tekst: string}> $notater
     * @return array{0: string, 1: array<string, string>}
     */
    public static function mal(array $kurs, array $notater): array
    {
        if ($kurs === [] && $notater === []) {
            return ['intern_morgenmelding_tom', []];
        }
        $deler = [];
        if ($kurs !== []) {
            $deler[] = "Kurs i dag:\n" . self::linjer($kurs, true);
        }
        if ($notater !== []) {
            $t = 'I kalenderen:';
            foreach ($notater as $n) {
                $t .= "\n• {$n['tid']} {$n['tekst']}";
            }
            $deler[] = $t;
        }
        return ['intern_morgenmelding', ['dagen' => implode("\n\n", $deler)]];
    }

    /**
     * Kurslinjene: «• 18:00 Kveldskurs keramikk – 6 påmeldte». Med
     * $medHolder faar kurs som en annen enn Monica holder « (Navn)» bak
     * (Monicas melding); kursholderens egen melding har ikke navnet.
     *
     * @param list<array<string, mixed>> $kurs
     */
    public static function linjer(array $kurs, bool $medHolder): string
    {
        $linjer = [];
        foreach ($kurs as $k) {
            $antall = (int) $k['antall'];
            $linje = "• {$k['tid']} {$k['tittel']} – {$antall} " . ($antall === 1 ? 'påmeldt' : 'påmeldte');
            $holder = (string) ($k['holder'] ?? '');
            if ($medHolder && $holder !== '') {
                $linje .= " ({$holder})";
            }
            $linjer[] = $linje;
        }
        return implode("\n", $linjer);
    }
}
