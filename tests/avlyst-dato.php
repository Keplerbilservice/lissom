<?php
/**
 * Avlyst dato (eieren, GO 9. oktober 2026).
 *
 *   A  «Avlys dato» i /ny-admin: kurs.php avlys + okt-varsel.php avlyst.
 *      Malen «kurs_avlyst» (SMS + e-post), ordrett tekst, til betalt og aktive
 *      reservasjoner, én gang (varsel_utsendinger), forhåndsvisning = det som
 *      sendes, «ikke nådd», ingen beskjed før datoen er avlyst, av = ingen.
 *   B  Min side: plassen vises som avlyst med tekst og beløp, ingen
 *      avbestilling og intet kursbevis.
 *   C  Velg ny dato: bare samme kurs med plass; fullt avvises; bytt flytter
 *      påmeldingen uten ny betaling og uten ekstra kostnad (også når den nye
 *      datoen har annen pris); bare egne plasser; bare fra avlyst dato.
 *   D  Få pengene tilbake (api/avbestill.php), også under 2 dager:
 *      Vipps (falsk Vipps, én refusjon med riktig beløp), gavekort (tilbake
 *      på kortet), ikke betalt (bare frigjort), kontant (sak i Må gjøres,
 *      «Betalt tilbake» tar den bort). Ikke avlyst under 2 dager: ingenting.
 *   E  Dobbeltklikk på to servere samtidig: én refusjon, aldri dobbel.
 *   F  «Betalt tilbake» føres i kassa den dagen (eieren 9. oktober 2026):
 *      kontant trekkes fra kontant i kassa og dagsoppgjøret (egen linje,
 *      i balanse), annen måte som egen linje; dobbelttrykk gir én føring.
 *   H  Kontrolløren runde 3: annullering av kontantbetalingen i admin samtidig
 *      med kundens «Få pengene tilbake» gir aldri en sak uten innbetaling;
 *      «Betalt tilbake» avviser (409) når innbetalingen bak saken mangler.
 *   G  Kontrolløren: datoer avlyst før migrasjon 271 og gjenopprettede
 *      datoer oppfører seg som før; bytt + refusjon i to faner samtidig gir
 *      aldri full refusjon på en flyttet plass.
 *
 * Ekte endepunkter (to PHP-servere) mot en isolert testbase, og
 * tests/falsk-vipps.mjs som Vipps. Ingen ekte betaling, e-post eller SMS:
 * meldingene legges i køen, ingen cron kjører, og køen ryddes.
 *   php tests/avlyst-dato.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
$oppsett = krev_testdatabase($rot);

$ledigPort = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
    return (int) substr($adr, strrpos($adr, ':') + 1);
};
$nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$prosesser = [];

// Falsk Vipps (node), og miljøet som peker dit. Settes før bootstrap.
$vippsPort = $ledigPort();
$prosesser[] = proc_open(['node', $rot . '/tests/falsk-vipps.mjs'], [0 => ['pipe', 'r'], 1 => ['file', $nul, 'a'], 2 => ['file', $nul, 'a']],
    $vp, $rot, array_merge(getenv(), ['FALSK_VIPPS_PORT' => (string) $vippsPort]));
fclose($vp[0]);
for ($v = 0; $v < 80; $v++) { $f = @fsockopen('127.0.0.1', $vippsPort, $e1, $e2, 0.1); if ($f) { fclose($f); break; } usleep(50000); }
putenv('LISSOM_VIPPS_BASE=http://127.0.0.1:' . $vippsPort);

require $rot . '/app/bootstrap.php';
if (!str_starts_with(Config::vippsBase(), 'http://127.0.0.1:')) {
    fwrite(STDERR, "Vipps-adressen er ikke den falske. Stopper.\n");
    exit(1);
}

$tag = 'AVL-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $kurs = []; $kort = []; $ferdig = false;
$smsFor = DB::harTabell('innstillinger')
    ? array_column(DB::alle("SELECT nokkel, verdi FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')"), 'verdi', 'nokkel')
    : [];
$malFor = DB::en("SELECT aktiv, kanal FROM notification_templates WHERE navn = 'kurs_avlyst'");
$logg = sys_get_temp_dir() . '/lissom-avlyst-' . bin2hex(random_bytes(4)) . '.log';
$betalingFor = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM payments');
$vlogg = __DIR__ . '/.falsk-vipps.jsonl';
$vloggFor = is_file($vlogg) ? count(file($vlogg)) : 0;

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, &$kort, &$prosesser, $betalingFor, $smsFor, $malFor): void {
    foreach ($prosesser as $p) { if (is_resource($p)) { proc_terminate($p); } }
    try {
        if (DB::harTabell('innstillinger')) {
            DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')");
            foreach ($smsFor as $n => $v) { DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v)', ['n' => $n, 'v' => $v]); }
        }
        if ($malFor !== null) {
            DB::kjor("UPDATE notification_templates SET aktiv = :a, kanal = :k WHERE navn = 'kurs_avlyst'", ['a' => $malFor['aktiv'], 'k' => $malFor['kanal']]);
        }
    } catch (Throwable $e) {}
    $k = implode(',', array_map('intval', $kurs ?: [0]));
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $g = implode(',', array_map('intval', $kort ?: [0]));
    $bIder = array_map('intval', array_column(DB::alle("SELECT id FROM bookings WHERE course_id IN ($k)"), 'id'));
    $b = implode(',', $bIder ?: [0]);
    $oIder = array_map('intval', array_column(DB::alle("SELECT id FROM course_sessions WHERE course_id IN ($k)"), 'id'));
    $o = implode(',', $oIder ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id IN ($b)",
        "DELETE FROM notifications WHERE ref_type = 'payment' AND ref_id > " . $betalingFor,
        "DELETE FROM varsel_utsendinger WHERE nokkel REGEXP '^(avlyst|flyttet):(" . str_replace(',', '|', $b) . "):'",
        "DELETE FROM avlyst_tilbakebetal WHERE booking_id IN ($b)",
        "DELETE FROM payment_refunds WHERE payment_id > " . $betalingFor,
        "DELETE FROM gift_card_uses WHERE gift_card_id IN ($g)",
        "UPDATE bookings SET payment_id = NULL WHERE id IN ($b)",
        "DELETE FROM payments WHERE id > " . $betalingFor . " AND (booking_id IN ($b) OR member_id IN ($m) OR booking_id IS NULL)",
        "DELETE FROM gift_cards WHERE id IN ($g)",
        "DELETE FROM bookings WHERE id IN ($b)",
        "DELETE FROM waitlist WHERE course_id IN ($k)",
        "DELETE FROM course_sessions WHERE id IN ($o)",
        "DELETE FROM courses WHERE id IN ($k)",
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m)",
        "DELETE FROM rate_limits WHERE nokkel LIKE 'avbestill%' OR nokkel LIKE 'avlyst-plass%'",
        "DELETE FROM members WHERE id IN ($m)",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

// To servere mot samme base: én forespørsel hver gir ekte samtidighet (E).
$porter = [];
foreach ([0, 1] as $_) {
    $p = $ledigPort();
    $porter[] = $p;
    $prosesser[] = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $p, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
    fclose($pp[0]);
}
$klar = true;
foreach ($porter as $p) {
    $denne = false;
    for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.1); if ($f) { fclose($f); $denne = true; break; } usleep(50000); }
    $klar = $klar && $denne;
}
$port = $porter[0];

function forbered(string $sti, ?array $data, string $token, int $port): CurlHandle
{
    $c = curl_init('http://127.0.0.1:' . $port . $sti);
    $hode = ['Origin: ' . Config::nettsted()];
    if ($token !== '') { $hode[] = 'Cookie: lissom_sesjon=' . $token; }
    $valg = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30];
    if ($data !== null) {
        $hode[] = 'Content-Type: application/json';
        $valg[CURLOPT_POST] = true;
        $valg[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    $valg[CURLOPT_HTTPHEADER] = $hode;
    curl_setopt_array($c, $valg);
    return $c;
}
/** @return array{0:int,1:mixed} */
function kall(string $sti, ?array $data, string $token = ''): array
{
    global $port;
    $c = forbered($sti, $data, $token, $port);
    $raa = (string) curl_exec($c);
    $s = [(int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), json_decode($raa, true)];
    curl_close($c);
    return $s;
}
/** To kall samtidig, hvert til sin server. */
function samtidig(array $a, array $b, string $token, ?string $token2 = null): array
{
    global $porter;
    $m = curl_multi_init();
    $h = [forbered($a[0], $a[1], $token, $porter[0]), forbered($b[0], $b[1], $token2 ?? $token, $porter[1])];
    foreach ($h as $c) { curl_multi_add_handle($m, $c); }
    do { curl_multi_exec($m, $aktiv); if ($aktiv) { curl_multi_select($m, 0.05); } } while ($aktiv);
    $ut = [];
    foreach ($h as $c) { $ut[] = [(int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), json_decode((string) curl_multi_getcontent($c), true)]; curl_multi_remove_handle($m, $c); curl_close($c); }
    curl_multi_close($m);
    return $ut;
}
$tekst = static fn(array $s): string => $s[0] . ' ' . json_encode($s[1], JSON_UNESCAPED_UNICODE);
$sesjon = static function (int $medlem): string {
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $medlem, 'token_hash' => hash('sha256', $t), 'maate' => 'passord',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3 * 3600)]);
    return $t;
};
$nyttMedlem = static function (string $navn, array $mer = []) use (&$medlemmer, $tag): int {
    $id = DB::settInn('members', $mer + ['navn' => $navn . ' ' . $tag, 'epost' => strtolower($tag) . '-' . bin2hex(random_bytes(3)) . '@example.com',
        'telefon' => '4' . random_int(1000000, 9999999), 'status' => 'ingen', 'rolle' => 'medlem']);
    $medlemmer[] = $id;
    return $id;
};
/** Refusjonskallene til den falske Vipps for én referanse (etter at testen startet). */
$refusjoner = static function (string $ref) use ($vlogg, $vloggFor): array {
    if (!is_file($vlogg)) { return []; }
    $ut = [];
    foreach (array_slice(file($vlogg), $vloggFor) as $l) {
        $j = json_decode($l, true);
        $sti = (string) ($j['sti'] ?? $j['path'] ?? $j['url'] ?? '');
        if (str_contains($sti, '/payments/' . rawurlencode($ref) . '/refund')) {
            $ut[] = $j;
        }
    }
    return $ut;
};
$vippsBetaling = static function (int $mid, int $bid, int $ore) use ($tag): array {
    $ref = $tag . '-' . bin2hex(random_bytes(6));
    $pid = DB::settInn('payments', ['member_id' => $mid, 'booking_id' => $bid, 'vipps_reference' => $ref, 'type' => 'epayment',
        'formal' => 'booking', 'belop_ore' => $ore, 'status' => 'betalt', 'idempotency_key' => bin2hex(random_bytes(18))]);
    DB::oppdater('bookings', ['payment_id' => $pid, 'status' => 'betalt'], ['id' => $bid]);
    return [$pid, $ref];
};

try {
    sjekk('HTTP-serverne klare', $klar);
    sjekk('migrasjon 271 er kjørt (malen og avlyst_tilbakebetal)', $malFor !== null && DB::harTabell('avlyst_tilbakebetal'));
    DB::kjor("UPDATE notification_templates SET aktiv = 1, kanal = 'epost_sms' WHERE navn = 'kurs_avlyst'");
    // SMS i køen (ingen cron, ingen sending): satt bare for testen.
    foreach (['sveve_bruker' => 'test', 'sveve_passord' => 'test', 'sms_leverandor' => 'sveve'] as $n => $v) {
        DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)', ['n' => $n, 'v' => $v]);
    }

    // ── Testdata ───────────────────────────────────────────────────────
    $admin = $nyttMedlem('Admin', ['rolle' => 'admin']);
    $adminT = $sesjon($admin);
    $kid = DB::settInn('courses', ['slug' => strtolower($tag) . '-kurs', 'tittel' => 'Dreiekurs ' . $tag, 'type' => 'kurs',
        'pris_ore' => 50000, 'kapasitet' => 3, 'status' => 'publisert']);
    $kurs[] = $kid;
    $annet = DB::settInn('courses', ['slug' => strtolower($tag) . '-annet', 'tittel' => 'Annet ' . $tag, 'type' => 'kurs',
        'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
    $kurs[] = $annet;
    $oslo = new DateTimeZone('Europe/Oslo');
    $om = static fn(int $timer): string => gmdate('Y-m-d H:00:00', time() + $timer * 3600);
    // Avlyses: om 20 timer (under 2 dager). Kapasitet 10 så alle får plass.
    $sAvl = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(20), 'status' => 'planlagt', 'kapasitet' => 10]);
    // Ledig, med en annen pris (700 kr): byttet skal ikke koste noe.
    $sLedig = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(240), 'status' => 'planlagt', 'pris_ore' => 70000]);
    // Full: kapasitet 3 på kurset, 3 plasser betalt.
    $sFull = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(260), 'status' => 'planlagt']);
    // Avlyst fra før, og passert: aldri tilbudt.
    $sAvl2 = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(280), 'status' => 'avlyst']);
    $sForbi = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(-48), 'status' => 'planlagt']);
    // Et annet kurs med plass: aldri tilbudt.
    $sAnnet = DB::settInn('course_sessions', ['course_id' => $annet, 'start_tid' => $om(250), 'status' => 'planlagt']);
    // Ikke avlyst, om 20 timer: 2-dagersregelen gjelder fortsatt.
    $sSnart = DB::settInn('course_sessions', ['course_id' => $annet, 'start_tid' => $om(21), 'status' => 'planlagt']);
    DB::settInn('bookings', ['course_id' => $kid, 'course_session_id' => $sFull, 'gjest_navn' => 'Full ' . $tag,
        'gjest_epost' => strtolower($tag) . '-full@example.com', 'antall' => 3, 'belop_ore' => 150000, 'status' => 'betalt', 'betalt_maate' => 'Kontant']);

    $plass = static function (int $mid, int $okt, string $status = 'reservert', int $antall = 1) use ($kid): int {
        return DB::settInn('bookings', ['member_id' => $mid, 'course_id' => $kid, 'course_session_id' => $okt,
            'antall' => $antall, 'belop_ore' => 50000 * $antall, 'status' => $status]);
    };
    // Kari: Vipps. Gro: gavekort. Ida: ikke betalt. Kim: kontant. Bo: bytter. Dag: dobbeltklikk.
    $kari = $nyttMedlem('Kari Nordmann'); $kariT = $sesjon($kari);
    $bKari = $plass($kari, $sAvl); [$pKari, $refKari] = $vippsBetaling($kari, $bKari, 50000);
    $gro = $nyttMedlem('Gro'); $groT = $sesjon($gro);
    $bGro = $plass($gro, $sAvl);
    $gk = DB::settInn('gift_cards', ['kode' => $tag . '-' . strtoupper(bin2hex(random_bytes(3))), 'opprinnelig_ore' => 80000,
        'saldo_ore' => 80000, 'gyldig_til' => gmdate('Y-m-d', time() + 365 * 86400), 'status' => 'aktivt']);
    $kort[] = $gk;
    $pGro = DB::settInn('payments', ['member_id' => $gro, 'booking_id' => $bGro, 'vipps_reference' => $tag . '-G' . bin2hex(random_bytes(5)),
        'type' => 'epayment', 'formal' => 'booking', 'belop_ore' => 0, 'status' => 'betalt', 'idempotency_key' => bin2hex(random_bytes(18)),
        'gavekort_id' => $gk, 'gavekort_ore' => 50000]);
    DB::oppdater('bookings', ['payment_id' => $pGro, 'status' => 'betalt'], ['id' => $bGro]);
    Booking::trekkGavekort($pGro);
    $ida = $nyttMedlem('Ida'); $idaT = $sesjon($ida);
    $bIda = $plass($ida, $sAvl);
    $kim = $nyttMedlem('Kim Kontant'); $kimT = $sesjon($kim);
    $bKim = $plass($kim, $sAvl);
    Booking::manuellBetaling($bKim, 50000, 'Kontant', $kim, $admin, 'Testdata');
    Booking::settBetaltStatus($bKim);
    $bo = $nyttMedlem('Bo'); $boT = $sesjon($bo);
    $bBo = $plass($bo, $sAvl); [$pBo, $refBo] = $vippsBetaling($bo, $bBo, 50000);
    $dag = $nyttMedlem('Dag'); $dagT = $sesjon($dag);
    $bDag = $plass($dag, $sAvl); [$pDag, $refDag] = $vippsBetaling($dag, $bDag, 50000);
    // Gjest uten konto, aktiv reservasjon: får beskjeden.
    $bGjest = DB::settInn('bookings', ['course_id' => $kid, 'course_session_id' => $sAvl, 'gjest_navn' => 'Gjest ' . $tag,
        'gjest_epost' => strtolower($tag) . '-gjest@example.com', 'gjest_telefon' => '41234567', 'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert']);
    // Utløpt reservasjon og avbestilt: får den ikke.
    $bUtlopt = DB::settInn('bookings', ['course_id' => $kid, 'course_session_id' => $sAvl, 'gjest_navn' => 'Utløpt ' . $tag,
        'gjest_epost' => strtolower($tag) . '-utlopt@example.com', 'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert',
        'reservert_til' => gmdate('Y-m-d H:i:s', time() - 3600)]);
    DB::settInn('bookings', ['course_id' => $kid, 'course_session_id' => $sAvl, 'gjest_navn' => 'Avbestilt ' . $tag,
        'gjest_epost' => strtolower($tag) . '-avb@example.com', 'antall' => 1, 'belop_ore' => 50000, 'status' => 'avbestilt']);
    // Uten e-post og telefon: «ikke nådd».
    DB::settInn('bookings', ['course_id' => $kid, 'course_session_id' => $sAvl, 'gjest_navn' => 'Ukjent ' . $tag,
        'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert']);
    // Kontroll: vanlig plass om 21 timer, ikke avlyst.
    $nina = $nyttMedlem('Nina'); $ninaT = $sesjon($nina);
    $bNina = DB::settInn('bookings', ['member_id' => $nina, 'course_id' => $annet, 'course_session_id' => $sSnart,
        'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert']);
    [$pNina, $refNina] = $vippsBetaling($nina, $bNina, 50000);

    $malTekst = static fn(string $fornavn) => 'Hei ' . $fornavn . '! Dessverre må vi avlyse Dreiekurs ' . $tag . ' '
        . Paaminnelse::ukedagDato($om(20)) . ' kl. ' . Paaminnelse::klokkeslett($om(20))
        . '. Velg en ny dato eller få pengene tilbake på Min side. Beklager, og velkommen tilbake! Hilsen Lissom';
    $V = '/api/admin/okt-varsel.php';

    echo "\n── A  Avlys dato i /ny-admin, og beskjeden ─────────────────────\n";
    $r = kall($V, ['handling' => 'avlyst', 'oktId' => $sAvl], $adminT);
    sjekk('ingen beskjed før datoen er avlyst (409)', $r[0] === 409, $tekst($r));
    $r = kall($V, ['handling' => 'forhandsvis', 'mal' => 'kurs_avlyst', 'oktId' => $sAvl], $adminT);
    sjekk('forhåndsvisningen: SMS-teksten er eierens, ordrett', ($r[1]['smsTekst'] ?? '') === $malTekst('Kari'), $tekst($r));
    sjekk('… 8 på lista, 7 nås (1 ikke nådd), 7 e-post og 7 SMS', ($r[1]['antall'] ?? 0) === 8 && ($r[1]['naas'] ?? 0) === 7
        && ($r[1]['epost'] ?? 0) === 7 && ($r[1]['sms'] ?? 0) === 7 && count($r[1]['ikkeNaadd'] ?? []) === 1, $tekst($r));
    $r = kall('/api/admin/kurs.php', ['handling' => 'avlys', 'oktId' => $sAvl], $adminT);
    sjekk('kurs.php avlys', $r[0] === 200 && DB::verdi('SELECT status FROM course_sessions WHERE id = :i', ['i' => $sAvl]) === 'avlyst', $tekst($r));
    DB::kjor("UPDATE notification_templates SET aktiv = 0 WHERE navn = 'kurs_avlyst'");
    $r = kall($V, ['handling' => 'avlyst', 'oktId' => $sAvl], $adminT);
    sjekk('malen av: ingen beskjed (409)', $r[0] === 409 && (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'kurs_avlyst' AND ref_type = 'booking' AND ref_id IN ($bKari, $bGjest)") === 0, $tekst($r));
    DB::kjor("UPDATE notification_templates SET aktiv = 1 WHERE navn = 'kurs_avlyst'");
    $r = kall($V, ['handling' => 'avlyst', 'oktId' => $sAvl], $adminT);
    sjekk('sendt til 7 (betalt og aktive reservasjoner), 1 ikke nådd', $r[0] === 200 && ($r[1]['sendt'] ?? 0) === 7 && ($r[1]['uten'] ?? 0) === 1, $tekst($r));
    $n = static fn(int $b, string $k): int => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'kurs_avlyst' AND ref_type = 'booking' AND ref_id = :b AND kanal = :k", ['b' => $b, 'k' => $k]);
    sjekk('Kari: én e-post og én SMS', $n($bKari, 'epost') === 1 && $n($bKari, 'sms') === 1);
    $sms = (string) DB::verdi("SELECT tekst FROM notifications WHERE mal = 'kurs_avlyst' AND ref_id = :b AND kanal = 'sms'", ['b' => $bKari]);
    sjekk('… SMS-en er eierens tekst, ordrett', $sms === $malTekst('Kari'), $sms);
    $emne = (string) DB::verdi("SELECT emne FROM notifications WHERE mal = 'kurs_avlyst' AND ref_id = :b AND kanal = 'epost'", ['b' => $bKari]);
    sjekk('… e-postens emne', $emne === 'Avlyst: Dreiekurs ' . $tag . ' ' . Paaminnelse::ukedagDato($om(20)), $emne);
    sjekk('gjest med aktiv reservasjon får den, utløpt reservasjon ikke', $n($bGjest, 'sms') === 1 && $n($bUtlopt, 'epost') === 0);
    $r = kall($V, ['handling' => 'avlyst', 'oktId' => $sAvl], $adminT);
    sjekk('nytt trykk: ingen nye (7 hadde alt fått den)', $r[0] === 200 && ($r[1]['sendt'] ?? -1) === 0 && ($r[1]['alleredeSendt'] ?? 0) === 7, $tekst($r));
    sjekk('… fortsatt bare én SMS til Kari', $n($bKari, 'sms') === 1);
    [$x, $y] = samtidig([$V, ['handling' => 'avlyst', 'oktId' => $sAvl]], [$V, ['handling' => 'avlyst', 'oktId' => $sAvl]], $adminT);
    sjekk('to faner samtidig: ingen nye', ($x[1]['sendt'] ?? -1) === 0 && ($y[1]['sendt'] ?? -1) === 0
        && (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'kurs_avlyst' AND ref_type = 'booking' AND ref_id = :b", ['b' => $bKari]) === 2);

    echo "\n── B  Min side viser plassen som avlyst ──────────────────────────\n";
    $finn = static fn(array $r, int $id): ?array => array_values(array_filter($r[1]['plasser'] ?? [], static fn($p) => (int) $p['id'] === $id))[0] ?? null;
    $r = kall('/api/mine-plasser.php', null, $kariT);
    $p = $finn($r, $bKari);
    sjekk('Kari: «Avlyst», med eierens tekst', $p !== null && $p['status'] === 'Avlyst' && !empty($p['avlyst'])
        && $p['frist'] === 'Lissom har dessverre avlyst denne datoen. Velg en ny dato, eller få pengene tilbake.', $tekst($r));
    sjekk('… «Du får 500 kr tilbake», ingen avbestilling, intet kursbevis', ($p['tilbake'] ?? '') === "Du får 500\u{a0}kr tilbake"
        && $p['kanAvbestille'] === false && $p['kursbevis'] === null, json_encode($p, JSON_UNESCAPED_UNICODE));
    $r = kall('/api/mine-plasser.php', null, $groT);
    sjekk('Gro (gavekort): «Du får 500 kr tilbake»', ($finn($r, $bGro)['tilbake'] ?? '') === "Du får 500\u{a0}kr tilbake", $tekst($r));
    $r = kall('/api/mine-plasser.php', null, $idaT);
    sjekk('Ida (ikke betalt): «Du får 0 kr tilbake»', ($finn($r, $bIda)['tilbake'] ?? '') === "Du får 0\u{a0}kr tilbake", $tekst($r));

    echo "\n── C  Velg ny dato ─────────────────────────────────────────────\n";
    $A = '/api/avlyst-plass.php';
    $r = kall($A, ['handling' => 'datoer', 'bookingId' => $bBo], $boT);
    $ider = array_map(static fn($d) => (int) $d['oktId'], $r[1]['datoer'] ?? []);
    sjekk('bare samme kurs med plass: den ledige', $r[0] === 200 && $ider === [$sLedig], $tekst($r));
    $r = kall($A, ['handling' => 'bytt', 'bookingId' => $bBo, 'oktId' => $sFull], $boT);
    sjekk('full dato avvises (409)', $r[0] === 409 && (int) DB::verdi('SELECT course_session_id FROM bookings WHERE id = :i', ['i' => $bBo]) === $sAvl, $tekst($r));
    foreach ([[$sAnnet, 'annet kurs'], [$sAvl2, 'avlyst dato'], [$sForbi, 'passert dato']] as [$o, $hva]) {
        $r = kall($A, ['handling' => 'bytt', 'bookingId' => $bBo, 'oktId' => $o], $boT);
        sjekk($hva . ' avvises', $r[0] === 409, $tekst($r));
    }
    $r = kall($A, ['handling' => 'bytt', 'bookingId' => $bBo, 'oktId' => $sLedig], $kariT);
    sjekk('andres plass: ikke funnet (404)', $r[0] === 404, $tekst($r));
    $betalingerFor = (int) DB::verdi('SELECT COUNT(*) FROM payments');
    $r = kall($A, ['handling' => 'bytt', 'bookingId' => $bBo, 'oktId' => $sLedig], $boT);
    $etter = DB::en('SELECT course_session_id, belop_ore, status, payment_id FROM bookings WHERE id = :i', ['i' => $bBo]);
    sjekk('bytt til ledig dato', $r[0] === 200 && (int) $etter['course_session_id'] === $sLedig, $tekst($r));
    sjekk('… ingen ekstra kostnad (500 kr, ikke 700), fortsatt betalt, samme betaling', (int) $etter['belop_ore'] === 50000
        && $etter['status'] === 'betalt' && (int) $etter['payment_id'] === $pBo);
    sjekk('… ingen ny betaling og intet kall til Vipps', (int) DB::verdi('SELECT COUNT(*) FROM payments') === $betalingerFor && $refusjoner($refBo) === []);
    sjekk('… «Ny dato på kurset» på e-post', (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'pamelding_flyttet' AND ref_type = 'booking' AND ref_id = :b", ['b' => $bBo]) === 1);
    $r = kall($A, ['handling' => 'bytt', 'bookingId' => $bBo, 'oktId' => $sLedig], $boT);
    sjekk('bytt igjen: datoen er ikke avlyst lenger (409)', $r[0] === 409, $tekst($r));

    echo "\n── D  Få pengene tilbake (også under 2 dager) ─────────────────\n";
    $B = '/api/avbestill.php';
    $r = kall($B, ['bookingId' => $bKari], $kariT);
    $pk = DB::en('SELECT status, refundert_ore FROM payments WHERE id = :i', ['i' => $pKari]);
    sjekk('Vipps: hele beløpet tilbake 20 timer før', $r[0] === 200 && ($r[1]['refunderes'] ?? '') === Booking::kroner(50000) && empty($r[1]['manuelt']), $tekst($r));
    sjekk('… betalingen refundert 50000, plassen refundert', (int) $pk['refundert_ore'] === 50000 && $pk['status'] === 'refundert'
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $bKari]) === 'refundert');
    $rk = $refusjoner($refKari);
    sjekk('… nøyaktig én refusjon i Vipps, 50000', count($rk) === 1 && (int) ($rk[0]['kropp']['modificationAmount']['value'] ?? 0) === 50000, json_encode($rk));
    $r = kall($B, ['bookingId' => $bKari], $kariT);
    sjekk('… nytt trykk: allerede avbestilt (409), ingen ny refusjon', $r[0] === 409 && count($refusjoner($refKari)) === 1, $tekst($r));
    $r = kall('/api/mine-plasser.php', null, $kariT);
    sjekk('… borte fra Min side', $finn($r, $bKari) === null);

    $saldo = static fn(): int => (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :k', ['k' => $gk]);
    sjekk('gavekort: før 30000 igjen på kortet', $saldo() === 30000);
    $r = kall($B, ['bookingId' => $bGro], $groT);
    sjekk('gavekort: 50000 tilbake på kortet (80000)', $r[0] === 200 && $saldo() === 80000 && ($r[1]['gavekortTilbakeOre'] ?? 0) === 50000, $tekst($r));
    sjekk('… plassen avbestilt, ingen Vipps-refusjon', DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $bGro]) === 'avbestilt'
        && (int) DB::verdi('SELECT COUNT(*) FROM payment_refunds WHERE payment_id = :p', ['p' => $pGro]) === 0);

    $r = kall($B, ['bookingId' => $bIda], $idaT);
    sjekk('ikke betalt: bare frigjort', $r[0] === 200 && ($r[1]['refunderes'] ?? '') === Booking::kroner(0)
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $bIda]) === 'avbestilt', $tekst($r));

    $r = kall($B, ['bookingId' => $bKim], $kimT);
    sjekk('kontant: avbestilt, pengene for hånd', $r[0] === 200 && !empty($r[1]['manuelt'])
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $bKim]) === 'avbestilt', $tekst($r));
    sjekk('… sak i avlyst_tilbakebetal, 50000', (int) DB::verdi('SELECT belop_ore FROM avlyst_tilbakebetal WHERE booking_id = :b AND ferdig_at IS NULL', ['b' => $bKim]) === 50000);
    $maa = static function () use ($adminT, $bKim): ?array {
        $r = kall('/api/admin/oversikt.php', null, $adminT);
        return array_values(array_filter($r[1]['maGjores'] ?? [], static fn($s) => $s['type'] === 'tilbakebetal' && (int) $s['id'] === $bKim))[0] ?? null;
    };
    $s = $maa();
    sjekk('… «Tilbakebetal 500 kr til Kim Kontant …» i Må gjøres', $s !== null && $s['tittel'] === "Tilbakebetal 500\u{a0}kr til Kim Kontant " . $tag && $s['gruppe'] === 'Betaling', json_encode($s, JSON_UNESCAPED_UNICODE));
    $r = kall('/api/admin/pamelding.php', ['handling' => 'tilbakebetalt', 'id' => $bKim], $kimT);
    sjekk('… «Betalt tilbake» ikke for kunden (404)', $r[0] === 404, $tekst($r));

    echo "\n── F  «Betalt tilbake» føres i kassa og dagsoppgjøret ───────────\n";
    $idag = (new DateTimeImmutable('now', $oslo))->format('Y-m-d');
    $dagsopp = static function () use ($adminT, $idag): array {
        $r = kall('/api/admin/dagsoppgjor.php?dato=' . $idag, null, $adminT);
        $b = array_values(array_filter($r[1]['bilag'] ?? [], static fn($x) => $x['dato'] === $idag))[0] ?? ['linjer' => [], 'inn' => [], 'balanse' => true];
        $l = []; foreach ($b['linjer'] as $x) { $l[$x['hva']] = (int) $x['belopOre']; }
        $i = []; foreach ($b['inn'] as $x) { $i[$x['maate']] = (int) $x['belopOre']; }
        return ['linjer' => $l, 'inn' => $i, 'balanse' => (bool) $b['balanse']];
    };
    $kasse = static function (): array {
        $o = KasseKurv::oppgjor();
        $r = []; foreach ($o['rader'] as $x) { $r[$x['navn']] = (int) $x['ore']; }
        return $r + ['_kontant' => (int) $o['kontantOre'], '_total' => (int) $o['totalOre']];
    };
    $forK = $kasse(); $forD = $dagsopp();
    $radFor = (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE booking_id = :b', ['b' => $bKim]);
    [$x, $y] = samtidig(['/api/admin/pamelding.php', ['handling' => 'tilbakebetalt', 'id' => $bKim]],
        ['/api/admin/pamelding.php', ['handling' => 'tilbakebetalt', 'id' => $bKim]], $adminT);
    $koder = [$x[0], $y[0]]; sort($koder);
    sjekk('dobbelttrykk «Betalt tilbake» på to servere: én 200 og én 409', $koder === [200, 409], $tekst($x) . ' | ' . $tekst($y));
    sjekk('… saken er borte fra Må gjøres', $maa() === null);
    $ut = DB::alle("SELECT * FROM payments WHERE booking_id = :b AND type = 'manuell' AND belop_ore = 0", ['b' => $bKim]);
    sjekk('… nøyaktig én utbetaling i kassa: Kontant 50000', count($ut) === 1 && (int) $ut[0]['refundert_ore'] === 50000
        && $ut[0]['maate'] === 'Kontant' && (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE booking_id = :b', ['b' => $bKim]) === $radFor + 1, json_encode($ut));
    sjekk('… koblet til saken', (int) DB::verdi('SELECT payment_id FROM avlyst_tilbakebetal WHERE booking_id = :b', ['b' => $bKim]) === (int) ($ut[0]['id'] ?? -1));
    $etK = $kasse(); $etD = $dagsopp();
    sjekk('kassa i dag: kontant 500 kr lavere, totalen 500 kr lavere', $etK['_kontant'] - $forK['_kontant'] === -50000 && $etK['_total'] - $forK['_total'] === -50000,
        json_encode([$forK, $etK]));
    sjekk('dagsoppgjøret: egen linje «Kurs og events – tilbakebetalt» −500 og «Kontant (tilbakebetalt)» −500, i balanse',
        ($etD['linjer']['Kurs og events – tilbakebetalt'] ?? 0) - ($forD['linjer']['Kurs og events – tilbakebetalt'] ?? 0) === -50000
        && ($etD['inn']['Kontant (tilbakebetalt)'] ?? 0) - ($forD['inn']['Kontant (tilbakebetalt)'] ?? 0) === -50000 && $etD['balanse'],
        json_encode([$forD, $etD], JSON_UNESCAPED_UNICODE));
    sjekk('… den opprinnelige kontantbetalingen står urørt', (int) DB::verdi("SELECT COUNT(*) FROM payments WHERE booking_id = :b AND type = 'manuell' AND belop_ore = 50000 AND status = 'betalt' AND COALESCE(refundert_ore, 0) = 0", ['b' => $bKim]) === 1);
    $r = kall('/api/admin/pamelding.php', ['handling' => 'tilbakebetalt', 'id' => $bKim], $adminT);
    sjekk('… et tredje trykk: 409, ingen ny føring', $r[0] === 409 && count(DB::alle('SELECT id FROM payments WHERE booking_id = :b AND belop_ore = 0', ['b' => $bKim])) === 1, $tekst($r));
    $csv = (static function () use ($adminT, $idag, $port): string {
        $c = curl_init('http://127.0.0.1:' . $port . '/api/admin/dagsoppgjor.php?dato=' . $idag . '&csv=ja');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Cookie: lissom_sesjon=' . $adminT]]);
        $t = (string) curl_exec($c); curl_close($c); return $t;
    })();
    sjekk('… CSV-en har «Tilbakebetalt · Kontant»', str_contains($csv, 'Tilbakebetalt · Kontant'));

    // Kontrolløren runde 2: utbetalingen kan ikke annulleres, og den synes.
    $utId = (int) ($ut[0]['id'] ?? 0);
    $r = kall('/api/admin/kursbetaling.php', ['handling' => 'annuller', 'betalingId' => $utId], $adminT);
    sjekk('utbetalingen kan ikke annulleres (409), står urørt', $r[0] === 409
        && DB::verdi('SELECT annullert_at FROM payments WHERE id = :i', ['i' => $utId]) === null, $tekst($r));
    $orig = (int) DB::verdi("SELECT id FROM payments WHERE booking_id = :b AND type = 'manuell' AND belop_ore = 50000", ['b' => $bKim]);
    $r = kall('/api/admin/kursbetaling.php', ['handling' => 'annuller', 'betalingId' => $orig], $adminT);
    sjekk('… heller ikke kontantbetalingen bak saken (409)', $r[0] === 409
        && DB::verdi('SELECT annullert_at FROM payments WHERE id = :i', ['i' => $orig]) === null, $tekst($r));
    sjekk('betalt på plassen etter utbetalingen: 0 (betalingerFor)', Booking::betalingerFor($bKim)['sum'] === 0, (string) Booking::betalingerFor($bKim)['sum']);
    sjekk('… og i listene (betaltSql): 0', (int) DB::verdi('SELECT ' . Booking::betaltSql('b') . ' FROM bookings b WHERE b.id = :i', ['i' => $bKim]) === 0);
    $r = kall('/api/admin/kursbetaling.php?bookingId=' . $bKim, null, $adminT);
    $hist = array_values(array_filter($r[1]['historikk'] ?? [], static fn($h) => (int) $h['id'] === $utId))[0] ?? null;
    sjekk('admin-historikken: «−kr. 500,-», «Kontant (tilbakebetalt)», kan ikke annulleres', $hist !== null
        && $hist['belop'] === '−' . Booking::kroner(50000) && $hist['maate'] === 'Kontant (tilbakebetalt)' && $hist['kanAnnulleres'] === false
        && ($r[1]['betalt'] ?? '') === Booking::kroner(0), json_encode([$hist, $r[1]['betalt'] ?? null], JSON_UNESCAPED_UNICODE));
    $r = kall('/api/mine-kjop.php', null, $kimT);
    $kj = array_values(array_filter($r[1]['kjop'] ?? [], static fn($k) => str_contains((string) $k['navn'], 'Dreiekurs')))[0] ?? null;
    sjekk('kundens kjøpshistorikk: «kr. 500,- refundert»', $kj !== null && $kj['refundert'] === Booking::kroner(50000) . ' refundert', json_encode($r[1], JSON_UNESCAPED_UNICODE));
    $tcsv = (static function () use ($adminT, $idag, $port): string {
        $c = curl_init('http://127.0.0.1:' . $port . '/api/admin/transaksjoner.php?maaned=' . substr($idag, 0, 7));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Cookie: lissom_sesjon=' . $adminT]]);
        $t = (string) curl_exec($c); curl_close($c); return $t;
    })();
    $linje = array_values(array_filter(explode("\n", $tcsv), static fn($l) => str_contains($l, (string) $ut[0]['vipps_reference'])))[0] ?? '';
    sjekk('transaksjonsuttrekket: «Kontant (tilbakebetalt)», «Tilbakebetalt», netto −500', str_contains($linje, ';"Kontant (tilbakebetalt)";Tilbakebetalt;0,00;500,00;-500,00;'), $linje);

    // Annen måte (Vipps i verkstedet): egen linje i kassa, ikke trukket fra kontant.
    $vera = $nyttMedlem('Vera'); $veraT = $sesjon($vera);
    $bVera = $plass($vera, $sAvl);
    Booking::manuellBetaling($bVera, 50000, 'Vipps i verkstedet', $vera, $admin, 'Testdata');
    Booking::settBetaltStatus($bVera);
    $r = kall($B, ['bookingId' => $bVera], $veraT);
    sjekk('Vipps i verkstedet: avbestilt, sak i Må gjøres', $r[0] === 200 && (int) DB::verdi('SELECT belop_ore FROM avlyst_tilbakebetal WHERE booking_id = :b', ['b' => $bVera]) === 50000, $tekst($r));
    $forK = $kasse(); $forD = $dagsopp();
    $r = kall('/api/admin/pamelding.php', ['handling' => 'tilbakebetalt', 'id' => $bVera], $adminT);
    $etK = $kasse(); $etD = $dagsopp();
    sjekk('… «Betalt tilbake»: egen linje «Tilbakebetalt, annen måte» −500, kontant urørt', $r[0] === 200
        && ($etK['Tilbakebetalt, annen måte'] ?? 0) - ($forK['Tilbakebetalt, annen måte'] ?? 0) === -50000
        && $etK['_kontant'] === $forK['_kontant'] && $etK['_total'] - $forK['_total'] === -50000, json_encode([$forK, $etK], JSON_UNESCAPED_UNICODE));
    sjekk('… dagsoppgjøret: «Vipps (tilbakebetalt)» −500, i balanse',
        ($etD['inn']['Vipps (tilbakebetalt)'] ?? 0) - ($forD['inn']['Vipps (tilbakebetalt)'] ?? 0) === -50000 && $etD['balanse'], json_encode($etD['inn'], JSON_UNESCAPED_UNICODE));

    $r = kall($B, ['bookingId' => $bNina], $ninaT);
    sjekk('kontroll: ikke avlyst, 21 timer før: ingenting tilbake og intet Vipps-kall', $r[0] === 200
        && ($r[1]['refunderes'] ?? '') === Booking::kroner(0) && $refusjoner($refNina) === [], $tekst($r));

    echo "\n── E  Dobbeltklikk: aldri dobbel refusjon ──────────────────────\n";
    [$x, $y] = samtidig([$B, ['bookingId' => $bDag]], [$B, ['bookingId' => $bDag]], $dagT);
    $koder = [$x[0], $y[0]]; sort($koder);
    sjekk('to samtidige: én 200 og én 409', $koder === [200, 409], $tekst($x) . ' | ' . $tekst($y));
    sjekk('… nøyaktig én refusjon i Vipps, 50000', count($refusjoner($refDag)) === 1 && (int) ($refusjoner($refDag)[0]['kropp']['modificationAmount']['value'] ?? 0) === 50000, json_encode($refusjoner($refDag)));
    sjekk('… refundert 50000, ikke mer', (int) DB::verdi('SELECT refundert_ore FROM payments WHERE id = :i', ['i' => $pDag]) === 50000);
    $r = kall($B, ['bookingId' => $bDag], $dagT);
    sjekk('… et tredje trykk: 409', $r[0] === 409 && count($refusjoner($refDag)) === 1, $tekst($r));

    echo "\n── G  Kontrolløren: gamle avlysninger, gjenoppretting, to faner ──\n";
    sjekk('«Avlys dato» lagrer tidspunktet (avlyst_at)', DB::verdi('SELECT avlyst_at FROM course_sessions WHERE id = :i', ['i' => $sAvl]) !== null);
    // Avlyst før migrasjon 271 (ingen avlyst_at): som før.
    $sGml = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(22), 'status' => 'avlyst', 'kapasitet' => 10]);
    $ola = $nyttMedlem('Ola'); $olaT = $sesjon($ola);
    $bOla = $plass($ola, $sGml); [$pOla, $refOla] = $vippsBetaling($ola, $bOla, 50000);
    $r = kall('/api/mine-plasser.php', null, $olaT);
    $p = $finn($r, $bOla);
    sjekk('gammel avlyst dato: Min side som før (ikke «Avlyst», ingen valg)', $p !== null && $p['status'] !== 'Avlyst' && empty($p['avlyst']), json_encode($p, JSON_UNESCAPED_UNICODE));
    $r = kall($A, ['handling' => 'datoer', 'bookingId' => $bOla], $olaT);
    sjekk('… «Velg ny dato» avvist (409)', $r[0] === 409, $tekst($r));
    $r = kall($V, ['handling' => 'avlyst', 'oktId' => $sGml], $adminT);
    sjekk('… ingen «Kurset er avlyst» (409)', $r[0] === 409, $tekst($r));
    kall('/api/admin/kurs.php', ['handling' => 'avlys', 'oktId' => $sGml], $adminT);
    sjekk('… et nytt «Avlys dato» gir den ikke et tidspunkt', DB::verdi('SELECT avlyst_at FROM course_sessions WHERE id = :i', ['i' => $sGml]) === null);
    $r = kall($B, ['bookingId' => $bOla], $olaT);
    sjekk('… avbestilling under 2 dager: 0 kr, intet Vipps-kall, ingen sak', $r[0] === 200 && ($r[1]['refunderes'] ?? '') === Booking::kroner(0)
        && $refusjoner($refOla) === [] && DB::verdi('SELECT booking_id FROM avlyst_tilbakebetal WHERE booking_id = :b', ['b' => $bOla]) === null, $tekst($r));

    // Avlyst og gjenopprettet: som før.
    $sGjen = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(23), 'status' => 'planlagt', 'kapasitet' => 10]);
    $tor = $nyttMedlem('Tor'); $torT = $sesjon($tor);
    $bTor = $plass($tor, $sGjen); [$pTor, $refTor] = $vippsBetaling($tor, $bTor, 50000);
    kall('/api/admin/kurs.php', ['handling' => 'avlys', 'oktId' => $sGjen], $adminT);
    $r = kall('/api/admin/kurs.php', ['handling' => 'gjenopprett', 'oktId' => $sGjen], $adminT);
    $r2 = kall('/api/admin/kurs.php', ['handling' => 'gjenopprett', 'oktId' => $sGjen], $adminT);
    sjekk('gjenopprett igjen: «ikke avlyst»', $r2[0] !== 200, $tekst($r2));
    sjekk('gjenopprettet: avlyst_at tatt bort', $r[0] === 200 && DB::verdi('SELECT avlyst_at FROM course_sessions WHERE id = :i', ['i' => $sGjen]) === null, $tekst($r));
    $r = kall($B, ['bookingId' => $bTor], $torT);
    sjekk('… avbestilling under 2 dager: 0 kr, intet Vipps-kall', $r[0] === 200 && ($r[1]['refunderes'] ?? '') === Booking::kroner(0) && $refusjoner($refTor) === [], $tekst($r));

    // To faner: «Bytt» til en dato om 30 timer og «Få pengene tilbake» samtidig.
    $sNaer = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(30), 'status' => 'planlagt', 'kapasitet' => 10]);
    foreach ([1, 2, 3] as $runde) {
        $siv = $nyttMedlem('Siv' . $runde); $sivT = $sesjon($siv);
        $bSiv = $plass($siv, $sAvl); [$pSiv, $refSiv] = $vippsBetaling($siv, $bSiv, 50000);
        [$x, $y] = samtidig([$A, ['handling' => 'bytt', 'bookingId' => $bSiv, 'oktId' => $sNaer]], [$B, ['bookingId' => $bSiv]], $sivT);
        $st = DB::en('SELECT b.status, b.course_session_id, p.refundert_ore FROM bookings b JOIN payments p ON p.id = :p WHERE b.id = :b', ['p' => $pSiv, 'b' => $bSiv]);
        $flyttet = (int) $st['course_session_id'] === $sNaer;
        $refundert = (int) $st['refundert_ore'];
        // Flyttet først: refusjonen regnes etter den nye datoen (30 t → 0).
        $ok1 = $flyttet
            ? ($refundert === 0 && count($refusjoner($refSiv)) === 0)
            : ($refundert === 50000 && count($refusjoner($refSiv)) === 1 && in_array($st['status'], ['refundert', 'avbestilt'], true));
        sjekk('to faner samtidig, runde ' . $runde . ': aldri full refusjon på en flyttet plass (' . ($flyttet ? 'flyttet' : 'refundert') . ')', $ok1,
            $tekst($x) . ' | ' . $tekst($y) . ' | ' . json_encode($st));
    }

    echo "\n── H  Kontrolløren runde 3: annullering mot «Få pengene tilbake» ──\n";
    // (a) Saken finnes, men innbetalingen bak er borte: ingen føring.
    $une = $nyttMedlem('Une'); $uneT = $sesjon($une);
    $bUne = $plass($une, $sAvl);
    $pUne = Booking::manuellBetaling($bUne, 50000, 'Kontant', $une, $admin, 'Testdata');
    Booking::settBetaltStatus($bUne);
    DB::oppdater('bookings', ['status' => 'avbestilt', 'avbestilt_at' => gmdate('Y-m-d H:i:s')], ['id' => $bUne]);
    DB::settInn('avlyst_tilbakebetal', ['booking_id' => $bUne, 'belop_ore' => 50000]);
    DB::oppdater('payments', ['status' => 'avbrutt', 'annullert_at' => gmdate('Y-m-d H:i:s')], ['id' => $pUne]);
    $r = kall('/api/admin/pamelding.php', ['handling' => 'tilbakebetalt', 'id' => $bUne], $adminT);
    sjekk('«Betalt tilbake» uten innbetaling bak saken: 409, ingen føring, saken står', $r[0] === 409
        && (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE booking_id = :b AND belop_ore = 0', ['b' => $bUne]) === 0
        && DB::verdi('SELECT ferdig_at FROM avlyst_tilbakebetal WHERE booking_id = :b', ['b' => $bUne]) === null, $tekst($r));

    // (b0) Annulleringen kommer mellom lesingen og avbestillingen (styrt):
    // testen holder låsen på påmeldingen, kunden trykker (avbestill.php har
    // lest 500 kr kontant og venter på låsen), betalingen annulleres, låsen
    // slippes. Avbestillingen skal se annulleringen og ikke lage noen sak.
    $vil = $nyttMedlem('Vil'); $vilT = $sesjon($vil);
    $bVil = $plass($vil, $sAvl);
    $pVil = Booking::manuellBetaling($bVil, 50000, 'Kontant', $vil, $admin, 'Testdata');
    Booking::settBetaltStatus($bVil);
    $pdo = DB::kobling();
    $pdo->beginTransaction();
    DB::verdi('SELECT id FROM bookings WHERE id = :b FOR UPDATE', ['b' => $bVil]);
    $mh = curl_multi_init();
    $ch = forbered($B, ['bookingId' => $bVil], $vilT, $porter[0]);
    curl_multi_add_handle($mh, $ch);
    $slutt = microtime(true) + 1.5;
    do { curl_multi_exec($mh, $aktiv); curl_multi_select($mh, 0.05); } while ($aktiv && microtime(true) < $slutt);
    DB::oppdater('payments', ['status' => 'avbrutt', 'annullert_at' => gmdate('Y-m-d H:i:s')], ['id' => $pVil]);
    $pdo->commit();
    do { curl_multi_exec($mh, $aktiv); if ($aktiv) { curl_multi_select($mh, 0.05); } } while ($aktiv);
    $rv = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string) curl_multi_getcontent($ch), true)];
    curl_multi_remove_handle($mh, $ch); curl_close($ch); curl_multi_close($mh);
    sjekk('annullert mens kunden ventet på låsen: 409, ingen sak, plassen står', $rv[0] === 409
        && DB::verdi('SELECT booking_id FROM avlyst_tilbakebetal WHERE booking_id = :b', ['b' => $bVil]) === null
        && DB::verdi('SELECT status FROM bookings WHERE id = :b', ['b' => $bVil]) !== 'avbestilt', $tekst($rv));

    // (b) Kappløpet: admin annullerer kontantbetalingen mens kunden trykker
    // «Ja, gi meg pengene tilbake». Aldri både sak og annullert betaling.
    $utfall = [];
    foreach ([1, 2, 3, 4, 5, 6] as $runde) {
        $rut = $nyttMedlem('Rut' . $runde); $rutT = $sesjon($rut);
        $bRut = $plass($rut, $sAvl);
        $pRut = Booking::manuellBetaling($bRut, 50000, 'Kontant', $rut, $admin, 'Testdata');
        Booking::settBetaltStatus($bRut);
        [$x, $y] = samtidig(['/api/admin/kursbetaling.php', ['handling' => 'annuller', 'betalingId' => $pRut]],
            [$B, ['bookingId' => $bRut]], $adminT, $rutT);
        $sak = DB::verdi('SELECT belop_ore FROM avlyst_tilbakebetal WHERE booking_id = :b', ['b' => $bRut]);
        $annullert = DB::verdi('SELECT annullert_at FROM payments WHERE id = :i', ['i' => $pRut]) !== null;
        $utfall[] = ($sak !== null ? 'sak' : '') . ($annullert ? 'annullert' : '');
        $godt = !($sak !== null && $annullert) && ($sak === null || (int) $sak === 50000);
        if ($sak !== null) {
            $r = kall('/api/admin/pamelding.php', ['handling' => 'tilbakebetalt', 'id' => $bRut], $adminT);
            $godt = $godt && $r[0] === 200
                && (int) DB::verdi('SELECT COALESCE(SUM(refundert_ore), 0) FROM payments WHERE booking_id = :b AND belop_ore = 0', ['b' => $bRut]) === 50000;
        }
        sjekk('kappløp runde ' . $runde . ': aldri sak uten innbetaling (' . ($utfall[$runde - 1] ?: 'ingen av delene') . ')', $godt,
            $tekst($x) . ' | ' . $tekst($y));
    }

    $ferdig = true;
} catch (Throwable $e) {
    echo "\n  FEIL  " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    $feil++;
    $ferdig = true;
}

echo "\n  $ok ok, $feil feil\n";
if ($feil > 0 && is_file($logg)) {
    echo "\n  Serverloggen (siste linjer):\n" . implode('', array_slice(file($logg), -15));
}
exit($feil > 0 ? 1 : 0);
