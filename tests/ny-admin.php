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
 * Kontrolløren og Codex, runde 2 (09.10.2026):
 *   D  Påminnelse for hånd har samme mottakere som cron (Paaminnelse): bare
 *      betalte, og 14-dagersregelen — ikke reserverte, ikke nylig påmeldte.
 *   K  Ingen dublett: «Ny dato på kurset» to ganger (etter hverandre og samtidig)
 *      gir én beskjed per påmelding og dato (varsel_utsendinger, migrasjon 270);
 *      en feil midt i køleggingen (påminnelse for hånd, cron og «Ny dato») ruller
 *      alt tilbake — ingenting i køen, datoen ikke merket — og neste forsøk
 *      sender én gang.
 *   M  Kontrolløren runde 3 (09.10.2026): påminnelsen merkes per påmelding
 *      («paaminnelse:<booking>»), så en påminnelse for hånd tidlig ikke stopper
 *      cron for dem som kom til etterpå; «Ny dato»-nøkkelen har fra-datoen med
 *      (A→B→C→B varsler hver gang); økta leses på nytt under låsen, og en annen
 *      dato enn admin så gir 409 (cron hopper over avlyste og flyttede); uten
 *      migrasjon 270 svarer «Ny dato» 503 og påminnelsen tar hele økta som før.
 *   N  Kontrolløren runde 4 (09.10.2026): ingen dobbel påminnelse etter
 *      migrasjon 270. (a) En økt med paaminnelse_sendt_at fra før og ingen
 *      nøkler regnes som sendt: cron og for hånd sender ikke på nytt til dem
 *      som var påmeldt; (b) utfyllingen i 270 gir nøkler og tåler to kjøringer;
 *      (c) nøkkelen har økta med («paaminnelse:<booking>:<økt>»), så en
 *      påmelding flyttet til ny økt får påminnelsen for den nye; (d) en ny
 *      påmeldt etter sendingen får den.
 *   L  «Ny dato på kurset» tilbys ikke som SMS (pamelding.php sender bare e-post).
 *   J  Betaling i ny admin: kalender.php gir betaltOre (delbetalt) og
 *      vippsPaaVei; «Ikke betalt» (oversikt.php) har restOre; ny-admin bruker dem,
 *      stopper Ta betalt når Paint on Pots-oppsettet mangler eller Vipps pågår,
 *      og tilbyr bare Booking::MAATER (ikke Faktura). Rediger påmelding sender
 *      bare feltet som gjelder (rabatt eller beløp), og Flytt sjekker
 *      flerdagerskurs på serverens tall (antSamlinger).
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
    try { DB::kobling()->exec('DROP TRIGGER IF EXISTS nyadmin_testfeil'); } catch (Throwable $e) {}
    // M: tabellen ble lånt bort for «uten migrasjon 270» — legg den tilbake.
    try {
        $finnes = static fn(string $t): bool => DB::verdi('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', ['t' => $t]) !== null;
        if (!$finnes('varsel_utsendinger') && $finnes('varsel_utsendinger_nyadmtest')) {
            DB::kobling()->exec('RENAME TABLE varsel_utsendinger_nyadmtest TO varsel_utsendinger');
        }
    } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    try {
        foreach (explode(',', $b) as $bid) {
            DB::kjor('DELETE FROM varsel_utsendinger WHERE nokkel LIKE :n OR nokkel LIKE :p',
                ['n' => 'flyttet:' . (int) $bid . ':%', 'p' => 'paaminnelse:' . (int) $bid . ':%']);
        }
    } catch (Throwable $e) {}
    foreach ([
        "UPDATE bookings SET payment_id = NULL WHERE id IN ($b)",
        "DELETE FROM payments WHERE booking_id IN ($b)",
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
// Datoen admin ser (norsk tid, «Y-m-d H:i»), slik ny-admin sender den som «forventet».
$forv = static fn(int $o): string => (new DateTimeImmutable((string) DB::verdi('SELECT start_tid FROM course_sessions WHERE id = :i', ['i' => $o]), new DateTimeZone('UTC')))
    ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d H:i');
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

    // ── D Påminnelse for hånd: samme mottakere som cron ─────────────────
    // Bare betalte, og bare den som meldte seg på for 14 dager siden eller mer (bin/cron.php, eieren 25.09).
    $gammel = gmdate('Y-m-d H:i:s', time() - 20 * 86400);
    DB::kjor("UPDATE bookings SET created_at = :c WHERE id IN ($bBetalt, $bRes, $bIngen)", ['c' => $gammel]);
    $bNy = $nyBooking($k, $okt, 'Nylig', 'betalt', ['created_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
    $malPaa('kurspaaminnelse', true, 'epost');
    $f = kall('/api/admin/okt-varsel.php', ['handling' => 'forhandsvis', 'oktId' => $okt, 'mal' => 'kurspaaminnelse'], $tA);
    sjekk('D forhåndsvisning: 2 får den (betalt, påmeldt ≥ 14 dager), 1 nås, 1 e-post, 0 SMS', $f[0] === 200 && ($f[1]['antall'] ?? 0) === 2 && ($f[1]['naas'] ?? 0) === 1
        && ($f[1]['epost'] ?? 0) === 1 && ($f[1]['sms'] ?? -1) === 0, $vis($f));
    sjekk('D forhåndsvisning: reservert og nylig påmeldt er ikke med (ikkeMed = 2)', ($f[1]['ikkeMed'] ?? null) === 2, $vis($f));
    sjekk('D samme utvalg som cron: Paaminnelse::mottakere', count(Paaminnelse::mottakere($okt)) === ($f[1]['antall'] ?? -1));
    sjekk('D forhåndsvisning: «ikke nådd» = den uten kontakt', ($f[1]['ikkeNaadd'] ?? null) === [$tag . ' Ingenkontakt'], $vis($f));
    sjekk('D forhåndsvisning: ikke sendt ennå', ($f[1]['paaminnelseSendt'] ?? 'x') === '', $vis($f));
    $foer = $ko('course_session', $okt);
    $r1 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $okt, 'forventet' => $forv($okt)], $tA);
    sjekk('D første påminnelse: sendt til 1 (den betalte)', $r1[0] === 200 && ($r1[1]['sendt'] ?? 0) === 1, $vis($r1));
    sjekk('D teller bare faktisk køede: 1 ny i køen', $ko('course_session', $okt) - $foer === 1);
    $tilEpost = array_column(DB::alle("SELECT mottaker FROM notifications WHERE ref_type = 'course_session' AND ref_id = :i AND mal = 'kurspaaminnelse'", ['i' => $okt]), 'mottaker');
    sjekk('D ikke til reservert eller nylig påmeldt', $tilEpost === [strtolower($tag) . '-betalt@example.com'], json_encode($tilEpost));
    $satt = DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $okt]);
    sjekk('D paaminnelse_sendt_at er satt', $satt !== null);
    $r2 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $okt, 'forventet' => $forv($okt)], $tA);
    sjekk('D andre gang: 409 «alt sendt»', $r2[0] === 409 && str_contains((string) ($r2[1]['feil'] ?? ''), 'alt sendt'), $vis($r2));
    sjekk('D andre gang: ingen nye i køen', $ko('course_session', $okt) - $foer === 1);
    $f2 = kall('/api/admin/okt-varsel.php', ['handling' => 'forhandsvis', 'oktId' => $okt, 'mal' => 'kurspaaminnelse'], $tA);
    sjekk('D forhåndsvisningen viser «Påminnelse sendt <tid>»', ($f2[1]['paaminnelseSendt'] ?? '') !== '', $vis($f2));
    // Samtidig: to forespørsler på en ny dato gir bare én utsending.
    $okt2 = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+4 days 18:00'), 'status' => 'planlagt']);
    $nyBooking($k, $okt2, 'Andre', 'betalt', ['created_at' => $gammel]);
    $mh = curl_multi_init(); $hs = [];
    foreach ([0, 1] as $_) {
        $c = curl_init('http://127.0.0.1:' . $port . '/api/admin/okt-varsel.php');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['handling' => 'paaminnelse', 'oktId' => $okt2, 'forventet' => $forv($okt2)]),
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
    $r3 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $okt, 'forventet' => $forv($okt)], $tA);
    sjekk('D malen av: 409, ikke «sendt»', $r3[0] === 409, $vis($r3));
    sjekk('D malen av: paaminnelse_sendt_at står tomt', DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $okt]) === null);
    sjekk('D malen av: ingenting i køen', $ko('course_session', $okt) === $foer);
    $malPaa('kurspaaminnelse', true, 'epost');
    // Ingen å nå: feltet slippes igjen.
    $okt3 = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+5 days 18:00'), 'status' => 'planlagt']);
    $nyBooking($k, $okt3, 'Ukjent', 'betalt', ['gjest_epost' => null, 'created_at' => $gammel]);
    $r4 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $okt3, 'forventet' => $forv($okt3)], $tA);
    sjekk('D ingen å nå: 409', $r4[0] === 409, $vis($r4));
    sjekk('D ingen å nå: paaminnelse_sendt_at står tomt', DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $okt3]) === null);

    // ── E Ny dato på kurset ─────────────────────────────────────────────
    $malPaa('pamelding_flyttet', false);
    $e1 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $okt, 'fra' => '2026-10-20 18:00', 'forventet' => $forv($okt)], $tA);
    sjekk('E «Ny dato» med malen av: 409, ikke «sendt=3»', $e1[0] === 409, $vis($e1));
    $malPaa('pamelding_flyttet', true, 'epost');
    $flyttKo = static fn(): int => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'pamelding_flyttet' AND ref_id IN ($bBetalt, $bRes, $bUtgaatt, $bIngen, $bNy)");
    $foer = $flyttKo();
    $e2 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $okt, 'fra' => '2026-10-20 18:00', 'forventet' => $forv($okt)], $tA);
    $etter = $flyttKo();
    sjekk('E «Ny dato»: sendt = 3 = det som kom i køen (betalt, reservert, nylig)', $e2[0] === 200 && ($e2[1]['sendt'] ?? 0) === 3 && $etter - $foer === 3, $vis($e2) . " kø=" . ($etter - $foer));
    sjekk('E «Ny dato»: ikke den utgåtte reservasjonen', (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'pamelding_flyttet' AND ref_id = $bUtgaatt") === 0);
    sjekk('E «Ny dato»: «ikke nådd» = den uten kontakt', ($e2[1]['ikkeNaadd'] ?? null) === [$tag . ' Ingenkontakt'], $vis($e2));

    // ── K Ingen dublett ved to kall, samtidige kall eller feil midt i ───
    sjekk('K migrasjon 270 er kjørt i testbasen (varsel_utsendinger)', DB::harTabell('varsel_utsendinger'));
    $e3 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $okt, 'fra' => '2026-10-20 18:00', 'forventet' => $forv($okt)], $tA);
    sjekk('K «Ny dato» en gang til: sendt 0, alleredeSendt 3', $e3[0] === 200 && ($e3[1]['sendt'] ?? -1) === 0 && ($e3[1]['alleredeSendt'] ?? 0) === 3, $vis($e3));
    sjekk('K «Ny dato» en gang til: ingen nye i køen', $flyttKo() === $etter, (string) ($flyttKo() - $etter));
    // Ny dato (datoen flyttet igjen): da er det en ny beskjed.
    DB::oppdater('course_sessions', ['start_tid' => $tid('+3 days 19:00')], ['id' => $okt]);
    $e4 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $okt, 'fra' => '2026-10-20 18:00', 'forventet' => $forv($okt)], $tA);
    sjekk('K ny dato igjen: ny beskjed til 3', ($e4[1]['sendt'] ?? 0) === 3 && $flyttKo() === $etter + 3, $vis($e4));
    // To faner samtidig på en ny dato: én beskjed per påmelding.
    DB::oppdater('course_sessions', ['start_tid' => $tid('+3 days 20:00')], ['id' => $okt]);
    $foerSamtidig = $flyttKo();
    $mh = curl_multi_init(); $hs = [];
    foreach ([0, 1] as $_) {
        $c = curl_init('http://127.0.0.1:' . $port . '/api/admin/okt-varsel.php');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['handling' => 'flyttet', 'oktId' => $okt, 'fra' => '2026-10-20 18:00', 'forventet' => $forv($okt)]),
            CURLOPT_HTTPHEADER => ['Origin: ' . Config::nettsted(), 'Cookie: lissom_sesjon=' . $tA, 'Content-Type: application/json']]);
        curl_multi_add_handle($mh, $c); $hs[] = $c;
    }
    do { curl_multi_exec($mh, $aktiv); if ($aktiv) { curl_multi_select($mh, 0.05); } } while ($aktiv);
    foreach ($hs as $c) { curl_multi_remove_handle($mh, $c); curl_close($c); }
    curl_multi_close($mh);
    sjekk('K to faner samtidig: 3 i køen, ikke 6', $flyttKo() - $foerSamtidig === 3, (string) ($flyttKo() - $foerSamtidig));

    // Feil midt i køleggingen: en trigger avviser meldingen til én mottaker.
    $feilMottaker = strtolower($tag) . '-feil@example.com';
    $harTrigger = true;
    try {
        DB::kobling()->exec("CREATE TRIGGER nyadmin_testfeil BEFORE INSERT ON notifications FOR EACH ROW
            BEGIN IF NEW.mottaker = " . DB::kobling()->quote($feilMottaker) . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'testfeil'; END IF; END");
    } catch (Throwable $e) { $harTrigger = false; echo '  HOPPET  K feil midt i (kan ikke lage trigger: ' . $e->getMessage() . ")\n"; }
    if ($harTrigger) {
        // Påminnelse for hånd: første mottaker går i køen, andre feiler → alt rulles tilbake.
        $okt4 = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+6 days 18:00'), 'status' => 'planlagt']);
        $nyBooking($k, $okt4, 'Forste', 'betalt', ['created_at' => $gammel]);
        $nyBooking($k, $okt4, 'Feil', 'betalt', ['created_at' => $gammel]);
        $p1 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $okt4, 'forventet' => $forv($okt4)], $tA);
        sjekk('K påminnelse, feil midt i: ikke 200', $p1[0] >= 500, $vis($p1));
        sjekk('K påminnelse, feil midt i: ingenting i køen', $ko('course_session', $okt4) === 0, (string) $ko('course_session', $okt4));
        sjekk('K påminnelse, feil midt i: datoen er ikke merket sendt', DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $okt4]) === null);
        // Cron-veien (samme kode): ruller tilbake, prøver igjen neste gang.
        $oktRad = DB::en('SELECT cs.id, cs.start_tid, cs.slutt_tid, c.tittel, c.sms_paaminnelse FROM course_sessions cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :i', ['i' => $okt4]);
        $kastet = false;
        try { Paaminnelse::send($oktRad, false); } catch (Throwable $e) { $kastet = true; }
        sjekk('K cron, feil midt i: kaster, ingenting i køen, ikke merket', $kastet && $ko('course_session', $okt4) === 0
            && DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $okt4]) === null);
        // «Ny dato», feil midt i: ingen nøkler og ingen meldinger igjen.
        $okt5 = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+7 days 18:00'), 'status' => 'planlagt']);
        $f1 = $nyBooking($k, $okt5, 'Forste', 'betalt');
        $f2 = $nyBooking($k, $okt5, 'Feil', 'betalt');
        $flytt5 = static fn(): int => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'pamelding_flyttet' AND ref_id IN ($f1, $f2)");
        $n1 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $okt5, 'fra' => '2026-10-20 18:00', 'forventet' => $forv($okt5)], $tA);
        sjekk('K «Ny dato», feil midt i: ikke 200, ingenting i køen, ingen nøkler', $n1[0] >= 500 && $flytt5() === 0
            && (int) DB::verdi('SELECT COUNT(*) FROM varsel_utsendinger WHERE nokkel LIKE :a OR nokkel LIKE :b', ['a' => "flyttet:$f1:%", 'b' => "flyttet:$f2:%"]) === 0, $vis($n1));
        DB::kobling()->exec('DROP TRIGGER IF EXISTS nyadmin_testfeil');
        $p2 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $okt4, 'forventet' => $forv($okt4)], $tA);
        sjekk('K påminnelse etter feilen: sendt til 2, 2 i køen', ($p2[1]['sendt'] ?? 0) === 2 && $ko('course_session', $okt4) === 2, $vis($p2));
        $n2 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $okt5, 'fra' => '2026-10-20 18:00', 'forventet' => $forv($okt5)], $tA);
        $n3 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $okt5, 'fra' => '2026-10-20 18:00', 'forventet' => $forv($okt5)], $tA);
        sjekk('K «Ny dato» etter feilen: 2, og et nytt forsøk gir ingen dublett', ($n2[1]['sendt'] ?? 0) === 2 && ($n3[1]['sendt'] ?? -1) === 0 && $flytt5() === 2, $vis($n2) . ' / ' . $vis($n3));
    }
    sjekk('K cron bruker den felles utsendingen', str_contains((string) file_get_contents($rot . '/bin/cron.php'), '$r = Paaminnelse::send($okt, false);')
        && !str_contains((string) file_get_contents($rot . '/bin/cron.php'), "AND b.created_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)"));

    // ── L «Ny dato på kurset» bare på e-post ────────────────────────────
    $gL = kall('/api/admin/meldinger.php', null, $tA);
    sjekk('L meldinger.php: «Ny dato på kurset» bare e-post', ($gL[1]['kanaler']['pamelding_flyttet'] ?? null) === ['epost'], $vis($gL));
    $gL2 = kall('/api/admin/meldinger.php', ['handling' => 'kanal', 'navn' => 'pamelding_flyttet', 'sms' => true, 'epost' => true], $tA);
    sjekk('L SMS på «Ny dato på kurset» avvises', $gL2[0] === 400, $vis($gL2));
    sjekk('L okt-varsel.php sender «Ny dato» uten telefon', str_contains((string) file_get_contents($rot . '/api/admin/okt-varsel.php'), "        'telefon' => null,"));

    // ── J Betaling i ny admin ───────────────────────────────────────────
    $oktJ = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+8 days 18:00'), 'status' => 'planlagt']);
    $bDel = $nyBooking($k, $oktJ, 'Delbetalt', 'reservert', ['reservert_til' => null, 'lagt_inn_av' => $admin]);
    DB::settInn('payments', ['vipps_reference' => 'NYADM-' . bin2hex(random_bytes(6)), 'formal' => 'booking', 'belop_ore' => 20000, 'status' => 'betalt',
        'idempotency_key' => sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6))),
        'booking_id' => $bDel, 'maate' => 'Kontant']);
    $bVipps = $nyBooking($k, $oktJ, 'Vippsvent', 'reservert', ['reservert_til' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $pV = DB::settInn('payments', ['vipps_reference' => 'NYADM-' . bin2hex(random_bytes(6)), 'formal' => 'booking', 'belop_ore' => 50000, 'status' => 'opprettet',
        'idempotency_key' => sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6))),
        'booking_id' => $bVipps]);
    DB::oppdater('bookings', ['payment_id' => $pV], ['id' => $bVipps]);
    $datoJ = (new DateTimeImmutable($tid('+8 days 18:00'), $utc))->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d');
    $kal = kall('/api/admin/kalender.php?fra=' . $datoJ . '&til=' . $datoJ, null, $tA);
    $hJ = null;
    foreach (($kal[1]['hendelser'] ?? []) as $hh) { if (($hh['oktId'] ?? 0) === $oktJ) { $hJ = $hh; } }
    $dJ = array_column($hJ['deltakere'] ?? [], null, 'bookingId');
    sjekk('J kalender.php: delbetalt har betaltOre 200 kr', ($dJ[$bDel]['betaltOre'] ?? null) === 20000, $vis($kal));
    sjekk('J kalender.php: Vipps pågår for den med opprettet betaling, ikke for den andre', ($dJ[$bVipps]['vippsPaaVei'] ?? null) === true && ($dJ[$bDel]['vippsPaaVei'] ?? null) === false);
    sjekk('J kalender.php: antSamlinger på økta (0 uten samlinger)', ($hJ['antSamlinger'] ?? null) === 0);
    $ov = kall('/api/admin/oversikt.php', null, $tA);
    $ubDel = null;
    foreach (($ov[1]['ubetalte'] ?? []) as $u) { if (($u['slag'] ?? '') === 'booking' && ($u['id'] ?? 0) === $bDel) { $ubDel = $u; } }
    sjekk('J «Ikke betalt»: delbetalt står med restOre 300 kr (500 − 200)', ($ubDel['restOre'] ?? null) === 30000 && ($ubDel['belopOre'] ?? null) === 50000, json_encode($ubDel));
    $js = static fn(string $f): string => (string) file_get_contents($rot . '/ny-admin/' . $f);
    sjekk('J topplinja og «Ikke betalt»-arket summerer det som gjenstår', str_contains($js('felles.js'), "const igjen = r => Number(typeof r.restOre === 'number' ? r.restOre : r.belopOre) || 0;")
        && str_contains($js('felles.js'), 'const ubSum = ub.reduce((s, r) => s + igjen(r), 0);') && str_contains($js('felles.js'), 'const sum = ub.reduce((s, r) => s + igjen(r), 0);'));
    sjekk('J Registrer betaling viser det som gjenstår', str_contains($js('kurs.js'), 'const rest = Math.max(0, (d.belopOre || 0) - (d.betaltOre || 0));'));
    sjekk('J Ta betalt stoppes når Paint on Pots-oppsettet mangler eller Vipps pågår', str_contains($js('kurs.js'), 'if (popUkjent()) return toast(POP_FEIL);')
        && str_contains($js('kurs.js'), 'if (d.vippsPaaVei) return toast(')
        && str_contains($js('kalender.js'), "catch { POP = {nivaer: [], depositumKurs: [], feil: true}; }"));
    // Betalingsmåtene i ny admin: bare Booking::MAATER, Gavekort (med kode) og «Ikke betalt».
    preg_match_all("/const (?:TB_)?MAATER = \[([^\]]*)\]/", $js('kurs.js'), $mm);
    $maater = [];
    foreach ($mm[1] as $l) { foreach (explode(',', $l) as $x) { $maater[] = trim($x, " '"); } }
    sjekk('J betalingsmåtene følger Booking::MAATER (ingen Faktura)', count($mm[1]) === 2 && !in_array('Faktura', $maater, true)
        && array_diff($maater, array_merge(Booking::MAATER, ['Gavekort', 'Ikke betalt'])) === [], json_encode($maater));
    sjekk('J Rediger påmelding sender bare feltet som gjelder', str_contains($js('kurs.js'), "if (RED.modus === 'belop') body.belop = bel;")
        && str_contains($js('kurs.js'), "else if (RED.modus === 'rabatt') body.rabatt = rab || '0';")
        && !str_contains($js('kurs.js'), "if (rab !== String(d.rabatt || '')) body.rabatt = rab || '0';"));
    sjekk('J Flytt sjekker flerdagerskurs på serverens tall, hentet før arket', str_contains($js('kalender.js'), 'const flerdager = h => (h.antSamlinger || 0) > 1')
        && str_contains($js('kalender.js'), 'for (const d of new Set(liste.map(h => h.dato))) await hentPeriode(d, d);'));

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

    // ── M Kontrolløren runde 3: per påmelding, fra-dato, låsen, uten 270 ──
    $mottakereAv = static fn(int $o): array => array_column(DB::alle("SELECT mottaker FROM notifications WHERE ref_type = 'course_session' AND ref_id = :i AND mal = 'kurspaaminnelse' ORDER BY id", ['i' => $o]), 'mottaker');
    $cronRad = static fn(int $o): array => DB::en('SELECT cs.id, cs.start_tid, cs.slutt_tid, c.tittel, c.sms_paaminnelse FROM course_sessions cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :i', ['i' => $o]);
    $malPaa('kurspaaminnelse', true, 'epost');
    // 1) Påminnelse for hånd tidlig, så en ny påmeldt: cron sender bare til den nye.
    $oktM = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+10 days 18:00'), 'status' => 'planlagt']);
    $m1 = $nyBooking($k, $oktM, 'Tidlig', 'betalt', ['created_at' => $gammel]);
    $pm1 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $oktM, 'forventet' => $forv($oktM)], $tA);
    sjekk('M for hånd tidlig: sendt til 1', $pm1[0] === 200 && ($pm1[1]['sendt'] ?? 0) === 1, $vis($pm1));
    sjekk('M for hånd: nøkkelen paaminnelse:<booking>:<økt> er satt', (int) DB::verdi('SELECT COUNT(*) FROM varsel_utsendinger WHERE nokkel = :n', ['n' => 'paaminnelse:' . $m1 . ':' . $oktM]) === 1);
    $m2 = $nyBooking($k, $oktM, 'Senere', 'betalt', ['created_at' => $gammel]);
    $fm = kall('/api/admin/okt-varsel.php', ['handling' => 'forhandsvis', 'oktId' => $oktM, 'mal' => 'kurspaaminnelse'], $tA);
    sjekk('M forhåndsvisning etterpå: 1 ny får den, 1 har alt fått den, kan sendes', ($fm[1]['antall'] ?? 0) === 1 && ($fm[1]['alleredeSendt'] ?? 0) === 1
        && ($fm[1]['kanSendes'] ?? false) === true && ($fm[1]['paaminnelseSendt'] ?? '') !== '', $vis($fm));
    $rc = Paaminnelse::send($cronRad($oktM), false);
    sjekk('M cron etter tidlig påminnelse for hånd: sendt til den nye (1)', $rc['sendt'] === 1, json_encode($rc));
    sjekk('M cron: den tidlige får ikke en til', $mottakereAv($oktM) === [strtolower($tag) . '-tidlig@example.com', strtolower($tag) . '-senere@example.com'], json_encode($mottakereAv($oktM)));
    $rc2 = Paaminnelse::send($cronRad($oktM), false);
    sjekk('M cron en gang til: ingen nye', $rc2['sendt'] === 0 && count($mottakereAv($oktM)) === 2, json_encode($rc2));
    $pm2 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $oktM, 'forventet' => $forv($oktM)], $tA);
    sjekk('M for hånd når alle har fått den: 409 «alt sendt»', $pm2[0] === 409 && str_contains((string) ($pm2[1]['feil'] ?? ''), 'alt sendt'), $vis($pm2));
    $fm2 = kall('/api/admin/okt-varsel.php', ['handling' => 'forhandsvis', 'oktId' => $oktM, 'mal' => 'kurspaaminnelse'], $tA);
    sjekk('M forhåndsvisning når alle har fått den: kan ikke sendes', ($fm2[1]['kanSendes'] ?? true) === false, $vis($fm2));
    sjekk('M cron velger økta selv om paaminnelse_sendt_at er satt (med nøklene)', str_contains((string) file_get_contents($rot . '/bin/cron.php'), "\$ikkeSendt = Paaminnelse::harNokler() ? '' : 'AND cs.paaminnelse_sendt_at IS NULL';"));

    // 2) A→B→C→B: hver flytting gir beskjed, også tilbake til B.
    $oktF = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+12 days 18:00'), 'status' => 'planlagt']);
    $fb = $nyBooking($k, $oktF, 'Flytter', 'betalt');
    $flyttN = static fn(): int => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'pamelding_flyttet' AND ref_id = $fb");
    $oslo = static fn(string $nar): string => (new DateTimeImmutable($nar, new DateTimeZone('Europe/Oslo')))->format('Y-m-d H:i');
    $A = $oslo('+12 days 18:00'); $B = $oslo('+13 days 18:00'); $C = $oslo('+14 days 18:00');
    $flytt = static function (string $fra, string $til) use ($oktF, $tid, $tA): array {
        DB::oppdater('course_sessions', ['start_tid' => $tid($til)], ['id' => $oktF]);
        return kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $oktF, 'fra' => $fra, 'forventet' => $til], $tA);
    };
    $ab = $flytt($A, $B); $bc = $flytt($B, $C); $cb = $flytt($C, $B);
    sjekk('M A→B→C→B: tre beskjeder (også den siste tilbake til B)', ($ab[1]['sendt'] ?? 0) === 1 && ($bc[1]['sendt'] ?? 0) === 1 && ($cb[1]['sendt'] ?? 0) === 1 && $flyttN() === 3,
        $vis($ab) . ' / ' . $vis($bc) . ' / ' . $vis($cb));
    $cb2 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $oktF, 'fra' => $C, 'forventet' => $B], $tA);
    sjekk('M C→B en gang til: ingen dublett', ($cb2[1]['sendt'] ?? -1) === 0 && ($cb2[1]['alleredeSendt'] ?? 0) === 1 && $flyttN() === 3, $vis($cb2));
    sjekk('M nøkkelen har fra-datoen: flyttet:<booking>:<fra>:<til>:epost', (int) DB::verdi('SELECT COUNT(*) FROM varsel_utsendinger WHERE nokkel LIKE :n', ['n' => "flyttet:$fb:%:%:epost"]) === 3);

    // 3) Under låsen: en annen dato enn admin så gir 409, og teksten bygges fra raden.
    $feilDato = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $oktF, 'fra' => $C, 'forventet' => $A], $tA);
    sjekk('M «Ny dato» med en annen dato enn admin så: 409, ingenting i køen', $feilDato[0] === 409 && $flyttN() === 3, $vis($feilDato));
    $oktD = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+15 days 18:00'), 'status' => 'planlagt']);
    $d1 = $nyBooking($k, $oktD, 'Datosjekk', 'betalt', ['created_at' => $gammel]);
    $sett = $forv($oktD);
    DB::oppdater('course_sessions', ['start_tid' => $tid('+16 days 18:00')], ['id' => $oktD]);
    $pd = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $oktD, 'forventet' => $sett], $tA);
    sjekk('M påminnelse med en annen dato enn admin så: 409, ingenting i køen, ingen nøkkel', $pd[0] === 409 && $ko('course_session', $oktD) === 0
        && (int) DB::verdi('SELECT COUNT(*) FROM varsel_utsendinger WHERE nokkel = :n', ['n' => 'paaminnelse:' . $d1 . ':' . $oktD]) === 0, $vis($pd));
    $pu = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $oktD], $tA);
    sjekk('M påminnelse uten forventet dato: 409', $pu[0] === 409 && $ko('course_session', $oktD) === 0, $vis($pu));
    // Cron: utvalget gjort, så flyttet eller avlyst → hoppes over.
    $valgt = $cronRad($oktD);
    DB::oppdater('course_sessions', ['start_tid' => $tid('+17 days 18:00')], ['id' => $oktD]);
    $rf = Paaminnelse::send($valgt, false);
    sjekk('M cron hopper over en økt som er flyttet etter utvalget', $rf['grunn'] === 'dato' && $rf['sendt'] === 0 && $ko('course_session', $oktD) === 0, json_encode($rf));
    $valgt = $cronRad($oktD);
    DB::oppdater('course_sessions', ['status' => 'avlyst'], ['id' => $oktD]);
    $ra = Paaminnelse::send($valgt, false);
    sjekk('M cron hopper over en økt som er avlyst etter utvalget', $ra['grunn'] === 'avlyst' && $ra['sendt'] === 0 && $ko('course_session', $oktD) === 0, json_encode($ra));
    DB::oppdater('course_sessions', ['status' => 'planlagt'], ['id' => $oktD]);
    $gammelRad = $cronRad($oktD);
    $gammelRad['tittel'] = 'UTDATERT TITTEL';
    $rt = Paaminnelse::send($gammelRad, false);
    $tekstD = (string) DB::verdi("SELECT tekst FROM notifications WHERE ref_type = 'course_session' AND ref_id = :i ORDER BY id DESC LIMIT 1", ['i' => $oktD]);
    sjekk('M teksten bygges fra raden under låsen (ikke den utvalget leste)', $rt['sendt'] === 1 && !str_contains($tekstD, 'UTDATERT') && str_contains($tekstD, $tag . ' Dreiekurs'), mb_substr($tekstD, 0, 200));

    // 4) Uten migrasjon 270: «Ny dato» og påminnelse for hånd svarer 503 (cron virker som før).
    DB::kobling()->exec('RENAME TABLE varsel_utsendinger TO varsel_utsendinger_nyadmtest');
    try {
        $u1 = kall('/api/admin/okt-varsel.php', ['handling' => 'flyttet', 'oktId' => $oktF, 'fra' => $C, 'forventet' => $forv($oktF)], $tA);
        sjekk('M uten 270: «Ny dato» 503 «Kjør oppdateringene først», ingenting i køen', $u1[0] === 503 && str_contains((string) ($u1[1]['feil'] ?? ''), 'Kjør oppdateringene først') && $flyttN() === 3, $vis($u1));
        $oktU = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+18 days 18:00'), 'status' => 'planlagt']);
        $nyBooking($k, $oktU, 'Uten1', 'betalt', ['created_at' => $gammel]);
        $pu1 = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $oktU, 'forventet' => $forv($oktU)], $tA);
        sjekk('M uten 270: påminnelse for hånd sperret (503), økta ikke merket, ingenting i køen', $pu1[0] === 503
            && str_contains((string) ($pu1[1]['feil'] ?? ''), 'Kjør oppdateringene først')
            && DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $oktU]) === null
            && $ko('course_session', $oktU) === 0, $vis($pu1));
        $fu = kall('/api/admin/okt-varsel.php', ['handling' => 'forhandsvis', 'oktId' => $oktU, 'mal' => 'kurspaaminnelse'], $tA);
        sjekk('M uten 270: forhåndsvisningen kan ikke sendes', ($fu[1]['kanSendes'] ?? true) === false, $vis($fu));
    } finally {
        DB::kobling()->exec('RENAME TABLE varsel_utsendinger_nyadmtest TO varsel_utsendinger');
    }
    sjekk('M tabellen er tilbake', DB::verdi("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'varsel_utsendinger'") !== null);

    // ── N Kontrolløren runde 4: eldre sending, utfylling, flyttet påmelding ──
    $nokkelFinnes = static fn(int $b, int $o): bool => (int) DB::verdi('SELECT COUNT(*) FROM varsel_utsendinger WHERE nokkel = :n', ['n' => 'paaminnelse:' . $b . ':' . $o]) === 1;
    $for40 = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
    $for25 = gmdate('Y-m-d H:i:s', time() - 25 * 86400);
    // (a) Sendt før nøklene: paaminnelse_sendt_at satt, ingen nøkler.
    $oktL = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+19 days 18:00'), 'status' => 'planlagt', 'paaminnelse_sendt_at' => $for25]);
    $l1 = $nyBooking($k, $oktL, 'Eldre', 'betalt', ['created_at' => $for40]);
    $fl = kall('/api/admin/okt-varsel.php', ['handling' => 'forhandsvis', 'oktId' => $oktL, 'mal' => 'kurspaaminnelse'], $tA);
    sjekk('N(a) eldre sending: forhåndsvisningen sier alt sendt og kan ikke sendes', ($fl[1]['antall'] ?? -1) === 0 && ($fl[1]['alleredeSendt'] ?? 0) === 1
        && ($fl[1]['kanSendes'] ?? true) === false, $vis($fl));
    $pl = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $oktL, 'forventet' => $forv($oktL)], $tA);
    sjekk('N(a) eldre sending: for hånd gir 409 «alt sendt», ingenting i køen', $pl[0] === 409 && str_contains((string) ($pl[1]['feil'] ?? ''), 'alt sendt')
        && $ko('course_session', $oktL) === 0, $vis($pl));
    $rl = Paaminnelse::send($cronRad($oktL), false);
    sjekk('N(a) eldre sending: cron sender ikke på nytt', $rl['sendt'] === 0 && $ko('course_session', $oktL) === 0, json_encode($rl));
    sjekk('N(a) cron gjorde den eldre sendingen om til nøkkel', $nokkelFinnes($l1, $oktL));
    // (d) Ny påmeldt etter den eldre sendingen: får den, de gamle ikke.
    $nyBooking($k, $oktL, 'Etterpaa', 'betalt', ['created_at' => $gammel]);
    $fl2 = kall('/api/admin/okt-varsel.php', ['handling' => 'forhandsvis', 'oktId' => $oktL, 'mal' => 'kurspaaminnelse'], $tA);
    sjekk('N(d) forhåndsvisning: 1 ny får den, 1 har alt fått den', ($fl2[1]['antall'] ?? 0) === 1 && ($fl2[1]['alleredeSendt'] ?? 0) === 1 && ($fl2[1]['kanSendes'] ?? false) === true, $vis($fl2));
    $rl2 = Paaminnelse::send($cronRad($oktL), false);
    sjekk('N(d) cron: bare den nye påmeldte får påminnelsen', $rl2['sendt'] === 1 && $mottakereAv($oktL) === [strtolower($tag) . '-etterpaa@example.com'], json_encode($mottakereAv($oktL)));
    $rl3 = Paaminnelse::send($cronRad($oktL), false);
    sjekk('N(d) cron igjen: ingen nye (heller ikke den eldre)', $rl3['sendt'] === 0 && count($mottakereAv($oktL)) === 1, json_encode($rl3));
    // (a/d) Samme for hånd: eldre sending, så en ny påmeldt, for hånd først og cron etterpå.
    $oktH = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+20 days 18:00'), 'status' => 'planlagt', 'paaminnelse_sendt_at' => $for25]);
    $nyBooking($k, $oktH, 'Heldre', 'betalt', ['created_at' => $for40]);
    $nyBooking($k, $oktH, 'Hny', 'betalt', ['created_at' => $gammel]);
    $ph = kall('/api/admin/okt-varsel.php', ['handling' => 'paaminnelse', 'oktId' => $oktH, 'forventet' => $forv($oktH)], $tA);
    sjekk('N(a/d) for hånd etter eldre sending: bare den nye (1)', $ph[0] === 200 && ($ph[1]['sendt'] ?? 0) === 1
        && $mottakereAv($oktH) === [strtolower($tag) . '-hny@example.com'], $vis($ph) . ' ' . json_encode($mottakereAv($oktH)));
    $rh = Paaminnelse::send($cronRad($oktH), false);
    sjekk('N(a) cron etter for hånd: den eldre får ikke (nøkkelen til den nye åpner ikke for de gamle)', $rh['sendt'] === 0 && count($mottakereAv($oktH)) === 1, json_encode($rh));

    // (b) Utfyllingen i migrasjon 270, kjørt to ganger.
    $oktB = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+21 days 18:00'), 'status' => 'planlagt', 'paaminnelse_sendt_at' => $for25]);
    $b1 = $nyBooking($k, $oktB, 'Bfoer', 'betalt', ['created_at' => $for40]);
    $b2 = $nyBooking($k, $oktB, 'Better', 'betalt', ['created_at' => $gammel]);
    $mig = (string) file_get_contents($rot . '/db/migrations/270_varsel_utsendinger.sql');
    $migFeil = '';
    try { DB::kobling()->exec($mig); DB::kobling()->exec($mig); } catch (Throwable $e) { $migFeil = $e->getMessage(); }
    sjekk('N(b) migrasjon 270 tåler å kjøres to ganger', $migFeil === '', $migFeil);
    sjekk('N(b) utfyllingen gir nøkkel til den som var påmeldt da', $nokkelFinnes($b1, $oktB));
    sjekk('N(b) utfyllingen gir ikke nøkkel til den som kom etterpå', !$nokkelFinnes($b2, $oktB));
    $rb = Paaminnelse::send($cronRad($oktB), false);
    sjekk('N(b) cron etter utfyllingen: bare den som kom etterpå', $rb['sendt'] === 1 && $mottakereAv($oktB) === [strtolower($tag) . '-better@example.com'], json_encode($mottakereAv($oktB)));

    // (c) Påmelding flyttet til en ny økt får påminnelsen for den nye datoen.
    $oktC1 = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+22 days 18:00'), 'status' => 'planlagt']);
    $oktC2 = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => $tid('+23 days 18:00'), 'status' => 'planlagt']);
    $c1 = $nyBooking($k, $oktC1, 'Flyttes', 'betalt', ['created_at' => $gammel]);
    $rc1 = Paaminnelse::send($cronRad($oktC1), false);
    sjekk('N(c) påminnelse for første økt', $rc1['sendt'] === 1 && $nokkelFinnes($c1, $oktC1), json_encode($rc1));
    DB::oppdater('bookings', ['course_session_id' => $oktC2], ['id' => $c1]);
    $rc2n = Paaminnelse::send($cronRad($oktC2), false);
    sjekk('N(c) flyttet til ny økt: får påminnelsen for den nye datoen', $rc2n['sendt'] === 1 && $nokkelFinnes($c1, $oktC2)
        && $mottakereAv($oktC2) === [strtolower($tag) . '-flyttes@example.com'], json_encode($rc2n));
    $rc3 = Paaminnelse::send($cronRad($oktC2), false);
    sjekk('N(c) ny økt en gang til: ingen dublett', $rc3['sendt'] === 0 && count($mottakereAv($oktC2)) === 1, json_encode($rc3));

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

    // ── O Eieren 09.10.2026: Vedlikehold, logoen og «Rediger kurset» ────
    $mg = kall('/api/migrer.php', null, $tA);
    sjekk('O migrer.php svarer admin med listen som venter (Vedlikehold i ny admin)', $mg[0] === 200 && is_array($mg[1]['mangler'] ?? null), $vis($mg));
    $rot = dirname(__DIR__);
    $fil = static fn(string $f): string => (string) file_get_contents($rot . '/' . $f);
    sjekk('O Innstillinger › Oppdateringer og «Må gjøres» bruker NA.kjorOppdateringer (api/migrer.php kjor=ja), ikke under Mer',
        str_contains($fil('ny-admin/felles.js'), "api('/api/migrer.php', {data: {kjor: 'ja'}})")
        && str_contains($fil('ny-admin/innstillinger.js'), "case 'Oppdateringer': return fanOppdateringer();")
        && !str_contains($fil('ny-admin/mer.js'), "'Vedlikehold', 'Databaseoppdateringer'")
        && str_contains($fil('ny-admin/idag.js'), "knapp('Kjør oppdateringer', 'migrer', true)"));
    sjekk('O Lissom-logoen i menyen går til I dag', str_contains($fil('ny-admin/felles.js'), '<button class="brand" type="button" data-side="idag"'));
    sjekk('O «Rediger kurset»/«Legg til bilde» åpner kurset direkte i admin-ny (#kurs?kurs=<id>, &vis=bilde)',
        str_contains($fil('ny-admin/kurs.js'), "tilGammel(0, 'bilde')")
        && str_contains($fil('admin-ny/app.js'), "params().get('kurs')") && str_contains($fil('admin-ny/app.js'), "pv==='bilde'"));

    // ── P Eieren 09.10.2026: Uttak leire lik leira på Min side (bilde, navn, pris, − antall +) ──
    if (DB::harKolonne('products', 'leire')) {
        $leireP = DB::settInn('products', ['tittel' => $tag . ' Uttaksleire', 'pris_ore' => 29000, 'lager' => 3, 'status' => 'publisert',
            'kategori' => 'Materialer', 'kun_medlemmer' => 1, 'leire' => 1, 'bilde' => 'bilder/leire-test.jpg']);
        $lp = kall('/api/admin/produkter.php', null, $tA);
        $rad = null;
        foreach ($lp[1]['varer'] ?? [] as $v) { if ((int) $v['id'] === $leireP) { $rad = $v; } }
        sjekk('P produkter.php gir leira i internbutikken med bilde, pris og lager (det uttaket viser)', $lp[0] === 200 && $rad !== null
            && $rad['leire'] === true && $rad['kunMedlemmer'] === true && $rad['bilde'] === 'bilder/leire-test.jpg' && (int) $rad['lager'] === 3 && (int) $rad['pris'] === 290, $vis($lp));
        $tu = kall('/api/admin/produkter.php', ['handling' => 'taUt', 'id' => $leireP], $tA);
        sjekk('P Ta ut trekker én pose fra lageret (3 → 2)', $tu[0] === 200 && (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $leireP]) === 2, $vis($tu));
    }
    $fj = $fil('ny-admin/felles.js');
    $fc = $fil('ny-admin/ny-admin.css');
    sjekk('P Uttak leire tegner Min side-raden: bilde (background-image), navn, pris · lager og − antall + per leire, sortert på navn',
        str_contains($fj, '<div class="leirerad" data-vare=') && str_contains($fj, 'class="leire-bilde"') && str_contains($fj, 'background-image:url(')
        && str_contains($fj, "· \${Number(x.lager)} på lager") && str_contains($fj, 'class="leire-ant"') && str_contains($fj, "handling: 'taUt'")
        && str_contains($fj, "localeCompare(String(b.tittel), 'nb-NO')")
        && str_contains($fc, '.leire-bilde{width:52px;height:52px') && str_contains($fc, '.leire-ant button{'));

    $ferdig = true;
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $ferdig = true;
}

echo "\n  $ok OK, $feil feil\n";
exit($feil > 0 ? 1 : 0);
