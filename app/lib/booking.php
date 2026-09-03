<?php
/**
 * Booking av kurs og events.
 *
 * Prinsippet gjennom hele fila: nettleseren sier HVA som skal bookes, aldri
 * HVA DET KOSTER. Prisen slås opp i databasen hver gang. Ellers kunne hvem som
 * helst endret beløpet i utviklerverktøyet før betaling.
 */

declare(strict_types=1);

final class Booking
{
    /** Ubetalte reservasjoner holdes så lenge, så plassen ikke låses av folk som ombestemte seg. */
    private const RESERVASJON_MINUTTER = 20;

    /**
     * Hvor mange plasser er igjen på en økt?
     *
     * Reserverte plasser teller med — ellers kunne to personer betalt for den
     * siste plassen samtidig.
     */
    /**
     * Hvor mange av hver ressurs verkstedet har.
     *
     * Eieren, 30. august: «vi maa tenke at alle disse har tilgang til de
     * samme 8 dreieskivene», og «1 dreieskive = 1 ressurs = 1 plass, 1
     * kursplass = 1 ressurs = 1 plass».
     *
     * Tallene staar i en tabell og ikke i koden: «maa kunne endre, slette og
     * legge til for aa mote endringer i verkstedet». De endres under
     * Verkstedet → Ressurser.
     *
     * En ressurs som er satt inaktiv teller ikke — da er det bare kursets
     * eget plasstall som gjelder, som for.
     *
     * Taaler at migrasjon 103 ikke er kjoert: da er det ingen tak, og
     * regnestykket er som for.
     *
     * @return array<int, int> ressursId => antall
     */
    public static function verkstedTak(): array
    {
        if (self::$tak !== null) {
            return self::$tak;
        }
        self::$tak = [];
        try {
            foreach (DB::alle('SELECT id, antall FROM ressurser WHERE aktiv = 1') as $r) {
                if ((int) $r['antall'] > 0) {
                    self::$tak[(int) $r['id']] = (int) $r['antall'];
                }
            }
        } catch (Throwable $e) {
            self::$tak = [];
        }
        return self::$tak;
    }

    /** Leses paa nytt naar noen har endret tallene. */
    public static function glemTak(): void
    {
        self::$tak = null;
    }

    /** @var array<int, int>|null */
    private static ?array $tak = null;

    /**
     * Ressursen innstemplede medlemmer teller mot.
     *
     * Et medlem som stempler inn sier ikke hva det skal gjore. Eieren ville
     * likevel ha dem trukket fra, og da maa de telle et sted. De teller mot
     * dreieskivene: det er den knappe ressursen, og en plass for mye holdt av
     * er bedre enn en skive solgt to ganger.
     *
     * Slaas opp paa navn, ikke paa id: en ressurs kan vaere slettet og laget
     * paa nytt. Finnes den ikke, teller de ingen steder.
     */
    private static function skiveRessurs(): int
    {
        if (self::$skive !== null) {
            return self::$skive;
        }
        try {
            self::$skive = (int) (DB::verdi(
                "SELECT id FROM ressurser WHERE navn = 'Dreieskive' AND aktiv = 1"
            ) ?? 0);
        } catch (Throwable $e) {
            self::$skive = 0;
        }
        return self::$skive;
    }

    /** @var int|null */
    private static ?int $skive = null;

    /**
     * Hvor mange medlemmer som staar innstemplet naa, per ressurs.
     *
     * Eieren, 30. august: «kunne det voere lost om de booker inn og velger
     * dreieskive, eller verkstedplass». For dette gjettet regnestykket at
     * enhver innstemplet sto ved en skive, og et medlem som haandbygget ved
     * bordet holdt av en skive ingen brukte. Naa sier medlemmet det selv naar
     * det stempler inn fra Min side.
     *
     * Rader uten valg — oekter som alt sto aapne da dette ble lagt ut —
     * teller mot skivene som for. Det er den gamle gjetningen, og den staar
     * bare til de har stemplet ut.
     *
     * Ett oppslag per foresporsel: ledigePlasserFlere kalles med hele
     * katalogen om gangen, og tallene er de samme for alle oektene.
     *
     * @return array<int, int> ressursId => antall
     */
    private static function inneNaa(): array
    {
        if (self::$inne !== null) {
            return self::$inne;
        }
        self::$inne = [];
        try {
            $felt = DB::harKolonne('check_ins', 'ressurs_id') ? 'ressurs_id' : 'NULL';
            foreach (DB::alle(
                "SELECT {$felt} AS r, COUNT(*) AS n FROM check_ins
                  WHERE ut_tid IS NULL GROUP BY r"
            ) as $rad) {
                $r = $rad['r'] === null ? self::skiveRessurs() : (int) $rad['r'];
                if ($r > 0) {
                    self::$inne[$r] = (self::$inne[$r] ?? 0) + (int) $rad['n'];
                }
            }
        } catch (Throwable $e) {
            self::$inne = [];
        }
        return self::$inne;
    }

    /** @var array<int, int>|null */
    private static ?array $inne = null;

    public static function ledigePlasser(int $oktId, bool $medLaas = false): int
    {
        // Med $medLaas laases okta for resten av transaksjonen. Uten den leser
        // to samtidige bookinger det samme oyeblikksbildet — begge ser den
        // siste plassen ledig, og begge far den. Kontrollen inne i
        // transaksjonen hjelper ikke naar lesningen ikke tar laas.
        //
        // Utenfor en transaksjon gir FOR UPDATE ingen mening; visningen av
        // «3 plasser igjen» skal heller ikke laase noe.
        if ($medLaas && DB::kobling()->inTransaction()) {
            DB::en('SELECT id FROM course_sessions WHERE id = :id FOR UPDATE', ['id' => $oktId]);
        }

        // Regnestykket staar ett sted, i ledigePlasserFlere. Sto det ogsaa
        // her, ville de to kunnet svare forskjellig — og den ene brukes til
        // aa vise «3 plasser igjen», den andre til aa selge den siste stolen.
        return self::ledigePlasserFlere([$oktId])[$oktId] ?? 0;
    }

    /**
     * Ledige plasser paa mange okter i én sporring.
     *
     * Katalogen viser hver eneste kursdato med «N plasser igjen». Ett kall per
     * dato ble tre sporringer per dato: 83 datoer ga 249 sporringer paa én
     * sidevisning, og tallet vokser med hver dato som legges ut. Etter at
     * Paint on Pots begynte aa folge aapningstidene lages datoene
     * av seg selv, og da vokser det fort.
     *
     * Samme regnestykke som for, i ett svar:
     *
     *   kapasitet (oktas egen, ellers kursets)
     *   − plasser tatt utenfor nettsiden (manuelt_opptatt)
     *   − betalte og gyldige reservasjoner
     *
     * En reservasjon som er lagt inn for haand har ingen frist — den frigis
     * ikke av seg selv. Uten «reservert_til IS NULL» ville nettsiden solgt
     * plassen til noen som staar i verkstedets egen bok.
     *
     * @param list<int> $oktIder
     * @return array<int, int> oktId => ledige
     */
    /**
     * Fristen for full refusjon, i timer.
     *
     * To doegn, fra kursvilkaarene. Staar som et tall med navn saa den kan
     * finnes igjen — den sto for som «14 * 24» midt i en if-setning i et
     * endepunkt.
     */
    private const AVBESTILLING_TIMER = 2 * 24;

    public static function ledigePlasserFlere(array $oktIder): array
    {
        // Overstyringa ligger her, utenpaa hele regnestykket, og ikke inne i
        // de to veiene under. Sto den to steder, ville de etter hvert svart
        // hver sitt — og den ene som ble glemt er reserveveien, som bare
        // kjorer paa en base uten ressurstabellen. Da hadde feilen vaert
        // usynlig helt til den dagen den ikke var det.
        return self::medVisFullt(self::ledigeRegnet($oktIder));
    }

    /** Regnestykket, uten overstyringa. Se ledigePlasserFlere(). */
    private static function ledigeRegnet(array $oktIder): array
    {
        $ider = array_values(array_unique(array_map('intval', $oktIder)));
        if ($ider === []) {
            return [];
        }

        // Ingen navngitte parametre i en IN-liste: heltallene er allerede
        // castet med intval over, saa de kan staa i SQL-en.
        $inn = implode(',', $ider);

        // ── Er de delte ressursene der i det hele tatt? ─────────────────
        //
        // Kolonna og tabellen kom med migrasjon 103. Utlegginga av koden og
        // kjoringa av migrasjonen skjer ikke i samme sekund, og i vinduet
        // imellom fantes ikke kolonna.
        //
        // Det kostet fire femhundre-feil paa lissom.no 31. august 04:32 —
        // /api/kurs.php, /api/admin/kurs.php, /api/admin/pameldte.php og
        // /api/admin/venteliste.php, alle fire i samme minutt, alle fire
        // fordi de regner ledige plasser. Kurslista var nede for alle mens
        // det sto paa.
        //
        // Resten av kodebasen spor alltid foerst — se $oppsettFelt,
        // $bilderFelt og $apenFelt i api/kurs.php. Her gjorde jeg det ikke.
        // Uten kolonna gjelder det gamle regnestykket: hver oekt for seg.
        $delteRessurser = DB::harKolonne('courses', 'ressurs_id')
            && DB::harTabell('ressurser');
        if (!$delteRessurser) {
            return self::ledigeUtenRessurser($inn, $ider);
        }

        // Aktiv booking: betalt, eller reservert og ikke gaatt ut paa tid.
        // Staar to steder i spoerringa under — paa oekta selv og paa alle de
        // andre som deler ressursen — og maa vaere den samme begge steder.
        $aktiv = "(b.status = 'betalt'
                   OR (b.status = 'reservert'
                       AND (b.reservert_til IS NULL
                            OR b.reservert_til > UTC_TIMESTAMP())))";
        $aktiv2 = str_replace('b.', 'b2.', $aktiv);

        // Slutt-tida naar den mangler.
        //
        // En oekt uten sluttid har ingen lengde, og da ville den ikke
        // overlappe noe som helst — et kurs uten sluttid ville stille frigitt
        // alle skivene. Tre timer er en kveld i verkstedet, og en gjetning
        // som holder plassen er bedre enn en null som gir den bort.
        $slutt  = 'COALESCE(cs.slutt_tid,  cs.start_tid  + INTERVAL 3 HOUR)';
        $slutt2 = 'COALESCE(cs2.slutt_tid, cs2.start_tid + INTERVAL 3 HOUR)';

        // ── Naar to oekter er i veien for hverandre ──────────────────────
        //
        // Et flerdagerskurs ligger som ÉN rad: «Nybegynner dreiekurs» staar
        // med 9. september 17:00 → 10. september 20:00. Det er ikke
        // syvogtyve timer i verkstedet, det er to kvelder á tre — akkurat den
        // fella Kursmal::varighetAv alt kjenner.
        //
        // Regnet rett fram holdt kurset tre dreieskiver opptatt gjennom natta
        // og hele torsdag formiddag, og en oekt torsdag klokka aatte sto med
        // fem ledige uten at noe skjedde i huset. Sett paa lissom.no like
        // etter at dette ble lagt ut.
        //
        // Derfor to proever, ikke én: datoene maa mötes, og klokkeslettene
        // maa mötes. To kvelder 17-20 er i veien for hverandre; en kveld
        // 17-20 og en formiddag 08-09:30 er det ikke, selv om raden spenner
        // over begge.
        //
        // Krysser en oekt midnatt uten aa vare flere dager — 22:00 til 01:00
        // — er klokkevinduet snudd. Da er den sammenhengende, og teller hele
        // doegnet framfor aa regnes bort.
        $vindu = static function (string $a, string $slutt): array {
            return [
                'fraDato'   => "DATE({$a}.start_tid)",
                'tilDato'   => "DATE({$slutt})",
                'fraKlokke' => "IF(TIME({$slutt}) > TIME({$a}.start_tid), TIME({$a}.start_tid), '00:00:00')",
                'tilKlokke' => "IF(TIME({$slutt}) > TIME({$a}.start_tid), TIME({$slutt}), '23:59:59')",
            ];
        };
        $v1 = $vindu('cs', $slutt);
        $v2 = $vindu('cs2', $slutt2);
        $iVeien = "{$v1['fraDato']} <= {$v2['tilDato']} AND {$v2['fraDato']} <= {$v1['tilDato']}"
                . " AND {$v1['fraKlokke']} < {$v2['tilKlokke']} AND {$v2['fraKlokke']} < {$v1['tilKlokke']}";

        $tak = self::verkstedTak();
        $inneNa = self::inneNaa();
        $naa = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $ut = [];
        foreach (DB::alle(
            "SELECT cs.id, cs.start_tid, {$slutt} AS slutt_reell, c.ressurs_id,
                    GREATEST(0,
                        COALESCE(cs.kapasitet, c.kapasitet)
                        - COALESCE(cs.manuelt_opptatt, 0)
                        - COALESCE((SELECT SUM(b.antall) FROM bookings b
                                     WHERE b.course_session_id = cs.id
                                       AND {$aktiv}), 0)
                    ) AS ledige,
                    -- Alt ANNET som legger beslag paa den samme ressursen
                    -- samtidig. Aatte skiver er aatte skiver enten de sitter
                    -- paa et dreiekurs eller en Date Night.
                    --
                    -- Et planlagt kurs holder plasstallet sitt, ikke bare de
                    -- solgte plassene. Eieren, 30. august: «det maa ikke vaere
                    -- mulig aa booke en plass eller dreieskive paa forhaand for
                    -- medlemmer naar det er planlagt kurs. Da er de ressursene
                    -- booket og opptatt med kurs.» Et dreiekurs med aatte
                    -- plasser tar alle aatte skivene i den tida det gaar, ogsaa
                    -- for noen har meldt seg paa — skivene staar dekket til
                    -- kurset.
                    --
                    -- Med ett unntak, og det er avgjorende: de aapne plassene
                    -- (fra_apningstid = 1 — Paint on Pots) holder
                    -- bare det som faktisk er booket. De er et tilbud, ikke en
                    -- plan. Holdt de plasstallet sitt ogsaa, ville en tom
                    -- aapen plass paa aatte sperret dreiekurset ved siden av,
                    -- og de to hadde tatt livet av hverandre.
                    COALESCE((
                        SELECT SUM(
                            GREATEST(
                                CASE WHEN cs2.fra_apningstid = 1 THEN 0
                                     ELSE COALESCE(cs2.kapasitet, c2.kapasitet) END,
                                COALESCE(cs2.manuelt_opptatt, 0)
                                + COALESCE((SELECT SUM(b2.antall) FROM bookings b2
                                             WHERE b2.course_session_id = cs2.id
                                               AND {$aktiv2}), 0)
                            ))
                          FROM course_sessions cs2
                          JOIN courses c2 ON c2.id = cs2.course_id
                         WHERE cs2.status = 'planlagt'
                           AND c2.status <> 'avlyst'
                           AND cs2.id <> cs.id
                           AND c2.ressurs_id = c.ressurs_id
                           AND ({$iVeien})
                    ), 0) AS brukt_ressurs,
                    -- Det oekta selv legger beslag paa. Her teller bare det
                    -- som er booket: sporsmalet er hvor mange FLERE den kan ta
                    -- imot, og da kan den ikke sperre for seg selv.
                    COALESCE(cs.manuelt_opptatt, 0)
                    + COALESCE((SELECT SUM(b.antall) FROM bookings b
                                 WHERE b.course_session_id = cs.id
                                   AND {$aktiv}), 0) AS eget_beslag
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt' AND cs.id IN ({$inn})"
        ) as $r) {
            // Uten ressurs — eller med en som er satt inaktiv — gjelder bare
            // kursets eget plasstall, slik det gjorde for 30. august.
            $ressurs = $r['ressurs_id'] === null ? 0 : (int) $r['ressurs_id'];
            if (!isset($tak[$ressurs])) {
                $ut[(int) $r['id']] = (int) $r['ledige'];
                self::$sperret[(int) $r['id']] = false;
                continue;
            }
            $igjen = $tak[$ressurs] - (int) $r['brukt_ressurs'] - (int) $r['eget_beslag'];

            // Medlemmer booker ikke — de stempler inn naar de kommer. De
            // teller derfor bare paa en oekt som gaar akkurat naa; en booking
            // om tre dager kan ikke vite hvem som moeter opp.
            //
            // Hver enkelt teller mot det den selv valgte ved innstemplinga.
            if (($inneNa[$ressurs] ?? 0) > 0) {
                $start = new DateTimeImmutable((string) $r['start_tid'], new DateTimeZone('UTC'));
                $slu   = new DateTimeImmutable((string) $r['slutt_reell'], new DateTimeZone('UTC'));
                if ($start <= $naa && $slu > $naa) {
                    $igjen -= $inneNa[$ressurs];
                }
            }

            $ut[(int) $r['id']] = max(0, min((int) $r['ledige'], $igjen));
            // Full fordi noe annet holder ressursen, ikke fordi noen har
            // booket her. «Fullbooket» paa en aapen time der det ikke er én
            // booking, men et kurs som opptar skivene, er en liten loegn — og
            // den gir en telefon. Se ledigTekst() i nettsida.
            self::$sperret[(int) $r['id']] =
                $ut[(int) $r['id']] === 0 && (int) $r['eget_beslag'] === 0
                && (int) $r['ledige'] > 0;
        }

        // En okt som ikke er «planlagt» — avlyst, eller som ikke finnes — har
        // ingen ledige plasser. Den staar ikke i svaret over, og skal svare 0
        // slik den gjorde da hvert kall sto for seg.
        foreach ($ider as $i) {
            $ut[$i] ??= 0;
        }

        return $ut;
    }

    /**
     * Oekter verkstedet har satt til «fullbooket» for haand.
     *
     * Eieren, 3. september: «pillen paa kortet som viser hvor mange plasser
     * det er, denne vil jeg ha mulighet til aa overstyre med en hake paa
     * kortet saa det staar fullbooket eller fult eller saa klart likt som de
     * andre fulle kursene».
     *
     * Null ledige er alt som skal til: ledigTekst() i nettsida gjor null om
     * til «Fullbooket», og den er det ene stedet pilla skrives — paa kortene,
     * i datovelgeren, paa kurssida og i medlemslista. Da staar det ordrett
     * det samme som paa et kurs som faktisk er fullt.
     *
     * Sperren mot aa booke folger med. Den regner paa det samme tallet, saa
     * en dato som VISES full kan heller ikke fylles opp bakveien.
     *
     * Ligger her, etter begge regnestykkene, og ikke inne i de to
     * SQL-setningene: to steder ville etter hvert svart hver sitt.
     *
     * @param  array<int, int> $ledige
     * @return array<int, int>
     */
    private static function medVisFullt(array $ledige): array
    {
        if ($ledige === [] || !DB::harKolonne('course_sessions', 'vis_fullt')) {
            // Kolonna kom med oppdatering 137. Kjores den ikke, er det ingen
            // oekter aa overstyre — og oppslaget skal ikke velte kurslista i
            // vinduet mellom utlegging og oppdatering. Det kostet fire
            // femhundre-feil paa lissom.no 31. august.
            return $ledige;
        }
        $inn = implode(',', array_map('intval', array_keys($ledige)));
        foreach (DB::alle(
            "SELECT id FROM course_sessions WHERE vis_fullt = 1 AND id IN ({$inn})"
        ) as $r) {
            $ledige[(int) $r['id']] = 0;
            // «Sperret» ville gitt «Kurs i verkstedet» i stedet for
            // «Fullbooket» — se ledigTekst(). Her er det verkstedet selv som
            // har sagt at datoen er full, og da skal den se ut som full.
            self::$sperret[(int) $r['id']] = false;
        }
        return $ledige;
    }

    /**
     * Hvor mye av det betalte som skal tilbake ved avbestilling.
     *
     * Eieren, 3. september, med kursvilkaarene sine: «Ved avbestilling mer
     * enn 2 dager for kursstart refunderes kursavgiften fullt ut. Ved
     * avbestilling mindre enn 2 dager for kursstart refunderes ikke
     * kursavgiften.» Og: «jeg vil at mine regler skal gjelde».
     *
     * ── Hvorfor den staar her ────────────────────────────────────────
     *
     * Regelen sto to steder: i api/avbestill.php, som ber Vipps om pengene,
     * og i api/mine-plasser.php, som forteller kunden hva hen faar. To
     * kopier av en regel om penger er én for mye — den dagen den ene endres
     * og den andre ikke, leser kunden ett tall og faar et annet inn paa
     * konto. Naa er det ett sted, og vilkaarsteksten sier det samme.
     *
     * @param  float|null $timerIgjen Timer til kursstart. null naar kurset
     *                                ikke har en fastsatt dato.
     * @return array{andel: float, regel: string, kunde: string, kanAvbestille: bool}
     */
    public static function avbestillingsregel(?float $timerIgjen): array
    {
        if ($timerIgjen === null) {
            return [
                'andel' => 1.0,
                'regel' => 'Kurset har ingen fastsatt dato, saa hele beloepet refunderes.',
                'kunde' => 'Ta kontakt om du må avbestille.',
                'kanAvbestille' => true,
            ];
        }
        // Kurset har vaert. Da er det ikke en avbestilling lenger.
        if ($timerIgjen <= 0) {
            return [
                'andel' => 0.0,
                'regel' => 'Kurset har vaert: ingen refusjon.',
                'kunde' => 'Kurset har vært.',
                'kanAvbestille' => false,
            ];
        }
        if ($timerIgjen > self::AVBESTILLING_TIMER) {
            return [
                'andel' => 1.0,
                'regel' => 'Avbestilt mer enn 2 dager for kursstart: full refusjon.',
                'kunde' => 'Full refusjon fram til 2 dager før kursstart.',
                'kanAvbestille' => true,
            ];
        }
        return [
            'andel' => 0.0,
            'regel' => 'Avbestilt mindre enn 2 dager for kursstart: ingen refusjon. '
                     . 'Plassen kan overfores til en annen person etter avtale — '
                     . 'ta kontakt, saa ordner vi det.',
            'kunde' => 'Nærmere enn 2 dager refunderes ikke, men plassen kan overføres til en annen.',
            'kanAvbestille' => false,
        ];
    }

    /**
     * Ledige plasser uten delte ressurser — regnestykket slik det var for
     * migrasjon 103.
     *
     * Brukes naar kolonna eller tabellen ikke finnes: i vinduet mellom at
     * koden legges ut og migrasjonen kjores, og paa en base som er rullet
     * tilbake. Hver oekt for seg, som for.
     *
     * @param list<int> $ider
     * @return array<int, int>
     */
    private static function ledigeUtenRessurser(string $inn, array $ider): array
    {
        $ut = [];
        foreach (DB::alle(
            "SELECT cs.id,
                    GREATEST(0,
                        COALESCE(cs.kapasitet, c.kapasitet)
                        - COALESCE(cs.manuelt_opptatt, 0)
                        - COALESCE((SELECT SUM(b.antall) FROM bookings b
                                     WHERE b.course_session_id = cs.id
                                       AND (b.status = 'betalt'
                                            OR (b.status = 'reservert'
                                                AND (b.reservert_til IS NULL
                                                     OR b.reservert_til > UTC_TIMESTAMP())))), 0)
                    ) AS ledige
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt' AND cs.id IN ({$inn})"
        ) as $r) {
            $ut[(int) $r['id']] = (int) $r['ledige'];
            self::$sperret[(int) $r['id']] = false;
        }
        foreach ($ider as $i) {
            $ut[$i] ??= 0;
        }
        return $ut;
    }

    /**
     * Hvilke oekter som er stengt av noe annet enn sine egne bookinger.
     *
     * Fylles av ledigePlasserFlere(), som alt har regnet det ut. Kall den
     * forst — ellers er svaret tomt, og et tomt svar betyr «ikke sperret»,
     * som er den trygge antakelsen.
     *
     * @param list<int> $oktIder
     * @return array<int, bool>
     */
    public static function sperretAvAnnet(array $oktIder): array
    {
        $ut = [];
        foreach ($oktIder as $i) {
            $ut[(int) $i] = self::$sperret[(int) $i] ?? false;
        }
        return $ut;
    }

    /** @var array<int, bool> */
    private static array $sperret = [];

    /**
     * Grupperabatten som gjelder for et kurs med et gitt antall plasser.
     *
     * Bookingsiden viste rabatten, men serveren regnet full pris — kunden saa
     * én sum og ble trukket en annen. Utregningen maa skje her, der belopet
     * faktisk settes; nettleseren skal aldri sende hva noe koster.
     *
     * Flere nivaaer kan treffe samtidig. Da gjelder det beste for kunden.
     */
    public static function rabattProsent(array $kurs, int $antall): float
    {
        // Medlemskap er ikke gruppekjop.
        $tema = (string) ($kurs['tema'] ?? '');
        if ($tema === 'Medlemskap' || $antall < 2) {
            return 0.0;
        }

        $dreiing = $tema === 'Dreiing'
            || str_contains(mb_strtolower((string) ($kurs['tittel'] ?? '')), 'dreie');

        $gjelder = ['alle', (string) ($kurs['slug'] ?? '')];
        if ($dreiing) {
            $gjelder[] = 'dreiing';
        }

        $plassholdere = implode(',', array_map(static fn($i) => ':g' . $i, array_keys($gjelder)));
        $param = ['a' => $antall];
        foreach ($gjelder as $i => $g) {
            $param['g' . $i] = $g;
        }

        $best = DB::verdi(
            "SELECT MAX(prosent) FROM discount_tiers
              WHERE aktiv = 1 AND min_antall <= :a AND gjelder IN ({$plassholdere})",
            $param
        );

        return max(0.0, min(100.0, (float) ($best ?? 0)));
    }

    /** Belopet for en booking, etter grupperabatt. Alltid hele ore. */
    public static function belopFor(array $kurs, int $antall): array
    {
        $brutto = (int) $kurs['pris_ore'] * $antall;
        $rabatt = self::rabattProsent($kurs, $antall);
        $netto  = (int) round($brutto * (1 - $rabatt / 100));

        return ['brutto' => $brutto, 'rabatt' => $rabatt, 'netto' => $netto];
    }

    /**
     * Oppretter en reservasjon og starter betaling i Vipps.
     *
     * @return array{redirectUrl:string,referanse:string,bookingId:int}
     */
    public static function reserverOgBetal(
        int $oktId,
        int $antall,
        string $navn,
        string $epost,
        string $telefon,
        ?int $medlemId,
        ?string $folgeMedlem = null,
        string $gavekortKode = '',
        ?string $allergier = null
    ): array {
        // Prisen paa datoen gaar foran kursets, naar den er satt. COALESCE
        // og ikke to sporringer: da er det ett sted prisen kommer fra, og
        // resten av metoden trenger ikke vite at det finnes to.
        $egenPris = DB::harKolonne('course_sessions', 'pris_ore')
            ? 'COALESCE(cs.pris_ore, c.pris_ore)' : 'c.pris_ore';
        // «Gjenstanden betales i verkstedet» (migrasjon 074). Et slikt kurs er
        // gratis med vilje — plassen koster ingenting, og kunden betaler det
        // hen maler naar hen staar der.
        $kassaFelt = DB::harKolonne('courses', 'gjenstand_i_kassa')
            ? 'c.gjenstand_i_kassa' : '0 AS gjenstand_i_kassa';
        $okt = DB::en(
            'SELECT cs.id, cs.course_id, cs.start_tid,
                    c.tittel, ' . $egenPris . ' AS pris_ore, c.type, c.tema, c.slug,
                    ' . $kassaFelt . '
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :id
                AND cs.status = \'planlagt\'
                AND c.status = \'publisert\'',
            ['id' => $oktId]
        );

        if ($okt === null) {
            throw new RuntimeException('Denne datoen kan ikke bookes.');
        }
        if ($okt['start_tid'] < gmdate('Y-m-d H:i:s')) {
            throw new RuntimeException('Denne datoen har vært.');
        }
        if (self::ledigePlasser($oktId) < $antall) {
            throw new RuntimeException('Det er ikke nok ledige plasser igjen.');
        }

        // Medlemsarrangementer er gratis, men krever aktivt medlemskap.
        //
        // «Gratis» og «kun for medlemmer» var det samme her: null kroner
        // betydde medlemsarrangement, punktum. Det holdt saa lenge det eneste
        // gratis vi hadde var medlemssamlinger — men Paint on Pots er gratis
        // fordi plassen er gratis, og gjenstanden betales i kassa. Da ble
        // sida staaende med tre datoer og en booking som svarte «kun for
        // medlemmer» til alle som ikke var det.
        $gratis = (int) $okt['pris_ore'] === 0;
        $apentForAlle = (int) ($okt['gjenstand_i_kassa'] ?? 0) === 1;
        if ($gratis && !$apentForAlle && $medlemId === null) {
            throw new RuntimeException('Dette arrangementet er kun for medlemmer.');
        }

        // Prisen regnes her, ikke i nettleseren. Rabatten som vises paa
        // bookingsiden og den kunden trekkes er naa det samme tallet.
        $pris   = self::belopFor($okt, $antall);
        $belop  = $pris['netto'];
        $rabatt = $pris['rabatt'];
        $referanse = Vipps::nyReferanse();

        // Gavekortet. Feltet paa bookingsiden var ikke koblet til noe, saa en
        // kode kunne skrives inn uten at den ble brukt til noe.
        //
        // Beloepet legges paa betalingen og trekkes forst naar den er
        // bekreftet: en booking som blir liggende i Vipps skal ikke spise av
        // saldoen.
        $gavekortId = null;
        $gavekortOre = 0;
        if (!$gratis && trim($gavekortKode) !== '') {
            $kort = self::finnGavekort($gavekortKode);
            if ($kort === null) {
                throw new RuntimeException('Fant ikke gavekortet, eller det er brukt opp.');
            }
            $gavekortId = $kort['id'];
            $gavekortOre = min($kort['saldo_ore'], $belop);
        }
        $aBetale = $belop - $gavekortOre;

        // Reservasjonen foerst, i en kort transaksjon. Kallet til Vipps ligger
        // utenfor med vilje: et HTTP-kall kan ta tjue sekunder, og saa lenge
        // skal ingen databaselaas staa aapen.
        $reservasjon = DB::iTransaksjon(static function () use (
            $okt, $oktId, $antall, $navn, $epost, $telefon, $medlemId,
            $folgeMedlem, $gratis, $belop, $aBetale, $gavekortId, $gavekortOre, $rabatt, $referanse,
            $allergier
        ): array {
            // Plassen sjekkes en gang til inne i transaksjonen. Uten dette kunne
            // to samtidige bookinger begge se den siste plassen som ledig.
            if (self::ledigePlasser($oktId, true) < $antall) {
                throw new RuntimeException('Plassen ble tatt mens du fylte ut skjemaet.');
            }

            $paymentId = null;
            if (!$gratis) {
                $felt = [
                    'vipps_reference' => $referanse,
                    'type'            => 'epayment',
                    'formal'          => 'booking',
                    'member_id'       => $medlemId,
                    // Det kunden faktisk skal betale. Beloepet paa bookingen
                    // er hele prisen — differansen er gavekortet.
                    'belop_ore'       => $aBetale,
                    'status'          => 'opprettet',
                    'idempotency_key' => Vipps::uuid(),
                ];
                if (DB::harKolonne('payments', 'gavekort_id')) {
                    $felt['gavekort_id'] = $gavekortId;
                    $felt['gavekort_ore'] = $gavekortOre;
                }
                $paymentId = DB::settInn('payments', $felt);
            }

            $felter = [
                'course_id'         => $okt['course_id'],
                'course_session_id' => $oktId,
                'member_id'         => $medlemId,
                'gjest_navn'        => $navn,
                'gjest_epost'       => $epost !== '' ? $epost : null,
                'gjest_telefon'     => $telefon !== '' ? $telefon : null,
                'antall'            => $antall,
                'belop_ore'         => $belop,
                // Rabatten lagres med bookingen. Endres nivaaene senere, skal
                // en gammel kvittering fortsatt kunne forklares.
                'rabatt_prosent'    => $rabatt,
                'status'            => $gratis ? 'betalt' : 'reservert',
                'payment_id'        => $paymentId,
                'folge_medlem'      => $folgeMedlem,
                'reservert_til'     => $gratis ? null
                    : gmdate('Y-m-d H:i:s', time() + self::RESERVASJON_MINUTTER * 60),
            ];

            // Kolonnen kommer med migrasjon 057. Er den ikke kjort, skal en
            // booking fortsatt gaa gjennom — vi mister opplysningen, ikke
            // plassen. Skjemaet sier fra om det samme.
            if ($allergier !== null && $allergier !== '' && DB::harKolonne('bookings', 'allergier')) {
                $felter['allergier'] = $allergier;
            }

            $bookingId = DB::settInn('bookings', $felter);

            // Betalingen peker tilbake paa paameldingen.
            //
            // Raden lages foer bookingen — den maa ha en referanse for kunden
            // sendes til Vipps — saa koblingen settes her. Uten den staar
            // betalingshistorikken tom paa en plass som faktisk er betalt,
            // og «Betaling» i Paameldte sier «ingen betaling registrert» om
            // penger som er kommet inn.
            if ($paymentId !== null && DB::harKolonne('payments', 'booking_id')) {
                DB::oppdater('payments', ['booking_id' => $bookingId], ['id' => $paymentId]);
            }

            return ['bookingId' => $bookingId, 'paymentId' => $paymentId];
        });

        if ($gratis) {
            self::sendBekreftelse($reservasjon['bookingId']);
            return ['redirectUrl' => '', 'referanse' => '', 'bookingId' => $reservasjon['bookingId']];
        }

        // Dekker gavekortet hele prisen, er det ingenting aa betale. Aa sende
        // noen til Vipps for null kroner er en omvei til en feilmelding.
        //
        // Under én krone gjelder det samme: Vipps godtar ikke beloepet, og
        // det finnes ingen vei videre. Da er resten dekket. Aa runde opp
        // ville vaert aa kreve mer enn kunden skylder.
        if ($aBetale < Vipps::MINSTE_BELOP_ORE) {
            self::markerBetalt($referanse);
            return ['redirectUrl' => '', 'referanse' => $referanse, 'bookingId' => $reservasjon['bookingId']];
        }

        try {
            $betaling = Vipps::opprettBetaling(
                $referanse,
                $aBetale,
                mb_substr($okt['tittel'], 0, 100),
                Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
                $telefon
            );
        } catch (Throwable $e) {
            // Betalingen kom aldri i gang, saa plassen frigis med det samme.
            // For sto den som reservert til cron ryddet etter tjue minutter —
            // og i de tjue minuttene sa sida «utsolgt» til alle andre, for en
            // betaling som aldri fantes. Er dette den siste plassen paa et
            // populaert kurs, er det tjue minutter for mye.
            DB::oppdater('payments', ['status' => 'feilet'], ['id' => $reservasjon['paymentId']]);
            DB::oppdater('bookings', ['status' => 'avbestilt'], ['id' => $reservasjon['bookingId']]);
            logg_feil('Kunne ikke starte betaling for booking ' . $reservasjon['bookingId'], $e);
            throw new RuntimeException('Fikk ikke startet betalingen. Prøv igjen om litt.');
        }

        DB::oppdater('payments', ['status' => 'venter'], ['id' => $reservasjon['paymentId']]);

        return [
            'redirectUrl' => $betaling['url'],
            'referanse'   => $referanse,
            'bookingId'   => $reservasjon['bookingId'],
        ];
    }

    /**
     * Markerer en booking som betalt. Kalles fra webhook og fra returen —
     * begge kan komme først, og begge kan komme flere ganger.
     */
    public static function markerBetalt(string $referanse): bool
    {
        return (bool) DB::iTransaksjon(static function () use ($referanse): bool {
            $betaling = DB::en(
                'SELECT id, status, belop_ore FROM payments WHERE vipps_reference = :r FOR UPDATE',
                ['r' => $referanse]
            );
            if ($betaling === null || $betaling['status'] === 'betalt') {
                return false; // ukjent, eller allerede håndtert
            }

            DB::oppdater('payments', ['status' => 'betalt'], ['id' => $betaling['id']]);

            // Gavekortet trekkes her, ikke naar ordren ble opprettet. En
            // handlekurv som blir forlatt i Vipps skal ikke spise av saldoen.
            self::trekkGavekort((int) $betaling['id']);

            // En betaling hoerer enten til en booking eller en butikkordre.
            // Begge kommer inn her, fra webhook og fra returen.
            $booking = DB::en('SELECT id FROM bookings WHERE payment_id = :p', ['p' => $betaling['id']]);
            if ($booking !== null) {
                DB::oppdater('bookings', [
                    'status'        => 'betalt',
                    'reservert_til' => null,
                ], ['id' => $booking['id']]);

                self::sendBekreftelse((int) $booking['id']);
                return true;
            }

            $ordre = DB::en('SELECT id FROM orders WHERE payment_id = :p', ['p' => $betaling['id']]);
            if ($ordre !== null) {
                DB::oppdater('orders', ['status' => 'betalt'], ['id' => $ordre['id']]);
                self::sendOrdrebekreftelse((int) $ordre['id']);
                return true;
            }

            $kort = DB::en('SELECT id FROM gift_cards WHERE payment_id = :p', ['p' => $betaling['id']]);
            if ($kort !== null) {
                self::aktiverGavekort((int) $kort['id']);
                return true;
            }

            // Et medlemskap uten fast trekk. Betalingen peker paa raden i
            // «subscriptions», og medlemskapet slaas paa naar den er i havn.
            // Uten dette ble medlemmet staaende som «venter» selv om pengene
            // var betalt.
            $ab = DB::en(
                'SELECT subscription_id FROM payments WHERE id = :p AND subscription_id IS NOT NULL',
                ['p' => $betaling['id']]
            );
            if ($ab !== null) {
                Medlemskap::betaltEngangs((int) $ab['subscription_id']);
                return true;
            }

            return false;
        });
    }

    /** Legger kvitteringen i varselkøen. Cron sender den. */
    public static function sendBekreftelse(int $bookingId): void
    {
        $b = DB::en(
            'SELECT b.*, c.tittel, cs.start_tid, cs.slutt_tid,
                    m.navn AS m_navn, m.epost AS m_epost, m.telefon AS m_telefon
               FROM bookings b
               JOIN courses c ON c.id = b.course_id
          LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
          LEFT JOIN members m ON m.id = b.member_id
              WHERE b.id = :id',
            ['id' => $bookingId]
        );
        if ($b === null) {
            return;
        }

        // ── Kurset og naar, hver for seg ──────────────────────────────
        //
        // Her sto alt i ett felt: «{ordre}» ble kursnavnet og datoen limt
        // sammen med en tankestrek, og malen sa «Vi har mottatt bestillingen
        // din ({ordre})». Kunden fikk «Vi har mottatt bestillingen din
        // (Nybegynner dreiekurs — onsdag 9. september, 17:00).» — datoen
        // gjemt inne i en parentes etter feil ord.
        //
        // Eieren, 1. september, om den e-posten: «eposten ser jo helt feil
        // ut».
        //
        // Naa er de to feltene: «{kurs}» og «{naar}». Malen kan sette dem
        // hvor den vil, og eieren kan flytte dem under Maler.
        //
        // «{ordre}» staar igjen med den gamle verdien. Koden rulles ut noen
        // minutter for migrasjonen kjores, og i det vinduet er malen fortsatt
        // den gamle — da skal den fortsatt kunne fylles.
        $naar = $b['start_tid']
            ? self::norskPeriode((string) $b['start_tid'], $b['slutt_tid'] ?? null)
            : '';
        Varsel::mal('ordrebekreftelse', [
            'epost'   => $b['m_epost'] ?? $b['gjest_epost'],
            'telefon' => $b['m_telefon'] ?? $b['gjest_telefon'],
        ], [
            'navn'  => (string) ($b['m_navn'] ?: $b['gjest_navn']),
            'kurs'  => (string) $b['tittel'],
            'naar'  => $naar,
            'ordre' => (string) $b['tittel'] . ($naar !== '' ? ' — ' . $naar : ''),
            'belop' => self::kroner((int) $b['belop_ore']),
        ], 'booking', $bookingId);
    }

    /**
     * Finner et gavekort som kan brukes naa.
     *
     * Koden leses opp over telefon og skrives inn for haand, saa den taaler
     * smaa bokstaver og manglende bindestreker. Alt annet maa stemme:
     * kortet maa vaere aktivt, ha saldo, og ikke ha gaatt ut paa dato.
     *
     * @return array{id:int,kode:string,saldo_ore:int}|null
     */
    public static function finnGavekort(string $kode): ?array
    {
        $rent = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $kode) ?? '');
        if ($rent === '' || mb_strlen($rent) > 32) {
            return null;
        }
        // Koden lagres med bindestreker. Vi sammenligner uten, saa «lis abc
        // def ghj» og «LIS-ABC-DEF-GHJ» er samme kort.
        $rad = DB::en(
            "SELECT id, kode, saldo_ore, status, gyldig_til
               FROM gift_cards
              WHERE REPLACE(REPLACE(UPPER(kode), '-', ''), ' ', '') = :k
              LIMIT 1",
            ['k' => $rent]
        );
        if ($rad === null || $rad['status'] !== 'aktivt') {
            return null;
        }
        if ((int) $rad['saldo_ore'] <= 0) {
            return null;
        }
        if ($rad['gyldig_til'] !== null && $rad['gyldig_til'] < gmdate('Y-m-d')) {
            return null;
        }
        return ['id' => (int) $rad['id'], 'kode' => (string) $rad['kode'], 'saldo_ore' => (int) $rad['saldo_ore']];
    }

    /**
     * Trekker beloepet fra kortet, én gang.
     *
     * Kalles naar betalingen er bekreftet — ikke naar ordren opprettes. En
     * handlekurv som blir forlatt i Vipps skal ikke spise av saldoen.
     *
     * Trekket er betinget i selve SQL-en: gaar saldoen ned et annet sted i
     * mellomtiden, blir det null rader, og vi trekker ikke mer enn det som
     * faktisk staar der.
     */
    public static function trekkGavekort(int $paymentId): void
    {
        if (!DB::harKolonne('payments', 'gavekort_id')) {
            return;
        }
        $b = DB::en(
            'SELECT id, gavekort_id, gavekort_ore FROM payments WHERE id = :i',
            ['i' => $paymentId]
        );
        $kortId = (int) ($b['gavekort_id'] ?? 0);
        $belop  = (int) ($b['gavekort_ore'] ?? 0);
        if ($kortId <= 0 || $belop <= 0) {
            return;
        }
        // Hva ble kortet brukt paa? Sporet skal si det — «betaling nr. 7»
        // hjelper ingen som leter etter en ordre. ref_type er en enum fra
        // 001_init med akkurat disse tre verdiene.
        $refType = 'ordre';
        $refId = 0;

        // Betalingen sier det selv, naar den kan.
        //
        // «orders.payment_id» peker bare paa den ene raden som staar som
        // salgets hovedrad. I et delt oppgjor er gavekortet en egen rad ved
        // siden av kontantene, og den er ikke hovedraden — saa oppslaget
        // nedenfor ville ikke funnet noen ordre, og uttaket blitt staaende
        // uloggfoert med refId 0. «payments.order_id» kom med migrasjon 134
        // og peker riktig vei for alle delene.
        $eget = DB::en(
            'SELECT ' . (DB::harKolonne('payments', 'order_id') ? 'order_id' : 'NULL AS order_id')
            . ', ' . (DB::harKolonne('payments', 'booking_id') ? 'booking_id' : 'NULL AS booking_id')
            . ' FROM payments WHERE id = :i',
            ['i' => $paymentId]
        );
        if ((int) ($eget['booking_id'] ?? 0) > 0) {
            $refType = 'booking';
            $refId = (int) $eget['booking_id'];
        } elseif ((int) ($eget['order_id'] ?? 0) > 0) {
            $refId = (int) $eget['order_id'];
        }

        // Rader laget for kolonnene fantes, finnes fortsatt. De maa slaas
        // opp den gamle veien.
        if ($refId === 0) {
            $b = DB::en('SELECT id FROM bookings WHERE payment_id = :p', ['p' => $paymentId]);
            if ($b !== null) {
                $refType = 'booking';
                $refId = (int) $b['id'];
            }
        }
        if ($refId === 0) {
            $o = DB::en('SELECT id FROM orders WHERE payment_id = :p', ['p' => $paymentId]);
            if ($o !== null) {
                $refId = (int) $o['id'];
            }
        }
        if ($refId === 0) {
            $s = DB::en(
                'SELECT id FROM subscriptions
                  WHERE id = (SELECT subscription_id FROM payments WHERE id = :p)',
                ['p' => $paymentId]
            );
            if ($s !== null) {
                $refType = 'medlemskap';
                $refId = (int) $s['id'];
            }
        }

        // Allerede trukket? Da staar bruken der fra for, og vi gjor ingenting.
        // Webhooken og returen kan begge komme, og begge kan komme flere
        // ganger.
        if (DB::harTabell('gift_card_uses') && $refId > 0) {
            $alt = DB::en(
                'SELECT id FROM gift_card_uses
                  WHERE gift_card_id = :k AND ref_type = :t AND ref_id = :r',
                ['k' => $kortId, 't' => $refType, 'r' => $refId]
            );
            if ($alt !== null) {
                return;
            }
        }

        $rader = DB::kjor(
            'UPDATE gift_cards SET saldo_ore = saldo_ore - :b
              WHERE id = :i AND saldo_ore >= :b2',
            ['b' => $belop, 'i' => $kortId, 'b2' => $belop]
        )->rowCount();
        if ($rader === 0) {
            logg('Gavekort hadde ikke daekning ved trekk', ['kort' => $kortId, 'belop' => $belop]);
            return;
        }

        if (DB::harTabell('gift_card_uses') && $refId > 0) {
            DB::settInn('gift_card_uses', [
                'gift_card_id' => $kortId,
                'belop_ore'    => $belop,
                'ref_type'     => $refType,
                'ref_id'       => $refId,
            ]);
        }

        // Tomt kort er brukt opp. Da skal det ikke ligge og se gyldig ut.
        DB::kjor("UPDATE gift_cards SET status = 'brukt' WHERE id = :i AND saldo_ore <= 0", ['i' => $kortId]);
    }

    /**
     * Legger beloepet tilbake paa kortet.
     *
     * Annulleres et salg som ble gjort opp med gavekort, er pengene paa
     * kortet ikke brukt. Uten dette ville kunden mistet dem: salget ble
     * strøket, men saldoen sto igjen nedtrukket.
     *
     * Uttaksraden slettes, og det er med vilje. «gift_card_uses.belop_ore» er
     * INT UNSIGNED — et negativt motstykke finnes ikke aa skrive. Sporet
     * ligger i revisjonsloggen, som er stedet for hvem som angret hva.
     *
     * @return int Beloepet som ble lagt tilbake, i oere. 0 om ingenting ble gjort.
     */
    public static function angreGavekort(int $paymentId): int
    {
        if (!DB::harKolonne('payments', 'gavekort_id')) {
            return 0;
        }
        $p = DB::en(
            'SELECT gavekort_id, gavekort_ore FROM payments WHERE id = :i',
            ['i' => $paymentId]
        );
        $kortId = (int) ($p['gavekort_id'] ?? 0);
        $belop  = (int) ($p['gavekort_ore'] ?? 0);
        if ($kortId <= 0 || $belop <= 0) {
            return 0;
        }

        // Aldri trukket? Da er det ingenting aa legge tilbake. Uten denne
        // ville en betaling som ble annullert for pengene kom inn — der
        // trekket aldri skjedde — gitt kunden beloepet i gave.
        if (DB::harTabell('gift_card_uses')) {
            $bruk = DB::en(
                'SELECT id FROM gift_card_uses
                  WHERE gift_card_id = :k AND belop_ore = :b
                  ORDER BY id DESC LIMIT 1',
                ['k' => $kortId, 'b' => $belop]
            );
            if ($bruk === null) {
                return 0;
            }
            DB::kjor('DELETE FROM gift_card_uses WHERE id = :i', ['i' => (int) $bruk['id']]);
        }

        DB::kjor(
            'UPDATE gift_cards SET saldo_ore = saldo_ore + :b WHERE id = :i',
            ['b' => $belop, 'i' => $kortId]
        );
        // Kortet ble satt til «brukt» da det gikk tomt. Naa har det saldo
        // igjen, og skal virke i kassa — men bare hvis det ikke er annullert
        // eller utloept av andre grunner.
        DB::kjor(
            "UPDATE gift_cards SET status = 'aktivt'
              WHERE id = :i AND status = 'brukt' AND saldo_ore > 0",
            ['i' => $kortId]
        );

        return $belop;
    }

    /**
     * Gir gavekortet en kode og gjor det gyldig.
     *
     * Koden settes forst her, ikke ved kjop: ellers kunne noen faatt en
     * gyldig kode ved aa starte en betaling og avbryte den.
     *
     * @param bool $varsle Om kortet skal sendes paa e-post. Et kort utstedt
     *                     over disk uten adresse skal ikke det: koden staar
     *                     paa skjermen og skrives paa kortet i handa. Uten
     *                     dette havner den i «Varsel maa sendes for haand»
     *                     hos admin hver eneste gang — et krav om aa gjore
     *                     noe som alt er gjort.
     */
    public static function aktiverGavekort(int $kortId, bool $varsle = true): void
    {
        $k = DB::en('SELECT * FROM gift_cards WHERE id = :i', ['i' => $kortId]);
        if ($k === null || $k['status'] !== 'ubetalt') {
            return; // allerede aktivert, eller ukjent
        }

        // Koden skal kunne leses opp over telefon. Derfor ingen tegn som
        // forveksles: verken 0/O, 1/I/L eller 5/S.
        $tegn = 'ABCDEFGHJKMNPQRTUVWXYZ2346789';
        do {
            $kode = 'LIS-';
            for ($i = 0; $i < 9; $i++) {
                if ($i > 0 && $i % 3 === 0) {
                    $kode .= '-';
                }
                $kode .= $tegn[random_int(0, strlen($tegn) - 1)];
            }
        } while (DB::en('SELECT id FROM gift_cards WHERE kode = :k', ['k' => $kode]) !== null);

        DB::oppdater('gift_cards', [
            'kode'      => $kode,
            'saldo_ore' => (int) $k['opprinnelig_ore'],
            'status'    => 'aktivt',
        ], ['id' => $kortId]);

        $belop = self::kroner((int) $k['opprinnelig_ore']);
        $gyldig = self::norskDatoKort((string) $k['gyldig_til']);

        // Til mottakeren, om det er oppgitt en. Ellers til kjoperen.
        $til = $k['mottaker_epost'] ?: $k['kjoper_epost'];

        // Kortet er gyldig og har koden sin. Skal det ikke sendes, eller
        // finnes det ingen adresse aa sende til, er vi ferdige her.
        if (!$varsle || !$til) {
            return;
        }
        $hilsen = $k['hilsen'] ? "\n\n«" . $k['hilsen'] . "»\n— " . $k['kjoper_navn'] : '';

        Varsel::mal('gavekort_mottaker', ['epost' => (string) $til], [
            'belop'  => $belop,
            'hilsen' => $hilsen,
            'kode'   => $kode,
            'gyldig' => $gyldig,
        ], 'gift_card', $kortId);

        // Kjoperen far ogsaa beskjed om at kortet er sendt.
        if ($k['mottaker_epost'] && $k['kjoper_epost'] && $k['mottaker_epost'] !== $k['kjoper_epost']) {
            Varsel::mal('gavekort_kjoper', ['epost' => (string) $k['kjoper_epost']], [
                'navn'     => (string) $k['kjoper_navn'],
                'belop'    => $belop,
                'mottaker' => (string) $k['mottaker_epost'],
                'kode'     => $kode,
                'gyldig'   => $gyldig,
            ], 'gift_card', $kortId);
        }
    }

    /** Kvittering for butikkjop, med beskjed om henting. */
    public static function sendOrdrebekreftelse(int $ordreId): void
    {
        $o = DB::en('SELECT * FROM orders WHERE id = :i', ['i' => $ordreId]);
        if ($o === null) {
            return;
        }

        $linjer = DB::alle(
            'SELECT tittel, antall, pris_ore FROM order_lines WHERE order_id = :o ORDER BY id',
            ['o' => $ordreId]
        );

        $liste = [];
        foreach ($linjer as $l) {
            $liste[] = sprintf('%d × %s — %s', $l['antall'], $l['tittel'],
                self::kroner((int) $l['pris_ore'] * (int) $l['antall']));
        }

        // ── Henting eller pakke ──────────────────────────────────────
        //
        // Eieren, 1. september, om e-posten Monica fikk: «Trenger ikke staa at
        // varene er klare innen 2 dager». Den lovet to virkedager; kassa paa
        // nettsiden lovet to uker. Ingen av dem var noe verkstedet ville staa
        // for, og de kunne ikke begge stemme.
        //
        // Butikken selger ogsaa frakt. Den som valgte «Send som pakke» skal
        // ikke faa beskjed om aa hente paa Teie — det er feil beskjed til den
        // kunden, og det var det den gamle teksten gav dem.
        $erPakke = (string) ($o['levering'] ?? 'hent') === 'pakke';
        $adresse = trim((string) ($o['adresse'] ?? ''));

        Varsel::mal($erPakke ? 'butikkordre_pakke' : 'butikkordre',
            ['epost' => (string) $o['kunde_epost']], [
                'ordre'      => (string) $o['ordrenr'],
                'varelinjer' => implode("\n", $liste),
                'sum'        => self::kroner((int) $o['sum_ore']),
                'adresse'    => $adresse !== '' ? 'Sendes til: ' . $adresse : '',
            ], 'order', $ordreId);

        // En gave krever en handling i verkstedet: pakke inn og skrive
        // kortet. Haken og hilsenen naadde ikke serveren i det hele tatt for
        // 24. august, saa ingen fikk vite det. Varsles bare naar det faktisk
        // er en gave — ellers ville hver eneste bestilling gitt en e-post.
        if ((int) ($o['gave'] ?? 0) === 1) {
            Varsel::malTilAdmin('intern_gave_pakkes', [
                'ordre'      => (string) $o['ordrenr'],
                'navn'       => (string) $o['kunde_navn'],
                'varelinjer' => implode("\n", $liste),
                'hilsen'     => trim((string) ($o['gave_hilsen'] ?? '')) !== ''
                    ? '«' . $o['gave_hilsen'] . '»'
                    : '(ingen hilsen skrevet)',
            ], 'order', $ordreId);
        }
    }

    public static function kroner(int $ore): string
    {
        // Harde mellomrom, begge to. Med vanlige mellomrom faar nettleseren
        // lov til aa brekke linja midt i belopet: «kr. 2» paa en linje og
        // «490,-» paa neste. Eieren, 1. september: «ikke bryt beloepe 2490»
        // og «her maa hele beloepet staa paa en linje».
        //
        // Serveren formaterer belopene og sender dem ferdig som tekst, saa
        // det er her det maa loeses — skjermen far bare en streng.
        //
        // CSV-ene til regnskapsforeren har hver sin egen formaterer uten
        // tusenskille i det hele tatt, og roeres ikke av dette.
        return "kr.\u{a0}" . number_format($ore / 100, 0, ',', "\u{a0}") . ',-';
    }

    /**
     * 2029-08-21 → «21. august 2029».
     *
     * PHPs date() gir engelske maanedsnavn uansett hva serveren staar til, og
     * «21. August 2029» paa et norsk gavekort ser ut som en feil.
     */
    public static function norskDatoKort(string $dato): string
    {
        // Tidspunkt lagres i UTC. Uten omregningen ville et kurs som slutter
        // like for midnatt norsk tid faatt gaarsdagens dato paa kursbeviset.
        $d = (new DateTimeImmutable($dato, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'));
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];

        return sprintf('%d. %s %d', (int) $d->format('j'), $mnd[(int) $d->format('n') - 1], (int) $d->format('Y'));
    }

    /**
     * Som norskDato, men tar med sluttdagen naar okten gaar over flere dager:
     * «onsdag 9. – torsdag 10. september, 17:00».
     *
     * Dreiekurset gaar to kvelder og er én paamelding. Sto bare startdagen,
     * kunne man tro man booket en enkeltkveld.
     */
    /**
     * «Tirsdag 1. september, 10:00–20:00».
     *
     * Til aapne vinduer: Paint on Pots er lagt ut paa aapningstidene, og da
     * er det ikke et klokkeslett man moeter opp til, men en tid doeren staar
     * aapen. Sto bare starten, saa det ut som at man kom for sent 10:05.
     *
     * Gaar vinduet over midnatt, faller vi tilbake paa norskPeriode() — den
     * kan si «tirsdag 1. – onsdag 2.» slik den alltid har gjort.
     */
    public static function norskSpenn(string $start, string $slutt): string
    {
        $sone = new DateTimeZone('Europe/Oslo');
        $s = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone($sone);
        $t = (new DateTimeImmutable($slutt, new DateTimeZone('UTC')))->setTimezone($sone);
        if ($s->format('Y-m-d') !== $t->format('Y-m-d')) {
            return self::norskPeriode($start, $slutt);
        }
        return self::norskDato($start) . '–' . $t->format('H:i');
    }

    public static function norskPeriode(string $start, ?string $slutt): string
    {
        $fra = self::norskDato($start);
        if ($slutt === null || $slutt === '') {
            return $fra;
        }

        $sone = new DateTimeZone('Europe/Oslo');
        $s = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone($sone);
        $t = (new DateTimeImmutable($slutt, new DateTimeZone('UTC')))->setTimezone($sone);
        if ($s->format('Y-m-d') === $t->format('Y-m-d')) {
            return $fra;
        }

        $dager = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];

        return sprintf(
            '%s %d. – %s %d. %s, %s',
            $dager[(int) $s->format('N') - 1],
            (int) $s->format('j'),
            $dager[(int) $t->format('N') - 1],
            (int) $t->format('j'),
            $mnd[(int) $t->format('n') - 1],
            $s->format('H:i')
        );
    }

    // ── Betaling registrert for haand ────────────────────────────────────
    //
    // Kom pengene kontant, paa faktura eller paa Vipps i verkstedet, sto det
    // en tekst i «betalt_maate» paa bookingen og ikke noe mer. Ingen rad i
    // payments. Da fantes det ikke noe svar paa hvem som registrerte
    // betalingen, naar, eller hvor mye — og en feilregistrering kunne bare
    // skrives over, ikke angres.
    //
    // Reglene staar her og ikke i endepunktet, slik resten av pengelogikken
    // gjor: da kan de testes, og da kan bare ett sted svare paa hva som er
    // betalt.

    /** Maatene en betaling kan komme paa naar den legges inn for haand. */
    public const MAATER = ['Kontant', 'Vipps i verkstedet', 'Faktura', 'Bankoverføring', 'Gratis'];

    /**
     * Betalingene som gjelder én paamelding, og summen av dem.
     *
     * Annullerte teller ikke i summen, men blir staaende i lista — det er
     * hele poenget med aa annullere framfor aa slette. Raden er et bilag.
     *
     * @return array{rader: list<array<string,mixed>>, sum: int}
     */
    public static function betalingerFor(int $bookingId): array
    {
        // Kolonnene kommer med migrasjon 084. Uten dem finnes det ingen
        // manuelle betalinger aa hente, og da skal dette svare tomt framfor
        // aa doe paa en kolonne som ikke er der.
        if (!DB::harKolonne('payments', 'booking_id')) {
            return ['rader' => [], 'sum' => 0];
        }

        // To veier inn til den samme raden, og det er med vilje.
        //
        // «payments.booking_id» kom med migrasjon 084 og er den vi vil ha.
        // Men «bookings.payment_id» har pekt paa Vipps-betalingen siden dag
        // én, og 084 fylte booking_id av den bare for de radene som fantes da
        // migrasjonen kjorte. En Vipps-betaling som kom til etterpaa, og for
        // rettelsen i reserverOgBetal, har fortsatt bare den gamle pekeren.
        //
        // Leser vi bare den nye, staar en betalt Vipps-plass som ubetalt her
        // — og det var noeyaktig det som skjedde. Vi leser begge.
        $rader = DB::alle(
            'SELECT p.id, p.vipps_reference, p.type, p.belop_ore, p.status, p.maate,
                    p.kommentar, p.annullert_at, p.created_at,
                    p.registrert_av, m.navn AS registrert_navn
               FROM payments p
          LEFT JOIN members m ON m.id = p.registrert_av
              WHERE p.booking_id = :b
                 OR p.id = (SELECT payment_id FROM bookings WHERE id = :b2)
           ORDER BY p.id',
            ['b' => $bookingId, 'b2' => $bookingId]
        );

        $sum = 0;
        foreach ($rader as $r) {
            if ($r['annullert_at'] === null
                && in_array((string) $r['status'], ['betalt', 'autorisert', 'delvis_refundert'], true)) {
                $sum += (int) $r['belop_ore'];
            }
        }

        return ['rader' => $rader, 'sum' => $sum];
    }

    /**
     * Setter status paa paameldingen etter det som faktisk er betalt.
     *
     * Ett sted, saa registrering og annullering aldri kan svare forskjellig:
     * paameldingen er betalt naar summen av betalingene som staar dekker
     * beloepet, og ikke ellers.
     *
     * Avbestilte og «ikke mott» roeres ikke. En som ikke kom, skal ikke bli
     * «reservert» igjen fordi noen annullerte en betaling.
     *
     * @return array{status: string, sum: int, skyldig: int}
     */
    public static function settBetaltStatus(int $bookingId): array
    {
        $b = DB::en('SELECT id, belop_ore, status FROM bookings WHERE id = :i', ['i' => $bookingId]);
        if ($b === null) {
            return ['status' => 'reservert', 'sum' => 0, 'skyldig' => 0];
        }

        $bet = self::betalingerFor($bookingId);

        // Den nyeste manuelle betalingen som fortsatt staar bestemmer hva som
        // vises som betalingsmaate. Bare manuelle: en Vipps-rad har ingen
        // «maate», og ville toemt feltet — og Paameldte viser uansett «Vipps»
        // naar det finnes en referanse.
        $siste = null;
        foreach ($bet['rader'] as $r) {
            if ((string) $r['type'] === 'manuell' && $r['annullert_at'] === null
                && (string) $r['status'] === 'betalt') {
                $siste = $r;
            }
        }

        $ny = (string) $b['status'];
        if (in_array($ny, ['betalt', 'reservert'], true)) {
            $ny = $bet['sum'] >= (int) $b['belop_ore'] ? 'betalt' : 'reservert';
        }

        // «payment_id» og «betalt_maate» roeres bare naar det finnes en
        // manuell betaling aa peke paa, eller naar den siste ble annullert.
        // Ellers ville en Vipps-booking mistet pekeren sin.
        $data = ['status' => $ny];
        if ($siste !== null) {
            $data['payment_id']   = (int) $siste['id'];
            $data['betalt_maate'] = $siste['maate'];
        } elseif (self::harManuell($bet['rader'])) {
            // Alle de manuelle er annullert. Da skal ikke pekeren staa igjen
            // paa en betaling som ikke gjelder.
            $data['payment_id']   = null;
            $data['betalt_maate'] = null;
        }

        DB::oppdater('bookings', $data, ['id' => $bookingId]);

        return [
            'status'  => $ny,
            'sum'     => $bet['sum'],
            'skyldig' => max(0, (int) $b['belop_ore'] - $bet['sum']),
        ];
    }

    /** @param list<array<string,mixed>> $rader */
    private static function harManuell(array $rader): bool
    {
        foreach ($rader as $r) {
            if ((string) $r['type'] === 'manuell') {
                return true;
            }
        }
        return false;
    }

    /** 2026-09-02 15:30:00 UTC → «onsdag 2. september, 17:30» */
    public static function norskDato(string $utc): string
    {
        $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'));

        $dager = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];

        return sprintf(
            '%s %d. %s, %s',
            $dager[(int) $d->format('N') - 1],
            (int) $d->format('j'),
            $mnd[(int) $d->format('n') - 1],
            $d->format('H:i')
        );
    }
}
