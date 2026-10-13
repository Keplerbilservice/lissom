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
            return;
        }
        // Malene staar under Tekst maler og kan endres og slaas av der
        // (migrasjon 276). Er malen av, gaar ingenting.
        [$mal, $felter] = self::mal($kurs, $notater);
        $n = Varsel::mal($mal, ['navn' => 'Monica', 'telefon' => $tlf], $felter, 'morgenmelding');
        $si('Morgenmelding: ' . ($n > 0 ? 'lagt i kø' : 'ikke sendt (malen er av, eller SMS er ikke satt opp)') . '.');
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

        $rader = DB::alle(
            "SELECT cs.start_tid, c.tittel,
                    (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
                      WHERE b.course_session_id = cs.id
                        AND (b.status = 'betalt'
                             OR (b.status = 'reservert'
                                 AND (b.reservert_til IS NULL OR b.reservert_til > UTC_TIMESTAMP())))) AS antall
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.status <> 'avlyst'
                AND cs.start_tid >= :fra AND cs.start_tid < :til
                AND " . Apent::skjulUtenBooking('cs') . '
              ORDER BY cs.start_tid, c.tittel',
            ['fra' => $fra, 'til' => $til]
        );
        $kurs = [];
        foreach ($rader as $r) {
            $kurs[] = [
                'tid' => (new DateTimeImmutable((string) $r['start_tid'], $utc))->setTimezone($oslo)->format('H:i'),
                'tittel' => trim((string) $r['tittel']),
                'antall' => (int) $r['antall'],
            ];
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
            $t = 'Kurs i dag:';
            foreach ($kurs as $k) {
                $t .= "\n• {$k['tid']} {$k['tittel']} – {$k['antall']} " . ($k['antall'] === 1 ? 'påmeldt' : 'påmeldte');
            }
            $deler[] = $t;
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
}
