<?php
/**
 * Drop-in: aapningstider, regler og pris.
 *
 *   GET                       tidene, regelen og prisen
 *   POST handling=lagreTider  { tider: [{ ukedag, fra, til, kapasitet }] }
 *   POST handling=lagreRegel  { tekst, pris, plasser }
 *   POST handling=lagUtOkter  { uker }  lager bookbare okter av tidene
 *
 * Pris og kapasitet ligger paa drop-in-kurset, ikke i en egen tabell. Ellers
 * ville prisen staatt to steder, og den kunden trekkes ville vaert den andre
 * enn den eieren ser.
 *
 * Regelteksten ligger i content_blocks, som resten av tekstene eieren endrer.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

const DROPIN_SLUG  = 'drop-in';
const DROPIN_REGEL = 'Dropin/regel';

$kurs = DB::en("SELECT * FROM courses WHERE slug = :s", ['s' => DROPIN_SLUG]);
if ($kurs === null) {
    Svar::feil('Fant ikke drop-in-kurset. Kjør databaseoppdateringen først.', 500);
}

$hentTider = static fn(): array => array_map(static fn($t) => [
    'id'        => (int) $t['id'],
    'ukedag'    => (int) $t['ukedag'],
    'fra'       => substr((string) $t['fra'], 0, 5),
    'til'       => substr((string) $t['til'], 0, 5),
    'kapasitet' => $t['kapasitet'] === null ? null : (int) $t['kapasitet'],
], DB::alle('SELECT * FROM dropin_tider WHERE aktiv = 1 ORDER BY ukedag, fra'));

/**
 * Tidene som faktisk ligger ute, ikke reglene.
 *
 * Skjermen viste ukereglene — «torsdag 10–13» — og et tall paa hvor mange
 * oekter som laa ute. Selve oektene var ikke til aa se noe sted, og de er
 * det som gjelder: de lages av reglene den dagen du trykker «Legg ut
 * tidene», og blir liggende. Endrer du regelen senere, ryddes bare de
 * framtidige som ingen har booket — og oekter som er laget for haand ryddes
 * aldri.
 *
 * Resultatet var at aapningstida paa nettsiden kunne si «til 19» av en oekt
 * ingen kunne finne: skjermen sa noe annet enn basen.
 */
$hentOkter = static function (array $kurs, array $tider): array {
    $oslo = new DateTimeZone('Europe/Oslo');
    $utc  = new DateTimeZone('UTC');
    $idag = (new DateTimeImmutable('now', $oslo))->setTime(0, 0);

    // Hvilke ukedag-og-klokkeslett som staar som regel naa. En oekt som ikke
    // svarer til noen av dem, stemmer ikke med det skjermen viser.
    $regler = [];
    foreach ($tider as $t) {
        $regler[$t['ukedag'] . ' ' . $t['fra'] . '-' . $t['til']] = true;
    }

    // Genererte oekter kom med migrasjon 076/079: drop-in blir bookbar de
    // dagene et kurs gaar, eller Lissom er stemplet inn. De folger ikke
    // ukereglene under, og skal ikke rammes inn i roedt som om noe var galt.
    $apenFelt = DB::harKolonne('course_sessions', 'fra_apningstid')
        ? 'cs.fra_apningstid' : '0 AS fra_apningstid';

    $rader = DB::alle(
        "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.fra_dropin_tid, {$apenFelt},
                (SELECT COUNT(*) FROM bookings b
                  WHERE b.course_session_id = cs.id
                    AND b.status IN ('betalt','reservert')) AS pameldte
           FROM course_sessions cs
          WHERE cs.course_id = :c
            AND cs.status = 'planlagt'
            AND cs.start_tid >= :fra
            AND cs.start_tid < :til
       ORDER BY cs.start_tid",
        [
            'c'   => $kurs['id'],
            'fra' => $idag->setTimezone($utc)->format('Y-m-d H:i:s'),
            'til' => $idag->modify('+8 weeks')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ]
    );

    return array_map(static function (array $r) use ($oslo, $utc, $regler): array {
        $start = (new DateTimeImmutable((string) $r['start_tid'], $utc))->setTimezone($oslo);
        $stopp = $r['slutt_tid'] !== null
            ? (new DateTimeImmutable((string) $r['slutt_tid'], $utc))->setTimezone($oslo)
            : null;
        $nokkel = $start->format('N') . ' ' . $start->format('H:i')
                . '-' . ($stopp !== null ? $stopp->format('H:i') : '');

        // Laget av aapningstidene: den folger kursene og innstemplinga, og er
        // riktig selv om den ikke staar i ukereglene.
        $fraApen = (int) ($r['fra_apningstid'] ?? 0) === 1;

        return [
            'oktId'    => (int) $r['id'],
            'naar'     => Booking::norskDato((string) $r['start_tid']),
            'fra'      => $start->format('H:i'),
            'til'      => $stopp !== null ? $stopp->format('H:i') : '',
            'idag'     => $start->format('Y-m-d') === (new DateTimeImmutable('now', $oslo))->format('Y-m-d'),
            'pameldte' => (int) $r['pameldte'],
            'fraRegel' => $r['fra_dropin_tid'] !== null,
            'fraApningstid' => $fraApen,
            // Svarer oekta til en av ukereglene som staar oppe naa? En oekt
            // laget av aapningstidene svarer ikke til noen ukeregel, og skal
            // heller ikke gjore det — den er riktig av en annen grunn.
            'stemmer'  => $fraApen || isset($regler[$nokkel]),
            'utenSlutt' => $stopp === null,
        ];
    }, $rader);
};

// ---------------------------------------------------------------- lesing
if (Foresporsel::metode() === 'GET') {
    $tider = $hentTider();
    Svar::json([
        'tider' => $tider,
        'regel' => (string) (DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => DROPIN_REGEL]) ?? ''),
        'pris'      => (int) $kurs['pris_ore'] / 100,
        'kapasitet' => (int) $kurs['kapasitet'],
        // Det faste vinduet: drop-in staar hver dag mellom disse to
        // klokkeslettene, uavhengig av kurs og aapningstider. Se migrasjon
        // 102 og Apent::leggUtPaaApneTider().
        'fastFra'   => substr((string) ($kurs['fast_fra'] ?? ''), 0, 5),
        'fastTil'   => substr((string) ($kurs['fast_til'] ?? ''), 0, 5),
        'okter'     => (int) DB::verdi(
            "SELECT COUNT(*) FROM course_sessions
              WHERE course_id = :c AND status = 'planlagt' AND start_tid > UTC_TIMESTAMP()",
            ['c' => $kurs['id']]
        ),
        // Selve tidene som ligger ute. Det er disse som styrer aapningstida
        // paa nettsiden — ikke reglene over.
        'okterListe' => $hentOkter($kurs, $tider),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

switch (Foresporsel::tekst('handling')) {

    // ------------------------------------------------------------ tidene
    case 'lagreTider':
        $inn = Foresporsel::kropp()['tider'] ?? null;
        if (!is_array($inn)) {
            Svar::feil('Mangler tidene.');
        }
        if (count($inn) > 40) {
            Svar::feil('For mange åpningstider.');
        }

        $rene = [];
        foreach ($inn as $t) {
            $dag = (int) ($t['ukedag'] ?? 0);
            $fra = (string) ($t['fra'] ?? '');
            $til = (string) ($t['til'] ?? '');

            if ($dag < 1 || $dag > 7) {
                continue;
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $fra) || !preg_match('/^\d{2}:\d{2}$/', $til)) {
                continue;
            }
            if ($til <= $fra) {
                Svar::feil('«Til» må være etter «fra» — sjekk ' . $fra . '–' . $til . '.');
            }
            $rene[] = [
                'ukedag'    => $dag,
                'fra'       => $fra . ':00',
                'til'       => $til . ':00',
                'kapasitet' => isset($t['kapasitet']) && $t['kapasitet'] !== '' && $t['kapasitet'] !== null
                                ? max(1, min(99, (int) $t['kapasitet'])) : null,
            ];
        }

        // Hele settet byttes ut i én transaksjon. Halvveis lagring ville gitt
        // aapningstider som ikke stemmer med noe.
        //
        // Oektene som alt ligger ute ryddes med: de er laget av de gamle
        // reglene, og blir de staaende, sier nettsiden aapningstider som
        // ikke finnes noe sted. Bare framtidige uten paameldte — en oekt
        // noen har booket staar, uansett hva reglene sier naa.
        DB::iTransaksjon(static function () use ($rene, $kurs): void {
            DB::kjor(
                "DELETE cs FROM course_sessions cs
                  WHERE cs.course_id = :c
                    AND cs.start_tid > UTC_TIMESTAMP()
                    AND NOT EXISTS (SELECT 1 FROM bookings b
                                     WHERE b.course_session_id = cs.id)",
                ['c' => $kurs['id']]
            );
            DB::kjor('UPDATE dropin_tider SET aktiv = 0');
            foreach ($rene as $t) {
                DB::kjor(
                    'INSERT INTO dropin_tider (ukedag, fra, til, kapasitet, aktiv)
                          VALUES (:d, :f, :t, :k, 1)
                     ON DUPLICATE KEY UPDATE til = VALUES(til), kapasitet = VALUES(kapasitet), aktiv = 1',
                    ['d' => $t['ukedag'], 'f' => $t['fra'], 't' => $t['til'], 'k' => $t['kapasitet']]
                );
            }
        });

        revider('dropin_tider_lagret', null, null, ['antall' => count($rene)]);
        Svar::ok(['tider' => $hentTider(), 'beskjed' => count($rene) . ' åpningstider er lagret.']);

    // ------------------------------------------------------------ regelen
    case 'lagreRegel':
        $tekst = mb_substr(Foresporsel::tekst('tekst'), 0, 2000);
        $pris  = Foresporsel::heltall('pris');
        $plass = Foresporsel::heltall('plasser');

        if ($pris < 0 || $pris > 20000) {
            Svar::feil('Prisen må være mellom 0 og 20 000 kroner.');
        }

        DB::kjor(
            'INSERT INTO content_blocks (nokkel, verdi, endret_av) VALUES (:n, :v, :a)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            ['n' => DROPIN_REGEL, 'v' => $tekst, 'a' => Sesjon::medlem()['id'] ?? null]
        );

        DB::oppdater('courses', [
            'pris_ore'  => $pris * 100,
            'kapasitet' => max(1, min(99, $plass ?: (int) $kurs['kapasitet'])),
        ], ['id' => $kurs['id']]);

        revider('dropin_regel_lagret', 'course', (int) $kurs['id'], ['pris' => $pris]);
        Svar::ok(['beskjed' => 'Reglene og prisen er lagret.']);

    // ------------------------------------------------------- fast vindu
    //
    // Eieren, 30. august: «det skal ikke foelge kurs eller aapningstider»,
    // «det skal kunne bookes tid mellom kl 08:00 og 22:00». To klokkeslett,
    // og saa staar drop-in der hver dag.
    //
    // Tomme felt slaar vinduet av. Da faller drop-in tilbake paa
    // aapningstidene, slik den sto for 30. august.
    case 'lagreVindu':
        $fra = trim(Foresporsel::tekst('fra'));
        $til = trim(Foresporsel::tekst('til'));
        $gyldig = static fn(string $t): bool => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);

        if ($fra === '' && $til === '') {
            DB::oppdater('courses', ['fast_fra' => null, 'fast_til' => null], ['id' => $kurs['id']]);
            Apent::leggUtPaaApneTider();
            revider('dropin_vindu_av', 'course', (int) $kurs['id'], []);
            Svar::ok(['beskjed' => 'Det faste vinduet er slått av. Drop-in følger åpningstidene igjen.']);
        }
        if (!$gyldig($fra) || !$gyldig($til)) {
            Svar::feil('Skriv begge klokkeslettene som tt:mm, for eksempel 08:00 og 22:00.');
        }
        if ($fra >= $til) {
            Svar::feil('Fra-tida må være før til-tida.');
        }
        // Kortere enn én plass gir ingen plasser i det hele tatt, og da ser
        // det ut som om noe er i stykker.
        $minutter = (((int) substr($til, 0, 2) * 60) + (int) substr($til, 3, 2))
                  - (((int) substr($fra, 0, 2) * 60) + (int) substr($fra, 3, 2));
        if ($minutter < Apent::PLASS_MINUTTER) {
            Svar::feil('Vinduet må være minst ' . Apent::PLASS_MINUTTER . ' minutter — én plass.');
        }

        DB::oppdater('courses', [
            'fast_fra' => $fra . ':00',
            'fast_til' => $til . ':00',
            'folger_apningstid' => 1,
        ], ['id' => $kurs['id']]);
        $r = Apent::leggUtPaaApneTider();
        revider('dropin_vindu_lagret', 'course', (int) $kurs['id'], ['fra' => $fra, 'til' => $til]);
        Svar::ok([
            'fastFra' => $fra,
            'fastTil' => $til,
            'beskjed' => 'Drop-in står nå ' . $fra . '–' . $til . ' hver dag. '
                . $r['laget'] . ' plasser lagt ut.',
        ]);

    // -------------------------------------------------------- lag ut okter
    //
    // Lager bookbare okter av aapningstidene, framover i tid. Okter som alt
    // er laget av en aapningstid og ikke har paameldte ryddes forst, saa
    // endrede tider slaar gjennom. Okter lagt inn for haand rores ikke.
    case 'lagUtOkter':
        // Staar det faste vinduet, er det den som gjelder. To generatorer paa
        // det samme kurset ville lagt plasser oppi hverandre — en tre timers
        // regelplass midt i rekka av halvannen time, med hvert sitt
        // plasstall.
        if (($kurs['fast_fra'] ?? null) !== null && ($kurs['fast_til'] ?? null) !== null) {
            $r = Apent::leggUtPaaApneTider();
            Svar::ok([
                'beskjed' => 'Drop-in står ' . substr((string) $kurs['fast_fra'], 0, 5) . '–'
                    . substr((string) $kurs['fast_til'], 0, 5) . ' hver dag. '
                    . $r['laget'] . ' plasser lagt ut, ' . $r['fjernet'] . ' ryddet bort.',
            ]);
        }
        $uker = max(1, min(26, Foresporsel::heltall('uker', 8)));
        $tider = DB::alle('SELECT * FROM dropin_tider WHERE aktiv = 1 ORDER BY ukedag, fra');
        if ($tider === []) {
            Svar::feil('Sett opp åpningstider først.');
        }

        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $naa  = new DateTimeImmutable('now', $oslo);

        $fjernet = DB::kjor(
            "DELETE cs FROM course_sessions cs
              WHERE cs.course_id = :c
                AND cs.fra_dropin_tid IS NOT NULL
                AND cs.start_tid > UTC_TIMESTAMP()
                AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.course_session_id = cs.id)",
            ['c' => $kurs['id']]
        )->rowCount();

        // Kursholderen: den som staar paa kurset, ellers verkstedets
        // standard. Drop-in er aapen tid, ikke en avtale — den gjor ingen
        // opptatt (se kollisjonssjekken i kurs.php), men den skal ha et navn
        // paa seg i kalenderen som alle andre datoer.
        $harHolder = Kursholder::klar();
        $holder    = $harHolder ? Kursholder::forKurs((int) $kurs['id']) : null;

        $laget = 0;
        for ($d = 0; $d < $uker * 7; $d++) {
            $dag = $naa->modify('+' . $d . ' days');
            foreach ($tider as $t) {
                if ((int) $dag->format('N') !== (int) $t['ukedag']) {
                    continue;
                }
                [$tf, $mf] = array_map('intval', explode(':', (string) $t['fra']));
                [$tt, $mt] = array_map('intval', explode(':', (string) $t['til']));

                $start = $dag->setTime($tf, $mf);
                if ($start <= $naa) {
                    continue;   // i dag, men klokka er passert
                }

                $verdier = [
                    'c' => $kurs['id'],
                    's' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'e' => $dag->setTime($tt, $mt)->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'k' => $t['kapasitet'],
                    't' => $t['id'],
                ];
                $ekstraKol = '';
                $ekstraVal = '';
                if ($harHolder) {
                    $ekstraKol = ', kursholder_id';
                    $ekstraVal = ', :h';
                    $verdier['h'] = $holder;
                }
                DB::kjor(
                    'INSERT IGNORE INTO course_sessions
                        (course_id, start_tid, slutt_tid, kapasitet, fra_dropin_tid' . $ekstraKol . ')
                     VALUES (:c, :s, :e, :k, :t' . $ekstraVal . ')',
                    $verdier
                );
                $laget++;
            }
        }

        revider('dropin_okter_laget', 'course', (int) $kurs['id'], ['uker' => $uker, 'laget' => $laget]);
        Svar::ok([
            'beskjed' => $laget . ' drop-in-tider er lagt ut for de neste ' . $uker . ' ukene.'
                . ($fjernet > 0 ? ' ' . $fjernet . ' gamle uten påmeldte ble ryddet bort.' : ''),
        ]);

    // ------------------------------------------------------- slett én okt
    //
    // Tidene som ligger ute er det som gjelder. Stemmer én av dem ikke med
    // reglene lenger — den ble laget for haand, eller reglene er endret
    // etterpaa — maa den kunne tas bort her, der man ser den.
    case 'slettOkt':
        $oktId = Foresporsel::heltall('oktId');
        $okt = DB::en(
            'SELECT id, start_tid FROM course_sessions WHERE id = :i AND course_id = :c',
            ['i' => $oktId, 'c' => $kurs['id']]
        );
        if ($okt === null) {
            Svar::feil('Fant ikke tiden.', 404);
        }
        $pameldte = (int) DB::verdi(
            "SELECT COUNT(*) FROM bookings
              WHERE course_session_id = :o AND status IN ('betalt','reservert')",
            ['o' => $oktId]
        );
        $naar = Booking::norskDato((string) $okt['start_tid']);

        if ($pameldte > 0) {
            DB::oppdater('course_sessions', ['status' => 'avlyst'], ['id' => $oktId]);
            revider('dropin_okt_avlyst', 'course_session', $oktId, ['pameldte' => $pameldte]);
            Svar::ok([
                'beskjed' => $pameldte . ($pameldte === 1 ? ' har' : ' har') . ' meldt seg paa '
                    . $naar . ', saa tiden er avlyst og tatt av nettsiden i stedet for slettet. '
                    . 'Husk aa gi beskjed og refundere.',
                'okterListe' => $hentOkter($kurs, $hentTider()),
            ]);
        }

        // Avbestilte bookinger peker fortsatt hit, uten fremmednokkel som
        // sier fra. Vi loesner dem foerst, saa bilaget beholder kurset sitt.
        DB::kjor(
            'UPDATE bookings SET course_session_id = NULL WHERE course_session_id = :o',
            ['o' => $oktId]
        );
        DB::kjor('DELETE FROM course_sessions WHERE id = :i', ['i' => $oktId]);
        revider('dropin_okt_slettet', 'course_session', $oktId, ['naar' => $naar]);
        Svar::ok([
            'beskjed' => $naar . ' er tatt bort.',
            'okterListe' => $hentOkter($kurs, $hentTider()),
        ]);

    default:
        Svar::feil('Ukjent handling.');
}
