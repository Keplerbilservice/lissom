<?php
/**
 * Ferdige i Må gjøres og Folk › Forespørsler (eieren 02.11.2026, «bygg og publiser» på visningen).
 *
 *   A  GET foresporsler.php: nyeste øverst (ORDER BY id DESC), ubesvarte sorteres ikke først.
 *   B  Én kilde (enquiries): merkes en forespørsel besvart, er den borte fra «maGjores» og står i
 *      «ferdige» med tid og hvem. Merkes den ubesvart (Angre / Merk ubesvart), er det omvendt.
 *   C  Bryteren «Vis/ferdigeliste» = nei gir ferdige = null.
 *   D  Ny admin: Ferdige-lista i idag.js, fanen og telefonsiden i medlemmer.js, ☰ i felles.js.
 *
 * Ekte endepunkter (php -S med tests/nettleser/ruter.php) mot en isolert testbase. Ingenting sendes.
 *   php tests/ferdige-foresporsler.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
$port = (int) substr($adr, strrpos($adr, ':') + 1);
$logg = sys_get_temp_dir() . '/lissom-ferdige-' . bin2hex(random_bytes(4)) . '.log';
$server = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

$tag = 'FERDIG-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $henv = []; $ferdig = false;
$bryterFor = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/ferdigeliste'");

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$henv, $server, $bryterFor): void {
    if (is_resource($server)) { proc_terminate($server); }
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $e = implode(',', array_map('intval', $henv ?: [0]));
    foreach ([
        "DELETE FROM enquiries WHERE id IN ($e)",
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m)",
        "DELETE FROM members WHERE id IN ($m)",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $x) { echo '  (opprydding: ' . $x->getMessage() . ")\n"; }
    }
    try {
        if ($bryterFor === null) { DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/ferdigeliste'"); }
        else { DB::kjor("UPDATE content_blocks SET verdi = :v WHERE nokkel = 'Vis/ferdigeliste'", ['v' => $bryterFor]); }
    } catch (Throwable $x) {}
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

/** @return array{0:int,1:mixed} */
function kall(string $sti, ?array $data, string $token): array
{
    global $port;
    $c = curl_init('http://127.0.0.1:' . $port . $sti);
    $hode = ['Origin: ' . Config::nettsted(), 'Cookie: lissom_sesjon=' . $token];
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
$vis = static fn(array $r): string => $r[0] . ' ' . mb_substr(is_string($r[1]) ? $r[1] : json_encode($r[1], JSON_UNESCAPED_UNICODE), 0, 300);

try {
    sjekk('HTTP-serveren klar', $klar);
    $admin = DB::settInn('members', ['navn' => $tag . ' Ingrid', 'rolle' => 'admin', 'brukernavn' => strtolower($tag) . '-admin',
        'epost' => strtolower($tag) . '-admin@example.com', 'status' => 'ingen']);
    $medlemmer[] = $admin;
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $t), 'maate' => 'passord',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $ny = static function (string $navn, string $status) use (&$henv, $tag): int {
        $id = DB::settInn('enquiries', ['navn' => $tag . ' ' . $navn, 'epost' => strtolower($tag) . '@example.com',
            'melding' => 'Testforespørsel ' . $navn, 'status' => $status]);
        $henv[] = $id;
        return $id;
    };
    $a = $ny('Anne', 'ubesvart');
    $b = $ny('Bjørn', 'ubesvart');
    $c = $ny('Cecilie', 'besvart');

    echo "\n── A Sortering ──────────────────────────────────────────────\n";
    $r = kall('/api/admin/foresporsler.php', null, $t);
    $ider = array_values(array_filter(array_map(static fn($f) => (int) $f['id'], $r[1]['foresporsler'] ?? []), static fn($i) => in_array($i, [$a, $b, $c], true)));
    sjekk('GET svarer 200', $r[0] === 200, $vis($r));
    sjekk('nyeste øverst, også når den er besvart', $ider === [$c, $b, $a], json_encode($ider));

    echo "\n── B Én kilde: Må gjøres ↔ Ferdige ──────────────────────────\n";
    $finnes = static fn(array $d, string $felt, int $id): ?array => array_values(array_filter($d[$felt] ?? [], static fn($s) => ($s['type'] ?? '') === 'henvendelse' && (int) $s['id'] === $id))[0] ?? null;
    $o = kall('/api/admin/oversikt.php', null, $t);
    sjekk('oversikt svarer 200 med ferdige-lista', $o[0] === 200 && is_array($o[1]['ferdige'] ?? null), $vis($o));
    sjekk('ubesvart står i Må gjøres', $finnes($o[1], 'maGjores', $a) !== null);
    sjekk('ubesvart står ikke i Ferdige', $finnes($o[1], 'ferdige', $a) === null);

    $r = kall('/api/admin/foresporsler.php', ['id' => $a, 'status' => 'besvart'], $t);
    sjekk('Ferdig (status=besvart) lagres', $r[0] === 200, $vis($r));
    sjekk('besvart_at og besvart_av satt i enquiries', DB::verdi('SELECT besvart_av FROM enquiries WHERE id = :i AND besvart_at IS NOT NULL', ['i' => $a]) == $admin);
    $o = kall('/api/admin/oversikt.php', null, $t);
    $f = $finnes($o[1], 'ferdige', $a);
    sjekk('borte fra Må gjøres', $finnes($o[1], 'maGjores', $a) === null);
    sjekk('står i Ferdige med Angre', $f !== null && $f['angre'] === true && $f['merke'] === 'Merket ferdig', json_encode($f, JSON_UNESCAPED_UNICODE));
    sjekk('Ferdige viser tid og hvem', $f !== null && abs((int) $f['tid'] - time()) < 120 && $f['av'] === $tag, json_encode($f, JSON_UNESCAPED_UNICODE));
    $r = kall('/api/admin/foresporsler.php', null, $t);
    $fa = array_values(array_filter($r[1]['foresporsler'] ?? [], static fn($x) => (int) $x['id'] === $a))[0] ?? [];
    sjekk('står som Besvart under Forespørsler', ($fa['status'] ?? '') === 'Besvart');

    $r = kall('/api/admin/foresporsler.php', ['id' => $a, 'status' => 'ubesvart'], $t);
    sjekk('Angre (status=ubesvart) lagres', $r[0] === 200, $vis($r));
    $o = kall('/api/admin/oversikt.php', null, $t);
    sjekk('tilbake i Må gjøres', $finnes($o[1], 'maGjores', $a) !== null);
    sjekk('borte fra Ferdige', $finnes($o[1], 'ferdige', $a) === null);

    echo "\n── C Bryter ─────────────────────────────────────────────────\n";
    DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/ferdigeliste', 'nei') ON DUPLICATE KEY UPDATE verdi = 'nei'");
    $o = kall('/api/admin/oversikt.php', null, $t);
    sjekk('Vis/ferdigeliste = nei gir ferdige = null', $o[0] === 200 && array_key_exists('ferdige', $o[1]) && $o[1]['ferdige'] === null, $vis($o));

    echo "\n── D Ny admin ───────────────────────────────────────────────\n";
    foreach (['ny-admin/idag.js', 'ny-admin/medlemmer.js', 'ny-admin/felles.js'] as $fil) {
        exec('node --check ' . escapeshellarg($rot . '/' . $fil) . ' 2>&1', $u, $kd);
        sjekk('node --check ' . $fil, $kd === 0);
    }
    $idag = (string) file_get_contents($rot . '/ny-admin/idag.js');
    $mlm = (string) file_get_contents($rot . '/ny-admin/medlemmer.js');
    $felles = (string) file_get_contents($rot . '/ny-admin/felles.js');
    sjekk('Ferdige nederst i Må gjøres, lukket til den åpnes', str_contains($idag, '${radFerdige(d)}') && str_contains($idag, 'let ferdigeApen = false'));
    sjekk('Angre bare på forespørsel og Klar til henting', str_contains($idag, "if (type === 'henting') return arkHentet(f);") && str_contains($idag, "{data: {id, status: 'ubesvart'}}"));
    sjekk('Folk har fanen Forespørsler', str_contains($mlm, 'data-v="foresporsler"'));
    sjekk('egen side for telefonen', str_contains($mlm, "registrer('foresporsler', { tittel: 'Forespørsler', ikon: '☺', tegn: tegnFspSide, mobil: true });"));
    sjekk('Forespørsler i ☰ på telefon', str_contains($felles, "['foresporsler', 'Forespørsler', '☺']"));
    sjekk('etter handling hentes oversikt og forespørsler på nytt', str_contains($mlm, "NA().oppdaterTopp?.(); await hentFsp();"));
} catch (Throwable $x) {
    sjekk('uventet feil', false, $x->getMessage());
}

$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
