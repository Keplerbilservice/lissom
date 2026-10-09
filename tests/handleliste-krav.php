<?php
/**
 * «Krev inn med Vipps» og «Send bestilling» i handlelista (api/admin/handlelister.php),
 * mot den falske Vippsen. Kontrolløren 9. oktober 2026 sa STOPP på tre ting:
 * ingen lås (to faner gir to krav), bestillingen kunne sendes før kravet (da får
 * medlemmene aldri krav), og ny admin viste bare «Oppdatert.».
 *
 *   (a) Send bestilling før kravet: sperret (409), navnene står i meldingen,
 *       ingenting er bestilt og ingen e-post til leverandøren.
 *   (b) Låsen holdt av en annen prosess: kravet svarer 409, ingenting til Vipps.
 *   (c) To krav samtidig (to servere, curl_multi): ett krav per medlem, riktig
 *       beløp (varer + gebyr), samme nøkkel hos Vipps som på raden, og den som
 *       mangler telefon står i «feilet» med grunnen.
 *   (d) Tredje trykk: ingen nye krav, ingenting til Vipps.
 *   (e) Vipps sier nei (400, salgsenheten har ikke lov): «feilet» med norsk grunn
 *       (ingen MSN), linja er ledig igjen og ingen rad står igjen.
 *   (f) Tapt svar fra Vipps (raden står «opprettet»): nytt trykk sender samme
 *       referanse og samme Idempotency-Key, og bestillingen er sperret imens.
 *   (g) Linja tatt av noe annet midt i (trigger): alt rulles tilbake, ingenting
 *       til Vipps, og grunnen vises.
 *   (h) Ny linje etter kravet: tilleggskrav på varene + gebyret, uten frakt.
 *   (i) Når alle har krav: Send bestilling går, linjene er bestilt, én e-post.
 *
 * Ekte endepunkter (to php -S med tests/nettleser/ruter.php) mot en isolert
 * testbase og den falske Vippsen (tests/falsk-vipps.mjs). Ingen ekte Vipps,
 * e-post eller SMS. Alt merkes «HlKrav-» og ryddes etterpå.
 *   php tests/handleliste-krav.php   (kjøres av tests/handleliste-krav.mjs via tests/nettleser/kjor.sh)
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';
if (!str_starts_with((string) Config::hent('vipps_base'), 'http://127.0.0.1:')) {
    throw new RuntimeException('Krever lokal falsk Vipps');
}

// Barneprosessen i (b): holder låsen kravet bruker, i noen sekunder.
if (($argv[1] ?? '') === 'hold') {
    DB::verdi("SELECT GET_LOCK(CONCAT('handleliste-krav:', DATABASE()), 5)");
    echo "låst\n";
    fflush(STDOUT);
    sleep((int) $argv[2]);
    exit;
}

$ledigPort = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
    return (int) substr($adr, strrpos($adr, ':') + 1);
};
$servere = [];
$porter = [];
$logg = sys_get_temp_dir() . '/lissom-hlkrav-' . bin2hex(random_bytes(4)) . '.log';
foreach ([0, 1] as $i) {
    $porter[$i] = $ledigPort();
    $servere[$i] = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $porter[$i], '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
    fclose($pp[0]);
}
$klar = true;
foreach ($porter as $p) {
    $opp = false;
    for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.1); if ($f) { fclose($f); $opp = true; break; } usleep(50000); }
    $klar = $klar && $opp;
}

$styr = static fn(string $navn): string => __DIR__ . '/' . $navn;
$krav400For = is_file($styr('.krav-400')) ? (string) file_get_contents($styr('.krav-400')) : null;
$sett400 = static function (?string $v) use ($styr): void { if ($v === null) { @unlink($styr('.krav-400')); } else { file_put_contents($styr('.krav-400'), $v); } };

$tag = 'HlKrav-' . strtoupper(bin2hex(random_bytes(3)));
$ferdig = false;
$medlemmer = []; $lev = 0; $varer = []; $linjer = []; $parkert = [];
$gebyrFor = DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'handleliste_gebyr_prosent'");

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$lev, &$varer, &$linjer, &$parkert, $servere, $sett400, $krav400For, $gebyrFor): void {
    foreach ($servere as $s) { if (is_resource($s)) { proc_terminate($s); } }
    $sett400($krav400For);
    try { DB::kobling()->exec('DROP TRIGGER IF EXISTS hlkrav_testtrigger'); } catch (Throwable $e) {}
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $l = implode(',', array_map('intval', $linjer ?: [0]));
    $o = implode(',', array_map('intval', array_column(DB::alle("SELECT id FROM orders WHERE member_id IN ($m)"), 'id')) ?: [0]);
    foreach ([
        "UPDATE handleliste_linjer SET order_id = NULL WHERE id IN ($l)",
        "DELETE FROM handleliste_linjer WHERE id IN ($l)",
        "DELETE FROM order_lines WHERE order_id IN ($o)",
        "UPDATE payments SET order_id = NULL WHERE member_id IN ($m)",
        "DELETE FROM orders WHERE id IN ($o)",
        "DELETE FROM payments WHERE member_id IN ($m)",
        "DELETE FROM notifications WHERE ref_type = 'leverandor' AND ref_id = " . (int) $lev,
        'DELETE FROM products WHERE id IN (' . implode(',', array_map('intval', $varer ?: [0])) . ')',
        'DELETE FROM leverandorer WHERE id = ' . (int) $lev,
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m) OR (objekt_type = 'order' AND objekt_id IN ($o))",
        "DELETE FROM members WHERE id IN ($m)",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    }
    try {
        if ($parkert !== []) { DB::kjor("UPDATE handleliste_linjer SET status = 'sendt' WHERE id IN (" . implode(',', array_map('intval', $parkert)) . ')'); }
        $gebyrFor === null || $gebyrFor === false
            ? DB::kjor("DELETE FROM innstillinger WHERE nokkel = 'handleliste_gebyr_prosent'")
            : DB::kjor("UPDATE innstillinger SET verdi = :v WHERE nokkel = 'handleliste_gebyr_prosent'", ['v' => (string) $gebyrFor]);
    } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

/** @return array{0:int,1:mixed} */
function kall(string $sti, ?array $data, string $token = '', int $server = 0): array
{
    global $porter;
    $c = curl_init('http://127.0.0.1:' . $porter[$server] . $sti);
    curl_setopt_array($c, kallValg($data, $token));
    $raa = (string) curl_exec($c);
    $kode = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$kode, json_decode($raa, true) ?? $raa];
}
function kallValg(?array $data, string $token): array
{
    $hode = ['Origin: ' . Config::nettsted()];
    if ($token !== '') { $hode[] = 'Cookie: lissom_sesjon=' . $token; }
    $valg = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60];
    if ($data !== null) {
        $hode[] = 'Content-Type: application/json';
        $valg[CURLOPT_POST] = true;
        $valg[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    $valg[CURLOPT_HTTPHEADER] = $hode;
    return $valg;
}
$vis = static fn(array $s): string => $s[0] . ' ' . mb_substr(is_string($s[1]) ? $s[1] : json_encode($s[1], JSON_UNESCAPED_UNICODE), 0, 500);

$vippsLogg = __DIR__ . '/.falsk-vipps.jsonl';
$lengde = static fn(): int => is_file($vippsLogg) ? count(file($vippsLogg)) : 0;
/** Opprettelsene (POST /epayment/v1/payments) siden $fra, til et av numrene. */
$opprett = static function (int $fra, array $tlf) use ($vippsLogg): array {
    if (!is_file($vippsLogg)) { return []; }
    $ut = [];
    foreach (array_slice(file($vippsLogg), $fra) as $l) {
        $k = json_decode($l, true);
        if (($k['metode'] ?? '') === 'POST' && ($k['sti'] ?? '') === '/epayment/v1/payments'
            && in_array((string) ($k['kropp']['customer']['phoneNumber'] ?? ''), $tlf, true)) {
            $ut[] = $k;
        }
    }
    return $ut;
};
$krav = static fn(int $medlem): array => DB::alle(
    "SELECT * FROM payments WHERE member_id = :m AND vipps_reference LIKE 'HL-%' ORDER BY id", ['m' => $medlem]);
$ko = static fn(int $lev): int => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = 'leverandor' AND ref_id = :l", ['l' => $lev]);

try {
    sjekk('to HTTP-servere klare', $klar);
    sjekk('SMS er ikke satt opp i testen (ingenting kan gå ut)', !Varsel::smsMulig());

    // Andre testers linjer som venter på krav, parkeres så kravløpet bare treffer våre (settes tilbake etterpå).
    $parkert = array_map('intval', array_column(DB::alle(
        "SELECT id FROM handleliste_linjer WHERE status = 'sendt' AND order_id IS NULL AND bestilt_at IS NULL"), 'id'));
    if ($parkert !== []) { DB::kjor("UPDATE handleliste_linjer SET status = 'apen' WHERE id IN (" . implode(',', $parkert) . ')'); }

    $nytt = static function (string $navn, ?string $tlf, string $rolle = 'medlem') use (&$medlemmer, $tag): int {
        $id = DB::settInn('members', ['navn' => $tag . ' ' . $navn, 'rolle' => $rolle, 'status' => 'aktiv', 'telefon' => $tlf,
            'epost' => strtolower($tag) . '-' . strtolower($navn) . '@example.com'] + ($rolle === 'admin' ? ['brukernavn' => strtolower($tag) . '-admin'] : []));
        $medlemmer[] = $id;
        return $id;
    };
    $admin = $nytt('Monica', null, 'admin');
    $tA = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $tA), 'maate' => 'passord', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $anna = $nytt('Anna', '+4790000001');
    $berit = $nytt('Berit', '+4790000002');
    $cato = $nytt('Cato', null);
    $tlfA = '4790000001'; $tlfB = '4790000002'; $tlfC = '4790000003';

    $lev = DB::settInn('leverandorer', ['navn' => $tag . ' Leirhuset', 'epost' => strtolower($tag) . '-lev@example.com', 'bestillingsmaate' => 'epost', 'aktiv' => 1]);
    $vare = DB::settInn('products', ['tittel' => $tag . ' Steintøyleire', 'pris_ore' => 29000, 'status' => 'publisert', 'leverandor_id' => $lev, 'artikkelnr' => 'HK-1']);
    $varer[] = $vare;
    $linje = static function (int $medlem, int $produkt, int $antall) use (&$linjer): int {
        $id = DB::settInn('handleliste_linjer', ['member_id' => $medlem, 'product_id' => $produkt, 'antall' => $antall, 'status' => 'sendt', 'sendt_at' => gmdate('Y-m-d H:i:s')]);
        $linjer[] = $id;
        return $id;
    };
    $lA = $linje($anna, $vare, 2); $lB = $linje($berit, $vare, 1); $lC = $linje($cato, $vare, 1);
    $g = kall('/api/admin/handlelister.php', ['handling' => 'gebyr', 'prosent' => '10'], $tA);
    $p = kall('/api/admin/handlelister.php', ['handling' => 'pris', 'produktId' => $vare, 'kroner' => '150'], $tA);
    sjekk('oppsett: gebyr 10 % og stykkpris 150 kr', $g[0] === 200 && $p[0] === 200
        && (int) DB::verdi('SELECT pris_ore FROM handleliste_linjer WHERE id = :i', ['i' => $lA]) === 15000, $vis($g) . ' / ' . $vis($p));
    $orderId = static fn(int $l): ?int => ($v = DB::verdi('SELECT order_id FROM handleliste_linjer WHERE id = :i', ['i' => $l])) === null ? null : (int) $v;

    // (a) Bestilling før kravet
    $fra = $lengde();
    $a = kall('/api/admin/handlelister.php', ['handling' => 'bestill', 'leverandorId' => $lev], $tA);
    $af = (string) ($a[1]['feil'] ?? '');
    sjekk('(a) Send bestilling før kravet er sperret (409) og sier hvem som mangler krav', $a[0] === 409
        && str_contains($af, 'Send Vipps-kravet før bestillingen') && str_contains($af, $tag . ' Anna') && str_contains($af, $tag . ' Cato'), $vis($a));
    sjekk('(a) ingenting bestilt, ingen e-post til leverandøren, ingenting til Vipps', DB::verdi('SELECT COUNT(*) FROM handleliste_linjer WHERE bestilt_at IS NOT NULL AND id IN (' . implode(',', $linjer) . ')') == 0
        && $ko($lev) === 0 && $opprett($fra, [$tlfA, $tlfB, $tlfC]) === []);

    // (b) Låsen holdt av en annen prosess
    $holder = proc_open([PHP_BINARY, __FILE__, 'hold', '3'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $hr);
    $laast = trim((string) fgets($hr[1])) === 'låst';
    $fra = $lengde();
    $b = kall('/api/admin/handlelister.php', ['handling' => 'krav'], $tA);
    sjekk('(b) kravet mens en annen holder låsen: 409 «sendes alt», ingenting til Vipps, ingen krav', $laast && $b[0] === 409
        && str_contains((string) ($b[1]['feil'] ?? ''), 'sendes alt') && $opprett($fra, [$tlfA, $tlfB]) === [] && $krav($anna) === [], $vis($b));
    proc_close($holder);

    // (c) To krav samtidig, mot to servere
    $fra = $lengde();
    $mh = curl_multi_init();
    $hs = [];
    foreach ([0, 1] as $i) {
        $hs[$i] = curl_init('http://127.0.0.1:' . $porter[$i] . '/api/admin/handlelister.php');
        curl_setopt_array($hs[$i], kallValg(['handling' => 'krav'], $tA));
        curl_multi_add_handle($mh, $hs[$i]);
    }
    do { $st = curl_multi_exec($mh, $aktive); if ($aktive) { curl_multi_select($mh, 1.0); } } while ($aktive && $st === CURLM_OK);
    $svar = [];
    foreach ($hs as $i => $h) {
        $svar[$i] = [(int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), json_decode((string) curl_multi_getcontent($h), true)];
        curl_multi_remove_handle($mh, $h);
    }
    curl_multi_close($mh);
    $vA = $opprett($fra, [$tlfA]); $vB = $opprett($fra, [$tlfB]);
    $kA = $krav($anna); $kB = $krav($berit);
    sjekk('(c) to samtidige krav: ett Vipps-krav og én betalingsrad per medlem', count($vA) === 1 && count($vB) === 1 && count($kA) === 1 && count($kB) === 1,
        'vipps A ' . count($vA) . ' B ' . count($vB) . ' rader A ' . count($kA) . ' B ' . count($kB) . ' — ' . $vis($svar[0]) . ' / ' . $vis($svar[1]));
    $hoved = ($svar[0][0] === 200 && in_array($tag . ' Anna', $svar[0][1]['sendt'] ?? [], true)) ? $svar[0] : $svar[1];
    $andre = $hoved === $svar[0] ? $svar[1] : $svar[0];
    sjekk('(c) den ene sendte (Anna og Berit), den andre ble stoppet av låsen eller fant ingenting nytt',
        $hoved[0] === 200 && in_array($tag . ' Berit', $hoved[1]['sendt'] ?? [], true)
        && ($andre[0] === 409 || ($andre[0] === 200 && array_intersect([$tag . ' Anna', $tag . ' Berit'], $andre[1]['sendt'] ?? []) === [])), $vis($svar[0]) . ' / ' . $vis($svar[1]));
    sjekk('(c) riktig beløp: Anna 2 × 150 kr + 10 % = 330 kr, Berit 150 kr + 10 % = 165 kr (i Vipps og på raden)',
        (int) ($vA[0]['kropp']['amount']['value'] ?? 0) === 33000 && (int) ($kA[0]['belop_ore'] ?? 0) === 33000
        && (int) ($vB[0]['kropp']['amount']['value'] ?? 0) === 16500 && (int) ($kB[0]['belop_ore'] ?? 0) === 16500
        && (int) DB::verdi('SELECT SUM(antall * pris_ore) FROM order_lines WHERE order_id = :o', ['o' => (int) $kA[0]['order_id']]) === 33000);
    sjekk('(c) PUSH_MESSAGE til riktig nummer, samme Idempotency-Key hos Vipps som på raden, raden «venter»',
        ($vA[0]['kropp']['userFlow'] ?? '') === 'PUSH_MESSAGE' && ($vA[0]['nokkel'] ?? '') === (string) $kA[0]['idempotency_key']
        && ($vA[0]['kropp']['reference'] ?? '') === (string) $kA[0]['vipps_reference'] && (string) $kA[0]['status'] === 'venter');
    sjekk('(c) linjene peker på kravets ordre', $orderId($lA) === (int) $kA[0]['order_id'] && $orderId($lB) === (int) $kB[0]['order_id'] && $orderId($lC) === null);
    $fC = array_values(array_filter($hoved[1]['feilet'] ?? [], static fn($f) => $f['navn'] === $tag . ' Cato'))[0] ?? null;
    sjekk('(c) Cato uten telefon står i «feilet» med grunnen, og beskjeden sier hvem som fikk krav og hvem ikke',
        $fC !== null && $fC['grunn'] === 'Mangler telefonnummer.'
        && str_contains((string) ($hoved[1]['beskjed'] ?? ''), 'Krav sendt til ') && str_contains((string) ($hoved[1]['beskjed'] ?? ''), 'Ikke sendt: ' . $tag . ' Cato — Mangler telefonnummer.'), $vis($hoved));

    // (d) Tredje trykk: ingenting nytt
    $fra = $lengde();
    $d = kall('/api/admin/handlelister.php', ['handling' => 'krav'], $tA);
    sjekk('(d) tredje trykk: ingen nye krav til Anna og Berit, ingenting til Vipps', $d[0] === 200 && ($d[1]['sendt'] ?? null) === []
        && $opprett($fra, [$tlfA, $tlfB]) === [] && count($krav($anna)) === 1 && count($krav($berit)) === 1, $vis($d));
    $bC = kall('/api/admin/handlelister.php', ['handling' => 'bestill', 'leverandorId' => $lev], $tA);
    sjekk('(d) bestillingen er fortsatt sperret, nå bare for Cato', $bC[0] === 409 && str_contains((string) ($bC[1]['feil'] ?? ''), $tag . ' Cato')
        && !str_contains((string) ($bC[1]['feil'] ?? ''), $tag . ' Anna'), $vis($bC));

    // (e) Vipps sier nei
    DB::oppdater('members', ['telefon' => '+' . $tlfC], ['id' => $cato]);
    $sett400('ja');
    $fra = $lengde();
    $e = kall('/api/admin/handlelister.php', ['handling' => 'krav'], $tA);
    $sett400(null);
    $fe = array_values(array_filter($e[1]['feilet'] ?? [], static fn($f) => $f['navn'] === $tag . ' Cato'))[0] ?? null;
    sjekk('(e) 400 fra Vipps: Cato står i «feilet» med norsk grunn, uten Vipps sin tekst eller MSN', $e[0] === 200 && $fe !== null
        && $fe['grunn'] === 'Vipps nekter salgsenheten å sende betalingskrav.' && !str_contains(json_encode($e[1], JSON_UNESCAPED_UNICODE), 'MSN'), $vis($e));
    sjekk('(e) ett forsøk hos Vipps, ingen betalingsrad eller ordre igjen, linja er ledig', count($opprett($fra, [$tlfC])) === 1 && $krav($cato) === []
        && $orderId($lC) === null && (int) DB::verdi('SELECT COUNT(*) FROM orders WHERE member_id = :m', ['m' => $cato]) === 0);

    // (f) Tapt svar: raden står «opprettet». Neste trykk sender det samme.
    $f1 = kall('/api/admin/handlelister.php', ['handling' => 'krav'], $tA);
    $kC = $krav($cato);
    sjekk('(f) Cato får krav (150 kr + 10 % = 165 kr)', $f1[0] === 200 && in_array($tag . ' Cato', $f1[1]['sendt'] ?? [], true) && count($kC) === 1
        && (int) $kC[0]['belop_ore'] === 16500, $vis($f1));
    DB::oppdater('payments', ['status' => 'opprettet'], ['id' => (int) $kC[0]['id']]);
    $fs = kall('/api/admin/handlelister.php', ['handling' => 'bestill', 'leverandorId' => $lev], $tA);
    sjekk('(f) uavklart krav: bestillingen er sperret til Vipps har svart', $fs[0] === 409 && str_contains((string) ($fs[1]['feil'] ?? ''), 'Vipps har ikke bekreftet kravet til ' . $tag . ' Cato'), $vis($fs));
    $fra = $lengde();
    $f2 = kall('/api/admin/handlelister.php', ['handling' => 'krav'], $tA);
    $vC = $opprett($fra, [$tlfC]);
    $kC2 = $krav($cato);
    sjekk('(f) nytt trykk etter tapt svar: samme referanse og samme Idempotency-Key, ingen ny rad, raden «venter»', $f2[0] === 200 && count($vC) === 1
        && ($vC[0]['kropp']['reference'] ?? '') === (string) $kC[0]['vipps_reference'] && ($vC[0]['nokkel'] ?? '') === (string) $kC[0]['idempotency_key']
        && (int) ($vC[0]['kropp']['amount']['value'] ?? 0) === 16500 && count($kC2) === 1 && (string) $kC2[0]['status'] === 'venter', $vis($f2));

    // (g) Linja tatt av noe annet midt i kravet
    $vare2 = DB::settInn('products', ['tittel' => $tag . ' Glasur', 'pris_ore' => 5000, 'status' => 'publisert', 'leverandor_id' => $lev, 'artikkelnr' => 'HK-2']);
    $varer[] = $vare2;
    $lB2 = $linje($berit, $vare2, 1);
    kall('/api/admin/handlelister.php', ['handling' => 'pris', 'produktId' => $vare2, 'kroner' => '50'], $tA);
    DB::kobling()->exec('CREATE TRIGGER hlkrav_testtrigger BEFORE INSERT ON orders FOR EACH ROW UPDATE handleliste_linjer SET order_id = '
        . (int) $kB[0]['order_id'] . ' WHERE id = ' . $lB2 . ' AND order_id IS NULL');
    $fra = $lengde();
    $gk = kall('/api/admin/handlelister.php', ['handling' => 'krav'], $tA);
    DB::kobling()->exec('DROP TRIGGER IF EXISTS hlkrav_testtrigger');
    $fg = array_values(array_filter($gk[1]['feilet'] ?? [], static fn($f) => $f['navn'] === $tag . ' Berit'))[0] ?? null;
    sjekk('(g) linja tatt imens: alt rulles tilbake, ingenting til Vipps, grunnen vises', $gk[0] === 200 && $fg !== null
        && $fg['grunn'] === 'Lista ble endret imens. Ingenting er sendt. Prøv igjen.' && $opprett($fra, [$tlfB]) === []
        && count($krav($berit)) === 1 && $orderId($lB2) === null, $vis($gk));

    // (h) Tilleggskrav på linja som kom etter kravet
    $hl = kall('/api/admin/handlelister.php', null, $tA);
    $mB = array_values(array_filter($hl[1]['medlemmer'] ?? [], static fn($m) => (int) $m['medlemId'] === $berit))[0] ?? null;
    sjekk('(h) oppgjøret viser at Berit ikke er ferdig krevd inn (ny linje uten krav)', $mB !== null && $mB['kravSendt'] === false && $mB['harKrav'] === true, $vis($hl));
    $fra = $lengde();
    $h = kall('/api/admin/handlelister.php', ['handling' => 'krav'], $tA);
    $vB2 = $opprett($fra, [$tlfB]);
    $kB2 = $krav($berit);
    sjekk('(h) tilleggskrav: 50 kr + 10 % = 55 kr, uten frakt, ett Vipps-krav, de første linjene ikke krevd igjen', $h[0] === 200 && count($vB2) === 1
        && (int) ($vB2[0]['kropp']['amount']['value'] ?? 0) === 5500 && count($kB2) === 2 && (int) $kB2[1]['belop_ore'] === 5500
        && $orderId($lB2) === (int) $kB2[1]['order_id'] && $orderId($lB) === (int) $kB[0]['order_id'] && $opprett($fra, [$tlfA, $tlfC]) === [], $vis($h));

    // (i) Alle har krav: bestillingen går
    $i = kall('/api/admin/handlelister.php', ['handling' => 'bestill', 'leverandorId' => $lev], $tA);
    sjekk('(i) Send bestilling når alle har krav: linjene er bestilt, én e-post til leverandøren', $i[0] === 200
        && (int) DB::verdi('SELECT COUNT(*) FROM handleliste_linjer WHERE bestilt_at IS NOT NULL AND id IN (' . implode(',', $linjer) . ')') === count($linjer)
        && $ko($lev) === 1, $vis($i));
    $sumA = (int) DB::verdi("SELECT SUM(belop_ore) FROM payments WHERE member_id = :m AND vipps_reference LIKE 'HL-%'", ['m' => $anna]);
    $sumB = (int) DB::verdi("SELECT SUM(belop_ore) FROM payments WHERE member_id = :m AND vipps_reference LIKE 'HL-%'", ['m' => $berit]);
    $sumC = (int) DB::verdi("SELECT SUM(belop_ore) FROM payments WHERE member_id = :m AND vipps_reference LIKE 'HL-%'", ['m' => $cato]);
    sjekk('(i) til sammen krevd: Anna 330 kr, Berit 220 kr (165 + 55), Cato 165 kr — én gang hver', $sumA === 33000 && $sumB === 22000 && $sumC === 16500,
        "A $sumA B $sumB C $sumC");

    $ferdig = true;
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $ferdig = true;
}

echo "\n  $ok av " . ($ok + $feil) . " handleliste-krav-kontroller bestått\n";
exit($feil > 0 ? 1 : 0);
