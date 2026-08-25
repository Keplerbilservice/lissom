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
sjekk('Paint on Pots har ordinaer pris', (int)$pop['pris_ore'] === 69000, $pop['pris_ore'] . ' ore');
$test = DB::en("SELECT status FROM courses WHERE slug='testkurs'");
sjekk('testkurset er ute av sirkulasjon', $test === null || $test['status'] === 'avlyst', $test['status'] ?? 'slettet');
$boller = DB::en("SELECT tema FROM courses WHERE slug='kurs-boller'");
sjekk('Kurs boller har tema Plateteknikk', $boller['tema'] === 'Plateteknikk', $boller['tema']);
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

$popKurs = DB::en("SELECT id, pris_ore FROM courses WHERE slug = 'paint-on-pots'");
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

echo "\n";
echo str_repeat('─', 46), "\n";
echo $ok, " av ", $ok + count($feil), " sjekker gikk gjennom\n";
if ($feil) { echo "\nFEIL:\n - ", implode("\n - ", $feil), "\n"; exit(1); }
