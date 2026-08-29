<?php
require dirname(__DIR__) . '/app/bootstrap.php';

$ok = 0; $feil = [];
function sjekk(string $hva, bool $stemmer, string $detalj = ''): void {
    global $ok, $feil;
    if ($stemmer) { $ok++; echo "  ✓ $hva\n"; }
    else { $feil[] = $hva . ($detalj ? " — $detalj" : ''); echo "  ✗ $hva" . ($detalj ? " — $detalj" : '') . "\n"; }
}

/**
 * Rydd bort spor fra forrige kjoring.
 *
 * Uten dette gikk testen bare én gang: andre gjennomkjoring stoppet paa at
 * testmedlemmet allerede fantes. En test man ikke kan kjore om igjen, er ikke
 * en test.
 *
 * Rekkefolgen folger fremmednoklene — det som peker paa noe, maa vekk forst.
 */
function nullstill(): void
{
    $medlemmer = array_column(
        DB::alle("SELECT id FROM members WHERE vipps_sub LIKE 'test-%'"),
        'id'
    );

    DB::kjor("DELETE FROM notifications WHERE mottaker LIKE '%@example.com'");
    DB::kjor("DELETE FROM waitlist WHERE epost LIKE '%@example.com'");

    $bookinger = array_column(DB::alle(
        "SELECT b.id FROM bookings b
      LEFT JOIN courses c ON c.id = b.course_id
          WHERE c.slug IN ('testliten', 'testgratis', 'testkapasitet')
             OR b.gjest_epost LIKE '%@example.com'
             OR b.gjest_navn IN ('Test', 'Utlopt', 'Forste', 'Andre')"
        . ($medlemmer ? ' OR b.member_id IN (' . implode(',', $medlemmer) . ')' : '')
    ), 'id');

    $betalinger = $bookinger
        ? array_column(DB::alle('SELECT payment_id FROM bookings WHERE id IN ('
            . implode(',', $bookinger) . ') AND payment_id IS NOT NULL'), 'payment_id')
        : [];

    if ($bookinger) {
        DB::kjor('DELETE FROM bookings WHERE id IN (' . implode(',', $bookinger) . ')');
    }
    if ($betalinger) {
        DB::kjor('DELETE FROM payments WHERE id IN (' . implode(',', $betalinger) . ')');
    }
    if ($medlemmer) {
        DB::kjor('DELETE FROM payments WHERE member_id IN (' . implode(',', $medlemmer) . ')');
    }

    DB::kjor("DELETE FROM course_sessions WHERE course_id IN (SELECT id FROM courses WHERE slug IN ('testliten', 'testgratis', 'testkapasitet'))");
    DB::kjor("DELETE FROM courses WHERE slug IN ('testliten', 'testgratis', 'testkapasitet')");
    DB::kjor("DELETE FROM rate_limits WHERE nokkel LIKE 'proev:%'");
    if (DB::harTabell('medlemsgaver') && $medlemmer) {
        DB::kjor('DELETE FROM medlemsgave_bruk WHERE member_id IN (' . implode(',', $medlemmer) . ')');
        DB::kjor('DELETE FROM medlemsgaver WHERE gitt_av IN (' . implode(',', $medlemmer) . ')'
               . ' OR member_id IN (' . implode(',', $medlemmer) . ')');
    }

    if ($medlemmer) {
        DB::kjor('DELETE FROM membership_applications WHERE member_id IN (' . implode(',', $medlemmer) . ')');
        DB::kjor('DELETE FROM members WHERE id IN (' . implode(',', $medlemmer) . ')');
    }
}

nullstill();

echo "\n== Katalog ==\n";
$kurs = DB::alle("SELECT * FROM courses WHERE status='publisert'");
sjekk('kurs er publisert', count($kurs) >= 9, count($kurs) . ' stk');
$pop = DB::en("SELECT * FROM courses WHERE slug='paint-on-pots'");
// Paint on Pots kostet 690 — prisen med gjenstanden inkludert. Etter
// migrasjon 074 og 075 er de to skilt: plassen er gratis, og gjenstanden
// betales i kassa. Sjekken sier naa at de to henger sammen, ikke at prisen
// er et bestemt tall: er plassen gratis, MAA gjenstanden betales i
// verkstedet — ellers er kurset gratis ved et uhell.
$kassa = DB::harKolonne('courses', 'gjenstand_i_kassa')
    ? (int) $pop['gjenstand_i_kassa'] : 0;
sjekk('Paint on Pots: gratis plass henger sammen med betaling i verkstedet',
    (int) $pop['pris_ore'] > 0 || $kassa === 1,
    $pop['pris_ore'] . ' ore, gjenstand_i_kassa=' . $kassa);
$test = DB::en("SELECT status FROM courses WHERE slug='testkurs'");
sjekk('testkurset er ute av sirkulasjon', $test === null || $test['status'] === 'avlyst', $test['status'] ?? 'slettet');
$boller = DB::en("SELECT tema FROM courses WHERE slug='kurs-boller'");
sjekk('bollekurset har tema Plateteknikk', $boller['tema'] === 'Plateteknikk', $boller['tema']);
// Nytt navn fra migrasjon 087, og slugen staar: /kurs/kurs-boller er delt i
// e-poster og indeksert av Google.
$bTittel = DB::verdi("SELECT tittel FROM courses WHERE slug='kurs-boller'");
sjekk('bollekurset heter «Lag din egen bolle»', $bTittel === 'Lag din egen bolle', (string) $bTittel);
// Kursteksten henger paa navnet. Byttes det ene uten det andre, faller
// kurssida tilbake paa den generelle plateteknikk-malen.
$malFil = file_get_contents(dirname(__DIR__) . '/app/lib/kursmal.php');
sjekk('kursteksten folger med det nye navnet',
    str_contains($malFil, "'Lag din egen bolle' => \$bolle,")
    && str_contains($malFil, "'Kurs boller'        => \$bolle,"));
sjekk('bollekurset beholder sin egen tekst etter navnebyttet',
    Kursmal::forKurs(['tittel' => 'Lag din egen bolle', 'tema' => 'Plateteknikk'])['lagerDu']
        === 'To personlige boller i keramikk.');
$dropin = DB::verdi("SELECT COUNT(*) FROM course_sessions cs JOIN courses c ON c.id=cs.course_id WHERE c.slug='drop-in'");
// Antallet varierer: apningstidene i admin lager nye okter framover, og
// «lag ut okter» rydder bort gamle uten paameldte. Det som betyr noe er at
// drop-in har datoer i det hele tatt — uten dem kan ingen booke.
sjekk('drop-in har datoer', (int) $dropin > 0, $dropin . ' okter');

echo "\n== Kapasitet ==\n";
// Testen laante en oekt fra katalogen (Paint on Pots). Da verkstedet endret
// datoene sine, sto kurset uten oekter — og testen sammenlignet null med null
// og meldte «gronn». En test som gaar gronn paa manglende data, tester
// ingenting. Riggen lages her og ryddes bort av nullstill().
$kapKurs = DB::settInn('courses', ['slug'=>'testkapasitet','tittel'=>'Testkapasitet','type'=>'kurs','pris_ore'=>69000,'kapasitet'=>12,'status'=>'publisert']);
$oktId = DB::settInn('course_sessions', ['course_id'=>$kapKurs,'start_tid'=>gmdate('Y-m-d H:i:s', time()+864000),'kapasitet'=>12]);
$okt = ['id'=>$oktId, 'kap'=>12];
sjekk('full kapasitet naar ingen har booket', Booking::ledigePlasser($oktId) === (int)$okt['kap'], Booking::ledigePlasser($oktId) . ' av ' . $okt['kap']);

echo "\n== Ledige plasser, én og mange ==\n";
//
// Katalogen spurte én gang per kursdato. Naa hentes alle i én sporring, og
// da maa de to svare likt — det ene tallet viser «3 plasser igjen», det
// andre selger den siste stolen.
{
    $prove = array_map(
        static fn(array $r): int => (int) $r['id'],
        DB::alle('SELECT id FROM course_sessions ORDER BY id DESC LIMIT 25')
    );
    // En som ikke finnes skal svare 0, slik den gjorde da hvert kall sto for seg.
    $prove[] = 999999999;

    $kart = Booking::ledigePlasserFlere($prove);
    $ulike = [];
    foreach ($prove as $id) {
        $en = Booking::ledigePlasser($id);
        if (($kart[$id] ?? null) !== $en) {
            $ulike[] = $id . ': ' . ($kart[$id] ?? 'mangler') . ' mot ' . $en;
        }
    }
    sjekk('samlekallet svarer som ett og ett', $ulike === [],
        count($prove) . ' okter' . ($ulike ? ' — ' . implode(', ', array_slice($ulike, 0, 3)) : ''));
    sjekk('en okt som ikke finnes har null plasser', ($kart[999999999] ?? null) === 0);
    sjekk('tom liste gir tomt svar', Booking::ledigePlasserFlere([]) === []);

    // En avlyst okt selger ingenting.
    $avlyst = DB::en("SELECT id FROM course_sessions WHERE status = 'avlyst' LIMIT 1");
    if ($avlyst !== null) {
        sjekk('en avlyst okt har null plasser',
            Booking::ledigePlasserFlere([(int) $avlyst['id']])[(int) $avlyst['id']] === 0);
    }
}

echo "\n== Medlem og sesjon ==\n";
$medlemId = DB::settInn('members', ['vipps_sub'=>'test-sub-1','navn'=>'Test Testesen','epost'=>'test@example.com','telefon'=>'+4791234567']);
$token = Sesjon::opprett($medlemId);
sjekk('sesjonstoken er 64 tegn', strlen($token) === 64);
sjekk('tokenet lagres ikke i klartekst', DB::en('SELECT 1 FROM sessions WHERE token_hash = :t', ['t'=>$token]) === null);
sjekk('hashen finnes', DB::en('SELECT 1 FROM sessions WHERE token_hash = :t', ['t'=>hash('sha256',$token)]) !== null);
$utl = DB::verdi('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), expires_at) FROM sessions WHERE token_hash = :t', ['t'=>hash('sha256',$token)]);
sjekk('sesjonen varer ~3 timer', (int)$utl >= 178 && (int)$utl <= 181, $utl . ' minutter');
sjekk('medlemmet gjenkjennes', (Sesjon::medlem()['id'] ?? 0) == $medlemId);
sjekk('nummeret gir admin (nodluke)', Sesjon::erAdmin());

echo "\n== Aapne plasser (Paint on Pots paa aapningstidene) ==\n";
//
// Regelen for naar det er aapent staar ett sted: app/lib/apent.php. Den samme
// regelen legger ut plassene. Det farlige er sirkelen — en plass laget fordi
// det var aapent, som deretter gjor at det er aapent — og at en plass noen
// har booket blir ryddet bort under foettene paa dem.
$folgerFelt = DB::harKolonne('courses', 'folger_apningstid') ? 'folger_apningstid' : 'gjenstand_i_kassa';
if (DB::harKolonne('course_sessions', 'fra_apningstid')
    && DB::en("SELECT id FROM courses WHERE {$folgerFelt} = 1 AND status = 'publisert'") !== null) {
    DB::kjor('DELETE FROM course_sessions WHERE fra_apningstid = 1');

    $r1 = Apent::leggUtPaaApneTider();
    sjekk('plasser legges ut paa de aapne dagene', $r1['laget'] > 0, $r1['laget'] . ' laget');

    $r2 = Apent::leggUtPaaApneTider();
    sjekk('en ny kjoring lager ingenting nytt',
        $r2['laget'] === 0 && $r2['fjernet'] === 0, json_encode($r2));

    // Sirkelen: aapningstida skal ikke telle plassene den selv laget.
    $genererte = array_map('intval', array_column(
        DB::alle('SELECT id FROM course_sessions WHERE fra_apningstid = 1'), 'id'));
    $kildeIder = [];
    foreach (Apent::dager()['kilder'] as $liste) {
        foreach ($liste as $o) {
            $kildeIder[] = (int) $o['oktId'];
        }
    }
    sjekk('aapningstida teller ikke plassene den selv laget',
        array_intersect($genererte, $kildeIder) === [],
        count($genererte) . ' genererte plasser');

    // En booket plass staar, ogsaa naar dagen stenges.
    $popKurs2 = DB::en("SELECT id FROM courses WHERE {$folgerFelt} = 1 AND status = 'publisert'");
    $okt2 = DB::en("SELECT id, start_tid FROM course_sessions
                     WHERE fra_apningstid = 1 AND course_id = :c AND start_tid > UTC_TIMESTAMP()
                  ORDER BY start_tid LIMIT 1", ['c' => (int) $popKurs2['id']]);
    if ($okt2 !== null) {
        $dagen = (new DateTimeImmutable((string) $okt2['start_tid'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d');
        DB::settInn('bookings', [
            'course_id' => (int) $popKurs2['id'], 'course_session_id' => (int) $okt2['id'],
            'gjest_navn' => 'Testplass apen', 'antall' => 1, 'belop_ore' => 0, 'status' => 'betalt',
        ]);
        DB::kjor("INSERT INTO apningstider (dato, stengt, merknad) VALUES (:d, 1, 'Test')
                  ON DUPLICATE KEY UPDATE stengt = 1, merknad = 'Test'", ['d' => $dagen]);
        Apent::leggUtPaaApneTider();
        sjekk('en booket plass ryddes ikke bort naar dagen stenges',
            DB::en('SELECT id FROM course_sessions WHERE id = :i', ['i' => (int) $okt2['id']]) !== null,
            'okt ' . $okt2['id'] . ' paa ' . $dagen);
        DB::kjor('DELETE FROM apningstider WHERE dato = :d', ['d' => $dagen]);
        DB::kjor("DELETE FROM bookings WHERE gjest_navn = 'Testplass apen'");
    }

    // Plassene er hoyst PLASS_MINUTTER lange, og de ligger inne i dagens
    // aapningstid — ogsaa i timene mellom to kurs, for da er hun der.
    DB::kjor('DELETE FROM course_sessions WHERE fra_apningstid = 1');
    Apent::leggUtPaaApneTider();
    $utc2 = new DateTimeZone('UTC');
    $oslo2 = new DateTimeZone('Europe/Oslo');
    $forLange = 0;
    $perDag = [];
    $iHull = 0;
    $kilder2 = Apent::dager()['kilder'];
    $dagerRad = [];
    foreach (Apent::dager()['dager'] as $d) {
        if (!$d['stengt'] && $d['fra'] !== null) {
            $dagerRad[(string) $d['dato']] = ['fra' => (string) $d['fra'], 'til' => (string) $d['til']];
        }
    }
    foreach (DB::alle('SELECT course_id, start_tid, slutt_tid FROM course_sessions
                        WHERE fra_apningstid = 1') as $r) {
        $a = new DateTimeImmutable((string) $r['start_tid'], $utc2);
        $b2 = new DateTimeImmutable((string) $r['slutt_tid'], $utc2);
        if ($b2->getTimestamp() - $a->getTimestamp() > Apent::PLASS_MINUTTER * 60) {
            $forLange++;
        }
        $lokal = $a->setTimezone($oslo2);
        $dag = $lokal->format('Y-m-d');
        // Taket gjelder per kurs. Paint on Pots og drop-in staar begge ute
        // paa den samme dagen, og til sammen er de flere enn taket sier.
        $nokkel2 = $r['course_id'] . ' ' . $dag;
        $perDag[$nokkel2] = ($perDag[$nokkel2] ?? 0) + 1;

        // Plassen skal ligge inne i dagens aapningstid — hele den, fra det
        // forste begynner til det siste slutter.
        if (!isset($dagerRad[$dag])
            || $lokal->format('H:i') < $dagerRad[$dag]['fra']
            || $b2->setTimezone($oslo2)->format('H:i') > $dagerRad[$dag]['til']) {
            $iHull++;
        }
    }
    sjekk('ingen plass er lengre enn ' . Apent::PLASS_MINUTTER . ' minutter',
        $forLange === 0, $forLange . ' for lange');
    sjekk('hoyst ' . Apent::PLASSER_PER_DAG . ' plass(er) per kurs per dag',
        max($perDag ?: [0]) <= Apent::PLASSER_PER_DAG,
        'flest paa en dag: ' . max($perDag ?: [0]));
    sjekk('ingen plass ligger utenfor aapningstida', $iHull === 0, $iHull . ' utenfor');

    // Flere kurs samme dag: timene mellom dem er ogsaa bookbare.
    //
    // 3. september i testdataene: Store fat 10-13, drop-in 16-19, Store fat
    // 17-20, Date Night 18-21. Dagen er aapen 10-21, og mellom 13 og 16 er
    // hun der uansett. Lissom 27. august: «husk tiden som er mellom kurs
    // ogsaa skal vaere tilgjengelig aa booke».
    $medFlere = null;
    foreach (Apent::dager()['kilder'] as $dato => $liste) {
        if (count($liste) < 2) {
            continue;
        }
        // Finn en dag der oektene IKKE henger sammen hele veien.
        $sortert = $liste;
        usort($sortert, static fn($a, $b) => strcmp($a['fra'], $b['fra']));
        $slutt = $sortert[0]['til'];
        foreach ($sortert as $o) {
            if ($o['fra'] > $slutt) {
                $medFlere = ['dato' => $dato, 'hullFra' => $slutt, 'hullTil' => $o['fra']];
                break 2;
            }
            $slutt = max($slutt, $o['til']);
        }
    }
    if ($medFlere !== null) {
        $iHullet = [];
        foreach (DB::alle('SELECT start_tid FROM course_sessions WHERE fra_apningstid = 1') as $r) {
            $t = (new DateTimeImmutable((string) $r['start_tid'], $utc2))->setTimezone($oslo2);
            if ($t->format('Y-m-d') === $medFlere['dato']
                && $t->format('H:i') >= $medFlere['hullFra']
                && $t->format('H:i') < $medFlere['hullTil']) {
                $iHullet[] = $t->format('H:i');
            }
        }
        sjekk('flere kurs samme dag: timene mellom dem er ogsaa bookbare',
            $iHullet !== [],
            $medFlere['dato'] . ' mellom ' . $medFlere['hullFra'] . ' og ' . $medFlere['hullTil']
            . ($iHullet ? ' — plasser ' . implode(', ', $iHullet) : ' — ingen plasser'));
    }

    // Et flerdagerskurs skal ikke gjore natta aapen.
    //
    // Oekta lagres som én rad fra forste dag til siste — dreiekurset gaar
    // 17-20 to kvelder og staar som «9. sept 17:00 → 10. sept 20:00». Ble hver
    // dag klippet mot dognet, sto dag to som aapen fra 00:00, og Paint on Pots
    // ble bookbart klokka to om natta.
    $flere = DB::en("SELECT cs.start_tid, cs.slutt_tid FROM course_sessions cs
                       JOIN courses c ON c.id = cs.course_id
                      WHERE cs.slutt_tid IS NOT NULL
                        AND DATE(cs.start_tid) <> DATE(cs.slutt_tid)
                        AND cs.fra_apningstid = 0
                        AND cs.start_tid > UTC_TIMESTAMP()
                        AND TIME(cs.slutt_tid) > TIME(cs.start_tid)
                   ORDER BY cs.start_tid LIMIT 1");
    if ($flere !== null) {
        $dagTo = (new DateTimeImmutable((string) $flere['slutt_tid'], $utc2))
            ->setTimezone($oslo2)->format('Y-m-d');
        $radTo = null;
        foreach (Apent::dager()['dager'] as $d) {
            if ($d['dato'] === $dagTo) {
                $radTo = $d;
            }
        }
        sjekk('dag to av et flerdagerskurs aapner ikke ved midnatt',
            $radTo !== null && $radTo['fra'] !== '00:00',
            $dagTo . ': ' . ($radTo === null ? 'dagen mangler' : $radTo['fra'] . '-' . $radTo['til']));
    }

    // Drop-in gaar paa det samme (27. august): samme bestilling, med datoer
    // og tider, og tilgjengeligheten folger kursene og innstemplinga.
    //
    // Det som kan gaa galt her er dubletter. Drop-in har egne tider fra
    // ukereglene — tirsdag 10-13 — og laa de to oppi hverandre, sto den
    // samme timen to ganger i bestillingen, med hvert sitt plasstall.
    $dropinKurs = DB::en("SELECT id FROM courses
                           WHERE type = 'dropin' AND status = 'publisert'"
                        . (DB::harKolonne('courses', 'folger_apningstid')
                            ? ' AND folger_apningstid = 1' : ''));
    if ($dropinKurs !== null) {
        $dId = (int) $dropinKurs['id'];
        sjekk('drop-in faar plasser paa de aapne dagene',
            (int) DB::verdi('SELECT COUNT(*) FROM course_sessions
                              WHERE course_id = :c AND fra_apningstid = 1', ['c' => $dId]) > 0);

        $egne = DB::alle('SELECT start_tid, slutt_tid FROM course_sessions
                           WHERE course_id = :c AND fra_apningstid = 0
                             AND slutt_tid IS NOT NULL
                             AND COALESCE(slutt_tid, start_tid) > UTC_TIMESTAMP()', ['c' => $dId]);
        $dubletter = [];
        foreach (DB::alle('SELECT start_tid, slutt_tid FROM course_sessions
                            WHERE course_id = :c AND fra_apningstid = 1', ['c' => $dId]) as $g) {
            foreach ($egne as $e) {
                if ($g['start_tid'] < $e['slutt_tid'] && $e['start_tid'] < $g['slutt_tid']) {
                    $dubletter[] = $g['start_tid'];
                }
            }
        }
        sjekk('ingen plass legges oppi drop-in-tidene som alt staar',
            $dubletter === [],
            count($egne) . ' egne tider' . ($dubletter ? ' — dublett ' . $dubletter[0] : ''));

        // Og de egne tidene staar urort. De definerer fortsatt naar det er
        // aapent, og skal ikke ryddes bort av utleggingen.
        sjekk('drop-in-tidene fra ukereglene roeres ikke',
            (int) DB::verdi('SELECT COUNT(*) FROM course_sessions
                              WHERE course_id = :c AND fra_dropin_tid IS NOT NULL
                                AND start_tid > UTC_TIMESTAMP()', ['c' => $dId]) > 0);
    }

    DB::kjor('DELETE FROM course_sessions WHERE fra_apningstid = 1');
    Apent::leggUtPaaApneTider();
}

echo "\n== Oppfoelgingen etter kurset ==\n";
//
// Meldingen skal ikke kunne gaa ut ved et uhell. Tre ting maa stemme, og
// den skal aldri naa noen som var her for lenge siden.
if (DB::harKolonne('course_sessions', 'anmeldelse_sendt_at')) {
    $mal = DB::en("SELECT kanal, aktiv FROM notification_templates WHERE navn = 'anmeldelse'");
    sjekk('malen finnes og staar som SMS',
        $mal !== null && (string) $mal['kanal'] === 'sms', json_encode($mal));

    // Uten SMS satt opp skal den gaa som e-post — samme melding, annen vei.
    // Det er hele poenget: den er klar for SMS uten aa vente paa SMS.
    $forSms = Varsel::smsMulig();
    sjekk('den gaar som e-post saa lenge SMS ikke er satt opp',
        $forSms === false || $forSms === true, 'smsMulig: ' . var_export($forSms, true));

    $kursId = (int) DB::verdi("SELECT id FROM courses WHERE status = 'publisert' LIMIT 1");
    $lagOkt = static function (int $kursId, int $timerSiden): int {
        return DB::settInn('course_sessions', [
            'course_id' => $kursId,
            'start_tid' => gmdate('Y-m-d H:i:s', time() - ($timerSiden + 3) * 3600),
            'slutt_tid' => gmdate('Y-m-d H:i:s', time() - $timerSiden * 3600),
            'kapasitet' => 8,
        ]);
    };

    // En okt fra i gaar og en fra forrige uke, begge med en betalt deltaker.
    $nyOkt = $lagOkt($kursId, 5);
    $gammelOkt = $lagOkt($kursId, 24 * 9);
    foreach ([$nyOkt, $gammelOkt] as $o) {
        DB::settInn('bookings', [
            'course_id' => $kursId, 'course_session_id' => $o,
            'gjest_navn' => 'Anmeldelsesprove', 'gjest_epost' => 'anm@example.com',
            'antall' => 1, 'belop_ore' => 0, 'status' => 'betalt',
        ]);
    }

    $koFor = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'anmeldelse'");

    // Uten lenke skal ingenting gaa, ogsaa naar bryteren staar paa.
    DB::kjor("INSERT INTO innstillinger (nokkel, verdi) VALUES ('anmeldelse_paa','1'),('anmeldelse_lenke','')
              ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)");
    Config::glemBasen();
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/cron.php') . ' anmeldelser 2>&1');
    sjekk('uten lenke sendes ingenting',
        (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'anmeldelse'") === $koFor);

    // Med lenke: den ferske okta skal med, den ni dager gamle ikke.
    DB::kjor("UPDATE innstillinger SET verdi = 'https://eksempel.test/anmeld' WHERE nokkel = 'anmeldelse_lenke'");
    Config::glemBasen();
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/cron.php') . ' anmeldelser 2>&1');

    sjekk('den ferske okta fikk oppfoelging',
        DB::verdi('SELECT anmeldelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $nyOkt]) !== null);
    sjekk('en okt fra ni dager siden roeres ikke',
        DB::verdi('SELECT anmeldelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $gammelOkt]) === null,
        'ellers ville alle tidligere deltakere faatt melding den dagen bryteren skrus paa');

    $tekst = (string) DB::verdi(
        "SELECT tekst FROM notifications WHERE mal = 'anmeldelse' ORDER BY id DESC LIMIT 1");
    sjekk('lenken staar i meldingen', str_contains($tekst, 'https://eksempel.test/anmeld'));
    sjekk('ingen ufylte plassholdere igjen',
        preg_match('/\{[a-zA-Z_]+\}/', $tekst) !== 1, $tekst);

    // En ny kjoring skal ikke sende paa nytt.
    $etter = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'anmeldelse'");
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/cron.php') . ' anmeldelser 2>&1');
    sjekk('en ny kjoring sender ikke paa nytt',
        (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'anmeldelse'") === $etter);

    // Og med bryteren av skjer ingenting.
    DB::kjor("UPDATE innstillinger SET verdi = '0' WHERE nokkel = 'anmeldelse_paa'");
    Config::glemBasen();
    $enda = $lagOkt($kursId, 6);
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/cron.php') . ' anmeldelser 2>&1');
    sjekk('med bryteren av skjer ingenting',
        DB::verdi('SELECT anmeldelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $enda]) === null);

    // Rydder etter oss.
    DB::kjor("DELETE FROM bookings WHERE gjest_navn = 'Anmeldelsesprove'");
    DB::kjor('DELETE FROM course_sessions WHERE id IN (:a, :b, :c)',
        ['a' => $nyOkt, 'b' => $gammelOkt, 'c' => $enda]);
    DB::kjor("DELETE FROM notifications WHERE mal = 'anmeldelse'");
    DB::kjor("UPDATE innstillinger SET verdi = '' WHERE nokkel = 'anmeldelse_lenke'");
    Config::glemBasen();
}

echo "\n== Booking uten Vipps (gratis medlemsarrangement) ==\n";
// Sto paa medlemsfrokosten. Den ble avlyst etter beskjed fra verkstedet
// (migrasjon 037), og testen stoppet paa «Denne datoen kan ikke bookes».
// Katalogen er verkstedets, ikke testens — riggen lages her.
$gratisKurs = DB::settInn('courses', ['slug'=>'testgratis','tittel'=>'Testgratis','type'=>'event','pris_ore'=>0,'kapasitet'=>10,'status'=>'publisert']);
$gratisOkt = DB::settInn('course_sessions', ['course_id'=>$gratisKurs,'start_tid'=>gmdate('Y-m-d H:i:s', time()+864000),'kapasitet'=>10]);
$r = Booking::reserverOgBetal($gratisOkt, 1, 'Test Testesen', 'test@example.com', '+4791234567', $medlemId);
sjekk('gratis booking uten betaling', $r['redirectUrl'] === '' && $r['bookingId'] > 0);
sjekk('bookingen er betalt med en gang', DB::verdi('SELECT status FROM bookings WHERE id=:i',['i'=>$r['bookingId']]) === 'betalt');
sjekk('kvittering lagt i ko', (int)DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type='booking' AND ref_id=:i",['i'=>$r['bookingId']]) > 0);

echo "\n== Betaling markeres som betalt, én gang ==\n";
$ref = Vipps::nyReferanse();
$pid = DB::settInn('payments', ['vipps_reference'=>$ref,'type'=>'epayment','formal'=>'booking','member_id'=>$medlemId,'belop_ore'=>69000,'status'=>'venter','idempotency_key'=>Vipps::uuid()]);
$bid = DB::settInn('bookings', ['course_id'=>$kapKurs,'course_session_id'=>$oktId,'member_id'=>$medlemId,'gjest_navn'=>'Test','antall'=>1,'belop_ore'=>69000,'status'=>'reservert','payment_id'=>$pid,'reservert_til'=>gmdate('Y-m-d H:i:s', time()+1200)]);
sjekk('forste markering virker', Booking::markerBetalt($ref) === true);
sjekk('bookingen ble betalt', DB::verdi('SELECT status FROM bookings WHERE id=:i',['i'=>$bid]) === 'betalt');
sjekk('reservasjonsfristen er fjernet', DB::verdi('SELECT reservert_til FROM bookings WHERE id=:i',['i'=>$bid]) === null);
sjekk('andre markering gjor ingenting', Booking::markerBetalt($ref) === false);
$antKvitt = (int)DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type='booking' AND ref_id=:i",['i'=>$bid]);
sjekk('kun én kvittering tross to markeringer', $antKvitt === 1, $antKvitt . ' stk');

echo "\n== Kapasitet teller reservasjoner ==\n";
$forbrukt = Booking::ledigePlasser($oktId);
sjekk('én plass er borte', $forbrukt === (int)$okt['kap'] - 1, "$forbrukt igjen av {$okt['kap']}");

echo "\n== Utlopt reservasjon frigir plassen ==\n";
$bid2 = DB::settInn('bookings', ['course_id'=>$kapKurs,'course_session_id'=>$oktId,'member_id'=>null,'gjest_navn'=>'Utlopt','antall'=>1,'belop_ore'=>69000,'status'=>'reservert','reservert_til'=>gmdate('Y-m-d H:i:s', time()-60)]);
sjekk('utlopt reservasjon teller ikke', Booking::ledigePlasser($oktId) === $forbrukt);

echo "\n== Overbooking avvises ==\n";
$liten = DB::settInn('courses', ['slug'=>'testliten','tittel'=>'Liten','type'=>'kurs','pris_ore'=>0,'kapasitet'=>1,'status'=>'publisert']);
$litenOkt = DB::settInn('course_sessions', ['course_id'=>$liten,'start_tid'=>gmdate('Y-m-d H:i:s', time()+864000)]);
Booking::reserverOgBetal($litenOkt, 1, 'Forste', 'a@example.com', '+4791234567', $medlemId);
try { Booking::reserverOgBetal($litenOkt, 1, 'Andre', 'b@example.com', '+4791234568', $medlemId); sjekk('siste plass kan ikke bookes to ganger', false, 'slapp gjennom'); }
catch (RuntimeException $e) { sjekk('siste plass kan ikke bookes to ganger', true, $e->getMessage()); }

echo "\n== Norsk dato og kroner ==\n";
sjekk('UTC blir norsk tid', Booking::norskDato('2026-09-02 15:30:00') === 'onsdag 2. september, 17:30', Booking::norskDato('2026-09-02 15:30:00'));
sjekk('kronebelop formateres', Booking::kroner(280000) === 'kr. 2 800,-', Booking::kroner(280000));
sjekk('null kroner', Booking::kroner(0) === 'kr. 0,-', Booking::kroner(0));
sjekk('kort dato regnes om til norsk tid',
    Booking::norskDatoKort('2026-08-19 22:30:00') === '20. august 2026',
    Booking::norskDatoKort('2026-08-19 22:30:00'));

echo "\n== Ratebegrensning ==\n";
for ($i = 0; $i < 3; $i++) { Rate::tillat('proev', 3, 60, 'x'); }
sjekk('grensen slaar inn ved fjerde forsok', Rate::tillat('proev', 3, 60, 'x') === false);

echo "\n== Medlemskap er ikke det samme som innlogging ==\n";

// Vipps Login gir en rad i members med status «ingen». Uten dette skillet
// ville alle med Vipps hatt dorkode og interne kurs.
$kundeId = DB::settInn('members', [
    'vipps_sub' => 'test-kunde-' . bin2hex(random_bytes(4)),
    'navn'      => 'Test Kunde',
    'epost'     => 'kunde@example.com',
    'telefon'   => '+4790000001',
    'status'    => 'ingen',
]);
$hent = static fn(): array => DB::en('SELECT * FROM members WHERE id = :id', ['id' => $GLOBALS['kundeId']]);
$GLOBALS['kundeId'] = $kundeId;
sjekk('innlogget kunde er ikke medlem', er_aktivt_medlem($hent()) === false, $hent()['status']);

foreach (['prove', 'aktiv', 'pause'] as $st) {
    DB::oppdater('members', ['status' => $st], ['id' => $kundeId]);
    sjekk("status «{$st}» gir tilgang", er_aktivt_medlem($hent()) === true);
}

DB::oppdater('members', ['status' => 'oppsagt'], ['id' => $kundeId]);
sjekk('oppsagt medlem mister tilgangen', er_aktivt_medlem($hent()) === false);

DB::oppdater('members', ['status' => 'ingen', 'rolle' => 'admin'], ['id' => $kundeId]);
sjekk('admin slipper inn uansett status', er_aktivt_medlem($hent()) === true);
DB::oppdater('members', ['rolle' => 'medlem', 'status' => 'ingen'], ['id' => $kundeId]);

// Soknaden er selve porten: den skal kunne staa til behandling, og det er
// godkjenningen — ikke soknaden — som apner medlemsdelen.
$soknadId = DB::settInn('membership_applications', [
    'member_id'   => $kundeId,
    'onsket_type' => '30 timer',
    'navn'        => 'Test Kunde',
    'epost'       => 'kunde@example.com',
    'status'      => 'venter',
]);
sjekk('soknaden ligger til behandling',
    DB::verdi('SELECT status FROM membership_applications WHERE id = :id', ['id' => $soknadId]) === 'venter');
sjekk('soknad apner ikke medlemsdelen i seg selv', er_aktivt_medlem($hent()) === false);

DB::oppdater('membership_applications', ['status' => 'godkjent'], ['id' => $soknadId]);
DB::oppdater('members', ['status' => 'prove', 'medlemskap_type' => '30 timer'], ['id' => $kundeId]);
sjekk('godkjenning apner medlemsdelen', er_aktivt_medlem($hent()) === true);

// Medlemsarrangementene er gratis og skjult fra den offentlige lista. Skjult
// er ikke stengt — book.php slaar opp temaet, og det oppslaget testes her.
$internOkt = DB::verdi(
    "SELECT cs.id FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
      WHERE c.tema = 'Kun for medlemmer' LIMIT 1"
);
sjekk('det finnes et medlemsarrangement aa sjekke mot', !empty($internOkt));
if ($internOkt) {
    sjekk('okt-oppslaget finner temaet book.php sjekker',
        (string) DB::verdi(
            'SELECT c.tema FROM course_sessions cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :id',
            ['id' => $internOkt]
        ) === 'Kun for medlemmer');
}

// ── Gaver fra verkstedet ───────────────────────────────────────────────────
//
// «Send gaven» la gaven i nettleseren til den som trykket. Testen her spor om
// gaven finner fram til medlemmet, og om den kan loeses inn mer enn én gang.

echo "\n== Gaver til medlemmene ==\n";

$idag = gmdate('Y-m-d');
$gaveAlle = DB::settInn('medlemsgaver', [
    'member_id' => null, 'type' => 'venn', 'gyldig_til' => gmdate('Y-m-t'), 'gitt_av' => $medlemId,
]);

// Samme utvalg som api/gave.php gjor, men avgrenset til gavene testen selv
// har laget. Uten avgrensningen ville en ekte gave i basen — verkstedet gir
// dem jo — avgjort svaret, og testen ville sagt fra om noe helt annet.
$minGave = static function (int $megId, array $ider) use ($idag): ?array {
    if (!$ider) { return null; }
    return DB::en(
        'SELECT g.* FROM medlemsgaver g
          WHERE g.id IN (' . implode(',', array_map('intval', $ider)) . ')
            AND (g.member_id = :m OR g.member_id IS NULL)
            AND g.status = \'aktiv\' AND g.gyldig_til >= :idag
            AND NOT EXISTS (SELECT 1 FROM medlemsgave_bruk b
                             WHERE b.gave_id = g.id AND b.member_id = :m2)
       ORDER BY g.member_id IS NULL, g.id DESC LIMIT 1',
        ['m' => $megId, 'm2' => $megId, 'idag' => $idag]
    );
};

sjekk('gave til alle naar medlemmet', (int) ($minGave($medlemId, [$gaveAlle])['id'] ?? 0) === $gaveAlle);

// En personlig gave gaar foran fellesgaven.
$gaveMin = DB::settInn('medlemsgaver', [
    'member_id' => $medlemId, 'type' => 'gavekort', 'belop_ore' => 50000,
    'gyldig_til' => gmdate('Y-m-t'), 'gitt_av' => $medlemId,
]);
sjekk('personlig gave gaar foran fellesgaven', (int) ($minGave($medlemId, [$gaveAlle, $gaveMin])['id'] ?? 0) === $gaveMin);

DB::settInn('medlemsgave_bruk', ['gave_id' => $gaveMin, 'member_id' => $medlemId]);
sjekk('brukt gave forsvinner', (int) ($minGave($medlemId, [$gaveAlle, $gaveMin])['id'] ?? 0) === $gaveAlle);

$toGanger = false;
try { DB::settInn('medlemsgave_bruk', ['gave_id' => $gaveMin, 'member_id' => $medlemId]); $toGanger = true; }
catch (PDOException $e) { /* uniknoekkelen stopper den — som den skal */ }
sjekk('samme gave kan ikke loeses inn to ganger', $toGanger === false);

DB::oppdater('medlemsgaver', ['status' => 'trukket'], ['id' => $gaveAlle]);
sjekk('trukket gave vises ikke', $minGave($medlemId, [$gaveAlle, $gaveMin]) === null);

DB::oppdater('medlemsgaver', ['status' => 'aktiv', 'gyldig_til' => gmdate('Y-m-d', time() - 86400)], ['id' => $gaveAlle]);
sjekk('utloept gave vises ikke', $minGave($medlemId, [$gaveAlle, $gaveMin]) === null);

echo "\n== Én person, én rad (Vipps-innlogging) ==\n";

// Duplikater i medlemslista kom herfra. Innmeldingsskjemaet slaar opp paa
// baade telefon og e-post for det lager en ny rad; Vipps-innloggingen slo bare
// opp paa telefon. Meldt inn for haand med e-post og uten nummer, sto personen
// der to ganger den dagen hun logget inn.
//
// En OAuth-runde mot Vipps kan ikke kjores her. Oppslaget som avgjor hvilken
// rad profilen hoerer til, kan.

$vippsRydd = static function (): void {
    DB::kjor("DELETE FROM members WHERE epost LIKE 'vippstest%@example.test'");
};
$vippsRydd();

// 1) Ingen fra for → ny rad
$p1 = ['sub' => 'sub-test-1', 'navn' => 'Vipps Test',
       'epost' => 'vippstest1@example.test', 'telefon' => '+4790000101'];
$id1 = Vipps::medlemFraProfil($p1);
sjekk('ukjent profil gir en ny rad', $id1 > 0, 'id ' . $id1);

// 2) Samme sub igjen → samme rad, ikke en ny
$id2 = Vipps::medlemFraProfil($p1);
sjekk('samme sub gir samme rad', $id1 === $id2, 'id ' . $id2);

// 3) Finnes fra for med telefon, uten sub → knyttes til den raden
$telefonId = DB::settInn('members', [
    'navn' => 'Meldt inn med nummer', 'telefon' => '+4790000102',
    'epost' => 'vippstest2@example.test',
]);
$id3 = Vipps::medlemFraProfil(['sub' => 'sub-test-2', 'navn' => 'Vipps Test 2',
    'epost' => 'vippstest2@example.test', 'telefon' => '+4790000102']);
sjekk('kjent telefon gir ingen dublett', $id3 === $telefonId, 'id ' . $id3 . ' mot ' . $telefonId);

// 4) Finnes fra for med e-post og UTEN telefon → skal ogsaa knyttes.
//    Dette er tilfellet som laget duplikatene.
$epostId = DB::settInn('members', [
    'navn' => 'Meldt inn uten nummer', 'epost' => 'vippstest3@example.test',
]);
$id4 = Vipps::medlemFraProfil(['sub' => 'sub-test-3', 'navn' => 'Vipps Test 3',
    'epost' => 'vippstest3@example.test', 'telefon' => '+4790000103']);
sjekk('kjent e-post uten telefon gir ingen dublett', $id4 === $epostId, 'id ' . $id4 . ' mot ' . $epostId);

// 5) En rad som alt hoerer til en annen Vipps-konto skal ikke kapres
$annenId = DB::settInn('members', [
    'navn' => 'Har egen Vipps', 'epost' => 'vippstest4@example.test',
    'vipps_sub' => 'sub-test-annen',
]);
$id5 = Vipps::medlemFraProfil(['sub' => 'sub-test-5', 'navn' => 'Noen andre',
    'epost' => 'vippstest4@example.test', 'telefon' => '']);
sjekk('rad med annen Vipps-konto kapres ikke', $id5 !== $annenId, 'id ' . $id5 . ' mot ' . $annenId);

// 6) Til slutt: hvor mange rader ble det egentlig?
$antall = (int) DB::verdi("SELECT COUNT(*) FROM members WHERE epost LIKE 'vippstest%@example.test'");
sjekk('fem profiler ga fem rader, ikke flere', $antall === 5, $antall . ' rader');

$vippsRydd();

echo "\n== Kursbevis ==\n";

// Beviset skal finnes for et betalt kurs som har vaert, og ikke for noe annet.
// Reglene ligger i api/mine-plasser.php og api/kursbevis.php; her testes selve
// betingelsene, som er der det gaar galt.
$bevis = static function (array $b, bool $betalt): ?string {
    if (!$betalt) return null;
    if (in_array((string) ($b['tema'] ?? ''), ['Drop-in', 'Kun for medlemmer'], true)) return null;
    $slutt = $b['slutt_tid'] ?: $b['start_tid'];
    if ($slutt === null || strtotime((string) $slutt) > time()) return null;
    return '/api/kursbevis.php?booking=1';
};
$igaar = gmdate('Y-m-d H:i:s', time() - 86400);
$imorgen = gmdate('Y-m-d H:i:s', time() + 86400);

sjekk('gjennomfort og betalt kurs gir bevis',
    $bevis(['tema' => 'Dreiing', 'start_tid' => $igaar, 'slutt_tid' => $igaar], true) !== null);
sjekk('kurs som ikke har vaert gir ikke bevis',
    $bevis(['tema' => 'Dreiing', 'start_tid' => $imorgen, 'slutt_tid' => $imorgen], true) === null);
sjekk('ubetalt kurs gir ikke bevis',
    $bevis(['tema' => 'Dreiing', 'start_tid' => $igaar, 'slutt_tid' => $igaar], false) === null);
sjekk('drop-in gir ikke kursbevis',
    $bevis(['tema' => 'Drop-in', 'start_tid' => $igaar, 'slutt_tid' => $igaar], true) === null);
sjekk('kurs uten dato gir ikke bevis',
    $bevis(['tema' => 'Dreiing', 'start_tid' => null, 'slutt_tid' => null], true) === null);

// Instruktoren staar paa kurset; er feltet tomt, er det Monica i malen.
sjekk('kurs har instruktorfelt',
    DB::verdi("SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'courses'
                  AND column_name = 'instruktor'") == 1);
sjekk('type godtar workshop',
    strpos((string) DB::verdi("SELECT column_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'courses' AND column_name = 'type'"), 'workshop') !== false);

echo "\n== Brukernavn og passord ==\n";

// Passordet skal aldri kunne leses tilbake, bare bekreftes.
$hash = password_hash('riktigHestBatteriStift', PASSWORD_DEFAULT);
sjekk('hashen er ikke passordet', strpos($hash, 'riktigHestBatteriStift') === false);
sjekk('riktig passord godtas', password_verify('riktigHestBatteriStift', $hash));
sjekk('feil passord avvises', password_verify('riktigHestBatteriStif', $hash) === false);

// Regelen for brukernavn er den samme i api/admin/brukere.php.
$gyldig = static fn(string $b): bool => (bool) preg_match('/^[a-z0-9._-]{3,64}$/', $b);
sjekk('vanlig brukernavn godtas', $gyldig('monica'));
sjekk('punktum og bindestrek godtas', $gyldig('monica.v-l'));
sjekk('mellomrom avvises', $gyldig('mo nica') === false);
sjekk('store bokstaver avvises', $gyldig('Monica') === false);
sjekk('for kort avvises', $gyldig('mo') === false);

sjekk('brukernavn er unikt i databasen',
    (int) DB::verdi("SELECT COUNT(*) FROM information_schema.statistics
                      WHERE table_schema = DATABASE() AND table_name = 'members'
                        AND index_name = 'uq_members_brukernavn'") > 0);
sjekk('passord_hash-kolonnen finnes',
    (int) DB::verdi("SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'members'
                        AND column_name = 'passord_hash'") === 1);

// ---------------------------------------------------------------- innstempling
echo "\n== Innstempling og timer ==\n";

$testMedlem = DB::settInn('members', [
    'navn' => 'Stempeltest', 'status' => 'aktiv',
    'medlemskap_type' => '30 timer', 'timer_per_mnd' => 30,
]);

DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);

sjekk('ingen apen okt til aa begynne med', Stempling::apenOkt($testMedlem) === null);

$okt = Stempling::inn($testMedlem);
sjekk('innstempling gir en apen okt', Stempling::apenOkt($testMedlem) !== null);

sjekk('dobbel innstempling gir samme okt', Stempling::inn($testMedlem) === $okt);
sjekk('bare én apen okt i basen',
    (int) DB::verdi('SELECT COUNT(*) FROM check_ins WHERE member_id = :m AND ut_tid IS NULL',
        ['m' => $testMedlem]) === 1);

// Skru okta 95 minutter tilbake og stemple ut.
DB::kjor('UPDATE check_ins SET inn_tid = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 95 MINUTE) WHERE id = :i', ['i' => $okt]);
$min = Stempling::ut($testMedlem);
sjekk('utstempling regner minutter', $min !== null && $min >= 94 && $min <= 96, 'fikk ' . var_export($min, true));
sjekk('ingen apen okt etter utstempling', Stempling::apenOkt($testMedlem) === null);
sjekk('utstempling uten apen okt gir null', Stempling::ut($testMedlem) === null);

sjekk('minutter denne maaneden teller med',
    Stempling::minutterDenneManeden($testMedlem) >= 94);

// Glemt utstempling skal lukkes, og ikke spise hele maaneden.
DB::settInn('check_ins', [
    'member_id' => $testMedlem,
    'inn_tid'   => gmdate('Y-m-d H:i:s', time() - 3 * 86400),
]);
Stempling::lukkGlemte();
$glemt = DB::en('SELECT minutter, auto_lukket FROM check_ins WHERE member_id = :m ORDER BY id DESC LIMIT 1',
    ['m' => $testMedlem]);
sjekk('glemt utstempling lukkes', (int) ($glemt['auto_lukket'] ?? 0) === 1);
sjekk('glemt okt kappes til seks timer', (int) ($glemt['minutter'] ?? 0) === 360);

// En okt kan aldri telle mer enn taket, selv om den staar lenge aapen.
$lang = DB::settInn('check_ins', [
    'member_id' => $testMedlem,
    'inn_tid'   => gmdate('Y-m-d H:i:s', time() - 9 * 3600),
]);
sjekk('lang okt kappes ogsaa ved utstempling', Stempling::ut($testMedlem) === 360);

// Synlighet: skjulte medlemmer telles, men vises ikke.
DB::settInn('check_ins', ['member_id' => $testMedlem, 'inn_tid' => gmdate('Y-m-d H:i:s')]);
DB::oppdater('members', ['vis_innstempling' => 0], ['id' => $testMedlem]);
$inne = Stempling::inneNa();
$skjultTelt = $inne['antall'] >= 1;
$skjultVist = false;
foreach ($inne['synlige'] as $rad) {
    if ($rad['navn'] === 'Stempeltest') { $skjultVist = true; }
}
sjekk('skjult medlem telles med', $skjultTelt);
sjekk('skjult medlem vises ikke i lista', $skjultVist === false);

sjekk('varighet skrives paa norsk', Stempling::varighet(80) === '1 t 20 min'
    && Stempling::varighet(45) === '45 min' && Stempling::varighet(120) === '2 t');
sjekk('timer skrives med komma', Stempling::timer(95) === '1,6');

sjekk('maanedsgrensa folger norsk kalender', (function () {
    $oslo = new DateTimeImmutable(Stempling::manedStart(), new DateTimeZone('UTC'));
    $lokal = $oslo->setTimezone(new DateTimeZone('Europe/Oslo'));
    return $lokal->format('j') === '1' && $lokal->format('H:i') === '00:00';
})());

DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);
DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $testMedlem]);

// ---------------------------------------------------------------------------
// Grupperabatt
//
// Bookingsiden viste «Grupperabatt 10 %» og en nedsatt sum, mens serveren
// regnet pris_ore × antall og trakk full pris. Testene her holder de to
// sammen: det som staar paa skjermen er det kunden faktisk trekkes.
// ---------------------------------------------------------------------------
echo "\n== Grupperabatt ==\n";

DB::kjor("DELETE FROM discount_tiers");
DB::settInn('discount_tiers', ['min_antall' => 3, 'prosent' => 10, 'gjelder' => 'alle', 'aktiv' => 1]);
DB::settInn('discount_tiers', ['min_antall' => 5, 'prosent' => 15, 'gjelder' => 'alle', 'aktiv' => 1]);
DB::settInn('discount_tiers', ['min_antall' => 3, 'prosent' => 20, 'gjelder' => 'dreiing', 'aktiv' => 1]);
DB::settInn('discount_tiers', ['min_antall' => 4, 'prosent' => 30, 'gjelder' => 'test-kurs', 'aktiv' => 0]);

$vanlig  = ['pris_ore' => 100000, 'tema' => 'Handbygging', 'type' => 'kurs',    'slug' => 'test-kurs', 'tittel' => 'Testkurs'];
$dreie   = ['pris_ore' => 100000, 'tema' => 'Dreiing',     'type' => 'kurs',    'slug' => 'dreietest', 'tittel' => 'Dreiekurs test'];
$dropin  = ['pris_ore' => 49000,  'tema' => 'Drop-in',     'type' => 'dropin',  'slug' => 'drop-in',   'tittel' => 'Drop-in'];
$medlem  = ['pris_ore' => 259000, 'tema' => 'Medlemskap',  'type' => 'kurs',    'slug' => 'medlem',    'tittel' => 'Medlemskap'];

sjekk('én plass gir ingen rabatt', Booking::rabattProsent($vanlig, 1) === 0.0);
sjekk('to plasser gir ingen rabatt naar nivaaet er tre', Booking::rabattProsent($vanlig, 2) === 0.0);
sjekk('tre plasser gir ti prosent', Booking::rabattProsent($vanlig, 3) === 10.0);
sjekk('fem plasser gir femten prosent', Booking::rabattProsent($vanlig, 5) === 15.0);

// Flere nivaaer kan treffe samtidig. Det beste for kunden skal gjelde.
sjekk('dreiekurs faar det beste nivaaet', Booking::rabattProsent($dreie, 3) === 20.0);

// Et inaktivt nivaa skal ikke telle, selv om det passer.
sjekk('inaktivt nivaa teller ikke', Booking::rabattProsent($vanlig, 4) === 10.0);

sjekk('drop-in har ingen grupperabatt', Booking::rabattProsent($dropin, 5) === 0.0);
sjekk('medlemskap har ingen grupperabatt', Booking::rabattProsent($medlem, 5) === 0.0);

// Belopet: det som vises og det som trekkes maa vaere samme tall.
$b = Booking::belopFor($vanlig, 3);
sjekk('belop trekkes med rabatt', $b['brutto'] === 300000 && $b['netto'] === 270000 && $b['rabatt'] === 10.0);
sjekk('belop uten rabatt er uendret', Booking::belopFor($vanlig, 1)['netto'] === 100000);

// Oredeling: 15 % av 3 × 1 490 er ikke et rundt tall.
$skjev = ['pris_ore' => 149000, 'tema' => '', 'type' => 'kurs', 'slug' => 'x', 'tittel' => 'X'];
sjekk('oredeling rundes til hele ore', Booking::belopFor($skjev, 5)['netto'] === (int) round(149000 * 5 * 0.85));

DB::kjor("DELETE FROM discount_tiers");

// ---------------------------------------------------------------------------
// Medlemskap og manedstrekk
//
// Selve kallet til Vipps kan ikke testes her. Alt rundt det kan: hvilke
// avtaler som skal trekkes, at et trekk bare skjer én gang per maaned, og at
// en stoppet avtale aldri belastes.
// ---------------------------------------------------------------------------
echo "\n== Medlemskap ==\n";

sjekk('planene ligger i basen', count(Medlemskap::planer()) >= 4);
// Navnet paa planen sto her som tekst. Verkstedet doepte «30 timer» om til
// «Basis 30» i admin — noe de har full rett til — og testen falt. Den skal
// proeve oppslaget, ikke hva planen heter denne uka.
$enPlan = DB::en("SELECT * FROM membership_plans WHERE aktiv = 1 AND engangs = 0 ORDER BY sortering LIMIT 1");
sjekk('prisen leses fra basen',
    (int) (Medlemskap::plan((string) $enPlan['navn'])['pris_ore'] ?? 0) === (int) $enPlan['pris_ore'],
    $enPlan['navn'] . ' — ' . $enPlan['pris_ore'] . ' ore');
sjekk('ukjent plan gir null', Medlemskap::plan('Finnes ikke') === null);
sjekk('proveperioden er engangs', (int) (Medlemskap::plan('Prøv Lissom')['engangs'] ?? 0) === 1);
sjekk('aarsavtalen har binding', (int) (Medlemskap::plan('Årsmedlemskap')['binding_mnd'] ?? 0) === 12);

$avtaleMedlem = DB::settInn('members', [
    'navn' => 'Avtaletest', 'epost' => 'avtale@test.local', 'status' => 'ingen',
]);

$avtaleId = DB::settInn('subscriptions', [
    'member_id' => $avtaleMedlem,
    'plan' => '30 timer',
    'pris_ore' => 259000,
    'vipps_agreement_id' => 'agr_test_' . bin2hex(random_bytes(4)),
    'status' => 'aktiv',
    'neste_trekk' => gmdate('Y-m-d'),
]);

$mine = static fn(int $id): int => count(array_filter(Medlemskap::tilTrekk(), static fn($a) => (int) $a['id'] === $id));

sjekk('avtale med forfall i dag skal trekkes', $mine($avtaleId) === 1);

DB::oppdater('subscriptions', ['neste_trekk' => gmdate('Y-m-d', time() + 86400)], ['id' => $avtaleId]);
sjekk('avtale med forfall i morgen skal ikke trekkes', $mine($avtaleId) === 0);

DB::oppdater('subscriptions', ['neste_trekk' => gmdate('Y-m-d'), 'status' => 'stoppet'], ['id' => $avtaleId]);
sjekk('stoppet avtale trekkes ikke', $mine($avtaleId) === 0);

DB::oppdater('subscriptions', ['status' => 'aktiv'], ['id' => $avtaleId]);

// Dobbelt trekk: er betalingen alt fort for maaneden, gjor vi ikke noe mer.
$avtale = DB::en('SELECT s.*, m.navn, m.epost FROM subscriptions s JOIN members m ON m.id = s.member_id WHERE s.id = :i', ['i' => $avtaleId]);
$maaned = (new DateTimeImmutable((string) $avtale['neste_trekk']))->format('Y-m');
$nokkel = substr(hash('sha256', 'trekk:' . $avtale['id'] . ':' . $maaned), 0, 36);

DB::settInn('payments', [
    'vipps_reference' => 'MED-TEST-' . bin2hex(random_bytes(4)),
    'type' => 'recurring_charge',
    'formal' => 'medlemskap',
    'member_id' => $avtaleMedlem,
    'subscription_id' => $avtaleId,
    'belop_ore' => 259000,
    'status' => 'betalt',
    'idempotency_key' => $nokkel,
]);

sjekk('trekk som alt er fort gjentas ikke', Medlemskap::trekk($avtale) === 'alt fort');
sjekk('ingen ny betaling ble lagt inn', (int) DB::verdi(
    'SELECT COUNT(*) FROM payments WHERE subscription_id = :s', ['s' => $avtaleId]) === 1);

sjekk('avtalen finnes for medlemmet', (int) (Medlemskap::avtale($avtaleMedlem)['id'] ?? 0) === $avtaleId);
DB::oppdater('subscriptions', ['status' => 'stoppet'], ['id' => $avtaleId]);
sjekk('stoppet avtale regnes ikke som loepende', Medlemskap::avtale($avtaleMedlem) === null);

DB::kjor('DELETE FROM payments WHERE subscription_id = :s', ['s' => $avtaleId]);
DB::kjor('DELETE FROM subscriptions WHERE id = :i', ['i' => $avtaleId]);
DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $avtaleMedlem]);


// ── Timene et medlemskap gir ───────────────────────────────────────────────
//
// «timer_per_mnd» paa medlemsraden ble aldri fylt ut — bare lest. Alle sto
// med NULL, som betyr fri tilgang, og Min side viste ingen timeoversikt til
// noen. Planen bestemmer naa, og medlemsraden overstyrer.

echo "\n== Timer per maaned ==\n";

// Timetallene sto her som tall. «Proev Lissom» ble satt opp fra 8 til 10
// timer (migrasjon 031), og testen falt paa en endring den selv ba om.
// Fasiten skal komme fra basen — det er den planen faktisk lover.
foreach (DB::alle('SELECT navn, timer FROM membership_plans WHERE aktiv = 1 ORDER BY sortering') as $pl) {
    $forventet = $pl['timer'] === null ? null : (int) $pl['timer'];
    sjekk(
        'planen «' . $pl['navn'] . '» gir ' . ($forventet === null ? 'ingen grense' : $forventet . ' timer'),
        Medlemskap::timerFor(['medlemskap_type' => $pl['navn'], 'timer_per_mnd' => null]) === $forventet
    );
}
sjekk('eget timetall gaar foran planen', Medlemskap::timerFor(['medlemskap_type' => (string) $enPlan['navn'], 'timer_per_mnd' => 12]) === 12);
sjekk('ukjent plan gir ingen grense', Medlemskap::timerFor(['medlemskap_type' => 'Finnes ikke', 'timer_per_mnd' => null]) === null);
sjekk('uten medlemskap ingen grense', Medlemskap::timerFor(['medlemskap_type' => '', 'timer_per_mnd' => null]) === null);

// ── Samtidige bookinger og betaling som ikke kom i gang ────────────────────
//
// To ting som bare viser seg naar noe gaar galt paa akkurat riktig tidspunkt,
// og som derfor er lette aa miste igjen ved en senere endring.

echo "\n== Plassen paa den siste stolen ==\n";

// Et kurs med pris. Testen under skal se at en betaling som ikke kommer i
// gang frigir plassen — og da maa det vaere noe aa betale. Paint on Pots sto
// her, og er gratis siden migrasjon 075: booking gikk rett gjennom, og
// halve testen falt bort uten at noen sa fra.
$popKurs = DB::en("SELECT id, pris_ore FROM courses
                    WHERE pris_ore > 0 AND status = 'publisert'
                 ORDER BY id LIMIT 1");
$enPlass = DB::settInn('course_sessions', [
    'course_id' => $popKurs['id'],
    'start_tid' => gmdate('Y-m-d H:i:s', time() + 86400 * 40),
    'slutt_tid' => gmdate('Y-m-d H:i:s', time() + 86400 * 40 + 7200),
    'kapasitet' => 1,
    'status'    => 'planlagt',
]);

sjekk('okta har én plass', Booking::ledigePlasser($enPlass) === 1);

// Lesningen inne i en transaksjon skal ta laas. Uten den ser to samtidige
// bookinger den samme siste plassen, og begge far den.
$laastLest = DB::iTransaksjon(static fn(): int => Booking::ledigePlasser($enPlass, true));
sjekk('laast lesning gir samme svar', $laastLest === 1, (string) $laastLest);
sjekk('laas utenfor transaksjon er ufarlig', Booking::ledigePlasser($enPlass, true) === 1);

// Betalingen kommer ikke i gang (ingen Vipps-noekler i testen), og da skal
// plassen frigis med det samme — ikke staa reservert i tjue minutter.
$fikkFeil = false;
try {
    Booking::reserverOgBetal($enPlass, 1, 'Testkunde', 'test@lissom.test', '90000000', null);
} catch (Throwable $e) {
    $fikkFeil = true;
}
if ($fikkFeil) {
    sjekk('plassen er ledig igjen etter mislykket betaling',
        Booking::ledigePlasser($enPlass) === 1, Booking::ledigePlasser($enPlass) . ' ledige');
    sjekk('bookingen staar som avbestilt', (int) DB::verdi(
        "SELECT COUNT(*) FROM bookings WHERE course_session_id = :i AND status = 'avbestilt'",
        ['i' => $enPlass]) === 1);
} else {
    // Med gyldige noekler gaar betalingen gjennom, og da er plassen opptatt.
    sjekk('plassen er opptatt naar betalingen kom i gang', Booking::ledigePlasser($enPlass) === 0);
}

// Bookingene forst — betalingene de peker paa kan ikke slettes for.
$betalinger = array_column(DB::alle(
    'SELECT payment_id FROM bookings WHERE course_session_id = :i AND payment_id IS NOT NULL',
    ['i' => $enPlass]), 'payment_id');
DB::kjor('DELETE FROM bookings WHERE course_session_id = :i', ['i' => $enPlass]);
foreach ($betalinger as $pid) {
    DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pid]);
}
DB::kjor('DELETE FROM course_sessions WHERE id = :i', ['i' => $enPlass]);

// ── Ingen kommer inn som medlem uten aa betale ─────────────────────────
//
// Soknaden om medlemskap oppretter betalingsavtalen i Vipps. Gjorde den ikke
// det, fantes det to veier inn: medlemskapssida med avtale og trekk, og
// soknadsskjemaet uten. Den andre ga tilgang uten at det fantes noe aa trekke
// fra — og cron henter bare avtaler som er aktive.
//
// Vipps naas ikke herfra, saa selve avtalen kan ikke opprettes i en test. Det
// som kan proves er alt rundt: at planen maa finnes, at godkjenningen krever
// en avtale, og at ingen soknad blir liggende igjen naar avtalen ikke lot seg
// opprette.
echo "\n== Medlemskap krever betaling ==\n";

$planer = array_column(Medlemskap::planer(), 'navn');
sjekk('det finnes medlemskap aa soke om', count($planer) > 0, implode(', ', $planer));

// Navnene skjemaet tilbyr maa vaere de samme som basen har. Sto som en fast
// liste i nettsida med «30 timer», mens basen heter «Basis 30» — soknaden ble
// sendt med et medlemskap som ikke fantes.
$iSida = [];
$html = file_get_contents(dirname(__DIR__) . '/lissom-2108.html');
if (preg_match('/bmTyper: this\.medlemsplaner\(\)/', $html) === 1) {
    sjekk('skjemaet henter medlemskapene fra basen, ikke fra en fast liste', true);
} else {
    sjekk('skjemaet henter medlemskapene fra basen, ikke fra en fast liste', false,
        'bmTyper staar fortsatt som en skrevet liste');
}

foreach (['30 timer', 'Tullemedlemskap'] as $tull) {
    sjekk('«' . $tull . '» er ikke et medlemskap som finnes',
        Medlemskap::plan($tull) === null);
}

// Godkjenning uten avtale: tillatt, men skal si fra. Eldre soknader har ingen.
$uten = Medlemskap::slippForsteTrekk(999999);
sjekk('et medlem uten avtale svarer «ingen»', $uten['status'] === 'ingen', $uten['status']);

// En avtale som staar til godkjenning holder trekket igjen.
$tPlan = Medlemskap::planer()[0];
$tMedlem = (int) DB::settInn('members', [
    'navn' => 'Testsoker Betaling', 'epost' => 'testsoker@example.test',
    'telefon' => '+4790000001', 'rolle' => 'medlem', 'status' => 'ingen',
]);
$tSoknad = (int) DB::settInn('membership_applications', [
    'member_id' => $tMedlem, 'onsket_type' => $tPlan['navn'],
    'navn' => 'Testsoker Betaling', 'epost' => 'testsoker@example.test',
    'status' => 'venter',
]);
$tAvtale = (int) DB::settInn('subscriptions', [
    'member_id' => $tMedlem, 'plan' => $tPlan['navn'],
    'pris_ore' => (int) $tPlan['pris_ore'], 'vipps_agreement_id' => '',
    'status' => 'venter',
]);
$rad = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $tAvtale]);
sjekk('en ny avtale staar som «venter» og har ingen trekkdato',
    $rad['status'] === 'venter' && $rad['neste_trekk'] === null);

// Uten avtale-id spor vi ikke Vipps, og statusen staar. Da skal godkjenningen
// nekte — ingen skal inn paa en avtale som ikke er godkjent.
$ut = Medlemskap::slippForsteTrekk($tMedlem);
sjekk('godkjenning slipper ikke trekket naar avtalen ikke er aktiv',
    $ut['status'] !== 'aktiv', $ut['status']);
sjekk('trekkdatoen staar fortsatt tom', DB::verdi(
    'SELECT neste_trekk FROM subscriptions WHERE id = :i', ['i' => $tAvtale]) === null);

// Og medlemmet er ikke sluppet inn.
sjekk('medlemmet har ikke faatt tilgang', DB::verdi(
    'SELECT status FROM members WHERE id = :i', ['i' => $tMedlem]) === 'ingen');

DB::kjor('DELETE FROM subscriptions WHERE id = :i', ['i' => $tAvtale]);
DB::kjor('DELETE FROM membership_applications WHERE id = :i', ['i' => $tSoknad]);
DB::kjor('DELETE FROM members WHERE id = :i', ['i' => $tMedlem]);

// ── Fast trekk eller ordne selv ────────────────────────────────────────
sjekk('aarsmedlemskapet krever fast trekk',
    Medlemskap::kreverFastTrekk(Medlemskap::plan('Årsmedlemskap') ?? []));
foreach (['Basis 30', 'Fri tilgang', 'Prøv Lissom'] as $fritt) {
    $pl = Medlemskap::plan($fritt);
    sjekk('«' . $fritt . '» lar medlemmet velge',
        $pl !== null && !Medlemskap::kreverFastTrekk($pl));
}

// Et medlemskap uten fast trekk skal aldri hentes av det automatiske trekket.
$eMedlem = (int) DB::settInn('members', [
    'navn' => 'Engangs Testesen', 'epost' => 'engangs@example.test',
    'rolle' => 'medlem', 'status' => 'ingen',
]);
$ePlan = Medlemskap::plan('Basis 30');
$eAb = (int) DB::settInn('subscriptions', [
    'member_id' => $eMedlem, 'plan' => 'Basis 30',
    'pris_ore' => (int) $ePlan['pris_ore'], 'vipps_agreement_id' => '',
    'status' => 'venter',
]);
Medlemskap::betaltEngangs($eAb);
$eRad = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $eAb]);
sjekk('betalt engangsmedlemskap blir aktivt', $eRad['status'] === 'aktiv', $eRad['status']);
sjekk('… men faar ingen trekkdato', $eRad['neste_trekk'] === null);
sjekk('… og medlemmet slippes inn',
    DB::verdi('SELECT status FROM members WHERE id = :i', ['i' => $eMedlem]) === 'aktiv');
sjekk('… og det automatiske trekket henter den ikke',
    !in_array($eAb, array_map('intval', array_column(Medlemskap::tilTrekk(), 'id')), true));
sjekk('en ny kjoring gjor ingenting mer', (static function () use ($eAb) {
    Medlemskap::betaltEngangs($eAb);
    return DB::verdi('SELECT neste_trekk FROM subscriptions WHERE id = :i', ['i' => $eAb]) === null;
})());
DB::kjor('DELETE FROM subscriptions WHERE id = :i', ['i' => $eAb]);
DB::kjor('DELETE FROM members WHERE id = :i', ['i' => $eMedlem]);

// ── Bindingstid og oppsigelsestid ──────────────────────────────────────
//
// To maaneder fra innmelding, tolv paa aarsavtalen, én maaneds oppsigelse.
echo "\n== Binding og oppsigelse ==\n";

foreach (['Basis 30' => 2, 'Fri tilgang' => 2, 'Prøv Lissom' => 2, 'Årsmedlemskap' => 12] as $navn => $mnd) {
    $pl = Medlemskap::plan($navn);
    sjekk('«' . $navn . '» har ' . $mnd . ' maaneders binding',
        $pl !== null && (int) $pl['binding_mnd'] === $mnd, (string) ($pl['binding_mnd'] ?? '?'));
    sjekk('«' . $navn . '» har én maaneds oppsigelse',
        $pl !== null && (int) $pl['oppsigelse_mnd'] === 1);
}

$bMedlem = (int) DB::settInn('members', [
    'navn' => 'Binding Testesen', 'epost' => 'binding@example.test',
    'rolle' => 'medlem', 'status' => 'aktiv',
]);
$lagAvtale = static function (string $plan, ?string $bindingTil) use ($bMedlem): array {
    $pl = Medlemskap::plan($plan);
    $id = (int) DB::settInn('subscriptions', [
        'member_id' => $bMedlem, 'plan' => $plan, 'pris_ore' => (int) $pl['pris_ore'],
        'vipps_agreement_id' => '', 'status' => 'aktiv', 'binding_til' => $bindingTil,
    ]);
    return DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $id]);
};

// Bundet: kan ikke sies opp.
$aar = $lagAvtale('Årsmedlemskap', gmdate('Y-m-d', strtotime('+300 days')));
$h = Medlemskap::hvorforIkkeSiOpp($aar);
sjekk('aarsavtalen kan ikke sies opp for aaret er ute', $h !== null);
sjekk('… og beskjeden sier at det er aarsavtalen',
    $h !== null && str_contains($h, 'Årsavtalen'), (string) $h);
$feilet = false;
try { Medlemskap::siOpp($aar); } catch (RuntimeException $e) { $feilet = true; }
sjekk('… og oppsigelsen blir avvist', $feilet);
sjekk('… uten aa sette sluttdato',
    DB::verdi('SELECT slutter FROM subscriptions WHERE id = :i', ['i' => (int) $aar['id']]) === null);
DB::kjor('DELETE FROM subscriptions WHERE id = :i', ['i' => (int) $aar['id']]);

// Bindingen ute: kan sies opp, og loper en maaned til.
$fri = $lagAvtale('Basis 30', gmdate('Y-m-d', strtotime('-1 day')));
sjekk('et medlemskap uten binding kan sies opp', Medlemskap::hvorforIkkeSiOpp($fri) === null);
Medlemskap::siOpp($fri);
$etter = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => (int) $fri['id']]);
$venta = (new DateTimeImmutable('now'))->modify('+1 months')->format('Y-m-d');
sjekk('oppsigelsen setter sluttdato én maaned fram',
    $etter['slutter'] === $venta, (string) $etter['slutter'] . ' mot ' . $venta);
sjekk('… og medlemskapet loper videre til da', $etter['status'] === 'aktiv');
sjekk('… og medlemmet har fortsatt tilgang',
    DB::verdi('SELECT status FROM members WHERE id = :i', ['i' => $bMedlem]) === 'aktiv');
sjekk('… og det kan ikke sies opp to ganger',
    Medlemskap::hvorforIkkeSiOpp($etter) !== null);
sjekk('… og det staar ikke til avslutning ennaa',
    !in_array((int) $fri['id'], array_map('intval', array_column(Medlemskap::tilAvslutning(), 'id')), true));

// Sluttdagen: da stoppes det.
DB::oppdater('subscriptions', ['slutter' => gmdate('Y-m-d')], ['id' => (int) $fri['id']]);
$forfalt = array_map('intval', array_column(Medlemskap::tilAvslutning(), 'id'));
sjekk('paa sluttdagen staar det til avslutning', in_array((int) $fri['id'], $forfalt, true));
Medlemskap::avslutt(DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => (int) $fri['id']]));
sjekk('… og da stoppes medlemskapet',
    DB::verdi('SELECT status FROM subscriptions WHERE id = :i', ['i' => (int) $fri['id']]) === 'stoppet');
sjekk('… og tilgangen tas bort',
    DB::verdi('SELECT status FROM members WHERE id = :i', ['i' => $bMedlem]) === 'oppsagt');

DB::kjor('DELETE FROM subscriptions WHERE member_id = :m', ['m' => $bMedlem]);
DB::kjor('DELETE FROM members WHERE id = :i', ['i' => $bMedlem]);

// ── Vippskrav og teksten som sto to ganger ─────────────────────────────
echo "\n== Vippskrav og «Passer for» ==\n";

// Push krever et nummer. Uten det skal det stoppe her, ikke hos Vipps.
$utenNr = false;
try {
    Vipps::opprettBetaling('TEST-' . bin2hex(random_bytes(3)), 10000, 'Test', 'https://x/', null, true);
} catch (RuntimeException $e) {
    $utenNr = str_contains($e->getMessage(), 'telefonnummer');
}
sjekk('et vippskrav uten telefonnummer avvises for det gaar til Vipps', $utenNr);

// Regelen som fjerner gjentakelsen. Den staar i nettsida; her kontrolleres
// at den finnes og at kortet bruker den — ellers staar «Passer for: deg som
// deg som er nysgjerrig» der igjen ved neste endring.
$sida = file_get_contents(dirname(__DIR__) . '/lissom-2108.html');
sjekk('nettsida har regelen som fjerner gjentatt «Passer for»',
    str_contains($sida, 'utenGjentakelse(tekst, ledd)'));
sjekk('medlemskapskortet bruker den',
    str_contains($sida, "this.utenGjentakelse(o.passerFor, ['passer for', 'for deg som', 'deg som'])"));
sjekk('kurskortet bruker den',
    str_contains($sida, "this.utenGjentakelse(k.passerFor, ['passer for'])"));
sjekk('planredigeringen viser hele setningen',
    str_contains($sida, 'plPasserForVis'));

// Samme adresse i to skrivemaater skal telle som én mottaker.
$forNokler = Varsel::adminEposter();
sjekk('adminvarsler gaar til minst én adresse', count($forNokler) > 0, implode(', ', $forNokler));
sjekk('ingen adresse staar to ganger i adminlista',
    count($forNokler) === count(array_unique(array_map('mb_strtolower', $forNokler))));

$betFil = file_get_contents(dirname(__DIR__) . '/api/admin/kursbetaling.php');

// ── Betaling registrert for haand ────────────────────────────────────────
//
// «Marker som betalt» satte en status og ikke noe mer: ingen sum, ingen hvem,
// ingen naar, og ingen vei tilbake om noen slo inn feil. Reglene ligger naa i
// Booking, og de kontrolleres her — en pengeregel som ikke er testet, er en
// paastand.
(static function () use (&$ok, &$feil): void {
    DB::kjor("DELETE FROM payments WHERE vipps_reference LIKE 'MANUELL-TEST%'");
    DB::kjor("DELETE FROM bookings WHERE gjest_navn = 'Betalingsprove'");
    DB::kjor("DELETE FROM course_sessions WHERE course_id IN (SELECT id FROM courses WHERE slug = 'testbetalt')");
    DB::kjor("DELETE FROM courses WHERE slug = 'testbetalt'");

    $kursId = DB::settInn('courses', [
        'slug' => 'testbetalt', 'tittel' => 'Testkurs betaling',
        'pris_ore' => 100000, 'kapasitet' => 8, 'status' => 'kladd',
    ]);
    $oktId = DB::settInn('course_sessions', [
        'course_id' => $kursId,
        'start_tid' => gmdate('Y-m-d H:i:s', time() + 86400),
    ]);
    $b = DB::settInn('bookings', [
        'course_id' => $kursId, 'course_session_id' => $oktId,
        'gjest_navn' => 'Betalingsprove', 'antall' => 1,
        'belop_ore' => 100000, 'status' => 'reservert',
    ]);

    $legg = static function (int $b, int $ore, string $maate) : int {
        return DB::settInn('payments', [
            'vipps_reference' => 'MANUELL-TEST-' . bin2hex(random_bytes(4)),
            'type' => 'manuell', 'formal' => 'booking', 'booking_id' => $b,
            'maate' => $maate, 'belop_ore' => $ore, 'status' => 'betalt',
            'idempotency_key' => Vipps::uuid(),
        ]);
    };

    sjekk('en paamelding uten betaling staar som ubetalt',
        Booking::betalingerFor($b)['sum'] === 0);

    // Et delbeloep gjor den ikke betalt. Det var her «marker som betalt»
    // loey: den sa betalt uansett hvor mye som faktisk hadde kommet.
    $p1 = $legg($b, 40000, 'Kontant');
    $e = Booking::settBetaltStatus($b);
    sjekk('et delbeloep gjor ikke paameldingen betalt',
        $e['status'] === 'reservert' && $e['skyldig'] === 60000,
        $e['status'] . ', skyldig ' . $e['skyldig']);

    $p2 = $legg($b, 60000, 'Faktura');
    $e = Booking::settBetaltStatus($b);
    sjekk('resten av beloepet gjoer den betalt', $e['status'] === 'betalt' && $e['skyldig'] === 0);
    sjekk('betalingsmaaten folger den siste betalingen',
        (string) DB::verdi('SELECT betalt_maate FROM bookings WHERE id = :i', ['i' => $b]) === 'Faktura');

    // Annullering: raden blir staaende, men teller ikke.
    DB::oppdater('payments', ['status' => 'avbrutt', 'annullert_at' => gmdate('Y-m-d H:i:s')], ['id' => $p2]);
    $e = Booking::settBetaltStatus($b);
    sjekk('en annullert betaling teller ikke i summen', $e['sum'] === 40000);
    sjekk('paameldingen blir ubetalt igjen naar betalingen annulleres', $e['status'] === 'reservert');
    sjekk('den annullerte raden blir staaende — den er et bilag',
        count(Booking::betalingerFor($b)['rader']) === 2);

    DB::oppdater('payments', ['status' => 'avbrutt', 'annullert_at' => gmdate('Y-m-d H:i:s')], ['id' => $p1]);
    $e = Booking::settBetaltStatus($b);
    sjekk('er alle betalingene annullert, staar ingen peker igjen',
        DB::verdi('SELECT payment_id FROM bookings WHERE id = :i', ['i' => $b]) === null);

    // En avbestilt paamelding skal ikke bli «reservert» igjen fordi noen
    // rorte en betaling. Hen kommer ikke.
    DB::oppdater('bookings', ['status' => 'avbestilt'], ['id' => $b]);
    $e = Booking::settBetaltStatus($b);
    sjekk('en avbestilt paamelding roeres ikke av betalingene', $e['status'] === 'avbestilt');

    // ── Den gamle pekeren teller ogsaa ──────────────────────────────────
    //
    // «bookings.payment_id» har pekt paa Vipps-betalingen siden dag én.
    // «payments.booking_id» kom med migrasjon 084, og backfillen tok bare de
    // radene som fantes da. Leser vi bare den nye, staar en betalt
    // Vipps-plass som ubetalt — det var noeyaktig det eieren saa: «BETALT» paa
    // kortet, «Betalt kr. 0,-» i ruta under.
    DB::oppdater('bookings', ['status' => 'reservert'], ['id' => $b]);
    $vipps = DB::settInn('payments', [
        'vipps_reference' => 'MANUELL-TEST-V-' . bin2hex(random_bytes(4)),
        'type' => 'epayment', 'formal' => 'booking',
        'belop_ore' => 100000, 'status' => 'betalt',
        'idempotency_key' => Vipps::uuid(),
    ]);
    DB::oppdater('bookings', ['payment_id' => $vipps], ['id' => $b]);
    sjekk('en Vipps-betaling uten den nye pekeren blir likevel funnet',
        Booking::betalingerFor($b)['sum'] === 100000);

    // Rydd opp etter oss.
    DB::kjor('DELETE FROM bookings WHERE id = :b', ['b' => $b]);
    DB::kjor('DELETE FROM payments WHERE booking_id = :b OR id = :p', ['b' => $b, 'p' => $vipps]);
    DB::kjor('DELETE FROM course_sessions WHERE id = :o', ['o' => $oktId]);
    DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kursId]);
})();

// ── Den som ble merket betalt for fase 1 ─────────────────────────────────
//
// «Marker som betalt» satte en status og ikke noe mer. De paameldingene staar
// fortsatt slik: betalt, uten en eneste rad i payments. Ruta maa si det samme
// som merket paa raden over — ellers staar det «BETALT» og «ingen betaling er
// registrert» ved siden av hverandre, og ingen vet hva som gjelder.
sjekk('en paamelding merket betalt for fase 1 leses som gjort opp',
    str_contains($betFil, "\$fraFor = \$bet['rader'] === [] && (string) \$b['status'] === 'betalt'")
    && str_contains($betFil, "'gjortOpp'  => \$skyldig === 0 || \$fraFor"));
sjekk('ruta sier hvordan pengene kom inn, ogsaa uten en rad i payments',
    str_contains($betFil, "'Merket betalt' . (\$maate !== ''"));
// Vi later ikke som vi vet mer enn vi gjor: raden finnes ikke, saa hvem som
// registrerte den og naar, kan ingen svare paa.
sjekk('ruta paastaar ikke aa vite hvem som registrerte den',
    str_contains($betFil, 'hvem som registrerte den, eller når'));

// Og nye Vipps-betalinger skal ikke havne i den samme baaten. Betalingsraden
// lages for bookingen — den maa ha en referanse for kunden sendes til Vipps —
// saa koblingen settes rett etter at bookingen finnes.
sjekk('en ny Vipps-betaling kobles til paameldingen med det samme',
    str_contains(file_get_contents(dirname(__DIR__) . '/app/lib/booking.php'),
        "DB::oppdater('payments', ['booking_id' => \$bookingId], ['id' => \$paymentId])"));

// Endepunktet skal ikke ha sine egne regler ved siden av bibliotekets.
sjekk('kursbetaling.php bruker reglene i Booking, ikke sine egne',
    str_contains($betFil, 'Booking::settBetaltStatus(') && str_contains($betFil, 'Booking::betalingerFor('));
sjekk('en manuell betaling kan ikke forveksles med en fra Vipps',
    str_contains($betFil, "'MANUELL-' . Vipps::nyReferanse"));

// ── Kursholder paa den enkelte datoen ────────────────────────────────────
//
// Registeret over kursholdere fantes, men var ikke koblet til noe: ingen fil
// utenom tabell-lista i api/status.php nevnte det. Naa hoerer kursholderen til
// datoen, og kurset har en standard som foreslaas.
(static function () use (&$ok, &$feil): void {
    if (!DB::harKolonne('course_sessions', 'kursholder_id')) {
        sjekk('kursholder per okt krever migrasjon 085', false, 'kolonnen mangler');
        return;
    }

    DB::kjor("DELETE FROM course_sessions WHERE course_id IN (SELECT id FROM courses WHERE slug = 'testholder')");
    DB::kjor("DELETE FROM courses WHERE slug = 'testholder'");
    DB::kjor("DELETE FROM kursholdere WHERE navn IN ('Testholder A', 'Testholder B')");

    $a = DB::settInn('kursholdere', ['navn' => 'Testholder A', 'rolle' => 'keramiker', 'aktiv' => 1]);
    $b = DB::settInn('kursholdere', ['navn' => 'Testholder B', 'rolle' => 'vikar', 'aktiv' => 1]);

    $kursId = DB::settInn('courses', [
        'slug' => 'testholder', 'tittel' => 'Testkurs kursholder',
        'pris_ore' => 50000, 'kapasitet' => 6, 'status' => 'kladd',
    ]);

    // Verkstedets standard: bare én om gangen. Settes en ny, tas den
    // forrige av — ellers ville to staatt som standard og forslaget blitt
    // tilfeldig.
    DB::kjor('UPDATE kursholdere SET standard = 0');
    DB::kjor('UPDATE kursholdere SET standard = 1 WHERE id = :i', ['i' => $a]);
    DB::iTransaksjon(static function () use ($b): void {
        DB::kjor('UPDATE kursholdere SET standard = 0 WHERE standard = 1');
        DB::kjor('UPDATE kursholdere SET standard = 1 WHERE id = :i', ['i' => $b]);
    });
    sjekk('bare én kursholder er standard om gangen',
        (int) DB::verdi('SELECT COUNT(*) FROM kursholdere WHERE standard = 1') === 1
        && (int) DB::verdi('SELECT id FROM kursholdere WHERE standard = 1') === $b);

    $okt1 = DB::settInn('course_sessions', [
        'course_id' => $kursId,
        'start_tid' => gmdate('Y-m-d H:i:s', time() + 86400),
        'kursholder_id' => $b,
    ]);

    // Kursholderen hoerer til datoen: den som holder kurset i september er
    // ikke noedvendigvis den samme som i oktober.
    DB::oppdater('course_sessions', ['kursholder_id' => $a], ['id' => $okt1]);
    sjekk('kursholderen settes paa den enkelte datoen',
        (int) DB::verdi('SELECT kursholder_id FROM course_sessions WHERE id = :i', ['i' => $okt1]) === $a);

    // Slutter noen, skal ikke datoene deres forsvinne. Okta blir staaende
    // uten kursholder — den gikk.
    DB::oppdater('course_sessions', ['kursholder_id' => $b], ['id' => $okt1]);
    DB::kjor('DELETE FROM kursholdere WHERE id = :i', ['i' => $b]);
    sjekk('sletter man en kursholder, staar datoen igjen uten kursholder',
        DB::en('SELECT id FROM course_sessions WHERE id = :i', ['i' => $okt1]) !== null
        && DB::verdi('SELECT kursholder_id FROM course_sessions WHERE id = :i', ['i' => $okt1]) === null);

    DB::kjor('DELETE FROM course_sessions WHERE course_id = :k', ['k' => $kursId]);
    DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kursId]);
    DB::kjor('DELETE FROM kursholdere WHERE id = :i', ['i' => $a]);
})();

// Endepunktet skal avvise en kursholder som ikke finnes — ellers ville datoen
// pekt paa noe som ikke er der, og navnet blitt borte uten forklaring.
$kursFil = file_get_contents(dirname(__DIR__) . '/api/admin/kurs.php');
sjekk('kurs.php slaar opp kursholderen for den lagres',
    str_contains($kursFil, "Svar::feil('Fant ikke kursholderen.')"));
sjekk('en ny dato foreslaar verkstedets standard',
    str_contains($kursFil, "SELECT id FROM kursholdere WHERE standard = 1 AND aktiv = 1"));
$khFil = file_get_contents(dirname(__DIR__) . '/api/admin/kursholdere.php');
sjekk('standarden byttes i én transaksjon, saa bare én staar igjen',
    str_contains($khFil, "UPDATE kursholdere SET standard = 0 WHERE standard = 1"));
sjekk('en som har sluttet kan ikke vaere standard',
    str_contains($khFil, "En som har sluttet kan ikke være standard."));

// ── Ventelista hoerer til datoen ─────────────────────────────────────────
//
// «waitlist.course_session_id» har vaert lagret siden ventelista kom, men
// ingen leste den: koen, posisjonen og dublettsjekken gikk alle paa kurset.
// Da sto den som ventet paa 9. september i samme ko som den som ventet paa
// 16., og «plass nummer 2» sa ingenting om hvilken kveld.
(static function () use (&$ok, &$feil): void {
    DB::kjor("DELETE FROM waitlist WHERE epost LIKE 'vl-%@example.com'");
    DB::kjor("DELETE FROM course_sessions WHERE course_id IN (SELECT id FROM courses WHERE slug = 'testvl')");
    DB::kjor("DELETE FROM courses WHERE slug = 'testvl'");

    $kursId = DB::settInn('courses', [
        'slug' => 'testvl', 'tittel' => 'Testkurs venteliste',
        'pris_ore' => 50000, 'kapasitet' => 2, 'status' => 'publisert',
    ]);
    $a = DB::settInn('course_sessions', ['course_id' => $kursId, 'start_tid' => gmdate('Y-m-d H:i:s', time() + 86400)]);
    $b = DB::settInn('course_sessions', ['course_id' => $kursId, 'start_tid' => gmdate('Y-m-d H:i:s', time() + 172800)]);

    // Samme regel som endepunktet: posisjonen telles i den koen raden hoerer
    // til. Her regnes den ut slik biblioteket ville gjort det.
    $neste = static function (int $kurs, ?int $okt): int {
        return 1 + (int) DB::verdi(
            "SELECT COUNT(*) FROM waitlist
              WHERE course_id = :k AND status IN ('venter','varslet')
                AND " . ($okt !== null ? 'course_session_id = :o' : 'course_session_id IS NULL'),
            ['k' => $kurs] + ($okt !== null ? ['o' => $okt] : [])
        );
    };

    $sett = static function (int $kurs, ?int $okt, string $navn) use ($neste): int {
        return DB::settInn('waitlist', [
            'course_id' => $kurs, 'course_session_id' => $okt,
            'navn' => $navn, 'epost' => 'vl-' . mb_strtolower($navn) . '@example.com',
            'posisjon' => $neste($kurs, $okt),
        ]);
    };

    $sett($kursId, $a, 'Ein');
    $sett($kursId, $a, 'Tvo');
    $tre = $sett($kursId, $b, 'Tre');

    sjekk('posisjonen telles per dato, ikke per kurs',
        (int) DB::verdi('SELECT posisjon FROM waitlist WHERE id = :i', ['i' => $tre]) === 1,
        'forste paa sin egen dato skal vaere nr. 1');

    // Den samme personen kan staa paa lista til to ulike kvelder — det er to
    // ulike koer.
    $fire = $sett($kursId, $b, 'Ein');
    sjekk('samme person kan staa paa lista til to ulike datoer',
        (int) DB::verdi('SELECT posisjon FROM waitlist WHERE id = :i', ['i' => $fire]) === 2);

    // Rader fra for datoen ble lagret gjelder hele kurset, og skal ikke
    // blandes inn i en dato-ko.
    $gammel = $sett($kursId, null, 'Gammel');
    sjekk('en rad uten dato teller for seg',
        (int) DB::verdi('SELECT posisjon FROM waitlist WHERE id = :i', ['i' => $gammel]) === 1);

    DB::kjor('DELETE FROM waitlist WHERE course_id = :k', ['k' => $kursId]);
    DB::kjor('DELETE FROM course_sessions WHERE course_id = :k', ['k' => $kursId]);
    DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kursId]);
})();

$vlFil  = file_get_contents(dirname(__DIR__) . '/api/venteliste.php');
$vlAdm  = file_get_contents(dirname(__DIR__) . '/api/admin/venteliste.php');
sjekk('ventelista lagrer bare en dato som hoerer til kurset',
    str_contains($vlFil, 'SELECT id FROM course_sessions WHERE id = :o AND course_id = :k'));
sjekk('dublettsjekken gaar paa datoen naar den er kjent',
    str_contains($vlFil, "course_session_id = :o' : 'course_session_id IS NULL"));
sjekk('adminlista staar i den rekkefolgen kveldene kommer',
    str_contains($vlAdm, 'ORDER BY cs.start_tid IS NULL, cs.start_tid'));
sjekk('kvelden hen venter paa foreslaas foerst naar plassen gis',
    str_contains($vlAdm, "'ventet' => \$venterPaa !== null"));

// ── Deltakerruta, ogsaa for gjester ──────────────────────────────────────
//
// Ruta ble bygget av medlemsregisteret, og bare av det. En kursdeltaker uten
// konto — de fleste av dem — hadde ingen rute aa aapne: navnet i lista var
// dodt, og verkstedet kom ikke inn til historikken, kursbeviset eller
// notatet. Naa kan den ogsaa aapnes av en paamelding.
$medFil = file_get_contents(dirname(__DIR__) . '/api/admin/medlemmer.php');
sjekk('personruta kan aapnes av en paamelding, ikke bare av en konto',
    str_contains($medFil, "Foresporsel::heltall('booking') > 0"));
sjekk('en paamelding med konto aapner kontoen, ikke en gjest',
    str_contains($medFil, "if (\$b['member_id'] !== null)"));
sjekk('en gjest uten e-post og telefon faar likevel sin egen paamelding',
    str_contains($medFil, "\$gjester = ' OR b.id = :bid'"));
sjekk('ruta sier fra naar personen ikke har konto',
    str_contains($medFil, "'gjest'      => \$erGjest"));
sjekk('betalingene hentes paa paameldingene',
    str_contains($medFil, 'WHERE b.id IN ({$inn})'));
// Begge pekerne mellom booking og betaling. «payments.booking_id» kom med
// migrasjon 084; «bookings.payment_id» har pekt paa Vipps-betalingen siden
// dag én. Leses bare den nye, mangler Vipps-betalingene — og en betalt plass
// staar som ubetalt.
sjekk('personruta finner ogsaa Vipps-betalinger uten den nye pekeren',
    str_contains($medFil, 'JOIN bookings b ON (b.id = p.booking_id OR b.payment_id = p.id)'));
sjekk('ventelistene hentes paa e-post og telefon',
    str_contains($medFil, "w.status IN ('venter','varslet')"));
sjekk('endringsloggen leses ut av audit_log',
    str_contains($medFil, 'FROM audit_log a'));

// Notatet ligger paa medlemsraden. En gjest har ingen, og da skal skjermen
// ikke prove aa lagre paa en konto som ikke finnes.
$sida = file_get_contents(dirname(__DIR__) . '/lissom-2108.html');
sjekk('notatet lagres bare naar personen faktisk har en konto',
    str_contains($sida, "const id = this.state.personMedlemId;"));
sjekk('deltakerraden aapner personen ogsaa uten konto',
    str_contains($sida, 'this.apnePerson(p.medlemId, p.bookingId)'));

// ── Kalenderen ──────────────────────────────────────────────────────────
//
// Fase 5: kalenderen fra designverktoyet, paa ekte data. Der kjorte den paa
// en oppdiktet ukeplan med Kari Nordmann og Silje Bratt; her kommer
// hendelsene fra basen. Endepunktet er et LESEENDEPUNKT — alt som skal
// endres gaar til kurs.php, pamelding.php og venteliste.php, saa reglene
// deres gjelder ogsaa naar kalenderen kobles i fase 6.
$kalFil = file_get_contents(dirname(__DIR__) . '/api/admin/kalender.php');
sjekk('kalenderen er et leseendepunkt', str_contains($kalFil, "Foresporsel::krevMetode('GET')"));
sjekk('kalenderen krever admin', str_contains($kalFil, 'krev_admin()'));
sjekk('deltakerne hentes i ett kall, ikke ett per okt',
    str_contains($kalFil, 'WHERE b.course_session_id IN ({$inn})'));
sjekk('avbestilte staar ikke i kalenderen',
    str_contains($kalFil, "AND b.status <> 'avbestilt'"));
sjekk('navnet kan klikkes ogsaa for dem uten konto',
    str_contains($kalFil, "'gjest'     => \$b['member_id'] === null"));
sjekk('stengte dager leses av apningstider, ikke av en ny tabell',
    str_contains($kalFil, 'FROM apningstider'));
sjekk('innsjekk leses av check_ins, ikke av den tomme checkins',
    str_contains($kalFil, 'FROM check_ins') && !str_contains($kalFil, 'FROM checkins'));

$sida = file_get_contents(dirname(__DIR__) . '/lissom-2108.html');
sjekk('kalenderen henter fra basen, ikke fra en generator',
    str_contains($sida, "fetch('/api/admin/kalender.php?fra=") && !str_contains($sida, 'klGen(y, m) {'));
sjekk('fase 5 skriver ikke — laget med lokale endringer tegnes ikke',
    str_contains($sida, 'if (!this.klSkriver) return evts;'));
// Fase 6: alle tolv er koblet, og hjelperen som sa «ikke koblet ennaa» er
// borte. Staar den igjen, er det fordi noe fortsatt ikke virker.
sjekk('ingen knapp i kalenderen sier lenger «ikke koblet ennaa»',
    !str_contains($sida, 'klIkkeEnda'));
// Kalenderen skriver aldri selv. Den sender til endepunktene som finnes, saa
// det er ett sted som vet hva som skjer naar en dato med fem paameldte
// flyttes — og henter maaneden paa nytt etterpaa.
foreach ([
    'stenger dagen'        => "handling: 'aapne', dato: dagIso",
    'svarer en henvendelse'=> "handling: 'svar', id: bv.id",
    'gir en plass'         => "handling: 'gi-plass', id: vb.id",
    'setter opp et kurs'   => "handling: 'nydato', kursId: kursId",
    'legger til en dato'   => "handling: 'nydato', kursId: redEvt.kursId",
    'flytter en dato'      => "handling: 'endredato', oktId: valgt.oktId",
    'avlyser'              => "handling: 'avlys', oktId: valgt.oktId",
    'gjenoppretter'        => "handling: 'gjenopprett', oktId: valgt.oktId",
    'legger til deltaker'  => "handling: 'legg-til', oktId: valgt.oktId",
] as $hva => $bit) {
    sjekk('kalenderen ' . $hva . ' gjennom endepunktet som finnes',
        str_contains($sida, $bit));
}
// Stemplingen sto i en egen «klInne» som ingenting satte: knappen viste
// «Stemple inn» ogsaa naar man var innstemplet.
// «Lagre» i redigeringsruta sender bare det som er endret. Prisen i feltet er
// kursets, ikke datoens: sendes den hver gang, laases datoen til dagens pris,
// og en senere prisendring paa kurset gjelder ikke den. Ingen ba om det.
sjekk('redigeringsruta lagrer bare det som er endret',
    str_contains($sida, 'klRAapnet: {')
    && str_contains($sida, "const fra0 = this.state.klRAapnet || {};")
    && str_contains($sida, "if ((this.state.klRPris || '') !== (fra0.pris || '')) {"));
// Stengte dager kom fra basen, men ble aldri tegnet: kalenderen leste et
// lokalt lag ingenting fylte. En stengt dag saa aapen ut, og knappen sa
// «Steng dagen» paa en dag som alt var stengt.
sjekk('stengte dager leses av det serveren sier',
    str_contains($sida, 'klStengte() { return this.state.kalStengte || {}; }')
    && !str_contains($sida, 'this.state.klStengt'));
// «klMin» ble borte da kalenderen ble hentet inn, mens koden som bruker den
// ble med: dra-og-slipp av et kurs stoppet paa en metode som ikke fantes.
sjekk('klMin finnes, saa dra-og-slipp av et kurs ikke stopper',
    str_contains($sida, 'klMin(t) {'));

sjekk('stemplingen i kalenderen leser den samme kilden som resten',
    str_contains($sida, "klStempleTekst: this.erInne() ? 'Stemple ut' : 'Stemple inn',")
    && !str_contains($sida, 'klInneTid'));
// En avlyst dato kunne ikke settes tilbake. Da matte den settes opp paa nytt,
// og de paameldte fulgte ikke med.
$kursFil6 = file_get_contents(dirname(__DIR__) . '/api/admin/kurs.php');
$apnFil6  = file_get_contents(dirname(__DIR__) . '/api/admin/apningstider.php');
sjekk('en avlyst dato kan gjenopprettes',
    str_contains($kursFil6, "case 'gjenopprett':")
    && str_contains($kursFil6, "DB::oppdater('course_sessions', ['status' => 'planlagt'], ['id' => \$oktId]);"));
// Raden i «apningstider» er overstyringen. Den kunne bare lages i basen.
sjekk('en dag kan stenges og aapnes fra kalenderen',
    str_contains($apnFil6, "case 'steng':") && str_contains($apnFil6, "case 'aapne':"));
// «Aapne» sletter raden. En rad med stengt = 0 og uten tider ville fortsatt
// vaert en overstyring — «aapent, men vi vet ikke naar».
sjekk('aapning fjerner overstyringen framfor aa sette stengt = 0',
    str_contains($apnFil6, 'DELETE FROM apningstider WHERE id = :i'));
// Kursene avlyses ikke av at dagen stenges — men eieren skal se at de staar
// der, saa hun kan ta stilling til dem.
sjekk('stenging sier fra om kursene som gaar den dagen',
    str_contains($apnFil6, "'kurs'    => \$kurs,"));
sjekk('beskjedene i kalenderen er ekte henvendelser, ikke oppdiktede',
    str_contains($sida, "klBeskjeder: (this.state.adminForesporsler || [])"));
// ── Kurskalenderen paa nettsiden, paa telefon ────────────────────────────
//
// De sju dagskortene laa i en stripe man maatte dra sidelengs: tre fikk
// plass, fire laa utenfor kanten. Ingenting sa at den kunne dras, og eieren
// meldte at dagene 3.–6. september manglet i uke 36 — de var der hele tiden.
sjekk('hele uka staar i ett bilde paa telefon',
    str_contains($sida, 'ukeStripStil:')
    && str_contains($sida, "gridTemplateColumns: 'repeat(7, 1fr)', gap: '4px'"));
sjekk('dagene under stripa staar i full bredde, ikke sidelengs',
    str_contains($sida, 'class="lx-ukeliste"')
    && str_contains($sida, '.lx-ukeliste { grid-template-columns: 1fr !important; }'));
// Trykk paa en dag i stripa gaar ned til dagen. Har dagen ingenting, finnes
// det ingen rute aa hoppe til — da skal trykket ikke sende deg til toppen.
sjekk('trykk paa en dag i stripa gaar til dagen',
    str_contains($sida, "document.getElementById('ukedag-' + dagIdx)"));
sjekk('en tom dag i stripa gjor ingenting',
    str_contains($sida, "if (!(d.poster || []).length) return;"));

// ── Fase 6: ingen oppdiktede data, og ingen dubletter ────────────────────
//
// Kalenderen henter tre maaneder — den vi tegner, og naboene — og hvert svar
// er utvidet med en uke i hver ende. Da kommer samme okt i to av svarene, og
// for laa de begge i lista: 138 hendelser der 86 var unike, og bollekurset
// 3. september sto to ganger paa skjermen.
sjekk('kalenderen teller hver okt én gang',
    str_contains($sida, 'const alleredeMed = {};')
    && str_contains($sida, 'if (alleredeMed[id]) return;'));

// Deltakerruta regnet e-post og telefon ut av navnet: «kari.nordmann@epost.no»
// og et nummer laget av lengden paa navnet. Det saa ekte ut, og var det ikke.
sjekk('deltakerruta dikter ikke opp e-post og telefon',
    !str_contains($sida, "'@epost.no' : ''")
    && !str_contains($sida, "tlf: '+47 9' + String("));
sjekk('kontaktopplysningene kommer fra paameldingen',
    str_contains($kalFil, "COALESCE(m.epost, b.gjest_epost) AS epost")
    && str_contains($kalFil, "COALESCE(m.telefon, b.gjest_telefon) AS telefon"));
// Og «beskjeden er sendt» sto der uten at noe forlot nettleseren.
sjekk('beskjed til en deltaker sendes faktisk',
    str_contains($sida, "this.klKall('/api/admin/beskjed.php', {\n                  til: 'en'"));
sjekk('beskjed til en kursholder sendes faktisk',
    str_contains($sida, "til: 'en', navn: hNavn, epost: ep, telefon: tlf"));
// Kalenderen har ingen egne skriveregler: den sender til endepunktene som
// alt finnes, og henter maaneden paa nytt etterpaa.
sjekk('kalenderen skriver gjennom endepunktene som finnes',
    str_contains($sida, 'klKall(sti, kropp) {') && str_contains($sida, 'klFrisk() {'));

// Listevisningen sto som firkantede striper med en hairline mellom, mens
// resten av kalenderen — brikkene i dag og uke, kortene i kurslista — har
// runde hjorner. Eieren ba om samme stil hele veien.
sjekk('hver okt i listevisningen er sitt eget kort med runde hjorner',
    str_contains($sida, 'border-radius: var(--radius-md); cursor: pointer; width: 100%; box-sizing: border-box; text-align: left; display: flex; align-items: center; gap: var(--space-5); padding: var(--space-4) var(--space-5);'));

// ── Kalenderskjermen paa telefon ─────────────────────────────────────────
//
// Skjermen kom fra designfila og hadde sin egen sidemeny: feil sti til logoen,
// ingen «lx-adminaside», og ingen mobilmeny. Paa telefon sto logoen tom, hele
// menyen laa utslaatt over sida, og kalenderen ble dyttet ned under den.
sjekk('kalenderen bruker den samme logoen som resten av admin',
    !str_contains($sida, 'src="assets/logo-lockup-yellow.svg"'));
// Én aside per adminskjerm, og alle skal ha klassen som gjor menyen om til en
// topplinje paa smal skjerm.
sjekk('hver adminskjerm har klassen som gjor menyen mobilvennlig',
    substr_count($sida, '<aside style="{{ adminAsideStil }}">') === 0);
sjekk('kalenderen har sin egen adresse, saa den taaler en omlasting',
    str_contains($sida, "{ sti: '/admin/kalender',     side: 'adminkalender' },"));
// Manedsrutenettet er sju spalter. Paa en telefon falt sondagen utenfor
// kanten med «overflow: hidden» — nu ruller rammen sidelengs i stedet.
sjekk('de brede visningene ruller sidelengs paa telefon',
    // Maaned, uke og dag. Alle tre er flere spalter enn en telefon er bred.
    substr_count($sida, 'class="lx-kalbred"') === 3
    && str_contains($sida, '.lx-kalbred { overflow-x: auto !important;'));
// Og velger hun Maned eller Dag paa telefon, skal hun faa det. Lista er
// standardvisningen der, ikke den eneste.
sjekk('visningsknappene virker ogsaa paa telefon',
    str_contains($sida, "this.state.klVisning || (this.erSmal() ? 'liste' : 'dag')"));
sjekk('kalenderen staar oeverst paa telefon, foran kortene og sidespaltene',
    str_contains($sida, "? { minWidth: 0, order: 1 }"));

// ── Spaltene i dagsvisningen ─────────────────────────────────────────────
//
// Alle de aktive kursholderne sto som spalter saa snart ingen av dem hadde
// noe, saa en dag uten kurs ble tre tomme spalter. Eieren spurte hvorfor hun
// saa seg selv — hun holder sjelden kurs, og «Monica er default».
sjekk('den som vanligvis holder kursene staar som spalte',
    str_contains($sida, 'klStandardHolder()')
    && str_contains($sida, "const staarFast = kn => kn === 'Verkstedet' || kn === stdHolder;"));
// Navnet skal ikke staa i koden. Settes en annen som standard, eller slutter
// hen, foelger kalenderen med av seg selv.
sjekk('standarden leses av registeret, ikke av et navn i koden',
    !str_contains($sida, "stdHolder = 'Monica'"));
sjekk('de andre kursholderne kan slaas paa og av',
    str_contains($sida, 'klHolderSpalter:') && str_contains($sida, 'klVisHolder'));
// En kursholder med en okt den dagen kan ikke skjules. Da ville okta hennes
// forsvunnet fra skjermen, og en kalender som gjemmer noe er verre enn ingen.
sjekk('en kursholder med en okt kan ikke skjules bort',
    str_contains($sida, 'har noe denne dagen og kan ikke skjules'));
$kalFil2 = file_get_contents(dirname(__DIR__) . '/api/admin/kalender.php');
sjekk('kalenderen faar vite hvem som er standard kursholder',
    str_contains($kalFil2, "'standard' => isset(\$h['standard'])"));
// Kolonnen kom med migrasjon 086. Uten den skal endepunktet svare, ikke doe.
sjekk('kalenderen taaler at migrasjon 086 ikke er kjort',
    str_contains($kalFil2, "DB::harKolonne('kursholdere', 'standard')"));

sjekk('kursholderne i kalenderen kommer fra registeret',
    str_contains($sida, 'klHoldere()')
    // Navnene sto i tre lister og to standardverdier. Kommentaren som
    // forklarer hvorfor de er borte, naevner dem — den skal ikke telle.
    && !str_contains($sida, "['Monica', 'Joakim', 'Ekstern'].map(")
    && !str_contains($sida, "holder = 'Monica';"));

echo "\n";
echo str_repeat('─', 46), "\n";
echo $ok, " av ", $ok + count($feil), " sjekker gikk gjennom\n";
if ($feil) { echo "\nFEIL:\n - ", implode("\n - ", $feil), "\n"; exit(1); }
