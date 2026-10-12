<?php
/**
 * Medlemmer og oppfølging (eieren 12.10.2026, visningen «Medlemmer og oppfølging»).
 *
 *   1. Migrasjon 273 tåler to kjøringer; malene har tekstene ordrett, to står av.
 *   2. Prøv Lissom slutter snart: saken, samlet SMS til admin-mobilen kl. 09
 *      (én per dag), SMS til prøvemedlemmet 3 dager før (bare når malen er på).
 *   3. SMS til medlemmer som ikke kommer: tidsvindu, én per fravær, bryter per
 *      medlem, aldri samme dag som en annen SMS, ikke prøve/pause, fri tilgang.
 *   4. «Snakket med» tar saken bort; notatet står på medlemskortet.
 *   5. API-et (php -S) og ny admin (medlemmer.js, idag.js, innstillinger.js).
 *
 * Ingen SMS sendes: alt havner i køen (notifications), som ryddes etterpå.
 *   php tests/oppfolging.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$tag = 'OPPF-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = [];
$ferdig = false;
$maler = array_column(DB::alle("SELECT navn, aktiv FROM notification_templates WHERE navn IN ('intern_prove_slutter_sms','prove_slutter_sms','medlem_savner_deg_sms')"), 'aktiv', 'navn');
$innst = array_column(DB::alle("SELECT nokkel, verdi FROM innstillinger WHERE nokkel LIKE 'oppfolging_%'"), 'verdi', 'nokkel');
$server = null;
// «Den falske SMS-en» (som tests/send-manuelt.php): Sveve-oppsettet settes til
// testverdier bare under testen, så meldingene havner i køen. Ingen cron kjører.
$smsFor = array_column(DB::alle("SELECT nokkel, verdi FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')"), 'verdi', 'nokkel');

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$server, $maler, $innst, $smsFor): void {
    if (is_resource($server)) { proc_terminate($server); }
    try {
        DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')");
        foreach ($smsFor as $n => $v) { DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v)', ['n' => $n, 'v' => $v]); }
    } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    foreach ([
        "DELETE FROM notifications WHERE ref_type IN ('prove_slutter','savner_deg','testsms') AND ref_id IN ($m)",
        "DELETE FROM notifications WHERE ref_type = 'prove_slutter_admin' AND mottaker = '+4799887766'",
        "DELETE FROM medlem_oppfolging WHERE member_id IN ($m)",
        "DELETE FROM check_ins WHERE member_id IN ($m)",
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m)",
        "DELETE FROM members WHERE id IN ($m)",
        "DELETE FROM innstillinger WHERE nokkel LIKE 'oppfolging_%'",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    }
    foreach ($innst as $k => $v) { try { DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (?, ?)', [$k, $v]); } catch (Throwable $e) {} }
    foreach ($maler as $n => $a) { try { DB::oppdater('notification_templates', ['aktiv' => (int) $a], ['navn' => $n]); } catch (Throwable $e) {} }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

$oslo = new DateTimeZone('Europe/Oslo');
$naa = static fn(string $t): DateTimeImmutable => new DateTimeImmutable($t, $oslo);
$utc = static fn(string $t): string => (new DateTimeImmutable($t, $oslo))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$nytt = static function (array $felt) use (&$medlemmer, $tag): int {
    $id = DB::settInn('members', $felt + ['epost' => strtolower($tag) . '-' . bin2hex(random_bytes(3)) . '@example.com']);
    $medlemmer[] = $id;
    return $id;
};
$ko = static fn(string $refType, int $id): array => DB::alle('SELECT * FROM notifications WHERE ref_type = :t AND ref_id = :i ORDER BY id', ['t' => $refType, 'i' => $id]);
$malPaa = static fn(string $n, bool $p) => DB::oppdater('notification_templates', ['aktiv' => $p ? 1 : 0], ['navn' => $n]);

DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')");
foreach (['sveve_bruker' => 'test', 'sveve_passord' => 'test', 'sms_leverandor' => 'sveve'] as $n => $v) {
    DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v)', ['n' => $n, 'v' => $v]);
}
Config::glemBasen();
sjekk('falsk SMS er på (bare køen)', Varsel::smsMulig());

echo "\n── Migrasjon 273────────────────────────────────────────────\n";
$sql = (string) file_get_contents($rot . '/db/migrations/273_medlemsoppfolging.sql');
foreach (['første', 'andre'] as $gang) {
    try { DB::kobling()->exec($sql); $f = null; } catch (Throwable $e) { $f = $e->getMessage(); }
    sjekk("$gang kjøring", $f === null, (string) $f);
}
DB::glemSkjema();
sjekk('godkjent av eieren 12.10', str_starts_with($sql, '-- godkjent av eieren: 2026-10-12'));
sjekk('bare tillegg', preg_match('/\b(DROP|MODIFY|DELETE|UPDATE)\b/i', str_replace('ON DUPLICATE KEY UPDATE', '', $sql)) !== 1);
sjekk('Oppfolging::klar()', Oppfolging::klar());
$tekst = static fn(string $n): string => (string) DB::verdi('SELECT tekst FROM notification_templates WHERE navn = :n', ['n' => $n]);
sjekk('admin-SMS ordrett', $tekst('intern_prove_slutter_sms') === 'Prøv Lissom slutter {dato} for {antall} stk: {liste}. Se I dag i admin.');
sjekk('prøve-SMS ordrett', $tekst('prove_slutter_sms') === 'Hei {navn}! Prøv Lissom varer til {dag} {dato}. Vil du fortsette, velger du medlemskap her: lissom.no/min-side Hilsen Monica');
sjekk('savner-SMS ordrett (med 🙂)', $tekst('medlem_savner_deg_sms') === 'Hei {navn}! Vi savner deg i verkstedet 🙂 Du har {timer} igjen denne måneden. Velkommen innom når det passer. Hilsen Monica, Lissom');
sjekk('migrasjonen setter prøve- og savner-SMS av', str_contains($sql, "lissom.no/min-side Hilsen Monica', 0, 'system')") && str_contains($sql, "Hilsen Monica, Lissom', 0, 'system')"));
foreach (['intern_prove_slutter_sms', 'prove_slutter_sms', 'medlem_savner_deg_sms'] as $n) {
    sjekk("$n står i malregisteret", in_array($n, Maler::iBruk(), true));
}
sjekk('standard: 5 dager / 21 dager', Oppfolging::STD_PROVE_DAGER === 5 && Oppfolging::STD_SAVNER_DAGER === 21);

echo "\n── Prøv Lissom slutter snart ────────────────────────────────\n";
$prove = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 1 AND aktiv = 1 ORDER BY sortering LIMIT 1');
$lopende = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 0 AND aktiv = 1 AND timer IS NOT NULL ORDER BY sortering LIMIT 1');
sjekk('planene finnes (fra membership_plans)', $prove !== '' && $lopende !== '');
$a = $nytt(['navn' => "Johanna $tag", 'telefon' => '90011222', 'status' => 'prove', 'medlemskap_type' => $prove, 'start_dato' => '2026-10-03', 'rolle' => 'medlem']);
$liste = Oppfolging::proveSlutterSnart(5, $naa('2026-10-26 09:30'));
$rad = array_values(array_filter($liste, static fn($p) => $p['id'] === $a))[0] ?? null;
sjekk('på lista 5 dager før', $rad !== null && $rad['dagerIgjen'] === 5 && $rad['slutt'] === '2026-10-31', json_encode($rad));
sjekk('ikke på lista 6 dager før', !in_array($a, array_column(Oppfolging::proveSlutterSnart(5, $naa('2026-10-25 09:30')), 'id'), true));
sjekk('ikke etter at perioden er over', !in_array($a, array_column(Oppfolging::proveSlutterSnart(5, $naa('2026-11-01 09:30')), 'id'), true));
sjekk('«fredag 30. oktober»', Oppfolging::langDato('2026-10-30') === 'fredag 30. oktober');
sjekk('telefon «900 11 222»', Oppfolging::visTelefon('90011222') === '900 11 222');

$malPaa('intern_prove_slutter_sms', true);
Oppfolging::lagreInnstillinger(['adminMobil' => '99887766']);
sjekk('kl. 08: ingen admin-SMS', Oppfolging::sendProveAdmin($naa('2026-10-26 08:30')) === 0);
$n = Oppfolging::sendProveAdmin($naa('2026-10-26 09:05'));
$adm = DB::alle("SELECT * FROM notifications WHERE ref_type = 'prove_slutter_admin' AND mottaker = '+4799887766'");
sjekk('kl. 09: én samlet SMS til admin-mobilen', $n >= 1 && count($adm) === 1, "n=$n rader=" . count($adm));
sjekk('… med teksten fra visningen', str_starts_with((string) ($adm[0]['tekst'] ?? ''), 'Prøv Lissom slutter 31.10 for ')
    && str_contains((string) $adm[0]['tekst'], "Johanna $tag 900 11 222") && str_ends_with((string) $adm[0]['tekst'], '. Se I dag i admin.'), (string) ($adm[0]['tekst'] ?? ''));
sjekk('samme dag: ingen ny', Oppfolging::sendProveAdmin($naa('2026-10-26 10:05')) === 0);
sjekk('neste dag: ingen ny for de samme', Oppfolging::sendProveAdmin($naa('2026-10-27 09:05')) === 0);
$malPaa('intern_prove_slutter_sms', false);
DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('oppfolging_prove_admin_dag','oppfolging_prove_admin_meldt')");
sjekk('malen av: ingen admin-SMS', Oppfolging::sendProveAdmin($naa('2026-10-28 09:05')) === 0);

$malPaa('prove_slutter_sms', false);
sjekk('prøve-SMS av fra start: ingenting', Oppfolging::sendProveMedlem($naa('2026-10-28 11:00')) === 0 && $ko('prove_slutter', $a) === []);
$malPaa('prove_slutter_sms', true);
sjekk('4 dager før: ingen prøve-SMS', Oppfolging::sendProveMedlem($naa('2026-10-27 11:00')) === 0 || $ko('prove_slutter', $a) === []);
Oppfolging::sendProveMedlem($naa('2026-10-28 11:00'));
$p = $ko('prove_slutter', $a);
sjekk('3 dager før: SMS til prøvemedlemmet', count($p) === 1 && $p[0]['tekst'] === 'Hei Johanna! Prøv Lissom varer til lørdag 31.10. Vil du fortsette, velger du medlemskap her: lissom.no/min-side Hilsen Monica', json_encode($p, JSON_UNESCAPED_UNICODE));
Oppfolging::sendProveMedlem($naa('2026-10-29 11:00'));
sjekk('… bare én gang', count($ko('prove_slutter', $a)) === 1);

Oppfolging::snakketMed($a, 'prove', 'Vil ha Mini', null);
sjekk('«Snakket med» tar saken bort', !in_array($a, array_column(Oppfolging::proveSlutterSnart(5, $naa('2026-10-26 09:30')), 'id'), true));
$not = Oppfolging::notater($a);
sjekk('notatet står på medlemskortet', ($not[0]['notat'] ?? '') === 'Vil ha Mini' && ($not[0]['sak'] ?? '') === 'prove');

$a2 = $nytt(['navn' => "Siri $tag", 'telefon' => '92233444', 'status' => 'aktiv', 'medlemskap_type' => $lopende, 'start_dato' => '2026-10-03', 'rolle' => 'medlem']);
sjekk('har valgt medlemskap: ingen sak', !in_array($a2, array_column(Oppfolging::proveSlutterSnart(5, $naa('2026-10-26 09:30')), 'id'), true));

echo "\n── SMS til medlemmer som ikke kommer ────────────────────────\n";
$b = $nytt(['navn' => "Kari $tag", 'telefon' => '91122333', 'status' => 'aktiv', 'medlemskap_type' => $lopende, 'start_dato' => '2025-03-02', 'rolle' => 'medlem']);
DB::settInn('check_ins', ['member_id' => $b, 'inn_tid' => $utc('2026-09-20 12:00'), 'ut_tid' => $utc('2026-09-20 13:00'), 'minutter' => 60]);
$malPaa('medlem_savner_deg_sms', false);
sjekk('av fra start: ingenting', Oppfolging::sendSavner($naa('2026-10-26 11:00')) === 0 && $ko('savner_deg', $b) === []);
$malPaa('medlem_savner_deg_sms', true);
sjekk('lørdag: ingenting', Oppfolging::sendSavner($naa('2026-10-24 11:00')) === 0 && $ko('savner_deg', $b) === []);
sjekk('kl. 09: ingenting', Oppfolging::sendSavner($naa('2026-10-26 09:00')) === 0 && $ko('savner_deg', $b) === []);
sjekk('kl. 18: ingenting', Oppfolging::sendSavner($naa('2026-10-26 18:00')) === 0 && $ko('savner_deg', $b) === []);
Oppfolging::sendSavner($naa('2026-10-26 11:00'));
$s = $ko('savner_deg', $b);
sjekk('hverdag kl. 11, 36 dager borte: SMS', count($s) === 1 && $s[0]['mal'] === 'medlem_savner_deg_sms', json_encode($s, JSON_UNESCAPED_UNICODE));
sjekk('… teksten fra visningen', preg_match('/^Hei Kari! Vi savner deg i verkstedet 🙂 Du har [\d,]+ timer? igjen denne måneden\. Velkommen innom når det passer\. Hilsen Monica, Lissom$/u', (string) ($s[0]['tekst'] ?? '')) === 1, (string) ($s[0]['tekst'] ?? ''));
Oppfolging::sendSavner($naa('2026-10-27 11:00'));
sjekk('ikke innom igjen: ingen ny SMS', count($ko('savner_deg', $b)) === 1);
// Innom igjen etter SMS-en, og borte på nytt.
DB::kjor("UPDATE notifications SET created_at = :t WHERE ref_type = 'savner_deg' AND ref_id = :i", ['t' => $utc('2026-08-01 11:00'), 'i' => $b]);
Oppfolging::sendSavner($naa('2026-10-27 11:00'));
sjekk('innom igjen og borte på nytt: ny SMS', count($ko('savner_deg', $b)) === 2);

$c = $nytt(['navn' => "Tone $tag", 'telefon' => '93344555', 'status' => 'aktiv', 'medlemskap_type' => $lopende, 'start_dato' => '2025-03-02', 'rolle' => 'medlem', 'paaminnelse_sms' => 0]);
$d = $nytt(['navn' => "Lise $tag", 'telefon' => '94455666', 'status' => 'pause', 'medlemskap_type' => $lopende, 'start_dato' => '2025-03-02', 'rolle' => 'medlem']);
$e = $nytt(['navn' => "Berit $tag", 'telefon' => '95566777', 'status' => 'aktiv', 'medlemskap_type' => $lopende, 'start_dato' => '2025-03-02', 'rolle' => 'medlem']);
DB::settInn('notifications', ['kanal' => 'sms', 'mottaker' => '+4795566777', 'tekst' => 'annen', 'ref_type' => 'testsms', 'ref_id' => $e, 'created_at' => $utc('2026-10-28 08:00')]);
$f = $nytt(['navn' => "Fri $tag", 'telefon' => '96677888', 'status' => 'aktiv', 'medlemskap_type' => $lopende, 'timer_per_mnd' => null, 'start_dato' => '2025-03-02', 'rolle' => 'medlem']);
$k = array_column(Oppfolging::savnerKandidater($naa('2026-10-28 11:00')), 'id');
sjekk('bryteren per medlem av: ingen SMS', !in_array($c, $k, true));
sjekk('pause: ingen SMS', !in_array($d, $k, true));
sjekk('prøvemedlem: ingen SMS', !in_array($a, $k, true));
sjekk('annen SMS samme dag: ingen SMS', !in_array($e, $k, true));
sjekk('neste dag: får den', in_array($e, array_column(Oppfolging::savnerKandidater($naa('2026-10-29 11:00')), 'id'), true));
$fri = (string) DB::verdi('SELECT navn FROM membership_plans WHERE timer IS NULL LIMIT 1');
if ($fri !== '') {
    DB::oppdater('members', ['medlemskap_type' => $fri], ['id' => $f]);
    Oppfolging::sendSavner($naa('2026-10-28 11:00'));
    $sf = $ko('savner_deg', $f);
    sjekk('fri tilgang (ingen timer å fylle inn): ingen SMS', $sf === [], json_encode($sf, JSON_UNESCAPED_UNICODE));
}

echo "\n── Lav aktivitet og «Snakket med» ───────────────────────────\n";
sjekk('Kari står under Lav aktivitet', in_array($b, array_column(Oppfolging::lavAktivitet(), 'id'), true));
Oppfolging::snakketMed($b, 'lav', 'Syk mor, kommer i november', null);
sjekk('«Snakket med» tar Lav aktivitet bort', !in_array($b, array_column(Oppfolging::lavAktivitet(), 'id'), true));
$ble = Oppfolging::bleMedlem();
sjekk('«X av Y ble medlem» har tallene', isset($ble['ble'], $ble['av']) && array_key_exists('prosent', $ble) && $ble['av'] >= $ble['ble']);

echo "\n── API (php -S) ─────────────────────────────────────────────\n";
$ledig = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($ledig, false); fclose($ledig);
$port = (int) substr($adr, strrpos($adr, ':') + 1);
$logg = sys_get_temp_dir() . '/lissom-oppf-' . bin2hex(random_bytes(4)) . '.log';
$server = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
for ($v = 0; $v < 60; $v++) { $fs = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($fs) { fclose($fs); break; } usleep(50000); }
$admin = $nytt(['navn' => "Admin $tag", 'telefon' => '97788999', 'status' => 'ingen', 'rolle' => 'admin']);
$token = bin2hex(random_bytes(32));
DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token), 'maate' => 'passord', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
$kall = static function (string $sti, ?array $data) use (&$port, $token): array {
    $c = curl_init('http://127.0.0.1:' . $port . $sti);
    $h = ['Origin: ' . Config::nettsted(), 'Cookie: lissom_sesjon=' . $token];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60];
    if ($data !== null) { $h[] = 'Content-Type: application/json'; $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($data); }
    $o[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($c, $o);
    $raa = (string) curl_exec($c);
    $kode = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$kode, json_decode($raa, true) ?? $raa];
};
[$kode, $d1] = $kall('/api/admin/oppfolging.php', null);
sjekk('GET: sakene, tallet og innstillingene', $kode === 200 && ($d1['klar'] ?? false) === true && isset($d1['prove'], $d1['lav'], $d1['innstillinger']['proveDager']) && array_key_exists('ble', $d1), $kode . ' ' . mb_substr(json_encode($d1, JSON_UNESCAPED_UNICODE), 0, 300));
[$kode, $d2] = $kall('/api/admin/oppfolging.php', ['handling' => 'innstillinger', 'adminMobil' => 'abc']);
sjekk('ugyldig mobilnummer avvises', $kode >= 400);
[$kode, $d2] = $kall('/api/admin/oppfolging.php', ['handling' => 'innstillinger', 'proveDager' => 7, 'savnerDager' => 28]);
sjekk('dagene lagres', $kode === 200 && ($d2['innstillinger']['proveDager'] ?? 0) === 7 && ($d2['innstillinger']['savnerDager'] ?? 0) === 28);
[$kode] = $kall('/api/admin/oppfolging.php', ['handling' => 'paaminnelse', 'medlemId' => $b, 'paa' => 'nei']);
sjekk('bryteren per medlem lagres', $kode === 200 && (int) DB::verdi('SELECT paaminnelse_sms FROM members WHERE id = :i', ['i' => $b]) === 0);
[$kode, $d3] = $kall('/api/admin/oppfolging.php?medlem=' . $b, null);
sjekk('medlemskortet: notat og sendte SMS', $kode === 200 && ($d3['notater'][0]['notat'] ?? '') === 'Syk mor, kommer i november' && ($d3['paaminnelse']['antall'] ?? 0) === 2);
[$kode] = $kall('/api/admin/oppfolging.php', ['handling' => 'snakket', 'medlemId' => $b, 'sak' => 'tull']);
sjekk('ukjent sak avvises', $kode >= 400);
[$kode, $d4] = $kall('/api/admin/medlemmer.php', null);
$mb = array_values(array_filter($d4['medlemmer'] ?? [], static fn($x) => $x['id'] === $b))[0] ?? [];
sjekk('medlemmer.php: medlemFra og paaminnelseSms', ($mb['medlemFra'] ?? '') === '2025-03-02' && ($mb['paaminnelseSms'] ?? null) === false);
sjekk('medlemmer.php: planene i sorteringsrekkefølge med engangs', ($d4['planer'][0]['navn'] ?? '') === (string) DB::verdi('SELECT navn FROM membership_plans WHERE aktiv = 1 ORDER BY sortering LIMIT 1') && array_key_exists('engangs', $d4['planer'][0] ?? []));
proc_terminate($server);

echo "\n── Ny admin ─────────────────────────────────────────────────\n";
foreach (['ny-admin/medlemmer.js', 'ny-admin/idag.js', 'ny-admin/innstillinger.js'] as $js) {
    exec('node --check ' . escapeshellarg($rot . '/' . $js) . ' 2>&1', $u, $kd);
    sjekk("node --check $js", $kd === 0, implode(' ', $u));
}
$mjs = (string) file_get_contents($rot . '/ny-admin/medlemmer.js');
sjekk('fem sorteringer', str_contains($mjs, "['lengst', 'Medlem lengst'], ['kortest', 'Nyeste medlem'], ['type', 'Type medlemskap'], ['navn', 'Navn A–Å'], ['sist', 'Lengst siden innom']"));
sjekk('sorteringen huskes per bruker', str_contains($mjs, "'lissom-nyadmin-medlemmer-sortering-' + (NA().meg?.id || '')") && str_contains($mjs, 'localStorage.setItem(sorteringNokkel(), v)'));
sjekk('«Medlem i X · siden mån. år»', str_contains($mjs, 'Medlem i ${esc(varighet(m.medlemFra))} · siden ${esc(maanedAar(m.medlemFra))}'));
sjekk('«Sist innom»: ingenting til og med 7 dager, gul 14–21, rød over 21', str_contains($mjs, "if (d <= 7) return '';") && str_contains($mjs, "d > 21 ? 'rod' : d >= 14 ? 'gul' : ''"));
sjekk('filterpiller med antall', str_contains($mjs, '`Alle (${alle.length})`'));
sjekk('«X av Y ble medlem»', str_contains($mjs, '${b.ble} av ${b.av} ble medlem'));
sjekk('bryteren på medlemskortet', str_contains($mjs, 'Påminnelse-SMS når hen ikke kommer') && str_contains($mjs, "handling: 'paaminnelse'"));
$ijs = (string) file_get_contents($rot . '/ny-admin/idag.js');
sjekk('I dag: Prøv Lissom med Send SMS og Snakket med', str_contains($ijs, 'Prøv Lissom slutter ${naar}') && str_contains($ijs, '>Send SMS</button>') && str_contains($ijs, '>Snakket med</button>'));
$sjs = (string) file_get_contents($rot . '/ny-admin/innstillinger.js');
sjekk('Varsler: medlemsoppfølging med brytere og dager', str_contains($sjs, "'SMS når Prøv Lissom snart slutter'") && str_contains($sjs, "'SMS til medlemmer som ikke kommer'") && str_contains($sjs, 'data-oppf-felt="adminMobil"'));
$cron = (string) file_get_contents($rot . '/bin/cron.php');
sjekk('cron «varsler» kjører oppfølgingen', str_contains($cron, '$o = Oppfolging::kjor();'));

$ferdig = true;
echo "\n$ok OK, $feil feil\n";
exit($feil === 0 ? 0 : 1);
