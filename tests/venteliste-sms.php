<?php
/**
 * Ventelisten på SMS (eieren 06.11.2026, migrasjon 284).
 *
 *   A  «Tilby plassen» (venteliste.php handling=varsle): e-posten som før, og
 *      en SMS (venteliste_ledig_sms) med godkjent tekst og kort dato.
 *      Uten dato (venter på hele kurset): ingen dato, ingen dobbel mellomrom.
 *   B  «Gi plass» (handling=gi-plass): e-posten som før, og en SMS
 *      (venteliste_tildelt_sms).
 *   C  Uten telefonnummer: ingen SMS. Uten SMS satt opp: ingen SMS.
 *
 * Meldingene legges bare i køen; ingen cron kjører, og køen ryddes. Ingenting sendes.
 *   php tests/venteliste-sms.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
$port = (int) substr($adr, strrpos($adr, ':') + 1);
$logg = sys_get_temp_dir() . '/lissom-vlsms-' . bin2hex(random_bytes(4)) . '.log';
$server = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

$tag = 'VLS-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $kurs = []; $ferdig = false;
$smsFor = array_column(DB::alle("SELECT nokkel, verdi FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')"), 'verdi', 'nokkel');
$varselFor = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM notifications');

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, $server, $smsFor, $varselFor): void {
    if (is_resource($server)) { proc_terminate($server); }
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $k = implode(',', array_map('intval', $kurs ?: [0]));
    foreach ([
        "DELETE FROM notifications WHERE id > $varselFor",
        "DELETE FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')",
        "DELETE FROM waitlist WHERE course_id IN ($k)",
        "DELETE FROM bookings WHERE course_id IN ($k)",
        "DELETE FROM course_sessions WHERE course_id IN ($k)",
        "DELETE FROM courses WHERE id IN ($k)",
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m)",
        "DELETE FROM members WHERE id IN ($m)",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $x) { echo '  (opprydding: ' . $x->getMessage() . ")\n"; }
    }
    foreach ($smsFor as $n => $v) {
        try { DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v)', ['n' => $n, 'v' => $v]); } catch (Throwable $x) {}
    }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

/** @return array{0:int,1:mixed} */
function post(string $sti, array $data, string $token): array
{
    global $port;
    $c = curl_init('http://127.0.0.1:' . $port . $sti);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Origin: ' . Config::nettsted(), 'Cookie: lissom_sesjon=' . $token, 'Content-Type: application/json']]);
    $raa = (string) curl_exec($c);
    $kode = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$kode, json_decode($raa, true) ?? $raa];
}
$vis = static fn(array $r): string => $r[0] . ' ' . mb_substr(is_string($r[1]) ? $r[1] : json_encode($r[1], JSON_UNESCAPED_UNICODE), 0, 300);
$sms = static fn(): array => DB::alle("SELECT mal, mottaker, tekst FROM notifications WHERE id > :f AND kanal = 'sms' ORDER BY id", ['f' => $varselFor]);
$epost = static fn(string $mal): int => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE id > :f AND kanal = 'epost' AND mal = :m", ['f' => $varselFor, 'm' => $mal]);
$tomKo = static fn() => DB::kjor('DELETE FROM notifications WHERE id > :f', ['f' => $varselFor]);

try {
    sjekk('HTTP-serveren klar', $klar);
    sjekk('migrasjon 284: begge SMS-malene finnes', (int) DB::verdi("SELECT COUNT(*) FROM notification_templates WHERE navn IN ('venteliste_ledig_sms','venteliste_tildelt_sms') AND kanal = 'sms'") === 2);
    $admin = DB::settInn('members', ['navn' => $tag . ' Monica', 'rolle' => 'admin', 'brukernavn' => strtolower($tag) . '-admin',
        'epost' => strtolower($tag) . '-admin@example.com', 'status' => 'ingen']);
    $medlemmer[] = $admin;
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $t), 'maate' => 'passord',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $kid = DB::settInn('courses', ['slug' => strtolower($tag) . '-fat', 'tittel' => 'Store fat kurs', 'type' => 'kurs',
        'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
    $kurs[] = $kid;
    // 7.11. kl. 18 norsk tid, et år fram, så datoen alltid er i framtiden.
    $aar = (int) gmdate('Y') + 1;
    $start = (new DateTimeImmutable("$aar-11-07 18:00:00", new DateTimeZone('Europe/Oslo')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $okt = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $start, 'status' => 'planlagt', 'kapasitet' => 8]);
    $ny = static function (string $navn, ?string $tlf, ?int $oktId) use ($kid, $tag): int {
        return DB::settInn('waitlist', ['course_id' => $kid, 'course_session_id' => $oktId, 'navn' => $navn . ' ' . $tag,
            'epost' => strtolower($navn) . '-' . strtolower($tag) . '@example.com', 'telefon' => $tlf, 'posisjon' => 1, 'status' => 'venter']);
    };
    foreach (['sveve_bruker' => 'test', 'sveve_passord' => 'test', 'sms_leverandor' => 'sveve'] as $n => $v) {
        DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)', ['n' => $n, 'v' => $v]);
    }
    Config::glemBasen();

    echo "\n── A Tilby plassen ───────────────────────────────────────────\n";
    $anita = $ny('Anita', '41111111', $okt);
    $r = post('/api/admin/venteliste.php', ['handling' => 'varsle', 'id' => $anita], $t);
    sjekk('varsle svarer 200', $r[0] === 200, $vis($r));
    $l = $sms();
    sjekk('én SMS med godkjent tekst og kort dato', count($l) === 1 && $l[0]['mal'] === 'venteliste_ledig_sms'
        && $l[0]['tekst'] === 'Hei Anita, det ble ledig plass pa store fat kurs 7.11. Forste som booker far plassen: lissom.no/kurs Hilsen Lissom', json_encode($l, JSON_UNESCAPED_UNICODE));
    sjekk('e-posten går som før', $epost('venteliste_ledig') === 1);
    $tomKo();
    $kari = $ny('Kari', '42222222', null);
    post('/api/admin/venteliste.php', ['handling' => 'varsle', 'id' => $kari], $t);
    $l = $sms();
    sjekk('uten dato: ingen dato og ingen dobbel mellomrom', ($l[0]['tekst'] ?? '') === 'Hei Kari, det ble ledig plass pa store fat kurs. Forste som booker far plassen: lissom.no/kurs Hilsen Lissom', json_encode($l, JSON_UNESCAPED_UNICODE));
    $tomKo();

    echo "\n── B Gi plass ────────────────────────────────────────────────\n";
    $per = $ny('Per', '43333333', $okt);
    $r = post('/api/admin/venteliste.php', ['handling' => 'gi-plass', 'id' => $per, 'oktId' => $okt], $t);
    sjekk('gi-plass svarer 200', $r[0] === 200, $vis($r));
    $l = $sms();
    sjekk('én SMS med godkjent tekst', count($l) === 1 && $l[0]['mal'] === 'venteliste_tildelt_sms'
        && $l[0]['tekst'] === 'Hei Per, du har fatt plass pa store fat kurs 7.11. Se plassen pa Min side: lissom.no/min-side Hilsen Lissom', json_encode($l, JSON_UNESCAPED_UNICODE));
    sjekk('e-posten går som før', $epost('venteliste_tildelt') === 1);
    $tomKo();

    echo "\n── C Uten telefon / uten SMS ─────────────────────────────────\n";
    $uten = $ny('Lise', null, $okt);
    post('/api/admin/venteliste.php', ['handling' => 'varsle', 'id' => $uten], $t);
    sjekk('uten telefon: ingen SMS, e-posten går', $sms() === [] && $epost('venteliste_ledig') === 1);
    $tomKo();
    DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')");
    $ola = $ny('Ola', '44444444', $okt);
    post('/api/admin/venteliste.php', ['handling' => 'varsle', 'id' => $ola], $t);
    $sveveFil = (string) Config::hent('sveve_bruker', '');
    sjekk('uten SMS satt opp: ingen SMS-mal i køen', $sveveFil !== '' || !in_array('venteliste_ledig_sms', array_column($sms(), 'mal'), true), json_encode($sms(), JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage());
}
$ferdig = true;
echo "\n$ok OK, $feil FEIL\n";
exit($feil === 0 ? 0 : 1);
