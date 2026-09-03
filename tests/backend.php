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
sjekk('kurs er publisert', count($kurs) >= 8, count($kurs) . ' stk');
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
// Temaet het «Plateteknikk» til 30. august. Da ble kategoriene ryddet:
// plateteknikk og workshop er det samme haandverket, og heter naa
// «Haandbygging» — se migrasjon 099. Begge godtas, saa testen virker enten
// migrasjonen er kjort eller ikke.
sjekk('bollekurset staar under Håndbygging',
    $boller !== null && in_array((string) $boller['tema'], ['Håndbygging', 'Plateteknikk'], true),
    (string) ($boller['tema'] ?? ''));
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
echo "\n== Kapasitet ==\n";
// Testen laante en oekt fra katalogen (Paint on Pots). Da verkstedet endret
// datoene sine, sto kurset uten oekter — og testen sammenlignet null med null
// og meldte «gronn». En test som gaar gronn paa manglende data, tester
// ingenting. Riggen lages her og ryddes bort av nullstill().
// Oektene under staar ti dager fram, klokka 10:00 UTC — ikke paa klokkeslettet
// testene kjores. Uten sluttid regner Apent tre timer, og kjorte man suiten
// etter klokka ni om kvelden, spilte den antatte slutten over midnatt. Da slo
// en helt annen sjekk feil — den om at dag to av et flerdagerskurs ikke skal
// aapne ved midnatt — og feilen fantes bare om kvelden.
$kapKurs = DB::settInn('courses', ['slug'=>'testkapasitet','tittel'=>'Testkapasitet','type'=>'kurs','pris_ore'=>69000,'kapasitet'=>12,'status'=>'publisert']);
$oktId = DB::settInn('course_sessions', ['course_id'=>$kapKurs,'start_tid'=>gmdate('Y-m-d', time()+864000) . ' 10:00:00','kapasitet'=>12]);
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

// ── «Vis som fullbooket» ───────────────────────────────────────────────
//
// Eieren, 3. september: «pillen paa kortet som viser hvor mange plasser det
// er, denne vil jeg ha mulighet til aa overstyre med en hake paa kortet saa
// det staar fullbooket eller fult eller saa klart likt som de andre fulle
// kursene».
//
// Regelen maa gjelde begge veier inn i regnestykket — samlekallet og det ene
// og ene — for kurslista bruker det foerste og bookingsperren det andre. Sier
// de to forskjellige ting, viser nettsida «Fullbooket» paa en dato som
// likevel kan bookes.
echo "\n== Vis som fullbooket ==\n";

// Rydd bort en rest fra en kjoring som stoppet underveis. Slug-en er unik.
DB::kjor("DELETE cs FROM course_sessions cs JOIN courses c ON c.id = cs.course_id WHERE c.slug = 'testvisfullt'");
DB::kjor("DELETE FROM courses WHERE slug = 'testvisfullt'");

$vfKurs = DB::settInn('courses', ['slug'=>'testvisfullt','tittel'=>'Testvisfullt','type'=>'kurs',
    'pris_ore'=>69000,'kapasitet'=>8,'status'=>'publisert']);
$vfOkt = DB::settInn('course_sessions', ['course_id'=>$vfKurs,
    'start_tid'=>gmdate('Y-m-d', time()+864000) . ' 10:00:00','kapasitet'=>8]);

sjekk('okta har alle plassene for haken settes', Booking::ledigePlasser($vfOkt) === 8,
    'fikk ' . Booking::ledigePlasser($vfOkt));

DB::oppdater('course_sessions', ['vis_fullt' => 1], ['id' => $vfOkt]);
sjekk('haken gir null ledige, ett og ett', Booking::ledigePlasser($vfOkt) === 0,
    'fikk ' . Booking::ledigePlasser($vfOkt));
sjekk('… og i samlekallet', Booking::ledigePlasserFlere([$vfOkt])[$vfOkt] === 0,
    'fikk ' . var_export(Booking::ledigePlasserFlere([$vfOkt])[$vfOkt] ?? null, true));

// «Sperret» ville gitt «Kurs i verkstedet» i pilla i stedet for «Fullbooket»
// — se ledigTekst() i nettsida. Her er det verkstedet selv som har sagt at
// datoen er full, og da skal den se ut som full.
Booking::ledigePlasserFlere([$vfOkt]);
sjekk('… og staar ikke som sperret av noe annet',
    Booking::sperretAvAnnet([$vfOkt])[$vfOkt] === false);

// Naboene skal ikke roeres. Overstyringa gjelder én dato.
$vfNabo = DB::settInn('course_sessions', ['course_id'=>$vfKurs,
    'start_tid'=>gmdate('Y-m-d', time()+950400) . ' 10:00:00','kapasitet'=>8]);
$vfBegge = Booking::ledigePlasserFlere([$vfOkt, $vfNabo]);
sjekk('nabodatoen staar urort', $vfBegge[$vfNabo] === 8, 'fikk ' . var_export($vfBegge[$vfNabo] ?? null, true));

DB::oppdater('course_sessions', ['vis_fullt' => 0], ['id' => $vfOkt]);
sjekk('haken av gir plassene tilbake', Booking::ledigePlasser($vfOkt) === 8,
    'fikk ' . Booking::ledigePlasser($vfOkt));

DB::kjor('DELETE FROM course_sessions WHERE course_id = :k', ['k' => $vfKurs]);
DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $vfKurs]);

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

// Proven laget seg ikke noe aa prove paa.
//
// Blokken under sto bak «finnes det et publisert kurs som foelger
// aapningstidene?», og i praksis var det Paint on Pots. Da eieren 2.
// september ba om at det kurset ikke lenger skulle foelge tidene selv, sluttet
// tolv sjekker aa kjore — stille. Koden var like mye i drift som for; det var
// bare ingen som saa etter den lenger.
//
// Naa lager proven sitt eget kurs naar verkstedet ikke har noe. Da holder den
// uansett hva som er skrudd paa eller av i admin, som en prove skal.
$midlKurs = null;
if (DB::harKolonne('course_sessions', 'fra_apningstid')
    && DB::en("SELECT id FROM courses WHERE {$folgerFelt} = 1 AND status = 'publisert'") === null) {
    $midlKurs = DB::settInn('courses', [
        'tittel'      => 'TESTAPENT — foelger aapningstidene',
        'slug'        => 'testapent',
        'type'        => 'kurs',
        'status'      => 'publisert',
        'kapasitet'   => 4,
        'pris_ore'    => 0,
        $folgerFelt   => 1,
    ]);
}

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
        // Taket gjelder per kurs. Flere kurs kan staa ute
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
    // 3. september i testdataene: Store fat 10-13, Store fat 17-20 og
    // Date Night 18-21. Dagen er aapen 10-21, og mellom 13 og 17 er
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

    DB::kjor('DELETE FROM course_sessions WHERE fra_apningstid = 1');
    Apent::leggUtPaaApneTider();
}

// Kurset proven laget seg, ryddes bort igjen. Oektene forst: de peker paa det.
if ($midlKurs !== null) {
    DB::kjor('DELETE FROM course_sessions WHERE course_id = :c', ['c' => $midlKurs]);
    DB::kjor('DELETE FROM courses WHERE id = :c', ['c' => $midlKurs]);
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
$gratisOkt = DB::settInn('course_sessions', ['course_id'=>$gratisKurs,'start_tid'=>gmdate('Y-m-d', time()+864000) . ' 10:00:00','kapasitet'=>10]);
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
$litenOkt = DB::settInn('course_sessions', ['course_id'=>$liten,'start_tid'=>gmdate('Y-m-d', time()+864000) . ' 10:00:00']);
Booking::reserverOgBetal($litenOkt, 1, 'Forste', 'a@example.com', '+4791234567', $medlemId);
try { Booking::reserverOgBetal($litenOkt, 1, 'Andre', 'b@example.com', '+4791234568', $medlemId); sjekk('siste plass kan ikke bookes to ganger', false, 'slapp gjennom'); }
catch (RuntimeException $e) { sjekk('siste plass kan ikke bookes to ganger', true, $e->getMessage()); }

echo "\n== Norsk dato og kroner ==\n";
sjekk('UTC blir norsk tid', Booking::norskDato('2026-09-02 15:30:00') === 'onsdag 2. september, 17:30', Booking::norskDato('2026-09-02 15:30:00'));
// ── Beloepet brekker ikke midt i tallet ───────────────────────────────
//
// Mellomrommene er harde (U+00A0), begge to. Med vanlige mellomrom fikk
// nettleseren lov til aa brekke linja midt i tallet: «kr. 2» paa en linje og
// «490,-» paa neste. Eieren, 1. september, med bilde av det: «ikke bryt
// beloepe 2490» og «her maa hele beloepet staa paa en linje».
//
// Det er serveren som formaterer og sender belopene ferdig som tekst, saa
// det maatte loeses her — skjermen far bare en streng.
//
// Malt i nettleseren paa 390 px: alle 40 belopene paa OEkonomi staar paa én
// linje. Med vanlig mellomrom brakk tretten av dem.
sjekk('kronebelop formateres', Booking::kroner(280000) === "kr.\u{a0}2\u{a0}800,-", Booking::kroner(280000));
sjekk('… med harde mellomrom, saa linja ikke brekker',
    !str_contains(Booking::kroner(280000), ' '));
// CSV-ene til regnskapsforeren har hver sin egen formaterer uten tusenskille
// i det hele tatt. De skal ikke faa harde mellomrom inn i tallkolonnene.
foreach (['dagsoppgjor', 'transaksjoner', 'deltakerliste'] as $csv) {
    $f = file_get_contents(dirname(__DIR__) . '/api/admin/' . $csv . '.php');
    sjekk('CSV-en i ' . $csv . ' har ikke tusenskille i det hele tatt',
        str_contains($f, "number_format(\$ore / 100, 2, ',', '')"));
}
sjekk('null kroner', Booking::kroner(0) === "kr.\u{a0}0,-", Booking::kroner(0));
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
    if ((string) ($b['tema'] ?? '') === 'Kun for medlemmer') return null;
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

/** «For N doegn siden, kl. HH:MM norsk tid» — som UTC. */
$osloTilbake = static function (int $doegn, string $kl): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))
        ->modify('-' . $doegn . ' day')
        ->setTime((int) substr($kl, 0, 2), (int) substr($kl, 3, 2))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
};

// Glemt utstempling skal lukkes, og ikke spise hele maaneden. Inn kl. 10,
// stengetid kl. 23 — tretten timer, seks telles. Eieren, spurt om hva som
// skal trekkes: «Behold taket paa 6 timer».
DB::settInn('check_ins', [
    'member_id' => $testMedlem,
    'inn_tid'   => $osloTilbake(3, '10:00'),
]);
Stempling::lukkGlemte();
$glemt = DB::en('SELECT minutter, auto_lukket FROM check_ins WHERE member_id = :m ORDER BY id DESC LIMIT 1',
    ['m' => $testMedlem]);
sjekk('glemt utstempling lukkes', (int) ($glemt['auto_lukket'] ?? 0) === 1);
sjekk('glemt okt kappes til seks timer', (int) ($glemt['minutter'] ?? 0) === 360,
    'fikk ' . var_export($glemt['minutter'] ?? null, true));

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

// ── Stengetid klokka 23 ────────────────────────────────────────────────
//
// Eieren, 2. september: «Automatisk utstemplibg kl 23».
//
// Foer var regelen en VARIGHET — ti timer aapen — saa en som stemplet inn
// klokka aatte om kvelden sto inne til seks neste morgen. Naa er det klokka
// som avgjor. Testene under maaler nettopp det skillet: de holder oekter som
// er kortere enn det gamle taket, og som likevel skal lukkes.
DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);

// Inn kl. 20 i gaar: tre timer til stengetid. Kortere enn det gamle taket paa
// ti, saa den gamle regelen ville latt den staa aapen.
$kveld = DB::settInn('check_ins', ['member_id' => $testMedlem, 'inn_tid' => $osloTilbake(1, '20:00')]);
Stempling::lukkGlemte();
$k = DB::en('SELECT ut_tid, minutter, auto_lukket FROM check_ins WHERE id = :i', ['i' => $kveld]);
sjekk('kveldsokt lukkes ved stengetid', (int) ($k['auto_lukket'] ?? 0) === 1);
sjekk('kveldsokt teller til klokka 23, ikke til taket', (int) ($k['minutter'] ?? 0) === 180,
    'fikk ' . var_export($k['minutter'] ?? null, true));
sjekk('kveldsokta lukkes klokka 23 norsk tid',
    (new DateTimeImmutable((string) $k['ut_tid'], new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i') === '23:00');

// Inn etter stengetid: da er det neste kveld som gjelder, ikke den som var.
$natt = DB::settInn('check_ins', ['member_id' => $testMedlem, 'inn_tid' => $osloTilbake(2, '23:30')]);
Stempling::lukkGlemte();
$n = DB::en('SELECT ut_tid FROM check_ins WHERE id = :i', ['i' => $natt]);
sjekk('innstemplet etter stengetid lukkes neste kveld',
    substr((string) $n['ut_tid'], 0, 16) === substr($osloTilbake(1, '23:00'), 0, 16),
    'fikk ' . var_export($n['ut_tid'] ?? null, true) . ', ventet ' . $osloTilbake(1, '23:00'));

// ── Glemt aa stemple ut: klokkeslettet man faktisk gikk ────────────────
//
// Eieren, 2. september: «Og mulighet aa legge til klokkeslett naar de faktisk
// gikk». Spurt om hvem: «Begge — medlemmet og du». Begge veier inn gaar
// gjennom denne, saa de kan ikke skille lag.
DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);
$rettes = DB::settInn('check_ins', ['member_id' => $testMedlem, 'inn_tid' => $osloTilbake(1, '10:00')]);
Stempling::lukkGlemte();
sjekk('okta staar som lukket av systemet foer rettinga',
    (int) DB::verdi('SELECT auto_lukket FROM check_ins WHERE id = :i', ['i' => $rettes]) === 1);

$r = Stempling::rettUtKlokke($testMedlem, '14:00');
sjekk('rettinga gaar gjennom', ($r['ok'] ?? false) === true, ($r['feil'] ?? ''));
sjekk('rettinga regner minutter fra innstemplinga', ($r['minutter'] ?? 0) === 240,
    'fikk ' . var_export($r['minutter'] ?? null, true));
$rr = DB::en('SELECT ut_tid, minutter, auto_lukket FROM check_ins WHERE id = :i', ['i' => $rettes]);
sjekk('rettinga setter klokkeslettet i norsk tid',
    (new DateTimeImmutable((string) $rr['ut_tid'], new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i') === '14:00');
sjekk('merket om automatisk lukking fjernes naar et menneske retter',
    (int) ($rr['auto_lukket'] ?? 1) === 0);

sjekk('rettinga avviser tull i klokkeslettet',
    (Stempling::rettUtKlokke($testMedlem, '25:00')['ok'] ?? true) === false
    && (Stempling::rettUtKlokke($testMedlem, 'i gaar')['ok'] ?? true) === false
    && (Stempling::rettUtKlokke($testMedlem, '')['ok'] ?? true) === false);

// Skrivefeil skal ikke bli et doegn. Inn kl. 10, skrevet 09:00 — det kan ikke
// vaere ni dagen etter, for da hadde verkstedet stengt for lengst.
sjekk('et klokkeslett foer innstemplinga avvises',
    (Stempling::rettUtKlokke($testMedlem, '09:00')['ok'] ?? true) === false);
sjekk('okta staar urort etter et avvist klokkeslett',
    (int) DB::verdi('SELECT minutter FROM check_ins WHERE id = :i', ['i' => $rettes]) === 240);

// Grensa gaar ved stengetid, ogsaa oppover: kom man kl. 22 og skriver 00:30,
// hadde verkstedet stengt en og en halv time for. Da er det ikke et
// klokkeslett vi kan skrive inn.
DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);
DB::settInn('check_ins', ['member_id' => $testMedlem, 'inn_tid' => $osloTilbake(1, '22:00')]);
sjekk('et klokkeslett etter stengetid avvises',
    (Stempling::rettUtKlokke($testMedlem, '00:30')['ok'] ?? true) === false);

// ... men den som stemplet inn ETTER stengetid, gikk naturligvis etter
// midnatt. Da er stengetida neste kveld, og klokkeslettet hoerer til der.
DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);
DB::settInn('check_ins', ['member_id' => $testMedlem, 'inn_tid' => $osloTilbake(1, '23:30')]);
$rs = Stempling::rettUtKlokke($testMedlem, '01:00');
sjekk('gikk man etter midnatt, teller det som samme okt', ($rs['minutter'] ?? 0) === 90,
    'fikk ' . var_export($rs['minutter'] ?? null, true) . ' ' . ($rs['feil'] ?? ''));

// Taket staar. Eieren, spurt om hva som skal trekkes: «Behold taket paa 6
// timer». Inn kl. 10, gikk kl. 22 — tolv timer, seks telles.
DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);
DB::settInn('check_ins', ['member_id' => $testMedlem, 'inn_tid' => $osloTilbake(1, '10:00')]);
sjekk('rettinga holder seg innenfor taket paa seks timer',
    (Stempling::rettUtKlokke($testMedlem, '22:00')['minutter'] ?? 0) === 360);

// ── Vinduet for rettinga ───────────────────────────────────────────────
//
// Rettinga fjerner merket om automatisk lukking — det var jo et menneske som
// satte klokkeslettet. Sto regelen paa det merket alene, kunne feltet bare
// brukes én gang, og den som skrev 15:00 i stedet for 16:00 satt igjen med
// feilen. Derfor gaar vinduet paa naar oekta tok slutt.
DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);
DB::settInn('check_ins', ['member_id' => $testMedlem, 'inn_tid' => $osloTilbake(1, '10:00')]);
Stempling::lukkGlemte();
sjekk('rettinga kan gjores om igjen',
    (Stempling::rettUtKlokke($testMedlem, '15:00')['minutter'] ?? 0) === 300
    && (Stempling::rettUtKlokke($testMedlem, '16:00')['minutter'] ?? 0) === 360,
    'andre forsok: ' . var_export(Stempling::rettUtKlokke($testMedlem, '16:00'), true));

// En oekt fra i forfjor er ikke «jeg glemte aa stemple ut» lenger.
DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);
DB::settInn('check_ins', [
    'member_id' => $testMedlem,
    'inn_tid'   => $osloTilbake(4, '10:00'),
    'ut_tid'    => $osloTilbake(4, '13:00'),
    'minutter'  => 180,
]);
sjekk('en gammel okt kan ikke rettes', (Stempling::sisteOkt($testMedlem)['kanRettes'] ?? true) === false);
sjekk('… og endepunktet slipper den heller ikke gjennom',
    (Stempling::rettUtKlokke($testMedlem, '14:00')['ok'] ?? true) === false);
sjekk('… og lar minuttene staa', (int) DB::verdi(
    'SELECT minutter FROM check_ins WHERE member_id = :m ORDER BY id DESC LIMIT 1',
    ['m' => $testMedlem]) === 180);

// En apen okt kan alltid rettes, uansett hvor lenge den har staatt.
DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);
DB::settInn('check_ins', ['member_id' => $testMedlem, 'inn_tid' => $osloTilbake(9, '10:00')]);
sjekk('en apen okt kan alltid rettes', (Stempling::sisteOkt($testMedlem)['kanRettes'] ?? false) === true);

// Ingen oekt aa rette: en beskjed, ikke en krasj.
DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);
sjekk('retting uten en okt gir beskjed',
    (Stempling::rettUtKlokke($testMedlem, '14:00')['ok'] ?? true) === false);
sjekk('siste okt er null naar det ikke finnes noen', Stempling::sisteOkt($testMedlem) === null);

// Skjermen leser «siste»: den bestemmer om «Glemt aa stemple ut» staar der.
$s1 = DB::settInn('check_ins', ['member_id' => $testMedlem, 'inn_tid' => $osloTilbake(0, '09:00')]);
$so = Stempling::sisteOkt($testMedlem);
sjekk('siste okt peker paa den apne', ($so['id'] ?? 0) === $s1 && $so !== null && $so['ut_tid'] === null);
sjekk('en apen okt er ikke merket som automatisk lukket', ($so['auto'] ?? true) === false);
DB::kjor('DELETE FROM check_ins WHERE member_id = :m', ['m' => $testMedlem]);

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
$medlem  = ['pris_ore' => 259000, 'tema' => 'Medlemskap',  'type' => 'kurs',    'slug' => 'medlem',    'tittel' => 'Medlemskap'];

sjekk('én plass gir ingen rabatt', Booking::rabattProsent($vanlig, 1) === 0.0);
sjekk('to plasser gir ingen rabatt naar nivaaet er tre', Booking::rabattProsent($vanlig, 2) === 0.0);
sjekk('tre plasser gir ti prosent', Booking::rabattProsent($vanlig, 3) === 10.0);
sjekk('fem plasser gir femten prosent', Booking::rabattProsent($vanlig, 5) === 15.0);

// Flere nivaaer kan treffe samtidig. Det beste for kunden skal gjelde.
sjekk('dreiekurs faar det beste nivaaet', Booking::rabattProsent($dreie, 3) === 20.0);

// Et inaktivt nivaa skal ikke telle, selv om det passer.
sjekk('inaktivt nivaa teller ikke', Booking::rabattProsent($vanlig, 4) === 10.0);

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

// Tallet sto som «minst fire». Da var det bundet til hvor mange planer som
// tilfeldigvis laa ute, og proven falt den dagen en av dem ble tatt ut av
// salg. Paastanden er at planene kommer fra basen — og at bare de i salg
// kommer med.
sjekk('planene ligger i basen',
    Medlemskap::planer() !== []
    && count(Medlemskap::planer())
       === (int) DB::verdi('SELECT COUNT(*) FROM membership_plans WHERE aktiv = 1'));
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
// Alle de andre planene i salg lar medlemmet velge selv. Sto navnene i en
// liste her, ble proven roed den dagen en plan ble tatt ut av salg — og det
// er en helt lovlig ting aa gjore. Naa gaar den paa planene som faktisk
// ligger ute.
foreach (Medlemskap::planer() as $pl) {
    $fritt = (string) $pl['navn'];
    if ($fritt === 'Årsmedlemskap') {
        continue;
    }
    sjekk('«' . $fritt . '» lar medlemmet velge', !Medlemskap::kreverFastTrekk($pl));
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

// Samme grunn som over: planene som ligger ute, ikke en fast navneliste.
//
// Tallene er eierens egne medlemsvilkaar, 2. september:
//   «Provemedlemskapet har ingen bindingstid» og «kan avsluttes uten
//    oppsigelsestid»
//   «Aarsmedlemskap har 12 maaneders bindingstid ... deretter 1 maaneds
//    oppsigelsestid»
//   «Alle ovrige medlemskap har 2 maaneders bindingstid ... 1 maaneds
//    oppsigelsestid»
//
// Sto proveperioden med to maaneders binding i basen, som den gjorde for
// migrasjon 133, ville hvorforIkkeSiOpp() nektet noen aa avslutte et
// medlemskap de har full rett til aa gaa ut av naar de vil.
foreach (Medlemskap::planer() as $pl) {
    $navn = (string) $pl['navn'];
    [$bind, $opps] = $navn === 'Prøv Lissom' ? [0, 0]
        : ($navn === 'Årsmedlemskap' ? [12, 1] : [2, 1]);
    sjekk('«' . $navn . '» har ' . $bind . ' maaneders binding',
        (int) $pl['binding_mnd'] === $bind, (string) ($pl['binding_mnd'] ?? '?'));
    sjekk('«' . $navn . '» har ' . $opps . ' maaneders oppsigelse',
        (int) $pl['oppsigelse_mnd'] === $opps, (string) ($pl['oppsigelse_mnd'] ?? '?'));
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

    // Verkstedets standard laanes, og leveres tilbake nederst. Uten dette
    // sto basen igjen uten standard etter hver kjoring av proven — og da
    // legger aapent verksted ut datoer uten kursholder.
    $forStandard = DB::verdi('SELECT id FROM kursholdere WHERE standard = 1 LIMIT 1');

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
    DB::kjor('UPDATE kursholdere SET standard = 0');
    if ($forStandard !== null) {
        DB::kjor('UPDATE kursholdere SET standard = 1 WHERE id = :i', ['i' => (int) $forStandard]);
    }
})();

// Endepunktet skal avvise en kursholder som ikke finnes — ellers ville datoen
// pekt paa noe som ikke er der, og navnet blitt borte uten forklaring.
$kursFil = file_get_contents(dirname(__DIR__) . '/api/admin/kurs.php');
sjekk('kurs.php slaar opp kursholderen for den lagres',
    str_contains($kursFil, "Svar::feil('Fant ikke kursholderen.')"));
sjekk('en ny dato foreslaar verkstedets standard',
    str_contains($kursFil, 'Kursholder::forKurs($kursId)')
    && str_contains(
        file_get_contents(dirname(__DIR__) . '/app/lib/kursholder.php'),
        "SELECT id FROM kursholdere WHERE standard = 1 AND aktiv = 1"
    ));
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
// Paint on Pots ble lagt ut automatisk paa hver eneste aapningstid
// (migrasjon 076). Det er tilbud — «her kan noen komme» — ikke noe som skjer.
// Kalenderabonnementet tok med hver av dem, og telefonen til eieren fylte seg
// med tomme oppforinger: 25 Paint on Pots i basen her, ingen med
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
// ogsaa de Paint on Pots-tidene som legges ut automatisk paa hver
// aapningstid. Setter noen en kursholder paa dem, ville verkstedet ikke
// kunnet legge et kurs paa sine egne aapne kvelder. Det er nettopp da de skal
// settes opp: doeren er aapen og noen er der.
$kursFil2 = file_get_contents(dirname(__DIR__) . '/api/admin/kurs.php');
sjekk('en tom aapningstid gjor ikke kursholderen opptatt',
    str_contains($kursFil2, "\$apenKol[] = 'cs.fra_apningstid = 1';")
    && str_contains($kursFil2, '"AND (NOT (" . implode(\' OR \', $apenKol) . ")'));
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
// («Type: Kurs, event eller workshop»), og som ingen knapp aapnet.
// Begge funksjonene finnes for ekte naa, saa beskrivelsene er bare i veien.
sjekk('attrappdialogene «Ny serie» og «Endre aapningstider» er borte',
    !str_contains($sida, 'aNySerie:') && !str_contains($sida, 'aEndreTider:'));
// Den ekte serien lages fra «Ny kursdato» med en gjentakelse, og kurs.php
// tar imot den.
sjekk('kursserien lages for ekte',
    str_contains($sida, "handling: 'serie',")
    && str_contains(file_get_contents(dirname(__DIR__) . '/api/admin/kurs.php'), "case 'serie':"));
// Aapningstidene redigeres for ekte fra kalenderen. En egen skjerm var
// den andre veien inn; den er borte — se docs/DROP-IN.md.
sjekk('aapningstidene redigeres for ekte',
    str_contains($sida, "this.klKall('/api/admin/apningstider.php'"));

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
                : Kursholder::forKurs(\$kursId);"));

// ── Hvordan gikk maanedstrekket? ─────────────────────────────────────────
//
// Trekket ble bedt om, raden ble lagt i payments med «venter» — og der ble
// den staaende for alltid. Trekk-ID-en fra Vipps ble kastet, saa det fantes
// ingen vei tilbake for aa sporre. Regnskapet viste null betalte maanedstrekk
// uansett hvor mange som gikk gjennom, og de to malene «Medlemskapet ditt er
// fornyet» og «Vi fikk ikke trukket betalingen» sto i basen uten avsender.
$medlFil = file_get_contents(dirname(__DIR__) . '/app/lib/medlemskap.php');
sjekk('trekk-id-en fra Vipps tas vare paa',
    str_contains($medlFil, "\$trekkId = Vipps::belastAvtale(")
    && str_contains($medlFil, "'vipps_psp_ref' => \$trekkId !== '' ? \$trekkId : null,"));
sjekk('… og et trekk slaas opp under avtalen sin, ikke som en ePayment',
    str_contains(file_get_contents(dirname(__DIR__) . '/app/lib/vipps.php'),
                 "'/charges/' . rawurlencode(\$trekkId)"));
sjekk('CHARGED gjor raden betalt og sender kvitteringen',
    str_contains($medlFil, "if (\$status === 'CHARGED') {")
    && str_contains($medlFil, "Varsel::mal('medlemskap_fornyet'"));
sjekk('FAILED sier fra til medlemmet',
    str_contains($medlFil, "if (\$status === 'FAILED' || \$status === 'CANCELLED') {")
    && str_contains($medlFil, "Varsel::mal('betaling_feilet'"));
$cronFil = file_get_contents(dirname(__DIR__) . '/bin/cron.php');
sjekk('cron sporr om trekkene som ikke har fatt svar',
    str_contains($cronFil, 'foreach (Medlemskap::trekkUtenSvar() as $p) {')
    && str_contains($cronFil, 'Medlemskap::sjekkTrekk($p)'));
// ePayment-oppslaget ga 404 paa hvert eneste maanedstrekk, hvert femte
// minutt, og skrev en linje i feilloggen hver gang.
sjekk('… og ePayment-oppslaget lar maanedstrekkene vaere',
    str_contains($cronFil, "AND type <> 'recurring_charge'"));

// Og selve utvalget: bare trekk med en id aa sporre paa, og bare de som ikke
// har gjort opp for seg.
(static function (): void {
    $medlemId = DB::verdi('SELECT id FROM members ORDER BY id LIMIT 1');
    if ($medlemId === null) {
        sjekk('trekkutvalget krever et medlem i basen', false, 'ingen medlemmer');
        return;
    }
    $abo = DB::settInn('subscriptions', [
        'member_id' => (int) $medlemId, 'plan' => 'Proveplan',
        'pris_ore' => 50000, 'status' => 'aktiv',
        'vipps_agreement_id' => 'agr_' . bin2hex(random_bytes(4)),
        'neste_trekk' => gmdate('Y-m-d', time() + 86400 * 30),
    ]);
    $legg = static function (int $abo, int $medlem, string $type, ?string $psp, string $status): int {
        return DB::settInn('payments', [
            'vipps_reference' => 'PROVE-' . bin2hex(random_bytes(5)),
            'type' => $type, 'formal' => 'medlemskap',
            'member_id' => $medlem, 'subscription_id' => $abo,
            'belop_ore' => 50000, 'status' => $status,
            'vipps_psp_ref' => $psp,
            'idempotency_key' => Vipps::uuid(),
        ]);
    };
    $med    = $legg($abo, (int) $medlemId, 'recurring_charge', 'chg_1', 'venter');
    $uten   = $legg($abo, (int) $medlemId, 'recurring_charge', null, 'venter');
    $ferdig = $legg($abo, (int) $medlemId, 'recurring_charge', 'chg_2', 'betalt');
    $annet  = $legg($abo, (int) $medlemId, 'epayment', 'chg_3', 'venter');

    $funnet = array_map(static fn(array $r): int => (int) $r['id'], Medlemskap::trekkUtenSvar(200));
    sjekk('et trekk som venter paa svar hentes',        in_array($med, $funnet, true));
    sjekk('… men ikke et uten trekk-id aa sporre paa',  !in_array($uten, $funnet, true));
    sjekk('… og ikke et som alt har gjort opp for seg', !in_array($ferdig, $funnet, true));
    sjekk('… og ikke en vanlig betaling',               !in_array($annet, $funnet, true));
    // Avtalen og medlemmet folger med, saa meldingen vet hvem den gaar til.
    $rad = null;
    foreach (Medlemskap::trekkUtenSvar(200) as $r) {
        if ((int) $r['id'] === $med) { $rad = $r; }
    }
    sjekk('raden vet hvilken avtale og hvilket medlem den hoerer til',
        $rad !== null && ($rad['vipps_agreement_id'] ?? '') !== '' && array_key_exists('epost', $rad));

    DB::kjor('DELETE FROM payments WHERE subscription_id = :s', ['s' => $abo]);
    DB::kjor('DELETE FROM subscriptions WHERE id = :s', ['s' => $abo]);
})();

// ── Regelen staar ett sted, og alle fire bruker den ──────────────────────
//
// Eieren, 1. september: «lag din egen bolle dukker opp i kalenderen naa, uten
// kursholder, hvordan er det mulig naar det kun er monica som er kursholder
// og default?» — og: «det gjelder saa klart ogsaa paa alle paint on pots».
//
// Fire steder lager kursdatoer. Bare «ny dato» i admin satte kursholder; de
// faste ukedagene og aapent verksted la dem ut tomme. Naa gaar alle
// fire gjennom Kursholder::forKurs().
(static function (): void {
    if (!DB::harKolonne('course_sessions', 'kursholder_id')
        || !DB::harKolonne('courses', 'kursholder_id')
        || !DB::harKolonne('kursholdere', 'standard')) {
        sjekk('kursholderregelen krever migrasjon 085 og 089', false, 'kolonner mangler');
        return;
    }

    // Basen settes tilbake til slik den sto etterpaa — proven kjores mot den
    // samme basen som resten, og en standard paa avveie ville flyttet seg
    // inn i andre proever.
    $forStandard = DB::verdi('SELECT id FROM kursholdere WHERE standard = 1 LIMIT 1');

    DB::kjor('UPDATE kursholdere SET standard = 0');
    $a = DB::settInn('kursholdere', ['navn' => 'Regelholder A', 'aktiv' => 1]);
    $b = DB::settInn('kursholdere', ['navn' => 'Regelholder B', 'aktiv' => 1, 'standard' => 1]);
    $kursId = DB::settInn('courses', [
        'slug' => 'regelkurs-' . bin2hex(random_bytes(3)),
        'tittel' => 'Regelkurs', 'pris_ore' => 0, 'status' => 'publisert',
        'kursholder_id' => $a,
    ]);

    // 1. Den som staar paa kurset gaar foran standarden.
    Kursholder::glem();
    sjekk('kursets egen kursholder gaar foran standarden',
        Kursholder::forKurs($kursId) === $a);

    // 2. Tomt paa kurset betyr ikke «ingen» — det betyr standarden.
    DB::oppdater('courses', ['kursholder_id' => null], ['id' => $kursId]);
    Kursholder::glem();
    sjekk('tomt paa kurset gir verkstedets standard',
        Kursholder::forKurs($kursId) === $b);

    // 3. En som har sluttet teller ikke som «den som staar paa kurset».
    //
    //    Her sto oppslaget uten aa sporre om hen fortsatt holder kurs.
    //    Pekte kurset paa en som hadde sluttet, ble navnet arvet videre til
    //    hver eneste nye dato, og fallbacken til standarden slo aldri inn —
    //    feltet var jo ikke tomt. Kalenderen viser ingen som har sluttet, saa
    //    datoen sto i spalta «Uten kursholder» med noen paa seg.
    //
    //    Eieren, 1. september: «flere paint on pots kurs ligger paa kolonnen
    //    uten kursholdere» — etter at standarden var satt.
    DB::oppdater('courses', ['kursholder_id' => $a], ['id' => $kursId]);
    DB::kjor('UPDATE kursholdere SET aktiv = 0 WHERE id = :i', ['i' => $a]);
    Kursholder::glem();
    sjekk('en kursholder som har sluttet arves ikke videre',
        Kursholder::forKurs($kursId) === $b,
        var_export(Kursholder::forKurs($kursId), true));
    DB::kjor('UPDATE kursholdere SET aktiv = 1 WHERE id = :i', ['i' => $a]);
    DB::oppdater('courses', ['kursholder_id' => null], ['id' => $kursId]);
    Kursholder::glem();
    // Og oppslaget maa faktisk sporre om det, ikke bare lese feltet.
    sjekk('… fordi oppslaget sporr om hen fortsatt holder kurs',
        str_contains(file_get_contents(dirname(__DIR__) . '/app/lib/kursholder.php'),
                     'JOIN kursholdere k ON k.id = c.kursholder_id AND k.aktiv = 1'));

    // 4. Er ingen standard, staar datoen tom. Da skal ingen faa et
    //    tilfeldig navn paa seg.
    DB::kjor('UPDATE kursholdere SET standard = 0 WHERE id = :i', ['i' => $b]);
    Kursholder::glem();
    sjekk('uten standard staar datoen tom framfor aa gjette',
        Kursholder::forKurs($kursId) === null);

    // 5. Og de faste ukedagene bruker den samme regelen. Dette er det stedet
    //    som la ut flest tomme datoer — Paint on Pots gaar hver uke.
    DB::kjor('UPDATE kursholdere SET standard = 1 WHERE id = :i', ['i' => $b]);
    Kursholder::glem();
    if (DB::harTabell('kurs_serier')) {
        DB::settInn('kurs_serier', [
            'course_id' => $kursId, 'ukedag' => 3,
            'fra' => '18:00', 'til' => '20:00',
            'uker_fram' => 2, 'aktiv' => 1,
        ]);
        Serier::fyllPaa($kursId);
        $tomme = (int) DB::verdi(
            'SELECT COUNT(*) FROM course_sessions WHERE course_id = :k AND kursholder_id IS NULL',
            ['k' => $kursId]
        );
        $laget = (int) DB::verdi(
            'SELECT COUNT(*) FROM course_sessions WHERE course_id = :k',
            ['k' => $kursId]
        );
        sjekk('faste ukedager lages med kursholder',
            $laget > 0 && $tomme === 0, $laget . ' datoer, ' . $tomme . ' uten');
        DB::kjor('DELETE FROM kurs_serier WHERE course_id = :k', ['k' => $kursId]);
    }

    DB::kjor('DELETE FROM course_sessions WHERE course_id = :k', ['k' => $kursId]);
    DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kursId]);
    DB::kjor('DELETE FROM kursholdere WHERE id IN (:a, :b)', ['a' => $a, 'b' => $b]);
    DB::kjor('UPDATE kursholdere SET standard = 0');
    if ($forStandard !== null) {
        DB::kjor('UPDATE kursholdere SET standard = 1 WHERE id = :i', ['i' => (int) $forStandard]);
    }
    Kursholder::glem();
})();

// Og en femte vei inn skal ikke kunne gli forbi: hver fil som lager en
// kursdato maa nevne kursholderen. Uten dette sto tre av fire stille i tre
// maaneder — koden gjorde ingenting galt, den gjorde bare ingenting.
$lagerDatoer = [];
$utenHolder  = [];
foreach (glob(dirname(__DIR__) . '/{api,api/admin,app/lib}/*.php', GLOB_BRACE) as $f) {
    $kode = file_get_contents($f);
    if (!preg_match('/INSERT[^;]{0,80}INTO course_sessions|settInn\(\s*\'course_sessions\'/', $kode)) {
        continue;
    }
    $lagerDatoer[] = basename($f);
    if (!str_contains($kode, 'kursholder_id')) {
        $utenHolder[] = basename($f);
    }
}
sjekk('alle stedene som lager kursdatoer er funnet',
    count($lagerDatoer) >= 3, implode(', ', $lagerDatoer));
sjekk('… og hvert av dem setter kursholder',
    $utenHolder === [], implode(', ', $utenHolder));
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
// Tallet er summen av skjermer med fanerad. Det gikk fra 15 til 16 da
// GEO-skjermen kom til — den har fanerekka som SEO-skjermen har.
sjekk('ventelisteskjermen har fanerekka, saa den ikke blir en blindvei',
    substr_count($sida, '{{ harOmrFaner }}') === 16);
// Oversikt var blitt en oppslagstavle med fjorten kort. Eieren, 29. august,
// pekte ut fire som skulle bort: «Kursadministrasjon», «Meld noen paa»,
// «Intern side» og programlista. Skjermene naas fra menyen som for — det er
// snarveiene som er borte, ikke funksjonene.
foreach (['Kursadministrasjon', 'Meld noen på', 'Intern side'] as $vekk) {
    sjekk('«' . $vekk . '» staar ikke lenger paa Oversikt',
        !str_contains($sida, "kort('" . $vekk . "',"));
}
sjekk('programlista er ute av Oversikt', !str_contains($sida, '{{ ovProgramValg }}'));
// Kortet «Programmet paa telefonen» skulle ogsaa bort (eieren, 29. august).
// Abonnementet fantes bare der, saa det er flyttet til Kalender-skjermen —
// fjernet fra Oversikt, men ikke borte.
sjekk('kortet «Programmet paa telefonen» er ute av Oversikt',
    !str_contains($sida, 'Programmet på telefonen'));
sjekk('… og kalenderabonnementet staar paa Kalender i stedet',
    str_contains($sida, '{{ kalKnapp }}')
    && strpos($sida, '{{ kalKnapp }}') < strpos($sida, 'data-screen-label="Admin – oversikt"'));
// Kortet skal staa der ogsaa naar ingen skylder — et kort som bare finnes
// noen dager er ikke et kort man ser etter.
sjekk('«Ikke betalt» staar alltid, med en tom tilstand',
    str_contains($sida, 'ovSkylderVis: true,')
    && str_contains($sida, 'Alle har gjort opp for seg.'));
// Roedt hver dag naar alt er gjort opp slutter man aa se etter.
sjekk('… men er bare roedt naar noen faktisk skylder',
    str_contains($sida, "liste.length ? 'var(--terracotta-500)' : 'var(--border-subtle)'"));

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
    // Maaned, uke og dag i kalenderen — og maaneden under Planlagte kurs.
    // Alle fire er flere spalter enn en telefon er bred.
    substr_count($sida, 'class="lx-kalbred"') === 4
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
// Regelen fikk et ledd til 1. september: spalta for det som ikke er tildelt
// noen staar aldri fast — se «Kalenderen: to feil som gjemte kurs».
sjekk('den som vanligvis holder kursene staar som spalte',
    str_contains($sida, 'klStandardHolder()')
    && str_contains($sida, 'const staarFast = kn => kn === stdHolder && kn !== UTEN_HOLDER;'));
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
sjekk('plassen staar som reservert til den er gjort opp',
    str_contains($pamFil, "in_array(\$maate, ['Betaler ved oppmøte', 'Vippskrav', 'Ikke betalt'], true)"));
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
    str_contains($sida2, "return ['Kontant', 'Vipps', 'Gavekort', 'Ikke betalt', 'Gratis'];"));

// ── Ikke betalt ────────────────────────────────────────────────────────
//
// Eieren: «legg til ikke betalt paa alle manuelle betalinger», og «lag et
// kort som varsler ikke betalt saa kan jeg kreve inn betaling fra dette
// kortet».
$ovFil = file_get_contents(dirname(__DIR__) . '/api/admin/oversikt.php');
sjekk('Oversikt vet om de ubetalte',
    // Kursplassene som for, men slaatt sammen med medlemskapene: kortet
    // holder begge slag siden eieren spurte om dem 2. september.
    str_contains($ovFil, "'ubetalte' => array_merge(array_map("));
// En nettbestilling som staar som reservert venter paa Vipps og ordner seg
// selv. Bare det som er lagt inn for haand skal staa paa kortet.
sjekk('… og bare de som er lagt inn for haand',
    str_contains($ovFil, 'AND b.lagt_inn_av IS NOT NULL'));
sjekk('kortet staar paa Oversikt', str_contains($sida2, '{{ ovSkylderSum }}')
    && str_contains($sida2, '<sc-for list="{{ ovSkylder }}" as="u"'));
// «ovUbetalte» var et tall fra for. renderVals gir ett flatt objekt, saa det
// samme navnet to steder gjor at den siste vinner — og sc-for-en gikk over et
// tall og tegnet ingenting.
sjekk('kortet har sitt eget navn, ikke et som var tatt',
    substr_count($sida2, 'ovSkylder:') === 1
    && !str_contains($sida2, 'ovUbetalte: liste'));
// Uten maaten staar plassen som betalt uten at noe sier hvor pengene kom fra.
sjekk('maaten foelger med naar den kreves inn fra kortet',
    str_contains($pamFil, "if (\$status === 'betalt' && in_array(\$nyMaate, MAATER, true)) {"));
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

// ── Aapningstidene samlet til én linje ─────────────────────────────────
//
// Paint on Pots foelger aapningstida, og den klippes i plasser paa halvannen
// time. En aapen dag ble til seks like rader i kalenderen. Eieren, 29.
// august: «jeg vil ikke at det skal splittes».
$kalFil3 = file_get_contents(dirname(__DIR__) . '/api/admin/kalender.php');
sjekk('kalenderen faar vite hvilke oekter en regel har laget',
    str_contains($kalFil3, "'auto'   => \$harAuto && (int) \$o['fra_apningstid'] === 1,"));
sjekk('kolonnen kan mangle uten at kalenderen doer',
    str_contains($kalFil3, "DB::harKolonne('course_sessions', 'fra_apningstid')"));
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
    str_contains($sida2, "const detalj = e => [e.samling || '', e.publisert === false ? 'Ikke publisert' : '', e.holder"));
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
// <sc-for> inne i <select> er ikke gyldig HTML. Standarden sier at en ukjent
// start-tag inne i et nedtrekk skal kastes, og Safari gjor det: loekka
// forsvinner, og med den valgene. Chrome tolererer det, saa feilen fantes
// bare paa telefonen. Eieren 30. august, om Monicas admin: «naar hun forsoker
// aa legge inn deltaker og skal velge betalingsmetode saa faar hun opp den
// gamle visningen som er tom».
//
// Foerste forsok var «label» paa hvert valg. Det var feil sted: valgene kom
// aldri saa langt. Naa er det brikker — vanlige knapper i en div — overalt.
preg_match_all('/<select\b[^>]*>.*?<\/select>/s', $sida2, $selects);
$medLokke = array_values(array_filter(
    $selects[0] ?? [],
    static fn(string $blokk): bool => str_contains($blokk, '<sc-for')
));
sjekk('ingen nedtrekk har en løkke inni seg', $medLokke === [],
    count($medLokke) . ' nedtrekk med sc-for');
sjekk('… og brikkene lages ett sted',
    str_contains($sida2, 'static get NEDTREKK()')
    && str_contains($sida2, 'return this.utenNedtrekk(vals);')
    && str_contains($sida2, 'brikkeliste(liste, naa, sett) {'));
// Setteren nedtrekket hadde roeres ikke: brikka kaller den med den samme
// hendelsen. Da er det bare visningen som er byttet.
sjekk('… og setterne er de samme som for',
    str_contains($sida2, "sett({ target: { value: verdi } });"));

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

// ── Markedsforing: forhaandsvisning for alle typene ────────────────────
//
// Eieren 29. august: «jeg velger bilde, og genererer tekst — naa vil ikke
// bilde komme med». Det stemte: bare innlegg til sosiale medier hadde en
// forhaandsvisning. En artikkel og et nyhetsbrev fikk raa tekst og ingen
// bilde, selv om bildet fulgte med hele veien i basen.
sjekk('artikkelutkast har en forhaandsvisning',
    str_contains($sida2, '{{ mkLesErArtikkel }}') && str_contains($sida2, '{{ mkLesOppsettTittel }}'));
sjekk('nyhetsbrevutkast har en forhaandsvisning',
    str_contains($sida2, '{{ mkLesErBrev }}'));
sjekk('innlegg har fortsatt sin', str_contains($sida2, '{{ mkLesErSosialt }}'));
// Bildet skal staa i alle tre, ikke bare i innlegget.
sjekk('bildet tegnes i alle tre forhaandsvisningene',
    substr_count($sida2, 'data-src="{{ mkLesBilde }}"') === 3);
// Avsnittene tegnes hver for seg. «white-space: pre-wrap» ga bare linjeskift,
// og en artikkel saa ut som en lapp.
sjekk('avsnittene faar luft mellom seg',
    str_contains($sida2, 'list="{{ mkLesAvsnitt }}"'));
// Ett klikk: «jeg vil trykke publiser i artikkelen saa publiseres den».
sjekk('«Publiser naa» staar i selve utkastet',
    str_contains($sida2, 'on-click="{{ mkLesPubliser }}"'));
sjekk('… og publiserer i samme kall som godkjenningen',
    str_contains($sida2, "handling: 'godkjenn', id: l.id, publiser: true"));
// Sperra sto foran alt og sa «AI-en svarer forst naar siden kjorer paa
// serveren» — ogsaa for godkjenn og publiser, som ikke sporr AI-en om noe.
sjekk('godkjenn og publiser stoppes ikke av AI-sperra',
    str_contains($sida2, 'if (!laget && !this.erPublisert()) {'));
// Et ferskt utkast staar ikke i lista enda. Sto det «return» her, aapnet
// det seg ikke — og da var bildet borte i det oyeblikket det gjaldt.
sjekk('et ferskt utkast kan aapnes for lista er hentet',
    str_contains($sida2, '.find(x => x.id === id) || {};')
    && str_contains($sida2, 'if (!laget && d && d.id) this.mkLes(d.id);'));

// Overskriften er UNIQUE i articles. To utkast om det samme ga hele
// SQLSTATE-feilen paa skjermen, og ingenting ble publisert.
sjekk('en dublett-overskrift gir et tall bak, ikke en SQL-feil',
    str_contains(file_get_contents(__DIR__ . '/../app/lib/artikler.php'), 'public static function ledigTittel')
    && str_contains(file_get_contents(__DIR__ . '/../api/admin/ai.php'), 'Artikler::ledigTittel('));
sjekk('… og skjemaet i Kunnskapsbank sier fra i klartekst',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/artikler.php'),
                 'Det finnes allerede en artikkel som heter'));

// Emneknaggene lagres uten «#», og skjermen setter ett foran. Kom de fra
// AI-en med tegnet alt paa, sto det «##keramikk».
$marked = file_get_contents(__DIR__ . '/../api/admin/marked.php');
sjekk('emneknagger renskes for «#» naar de leses',
    str_contains($marked, "ltrim(trim((string) \$h), '#')"));
sjekk('… og naar de lagres',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/ai.php'), "ltrim(trim((string) \$h), '#')"));
// Serveren beskriver oppsettet for hver type, ikke bare for sosialt.
sjekk('serveren gir et oppsett til artikkel og brev ogsaa',
    str_contains($marked, "'slag'    => 'artikkel'") && str_contains($marked, "'slag'    => 'brev'"));

// ── Meld inn feil ──────────────────────────────────────────────────────
//
// To slag, som fanger hver sin type feil. Det tomme nedtrekket paa iPhone
// kastet ingenting — sida virket teknisk sett og manglet bare innholdet.
// Uten et menneske som sier fra, faar ingen vite om den slags.
sjekk('feilvakta fanger unntak, lovnader og serverfeil',
    str_contains($sida2, "window.addEventListener('error', this._feilLytter, true)")
    && str_contains($sida2, "window.addEventListener('unhandledrejection', this._feilLovnad)")
    && str_contains($sida2, "r.status >= 500"));
// Vakta bruker fetch selv. Melder den om sin egen melding, blir det en sloyfe.
sjekk('vakta melder ikke om sitt eget kall',
    str_contains($sida2, "adresse.indexOf('/api/feil.php') === -1"));
sjekk('… og aldri mer enn fem fra én sideinnlasting',
    str_contains($sida2, 'this._feilAntall >= 5'));
// Knappen staar tre steder, og bare naar eieren har slaatt den paa.
sjekk('«Meld inn feil» staar i bunnteksten',
    substr_count($sida2, 'onClick="{{ fmAapne }}"') === 2);
sjekk('… og i adminmenyen', str_contains($sida2, "navn: '⚑  Meld inn feil',"));
sjekk('… bak bryteren, ikke alltid',
    substr_count($sida2, '{{ fmKnappVis }}') === 2);
sjekk('skjemaet ligger utenfor skjermene, saa det virker overalt',
    str_contains($sida2, '{{ fmVises }}') && str_contains($sida2, '{{ fmOmraadeStil }}'));
// Skjermen som viser dem, og veien til aa lime dem inn.
sjekk('feilmeldingsskjermen finnes med kopiknapp og bryter',
    str_contains($sida2, 'data-screen-label="Admin – feilmeldinger"')
    && str_contains($sida2, 'on-click="{{ frKopier }}"')
    && str_contains($sida2, 'on-click="{{ frSlaaPaa }}"'));
sjekk('… og staar under Nettsiden', str_contains($sida2, "['Feilmeldinger', 'adminfeil'],"));
sjekk('kortet paa Oversikt staar bare naar noe venter',
    str_contains($sida2, "kort('Feil meldt inn',"));
// Endepunktene.
$fapi = file_get_contents(__DIR__ . '/../api/feil.php');
sjekk('api/feil.php lagrer ikke IP-adressen', !str_contains($fapi, "'ip'"));
sjekk('… og tar nettleseren fra hodet, ikke fra kroppen',
    str_contains($fapi, 'Foresporsel::userAgent()'));
sjekk('… og har egne grenser for menneske og maskin',
    str_contains($fapi, "Rate::sjekk('feilmelding'") && str_contains($fapi, "Rate::sjekk('feilfangst'"));
sjekk('… og teller opp den samme feilen framfor aa lage en rad til',
    str_contains($fapi, 'ON DUPLICATE KEY UPDATE'));
sjekk('en melding krever at bryteren staar paa',
    str_contains($fapi, 'innmelding av feil er stengt akkurat nå.'));

// ── Hvert kort paa Oversikt maa ha en knapp som gaar et sted ───────────
//
// Kortet «Maler» ble lagt ut med argumentene i feil rekkefolge:
//
//   kort(navn, hva, tall, knapp, velg, haster)
//   kort('Maler', '…', null, 'adminmaler', {}, 'Se malene →')
//
// Ruta havnet der knappeteksten skulle staa, og der klikket skulle staa laa
// et tomt objekt. Eieren, 1. september: «ser ingen mal paa oversikten».
//
// Hjelperen tar imot hva som helst, og hverken knappesjekk eller
// metodesjekk ser forskjell paa en funksjon og et objekt. Denne gjor det:
// hvert kort skal ha et kall som faktisk gaar et sted.
(static function () use ($sida2): void {
    // Blokka mellom hjelperen og lista si slutt.
    $fra = strpos($sida2, 'const kort = (navn, hva, tall, knapp, velg, haster) => ({');
    if ($fra === false) {
        sjekk('fant kortlista paa Oversikt', false, 'hjelperen er borte eller endret');
        return;
    }
    $blokk = substr($sida2, $fra, 40000);

    // Hvert kort('…')-kall, med parentesene talt saa nostede kall folger med.
    $uten = [];
    $antall = 0;
    $i = 0;
    while (($j = strpos($blokk, "kort('", $i)) !== false) {
        $k = strpos($blokk, '(', $j);
        $dybde = 0;
        $slutt = $k;
        $lengde = strlen($blokk);
        while ($slutt < $lengde) {
            if ($blokk[$slutt] === '(') { $dybde++; }
            elseif ($blokk[$slutt] === ')') { $dybde--; if ($dybde === 0) { break; } }
            $slutt++;
        }
        $kall = substr($blokk, $j, $slutt - $j + 1);
        $antall++;
        preg_match("/kort\('([^']+)'/", $kall, $m);
        // En pilfunksjon er det eneste som kan klikkes. Et objekt, en streng
        // eller ingenting gir et kort som ikke gaar noe sted.
        if (!str_contains($kall, '=>')) {
            $uten[] = $m[1] ?? '(uten navn)';
        }
        $i = $slutt;
    }

    sjekk('kortene paa Oversikt er funnet', $antall >= 15, $antall . ' kort');
    sjekk('… og hvert av dem har en knapp som gaar et sted',
        $uten === [], implode(', ', $uten));
})();

// ── Et bilde til feilmeldingen ─────────────────────────────────────────
//
// Eieren, 31. august: «paa admin burde man kunne legge inn bilde naar man
// melder feil, ellers er jeg redd du ikke forstaar hva vi mener».
//
// «Listen var tom» kan bety fem ting; et skjermbilde betyr én.
sjekk('skjemaet har en rute for skjermbilde',
    str_contains($sida2, '{{ fmBildeVelg }}')
    && str_contains($sida2, 'Velg et bilde fra maskinen eller telefonen'));
sjekk('… med forhaandsvisning og en vei til aa fjerne det',
    str_contains($sida2, '{{ fmHarBilde }}') && str_contains($sida2, '{{ fmFjernBilde }}'));
sjekk('… og bildet folger med naar meldinga sendes',
    str_contains($sida2, "bilde: String(this.state.fmBilde || ''),"));
sjekk('bildet vises igjen paa skjermen som viser rapportene',
    str_contains($sida2, '{{ r.harBilde }}') && str_contains($sida2, '{{ r.bilde }}'));
// Bare mennesker legger ved bilde. En sloyfe som kaster den samme feilen
// tusen ganger skal ikke kunne fylle disken med skjermbilder.
sjekk('bare en melding fra et menneske kan ha bilde',
    str_contains($fapi, "if (\$slag === 'melding' && DB::harKolonne('feilrapporter', 'bilde')) {"));
sjekk('… og bare JPG, PNG og WEBP tas imot',
    str_contains($fapi, "preg_match('~^data:image/(png|jpe?g|webp);base64,~i', \$data)"));
sjekk('… med et tak paa stoerrelsen',
    str_contains($fapi, 'strlen($data) > 10 * 1024 * 1024'));
// Et skjermbilde fra admin kan vise hva som helst — deltakerlister,
// e-postadresser, en halvferdig ordre.
$bildeFil = file_get_contents(__DIR__ . '/../api/bilde.php');
sjekk('skjermbildet er bare for verkstedet',
    str_contains($bildeFil, "\$sti = Bilder::sti(\$feil, 'feilrapporter');")
    && str_contains($bildeFil, 'if ($sti === null || !Sesjon::erAdmin()) {'));
// En rad som slettes tar ikke fila med seg av seg selv.
sjekk('ryddingen tar bildene med seg',
    str_contains($fapi, "Bilder::slett((string) \$g['bilde'], 'feilrapporter');"));
// Kolonnen kommer med migrasjon 114, og koden ligger ute for den er kjort.
sjekk('kolonnene settes sammen, saa «bilde» kan mangle',
    str_contains($fapi, "\$kolonner = array_keys(\$rad);")
    && str_contains($fapi, "'INSERT INTO feilrapporter (' . implode(', ', \$kolonner) . ')"));

if (DB::harKolonne('feilrapporter', 'bilde')) {
    // Adressen skal peke gjennom api/bilde.php, ikke paa fila.
    $rid = DB::settInn('feilrapporter', [
        'slag' => 'melding', 'melding' => 'Bildeprove',
        'nettleser' => 'Proven', 'bilde' => 'abc123.jpg',
        'fingeravtrykk' => sha1('bilde:' . bin2hex(random_bytes(8))),
    ]);
    $lest = DB::en('SELECT bilde FROM feilrapporter WHERE id = :i', ['i' => $rid]);
    sjekk('bildet lagres paa rapporten', ($lest['bilde'] ?? '') === 'abc123.jpg');
    DB::kjor('DELETE FROM feilrapporter WHERE id = :i', ['i' => $rid]);
} else {
    sjekk('bildekolonnen krever migrasjon 114', false, 'kolonnen mangler');
}

// ── «Sett paa» sa «Fant ikke rapporten» ────────────────────────────────
//
// Eieren, 1. september: «feilrapport som er sendt inn, trykket paa sett paa,
// men den staar der fortsatt, og naar jeg forsoker aa trykke sett paa igjen,
// saa sier den fant ikke rapporten».
//
// rowCount() teller rader som ble ENDRET, ikke rader som ble funnet. Sto
// rapporten alt paa «lukket» — sett paa i en annen fane, paa telefonen, eller
// bare en liste som var noen minutter gammel — endret UPDATE ingenting, og
// endepunktet svarte at rapporten ikke fantes. Den fantes.
$frapi = file_get_contents(__DIR__ . '/../api/admin/feilrapporter.php');
sjekk('rapporten slaas opp for den avvises',
    str_contains($frapi, "if (DB::en('SELECT id FROM feilrapporter WHERE id = :id', ['id' => \$id]) === null) {"));
sjekk('… og statusen settes uten aa telle endrede rader',
    str_contains($frapi, "DB::kjor('UPDATE feilrapporter SET status = :s WHERE id = :id', ['s' => \$ny, 'id' => \$id]);")
    && !str_contains($frapi, "'s' => \$ny, 'id' => \$id])->rowCount() === 0"));

if (DB::harTabell('feilrapporter')) {
    $rid = DB::settInn('feilrapporter', [
        'slag' => 'melding', 'melding' => 'Proverapport',
        'nettleser' => 'Proven', 'fingeravtrykk' => sha1('prove:' . bin2hex(random_bytes(8))),
    ]);
    $sett = static function (int $id, string $status): bool {
        if (DB::en('SELECT id FROM feilrapporter WHERE id = :id', ['id' => $id]) === null) {
            return false;
        }
        DB::kjor('UPDATE feilrapporter SET status = :s WHERE id = :id', ['s' => $status, 'id' => $id]);
        return true;
    };
    sjekk('foerste «sett paa» gaar gjennom', $sett($rid, 'lukket'));
    sjekk('… og andre gang er den fortsatt funnet, ikke borte', $sett($rid, 'lukket'));
    sjekk('… mens en rapport som virkelig ikke finnes avvises', !$sett(999999999, 'lukket'));
    DB::kjor('DELETE FROM feilrapporter WHERE id = :i', ['i' => $rid]);
}

// Og raden skal forsvinne under fingeren, ikke staa til svaret kommer.
sjekk('raden tas ut av lista med det samme',
    str_contains($sida2, "if (kropp.handling === 'status' && kropp.status === 'lukket') {")
    && str_contains($sida2, 'rapporter: f.rapporter.filter(r => r.id !== kropp.id),'));
// Gikk det ikke, skal lista bli sann igjen framfor aa vise det vi trodde.
sjekk('… og lista hentes paa nytt ogsaa naar serveren sier nei',
    str_contains($sida2, "// Gikk det ikke, hentes lista likevel: da staar det som faktisk"));

// Bildene i kassa ba om «{{ utQrBilde }}» som om det var en filadresse.
// Feilvakta fant det selv, forste gang den kjorte.
sjekk('ingen bilder binder paa src — alle bruker data-src',
    preg_match('~<img [^>]*(?<![-\\w])src="\\{\\{~', $sida2) !== 1);

// ── Datoer som ligger ute ──────────────────────────────────────────────
//
// api/admin/pameldte.php sender med de siste tretti dagene — deltakerlista
// trenger dem. Datolista filtrerte bare oppover, saa «Neste aatte uker»
// viste mandag 24. august fem dager etter at den var over.
sjekk('datolista tar bort datoer som har vaert',
    str_contains($sida2, 'const harVaert = (o) => {')
    && str_contains($sida2, '.filter(o => !harVaert(o));'));
sjekk('… men beholder dagen i dag',
    str_contains($sida2, 'const iDagFra = new Date(new Date().toDateString()).getTime();'));
// Kortet eieren ikke trengte.
sjekk('kortet «Kurs gaar tomme for datoer» er borte',
    !str_contains($sida2, "kort('Kurs går tomme for datoer'"));
// Statistikk: de mest populaere kursene, regnet av solgte plasser.
sjekk('statistikk-kortet staar paa Oversikt',
    str_contains($sida2, 'Statistikk · mest populære kurs')
    && str_contains($sida2, 'list="{{ ovPopulaere }}"'));
sjekk('… og tallene kommer fra serveren',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/oversikt.php'), "'populaere' => array_map")
    && str_contains($sida2, "(this.state.adminData || {}).populaere"));
sjekk('… regnet av solgte plasser, ikke av antall datoer',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/oversikt.php'),
                 "COALESCE(SUM(b.antall), 0) AS plasser"));

// De tre pillene nederst i bunnteksten skal vaere like store (eieren,
// 30. august). Lik bredde av «flex: 1 1 0», lik hoyde av «stretch» paa
// raden — ellers staar de to andre igjen som halve naar «Meld inn feil»
// brekker til to linjer paa telefon.
sjekk('de tre pillene i bunnteksten er like store',
    substr_count($sida2, 'flex: 1 1 0; min-width: 0; min-height: 42px; text-align: center; line-height: 1.25;') === 3
    && str_contains($sida2, 'display: flex; align-items: stretch; gap: var(--space-3); flex: 1 1 auto; max-width: 460px;'));
// … og teksten paa én linje, ogsaa paa telefon. Maalt: pillene trenger
// 117, 103 og 87 px paa én linje mot en rad paa 326. Tre like paa 117 blir
// 375 — 49 for mye. De 49 er hentet i mindre skrift, mindre luft mellom
// versalene og strammere innvendig, bare paa smal skjerm.
sjekk('… med teksten paa én linje',
    substr_count($sida2, 'class="lx-bunnpille"') === 3
    && str_contains($sida2, 'white-space: nowrap !important;')
    && str_contains($sida2, '.lx-bunnpiller { gap: 8px !important; width: 100%; }'));

// Snarveiene under «Ofte brukt» paa Oversikt. Paa telefon sto «Ny
// kursdato» paa samme linje som overskriften og de tre andre under, hver
// i sin bredde. Eieren 30. august: like store, paa linjer under.
// Eieren 30. august: «naar jeg ber om det som jeg gjorde paa forsiden saa
// mener jeg saa klart over alt». Regelen gjelder begge de gule radene —
// «Ofte brukt» paa Oversikt og «Hurtig» paa omraadeskjermene.
sjekk('snarveiene i de gule radene er like store paa telefon',
    substr_count($sida2, 'class="lx-hurtig"') === 2
    && str_contains($sida2, '.lx-hurtig > span { flex: 1 1 100% !important;')
    && str_contains($sida2, '.lx-hurtig button {'));

// Paint on Pots og andre der gjenstanden velges i verkstedet sto helt uten
// pris paa kortet — og et kort uten pris ser ut som et kort der noe mangler.
// Eieren 30. august: det skal staa en fra-pris. Tallet er serverens: plassen
// pluss den rimeligste varen som kan males, saa kortet aldri lover mindre
// enn kassa krever.
sjekk('kort med gjenstand i kassa viser en fra-pris',
    substr_count($sida2, "? (kat.prisFraOre ? 'Fra ' + kat.prisFra : '')") === 2);
sjekk('… og serveren regner den ut av den rimeligste varen',
    str_contains(file_get_contents(__DIR__ . '/../api/kurs.php'), "'prisFraOre'      => \$fra,")
    && str_contains(file_get_contents(__DIR__ . '/../api/kurs.php'), 'SELECT MIN(pris_ore) FROM products'));

// «Velg» paa medlemskapssiden gikk til bookingskjermen, som alltid opprettet
// en avtale i Vipps — uten valget mellom fast trekk og aa ordne selv
// (migrasjon 081), og med en knapp som krevde innlogging etter at hele
// skjemaet var fylt ut. Innmeldingen paa Min side har alt dette fra for.
sjekk('«Velg» paa medlemskap foerer til innmeldingen',
    str_contains($sida2, 'const meldInn = () => this.meldInnPlan(o.navn);')
    && str_contains($sida2, 'kjop: meldInn,'));
sjekk('… og valget overlever innloggingen',
    str_contains($sida2, "sessionStorage.setItem('lissom_medlemsplan', navn)")
    && str_contains($sida2, "sessionStorage.getItem('lissom_medlemsplan')"));
sjekk('… og skjemaet har et anker aa rulle til',
    str_contains($sida2, 'id="bli-medlem"')
    && str_contains($sida2, "document.getElementById('bli-medlem')"));
// Begge betalingsveiene finnes paa serveren, ikke bare paa skjermen.
sjekk('innmeldingen tar imot bade fast trekk og «ordner selv»',
    str_contains(file_get_contents(__DIR__ . '/../api/bli-medlem.php'),
                 "\$betaling = Foresporsel::tekst('betaling') === 'selv' ? 'selv' : 'trekk';"));

// ── Frakt og adresse ───────────────────────────────────────────────────
//
// Kassa viste «Inkludert frakt kr. 89,-» og la belopet til i totalen paa
// skjermen. Det gikk aldri til serveren: api/ordre.php regnet summen av
// varene alene, og valget «Send som pakke» fulgte ikke med i det hele tatt.
// Bestilte noen med sending, betalte verkstedet portoen selv — og ingen fikk
// vite hvor pakken skulle.
$ordre = file_get_contents(__DIR__ . '/../api/ordre.php');
sjekk('serveren legger frakten paa summen',
    str_contains($ordre, "\$levering = Foresporsel::tekst('levering') === 'pakke' ? 'pakke' : 'hent';")
    && str_contains($ordre, '$sum += $fraktOre;'));
sjekk('… og henter prisen fra basen, ikke fra nettleseren',
    str_contains($ordre, "SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => 'frakt_ore']"));
sjekk('… og krever adresse naar det skal sendes',
    str_contains($ordre, 'Vi trenger gateadressen pakken skal til.')
    && str_contains($ordre, 'Postnummeret skal ha fire siffer.'));
sjekk('… og frakten staar som en egen linje paa ordren',
    str_contains($ordre, "'tittel'     => 'Frakt — sendt som pakke',"));
sjekk('kassa sender leveringen med bestillingen',
    str_contains($sida2, "levering: this.erPakke() ? 'pakke' : 'hent',")
    && str_contains($sida2, "adresse: (this.state.levAdresse || '').trim(),"));
sjekk('… og adressefeltene staar bare naar det skal sendes',
    str_contains($sida2, '{{ levPakke }}') && str_contains($sida2, 'label="Gateadresse"'));
// En adresse uten navn er ikke noe Posten kan levere paa. Navnet staar over
// adressen, og bare der: kortet «Kontaktopplysninger» er tatt bort (eieren,
// 30. august). Det kunne gaa fordi kassa krever innlogging med Vipps —
// navn, telefon og e-post kommer derfra, og api/ordre.php faller tilbake
// paa medlemmets egne verdier naar feltene staar tomme.
sjekk('navnet staar over adressen',
    str_contains($sida2, 'label="Navn" placeholder="Kari Nordmann" value="{{ kaNavn }}"'));
sjekk('… og kortet «Kontaktopplysninger» er borte',
    !str_contains($sida2, '>Kontaktopplysninger</div>'));
// Tallet 89 sto skrevet inn fire steder. Naa staar det ett sted, i basen.
sjekk('fraktprisen staar ingen steder i koden',
    !str_contains($sida2, 'Send som pakke (kr. 89,-)'));
sjekk('… men kan endres i admin',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/produkter.php'), "if (\$handling === 'frakt') {"));
// Gavekortfeltet tok like mye plass som leveringen. De fleste har ikke et.
sjekk('gavekortfeltet er en lenke til man trenger det',
    str_contains($sida2, 'Har du gavekort eller rabattkode?')
    && str_contains($sida2, '{{ gkSkjult }}'));

// ── Kursholderne ───────────────────────────────────────────────────────
//
// Migrasjon 093 flyttet kursene over paa Monica, men ingen ble merket som
// verkstedets standard. Da falt vaktlista tilbake paa «Ikke tildelt» paa
// hver eneste rad. Eieren 30. august: «alle kurs og vakter skal vaere
// default Monica».
$m096 = file_get_contents(__DIR__ . '/../db/migrations/096_monica_er_standard.sql');
sjekk('Monica settes som standard kursholder',
    str_contains($m096, "SET standard = 1") && str_contains($m096, "navn = 'Monica'"));
sjekk('… men bare naar hun finnes én gang og ingen andre er standard',
    str_contains($m096, "AND aktiv = 1") && str_contains($m096, 'AS antall FROM kursholdere'));
sjekk('… og en som har sluttet kan ikke bli staaende som standard',
    str_contains($m096, 'SET standard = 0 WHERE standard = 1 AND aktiv = 0'));

// «Selv om jeg har flere kursholdere, skal de aldri vises dersom de ikke er
// aktivert.» Uten «aktiv = 1» paa oppslaget sto navnet til en som hadde
// sluttet igjen paa hver dato hen var satt opp paa — og COALESCE gikk aldri
// videre til standarden, fordi navnet var der.
$vst = file_get_contents(__DIR__ . '/../api/admin/verkstedet.php');
sjekk('vaktlista henter bare navn fra aktive kursholdere',
    str_contains($vst, 'LEFT JOIN kursholdere kh ON kh.id = cs.kursholder_id AND kh.aktiv = 1')
    && str_contains($vst, 'LEFT JOIN kursholdere kk ON kk.id = c.kursholder_id AND kk.aktiv = 1'));
sjekk('… og kalenderen gjor det samme',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/kalender.php'),
                 'LEFT JOIN kursholdere h ON h.id = cs.kursholder_id AND h.aktiv = 1'));
// Overskriften sier «kursene framover». Lista viste en uke bakover.
sjekk('vaktlista starter i dag, ikke sist uke',
    str_contains($vst, "\$fra = \$dag(Foresporsel::tekst('fra'), 0);"));

// ── Ferie ──────────────────────────────────────────────────────────────
//
// Eieren 30. august: en pille som heter «Ferie», en kalender aa velge i,
// «en og en dag, eller hele uker», og kursene i perioden skal ikke vises paa
// nettsiden.
//
// Ingen egen tabell: «apningstider» med «stengt = 1» er det samme, og
// migrasjon 060 nevner til og med «en ferieuke». Jeg begynte paa en
// «ferie»-tabell og maatte rive den — to tabeller for den samme dagen er to
// steder aa se etter, og to steder aa ta feil.
sjekk('ferien bygger paa apningstider, ikke en egen tabell',
    !file_exists(__DIR__ . '/../db/migrations/097_ferie.sql')
    && str_contains(file_get_contents(__DIR__ . '/../app/lib/ferie.php'),
                    "SELECT dato FROM apningstider WHERE stengt = 1"));
// Det som var nytt: en stengt dag skjuler kursdatoene, ikke bare
// aapningstidene i bunnteksten.
sjekk('en stengt dag skjuler kursdatoene paa nettsida',
    str_contains(file_get_contents(__DIR__ . '/../api/kurs.php'), 'Ferie::stengt('));
sjekk('… og aapningstidene folger med',
    str_contains(file_get_contents(__DIR__ . '/../app/lib/apent.php'), '$okter = Ferie::utenom($okter);'));
// Skjult er ikke det samme som stengt: en gammel fane kan sende okt-id-en
// rett til serveren lenge etterpaa.
sjekk('… og bookingen stoppes paa serveren',
    str_contains(file_get_contents(__DIR__ . '/../api/book.php'),
                 'Verkstedet holder stengt denne dagen. Velg en annen dato.'));
// Sammenlikningen skjer i PHP: CONVERT_TZ krever tidssonetabeller som ofte
// ikke er lastet paa et delt webhotell, og svarer da NULL.
sjekk('… og datoen regnes om i PHP, ikke med CONVERT_TZ',
    !str_contains(file_get_contents(__DIR__ . '/../app/lib/ferie.php'), 'CONVERT_TZ(')
    && str_contains(file_get_contents(__DIR__ . '/../app/lib/ferie.php'), "new DateTimeZone('Europe/Oslo')"));
// Hele uker med ett trykk.
sjekk('hele uka kan stenges med ett trykk',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/apningstider.php'),
                 "if (Foresporsel::tekst('handling') === 'uke') {"));
sjekk('… men dager som har vaert hoppes over',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/apningstider.php'), 'if ($d < $idag) {'));
// Skjermen og pillen.
sjekk('ferieskjermen finnes, med ukenummer aa trykke paa',
    str_contains($sida2, 'data-screen-label="Admin – ferie"')
    && str_contains($sida2, 'onClick="{{ v.vekslUke }}"')
    && str_contains($sida2, 'onClick="{{ g.veksl }}"'));
sjekk('… og pillen staar paa Oversikt, sammen med innstemplinga',
    str_contains($sida2, "ferieVelg: () => this.gaaAdmin('adminferie', {}),")
    && str_contains($sida2, '{{ ovStempNavn }}') && str_contains($sida2, '{{ ovFerieNavn }}'));
// Eieren, 30. august: «stemple inn og ferie maa flyttes til oversikt».
sjekk('… og de staar ikke lenger i menyen',
    str_contains($sida2, 'const stempling = [];'));
// Paameldte forsvinner ikke av seg selv — eieren maa faa vite om dem.
sjekk('skjermen sier fra naar noen alt har booket',
    str_contains($sida2, 'de forsvinner ikke av seg selv, så gi beskjed.'));

// ── Planlagte kurs, som listevisningen i kalenderen ────────────────────
//
// Sju spalter ved siden av hverandre er 55 piksler per dag paa en telefon.
// Eieren, 30. august: «finn en mer oversiktlig loesning for planlagte kurs,
// samme som kallender visningen er best», og etterpaa: «jeg vil at denne skal
// se lik ut og vises likt som kalenderen».
$pameldteFil = file_get_contents(__DIR__ . '/../api/admin/pameldte.php');
sjekk('rutenettet paa sju spalter er borte',
    !str_contains($sida2, 'class="lx-week"'));
sjekk('planlagte kurs staar dag for dag nedover',
    str_contains($sida2, '{{ d.dagTittel }}')
    && str_contains($sida2, '<sc-for list="{{ d.poster }}" as="p"'));
sjekk('… med klokkeslett, prikk og detaljlinje som i kalenderen',
    str_contains($sida2, '{{ p.tid }}')
    && str_contains($sida2, '{{ p.prikkStil }}')
    && str_contains($sida2, '{{ p.detalj }}'));
sjekk('prikken henter fargene fra kalenderen selv',
    str_contains($sida2, 'const info = this.klTypeInfo();'));
sjekk('dager uten noe hopper lista over',
    str_contains($sida2, 'if (!poster.length && !taMedTomme) return null;')
    && str_contains($sida2, 'if (kort) dager.push(kort);'));
// Eieren, 30. august: «jeg vil og ha uke og maanedsvisning».
sjekk('planlagte kurs har uke, maaned og liste',
    str_contains($sida2, "admVisValg: [['uke', 'Uke'], ['maned', 'Måned'], ['liste', 'Liste']]")
    && str_contains($sida2, '{{ admErMnd }}') && str_contains($sida2, '{{ admErAgenda }}'));
sjekk('… og ukevisningen viser ogsaa de tomme dagene',
    str_contains($sida2, 'dager.push(dagKort(d, true));')
    && str_contains($sida2, '{{ d.erTom }}'));
// Eieren, 30. august: «naar jeg trykker paa planlagte kurs saa kommer jeg
// ikke til kurset». Raden sendte deg til Paameldte-skjermen med et filter paa.
sjekk('et trykk paa en dato aapner kurset',
    str_contains($sida2, "this.setState({ datoerFor: o.tittel, kRed: false, oktRediger: null });"));
// Aapningstida klippes i plasser paa halvannen time. Uten sammenslaaingen sto
// det samme tilbudet i seks like linjer, slik den gjorde i kalenderen for.
sjekk('planlagte kurs samler tidene som foelger en regel',
    str_contains($pameldteFil, "'auto'      => (int) (\$o['fra_apningstid'] ?? 0) === 1")
    && str_contains($sida2, "if (o.auto) { (perKurs[o.tittel] = perKurs[o.tittel] || []).push(o); }"));
sjekk('… og den samlede linja kan aapnes',
    str_contains($sida2, "utvidet[o.gruppeId] ? 'Skjul' : 'Vis tidene'"));

// ── Datoer paa et kurs som ikke er publisert ───────────────────────────
//
// Eieren, 30. august: «fortsatt feil i visning, lag din egen bolle, du maa
// sjekke globalt». To linjer som ser helt like ut er ikke like naar bare den
// ene kan bookes: den andre hoerer til et kurs som ligger som utkast.
sjekk('kalenderen vet om kurset er publisert',
    str_contains($kalFil3, "c.status AS kurs_status")
    && str_contains($kalFil3, "'publisert' => (string) (\$o['kurs_status'] ?? 'publisert') === 'publisert',"));
sjekk('planlagte kurs vet det samme',
    str_contains($pameldteFil, "'publisert' => (string) (\$o['kurs_status'] ?? 'publisert') === 'publisert',"));
sjekk('… og begge listene sier fra paa linja',
    str_contains($sida2, "e.publisert === false ? 'Ikke publisert' : ''")
    && str_contains($sida2, "o.publisert === false ? 'Ikke publisert' : ''"));

// ── E-post naar en deltaker legges inn fra kalenderen ──────────────────
//
// Eieren, 30. august: «naar jeg legger til deltakere saa vil jeg ogsaa ha med
// epost». De to fyldige skjemaene hadde feltet; hurtigfeltet i kalenderen
// hadde det ikke, og deltakeren sto uten adresse.
sjekk('hurtigfeltet i kalenderen har e-post',
    str_contains($sida2, '{{ klNyDEpost }}')
    && str_contains($sida2, 'settKlNyDEpost: e => this.setState({ klNyDEpost: e.target.value }),'));
sjekk('… og adressen foelger med kallet',
    str_contains($sida2, "epost: (this.state.klNyDEpost || '').trim(),"));

// ── Nye paameldinger faller av etter tre dager ─────────────────────────
//
// Eieren, 30. august: «nye paameldinger vises kun i 3 dager». Om plassen er
// betalt, staar paa kursdatoen — det er ingenting aa gjore fra kortet ut over
// aa se det, og da skal det tomme seg selv framfor aa vise et tall som ikke
// gaar ned.
$oversiktFil = file_get_contents(__DIR__ . '/../api/admin/oversikt.php');
sjekk('nye paameldinger er avgrenset til tre dager',
    str_contains($oversiktFil, 'AND b.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)'));
// Kortet paa Oversikt teller den samme lista, saa tallet foelger med.
sjekk('… og kortet teller den samme lista',
    str_contains($sida2, "const nyeste = ((this.state.adminData || {}).nyeste || []).length;")
    && str_contains($sida2, "nyeste ? 'De siste tre dagene.'"));

// ── Refusjon fra admin ─────────────────────────────────────────────────
//
// Eieren, 30. august: «legg inn refunder-knappen». Serverdelen fantes fra
// for; det var knappen som manglet, saa alt annet enn kundens egen
// avbestilling matte gjores i portalen hos Vipps — og da visste ikke basen
// om det.
$betFil = file_get_contents(__DIR__ . '/../api/admin/betalinger.php');
sjekk('refusjonen kaller Vipps og skriver den ned',
    str_contains($betFil, 'Vipps::refunder($referanse, $belop);')
    && str_contains($betFil, "'status'        => \$nyRefundert >= (int) \$betaling['belop_ore'] ? 'refundert' : 'delvis_refundert',"));
// En delrefusjon etter vilkaarene (50 % inntil sju dager for) skal ikke ta
// plassen fra deltakeren. Her sto oppdateringen uten betingelse.
sjekk('… og plassen settes bare som refundert naar alt er sendt tilbake',
    str_contains($betFil, "if (\$nyRefundert >= (int) \$betaling['belop_ore']) {"));
sjekk('knappen staar paa betalingen under Økonomi',
    str_contains($sida2, 'onClick="{{ b.refApne }}"')
    && str_contains($sida2, 'onClick="{{ b.refUtfor }}"')
    && str_contains($sida2, '{{ b.refHjelp }}'));
// Penger som gaar ut skal bekreftes, og bare en gjennomfort betaling med noe
// igjen kan refunderes.
sjekk('… med bekreftelse for pengene sendes',
    str_contains($sida2, "'Pengene går tilbake på Vipps med det samme. Dette kan ikke angres.'"));
sjekk('… og bare der det er noe aa refundere',
    str_contains($sida2, "kanRefundere: ['betalt', 'delvis_refundert'].indexOf(String(b.status || '')) !== -1"));

// ── Vis eller skjul en referansekunde ──────────────────────────────────
//
// Eieren, 30. august: «kan jeg faa mulighet aa vise eller ikke vise
// referansekundene, saa slipper jeg aa slette de». Bryteren fantes bare inne
// i redigeringsskjemaet: aapne, finn haken, lagre — og det samme igjen naar
// kortet skulle tilbake.
$refFil = file_get_contents(__DIR__ . '/../api/admin/referanser.php');
sjekk('referansekunder kan skjules uten aa slettes',
    str_contains($refFil, "if (\$handling === 'veksle') {")
    && str_contains($refFil, "DB::oppdater('referansekunder', ['aktiv' => \$paa ? 1 : 0], ['id' => \$id]);"));
// Samtykket er en avtale med kunden, ikke en synlighet. Det skal ikke kunne
// slaas paa ved et uhell fra lista.
sjekk('… og samtykket roeres ikke av bryteren',
    !str_contains(substr($refFil, strpos($refFil, "if (\$handling === 'veksle')"),
                         strpos($refFil, '// ── Slett') - strpos($refFil, "if (\$handling === 'veksle')")),
                  "'samtykke' =>"));
sjekk('… og svaret sier fra naar kortet likevel ikke vises',
    str_contains($refFil, "» er slått på, men vises ikke før '"));
sjekk('knappen staar paa raden, ved siden av Rediger',
    str_contains($sida2, 'onClick="{{ r.veksle }}"')
    && str_contains($sida2, "visTekst: k.aktiv ? 'Skjul' : 'Vis',"));

// ── Brikkene maa ha plass ──────────────────────────────────────────────
//
// Da nedtrekkene ble brikker, arvet de spaltene nedtrekket sto i — 74 px i
// rabattraden, 130-220 px i skjemaene. Ni valg ble en soyle nedover. Eieren
// 30. august, om grupperabatten: «syns du dette ser bra ut?» Nei.
//
// Raden er derfor et kort med etiketter, og hvert brikkefelt i et rutenett
// tar hele raden.
sjekk('rabattraden er ikke fem smale spalter lenger',
    !str_contains($sida2, "grid-template-columns: 74px 74px 1fr 44px 28px"));
sjekk('… og tallfeltene har sin egen bredde',
    str_contains($sida2, 'grTallStil:'));
sjekk('brikkefeltene i et rutenett tar hele raden',
    substr_count($sida2, 'grid-column: 1 / -1;') >= 8);

// ── Kundelogoen paa forsida ────────────────────────────────────────────
//
// Bak logoen sto en hvit plate med ramme, saa en hvilken som helst logo
// skulle lese mot leirefargen. En PNG med gjennomsiktig bakgrunn viste da
// plata — eieren, 30. august: «hvorfor er det hvit bakgrunn paa kepler
// logoen, det er png fil», og etterpaa: «ja, ingen hvit plate».
sjekk('kundelogoen ligger rett paa bakgrunnen',
    str_contains($sida2, "backgroundPosition: 'left center',")
    && !preg_match("/rotLogoStil: \{[^}]*background: '#fff'/s", $sida2));
// Ruta i admin skal vise det samme som forsida gjor.
sjekk('… og forhaandsvisningen i admin viser det samme',
    str_contains($sida2, "backgroundColor: 'transparent',"));

// ── Haandbygging og Events ─────────────────────────────────────────────
//
// Eieren, 30. august: «sip&clay, datenights og en paint on pots skal ligge
// under en ny pille som heter events», «lag din egen bolle, og workshop, skal
// ligge under pillen med det nye navnet haandbygging», og «jeg vil ha
// mulighet aa velge haandbygging naar jeg legger ut nye kurs».
sjekk('pillene ute er Dreiing, Haandbygging og Events',
    str_contains($sida2, "const ute = ['Dreiing', 'Håndbygging', 'Events'];"));
sjekk('… og de tre arrangementene havner under Events',
    str_contains($sida2, "'Sip & Clay': 'Events', 'Date Night': 'Events',")
    && str_contains($sida2, "'Paint on pots': 'Events', 'Paint on Pots': 'Events',"));
sjekk('… og workshop og plateteknikk under Haandbygging',
    str_contains($sida2, "'Workshop': 'Håndbygging', 'Plateteknikk': 'Håndbygging',"));
// Kategorien maa kunne velges der kurs faktisk legges ut — hurtigskjemaet.
sjekk('Haandbygging kan velges naar et kurs legges ut',
    str_contains($sida2, "nkTyper: ['Kurs', 'Håndbygging', 'Event', 'Sip & Clay']")
    && str_contains($sida2, "'Håndbygging':   { type: 'Kurs',          tema: 'Håndbygging' , plasser: 12 },"));
sjekk('… og lagres som tema «Håndbygging»',
    str_contains($sida2, "'Håndbygging': 'Håndbygging', 'Workshop': 'Håndbygging',"));
// Gamle rader skal foelge med, ellers faller et kurs ut av sin egen kategori.
$m099 = file_get_contents(__DIR__ . '/../db/migrations/099_handbygging_og_events.sql');
sjekk('migrasjonen flytter de gamle temaene',
    str_contains($m099, "WHERE tema IN ('Workshop', 'Plateteknikk')")
    && str_contains($m099, "WHERE tittel = 'Lag din egen bolle'"));
// Eieren, 30. august: «jeg savner et kort som heter kurs. Som viser hvilke
// kurs som ligger i databasen, slik at jeg enkelt kan redigere de.»
// Eieren, 30. august: «en base over alle type kurs», «jeg vil bare se disse
// grunninnstillingene for kurs». Skjermen fantes — «Kursene vaare» — men uten
// inngang fra omraadet, og raden sa hvor mange datoer kurset har framfor hva
// kurset er.
sjekk('«Kurs og deltakere» har et kort inn til basen',
    str_contains($sida2, "medMer(kort('Kurs',")
    && str_contains($sida2, "kursData.length, 'admin', { kursFane: 'maler', datoerFor: '', kRed: false }, 'Se kursene')")
    && str_contains($sida2, "'Lag et nytt kurs',"));
sjekk('… og raden i basen viser grunninnstillingene',
    str_contains($sida2, "plasser ? plasser.trim() + ' plasser' : '',")
    && str_contains($sida2, "k.status && k.status !== 'publisert' ? 'Ikke publisert' : ''"));
// Datolista er skjult i basen, saa «vis datoene» ville ikke fort noe sted.
sjekk('… og et trykk paa raden aapner kursoppsettet',
    str_contains($sida2, "if ((this.state.kursFane || 'alle') === 'maler') {\n            this.setState({ datoerFor: '' });\n            this.apneKursRed(k, i);"));

// ── Signaturen paa telefon ─────────────────────────────────────────────
//
// Eieren, 30. august, med en testmelding aapnet paa iPhone: «epost til mobil
// deler signaturen, den maa skaleres». Logoen tok 152 piksler pluss 20 i luft
// paa hver side av streken; da fikk «Monica Vaethe-Larsen» 165 piksler aa staa
// paa, og navnet brakk over to linjer.
$sigFil = file_get_contents(__DIR__ . '/../e-post-signatur.html');
sjekk('logoen i signaturen er skalert ned',
    substr_count($sigFil, 'width="100" height="92"') === 2
    && !str_contains($sigFil, 'width="152" height="139"'));
sjekk('… og bildet krymper med spalten sin',
    substr_count($sigFil, 'width:100px;max-width:100%;height:auto;') === 2);
sjekk('… begge utgavene paa sida er like',
    substr_count($sigFil, 'padding:0 12px 0 0') === 2);
// Signaturen som gaar ut ligger i innstillingene — eieren limte den inn der.
// Rettes bare fila, gaar meldingene fortsatt ut med den gamle.
$m100 = file_get_contents(__DIR__ . '/../db/migrations/100_signatur_skalerer.sql');
sjekk('… og den lagrede signaturen rettes med',
    str_contains($m100, "REPLACE(verdi, 'width=\"152\" height=\"139\"', 'width=\"100\" height=\"92\"')")
    && str_contains($m100, "AND verdi LIKE '%lissom-signatur-logo.png%'"));

// ── Kategoriene staar én gang ──────────────────────────────────────────
//
// Eieren, 30. august: «du har lagt inn kategorier to ganger». To steder:
// «Events» sto ved siden av de tre som utgjor Events, og «Metode» gjentok
// Dreiing og Haandbygging rett under kategorien.
// Var fem. Drop-in er revet ut — se «Drop-in finnes ikke» — saa den interne
// brikka som var igjen er «Kun medlemmer».
sjekk('kategorivalget er fire brikker, uten dublettene',
    str_contains($sida2, "return medInterne ? ute.concat(['Kun medlemmer']) : ute;")
    && !str_contains($sida2, "'Sip & Clay':    { type: 'Sip & Clay',    tema: 'Sip & Clay' , plasser: 12 },"));
sjekk('… og de tre arrangementene kjennes fortsatt igjen',
    str_contains($sida2, "'Sip & Clay': 'Events',")
    && str_contains($sida2, "'Date Night': 'Events',"));
sjekk('«Metode» er borte fra kursoppsettet',
    !str_contains($sida2, "['Metode', 'kMetode',"));
sjekk('… og metoden foelger kategorien i stedet',
    str_contains($sida2, 'metodeAvKategori(kategori, tema, naa) {')
    && str_contains($sida2, "metode: this.metodeAvKategori(this.state.kKategori, this.state.kTema, this.state.kMetode),"));

// ── Rekkefolgen, overalt ───────────────────────────────────────────────
//
// Eieren, 30. august: «sorter globalt, alle dreiekurs foerst, saa alle
// haandbygging, saa event».
sjekk('rekkefolgen staar ett sted',
    str_contains($sida2, "static KURSRANG = ['Dreiing', 'Håndbygging', 'Events'];")
    && str_contains($sida2, 'sorterKurs(liste, hent) {'));
// Teksten paa kortet og plassen i lista leste hver sin regel. «Store fat
// kurs» staar med temaet «Kurs» i basen: kortet falt tilbake paa typen og sa
// «Dreiing», sorteringa gjorde det ikke og la kurset feil. Naa er
// det én funksjon, og de kan ikke si to forskjellige ting.
sjekk('kategorien paa kortet og plassen i lista er samme regel',
    str_contains($sida2, 'kategoriVist(tema, tittel, type) {')
    && str_contains($sida2, 'Component.KURSRANG.indexOf(this.kategoriVist(tema, tittel, type))')
    && str_contains($sida2, "under: [this.kategoriVist(k.tema, k.navn, k.type) || 'Uten kategori',"));
sjekk('… og typen foelger med i sorteringa',
    str_contains($sida2, 'this.kursRang(x.tema, x.navn, x.type) - this.kursRang(y.tema, y.navn, y.type)')
    && substr_count($sida2, 'type: k.type') + substr_count($sida2, 'type: r.k.type')
       + substr_count($sida2, 'type: o.type') >= 4);
sjekk('… og brukes ute, i basen, i nedtrekket og ved paamelding',
    substr_count($sida2, 'this.sorterKurs(') >= 4);

// ── Gavekortet, diskret overalt ────────────────────────────────────────
//
// Eieren, 30. august: «gavekort og rabattkode gjoeres mindre og mer diskret
// som jeg ba om tidligere, globalt». Kassa hadde det; paameldingen ikke.
sjekk('gavekortfeltet er en lenke ogsaa i paameldingen',
    substr_count($sida2, 'Har du gavekort eller rabattkode?') === 2);

// ── Knapperada i kursoppsettet ─────────────────────────────────────────
//
// «Neste» og «Lagre endringene» sto side om side uten aa kunne brekke, og
// paa en telefon stakk den siste ut av kortet.
sjekk('knapperada i kursoppsettet bryter',
    str_contains($sida2, 'row-gap: var(--space-4); align-items: center; justify-content: space-between; flex-wrap: wrap; border-top: 2px solid var(--lissom-brown);'));

// ── Tre felt ut av kursoppsettet ───────────────────────────────────────
//
// Eieren, 30. august: «varighet, dette regnes fra kursstart til slutt, saa
// fjern disse pillene» og «kort beskrivelse og dette lager du kan fjernes».
// Varigheten staar i faktaboksen, regnet av start- og sluttida; brikkene var
// en andre fasit ved siden av klokka. «Kort beskrivelse» laa ved siden av
// «Om kurset», «Dette lager du» ved siden av «Dette faar du med hjem».
sjekk('varighetsbrikkene er borte fra kursoppsettet',
    !str_contains($sida2, "['Varighet', 'kVarighet',"));
// «Fjern varighet og bruk tidene paa datoen»: ogsaa tekstfeltet, som
// overstyrte klokka naar det sto noe i det.
sjekk('… og varighetsfeltet ogsaa',
    !str_contains($sida2, 'id="k-varighet"') && !str_contains($sida2, 'settKVarighetTekst'));
sjekk('… varigheten regnes av tidene paa datoene, alltid',
    !str_contains(file_get_contents(__DIR__ . '/../app/lib/kursmal.php'),
                  "\$egen = trim((string) (\$kurs['varighet_tekst'] ?? ''));"));
sjekk('«Kort beskrivelse» og «Dette lager du» har ingen felt lenger',
    !str_contains($sida2, 'id="k-kortom"') && !str_contains($sida2, 'id="k-lagerdu"')
    && !str_contains($sida2, 'settKKortBeskrivelse') && !str_contains($sida2, 'settKLagerDu'));
// Feltene er borte fra skjemaet, ikke fra kursene. Sendte vi ikke verdien
// videre, ville teksten som staar ute blitt tom foerste gang noen lagret.
sjekk('… men teksten som staar lagret sendes videre urort',
    str_contains($sida2, "kortBeskrivelse: this.state.kKortBeskrivelse || '',")
    && str_contains($sida2, "lagerDu: this.state.kLagerDu || '',"));
sjekk('Kursveilederen vekter ikke lenger paa varighet',
    str_contains($sida2, "const onsket = { nivaa: [], hvem: [], metode: [] };")
    && !str_contains($sida2, "['Varighet', 'varighet', MERKER.varighet"));

// ── «Neste» mister deg ikke ───────────────────────────────────────────
//
// Steg 1 er tolv seksjoner langt, steg 2 og 3 er korte. Trykte du «Neste»
// nederst i steg 1, sto rullingen stille mens kortet krympet under foettene
// paa deg — og du sto midt i kurslista under. Eieren: «naar jeg trykker paa
// neste saa kommer jeg hit, hva i helvete».
sjekk('kursoppsettet har et feste aa rulle til',
    str_contains($sida2, 'id="kursoppsett"'));
sjekk('… og «Neste», «Tilbake» og stegbrikkene bruker det',
    str_contains($sida2, 'rullTilKursoppsettet() {')
    && substr_count($sida2, 'this.rullTilKursoppsettet();') === 3);

// ── Standardtekst per kategori ─────────────────────────────────────────
//
// Eieren: «feltene godt aa vite, naar er den ferdig, alt som er inkludert og
// praktisk informasjon — dette vil jeg kunne redigere default tekst, enkelt
// rett i feltene og faa opp lagre». Standarden foelger kategorien.
$kmal = file_get_contents(__DIR__ . '/../app/lib/kursmal.php');
sjekk('de tre feltene har en standardtekst per kategori',
    str_contains($kmal, "public const EGNE_FELT = ['punkter', 'praktisk', 'ferdigTid'];")
    && str_contains($kmal, 'public static function standardtekster(): array'));
// Standarden ligger oeverst paa malen: har eieren skrevet en, er det hennes
// som gjelder, ikke den vi skrev i koden.
sjekk('… og den legges oeverst paa malen',
    str_contains($kmal, 'self::standardtekster()[$kategori] ?? []'));
sjekk('… kategorien leses av temaet, ogsaa de gamle navnene',
    str_contains($kmal, "'Workshop' => 'Håndbygging',")
    && str_contains($kmal, "'Sip & Clay' => 'Events',"));
// En oedelagt JSON eller en tabell som ikke finnes skal gi ingen
// standardtekst, ikke en hvit side paa kurssida.
sjekk('… og en oedelagt verdi gir ingen tekst, ikke en feil',
    str_contains($kmal, '} catch (Throwable $e) {'));
$akurs = file_get_contents(__DIR__ . '/../api/admin/kurs.php');
sjekk('standardteksten lagres per kategori og felt',
    str_contains($akurs, "case 'standardtekst':")
    && str_contains($akurs, "in_array(\$kategori, Kursmal::KATEGORIER, true)")
    && str_contains($akurs, "in_array(\$felt, Kursmal::EGNE_FELT, true)"));
// Hele objektet skrives paa nytt, saa en tekst satt for et annet felt eller
// en annen kategori staar urort.
sjekk('… uten aa slette de andre',
    str_contains($akurs, '$alle = Kursmal::standardtekster();')
    && str_contains($akurs, "'n' => 'kurs_standardtekster'"));
sjekk('praktisk informasjon faller tilbake paa standarden ute',
    str_contains(file_get_contents(__DIR__ . '/../api/kurs.php'),
                 "(string) (Kursmal::forKurs(\$k)['praktisk'] ?? '')"));
sjekk('lagre-lenka staar bare naar teksten er endret',
    str_contains($sida2, 'harLagre: kategori !== \'\' && naa !== \'\' && naa !== fasit,'));
// «Lagre som standard» lagrer en tekst ved siden av kurset man holder paa
// med. Lukket den skjemaet, mistet man plassen sin i tolv seksjoner.
sjekk('… og lagringa lukker ikke kursoppsettet',
    str_contains($sida2, "this.kursKall({ handling: 'standardtekst', kategori: kategori, felt: felt, tekst: naa }, true);")
    && str_contains($sida2, 'if (!behold) ny.kRed = false;'));
sjekk('alle tre feltene har raden',
    substr_count($sida2, 'Hent standardteksten</button>') === 3);

// ── Hvor hvert felt vises ──────────────────────────────────────────────
//
// Eieren: «er det info som vises deltakerne paa epost, saa faa det tydelig
// frem at de faar det paa epost. Jeg vet jo ikke hvor alt vises.»
sjekk('hver seksjon sier hvor teksten havner',
    substr_count($sida2, 'Vises på kurssiden') >= 6);
// Seksjon 12 lovte at teksten gikk ut i kvitteringen. Kolonnen
// bekreftelse_tekst skrives fra kursoppsettet og leses ingen steder — mailen
// som gaar er malen «ordrebekreftelse» med {navn}, {ordre} og {belop}.
// ── Ett felt per ting ──────────────────────────────────────────────────
//
// Eieren, 30. august: «en praktisk informasjon og en dette faar du med hjem,
// rydd resten». Tre par sa det samme: «Godt aa vite» og «Praktisk
// informasjon», «Dette lager du» og «Dette faar du med hjem», og
// «Bekreftelse» som overlappet den forste og dessuten ikke ble lest noe sted.
sjekk('«Godt aa vite» og «Bekreftelse» har ingen felt lenger',
    !str_contains($sida2, 'id="k-tillegg"') && !str_contains($sida2, 'settKTillegg')
    && !str_contains($sida2, 'id="k-bekreftelse"') && !str_contains($sida2, 'settKBekreftelse'));
sjekk('… og kurssiden har fem avsnitt, ikke sju',
    !str_contains($sida2, "['Dette lager du', k.lagerDu],")
    && !str_contains($sida2, "['Godt å vite', k.tillegg],")
    && str_contains($sida2, "['Dette får du med hjem', k.medHjem],"));
// Faktaboksen leste «Dette lager du» mens avsnittet under leste «Dette faar
// du med hjem». Det forste feltet finnes ikke lenger i oppsettet.
sjekk('… og faktaboksens «Med hjem» leser det feltet som staar igjen',
    str_contains($sida2, "{ merke: 'Med hjem', verdi: kort(k.medHjem) },"));
// Feltene er borte fra skjemaet, ikke fra kursene.
sjekk('… men teksten som staar lagret sendes fortsatt videre urort',
    str_contains($sida2, "bekreftelse: this.state.kBekreftelse || '',")
    && str_contains($sida2, "tillegg: this.state.kTillegg || '',"));
sjekk('seksjon 12 lover ikke lenger en e-post den ikke sender',
    !str_contains($sida2, 'Teksten de får på skjermen etter kjøp og i e-postkvitteringen.')
    && str_contains($sida2, '12 · Påminnelse')
    && str_contains($sida2, 'settes opp under Beskjeder → E-post- og SMS-maler'));

// ── «Store fat kurs» er haandbygging ───────────────────────────────────
//
// Kurset sto med temaet «Kurs» i basen — ingen ekte kategori — og kortet
// gjettet «Dreiing» av kurstypen. Eieren: «store fat er haandbygging».
$m101 = file_get_contents(__DIR__ . '/../db/migrations/101_store_fat_er_handbygging.sql');
sjekk('«Store fat kurs» faar temaet Haandbygging',
    str_contains($m101, "SET tema = 'Håndbygging'")
    && str_contains($m101, "WHERE tittel = 'Store fat kurs'"));
// Samme vakt som migrasjon 099: har noen alt gitt kurset en kategori, er det
// den som gjelder.
sjekk('… bare naar det ikke alt har en kategori',
    str_contains($m101, "AND (tema IS NULL OR tema = '' OR tema = 'Kurs')"));
// Foelgefeil av migrasjon 099: malene heter fortsatt «Plateteknikk», mens
// radene ble skrevet om til «Haandbygging». Uten en kobling falt hvert
// haandbyggingskurs paa reservemalen «*», som ikke har «beskrivelse».
sjekk('haandbyggingskurs finner malen sin',
    str_contains($kmal, "'Håndbygging' => 'Plateteknikk'"));
sjekk('… og faar en beskrivelse, ikke reservemalen',
    trim((string) (Kursmal::forKurs(['tema' => 'Håndbygging', 'tittel' => 'Nytt kurs'])['beskrivelse'] ?? '')) !== '');

// ── Drop-in finnes ikke ────────────────────────────────────────────────
//
// Eieren, 2. september: «Det skal heller ikke vaere noe som heter drop inn.»
//
// Her sto det forst ni sjekker paa at drop-in VAR paa plass. Da tilbudet ble
// tatt ned 31. august ble de snudd til det motsatte, men beholdt — som kartet
// tilbake, fordi han samtidig ba om aa «lagre hvordan drop inn virker slik at
// jeg kan be deg hente det frem senere».
//
// Naa er kartet revet med. Migrasjon 136 fjerner kurset, ukereglene,
// kolonnene og verdiene i enum-ene; koden som leste dem er borte. Skal
// drop-in tilbake, maa det bygges paa nytt.
//
// Vakta staar igjen her og i bin/dropinsjekk.mjs. Den siste aapner skjermene
// i en nettleser og leser hva som faktisk staar; denne passer paa kildekoden,
// saa en gjeninnfoering ikke sklir inn ubemerket.
$m136 = file_get_contents(__DIR__ . '/../db/migrations/136_drop_in_finnes_ikke.sql');
sjekk('migrasjon 136 fjerner kurset',
    str_contains($m136, 'DELETE c' . PHP_EOL . '  FROM courses c')
    && str_contains($m136, "c.type = 'dropin' OR c.slug = 'drop-in' OR c.tema = 'Drop-in'"));
// En rad et bilag peker paa, slettes ikke.
sjekk('… men lar et kurs med bookinger staa',
    str_contains($m136, 'NOT EXISTS (SELECT 1 FROM bookings b  WHERE b.course_id = c.id)'));
sjekk('… og fjerner ukereglene',
    str_contains($m136, 'DROP TABLE IF EXISTS dropin_tider;'));
// «fast_fra»/«fast_til» var drop-ins eget vindu, «fra_dropin_tid» pekeren
// tilbake til ukeregelen. Ingen andre kurs har brukt dem.
sjekk('… og kolonnene som bare drop-in brukte',
    str_contains($m136, 'DROP COLUMN IF EXISTS fast_fra')
    && str_contains($m136, 'DROP COLUMN IF EXISTS fast_til')
    && str_contains($m136, 'DROP COLUMN IF EXISTS fra_dropin_tid'));
// Saa lenge «dropin» staar som lovlig verdi, kan et nytt drop-in-kurs lages
// ved et uhell — og da ville alt som nettopp ble fjernet trengtes igjen.
sjekk('… og verdien i enum-ene, saa det ikke kan lages paa nytt',
    str_contains($m136, "MODIFY COLUMN type ENUM('kurs','event','workshop')")
    && str_contains($m136, "MODIFY COLUMN formal ENUM('booking','gavekort','ordre','medlemskap')"));

// Koden: ingen PHP-fil skal nevne drop-in lenger.
$rotDi = dirname(__DIR__);
$phpMedDropin = [];
foreach (['app', 'api'] as $mappe) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rotDi . '/' . $mappe));
    foreach ($it as $fil) {
        if ($fil->isFile() && $fil->getExtension() === 'php') {
            $innhold = (string) file_get_contents($fil->getPathname());
            // llms.php nevner vaktskriptet ved navn. Det er ikke drop-in.
            $innhold = str_replace('bin/dropinsjekk.mjs', '', $innhold);
            if (stripos($innhold, 'dropin') !== false || stripos($innhold, 'drop-in') !== false) {
                $phpMedDropin[] = substr($fil->getPathname(), strlen($rotDi) + 1);
            }
        }
    }
}
sjekk('ingen PHP-fil nevner drop-in',
    $phpMedDropin === [], implode(', ', $phpMedDropin));
// Og hovedfila. Her sto «DROP-IN · HELE UKA · 7 GJESTER» igjen i demodataene
// og slapp gjennom til lissom.no, fordi jeg lette etter «drop-in» og
// «Drop-in» — ikke versaler. Sjekken leter uten aa skille paa store og smaa,
// og taaler baade «drop-in» og «drop in».
sjekk('… og hovedfila nevner det ikke, uansett hvordan det skrives',
    preg_match('~drop.?in~i', $sida2) === 0,
    (string) (preg_match('~.{0,50}drop.?in.{0,50}~i', $sida2, $t) ? $t[0] : ''));
// Endepunktet som satte opp tidene, reglene og prisen.
sjekk('… og api/admin/dropin.php finnes ikke',
    !file_exists($rotDi . '/api/admin/dropin.php'));
// Oppskriften paa aa hente det fram igjen. Den var hele grunnen til at
// nedtakingen i august ikke var en sletting.
sjekk('… og docs/DROP-IN.md er borte',
    !file_exists($rotDi . '/docs/DROP-IN.md'));

// Skjermen: hverken rute, meny eller kategori.
sjekk('drop-in er ute av kursoversikten og toppmenyen',
    !str_contains($sida2, "{ sti: '/drop-in',")
    && !str_contains($sida2, "'Drop-in': ['kurs', 'Drop-in'],"));
sjekk('… og adminskjermen /admin/drop-in er borte',
    !str_contains($sida2, "{ sti: '/admin/drop-in',")
    && !str_contains($sida2, "['Drop-in',       'admindropin'],"));
sjekk('… og inngangen paa medlemskapssida er borte',
    !str_contains($sida2, 'mdiTittel:') && !str_contains($sida2, '>Book drop-in</x-import>'));
// «Drop-in» sto som en kurskategori man kunne velge i admin.
sjekk('… og «Drop-in» er ikke lenger en kategori',
    !in_array('Drop-in', Kursmal::KATEGORIER, true));

// Og i basen, naar migrasjonen er kjort.
if (!DB::harTabell('dropin_tider')) {
    sjekk('basen har ingen drop-in-kurs igjen',
        (int) DB::verdi("SELECT COUNT(*) FROM courses
                          WHERE slug = 'drop-in' OR tema = 'Drop-in' OR tittel LIKE '%rop-in%'") === 0);
    sjekk('… og hverken fast_fra, fast_til eller fra_dropin_tid',
        !DB::harKolonne('courses', 'fast_fra')
        && !DB::harKolonne('courses', 'fast_til')
        && !DB::harKolonne('course_sessions', 'fra_dropin_tid'));
    // Uten verdien i enum-et kan et drop-in-kurs ikke lages, heller ikke ved
    // et uhell eller rett i basen.
    $typeKol = (string) DB::verdi(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'type'"
    );
    sjekk('… og «dropin» er ikke en lovlig kurstype lenger',
        stripos($typeKol, 'dropin') === false, $typeKol);
}

// ── Gjentakelsen i kursveiviseren setter opp en ekte regel ─────────────
//
// Eieren, 1. september: «La ut kurs for barn hver fredag i 10 uker fremover
// ved aa trykke paa gjentagelse ukentlig 10 ganger. Men som du ser her saa
// ligger det bare ute en dato».
//
// «Ukentlig · 10 ganger» ble skrevet inn i feltet «gjentas» paa kurset og ble
// aldri annet enn en etikett: datoene ble lagt ut én og én med «nydato», og
// ingen regel ble satt opp. Veiviseren «Ny kursdato» paa Oversikt har gjort
// det riktig hele tiden — det var kursoppsettet som ikke gjorde det.
sjekk('kursveiviseren setter opp gjentakelsen',
    str_contains($sida2, "const serieKall = ()")
    && str_contains($sida2, ".then(serieKall)"));
// Rekkefolgen betyr noe: regelen settes opp ETTER at datoene er lagt ut, saa
// Serier::fyllPaa teller dem med i «ti ganger» framfor aa legge ti paa toppen.
sjekk('… etter at datoene er lagt ut, ikke for',
    strpos($sida2, "handling: 'nydato',") < strpos($sida2, "const serieKall = ()")
    || strpos($sida2, ".then(serieKall)") > strpos($sida2, "handling: 'nydato',"));
// Og Serier::fyllPaa maa faktisk telle en dato som alt laa der. Uten $alt++
// utenfor if-en ville regelen lagt ut ti nye i tillegg til den ene.
$serier = file_get_contents(__DIR__ . '/../app/lib/serier.php');
sjekk('… og en dato som alt laa der teller med i «ti ganger»',
    str_contains($serier, '$laget += $ny;')
    && str_contains($serier, '$alt++;'));

// ── «N ganger» faar plass i vinduet ────────────────────────────────────
//
// kurs_serier har to tall som lett forveksles: «antall» er hvor mange ganger
// det skal gaa, «uker_fram» er hvor langt fram datoene legges ut. Ber du om
// ti ganger i et vindu paa aatte uker, faar du aatte datoer.
//
// Fire steder setter opp serier. Regelen sto ett sted og manglet de tre
// andre: kalenderen sendte ikke ukerFram i det hele tatt (serveren falt
// tilbake paa aatte), og «fast dag» brukte brukerens vindu raatt.
//
// Maalt: ti ganger ukentlig med startdato tre uker fram ga 9 av 10 datoer med
// det gamle vinduet paa 11 uker, og 10 av 10 med 15.
sjekk('regelen for vinduet staar ett sted',
    str_contains($sida2, 'ukerForSerie(monster, antall, startDato, minst)'));
sjekk('… og alle fire stedene bruker den',
    substr_count($sida2, 'this.ukerForSerie(') === 4);
// Avstanden til startdatoen maa med: vinduet regnes fra i dag, tellingen fra
// den forste datoen.
sjekk('… og den regner med avstanden til startdatoen',
    str_contains($sida2, 'Math.ceil(n * perGang + dagerTil / 7) + 1'));
// Et vindu noen har satt selv skal aldri senkes.
sjekk('… uten aa senke et vindu noen har satt selv',
    str_contains($sida2, 'Math.max(trengs, gulv)'));
// Kalenderen sendte ikke vinduet i det hele tatt.
sjekk('… og kalenderen sender det ogsaa',
    str_contains($sida2, "ukerFram: this.ukerForSerie(monsterKl, antallGanger, d, 0),"));

// ── Kalenderen: to feil som gjemte kurs ────────────────────────────────
//
// Eieren, 1. september: «Kurset lag din egen bolle 3 september. Den vises paa
// min admin, men ikke monica sin. Er det noe feil eller manglende synk i
// admin brukerne» — og etterpaa: «Paa monica sin saa vises det paa mobil
// admin ikke pc».
//
// To ulike feil, som saa like ut.
//
// 1) DAGSVISNINGEN HADDE INGEN PLASS TIL DET SOM IKKE ER TILDELT NOEN.
//    Spaltene lages per kursholder, og en okt uten tildelt holder passet
//    ingen av dem — den ble aldri tegnet. Mobilen staar i listevisning, som
//    tegner alt; PC-en staar i dagsvisning. Skjermbildet hans sa det selv:
//    «· 2 okter · 0 av 20 plasser» oeverst, og Monica-spalta under med «1
//    okt». Den andre var borte.
//
//    Maalt: en okt uten kursholder i dag var usynlig paa 1400 px og synlig
//    paa 390 px. Etterpaa staar den begge steder.
sjekk('dagsvisningen har en spalte for det som ikke er tildelt noen',
    str_contains($sida2, "const UTEN_HOLDER = 'Uten kursholder';")
    && str_contains($sida2, 'this.klHoldere().concat([UTEN_HOLDER]).map(kn => {'));
sjekk('… og den henter oktene uten holder',
    str_contains($sida2, "? dagensAlle.filter(e => !e.holder)"));
// En tom spalte hver dag ville vaert stoy. Den skal bare staa naar den har noe.
sjekk('… og staar bare naar den har noe',
    str_contains($sida2, 'const staarFast = kn => kn === stdHolder && kn !== UTEN_HOLDER;'));
// Spalta er ingen person: ingen beskjeder aa sende, ingen side aa aapne.
sjekk('… og er ikke en person',
    str_contains($sida2, 'erPerson: kn !== UTEN_HOLDER,'));

// 2) KALENDEREN HENTET HVER MAANED ÉN GANG OG ALDRI MER.
//    «kalHentet» merker maaneden som hentet, og klHent() gaar rett ut igjen.
//    klFrisk() nullstiller merkene — men kalles bare etter endringer man gjor
//    selv. Gjorde noen andre endringen, kom den aldri fram.
//
//    Maalt: med kalenderen aapen la jeg et kurs i basen; det kom ikke fram
//    ved aa vente eller klikke. Etterpaa kommer det naar fanen hentes fram.
sjekk('kalenderen hentes paa nytt', str_contains($sida2, '  oppfriskAdmin() {'));
sjekk('… naar fanen kommer fram, og hvert minutt',
    str_contains($sida2, 'this._kalenderur = setInterval(() => this.oppfriskAdmin(), 60000);')
    && str_contains($sida2, "document.addEventListener('visibilitychange', this._kalenderSynlig);"));
// Gjaldt foer bare kalenderen. Da eieren meldte at deltakere lagt inn av en
// annen ikke kom fram paa Oversikt heller, ble den utvidet til hele admin.
sjekk('… paa alle adminskjermene',
    str_contains($sida2, "if (!this.erAdminSkjerm(this.state.side) || document.hidden) return;"));
// Midt i en flytting skal ingenting rykke under fingeren.
sjekk('… og ikke midt i en flytting',
    str_contains($sida2, 'if (this.state.klDrag) return;'));
// Merkene nullstilles, men dataene blir staaende til de nye kommer — ellers
// ville kalenderen blinket tom hvert minutt.
sjekk('… uten aa blinke tom',
    str_contains($sida2, 'this.setState({ kalHentet: {} });'));
sjekk('… og klokka ryddes naar skjermen forlates',
    str_contains($sida2, 'clearInterval(this._kalenderur);'));

// ── Alle utsendelser er maler Monica kan endre ─────────────────────────
//
// Eieren, 1. september: «hvorfor kan ikke alle vaere redigerbare? og ligge i
// et eget kort paa oversikt som heter maler» — og «ja, alle 29».
//
// Tekstene laa to steder: ni i «notification_templates», tjue skrevet rett
// inn i PHP-en. Og selv de ni kunne ingen endre — lista paa varselskjermen
// var designdata, og dialogen viste teksten uten aa lagre den. Det fantes
// ikke noe endepunkt i det hele tatt.
//
// Denne proeven er den som holder det paa plass: skriver noen en ny e-post
// rett inn i koden, blir den roed.
$sendere = [];
foreach (glob(dirname(__DIR__) . '/{api,api/admin,app/lib}/*.php', GLOB_BRACE) as $f) {
    $kort = basename($f);
    // varsler.php ER avsenderen. beskjed.php sender det Monica selv skriver
    // i skjemaet — der finnes ingen tekst aa lagre som en mal.
    if (in_array($kort, ['varsler.php', 'beskjed.php', 'test-varsel.php'], true)) {
        continue;
    }
    $kode = file_get_contents($f);
    foreach (['Varsel::epost(', 'Varsel::sms(', 'Varsel::tilAdmin('] as $kall) {
        if (str_contains($kode, $kall)) {
            $sendere[] = $kort . ' → ' . rtrim($kall, '(');
        }
    }
}
sjekk('ingen sender e-post eller SMS utenom malene',
    $sendere === [], implode(' | ', $sendere));

// Malene koden kaller maa finnes. Varsel::mal() skriver en linje i loggen og
// gaar videre naar malen mangler — altsaa ville en skrivefeil i navnet betydd
// at meldingen stilltiende sluttet aa gaa ut.
$kalt = [];
$kildekode = '';
foreach (array_merge(
    glob(dirname(__DIR__) . '/{api,api/admin,app/lib}/*.php', GLOB_BRACE),
    [dirname(__DIR__) . '/bin/cron.php']
) as $f) {
    // maler.php er selve registeret. Der staar hvert eneste navn, saa tar vi
    // den med, beviser proeven bare at registeret er likt seg selv.
    if (basename($f) === 'maler.php') {
        continue;
    }
    $kode = file_get_contents($f);
    $kildekode .= $kode;
    preg_match_all("/Varsel::mal(?:TilAdmin)?\(\s*'([a-z_]+)'/", $kode, $m);
    foreach ($m[1] as $navn) {
        $kalt[$navn] = true;
    }
}
$kalt = array_keys($kalt);
sjekk('… og det er faktisk maler aa se paa', count($kalt) >= 20, count($kalt) . ' navn kalles');

$iRegister = Maler::iBruk();
$utenRegister = array_values(array_diff($kalt, $iRegister));
sjekk('hver mal koden kaller staar i registeret',
    $utenRegister === [], implode(', ', $utenRegister));

// Registeret skal ikke love maler som ikke finnes heller.
//
// Navnet naar ikke alltid Varsel::mal() som en ferdig tekst: noen velges i en
// ternaer («pakke eller henting»), ett staar i en konstant, ett kommer inn som
// argument til en hjelpefunksjon. Derfor leter vi etter navnet i kildekoden,
// ikke bare i kallene — et navn som ikke staar noe sted, sendes ingen steder.
$utenKall = [];
foreach ($iRegister as $n) {
    if (!str_contains($kildekode, "'" . $n . "'")) {
        $utenKall[] = $n;
    }
}
sort($utenKall);

// Her sto to navn en stund: «medlemskap_fornyet» og «betaling_feilet» laa i
// basen fra 002, men ingen kode sendte dem — statusen paa maanedstrekket ble
// aldri hentet tilbake fra Vipps. Naa gjor den det, og lista er tom igjen.
sjekk('… og registeret lover ingen mal som ikke kalles',
    $utenKall === [], implode(', ', $utenKall));

// Feltene. Eieren: «jeg vil ha en oversikt over komandoer som jeg kan
// kopiere, slike som denne {varelinjer}». Et felt som staar i teksten men
// ikke i registeret, staar igjen som raa tekst i e-posten kunden faar.
if (DB::harTabell('notification_templates')) {
    $feilFelt = [];
    foreach (DB::alle('SELECT navn, emne, tekst FROM notification_templates') as $m) {
        $navn = (string) $m['navn'];
        if (!in_array($navn, $iRegister, true)) {
            continue;
        }
        $kjente = array_column(Maler::felter($navn), 'felt');
        preg_match_all('/\{([a-zA-Z_]+)\}/', (string) $m['emne'] . ' ' . (string) $m['tekst'], $funn);
        foreach (array_unique(array_diff($funn[1], $kjente)) as $ukjent) {
            $feilFelt[] = $navn . ' → {' . $ukjent . '}';
        }
    }
    sjekk('ingen mal bruker et felt den ikke faar',
        $feilFelt === [], implode(' | ', $feilFelt));
}

// Skjermen og endepunktet.
$malApi = file_get_contents(dirname(__DIR__) . '/api/admin/maler.php');
sjekk('malene kan endres fra admin', str_contains($malApi, "if (\$handling !== 'lagre') {"));
sjekk('… og slettes', str_contains($malApi, "if (\$handling === 'slett') {"));
// Her sto en sperre: maler koden kaller kunne ikke slettes. Eieren,
// 1. september: «jeg oensker mulighet til aa slette de malene jeg selv vil».
// Naa kan de slettes, men bare med et uttrykkelig ja — foelgen er at
// meldingen slutter aa gaa ut, og ingenting sier fra om det etterpaa.
sjekk('… ogsaa de koden sender selv, men da med et uttrykkelig ja',
    str_contains($malApi, "\$sendesAutomatisk = in_array(\$navn, \$IBRUK, true);")
    && str_contains($malApi, "if (\$sendesAutomatisk && Foresporsel::tekst('bekreftet') !== 'ja') {"));
sjekk('… og skjermen sier hva foelgen er foer den spoer',
    str_contains($sida2, 'Denne sendes automatisk av systemet. Slettes den, slutter meldingen å gå ')
    && str_contains($sida2, "navn: valgt.navn, bekreftet: 'ja' }"));
// Slettinga skrives i revisjonsloggen, med om malen gikk ut av seg selv.
sjekk('… og det staar i loggen hva som ble slettet',
    str_contains($malApi, "['navn' => \$navn, 'sendes_automatisk' => \$sendesAutomatisk]"));
sjekk('… og et ukjent felt avvises for det naar kunden',
    str_contains($malApi, "Denne malen kjenner ikke {"));
sjekk('Maler-skjermen finnes', str_contains($sida2, "erAdminMaler: side === 'adminmaler',"));
sjekk('… og staar som kort paa Oversikt', str_contains($sida2, "kort('Maler',"));
sjekk('… med feltene til aa kopiere', str_contains($sida2, 'navigator.clipboard.writeText(t)'));

// ── Hentetiden staar ett sted ──────────────────────────────────────────
//
// Eieren, 1. september: «all hentetid av keramikk som er laget her er to til
// fire uker, de faar beskjed eller kan se paa min side eller
// https://lissom.no/ferdigbrent».
//
// Sidene sa «to uker» fire steder og «2–3 uker» tre steder, og butikkassa
// lovet «Hentetid i butikken: to uker» mens e-posten lovet to virkedager.
sjekk('hentetida staar som 2–4 uker, ett sted',
    str_contains(file_get_contents(dirname(__DIR__) . '/app/lib/kursmal.php'),
        "klar til henting etter 2–4 uker"));
sjekk('… og ingen side sier noe annet',
    !str_contains($sida2, '2–3 uker') && !str_contains($sida2, 'etter ca. to uker'));
// Butikkvarer er ikke keramikk laget her, og skal ikke ha et antall dager.
sjekk('kassa lover ingen hentetid paa butikkvarer',
    !str_contains($sida2, 'Hentetid i butikken'));
$butikkmal = DB::harTabell('notification_templates')
    ? (string) DB::verdi("SELECT tekst FROM notification_templates WHERE navn = 'butikkordre'") : '';
sjekk('… og butikkbekreftelsen heller ikke',
    $butikkmal === '' || (!str_contains($butikkmal, 'virkedager') && !str_contains($butikkmal, 'to uker')));
// Den som valgte «Send som pakke» skal ikke faa beskjed om aa hente paa Teie.
sjekk('butikkbekreftelsen leser leveringsvalget',
    str_contains(file_get_contents(dirname(__DIR__) . '/app/lib/booking.php'),
        "\$erPakke ? 'butikkordre_pakke' : 'butikkordre'"));

// ── Admin henter paa nytt naar noen andre har endret noe ───────────────
//
// Eieren, 1. september: «Det er lagt til 2 deltakere lag din egen bolle, men
// ikke synlig», «kurset lag din egen bolle 3 september vises paa min admin,
// men ikke Monica sin», «og naa er min kalender tom paa 3 september ogsaa».
//
// Det var ingen feil i koblingene. Jeg la inn to deltakere paa den datoen og
// spurte serveren direkte:
//
//   oversikt.php  → begge to, overst under «Nye paameldinger»
//   kalender.php  → «Lag din egen bolle | pameldt=2 | [Prove En, Prove To]»
//
// Serveren svarte riktig hele veien. Feilen var at skjermen aldri spurte paa
// nytt: hentAdmin() kalles 28 steder, og alle er etter dine EGNE lagringer.
// Legger Monica inn en deltaker, faar din skjerm det aldri vite.
//
// Maalt i nettleseren, for og etter: «Nye paameldinger» sto paa 4, en annen
// la inn en paamelding, fanen fikk fokus igjen — og tallet ble 5 uten
// omlasting, det samme som en omlasting gir. Og i kalenderen: en ny kursdato
// lagt inn utenfra kom fram ved fokus, paa baade 390 px og 1400 px.
sjekk('admin henter paa nytt naar man kommer tilbake til fanen',
    str_contains($sida2, '  oppfriskAdmin() {'));
sjekk('… og det gjelder alle adminskjermene, ikke bare kalenderen',
    str_contains($sida2, "if (!this.erAdminSkjerm(this.state.side) || document.hidden) return;")
    && str_contains($sida2, "    this._adminHentes = false;\n    this.hentAdmin();\n  }"));
sjekk('… ogsaa paa klokke mens skjermen staar framme',
    str_contains($sida2, 'this._kalenderur = setInterval(() => this.oppfriskAdmin(), 60000);'));
sjekk('… og med det samme fanen faar fokus',
    str_contains($sida2, "this._kalenderSynlig = () => { if (!document.hidden) this.oppfriskAdmin(); };"));

// Kalenderen henter maaned for maaned. Nullstiller vi dataene og ikke bare
// merkene, blir skjermen tom til svaret kommer — hvert minutt.
sjekk('kalenderen blinker ikke naar den oppfriskes',
    str_contains($sida2, "if (this.state.side === 'adminkalender') this.setState({ kalHentet: {} });"));

// Ingenting skal rykke under fingeren, og et aapent skjema skal staa i fred.
sjekk('… ikke midt i en flytting',
    str_contains($sida2, "    if (this.state.klDrag) return;\n    if (this.state.kRed"));
sjekk('… og ikke mens et skjema eller en rute staar aapen',
    str_contains($sida2, "if (this.state.kRed || this.state.detalj || this.state.klKursRedId) return;"));

// Serveren var aldri feilen. Spoerringene skal fortsatt ta med begge
// statusene en paamelding kan ha, ellers forsvinner de som ikke har betalt.
$ovr = file_get_contents(dirname(__DIR__) . '/api/admin/oversikt.php');
sjekk('nye paameldinger tar med baade betalte og reserverte',
    str_contains($ovr, "WHERE b.status IN ('betalt','reservert')"));

// ── Betalingene i kassa ────────────────────────────────────────────────
//
// Eieren, 1. september: «Jeg mangler og en viktig funksjon som maa ligge i
// kortet kasse. 1. her maa betalinger ses 2. betalinger maa vaere klikkbare
// 3. jeg maa kunne reversere belop naar jeg klikker inn.»
//
// Alle tre fantes fra for — men under OEkonomi, tre klikk unna og et annet
// sted enn der man staar naar kunden er ved disken. Det er samme liste og
// samme refusjon; den staar naa der den brukes. Paa sporsmaal om omfang
// svarte han: alt, med sok — og i tillegg dagsoppgjor, kvittering paa nytt
// og sok.
$bet = file_get_contents(dirname(__DIR__) . '/api/admin/betalinger.php');

sjekk('kassa har en egen fane for betalinger',
    str_contains($sida2, "['Betalinger',       'betalinger', 'adminuttak'],"));
sjekk('… og de andre fanene viker for den',
    str_contains($sida2, "utVarerVis: del === 'butikk' || del === 'intern',"));
sjekk('… og lista er sokbar',
    str_contains($sida2, 'settBtSok: e => this.setState({ btSok: e.target.value }),'));
sjekk('… og radene er klikkbare',
    str_contains($sida2, 'btApen: s2.btApen === b.referanse ? null : b.referanse,'));

// Reverseringen sender penger. Da skal det bekreftes med navn og belop, og
// det skal staa at det ikke kan angres — for det kan det ikke.
sjekk('reverseringen bekreftes med navn og belop',
    str_contains($sida2, "window.confirm('Reversere ' + this.kroner(ore) + ' til '"));
sjekk('… og sier at det ikke kan angres',
    str_contains($sida2, 'Pengene går tilbake på Vipps med det samme. Dette kan ikke angres.'));
// Tomt felt = hele resten. Skrevet tall = bare det, men aldri mer enn det
// som staar igjen.
sjekk('… og delbelop er mulig, men aldri mer enn det som staar igjen',
    str_contains($sida2, 'const ore = skrevet > 0 ? Math.min(skrevet * 100, igjen) : igjen;'));

// Et kontantsalg har aldri vaert innom Vipps. Foer denne sto refusjonen og
// ventet paa en feil fra Vipps paa en referanse Vipps ikke kjenner.
sjekk('kontantsalg kan ikke reverseres gjennom Vipps',
    str_contains($bet, "if ((string) \$betaling['type'] === 'manuell') {"));
sjekk('… og skjermen sier hvorfor, framfor aa mangle knappen',
    str_contains($sida2, 'refSperreTekst:'));

// Dagsoppgjoret skal vise det som faktisk staar igjen.
sjekk('dagsoppgjoret deler dagen i kontant og Vipps',
    str_contains($sida2, 'const kontant = iDag.filter(b => !b.erVipps).reduce((n, b) => n + netto(b), 0);')
    && str_contains($sida2, 'const vipps = iDag.filter(b => b.erVipps).reduce((n, b) => n + netto(b), 0);'));
sjekk('… og trekker fra det som er refundert',
    str_contains($sida2, 'const netto = b => Math.max(0, (b.belopOre || 0) - (b.refundertOre || 0));'));
sjekk('… og teller bare gjennomforte betalinger',
    str_contains($sida2, "&& ['betalt', 'delvis_refundert'].indexOf(String(b.status || '')) !== -1);"));
sjekk('serveren sier hvilken betalingsmaate det var',
    str_contains($bet, "'maate'      => (string) \$p['type'] === 'manuell' ? 'Kontant' : 'Vipps',"));

// Kvitteringen sendes paa nytt med de samme funksjonene bookingen og ordren
// bruker naar de blir betalt — ingen ny tekst, og ingen ny ordre.
sjekk('kvitteringen kan sendes paa nytt',
    str_contains($bet, "if (Foresporsel::tekst('handling') === 'kvittering') {"));
sjekk('… med de samme bekreftelsene som ved betaling',
    str_contains($bet, 'Booking::sendBekreftelse((int) $booking[\'id\']);')
    && str_contains($bet, 'Booking::sendOrdrebekreftelse((int) $ordre[\'id\']);'));
// Gavekort og medlemstrekk har ingen kvittering av dette slaget. Da skal det
// staa, framfor at skjermen svarer «sendt» uten aa ha sendt noe.
sjekk('… og sier fra naar det ikke finnes noen kvittering',
    str_contains($bet, "Svar::feil('Denne betalingen har ingen kvittering å sende. '"));
sjekk('… og knappen staar bare der det finnes en',
    str_contains($sida2, 'harKvittering: !!(b.bookingId || b.ordreId),'));

// Et sok som bare naar to hundre rader tilbake finner ikke betalingen fra
// forrige maaned.
sjekk('lista strekker lenger enn to hundre rader',
    str_contains($bet, 'LIMIT 1000'));

// ── Tre datoer paa kurskortet ──────────────────────────────────────────
//
// Kortet bar foer bare den FOERSTE datoen, og den ble lest som den eneste.
// Nybegynner dreiekurs gaar 9. og 16. september og 7. oktober; kortet sa
// «onsdag 9. – torsdag 10. september», og oktober fantes ikke for den som saa
// paa lista. Eieren, 31. august: «paa disse kortene maa vi fjerne dato» — saa
// datoen ble tatt bort.
//
// Men da forsvant ogsaa det folk leter etter. Eieren, 1. september: «paa
// kortene kurs saa ba jeg deg fjerne datoer, men jeg vil at det skal vises 3
// planlagte datoer og se fler datoer».
//
// Foerste forsok la dem som piller UNDER kortet, fordi CourseCard er en ferdig
// komponent utenfra. Eieren: «Jaevla stoegt» — «de maa jo vaere inni kortet og
// diskret». Kortet har alt et felt for det: «date» tegnes med kalenderikon ved
// siden av varigheten, inne i kortet.
sjekk('kortene har tre datoer', str_contains($sida2, '  kortDatoer(k) {'));
sjekk('… og den brukes av alle kortlistene',
    str_contains($sida2, '.map(k => Object.assign({}, k, this.kortDatoer(k), {'));
// Kortet baerer datoene som «okter»; «datoer» er navnet i katalogen.
sjekk('… og finner datoene under begge navnene',
    str_contains($sida2, 'const alle = (k.okter || k.datoer || []).filter(d => d && d.dag);'));
// Tre tider samme dag er én dato paa kortet, ikke tre.
sjekk('… og teller dager, ikke tidspunkt',
    str_contains($sida2, 'alle.forEach(d => { if (dager.indexOf(d.dag) === -1) dager.push(d.dag); });'));
sjekk('… viser hoyst tre', str_contains($sida2, 'const vist = dager.slice(0, 3).map(kort).join(\' · \');'));
sjekk('… og sier hvor mange flere det er',
    str_contains($sida2, "return { kdDato: flere > 0 ? vist + ' +' + flere : vist };"));

// Inne i kortet, ikke under det. «date» er kortets eget felt.
sjekk('datoene staar i kortets eget datofelt',
    substr_count($sida2, 'date="{{ k.kdDato }}"') === 3);
// Pillene under kortet er borte, og med dem den ekstra ramma rundt.
sjekk('… og pillestripa under kortet er borte',
    !str_contains($sida2, 'k.kdListe') && !str_contains($sida2, 'kdSeFlere'));

// ── «Kasse» i kalenderen aapner kassa ──────────────────────────────────
//
// Eieren, 1. september, med et skjermbilde av ruta knappen aapnet: «Kasse fra
// kalender er ikke ferdig».
//
// Ruta sa «Kassen er bygget i hovedprosjektet og kobles paa her. Knappen skal
// aapne kassevisningen direkte fra kalenderen» — en knapp som lovet noe og
// gjorde ingenting. Kassa finnes: Oversikt → Kassa, samme sted kortet paa
// oversikten sender deg.
sjekk('placeholderen for kassa er borte',
    !str_contains($sida2, 'kobles på her'));
sjekk('… og knappen aapner den ekte kassa',
    str_contains($sida2, "klKasse: () => this.gaaAdmin('adminuttak', {"));
sjekk('… med samme utgangspunkt som kortet paa oversikten',
    substr_count($sida2, "utKurv: {}, utKunde: '', utSok: '', utDel: 'salg',") === 2);

// ── Alle fire medlemskapene, ikke bare de tre ──────────────────────────
//
// Eieren, 1. september: «Funker det paa alle medlemskap?»
//
// Nei. To ting sto igjen, og begge gjaldt medlemskap som betales én gang.
//
// 1) api/medlemskap.php opprettet ALLTID en loepende avtale i Vipps, uansett
//    plan. «Prov Lissom» — ti timer i lopet av tretti dager — ville faatt et
//    trekk hver maaned for noe som er over. Innmeldingen i bli-medlem.php har
//    alltid skilt paa dette; her sto skillet ikke.
//
// 2) Medlemskap::startEngangs() satte ikke «idempotency_key», og kolonna er
//    NOT NULL uten standardverdi. Innsettingen kastet hver eneste gang. Hele
//    veien for medlemskap som betales én gang — «Prov Lissom», og alle som
//    valgte «ordner selv» — dode i en databasefeil for Vipps ble kontaktet.
//
// Maalt: for rettelsen svarte «Prov Lissom» «Field 'idempotency_key' doesn't
// have a default value». Etterpaa kommer alle fire helt fram til Vipps.
$mapi = file_get_contents(dirname(__DIR__) . '/api/medlemskap.php');
$mlibEngangs = file_get_contents(dirname(__DIR__) . '/app/lib/medlemskap.php');

sjekk('engangsplaner faar ikke fast trekk',
    str_contains($mapi, "if ((int) (\$plan['engangs'] ?? 0) === 1) {\n            \$betaling = 'selv';"));
sjekk('… og planer som krever fast trekk faar det',
    str_contains($mapi, "if (Medlemskap::kreverFastTrekk(\$plan)) {\n            \$betaling = 'trekk';"));
sjekk('… og de to veiene gaar hver sin vei',
    str_contains($mapi, "\$ut = \$betaling === 'trekk'\n                ? Medlemskap::startAvtale(\$medlem, \$planNavn)\n                : Medlemskap::startEngangs(\$medlem, \$planNavn);"));
sjekk('… og en ukjent plan avvises for noe opprettes',
    str_contains($mapi, "if (\$plan === null) {\n            Svar::feil('Ukjent medlemskap.');"));

// Kolonna er NOT NULL uten standardverdi, saa en betaling uten noekkel kan
// ikke settes inn i det hele tatt.
sjekk('engangsbetalingen setter idempotency-noekkelen',
    str_contains($mlibEngangs, "'subscription_id' => \$id,")
    && str_contains($mlibEngangs, "'idempotency_key' => Vipps::uuid(),"));
// Alle stedene som oppretter en betaling maa sette den. Ett sted som glemmer
// det er nok til at den veien er doed.
$utenNokkel = [];
foreach (glob(dirname(__DIR__) . '/{api,api/admin,app/lib}/*.php', GLOB_BRACE) as $f) {
    $kode = file_get_contents($f);
    $fra = 0;
    while (($i = strpos($kode, "DB::settInn('payments'", $fra)) !== false) {
        // Fram til den avsluttende «]);» — et fast vindu kutter en lang
        // liste midt i, og da ser noekkelen ut til aa mangle.
        // Feltene bygges ofte i en variabel rett over innsettingen
        // (api/ordre.php, app/lib/booking.php). Da staar noekkelen der, ikke
        // i kallet — saa vi ser bakover ogsaa.
        $slutt = strpos($kode, "]);", $i);
        $fram  = ($slutt === false ? 2000 : $slutt - $i);
        $start = max(0, $i - 2500);
        $blokk = substr($kode, $start, ($i - $start) + $fram);
        if (!str_contains($blokk, 'idempotency_key')) {
            $utenNokkel[] = basename($f) . ' linje ' . (substr_count(substr($kode, 0, $i), "\n") + 1);
        }
        $fra = $i + 20;
    }
}
sjekk('ingen oppretter en betaling uten idempotency-noekkel',
    $utenNokkel === [], implode(' | ', $utenNokkel));

// ── Bare butikkvarer kan ligge i handlekurven ──────────────────────────
//
// Eieren, 1. september: «Funker det overalt?»
//
// Nei — det var ett til. kjopKurv() slaar hver linje opp blant butikkvarene,
// saa en noekkel som ikke er en vare gir «finnes ikke i butikken lenger», og
// raadet om aa ta den ut av kurven er umulig aa folge.
//
// Tre steder la noe annet enn en vare i kurven:
//   medlemskapet   tre knapper paa Min side   (rettet over)
//   gavekortet     «Paafyll» paa Min side, til faste 500 kroner
//   et kurs        en ubrukt binding paa bookingskjermen
//
// Maalt i nettleseren: «Paafyll → Gavekort → Legg til» og deretter «Fullfor
// bestilling» → «Godkjenn i Vipps» ga «Kan ikke bestille alt — Gavekort
// finnes ikke i butikken lenger».
//
// Gavekortet har heller ikke fast pris: belop, mottaker og hilsen er hele
// poenget, og de finnes bare paa gavekortsida.
sjekk('gavekortet legges ikke i handlekurven',
    str_contains($sida2, "kjop: this.go('gavekortside'),"));
sjekk('… og kurs legges ikke i den heller',
    !str_contains($sida2, 'bookTilKurv:'));

// Alt som fortsatt kan legges i kurven maa vaere noe kassa finner igjen:
// «#<id>» paa en butikkvare, eller varens tittel. Et gavekortbelop hoerer
// til forhaandsvisningen i designverktoyet, der det ikke gaar mot serveren.
preg_match_all('/this\.leggTil\((.*)$/m', $sida2, $m);
$kurvKall = array_map('trim', $m[1]);
$mistenkelige = array_values(array_filter($kurvKall, static fn(string $x): bool
    => !str_contains($x, "'#'") && !str_starts_with($x, "'Gavekort kr. '")));
sjekk('ingenting annet enn butikkvarer legges i kurven',
    $mistenkelige === [], implode(' | ', $mistenkelige));
sjekk('… og det er faktisk kall aa se paa', count($kurvKall) >= 3, count($kurvKall) . ' kall');

// ── Ingen slippes inn uten at betalingen er i havn ─────────────────────
//
// Eieren, 1. september: «Hun fikk medlemskap selv om betalingen ikke gikk inn
// hva faen».
//
// Godkjenningen i admin sjekket avtalen i Vipps naar sokeren hadde valgt fast
// trekk. Valgte hen «ordner selv», ble ingenting sjekket: ett trykk paa
// Godkjenn ga status «prove» — som er full tilgang — og svaret sa «gjor opp
// selv for hver periode», som om alt var i orden.
//
// Maalt paa den gamle koden: soknad med betaling «selv» og betalingsraden paa
// «venter» ga {"ok":true}, medlemmet ble «prove», og betalingen sto fortsatt
// som «venter». Ingen penger, full tilgang.
$soknader = file_get_contents(dirname(__DIR__) . '/api/admin/soknader.php');
$mlib     = file_get_contents(dirname(__DIR__) . '/app/lib/medlemskap.php');

sjekk('«ordner selv» sjekkes for godkjenning',
    str_contains($soknader, "if (\$vedtak === 'godkjent' && \$betaling === 'selv') {"));
sjekk('… og avvises naar betalingen ikke er kommet inn',
    str_contains($soknader, "if (!in_array(\$avtaleStatus, ['aktiv', 'ingen'], true)) {\n        Svar::feil('Betalingen fra '"));
// Faar vi ikke svar fra Vipps, vet vi ikke — og da skal ingen inn.
sjekk('… og ingen slippes inn naar Vipps ikke svarer',
    str_contains($soknader, "if (\$avtaleStatus === 'ukjent') {"));
sjekk('… mens fast trekk sjekkes som for',
    str_contains($soknader, "if (\$vedtak === 'godkjent' && \$betaling === 'trekk') {"));

// Fasiten er Vipps, ikke raden vaar: kunden kan ha betalt uten aa komme
// tilbake til nettsiden, og da skal godkjenningen ikke stoppes.
sjekk('sjekken spor Vipps, ikke bare vaar egen rad',
    str_contains($mlib, 'public static function engangsBetalt(int $medlemId): array')
    && str_contains($mlib, '$svar = Vipps::hentBetaling($ref);'));
sjekk('… og retter opp en betaling som er bokfoert men ikke slaatt paa',
    str_contains($mlib, "if ((string) \$betaling['status'] === 'betalt') {\n            self::betaltEngangs((int) \$a['id']);"));
// En feil fra Vipps skal ikke leses som «betalt».
sjekk('… og sier «ukjent» framfor aa gjette naar Vipps feiler',
    str_contains($mlib, "return ['status' => 'ukjent', 'avtale' => \$a];"));

// Svaret paa skjermen maa si hva som faktisk gjelder. «Foerste trekk gaar ut
// i natt» til en som gjor opp selv er et trekk som aldri kommer.
sjekk('svaret lover ikke et trekk til en som gjor opp selv',
    str_contains($soknader, "\$betaling !== 'selv' && \$avtaleStatus === 'aktiv'"));
sjekk('… og sier fra naar det ikke finnes noen betaling i det hele tatt',
    str_contains($soknader, "'Godkjent. Det finnes ingen betaling på '"));

// ── Medlemskap kan ikke legges i handlekurven ──────────────────────────
//
// Eieren, 1. september, med et skjermbilde fra et medlem som ikke fikk
// betalt: «Har faatt denne fra et medlem som skal betale!!!!!!!!»
//
// Kassa sa «Abonnement: Basis 30 finnes ikke i butikken lenger». Den hadde
// rett: kjopKurv() slaar hver linje opp i butikkvarene, og et medlemskap
// staar ikke der. Tre knapper paa Min side la det likevel i kurven. Veien
// var stengt fra foerste trykk — ikke en gammel kurv som hadde blitt
// staaende, men en doer som aldri hadde gaatt opp.
//
// Et medlemskap er en avtale i Vipps som belastes hver periode. Det kan
// aldri bli en ordre.
sjekk('ingen knapp legger et medlemskap i handlekurven',
    !str_contains($sida2, "leggTil('Abonnement: "));
sjekk('«Forny» spor hvordan det skal betales i stedet',
    str_contains($sida2, "aFornyAbo: this.apneFornyValg("));
sjekk('… og det gjor plankortet ogsaa',
    str_contains($sida2, "'Lukk', null, false, null, this.aboPlanValg(p.navn)),"));
sjekk('… og begge gaar til handling=start',
    str_contains($sida2, "this.medlemskapKall({ handling: 'start', plan: navn, betaling: maate }"));

// Kurver som stod aapne da rettelsen gikk ut, skal ikke moete den samme
// doede enden. De var det eneste stedet en «Abonnement:»-linje kunne
// komme fra etter dette.
sjekk('en kurv som alt har et medlemskap i seg blir ikke en blindvei',
    str_contains($sida2, "const abonnement = Object.keys(kurv).filter(n => String(n).indexOf('Abonnement: ') === 0);"));
sjekk('… den starter avtalen naar den staar alene',
    str_contains($sida2, "        this.startAbonnement(abonnement[0]);"));
// Sammen med varer er det to ulike betalinger i ett trykk. Da skal det staa
// hva som maa gjores, ikke velges for medlemmet.
sjekk('… og sier fra naar den staar sammen med varer',
    str_contains($sida2, "kvittering: 'Medlemskapet betales for seg',"));
// Blir avtalen startet, skal linja ut av kurven — ellers stopper den neste
// butikkjop.
sjekk('… og medlemskapet tas ut av kurven naar avtalen startes',
    str_contains($sida2, "Object.keys(k).forEach(n => { if (String(n).indexOf('Abonnement: ') === 0) delete k[n]; });\n      return { kurv: k };"));

// Serveren skal fortsatt vaere den som avgjor. Den er den eneste som vet
// hva som staar i Vipps, og den hindrer to avtaler ved siden av hverandre.
$mlib = file_get_contents(dirname(__DIR__) . '/app/lib/medlemskap.php');
sjekk('serveren nekter to avtaler ved siden av hverandre',
    str_contains($mlib, "throw new RuntimeException('Du har alt et medlemskap."));

// ── GEO: aa bli sitert av en AI ────────────────────────────────────────
//
// Eieren, 1. september: «jeg er blitt fortalt at noe heter GEO som er med ai
// chat gpt, jeg vil optimalisere siden for dette ogsaa. Og jeg vil ha samme
// knapp som seo, optimaliser for geo».
//
// Skjermen sjekkes i nettleseren av bin/dropinsjekk.mjs, som gaar gjennom
// alle adressene i STIER. Her sjekkes det den ikke ser: at reglene faktisk
// staar der, og at ingen av de ferdigskrevne setningene bryter dem.
sjekk('GEO har egen adresse', str_contains($sida2, "{ sti: '/admin/geo',          side: 'admingeo' },"));
sjekk('… og staar i begge menyene',
    substr_count($sida2, "'admingeo',") >= 2);
sjekk('… med sin egen brodsmulesti',
    str_contains($sida2, "case 'admingeo':"));
sjekk('… og en skjerm som tegnes', str_contains($sida2, "erAdminGeo: side === 'admingeo',"));
sjekk('… og en verdiblokk bak den', str_contains($sida2, "if (side !== 'admingeo') return {};"));

// Feltene lagres som «GEO/<id>» i content_blocks, slik SEO gjor.
sjekk('GEO lagres i content_blocks', str_contains($sida2, "'GEO/' + valgtSide.id"));

// Scoren skal trekke for det som gjor en setning uselvstendig. Dette er
// selve regelen — endres den, skal noen ha ment aa endre den.
sjekk('scoren trekker for manglende kort svar',
    str_contains($sida2, "score -= 30; trekk.push('Mangler kort svar"));
sjekk('… for svar uten tall', str_contains($sida2, "score -= 12; trekk.push('Svaret har ingen tall"));
sjekk('… for svar uten stedsnavn', str_contains($sida2, "score -= 10; trekk.push('Svaret sier ikke hvor det er"));
// «Vi holder kurs hver onsdag» sier ingenting naar setningen staar alene i
// et AI-svar: leseren vet ikke hvem «vi» er.
sjekk('… og for «vi» og «oss»',
    str_contains($sida2, "if (/\\b(vi|oss|vår|våre)\\b/i.test(sv))"));

// Alle de ferdigskrevne setningene maa taale sine egne regler. Uten dette
// kunne «Optimaliser for GEO» fylt inn tjue sider som scorer 60.
preg_match('/static get GEO_FERDIG\(\) \{(.*?)\n  \}\n/s', $sida2, $m);
$geoBlokk = $m[1] ?? '';
sjekk('de ferdigskrevne GEO-tekstene finnes', $geoBlokk !== '');

preg_match_all("/\n        svar: '((?:[^'\\\\]|\\\\.)*)',/", $geoBlokk, $sv);
$svar = array_map(static fn(string $x): string => str_replace(["\\'", '\\\\'], ["'", '\\'], $x), $sv[1]);
sjekk('… og det er tjue av dem', count($svar) === 20, count($svar) . ' svar');

$forKorte = array_filter($svar, static fn(string $x): bool => mb_strlen($x) < 60);
$forLange = array_filter($svar, static fn(string $x): bool => mb_strlen($x) > 220);
$utenTall = array_filter($svar, static fn(string $x): bool => !preg_match('/\d/', $x));
$utenSted = array_filter($svar, static fn(string $x): bool => !preg_match('/tønsberg|nøtterøy|teie|vestfold/iu', $x));
$medVi    = array_filter($svar, static fn(string $x): bool => (bool) preg_match('/(^|[^\p{L}])(vi|oss|vår|våre)([^\p{L}]|$)/iu', $x));

sjekk('… alle er lange nok til aa staa alene', $forKorte === [], implode(' | ', $forKorte));
sjekk('… og korte nok til aa siteres helt', $forLange === [], implode(' | ', $forLange));
sjekk('… alle har et tall', $utenTall === [], implode(' | ', $utenTall));
sjekk('… alle sier hvor det er', $utenSted === [], implode(' | ', $utenSted));
sjekk('… og ingen sier «vi» eller «oss»', $medVi === [], implode(' | ', $medVi));

// Prisen skal aldri staa i den ferdige teksten. Staar den der, blir den
// staaende igjen som feil den dagen Monica endrer prisen — og en AI siterer
// et gammelt tall like villig som et nytt.
$medPris = array_filter($svar, static fn(string $x): bool => (bool) preg_match('/\bkr\.?\s|\bkroner\b/iu', $x));
sjekk('… og ingen av dem har prisen skrevet inn', $medPris === [], implode(' | ', $medPris));
sjekk('prisen hentes fra katalogen i stedet',
    str_contains($sida2, "kortSvar: pris ? f.svar.replace(/\\.\$/, '') + '. Prisen er ' + pris + '.' : f.svar,"));

// FAQPage i hodet. «Hvem passer kontakt for?» er ikke et sporsmaal noen
// stiller — det skal bare paa kurs og events.
sjekk('sporsmaal og svar legges i hodet som FAQPage',
    str_contains($sida2, "return { '@type': 'FAQPage', mainEntity: sporsmaal };"));
sjekk('… bare naar baade sporsmaal og svar finnes',
    str_contains($sida2, "if (!sp || !sv) return null;"));
sjekk('… og «hvem passer det for» bare paa kurs og events',
    str_contains($sida2, "const erKurs = sd && (sd.type === 'kurs' || sd.type === 'event');"));

// llms.txt er fila AI-tjenestene leser forst. Uten dette blir svarene
// eieren skriver liggende i basen uten aa naa noen.
$llms = file_get_contents(dirname(__DIR__) . '/api/llms.php');
sjekk('llms.txt henter svarene fra basen',
    str_contains($llms, "WHERE nokkel LIKE 'GEO/%'"));
sjekk('… og hopper over dem uten svar',
    str_contains($llms, "if (\$sp === '' || \$sv === '') {"));
// En «Kilde:» som peker feil er verre enn ingen kilde.
sjekk('… og slaar opp kursadressene paa tittelen',
    str_contains($llms, "\$GEO_SIDER[\$id] = '/kurs/' . rawurlencode((string) \$c['slug']);"));

// ── Ressursene deles av alle ───────────────────────────────────────────
//
// Eieren, 30. august: «maa ta plasser fra de samme ressursene. Altsaa om det
// er kurs eller andre medlemmer, vi maa tenke at alle disse har tilgang til
// de samme 8 dreieskivene», «1 dreieskive = 1 ressurs = 1 plass», og «kurs og
// medlembooking maa alle hente fra tilgjengelige ressurser».
if (DB::harTabell('ressurser') && DB::harKolonne('courses', 'ressurs_id')) {
    $skive = DB::en("SELECT id, antall FROM ressurser WHERE navn = 'Dreieskive'");
    sjekk('dreieskivene staar i basen, ikke i koden', $skive !== null && (int) $skive['antall'] > 0);
    sjekk('… og dreiekursene og Date Night peker paa dem',
        (int) DB::verdi('SELECT COUNT(*) FROM courses WHERE ressurs_id = :i', ['i' => (int) $skive['id']]) >= 3);

    // Selve regnestykket: to ting som gaar samtidig og deler ressursen, skal
    // ikke kunne selge den samme skiva to ganger.
    $par = DB::en(
        'SELECT a.id aid, b.id bid
           FROM course_sessions a
           JOIN courses ca ON ca.id = a.course_id
           JOIN course_sessions b ON b.id <> a.id AND b.status = \'planlagt\'
           JOIN courses cb ON cb.id = b.course_id AND cb.ressurs_id = ca.ressurs_id
          WHERE a.status = \'planlagt\' AND ca.ressurs_id = :r
            AND a.start_tid > UTC_TIMESTAMP()
            AND b.start_tid < COALESCE(a.slutt_tid, a.start_tid + INTERVAL 3 HOUR)
            AND a.start_tid < COALESCE(b.slutt_tid, b.start_tid + INTERVAL 3 HOUR)
          LIMIT 1',
        ['r' => (int) $skive['id']]
    );
    if ($par !== null) {
        $tak = (int) $skive['antall'];
        $for = Booking::ledigePlasserFlere([(int) $par['aid'], (int) $par['bid']]);
        sjekk('ingen oekt viser flere ledige enn ressursen har',
            $for[(int) $par['aid']] <= $tak, 'sto med ' . $for[(int) $par['aid']] . ' av ' . $tak);

        $kurs = (int) DB::verdi('SELECT course_id FROM course_sessions WHERE id = :s',
                                ['s' => (int) $par['bid']]);
        DB::kjor(
            "INSERT INTO bookings (course_id, course_session_id, gjest_navn, gjest_epost,
                                   antall, belop_ore, status)
             VALUES (:c, :s, 'Ressursproeve', 'proeve@lissom.test', 6, 0, 'betalt')",
            ['c' => $kurs, 's' => (int) $par['bid']]
        );
        $etter = Booking::ledigePlasserFlere([(int) $par['aid'], (int) $par['bid']]);
        // Maalt mot det som sto for, ikke mot taket: testdataene har alt
        // bookinger paa noen av oektene, og proven skal si noe om
        // *endringen* — at seks plasser paa den ene faktisk forsvinner fra
        // den andre.
        sjekk('… og seks booket paa den ene tar seks fra den andre',
            $etter[(int) $par['aid']] === max(0, $for[(int) $par['aid']] - 6),
            'gikk fra ' . $for[(int) $par['aid']] . ' til ' . $etter[(int) $par['aid']]);
        // Kurset kan ikke ta imot flere enn ressursen har, uansett hva
        // plasstallet paa kurset sier. Date Night staar med tolv plasser og
        // aatte skiver.
        sjekk('… ingen oekt kan selge flere plasser enn ressursen har',
            max($etter) <= $tak, 'flest: ' . max($etter));
        DB::kjor("DELETE FROM bookings WHERE gjest_navn = 'Ressursproeve'");
    }
}
$ress = file_get_contents(__DIR__ . '/../api/admin/ressurser.php');
// Eieren, spurt om hva som skal skje: «nekt, og si hvilke kurs». Ellers
// forsvant taket stille, og verkstedet kunne solgt seksten plasser paa aatte
// skiver uten at noe sa fra.
sjekk('en ressurs kurs bruker kan ikke slettes',
    str_contains($ress, "' brukes av ' . count(\$bruker) . ' kurs: '")
    && str_contains($ress, 'Flytt dem til en annen ressurs først'));
sjekk('… men den kan slaas av, og koblingene staar',
    str_contains($ress, "case 'veksle':"));
// Null plasser er ikke en ressurs, det er en stengt dor.
sjekk('… og antallet maa vaere et tall det gaar an aa dele',
    str_contains($ress, '$antall < 1 || $antall > 999'));
sjekk('kortet «Ressurser» staar paa Oversikt',
    str_contains($sida2, "return kort('Ressurser',"));
sjekk('kurset velger ressurs i oppsettet',
    str_contains($sida2, 'kRessursValg:')
    && str_contains($sida2, "ressursId: this.state.kRessursId || 0,"));
// En id som ikke finnes avvises: ellers ville kurset staatt uten tak uten
// aa si fra.
sjekk('… og en ukjent ressurs avvises ved lagring',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/kurs.php'),
                 "Svar::feil('Fant ikke ressursen.');"));

// ── Medlemmet velger selv ──────────────────────────────────────────────
//
// Eieren, 30. august: «kunne det voere lost om de booker inn og velger
// dreieskive, eller verkstedplass» — medlemmene — «det skjer paa min side».
// For dette gjettet regnestykket at enhver innstemplet sto ved en skive.
if (DB::harKolonne('check_ins', 'ressurs_id') && DB::harTabell('ressurser')) {
    $medl  = DB::en('SELECT id FROM members LIMIT 1');
    $skive = (int) DB::verdi("SELECT id FROM ressurser WHERE navn = 'Dreieskive'");
    $bord  = (int) DB::verdi("SELECT id FROM ressurser WHERE navn = 'Bordplass'");
    if ($medl !== null && $skive > 0 && $bord > 0) {
        $forInne = DB::alle('SELECT id FROM check_ins WHERE ut_tid IS NULL');
        DB::kjor('DELETE FROM check_ins WHERE ut_tid IS NULL');

        // En oekt som gaar akkurat naa. Innstemplede teller bare paa dem —
        // en booking om tre dager kan ikke vite hvem som moeter opp.
        $kurs = (int) DB::verdi('SELECT id FROM courses WHERE ressurs_id = :r LIMIT 1',
                                ['r' => $skive]);
        $naaU = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $oktId = DB::settInn('course_sessions', [
            'course_id' => $kurs,
            'start_tid' => $naaU->modify('-20 minutes')->format('Y-m-d H:i:s'),
            'slutt_tid' => $naaU->modify('+70 minutes')->format('Y-m-d H:i:s'),
            'kapasitet' => 8, 'status' => 'planlagt', 'fra_apningstid' => 0,
        ]);
        // Bufferne i Booking gjelder én foresporsel. I proven endrer vi
        // basen mellom hvert kall, og da maa de toemmes.
        $blank = static function (): void {
            $r = new ReflectionClass('Booking');
            foreach (['tak', 'inne', 'skive'] as $n) {
                $p = $r->getProperty($n);
                $p->setAccessible(true);
                $p->setValue(null, null);
            }
        };
        $les = static function () use ($oktId, $blank): int {
            $blank();
            return Booking::ledigePlasserFlere([$oktId])[$oktId];
        };

        $tomt = $les();
        Stempling::inn((int) $medl['id'], $bord);
        sjekk('et medlem ved bordet tar ikke en dreieskive',
            $les() === $tomt, 'gikk fra ' . $tomt . ' til ' . $les());
        DB::kjor('DELETE FROM check_ins WHERE ut_tid IS NULL');
        Stempling::inn((int) $medl['id'], $skive);
        sjekk('… og et medlem ved skiva tar én',
            $les() === $tomt - 1, 'gikk fra ' . $tomt . ' til ' . $les());

        // Uten valg gjelder den gamle gjetningen: mot skivene. Ellers ville
        // en oekt som alt sto aapen sluppet fri en skive i det dette ble
        // lagt ut.
        DB::kjor('DELETE FROM check_ins WHERE ut_tid IS NULL');
        DB::settInn('check_ins', ['member_id' => (int) $medl['id'],
                                  'inn_tid' => gmdate('Y-m-d H:i:s')]);
        sjekk('… og en innstempling uten valg teller mot skivene, som for',
            $les() === $tomt - 1, 'fikk ' . $les());

        DB::kjor('DELETE FROM check_ins WHERE ut_tid IS NULL');
        DB::kjor('DELETE FROM course_sessions WHERE id = :i', ['i' => $oktId]);
        foreach ($forInne as $r) {
            DB::kjor('UPDATE check_ins SET ut_tid = NULL WHERE id = :i', ['i' => (int) $r['id']]);
        }
        $blank();
    }
}
sjekk('valget staar paa Min side, der medlemmet stempler inn',
    str_contains($sida2, 'msRessursValg:')
    && str_contains($sida2, "{ handling: 'inn', ressursId: valgt || 0 }"));
// En ressurs som er slettet eller slaatt av skal ikke gjore at innstemplinga
// mislykkes — medlemmet staar med telefonen i haanda i dora.
sjekk('… og en ukjent ressurs stopper ikke innstemplinga',
    str_contains(file_get_contents(__DIR__ . '/../api/stempling.php'),
                 "\$rid = 0;"));

// ── Et flerdagerskurs sperrer ikke natta ───────────────────────────────
//
// Et kurs over to kvelder ligger som ÉN rad: «Nybegynner dreiekurs» staar med
// 9. september 17:00 → 10. september 20:00. Det er ikke syvogtyve timer i
// verkstedet, det er to kvelder á tre.
//
// Regnet rett fram holdt kurset tre dreieskiver opptatt gjennom natta og hele
// torsdag formiddag, og en aapen plass torsdag klokka aatte sto med fem ledige uten
// at noe skjedde i huset. Sett paa lissom.no like etter at delte ressurser
// ble lagt ut.
if (DB::harTabell('ressurser') && DB::harKolonne('courses', 'ressurs_id')) {
    $fler = DB::en(
        "SELECT cs.id, cs.start_tid, cs.slutt_tid, c.ressurs_id
           FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
          WHERE cs.slutt_tid IS NOT NULL
            AND DATE(cs.slutt_tid) > DATE(cs.start_tid)
            AND TIME(cs.slutt_tid) > TIME(cs.start_tid)
            AND c.ressurs_id IS NOT NULL
          LIMIT 1"
    );
    if ($fler !== null) {
        // Proven lager sin egen booking. Testdataene kan ha null, og da ville
        // begge maalingene gitt fullt hus og sagt ingenting.
        $kursId = (int) DB::verdi('SELECT course_id FROM course_sessions WHERE id = :s',
                                  ['s' => (int) $fler['id']]);
        DB::kjor(
            "INSERT INTO bookings (course_id, course_session_id, gjest_navn, gjest_epost,
                                   antall, belop_ore, status)
             VALUES (:c, :s, 'Flerdagersproeve', 'proeve@lissom.test', 3, 0, 'betalt')",
            ['c' => $kursId, 's' => (int) $fler['id']]
        );
        // Plassene paa den samme ressursen, dagen etter at kurset begynner.
        $andre = DB::alle(
            'SELECT cs.id, cs.start_tid FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE c.ressurs_id = :r AND cs.id <> :s AND cs.status = \'planlagt\'
                AND DATE(cs.start_tid) = DATE(:slutt)
              ORDER BY cs.start_tid',
            ['r' => (int) $fler['ressurs_id'], 's' => (int) $fler['id'],
             'slutt' => (string) $fler['slutt_tid']]
        );
        if ($andre !== []) {
            $led = Booking::ledigePlasserFlere(array_column($andre, 'id'));
            $morgen = null;
            $kveld = null;
            foreach ($andre as $o) {
                $kl = substr((string) $o['start_tid'], 11, 5);
                if ($kl < substr((string) $fler['start_tid'], 11, 5)) {
                    $morgen ??= $led[(int) $o['id']];
                } else {
                    $kveld ??= $led[(int) $o['id']];
                }
            }
            $tak = Booking::verkstedTak()[(int) $fler['ressurs_id']] ?? 0;
            if ($morgen !== null && $tak > 0) {
                sjekk('formiddagen dagen etter er ledig, kurset gaar om kvelden',
                    $morgen === $tak, 'sto med ' . $morgen . ' av ' . $tak);
            }
            // Men kvelden derpaa er kursets egen — den skal fortsatt sperre.
            if ($kveld !== null && $tak > 0) {
                sjekk('… men kvelden derpaa sperrer, den er kursets andre samling',
                    $kveld < $tak, 'sto med ' . $kveld . ' av ' . $tak);
            }
        }
        DB::kjor("DELETE FROM bookings WHERE gjest_navn = 'Flerdagersproeve'");
    }
}

// ── Et planlagt kurs holder plassene sine ──────────────────────────────
//
// Eieren, 30. august: «det maa ikke vaere mulig aa booke drop in eller
// dreieskive paa forhaand for medlemmer naar det er planlagt kurs. Da er de
// ressursene booket og opptatt med kurs.»
//
// Spurt om et kurs med faerre plasser enn ressursen har: «kurset holder av
// sine plasser». Et dreiekurs paa aatte tar altsaa alle aatte skivene, ogsaa
// for noen har meldt seg paa.
if (DB::harTabell('ressurser') && DB::harKolonne('courses', 'ressurs_id')) {
    $kurs = DB::en(
        "SELECT cs.id, cs.start_tid, cs.slutt_tid, c.ressurs_id,
                COALESCE(cs.kapasitet, c.kapasitet) AS kap
           FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
          WHERE cs.status = 'planlagt' AND cs.fra_apningstid = 0
            AND c.ressurs_id IS NOT NULL AND cs.slutt_tid IS NOT NULL
            AND DATE(cs.slutt_tid) = DATE(cs.start_tid)
            AND cs.start_tid > UTC_TIMESTAMP()
            -- Bare et kurs som faktisk har aapne plasser inni seg. Ellers
            -- maaler proven ingenting, og gaar gjennom uten aa ha sett noe.
            AND EXISTS (
                SELECT 1 FROM course_sessions a
                  JOIN courses ca ON ca.id = a.course_id
                 WHERE a.fra_apningstid = 1 AND a.status = 'planlagt'
                   AND ca.ressurs_id = c.ressurs_id
                   AND a.start_tid >= cs.start_tid AND a.start_tid < cs.slutt_tid)
          ORDER BY cs.start_tid LIMIT 1"
    );
    if ($kurs !== null) {
        $tak = Booking::verkstedTak()[(int) $kurs['ressurs_id']] ?? 0;
        // De aapne plassene som ligger inni kursets tid.
        $aapne = DB::alle(
            'SELECT cs.id FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
              WHERE cs.fra_apningstid = 1 AND cs.status = \'planlagt\'
                AND c.ressurs_id = :r
                AND cs.start_tid >= :s AND cs.start_tid < :e',
            ['r' => (int) $kurs['ressurs_id'], 's' => (string) $kurs['start_tid'],
             'e' => (string) $kurs['slutt_tid']]
        );
        if ($aapne !== [] && $tak > 0) {
            $led = Booking::ledigePlasserFlere(array_column($aapne, 'id'));
            $ventet = max(0, $tak - (int) $kurs['kap']);
            sjekk('en aapen plass er stengt mens kurset gaar',
                max($led) === $ventet,
                'ventet ' . $ventet . ' ledige (' . $tak . ' minus kursets '
                . $kurs['kap'] . '), fikk ' . max($led));
        }
        // Men kurset selv skal ikke sperre for seg selv: det er nettopp det
        // som skjer om en tom aapen plass paa aatte holder plasstallet sitt
        // ogsaa. Da tar de to livet av hverandre.
        $egen = Booking::ledigePlasserFlere([(int) $kurs['id']])[(int) $kurs['id']];
        sjekk('… men kurset sperrer ikke for seg selv',
            $egen > 0, 'kurset sto med ' . $egen . ' ledige');

        // Og kunden skal faa vite hvorfor. «Fullbooket» paa en aapen time
        // der det ikke er én booking, men et kurs, gir en telefon fra en som
        // ikke ser noen i verkstedet.
        if (isset($aapne) && $aapne !== [] && ($ventet ?? 1) === 0) {
            $sp = Booking::sperretAvAnnet(array_column($aapne, 'id'));
            sjekk('… og en stengt aapen time sier at det gaar kurs',
                in_array(true, $sp, true), 'ingen av dem er merket sperret');
        }
    }
}

sjekk('nettsida skriver «Kurs i verkstedet», ikke «Fullbooket»',
    str_contains($sida2, "if (sperret && (isNaN(l) || l <= 0)) return 'Kurs i verkstedet';"));
// Grunnen maa foelge med helt ut. Regnes den ett sted og vises et annet,
// kommer de to til aa si forskjellige ting.
sjekk('… og grunnen sendes med fra serveren',
    str_contains(file_get_contents(__DIR__ . '/../api/kurs.php'),
                 "'sperret'  => \$sperretKart[(int) \$o['id']] ?? false,"));

// ── Ressursene staar oeverst ───────────────────────────────────────────
//
// Eieren, 31. august, med skjermen aapen paa telefonen: «jeg vil og se hvilke
// ressurser som ligger inne». Lista laa der, men under et skjema som tok hele
// skjermen — og da er den ikke der.
sjekk('lista over ressurser staar for skjemaet',
    strpos($sida2, '{{ reListeTittel }}') < strpos($sida2, 'id="ressursskjema"'));
sjekk('… og sier hvor mange plasser verkstedet har',
    str_contains($sida2, "'Verkstedet har ' + sum + (sum === 1 ? ' plass' : ' plasser')"));
// Skjemaet staar under lista. Uten dette fylles det ut et sted man ikke ser,
// og ingenting ser ut til aa skje naar man trykker «Endre».
sjekk('… og «Endre» ruller ned til skjemaet',
    str_contains($sida2, "this.rullTil('ressursskjema');"));

// ── Kursbildet beholder adressen sin ───────────────────────────────────
//
// Eieren, 31. august: «bilde fra mitt nye kurs vises ikke».
//
// Bildet laa der hele tiden. Lagringa kjorte basename() paa verdien fra
// billedvelgeren, og basename() tar siste ledd av en sti:
// «api/bilde.php?artikkel=b8e795….jpg» ble til «bilde.php?artikkel=…».
// Uten «api/» finnes ingen slik fil, og ruteren svarte med hele nettsida.
// Maalt paa lissom.no: den klippede adressen ga 1,2 MB HTML, den hele ga
// 180 kB image/jpeg.
$akurs2 = file_get_contents(__DIR__ . '/../api/admin/kurs.php');
sjekk('et opplastet kursbilde beholder api/-adressen',
    str_contains($akurs2, "preg_match('~^api/bilde\\.php\\?artikkel=[A-Za-z0-9._-]{1,120}\$~', \$raa) === 1"));
// Men en sti utenfra skal fortsatt klippes. Vakta er ikke fjernet, den er
// gjort presis — samme regel som api/admin/referanser.php alt hadde.
sjekk('… men en sti utenfra klippes fortsatt',
    str_contains($akurs2, '$navn = basename($raa);'));
$m105 = file_get_contents(__DIR__ . '/../db/migrations/105_kursbilder_far_adressen_sin.sql');
sjekk('… og radene som alt er lagret rettes',
    str_contains($m105, "SET bilde = CONCAT('api/', bilde)")
    && str_contains($m105, "WHERE bilde LIKE 'bilde.php?artikkel=%'")
    && str_contains($m105, "'\"bilde.php?artikkel=', '\"api/bilde.php?artikkel='"));

// ── Stegene har navn ───────────────────────────────────────────────────
//
// Eieren: «finner ikke noe sted aa legge inn bilde naar jeg proever aa
// redigere et kurs som allerede er lagt ut». Bildene ligger i steg 3 — bak to
// trykk paa «Neste», nederst i tolv seksjoner. At de fantes var ikke til aa
// vite av «Steg 1 av 3».
sjekk('stegene i kursoppsettet har navn og kan trykkes',
    str_contains($sida2, "kStegValg: [[1, 'Kursoppsett'], [2, 'Dager'], [3, 'Bilder og video']]")
    && !str_contains($sida2, '· Steg {{ kSteg }} av 3'));

// ── Hjertet etter navnet ───────────────────────────────────────────────
//
// Eieren, 31. august: «paa oversikt saa staar det God morgen, Monica, kan du
// legge til logo hjertet bak navnet, men dette er kun paa Monica».
sjekk('hjertet staar bare naar Monica er logget inn',
    str_contains($sida2, "adminHilsenHjerte: (this.state.vippsNavn || '').trim().split(/\\s+/)[0]")
    && str_contains($sida2, ".toLowerCase() === 'monica',"));
// Masken er den samme fila heroen bruker, saa formen er logoens og ikke et
// hjerte fra en skrifttype.
// Foerst var hjertet heart-logo-mask.png som maske: 720 x 695 piksler
// krympet til 24. Kantene ble grumsete, og eieren saa det med det samme.
// En kurve er skarp i enhver stoerrelse.
sjekk('… og det er tegnet, ikke et krympet bilde',
    str_contains($sida2, '<svg aria-hidden="true" viewBox="0 0 24 22"')
    && !str_contains($sida2, "mask-image: url('heart-logo-mask.png'); -webkit-mask-size: contain"));

// ── Koden taaler at migrasjonen ikke er kjort ──────────────────────────
//
// Fire femhundre-feil paa lissom.no 31. august 04:32 — /api/kurs.php,
// /api/admin/kurs.php, /api/admin/pameldte.php og /api/admin/venteliste.php,
// alle i samme minutt, alle fordi de regner ledige plasser.
//
// Aarsaken: ledigePlasserFlere leste courses.ressurs_id uten aa spore om
// kolonna fantes. Utlegginga av koden og kjoringa av migrasjonen skjer ikke i
// samme sekund, og i vinduet imellom var kurslista nede for alle.
//
// Resten av kodebasen spor alltid foerst — se $oppsettFelt, $bilderFelt og
// $apenFelt i api/kurs.php.
$bok = file_get_contents(__DIR__ . '/../app/lib/booking.php');
sjekk('ledige plasser spor om de delte ressursene finnes',
    str_contains($bok, "\$delteRessurser = DB::harKolonne('courses', 'ressurs_id')")
    && str_contains($bok, "&& DB::harTabell('ressurser');"));
sjekk('… og har det gamle regnestykket som reserve',
    str_contains($bok, 'private static function ledigeUtenRessurser(string $inn, array $ider): array'));
// Skjemaet sender ressursId uansett. Uten vakta ville lagringa av et hvilket
// som helst kurs feilet paa en kolonne som ikke fantes.
sjekk('kurslagringa taaler det samme',
    str_contains($akurs2, "if (\$har('ressursId') && DB::harKolonne('courses', 'ressurs_id') && DB::harTabell('ressurser'))"));
sjekk('… og innstemplinga ogsaa',
    str_contains(file_get_contents(__DIR__ . '/../api/stempling.php'),
                 "if (\$rid > 0 && (!DB::harTabell('ressurser')"));

// ── Kalenderen ─────────────────────────────────────────────────────────
//
// Eieren, 31. august: «i kalenderen saa er det naa en kollone som heter
// verkstedet, hvor alle drop in timene ligger, fjern denne kolonnen» — og,
// spurt: «hele kolonnen, jeg vil likevel legge til f eks brenning paa min
// kalender, en kollone».
// 1. september kom det én spalte tilbake — men ikke «Verkstedet», og ikke
// som en fast kolonne. «Uten kursholder» staar bare naar den har noe, og
// finnes fordi en okt uten tildelt holder ellers ikke ble tegnet i det hele
// tatt. Se «Kalenderen: to feil som gjemte kurs».
sjekk('«Verkstedet»-kolonnen er borte, og ingen ny fast kolonne satt i stedet',
    str_contains($sida, 'this.klHoldere().concat([UTEN_HOLDER]).map(kn => {')
    && !str_contains($sida, "concat(['Verkstedet'])")
    && !str_contains($sida, "concat(['Brenning'])"));
// Brenning og innstempling har ingen kursholder og laa derfor i den gamle
// kolonnen. Foerst sila jeg dem bort i dagsvisninga alene, og da stod
// ukesvisninga full av dem. Sila hoerer hjemme der hendelsene hentes, saa
// gjelder den alle visningene.
sjekk('brenning og verksted siles bort der hendelsene hentes',
    str_contains($sida, "const alle = this.klAlle(y, m)\n"
        . "      .filter(e => e.type !== 'verksted' && e.type !== 'brenning')"));
sjekk('… og kolonnene tar da alt som hoerer kursholderen til',
    str_contains($sida, ": dagensAlle.filter(e => e.holder === kn);"));
// Eieren, gang paa gang: «det hvite feltet under alle kurs skulle staa paa
// linje med det hvite i kallenderen». Overskriftsrada i kalenderen er ulik
// hoey i de tre visningene, saa hver visning trenger sitt eget loft.
//
// Dagen laa paa -65 px saa lenge «Viser»-raden med kursholderpillene laa inni
// kalenderspalta og skjov rutenettet ned. Raden ligger naa i full bredde over
// begge spaltene, og da holder -18. Maalt paa nytt 1. september: det hvite
// lander likt paa 1200, 1500 og 1700 px.
sjekk('det hvite staar paa linje i alle tre visningene',
    str_contains($sida, "marginTop: visning === 'dag' ? '-18px' : visning === 'uke' ? '17px' : '21px'"));

// Ruta som aapnes fra kalenderen var bygget ved aa ramse opp hver kolonne
// kurset har, og viste derfor ogsaa feltene eieren alt hadde bedt om aa bli
// kvitt i kursoppsettet — «kort beskrivelse og dette lager du kan fjernes»
// (30. august) og «rydd resten» om Godt aa vite. Da sier de to skjermene
// forskjellige ting om det samme kurset.
sjekk('ruta fra kalenderen viser ikke feltene som er fjernet',
    !str_contains($sida, "felt('kortBeskrivelse',")
    && !str_contains($sida, "felt('lagerDu',")
    && !str_contains($sida, "felt('tillegg',")
    && !str_contains($sida, "felt('passerNivaa',")
    && !str_contains($sida, "felt('metode',")
    && !str_contains($sida, "felt('instruktor',"));
// Hentetiden sto to steder, med to forskjellige tall. «Dette faar du med
// hjem» endte paa en fast setning om henting — skrevet for feltet «Naar er
// den ferdig» fantes — og paa kurssida sto de rett under hverandre og sa
// «2–3 uker» og «2-4 uker». Eieren, 31. august: «2-4 uker er riktig».
$mal = file_get_contents(__DIR__ . '/../app/lib/kursmal.php');
sjekk('hentetiden staar bare i «Naar er den ferdig»',
    substr_count($mal, 'self::HENTING') === 6
    && !preg_match("~'medHjem'[^\n]*(\n\s*\.[^\n]*)*self::HENTING~", $mal));
// Alle malene maa ha den. Uten en standard staar feltet tomt, og da sier
// kurssida ingenting om naar keramikken er klar. Det var seks; «Drop-in» gikk
// ut da tilbudet ble revet ut 2. september.
sjekk('… og alle fem malene har den, med reservemalen',
    substr_count($mal, "'ferdigTid'       => self::HENTING,") === 6);
// Og eieren ba om at det skal staa hvor man ser det selv.
sjekk('… og teksten sier hvor man kan se det selv',
    str_contains($mal, '2–4 uker')
    && str_contains($mal, 'lissom.no/ferdigbrent')
    && str_contains($mal, 'logge inn på Min side'));
// Fire kurs hadde den innlimte teksten. Migrasjonen toemmer bare den
// noeyaktige — har noen skrevet noe eget, blir det staaende.
sjekk('… og de fire kursene med innlimt tekst foelger malen',
    str_contains(file_get_contents(__DIR__ . '/../db/migrations/109_hentetiden_staar_ett_sted.sql'),
                 "WHERE TRIM(ferdig_tid) = 'Klart til henting etter 2-4 uker. Vi gir beskjed.';"));

// Eieren, 31. august: «jeg forstaar ikke alle de tomme feltene». Et tomt felt
// betyr at nettsida viser standardteksten for kategorien — men ruta viste
// bare en tom boks, mens kursoppsettet viser teksten som graa hjelpetekst.
// Samme felt, to skjermer, og bare den ene forklarte seg.
sjekk('ruta viser standardteksten i de tomme feltene',
    str_contains($sida, 'placeholder="{{ f.mal }}"')
    && substr_count($sida, 'placeholder="{{ f.mal }}"') === 2
    && str_contains($sida, "mal: kortet(mal[MAL_FELT[nokkel]] || '', 150),"));
// Samme kilde som kursoppsettet — «red.mal», som serveren regner ut i
// Kursmal::forKurs(). Da kan de to skjermene ikke si hver sin ting om hva
// som kommer paa nettsida.
sjekk('… fra samme mal som kursoppsettet bruker',
    str_contains($sida, 'const mal = red.mal || {};')
    && str_contains($sida, "om: 'beskrivelse', laerer: 'laerer', medHjem: 'medHjem',"));
// Og under feltet: hva som faktisk gjelder akkurat naa.
sjekk('… og sier om standarden brukes eller er overstyrt',
    str_contains($sida, "? 'Står tomt: nettsiden viser teksten over'")
    && str_contains($sida, ": 'Egen tekst. Den står foran den anbefalte.',"));

// Eieren, 31. august: «paa disse kortene maa vi fjerne dato». Kortet baerer
// bare den foerste av datoene kurset har, og det leses som om det er den
// eneste — Nybegynner dreiekurs gaar 9. sep, 16. sep og 7. okt, og oktober
// fantes ikke for den som saa paa lista.
//
// 1. september kom resten av svaret: «jeg vil at det skal vises 3 planlagte
// datoer». Kortet skal altsaa ikke staa uten dato, men uten den ENE — og
// aldri med «k.date», som var nettopp den foerste. Se «Tre datoer paa
// kurskortet» over.
sjekk('kortet viser aldri bare den forste datoen',
    !str_contains($sida, 'date="{{ k.date }}"')
    && substr_count($sida, 'CourseCard" level="{{ k.level }}" title="{{ k.title }}" date="{{ k.kdDato }}" duration="{{ k.duration }}"') === 3);
// «Teksten onsdag 9 ….. endrer seg ikke til tross for at jeg velger en annen
// dato». Linja over «Velg dato» sto paa kursets FOERSTE dato. Foerste forsoek
// leste «valgtKurs.datoer» — feil liste; datovelgeren bruker ekteDatoer().
sjekk('linja over «Velg dato» foelger datoen som er valgt',
    str_contains($sida, 'const datoer = this.ekteDatoer() || k.datoer || [];')
    && str_contains($sida, "(this.state.bDato && datoer.find(d => d && d.dato === this.state.bDato))")
    && str_contains($sida, "String(d.dag || d.dato || '') === this.state.bDag"));

// Eieren, 31. august: «hvorfor sorterer ikke paa dato som vi har snakket om?».
// Lista ble sortert paa dato da den ble bygget, og saa kastet sorterKurs det
// bort og stilte dem alfabetisk innenfor hver kategori: «Lag din egen bolle»
// 3. september over «Store fat kurs» 1. september, fordi L kommer foer S.
sjekk('kurs sorteres paa dato, ikke paa navn',
    str_contains($sida, 'const d = this.forsteOktTid(a) - this.forsteOktTid(b);')
    && str_contains($sida, 'if (d !== 0 && !isNaN(d)) return d;'));
// Datoregelen staar ett sted og brukes to: her og i alleKort().
sjekk('… og datoregelen staar ett sted',
    str_contains($sida, 'forsteOktTid(k) {')
    && substr_count($sida, 'this.forsteOktTid(') === 3);
// Filteret som holdt drop-in ute av kurslistene og av tallene, er borte
// sammen med drop-in selv — se «Drop-in finnes ikke» lenger nede. Lista er
// naa alle oektene, og tallet teller de samme.
sjekk('kurslistene i admin er alle oektene',
    str_contains($sida, "const okter = (this.state.adminOkter || []);")
    && !str_contains($sida, 'oktIKurslista'));
sjekk('… og tallet paa Oversikt teller de samme',
    str_contains($sida, '.filter(o => String(o.dato || \'\').slice(0, 10) === iso).length;'));

// Eieren, 31. august: «jeg faar ikke scrollet tilbake, da virker det som
// siden under er den som scroller». Det er nettopp det som skjer: naar ruta
// er rullet helt til topps, fortsetter fingeren ned i sida bak.
sjekk('rullinga stopper i ruta, den gaar ikke ned i sida bak',
    substr_count($sida, "overscrollBehavior: 'contain'") >= 2);
// Han spurte om det fantes flere: «er det flere slike scenario eller sjekket
// og fikset du alle?». Det gjorde det — sju overlegg til med samme feil. Her
// telles de i stedet for aa ramses opp, saa et nytt overlegg uten stopp blir
// fanget den dagen det skrives.
$rullendeUtenStopp = 0;
foreach (explode("\n", $sida) as $linje) {
    foreach (preg_split('~style="~', $linje) as $bit) {
        $stil = explode('"', $bit)[0];
        if (str_contains($stil, 'position: fixed')
            && preg_match('~overflow(-y)?:\s*(auto|scroll)~', $stil) === 1
            && !str_contains($stil, 'overscroll-behavior')) {
            $rullendeUtenStopp++;
        }
    }
}
sjekk('… og ingen andre overlegg som ruller mangler den',
    $rullendeUtenStopp === 0);

// Tavla under Markedsforing lister utkast med status «godkjent», saa et
// nyhetsbrev eller et innlegg ikke blir borte for det er sendt eller limt
// inn. En artikkel som ligger ute er derimot brukt — men statusen ble aldri
// satt til «publisert», saa den ble staaende under «Godkjent — klar til
// bruk» for alltid. Eieren, 31. august: «disse ligger her og jeg kan trykke
// publiser, men de er publisert».
$ai = file_get_contents(__DIR__ . '/../api/admin/ai.php');
sjekk('et utkast som publiseres blir merket publisert',
    str_contains($ai, "'status'      => \$utNaa ? 'publisert' : 'godkjent',")
    && str_contains($ai, "DB::oppdater('ai_utkast', ['status' => 'publisert'], ['id' => \$id]);"));
// Tavla henter fortsatt bare «godkjent» — det er den som er gjorelista.
sjekk('… og tavla lister bare det som ikke er brukt ennaa',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/marked.php'),
                 "FROM ai_utkast WHERE status = 'godkjent' ORDER BY id DESC LIMIT 20"));
// Migrasjon 108 rydder dem som alt laa ute. Bare utkast som peker paa en
// artikkel som faktisk er publisert — et utkast med en kladd bak seg har
// fortsatt noe ugjort.
sjekk('… og de som alt laa ute er ryddet',
    str_contains(file_get_contents(__DIR__ . '/../db/migrations/108_publiserte_utkast_er_ferdige.sql'),
                 "WHERE u.status = 'godkjent'\n    AND a.status = 'publisert';"));

// Kursbeviset leste «courses.instruktor», et fritekstfelt ingen annen del av
// systemet bruker. Eieren, 31. august: «dersom noen andre holder kurset saa er
// vel dette valgt i kursoppsettet? Saa da henter det her infra» — og «fiks
// kursbeviset da». Naa henter det derfra: oekta foerst, saa kurset.
$bevis = file_get_contents(__DIR__ . '/../api/kursbevis.php');
sjekk('kursbeviset henter kursholderen fra oekta foerst, saa kurset',
    str_contains($bevis, "LEFT JOIN kursholdere kho ON kho.id = cs.kursholder_id")
    && str_contains($bevis, "LEFT JOIN kursholdere khk ON khk.id = c.kursholder_id")
    && str_contains($bevis, "foreach ([['holder_navn', 'holder_signatur'],"));
// Navnet og signaturen maa hentes fra samme kilde. Ett navn med en annen
// signatur under er verre enn feil navn: det ser ut som noen har skrevet
// under paa noe hen ikke var med paa.
sjekk('… og signaturen foelger navnet, aldri fra en annen kilde',
    str_contains($bevis, "\$instruktor = \$kandidat;")
    && str_contains($bevis, "\$signatur   = trim((string) (\$b[\$sigFelt] ?? ''));"));
// Er signaturen ukjent eller tom, staar arket uten. Foer falt den tilbake paa
// Monicas, og da ville en innleid kursholders navn faatt hennes signatur.
sjekk('… og et ark uten signatur skrives uten, ikke med Monicas',
    str_contains($bevis, "\$signatur = '';")
    && str_contains($bevis, "<?php if (\$signatur !== ''): ?>"));
// Monica staar som kursholder paa alle kurs fra migrasjon 093. Uten en
// signatur paa raden hennes ville hvert eneste bevis mistet signaturen i det
// beviset begynte aa lese kursholderen.
sjekk('… og Monica faar signaturen sin med i samme migrasjon',
    str_contains(file_get_contents(__DIR__ . '/../db/migrations/107_kursholder_signatur.sql'),
                 "ADD COLUMN IF NOT EXISTS signatur VARCHAR(255) NULL")
    && str_contains(file_get_contents(__DIR__ . '/../db/migrations/107_kursholder_signatur.sql'),
                 "SET signatur = 'signatur-monica.png'"));
sjekk('… og signaturen kan legges inn paa kursholderen',
    str_contains($sida, "khVelgSignatur: () => this.apneBildevalg({ slag: 'signatur' }),")
    && str_contains($sida, "if (v.slag === 'signatur') {")
    && str_contains(file_get_contents(__DIR__ . '/../api/admin/kursholdere.php'),
                    "if (DB::harKolonne('kursholdere', 'signatur')) {"));
// Metoden regnes ut av kategorien. Skrev du noe i feltet, ble det overskrevet
// neste gang kurset ble lagret fra oppsettet.
sjekk('… og metoden regnes fortsatt ut av kategorien',
    str_contains($sida, 'metode: this.metodeAvKategori(this.state.kKategori, this.state.kTema, this.state.kMetode),'));
// Teksten som ligger lagret skal bli staaende og vises som for. Det er
// feltene som er borte, ikke innholdet.
sjekk('… men lagringa sender verdiene videre urort',
    str_contains($sida, "'nivaaIntern', 'nivaaTekst', 'kortBeskrivelse', 'lagerDu',"));

// Eieren, 31. august: «Jeg skal ikke ha sms paaminnelse som default noe
// jaevla sted! Dette har jeg sagt saa mange ganger naa». Den kom tilbake
// fordi den sto paa fem steder, ikke ett: kolonnen med DEFAULT 1, lagringa
// som skrev «alt som ikke er nei blir 1», og tre steder i fronten som leste
// «!== false» — og det gjor undefined til paa.
sjekk('SMS slaas bare paa av et uttrykkelig ja',
    str_contains(file_get_contents(__DIR__ . '/../api/admin/kurs.php'),
                 "\$data['sms_paaminnelse'] = Foresporsel::tekst('sms') === 'ja' ? 1 : 0;"));
sjekk('… og ingen steder i fronten gjoer undefined til paa',
    preg_match('~sms[^\n]*!== false~i', $sida) !== 1
    && str_contains($sida, 'sms: this.state.kSms === true,')
    && str_contains($sida, "kSms: k.sms === true, kGjentak:")
    && str_contains($sida, 'sms: k.sms === true,')
    && str_contains($sida, 'sms: jaNei(raa.sms === true),'));
// Kolonnen sto med DEFAULT 1 fra 001_init, saa enhver INSERT som ikke nevnte
// den fikk SMS paa. Migrasjon 106 tar bade standarden og radene som ligger
// inne.
sjekk('… og kolonnen staar av som standard',
    str_contains(file_get_contents(__DIR__ . '/../db/migrations/106_sms_er_av_som_standard.sql'),
                 'MODIFY COLUMN sms_paaminnelse TINYINT(1) NOT NULL DEFAULT 0;')
    && str_contains(file_get_contents(__DIR__ . '/../db/migrations/106_sms_er_av_som_standard.sql'),
                 'UPDATE courses SET sms_paaminnelse = 0 WHERE sms_paaminnelse <> 0;'));

// Det som bare gjelder medlemmer hoerer ikke hjemme paa en side hvem som
// helst kan se.
sjekk('interne samlinger staar ikke i den offentlige kalenderen',
    str_contains($sida, 'visesIKalenderen(k) {')
    && str_contains($sida, "return k.tema !== 'Kun for medlemmer';"));
// Regelen maa gjelde begge steder. Brukes den bare i lista, teller
// okterEtterUke() dem likevel, og kalenderen aapner paa feil uke.
sjekk('… ogsaa naar det regnes ut hvilken uke den aapner paa',
    substr_count($sida, 'if (!this.visesIKalenderen(k)) return;') === 2
    && !str_contains($sida, "if (k.tema === 'Kun for medlemmer') return;"));
// Sida lovet «kurs, events og drop-in samlet». Det stemte ikke lenger.
sjekk('… og teksten lover ikke et tilbud som ikke finnes',
    !str_contains($sida, 'Kurs, events og drop-in samlet')
    && str_contains($sida, 'Kurs og events samlet, uke for uke.'));

// Eieren, 31. august, med et bilde av footeren paa telefon: «Nordre løkkevei
// 15 paa en linje takk». Hele adressen sto som én streng, og i den smale
// spalta brakk den midt i gatenavnet. Spalta er 151 piksler og gata trenger
// 108, saa den faar plass — den maa bare ikke faa lov til aa brekke.
sjekk('gateadressen i footeren brekker ikke',
    str_contains($sida, '<span style="display: block; white-space: nowrap;">{{ ftAdresse1 }}</span>'));
sjekk('… og postnummeret staar paa linja under',
    str_contains($sida, "const komma = hel.indexOf(',');")
    && str_contains($sida, 'ftAdresse1: komma === -1 ? hel : hel.slice(0, komma).trim(),')
    && str_contains($sida, '{{ ftAdresse2 }}'));

// Eieren, 31. august: «kan du legge til paa sporsmaal og svar: Kan man
// bestille kun brenning hos dere? Nei, det er forbeholdt medlemmer».
// Standardteksten staar i malen og feltet i redigeringsskjemaet — begge maa
// med, ellers staar sporsmaalet der uten aa kunne endres, eller omvendt.
sjekk('sporsmaalet om brenning staar paa sida',
    str_contains($sida, "{ q: 'Kan jeg bestille bare brenning hos dere?', a: 'Nei. Brenning er forbeholdt medlemmer.' },"));
// Var nummer 12. De to spoersmaalene om drop-in er tatt bort — se
// docs/DROP-IN.md — saa brenningsspoersmaalet er naa nummer 10.
sjekk('… og kan endres under Nettsiden → Innhold',
    str_contains($sida, "{ l: 'Spørsmål 10', v: 'Kan jeg bestille bare brenning hos dere?' },")
    && str_contains($sida, "{ l: 'Svar 10', v: 'Nei. Brenning er forbeholdt medlemmer.', lang: true } ] }")
    && str_contains($sida, "type: '10 spørsmål'"));
// Og ingen av dem handler om drop-in lenger.
sjekk('… og ingen av spoersmaalene handler om drop-in',
    !str_contains($sida, "{ q: 'Hvordan fungerer drop-in?'")
    && !str_contains($sida, "{ q: 'Hva er inkludert i drop-in?'"));

// bin/breddesjekk.mjs sitt foerste funn utenom feilen den ble laget for:
// de tre kortene paa «Slik virker Vipps hos oss» naadde til 902 piksler paa
// en skjerm som er 390. «lx-cols4» er klassen som faar et rutenett til aa
// falle til to spalter paa nettbrett og én paa telefon, og den manglet.
sjekk('de tre Vipps-kortene faller til én spalte paa telefon',
    str_contains($sida, '<div class="lx-cols4" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-6); align-items: start;">'));

// Eieren, 31. august, med et bilde fra «Frys av medlemskap» paa mobil: «se
// pillene som er for store». Datofeltene sto stablet i full bredde fordi
// kolonnen kreide 160 piksler — to fikk ikke plass. Maalt paa 390 px: to felt
// á 325 px i to rader (131 px) ble to á 157 px paa én rad (44 px).
sjekk('datofeltene i frys staar to i bredden ogsaa paa mobil',
    str_contains($sida, 'grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: var(--space-3);'));
// Safari paa iPhone gir «input type=date» sin egen hoyde og midtstiller
// datoen. «appearance: none» tar begge deler.
sjekk('… og datofeltet har sin egen, lavere stil',
    str_contains($sida, 'frysDatoStil: Object.assign({}, felt, {')
    && str_contains($sida, "padding: '8px 10px', textAlign: 'left',")
    && str_contains($sida, "WebkitAppearance: 'none', appearance: 'none', minHeight: 0,")
    && substr_count($sida, '{{ frysDatoStil }}') === 2);

// Eieren, 31. august, om sidemenyen: «denne visningen er fin, smal bredde,
// evnt kan du teste med en bredde men lavere hoyde paa pillene. I allefall
// paa mobil maa det vaere slik». Spurt om pc-en ogsaa skulle ha den: «bare
// mobil». Saa pc beholder to i bredden, mobilen faar én lav pille.
sjekk('sidemenyen har to i bredden paa pc og én paa mobil',
    str_contains($sida, "klSideRutenett: this.erSmal()")
    && str_contains($sida, "? { display: 'grid', gridTemplateColumns: '1fr', gap: '4px' }")
    && str_contains($sida, ": { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '6px' },"));
sjekk('… og kurspilla er én linje paa mobilen',
    str_contains($sida, "? { display: 'flex', alignItems: 'baseline', gap: '8px', padding: '4px 8px' }")
    && str_contains($sida, ": { padding: '6px 8px' }),")
    && str_contains($sida, "this.erSmal() ? { marginLeft: 'auto', flex: '0 0 auto' } : {}),"));
// ── Ventelistepilla er like stor som kurspilla ────────────────────────
//
// Ventelistekortet sto med 16 px display-skrift og 12/14 px luft, mens
// kurspillene rett over sto med 12 px og 6/8. To lister under hverandre i den
// samme spalta, i to stoerrelser.
//
// Eieren, 1. september: «jeg vil ha samme stoerrelse paa pillen som paa
// kursene over». Tallene er hentet ordrett fra klSideKurs.
//
// Denne staar for at de to ikke skal gli fra hverandre igjen: endrer noen
// kurspilla, skal ventelistepilla foelge med, eller sjekken bli roed.
// Uten avsluttende klamme: kurspilla har en opacity til slutt (kladd staar
// blekt), ventelistepilla har ikke det. Alt foran er likt, og det er formen.
$kurspille = "borderRadius: 'var(--radius-sm)', cursor: 'grab', userSelect: 'none', minWidth: 0";
sjekk('… og ventelistepilla er like stor som kurspilla',
    substr_count($sida, $kurspille) === 2
    && substr_count($sida, "? { display: 'flex', alignItems: 'baseline', gap: '8px', padding: '4px 8px' }") === 2
    && substr_count($sida, ": { padding: '6px 8px' }),") === 2);
sjekk('… med den samme skrifta i navnet og i det under',
    substr_count($sida, "navnStil: Object.assign({ fontSize: '12px', fontWeight: 700, color: 'var(--text-heading)', lineHeight: 1.3, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' },") === 2
    && substr_count($sida, "fontSize: '10px', color: 'var(--text-muted)', overflow: 'hidden', whiteSpace: 'nowrap' },") === 2);
// Bare venstrekanten skiller dem, saa man ser hvilken liste man er i.
sjekk('… men ventelista beholder den terrakotta venstrekanten',
    str_contains($sida, "borderLeftColor: 'var(--terracotta-500)', borderRadius: 'var(--radius-sm)'"));

// ── Et klikk aapner henne der plassen gis ─────────────────────────────
//
// Pilla kunne bare dras. Eieren, 1. september: «og jeg vil kunne trykke paa
// den og komme inn for aa tildele eller avslaa ventelisten».
//
// Ingen ny skjerm: Venteliste-raden har allerede datoknappene, «Varsle» og
// «Fjern», og aapnes paa vlApen.
sjekk('et klikk paa ventelistepilla aapner hennes rad paa Venteliste',
    str_contains($sida, "this.gaaAdmin('adminventeliste', {")
    && str_contains($sida, 'vlApen: p.id,'));
sjekk('… med den samme emnelinja raden setter selv',
    str_contains($sida, "vlEmne: 'Ledig plass på ' + (p.kurs || ''),"));
// Et klikk er et trykk uten aa dra. Draget skal fortsatt virke.
sjekk('… mens draget fortsatt gir plassen paa den okta du slipper paa',
    str_contains($sida, 'if (!d) return;')
    && str_contains($sida, 'if (!d.moved) {')
    && str_contains($sida, "this.setState({ klVlBekreft: { id: p.id, navn: p.navn, malId: malId } });"));

// Eieren, 31. august: «kan kortene altsaa avtalene i kalendere skaleres naar
// jeg trekker et kort til paa samme tid?» — ja, de deler bredden. Bade i
// dagsvisninga og i uka.
sjekk('blokker til samme tid deler bredden',
    // Uka deler hele bredden. Dagsvisningen deler stripa blokka staar i —
    // se «Paint on Pots tar ikke spalta» under.
    substr_count($sida, "left: 'calc(' + (p.lane / p.av * 100) + '% + ") === 1
    && substr_count($sida, "width: 'calc(' + (100 / p.av) + '% - ") === 1
    && str_contains($sida, "left: 'calc(' + (p.fra + p.lane / p.av * p.bredde) + '% + '")
    && str_contains($sida, "width: 'calc(' + (p.bredde / p.av) + '% - '"));
// Delinga gaar per klynge, ikke per dag: to som kraesjer klokka ti skal ikke
// gjore alt annet den dagen smalere. Tre kall naa: de smale for seg, de
// andre for seg, og uka.
sjekk('… og bredden deles per klynge, ikke per dag',
    str_contains($sida, 'const delBredden = liste => {')
    && str_contains($sida, 'if (klynge.length && p.s >= klyngeSlutt) lukk();')
    && substr_count($sida, 'delBredden(') === 3);

// ── Dagsoppgjoeret, slik regnskapsfoereren ba om det ───────────────────
//
// Hun saa paa fila 1. september og svarte paa alle punktene. Det eneste hun
// ville ha annerledes var kolonnene:
//
//   «Det skal ikke vaere debet- og kreditkolonner men man bruker fortegn i
//    beloep (positivt beloep = debet, negativt beloep = kredit). For oevrig
//    ser det bra ut.»
$doFil = file_get_contents(dirname(__DIR__) . '/api/admin/dagsoppgjor.php');
sjekk('fila har ett beloepsfelt, ikke debet og kredit',
    str_contains($doFil, "fputcsv(\$f, ['Dato', 'Bilagstekst', 'Konto', 'Mva-kode', 'Beløp', 'Beskrivelse']")
    && !str_contains($doFil, "'Debet', 'Kredit'"));
// Inntekt er kredit og skal staa negativt; pengene inn er debet og positivt.
sjekk('inntekt staar negativt, innbetalinger positivt',
    str_contains($doFil, "\$kr(-\$l['belopOre'])")
    && str_contains($doFil, "\$kr(\$i['belopOre'])"));

// «Butikk boer skilles fra medlemskap slik at man kan beregne
// bruttofortjeneste.» De sto begge paa 3000.
sjekk('butikken har sin egen konto',
    str_contains($doFil, "'ordre'      => ['navn' => 'Varer i butikk',"));

// Kontoene for et tilbud som ikke finnes.
sjekk('det revne tilbudet staar ikke i regnskapsoppsettet',
    !str_contains($doFil, 'regnskap_konto_dropin')
    && !str_contains($doFil, 'regnskap_mva_dropin'));
sjekk('… og ikke blant feltene i admin heller',
    !str_contains($sida, "'regnskap_konto_dropin'")
    && !str_contains($sida, "'regnskap_mva_dropin'"));

// Kontoene hun opprettet i Tripletex.
$m116 = file_get_contents(dirname(__DIR__) . '/db/migrations/116_kontoene_fra_regnskapsforeren.sql');
foreach ([['regnskap_konto_kurs', '3200'], ['regnskap_mva_kurs', '6'],
          ['regnskap_konto_medlemskap', '3000'], ['regnskap_mva_medlemskap', '3'],
          ['regnskap_konto_butikk', '3020'], ['regnskap_mva_butikk', '3'],
          ['regnskap_konto_gavekort', '2905'],
          ['regnskap_motkonto_vipps', '1510'], ['regnskap_motkonto_kontant', '1900'],
          ['regnskap_motkonto_faktura', '1920']] as [$n, $v]) {
    sjekk('migrasjon 116 setter ' . $n . ' = ' . $v,
        preg_match("~'" . $n . "',\s*'" . $v . "'~", $m116) === 1);
}
sjekk('… og rydder bort kontoen om den sto der',
    str_contains($m116, "WHERE nokkel IN ('regnskap_konto_dropin', 'regnskap_mva_dropin')"));

// Er kontoene faktisk satt i basen, skal bilaget ikke si at noe mangler.
if (DB::harTabell('innstillinger')) {
    $sattFeil = [];
    foreach ([['regnskap_konto_kurs', '3200'], ['regnskap_konto_medlemskap', '3000'],
              ['regnskap_konto_butikk', '3020'], ['regnskap_konto_gavekort', '2905'],
              ['regnskap_motkonto_vipps', '1510'], ['regnskap_motkonto_kontant', '1900'],
              ['regnskap_motkonto_faktura', '1920']] as [$n, $v]) {
        $har = trim((string) DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => $n]));
        if ($har !== '' && $har !== $v) {
            $sattFeil[] = $n . ' = ' . $har;
        }
    }
    sjekk('kontoene i basen er dem regnskapsfoereren oppga',
        $sattFeil === [], implode(', ', $sattFeil));
    sjekk('… og kontoen ligger ikke igjen',
        DB::en("SELECT nokkel FROM innstillinger WHERE nokkel LIKE 'regnskap%dropin'") === null);
}

// ── Deltakerne er kaffekopper fra logoen ──────────────────────────────
//
// Kortene i kalenderen hadde en liten menneskefigur per paameldt deltaker —
// et hode og et par skuldre, hentet fra et ikonsett og ikke fra oss.
//
// Eieren, 1. september: «kortene i kallenderen har smaa avtarer en pr
// kursdeltaker som er pmeldt, jeg vil at disse endres til smaa kaffekopper
// fra logen vaar».
//
// Koppen er ikke tegnet paa nytt: banene er hentet ordrett fra mark-cup.svg,
// samme merke som staar oeverst til venstre. Bade koppen og skaala.
$merket = file_get_contents(dirname(__DIR__) . '/mark-cup.svg');
preg_match_all('~<path[^>]*\sd="(.*?)"~s', $merket, $mm);
$reint = static fn(string $b): string => trim((string) preg_replace('~(&#xA;|&#x9;|\s)+~', ' ', $b));
sjekk('merket har skaala, koppen og ordmerket', count($mm[1]) === 3);
// Ordmerket «lissom» er den tredje banen. Den ligger utenfor viewBox-en og
// skal ikke vaere med — ellers drar vi 5,8 kB tekst inn i hvert kort.
$skaala = $reint($mm[1][0]);
$koppen = $reint($mm[1][1]);
$ordet  = $reint($mm[1][2]);
sjekk('koppen i kalenderen er banen fra logoen', str_contains($sida, $koppen));
sjekk('… og skaala under den ogsaa', str_contains($sida, $skaala));
sjekk('… mens ordmerket ikke er dratt med', !str_contains($sida, $ordet));

// To steder: dagvisningen har en kopp per deltaker, ukevisningen har en kopp
// og et tall. Begge sto med den samme menneskefiguren.
sjekk('begge visningene bruker koppen',
    substr_count($sida, 'viewBox="60 40 192 112"') === 2);
sjekk('… og menneskefiguren er borte',
    !str_contains($sida, '<circle cx="12" cy="8" r="4"></circle>'));

// Koppen arver kortets egen tekstfarge. Kortene har seks bakgrunner — gul,
// terrakotta, slip, brun, leire og hvit — og en fast brun kopp ville
// forsvunnet paa de fire morke. Malt i nettleseren: paa terrakotta staar
// koppen i rgb(239,239,239), paa gult i rgb(77,29,18), begge like kortets
// egen tekstfarge.
sjekk('koppene tegnes i kortets tekstfarge',
    substr_count($sida, 'viewBox="60 40 192 112" fill="currentColor"') === 2);
// Ringen rundt ansiktet er borte fra kalenderen. Merk at samme runde form
// brukes av medlemsavatarene med initialer paa andre skjermer — de skal staa
// som de er, saa dette maa maales paa kalenderens egen stil og ikke paa
// formen i seg selv.
sjekk('… og ringen rundt ansiktet er borte fra kalenderen',
    !str_contains($sida, "stil: { width: '18px', height: '18px', borderRadius: '50%'")
    && str_contains($sida, "stil: { flex: '0 0 auto', display: 'inline-flex', alignItems: 'center', color: 'currentColor' },"));
sjekk('… mens medlemsavatarene med initialer staar urort',
    substr_count($sida, '{{ m.avatarStil }}') === 2
    && str_contains($sida, "borderRadius: '50%'"));

// Navnet staar fortsatt paa hver kopp. Det er hele grunnen til at det er en
// per deltaker og ikke bare et tall.
sjekk('hver kopp baerer fortsatt navnet til den paameldte',
    str_contains($sida, '<span title="{{ av.tittel }}" style="{{ av.stil }}">')
    && str_contains($sida, 'tittel: dl.navn,'));

// ── Den som venter paa kurset finnes ogsaa ────────────────────────────
//
// Ventelista har to slag folk: de som valgte en bestemt kveld, og de som
// valgte kurset. De siste far course_session_id = NULL (api/venteliste.php),
// og det gjor ogsaa hver rad som er eldre enn den kolonna.
//
// Kalenderen hentet koen med «WHERE w.course_session_id IN (...)». Den som
// ventet paa kurset falt ut av spoerringen og var usynlig uansett hvilken
// dato man aapnet — mens hun sto tydelig paa Venteliste-skjermen, som bruker
// LEFT JOIN og tar med alle. To skjermer, samme base, motsatt svar.
//
// Eieren, 1. september: «vi har faktisk en paa venteliste, men den vises ikke
// i kallender?» og «men ingen paa venteliste paa kalender side meny, til
// tross for at det er en person paa venteliste i venteliste kortet».
//
// Malt paa denne basen for og etter: Venteliste-skjermen fant 1 rad,
// kalenderen fant 0 paa alle 69 oektene i sju uker — etterpaa 10, alle
// merket «paaKurset».
$kalFil = file_get_contents(dirname(__DIR__) . '/api/admin/kalender.php');
sjekk('kalenderen henter ogsaa dem som venter paa kurset',
    str_contains($kalFil, 'WHERE w.course_session_id IS NULL'));
sjekk('… og merker dem, saa skjermen kan si hvorfor de staar der',
    str_contains($kalFil, "'paaKurset' => !empty(\$w['paa_kurset']),"));
// Bare kommende datoer: en plass paa en kveld som er over er ingen plass, og
// kalenderen henter en uke bakover.
sjekk('… bare paa datoer som ikke har vaert',
    str_contains($kalFil, "if (\$ko === [] || (string) \$o['start_tid'] < \$naa) {"));
// Og den gamle spoerringa skal fortsatt staa: de som valgte en kveld skal
// vaere paa nettopp den kvelden, ikke paa alle.
sjekk('… mens den som valgte en kveld fortsatt staar bare der',
    str_contains($kalFil, 'WHERE w.course_session_id IN ({$inn})')
    && str_contains($kalFil, "\$w['paa_kurset'] = false;"));

// Regelen, malt mot basen og ikke mot teksten i filen. Uten dette ville
// sjekkene over vaere gronne selv om spoerringa aldri fant noen.
if (DB::harTabell('waitlist') && DB::harTabell('course_sessions')) {
    $utenDato = DB::alle(
        "SELECT id, course_id, navn FROM waitlist
          WHERE course_session_id IS NULL AND status IN ('venter','varslet')"
    );
    // Den gamle spoerringa, ordrett: bare rader som henger paa en okt.
    $gammelFant = array_map(
        static fn(array $r): int => (int) $r['id'],
        DB::alle(
            "SELECT w.id FROM waitlist w
               JOIN course_sessions cs ON cs.id = w.course_session_id
              WHERE w.status IN ('venter','varslet')"
        )
    );
    // Den nye: i tillegg de uten dato, paa kurs som har en kveld igjen.
    $nyFant = array_map(
        static fn(array $r): int => (int) $r['id'],
        DB::alle(
            "SELECT w.id FROM waitlist w
              WHERE w.course_session_id IS NULL
                AND w.status IN ('venter','varslet')
                AND EXISTS (SELECT 1 FROM course_sessions cs
                             WHERE cs.course_id = w.course_id
                               AND cs.start_tid >= NOW())"
        )
    );
    // Ingen av dem den nye finner sto i den gamle. Det er hele feilen:
    // to skjermer, samme base, motsatt svar.
    sjekk('de uten dato er nettopp dem den gamle spoerringa ikke fant',
        array_intersect($nyFant, $gammelFant) === [],
        'gammel ' . count($gammelFant) . ', ny ' . count($nyFant));
    // Og hver av dem har faktisk en kveld aa staa paa — ellers ville fiksen
    // ikke gjort dem synlige noe sted.
    foreach ($nyFant as $wid) {
        $antall = (int) DB::verdi(
            'SELECT COUNT(*) FROM course_sessions cs
               JOIN waitlist w ON w.course_id = cs.course_id
              WHERE w.id = :i AND cs.start_tid >= NOW()',
            ['i' => $wid]
        );
        sjekk('… og hver av dem staar paa minst én kommende dato',
            $antall > 0, 'venteliste #' . $wid . ': ' . $antall . ' datoer');
    }
}

// ── Ingen pille paa kortet ────────────────────────────────────────────
//
// Koen fikk et oyeblikk en pille paa selve kortet som sa «1 venter · paa
// kurset». Eieren, 1. september: «hvorfor merke en venter paa kurset? det er
// ikke det jeg snakker om, saa det skal ikke frem» — og: «men om du ser paa
// sidemenyen paa kallenderen, saa er det et felt under alle kurs, som heter
// venteliste».
//
// Det er sidepanelet som skal fylles, ikke kortet. Denne staar for at pilla
// ikke skal snike seg inn igjen.
sjekk('kortene i kalenderen har ingen pille for koen',
    !str_contains($sida, 'harVenteliste: (p.e.venteliste || []).length > 0,')
    && !str_contains($sida, '{{ h.ventelisteTekst }}')
    && !str_contains($sida, '{{ h.ventelisteAntall }}'));
// Sveipekortet og hoyreklikkmenyen sa det fra for, og skal fortsatt gjore det.
sjekk('… mens sveipekortet fortsatt sier hvor mange som venter',
    str_contains($sida, "' · ' + he.venteliste.length + ' på venteliste'"));
sjekk('… og hoyreklikkmenyen fortsatt kan gi plassen',
    str_contains($sida, "{ navn: 'Tildel plass: ' + menyEvt.venteliste[0].navn,"));

// ── Én person, én rad ─────────────────────────────────────────────────
//
// Den som venter paa kurset staar paa hver kommende dato. Sidepanelet lister
// én rad per person per dato og kutter paa aatte — uten en dublettsjekk ville
// én person fylt hele panelet alene. Malt i nettleseren: hun staar én gang.
sjekk('sidepanelet viser hver som venter én gang',
    str_contains($sida, "klVlSide: (() => { const sett = {}; return alle.filter(")
    && str_contains($sida, "if (sett[n2]) { return false; }"));
sjekk('… og sier «Hele kurset» framfor en kveld hun ikke har valgt',
    str_contains($sida, "under: (v.paaKurset
                ? 'Hele kurset'"));
// Grupperte oekter (et kurs over flere dager paa én paamelding) legger
// koene sammen. Uten dublettsjekk ville «3 venter» vaert én person.
sjekk('grupperte oekter teller ikke den samme personen flere ganger',
    str_contains($sida, "kjede.forEach(e => (e.venteliste || []).forEach(v => {"));

// ── Tre e-poster med samme emne ───────────────────────────────────────
//
// «Takk for bestillingen hos Lissom!» sto som emne paa tre forskjellige
// meldinger: kurspaamelding, butikkvare til henting, og butikkvare i pakke.
// Kunden fikk samme linje i innboksen enten hun hadde kjopt en kopp, fatt en
// pakke i posten, eller meldt seg paa et dreiekurs.
//
// Eieren, 1. september, om det som sto under Systemmeldinger: «denne staar jo
// to ganger og eposten ser jo helt feil ut».
$mig119 = file_get_contents(dirname(__DIR__) . '/db/migrations/119_tre_eposter_med_samme_emne.sql');
sjekk('kurspaameldingen far sitt eget emne',
    str_contains($mig119, "SET emne  = 'Du er påmeldt {kurs}',"));
sjekk('… henting far sitt', str_contains($mig119, "SET emne = 'Bestillingen din er klar til henting'"));
sjekk('… og pakke far sitt', str_contains($mig119, "SET emne = 'Takk for bestillingen — den sendes som pakke'"));
// Har eieren skrevet om en av dem selv, skal hennes ord staa.
// Alle tre er vernet paa det gamle emnet, og kurspaameldingen ogsaa paa den
// gamle teksten — den skrives helt om, saa den maa vaere sikrere enn de to
// som bare bytter emne.
sjekk('… og en mal hun har endret selv roeres ikke',
    substr_count($mig119, "emne = 'Takk for bestillingen hos Lissom!'") === 3
    && str_contains($mig119, "AND tekst LIKE '%Vi har mottatt bestillingen din%'"));

// ── Kurset og naar, hver for seg ──────────────────────────────────────
//
// Kursnavnet og datoen var limt sammen i ett felt, saa malen matte si
// «bestillingen din ({ordre})» — datoen gjemt i en parentes etter feil ord.
//
// Malt ende til ende gjennom Booking::sendBekreftelse og varselkoen:
//   emne   «Du er paameldt Paint on Pots»
//   naar   «onsdag 2. september, 11:24»
//   to kvelder: «onsdag 14. – torsdag 15. oktober, 17:00»
$bkFil = file_get_contents(dirname(__DIR__) . '/app/lib/booking.php');
sjekk('kurset og naar er to felt', str_contains($bkFil, "'kurs'  => (string) \$b['tittel'],")
    && str_contains($bkFil, "'naar'  => \$naar,"));
// Sluttida maa hentes, ellers kan et flerdagerskurs ikke si begge dagene.
sjekk('… og sluttida hentes, saa to kvelder blir to kvelder',
    str_contains($bkFil, 'SELECT b.*, c.tittel, cs.start_tid, cs.slutt_tid,')
    && str_contains($bkFil, "self::norskPeriode((string) \$b['start_tid'], \$b['slutt_tid'] ?? null)"));
// Koden rulles ut noen minutter for migrasjonen kjores. I det vinduet staar
// den gamle malen, og den bruker {ordre}. Malt: den fyller seg som for.
sjekk('… mens {ordre} staar igjen for vinduet for vedlikeholdet er kjort',
    str_contains($bkFil, "'ordre' => (string) \$b['tittel'] . (\$naar !== '' ? ' — ' . \$naar : ''),"));
sjekk('… og registeret lover de nye feltene',
    str_contains(file_get_contents(dirname(__DIR__) . '/app/lib/maler.php'),
                 "'kurs'  => 'Navnet på kurset',"));

// Malen i basen, naar vedlikeholdet er kjort.
if (DB::harTabell('notification_templates')) {
    $ob = DB::en("SELECT emne, tekst FROM notification_templates WHERE navn = 'ordrebekreftelse'");
    if ($ob !== null) {
        sjekk('malen i basen sier ikke lenger «bestillingen din» om et kurs',
            !str_contains((string) $ob['tekst'], 'bestillingen din'),
            mb_substr((string) $ob['emne'], 0, 60));
        sjekk('… og de tre emnene er forskjellige',
            (int) DB::verdi(
                "SELECT COUNT(DISTINCT emne) FROM notification_templates
                  WHERE navn IN ('ordrebekreftelse','butikkordre','butikkordre_pakke')"
            ) === 3);
    }
}

// ── To redigeringsbokser som ikke hoerer hjemme paa en telefon ────────
//
// Grupperabatten og banner-redigeringen er to skjemaer med felter, tallrader
// og ni-ti brikker hver. Paa Oversikt ble de to lange sperrer man maa rulle
// forbi paa en telefon for aa komme til noe man faktisk skal gjore der og da.
//
// Eieren, 1. september, med bilde av begge: «paa admin mobil oversikt, fjern
// banneret under toppbildet, og grupperabatt».
//
// Malt i nettleseren: paa 390 px finnes ingen av dem, paa 1500 px staar begge.
sjekk('grupperabatten staar bare paa pc', str_contains($sida, 'visGrRabatt: !this.erSmal(),'));
sjekk('bannerboksen staar bare paa pc', str_contains($sida, 'visBannerRed: !this.erSmal(),'));
sjekk('… og markupen spor om det',
    str_contains($sida, '<sc-if value="{{ visGrRabatt }}" hint-placeholder-val="{{ true }}">')
    && str_contains($sida, '<sc-if value="{{ visBannerRed }}" hint-placeholder-val="{{ true }}">'));
// Av/paa-bryteren som heter det samme er noe annet: den styrer om banneret
// vises for kunden, og staar igjen. Den er én linje, ikke et skjema.
sjekk('… mens av/paa-bryteren med samme navn staar igjen',
    str_contains($sida, 'label="Banneret under toppbildet"'));

// ── Ingen priser skrevet inn i koden ──────────────────────────────────
//
// Paint on Pots viste tre forskjellige tall paa den samme sida:
//
//   «Fra kr. 800,-»   prislinja  (plassen + billigste malbare gjenstand)
//   «kr. 690,-»       knappen    (en pristabell skrevet inn i koden)
//   kr. 500,-         det serveren faktisk trakk (courses.pris_ore)
//
// Eieren, 1. september: «Kr 500 er kr 500 alt annet fjernes», «Ingen
// hardkode priser» og «Kr 500 som jeg kan endre senere i admin. Ingen fra
// pris».
//
// Malt i nettleseren for og etter: tre tall ble til ett — kr. 500,- baade i
// prislinja og paa knappen, som er det serveren regner ut.
sjekk('ingen pristabell i koden',
    !str_contains($sida, "'Nybegynner dreiekurs': 2800")
    && !str_contains($sida, "'Paint on Pots': 690")
    && !preg_match('~\n  priser\(\) \{~', $sida));
sjekk('… og prisAv henter fra katalogen paa serveren',
    str_contains($sida, "const kurs = (this.state.katalog || []).find(x => x.tittel === navn);")
    && str_contains($sida, 'return Math.round(kurs.prisOre / 100);'));
// Uten pris fra serveren skal skjermen tie, ikke gjette.
sjekk('… og gjetter ikke naar serveren ikke sier noe',
    str_contains($sida, "    return 0;\n  }\n\n  leggTil(navn) {"));

// ── Ingen «fra»-pris ──────────────────────────────────────────────────
sjekk('kortene viser prisen, ikke «Fra»',
    str_contains($sida, "price: k.pris === 'Gratis' ? 'Gratis' : k.pris,"));
sjekk('… og den store prislinja gjor det samme',
    str_contains($sida, "bPris: k.price === 'Gratis' ? 'Gratis'\n            : (grunn > 0 ? fmt(netto) : (k.price || '')),"));
sjekk('… ogsaa der gjenstanden velges i verkstedet',
    str_contains($sida, "bPris: fmt(kat2.prisFraOre / 100 * antall),"));

// ── Paint on Pots koster 500 ──────────────────────────────────────────
$mig120 = file_get_contents(dirname(__DIR__) . '/db/migrations/120_paint_on_pots_koster_500.sql');
sjekk('migrasjonen setter prisen til 500',
    str_contains($mig120, 'SET pris_ore = 50000,'));
sjekk('… og slaar av «gjenstand i kassa», som la 300 oppaa',
    str_contains($mig120, 'gjenstand_i_kassa = 0'));
if (DB::harTabell('courses') && DB::harKolonne('courses', 'gjenstand_i_kassa')) {
    $pop = DB::en("SELECT pris_ore, gjenstand_i_kassa, status FROM courses WHERE slug = 'paint-on-pots'");
    if ($pop !== null) {
        sjekk('Paint on Pots koster 500 i basen', (int) $pop['pris_ore'] === 50000,
            (int) $pop['pris_ore'] . ' oere');
        sjekk('… og legger ikke gjenstanden oppaa', (int) $pop['gjenstand_i_kassa'] === 0);
        // Et kurs uten pris far ingen bookingknapp i det hele tatt — knappen
        // ligger inne i «erBetalt» eller «erGratis», og et kurs til 0 er
        // ingen av delene. Med 500 er det «erBetalt».
        sjekk('… saa kurset har noe aa booke', (int) $pop['pris_ore'] > 0);
    }
}
// Prisen skal kunne endres i admin. Feltet finnes, og lagringen sender
// alltid status — uten den ville kurset blitt avpublisert, fordi kurs.php
// skriver status ubetinget.
sjekk('prisen kan endres i admin', str_contains($sida, 'id="k-pris"'));
sjekk('… og lagringen sender status, saa kurset ikke avpubliseres',
    str_contains($sida, "status: raa.status || 'publisert',"));

// ── Én oppfordring for lite er bedre enn tre ─────────────────────────
//
// Kontaktsida sa det samme tre ganger: «Kontakt oss» i toppmenyen, «Send
// foresporsel» i boksen midt paa, og «Send oss en foresporsel» i bandet
// nederst — alle tre til det samme skjemaet.
//
// Eieren, 1. september: «Kontskt oss, saa send en foresporsel saa send oss
// en foresporsel 🤣».
//
// Bandet nederst staar i bunnteksten paa hver side. Paa alle andre sider er
// det den eneste oppfordringen, og der gjor det jobben sin. Bare paa
// kontaktsida ble det den tredje.
//
// Malt i nettleseren: tre knapper ble til to paa /kontakt.
sjekk('bunnbandet staar ikke paa kontaktsida',
    str_contains($sida, "visFotOppfordring: side !== 'kontakt',"));
sjekk('… og markupen spor om det',
    str_contains($sida, '<sc-if value="{{ visFotOppfordring }}" hint-placeholder-val="{{ true }}">'));
// Bunnteksten selv staar fortsatt paa kontaktsida — det er bare bandet
// oeverst i den som gaar bort.
sjekk('… mens resten av bunnteksten staar som for',
    str_contains($sida, "'gavekortside', 'kontakt', 'ferdigbrent'].indexOf(side) !=="));

// ── «Kontakt oss» aapner det enkle skjemaet ───────────────────────────
//
// Knappen paa et kurskort uten datoer aapnet gruppeforespoerselen: «Antall
// personer 2–5 / 6–10 / 11–16» og «Hva oensker dere? F.eks. teambuilding for
// 10 kolleger». Paa Date Night — en kveld for to — var hvert eneste felt
// feil.
//
// Eieren, 1. september: «kontakt oss maa vaere med emne datenight og ikke
// flrespoerselskjemaet som er naa», med bilde av det enkle skjemaet: «Jeg vil
// de skal faa dette skjemaet».
//
// Gruppeskjemaet er ikke feil i seg selv — det hoerer til «Er dere en gruppe,
// eller har spesielle oensker? Les mer» paa forsida, og staar der som for.
//
// Malt i nettleseren, med Date Night uten datoer slik det staar hos eieren:
// kortet viser kr. 2 990,-, knappen aapner det enkle skjemaet, det sier
// «Gjelder: Date Night», og «Antall personer» er ikke der.
sjekk('kurssida aapner det enkle skjemaet',
    str_contains($sida, "this.setState({ ktApen: true, ktSendt2: false, ktFeil: null,\n          ktEmne: k.title || k.tittel || '', ktTop: Math.min(this.topNaa(60), 90) });"));
sjekk('… og gruppeskjemaet aapnes bare fra gruppelenka',
    substr_count($sida, 'fsApen: true') === 2
    && str_contains($sida, 'goForesporsel: () => this.apneForesporsel(),'));
sjekk('… kurset foelger med som emne til serveren',
    str_contains($sida, "type: (s.ktEmne || '').trim() || 'Kontaktskjema',"));
sjekk('… skjermen sier hva det gjelder',
    str_contains($sida, 'Gjelder: {{ ktEmne }}')
    && str_contains($sida, "ktHarEmne: !!(this.state.ktEmne || '').trim(),"));
// Emnet maa toemmes etter sending, ellers henger kursnavnet igjen paa neste
// foresporsel fra menyen.
sjekk('… og emnet toemmes naar skjemaet er sendt',
    str_contains($sida, "ktNavn: '', ktTlf: '', ktEpost: '', ktMelding: '', ktEmne: '' });"));

// ── Date Night koster 2990 ────────────────────────────────────────────
$mig121 = file_get_contents(dirname(__DIR__) . '/db/migrations/121_date_night_koster_2990.sql');
sjekk('migrasjonen setter Date Night til 2990', str_contains($mig121, 'SET pris_ore = 299000'));
if (DB::harTabell('courses')) {
    $dn = DB::en("SELECT pris_ore FROM courses WHERE tittel = 'Date Night'");
    if ($dn !== null) {
        sjekk('Date Night koster 2990 i basen', (int) $dn['pris_ore'] === 299000,
            (int) $dn['pris_ore'] . ' oere');
    }
}

// ── Den tomme avtale-ID-en sperret betalingen ─────────────────────────
//
// «subscriptions.vipps_agreement_id» har UNIQUE KEY uq_subs_agreement.
// Medlemskap::startEngangs() satte den til tom streng i stedet for NULL. To
// rader med '' er to like verdier, og den andre ble avvist:
//
//   SQLSTATE[23000]: Integrity constraint violation: 1062
//   Duplicate entry '' for key 'uq_subs_agreement'
//
// Feilen slo inn fra og med den ANDRE gangen noen betalte paa denne maaten:
// den forste raden gikk gjennom og ble staaende, og alle etter den traff
// den. Eieren fikk den 2. september da han skulle betale for medlemskapet
// sitt.
//
// En engangsbetaling har ingen avtale i Vipps, og skal staa uten. NULL
// teller ikke som en verdi i en unik noekkel, saa flere rader kan staa slik.
$mlib2 = file_get_contents(dirname(__DIR__) . '/app/lib/medlemskap.php');
sjekk('engangsbetalingen staar uten avtale-id, ikke med tom streng',
    str_contains($mlib2, "'vipps_agreement_id' => null,"));
// Kontrollen: den tomme strengen skal vaere borte.
sjekk('… og den tomme strengen er borte',
    !str_contains($mlib2, "'vipps_agreement_id' => '',"));
// Avtalen som VIRKELIG finnes i Vipps skal fortsatt lagres.
sjekk('… mens en ekte avtale fortsatt lagres',
    str_contains($mlib2, "'vipps_agreement_id' => \$vipps['avtaleId'],"));
// Raden som alt staar med '' sperrer for alle andre til den ryddes.
$mig125 = file_get_contents(dirname(__DIR__) . '/db/migrations/125_tom_avtale_id_sperret_betaling.sql');
sjekk('migrasjonen rydder raden som sperrer',
    str_contains($mig125, 'SET vipps_agreement_id = NULL')
    && str_contains($mig125, "WHERE vipps_agreement_id = ''"));
if (DB::harTabell('subscriptions')) {
    $tomme = DB::verdi("SELECT COUNT(*) FROM subscriptions WHERE vipps_agreement_id = ''");
    sjekk('ingen rad staar med tom avtale-id i basen', (int) $tomme === 0, $tomme . ' rader');
}

// ── «Velg» endte paa Min side uten noe der ────────────────────────────
//
// Innmeldingsskjemaet paa Min side staar bak «innlogget og ikke medlem» — se
// visBliMedlem. Og er_aktivt_medlem() i app/lib/auth.php sier at admin ALLTID
// teller som medlem:
//
//   if ((string) ($medlem['rolle'] ?? '') === 'admin') { return true; }
//
// Eieren er admin. For ham fantes skjemaet aldri: «Velg» sendte ham til Min
// side, der sto ingenting, og det skjedde ingenting. Ingen beskjed, ingen
// Vipps, ingen vei videre.
//
// Eieren, 2. september: «totalslakt av velg knappen, jeg kommer direkte inn
// paa min side ingen vippsbetsling», og «jeg kommer altsaa rett inn paa min
// side naar jeg proever aa betale for mitt medlemskap».
//
// Et medlem trenger ikke soke om aa bli medlem. Det som mangler er avtalen,
// og den starter samme sted som «Forny». Malt i nettleseren som innlogget
// admin, med kallet fanget: dialogen «Hvordan vil du betale?» aapner, og
// «Fast trekk i Vipps» sender {handling:start, plan:«Fri tilgang»,
// betaling:trekk}.
// Betalingsvalget staar paa sida, ikke i en dialog. Eieren, 2. september:
// «hvorfor lager du ting paa nytt, naar det allerede finnes i systemet» og
// «den maa jo vaere helt identisk som naar man betaler for et kurs».
//
// Malt i nettleseren: ingen dialog spretter opp; boksen viser «Betaling»,
// de to pillene, teksten under, og knappen «Bli medlem · kr. 990,-» som
// sender {handling:start, plan:«Prov Lissom», betaling:trekk}.
sjekk('«Velg» gaar til betaling for den som alt er medlem',
    str_contains($sida, 'const kanBetaleNaa = this.state.fra === \'medlemskap\'')
    && str_contains($sida, '&& !!(this.state.erMedlemBruker || gammel);'));
// Pillene er skjemaets egne, ikke nye. Da kan de to ikke bli uenige.
sjekk('… med de samme pillene som innmeldingsskjemaet',
    str_contains($sida, '<sc-for list="{{ bmBetalingsvalg }}" as="v" hint-placeholder-count="2">')
    && substr_count($sida, '{{ bmBetalingsvalg }}') === 2);
// Kontrollen: medlemskapssida skal ikke aapne noen dialog. «Forny» paa Min
// side bruker den fortsatt — der er en dialog systemets egen form, som paa
// «Si opp» og «Angre» — men veien fra medlemskapssida gaar rett paa sida.
sjekk('… og medlemskapssida aapner ingen dialog',
    !str_contains($sida, 'this.apneFornyValg(navn, gammelAvtale')
    && !str_contains($sida, 'velgMedlemskapKnapp: () => {\n        const k = this.state.valgtKurs || {};\n        this.apneFornyValg'));
// Den som har vaert medlem for skal ikke soke paa nytt om noe hun alt har
// hatt. Serveren sender ogsaa en avtale som er stoppet eller utloept, og den
// er nok til aa vite det. Eieren: «er du ikke aktivt medlem saa maa man faa
// beskjed om aa fornye».
sjekk('… og den som har vaert medlem far beskjed om aa fornye',
    str_contains($sida, 'const gammel = this.state.minAvtale || null;')
    && str_contains($sida, 'Medlemskapet ditt løper ikke lenger. Forny det her'));
sjekk('… og knappen sier «Forny medlemskapet» da',
    str_contains($sida, "medlemsBetalTekst: (utloept ? 'Forny medlemskapet' : 'Bli medlem')"));
// Knappen gaar samme vei som «Forny» paa Min side.
sjekk('… og knappen gaar til startAbonnement, som «Forny»',
    str_contains($sida, "this.startAbonnement(k.title || k.tittel || '', this.bmBetalingsvalgt());"));
// Den som ikke er medlem skal fortsatt til innmeldingen, med planen med seg
// gjennom innloggingen.
sjekk('… og den som ikke er medlem gaar til innmeldingen som for',
    str_contains($sida, "sessionStorage.setItem('lissom_medlemsplan', navn)")
    && str_contains($sida, "side: this.state.innlogget ? 'minside' : 'login',"));
// Regelen som gjorde det usynlig staar urort: admin skal fortsatt komme inn
// paa medlemsdelen. Det var skjemaets port som var feil sted aa lande.
sjekk('… og admin teller fortsatt som medlem paa serveren',
    str_contains(file_get_contents(dirname(__DIR__) . '/app/lib/auth.php'),
                 "if ((string) (\$medlem['rolle'] ?? '') === 'admin') {"));

// ── «Maks 12 deltakere» over en kveld for to ──────────────────────────
//
// Tallet gjelder én kveld med et bestemt antall plasser. Et kurs som settes
// opp naar noen sporr har ingen kveld aa fylle — tallet kom da fra
// kapasiteten i oppsettet, ikke fra noe som var avtalt.
//
// Eieren, 2. september: «datenight fjern maks 12 deltakere».
//
// Malt paa kurssida for og etter: linja er borte.
sjekk('kurs uten datoer sier ikke «Maks N deltakere»',
    str_contains($sida, 'const p = k.kunKontakt ? 0 : (parseInt(k.plasser, 10) || 0);'));
// Kontrollen: den gamle linja, som tok tallet uansett, skal vaere borte.
sjekk('… og den gamle linja er borte',
    !str_contains($sida, "const p = parseInt(k.plasser, 10) || 0;\n        return p > 0 ? ['Maks '"));
// Et vanlig kurs skal fortsatt si det. Tallet er det eneste stedet kunden
// ser hvor mange som er med.
sjekk('… mens et kurs med datoer fortsatt sier det',
    str_contains($sida, "return p > 0 ? ['Maks ' + p + ' deltakere.'].concat(fast) : fast;"));

// ── Avsnittene i beskrivelsen ─────────────────────────────────────────
//
// Eierens tekst paa Date Night har fire avsnitt. Uten «pre-wrap» slaar
// nettleseren linjeskiftene sammen, og teksten blir én klump — mens feltet i
// admin har vist den med avsnitt hele tida. Det man saa i redigeringa var
// altsaa ikke det kunden fikk.
//
// Malt i nettleseren: seks linjeskift i teksten paa sida, og «white-space:
// pre-wrap» paa avsnittet.
// «white-space: pre-wrap» er ikke nok: teksten settes inn i en <span> som
// skjermkoden lager, og den staar med «normal». Malt i nettleseren:
// forelderen fikk pre-wrap, spanen inni beholdt normal, og linjeskiftene ble
// slaatt sammen likevel. Derfor deles teksten i avsnitt, og hvert avsnitt
// faar sitt eget <p>. Malt etter: fire avsnitt paa sida, med luft mellom.
// Sto laast til den ene kodelinja som gjorde delinga. Paastanden er at
// teksten DELES og at hvert avsnitt faar sitt eget <p> — ikke hvordan.
sjekk('kurssida deler beskrivelsen i avsnitt',
    str_contains($sida, '<sc-for list="{{ bOmAvsnitt }}" as="a"')
    && str_contains($sida, 'const deler = raa.split(/\r?\n+/).map(t => t.trim()).filter(Boolean);')
    && str_contains($sida, 'bOmAvsnitt: deler.map((t, i) => ({'));
// Kontrollen: det ene avsnittet som tok hele teksten skal vaere borte.
sjekk('… og det ene avsnittet som tok alt er borte',
    !str_contains($sida, 'text-wrap: pretty;">{{ bOm }}</p>'));
$mig123 = file_get_contents(dirname(__DIR__) . '/db/migrations/123_eierens_tekst_pa_date_night.sql');
sjekk('migrasjonen skriver eierens tekst med avsnitt',
    str_contains($mig123, "'Date Night i keramikkverkstedet 💕'")
    && str_contains($mig123, 'CHAR(10)')
    && str_contains($mig123, 'Den perfekte daten for dere'));
// Har verkstedet skrevet noe eget etter 122, skal deres ord staa.
sjekk('… bare der teksten fra 122 staar',
    str_contains($mig123, "AND beskrivelse = 'En romantisk og kreativ kveld for to."));

// ── Kortet forer til kurset, ikke rett i skjemaet ─────────────────────
//
// Date Night ligger ute uten datoer. Kortet sa «Kontakt oss», og bade
// knappen og selve kortflata aapnet foresporselen med det samme. Kurssida —
// bildene, beskrivelsen, «dette laerer du», «dette faar du med hjem» — var
// det ingen vei til.
//
// Eieren, 2. september: «Datenight, mangler info. Naar jeg klikker paa den
// kommer jeg rett til foresporsel. Bor vel ha samme vei som de andre», og
// «klikk paa kortet, faa opp info», og «da bytte ut kontakt oss paa kortet
// med les mer».
//
// Verre: kurssida VISTE hele bookingskjemaet for et kurs uten datoer —
// «Velg dato · Etter avtale», antall plasser, e-post, telefon, vilkaar og
// «BOOK PLASS · KR. 2 990,-». Malt i nettleseren: fyller man ut alt og
// trykker, sendes ikke ett eneste kall. Knappen var en blindvei som saa ut
// som veien inn.
//
// Malt for og etter: kortet sier naa «LES MER», kortklikket ender paa
// /kurs/date-night, boksen der sier «Etter avtale» med «Kontakt oss», og den
// knappen aapner det enkle skjemaet med «Gjelder: Date Night».
sjekk('kortet forer til kurssida, ogsaa uten datoer',
    str_contains($sida, "book: () => { this.setState({ side: 'booking', fra: 'kurs', valgtKurs: k,"));
// Kontrollen: den gamle grenen, som aapnet skjemaet fra kortet, er borte.
sjekk('… og kortet aapner ikke skjemaet lenger',
    !str_contains($sida, "book: k.kunKontakt"));
// Bookingskjemaet skal ikke staa paa et kurs det ikke gaar an aa booke.
sjekk('kurs uten datoer har ingen datovelger',
    str_contains($sida, "&& !(this.state.valgtKurs && this.state.valgtKurs.kunKontakt)\n               && this.state.fra !== 'medlemskap',"));
sjekk('… og ingen betalingsdel',
    str_contains($sida, "// Et kurs uten datoer betales ikke her. Det avtales forst.\n        if (k.kunKontakt) return false;"));
sjekk('… men en «Kontakt oss» som staar i stedet',
    str_contains($sida, 'visKontaktKurs: !!(this.state.valgtKurs && this.state.valgtKurs.kunKontakt),')
    && str_contains($sida, '<sc-if value="{{ visKontaktKurs }}"'));
sjekk('… og den tar kurset med som emne',
    str_contains($sida, "ktEmne: k.title || k.tittel || '', ktTop: Math.min(this.topNaa(60), 90) });"));

// ── Én linje om salg, ikke to ─────────────────────────────────────────
//
// «Selg egne arbeider gjennom lissom.no» ble lagt paa hver loepende plan,
// uansett hva som alt sto i punktlista. Basis 30 har den skrevet inn i basen
// fra for, og fikk den dermed to ganger rett under hverandre:
//
//   ✓ Tilgang 24/7 med dorkode
//   ✓ Selg egne arbeider gjennom lissom.no
//   ✓ Selg egne arbeider gjennom lissom.no
//
// Malt i nettleseren paa medlemskapssida, for og etter: fire forekomster ble
// til tre. Aarsmedlemskap og Fri tilgang har den fortsatt én gang hver, og
// proveperioden ingen.
//
// Verkstedet skal kunne skrive linja inn selv uten at den kommer dobbelt.
sjekk('punktlista kommer fra basen, ikke fra koden',
    str_contains($sida, 'const punkter = o.punkter || [];'));
// Kontrollen: koden skal ikke legge paa noen linje selv lenger.
sjekk('… og koden legger ikke paa salgslinja',
    !str_contains($sida, "concat(['Selg egne arbeider gjennom lissom.no'])")
    && !str_contains($sida, "const SALG = 'Selg egne arbeider gjennom lissom.no';"));
// Migrasjonen flytter den dit den hoerer hjemme.
$mig124 = file_get_contents(dirname(__DIR__) . '/db/migrations/124_salgslinja_bare_pa_arsmedlemskap.sql');
sjekk('… migrasjonen tar den av alt som ikke er aarsavtale',
    str_contains($mig124, 'WHERE binding_mnd < 12'));
sjekk('… og setter den paa aarsmedlemskapet',
    str_contains($mig124, 'WHERE binding_mnd >= 12')
    && str_contains($mig124, "punkter NOT LIKE '%Selg egne arbeider gjennom lissom.no%'"));
if (DB::harTabell('membership_plans')) {
    // Sto som «Selg egne arbeider» — ordrett den formuleringa migrasjon 124
    // satte inn. Eieren skrev punktlista om selv i migrasjon 128, og linja
    // heter naa «Mulighet til aa selge egne arbeider gjennom lissom.no».
    // Paastanden er hvilken PLAN som har den, ikke hvordan den er formulert.
    $med = DB::alle(
        "SELECT navn, punkter FROM membership_plans
          WHERE punkter LIKE '%elg egne arbeider%' OR punkter LIKE '%elge egne arbeider%'"
    );
    sjekk('salgslinja staar bare paa aarsmedlemskapet i basen',
        count($med) === 1 && str_contains((string) $med[0]['navn'], 'rsmedlemskap'),
        count($med) . ' plan(er): ' . implode(', ', array_column($med, 'navn')));
}

// ── Medlemskapskortet: info forst, «Velg» etterpaa ────────────────────
//
// Kortet sendte «kjop» til bade knappen og kortflata, saa et klikk hvor som
// helst meldte deg inn. «kjopKort», som viser hva medlemskapet er, sto i
// koden fra for og var aldri koblet til noe.
//
// Eieren, 2. september: «klikk paa kortet saa aapnes mer info for man kan
// trykke velg».
//
// De to skilles uten aa roere den genererte komponenten: kortflata kaller
// onBook() uten argument, mens knappen er en React-knapp og sender
// hendelsen med. Se CourseCard i ds-bundle.js.
// Kortet forteller ikke nok til at man kan velge fra det: krav, binding,
// oppsigelse og hva som foelger med staar paa sida under. Eieren, 2.
// september: «og knappene paa alle medlemskapene maa naa hete les mer».
sjekk('medlemskapskortet sier «Les mer», som kurskortene',
    str_contains($sida, 'cta-label="Les mer" on-book="{{ a.lesMer }}"')
    && str_contains($sida, 'lesMer: () => visMer(),'));
sjekk('… og kortflata forer samme sted',
    str_contains($sida, "kjopKort: (e) => { if (e && e.target && e.target.closest && e.target.closest('button')) return; visMer(); },"));
// Infosida hadde den gamle veien nederst: «Opprett avtale i Vipps», med
// e-post, telefon, allergier og vilkaar over. Naa staar «Velg», som gaar
// samme vei som knappen paa kortet.
sjekk('infosida for et medlemskap har «Velg»',
    str_contains($sida, "visVelgMedlemskap: this.state.fra === 'medlemskap',")
    && str_contains($sida, '<sc-if value="{{ visVelgMedlemskap }}"'));
sjekk('… og bookingskjemaet staar ikke under den',
    str_contains($sida, "// Et medlemskap heller ikke: det gaar gjennom innmeldingen.\n        if (this.state.fra === 'medlemskap') return false;"));
// Veien inn laa som en lukking inne i kortlista, og naadde ikke infosida.
sjekk('… og begge gaar gjennom den samme metoden',
    str_contains($sida, 'meldInnPlan(navn) {')
    && substr_count($sida, 'this.meldInnPlan(') === 2);

// ── Kunden ser bare Vipps ─────────────────────────────────────────────
//
// Trykket man «Velg» uten aa vaere innlogget, kom man til en skjerm med
// «Fortsett med Vipps» oeverst og verkstedets egen konto rett under:
// «Brukernavn», «Passord», og «Konto for verkstedet. Har du glemt passordet,
// si fra til Monica — hun setter et nytt.»
//
// Eieren, 2. september: «naar man trykker velg vil jeg ha en vippslosning
// kun, ikke som i dag med annen log in info».
//
// Skjemaet er ikke fjernet. Det staar paa /admin/logg-inn, dit ADMIN nederst
// paa sida forer, og der er Vipps-knappen borte i stedet.
//
// Malt i nettleseren: kundeskjermen har naa null skrivefelt; /admin/logg-inn
// har fortsatt «fornavn» og passordfeltet.
sjekk('kundeinnloggingen har bare Vipps',
    str_contains($sida, "visPassordinnlogging: side === 'adminlogin',"));
sjekk('… og passordskjemaet staar bak den porten',
    str_contains($sida, '<sc-if value="{{ visPassordinnlogging }}"'));
// Verkstedet maa fortsatt komme inn. Vipps-porten er uendret, og de to er
// hverandres motsatte: aldri begge, aldri ingen.
sjekk('… mens verkstedets egen skjerm staar som for',
    str_contains($sida, "visVippsInnlogging: side !== 'adminlogin',")
    && str_contains($sida, "{ sti: '/admin/logg-inn',     side: 'adminlogin' },"));
// «eller» sto mellom de to. Med bare én av dem ville det staatt et «eller»
// uten noe paa den andre sida.
sjekk('… og «eller»-skillet staar ikke alene',
    str_contains($sida, 'visLoginSkille: false,'));

// ── «Forny» lot ingen gjore opp selv ──────────────────────────────────
//
// Eieren, 2. september, fra en iPhone paa Min side: «naar jeg skulle betale
// og valgte forny gikk det bare an aa betale paa Vipps med fast trekk
// maanedlig. Maatte gaa ut av den og inn paa det andre stedet for aa kunne
// velge jeg ordner selv».
//
// Knappen sendte {handling, plan} — uten «betaling». api/medlemskap.php
// leser feltet slik at et fravaer blir «trekk», saa den opprettet alltid en
// loepende avtale. Serveren har stott «selv» siden migrasjon 081; det var
// skjermen som aldri spurte. «Det andre stedet» er innmeldingsskjemaet paa
// medlemskapssida, som har sendt feltet hele tida.
//
// Malt i nettleseren paa 390 px, med kallet til serveren fanget:
//
//   Basis 30      to knapper  → betaling=trekk og betaling=selv
//   Fri tilgang   to knapper  → betaling=trekk og betaling=selv
//   Aarsmedlemskap  én knapp  → betaling=trekk
//   Prov Lissom     én knapp  → «Gjor opp i Vipps»
//
// Det samme fra plankortet under «Bytt abonnement».
sjekk('«Forny» sender betalingsmaaten til serveren',
    str_contains($sida, "this.medlemskapKall({ handling: 'start', plan: navn, betaling: maate }"));
// Kontrollen: det gamle kallet uten feltet skal vaere borte. Uten denne
// ville proven over vaere gronn ogsaa om noen la det tilbake ved siden av.
sjekk('… og det gamle kallet uten feltet er borte',
    !str_contains($sida, "this.medlemskapKall({ handling: 'start', plan: navn }"));
sjekk('… og bare «selv» og «trekk» slipper gjennom',
    str_contains($sida, "const maate = betaling === 'selv' ? 'selv' : 'trekk';"));

// Regelen staar ett sted, saa «Forny» og plankortet ikke kan bli uenige.
sjekk('planen bestemmer hvilke valg som finnes',
    str_contains($sida, "if (pl.fastTrekk) return [{ plan: pl.navn, navn: 'Fast trekk i Vipps', betaling: 'trekk' }];")
    && str_contains($sida, "if (pl.engangs) return [{ plan: pl.navn, navn: 'Betal i Vipps', betaling: 'selv' }];"));
// Knappen het «Jeg ordner selv». Pengene gaar gjennom Vipps ogsaa den veien —
// det er én betaling i stedet for en staaende avtale — saa navnet leste som
// «utenom Vipps», og det er feil. Eieren, 2. september: «bytt fra jeg ordner
// selv paa alle steder til vanlig vipps knapp».
sjekk('… og knappen sier at det er Vipps',
    !str_contains($sida, 'Gjør opp i Vipps')
    && str_contains($sida, "knapp('selv', 'Betal i Vipps')"));
// Bare knappetekstene — kommentaren i koden forteller hvorfor navnet ble
// byttet, og den skal faa staa.
sjekk('… og «Jeg ordner selv» staar ikke igjen som knappetekst',
    !str_contains($sida, "'Jeg ordner selv'")
    && !str_contains($sida, "'Jeg ordner selv — '"));
sjekk('… og begge knappene bruker den samme regelen',
    substr_count($sida, 'this.aboPlanValg(') === 2);
// Ordlyden staar ett sted. Sto den to, ville skjemaet og dialogen etter
// hvert sagt hver sin ting om det samme.
sjekk('de to setningene om betaling staar ett sted',
    str_contains($sida, 'static get BETALINGSORD()')
    && str_contains($sida, "bmBetalingTekst: valgt === 'trekk' ? Component.BETALINGSORD.trekk : Component.BETALINGSORD.selv,"));
sjekk('… og dialogen henter dem derfra',
    str_contains($sida, 'const TREKK = Component.BETALINGSORD.trekk;'));

// Serveren skal fortsatt avgjore. Skjermen kan ta feil; det er bare basen
// som vet hva planen krever.
$mapi = file_get_contents(dirname(__DIR__) . '/api/medlemskap.php');
sjekk('serveren tvinger fast trekk der planen krever det',
    str_contains($mapi, 'if (Medlemskap::kreverFastTrekk($plan)) {'));
sjekk('… og en engangsplan kan ikke faa fast trekk',
    str_contains($mapi, "if ((int) (\$plan['engangs'] ?? 0) === 1) {"));

// ── Kortet viste et annet medlemskap enn medlemmet har ────────────────
//
// aktivPlan() leste bare «state.abo», som settes her paa skjermen naar en
// avtale opprettes i samme okt. Den som lastet Min side paa nytt hadde den
// tom, og da falt kortet ned paa «forste loepende plan».
//
// Malt i nettleseren med Aarsmedlemskap i basen: kortet sa «Basis 30 · kr.
// 2 590,-» mens «Neste trekk» rett under leste den ekte avtalen. Etter:
// «Aarsmedlemskap · kr. 1 990,- · 35 timer i maaneden · aarsavtale».
//
// Det avgjor ogsaa betalingsvalget: med feil plan fikk et aarsmedlem tilbud
// om aa gjore opp selv, noe aarsmedlemskapet ikke tillater.
sjekk('kortet leser medlemskapet fra avtalen serveren sender',
    str_contains($sida, "const navn = this.state.abo || ((this.state.minAvtale || {}).plan || '');"));
sjekk('… og faller tilbake til forste loepende plan uten avtale',
    str_contains($sida, "|| alle.find(p => !p.engangs)"));

// ── Fullbooket kurs sier «Les mer», ikke «Book plass» ─────────────────
//
// Sip & Clay sto med merket FULLBOOKET oeverst paa bildet og knappen BOOK
// PLASS nederst paa det samme kortet. To motsatte beskjeder, og det er
// knappen folk trykker paa.
//
// Eieren, 1. september, med bilde av nettopp det kortet: «naar noe er
// fullbooket, vil jeg ha knappen les mer og ikke book paa forsiden av
// kortet».
//
// Malt i nettleseren for og etter, paa den lokale basen: for sto tre kort
// med FULLBOOKET og «Book plass» (Sip & Clay, Lag din egen bolle, Liten);
// etter sier alle tre «Les mer», mens de aatte som har ledige plasser staar
// urort med «Book plass». Knappen forer samme sted som for — «Les mer» paa
// Sip & Clay endte paa /kurs/sip-and-clay, der datoene og ventelista staar.
//
// Regelen staar i medBooking, som bade kortene i kurslista og de store
// kortene under «Kursene vaare» henter «cta» fra. Ett sted, saa de to ikke
// kan bli uenige.
sjekk('fullbooket kurs faar «Les mer» paa knappen',
    str_contains($sida, "cta: (k.kunKontakt || k.status === 'Fullbooket') ? 'Les mer' : (k.cta || 'Book plass'),"));
// Kontrollen: den gamle linja, som ga «Book plass» uansett, skal vaere borte.
// Uten denne ville proven over vaere gronn ogsaa om noen la den tilbake.
sjekk('… og den gamle regelen uten unntak er borte',
    !str_contains($sida, "cta: k.kunKontakt ? 'Kontakt oss' : (k.cta || 'Book plass'),"));
// Et kurs uten datoer sier ogsaa «Les mer» — det staar i regelen over, og
// «Kontakt oss» skal ikke lenger staa paa noe kurskort.
sjekk('… og «Kontakt oss» staar ikke lenger paa et kurskort',
    !str_contains($sida, "cta: k.kunKontakt\n          ? 'Kontakt oss'"));
// Ordet kommer fra ledigTekst, som er det ene stedet plasstallet blir tekst.
// Skrives det om der, maa det skrives om her ogsaa — derfor staar de to i
// samme prove.
sjekk('… og «Fullbooket» er fortsatt ordet ledigTekst gir',
    str_contains($sida, "if (isNaN(l) || l <= 0) return 'Fullbooket';"));

// ── Teksten paa Date Night ────────────────────────────────────────────
//
// Kortet sto med en linje jeg skrev da kurset ble lagt inn. Eieren,
// 1. september, med sin egen tekst: «Legg til tekst paa datenight kortet.»
//
// Teksten ligger i «05 · Beskrivelse» i kursoppsettet, og vises oeverst paa
// kurssida og paa det store kortet under «Kursene vaare». Malt i nettleseren
// etter migrasjonen: begge stedene staar med den nye teksten.
$mig122 = file_get_contents(dirname(__DIR__) . '/db/migrations/122_teksten_pa_date_night.sql');
sjekk('migrasjonen skriver den nye teksten paa Date Night',
    str_contains($mig122, 'En romantisk og kreativ kveld for to.')
    && str_contains($mig122, 'minnene varer lenge.'));
// Har verkstedet skrevet noe eget i mellomtida, skal deres ord staa. Uten
// denne betingelsen ville migrasjonen overkjore dem.
sjekk('… bare der den gamle linja staar',
    str_contains($mig122, "AND beskrivelse = 'En kveld for dere to."));
if (DB::harTabell('courses')) {
    $dnT = DB::en("SELECT beskrivelse FROM courses WHERE tittel = 'Date Night'");
    if ($dnT !== null) {
        sjekk('Date Night har eierens egen tekst i basen',
            str_starts_with((string) $dnT['beskrivelse'], 'Date Night i keramikkverkstedet'),
            mb_substr((string) $dnT['beskrivelse'], 0, 40));
    }
}

// ── Kalenderen begynner der dagen begynner ────────────────────────────
//
// Bade dag- og ukevisningen sto med Math.min(600, ...): visningen kunne bare
// begynne TIDLIGERE enn 10:00, aldri senere. En dag med ett kveldskurs
// 18:00–20:00 viste likevel 10:00–20:00 — ti tomme timer over det ene
// kortet, og kortet sa langt nede at det havnet under skjermkanten.
//
// Eieren, 1. september: «kallender i admin, kan den starte visning en time
// for forste kurs?»
//
// Malt i nettleseren for og etter: dagvisningen gikk fra tidsakse 10:00–21:00
// med okta pa 20:00 (usynlig uten a rulle) til 19:00–21:00 med hele okta
// framme, og timehoyden doblet seg fra 54 til 110 px fordi det ble farre
// timer a dele plassen pa.
//
// Slutten er urort: fortsatt minst 20:00, og lenger om noe varer lenger.
$dagRegel  = 'bStart = Math.max(0, Math.floor(forsteMin / 60) * 60 - 60);';
$ukeRegel  = 'wStart = Math.max(0, Math.floor(f1 / 60) * 60 - 60);';
sjekk('dagvisningen begynner en time for forste okt', str_contains($sida, $dagRegel));
sjekk('ukevisningen begynner en time for forste okt i uka', str_contains($sida, $ukeRegel));
// Kontrollen: den gamle regelen skal vaere borte begge steder. Uten denne
// ville sjekkene over vaere gronne selv om noen la den tilbake ved siden av.
sjekk('… og det gamle taket pa 10:00 er borte',
    !str_contains($sida, 'Math.min(600, Math.floor(forsteMin / 60) * 60)')
    && !str_contains($sida, 'Math.min(600, Math.floor(f1 / 60) * 60)'));
// ── Og en time etter den siste ────────────────────────────────────────
//
// Slutten hadde det motsatte problemet av starten: Math.max(1200, ...) lot
// visningen bare slutte SENERE enn 20:00, aldri for. Onsdag 2. september har
// to okter som er ferdige 13:24 — og viste likevel helt til 20:00.
//
// Eieren, 1. september: «paa kallender i admin, saa vil jeg ogsaa ha en time
// visning etter kurs er over».
//
// Malt i nettleseren for og etter, paa nettopp den dagen: tidsaksen gikk fra
// ti timer (10:00–19:00) til fem (10:00–14:00).
sjekk('dagvisningen slutter en time etter siste okt',
    str_contains($sida, 'bSluttMin = Math.min(24 * 60, Math.ceil(sisteMin / 60) * 60 + 60);'));
sjekk('ukevisningen slutter en time etter siste okt i uka',
    str_contains($sida, 'wSlutt = Math.min(24 * 60, Math.ceil(s2 / 60) * 60 + 60);'));
// Kontrollen: det gamle gulvet paa 20:00 skal vaere borte begge steder.
sjekk('… og det gamle gulvet pa 20:00 er borte',
    !str_contains($sida, 'Math.max(1200, Math.min(24 * 60, Math.ceil('));
// Midnatt er fortsatt taket. En okt som slutter 23:30 skal ikke skyve
// visningen til 25:00.
sjekk('… mens midnatt fortsatt er taket',
    substr_count($sida, 'Math.min(24 * 60, Math.ceil(') === 2);
// En tom dag har ingen forste okt, og skal falle tilbake til 10:00–20:00.
sjekk('… og en dag uten okter star som for',
    str_contains($sida, 'let bStart = 600, bSluttMin = 1200;')
    && str_contains($sida, 'let wStart = 600, wSlutt = 1200;'));

// ── To tall som ble blandet sammen ─────────────────────────────────────
//
// Brennetida og oppbevaringstida er to forskjellige ting, og de sa hver sin
// ting flere steder:
//
//   brennetid     hvor lenge det tar for keramikken er ferdig — 2–4 uker
//   oppbevaring   hvor lenge vi tar vare paa den etterpaa — sto som TRE uker
//                 i SMS-en og TO paa nettsiden
//
// En kunde som leste SMS-en trodde hen hadde en uke ekstra. Eieren,
// 1. september: «to uker».
$fbFil = file_get_contents(dirname(__DIR__) . '/api/admin/ferdigbrent.php');
sjekk('arbeidene oppbevares i to uker', str_contains($fbFil, 'const UKER_OPPBEVARING = 2;'));
sjekk('… og skjermen sier det samme foer serveren svarer',
    str_contains($sida, 'const uker = (d && d.uker) || 2;'));
sjekk('… og malen kunden faar sier det ogsaa',
    str_contains(file_get_contents(dirname(__DIR__) . '/db/migrations/118_oppbevaring_i_to_uker.sql'),
                 "'Vi oppbevarer den hos oss i to uker.'"));
sjekk('… og spoersmaal og svar staar som for',
    str_contains($sida, 'Vi oppbevarer ferdige arbeider i to uker etter at du har fått beskjed'));

// Butikken lover ingen frist i det hele tatt. Eieren, 1. september:
// «butikken skal ikke si vi holder av varen, fjern det fra systemet».
// Adressen staar; loftet gjor det ikke.
// Merknaden i koden siterer setningen, saa proven maa se paa selve punktet
// og ikke paa fila som helhet.
sjekk('butikken lover ikke aa holde av varen',
    !str_contains($sida, 'Nordre Løkkevei 15, 3120 Nøtterøy. Vi holder av varen')
    && str_contains($sida, "tekst: 'Nordre Løkkevei 15, 3120 Nøtterøy.' }"));

// Brennetida er noe annet, og skal fortsatt vaere to til fire uker.
sjekk('brennetida er to til fire uker, og staar ett sted',
    str_contains(file_get_contents(dirname(__DIR__) . '/app/lib/kursmal.php'),
                 "const HENTING = 'Den er normalt klar til henting etter 2–4 uker."));
// Ingen av de to tallene skal si «tre uker» noe sted i det kunden ser.
sjekk('ingen tekst lover tre uker lenger',
    !preg_match('~oppbevar\w*[^.]{0,40}tre uker~iu', $sida)
    && !preg_match('~oppbevar\w*[^.]{0,40}tre uker~iu', $fbFil));

if (DB::harTabell('notification_templates')) {
    $fb = (string) DB::verdi("SELECT tekst FROM notification_templates WHERE navn = 'ferdig_brent'");
    sjekk('malen i basen sier to uker',
        $fb === '' || (str_contains($fb, 'to uker') && !str_contains($fb, 'tre uker')),
        mb_substr($fb, 0, 120));
}

// ── Regnskapsfoereren har sin egen innlogging ──────────────────────────
//
// Eieren, 1. september: «jeg oensker aa lage en bruker log in til min
// regnskapsoerer», og paa spoersmaalet om hva hun skal se: «OEkonomi og
// betalinger», med brukernavn og passord.
//
// Systemet hadde to roller. En admin ser alt — deltakerlister, medlemmer,
// e-postadressene til alle som har vaert paa kurs. Uten en tredje rolle var
// valget mellom aa gi henne hele verkstedet eller aa sende filene for haand.
sjekk('rollen finnes i basen',
    str_contains(file_get_contents(dirname(__DIR__) . '/db/migrations/117_regnskapsforeren_far_egen_bruker.sql'),
                 "ENUM('medlem', 'admin', 'regnskap')"));
$sesjFil = file_get_contents(dirname(__DIR__) . '/app/lib/session.php');
sjekk('… og krever passord, som admin',
    str_contains($sesjFil, "if (\$m === null || (\$m['rolle'] ?? '') !== 'regnskap') {")
    && str_contains($sesjFil, "return (\$m['innlogging_maate'] ?? '') === 'passord';"));
// En admin er ogsaa «regnskap» — hun ser alt uansett, og da trenger ikke
// hvert endepunkt to sjekker.
sjekk('… og en admin gaar alltid gjennom',
    str_contains($sesjFil, "if (self::erAdmin()) {\n            return true;\n        }"));

$authFil = file_get_contents(dirname(__DIR__) . '/app/lib/auth.php');
sjekk('krev_regnskap() svarer 404, ikke 403',
    str_contains($authFil, 'function krev_regnskap(): array')
    && str_contains($authFil, "logg('Avvist regnskapsforsøk', ['medlem' => \$m['id']]);"));

// Hvilke endepunkter hun naar. Maalt mot den kjorende siden 1. september:
// 200 paa de fire, 404 paa ti andre, og 404 paa refusjon.
foreach (['okonomi', 'dagsoppgjor', 'transaksjoner', 'betalinger'] as $e) {
    sjekk('regnskapet er aapent: ' . $e,
        str_contains(file_get_contents(dirname(__DIR__) . '/api/admin/' . $e . '.php'), 'krev_regnskap();'));
}
// Aa flytte penger er verkstedets avgjorelse. Refusjon og «send kvittering
// paa nytt» ligger bak krev_admin() i det samme endepunktet.
$betFil = file_get_contents(dirname(__DIR__) . '/api/admin/betalinger.php');
sjekk('… men refusjon krever fortsatt admin',
    str_contains($betFil, 'krev_regnskap();')
    && str_contains($betFil, "Foresporsel::krevMetode('POST');")
    && str_contains($betFil, 'krev_admin();')
    // Rekkefolgen er poenget: lesing er aapen, og admin kreves foer POST-en
    // behandles. Sto krev_admin() overst, ville hun ikke sett lista i det
    // hele tatt; sto den ikke der, kunne hun refundert.
    && strpos($betFil, 'krev_regnskap();') < strpos($betFil, "Foresporsel::krevMetode('POST');")
    && strpos($betFil, "Foresporsel::krevMetode('POST');") < strpos($betFil, 'krev_admin();'));

// Og resten av admin er stengt: ingen andre endepunkter slipper rollen inn.
$aapne = [];
foreach (glob(dirname(__DIR__) . '/api/admin/*.php') as $f) {
    if (str_contains(file_get_contents($f), 'krev_regnskap();')) {
        $aapne[] = basename($f, '.php');
    }
}
sort($aapne);
sjekk('… og ingen andre endepunkter er aapnet',
    $aapne === ['betalinger', 'dagsoppgjor', 'okonomi', 'transaksjoner'], implode(', ', $aapne));

// Skjermen: ett menypunkt, og ingen vei til de andre.
sjekk('menyen viser bare OEkonomi for rollen',
    str_contains($sida2, "? Component.ADMIN_MENY.filter(([navn]) => navn === 'Økonomi')"));
sjekk('… og navigasjonen sender henne tilbake dit',
    str_contains($sida2, "if (this.erBareRegnskap() && Component.REGNSKAP_SKJERMER.indexOf(rute) === -1) {"));
sjekk('… og hun lander paa OEkonomi naar hun logger inn',
    str_contains($sida2, "side: d.erAdmin ? 'adminoversikt' : (d.erRegnskap ? 'adminokonomi' : 'minside'),"));

// Rollen kunne ikke velges i det hele tatt: skjemaet hadde én avkryssingsboks
// for admin. Eieren, 1. september: «jeg kan ikke velge hva en ny bruker skal
// ha av rettigheter under brukere» — og: «admin, medlem, regnskap».
sjekk('en ny bruker kan faa hvilken som helst av de tre rollene',
    str_contains($sida2, "['admin', 'Admin'], ['regnskap', 'Regnskap'], ['medlem', 'Medlem'],")
    && str_contains($sida2, "rolle: this.state.brNyRolle || 'admin',")
    && !str_contains($sida2, "rolle: this.state.brNyAdmin === false ? 'medlem' : 'admin',"));
sjekk('… og serveren tar imot alle tre',
    str_contains(file_get_contents(dirname(__DIR__) . '/api/admin/brukere.php'),
                 "in_array(Foresporsel::tekst('rolle'), ['admin', 'regnskap'], true)"));

// ── Ruter som aapner seg utenfor skjermen ──────────────────────────────
//
// Eieren, 1. september: «hele systemet har en tendens til aa aapne pop up
// eller nye vinduer utenfor skjermbildet om jeg er langt nede paa siden».
//
// «Sett opp kurset» legger seg ved kortet du trykket paa. Klemmen sa bare at
// TOPPEN skulle vaere innenfor skjermen — men ruta kan vaere 78 % av
// skjermhoyden hoy. Trykte du langt nede, startet den innenfor og fortsatte
// langt utenfor, og «Lagre endringer» sto under skjermkanten.
//
// Maalt i nettleseren, klikk paa et kort nederst paa skjermen:
//   for:   700 px skjerm → bunnen paa 1046   (346 px utenfor)
//          950 px skjerm → bunnen paa 1491   (541 px utenfor)
//   etter: 700 px skjerm → bunnen paa 688
//          950 px skjerm → bunnen paa 938
sjekk('ruta faar bare den hoyden det er plass til',
    str_contains($sida, "maxHeight: Math.max(180, Math.min(Math.round(vh * 0.78), vh - topp - 12)) + 'px',")
    && !str_contains($sida, "width: 'min(420px, calc(100vw - 24px))', maxHeight: '78vh', overflow: 'auto',"));
// Toppen skal fortsatt legge seg ved kortet, ikke midt paa skjermen.
sjekk('… og legger seg fortsatt der du trykket',
    str_contains($sida, "const topp = Math.max(12, Math.min((pos.y || 90), vh - 200));"));
// Ruta ruller inni seg selv naar innholdet er hoyere enn plassen.
sjekk('… og ruller inni seg selv naar den ikke faar plass',
    str_contains($sida, "overflow: 'auto',\n                      overscrollBehavior: 'contain',"));

// ── Pillene laa oppi kortene over ──────────────────────────────────────
//
// Dagsvisningen ble loftet 65 px for at rutenettet skulle staa paa linje med
// kortet til venstre. Loftet tok HELE spalta, og det foerste i spalta er
// «Viser»-raden med kursholderpillene: de laa 45 px inni kortene over, likt
// paa 1200, 1400 og 1700 px.
// Pillene laa 45 px inni kortene over, fordi loftet tok hele spalta og raden
// var det foerste i den. Eieren valgte selv hva som skulle vike: «Flytt
// pillene, behold linjeringen».
//
// Raden har naa en egen rad i full bredde over begge spaltene. Ligger den
// bare i kalenderspalta, forskyves den ene og ikke den andre, og linjeringen
// ryker — det var maalt til 88 px feil for den ble full bredde.
sjekk('pillene ligger i sin egen rad, i full bredde',
    str_contains($sida, "klPilleradStil: this.erSmal()")
    && str_contains($sida, ": { gridColumn: '1 / -1', gridRow: '2', minWidth: 0 },"));
sjekk('… og kalenderen ligger i rada under',
    str_contains($sida, ": { minWidth: 0, gridColumn: '2', gridRow: '3',")
    && str_contains($sida, "gridColumn: '1', gridRow: '3' },"));
// Maalt paa den kjorende siden: pilleraden paa 430, kortene slutter paa 410,
// null kollisjoner, og det hvite likt paa begge sider.
sjekk('… og raden er ute av loftet',
    !str_contains($sida, "marginTop: visning === 'dag' ? '-65px'"));

// ── Paint on Pots tar ikke spalta ──────────────────────────────────────
//
// Eieren, 1. september: «kortene paint on pots, disse vil jeg skal vises mye
// mindre slik at de ikke tar saa mye plass i bredden. om den kan krympes ned
// til 15% av stoerrelsen».
//
// De legges ut automatisk paa hver aapningstid, saa det er seks-sju av dem
// paa en dag. Delte de bredden likt med et ekte kurs, ble kurset en strime.
sjekk('Paint on Pots staar i en smal stripe',
    str_contains($sida, 'const SMAL_ANDEL = 25;')
    && str_contains($sida, "const erSmal = e => e.type === 'pop';"));
sjekk('… og de andre faar resten av bredden',
    str_contains($sida, 'const bredResten = smaa.length ? 100 - SMAL_ANDEL : 100;'));
// Er det ingen Paint on Pots den dagen, skal spalta se ut som for.
sjekk('… men hele bredden naar det ikke er noen',
    str_contains($sida, 'smaa.length ? 100 - SMAL_ANDEL : 100'));
// Bare bredden endres. Eieren, 1. september: «dette er et kurs, som jeg ikke
// trenger aa se annen enn at det faktisk kommer noen» — plasstallet og
// deltakerne er nettopp det som skal staa, ogsaa paa en smal blokk.
sjekk('den smale blokka viser fortsatt plasstall, ansikter og merknader',
    !str_contains($sida, "detalj: p.smal ? '' :")
    && !str_contains($sida, 'harAvatarer: !p.smal')
    && !str_contains($sida, 'harMerknad: !p.smal')
    && str_contains($sida, "detalj: [belegg(p.e) ? belegg(p.e) + ' plasser' : ''")
    && str_contains($sida, 'harAvatarer: (p.e.deltakere || []).length > 0,'));
sjekk('… og blokkene ligger foran rutenettet',
    substr_count($sida, 'zIndex: 2 + p.lane') === 2);

// ── «Ikke betalt» og statistikken er kort som de andre ─────────────────
//
// Eieren: «jeg vil at de skal ha vaert sitt kort, men ikke slike kort som i
// dag, jeg vil at kortene skal vaere like som de andre kortene paa denne
// siden». De sto som to brede paneler over rutenettet, i en annen drakt.
sjekk('de to panelene staar inne i kortrutenettet',
    strpos($sida2, 'id="ov-kortrutenett"') < strpos($sida2, '{{ ovSkylderVis }}'));
sjekk('… med samme ramme som resten',
    str_contains($sida2, "borderRadius: 'var(--radius-lg)', background: 'var(--surface-card)',")
    && str_contains($sida2, "borderRadius: 'var(--radius-lg)', overflow: 'hidden',"));
// «span 2» var min feil. Paa telefonen har rutenettet én spalte, og «span 2»
// lager da en spalte til: panelene ble 413 piksler paa en skjerm som er 390,
// og hele Oversikt maatte dras sidelengs. Under 760 piksler skal de spenne
// over hele rada, over 760 over to spalter som for.
sjekk('… og sprenger ikke telefonskjermen',
    substr_count($sida2, "gridColumn: this.erSmal() ? '1 / -1' : 'span 2',") === 2
    && !str_contains($sida2, 'grid-column: span 2;'));

// ── Dra-kortene i kalenderen ───────────────────────────────────────────
//
// Eieren, 31. august: «pillene under alle kurs paa kalenderoversikten maa bli
// mye mindre, og det kan ligge to piller i bredden, slik at de som kommer paa
// venteliste bli synlig». Seksten kort á nitti piksler er fjorten hundre
// piksler dra-liste for aa naa ventelista under.
sjekk('dra-kortene ligger to i bredden',
    str_contains($sida, ": { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '6px' },"));
sjekk('… og er mye mindre',
    str_contains($sida, "borderRadius: 'var(--radius-sm)', cursor: 'grab'")
    && str_contains($sida, ": { padding: '6px 8px' }),"));
// Prisen hoerer til kurset og staar i kursoppsettet ett klikk unna. Her skal
// man finne kurset og dra det.
sjekk('… uten prisen paa kortet',
    !str_contains($sida, '<span >{{ k.pris }}</span>')
    && str_contains($sida, '>{{ k.navn }}</div>'));

// ── Kassa viser dagen i dag ────────────────────────────────────────────
//
// Eieren, 2. september: «oversikt - kasse - betalinger, her vises alt i en
// streng, jeg vil ha default dagens transaksjoner». Lista sto med alt, tusen
// rader tilbake, i én lang streng.
echo "\n== Kassa viser dagen i dag ==\n";

sjekk('betalingene staar paa dagen i dag som standard',
    str_contains($sida, "const periode = sokB ? 'Alt' : (st.btPeriode || 'I dag');")
    && str_contains($sida, "return periode === 'Alt' || b.dato === idagIso;"));
sjekk('… med brikker for aa se resten',
    str_contains($sida, "btPerioder: ['I dag', 'Alt'].map(n => {")
    && str_contains($sida, '<sc-for list="{{ btPerioder }}" as="pb"'));
// Soeket maa gaa gjennom ALT. En betaling fra forrige maaned skal kunne
// finnes, ellers er soekefeltet en felle.
sjekk('… mens soeket gaar gjennom alt',
    str_contains($sida, "              if (sokB) {\n                return [b.medlem || '', b.belop || '', b.tidspunkt || '', b.referanse || '',"));
sjekk('… og en tom dag sier hvor resten er',
    str_contains($sida, "'Ingen betalinger i dag ennå. Trykk «Alt» for å se resten.'"));

// ── Forsida viser fire ekte kurs ───────────────────────────────────────
//
// Fire kursnavn sto skrevet inn i fila, og hvert ble slaatt opp paa TITTEL.
// Fant den ikke tittelen, sto designraden igjen — med oppdiktet dato, pris og
// «3 plasser igjen». «Kurs boller» finnes ikke lenger paa lissom.no, og
// forsida viste det likevel.
//
// Eieren, 2. september: «forside kurs og events kommende datoer, jeg vil du
// skal vise 4 kort her».
echo "\n== Forsida viser fire ekte kurs ==\n";

sjekk('forsida tar de fire som gaar naermest',
    str_contains($sida, 'kursForside: this.kursKort().slice(0, 4),'));
// Fire, ikke tre og ikke ni. Rutenettet er repeat(4, 1fr) — et annet tall
// gir enten en tom rute eller en rad til.
sjekk('… og det er fire',
    preg_match('/kursForside: this\.kursKort\(\)\.slice\(0, (\d+)\),/', $sida, $mF) === 1
    && $mF[1] === '4', $mF[1] ?? '?');
// Kontrollen: de fire navnene som sto skrevet inn skal vaere borte herfra.
sjekk('… og navnene som var skrevet inn er borte',
    !str_contains($sida, "kursForside: this.medBooking(["));
// kursKort() er alt sortert etter naar kursene gaar — det er den sorteringa
// som gjor «de fire forste» til «kommende datoer».
sjekk('… og kursKort() er sortert etter naar de gaar',
    str_contains($sida, 'const naar = (k) => this.forsteOktTid(k);')
    && str_contains($sida, '.sort((a, b) => (a.n - b.n) || (a.i - b.i))'));

// ── «Passer for» og betalingsknappen ───────────────────────────────────
//
// Eieren, 2. september: «fjern dette du har lagt ut under passer deg og ikke
// for deg» — linja sto mellom navnet og punktlista i «Alt som er inkludert»,
// og sa det samme som punktene under, bare vagere.
sjekk('«Passer for»-linja er borte fra «Alt som er inkludert»',
    !str_contains($sida, '{{ a.passer }}'));
// Feltet lever videre — det brukes paa medlemskapskortene lenger opp, og
// staar fortsatt i planskjemaet.
sjekk('… men feltet finnes fortsatt',
    str_contains($sida, "plPasserFor: v('passerFor'),"));

// ── Den utfyllende teksten skal vaere til aa lese ──────────────────────
//
// Eieren, 2. september: «teksten som er inne paa medlemskapene, den utvidede,
// kan formateres penere».
//
// Ti avsnitt i samme storrelse paa rad er en vegg. Foerste linje var dessuten
// en overskrift — «Mini 15 – fleksibelt medlemskap for deg som vil skape
// jevnlig» — satt som broedtekst rett under <h1>Mini 15</h1>, saa halve linja
// sto to ganger. Og «Viktig aa vite» var en LISTE som sto som én klump med
// linjeskift inni.
echo "\n== Den utfyllende teksten ==\n";

sjekk('overskriftslinja blir en ingress',
    str_contains($sida, 'bOmHarIngress: !!ingress,')
    && str_contains($sida, '<sc-if value="{{ bOmHarIngress }}"'));
// Navnet staar alt i <h1>. Ingressen skal vaere halen, ikke hele linja.
sjekk('… og navnet gjentas ikke i den',
    str_contains($sida, "const halen = f.slice(tittel.length).replace(/^\\s*[–—-]\\s*/, '').trim();"));
// Bare naar den faktisk ER en overskrift. Et kurs der forste avsnitt er en
// vanlig setning skal ikke faa den lofta ut av teksten.
sjekk('… bare naar forste linje ser ut som en overskrift',
    str_contains($sida, "if (f.indexOf(tittel) === 0 && f.length < 130 && !/[.!?]\$/.test(f)) {"));
sjekk('avsnittene faar ulik vekt',
    str_contains($sida, "fontSize: i === 0 ? 'var(--text-lg)' : 'var(--text-base)',")
    && str_contains($sida, '<p style="{{ a.stil }}">{{ a.tekst }}</p>'));
sjekk('«Viktig aa vite» tegnes som punkter',
    str_contains($sida, '<sc-if value="{{ sk.erListe }}"')
    && str_contains($sida, '<sc-for list="{{ sk.punkter }}" as="pp"'));
// Kontrollen: de andre seksjonene er fortsatt tekst, ikke lister.
sjekk('… mens de andre seksjonene fortsatt er tekst',
    str_contains($sida, '<sc-if value="{{ sk.erTekst }}"')
    && str_contains($sida, "erTekst: true, erListe: false, punkter: [] }))"));
// Og at klumpen med linjeskift er borte.
sjekk('… og klumpen med linjeskift er borte',
    !str_contains($sida, "['Viktig å vite', (k.viktig || []).join('\\n')],"));

// ── Vilkaarene maa godtas ──────────────────────────────────────────────
//
// Eieren, 2. september: «er det mulig aa legge til godta vilkaar for man faar
// kjopt et medlemskap?»
//
// Innmeldingsskjemaet hadde ingen hake. Under knappen sto én graa linje om
// bindingstid — uten bekreftelse, uten at noe ble skrevet ned, og feil for
// halvparten av medlemskapene: den sa «2 maaneder» ogsaa om aarsavtalen med
// tolv, og om proveperioden som ikke har binding i det hele tatt.
echo "\n== Vilkaarene maa godtas ==\n";

$mig133 = file_get_contents(dirname(__DIR__) . '/db/migrations/133_medlemsvilkar_godtas.sql');
sjekk('migrasjon 133 lagrer samtykket',
    str_contains($mig133, 'ADD COLUMN vilkaar_godtatt_at')
    && str_contains($mig133, 'vilkaar_versjon'));
sjekk('… og kolonnene staar i basen',
    DB::harKolonne('membership_applications', 'vilkaar_godtatt_at')
    && DB::harKolonne('membership_applications', 'vilkaar_versjon'));
sjekk('utgaven av vilkaarene staar ett sted',
    preg_match('/^\d{4}-\d{2}-\d{2}$/', Medlemskap::VILKAAR_VERSJON) === 1,
    Medlemskap::VILKAAR_VERSJON);

// Kravet maa staa paa SERVEREN. Haken i nettleseren er en hoeflighet mot den
// som fyller ut; det er kallet som avgjor om noen blir medlem, og en graa
// knapp stopper ikke den som sender kallet utenom nettleseren.
$bliVilkaar = file_get_contents(dirname(__DIR__) . '/api/bli-medlem.php');
sjekk('serveren krever samtykket',
    str_contains($bliVilkaar, "\$vilkaar = Foresporsel::tekst('vilkaar') === 'ja';")
    && str_contains($bliVilkaar, "Svar::feil('Du må godta medlemsvilkårene for å melde deg inn.');"));
// Kravet maa staa FOER avtalen opprettes. Sto det etter, ville en innmelding
// uten samtykke alt ha laget en avtale i Vipps for den ble avvist.
sjekk('… og kravet staar foer avtalen opprettes i Vipps',
    strpos($bliVilkaar, "\$vilkaar = Foresporsel::tekst('vilkaar')")
    < strpos($bliVilkaar, 'Medlemskap::startAvtale($medlem, $type)'));
sjekk('… og samtykket lagres med dato og utgave',
    str_contains($bliVilkaar, "'vilkaar_godtatt_at' => gmdate('Y-m-d H:i:s'),")
    && str_contains($bliVilkaar, "'vilkaar_versjon'    => Medlemskap::VILKAAR_VERSJON,"));

// Skjemaet: haken, lenka til vilkaarene, og knappen som er laast uten den.
sjekk('skjemaet har haken',
    str_contains($sida, 'label="Jeg godtar medlemsvilkårene" checked="{{ bmVilkaarOk }}" on-change="{{ toggleBmVilkaar }}"'));
sjekk('… med lenke til vilkaarene',
    str_contains($sida, 'onClick="{{ goVilkar }}"'));
sjekk('… og «Bli medlem» er laast til den staar',
    str_contains($sida, 'disabled="{{ bmVilkaarMangler }}" on-click="{{ bmSend }}"'));
sjekk('… og kallet sender samtykket',
    str_contains($sida, "vilkaar: this.state.bmVilkaarOk ? 'ja' : 'nei',"));

// Bindingslinja skal si det som gjelder DET medlemskapet man velger. Sto den
// fast paa «2 maaneder», loy den til to av fire.
sjekk('bindingslinja leses av planen',
    str_contains($sida, "const b = parseInt(pl.binding, 10) || 0;")
    && str_contains($sida, "const o = parseInt(pl.oppsigelse, 10);"));
sjekk('… og den gamle faste linja er borte',
    !str_contains($sida, "'Medlemskapet har 2 måneders bindingstid fra du melder deg inn, '"));
$medApiV = file_get_contents(dirname(__DIR__) . '/api/medlemskap.php');
sjekk('… og serveren sender oppsigelsestida med',
    str_contains($medApiV, "'oppsigelse' => (int) (\$p['oppsigelse_mnd'] ?? 1),"));

// Samtykket skal kunne vises fram i ettertid. En hake som bare laaser opp en
// knapp er ikke noe bevis.
$medlApiV = file_get_contents(dirname(__DIR__) . '/api/admin/medlemmer.php');
sjekk('medlemsruta viser naar vilkaarene ble godtatt',
    str_contains($medlApiV, "'Godtok medlemsvilkårene '"));
sjekk('… og skjermen tegner den, begge steder',
    substr_count($sida, '{{ personVilkaar }}') === 2);
// Og at verdien bak faktisk settes. Foerste forsoek hadde markupen paa plass
// og props-en ikke — den proven var gronn, mens ruta sto tom. listesjekk
// fanget det; denne linja gjor at proven gjor det ogsaa.
sjekk('… og verdien bak den settes',
    str_contains($sida, "personVilkaar: p.vilkaar || '',")
    && str_contains($sida, 'personHarVilkaar: !!p.vilkaar,'));

// Vilkaarsteksten maa faktisk staa paa sida haken lenker til. Uten den peker
// «Les vilkaarene» paa en side der vilkaarene ikke finnes.
//
// Proven ser paa SETNINGENE, ikke paa overskriftene. Overskriftene ble dopt
// om 3. september da sida ble delt i kursvilkaar og medlemsvilkaar, og en
// prove som bare leste overskrifter gikk roedt paa en ren omdoping — mens den
// ville staatt gronn om selve teksten forsvant. Det er motsatt av det den er
// til for.
foreach ([
    'bindingstid'  => '12 måneders bindingstid fra oppstartsdato',
    'oppsigelse'   => 'Oppsigelsestiden regnes fra første dag i påfølgende måned',
    'HMS'          => 'Medlemmet plikter å sette seg inn i og følge gjeldende HMS-rutiner',
    'mislighold'   => 'avslutte et medlemskap med umiddelbar virkning',
    'endringer'    => 'oppdatere medlemsvilkårene ved behov',
] as $navn => $setning) {
    sjekk('medlemsvilkaarene: ' . $navn . ' staar paa vilkaarssida',
        str_contains($sida, $setning));
}

// ── Kursvilkaarene ─────────────────────────────────────────────────────
//
// Eieren, 3. september, med teksten sin: «til alle våre kurs, så er det dette
// som er vilkårene». Og: «ikke bland den andre teksten som er for medlemmer,
// dette er for kursdeltakere».
echo "\n== Kursvilkaarene ==\n";
foreach ([
    'bindende paamelding' => 'Påmelding er bindende',
    'betaling for start'  => 'skal være betalt før kursstart',
    'avbestilling'        => 'mer enn 2 dager før kursstart refunderes kursavgiften fullt ut',
    'ingen refusjon naer' => 'mindre enn 2 dager før kursstart refunderes ikke kursavgiften',
    'overfoere plassen'   => 'Plassen kan overføres til en annen person etter avtale',
    'fravaer'             => 'Manglende oppmøte gir ikke rett til refusjon',
    'HMS'                 => 'Deltakerne benytter verksted og utstyr på eget ansvar',
    'egne arbeider'       => 'kan ikke garantere mot skader, sprekker',
    'ordensregler'        => 'Verkstedet skal forlates ryddig etter bruk',
    'forbehold'           => 'avlyse kurs ved for få deltakere eller sykdom',
] as $navn => $setning) {
    sjekk('kursvilkaarene: ' . $navn, str_contains($sida, $setning));
}

// Den gamle avbestillingsregelen skal vaere borte FRA VILKAARENE. Sto begge
// to der, ville sida lovet to forskjellige ting om de samme pengene.
//
// Den staar fortsatt tre andre steder — linja under bookingknappen,
// avbestillingsruta, og selve utregningen i api/avbestill.php, som er den som
// ber Vipps om pengene. De henger sammen med en avgjorelse eieren ikke har
// tatt enda: skal systemet ogsaa BETALE etter den nye regelen? Til den er
// tatt, er det riktigere at koden gjor det den alltid har gjort enn at den
// gjor noe halvveis.
sjekk('den gamle 14-dagersregelen staar ikke lenger i vilkaarene',
    !str_contains($sida, 'mer enn 14 dager før kursstart, 50 % inntil 7 dager'));

// Og saa lenge de to spriker, skal det staa svart paa hvitt her at de gjor
// det — saa ingen tror jobben er ferdig.
sjekk('utregningen folger fortsatt den gamle regelen (uavklart)',
    str_contains(file_get_contents(dirname(__DIR__) . '/api/avbestill.php'), '14 * 24'));

// Hver hake maa lande paa SIN bolk. Pekte begge samme sted, ville
// kursdeltakeren fortsatt maattet lese seg gjennom medlemsvilkaarene.
sjekk('sida har begge bolkene', str_contains($sida, 'id="vilkar-kurs"')
    && str_contains($sida, 'id="vilkar-medlem"'));
sjekk('kursbookingen sender deg til kursvilkaarene',
    str_contains($sida, "goVilkar: this.goVilkarDel('vilkar-kurs')"));
sjekk('innmeldingen sender deg til medlemsvilkaarene',
    str_contains($sida, "goVilkarMedlem: this.goVilkarDel('vilkar-medlem')")
    && str_contains($sida, 'onClick="{{ goVilkarMedlem }}"'));
// Den gamle teksten lovte kunden noe systemet ikke gjor: at oppsigelsen
// gjelder fra neste trekk og at man ikke trekkes igjen.
// Medlemskap::sluttdato() regner fra forste dag i paafolgende maaned og legger
// til oppsigelsestida — og trekket gaar som for i den maaneden.
sjekk('… og den gamle setningen om «ikke trukket igjen» er borte',
    !str_contains($sida, 'du beholder tilgangen ut den perioden du har betalt for, og blir ikke trukket igjen'));

// Koden og vilkaarene maa gi det samme svaret. Vilkaarene: «Oppsigelsestiden
// regnes fra forste dag i paafolgende maaned etter at oppsigelsen er mottatt»,
// pluss én maaned. Sier man opp i dag, skal medlemskapet loepe ut den maaneden.
$sluttForventet = (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))
    ->modify('first day of this month')->modify('+2 months')->modify('-1 day')->format('Y-m-d');
sjekk('koden gir den oppsigelsestida vilkaarene lover',
    Medlemskap::sluttdato(['plan' => 'Basis 30']) === $sluttForventet,
    Medlemskap::sluttdato(['plan' => 'Basis 30']));

// ── Naar gaar pengene? ─────────────────────────────────────────────────
//
// Medlemmet Eirin, 2. september: «Jeg betalte med vipps i gaar via siden her.
// Saa ut til aa fungere greit. Men pengene er fremdeles paa min konto.»
//
// Hun hadde rett, og hun skrev fordi ingen hadde sagt fra. Fast trekk i Vipps
// er en fullmakt, ikke en betaling: forfallet ligger tre dager fram fordi
// Vipps krever forvarsel. Sida sa bare «saa er du i gang», og velkomsten sa
// «du faar beskjed for hvert trekk» — sant, men ikke NAAR.
echo "\n== Naar gaar pengene ==\n";

$bliTekst = file_get_contents(dirname(__DIR__) . '/api/bli-medlem.php');
sjekk('sida sier naar forste trekk kommer',
    str_contains($bliTekst, 'Første trekk kommer om noen dager — du får en e-post fra oss først.'));
// Bare paa fast trekk. Den som gjor opp selv betaler NAA, i Vipps, og skal
// ikke faa beskjed om at pengene gaar om noen dager.
sjekk('… og bare paa fast trekk',
    str_contains($bliTekst, "        : 'Betal i Vipps, så er du i gang.',"));

$mig132 = file_get_contents(dirname(__DIR__) . '/db/migrations/132_velkomsten_sier_naar_pengene_gaar.sql');
sjekk('migrasjon 132 retter velkomstmalen',
    str_contains($mig132, "WHERE navn = 'innmelding_fast_trekk'"));

$velkomst = (string) DB::verdi(
    "SELECT tekst FROM notification_templates WHERE navn = 'innmelding_fast_trekk'"
);
sjekk('velkomsten sier at forste trekk kommer om noen dager',
    str_contains($velkomst, 'Første trekk kommer om noen dager'), mb_substr($velkomst, 0, 40));
sjekk('… og hvorfor det tar tid',
    str_contains($velkomst, 'Vipps krever at vi varsler deg først'));
// Den gamle setningen lovte noe systemet ikke gjor: medlemmet settes aktivt
// naar AVTALEN blir aktiv i Vipps, for en eneste krone har flyttet seg.
sjekk('… og lover ikke lenger at medlemskapet venter paa betalingen',
    !str_contains($velkomst, 'aktivt så snart betalingen er registrert'));
sjekk('… men sier at det er aktivt med det samme',
    str_contains($velkomst, 'Medlemskapet er aktivt med det samme'));
// Plassholderne maa staa. Uten dem staar det «Hei {navn}» i e-posten.
foreach (['{navn}', '{type}'] as $felt) {
    sjekk('… og «' . $felt . '» staar igjen i malen', str_contains($velkomst, $felt));
}

// ── Rekkefolgen i medlemstrekket ───────────────────────────────────────
//
// Medlemmet Eirin godkjente avtalen i Vipps-appen 1. september og kom aldri
// tilbake til nettsiden. Da sto raden vaar paa «venter» til cron sporte Vipps
// — men det spørsmålet sto ETTER trekkrunden. Hun ble aktivert klokka 04 den
// 2., etter at trekkrunden hadde kjort, og trekket kom forst natta etter.
//
// Ingen penger gikk tapt. Men hvert medlem som godkjenner i appen tapte et
// dogn, hver gang. Aktiveringen maa staa foerst.
echo "\n== Rekkefolgen i medlemstrekket ==\n";

$cronKode = file_get_contents(dirname(__DIR__) . '/bin/cron.php');
$posMedlemstrekk = strpos($cronKode, "case 'medlemstrekk':");
$posAktiver = strpos($cronKode, "WHERE status = 'venter'", $posMedlemstrekk);
$posTrekk   = strpos($cronKode, 'Medlemskap::tilTrekk()', $posMedlemstrekk);
sjekk('begge blokkene finnes i medlemstrekket',
    $posMedlemstrekk !== false && $posAktiver !== false && $posTrekk !== false);
sjekk('avtalene aktiveres FOER trekkrunden',
    $posAktiver !== false && $posTrekk !== false && $posAktiver < $posTrekk,
    'aktiver@' . (int) $posAktiver . ' trekk@' . (int) $posTrekk);

// ── Det samme forsoeket to ganger ──────────────────────────────────────
//
// Eieren, 2. september: «nytt medlem har meldt seg inn, faar denne e-posten
// 2 ganger» — bade «Nytt medlem: Anniken Johnsgaard» og «Varsel maa sendes
// for haand: Nytt medlem». Begge kom 20:42, samme minutt.
//
// api/bli-medlem.php hadde ingen vakt. Vakta i startAvtale() slaar bare til
// paa en avtale som ER aktiv; en som staar «venter» — den forste klikket
// nettopp opprettet — stoppet ingenting. Andre kall gikk derfor gjennom og
// lagde en avtale til i Vipps, en soknadsrad til, og alle varslene om igjen.
//
// To e-poster er irriterende. To avtaler er to trekk.
echo "\n== Det samme forsoeket to ganger ==\n";

$mig131 = file_get_contents(dirname(__DIR__) . '/db/migrations/131_samme_forsok_samme_avtale.sql');
sjekk('migrasjon 131 husker adressen forsoeket godkjennes paa',
    str_contains($mig131, 'ADD COLUMN vipps_url'));
sjekk('… og kolonna staar i basen', DB::harKolonne('subscriptions', 'vipps_url'));

$dMedlem = (int) DB::settInn('members', [
    'navn' => 'Dobbel Testesen', 'epost' => 'dobbel@example.test',
    'rolle' => 'medlem', 'status' => 'ingen',
]);
$dAvtale = (int) DB::settInn('subscriptions', [
    'member_id' => $dMedlem, 'plan' => 'Basis 30', 'pris_ore' => 259000,
    'vipps_agreement_id' => 'agr-dobbel-' . $dMedlem,
    'vipps_url' => 'https://vipps.example/godkjenn/1', 'status' => 'venter',
]);
$funnet = Medlemskap::paagaaendeForsok($dMedlem, 'Basis 30');
sjekk('det samme forsoeket gjenbrukes',
    $funnet !== null && (string) $funnet['vipps_url'] === 'https://vipps.example/godkjenn/1');
// Bytter man medlemskap i mellomtida, er det et annet forsoek.
sjekk('… men ikke paa en annen plan',
    Medlemskap::paagaaendeForsok($dMedlem, 'Mini 15') === null);
// Vinduet er kort med vilje: en som virkelig vil proeve paa nytt skal ikke
// sitte fast med en adresse som er utloept hos Vipps.
DB::kjor('UPDATE subscriptions SET created_at = UTC_TIMESTAMP() - INTERVAL 6 MINUTE WHERE id = :i',
    ['i' => $dAvtale]);
sjekk('… og ikke etter fem minutter',
    Medlemskap::paagaaendeForsok($dMedlem, 'Basis 30') === null);
// Uten lagret adresse er det ingenting aa sende noen til.
DB::kjor('UPDATE subscriptions SET created_at = UTC_TIMESTAMP(), vipps_url = NULL WHERE id = :i',
    ['i' => $dAvtale]);
sjekk('… og ikke uten en adresse aa gjenbruke',
    Medlemskap::paagaaendeForsok($dMedlem, 'Basis 30') === null);
// En avtale som alt er aktiv er et helt annet tilfelle — den skal avvises,
// ikke gjenbrukes.
DB::kjor("UPDATE subscriptions SET status = 'aktiv', vipps_url = 'https://vipps.example/godkjenn/1',
          created_at = UTC_TIMESTAMP() WHERE id = :i", ['i' => $dAvtale]);
sjekk('… og en aktiv avtale gjenbrukes ikke',
    Medlemskap::paagaaendeForsok($dMedlem, 'Basis 30') === null);
DB::kjor('DELETE FROM subscriptions WHERE member_id = :m', ['m' => $dMedlem]);
DB::kjor('DELETE FROM members WHERE id = :i', ['i' => $dMedlem]);

// Begge veiene inn maa ha vakta. Bare den ene, og halvparten av innmeldingene
// kunne fortsatt bli dobbelt.
$mlKode = file_get_contents(dirname(__DIR__) . '/app/lib/medlemskap.php');
sjekk('bade fast trekk og engangs sjekker om forsoeket paagaar',
    substr_count($mlKode, "\$igjen = self::paagaaendeForsok((int) \$medlem['id'], \$planNavn);") === 2);
sjekk('… og begge lagrer adressen',
    substr_count($mlKode, 'self::husk((int) $id,') === 2);

// Endepunktet maa la vaere aa sende varslene om igjen. Uten dette ville
// avtalen blitt gjenbrukt, men e-postene kommet dobbelt likevel.
$bliKode = file_get_contents(dirname(__DIR__) . '/api/bli-medlem.php');
sjekk('innmeldingen sender ikke varslene om igjen',
    str_contains($bliKode, "if (!empty(\$avtale['gjentakelse'])) {"));
// Kontrollen: vakta maa staa FOER varslene, ellers rekker de aa gaa ut.
sjekk('… og vakta staar foer dem',
    strpos($bliKode, "if (!empty(\$avtale['gjentakelse'])) {")
    < strpos($bliKode, "Varsel::malTilAdmin('intern_nytt_medlem'"));

// ── Samme nummer to ganger ─────────────────────────────────────────────
//
// «Varsel maa sendes for haand» sendes én gang per adminnummer. Sto det
// samme nummeret der to ganger — «40603093» og «+47 406 03 093» er den samme
// telefonen — gikk varselet to ganger. Varsel::adminEposter() har hatt den
// samme regelen for e-postadressene lenge; nummerne manglet den.
$cfg = file_get_contents(dirname(__DIR__) . '/app/config.php');
sjekk('adminnumrene telles én gang hver',
    str_contains($cfg, 'array_values(array_unique(array_filter(array_map('));
sjekk('… og skrivemaaten avgjor ikke',
    normaliser_telefon('40603093') === normaliser_telefon('+47 406 03 093'));

// ── «Script error.» ────────────────────────────────────────────────────
//
// Eieren, 2. september, fra Min side paa iPhone: «Fanget automatisk — Script
// error.» og ikke et ord mer. Nettleseren nekter aa si hva som skjedde naar
// unntaket kom fra et skript den regner som et annet nettsted, og da leter
// man etter en feil i egen kode som ikke finnes.
sjekk('rapporten sier hva «Script error.» betyr',
    str_contains($sida, "const skjult = melding === 'Script error.' || melding === 'Script error';")
    && str_contains($sida, 'nettleseren skjuler detaljene'));
// Og om analysen kjorte. Det er forskjellen paa «Google» og «en utvidelse i
// nettleseren» — uten den staar man like langt neste gang.
sjekk('… og om analysen kjorte da det skjedde',
    str_contains($sida, "this._gaSatt ? 'Analysen (Google) kjørte da det skjedde'"));

// ── Har medlemmet betalt? ──────────────────────────────────────────────
//
// Eieren, 2. september: «jeg kan ikke se paa min side paa et medlem om det er
// betalt for medlemskapet eller ikke ... Paa Eirin staar det fast trekk og paa
// Anniken staar det gjor opp selv».
//
// Det er betalingsMAATEN. Skjermen leste vipps_agreement_id og skrev «Fast
// trekk» eller «Gjor opp selv» — ingen av delene sier at penger er kommet.
// Tallene laa i basen hele tida; ingen slo dem opp.
//
// Regelen staar ETT sted, Medlemskap::betalingsstatus(), fordi tre skjermer
// spor om den: medlemslista, kortet paa Oversikt og medlemsruta. Sto den tre
// steder, kunne de svart hver sitt om den samme personen.
echo "\n== Har medlemmet betalt ==\n";

$mig130 = file_get_contents(dirname(__DIR__) . '/db/migrations/130_medlem_som_ikke_betaler.sql');
sjekk('migrasjon 130 gir medlemmet «betaler ikke»',
    str_contains($mig130, 'ADD COLUMN betaler_ikke')
    && str_contains($mig130, 'betaler_ikke_grunn'));
sjekk('… og kolonnene staar i basen',
    DB::harKolonne('members', 'betaler_ikke')
    && DB::harKolonne('members', 'betaler_ikke_grunn'));

// ── Regelen, tilstand for tilstand ─────────────────────────────────────
//
// Fem medlemmer, fem svar. Uten disse ville en endring i regelen kunne
// snudd et «betalt» til «forfalt» uten at noe sa fra.
$bMedlem = static function (string $navn, string $plan, int $fri = 0, string $grunn = ''): array {
    return ['id' => 0, 'navn' => $navn, 'medlemskap_type' => $plan, 'status' => 'aktiv',
            'betaler_ikke' => $fri, 'betaler_ikke_grunn' => $grunn !== '' ? $grunn : null];
};
$bBetaling = static fn(string $dato): array => ['created_at' => $dato . ' 12:00:00', 'belop_ore' => 259000];
$iDag  = gmdate('Y-m-d');
$forLenge = gmdate('Y-m-d', strtotime('-3 months'));

$b = Medlemskap::betalingsstatus($bMedlem('Fri', 'Basis 30', 1, 'bytter mot dugnad'), null, null);
sjekk('haken gaar foran alt — et gratismedlem lyser aldri roedt',
    $b['tilstand'] === 'fri' && $b['forfalt'] === false
    && str_contains($b['tekst'], 'bytter mot dugnad'), $b['tilstand'] . ' · ' . $b['tekst']);

$b = Medlemskap::betalingsstatus($bMedlem('Ny', 'Basis 30'), null, null);
sjekk('aldri betalt staar som forfalt',
    $b['tilstand'] === 'venter' && $b['forfalt'] === true, $b['tekst']);

$b = Medlemskap::betalingsstatus($bMedlem('Fersk', 'Basis 30'), null, $bBetaling($iDag));
sjekk('betalt i dag er betalt', $b['tilstand'] === 'betalt' && $b['forfalt'] === false, $b['tekst']);

$b = Medlemskap::betalingsstatus($bMedlem('Gammel', 'Basis 30'), null, $bBetaling($forLenge));
sjekk('betalt for tre maaneder siden er forfalt',
    $b['tilstand'] === 'forfalt' && $b['forfalt'] === true, $b['tekst']);

// Proveperioden betales én gang og loper til slutt_dato. Uten dette ville den
// forfalt hver maaned, og et proevemedlem lyst roedt fra dag 31.
$b = Medlemskap::betalingsstatus($bMedlem('Prove', 'Prøv Lissom'), null, $bBetaling($forLenge));
sjekk('proveperioden forfaller ikke hver maaned',
    $b['tilstand'] === 'betalt' && $b['forfalt'] === false, $b['tekst']);

// Fast trekk: «neste_trekk» er fasiten paa om perioden er dekket.
$avt = static fn(string $neste, string $sist = ''): array => [
    'vipps_agreement_id' => 'agr-test', 'neste_trekk' => $neste,
    'siste_trekk' => $sist !== '' ? $sist : null, 'status' => 'aktiv'];

// ── Trekket, slik det faktisk gikk ─────────────────────────────────────
//
// Medlemmet Eirin, 2. september: «Jeg betalte med vipps i gaar via siden her.
// Saa ut til aa fungere greit. Men pengene er fremdeles paa min konto.»
//
// Hun hadde rett. Fast trekk i Vipps er en fullmakt, ikke en betaling: cron
// ber om trekket, og Vipps krever at kunden varsles for det skjer — saa
// forfallet ligger tre dager fram (VARSEL_DAGER).
//
// «subscriptions.siste_trekk» settes i det trekket BES OM. Leste vi bare den,
// sto det «Betalt» om noe som bare var bestilt, og det ble staaende ogsaa om
// trekket senere feilet. Det er den samme forvekslingen Eirin ble utsatt for,
// bakt inn i verkstedets egen oversikt.
$tAvt = ['vipps_agreement_id' => 'agr-test', 'status' => 'aktiv',
         'neste_trekk' => gmdate('Y-m-d', strtotime('+27 days')), 'siste_trekk' => $iDag];
$tTrekk = static fn(string $st): array
    => ['status' => $st, 'created_at' => $iDag . ' 04:00:00', 'belop_ore' => 199000];
$tMedlem = $bMedlem('Trekk', 'Årsmedlemskap');

foreach (['opprettet', 'venter'] as $st) {
    $b = Medlemskap::betalingsstatus($tMedlem, $tAvt, null, $tTrekk($st));
    sjekk('et trekk som er bestilt («' . $st . '») staar ikke som betalt',
        $b['tilstand'] === 'bestilt' && $b['forfalt'] === false, $b['tekst']);
}
$b = Medlemskap::betalingsstatus($tMedlem, $tAvt, null, $tTrekk('betalt'));
sjekk('… og et trekk som gikk gjennom staar som betalt',
    $b['tilstand'] === 'betalt' && $b['forfalt'] === false, $b['tekst']);
foreach (['feilet', 'avbrutt'] as $st) {
    $b = Medlemskap::betalingsstatus($tMedlem, $tAvt, null, $tTrekk($st));
    sjekk('… og et trekk som ikke gikk («' . $st . '») er forfalt',
        $b['tilstand'] === 'forfalt' && $b['forfalt'] === true, $b['tekst']);
}
// Trekkdato passert uten at cron har bedt om noe: da er det noe galt, og det
// skal sies. Dette er tilfellet der jobben i cPanel har stoppet.
$b = Medlemskap::betalingsstatus($tMedlem,
    ['vipps_agreement_id' => 'agr-test', 'status' => 'aktiv',
     'neste_trekk' => gmdate('Y-m-d', strtotime('-4 days')), 'siste_trekk' => null], null, null);
sjekk('… og en trekkdato som er passert uten trekk er forfalt',
    $b['tilstand'] === 'forfalt' && $b['forfalt'] === true, $b['tekst']);

// Oppslaget maa hente trekket uansett hvordan det gikk — ogsaa det som feilet.
// sisteBetalinger() teller bare det som ER betalt, og duger derfor ikke her.
$tMed = (int) DB::settInn('members', [
    'navn' => 'Trekkprove Testesen', 'epost' => 'trekkprove@example.test',
    'rolle' => 'medlem', 'status' => 'aktiv', 'medlemskap_type' => 'Årsmedlemskap',
]);
$tSub = (int) DB::settInn('subscriptions', [
    'member_id' => $tMed, 'plan' => 'Årsmedlemskap', 'pris_ore' => 199000,
    'vipps_agreement_id' => 'agr-trekkprove-' . $tMed, 'status' => 'aktiv',
]);
DB::settInn('payments', [
    'vipps_reference' => 'trekkprove-' . $tMed, 'type' => 'recurring_charge',
    'formal' => 'medlemskap', 'member_id' => $tMed, 'subscription_id' => $tSub,
    'belop_ore' => 199000, 'status' => 'venter',
    'idempotency_key' => bin2hex(random_bytes(18)),
]);
$tHentet = Medlemskap::sisteTrekk([$tSub]);
sjekk('oppslaget finner et trekk som ikke er gjort opp enda',
    isset($tHentet[$tSub]) && (string) $tHentet[$tSub]['status'] === 'venter');
sjekk('… mens sisteBetalinger() med rette lar det vaere',
    Medlemskap::sisteBetalinger([$tMed]) === []);
sjekk('tomt oppslag gir tom liste, ogsaa her', Medlemskap::sisteTrekk([]) === []);
DB::kjor('DELETE FROM payments WHERE member_id = :m', ['m' => $tMed]);
DB::kjor('DELETE FROM subscriptions WHERE id = :i', ['i' => $tSub]);
DB::kjor('DELETE FROM members WHERE id = :i', ['i' => $tMed]);

$b = Medlemskap::betalingsstatus($bMedlem('Trekk', 'Årsmedlemskap'),
    $avt(gmdate('Y-m-d', strtotime('+20 days')), $iDag), null);
sjekk('fast trekk som har gaatt gjennom er betalt',
    $b['tilstand'] === 'betalt' && $b['forfalt'] === false, $b['tekst']);
$b = Medlemskap::betalingsstatus($bMedlem('Trekk', 'Årsmedlemskap'),
    $avt(gmdate('Y-m-d', strtotime('-5 days')), $iDag), null);
sjekk('… og et trekk som ikke gikk er forfalt',
    $b['tilstand'] === 'forfalt' && $b['forfalt'] === true, $b['tekst']);

// Den som ikke er medlem har ingenting aa betale for.
$ikkeMedlem = ['id' => 0, 'navn' => 'Kursdeltaker', 'medlemskap_type' => null,
               'status' => 'ingen', 'betaler_ikke' => 0, 'betaler_ikke_grunn' => null];
sjekk('en som ikke er medlem har ingen betalingsstatus',
    Medlemskap::betalingsstatus($ikkeMedlem, null, null)['tilstand'] === 'ingen');

// ── Forfalt og utestaaende er to forskjellige ting ─────────────────────
//
// Eieren, 2. september: «verken hun eller Eirin kommer opp i kortet ikke
// betalt paa oversikten, og det maa de jo, helt til pengene er inne».
//
// Eirin hadde godkjent avtalen i Vipps, men trekket var ikke forfalt enda —
// og falt dermed ut av tellingen, enda ingen krone hadde kommet. Tellingen
// gikk paa «forfalt». Den skal gaa paa «pengene er ikke inne».
//
//   forfalt      pengene skulle vaert her. Roedt merke.
//   utestaaende  pengene er ikke inne. Kan vaere helt i orden, men skal telles.
$uMedlem = static fn(string $plan): array => ['id' => 0, 'navn' => 'U',
    'medlemskap_type' => $plan, 'status' => 'aktiv', 'betaler_ikke' => 0,
    'betaler_ikke_grunn' => null, 'start_dato' => $iDag];

// Avtalen er godkjent, ingenting trukket enda: ikke roedt, men utestaaende.
$b = Medlemskap::betalingsstatus($uMedlem('Årsmedlemskap'),
    ['vipps_agreement_id' => 'agr', 'neste_trekk' => $iDag,
     'siste_trekk' => null, 'status' => 'aktiv'], null, null);
sjekk('en godkjent avtale uten trekk er utestaaende, men ikke forfalt',
    $b['utestaaende'] === true && $b['forfalt'] === false, $b['tekst']);

// Trekket er bestilt: heller ikke roedt, men pengene er ikke inne.
$b = Medlemskap::betalingsstatus($uMedlem('Årsmedlemskap'),
    ['vipps_agreement_id' => 'agr', 'neste_trekk' => gmdate('Y-m-d', strtotime('+27 days')),
     'siste_trekk' => $iDag, 'status' => 'aktiv'], null,
    ['status' => 'venter', 'created_at' => $iDag . ' 04:00:00', 'belop_ore' => 199000]);
sjekk('… og et bestilt trekk likesaa',
    $b['tilstand'] === 'bestilt' && $b['utestaaende'] === true && $b['forfalt'] === false,
    $b['tekst']);

// Pengene er inne: ikke utestaaende.
$b = Medlemskap::betalingsstatus($uMedlem('Årsmedlemskap'),
    ['vipps_agreement_id' => 'agr', 'neste_trekk' => gmdate('Y-m-d', strtotime('+27 days')),
     'siste_trekk' => $iDag, 'status' => 'aktiv'], null,
    ['status' => 'betalt', 'created_at' => $iDag . ' 04:00:00', 'belop_ore' => 199000]);
sjekk('… mens et gjennomfort trekk ikke er noen av delene',
    $b['utestaaende'] === false && $b['forfalt'] === false, $b['tekst']);

// Den som betaler selv og aldri fullforte i Vipps: begge deler, og teksten
// sier HVA som skjedde. «Ikke betalt ennaa» sa det samme som merket over.
$b = Medlemskap::betalingsstatus($uMedlem('Prøv Lissom'), null, null,
    ['status' => 'venter', 'created_at' => $iDag . ' 20:42:00', 'belop_ore' => 99000]);
sjekk('en betaling som ble startet men ikke fullfort sier det',
    $b['utestaaende'] === true && $b['forfalt'] === true
    && str_contains($b['tekst'], 'aldri fullført'), $b['tekst']);
$b = Medlemskap::betalingsstatus($uMedlem('Prøv Lissom'), null, null,
    ['status' => 'feilet', 'created_at' => $iDag . ' 20:42:00', 'belop_ore' => 99000]);
sjekk('… og en som gikk i staa sier det',
    str_contains($b['tekst'], 'gikk ikke gjennom'), $b['tekst']);

// Fritatt teller aldri.
$b = Medlemskap::betalingsstatus(
    ['id' => 0, 'navn' => 'F', 'medlemskap_type' => 'Basis 30', 'status' => 'aktiv',
     'betaler_ikke' => 1, 'betaler_ikke_grunn' => 'dugnad'], null, null, null);
sjekk('… og et fritatt medlem er hverken forfalt eller utestaaende',
    $b['utestaaende'] === false && $b['forfalt'] === false);

// Kortet og filteret maa telle det samme — og det maa vaere «utestaaende».
$ovU = file_get_contents(dirname(__DIR__) . '/api/admin/oversikt.php');
sjekk('Oversikt teller det som er utestaaende',
    str_contains($ovU, "} elseif (!empty(\$b['utestaaende'])) {"));
sjekk('… og filteret «Ubetalte» det samme',
    str_contains($sida, "if (fv === 'Ubetalte') return !!m.erMedlem && !m.erFritatt && !!m.betalingUte;"));

// ── Oppslaget for hele lista ───────────────────────────────────────────
//
// Ett kall, ikke ett per medlem. Fem hundre medlemmer ville blitt fem hundre
// sporringer paa medlemsskjermen.
sjekk('tomt oppslag gir tom liste', Medlemskap::sisteBetalinger([]) === []);
$sbMedlem = (int) DB::settInn('members', [
    'navn' => 'Betalingsprove Testesen', 'epost' => 'betalingsprove@example.test',
    'rolle' => 'medlem', 'status' => 'aktiv', 'medlemskap_type' => 'Basis 30',
]);
DB::settInn('payments', [
    'vipps_reference' => 'prove-gammel-' . $sbMedlem, 'type' => 'manuell',
    'formal' => 'medlemskap', 'member_id' => $sbMedlem, 'belop_ore' => 100000,
    'status' => 'betalt', 'idempotency_key' => bin2hex(random_bytes(18)),
    'created_at' => '2026-01-01 10:00:00',
]);
$nyBet = (int) DB::settInn('payments', [
    'vipps_reference' => 'prove-ny-' . $sbMedlem, 'type' => 'manuell',
    'formal' => 'medlemskap', 'member_id' => $sbMedlem, 'belop_ore' => 259000,
    'status' => 'betalt', 'idempotency_key' => bin2hex(random_bytes(18)),
    'created_at' => '2026-08-01 10:00:00',
]);
$sb = Medlemskap::sisteBetalinger([$sbMedlem]);
sjekk('oppslaget tar den nyeste betalingen, ikke den forste',
    isset($sb[$sbMedlem]) && (int) $sb[$sbMedlem]['belop_ore'] === 259000,
    (string) ((int) ($sb[$sbMedlem]['belop_ore'] ?? 0) / 100) . ' kr');
// En annullert betaling er ikke en betaling. Uten dette ville en refundert
// maaned staatt som gjort opp.
DB::kjor('UPDATE payments SET annullert_at = UTC_TIMESTAMP() WHERE id = :i', ['i' => $nyBet]);
$sb = Medlemskap::sisteBetalinger([$sbMedlem]);
sjekk('… og hopper over en annullert betaling',
    isset($sb[$sbMedlem]) && (int) $sb[$sbMedlem]['belop_ore'] === 100000,
    (string) ((int) ($sb[$sbMedlem]['belop_ore'] ?? 0) / 100) . ' kr');
DB::kjor('DELETE FROM payments WHERE member_id = :m', ['m' => $sbMedlem]);
DB::kjor('DELETE FROM members WHERE id = :i', ['i' => $sbMedlem]);

// ── Serveren maa sende det ut ──────────────────────────────────────────
$medlApi = file_get_contents(dirname(__DIR__) . '/api/admin/medlemmer.php');
sjekk('medlemslista regner ut betalingen',
    str_contains($medlApi, 'Medlemskap::betalingsstatus(')
    && str_contains($medlApi, "'betaling' => \$b['tilstand'], 'betalingTekst' => \$b['tekst']"));
// «siste_trekk» manglet i oppslaget, saa et fast trekk som HAR gaatt gjennom
// sto som «venter paa forste trekk». Malt i nettleseren for og etter.
sjekk('… og oppslaget henter «siste_trekk»',
    str_contains($medlApi, 's.neste_trekk, s.siste_trekk, s.vipps_agreement_id'));
sjekk('… og timer igjen, ikke bare brukt',
    str_contains($medlApi, "'timerIgjen' =>"));
sjekk('… og haken staar paa raden',
    str_contains($medlApi, "'betalerIkke'     => !empty(\$m['betaler_ikke'])"));
sjekk('medlemsruta svarer paa det samme',
    str_contains($medlApi, "'betalerIkke'      => !empty(\$m['betaler_ikke'])"));
sjekk('serveren tar imot haken',
    str_contains($medlApi, "if (\$handling === 'betaler-ikke') {")
    && str_contains($medlApi, "'betaler_ikke'       => \$paa ? 1 : 0,"));
// Grunnen hoerer til haken. Skrus den av, skal ikke en gammel begrunnelse bli
// staaende og dukke opp igjen neste gang.
sjekk('… og glemmer grunnen naar haken skrus av',
    str_contains($medlApi, "'betaler_ikke_grunn' => \$paa && \$grunn !== '' ? \$grunn : null,"));
sjekk('innmeldingen kan sette haken med det samme',
    str_contains($medlApi, "\$fri      = Foresporsel::tekst('betalerIkke') === 'ja';"));

$ovApi = file_get_contents(dirname(__DIR__) . '/api/admin/oversikt.php');
sjekk('Oversikt teller de ubetalte med den samme regelen',
    str_contains($ovApi, 'Medlemskap::betalingsstatus(')
    // Tallet og radene i «Ikke betalt» leser det samme regnestykket, som
    // gjores én gang. Hvert sitt sted kunne de svart hver sitt om det samme
    // medlemmet.
    && str_contains($ovApi, "'ubetalte'    => \$medlemsstatus['ubetalte'],"));
sjekk('… og teller ikke dem som er fritatt',
    str_contains($ovApi, "if (\$b['tilstand'] === 'fri') {"));

// ── Skjermene ──────────────────────────────────────────────────────────
sjekk('medlemsraden viser om det er betalt',
    str_contains($sida, '<span style="{{ m.betalingStil }}">{{ m.betalingMerke }}</span>')
    && str_contains($sida, "betalingMerke: { fri: 'Fri', betalt: 'Betalt', bestilt: 'Bestilt',"));
sjekk('… og timer igjen',
    str_contains($sida, "timerIgjen: m.timerIgjen ? m.timerIgjen + ' t igjen' : '',"));
// Filteret maa lese det samme flagget kortet teller. Ellers kunne kortet sagt
// seks og lista vist sju.
sjekk('filteret «Ubetalte» finnes',
    str_contains($sida, "'Alle', 'Aktive', 'Ubetalte', 'Sluttet'"));
sjekk('… og teller det samme som kortet',
    str_contains($sida, "if (fv === 'Ubetalte') return !!m.erMedlem && !m.erFritatt && !!m.betalingUte;"));
sjekk('Oversikt har kortet «Medlemmer og betaling»',
    str_contains($sida, "kort('Medlemmer og betaling',"));
sjekk('… og det gaar til de ubetalte',
    str_contains($sida, "{ medlemFilter: 'Ubetalte', medlemSok: '' }"));

// Haken staar begge steder skjemaet staar, og i begge personrutene. Sto den
// bare det ene stedet, ville de to skjermene sagt forskjellige ting.
sjekk('haken staar i innmeldingsskjemaet, begge steder',
    substr_count($sida, 'checked="{{ miFri }}" onChange="{{ vekslMiFri }}"') === 2);
sjekk('… og i medlemsruta, begge steder',
    substr_count($sida, 'checked="{{ personFri }}" onChange="{{ vekslPersonFri }}"') === 2);
sjekk('… og innmeldingen sender den',
    str_contains($sida, "betalerIkke: this.state.miFri ? 'ja' : 'nei',"));
sjekk('… og medlemsruta lagrer den',
    str_contains($sida, "this.medlemKall({ handling: 'betaler-ikke', medlemId: p.id,"));

// ── Utfyllende informasjon paa medlemskapene ───────────────────────────
//
// Eieren, 2. september: «jeg vil ha utvidet info paa medlemskapene», og
// deretter én melding per plan med teksten som skal staa der.
//
// «beskrivelse» er én setning paa kortet og rommer 400 tegn. Teksten som skal
// inn er seks avsnitt lang og hoerer hjemme paa sida man kommer til naar man
// klikker seg inn. Migrasjon 127 gir den et eget felt, og «Viktig aa vite»
// et til.
echo "\n== Utfyllende info paa medlemskapene ==\n";

$mig127 = file_get_contents(dirname(__DIR__) . '/db/migrations/127_utfyllende_info_pa_medlemskap.sql');
sjekk('migrasjon 127 gir planene langtekst og viktig',
    str_contains($mig127, 'ADD COLUMN langtekst TEXT')
    && str_contains($mig127, 'ADD COLUMN viktig    TEXT'));
sjekk('… og kolonnene staar i basen',
    DB::harKolonne('membership_plans', 'langtekst')
    && DB::harKolonne('membership_plans', 'viktig'));

// Teksten er lagret, ikke skrevet inn i koden.
foreach (['Prøv Lissom', 'Basis 30', 'Årsmedlemskap'] as $navn) {
    $pl = Medlemskap::plan($navn);
    sjekk('«' . $navn . '» har utfyllende tekst i basen',
        $pl !== null && mb_strlen((string) $pl['langtekst']) > 600,
        mb_strlen((string) ($pl['langtekst'] ?? '')) . ' tegn');
    sjekk('… og «Viktig aa vite»',
        $pl !== null && Medlemskap::punkter($pl['viktig']) !== [],
        count(Medlemskap::punkter($pl['viktig'] ?? null)) . ' punkter');
    // Avsnittene skal staa som avsnitt. Ett avsnitt betyr at tomlinjene er
    // borte, og da blir hele teksten én klump paa sida.
    sjekk('… og teksten staar i flere avsnitt',
        $pl !== null && count(preg_split('/\r?\n\s*\r?\n/', trim((string) $pl['langtekst']))) >= 4);
}

// Serveren sender dem ut. Uten dette staar teksten i basen og ingen ser den.
$medApi = file_get_contents(dirname(__DIR__) . '/api/medlemskap.php');
sjekk('api/medlemskap.php sender langtekst og viktig',
    str_contains($medApi, "'langtekst'  => (string) (\$p['langtekst'] ?? '')")
    && str_contains($medApi, "'viktig'     => Medlemskap::punkter(\$p['viktig'] ?? null)"));

// Nettsida bruker dem: den lange teksten er brodteksten, «Viktig aa vite» er
// en egen bolk under de andre seksjonene.
sjekk('medlemskapssida viser den utfyllende teksten',
    str_contains($sida, 'om: o.langtekst'));
sjekk('… og «Viktig aa vite» som egen bolk',
    str_contains($sida, "tittel: 'Viktig å vite', tekst: '', erTekst: false,"));

// Skjemaet i admin. Uten feltene finnes teksten bare i en migrasjon, og
// verkstedet maa be om hjelp for aa rette et komma.
sjekk('planskjemaet har begge feltene',
    str_contains($sida, 'value="{{ plLangtekst }}" onChange="{{ settPlLangtekst }}"')
    && str_contains($sida, 'value="{{ plViktig }}" onChange="{{ settPlViktig }}"'));
sjekk('… og de er koblet til skjemaet',
    str_contains($sida, "plLangtekst: v('langtekst'),  settPlLangtekst: sett('langtekst'),")
    && str_contains($sida, "plViktig: v('viktig'),        settPlViktig: sett('viktig'),"));
// Lagringen maa sende dem, og «Rediger» maa hente dem. Mangler det ene, blir
// teksten toemt i det noen retter prisen.
sjekk('… lagringen sender dem',
    str_contains($sida, "langtekst: d.langtekst || '', viktig: d.viktig || '',"));
sjekk('… og «Rediger» henter dem',
    str_contains($sida, "langtekst: pl.langtekst || '', viktig: pl.viktig || '',"));
$planApi127 = file_get_contents(dirname(__DIR__) . '/api/admin/planer.php');
sjekk('… og serveren tar dem imot',
    str_contains($planApi127, "'langtekst'   => mb_substr(trim((string) (\$kropp['langtekst'] ?? '')), 0, 20000),")
    && str_contains($planApi127, "'viktig'      => implode(\"\\n\", Medlemskap::punkter((string) (\$kropp['viktig'] ?? ''))),"));
sjekk('… og sender dem tilbake til skjemaet',
    str_contains($planApi127, "'langtekst'   => (string) (\$p['langtekst'] ?? ''),"));

// ── Bildet velges, det skrives ikke ────────────────────────────────────
//
// Eieren, 2. september: «jeg vil ogsaa ha mulighet aa legge ut bilder direkte
// i dette bildet, ikke slik det er naa hvor det staar Bilde /
// uploads_shutterstock_2829103797.jpg».
sjekk('medlemskapet har billedvelger, ikke et filnavnfelt',
    str_contains($sida, "plVelgBilde: () => this.apneBildevalg({ slag: 'plan' }),")
    && str_contains($sida, 'on-click="{{ plVelgBilde }}"'));
sjekk('… og tekstfeltet med filnavnet er borte',
    !str_contains($sida, 'value="{{ plBilde }}" onChange="{{ settPlBilde }}"')
    && !str_contains($sida, 'Filnavnet på bildet slik det heter under Nettsiden → Bilder.'));
sjekk('… og det valgte bildet havner i skjemaet',
    str_contains($sida, "if (v.slag === 'plan') {\n      this.endrePlan({ bilde: url || '' });"));

// ── Faktaboksen og «Passer for» hoerer til kursene ─────────────────────
//
// Eieren, 2. september: «firkanten som er paa alle kurs naa, med Varighet
// 30 timer i maaneden skal jeg ikke ha» og «jeg vil heller ikke ha med dette,
// Passer for: Alle — ingen forkunnskaper nodvendig». Bare paa medlemskapene:
// paa kursene er Nivaa, Varighet, Du laerer og Med hjem felt som fylles ut i
// kursoppsettet.
sjekk('faktaboksen staar ikke paa medlemskapene',
    str_contains($sida, "bVisFakta: this.state.fra !== 'medlemskap' && this.bFaktaRader().length > 0,"));
sjekk('«Passer for»-linja staar ikke paa medlemskapene',
    str_contains($sida, "bVisPasserFor: this.state.fra !== 'medlemskap',"));
sjekk('… og begge staar bak en vakt i markupen',
    str_contains($sida, '<sc-if value="{{ bVisFakta }}"')
    && str_contains($sida, '<sc-if value="{{ bVisPasserFor }}"'));
// Regnestykket staar ett sted, saa ramma ikke kan staa tom.
sjekk('… og faktaradene regnes ut ett sted',
    str_contains($sida, 'bFakta: this.bFaktaRader(),')
    && substr_count($sida, 'bFaktaRader() {') === 1);

// ── «Mini 15» ──────────────────────────────────────────────────────────
//
// Eieren, 2. september: «legg til et nytt medlemskap … Pris kr 1790».
echo "\n== Mini 15 ==\n";

$mini = Medlemskap::plan('Mini 15');
sjekk('«Mini 15» ligger i basen og er i salg', $mini !== null);
sjekk('… koster kr 1 790', (int) ($mini['pris_ore'] ?? 0) === 179000,
    ((int) ($mini['pris_ore'] ?? 0) / 100) . ' kr');
sjekk('… og gir 15 timer i maaneden', (int) ($mini['timer'] ?? 0) === 15);
sjekk('… med to maaneders binding og én maaneds oppsigelse',
    (int) ($mini['binding_mnd'] ?? 0) === 2 && (int) ($mini['oppsigelse_mnd'] ?? 0) === 1);
sjekk('… er ikke en proveperiode', (int) ($mini['engangs'] ?? 1) === 0);
sjekk('… og lar medlemmet velge betalingsmaate',
    $mini !== null && !Medlemskap::kreverFastTrekk($mini));
sjekk('… har teksten sin', mb_strlen((string) ($mini['langtekst'] ?? '')) > 600
    && Medlemskap::punkter($mini['punkter'] ?? null) !== []
    && Medlemskap::punkter($mini['viktig'] ?? null) !== []);
// Ligger mellom proveperioden og Basis 30 — i pris, i timer og i rekka.
$rekke = array_column(Medlemskap::planer(), 'navn');
sjekk('… og staar mellom «Prøv Lissom» og «Basis 30»',
    array_search('Mini 15', $rekke, true) === array_search('Prøv Lissom', $rekke, true) + 1
    && array_search('Basis 30', $rekke, true) === array_search('Mini 15', $rekke, true) + 1,
    implode(' · ', $rekke));
// To planer paa samme plass i sorteringa gir tilfeldig rekkefolge.
sjekk('… uten at to planer deler plass i rekka',
    count(array_unique(array_column(Medlemskap::planer(), 'sortering')))
    === count(Medlemskap::planer()));

// ── Avpubliser et kurs ─────────────────────────────────────────────────
//
// Eieren, 2. september: «nå vil jeg at du legger til så jeg kan avpublisere
// kurs, altså ikke vis på nettsiden».
//
// Basen har kjent forskjell paa «kladd» og «publisert» hele tida, og
// api/kurs.php henter bare det som staar som publisert. Kurslista i admin
// skriver til og med «Ikke publisert». Men kursskjemaet sendte
// «status: 'publisert'» fast, saa det fantes ingen vei tilbake: eneste maaten
// aa faa et kurs vekk fra nettsida var aa slette det — og da fulgte datoene
// og paameldingene med.
echo "\n== Avpubliser et kurs ==\n";

// Bryteren i skjemaet. Samme etikett som paa kalenderdatoene, saa de to
// stedene sier det samme.
sjekk('kursskjemaet har «Publisert paa nettsiden»',
    str_contains($sida, 'checked="{{ kPublisert }}" on-change="{{ toggleKPublisert }}"')
    && substr_count($sida, 'label="Publisert på nettsiden"') === 2);
sjekk('… og bryteren er koblet',
    str_contains($sida, 'kPublisert: this.state.kPublisert !== false,')
    && str_contains($sida, 'toggleKPublisert: () => this.setState(s => ({ kPublisert: s.kPublisert === false })),'));

// Kjernen: statusen kommer fra skjemaet, ikke fra en fast streng.
sjekk('lagringen sender statusen fra skjemaet',
    str_contains($sida, "status: rad.publisert === false"));
// Kontrollen. Uten denne ville proven over vaere gronn ogsaa om den gamle
// linja sto igjen ved siden av den nye — og da er det den som gjelder, for
// den siste tilordningen vinner i objektet som sendes til serveren.
//
// Vi ser bare paa kroppen lagreKurs() sender. «status: 'publisert'» staar med
// full rett andre steder — varer, artikler og medlemssalg har sin egen.
$kursLagring = (static function (string $kode): string {
    $fra = strpos($kode, 'lagreKurs(rad, okter, behold) {');
    if ($fra === false) {
        return '';
    }
    $til = strpos($kode, '.then(r => r.json()', $fra);
    return $til === false ? '' : substr($kode, $fra, $til - $fra);
})($sida);
sjekk('… og kursoppsettet er funnet i det hele tatt', $kursLagring !== '');
sjekk('… og den faste «publisert» er borte fra kurslagringen',
    !str_contains($kursLagring, "status: 'publisert',"));
sjekk('… og et avlyst kurs blir ikke gjort om til en kladd',
    str_contains($sida, "? ((eksisterende && eksisterende.status === 'avlyst') ? 'avlyst' : 'kladd')"));

// Skjemaet maa vite hva kurset staar som naar det aapnes, ellers slaar
// bryteren seg paa igjen av seg selv.
sjekk('skjemaet leser statusen naar kurset aapnes',
    str_contains($sida, "kPublisert: (raa.status || 'publisert') === 'publisert',"));
// Et nytt kurs skal alltid starte som publisert. Uten dette hang av-stillingen
// fra forrige kurs igjen i skjemaet.
sjekk('… og et nytt kurs starter som publisert',
    substr_count($sida, 'kUtenDato: false, kPublisert: true,') === 4);

// Lista maa vise det. Merket sto bare i basen-fanen; tok du et kurs ned og
// gikk tilbake til kurslista, saa den helt lik ut.
sjekk('kurslista merker det som ikke er publisert',
    substr_count($sida, "k.status && k.status !== 'publisert' ? 'Ikke publisert' : ''") === 2);

// Serveren tar imot «kladd» — den har gjort det hele tida.
$kursApi = file_get_contents(dirname(__DIR__) . '/api/admin/kurs.php');
sjekk('serveren tar imot kladd, publisert og avlyst',
    str_contains($kursApi, "in_array(Foresporsel::tekst('status'), ['kladd', 'publisert', 'avlyst'], true)"));
// … og nettsida henter bare det som er publisert. Det er dette som gjor at
// bryteren faktisk tar kurset ned.
foreach (['api/kurs.php', 'api/venteliste.php', 'app/lib/apent.php', 'app/lib/booking.php'] as $fil) {
    // booking.php skriver spoersmaalet i en enkeltfnuttet streng, saa fnuttene
    // rundt «publisert» staar escapet der. Samme krav, annen skrivemaate.
    $kode = file_get_contents(dirname(__DIR__) . '/' . $fil);
    sjekk('«' . $fil . '» krever status = publisert',
        str_contains($kode, "status = 'publisert'")
        || str_contains($kode, "status = \\'publisert\\'"));
}

// ── «Fri tilgang» ut av salg ───────────────────────────────────────────
//
// Eieren, 2. september: «meldemskapet proff - fri tilgang skal avpubliseres».
//
// Planskjemaet har haken «I salg på nettsiden» fra for, og Medlemskap::planer()
// henter bare rader med aktiv = 1. Migrasjonen setter den av.
echo "\n== «Fri tilgang» ut av salg ==\n";

$mig126 = file_get_contents(dirname(__DIR__) . '/db/migrations/126_fri_tilgang_ut_av_salg.sql');
sjekk('migrasjon 126 tar «Fri tilgang» ut av salg',
    str_contains($mig126, "SET aktiv = 0")
    && str_contains($mig126, "WHERE navn = 'Fri tilgang'"));
sjekk('… og planen staar ikke lenger i salg',
    Medlemskap::plan('Fri tilgang') === null);
sjekk('… mens de andre planene staar som for',
    Medlemskap::plan('Basis 30') !== null
    && Medlemskap::plan('Årsmedlemskap') !== null
    && Medlemskap::plan('Prøv Lissom') !== null);
// Raden blir staaende, saa den som alt staar paa den beholder prisen sin —
// og verkstedet kan legge den ut igjen med haken i planskjemaet.
sjekk('… men raden er ikke slettet',
    DB::en('SELECT navn FROM membership_plans WHERE navn = :n', ['n' => 'Fri tilgang']) !== null);

// Haken maa kunne skrus av uten aa dra med seg noe annet.
//
// «krever_fast_trekk» ble skrevet ubetinget i api/admin/planer.php, og
// planskjemaet sender ikke feltet. Aarsmedlemskapet krever fast trekk, og det
// ville falt bort i det noen rettet en skrivefeil paa planen.
$planApi = file_get_contents(dirname(__DIR__) . '/api/admin/planer.php');
sjekk('planlagringen roerer ikke fast trekk naar feltet ikke er med',
    str_contains($planApi, "if (!array_key_exists('fastTrekk', \$kropp)\n        || !DB::harKolonne('membership_plans', 'krever_fast_trekk')) {"));
sjekk('… og aarsmedlemskapet krever fortsatt fast trekk',
    Medlemskap::kreverFastTrekk(Medlemskap::plan('Årsmedlemskap') ?? []));

// ── PHP-en maa la seg lese ─────────────────────────────────────────────
//
// 1. september gikk publiseringen til lissom.no i stykker paa
//
//     PHP Parse error: syntax error, unexpected token "\\"
//     in api/apningstider.php on line 123
//
// En tekst i fila var byttet med et python-skript, der «\\$linjer» sto i en
// dobbeltfnuttet streng. «\\$» er ingen escape i python, saa backslashen ble
// skrevet rett inn i PHP-en. Filene som ble roert samtidig ble kjort gjennom
// «php -l»; akkurat denne ble det ikke.
//
// GitHub-jobben tar det — den kjorer den samme sjekken, og publiseringen
// stoppet for noe naadde nettsiden. Men da har det alt gaatt inn i main, og
// da staar hovedgrenen med en fil som ikke lar seg lese. Sjekken hoerer
// hjemme her, der den koster to sekunder og fanger det for det pushes.
//
// Samme utvalg som .github/workflows/deploy.yml: api, app og bin.
echo "\n== Delt betaling og to slag gavekort ==\n";
// Eieren: «hva i huleste gjor jeg naar noen skal betale paa stedet og jeg maa
// registrere salget manuelt, og de har et gavekort, men ikke paa hele
// beloepet? jeg maa kunne taste inn gavekort, kontant og vipps, de maa kunne
// dele opp. og regnskapsmessig, saa vil jeg ha to typer gavekort, et som er
// ting vi gir ut som ikke skal skatteberegnes og et som faktisk er kjopt av
// oss.»
$m134 = file_get_contents(dirname(__DIR__) . '/db/migrations/134_delt_betaling_og_gavekort.sql');

// «payments.order_id» er det som gjor at flere betalinger kan hore til det
// samme salget. Uten den er et delt oppgjor bare tre lose rader.
sjekk('migrasjon 134 gir payments en order_id',
    str_contains($m134, 'ALTER TABLE payments')
    && preg_match('~ADD COLUMN IF NOT EXISTS order_id\s+BIGINT UNSIGNED NULL~', $m134) === 1);
sjekk('… med indeks, saa dagens salg ikke leser hele tabellen',
    str_contains($m134, 'CREATE INDEX IF NOT EXISTS ix_payments_order ON payments (order_id)'));
// Uten dette ville historikken paa alle gamle salg staatt tom.
sjekk('… og kobler betalingene som alt finnes',
    str_contains($m134, 'UPDATE payments p')
    && str_contains($m134, 'JOIN orders o ON o.payment_id = p.id')
    && str_contains($m134, 'SET p.order_id = o.id'));
sjekk('migrasjon 134 skiller kjopte gavekort fra dem som ble gitt bort',
    preg_match("~ADD COLUMN IF NOT EXISTS opprinnelse ENUM\('kjopt','gitt'\) NOT NULL DEFAULT 'kjopt'~", $m134) === 1);
sjekk('… og husker hvem som utstedte det over disk',
    str_contains($m134, 'ADD COLUMN IF NOT EXISTS utstedt_av'));

$utFil = file_get_contents(dirname(__DIR__) . '/api/admin/uttak.php');

// Gavekortet er ikke en betalingsmaate — det er ingen penger inn — men det er
// en del av oppgjoret. Derfor to lister.
sjekk('kassa har egne maater for delene av et oppgjor',
    str_contains($utFil, "const DELMAATER = ['Gavekort', 'Kontant', 'Vipps'];")
    && str_contains($utFil, "const MAATER = ['Kontant', 'Vipps'];"));
sjekk('gavekortdelen foeres med null i penger og beloepet i gavekort_ore',
    str_contains($utFil, "'belop_ore'       => \$erGavekort ? 0 : \$r['ore'],")
    && str_contains($utFil, "\$felt['gavekort_ore'] = \$r['ore'];"));
// Uten dette havner kontantdelen paa Vipps-kontoen: ordren kan bare baere én
// maate, radene kan baere hver sin.
sjekk('hver del baerer sin egen betalingsmaate',
    str_contains($utFil, "\$felt['maate'] = \$r['maate'];"));
// Hovedraden skal ha penger i seg. Peker den paa gavekortraden, staar salget
// med null kroner for alt som leser orders.payment_id.
sjekk('hovedraden er en pengedel, ikke gavekortraden',
    str_contains($utFil, "['payment_id' => \$pengerad ?? \$ider[0]]"));
// Ett salg maa kunne annulleres i ett grep. Strykes bare hovedraden, staar
// kontantdelen igjen som betalt og dagen summerer til for mye.
sjekk('annullering tar alle delene av salget',
    str_contains($utFil, "'SELECT id, belop_ore, refundert_ore FROM payments WHERE order_id = :o'")
    && str_contains($utFil, 'foreach ($deler as $d) {'));
sjekk('… og legger gavekortet tilbake',
    str_contains($utFil, '$tilbake += Booking::angreGavekort((int) $d[\'id\']);'));
// Uten kolonnen henger ikke delene sammen, og en annullering ville latt
// resten staa som betalt. Da er det bedre aa ikke ta imot salget.
sjekk('delt oppgjor nektes naar basen ikke er oppdatert',
    str_contains($utFil, "if (!DB::harKolonne('payments', 'order_id')) {"));
// Alle salg skal ha koblingen, ikke bare de delte. Ellers betyr kolonnen
// noe forskjellig fra rad til rad.
sjekk('ogsaa vanlige kassesalg kobler betalingen til ordren',
    substr_count($utFil, "DB::oppdater('payments', ['order_id' => \$id], ['id' => \$betalingId]);") === 4);

// Et kort som gis bort har ingen penger bak seg. En betalingsrad paa null
// ville sagt at det kom inn noe som aldri kom.
sjekk('et bortgitt gavekort lager ingen betaling',
    str_contains($utFil, "if (\$opprinnelse === 'kjopt') {")
    && str_contains($utFil, '$betalingId = null;'));
sjekk('et solgt gavekort foeres som gavekort, ikke som salg',
    str_contains($utFil, "'formal'          => 'gavekort',"));

$bokFil = file_get_contents(dirname(__DIR__) . '/app/lib/booking.php');
// Gavekortraden i et delt oppgjor er ikke orders.payment_id. Uten oppslaget
// paa order_id ville uttaket blitt staaende uloggfoert.
sjekk('gavekorttrekket finner ordren gjennom payments.order_id',
    str_contains($bokFil, "elseif ((int) (\$eget['order_id'] ?? 0) > 0) {"));
sjekk('et kort utstedt over disk uten adresse maser ikke paa admin',
    str_contains($bokFil, 'public static function aktiverGavekort(int $kortId, bool $varsle = true): void')
    && str_contains($bokFil, 'if (!$varsle || !$til) {'));

$doFil2 = file_get_contents(dirname(__DIR__) . '/api/admin/dagsoppgjor.php');
// Ordren staar én gang for hele salget og kan ikke skille kontantdelen fra
// Vipps-delen. Raden kan.
sjekk('dagsoppgjoret leser maaten paa raden foer den paa ordren',
    str_contains($doFil2, "\$m = (string) (\$r['radmaate'] ?? '');")
    && str_contains($doFil2, "\$m = (string) (\$r['betalt_maate'] ?? '');"));
// Et bortgitt kort ble aldri bokfoert som gjeld. Trekker vi ned gjelden naar
// det loeses inn, gaar kontoen i minus av kort ingen har betalt for.
sjekk('et bortgitt gavekort foeres mot kostnad, ikke mot gjelden',
    str_contains($doFil2, "'Gavekort (gitt)' => 'regnskap_konto_gavekort_gitt',")
    && str_contains($doFil2, "? 'Gavekort (gitt)'"));
sjekk('… og kontoen kan settes av regnskapsfoereren',
    str_contains($doFil2, "'regnskap_konto_gavekort_gitt',"));
// Config leser bare noekler som staar paa lista. Uten denne ville kontoen
// vaert lagret, men aldri lest — og bilaget sagt MANGLER.
$cfgFil = file_get_contents(dirname(__DIR__) . '/app/config.php');
sjekk('… og leses faktisk ut av basen',
    str_contains($cfgFil, "'regnskap_konto_gavekort_gitt',"));
sjekk('… og staar som felt under OEkonomi',
    str_contains($sida, "'regnskap_konto_gavekort_gitt', '7320'"));

// Skjermen: delingen ligger i det samme salget, ikke i et eget skjema.
sjekk('kassa kan dele opp betalingen',
    str_contains($sida, "utDeltByttTekst: delt ? 'Tilbake til ett beløp' : 'Del opp betalingen',")
    && str_contains($sida, "handling: 'delt',"));
// Beloepsfeltet og QR-en leser ett belop. Staar de framme mens man deler,
// ville de sendt et krav paa ingenting.
sjekk('… og skjuler enkeltbeloepet mens man deler',
    str_contains($sida, '<sc-if value="{{ utEnkeltPaa }}" hint-placeholder-val="{{ true }}">'));
// Saldoen maa vaere kjent FOER delingen skrives inn — det er hele poenget.
sjekk('saldoen paa kortet slaas opp mens man skriver',
    str_contains($sida, 'gavekortSaldo(kode) {')
    && str_contains($sida, 'this.gavekortSaldo(v);'));
sjekk('kassa kan utstede gavekort, solgt eller gitt bort',
    str_contains($sida, "handling: 'gavekort',")
    && str_contains($sida, "opprinnelse: gvSolgt ? 'kjopt' : 'gitt',"));

// ── Og saa i virkeligheten ────────────────────────────────────────────
//
// Det over er tekst i filer. Dette er det som faktisk skjer med et kort.
if (DB::harKolonne('payments', 'order_id') && DB::harKolonne('gift_cards', 'opprinnelse')) {
    DB::kjor("DELETE FROM gift_card_uses WHERE gift_card_id IN (SELECT id FROM gift_cards WHERE kode LIKE 'LIS-TEST%')");
    DB::kjor("DELETE FROM payments WHERE vipps_reference LIKE 'KASSE-TEST%'");
    DB::kjor("DELETE FROM gift_cards WHERE kode LIKE 'LIS-TEST%'");

    $kortId = DB::settInn('gift_cards', [
        'kode' => 'LIS-TEST-DEL-ING', 'opprinnelig_ore' => 50000, 'saldo_ore' => 50000,
        'gyldig_til' => gmdate('Y-m-d', strtotime('+1 year')),
        'status' => 'aktivt', 'opprinnelse' => 'kjopt',
    ]);
    $ordreId = DB::settInn('orders', [
        'ordrenr' => 'TEST-DELT', 'kunde_navn' => 'Testkunde', 'sum_ore' => 69000,
        'status' => 'hentet', 'betalt_maate' => 'Gavekort + Kontant', 'payment_id' => null,
    ]);
    // Gavekortdelen: ingen penger inn, 300 kroner av kortet.
    $gkRad = DB::settInn('payments', [
        'vipps_reference' => 'KASSE-TEST-1', 'type' => 'manuell', 'formal' => 'booking',
        'belop_ore' => 0, 'status' => 'betalt', 'idempotency_key' => Vipps::uuid(),
        'order_id' => $ordreId, 'maate' => 'Gavekort',
        'gavekort_id' => $kortId, 'gavekort_ore' => 30000,
    ]);
    // Kontantdelen, og det er DEN ordren peker paa.
    //
    // Dette er hele poenget: gavekortraden er ikke orders.payment_id. Sto den
    // der, ville det gamle oppslaget funnet ordren likevel, og testen vaert
    // gronn uten aa ha proevd den nye veien i det hele tatt.
    $kontantRad = DB::settInn('payments', [
        'vipps_reference' => 'KASSE-TEST-2', 'type' => 'manuell', 'formal' => 'booking',
        'belop_ore' => 39000, 'status' => 'betalt', 'idempotency_key' => Vipps::uuid(),
        'order_id' => $ordreId, 'maate' => 'Kontant',
    ]);
    DB::oppdater('orders', ['payment_id' => $kontantRad], ['id' => $ordreId]);

    Booking::trekkGavekort($gkRad);
    sjekk('gavekortet trekkes ned av en del som ikke er ordrens hovedrad',
        (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]) === 20000,
        'saldo ' . DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]));
    // Sporet skal si hva kortet ble brukt paa, ikke «betaling nr. 7».
    $bruk = DB::en('SELECT ref_type, ref_id, belop_ore FROM gift_card_uses WHERE gift_card_id = :k', ['k' => $kortId]);
    sjekk('… og uttaket loggfoeres mot ordren',
        $bruk !== null && (string) $bruk['ref_type'] === 'ordre'
        && (int) $bruk['ref_id'] === $ordreId && (int) $bruk['belop_ore'] === 30000);

    // Webhooken og returen kan begge komme, og begge flere ganger.
    Booking::trekkGavekort($gkRad);
    sjekk('… og trekkes ikke to ganger',
        (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]) === 20000);

    // Annulleres salget, er pengene paa kortet ikke brukt.
    $tilbake = Booking::angreGavekort($gkRad);
    sjekk('annullering legger beloepet tilbake paa kortet',
        $tilbake === 30000
        && (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]) === 50000);
    // Angres det som alt er angret, skal kunden ikke faa beloepet i gave.
    sjekk('… men bare én gang', Booking::angreGavekort($gkRad) === 0
        && (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]) === 50000);

    // Et kort som gikk tomt ble satt til «brukt». Kommer saldoen tilbake,
    // skal det virke igjen.
    DB::oppdater('gift_cards', ['saldo_ore' => 0, 'status' => 'brukt'], ['id' => $kortId]);
    DB::settInn('gift_card_uses', [
        'gift_card_id' => $kortId, 'belop_ore' => 30000,
        'ref_type' => 'ordre', 'ref_id' => $ordreId,
    ]);
    Booking::angreGavekort($gkRad);
    $etter = DB::en('SELECT saldo_ore, status FROM gift_cards WHERE id = :i', ['i' => $kortId]);
    sjekk('et oppbrukt kort blir gyldig igjen naar saldoen kommer tilbake',
        (int) $etter['saldo_ore'] === 30000 && (string) $etter['status'] === 'aktivt',
        $etter['saldo_ore'] . ' / ' . $etter['status']);

    DB::kjor('DELETE FROM gift_card_uses WHERE gift_card_id = :k', ['k' => $kortId]);
    DB::kjor('DELETE FROM orders WHERE id = :i', ['i' => $ordreId]);
    DB::kjor('DELETE FROM payments WHERE id IN (:i, :j)', ['i' => $gkRad, 'j' => $kontantRad]);
    DB::kjor('DELETE FROM gift_cards WHERE id = :i', ['i' => $kortId]);
}

echo "\n== Medlemskapene i «Ikke betalt» ==\n";
// Eieren, 2. september: «dverken hun eller eiriin kommer opp i kortet ikke
// betalt paa oversikten, og det maa de jo gjore, helt til pengene er inne».
// Og da det fortsatt ikke sto der: «hvorfor vises ikke de to som er ubetalte
// i oversikten, altsaa eirin og ida».
//
// Kortet ble bygget 29. august for kursplasser lagt inn for haand, for
// medlemmene hadde noen betalingsstatus i det hele tatt. Forste gang han
// spurte, endret jeg telleren i Medlemmer-kortet og ikke kortet han pekte paa.
$ovFil = file_get_contents(dirname(__DIR__) . '/api/admin/oversikt.php');

// To steder trenger regnestykket: tallet og radene. Regnet hvert sitt sted
// kunne de svart hver sitt om det samme medlemmet.
sjekk('betalingsstatusen for medlemmene regnes ett sted',
    str_contains($ovFil, '$medlemsstatus = (static function (): array {')
    // Kallet, ikke omtalen: kommentarene nevner den ogsaa.
    && substr_count($ovFil, '= Medlemskap::betalingsstatus(') === 1);
sjekk('… og telleren leser den',
    str_contains($ovFil, "'ubetalte'    => \$medlemsstatus['ubetalte'],"));
sjekk('… og kortet leser de samme radene',
    str_contains($ovFil, "}, \$medlemsstatus['rader'])),"));
// Uten dette staar kursplassene alene i kortet, som for.
sjekk('kortet slaar sammen kursplasser og medlemskap',
    str_contains($ovFil, "'ubetalte' => array_merge(array_map("));
// Skjermen maa vite hvilket slag raden er: de to gjores opp hvert sitt sted.
sjekk('radene sier hvilket slag de er',
    str_contains($ovFil, "'slag'    => 'booking',")
    && str_contains($ovFil, "'slag'  => 'medlem',"));
// «Alle med utestaaende», ikke bare de forfalte: et trekk som er bestilt og
// ikke landet enda skal ogsaa staa der.
sjekk('alle med utestaaende kommer med, ikke bare de forfalte',
    str_contains($ovFil, "} elseif (!empty(\$b['utestaaende'])) {"));
// «kr. 0,-» leses som «skylder ingenting».
sjekk('ukjent pris staar tomt, ikke som null kroner',
    str_contains($ovFil, "'belop' => \$m['pris'] > 0 ? Booking::kroner(\$m['pris']) : '',"));

$mFil = file_get_contents(dirname(__DIR__) . '/api/admin/medlemmer.php');
// Knappene i kortet gjorde opp en kursplass. For et medlemskap fantes det
// ikke noe sted i systemet aa registrere at pengene kom.
sjekk('et medlemskap kan registreres betalt',
    str_contains($mFil, "if (\$handling === 'betaling') {"));
sjekk('… som en ekte betaling, ikke et flagg',
    str_contains($mFil, "'formal'          => 'medlemskap',")
    && str_contains($mFil, "'type'            => 'manuell',")
    && str_contains($mFil, "'status'          => 'betalt',"));
// Uten member_id teller den ikke i Medlemskap::sisteBetalinger(), og medlemmet
// ville staatt som ubetalt selv etter at pengene var talt opp.
sjekk('… knyttet til medlemmet, saa merket slaar om',
    str_contains($mFil, "'member_id'       => \$id,"));
sjekk('… med hvem som registrerte den',
    str_contains($mFil, "\$felt['registrert_av'] = (int) \$jeg['id'];"));
// Prisen da avtalen ble inngaatt, ikke dagens.
sjekk('… til prisen som ble avtalt',
    str_contains($mFil, "\$ore = (int) \$avtale['pris_ore'];"));

// Skjermen
sjekk('medlemsrader gjores opp mot medlemmet, kursplasser mot paameldingen',
    str_contains($sida, "? this.medlemKall({ handling: 'betaling', medlemId: u.id, maate: m })")
    && str_contains($sida, ": this.pameldingKall({ handling: 'status', id: u.id, status: 'betalt', maate: m })"));
sjekk('… og navnet paa et medlem er en vei inn til medlemmet',
    str_contains($sida, "this.gaaAdmin('adminmedlem', { medlemFilter: 'Alle', medlemSok: u.navn })"));
// «plasser» sto der fra den gang kortet bare hadde kursplasser.
sjekk('overskriften sier ikke lenger «plasser» om et medlemskap',
    str_contains($sida, "(liste.length === 1 ? ' ubetalt · ' : ' ubetalte · ')")
    && !str_contains($sida, "(liste.length === 1 ? ' plass · ' : ' plasser · ')"));

echo "\n== Paint on Pots settes opp for haand ==\n";
// Eieren, 2. september: «hvorfor vises paint on pots i kalenderen naar det
// ikke er kurs?», og «hvordan kan det vises bare paint on pots i kalendern».
// Deretter: «jeg vil ikke at kurset skal foelge automatisk, kan du slette det
// og gjore det saa jeg maa legge ut tid selv?»
//
// Kurset sto med folger_apningstid = 1, og da satte utleggingen oektene opp
// selv — halvannen time om gangen gjennom hele den aapne tida. Én aapen dag
// ga fire til seks linjer i kalenderen, mot én for et ekte kurs.
$m135 = file_get_contents(dirname(__DIR__) . '/db/migrations/135_paint_on_pots_settes_opp_for_haand.sql');

// Utleggingen henter «WHERE folger_apningstid = 1». Nullen tar kurset ut av
// den, og ingen nye oekter lages.
sjekk('migrasjon 135 slaar av automatikken paa Paint on Pots',
    preg_match("~UPDATE courses\s+SET folger_apningstid = 0\s+WHERE tittel = 'Paint on Pots'~", $m135) === 1);
// Bare de genererte. En oekt satt opp for haand er noen sin avgjorelse.
sjekk('… og rydder bare de genererte lukene',
    str_contains($m135, 'AND cs.fra_apningstid = 1'));
// En plass noen har kjopt skal ikke forsvinne under foettene paa dem.
sjekk('… og lar en luke noen har booket staa',
    str_contains($m135, 'SELECT 1 FROM bookings b')
    && str_contains($m135, "AND b.status <> 'avbestilt'"));
// Det som har vaert og gaatt er historikk.
sjekk('… og roerer ikke det som er over',
    str_contains($m135, 'AND COALESCE(cs.slutt_tid, cs.start_tid) > UTC_TIMESTAMP()'));

// Og i virkeligheten: staar flagget av, skal utleggingen ikke lage noe.
if (DB::harKolonne('courses', 'folger_apningstid')) {
    $pop = DB::en("SELECT id, folger_apningstid FROM courses WHERE tittel = 'Paint on Pots'");
    if ($pop !== null) {
        sjekk('Paint on Pots foelger ikke lenger aapningstidene',
            (int) $pop['folger_apningstid'] === 0);
        sjekk('… og har ingen genererte luker igjen framover',
            (int) DB::verdi(
                'SELECT COUNT(*) FROM course_sessions
                  WHERE course_id = :i AND fra_apningstid = 1
                    AND COALESCE(slutt_tid, start_tid) > UTC_TIMESTAMP()',
                ['i' => (int) $pop['id']]
            ) === 0);
        // Uten dette kunne cron lagt dem ut igjen neste natt, og eieren
        // staatt med den samme kalenderen om et doegn.
        $forOkter = (int) DB::verdi('SELECT COUNT(*) FROM course_sessions WHERE course_id = :i',
            ['i' => (int) $pop['id']]);
        Apent::leggUtPaaApneTider();
        sjekk('… og utleggingen lager ingen nye',
            (int) DB::verdi('SELECT COUNT(*) FROM course_sessions WHERE course_id = :i',
                ['i' => (int) $pop['id']]) === $forOkter);
    }
}

echo "\n== Kvitteringen ser paa haken ==\n";
// Eieren, 2. september, etter aa ha huket av «Vis kurset paa nettsiden ogsaa
// uten datoer»: «Huket av paa at det skal vises uten datoer som du ser, men
// fikk beskjed om at det ikke vises uten datoer».
//
// Kurset LAA ute — utenDatoOk var satt, og kortet sto under Events. Det var
// kvitteringen som sa noe annet enn det som var sant, og han trodde derfor
// at haken ikke virket.
//
// Beskjeden ble regnet av datoene alene:
//
//     kvittering: framover === 0 ? '... vises ikke ute' : '... er lagret'
//
// «framover» er datoer som ligger foran oss. Haken var aldri med i
// regnestykket, saa den kunne ikke gjore beskjeden riktig.
sjekk('«vises ikke ute» sier ikke fra naar haken er paa',
    str_contains($sida, "kvittering: (framover === 0 && !\$rad->utenDato)")
    || str_contains($sida, 'kvittering: (framover === 0 && !rad.utenDato)'));
// Og det motsatte: staar haken av og kurset mangler datoer, skal advarselen
// staa som for. Det var den som gjorde at et kurs ikke forsvant i stillhet.
sjekk('… men staar der fortsatt naar haken er av',
    str_contains($sida, "? rad.navn + ' er lagret, men vises ikke ute'"));
// Detaljlinja skal si hva som faktisk vises, ikke bare at det gikk bra.
sjekk('… og detaljen sier at kortet staar med «Kontakt oss»',
    str_contains($sida, "? 'Kurset står på nettsiden med «Kontakt oss», siden det ikke har noen dato framover.'"));
// Regelen paa nettsida er den beskjeden skal stemme med: et kurs uten datoer
// vises naar haken er paa. Staar de to ulikt, lyver den ene.
sjekk('… og nettsida slipper gjennom et kurs uten datoer naar haken er paa',
    str_contains($sida, ".filter(k => (k.datoer || []).length > 0 || k.utenDatoOk)"));

echo "\n== PHP-en lar seg lese ==\n";
$rot = dirname(__DIR__);
$phpFiler = [];
foreach (['api', 'app', 'bin'] as $mappe) {
    $sti = $rot . '/' . $mappe;
    if (!is_dir($sti)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sti, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $phpFiler[] = $f->getPathname(); }
    }
}
sort($phpFiler);
$ulesbare = [];
foreach ($phpFiler as $f) {
    $ut = [];
    $kode = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $ut, $kode);
    if ($kode !== 0) {
        $ulesbare[] = substr($f, strlen($rot) + 1) . ': ' . trim((string) ($ut[0] ?? 'ukjent feil'));
    }
}
sjekk('alle PHP-filene lar seg lese',
    $ulesbare === [],
    count($phpFiler) . ' filer' . ($ulesbare ? ' — ' . implode(' | ', array_slice($ulesbare, 0, 3)) : ''));
// En tom liste ville gitt gronn uten aa ha sjekket noe.
sjekk('… og det er faktisk filer aa sjekke', count($phpFiler) > 50, count($phpFiler) . ' filer');

echo "\n";
echo str_repeat('─', 46), "\n";
echo $ok, " av ", $ok + count($feil), " sjekker gikk gjennom\n";
if ($feil) { echo "\nFEIL:\n - ", implode("\n - ", $feil), "\n"; exit(1); }
