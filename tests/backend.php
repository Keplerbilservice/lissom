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
// Navnet kom i migrasjon 087, adressen fulgte etter i 092. Testen slo opp
// paa den gamle adressen og doede den dagen 092 ble kjort — den skal folge
// kurset, ikke adressen kurset tilfeldigvis hadde. Begge godtas, saa den
// virker enten migrasjonen er kjort eller ikke.
$boller = DB::en("SELECT tema, slug, tittel FROM courses
                   WHERE slug IN ('lag-din-egen-bolle', 'kurs-boller')
                     AND status = 'publisert' LIMIT 1");
sjekk('bollekurset finnes og er publisert', $boller !== null);
sjekk('bollekurset har tema Plateteknikk',
    $boller !== null && $boller['tema'] === 'Plateteknikk', (string) ($boller['tema'] ?? ''));
sjekk('bollekurset heter «Lag din egen bolle»',
    $boller !== null && $boller['tittel'] === 'Lag din egen bolle', (string) ($boller['tittel'] ?? ''));
// Den gamle adressen er delt i e-poster og indeksert av Google. Er den
// flyttet, maa .htaccess sende den videre — ellers er lenkene doede.
if ($boller !== null && $boller['slug'] === 'lag-din-egen-bolle') {
    sjekk('den gamle bolleadressen sendes videre',
        str_contains(file_get_contents(dirname(__DIR__) . '/.htaccess'),
                     'kurs/kurs-boller'));
}
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
// Regelen ble endret 29. august paa bestilling: sluttdatoen er den siste
// dagen i maaneden oppsigelsen kommer, pluss oppsigelsestida. Foer laa den én
// maaned fram fra dagen i dag, saa to som sa opp samme maaned fikk hver sin
// dato — og trekket gikk et halvt intervall inn i en maaned ingen hadde bedt
// om.
$venta = (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))
    ->modify('first day of this month')->modify('+2 months')
    ->modify('-1 day')->format('Y-m-d');
sjekk('oppsigelsen gjelder ut maaneden etter, siste dag',
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
    && str_contains($sida, "const st = this.state, fra0 = st.klRAapnet || {}, ut = [];")
    && str_contains($sida, "if ((st.klRPris || '') !== (fra0.pris || '')) {"));
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

$kursFil6 = file_get_contents(dirname(__DIR__) . '/api/admin/kurs.php');

// ── Resten av fase 6 ─────────────────────────────────────────────────────
//
// Kursholderkonflikten. Registeret var lenge ikke koblet til noe, og da kunne
// ingen dobbeltbookes fordi ingen var bookede. Naa hoerer kursholderen til
// datoen, og den samme personen kunne settes paa to kurs som gaar samtidig.
// Det oppdages foerst den kvelden begge skal gaa.
sjekk('kursholderen kan ikke staa paa to kurs samtidig',
    str_contains($kursFil6, '$holderOpptatt = static function')
    && str_contains($kursFil6, 'AND cs.start_tid < :slutt')
    && str_contains($kursFil6, 'AND COALESCE(cs.slutt_tid, cs.start_tid + INTERVAL 1 HOUR) > :start'));
// Alle tre veiene en kursholder kan bli opptatt paa.
sjekk('konflikten sjekkes paa ny dato, bytte av kursholder og flytting',
    substr_count($kursFil6, '$krevLedigHolder(') === 3);
// Avlyste okter gaar ikke, og skal ikke sperre noe.
sjekk('en avlyst okt sperrer ikke kursholderen',
    str_contains($kursFil6, "AND cs.status <> 'avlyst'\n            AND cs.start_tid < :slutt"));

// De sju daglige oppgavene, fra kalenderen.
foreach ([
    'moette ikke opp'         => "handling: 'status', id: dv.bookingId, status: 'ikke_mott'",
    'avbestiller en plass'    => "handling: 'fjern', id: dv.bookingId",
    'flytter én deltaker'     => "handling: 'flytt', id: dv.bookingId, oktId: til",
    'sperrer kursbeviset'     => "handling: 'bevis', id: dv.bookingId, sperret: 'ja'",
    'sender til alle paa okta'=> "til: 'okt', oktId: valgt.oktId",
    'melder keramikken klar'  => "handling: 'meld-alle', oktId: valgt.oktId",
    'foerer kursholdertimer'  => "handling: 'timer', id: hId, dato: valgt.dato",
] as $hva => $bit) {
    sjekk('kalenderen ' . $hva, str_contains($sida, $bit));
}
// «Ikke moett» fantes bare som en verdi i pamelding.php. Ingen knapp noe sted
// brukte den — statusen kunne ikke settes.
sjekk('«ikke moett» kan endelig settes fra en skjerm',
    str_contains($sida, 'klDIkkeMott: () => {'));

// Dra-og-slipp. Konflikten sjekkes der endringen skjer, ikke i nettleseren:
// en sjekk i skjermen ville sett paa det skjermen tilfeldigvis hadde hentet.
sjekk('en okt kan dras til et nytt tidspunkt',
    str_contains($sida, 'klDragStart(evt, e) {') && str_contains($sida, 'klSlippMaal(mv, lengde)'));
// Maanedsrutenettet har ingen tidsakse. Da flyttes dagen, og klokkeslettet
// staar — det er det eneste svaret som ikke er en gjetning.
sjekk('draing i maanedsrutenettet beholder klokkeslettet',
    str_contains($sida, 'const nyFra = mal.fra || evt.tid;'));
// Angre er en ekte flytting tilbake, ikke et lag over skjermen.
sjekk('angre flytter faktisk tilbake',
    str_contains($sida, 'klTilbyAngre(tekst, tilbake) {')
    && str_contains($sida, 'this.klKall(a.tilbake.sti, a.tilbake.kropp);'));
// Samme regel begge veier: er sluttida lik starten, sendes den ikke — ellers
// avviser serveren angringen, og knappen ser ut som den virket.
sjekk('angringen bruker samme regel for sluttida som flyttingen',
    str_contains($sida, "slutt: lengde ? evt.dato + ' ' + evt.slutt : '' },"));
// Aa gi bort en stol ved et uhell er ikke noe man oppdager og retter: den
// neste i koen har alt faatt e-posten. Slippet aapner bekreftelsen.
sjekk('draing fra ventelista aapner bekreftelsen framfor aa gi plassen',
    str_contains($sida, 'this.setState({ klVlBekreft: { id: p.id, navn: p.navn, malId: malId } });'));

// ── Ledige tider er ikke avtaler ─────────────────────────────────────────
//
// Paint on Pots og drop-in legges ut automatisk paa hver eneste aapningstid
// (migrasjon 076). Det er tilbud — «her kan noen komme» — ikke noe som skjer.
// Kalenderabonnementet tok med hver av dem, og telefonen til eieren fylte seg
// med tomme oppforinger: 25 Paint on Pots og 17 drop-in i basen her, ingen med
// paameldte. Da druknet de ekte kursene.
$icsFil = file_get_contents(dirname(__DIR__) . '/api/kalender-abonnement.php');
sjekk('tomme aapningstider staar ikke i kalenderabonnementet',
    str_contains($icsFil, 'cs.fra_apningstid = 0')
    && str_contains($icsFil, 'WHERE b2.course_session_id = cs.id'));
// Er noen paameldt, er oekta en avtale og skal staa der.
sjekk('en booket aapningstid kommer med likevel',
    str_contains($icsFil, "AND b2.status = 'betalt') > 0)"));
// Vanlige kurs staar uansett: en kursdato som ligger ute er noe verkstedet
// skal vaere klar til, ogsaa foer den foerste melder seg paa.
sjekk('vanlige kursdatoer staar ogsaa naar de er tomme',
    str_contains($icsFil, '$utenLedige = DB::harKolonne(\'course_sessions\', \'fra_apningstid\')'));

// ── Den gamle bolleadressen sendes videre ────────────────────────────────
//
// «kurs-boller» er adressen Google har indeksert og den som er delt. Uten et
// 301 ville den vaert en blindvei: 200, riktig tittel i toppen, og «siden
// finnes ikke» under — samme fella som /kurs/lag-din-egen-bolle sto i for
// migrasjon 091 og 092.
$ht = file_get_contents(dirname(__DIR__) . '/.htaccess');
sjekk('den gamle bolleadressen gaar videre med 301',
    str_contains($ht, 'RewriteRule ^kurs/kurs-boller/?$ /kurs/lag-din-egen-bolle [R=301,L]'));
// Regelen maa staa over den som sender alt annet til side.php, ellers ville
// «/kurs/kurs-boller» blitt servert som en vanlig side.
sjekk('omdirigeringen staar for oppsamlingsregelen',
    strpos($ht, 'RewriteRule ^kurs/kurs-boller/?$')
    < strpos($ht, 'RewriteRule ^ /side.php [L]'));

// ── Adressen foelger navnet ──────────────────────────────────────────────
//
// Kurset het «Kurs boller» og fikk navnet «Lag din egen bolle» i 087.
// Adressen sto igjen som «kurs-boller» med vilje den gangen — gamle lenker
// skulle virke. Eieren 29. august: adressen skal foelge navnet.
//
// «courses.slug» er unik, og kladden 091 satte til side holder fortsatt paa
// «lag-din-egen-bolle». Settes den nye adressen for den gamle raden har
// sluppet den, feiler hele migrasjonen paa den unike noekkelen.
$m92 = file_get_contents(dirname(__DIR__) . '/db/migrations/092_bolleadressen.sql');
sjekk('kladden slipper adressen for det publiserte kurset tar den',
    strpos($m92, "SET slug = ''lag-din-egen-bolle-gammel''")
    < strpos($m92, "SET slug = ''lag-din-egen-bolle'' WHERE id = @kurs"));
// Er adressen opptatt av noe annet, skal migrasjonen la vaere.
sjekk('adressen flyttes bare naar den er ledig',
    str_contains($m92, 'IF(@kurs IS NOT NULL AND @opptatt = 0,'));
sjekk('slug er unik, saa to kurs ikke kan dele adresse',
    (int) DB::verdi(
        "SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'courses'
            AND index_name = 'uq_courses_slug' AND non_unique = 0") > 0);

// ── Webp-tvillingen til originalen ───────────────────────────────────────
//
// .htaccess serverer «bilde.jpg.webp» til nettlesere som ber om webp, paa
// den samme .jpg-adressen. Skriptet laget den tvillingen bare for de
// nedskalerte utgavene; originalene hadde stort sett fatt sin en gang for
// haand, men to sto igjen som ren jpeg — og da hadde .htaccess ingenting aa
// servere. Verre enn de kilobytene: det neste bildet noen laster opp ville
// havnet i samme hull.
$bildFil = file_get_contents(dirname(__DIR__) . '/bin/bilder.php');
sjekk('bildeskriptet lager webp ogsaa for originalen',
    str_contains($bildFil, "\$tvilling = \$sti . '.webp';")
    && str_contains($bildFil, 'imagewebp($im, $tvilling, 78);'));
// «delingsbilde» skal vaere ren jpeg: Facebook, LinkedIn og WhatsApp henter
// den, og flere av dem viser ingenting om de faar webp paa .jpg-adressen.
sjekk('delingsbildet holdes utenfor', str_contains($bildFil, "'delingsbilde'];"));
// Alle fotografiene har en tvilling naa, bortsett fra det ene.
sjekk('bare delingsbildet mangler en webp-tvilling', (static function (): bool {
    foreach (glob(dirname(__DIR__) . '/*.jpg') ?: [] as $sti) {
        if (str_starts_with(basename($sti), 'delingsbilde')) {
            continue;
        }
        if (!is_file($sti . '.webp')) {
            return false;
        }
    }
    return true;
})());

// ── En ledig tid gjor ingen opptatt ──────────────────────────────────────
//
// Eieren: «jeg vil at Paint on Pots skal vaere mulig aa booke naar det er
// kurs, ikke vises som opptatt».
//
// Konfliktsjekken fra fase 6 talte hver eneste oekt kursholderen sto paa —
// ogsaa de Paint on Pots- og drop-in-tidene som legges ut automatisk paa hver
// aapningstid. Setter noen en kursholder paa dem, ville verkstedet ikke
// kunnet legge et kurs paa sine egne aapne kvelder. Det er nettopp da de skal
// settes opp: doeren er aapen og noen er der.
$kursFil2 = file_get_contents(dirname(__DIR__) . '/api/admin/kurs.php');
sjekk('en tom aapningstid gjor ikke kursholderen opptatt',
    str_contains($kursFil2, '$ledigTid = DB::harKolonne(\'course_sessions\', \'fra_apningstid\')')
    && str_contains($kursFil2, 'AND (cs.fra_apningstid = 0'));
// Har noen booket, er den en avtale med et menneske, og to ting samtidig er
// en ekte kollisjon.
sjekk('en booket aapningstid teller likevel som opptatt',
    str_contains($kursFil2, "AND b.status IN ('betalt', 'reservert')) > 0)"));
// Og en ekte kollisjon mellom to kurs skal fortsatt stoppes.
sjekk('to kurs paa samme kursholder og tid stoppes fortsatt',
    str_contains($kursFil2, 'staar allerede paa') || str_contains($kursFil2, 'står allerede på'));

// ── To kurs med samme navn ───────────────────────────────────────────────
//
// Paa den ekte siden laa «Lag din egen bolle» to ganger, begge med en oekt
// 3. september klokka 17. Kalenderen ute viste den derfor dobbelt — det var
// dette eieren meldte fra om, og som jeg foerst trodde var tellefeilen i
// admin-kalenderen. Tellefeilen fantes og er rettet; dette var noe annet.
//
// Verre: kortet slo kurset opp paa TITTEL i katalogen og tok foerste treff.
// Begge fikk da det foerste kursets adresse, og det andre ble uaapnelig —
// lenka laa i sitemap, svarte 200 med riktig tittel, og viste «siden finnes
// ikke». Har kortet et katalognummer, er det det som gjelder naa.
sjekk('kurskortet finner kurset paa nummer, ikke paa navn',
    str_contains($sida, 'const kat = (k.katId ? katalog.find(x => x.id === k.katId) : null)')
    && str_contains($sida, '|| katalog.find(x => x.tittel === tittel);'));
// Dubletten selv ryddes av migrasjon 091 — men bare naar den er tom.
$m91 = file_get_contents(dirname(__DIR__) . '/db/migrations/091_ett_bollekurs.sql');
sjekk('migrasjon 091 tar bare ned et kurs uten paameldte og venteliste',
    str_contains($m91, "WHERE b.course_id = @id AND b.status <> 'avbestilt'")
    && str_contains($m91, 'SELECT COUNT(*) FROM waitlist w WHERE w.course_id = @id')
    && str_contains($m91, 'IF(@id IS NOT NULL AND @henger = 0,'));
// Kurset slettes ikke, og ingen oekt avlyses: «kladd» tar det av nettsiden og
// ut av sitemap, og ett trykk setter det tilbake.
sjekk('kurset settes til kladd, det slettes ikke',
    str_contains($m91, "UPDATE courses SET status = ''kladd'' WHERE id = @id")
    && !str_contains($m91, 'DELETE FROM courses'));
// Sitemap tar bare med publiserte kurs, saa et kladd forsvinner derfra av seg
// selv.
sjekk('sitemap tar bare med publiserte kurs',
    str_contains(file_get_contents(dirname(__DIR__) . '/api/sitemap.php'),
                 "WHERE c.status = 'publisert'"));

// ── Oppsigelse: én regel, uansett hvem som sier opp ──────────────────────
//
// Eieren, 29. august: «settes til den siste dagen i maaneden man sier opp,
// pluss oppsigelsestiden». Den forrige regelen la maanedene rett paa dagen i
// dag, saa to som sa opp samme maaned fikk hver sin sluttdato.
$medlFil = file_get_contents(dirname(__DIR__) . '/app/lib/medlemskap.php');
sjekk('sluttdatoen er siste dag i maaneden pluss oppsigelsestida',
    str_contains($medlFil, "->modify('first day of this month')")
    && str_contains($medlFil, "->modify('+' . (\$mnd + 1) . ' months')")
    && str_contains($medlFil, "->modify('-1 day')"));
// Regnestykket gaar via den foerste i maaneden: «siste dag i denne maaneden»
// pluss én maaned gir 30. oktober naar man starter paa 30. september.
sjekk('regelen treffer siste dag ogsaa i februar', (static function (): bool {
    foreach ([['2026-09-14', 1, '2026-10-31'], ['2026-09-30', 1, '2026-10-31'],
              ['2026-01-15', 1, '2026-02-28'], ['2026-09-14', 0, '2026-09-30'],
              ['2026-12-20', 1, '2027-01-31']] as [$naar, $mnd, $ventet]) {
        $ut = (new DateTimeImmutable($naar, new DateTimeZone('Europe/Oslo')))
            ->modify('first day of this month')
            ->modify('+' . ($mnd + 1) . ' months')
            ->modify('-1 day')->format('Y-m-d');
        if ($ut !== $ventet) {
            return false;
        }
    }
    return true;
})());
// Datoen regnes i norsk tid. Serveren staar i UTC, og en oppsigelse levert
// 1. oktober klokka 00:30 norsk tid ville ellers telt som september.
sjekk('sluttdatoen regnes i norsk tid',
    str_contains($medlFil, "new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo'))"));

// Verkstedets egen avslutning fulgte en annen regel: sluttdato i dag, og
// medlemmet mistet tilgangen samme sekund. Loep det en Vipps-avtale, ble hele
// avslutningen avvist, saa eieren maatte inn i Vipps for haand.
$medFil = file_get_contents(dirname(__DIR__) . '/api/admin/medlemmer.php');
sjekk('verkstedets avslutning bruker den samme regelen',
    str_contains($medFil, '$slutter = Medlemskap::sluttdato(')
    && !str_contains($medFil, "'slutt_dato' => date('Y-m-d'),"));
sjekk('en loepende Vipps-avtale stopper ikke lenger avslutningen',
    !str_contains($medFil, 'Medlemmet har en løpende Vipps-avtale. Den må sies opp først')
    && str_contains($medFil, "'sagt_opp_at' => gmdate('Y-m-d H:i:s'),"));
// Statusen staar til datoen er ute — cron setter «oppsagt» naar den har
// passert, baade for dem med avtale og dem som er meldt inn for haand.
sjekk('medlemmet staar aktivt ut oppsigelsestida',
    str_contains($medFil, "DB::oppdater('members', ['slutt_dato' => \$slutter], ['id' => \$id]);"));
sjekk('cron avslutter naar sluttdatoen har passert',
    str_contains(file_get_contents(dirname(__DIR__) . '/bin/cron.php'),
                 'AND slutt_dato < CURDATE()'));
// Aapnes medlemskapet igjen, maa oppsigelsen trekkes tilbake ogsaa paa
// avtalen — ellers stopper cron den i Vipps paa den gamle datoen.
sjekk('gjenaapning trekker oppsigelsen tilbake',
    str_contains($medFil, 'UPDATE subscriptions SET sagt_opp_at = NULL, slutter = NULL'));

// Medlemmet skal se datoen foer det bekrefter, ikke etterpaa.
sjekk('medlemmet faar datoen foer det sier opp',
    str_contains(file_get_contents(dirname(__DIR__) . '/api/medlemskap.php'),
                 "'sluttHvisOppsagt' => Booking::norskDatoKort(")
    && str_contains($sida, 'const naar = (a && (a.slutter || a.sluttHvisOppsagt))'));
// «Én maaned» sto fast i teksten. Tallet hoerer til planen.
sjekk('oppsigelsestida i teksten leses av planen',
    str_contains($sida, 'const mnd = (a && a.oppsigelseMnd) || 1;')
    && !str_contains($sida, "'Det har én måneds oppsigelsestid, så det gjelder ut '"));

// ── Dubletter i medlemslista ─────────────────────────────────────────────
//
// Samme person ligger flere ganger: booket som gjest med e-posten, meldte
// seg inn med telefonen, logget inn med Vipps en tredje gang. Da staar
// timene paa én rad og kursbevisene paa en annen. «Slettes for haand under
// Medlemmer» sto det i de aapne punktene — men aa slette den ene er aa miste
// historikken hennes, ikke aa rydde.
$dubFil = file_get_contents(dirname(__DIR__) . '/api/admin/dubletter.php');
// E-post og telefon er sikre funn; navn alene er et forslag. To personer kan
// hete det samme, men de deler ikke innboks.
sjekk('navn alene er et forslag, ikke et funn',
    str_contains($dubFil, "\$sikker = (in_array('epost', \$slag, true) || in_array('telefon', \$slag, true))"));
// Et nummer mange deler er en plassholder, ikke ett menneske.
sjekk('et nummer mange deler regnes ikke som sikkert',
    str_contains($dubFil, '$mange = 4;')
    && str_contains($dubFil, "Deler e-post eller telefon med mange andre rader"));
// Femten tabeller peker paa members. Lista bygges av basen, ikke skrevet av
// for haand — den som legger til en tabell neste gang skal slippe aa huske
// denne fila.
sjekk('tabellene som peker paa medlemmet finnes av basen',
    str_contains($dubFil, "AND column_name IN ('member_id', 'registrert_av')"));
// Alt eller ingenting. Foerste forsoek flyttet innstemplingene og falt saa
// paa en unik noekkel: bookingene sto paa den nye raden mens den gamle
// fortsatt var et aktivt medlem.
sjekk('sammenslaaingen er alt eller ingenting',
    str_contains($dubFil, 'DB::iTransaksjon(static function () use ($behold, $fjern, $a, $b): array {'));
// «vipps_sub», «brukernavn» og «recurring_agreement_id» er unike i members.
// Kopieres de over foer den gamle raden er toemt, kolliderer de med seg selv.
sjekk('den gamle raden toemmes for verdiene flyttes',
    strpos($dubFil, "'anonymisert_at'  => gmdate")
    < strpos($dubFil, "if (\$fyll !== []) {\n    DB::oppdater('members', \$fyll"));
// Ingenting slettes. To tabeller har en unik noekkel som inkluderer
// medlemmet, og der blir raden staaende framfor aa bli slettet.
sjekk('raden anonymiseres, den slettes ikke',
    str_contains($dubFil, "'navn'            => 'Slått sammen',")
    && !str_contains($dubFil, 'DELETE FROM members'));
sjekk('det som ikke kan flyttes blir staaende, ikke slettet',
    str_contains($dubFil, 'UPDATE IGNORE `{$t}` SET `{$k}`')
    && str_contains($dubFil, "kunne ikke flyttes fordi den samme "));
// En administrator slaas ikke bort ved et uhell.
sjekk('en administrator kan ikke slaas bort',
    str_contains($dubFil, "if (\$b['rolle'] === 'admin') {")
    && str_contains($dubFil, 'Du kan ikke slå sammen din egen konto inn i en annen.'));
// Panelet staar bare naar noe er funnet, som frys-panelet ved siden av.
sjekk('panelet staar bare naar noe er funnet',
    str_contains($sida, 'dubHar: grupper.length > 0,')
    && str_contains($sida, '<sc-if value="{{ dubHar }}"'));
// Knappen staar ikke paa raden som foreslaas beholdt, og ikke paa en admin.
sjekk('knappen staar bare der den kan brukes',
    str_contains($sida, 'kanSlaas: i > 0 && !m.erAdmin,'));
// Sammenslaaing er ikke noe man gjor ved et uhell.
sjekk('sammenslaaingen spor forst',
    str_contains($sida, "if (!window.confirm('Slå «' + m.navn + '» inn i «'"));
// Lista maa hentes paa nytt: den ene raden finnes ikke lenger.
sjekk('listene hentes paa nytt etter en sammenslaaing',
    str_contains($sida, 'dubletter: null, adminMedlemmer: null,')
    && str_contains($sida, 'this.hentDubletter();'));

// ── Kommentarer som ikke lenger stemte ───────────────────────────────────
//
// Fire kommentarer sa at noe ikke var koblet opp, med koden som kobler det
// rett under. Den forste setningen stemte da den ble skrevet og ble staaende
// da funksjonen kom. En kommentar som lyver er verre enn ingen kommentar:
// den lurte meg selv 29. august til aa tro at timeforbruket paa Min side ikke
// virket, og jeg holdt paa aa bygge det om igjen.
sjekk('ingen kommentar paastaar at innstemplingen mangler',
    !str_contains($sida, 'Innstempling finnes ikke i basen')
    && !str_contains($sida, 'Timeteljinga finnes ikke ennaa')
    && !str_contains($sida, '«I verkstedet naa» krever innstempling, og den er ikke koblet opp'));
sjekk('ingen kommentar paastaar at interne samlinger mangler',
    !str_contains($sida, 'Interne samlinger finnes ikke i basen ennaa'));
// Og det de sa var ubygget, virker: timene kommer fra api/stempling.php.
sjekk('timene paa Min side kommer fra innstemplingene',
    str_contains($sida, "timerIgjen: fri ? '∞' : String(st.timer.igjen).replace('.', ',')")
    && str_contains($sida, 'timerBrukt: st.timer.brukt,'));
sjekk('endepunktet regner ut timene',
    str_contains(file_get_contents(dirname(__DIR__) . '/api/stempling.php'),
                 'Stempling::minutterDenneManeden($id)'));
// Lista over hvem som er i verkstedet leses ogsaa derfra.
sjekk('«i verkstedet naa» leses av innstemplingene',
    str_contains($sida, 'this.state.stempling.inne.liste.map('));

// ── Varselkort: to koer ingen sto vakt over ──────────────────────────────
//
// Et medlem som legger en gjenstand ut for salg venter paa aa bli godkjent,
// og varen ligger ute av butikken saa lenge. Et medlem som soker om aa fryse
// medlemskapet venter paa svar. Begge har hver sin skjerm i admin, men ingen
// vei dit fra Oversikt — man maatte vite at koen fantes for aa gaa og se
// etter, og da kan noe bli liggende i ukevis.
$ovFil = file_get_contents(dirname(__DIR__) . '/api/admin/oversikt.php');
sjekk('oversikten teller de to koene',
    str_contains($ovFil, "SELECT COUNT(*) FROM member_sales WHERE status = 'til_godkjenning'")
    && str_contains($ovFil, "SELECT COUNT(*) FROM medlem_frys WHERE status = 'sokt'"));
// Begge tabellene kom med senere migrasjoner. Er de ikke kjort, skal
// endepunktet svare, ikke doe.
sjekk('oversikten taaler at tabellene ikke finnes',
    str_contains($ovFil, "DB::harTabell('member_sales')")
    && str_contains($ovFil, "DB::harTabell('medlem_frys')"));
// Kortene staar bare naar noe venter — samme regel som ventelista. Et kort
// som hver dag sier «ingen» er ett kort mer aa lese forbi.
sjekk('kortene staar bare naar noe venter',
    str_contains($sida, "...(tilGodkjenning ? [kort('Medlemsvarer til godkjenning',")
    && str_contains($sida, "...(frysVenter ? [kort('Søknader om frys',"));
// Tallet er serverens. Kortet «Internbutikk» paa Medlemmer teller de samme
// radene fra lista i nettleseren; to utregninger av samme tall kan si hver
// sin ting naar den ene ikke har lastet ennaa.
sjekk('tallene kommer fra endepunktet, ikke fra en egen utregning',
    str_contains($sida, 'const tilGodkjenning = koer.medlemsvarer || 0;')
    && str_contains($sida, 'const frysVenter = koer.frys || 0;'));
// Kortene maa fore dit man kan gjore noe.
sjekk('kortene gaar dit koen behandles',
    str_contains($sida, "this.gaaAdmin('adminbutikk', { butikkFane: 'Medlemssalg' }),")
    && str_contains($sida, "this.gaaAdmin('adminmedlem', {}),"));
// Kasse-kortet sa hva kassa er, ikke hva som har skjedd i den.
sjekk('kassekortet viser dagens salg',
    str_contains($sida, "'Solgt for ' + kasseIdag + ' i dag. '")
    && str_contains($sida, "'Ingen salg over disk i dag ennå. '"));
// «kr. 0,-» paa kortet ser ut som en feil, ikke som en rolig formiddag.
sjekk('kassekortet viser ikke null kroner',
    str_contains($sida, "const kasseIdag = /[1-9]/.test(kasseRaa) ? kasseRaa : '';"));
// Beloepet hoerer ikke hjemme i tallmerket ved siden av «3» paa ventelista —
// der leses et tall som antall.
sjekk('beloepet staar i teksten, ikke i tallmerket',
    str_contains($sida, "+ 'medlemskap eller en ting fra hylla. Går rett i regnskapet.',\n                 null, 'Åpne kassa',"));

// ── Fase 9: opprydding ───────────────────────────────────────────────────
//
// Doed kode er ikke bare stygt. Den ser ut som noe som virker, og forrige
// gang kostet det: en helsesjekk som leste feil tabell sa «alt i orden» mens
// den ekte manglet, og fem ledninger i kalenderen gikk ingen steder.
//
// «tilOrdre» var det eneste stedet «ordreApen» ble satt. «ordrePopup» krevde
// den, saa bestillingsboksen paa Min side kunne aldri aapne seg — og
// «lukkOrdre» lukket noe som aldri sto aapent.
sjekk('bestillingsboksen som aldri kunne aapne seg er borte',
    !str_contains($sida, 'tilOrdre()') && !str_contains($sida, 'ordreApen')
    && !str_contains($sida, 'ordrePopup:') && !str_contains($sida, 'lukkOrdre:'));
// «Flyttet» sto som status paa Min side, lest av state.flyttet. Den ble bare
// skrevet av «flyttPlass», som ingen kalte — og medlemmet har ingen
// «Flytt»-knapp, bare «Avbestill». Statusen kunne aldri vises.
sjekk('statusen «Flyttet» som aldri kunne settes er borte',
    !str_contains($sida, 'flyttPlass(') && !str_contains($sida, 'this.state.flyttet'));
// Avbestillingen blir staaende: den er forhaandsvisningens utgave av
// avbestillEkte, og den kalles.
sjekk('avbestillingen staar, den er fortsatt i bruk',
    str_contains($sida, 'avbestillPlass(tittel)') && str_contains($sida, 'this.avbestillPlass(p.tittel)'));
// Et internkjop som bare la seg i state og forsvant ved neste sidelasting.
sjekk('internkjopet som aldri naadde serveren er borte',
    !str_contains($sida, 'kjopIntern('));
// Prikkene under rotasjonen er ikke knapper, saa ingen kunne hoppe med dem.
sjekk('hoppet mellom rotasjonsbildene er borte', !str_contains($sida, 'rotasjonTil('));
// Pilene bruker fortsatt tonBytt.
sjekk('pilene i rotasjonen virker fortsatt', str_contains($sida, "this.tonBytt('rot'"));
// Seks props som ingen skjerm binder. Skjemaet i detaljdialogen er bygget om,
// og «Ny kursdato» velger kurs med brikker, ikke med en nedtrekksliste.
foreach (['detaljSkjema:', 'detaljBilder:', 'adminFokusValg:', 'settNdKurs:',
          'ndKursValg:', 'ndKanLegge:'] as $p) {
    sjekk('propen «' . rtrim($p, ':') . '» er borte', !str_contains($sida, $p));
}
sjekk('kursvelgeren i «Ny kursdato» staar', str_contains($sida, 'ndKursListe:'));
// To attrapper: dialoger som beskrev en funksjon i stedet for aa gjore den
// («Type: Kurs, event, drop-in eller workshop»), og som ingen knapp aapnet.
// Begge funksjonene finnes for ekte naa, saa beskrivelsene er bare i veien.
sjekk('attrappdialogene «Ny serie» og «Endre aapningstider» er borte',
    !str_contains($sida, 'aNySerie:') && !str_contains($sida, 'aEndreTider:'));
// Den ekte serien lages fra «Ny kursdato» med en gjentakelse, og kurs.php
// tar imot den.
sjekk('kursserien lages for ekte',
    str_contains($sida, "handling: 'serie',")
    && str_contains(file_get_contents(dirname(__DIR__) . '/api/admin/kurs.php'), "case 'serie':"));
// Aapningstidene redigeres for ekte fra drop-in-tidene og fra kalenderen.
sjekk('aapningstidene redigeres for ekte',
    str_contains($sida, 'aNyDropin: () => this.setState({ dRed: true')
    && str_contains($sida, "this.klKall('/api/admin/apningstider.php'"));

// To tabeller fra 001_init som ingen SQL leser. «checkins» er tvillingen til
// «check_ins» med understrek — den ekte — og «hour_usage» ble aldri bygget:
// timene regnes ut av check_ins.
$m90 = file_get_contents(dirname(__DIR__) . '/db/migrations/090_rydder_to_doede_tabeller.sql');
sjekk('migrasjon 090 dropper bare tabeller som er tomme',
    str_contains($m90, 'SELECT COUNT(*) INTO @rader FROM checkins')
    && str_contains($m90, 'IF(@n = 1 AND @rader = 0, \'DROP TABLE checkins\', \'DO 0\')')
    && str_contains($m90, 'IF(@n = 1 AND @rader = 0, \'DROP TABLE hour_usage\', \'DO 0\')'));
// Er de droppet, skal ingen SQL savne dem.
sjekk('ingen SQL leser de to doede tabellene',
    !preg_match('/(FROM|INTO|UPDATE|JOIN)\s+`?(checkins|hour_usage)`?\b/',
        $sida . file_get_contents(dirname(__DIR__) . '/api/admin/kalender.php')));
// Innstemplingen leser den ekte.
sjekk('innstemplingen leser check_ins med understrek',
    DB::harTabell('check_ins'));

// ── Fase 8: verkstedet ───────────────────────────────────────────────────
//
// Notatet og paaminnelsene i kalenderen sto i «localStorage» — i nettleseren
// paa den maskinen de ble skrevet paa. Skrev eieren en paaminnelse paa
// telefonen, fantes den ikke paa PC-en, og toemte hun nettleserdataene var den
// borte. Det var ikke til aa se paa skjermen, og det er nettopp derfor det var
// farlig: hun skrev noe hun trodde var lagret.
foreach (['verksted_notater', 'verksted_paaminnelser', 'brenninger'] as $t) {
    sjekk('tabellen «' . $t . '» finnes', DB::harTabell($t));
}
sjekk('notatet og paaminnelsene ligger ikke lenger i nettleseren',
    !str_contains($sida, "localStorage.getItem('lissomKlNotat') || ''; } catch")
    && !str_contains($sida, "localStorage.setItem('lissomKlPamin'"));
// Det som alt sto i nettleseren maa flyttes inn, ellers ville notatet
// forsvunnet i det oyeblikket feltet begynte aa lese fra serveren.
sjekk('det som sto i nettleseren flyttes inn i basen én gang',
    str_contains($sida, 'vstFlyttFraNettleseren(d) {')
    && str_contains($sida, "localStorage.removeItem('lissomKlNotat')"));
// Notatet er personlig. Uten «member_id» i betingelsen kunne en admin slettet
// en annens paaminnelse ved aa gjette nummeret.
$vstFil = file_get_contents(dirname(__DIR__) . '/api/admin/verkstedet.php');
sjekk('paaminnelsene er personlige, ogsaa naar de slettes',
    str_contains($vstFil, 'DELETE FROM verksted_paaminnelser WHERE id = :i AND member_id = :m'));
// En brenning gaar ofte over natta.
sjekk('en brenning kan gaa over natta',
    str_contains($vstFil, "\$sDato = Foresporsel::tekst('sluttDato') ?: \$dato;"));
sjekk('brenningens slag er et valg, ikke fritekst',
    str_contains($vstFil, "const BRENNSLAG = ['raabrann', 'glasurbrann', 'annet'];"));
// Brenningene staar i kalenderen. Vaktene gjor det ikke: hver vakt er en
// kursdato som alt ligger der, saa en egen vaktbrikke ville dublert raden.
sjekk('brenningene staar i kalenderen, vaktene ikke',
    str_contains($kalFil, "'type'      => 'brenning',")
    && !str_contains($kalFil, "'type'   => 'vakt',")
    && str_contains($kalFil, 'array_merge($hendelser, $verksted, $brenninger)'));
// Uten tabellen skal endepunktet svare, ikke doe.
sjekk('kalenderen taaler at migrasjon 088 ikke er kjort',
    str_contains($kalFil, "DB::harTabell('brenninger')"));
// Verkstedet er blitt et sted med tre faner.
sjekk('verkstedet har oppskrifter, vakter og brenning',
    str_contains($sida, "['Vakter',        'adminoppskrifter', { vstFane: 'vakter' }],")
    && str_contains($sida, "['Brenning',      'adminoppskrifter', { vstFane: 'brenning' }],"));
// Lista viser hele aaret. En liste som bare viser to uker ser tom ut for den
// som satte opp en vakt i november.
sjekk('listene viser hele aaret framover, ikke bare to uker',
    str_contains($sida, "const om = new Date(naa.getFullYear() + 1, naa.getMonth(), naa.getDate());"));

// ── Beskjedkortet i kalenderen ───────────────────────────────────────────
//
// Kortet sto med overskrifta og «Aapne →» og ingenting under. Det ser ikke
// tomt ut, det ser oedelagt ut: eieren kunne ikke vite om ingen hadde skrevet,
// eller om lista hadde sluttet aa laste. Sosterkortet «Paaminnelser» har hatt
// den linja hele tida.
sjekk('beskjedkortet sier fra naar koen er tom',
    str_contains($sida, 'klBeskjederTom: (this.state.adminForesporsler || [])')
    && str_contains($sida, 'Ingen ubesvarte beskjeder.'));
// Forhaandsvisningen var én linje med «nowrap», saa paa telefon sto det tre
// ord og en ellipse. Da maatte man aapne hver melding for aa se hva den gjaldt.
sjekk('forhaandsvisningen viser to linjer av meldingen',
    str_contains($sida, '-webkit-line-clamp: 2; line-clamp: 2;')
    && !str_contains($sida, 'text-overflow: ellipsis; white-space: nowrap;">{{ b.tekst }}'));
// «Aapne» gikk til Beskjeder — skjermen der man skriver ut til en gruppe.
// Kortet viser henvendelser som venter paa svar, og det er dit man vil.
sjekk('«Aapne» gaar til de ubesvarte naar det er noe ubesvart',
    str_contains($sida, "? 'adminubesvarte' : 'adminbeskjeder'),"));

// ── Kursholderen paa kurset, og vaktene ut ───────────────────────────────
//
// Vakttabellen kom og gikk. Eieren: «det er ingen andre vakter utenom
// kursholdere» — den som er i verkstedet, er der fordi hun holder et kurs.
// En vakttabell ved siden av kursdatoene ville vaert to steder aa vedlikeholde
// det samme, og de to ville sklidd fra hverandre.
sjekk('vakttabellen er borte', !DB::harTabell('vakter'));
sjekk('verkstedet tar ikke lenger imot vakter',
    !str_contains($vstFil, "case 'vakt':") && !str_contains($vstFil, "case 'vaktVekk':"));
// Lista leses av kursdatoene i stedet, saa den ikke kan si noe annet enn
// kalenderen.
sjekk('vaktlista leses av de planlagte kursene',
    str_contains($vstFil, 'COALESCE(kh.navn, kk.navn, std.navn) AS navn')
    && str_contains($vstFil, 'LEFT JOIN kursholdere kk ON kk.id = c.kursholder_id')
    && str_contains($vstFil, "AND cs.status <> 'avlyst'"));
// En liste uten skjema maa ha en vei videre, ellers er den en blindvei: man
// ser hvem som staar der, uten aa kunne endre det.
sjekk('vaktfana har ingen felter, men en vei til aa legge ut en dato',
    str_contains($sida, 'vstHarSkjema: erBrenn,')
    && str_contains($sida, 'vstFelt: erVakt ? [] : brennFelt,')
    && str_contains($sida, 'vstNyDato: () => this.apneNyKursdato(),'));

// Kursholderen har hoert til den enkelte datoen siden 085. Uten et valg paa
// kurset matte man satt den samme personen paa hver eneste dato.
sjekk('kurset har en kursholder', DB::harKolonne('courses', 'kursholder_id'));
sjekk('kurset mister ikke kursholderen naar noen slutter',
    (int) DB::verdi(
        'SELECT COUNT(*) FROM information_schema.referential_constraints
          WHERE constraint_schema = DATABASE() AND constraint_name = :n
            AND delete_rule = :r',
        ['n' => 'fk_kurs_holder', 'r' => 'SET NULL']) === 1);
$kursFil = file_get_contents(dirname(__DIR__) . '/api/admin/kurs.php');
sjekk('kursholderen kan lagres paa kurset',
    str_contains($kursFil, "if (\$har('kursholderId') && DB::harKolonne('courses', 'kursholder_id')) {")
    && str_contains($kursFil, "\$data['kursholder_id'] = \$holderId('kursholderId');"));
// Tre trinn, én vei: datoens valg staar over kursets, kursets over standarden.
// Tomt paa kurset betyr ikke «ingen» — det betyr Monica.
sjekk('en ny dato arver kursets kursholder, ellers verkstedets standard',
    str_contains($kursFil, "? \$holderId('kursholderId')
                : (\$paaKurset !== null ? (int) \$paaKurset
                    : (\$standard !== null ? (int) \$standard : null));"));
sjekk('kursholderen er et valg i kursoppsettet',
    str_contains($sida, "felt('kursholderId', 'Kursholder', 'valg',")
    && str_contains($sida, "[['0', 'Verkstedets standard']].concat("));

// ── Fase 7: menyen ───────────────────────────────────────────────────────
//
// «Deltakere» og «Kurs og medlemskap» sto som to punkter og delte allerede to
// faner: «Paameldte» og «Kurs» sto begge steder. To faner med samme navn gikk
// til hver sin liste — «Kurs» var malene det ene stedet og de aapne kursene
// det andre. Naa er de ett omraade.
sjekk('kurs og deltakere er ett menypunkt',
    str_contains($sida, "['Kurs og deltakere', 'adminomrkurs'],")
    && !str_contains($sida, "['Deltakere', 'adminomrdeltakere'],")
    && !str_contains($sida, "['Kurs og medlemskap', 'adminomrkurs'],"));
// Ingen adresse doer av en opprydding. /admin/deltakere er delt og bokmerket.
sjekk('begge adressene lander paa den samme skjermen',
    str_contains($sida, "case 'adminomrdeltakere':  return p('Kurs og deltakere', '', '');")
    && str_contains($sida, "side === 'adminomrkurs' || side === 'adminomrdeltakere'"));
// Kalenderen gjor det meste av det daglige etter fase 6, og skal ikke ligge
// midt i lista.
sjekk('kalenderen staar som punkt nummer to',
    str_contains($sida, "['Oversikt',  'adminoversikt'],\n      // Kalenderen staar som nummer to."));
// Oppskriftene er verkstedets egne, og «Verkstedet» er stedet fase 8 lander.
sjekk('oppskriftene staar under «Verkstedet»',
    str_contains($sida, "['Verkstedet', 'adminoppskrifter'],")
    // Fase 8 ga stedet tre faner, saa menypunktet peker paa et omraade naa.
    && str_contains($sida, "case 'adminoppskrifter':\n        return p('Verkstedet', 'Verkstedet',"));
// Ventelista var bare aa naa fra et kort paa Oversikt.
sjekk('ventelista har faatt sin plass i fanerekka',
    str_contains($sida, "['Venteliste',    'adminventeliste'],")
    && str_contains($sida, "case 'adminventeliste':    return p('Kurs og deltakere', 'Kurs og deltakere', 'Venteliste');"));
// En fane som forer til en skjerm uten fanerad er en blindvei.
sjekk('ventelisteskjermen har fanerekka, saa den ikke blir en blindvei',
    substr_count($sida, '{{ harOmrFaner }}') === 15);
// Veien inn til dagen. Kalenderen sto som ett menypunkt blant elleve, og den
// som aapnet Oversikt om morgenen fikk ingen vei dit.
sjekk('«Kursadministrasjon» staar oeverst paa Oversikt',
    str_contains($sida, "kort('Kursadministrasjon',")
    && str_contains($sida, "if (i === -1 && k.navn === 'Kursadministrasjon') return -1;")
    && str_contains($sida, "const ROR = ['Kursadministrasjon',"));

// ── «Rediger okten» paa telefon ──────────────────────────────────────────
//
// «Lagre» laa nederst i ruta, og ruta ruller. Paa en telefon med tastaturet
// oppe var knappen langt under skjermkanten — eieren fant den ikke, og trodde
// det ikke fantes noen lagreknapp.
sjekk('lagrelinja i «Rediger okten» staar klistret oeverst',
    str_contains($sida, 'position: sticky; top: calc(var(--space-8) * -1); z-index: 2;')
    && str_contains($sida, '{{ klREndretTekst }}'));
// Navnefeltet sto der og kunne skrives i, men ingenting sendte det noe sted.
// «course_sessions» har ingen tittel — den peker paa kurset.
sjekk('navnefeltet i okta endrer kurset, og sier fra om det',
    str_contains($sida, "hva: 'navnet på kurset'")
    && str_contains($sida, 'Navnet hører til kurset. Endrer du det, endres det på alle datoene'));
// Ett sted som vet hva som er endret, saa linja og lagringen aldri kan vaere
// uenige om hva som skjer naar du trykker.
sjekk('linja og lagringen leser den samme lista',
    str_contains($sida, 'klRedEndringer(redEvt) {')
    && str_contains($sida, 'this.klKallFlere(endr.map(e2 => e2.kall))'));

// ── Kurset redigeres der kortet staar ────────────────────────────────────
//
// Kortene i kurslista kunne bare dras. Ville eieren rette en pris eller en
// tekst, matte hun ut av kalenderen, inn i kursoppsettet, finne kurset igjen
// og tilbake. Naa aapner et klikk kurset der det staar.
sjekk('et klikk paa kurskortet aapner kurset',
    str_contains($sida, 'klApneKursRed(kurs, mv);')
    && str_contains($sida, 'klApneKursRed(kort, ev) {'));
// Ruta legger seg ved kortet, ikke midt paa skjermen.
sjekk('ruta staar der kortet staar',
    str_contains($sida, "{ x: ev.clientX + 16, y: Math.max(12, ev.clientY - 80) }"));
// Lagrelinja kommer forst naar noe faktisk er endret.
sjekk('lagrelinja kommer naar noe er endret',
    str_contains($sida, 'klKursRedEndret: endret.length > 0,'));
// «Lagre» sender forskjellen. Sendes alt, toemmes et felt skjermen ikke
// kjenner — og status skrives ubetinget, saa uten den ville kurset falt til
// kladd og forsvunnet fra nettsida.
sjekk('lagringen sender det som er endret, pluss tittel og status',
    str_contains($sida, "endret.forEach(f => { kropp[f.nokkel] = naa[f.nokkel] || ''; });")
    && str_contains($sida, "status: naa.status || start.status || 'kladd' }"));
// Endringen gjelder kurset, og datoene peker paa kurset. Svaret sier hvor
// langt rettelsen rekker.
sjekk('svaret sier hvor mange planlagte datoer endringen gjelder',
    str_contains($kursFil6, "'Endringen gjelder også de ' . \$framover . ' planlagte datoene.'"));

sjekk('stemplingen i kalenderen leser den samme kilden som resten',
    str_contains($sida, "klStempleTekst: this.erInne() ? 'Stemple ut' : 'Stemple inn',")
    && !str_contains($sida, 'klInneTid'));
// En avlyst dato kunne ikke settes tilbake. Da matte den settes opp paa nytt,
// og de paameldte fulgte ikke med.
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

// ── Legg til deltaker rett paa okta, med vippskrav ─────────────────────
//
// Panelet ligger i oktredigereren i kalenderen. Det er ingen ny
// paameldingsvei: samme endepunkt, samme booking, samme deltakerliste.
// «Vippskrav» er den eneste maaten som sender noe.
$pamFil = file_get_contents(dirname(__DIR__) . '/api/admin/pamelding.php');
$sida2  = file_get_contents(dirname(__DIR__) . '/lissom-2108.html');

sjekk('vippskrav er en godkjent betalingsmaate',
    str_contains($pamFil, "'Vippskrav'") && str_contains($pamFil, 'const MAATER'));
sjekk('et krav uten mobilnummer avvises',
    str_contains($pamFil, 'Et vippskrav må ha et mobilnummer'));
sjekk('et krav paa null kroner avvises',
    str_contains($pamFil, 'Et vippskrav må ha et beløp over null'));
sjekk('plassen staar som reservert til kravet er godtatt',
    str_contains($pamFil, "in_array(\$maate, ['Betaler ved oppmøte', 'Vippskrav'], true)"));
sjekk('kravet gaar som push, ikke som en nettleserbetaling',
    str_contains($pamFil, 'Vipps::opprettBetaling(') && str_contains($pamFil, "\$telefon,\n            true"));
sjekk('betalingen knyttes til bookingen begge veier',
    str_contains($pamFil, "DB::harKolonne('payments', 'booking_id') ? \$bookingId : null")
    && str_contains($pamFil, "DB::oppdater('bookings', ['payment_id' => \$betalingId]"));

// ── Ryddingen etter et krav som ikke gikk gjennom ──────────────────────
//
// «bookings.payment_id» peker paa «payments». Slettes betalingen forst,
// avviser basen det med en fremmednoekkelfeil — og da sto vi igjen med en
// reservert plass, en betaling ingen hadde bedt om, og en 500-feil i stedet
// for en forklaring. Den feilen var ekte; dette er vakten mot at den kommer
// tilbake.
$bPos = strpos($pamFil, "DELETE FROM bookings WHERE id = :b");
$pPos = strpos($pamFil, "DELETE FROM payments WHERE id = :p");
sjekk('ryddingen sletter bookingen for betalingen',
    $bPos !== false && $pPos !== false && $bPos < $pPos);
sjekk('en rydding som selv feiler sier fra at plassen ble staaende',
    str_contains($pamFil, 'plassen ble stående'));

// Og at basen faktisk oppforer seg slik regelen sier.
$fkOkt = DB::verdi("SELECT cs.id FROM course_sessions cs
                     JOIN courses c ON c.id = cs.course_id
                    WHERE cs.status <> 'avlyst' ORDER BY cs.id LIMIT 1");
if ($fkOkt !== null) {
    $fkBet = (int) DB::settInn('payments', [
        'vipps_reference' => 'FK-TEST-' . bin2hex(random_bytes(4)),
        'type' => 'epayment', 'formal' => 'booking',
        'belop_ore' => 45000, 'status' => 'opprettet',
        'idempotency_key' => Vipps::uuid(),
    ]);
    $fkKurs = (int) DB::verdi('SELECT course_id FROM course_sessions WHERE id = :i', ['i' => $fkOkt]);
    $fkBook = (int) DB::settInn('bookings', [
        'course_id' => $fkKurs, 'course_session_id' => (int) $fkOkt,
        'gjest_navn' => 'FK Testesen', 'antall' => 1, 'belop_ore' => 45000,
        'status' => 'reservert', 'betalt_maate' => 'Vippskrav', 'payment_id' => $fkBet,
    ]);

    $stoppet = false;
    try {
        DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $fkBet]);
    } catch (Throwable $e) {
        $stoppet = true;
    }
    sjekk('basen nekter aa slette betalingen forst', $stoppet);

    DB::kjor('DELETE FROM bookings WHERE id = :b', ['b' => $fkBook]);
    DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $fkBet]);
    sjekk('… og slipper den naar bookingen er ute forst',
        DB::en('SELECT id FROM payments WHERE id = :p', ['p' => $fkBet]) === null);
}

// ── Panelet i oktredigereren ───────────────────────────────────────────
sjekk('panelet staar bare der noen kan meldes paa',
    str_contains($sida2, 'kdMulig: kanMelde')
    && str_contains($sida2, 'const kanMelde = !!(redEvt && redEvt.oktId);'));
// Alle typer likt. Sto det et oppslag i kurslista her, forsvant panelet fra
// alle oktene samtidig naar kalenderen ble aapnet direkte — lista lastes av
// et annet kall.
sjekk('panelet henger ikke paa en liste som lastes et annet sted',
    !str_contains($sida2, 'kanMelde = !!(redEvt && redEvt.oktId\n')
    && !preg_match('/const kanMelde[^;]*kursData\(\)/', $sida2));
sjekk('panelet bruker det samme endepunktet som resten',
    str_contains($sida2, "handling: 'legg-til',\n                      oktId: Number(redEvt.oktId),"));
// Lista hadde vokst til seks. Eieren, 29. august: «folk kan betale kontant,
// med vipps eller gavekort. Og noen faar gratis.»
sjekk('valget paa okta er kortet ned til det som brukes',
    str_contains($sida2, "return ['Kontant', 'Vipps', 'Gavekort', 'Gratis'];"));
// De gamle maatene staar igjen i BETALT_MAATER, saa paameldinger som alt er
// lagt inn med «Faktura» beholder maaten sin.
sjekk('de gamle maatene finnes fortsatt for det som er lagt inn',
    str_contains($sida2, "return ['Kontant', 'Vipps i verkstedet', 'Faktura', 'Betaler ved oppmøte', 'Gratis'];"));
// Et gavekort er penger som alt er betalt inn. Trekkes det ikke fra kortet,
// kan det brukes om igjen, og gavekortgjelda blir aldri nedskrevet.
sjekk('gavekortet finnes for plassen legges inn',
    str_contains($pamFil, 'Booking::finnGavekort(Foresporsel::tekst(\'kode\'))'));
sjekk('… og saldoen maa daekke plassen',
    str_contains($pamFil, "if (\$kort['saldo_ore'] < \$belop) {"));
sjekk('… og beloepet trekkes fra kortet',
    str_contains($pamFil, 'Booking::trekkGavekort($betalingId);'));
sjekk('… uten at det telles som penger inn',
    str_contains($pamFil, "'belop_ore'       => 0,\n        'gavekort_id'     => \$kort['id'],"));
sjekk('knappen sier hva den gjor naar det er et krav',
    str_contains($sida2, "kdKnapp: krav ? 'Send vippskrav' : 'Legg inn deltakeren'"));
// To felter som het «Navn», og to knapper som het «Legg til», sto i samme
// bilde. Begge er dopt om, og begge navnene skal holde seg unike.
sjekk('deltakerfeltet heter noe annet enn oktas eget navnefelt',
    str_contains($sida2, "tekst('navn', 'Deltakerens navn'"));
sjekk('utkastet toemmes naar en annen okt aapnes',
    str_contains($sida2, 'kdApen: false, kdUtkast: {}, kdVarsle: false'));
sjekk('kalenderen hentes paa nytt saa belegget stemmer',
    str_contains($sida2, "this.setState({ kdUtkast: {}, kdVarsle: false, kdApen: false });"));

// ── Drop-in og Paint on Pots samlet til én linje ───────────────────────
//
// Begge foelger aapningstida, og den klippes i plasser paa halvannen time.
// En aapen dag ble til seks like rader per kurs i kalenderen. Eieren, 29.
// august: «jeg vil ikke at det skal splittes».
$kalFil3 = file_get_contents(dirname(__DIR__) . '/api/admin/kalender.php');
sjekk('kalenderen faar vite hvilke oekter en regel har laget',
    str_contains($kalFil3, "'auto'   => (\$harAuto && (int) \$o['fra_apningstid'] === 1)"));
// Uten ukereglene ble drop-in delt i to av sin egen ettermiddagstid: de
// maskinklipte plassene stoppet der ukeregelen tok over.
sjekk('begge reglene teller som laget av en regel',
    str_contains($kalFil3, "\$harDropinTid && \$o['fra_dropin_tid'] !== null"));
sjekk('kolonnene kan mangle uten at kalenderen doer',
    str_contains($kalFil3, "DB::harKolonne('course_sessions', 'fra_apningstid')")
    && str_contains($kalFil3, "DB::harKolonne('course_sessions', 'fra_dropin_tid')"));
sjekk('kalenderen samler plassene til én linje',
    str_contains($sida2, 'const samle = evts =>') && str_contains($sida2, 'gruppeAv'));
// Tid mellom to kurs deler ikke linja — aapningstida gaar i ett spenn fra
// dagens forste kurs til det siste, saa mellomtida er alt klippet i plasser
// og rekka henger sammen. Et hull betyr at doeren var lukket, og da skal det
// staa to linjer: eieren stemplet inn om kvelden.
sjekk('et hull i rekka deler linja i to',
    str_contains($sida2, "if (forrige && forrige.slutt && e.tid === forrige.slutt) { kjede.push(e); return; }"));
sjekk('to linjer samme dag kan aapnes hver for seg',
    str_contains($sida2, "id: 'gruppe|' + f.dato + '|' + f.kursId + '|' + f.tid,"));
// En okt noen har satt opp selv er ingen utsnitt, og skal staa for seg.
sjekk('bare det en regel har laget slaas sammen',
    str_contains($sida2, 'if (e.auto && e.kursId)'));
// Linja er ingen okt: den kan ikke dras, og hoyreklikkmenyen slaar opp paa
// en id som ikke finnes blant oektene.
sjekk('den samlede linja kan ikke dras', str_contains($sida2, "oktId: 0,"));
sjekk('den samlede linja aapner seg selv, ikke en okt',
    str_contains($sida2, 'velg: vekslUtvid(e.id)'));
// Seks tider a tolv er ikke syttito stoler. Summen ville sagt «0 av 72».
sjekk('belegget summeres ikke paa tvers av tidene',
    !str_contains($sida2, 'const gruppeDetalj = g => [g.antallTider + \' tider\', g.holder, belegg(g),'));
// Uten linja over tidene fantes det ingen vei tilbake naar den var aapnet.
sjekk('en aapnet linje kan lukkes igjen',
    str_contains($sida2, "utvidet[g.id] ? 'Skjul' : 'Vis tidene'"));
// Uke- og dagvisningen staar urort: der ligger oektene paa en tidsakse, og
// hver enkelt maa kunne dras til et nytt klokkeslett.
sjekk('bare maaneden og lista samler; tidsaksene staar urort',
    substr_count($sida2, 'samle(dagEvts(') === 2);

// ── Dag to og tre av et flerdagerskurs i kalenderen ────────────────────
//
// Et kurs kan gaa over to eller tre dager paa én paamelding. Dagene ligger i
// «okt_samlinger» med hver sin dato og klokkeslett. Kunden saa dem paa
// kurssida, men kalenderen viste bare den forste — dag to og tre fantes ikke
// for den som planla uka. Eieren, 29. august: «det maa og vises slik i
// kalender».
sjekk('kalenderen henter samlingene', str_contains($kalFil3, 'Samlinger::forOkter($oktIder)'));
sjekk('startdagen legges ikke inn to ganger',
    str_contains($kalFil3, "if ((string) \$sa['dato'] === \$iOslo((string) \$o['start_tid'], 'Y-m-d'))"));
sjekk('dag to sier hvilken dag av kurset det er',
    str_contains($kalFil3, "'samling'     => 'Samling ' . \$sa['nummer'] . ' av ' . \$antSaml,")
    && str_contains($kalFil3, "'samlingKort' => \$sa['nummer'] . ' av ' . \$antSaml,"));
sjekk('dag to baerer de samme paameldte som startdagen',
    str_contains($kalFil3, '$hendelser[count($hendelser) - 1]'));
sjekk('kalenderen viser hvilken dag av kurset det er',
    str_contains($sida2, "const detalj = e => [e.samling || '', e.holder"));
sjekk('maanedsbrikka faar den korte formen',
    str_contains($sida2, "(e.samlingKort ? ' · ' + e.samlingKort : '')"));
// Redigereren ville vist startdagen og latt deg flytte hele kurset fra en dag
// som ikke er den det staar paa.
sjekk('dag to aapner okta slik den staar, ikke redigeringen',
    str_contains($sida2, "const velg = e => (e.samling ? visDetaljer(e) : () => this.klApneEnkel(e));"));
// Aa dra dag to alene ville flyttet hele kurset dit.
sjekk('dag to kan ikke dras',
    str_contains($sida2, "if (e.button !== 0 || !evt || !evt.oktId || evt.samling) return;"));
// Spennet kalenderen henter har en uke slingringsmonn i hver ende, saa et
// kurs som begynner sist i maaneden faar med seg dag to i den neste.
sjekk('spennet naar over et maanedsskifte',
    str_contains($kalFil3, "\$dagStart(Foresporsel::tekst('fra'), -7)"));

// ── Flerdagerskurset paa telefonen ─────────────────────────────────────
//
// Feeden sendte start og slutt raatt, saa et kurs onsdag og torsdag ble én
// hendelse fra onsdag 17:00 til torsdag 20:00 — 27 timer i strekk. Naa er det
// én hendelse per kursdag.
$icsFil = file_get_contents(dirname(__DIR__) . '/api/kalender-abonnement.php');
sjekk('feeden henter samlingene', str_contains($icsFil, 'Samlinger::forOkter(array_map('));
sjekk('feeden lager én hendelse per kursdag',
    str_contains($icsFil, 'foreach ($moter as $mt) {')
    && str_contains($icsFil, "'UID:' . \$mt['uid'] . '@lissom.no'"));
// Den forste dagen beholder den gamle id-en. Ellers blir 27-timersblokka
// liggende igjen paa telefonen ved sida av de nye.
sjekk('den forste dagen beholder id-en telefonen alt kjenner',
    str_contains($icsFil, "'okt-' . (int) \$o['id'] . (\$i === 0 ? '' : '-s' . (int) \$sa['nummer'])"));
sjekk('tittelen sier hvilken dag av kurset det er',
    str_contains($icsFil, "'merke' => ' · ' . ((int) \$sa['nummer']) . ' av ' . \$antSaml,"));
// Et vanlig endagskurs skal se ut noeyaktig som for.
sjekk('et kurs uten samlinger er fortsatt ett moete',
    str_contains($icsFil, "if (\$moter === []) {"));
sjekk('en samling uten sluttid faar den samme reserven som en okt',
    str_contains($icsFil, "\$mStart->modify('+3 hours')"));
// Samlingene ligger i sin egen tabell, saa okta rorte seg ikke naar de ble
// rettet — og SEQUENCE er det telefonen leser for aa se at noe er endret.
$samlFil = file_get_contents(dirname(__DIR__) . '/app/lib/samlinger.php');
sjekk('okta merkes som endret naar samlingene rettes',
    str_contains($samlFil, "UPDATE course_sessions SET updated_at = UTC_TIMESTAMP() WHERE id = :s"));
sjekk('… og taaler at kolonnen mangler',
    str_contains($samlFil, "DB::harKolonne('course_sessions', 'updated_at')"));

// ── Vipps sier hva som er galt ─────────────────────────────────────────
//
// Feilene sto bare i feilloggen paa webhotellet. Paa skjermen sto det «Sjekk
// at nummeret har Vipps, og prov igjen» uansett hva som var galt — feil
// nokler, en salgsenhet uten lov til aa sende betalingskrav, et nummer uten
// Vipps. Eieren kunne ikke se forskjell, og ingen av dem loeses ved aa prove
// igjen.
$vippsFil = file_get_contents(dirname(__DIR__) . '/app/lib/vipps.php');
sjekk('grunnen fra Vipps naar fram', str_contains($vippsFil, 'private static function grunn(array $svar): string'));
sjekk('betalingen kaster grunnen, ikke en gjetning',
    str_contains($vippsFil, 'throw new RuntimeException(self::grunn($svar));')
    && !str_contains($vippsFil, "throw new RuntimeException('Fikk ikke startet betalingen. Prøv igjen om litt.');"));
sjekk('ogsaa naar noklene er feil',
    !str_contains($vippsFil, "throw new RuntimeException('Vipps svarte ikke som forventet.');"));
// Tokenkallet gaar som skjema, saa svaret staar bare i «kropp».
sjekk('svaret leses ogsaa naar det ikke er avkodet fra for',
    str_contains($vippsFil, "json_decode((string) (\$svar['kropp'] ?? ''), true)"));
// «Forbidden» sier ikke mer enn tallet foran.
sjekk('«detail» gaar foran «title»',
    str_contains($vippsFil, "\$detalj = trim((string) (\$j['detail'] ?? ''));"));
sjekk('feltet som er galt staar med navn',
    str_contains($vippsFil, "\$biter[] = (\$f !== '' ? \$f . ': ' : '') . \$r;"));
// ErrorCode 5080: salgsenheten har ikke lov til aa sende betalingskrav. Ingen
// kode retter det — meldingen skal si hva som skal gjores.
sjekk('feil 5080 sier hva som skal gjores',
    str_contains($vippsFil, "str_contains(\$tekst, 'PUSH_MESSAGE') || str_contains(\$tekst, '5080')")
    && str_contains($vippsFil, 'Be Vipps skru på PUSH_MESSAGE for salgsenheten'));
sjekk('kassa viser grunnen',
    str_contains(file_get_contents(dirname(__DIR__) . '/api/admin/uttak.php'),
                 "'Fikk ikke sendt kravet. ' . \$e->getMessage()"));
sjekk('paameldingen viser grunnen',
    str_contains($pamFil, "'Fikk ikke sendt kravet. ' . \$e->getMessage() . ' Ingen plass er lagt inn.'"));

// ── Vipps-QR i kassa ───────────────────────────────────────────────────
//
// Salgsenheten har ikke lov til aa sende betalingskrav (ErrorCode 5080). En
// vanlig betaling virker, og adressen den gir tilbake vises som en kode
// kunden skanner.
$utFil = file_get_contents(dirname(__DIR__) . '/api/admin/uttak.php');
sjekk('kassa kan lage en Vipps-QR', str_contains($utFil, "if (\$handling === 'vippsqr') {"));
// Ingen telefon og ingen push: dette er den vanlige veien, den som virker.
sjekk('QR-en bruker den vanlige betalingen, ikke kravet',
    str_contains($utFil, "Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode(\$referanse)\n        );"));
sjekk('adressen sendes til skjermen', str_contains($utFil, "'url'       => \$url,"));
// Gir Vipps ingen adresse, staar vi igjen med en ordre ingen kan betale.
sjekk('en ordre uten adresse ryddes bort',
    str_contains($utFil, "Svar::feil('Vipps ga ingen betalingsadresse. Ingenting er registrert.');"));
sjekk('skjermen kan sporre om det er betalt',
    str_contains($utFil, "if (\$handling === 'betalstatus') {"));
$qrFil = dirname(__DIR__) . '/vendor/qrcode-2.0.4.js';
sjekk('QR-biblioteket ligger hos oss, ikke paa et fremmed nettsted',
    is_file($qrFil) && str_contains((string) file_get_contents($qrFil), 'MIT license'));
sjekk('biblioteket lastes forst naar koden skal vises',
    str_contains($sida2, "s.src = '/vendor/qrcode-2.0.4.js';")
    && str_contains($sida2, 'if (window.qrcode) return Promise.resolve(window.qrcode);'));
sjekk('kassa viser koden og beloepet',
    str_contains($sida2, 'utQrLag:') && str_contains($sida2, '{{ utQrBilde }}')
    && str_contains($sida2, '{{ utQrBelop }}'));
// Webhooken setter betalingen til «betalt», men skjermen faar ikke vite det
// av seg selv.
sjekk('skjermen foelger med til pengene er inne',
    str_contains($sida2, 'utQrFolg(referanse) {') && str_contains($sida2, "handling: 'betalstatus'"));
// En foresporsel i minuttet i evig tid er ikke noe aa la ligge igjen.
sjekk('den gir seg etter ti minutter',
    str_contains($sida2, 'if (Date.now() - start > 600000) { this.utQrStopp(); return; }'));
sjekk('… og naar koden lukkes', str_contains($sida2, 'utQrLukk: () => { this.utQrStopp();'));
// «if (ok)» skal virke som for hos alle de andre som kaller uttakKall.
sjekk('uttakKall gir svaret tilbake uten aa velte de andre',
    str_contains($sida2, 'return ok ? d : false;'));

// ── Prisen paa datoen gaar foran prisen paa kurset ─────────────────────
//
// «Prisen kan avvike paa én dato» er en egen kolonne, og nettsida og
// Booking::forOkt() har alltid lest den. Den manuelle paameldingen leste bare
// kursets pris, saa en dato med egen pris ble fort til feil sum naar
// beloepsfeltet sto tomt. Eieren la merke til teksten: «dette stemmer vel
// ikke».
sjekk('den manuelle paameldingen leser prisen paa datoen',
    str_contains($pamFil, "'COALESCE(cs.pris_ore, c.pris_ore)' : 'c.pris_ore'"));
sjekk('… med det samme uttrykket som resten av systemet',
    str_contains(file_get_contents(dirname(__DIR__) . '/app/lib/booking.php'),
                 "'COALESCE(cs.pris_ore, c.pris_ore)' : 'c.pris_ore'"));
sjekk('… og taaler at kolonnen mangler',
    str_contains($pamFil, "DB::harKolonne('course_sessions', 'pris_ore')"));
// Teksten under feltet lovet kursets pris. Naa sier den det samme som koden.
sjekk('teksten sier det koden gjor',
    str_contains($sida2, 'brukes prisen på denne datoen.')
    && !str_contains($sida2, 'brukes kursets ordinære pris.'));

// ── Betalingsvalget paa iPhone ─────────────────────────────────────────
//
// Et nedtrekk godtar bare valg-elementer inni seg etter HTML-standarden.
// Malen la en sc-for-lokke der. En parser som folger standarden kaster lokka
// og lar én tom rad staa — provd med html5lib, som bygger treet slik
// standarden sier. Chrome tolererte det og viste alle seks; Safari paa iPhone
// gjorde det ikke, og eieren fikk et tomt nedtrekk med en hake i.
//
// Brikker er vanlige knapper i en div. Ingen regler om hva som kan staa inni.
sjekk('betalingsvalget er brikker, ikke et nedtrekk',
    str_contains($sida2, "erTekst: false, erValg: true, etikett: 'Betaling'")
    && str_contains($sida2, '<button type="button" onClick="{{ v.velg }}" style="{{ v.stil }}">{{ v.navn }}</button>'));
sjekk('… og stilen nedtrekket hadde er ryddet bort',
    !str_contains($sida2, 'kdValgStil'));

// ── Nedtrekkene paa iPhone ─────────────────────────────────────────────
//
// Motoren pakker {{ }} i et <span class="sc-interp">. Chrome faller tilbake
// paa option.text og viser navnet; Safari paa iPhone leser innholdet slik det
// staar, finner et element framfor tekst, og viser en blank linje. Eieren
// 29. august: nedtrekket «Betaling» var tomt med en hake i.
//
// «label» er det standarden peker paa naar et valg skal ha en annen etikett
// enn innholdet, og den vises naar den finnes. Innholdet blir staaende, saa
// option.text virker som for.
preg_match_all('/<option value="\{\{ [^}]+ \}\}"([^>]*)>/', $sida2, $treff);
$utenLabel = array_values(array_filter(
    $treff[1] ?? [],
    static fn(string $rest): bool => !str_contains($rest, 'label="{{')
));
sjekk('hvert valg i et nedtrekk har en etikett Safari kan lese',
    $utenLabel === [], count($utenLabel) . ' uten label');
sjekk('… og det gjelder alle nedtrekkene', count($treff[1] ?? []) >= 28,
    count($treff[1] ?? []) . ' valg');

// ── Fast QR til disken ─────────────────────────────────────────────────
//
// Vipps sin egen faste kode vil ha en landingsside med Hurtigkasse — Vipps
// Checkout, et annet produkt enn ePayment som nettsida bruker. /betal gjor
// det samme med det vi alt har.
$betalFil = file_get_contents(dirname(__DIR__) . '/api/betal.php');
sjekk('betalingssida har sitt eget endepunkt', is_file(dirname(__DIR__) . '/api/betal.php'));
// Kunden staar i doera. Et passord der er én ting for mye.
sjekk('den krever ingen innlogging', !str_contains($betalFil, 'krev_admin()')
    && !str_contains($betalFil, 'Sesjon::krevMedlem'));
// Beloepet kommer fra nettleseren her, og bare her. Grensa per IP er det som
// staar imot soppel — det finnes ingen pris aa jukse med.
sjekk('den har en grense per IP', str_contains($betalFil, "Rate::sjekk('betal'"));
sjekk('den godtar ikke null eller smaabeloep',
    str_contains($betalFil, 'if ($sum < Vipps::MINSTE_BELOP_ORE) {'));
sjekk('… og ikke urimelig store', str_contains($betalFil, 'if ($sum > 10000000) {'));
sjekk('den bruker den vanlige betalingen, ikke kravet',
    str_contains($betalFil, 'Vipps::opprettBetaling(')
    && !str_contains($betalFil, 'true' . "\n" . '    );'));
sjekk('ordren ryddes bort naar betalingen ikke ble noe av',
    substr_count($betalFil, 'DELETE FROM orders WHERE id = :o') === 2);
sjekk('salget staar som «ny» til pengene er inne',
    str_contains($betalFil, "'status'       => 'ny',"));
sjekk('sida har sin egen adresse', str_contains($sida2, "{ sti: '/betal',         side: 'betal' },"));
sjekk('sida er koblet opp', str_contains($sida2, "erBetal: side === 'betal',")
    && str_contains($sida2, 'btBetal: () => {'));
sjekk('kassa kan vise den faste koden',
    str_contains($sida2, 'utFastLag:') && str_contains($sida2, '{{ utFastBilde }}'));
// Skrev vi ut sida slik den staar, kom hele kassa med.
sjekk('utskriften tar bare koden, ikke hele kassa',
    str_contains($sida2, "utFastSkrivUt:") && str_contains($sida2, "window.open('', '_blank'"));

// ── Vippskrav i den raske ruta ─────────────────────────────────────────
//
// Den enkle boksen nederst i deltakerlista tok navn, telefon og Vipps/Kontant.
// Eieren, 29. august: «vi vil ha vipps og betaling ja».
sjekk('den raske ruta kan sende vippskrav',
    str_contains($sida2, "klNyDBetValg: ['Vipps', 'Kontant', 'Vippskrav']"));
sjekk('… og har et belopsfelt naar den skal det',
    str_contains($sida2, 'klNyDErKrav:') && str_contains($sida2, 'settKlNyDBelop:'));
sjekk('… og sier at nummeret er adressen kravet gaar til',
    str_contains($sida2, "'Mobil — kravet går hit' : 'Telefon'"));
sjekk('… og stopper et krav uten nummer eller belop',
    str_contains($sida2, "if (krav && tlf === '') {")
    && str_contains($sida2, 'if (krav && !(parseInt(belop, 10) > 0)) {'));

// ── Klikk paa en hendelse i kalenderen ─────────────────────────────────
//
// «data-evt» paa den samme knappen som en bundet handler fikk motoren til aa
// skrive ut onclick som teksten «h.velg». Hvert klikk endte i «h is not
// defined», og ingen av de fire visningene aapnet noe. Id-en gjor det samme
// for dra-og-slipp, uten aa velte handleren.
sjekk('hendelsene i kalenderen har en id, ikke et data-felt',
    str_contains($sida2, 'id="{{ h.domId }}"') && !str_contains($sida2, 'data-evt="{{ h.id }}"'));
sjekk('dra-og-slipp finner hendelsen paa id-en',
    str_contains($sida2, "indexOf('kalevt-') === 0"));
// Uke og dag hadde bare onMouseDown. Et klikk uten dra traff ingenting.
sjekk('et klikk aapner okta i alle visningene',
    substr_count($sida2, 'id="{{ h.domId }}" onClick="{{ h.velg }}"') === 3);

echo "\n";
echo str_repeat('─', 46), "\n";
echo $ok, " av ", $ok + count($feil), " sjekker gikk gjennom\n";
if ($feil) { echo "\nFEIL:\n - ", implode("\n - ", $feil), "\n"; exit(1); }
