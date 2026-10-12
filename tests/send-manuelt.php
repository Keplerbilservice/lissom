<?php
/**
 * «Send ▾» manuelt i ny admin (eieren GO 11.10.2026, vedtak send-manuelt).
 *
 *   A  «Send beskjed»: beskjed.php til=en, bare SMS, norskMobil. Krever admin,
 *      avviser et nummer som ikke er norsk mobil, gir den eksisterende
 *      feilmeldingen når SMS er av, og legger én SMS i køen (og en linje i
 *      revisjonsloggen) med den falske SMS-en.
 *   B  «Send kursbevis»: personen legges til med pamelding.php legg-til
 *      «Gratis» uten bekreftelse (ingen betaling, null kroner), og
 *      handling=kursbevis kanal=sms legger malen kursbevis_sms med den korte
 *      lenken /k/ i køen. SMS av og malen av gir feil, ikke «sendt». En som
 *      alt står på økta får beviset uten ny påmelding. En kommende økt gir
 *      ikke bevis ennå.
 *
 * «Den falske SMS-en»: Sveve-oppsettet settes til testverdier bare under
 * testen, så meldingene havner i køen (notifications). Ingen cron kjører, og
 * køen ryddes etterpå — ingenting går ut.
 *   php tests/send-manuelt.php
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
$logg = sys_get_temp_dir() . '/lissom-send-manuelt-' . bin2hex(random_bytes(4)) . '.log';
$server = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

$tag = 'SENDM-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $kurs = []; $ferdig = false;
// Testnumre: norske mobilnumre som ikke tilhører noen her (9 + sju tilfeldige siffer).
$nummer = static fn(): string => '+479' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$tlfA = $nummer(); $tlfB = $nummer(); $tlfC = $nummer();
$smsFor = array_column(DB::alle("SELECT nokkel, verdi FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')"), 'verdi', 'nokkel');
$malFor = DB::en("SELECT aktiv, kanal FROM notification_templates WHERE navn = 'kursbevis_sms'");

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, $server, $smsFor, $malFor, $tlfA, $tlfB, $tlfC): void {
    if (is_resource($server)) { proc_terminate($server); }
    try {
        DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')");
        foreach ($smsFor as $n => $v) { DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v)', ['n' => $n, 'v' => $v]); }
        if ($malFor !== null) {
            DB::kjor("UPDATE notification_templates SET aktiv = :a, kanal = :k WHERE navn = 'kursbevis_sms'", ['a' => $malFor['aktiv'], 'k' => $malFor['kanal']]);
        }
    } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    $k = implode(',', array_map('intval', $kurs ?: [0]));
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $b = implode(',', array_map('intval', array_column(DB::alle("SELECT id FROM bookings WHERE course_id IN ($k)"), 'id')) ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE mottaker IN ('$tlfA', '$tlfB', '$tlfC')",
        "DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id IN ($b)",
        "DELETE FROM audit_log WHERE objekt_type = 'booking' AND objekt_id IN ($b)",
        "DELETE FROM bookings WHERE id IN ($b)",
        "DELETE FROM course_sessions WHERE course_id IN ($k)",
        "DELETE FROM courses WHERE id IN ($k)",
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
$smsPaa = static function (bool $paa): void {
    DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')");
    if ($paa) {
        foreach (['sveve_bruker' => 'test', 'sveve_passord' => 'test', 'sms_leverandor' => 'sveve'] as $n => $v) {
            DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v)', ['n' => $n, 'v' => $v]);
        }
    }
    Config::glemBasen();
};
$koTil = static fn(string $tlf): array => DB::alle('SELECT mal, kanal, mottaker, tekst, ref_type, ref_id FROM notifications WHERE mottaker = :t ORDER BY id', ['t' => $tlf]);

try {
    sjekk('HTTP-serveren klar', $klar);
    sjekk('migrasjon 259 er kjørt (malen kursbevis_sms)', $malFor !== null);
    DB::kjor("UPDATE notification_templates SET aktiv = 1, kanal = 'sms' WHERE navn = 'kursbevis_sms'");

    $admin = $nyttMedlem(['navn' => $tag . ' Monica', 'rolle' => 'admin', 'brukernavn' => strtolower($tag) . '-admin']);
    $vanlig = $nyttMedlem(['navn' => $tag . ' Kari', 'rolle' => 'medlem', 'status' => 'aktiv']);
    $tA = $sesjon($admin);
    $tM = $sesjon($vanlig);

    // ── A Send beskjed ──────────────────────────────────────────────────
    $beskjed = static fn(string $tlf, string $tekst, string $tok) => kall('/api/admin/beskjed.php',
        ['til' => 'en', 'navn' => $tag . ' Kari Nordmann', 'telefon' => $tlf, 'tekst' => $tekst, 'norskMobil' => 'ja', 'ogsaaSms' => 'ja', 'bareSms' => 'ja'], $tok);
    $u = $beskjed($tlfA, 'Hei Kari, ovnen er tømt.', '');
    sjekk('A uten innlogging: 401', $u[0] === 401, $vis($u));
    $m = $beskjed($tlfA, 'Hei Kari, ovnen er tømt.', $tM);
    sjekk('A som vanlig medlem: avvist', in_array($m[0], [403, 404], true), $vis($m));
    foreach (['12345678', '+4612345678', '9123456'] as $galt) {
        $g = $beskjed($galt, 'Hei Kari, ovnen er tømt.', $tA);
        sjekk("A «{$galt}» er ikke norsk mobil: «Telefonnummeret ser ikke riktig ut.»", $g[0] === 400 && ($g[1]['feil'] ?? '') === 'Telefonnummeret ser ikke riktig ut.', $vis($g));
    }
    $smsPaa(false);
    $av = $beskjed($tlfA, 'Hei Kari, ovnen er tømt.', $tA);
    sjekk('A SMS av: 409 med den eksisterende feilmeldingen', $av[0] === 409 && str_contains((string) ($av[1]['feil'] ?? ''), 'SMS er ikke satt opp'), $vis($av));
    sjekk('A SMS av: ingenting i køen', $koTil($tlfA) === []);
    $smsPaa(true);
    $s = $beskjed(substr($tlfA, 3, 3) . ' ' . substr($tlfA, 6, 2) . ' ' . substr($tlfA, 8), 'Hei Kari, ovnen er tømt.', $tA);
    sjekk('A med den falske SMS-en: 200, én SMS og ingen e-post', $s[0] === 200 && ($s[1]['sms'] ?? null) === 1 && ($s[1]['epost'] ?? null) === 0, $vis($s));
    $k = $koTil($tlfA);
    sjekk('A én SMS i køen til nummeret (med +47), med teksten', count($k) === 1 && $k[0]['kanal'] === 'sms' && $k[0]['tekst'] === 'Hei Kari, ovnen er tømt.' && $k[0]['ref_type'] === 'beskjed-en', json_encode($k, JSON_UNESCAPED_UNICODE));
    sjekk('A logget (revider beskjed_sendt)', (int) DB::verdi("SELECT COUNT(*) FROM audit_log WHERE member_id = :m AND handling = 'beskjed_sendt' AND JSON_EXTRACT(detaljer, '$.sms') = 1", ['m' => $admin]) === 1);
    $kort = $beskjed($tlfA, 'Hi', $tA);
    sjekk('A for kort beskjed: «Skriv en melding først.»', $kort[0] === 400 && ($kort[1]['feil'] ?? '') === 'Skriv en melding først.', $vis($kort));

    // ── B Send kursbevis ────────────────────────────────────────────────
    $kid = DB::settInn('courses', ['slug' => strtolower($tag) . '-kurs', 'tittel' => $tag . ' Dreiekurs', 'type' => 'kurs',
        'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
    $kurs[] = $kid;
    $ferdigOkt = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => gmdate('Y-m-d H:i:s', time() - 3 * 86400),
        'slutt_tid' => gmdate('Y-m-d H:i:s', time() - 3 * 86400 + 3 * 3600), 'status' => 'planlagt']);
    $kommendeOkt = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => gmdate('Y-m-d H:i:s', time() + 5 * 86400), 'status' => 'planlagt']);
    $leggTil = static fn(int $okt, string $navn, string $tlf) => kall('/api/admin/pamelding.php',
        ['handling' => 'legg-til', 'oktId' => $okt, 'navn' => $navn, 'telefon' => $tlf, 'antall' => 1, 'betaltMaate' => 'Gratis', 'varsle' => 'nei'], $tA);
    $bevis = static fn(int $id) => kall('/api/admin/pamelding.php', ['handling' => 'kursbevis', 'id' => $id, 'kanal' => 'sms'], $tA);

    $l = $leggTil($ferdigOkt, $tag . ' Kari Nordmann', $tlfB);
    $bid = (int) ($l[1]['id'] ?? 0);
    sjekk('B legg-til «Gratis»: 200 med påmeldingen', $l[0] === 200 && $bid > 0, $vis($l));
    $rad = DB::en('SELECT status, belop_ore, betalt_maate, payment_id, gjest_telefon FROM bookings WHERE id = :i', ['i' => $bid]);
    sjekk('B påmeldingen er betalt, 0 kr, «Gratis», uten betaling, med nummeret', $rad !== null && $rad['status'] === 'betalt' && (int) $rad['belop_ore'] === 0
        && $rad['betalt_maate'] === 'Gratis' && $rad['payment_id'] === null && $rad['gjest_telefon'] === $tlfB, json_encode($rad));
    sjekk('B ingen betalingsrad (ingen ny pengeflyt)', (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE booking_id = :b', ['b' => $bid]) === 0);
    sjekk('B ingen bekreftelse i køen', (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = 'booking' AND ref_id = :b", ['b' => $bid]) === 0);

    $smsPaa(false);
    $av = $bevis($bid);
    sjekk('B SMS av: 409 «SMS er ikke satt opp, så ingen SMS ble sendt.»', $av[0] === 409 && ($av[1]['feil'] ?? '') === 'SMS er ikke satt opp, så ingen SMS ble sendt.', $vis($av));
    $smsPaa(true);
    DB::kjor("UPDATE notification_templates SET aktiv = 0 WHERE navn = 'kursbevis_sms'");
    $malAv = $bevis($bid);
    sjekk('B malen av: 409, ikke «sendt»', $malAv[0] === 409, $vis($malAv));
    sjekk('B SMS av / malen av: ingenting i køen', $koTil($tlfB) === []);
    DB::kjor("UPDATE notification_templates SET aktiv = 1 WHERE navn = 'kursbevis_sms'");

    $s = $bevis($bid);
    sjekk('B kursbevis kanal=sms: 200 «Kursbeviset er sendt til …»', $s[0] === 200 && ($s[1]['beskjed'] ?? '') === 'Kursbeviset er sendt til ' . $tlfB . '.', $vis($s));
    $k = $koTil($tlfB);
    sjekk('B malen kursbevis_sms i køen med den korte lenken /k/', count($k) === 1 && $k[0]['mal'] === 'kursbevis_sms' && $k[0]['kanal'] === 'sms'
        && str_contains((string) $k[0]['tekst'], '/k/' . $bid . '.') && !str_contains((string) $k[0]['tekst'], 'kursbevis.php'), json_encode($k, JSON_UNESCAPED_UNICODE));
    sjekk('B ingen e-post sendt', (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = 'booking' AND ref_id = :b AND kanal = 'epost'", ['b' => $bid]) === 0);
    sjekk('B logget (revider kursbevis_sendt)', (int) DB::verdi("SELECT COUNT(*) FROM audit_log WHERE handling = 'kursbevis_sendt' AND objekt_type = 'booking' AND objekt_id = :b", ['b' => $bid]) === 1);

    // Står personen alt på økta (samme nummer): ingen ny påmelding, bare beviset.
    $foer = (int) DB::verdi('SELECT COUNT(*) FROM bookings WHERE course_session_id = :o', ['o' => $ferdigOkt]);
    $igjen = $bevis($bid);
    sjekk('B alt påmeldt: beviset sendes igjen uten ny påmelding', $igjen[0] === 200 && count($koTil($tlfB)) === 2
        && (int) DB::verdi('SELECT COUNT(*) FROM bookings WHERE course_session_id = :o', ['o' => $ferdigOkt]) === $foer, $vis($igjen));
    $dobbel = $leggTil($ferdigOkt, $tag . ' Kari Nordmann', $tlfB);
    sjekk('B samme navn to ganger: avvist («står alt på denne datoen»)', $dobbel[0] === 400 && str_contains((string) ($dobbel[1]['feil'] ?? ''), 'står alt på denne datoen'), $vis($dobbel));

    // Kommende økt: plassen legges til, men beviset finnes ikke før kurset er ferdig.
    $l2 = $leggTil($kommendeOkt, $tag . ' Ola Nordmann', $tlfC);
    $kb = $bevis((int) ($l2[1]['id'] ?? 0));
    sjekk('B kommende økt: «Påmeldingen gir ikke kursbevis …», ingenting i køen', $kb[0] === 400 && str_starts_with((string) ($kb[1]['feil'] ?? ''), 'Påmeldingen gir ikke kursbevis') && $koTil($tlfC) === [], $vis($kb));

    $ferdig = true;
} catch (Throwable $e) {
    echo '  FEIL  ' . $e->getMessage() . "\n";
    $feil++;
    $ferdig = true;
}
echo "\n  $ok OK, $feil FEIL\n";
exit($feil > 0 ? 1 : 0);
