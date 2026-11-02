<?php
/**
 * «＋ Lag nytt kurs …» i «Nytt kurs eller serie» (eieren 02.11.2026, «ja, fint» på visningen).
 *
 *   A  kurs.php handling=lagre med id 0 og feltene arket sender, lager kurset (tittel, pris, plasser,
 *      status kladd/publisert, beskrivelse, type kurs, SMS av) og det står i kurslista (GET kurs.php).
 *   B  Uten navn svarer API-et med feilmeldingen arket viser som toast.
 *   C  Ny admin: valget øverst i Kursmal, arket «Nytt kurs» med ordrett tekst, ingen forhåndsvalg, og
 *      etter lagring er kurset valgt med plasser og pris fra kurset.
 *
 * Ekte endepunkt (php -S med tests/nettleser/ruter.php) mot en isolert testbase. Ingenting sendes.
 *   php tests/nytt-kurs-ark.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
$port = (int) substr($adr, strrpos($adr, ':') + 1);
$logg = sys_get_temp_dir() . '/lissom-nyttkurs-' . bin2hex(random_bytes(4)) . '.log';
$server = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

$tag = 'NYTTKURS-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $kurs = []; $ferdig = false;

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, $server): void {
    if (is_resource($server)) { proc_terminate($server); }
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $k = implode(',', array_map('intval', $kurs ?: [0]));
    foreach ([
        "DELETE FROM courses WHERE id IN ($k)",
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m)",
        "DELETE FROM members WHERE id IN ($m)",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $x) { echo '  (opprydding: ' . $x->getMessage() . ")\n"; }
    }
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

    echo "\n── A Lagre nytt kurs ────────────────────────────────────────\n";
    foreach (['kladd', 'publisert'] as $status) {
        $r = kall('/api/admin/kurs.php', ['handling' => 'lagre', 'id' => 0, 'tittel' => $tag . ' ' . $status, 'pris' => 650,
            'kapasitet' => 6, 'status' => $status, 'om' => 'Beskrivelse ' . $status, 'type' => 'kurs', 'sms' => 'nei'], $t);
        $id = (int) ($r[1]['id'] ?? 0);
        if ($id) { $kurs[] = $id; }
        sjekk("lagre id 0 ($status) svarer 200 med ny id", $r[0] === 200 && $id > 0, $vis($r));
        $rad = DB::en('SELECT tittel, pris_ore, kapasitet, status, beskrivelse, type, sms_paaminnelse FROM courses WHERE id = :i', ['i' => $id]) ?? [];
        sjekk("kurset ligger i basen ($status)", ($rad['tittel'] ?? '') === $tag . ' ' . $status && (int) ($rad['pris_ore'] ?? 0) === 65000
            && (int) ($rad['kapasitet'] ?? 0) === 6 && ($rad['status'] ?? '') === $status && ($rad['beskrivelse'] ?? '') === 'Beskrivelse ' . $status
            && ($rad['type'] ?? '') === 'kurs' && (int) ($rad['sms_paaminnelse'] ?? 1) === 0, json_encode($rad, JSON_UNESCAPED_UNICODE));
    }
    $g = kall('/api/admin/kurs.php', null, $t);
    $iLista = array_values(array_filter($g[1]['kurs'] ?? [], static fn($k) => in_array((int) $k['id'], $kurs, true)));
    sjekk('står i kurslista med pris og plasser arket fyller inn', count($iLista) === 2 && $iLista[0]['kapasitet'] === 6 && (int) $iLista[0]['pris'] === 650,
        json_encode($iLista[0] ?? null, JSON_UNESCAPED_UNICODE));

    echo "\n── B Feil vises fra API-et ──────────────────────────────────\n";
    $r = kall('/api/admin/kurs.php', ['handling' => 'lagre', 'id' => 0, 'tittel' => '', 'pris' => 650, 'kapasitet' => 6, 'status' => 'kladd', 'type' => 'kurs'], $t);
    sjekk('uten navn: feilmelding fra API-et', $r[0] >= 400 && ($r[1]['feil'] ?? '') === 'Kurset må ha en tittel.', $vis($r));

    echo "\n── C Ny admin ───────────────────────────────────────────────\n";
    exec('node --check ' . escapeshellarg($rot . '/ny-admin/kalender.js') . ' 2>&1', $u, $kd);
    sjekk('node --check ny-admin/kalender.js', $kd === 0);
    $js = (string) file_get_contents($rot . '/ny-admin/kalender.js');
    sjekk('«＋ Lag nytt kurs …» rett under «Velg kurs …»', str_contains($js, '<option value="0">Velg kurs …</option><option value="ny">＋ Lag nytt kurs …</option>'));
    foreach (['<h2>Nytt kurs</h2>', 'Kurset legges i lista over kursmaler. Etterpå setter du opp datoene.', '<small>Kursnavn</small>',
        '<small>Pris (kr)</small><input id="nk-pris"', '<small>Plasser</small><input id="nk-plasser"', 'Synlig på nettsida',
        'data-v="kladd"', 'Utkast – ikke synlig ennå', 'data-v="publisert"', '<small>Beskrivelse</small><textarea class="felt" id="nk-om">',
        'Bilder og resten av teksten legger du inn under Kurs › Rediger, som i dag.', 'data-k="nkTilbake">Tilbake</button>',
        'data-k="nkLagre" ${nkKlar() ? \'\' : \'disabled\'}>Lagre kurset</button>', "toast('Kurset er lagret.');"] as $bit) {
        sjekk('arket har ' . $bit, str_contains($js, $bit));
    }
    sjekk('ingen forhåndsvalg på synlighet', str_contains($js, "synlig: ''") && str_contains($js, '+NK.plasser > 0 && NK.synlig'));
    sjekk('lagrer med kurs.php handling=lagre id 0', str_contains($js, "api('kurs.php', {handling: 'lagre', id: 0, tittel: NK.navn.trim(), pris: +pris, kapasitet: +NK.plasser,"));
    sjekk('feil fra API-et vises som toast, arket står', str_contains($js, '} catch (e) { b.disabled = false; return toast(esc(e.message)); }'));
    sjekk('etter lagring: kurset valgt, plasser og pris fra kurset', str_contains($js, 'Object.assign(SE, {kursId: +r.id, holderId: 0, plasser: ny?.kapasitet || +NK.plasser, pris: String(ny?.pris ?? pris)});'));
    sjekk('Tilbake beholder serie-arket', str_contains($js, 'H.nkTilbake = () => tegnSerie();') && str_contains($js, 'const forrige = SE.kursId; lesSerie(); SE.kursId = forrige; arkNyttKurs();'));
} catch (Throwable $x) {
    sjekk('uventet feil', false, $x->getMessage());
}

$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
