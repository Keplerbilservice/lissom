<?php
/**
 * Frys og fast trekk — scenarioer mot den falske Vippsen (ingen ekte kall).
 *
 * Eieren, 2. oktober 2026: stengingen starter foerst naar den betalte
 * perioden er over. Dager medlemmet har betalt for, har det alltid tilgang
 * i — ogsaa med en godkjent frys. Trekkrunden hopper over en maaned en
 * godkjent frys dekker minst 15 dager av (Medlemskap::hoppOverPause).
 *
 *   (a) frys over 20 dager i én maaned: trekket hoppes over, og medlemmet
 *       er stengt ute etter den betalte perioden
 *   (b) frys over 10 dager: trekket tas, og medlemmet har tilgang hele den
 *       betalte maaneden
 *   (c) engangsbetalt medlemskap med frys midt i perioden: tilgang til
 *       perioden er over, stengt etterpaa
 *   (d) flytting med avtale og frys: trekk() paa den flyttede avtalen hopper
 *       over riktig maaned — ogsaa for en avsluttet frys (forsinket runde)
 *
 * Datoene ligger i 2028, saa de ikke treffer dagens trekk. Kjoeres av
 * tests/frys-trekk.mjs via tests/nettleser/kjor.sh (falsk Vipps paa).
 */
declare(strict_types=1);
require __DIR__ . '/nettleser/testdatabase.php';
krev_testdatabase(dirname(__DIR__));
require dirname(__DIR__) . '/app/bootstrap.php';
if (!str_starts_with((string) Config::hent('vipps_base'), 'http://127.0.0.1:')) {
    throw new RuntimeException('Krever lokal falsk Vipps');
}

$ferdig = false;
$server = null;
$rydd = [];
register_shutdown_function(static function () use (&$ferdig, &$server, &$rydd): void {
    foreach ($rydd as $id) {
        try {
            DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'subscription'
                       AND objekt_id IN (SELECT id FROM subscriptions WHERE member_id = :m)", ['m' => $id]);
        } catch (Throwable $e) {}
        foreach (['check_ins', 'medlem_frys', 'payments', 'subscriptions', 'sessions'] as $t) {
            try { DB::kjor("DELETE FROM {$t} WHERE member_id = :m", ['m' => $id]); } catch (Throwable $e) {}
        }
        try { DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $id]); } catch (Throwable $e) {}
    }
    if (is_resource($server)) { proc_terminate($server); }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

$logg = __DIR__ . '/.falsk-vipps.jsonl';
$loggLengde = static fn(): int => is_file($logg) ? count(file($logg)) : 0;
$bestillinger = static function (string $agr, int $fra) use ($logg): int {
    if (!is_file($logg)) { return 0; }
    $n = 0;
    foreach (array_slice(file($logg), $fra) as $l) {
        $k = json_decode($l, true);
        if (($k['metode'] ?? '') === 'POST' && str_contains((string) ($k['sti'] ?? ''), $agr . '/charges')) { $n++; }
    }
    return $n;
};

$tag = 'frystrekk-' . bin2hex(random_bytes(3));
$plan = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 0 AND aktiv = 1 ORDER BY pris_ore LIMIT 1');
$engangs = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 1 AND aktiv = 1 ORDER BY sortering LIMIT 1');
$pris = (int) DB::verdi('SELECT pris_ore FROM membership_plans WHERE navn = :n', ['n' => $plan]);
$medlem = static function (string $status, string $plan, array $ekstra = []) use ($tag, &$rydd): int {
    $id = DB::settInn('members', $ekstra + ['navn' => 'Frystrekk ' . $tag, 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'telefon' => '+479' . random_int(1000000, 9999999), 'rolle' => 'medlem', 'status' => $status,
        'medlemskap_type' => $plan, 'start_dato' => '2028-01-01']);
    $rydd[] = $id;
    return $id;
};
$avtale = static function (int $m, string $agr, string $neste) use ($plan, $pris): int {
    return DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $plan, 'pris_ore' => $pris, 'status' => 'aktiv',
        'vipps_agreement_id' => $agr, 'neste_trekk' => $neste, 'trekk_dag' => 1]);
};
$betalt = static function (int $m, ?int $avt, string $fra) use ($pris): int {
    return DB::settInn('payments', ['member_id' => $m, 'subscription_id' => $avt, 'formal' => 'medlemskap',
        'type' => $avt ? 'recurring_charge' : 'epayment', 'status' => 'betalt', 'belop_ore' => $pris, 'gjelder_fra' => $fra,
        'vipps_reference' => 'TEST-' . bin2hex(random_bytes(12)), 'idempotency_key' => Vipps::uuid()]);
};
$frys = static fn(int $m, string $fra, string $til, string $status = 'godkjent', array $ekstra = []): int =>
    DB::settInn('medlem_frys', ['member_id' => $m, 'fra_dato' => $fra, 'til_dato' => $til, 'status' => $status, 'status_for' => 'aktiv'] + $ekstra);
$rad = static fn(int $id): array => (array) DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);
$avtaleRad = static function (int $id): array {
    $a = (array) DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $id]);
    $a['epost'] = ''; $a['navn'] = 'Frystrekk';   // ingen e-post fra trekket
    return $a;
};
$rader = static fn(int $avt): array => DB::alle('SELECT * FROM payments WHERE subscription_id = :s ORDER BY id', ['s' => $avt]);

// ── (a) frys over 20 dager i mars ───────────────────────────────────────
echo "\n── (a) frys over 20 dager: trekket hoppes over ─────────────\n";
$m = $medlem('aktiv', $plan);
$agr = 'agr_frysA_' . $tag;
$avt = $avtale($m, $agr, '2028-03-01');
$betalt($m, $avt, '2028-02-01');
$frys($m, '2028-02-20', '2028-03-25');
sjekk('25. februar (betalt, frysen har startet): ikke fryst', Frys::frystNaa($rad($m), '2028-02-25') === null);
sjekk('… og har tilgang', Medlemskap::harBetaltPeriode($rad($m), '2028-02-25'));
$fra = $loggLengde();
$ut = Medlemskap::trekk($avtaleRad($avt), '2028-02-26');
sjekk("trekket for mars hoppes over (svar: $ut)", count($rader($avt)) === 1 && $bestillinger($agr, $fra) === 0);
sjekk('… neste trekk 1. april', (string) $avtaleRad($avt)['neste_trekk'] === '2028-04-01');
sjekk('5. mars (perioden er over): fryst til 25. mars', Frys::frystNaa($rad($m), '2028-03-05') === ['til' => '2028-03-25'],
    json_encode(Frys::frystNaa($rad($m), '2028-03-05')));
sjekk('… og har ikke betalt tilgang', !Medlemskap::harBetaltPeriode($rad($m), '2028-03-05'));

// ── (b) frys over 10 dager ──────────────────────────────────────────────
echo "\n── (b) frys over 10 dager: trekket tas ─────────────────────\n";
$m = $medlem('aktiv', $plan);
$agr = 'agr_frysB_' . $tag;
$avt = $avtale($m, $agr, '2028-07-01');
$betalt($m, $avt, '2028-06-01');
$frys($m, '2028-06-25', '2028-07-10');
sjekk('28. juni (betalt, frysen har startet): ikke fryst', Frys::frystNaa($rad($m), '2028-06-28') === null);
$fra = $loggLengde();
$ut = Medlemskap::trekk($avtaleRad($avt), '2028-06-28');
$p = $rader($avt);
sjekk("frysen dekker 10 dager av juli (< 15): juli trekkes (svar: $ut)", str_starts_with($ut, 'bedt om trekk til 2028-07-01')
    && count($p) === 2 && (string) $p[1]['gjelder_fra'] === '2028-07-01' && (int) $p[1]['belop_ore'] === $pris
    && $bestillinger($agr, $fra) === 1);
// Vipps tar trekket.
DB::oppdater('payments', ['status' => 'betalt'], ['id' => (int) $p[1]['id']]);
foreach (['2028-07-01', '2028-07-05', '2028-07-10', '2028-07-31'] as $dagen) {
    sjekk("$dagen: ikke fryst, og har tilgang (juli er betalt)",
        Frys::frystNaa($rad($m), $dagen) === null && Medlemskap::harBetaltPeriode($rad($m), $dagen));
}

// ── (c) engangsbetalt med frys midt i perioden ──────────────────────────
echo "\n── (c) engangsbetalt: tilgang til perioden er over ─────────\n";
sjekk('engangsplanen finnes', $engangs !== '');
$m = $medlem('prove', $engangs, ['slutt_dato' => '2028-09-30']);
$betalt($m, null, '2028-09-02');
$frys($m, '2028-09-15', '2028-10-20');
foreach (['2028-09-15', '2028-09-20', '2028-09-30'] as $dagen) {
    sjekk("$dagen: ikke fryst, og har tilgang (perioden er betalt)",
        Frys::frystNaa($rad($m), $dagen) === null && Medlemskap::harBetaltPeriode($rad($m), $dagen));
}
sjekk('1. oktober (perioden er over): fryst til 20. oktober', Frys::frystNaa($rad($m), '2028-10-01') === ['til' => '2028-10-20']);
sjekk('… og har ikke tilgang', !Medlemskap::harBetaltPeriode($rad($m), '2028-10-01'));

// ── (e) feilet trekk i en maaned med frys under 15 dager ────────────────
echo "\n── (e) feilet trekk, frys under 15 dager: forfalt ──────────\n";
$m = $medlem('aktiv', $plan);
$agr = 'agr_frysE_' . $tag;
$avt = $avtale($m, $agr, '2029-02-01');
$betalt($m, $avt, '2029-01-01');
$frys($m, '2029-02-20', '2029-03-05');
$ut = Medlemskap::trekk($avtaleRad($avt), '2029-01-29');
$p = $rader($avt);
sjekk("februar trekkes (frysen dekker 9 dager) (svar: $ut)", count($p) === 2 && (string) $p[1]['gjelder_fra'] === '2029-02-01');
DB::oppdater('payments', ['status' => 'feilet'], ['id' => (int) $p[1]['id']]);   // Vipps: FAILED
$bs = Medlemskap::betalingsstatusFor($rad($m), '2029-02-25');
sjekk('25. februar: stengt ute av frysen', Frys::frystNaa($rad($m), '2029-02-25') === ['til' => '2029-03-05']);
sjekk('… men februar staar forfalt og utestaaende (ikke «Fryst»)', $bs['tilstand'] === 'forfalt' && $bs['utestaaende'] === true, json_encode($bs));
sjekk('… og maaneden er ikke fritatt', !Medlemskap::fritattMaaned($m, '2029-02-25') && !Medlemskap::fritattTilgang($rad($m), '2029-02-10'));

// ── (f) feilet trekk 1.11, frys 20.11–31.01 ─────────────────────────────
echo "\n── (f) feilet trekk 1.11 + frys 20.11–31.1 ─────────────────\n";
$m = $medlem('aktiv', $plan);
$agr = 'agr_frysF_' . $tag;
$avt = $avtale($m, $agr, '2029-11-01');
$betalt($m, $avt, '2029-10-01');
$frys($m, '2029-11-20', '2030-01-31');
$ut = Medlemskap::trekk($avtaleRad($avt), '2029-10-29');
$p = $rader($avt);
sjekk("november trekkes (frysen dekker 11 dager) (svar: $ut)", count($p) === 2 && (string) $p[1]['gjelder_fra'] === '2029-11-01');
DB::oppdater('payments', ['status' => 'feilet'], ['id' => (int) $p[1]['id']]);
$fra = $loggLengde();
$utDes = Medlemskap::trekk($avtaleRad($avt), '2029-11-28');
$utJan = Medlemskap::trekk($avtaleRad($avt), '2029-12-29');
sjekk("desember og januar hoppes over ($utDes | $utJan)", count($rader($avt)) === 2 && $bestillinger($agr, $fra) === 0
    && (string) $avtaleRad($avt)['neste_trekk'] === '2030-02-01');
foreach (['2029-11-25', '2029-12-10', '2030-01-15'] as $dagen) {
    $bs = Medlemskap::betalingsstatusFor($rad($m), $dagen);
    sjekk("$dagen: november staar fortsatt ubetalt (forfalt)", $bs['tilstand'] === 'forfalt' && $bs['utestaaende'], json_encode($bs));
}

// Betalingseksperten (C): medlemmet betaler det utestaaende 5.12. Betalingen
// gjelder november, desember er fortsatt fritatt, og frysen virker videre.
$f1 = Frys::frystNaa($rad($m), '2029-12-05');
sjekk('5. desember: stengt ute av frysen', $f1 === ['til' => '2030-01-31'], json_encode($f1));
sjekk('… skyldig maaned er november', Medlemskap::skyldigMaaned($rad($m), '2029-12-05') === '2029-11-01');
$forny = Medlemskap::fornyPeriodePaa($rad($m), $avtaleRad($avt), '2029-12-05');
$ny = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'epayment' ORDER BY id DESC LIMIT 1", ['s' => $avt]);
sjekk('betalingen 5.12 gjelder november', $ny !== null && (string) $ny['gjelder_fra'] === '2029-11-01', json_encode($ny['gjelder_fra'] ?? null));
DB::oppdater('payments', ['status' => 'betalt'], ['id' => (int) $ny['id']]);   // Vipps: betalt
$bs = Medlemskap::betalingsstatusFor($rad($m), '2029-12-05');
sjekk('… november er betalt, desember fritatt: ingenting utestaaende', $bs['tilstand'] === 'fryst' && $bs['utestaaende'] === false, json_encode($bs));
sjekk('… november gir tilgang naa', Medlemskap::harBetaltPeriode($rad($m), '2029-11-25'));
sjekk('… og frysen virker videre i desember', Frys::frystNaa($rad($m), '2029-12-10') === ['til' => '2030-01-31']);
sjekk('… og en ny betaling sperres naa (ingenting skyldes)', (static function () use ($rad, $m, $avtaleRad, $avt): bool {
    try { Medlemskap::fornyPeriodePaa($rad($m), $avtaleRad($avt), '2029-12-06'); return false; }
    catch (RuntimeException $e) { return str_contains($e->getMessage(), 'fryst til'); }
})());

// ── (h) frysen avsluttes tidlig etter at maaneden er hoppet over ────────
echo "\n── (h) frys avsluttet 10.3 etter at mars er hoppet over ────\n";
$m = $medlem('aktiv', $plan);
$agr = 'agr_frysH_' . $tag;
$avt = $avtale($m, $agr, '2031-03-01');
$betalt($m, $avt, '2031-02-01');
$fH = $frys($m, '2031-03-01', '2031-03-31');
$ut = Medlemskap::trekk($avtaleRad($avt), '2031-02-26');
sjekk("mars hoppes over (svar: $ut)", count($rader($avt)) === 1 && (string) $avtaleRad($avt)['neste_trekk'] === '2031-04-01');
$antall = static fn(): int => (int) DB::verdi("SELECT COUNT(*) FROM audit_log WHERE handling = :h AND objekt_type = 'subscription' AND objekt_id = :a",
    ['h' => Medlemskap::HOPPET_OVER, 'a' => $avt]);
sjekk('… og det er lagret at mars ble hoppet over (én rad paa avtalen)', $antall() === 1);
// Verkstedet avslutter frysen 10. mars.
DB::kjor("UPDATE medlem_frys SET status = 'avsluttet', updated_at = '2031-03-10 09:00:00' WHERE id = :i", ['i' => $fH]);
sjekk('frysen dekker naa bare 9 dager av mars', Medlemskap::pauseDager($m, '2031-03-15') === 9);
sjekk('… men mars er fortsatt fritatt', Medlemskap::fritattMaaned($m, '2031-03-15'));
foreach (['2031-03-10', '2031-03-20', '2031-03-31'] as $dagen) {
    $bs = Medlemskap::betalingsstatusFor($rad($m), $dagen);
    sjekk("$dagen: tilgang, ikke fryst, og skylder ingenting", Medlemskap::harBetaltPeriode($rad($m), $dagen)
        && Frys::frystNaa($rad($m), $dagen) === null && $bs['utestaaende'] === false && $bs['forfalt'] === false, json_encode($bs));
}
sjekk('1. april er ikke fritatt', !Medlemskap::fritattMaaned($m, '2031-04-01'));

// ── (g) restdagene etter frysen i en overhoppet maaned ──────────────────
echo "\n── (g) restdager etter frys i overhoppet maaned ────────────\n";
$m = $medlem('aktiv', $plan);
$agr = 'agr_frysG_' . $tag;
$avt = $avtale($m, $agr, '2030-03-01');
$betalt($m, $avt, '2030-02-01');
$frys($m, '2030-02-20', '2030-03-25');
$ut = Medlemskap::trekk($avtaleRad($avt), '2030-02-26');
sjekk("mars hoppes over (svar: $ut)", count($rader($avt)) === 1 && (string) $avtaleRad($avt)['neste_trekk'] === '2030-04-01');
sjekk('15. mars (i frysen): stengt ute, ikke tilgang', Frys::frystNaa($rad($m), '2030-03-15') === ['til' => '2030-03-25']
    && !Medlemskap::harBetaltPeriode($rad($m), '2030-03-15'));
foreach (['2030-03-26', '2030-03-28', '2030-03-31'] as $dagen) {
    $bs = Medlemskap::betalingsstatusFor($rad($m), $dagen);
    sjekk("$dagen: gratis tilgang, ikke fryst, og skylder ingenting",
        Medlemskap::harBetaltPeriode($rad($m), $dagen) && Frys::frystNaa($rad($m), $dagen) === null
        && $bs['utestaaende'] === false && $bs['forfalt'] === false, json_encode($bs));
}
$bs = Medlemskap::betalingsstatusFor($rad($m), '2030-03-15');
sjekk('15. mars: «Fryst til 25. mars», ikke utestaaende', $bs['tilstand'] === 'fryst' && $bs['utestaaende'] === false
    && str_contains($bs['tekst'], '25. mars'), json_encode($bs));
$fra = $loggLengde();
$ut = Medlemskap::trekk($avtaleRad($avt), '2030-03-29');
sjekk("trekket fortsetter 1. april (svar: $ut)", str_starts_with($ut, 'bedt om trekk til 2030-04-01') && $bestillinger($agr, $fra) === 1);
sjekk('1. april: ikke fritatt (trekket er tatt)', !Medlemskap::fritattMaaned($m, '2030-04-01'));

// ── (d) flytting med avtale og frys ─────────────────────────────────────
echo "\n── (d) flytting: trekket paa den flyttede avtalen ──────────\n";
$port = random_int(18200, 18900);
$rot = dirname(__DIR__);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/frys-trekk-php.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/frys-trekk-php.log', 'a']],
    $ror, $rot);
for ($i = 0; $i < 40; $i++) {
    if (@fsockopen('127.0.0.1', $port, $en, $to, 0.2)) { break; }
    usleep(150000);
}
$admin = $medlem('ingen', '', ['rolle' => 'admin', 'medlemskap_type' => null]);
$tok = bin2hex(random_bytes(32));
DB::settInn('sessions', ['token_hash' => hash('sha256', $tok), 'member_id' => $admin, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
$fraM = $medlem('aktiv', $plan);
$tilM = $medlem('ingen', '', ['medlemskap_type' => null]);
$agr = 'agr_frysD_' . $tag;
$avt = $avtale($fraM, $agr, '2028-11-01');
$betalt($fraM, $avt, '2028-10-01');
$fGodkjent = $frys($fraM, '2028-10-25', '2028-11-30');
// Avsluttet etter perioden (gjenaapnet), men desember er ikke trukket ennaa.
$fAvsl = $frys($fraM, '2028-12-01', '2028-12-20', 'avsluttet', ['updated_at' => '2028-12-21 03:00:00']);
$svar = @file_get_contents("http://127.0.0.1:$port/api/admin/medlemmer.php", false, stream_context_create(['http' => [
    'method' => 'POST', 'ignore_errors' => true, 'timeout' => 20,
    'header' => 'Cookie: ' . Sesjon::COOKIE . "=$tok\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
    'content' => json_encode(['handling' => 'flytt-medlemskap', 'fra' => $fraM, 'til' => $tilM]),
]]));
$d = json_decode((string) $svar, true);
sjekk('flyttingen gaar gjennom', is_array($d) && empty($d['feil']), (string) $svar);
sjekk('… avtalen staar paa det nye medlemmet', (int) $avtaleRad($avt)['member_id'] === $tilM);
sjekk('… begge frysene fulgte med, og den avsluttede beholdt avslutningsdagen',
    (int) DB::verdi('SELECT member_id FROM medlem_frys WHERE id = :i', ['i' => $fGodkjent]) === $tilM
    && (int) DB::verdi('SELECT member_id FROM medlem_frys WHERE id = :i', ['i' => $fAvsl]) === $tilM
    && (string) DB::verdi('SELECT updated_at FROM medlem_frys WHERE id = :i', ['i' => $fAvsl]) === '2028-12-21 03:00:00');
sjekk('5. november: det nye medlemmet er fryst til 30. november (oktober er betalt, november ikke)',
    Frys::frystNaa($rad($tilM), '2028-11-05') === ['til' => '2028-11-30'], json_encode(Frys::frystNaa($rad($tilM), '2028-11-05')));
sjekk('28. oktober: ikke fryst (oktober er betalt)', Frys::frystNaa($rad($tilM), '2028-10-28') === null);
$fra = $loggLengde();
$ut = Medlemskap::trekk($avtaleRad($avt), '2028-10-28');
sjekk("november hoppes over paa den flyttede avtalen (svar: $ut)", count($rader($avt)) === 1 && $bestillinger($agr, $fra) === 0
    && (string) $avtaleRad($avt)['neste_trekk'] === '2028-12-01');
$fra = $loggLengde();
$ut = Medlemskap::trekk($avtaleRad($avt), '2028-12-22');
sjekk("desember (avsluttet frys, forsinket runde) hoppes ogsaa over (svar: $ut)", count($rader($avt)) === 1
    && $bestillinger($agr, $fra) === 0 && (string) $avtaleRad($avt)['neste_trekk'] === '2029-01-01');
$fra = $loggLengde();
$ut = Medlemskap::trekk($avtaleRad($avt), '2028-12-29');
$p = $rader($avt);
sjekk("januar trekkes som vanlig (svar: $ut)", count($p) === 2 && (string) $p[1]['gjelder_fra'] === '2029-01-01' && $bestillinger($agr, $fra) === 1);

echo "\n$ok av " . ($ok + $feil) . " frys-trekk-kontroller bestått\n";
$ferdig = true;
exit($feil === 0 ? 0 : 1);
