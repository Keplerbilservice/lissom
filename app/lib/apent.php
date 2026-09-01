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
 * og drop-in legges ut paa de aapne vinduene; talte de med, ville verkstedet
 * holdt seg aapent av sin egen skygge.
 */

declare(strict_types=1);

final class Apent
{
    /** Hvor mange dager fram vi svarer for. */
    public const DAGER_FRAM = 14;

    /**
     * Hvor lenge én plass varer.
     *
     * Lissom 27. august: «endre drop in og paint on pots fra 2 timer, til
     * 1,5 timer». Gjelder begge — de deler den samme bestillingen.
     *
     * Ikke aa forveksle med drop-in-tidene 10-13 under Kurs og medlemskap →
     * Drop-in. De sier naar doeren staar aapen, ikke hvor lenge man sitter.
     */
    public const PLASS_MINUTTER = 90;

    /**
     * Hoyst saa mange plasser per dag.
     *
     * Bookingen viser tre dager, og tidspunktene under den dagen man velger.
     * Kortet paa sida er ett uansett — dagene og tidene staar inne i
     * bestillingen — saa taket er ikke for aa spare plass paa skjermen.
     *
     * Aatte plasser à halvannen time er tolv timer. Det er lengre enn en dag
     * i verkstedet noen gang varer, og da er det aapningstida som setter
     * grensa, ikke dette tallet. Sto det lavere, ville kvelden falt bort paa
     * en lang dag.
     */
    public const PLASSER_PER_DAG = 8;

    /**
     * Vakten for et kurs med sitt eget vindu.
     *
     * Der er det vinduet som bestemmer — 08 til 22 med halvannen time per
     * plass blir ni. Dette tallet er bare der saa en feil i et klokkeslett
     * ikke kan lage tusen rader i basen.
     */
    public const PLASSER_TAK = 24;

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

        // Ferie. Er verkstedet stengt den dagen, er det ikke aapent heller —
        // aapningstidene lages av kursene, saa de maa foelge med naar kursene
        // blir borte. Ellers ville nettsida sagt «aapent 10–13» paa en dag
        // ingen er der.
        $okter = Ferie::utenom($okter);

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

            // Et kurs som gaar over flere dager gjor alle dagene aapne.
            //
            // Her ble hver dag klippet mot dognet: dag to sto som aapen fra
            // 00:00. Et dreiekurs 17-20 over to dager gjorde dermed natta til
            // dag to «aapen», og bunnteksten sa 00:00-20:00. Da Paint on Pots
            // ble lagt ut paa aapningstidene, ble natta bookbar ogsaa.
            //
            // Et flerdagerskurs gaar de samme klokkeslettene hver dag — det er
            // det samme kurset to kvelder, ikke ett som varer i 27 timer.
            // Varigheten regnes alt slik («3 timer per gang · 2 ganger»).
            //
            // Slutter oekta FOER den begynte paa dogneet, er den ekte nattevakt
            // — 22:00 til 02:00 — og da klipper vi mot dognet som for.
            $overNatta = $stopp->format('H:i') <= $start->format('H:i');
            $dag = $start->setTime(0, 0);
            $sisteDag = $stopp->setTime(0, 0);
            while ($dag <= $sisteDag) {
                $nokkel = $dag->format('Y-m-d');
                $fra = $overNatta
                    ? ($dag->format('Y-m-d') === $start->format('Y-m-d') ? $start->format('H:i') : '00:00')
                    : $start->format('H:i');
                $til = $overNatta
                    ? ($dag->format('Y-m-d') === $stopp->format('Y-m-d') ? $stopp->format('H:i') : '23:59')
                    : $stopp->format('H:i');
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
     * Paint on Pots, drop-in og lignende, lagt ut paa de aapne tidene.
     *
     * Lissom ba om at Paint on Pots skal kunne bookes naar hun allerede er
     * der: naar det gaar et planlagt kurs, eller naar hun har stemplet inn.
     * Den 27. august kom drop-in med paa det samme — samme bestilling, med
     * datoer og tider, og tilgjengeligheten folger kursene og innstemplinga.
     *
     * Hvilke kurs det gjelder staar i courses.folger_apningstid. Foer sto det
     * i gjenstand_i_kassa, som gjorde to jobber paa én gang: «gjenstanden
     * betales i verkstedet» OG «datoene lages av aapningstidene». Drop-in
     * betales paa nett som for, og trengte bare den andre halvdelen.
     *
     * Plassene klippes ut av den aapne tida, halvannen time om gangen —
     * ogsaa timene mellom to kurs, for da er hun der. Er verkstedet stemplet
     * inn paa en dag det ellers ikke skjer noe, aapnes det tre timer fram.
     *
     * Kursets egne oekter gaar foran: gaar drop-in 10-13 paa tirsdag fra
     * ukereglene, lages det ingen plass oppi den. Ellers ville den samme
     * timen ligget to ganger i bestillingen, med hvert sitt plasstall.
     *
     * Ryddingen: en generert oekt som ingen har booket, og som ikke lenger
     * svarer til en aapen dag, tas bort igjen. En oekt lagt inn for haand
     * roeres aldri — den er noen sin avgjorelse.
     *
     * @return array{laget: int, fjernet: int}
     */
    public static function leggUtPaaApneTider(?DateTimeImmutable $naaInn = null): array
    {
        if (!DB::harKolonne('course_sessions', 'fra_apningstid')) {
            return ['laget' => 0, 'fjernet' => 0];
        }

        // folger_apningstid kom med migrasjon 079. Er den ikke kjoert, gjelder
        // det gamle feltet — da staar Paint on Pots ute som for.
        $felt = DB::harKolonne('courses', 'folger_apningstid')
            ? 'folger_apningstid'
            : (DB::harKolonne('courses', 'gjenstand_i_kassa') ? 'gjenstand_i_kassa' : null);
        if ($felt === null) {
            return ['laget' => 0, 'fjernet' => 0];
        }

        // Kurs med sitt eget vindu staar hver dag mellom to klokkeslett,
        // uavhengig av kurs og aapningstider. Se «Drop-in foelger sitt eget
        // vindu» lenger nede, og migrasjon 102.
        $fast = DB::harKolonne('courses', 'fast_fra') && DB::harKolonne('courses', 'fast_til');
        $kurs = DB::alle(
            'SELECT id, kapasitet' . ($fast ? ', fast_fra, fast_til' : '') . " FROM courses
              WHERE {$felt} = 1 AND status = 'publisert'"
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
        //
        // Hele dagen, fra det forste begynner til det siste slutter. Det er
        // det samme spennet som staar i bunnteksten paa nettsiden.
        //
        // Timene mellom to kurs teller med. Lissom 27. august: «husk tiden
        // som er mellom kurs ogsaa skal vaere tilgjengelig aa booke» — gaar
        // det et kurs 10-13 og et til 16-19, er hun der hele dagen, og da
        // skal noen kunne sette seg ned klokka 14.
        //
        // Her ble hullet stengt en periode. Det var feil vei: det gjorde en
        // dag hun uansett er i huset mindre bookbar enn en dag hun kommer
        // innom en time.
        $alt = self::dager();
        $vinduer = [];
        foreach ($alt['dager'] as $d) {
            if ($d['stengt'] || $d['fra'] === null || $d['til'] === null) {
                continue;
            }
            $vinduer[(string) $d['dato']] = [['fra' => (string) $d['fra'], 'til' => (string) $d['til']]];
        }

        // Stemplet inn: doeren staar aapen naa, uansett hva kalenderen sier.
        // Er dagen ikke aapen fra for, aapnes den tre timer fram. Er den
        // aapen, men slutter for, forlenges den — noen ER der.
        $bemannet = Stempling::verkstedetBemannet();
        if ($bemannet['apen']) {
            $fra = $nesteKvarter($naa);
            $til = $fra->modify('+3 hours');
            if ($til->format('Y-m-d') !== $idag) {
                $til = $naa->setTime(23, 45);
            }
            if ($til > $fra) {
                $vinduer[$idag][] = ['fra' => $fra->format('H:i'), 'til' => $til->format('H:i')];

                // Henger innstemplinga sammen med dagen fra for, er det én
                // periode. Er den ikke det — kurs 10-13, stemplet inn 18 —
                // staar de hver for seg: hun var der om formiddagen og er
                // der naa, men ikke i mellomtida.
                usort($vinduer[$idag], static fn(array $a, array $b): int => strcmp($a['fra'], $b['fra']));
                $slaatt = [];
                foreach ($vinduer[$idag] as $v2) {
                    $siste = $slaatt === [] ? null : array_key_last($slaatt);
                    if ($siste !== null && $v2['fra'] <= $slaatt[$siste]['til']) {
                        $slaatt[$siste]['til'] = max($slaatt[$siste]['til'], $v2['til']);
                        continue;
                    }
                    $slaatt[] = $v2;
                }
                $vinduer[$idag] = array_values($slaatt);
            }
        }

        $laget = 0;
        $fjernet = 0;

        foreach ($kurs as $k) {
            $kursId = (int) $k['id'];

            // ── Kursets eget vindu ─────────────────────────────────────────
            //
            // Eieren, 30. august, om drop-in: «det skal ikke foelge kurs
            // eller aapningstider» og «det skal kunne bookes tid mellom kl
            // 08:00 og 22:00».
            //
            // Sto drop-in paa aapningstidene, var den bare bookbar de dagene
            // det tilfeldigvis gikk et kurs — for det er kursene som lager
            // aapningstida. Det er den motsatte logikken av hva drop-in er.
            //
            // Med to klokkeslett paa kurset staar det hver dag, DAGER_FRAM
            // dager fram, uten aa spoerre noen. Innstemplinga teller ikke:
            // vinduet er avgjort paa forhaand.
            $egetVindu = null;
            if (($k['fast_fra'] ?? null) !== null && ($k['fast_til'] ?? null) !== null) {
                $fra = substr((string) $k['fast_fra'], 0, 5);
                $til = substr((string) $k['fast_til'], 0, 5);
                if ($fra < $til) {
                    $egetVindu = [];
                    for ($d = 0; $d <= self::DAGER_FRAM; $d++) {
                        $dag = $naa->modify('+' . $d . ' days')->format('Y-m-d');
                        $egetVindu[$dag] = [['fra' => $fra, 'til' => $til]];
                    }
                }
            }
            $mineVinduer = $egetVindu ?? $vinduer;
            // Taket paa plasser per dag er satt for aapningstidene, der
            // vinduet aldri blir lengre enn en arbeidsdag. Et eget vindu paa
            // fjorten timer er lengre enn det, og da skal vinduet bestemme —
            // ikke et tall som var ment som en vakt.
            $takPerDag = $egetVindu === null ? self::PLASSER_PER_DAG : self::PLASSER_TAK;

            // Kursets egne oekter, lagt inn for haand eller av ukereglene.
            // De gaar foran: det lages ingen plass oppi en tid kurset alt
            // staar med.
            $egne = [];
            foreach (DB::alle(
                'SELECT start_tid, slutt_tid FROM course_sessions
                  WHERE course_id = :c AND fra_apningstid = 0
                    AND COALESCE(slutt_tid, start_tid) > UTC_TIMESTAMP()',
                ['c' => $kursId]
            ) as $rad) {
                $s0 = new DateTimeImmutable((string) $rad['start_tid'], $utc);
                $egne[] = [
                    'start' => $s0,
                    'slutt' => $rad['slutt_tid'] !== null
                        ? new DateTimeImmutable((string) $rad['slutt_tid'], $utc)
                        : $s0->modify('+' . self::PLASS_MINUTTER . ' minutes'),
                ];
            }

            // ── Hva som skal staa ute ──────────────────────────────────────
            //
            // Den aapne tida klippes i plasser paa halvannen time, saa folk
            // har noe aa velge mellom paa en lang dag.
            //
            // Hoyst PLASSER_PER_DAG per dag — se konstanten: taket ligger
            // over den lengste dagen verkstedet har, saa det er aapningstida
            // som bestemmer, ikke tallet.
            $skalLages = [];
            foreach ($mineVinduer as $dato => $perioder) {
                $paaDagen = 0;
                foreach ($perioder as $v) {
                    if ($paaDagen >= $takPerDag) {
                        break;
                    }
                    $start = new DateTimeImmutable($dato . ' ' . $v['fra'], $oslo);
                    $slutt = new DateTimeImmutable($dato . ' ' . $v['til'], $oslo);
                    // En periode som har begynt staar fortsatt aapen. Da
                    // begynner plassen naa, ikke i formiddag.
                    if ($start <= $naa) {
                        $start = $nesteKvarter($naa);
                    }
                    while ($start < $slutt && $paaDagen < $takPerDag) {
                        $til = $start->modify('+' . self::PLASS_MINUTTER . ' minutes');
                        // Hele lengden, eller ingenting.
                        //
                        // Her ble resten av vinduet klippet til det som var
                        // igjen, og en aapen periode 10-13 ga en halvtime
                        // 12:30-13. Kunden velger et tidspunkt og har bordet
                        // halvannen time — da skal det ikke ligge en halvtime
                        // paa lista som ser ut som de andre.
                        if ($til > $slutt) {
                            break;
                        }
                        // Staar kurset alt med en tid som overlapper, er det
                        // den som gjelder. Ellers laa den samme timen to
                        // ganger i bestillingen, med hvert sitt plasstall.
                        $kroken = false;
                        foreach ($egne as $e) {
                            if ($start < $e['slutt'] && $e['start'] < $til) {
                                $kroken = true;
                                $start = $e['slutt']->setTimezone($oslo);
                                break;
                            }
                        }
                        if ($kroken) {
                            continue;
                        }
                        $skalLages[$start->setTimezone($utc)->format('Y-m-d H:i:s')] = [
                            'dag'   => $dato,
                            'start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                            'slutt' => $til->setTimezone($utc)->format('Y-m-d H:i:s'),
                        ];
                        $paaDagen++;
                        $start = $til;
                    }
                }
            }

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
                $s = (string) $rad['start_tid'];

                // Staar plassen alt der, med samme start og slutt, er den
                // riktig og skal bli staaende.
                if (isset($skalLages[$s]) && (string) $rad['slutt_tid'] === $skalLages[$s]['slutt']) {
                    unset($skalLages[$s]);
                    continue;
                }

                // Har noen booket, staar den uansett. Plassen er noen sin, og
                // den skal ikke forsvinne under foettene paa dem.
                if ((int) $rad['booket'] === 1) {
                    unset($skalLages[$s]);
                    continue;
                }

                // En plass som har begynt, men ikke sluttet, staar. Doeren er
                // aapen naa, og starttiden flytter seg hvert kvarter — aa
                // slette og lage den paa nytt hvert kvarter ville vaert stoy.
                $start = new DateTimeImmutable($s, $utc);
                $slutt = new DateTimeImmutable((string) ($rad['slutt_tid'] ?? $s), $utc);
                $naaUtc = new DateTimeImmutable('now', $utc);
                if ($start <= $naaUtc && $slutt > $naaUtc) {
                    $dagen = $start->setTimezone($oslo)->format('Y-m-d');
                    foreach (array_keys($skalLages) as $n) {
                        if ($skalLages[$n]['dag'] === $dagen) {
                            unset($skalLages[$n]);
                            break;
                        }
                    }
                    continue;
                }

                DB::kjor('DELETE FROM course_sessions WHERE id = :i', ['i' => (int) $rad['id']]);
                $fjernet++;
            }

            // INSERT IGNORE fordi (course_id, start_tid) er unik: staar det
            // alt en oekt lagt inn for haand paa samme klokkeslett, er det
            // den som gjelder.
            // Kursholderen: den som staar paa kurset, ellers verkstedets
            // standard. Ett oppslag for alle datoene — det er samme kurs.
            $harHolder = Kursholder::klar();
            $holder    = $harHolder ? Kursholder::forKurs($kursId) : null;

            foreach ($skalLages as $v) {
                $verdier = ['c' => $kursId, 's' => $v['start'], 'e' => $v['slutt'],
                            'k' => max(1, (int) $k['kapasitet'])];
                $ekstraKol = '';
                $ekstraVal = '';
                if ($harHolder) {
                    $ekstraKol = ', kursholder_id';
                    $ekstraVal = ', :h';
                    $verdier['h'] = $holder;
                }
                $laget += DB::kjor(
                    'INSERT IGNORE INTO course_sessions
                        (course_id, start_tid, slutt_tid, kapasitet, status, fra_apningstid' . $ekstraKol . ')
                     VALUES (:c, :s, :e, :k, \'planlagt\', 1' . $ekstraVal . ')',
                    $verdier
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
