<?php
/**
 * Naar verkstedet er aapent.
 *
 * Regelen sto inne i api/apningstider.php, og bare der. Da Paint on Pots
 * skulle kunne bookes «naar jeg allerede er her», trengtes den samme regelen
 * et sted til — og to utgaver av naar doeren staar aapen er én for mye.
 * Regelen er flyttet hit; endepunktet bruker den, og bookingen bruker den.
 *
 * Regelen er enkel og staar ett sted:
 *
 *   1. Er det lagt inn en rad i apningstider for dagen, gjelder den. Punktum.
 *      Det er den manuelle overstyringen — en helligdag, en ferieuke, en dag
 *      verkstedet er stengt selv om det staar et kurs i kalenderen.
 *   2. Ellers: gaar det ett eller flere kurs den dagen, er verkstedet aapent
 *      fra det forste begynner til det siste slutter.
 *   3. Ellers staar dagen ute av lista. En dag uten noe er ikke en dag med
 *      aapningstid — den er en dag det ikke skjer noe.
 *
 * Avlyste datoer teller ikke. Kladder teller ikke. En dato uten tidspunkt
 * teller ikke — den kan ikke si noe om naar det er aapent.
 *
 * Og: en oekt som selv er laget AV en aapningstid teller ikke. Paint on Pots
 * legges ut paa de aapne vinduene; talte de med, ville verkstedet holdt seg
 * aapent av sin egen skygge.
 */

declare(strict_types=1);

final class Apent
{
    /** Hvor mange dager fram vi svarer for. */
    public const DAGER_FRAM = 14;

    /**
     * Dagene med aapningstid, og hvilke oekter tallene er regnet av.
     *
     * @return array{dager: list<array<string,mixed>>, kilder: array<string, list<array<string,mixed>>>}
     */
    public static function dager(int $dagerFram = self::DAGER_FRAM): array
    {
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $naa  = new DateTimeImmutable('now', $oslo);
        $idag = $naa->setTime(0, 0);
        $slutt = $idag->modify('+' . $dagerFram . ' days');

        // Oekter som selv er laget av en aapningstid teller ikke med. Ellers
        // holder verkstedet seg aapent av sin egen skygge: en oekt laget
        // fordi det var aapent, som deretter gjor at det er aapent.
        $ikkeGenerert = DB::harKolonne('course_sessions', 'fra_apningstid')
            ? ' AND cs.fra_apningstid = 0' : '';

        // ── Kursene ────────────────────────────────────────────────────────────────
        //
        // Bare det som faktisk gaar: planlagt, paa et kurs som er publisert, og med
        // et tidspunkt. En drop-in ingen har meldt seg paa er en aapen doer og
        // teller med — den er nettopp en aapningstid.
        $okter = DB::alle(
            "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.fra_dropin_tid, c.tittel, c.tema, c.type,
                    (SELECT COUNT(*) FROM bookings b
                      WHERE b.course_session_id = cs.id
                        AND b.status IN ('betalt','reservert')) AS pameldte
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt'
                AND c.status = 'publisert'
                AND cs.start_tid IS NOT NULL
                AND cs.start_tid >= :fra
                AND cs.start_tid < :til" . $ikkeGenerert . "
           ORDER BY cs.start_tid",
            [
                'fra' => $idag->setTimezone($utc)->format('Y-m-d H:i:s'),
                'til' => $slutt->setTimezone($utc)->format('Y-m-d H:i:s'),
            ]
        );

        // ── Drop-in-oekter som ikke svarer til en aapningstid lenger ───────────────
        //
        // Drop-in-tidene lages av ukereglene den dagen noen trykker «Legg ut tidene»,
        // og blir liggende. Endres reglene etterpaa, ryddes bare de framtidige som
        // ingen har booket — og en oekt lagt inn for haand ryddes aldri.
        //
        // Da sa nettsiden «aapent til 19» av en oekt som ikke sto noe sted: skjermen
        // viste reglene, basen hadde noe annet. Verkstedet lette etter et kurs som
        // ikke fantes.
        //
        // Her gjelder reglene. En drop-in-oekt teller bare med naar den svarer til en
        // aapningstid som staar oppe naa — eller naar noen faktisk har booket den,
        // for da er doeren aapen uansett hva reglene sier.
        $regler = [];
        if (DB::harTabell('dropin_tider')) {
            foreach (DB::alle('SELECT ukedag, fra, til FROM dropin_tider WHERE aktiv = 1') as $r) {
                $regler[(int) $r['ukedag'] . ' ' . substr((string) $r['fra'], 0, 5)
                      . '-' . substr((string) $r['til'], 0, 5)] = true;
            }
        }

        $okter = array_values(array_filter($okter, static function (array $o) use ($regler, $oslo, $utc): bool {
            $erDropin = (string) ($o['type'] ?? '') === 'dropin' || $o['fra_dropin_tid'] !== null;
            if (!$erDropin || (int) $o['pameldte'] > 0) {
                return true;
            }
            $start = (new DateTimeImmutable((string) $o['start_tid'], $utc))->setTimezone($oslo);
            $stopp = $o['slutt_tid'] !== null
                ? (new DateTimeImmutable((string) $o['slutt_tid'], $utc))->setTimezone($oslo)
                : null;
            $nokkel = $start->format('N') . ' ' . $start->format('H:i')
                    . '-' . ($stopp !== null ? $stopp->format('H:i') : '');

            return isset($regler[$nokkel]);
        }));

        /** @var array<string, array{fra: string, til: string}> */
        $avKurs = [];
        /**
         * Hvilke oekter hver dag er regnet av.
         *
         * Uten dette staar det «10–19» i bunnteksten og ingen kan se hvor tallene
         * kommer fra. Verkstedet spurte 27. august hvilket kurs som gikk til 19:00
         * og fant det ikke — svaret laa bare i denne utregningen.
         *
         * @var array<string, array<int, array<string, mixed>>>
         */
        $kilder = [];

        foreach ($okter as $o) {
            $start = (new DateTimeImmutable((string) $o['start_tid'], $utc))->setTimezone($oslo);
            // Mangler sluttiden, regner vi tre timer. Da er det verdt aa si fra: en
            // oekt som begynner 16:00 uten sluttid gjor dagen aapen til 19:00, og
            // det er et tall ingen har skrevet inn.
            $antattSlutt = $o['slutt_tid'] === null;
            $stopp = $o['slutt_tid'] !== null
                ? (new DateTimeImmutable((string) $o['slutt_tid'], $utc))->setTimezone($oslo)
                : $start->modify('+3 hours');
            if ($stopp <= $start) {
                $stopp = $start->modify('+3 hours');
                $antattSlutt = true;
            }

            // Et kurs som gaar over to dager gjor begge dagene aapne. Vi gaar dag for
            // dag fra start til slutt, og klipper mot dognet.
            $dag = $start->setTime(0, 0);
            $sisteDag = $stopp->setTime(0, 0);
            while ($dag <= $sisteDag) {
                $nokkel = $dag->format('Y-m-d');
                $fra = $dag->format('Y-m-d') === $start->format('Y-m-d') ? $start->format('H:i') : '00:00';
                $til = $dag->format('Y-m-d') === $stopp->format('Y-m-d') ? $stopp->format('H:i') : '23:59';
                if (!isset($avKurs[$nokkel])) {
                    $avKurs[$nokkel] = ['fra' => $fra, 'til' => $til];
                } else {
                    $avKurs[$nokkel]['fra'] = min($avKurs[$nokkel]['fra'], $fra);
                    $avKurs[$nokkel]['til'] = max($avKurs[$nokkel]['til'], $til);
                }
                // Hva slags oppforing det er, og hvor den settes.
                //
                // «Drop-in i verkstedet» er ikke et kurs man melder seg paa — det er
                // aapningstidene under Drop-in, lagt ut som bookbare oekter. Staar
                // det bare et kursnavn her, leter man etter et kurs som ikke finnes.
                $slag = 'Kursdato';
                $satt  = 'Kurs og medlemskap → kurset';
                if ((string) ($o['type'] ?? '') === 'dropin' || $o['fra_dropin_tid'] !== null) {
                    $slag = 'Drop-in-tid';
                    $satt = 'Kurs og medlemskap → Drop-in';
                } elseif ((string) ($o['tema'] ?? '') === 'Kun for medlemmer') {
                    $slag = 'Intern samling';
                    $satt = 'Medlemmer → Kurs';
                }

                $kilder[$nokkel][] = [
                    'oktId'       => (int) $o['id'],
                    'tittel'      => (string) $o['tittel'],
                    'tema'        => (string) ($o['tema'] ?? ''),
                    'slag'        => $slag,
                    'satt'        => $satt,
                    'fra'         => $fra,
                    'til'         => $til,
                    'antattSlutt' => $antattSlutt,
                ];
                $dag = $dag->modify('+1 day');
            }
        }

        // ── Overstyringene ─────────────────────────────────────────────────────────
        $manuelt = [];
        if (DB::harTabell('apningstider')) {
            foreach (DB::alle(
                'SELECT dato, stengt, fra, til, merknad FROM apningstider
                  WHERE dato >= :fra AND dato < :til',
                ['fra' => $idag->format('Y-m-d'), 'til' => $slutt->format('Y-m-d')]
            ) as $r) {
                $manuelt[(string) $r['dato']] = $r;
            }
        }

        // ── Svaret ─────────────────────────────────────────────────────────────────
        $DAG = [1 => 'Mandag', 2 => 'Tirsdag', 3 => 'Onsdag', 4 => 'Torsdag',
                5 => 'Fredag', 6 => 'Lørdag', 7 => 'Søndag'];
        $MND = [1 => 'januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];

        $ut = [];
        for ($i = 0; $i < $dagerFram; $i++) {
            $dag = $idag->modify('+' . $i . ' days');
            $nokkel = $dag->format('Y-m-d');
            $m = $manuelt[$nokkel] ?? null;
            $k = $avKurs[$nokkel] ?? null;

            // Overstyringen gaar foran. Er den ikke der, gjelder kursene.
            if ($m !== null) {
                $stengt = (int) $m['stengt'] === 1;
                $fra = $m['fra'] !== null ? substr((string) $m['fra'], 0, 5) : ($k['fra'] ?? null);
                $til = $m['til'] !== null ? substr((string) $m['til'], 0, 5) : ($k['til'] ?? null);
                // Stengt, eller satt uten tider og uten kurs: da er det ingenting
                // aa si om naar det er aapent.
                if (!$stengt && ($fra === null || $til === null)) {
                    continue;
                }
            } elseif ($k !== null) {
                $stengt = false;
                $fra = $k['fra'];
                $til = $k['til'];
            } else {
                continue;   // ingen kurs, ingen overstyring — dagen staar ikke ute
            }

            $ut[] = [
                'dato'    => $nokkel,
                'dag'     => $DAG[(int) $dag->format('N')],
                'naar'    => (int) $dag->format('j') . '. ' . $MND[(int) $dag->format('n')],
                'idag'    => $i === 0,
                'stengt'  => $stengt,
                'fra'     => $stengt ? null : $fra,
                'til'     => $stengt ? null : $til,
                'tid'     => $stengt ? 'Stengt' : $fra . '–' . $til,
                'merknad' => (string) ($m['merknad'] ?? ''),
                // Sier hva aapningstida gjelder. Verkstedet er aapent for dem som
                // gaar paa kurset — det er ikke det samme som at butikken staar aapen
                // for alle som gaar forbi.
                'hva'     => $m !== null && $m['merknad'] ? (string) $m['merknad'] : 'Kurs og events',
                // Om dagen er satt for hand framfor regnet av kursene.
                'overstyrt' => $m !== null,
                // Oektene tallene kommer av, i den rekkefolgen de gaar. Tomt naar
                // dagen er satt for hand uten at det gaar noe.
                'okter'   => array_values(array_map(
                    static fn(array $kilde) => [
                        'tittel'      => $kilde['tittel'],
                        'tema'        => $kilde['tema'],
                        'slag'        => $kilde['slag'],
                        'satt'        => $kilde['satt'],
                        'naar'        => $kilde['fra'] . '–' . $kilde['til'],
                        'antattSlutt' => $kilde['antattSlutt'],
                    ],
                    $kilder[$nokkel] ?? []
                )),
            ];
        }
        return ['dager' => $ut, 'kilder' => $kilder];
    }

    /**
     * Paint on Pots og lignende, lagt ut paa de aapne tidene.
     *
     * Lissom ba om at Paint on Pots skal kunne bookes naar hun allerede er
     * der: naar det gaar et planlagt kurs, eller naar hun har stemplet inn.
     * Da settes ikke datoene opp for haand — de folger doeren.
     *
     * Én oekt per aapen dag, med dagens aapningstid. Er verkstedet stemplet
     * inn paa en dag det ellers ikke skjer noe, settes det opp en oekt fra
     * naa og tre timer fram — for da staar doeren aapen naa.
     *
     * Ryddingen: en generert oekt som ingen har booket, og som ikke lenger
     * svarer til en aapen dag, tas bort igjen. En oekt lagt inn for haand
     * roeres aldri — den er noen sin avgjorelse.
     *
     * @return array{laget: int, fjernet: int}
     */
    public static function leggUtPaaApneTider(?DateTimeImmutable $naaInn = null): array
    {
        if (!DB::harKolonne('course_sessions', 'fra_apningstid')
            || !DB::harKolonne('courses', 'gjenstand_i_kassa')) {
            return ['laget' => 0, 'fjernet' => 0];
        }

        $kurs = DB::alle(
            "SELECT id, kapasitet FROM courses
              WHERE gjenstand_i_kassa = 1 AND status = 'publisert'"
        );
        if ($kurs === []) {
            return ['laget' => 0, 'fjernet' => 0];
        }

        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        // Klokka kan settes utenfra. Bare for testene: de maa kunne staa paa
        // et bestemt tidspunkt for aa si noe om hva som skjer klokka 14.
        $naa  = $naaInn !== null ? $naaInn->setTimezone($oslo) : new DateTimeImmutable('now', $oslo);
        $idag = $naa->format('Y-m-d');

        /** Neste kvarter. Ingen booker et kvarter som begynte for fem minutter siden. */
        $nesteKvarter = static function (DateTimeImmutable $t): DateTimeImmutable {
            $min = (int) $t->format('i');
            $opp = (int) (ceil(($min + 1) / 15) * 15);
            return $opp >= 60
                ? $t->setTime((int) $t->format('H'), 0)->modify('+1 hour')
                : $t->setTime((int) $t->format('H'), $opp);
        };

        // ── Vinduene doeren staar aapen i ──────────────────────────────────
        $vinduer = [];
        foreach (self::dager()['dager'] as $d) {
            if ($d['stengt'] || $d['fra'] === null || $d['til'] === null) {
                continue;
            }
            $vinduer[(string) $d['dato']] = ['fra' => (string) $d['fra'], 'til' => (string) $d['til']];
        }

        // Stemplet inn: doeren staar aapen naa, uansett hva kalenderen sier.
        // Er dagen ikke aapen fra for, aapnes den tre timer fram. Er den
        // aapen, men slutter for, forlenges den — noen ER der.
        $bemannet = Stempling::verkstedetBemannet();
        if ($bemannet['apen']) {
            $til = $nesteKvarter($naa)->modify('+3 hours');
            if ($til->format('Y-m-d') !== $idag) {
                $til = $naa->setTime(23, 45);
            }
            if (!isset($vinduer[$idag])) {
                $vinduer[$idag] = ['fra' => $nesteKvarter($naa)->format('H:i'), 'til' => $til->format('H:i')];
            } elseif ($vinduer[$idag]['til'] < $til->format('H:i')) {
                $vinduer[$idag]['til'] = $til->format('H:i');
            }
        }

        // ── Hva som skal staa ute ──────────────────────────────────────────
        //
        // Ett vindu per dag. Har vinduet begynt, men ikke sluttet, staar
        // doeren fortsatt aapen — da skal plassen begynne naa, ikke i
        // formiddag. Ellers ville ingen faatt booket i dag etter at dagens
        // forste kurs hadde begynt.
        $onsket = [];
        foreach ($vinduer as $dato => $v) {
            $start = new DateTimeImmutable($dato . ' ' . $v['fra'], $oslo);
            $slutt = new DateTimeImmutable($dato . ' ' . $v['til'], $oslo);
            if ($start <= $naa) {
                $start = $nesteKvarter($naa);
            }
            // Vinduet er over, eller for kort til aa vaere en plass.
            if ($slutt <= $start->modify('+15 minutes')) {
                continue;
            }
            $onsket[$dato] = [
                'start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                'slutt' => $slutt->setTimezone($utc)->format('Y-m-d H:i:s'),
            ];
        }

        $laget = 0;
        $fjernet = 0;

        foreach ($kurs as $k) {
            $kursId = (int) $k['id'];
            $skalLages = $onsket;

            // Det som alt staar ute. Vi ser paa alt som ikke er over: en oekt
            // som gaar naa er like relevant som en i morgen.
            foreach (DB::alle(
                "SELECT cs.id, cs.start_tid, cs.slutt_tid,
                        EXISTS (SELECT 1 FROM bookings b
                                 WHERE b.course_session_id = cs.id
                                   AND b.status <> 'avbestilt') AS booket
                   FROM course_sessions cs
                  WHERE cs.course_id = :c
                    AND cs.fra_apningstid = 1
                    AND COALESCE(cs.slutt_tid, cs.start_tid) > UTC_TIMESTAMP()",
                ['c' => $kursId]
            ) as $rad) {
                $dag = (new DateTimeImmutable((string) $rad['start_tid'], $utc))
                    ->setTimezone($oslo)->format('Y-m-d');

                // Stemmer sluttiden med vinduet, er oekta fortsatt riktig —
                // ogsaa naar den begynte for en time siden. Starttiden flytter
                // seg hvert kvarter saa lenge doeren staar aapen, og aa
                // slette og lage paa nytt hvert kvarter ville vaert stoy.
                $passer = isset($skalLages[$dag])
                    && (string) $rad['slutt_tid'] === $skalLages[$dag]['slutt'];

                if ($passer) {
                    unset($skalLages[$dag]);
                    continue;
                }
                // Har noen booket, staar den. Plassen er noen sin.
                if ((int) $rad['booket'] === 1) {
                    unset($skalLages[$dag]);
                    continue;
                }
                DB::kjor('DELETE FROM course_sessions WHERE id = :i', ['i' => (int) $rad['id']]);
                $fjernet++;
            }

            // INSERT IGNORE fordi (course_id, start_tid) er unik: staar det
            // alt en oekt lagt inn for haand paa samme klokkeslett, er det
            // den som gjelder.
            foreach ($skalLages as $v) {
                $laget += DB::kjor(
                    'INSERT IGNORE INTO course_sessions
                        (course_id, start_tid, slutt_tid, kapasitet, status, fra_apningstid)
                     VALUES (:c, :s, :e, :k, \'planlagt\', 1)',
                    ['c' => $kursId, 's' => $v['start'], 'e' => $v['slutt'],
                     'k' => max(1, (int) $k['kapasitet'])]
                )->rowCount();
            }
        }

        return ['laget' => $laget, 'fjernet' => $fjernet];
    }

    /**
     * Er verkstedet aapent denne dagen, og naar?
     *
     * Svarer null naar dagen ikke staar ute, eller staar som stengt. Brukes
     * av bookingen: en plass kan bare settes opp i et vindu som faktisk er
     * aapent.
     *
     * @return array{fra: string, til: string}|null
     */
    public static function vindu(string $dato, int $dagerFram = self::DAGER_FRAM): ?array
    {
        foreach (self::dager($dagerFram)['dager'] as $d) {
            if ($d['dato'] !== $dato) {
                continue;
            }
            if ($d['stengt'] || $d['fra'] === null || $d['til'] === null) {
                return null;
            }
            return ['fra' => (string) $d['fra'], 'til' => (string) $d['til']];
        }
        return null;
    }
}
