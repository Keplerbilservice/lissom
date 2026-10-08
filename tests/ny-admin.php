<?php
/**
 * Den nye adminen (/ny-admin, eieren 08.10.2026), endepunktene og rettingene
 * etter den grundige sjekken (kontrolløren, Codex, testmesteren, designvokteren
 * og brukertesten 08.10.2026).
 *
 *   A  Tilgang: okt-varsel, pop-priser, meldinger og skoleferier krever admin
 *      (401 uten innlogging, 403 for et vanlig medlem).
 *   B  pop-priser.php svarer 200 med nivåene (regresjon: «Class Poppris not
 *      found» ga 500 på hver Kalender og Kurs).
 *   C  «Send beskjed»/avlysning til en dato (beskjed.php til=okt, medReserverte):
 *      mottakerne er de som står på lista (betalt + aktive reservasjoner,
 *      Booking::aktivSql), antall og utsending er like, og den som ikke nås
 *      står i «ikke_naadd». Uten feltet: bare betalte, som før. «Bare SMS»
 *      uten telefon gir ikke «sendt».
 *   D  Påminnelse for hånd (okt-varsel.php): forhåndsvisningen teller det som
 *      faktisk går ut, sperren er atomisk (andre gang 409, ingen nye
 *      meldinger i køen), «Påminnelse sendt <tid>» vises, og
 *      paaminnelse_sendt_at settes bare når noe faktisk ble lagt i kø (malen av
 *      eller ingen å nå: feltet står tomt, så cron kan sende sin).
 *   E  «Ny dato på kurset» (okt-varsel.php flyttet) teller bare det som ble lagt
 *      i kø, og malen av gir 409 i stedet for «sendt».
 *   F  Kursbevis med malen «Be om en anmeldelse» av gir tydelig feil (409), ikke
 *      «sendt».
 *   G  Innstillinger › Meldinger: bare kanalene malen kan sendes på
 *      (meldinger.php «kanaler»), og SMS kan ikke slås på for en ren e-postmal.
 *   H  Skoleferiene svarer (skoleferier.php).
 *   I  Varer: lageret lagres som differanse (produkter.php lagerEndring), så et
 *      salg imens arket står åpent ikke overskrives, og et navnebytte ikke rører
 *      lageret.
 *
 * Ekte endepunkter (php -S med tests/nettleser/ruter.php) mot en isolert
 * testbase. SMS er ikke satt opp og ingen cron kjører: ingenting går ut, og
 * køen ryddes etterpå.
 *   php tests/ny-admin.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$ledigPort = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
    return (int) substr($adr, strrpos($adr, ':') + 1);
};
$port = $ledigPort();
$logg = sys_get_temp_dir() . '/lissom-ny-admin-' . bin2hex(random_bytes(4)) . '.log';
$server = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

$tag = 'NYADM-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $kurs = []; $produkt = 0; $ferdig = false;
$malerFor = array_column(DB::alle("SELECT navn, aktiv, kanal FROM notification_templates WHERE navn IN ('kurspaaminnelse','pamelding_flyttet','anmeldelse')"), null, 'navn');

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, &$produkt, $server, $malerFor): void {
    if (is_resource($server)) { proc_terminate($server); }
    foreach ($malerFor as $n => $r) {
        try { DB::oppdater('notification_templates', ['aktiv' => (int) $r['aktiv'], 'kanal' => (string) $r['kanal']], ['navn' => $n]); } catch (Throwable $e) {}
    }
    $k = implode(',', array_map('intval', $kurs ?: [0]));
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $b = implode(',', array_map('intval', array_column(DB::alle("SELECT id FROM bookings WHERE course_id IN ($k)"), 'id')) ?: [0]);
    $s = implode(',', array_map('intval', array_column(DB::alle("SELECT id FROM course_sessions WHERE course_id IN ($k)"), 'id')) ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE ref_type IN ('booking') AND ref_id IN ($b)",
        "DELETE FROM notifications WHERE ref_type IN ('course_session', 'beskjed-okt') AND ref_id IN ($s)",
        "DELETE FROM bookings WHERE id IN ($b)",
        "DELETE FROM course_sessions WHERE course_id IN ($k)",
        "DELETE FROM courses WHERE id IN ($k)",
        'DELETE FROM products WHERE id = ' . (int) $produkt,
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m)",
        "DELETE FROM members WHERE id IN ($m)",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

/** @return array{0:int,1:mixed} */
function kall(string $sti, ?array $data, string $token = ''): array
{
    global $port;
    $c = curl_init('http://127.0.0.1:' . $port . $sti);
    $hode = ['Origin: ' . Config::nettsted()];
    if ($token !== '') { $hode[] = 'Cookie: lissom_sesjon=' . $token; }
    $valg = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60];
    if ($data !== null) {
        $hode[] = 'Content-Type: application/json';
        $valg[CURLOPT_POST] = true;
        $valg[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    $valg[CURLOPT_HTTPHEADER] = $hode;
    curl_setopt_array($c, $valg);
    $raa = (string) curl_exec($c);
    $kode = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$kode, json_decode($raa, true) ?? $raa];
}
$vis = static fn(array $s): string => $s[0] . ' ' . mb_substr(is_string($s[1]) ? $s[1] : json_encode($s[1], JSON_UNESCAPED_UNICODE), 0, 400);

$sesjon = static function (int $medlem): string {
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $medlem, 'token_hash' => hash('sha256', $t), 'maate' => 'passord',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    return $t;
};
$nyttMedlem = static function (array $felt) use (&$medlemmer, $tag): int {
    $id = DB::settInn('members', $felt + ['epost' => strtolower($tag) . '-' . bin2hex(random_bytes(3)) . '@example.com', 'status' => 'ingen']);
    $medlemmer[] = $id;
    return $id;
};
$utc = new DateTimeZone('UTC');
$tid = static fn(string $nar): string => (new DateTimeImmutable($nar, new DateTimeZone('Europe/Oslo')))->setTimezone($utc)->format('Y-m-d H:i:s');
$nyttKurs = static function () use (&$kurs, $tag): int {
    $id = DB::settInn('courses', ['slug' => strtolower($tag) . '-' . bin2hex(random_bytes(3)), 'tittel' => $tag . ' Dreiekurs',
        'type' => 'kurs', 'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
    $kurs[] = $id;
    return $id;
};
$nyBooking = static function (int $kursId, int $okt, string $navn, string $status, array $mer = []) use ($tag): int {
    return DB::settInn('bookings', $mer + ['course_id' => $kursId, 'course_session_id' => $okt, 'gjest_navn' => $tag . ' ' . $navn,
        'gjest_epost' => strtolower($tag) . '-' . strtolower($navn) . '@example.com', 'antall' => 1, 'belop_ore' => 50000,
        'status' => $status, 'betalt_maate' => $status === 'betalt' ? 'Kontant' : 'Ikke betalt']);
};
$ko = static fn(string $refType, int $refId): int => (int) DB::verdi(
    'SELECT COUNT(*) FROM notifications WHERE ref_type = :t AND ref_id = :i', ['t' => $refType, 'i' => $refId]);
$malPaa = static function (string $navn, bool $paa, ?string $kanal = null): void {
    DB::oppdater('notification_templates', ['aktiv' => $paa ? 1 : 0] + ($kanal !== null ? ['kanal' => $kanal] : []), ['navn' => $navn]);
};

try {
    sjekk('HTTP-serveren klar', $klar);
    sjekk('SMS er ikke satt opp i testen (ingenting kan gå ut)', !Varsel::smsMulig());

    $admin = $nyttMedlem(['navn' => $tag . ' Monica', 'rolle' => 'admin', 'brukernavn' => strtolower($tag) . '-admin']);
    $vanlig = $nyttMedlem(['navn' => $tag . ' Kari', 'rolle' => 'medlem', 'status' => 'aktiv']);
    $tA = $sesjon($admin);
    $tM = $sesjon($vanlig);

    // ── A Tilgang ───────────────────────────────────────────────────────
    foreach ([['/api/admin/pop-priser.php', null], ['/api/admin/skoleferier.php', null], ['/api/admin/meldinger.php', null],
              ['/api/admin/okt-varsel.php', ['handling' => 'forhandsvis', 'oktId' => 1, 'mal' => 'kurspaaminnelse']]] as [$sti, $data]) {
        $u = kall($sti, $data);
        sjekk("A $sti uten innlogging: 401", $u[0] === 401, $vis($u));
        $m = kall($sti, $data, $tM);
        // krev_admin() svarer 404 til et vanlig medlem (adminen skal ikke røpe at den finnes).
        sjekk("A $sti som vanlig medlem: avvist (403/404)", in_array($m[0], [403, 404], true), $vis($m));
    }

    // ── B pop-priser ────────────────────────────────────────────────────
    $p = kall('/api/admin/pop-priser.php', null, $tA);
    sjekk('B pop-priser.php svarer 200 (ikke «Class Poppris not found»)', $p[0] === 200, $vis($p));
    sjekk('B pop-priser.php har nivåene og depositumKurs', is_array($p[1]['nivaer'] ?? null) && is_array($p[1]['depositumKurs'] ?? null), $vis($p));
    sjekk('B nivåene er de samme som PopPris::nivaer()', count($p[1]['nivaer'] ?? []) === count(PopPris::nivaer()));

    // ── Testdata: én dato med betalt, aktiv reservasjon, utgått reservasjon og en uten kontakt ──
    $k = $nyttKurs();
    $okt = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+3 days 18:00'), 'status' => 'planlagt']);
    $bBetalt = $nyBooking($k, $okt, 'Betalt', 'betalt', ['gjest_telefon' => '+4790000001']);
    $bRes = $nyBooking($k, $okt, 'Reservert', 'reservert', ['reservert_til' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $bUtgaatt = $nyBooking($k, $okt, 'Utgaatt', 'reservert', ['reservert_til' => gmdate('Y-m-d H:i:s', time() - 3600)]);
    $bIngen = $nyBooking($k, $okt, 'Ingenkontakt', 'betalt', ['gjest_epost' => null]);

    // ── C Send beskjed / avlysning ──────────────────────────────────────
    $a = kall('/api/admin/beskjed.php', ['handling' => 'antall', 'til' => 'okt', 'oktId' => $okt, 'medReserverte' => 'ja'], $tA);
    sjekk('C antall med reserverte: 3 (betalt, aktiv reservasjon, uten kontakt; ikke den utgåtte)', ($a[1]['alle'] ?? null) === 3, $vis($a));
    sjekk('C «ikke nådd» på e-post: den uten kontakt', ($a[1]['utenEpost'] ?? null) === [$tag . ' Ingenkontakt'], $vis($a));
    $foer = $ko('beskjed-okt', $okt);
    $s = kall('/api/admin/beskjed.php', ['til' => 'okt', 'oktId' => $okt, 'tekst' => 'Kurset er avlyst. Hilsen Lissom', 'medReserverte' => 'ja', 'ogsaaSms' => 'nei'], $tA);
    sjekk('C beskjed til dato med reserverte: 2 e-post (betalt + reservert)', $s[0] === 200 && ($s[1]['epost'] ?? null) === 2, $vis($s));
    sjekk('C den uten kontakt står i ikke_naadd', ($s[1]['ikke_naadd'] ?? null) === [$tag . ' Ingenkontakt'], $vis($s));
    sjekk('C køen fikk akkurat 2 nye', $ko('beskjed-okt', $okt) - $foer === 2);
    $gml = kall('/api/admin/beskjed.php', ['handling' => 'antall', 'til' => 'okt', 'oktId' => $okt], $tA);
    sjekk('C uten medReserverte: bare betalte, som før (admin-ny)', ($gml[1]['alle'] ?? null) === 2, $vis($gml));
    $foer = $ko('beskjed-okt', $okt);
    $sms = kall('/api/admin/beskjed.php', ['til' => 'okt', 'oktId' => $okt, 'tekst' => 'Bare SMS-test', 'medReserverte' => 'ja', 'ogsaaSms' => 'ja', 'bareSms' => 'ja'], $tA);
    sjekk('C «bare SMS» uten SMS-oppsett: 409, ikke «sendt»', $sms[0] === 409, $vis($sms));
    sjekk('C «bare SMS»: ingenting i køen', $ko('beskjed-okt', $okt) === $foer);

    // ── D Påminnelse for hånd ───────────────────────────────────────────
    $malPaa('kurspaaminnelse', true, 'epost');
    $f = kall('/api/admin/okt-varsel.php', ['handling' => 'forhandsvis', 'oktId' => $okt, 'mal' => 'kurspaaminnelse'], $tA);
    sjekk('D forhåndsvisning: 3 på lista, 2 nås, 2 e-post, 0 SMS', $f[0] === 200 && ($f[1]['antall'] ?? 0) === 3 && ($f[1]['naas'] ?? 0) === 2
        && ($f[1]['epost'] ?? 0) === 2 && ($f[1]['sms'] ?? -1) === 0, $vis($f));
    sjekk('D forhåndsvisning: «ikke nådd» = den uten kontakt', ($f[1]['ikkeNaadd'] ?? null) === [$tag . ' Ingenkontakt'], $vis($f));
    sjekk('D forhåndsvisning: ikke sendt ennå', ($f[1]['paaminnelseSendt'] ?? 'x') === '', $vis($f));
    $foer = $ko('course_session', $okt);
    $r1 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $okt], $tA);
    sjekk('D første påminnelse: sendt til 2', $r1[0] === 200 && ($r1[1]['sendt'] ?? 0) === 2, $vis($r1));
    sjekk('D teller bare faktisk køede: 2 nye i køen', $ko('course_session', $okt) - $foer === 2);
    $satt = DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $okt]);
    sjekk('D paaminnelse_sendt_at er satt', $satt !== null);
    $r2 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $okt], $tA);
    sjekk('D andre gang: 409 «alt sendt»', $r2[0] === 409 && str_contains((string) ($r2[1]['feil'] ?? ''), 'alt sendt'), $vis($r2));
    sjekk('D andre gang: ingen nye i køen', $ko('course_session', $okt) - $foer === 2);
    $f2 = kall('/api/admin/okt-varsel.php', ['handling' => 'forhandsvis', 'oktId' => $okt, 'mal' => 'kurspaaminnelse'], $tA);
    sjekk('D forhåndsvisningen viser «Påminnelse sendt <tid>»', ($f2[1]['paaminnelseSendt'] ?? '') !== '', $vis($f2));
    // Samtidig: to forespørsler på en ny dato gir bare én utsending.
    $okt2 = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+4 days 18:00'), 'status' => 'planlagt']);
    $nyBooking($k, $okt2, 'Andre', 'betalt');
    $mh = curl_multi_init(); $hs = [];
    foreach ([0, 1] as $_) {
        $c = curl_init('http://127.0.0.1:' . $port . '/api/admin/okt-varsel.php');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['handling' => 'paaminnelse', 'oktId' => $okt2]),
            CURLOPT_HTTPHEADER => ['Origin: ' . Config::nettsted(), 'Cookie: lissom_sesjon=' . $tA, 'Content-Type: application/json']]);
        curl_multi_add_handle($mh, $c); $hs[] = $c;
    }
    do { curl_multi_exec($mh, $aktiv); if ($aktiv) { curl_multi_select($mh, 0.05); } } while ($aktiv);
    foreach ($hs as $c) { curl_multi_remove_handle($mh, $c); curl_close($c); }
    curl_multi_close($mh);
    sjekk('D to klikk samtidig: bare én påminnelse i køen', $ko('course_session', $okt2) === 1, (string) $ko('course_session', $okt2));
    // Malen av: ingenting går ut, og feltet står tomt (cron kan fortsatt sende).
    DB::oppdater('course_sessions', ['paaminnelse_sendt_at' => null], ['id' => $okt]);
    $malPaa('kurspaaminnelse', false);
    $foer = $ko('course_session', $okt);
    $r3 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $okt], $tA);
    sjekk('D malen av: 409, ikke «sendt»', $r3[0] === 409, $vis($r3));
    sjekk('D malen av: paaminnelse_sendt_at står tomt', DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $okt]) === null);
    sjekk('D malen av: ingenting i køen', $ko('course_session', $okt) === $foer);
    $malPaa('kurspaaminnelse', true, 'epost');
    // Ingen å nå: feltet slippes igjen.
    $okt3 = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+5 days 18:00'), 'status' => 'planlagt']);
    $nyBooking($k, $okt3, 'Ukjent', 'betalt', ['gjest_epost' => null]);
    $r4 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $okt3], $tA);
    sjekk('D ingen å nå: 409', $r4[0] === 409, $vis($r4));
    sjekk('D ingen å nå: paaminnelse_sendt_at står tomt', DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $okt3]) === null);

    // ── E Ny dato på kurset ─────────────────────────────────────────────
    $malPaa('pamelding_flyttet', false);
    $e1 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $okt, 'fra' => '2026-10-20 18:00'], $tA);
    sjekk('E «Ny dato» med malen av: 409, ikke «sendt=3»', $e1[0] === 409, $vis($e1));
    $malPaa('pamelding_flyttet', true, 'epost');
    $foer = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'pamelding_flyttet' AND ref_id IN ($bBetalt, $bRes, $bUtgaatt, $bIngen)");
    $e2 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $okt, 'fra' => '2026-10-20 18:00'], $tA);
    $etter = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'pamelding_flyttet' AND ref_id IN ($bBetalt, $bRes, $bUtgaatt, $bIngen)");
    sjekk('E «Ny dato»: sendt = 2 = det som kom i køen', $e2[0] === 200 && ($e2[1]['sendt'] ?? 0) === 2 && $etter - $foer === 2, $vis($e2) . " kø=" . ($etter - $foer));
    sjekk('E «Ny dato»: ikke den utgåtte reservasjonen', (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'pamelding_flyttet' AND ref_id = $bUtgaatt") === 0);
    sjekk('E «Ny dato»: «ikke nådd» = den uten kontakt', ($e2[1]['ikkeNaadd'] ?? null) === [$tag . ' Ingenkontakt'], $vis($e2));

    // ── F Kursbevis med malen av ────────────────────────────────────────
    $fortid = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('-2 days 18:00'), 'slutt_tid' => $tid('-2 days 21:00'), 'status' => 'planlagt']);
    $bBevis = $nyBooking($k, $fortid, 'Bevis', 'betalt');
    $malPaa('anmeldelse', false);
    $kb = kall('/api/admin/pamelding.php', ['handling' => 'kursbevis', 'id' => $bBevis], $tA);
    sjekk('F kursbevis med «Be om en anmeldelse» av: 409 med beskjed', $kb[0] === 409 && str_contains((string) ($kb[1]['feil'] ?? ''), 'slått av'), $vis($kb));
    $malPaa('anmeldelse', true);
    $kb2 = kall('/api/admin/pamelding.php', ['handling' => 'kursbevis', 'id' => $bBevis], $tA);
    sjekk('F kursbevis med malen på: sendt', $kb2[0] === 200, $vis($kb2));

    // ── G Meldinger: kanalene malen kan sendes på ───────────────────────
    $g = kall('/api/admin/meldinger.php', null, $tA);
    sjekk('G meldinger.php har «kanaler»', $g[0] === 200 && is_array($g[1]['kanaler'] ?? null), $vis($g));
    sjekk('G anmeldelse: bare e-post', ($g[1]['kanaler']['anmeldelse'] ?? null) === ['epost'], $vis($g));
    sjekk('G påminnelsen: e-post og SMS', ($g[1]['kanaler']['kurspaaminnelse'] ?? null) === ['epost', 'sms'], $vis($g));
    $g2 = kall('/api/admin/meldinger.php', ['handling' => 'kanal', 'navn' => 'anmeldelse', 'sms' => true, 'epost' => true], $tA);
    sjekk('G SMS på en ren e-postmal avvises', $g2[0] === 400, $vis($g2));
    sjekk('G malen står fortsatt på e-post', (string) DB::verdi("SELECT kanal FROM notification_templates WHERE navn = 'anmeldelse'") === (string) $malerFor['anmeldelse']['kanal']);
    $g3 = kall('/api/admin/meldinger.php', ['handling' => 'kanal', 'navn' => 'kurspaaminnelse', 'sms' => false, 'epost' => false], $tA);
    sjekk('G aldri en mal uten kanal', $g3[0] === 400, $vis($g3));

    // ── H Skoleferier ───────────────────────────────────────────────────
    $h = kall('/api/admin/skoleferier.php', null, $tA);
    sjekk('H skoleferier.php svarer med perioder', $h[0] === 200 && is_array($h[1]['perioder'] ?? null) && count($h[1]['perioder']) > 0, $vis($h));

    // ── I Varer: lageret som differanse ─────────────────────────────────
    $produkt = DB::settInn('products', ['tittel' => $tag . ' Leire', 'pris_ore' => 29000, 'lager' => 10, 'status' => 'publisert', 'kategori' => 'Materialer']);
    DB::oppdater('products', ['lager' => 8], ['id' => $produkt]); // to solgt mens arket sto åpent (lest 10)
    $v1 = kall('/api/admin/produkter.php', ['handling' => 'lagre', 'id' => $produkt, 'tittel' => $tag . ' Leire', 'pris' => 290, 'kategori' => 'Materialer',
        'lager' => '12', 'lagerEndring' => 2, 'status' => 'publisert', 'mva' => 25, 'kunMedlemmer' => 'nei', 'iNettbutikk' => 'ja'], $tA);
    sjekk('I +2 i arket etter et salg: 8 + 2 = 10 (ikke 12)', $v1[0] === 200 && (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $produkt]) === 10, $vis($v1));
    DB::oppdater('products', ['lager' => 7], ['id' => $produkt]);
    $v2 = kall('/api/admin/produkter.php', ['handling' => 'lagre', 'id' => $produkt, 'tittel' => $tag . ' Leire ny', 'pris' => 290, 'kategori' => 'Materialer',
        'lager' => '10', 'lagerEndring' => 0, 'status' => 'publisert', 'mva' => 25, 'kunMedlemmer' => 'nei', 'iNettbutikk' => 'ja'], $tA);
    sjekk('I bare navnet endret: lageret røres ikke (7)', $v2[0] === 200 && (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $produkt]) === 7, $vis($v2));

    $ferdig = true;
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $ferdig = true;
}

echo "\n  $ok OK, $feil feil\n";
exit($feil > 0 ? 1 : 0);
