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

// ── (i) fast trekk feilet, fryst: «Forny og betal» betaler bare maaneden ──
echo "\n── (i) fast trekk feilet under frys: betal maaneden, ikke dobbelt ─\n";
// Eieren, 2. oktober 2026: knappen paa Min side betaler BARE maaneden som
// skyldes, med én betaling paa avtalen som loeper. Vipps skal ikke trekke den
// samme maaneden igjen etterpaa.
$m = $medlem('aktiv', $plan);
$agr = 'agr_frysI_' . $tag;
$avt = $avtale($m, $agr, '2034-03-01');
$betalt($m, $avt, '2034-02-01');
$frys($m, '2034-03-05', '2034-03-12');   // 8 dager: mars trekkes
$feiler = __DIR__ . '/.trekk-feiler';
file_put_contents($feiler, 'ja');        // nettbrudd: trekket kom aldri til Vipps
try { Medlemskap::trekk($avtaleRad($avt), '2034-02-26'); } catch (RuntimeException $e) {}
@unlink($feiler);
$feilet = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'recurring_charge' ORDER BY id DESC LIMIT 1", ['s' => $avt]);
sjekk('mars-trekket feilet uten charge-id', $feilet !== null && (string) $feilet['status'] === 'feilet' && empty($feilet['vipps_psp_ref'])
    && (string) $avtaleRad($avt)['neste_trekk'] === '2034-03-01', json_encode([$feilet['status'] ?? null, $avtaleRad($avt)['neste_trekk']]));
sjekk('8. mars: stengt ute, og mars staar forfalt', Frys::frystNaa($rad($m), '2034-03-08') === ['til' => '2034-03-12']
    && Medlemskap::betalingsstatusFor($rad($m), '2034-03-08')['forfalt']);
$forny = Medlemskap::fornyPeriodePaa($rad($m), $avtaleRad($avt), '2034-03-08');
$ny = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'epayment' ORDER BY id DESC LIMIT 1", ['s' => $avt]);
sjekk('«Forny og betal»: én engangsbetaling for mars, paa den samme avtalen', $ny !== null
    && (string) $ny['gjelder_fra'] === '2034-03-01' && (int) $ny['belop_ore'] === $pris
    && (string) $avtaleRad($avt)['status'] === 'aktiv' && (string) $avtaleRad($avt)['vipps_agreement_id'] === $agr);
DB::oppdater('payments', ['status' => 'betalt'], ['id' => (int) $ny['id']]);   // betalt i Vipps
// Neste trekkrunde: mars er betalt, og skal ikke bestilles hos Vipps paa nytt.
$fra = $loggLengde();
$ut = Medlemskap::trekk($avtaleRad($avt), '2034-03-09');
sjekk("trekkrunden bestiller ikke mars paa nytt (svar: $ut)", $bestillinger($agr, $fra) === 0
    && (string) $avtaleRad($avt)['neste_trekk'] === '2034-04-01');
sjekk('… mars er betalt én gang',
    (int) DB::verdi("SELECT COUNT(*) FROM payments WHERE subscription_id = :s AND status IN ('betalt','venter') AND gjelder_fra BETWEEN '2034-03-01' AND '2034-03-31'", ['s' => $avt]) === 1);
$bs = Medlemskap::betalingsstatusFor($rad($m), '2034-03-10');
sjekk('… og ingenting staar utestaaende', $bs['utestaaende'] === false && $bs['forfalt'] === false, json_encode($bs));
$fra = $loggLengde();
$ut = Medlemskap::trekk($avtaleRad($avt), '2034-03-29');
sjekk("april trekkes som vanlig (svar: $ut)", str_starts_with($ut, 'bedt om trekk til 2034-04-01') && $bestillinger($agr, $fra) === 1);

// ── (k) betalingen er paa vei naar nattrunden kjoerer ───────────────────
echo "\n── (k) betaling «opprettet» naar runden kjoerer: vent 30 min ─\n";
// Betaling, 2. oktober 2026: ingen dobbel betaling. Holder medlemmet paa aa
// betale maaneden selv, venter runden; etter 30 minutter bestilles trekket.
$m = $medlem('aktiv', $plan);
$agr = 'agr_frysK_' . $tag;
$avt = $avtale($m, $agr, '2035-03-01');
$betalt($m, $avt, '2035-02-01');
$frys($m, '2035-03-05', '2035-03-12');
file_put_contents($feiler, 'ja');
try { Medlemskap::trekk($avtaleRad($avt), '2035-02-26'); } catch (RuntimeException $e) {}
@unlink($feiler);
Medlemskap::fornyPeriodePaa($rad($m), $avtaleRad($avt), '2035-03-08');
$egen = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'epayment' ORDER BY id DESC LIMIT 1", ['s' => $avt]);
DB::oppdater('payments', ['status' => 'opprettet'], ['id' => (int) $egen['id']]);   // medlemmet er i Vipps naa
sjekk('medlemmets betaling for mars staar «opprettet»', (string) $egen['gjelder_fra'] === '2035-03-01');
$fra = $loggLengde();
$ut = Medlemskap::trekk($avtaleRad($avt), '2035-03-09');
sjekk("nattrunden venter: null bestillinger (svar: $ut)", $ut === 'bestilles alt' && $bestillinger($agr, $fra) === 0
    && (string) $avtaleRad($avt)['neste_trekk'] === '2035-03-01');
DB::oppdater('payments', ['status' => 'venter'], ['id' => (int) $egen['id']]);
$ut = Medlemskap::trekk($avtaleRad($avt), '2035-03-09');
sjekk("… ogsaa naar den staar «venter» (svar: $ut)", $ut === 'bestilles alt' && $bestillinger($agr, $fra) === 0);
// 30 minutter uten fullfoert betaling.
DB::kjor('UPDATE payments SET created_at = (UTC_TIMESTAMP() - INTERVAL 31 MINUTE) WHERE id = :i', ['i' => (int) $egen['id']]);
$ut = Medlemskap::trekk($avtaleRad($avt), '2035-03-09');
sjekk("etter 30 minutter uten fullfoert betaling: trekket bestilles (svar: $ut)", $bestillinger($agr, $fra) === 1);

// ── (l) oktobertrekket henger paa «venter» etter fristen ────────────────
echo "\n── (l) trekk henger paa «venter», frysen dekker oktober ─────\n";
// Kontrolloeren, 2. oktober 2026: maaneden skyldes, og trekket avlyses hos
// Vipps foer medlemmet betaler selv. Ingen dobbel betaling.
$lagL = static function (string $suffiks) use ($medlem, $plan, $avtale, $betalt, $frys, $avtaleRad, $tag, $rader): array {
    $m = $medlem('aktiv', $plan);
    $agr = 'agr_frysL' . $suffiks . '_' . $tag;
    $avt = $avtale($m, $agr, '2036-10-01');
    $betalt($m, $avt, '2036-09-01');
    $ut = Medlemskap::trekk($avtaleRad($avt), '2036-09-28');   // bestilt foer frysen
    $frys($m, '2036-10-01', '2036-10-31');                     // godkjent etterpaa
    $t = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'recurring_charge' ORDER BY id DESC LIMIT 1", ['s' => $avt]);
    return [$m, $agr, $avt, $ut, $t];
};
[$m, $agr, $avt, $ut, $t] = $lagL('A');
sjekk("oktobertrekket er bestilt og venter hos Vipps (svar: $ut)", $t !== null && (string) $t['status'] === 'venter' && !empty($t['vipps_psp_ref']),
    json_encode([$t['status'] ?? null, $t['vipps_psp_ref'] ?? null]));
$bs = Medlemskap::betalingsstatusFor($rad($m), '2036-10-15');
sjekk('15. oktober (etter fristen): forfalt, og stengt ute av frysen', $bs['forfalt'] && Frys::frystNaa($rad($m), '2036-10-15') === ['til' => '2036-10-31'], json_encode($bs));
sjekk('… skyldig maaned er oktober', Medlemskap::skyldigMaaned($rad($m), '2036-10-15') === '2036-10-01');
$fraLogg = $loggLengde();
Medlemskap::fornyPeriodePaa($rad($m), $avtaleRad($avt), '2036-10-15');
$slettet = 0;
foreach (array_slice(is_file($logg) ? file($logg) : [], $fraLogg) as $l) {
    $k = json_decode($l, true);
    if (($k['metode'] ?? '') === 'DELETE' && str_contains((string) ($k['sti'] ?? ''), $agr . '/charges/' . $t['vipps_psp_ref'])) { $slettet++; }
}
$ny = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'epayment' ORDER BY id DESC LIMIT 1", ['s' => $avt]);
sjekk('Vipps-trekket er avlyst hos Vipps, og raden staar «avbrutt»', $slettet === 1
    && (string) DB::verdi('SELECT status FROM payments WHERE id = :i', ['i' => (int) $t['id']]) === 'avbrutt');
sjekk('… betalingen gjelder oktober', $ny !== null && (string) $ny['gjelder_fra'] === '2036-10-01');
DB::oppdater('payments', ['status' => 'betalt'], ['id' => (int) $ny['id']]);
sjekk('… oktober er betalt én gang (ingen dobbel betaling)',
    (int) DB::verdi("SELECT COUNT(*) FROM payments WHERE subscription_id = :s AND status IN ('betalt','venter','opprettet') AND gjelder_fra BETWEEN '2036-10-01' AND '2036-10-31'", ['s' => $avt]) === 1);
// Vipps sier nei til aa avlyse: da avvises betalingen.
[$m, $agr, $avt, $ut, $t] = $lagL('B');
file_put_contents(__DIR__ . '/.trekk-slett-nei', 'ja');
$melding = '';
try { Medlemskap::fornyPeriodePaa($rad($m), $avtaleRad($avt), '2036-10-15'); } catch (RuntimeException $e) { $melding = $e->getMessage(); }
@unlink(__DIR__ . '/.trekk-slett-nei');
sjekk("Vipps avlyser ikke: betalingen avvises («$melding»)", $melding === 'Vipps stoppet ikke trekket.'
    && (int) DB::verdi("SELECT COUNT(*) FROM payments WHERE subscription_id = :s AND type = 'epayment'", ['s' => $avt]) === 0
    && (string) DB::verdi('SELECT status FROM payments WHERE id = :i', ['i' => (int) $t['id']]) === 'venter');

// ── (m) kappløpet: egenbetaling og trekkrunde samtidig ──────────────────
echo "\n── (m) kappløp: to tilkoblinger, aldri to betalinger ───────\n";
// Kontrolloeren, 2. oktober 2026 (funnet av Codex): fornyPeriodePaa() og
// trekk() tar den samme medlemslaasen. Her holder én tilkobling laasen mens en
// annen prosess kjoerer den andre siden; den maa vente, og ser det den
// foerste gjorde. Uansett rekkefoelge: aldri to betalinger for samme maaned.
$s = require dirname(__DIR__) . '/app/secrets.php';
$annen = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $s['db_vert'], (int) ($s['db_port'] ?? 3306), $s['db_navn']),
    $s['db_bruker'], $s['db_passord'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rotM = dirname(__DIR__);
$barn = static function (string $kode) use ($rotM) {
    $p = proc_open([PHP_BINARY, '-r', 'require "app/bootstrap.php"; ' . $kode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ror, $rotM);
    return [$p, $ror];
};
$vent = static function ($p, array $ror): string {
    $ut = stream_get_contents($ror[1]);
    stream_get_contents($ror[2]);
    proc_close($p);
    return trim((string) $ut);
};
$lever = static fn($p): bool => (bool) (proc_get_status($p)['running'] ?? false);
$lagM = static function (string $suffiks, string $mnd) use ($medlem, $plan, $avtale, $betalt, $frys, $avtaleRad, $tag, $feiler): array {
    $m = $medlem('aktiv', $plan);
    $agr = 'agr_frysM' . $suffiks . '_' . $tag;
    $forrige = (new DateTimeImmutable($mnd))->modify('first day of previous month')->format('Y-m-d');
    $avt = $avtale($m, $agr, $mnd);
    $betalt($m, $avt, $forrige);
    $frys($m, substr($mnd, 0, 8) . '05', substr($mnd, 0, 8) . '12');   // 8 dager: maaneden trekkes
    file_put_contents($feiler, 'ja');
    try { Medlemskap::trekk($avtaleRad($avt), (new DateTimeImmutable($mnd))->modify('-3 days')->format('Y-m-d')); } catch (RuntimeException $e) {}
    @unlink($feiler);
    $t = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'recurring_charge' ORDER BY id DESC LIMIT 1", ['s' => $avt]);
    return [$m, $agr, $avt, $t];
};
$betalinger = static fn(int $avt, string $mnd): int => (int) DB::verdi(
    "SELECT COUNT(*) FROM payments WHERE subscription_id = :s AND status IN ('betalt','venter','opprettet')
       AND gjelder_fra BETWEEN :f AND LAST_DAY(:g)", ['s' => $avt, 'f' => $mnd, 'g' => $mnd]);

// 1) Trekkrunden holder laasen og tar maaneden; egenbetalingen venter, og
//    avvises naar den slipper til (trekket har ingen charge-id ennaa).
[$m, $agr, $avt, $t] = $lagM('A', '2037-03-01');
$annen->beginTransaction();
$annen->prepare('SELECT id FROM members WHERE id = ? FOR UPDATE')->execute([$m]);
[$p, $ror] = $barn('try { Medlemskap::fornyPeriodePaa(DB::en("SELECT * FROM members WHERE id = ' . $m . '"), DB::en("SELECT * FROM subscriptions WHERE id = ' . $avt . '"), "2037-03-08"); echo "BETALING"; } catch (Throwable $e) { echo "AVVIST:" . $e->getMessage(); }');
usleep(1500000);
sjekk('egenbetalingen venter paa laasen', $lever($p));
$annen->prepare("UPDATE payments SET status = 'opprettet' WHERE id = ?")->execute([(int) $t['id']]);   // runden tar forsoeket
$annen->commit();
$svar = $vent($p, $ror);
sjekk("… og avvises naar den slipper til ($svar)", $svar === 'AVVIST:Vipps stoppet ikke trekket.'
    && (int) DB::verdi("SELECT COUNT(*) FROM payments WHERE subscription_id = :s AND type = 'epayment'", ['s' => $avt]) === 0);
sjekk('… høyst én betaling for mars', $betalinger($avt, '2037-03-01') <= 1);

// 2) Egenbetalingen holder laasen og setter inn raden sin; trekkrunden
//    venter, og bestiller ikke naar den slipper til.
[$m, $agr, $avt, $t] = $lagM('B', '2037-05-01');
$annen->beginTransaction();
$annen->prepare('SELECT id FROM members WHERE id = ? FOR UPDATE')->execute([$m]);
$annen->prepare("INSERT INTO payments (vipps_reference, type, formal, member_id, subscription_id, belop_ore, status, idempotency_key, gjelder_fra)
    VALUES (?, 'epayment', 'medlemskap', ?, ?, ?, 'opprettet', ?, '2037-05-01')")
    ->execute(['TEST-' . bin2hex(random_bytes(10)), $m, $avt, $pris, Vipps::uuid()]);
$fra = $loggLengde();
[$p, $ror] = $barn('$a = DB::en("SELECT * FROM subscriptions WHERE id = ' . $avt . '"); $a["epost"] = ""; $a["navn"] = "Frystrekk"; try { echo Medlemskap::trekk($a, "2037-05-02"); } catch (Throwable $e) { echo "FEIL:" . $e->getMessage(); }');
usleep(1500000);
sjekk('trekkrunden venter paa laasen', $lever($p));
$annen->commit();
$svar = $vent($p, $ror);
sjekk("… og bestiller ikke naar den slipper til ($svar)", $svar === 'bestilles alt' && $bestillinger($agr, $fra) === 0);
sjekk('… én betaling for mai (medlemmets egen)', $betalinger($avt, '2037-05-01') === 1,
    json_encode(DB::alle('SELECT type, status, gjelder_fra, vipps_psp_ref FROM payments WHERE subscription_id = :s ORDER BY id', ['s' => $avt])));

// 3) Trekket gaar gjennom mens egenbetalingen venter paa laasen: maaneden er
//    betalt naar den slipper til, og egenbetalingen avvises.
[$m, $agr, $avt, $t] = $lagM('C', '2037-07-01');
$annen->beginTransaction();
$annen->prepare('SELECT id FROM members WHERE id = ? FOR UPDATE')->execute([$m]);
[$p, $ror] = $barn('try { Medlemskap::fornyPeriodePaa(DB::en("SELECT * FROM members WHERE id = ' . $m . '"), DB::en("SELECT * FROM subscriptions WHERE id = ' . $avt . '"), "2037-07-08"); echo "BETALING"; } catch (Throwable $e) { echo "AVVIST:" . $e->getMessage(); }');
usleep(1500000);
sjekk('egenbetalingen venter paa laasen', $lever($p));
$annen->prepare("UPDATE payments SET status = 'betalt', vipps_psp_ref = ? WHERE id = ?")->execute(['chr_test_' . $tag, (int) $t['id']]);
$annen->commit();
$svar = $vent($p, $ror);
sjekk("trekket gikk foerst: egenbetalingen avvises ($svar)", $svar === 'AVVIST:Måneden er alt betalt.'
    && (int) DB::verdi("SELECT COUNT(*) FROM payments WHERE subscription_id = :s AND type = 'epayment'", ['s' => $avt]) === 0
    && $betalinger($avt, '2037-07-01') === 1);

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

// ── (j) «Forny og betal» paa Min side, i dag: samme vei som (i) ─────────
echo "\n── (j) Min side-knappen for fryst medlem med feilet trekk ──\n";
$oslo = new DateTimeZone('Europe/Oslo');
$idag = (new DateTimeImmutable('now', $oslo))->format('Y-m-d');
$denne = (new DateTimeImmutable($idag))->modify('first day of this month')->format('Y-m-d');
$forrige = (new DateTimeImmutable($idag))->modify('first day of previous month')->format('Y-m-d');
$m = $medlem('pause', $plan);
$agr = 'agr_frysJ_' . $tag;
$avt = $avtale($m, $agr, (new DateTimeImmutable($denne))->modify('first day of next month')->format('Y-m-d'));
$betalt($m, $avt, $forrige);
DB::settInn('payments', ['member_id' => $m, 'subscription_id' => $avt, 'formal' => 'medlemskap', 'type' => 'recurring_charge',
    'status' => 'feilet', 'belop_ore' => $pris, 'gjelder_fra' => $denne, 'vipps_reference' => 'TEST-' . bin2hex(random_bytes(12)),
    'idempotency_key' => Vipps::uuid()]);
$frys($m, (new DateTimeImmutable($idag))->modify('-2 days')->format('Y-m-d'), (new DateTimeImmutable($idag))->modify('+4 days')->format('Y-m-d'));
$tokM = bin2hex(random_bytes(32));
DB::settInn('sessions', ['token_hash' => hash('sha256', $tokM), 'member_id' => $m, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
$avtalerFoer = (int) DB::verdi('SELECT COUNT(*) FROM subscriptions WHERE member_id = :m', ['m' => $m]);
$svar = @file_get_contents("http://127.0.0.1:$port/api/medlemskap.php", false, stream_context_create(['http' => [
    'method' => 'POST', 'ignore_errors' => true, 'timeout' => 20,
    'header' => 'Cookie: ' . Sesjon::COOKIE . "=$tokM\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
    'content' => json_encode(['handling' => 'start', 'plan' => $plan]),
]]));
$d = json_decode((string) $svar, true);
$ny = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'epayment' ORDER BY id DESC LIMIT 1", ['s' => $avt]);
sjekk('knappen gir én betaling i Vipps (fornyelse, ikke ny avtale)', is_array($d) && !empty($d['url']) && ($d['fornyelse'] ?? false) === true, (string) $svar);
sjekk('… for maaneden som skyldes', $ny !== null && (string) $ny['gjelder_fra'] === $denne, json_encode($ny['gjelder_fra'] ?? null));
sjekk('… paa den samme avtalen, som beholdes', (int) DB::verdi('SELECT COUNT(*) FROM subscriptions WHERE member_id = :m', ['m' => $m]) === $avtalerFoer
    && (string) $avtaleRad($avt)['status'] === 'aktiv' && (string) $avtaleRad($avt)['vipps_agreement_id'] === $agr);

echo "\n$ok av " . ($ok + $feil) . " frys-trekk-kontroller bestått\n";
$ferdig = true;
exit($feil === 0 ? 0 : 1);
