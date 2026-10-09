<?php
/**
 * /ny-admin › Varer (eieren 09.10.2026): faner, rediger/fjern, minimum og maks,
 * og handlelista for verkstedets lager.
 *
 *   A  Fanene bygger på feltene som finnes: kunMedlemmer (Internlager, også
 *      «bare i admin»), iNettbutikk (Lissom kolleksjon) og medlemssalg
 *      (Medlemskolleksjon). produkter.php gir handleliste og harHandleliste.
 *   B  Minimum og maks lagres (lagerMin/lagerMaks), og en vare på eller under
 *      minimum står i litePaaLager og havner i handlelista (fyll opp til maks).
 *   C  bestillMer med eget antall endrer linja; ugyldig antall gir feil.
 *   D  handlelisteFjern fjerner bare verkstedets linje — et medlems linje for
 *      samme vare røres ikke, og kan ikke fjernes herfra.
 *   E  «Handlet»: linja blir ferdig og antallet legges på lageret, én gang
 *      (andre trykk gir feil og lageret står).
 *   F  Medlemskolleksjonen: endre lagrer navn, pris, kategori og antall; tomt
 *      navn og ugyldig pris avvises; slett fjerner varen.
 *   G  Fjern vare (produkter.php slett) sletter en vare som aldri er solgt.
 *   H  Tilgang: et vanlig medlem får ikke bruke handlingene.
 *
 * Ekte endepunkter (php -S med tests/nettleser/ruter.php) mot en isolert
 * testbase. SMTP og SMS er ikke satt opp; køen ryddes etterpå.
 *   php tests/ny-admin-varer.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
$port = (int) substr($adr, strrpos($adr, ':') + 1);
$logg = sys_get_temp_dir() . '/lissom-ny-admin-varer-' . bin2hex(random_bytes(4)) . '.log';
$server = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

$tag = 'VARER-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $produkter = []; $salg = []; $ferdig = false;

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$produkter, &$salg, $server): void {
    if (is_resource($server)) { proc_terminate($server); }
    $p = implode(',', array_map('intval', $produkter ?: [0]));
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $g = implode(',', array_map('intval', $salg ?: [0]));
    foreach ([
        "DELETE FROM handleliste_linjer WHERE product_id IN ($p)",
        "DELETE FROM notifications WHERE ref_type = 'product' AND ref_id IN ($p)",
        "DELETE FROM member_sales WHERE id IN ($g)",
        "DELETE FROM products WHERE id IN ($p)",
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
$vis = static fn(array $s): string => $s[0] . ' ' . mb_substr(is_string($s[1]) ? $s[1] : json_encode($s[1], JSON_UNESCAPED_UNICODE), 0, 300);
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
$lagre = static function (array $felt, string $t) use (&$produkter): array {
    $r = kall('/api/admin/produkter.php', $felt + ['handling' => 'lagre', 'id' => 0, 'pris' => 100, 'status' => 'publisert', 'mva' => 25,
        'kunMedlemmer' => 'nei', 'iNettbutikk' => 'nei', 'lager' => '', 'lagerMin' => '', 'lagerMaks' => ''], $t);
    if (($r[1]['id'] ?? 0) > 0 && !in_array($r[1]['id'], $produkter, true)) { $produkter[] = (int) $r[1]['id']; }
    return $r;
};
$hentVare = static fn(array $d, int $id): ?array => array_values(array_filter($d['varer'] ?? [], static fn($v) => $v['id'] === $id))[0] ?? null;

try {
    sjekk('HTTP-serveren klar', $klar);
    sjekk('SMS er ikke satt opp i testen (ingenting kan gå ut)', !Varsel::smsMulig());
    sjekk('Handlelista har verkstedets linjer (migrasjon 253)', Lager::harVerkstedslinjer());

    $admin = $nyttMedlem(['navn' => $tag . ' Monica', 'rolle' => 'admin', 'brukernavn' => strtolower($tag) . '-admin']);
    $vanlig = $nyttMedlem(['navn' => $tag . ' Kari', 'rolle' => 'medlem', 'status' => 'aktiv']);
    $tA = $sesjon($admin); $tM = $sesjon($vanlig);
    $lev = (int) DB::verdi("SELECT id FROM leverandorer WHERE aktiv = 1 ORDER BY id LIMIT 1");
    sjekk('Det finnes en leverandør i basen', $lev > 0);

    // ── A Fanene ─────────────────────────────────────────────────────────
    $intern = $lagre(['tittel' => $tag . ' Leire', 'kunMedlemmer' => 'ja', 'lager' => '2', 'lagerMin' => '5', 'lagerMaks' => '20', 'leverandorId' => $lev], $tA);
    $nett   = $lagre(['tittel' => $tag . ' Kopp', 'iNettbutikk' => 'ja', 'lager' => '4'], $tA);
    $begge  = $lagre(['tittel' => $tag . ' Fat', 'kunMedlemmer' => 'ja', 'iNettbutikk' => 'ja', 'lager' => '9'], $tA);
    $admin1 = $lagre(['tittel' => $tag . ' Glasur', 'lager' => '1'], $tA);
    sjekk('A fire varer lagret', ($intern[1]['id'] ?? 0) > 0 && ($nett[1]['id'] ?? 0) > 0 && ($begge[1]['id'] ?? 0) > 0 && ($admin1[1]['id'] ?? 0) > 0, $vis($intern));
    [$iId, $nId, $bId, $aId] = [(int) $intern[1]['id'], (int) $nett[1]['id'], (int) $begge[1]['id'], (int) $admin1[1]['id']];
    $d = kall('/api/admin/produkter.php', null, $tA)[1];
    // Samme vilkår som ny-admin/varer.js (erIntern / erLissom).
    $erIntern = static fn(array $v): bool => $v['kunMedlemmer'] || !$v['iNettbutikk'];
    $erLissom = static fn(array $v): bool => $v['iNettbutikk'];
    $fane = static fn(callable $f): array => array_values(array_map(static fn($v) => $v['id'],
        array_filter($d['varer'], static fn($v) => in_array($v['id'], [$iId, $nId, $bId, $aId], true) && $f($v))));
    sjekk('A Internlager = internbutikk og bare i admin (også den som er begge)', count(array_diff([$iId, $bId, $aId], $fane($erIntern))) === 0 && !in_array($nId, $fane($erIntern), true), json_encode($fane($erIntern)));
    sjekk('A Lissom kolleksjon = nettbutikken', count(array_diff([$nId, $bId], $fane($erLissom))) === 0 && !in_array($iId, $fane($erLissom), true) && !in_array($aId, $fane($erLissom), true), json_encode($fane($erLissom)));
    sjekk('A produkter.php gir handleliste og harHandleliste', is_array($d['handleliste'] ?? null) && ($d['harHandleliste'] ?? null) === true);

    // ── B Minimum og maks ────────────────────────────────────────────────
    $v = $hentVare($d, $iId);
    sjekk('B minimum og maks er lagret', $v !== null && $v['lagerMin'] === 5 && $v['lagerMaks'] === 20 && $v['leverandorId'] === $lev, json_encode($v));
    sjekk('B varen under minimum står i litePaaLager', (bool) array_filter($d['litePaaLager'], static fn($r) => $r['id'] === $iId && $r['bestill'] === 18));
    $linje = array_values(array_filter($d['handleliste'], static fn($l) => $l['produktId'] === $iId))[0] ?? null;
    sjekk('B varen havnet i handlelista med 18 (fyll opp til maks)', $linje !== null && $linje['antall'] === 18 && $linje['min'] === 5 && $linje['maks'] === 20, json_encode($d['handleliste']));

    // ── C Eget antall ────────────────────────────────────────────────────
    $c = kall('/api/admin/produkter.php', ['handling' => 'bestillMer', 'id' => $iId, 'antall' => 7], $tA);
    $d = kall('/api/admin/produkter.php', null, $tA)[1];
    $linjer = array_values(array_filter($d['handleliste'], static fn($l) => $l['produktId'] === $iId));
    sjekk('C bestillMer med antall 7 endrer linja (fortsatt én)', $c[0] === 200 && count($linjer) === 1 && $linjer[0]['antall'] === 7, $vis($c));
    $c0 = kall('/api/admin/produkter.php', ['handling' => 'bestillMer', 'id' => $iId, 'antall' => 0], $tA);
    sjekk('C antall 0 avvises', $c0[0] >= 400, $vis($c0));
    $cu = kall('/api/admin/produkter.php', ['handling' => 'bestillMer', 'id' => $nId, 'antall' => 3], $tA);
    sjekk('C vare uten leverandør: tydelig feil', $cu[0] >= 400 && str_contains((string) ($cu[1]['feil'] ?? ''), 'leverandør'), $vis($cu));

    // ── D Fjern bare verkstedets linje ───────────────────────────────────
    $medlemsLinje = DB::settInn('handleliste_linjer', ['member_id' => $vanlig, 'product_id' => $iId, 'antall' => 2, 'status' => 'sendt', 'sendt_at' => gmdate('Y-m-d H:i:s')]);
    $fm = kall('/api/admin/produkter.php', ['handling' => 'handlelisteFjern', 'linjeId' => $medlemsLinje], $tA);
    sjekk('D et medlems linje kan ikke fjernes herfra', $fm[0] >= 400 && DB::verdi('SELECT status FROM handleliste_linjer WHERE id = :i', ['i' => $medlemsLinje]) === 'sendt', $vis($fm));
    $d = kall('/api/admin/produkter.php', null, $tA)[1];
    sjekk('D handlelista viser bare verkstedets linjer', !array_filter($d['handleliste'], static fn($l) => $l['linjeId'] === $medlemsLinje));

    // ── E Handlet ────────────────────────────────────────────────────────
    $lid = $linjer[0]['linjeId'];
    $h = kall('/api/admin/produkter.php', ['handling' => 'handlet', 'linjeId' => $lid], $tA);
    $rad = DB::en('SELECT status, bestilt_at FROM handleliste_linjer WHERE id = :i', ['i' => $lid]);
    $lager = (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $iId]);
    sjekk('E handlet: linja er ferdig og lageret 2 + 7 = 9', $h[0] === 200 && $rad['status'] === 'ferdig' && $rad['bestilt_at'] !== null && $lager === 9 && ($h[1]['lager'] ?? null) === 9, $vis($h));
    sjekk('E linja er borte fra handlelista', !array_filter($h[1]['handleliste'] ?? [], static fn($l) => $l['linjeId'] === $lid));
    $h2 = kall('/api/admin/produkter.php', ['handling' => 'handlet', 'linjeId' => $lid], $tA);
    sjekk('E andre trykk gir feil, lageret står', $h2[0] >= 400 && (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $iId]) === 9, $vis($h2));
    sjekk('E medlemmets linje er urørt', DB::verdi('SELECT status FROM handleliste_linjer WHERE id = :i', ['i' => $medlemsLinje]) === 'sendt');

    // Legg til og fjern igjen.
    kall('/api/admin/produkter.php', ['handling' => 'bestillMer', 'id' => $iId, 'antall' => 4], $tA);
    $ny = DB::verdi("SELECT id FROM handleliste_linjer WHERE product_id = :p AND member_id IS NULL AND status = 'sendt' AND bestilt_at IS NULL", ['p' => $iId]);
    $fj = kall('/api/admin/produkter.php', ['handling' => 'handlelisteFjern', 'linjeId' => (int) $ny], $tA);
    sjekk('D legg til og fjern: linja er slettet', $ny !== null && $fj[0] === 200 && DB::verdi('SELECT id FROM handleliste_linjer WHERE id = :i', ['i' => (int) $ny]) === null, $vis($fj));

    // ── F Medlemskolleksjonen ────────────────────────────────────────────
    $gid = DB::settInn('member_sales', ['member_id' => $vanlig, 'tittel' => $tag . ' Krus', 'pris_ore' => 30000, 'vippsnummer' => '90000000',
        'kategori' => 'Kopper', 'antall' => 1, 'status' => 'publisert']);
    $salg[] = $gid;
    $ms = kall('/api/admin/medlemssalg.php', null, $tA);
    $rg = array_values(array_filter($ms[1]['salg'] ?? [], static fn($r) => $r['id'] === $gid))[0] ?? null;
    sjekk('F medlemssalg.php gir prisKr', $rg !== null && $rg['prisKr'] === 300, json_encode($rg));
    $e = kall('/api/admin/medlemssalg.php', ['handling' => 'endre', 'id' => $gid, 'tittel' => $tag . ' Stort krus', 'pris' => 450, 'kategori' => 'Krus', 'antall' => 3, 'beskrivelse' => 'Blå'], $tA);
    $r = DB::en('SELECT tittel, pris_ore, kategori, antall, beskrivelse, status, vippsnummer FROM member_sales WHERE id = :i', ['i' => $gid]);
    sjekk('F endre lagrer navn, pris, kategori, antall og beskrivelse (status og Vipps urørt)', $e[0] === 200 && $r['tittel'] === $tag . ' Stort krus'
        && (int) $r['pris_ore'] === 45000 && $r['kategori'] === 'Krus' && (int) $r['antall'] === 3 && $r['beskrivelse'] === 'Blå'
        && $r['status'] === 'publisert' && $r['vippsnummer'] === '90000000', $vis($e));
    $e0 = kall('/api/admin/medlemssalg.php', ['handling' => 'endre', 'id' => $gid, 'tittel' => '', 'pris' => 450], $tA);
    $ep = kall('/api/admin/medlemssalg.php', ['handling' => 'endre', 'id' => $gid, 'tittel' => 'X', 'pris' => 200000], $tA);
    sjekk('F tomt navn og ugyldig pris avvises', $e0[0] >= 400 && $ep[0] >= 400 && DB::verdi('SELECT tittel FROM member_sales WHERE id = :i', ['i' => $gid]) === $tag . ' Stort krus');
    $sl = kall('/api/admin/medlemssalg.php', ['handling' => 'slett', 'id' => $gid], $tA);
    sjekk('F slett fjerner varen', $sl[0] === 200 && DB::verdi('SELECT id FROM member_sales WHERE id = :i', ['i' => $gid]) === null, $vis($sl));

    // ── G Fjern vare ─────────────────────────────────────────────────────
    $fv = kall('/api/admin/produkter.php', ['handling' => 'slett', 'id' => $aId], $tA);
    sjekk('G fjern vare sletter en vare som aldri er solgt', $fv[0] === 200 && DB::verdi('SELECT id FROM products WHERE id = :i', ['i' => $aId]) === null, $vis($fv));

    // ── H Tilgang ────────────────────────────────────────────────────────
    foreach ([['/api/admin/produkter.php', ['handling' => 'handlet', 'linjeId' => 1]], ['/api/admin/produkter.php', ['handling' => 'handlelisteFjern', 'linjeId' => 1]],
              ['/api/admin/medlemssalg.php', ['handling' => 'endre', 'id' => 1, 'tittel' => 'x', 'pris' => 1]]] as [$sti, $data]) {
        $u = kall($sti, $data, $tM);
        sjekk('H vanlig medlem stoppes: ' . $data['handling'], $u[0] >= 400 && $u[0] !== 500, $vis($u));
    }
} catch (Throwable $e) {
    sjekk('Uventet feil', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

$ferdig = true;
echo "\n  $ok OK, $feil FEIL\n";
exit($feil > 0 ? 1 : 0);
