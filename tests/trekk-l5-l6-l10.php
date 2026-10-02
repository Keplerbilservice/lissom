<?php
/**
 * Revisjonens L-5, L-6 og L-10 + trekkdag den 1. (eieren, 2. oktober 2026).
 *
 *   L-5  trekkrunden henger: «opprettet» uten charge-id proeves igjen med
 *        samme noekkel; neste_trekk flyttes sammen med charge-id-en.
 *   L-6  foerste trekk foeres selv om oppslaget feilet da avtalen ble aktiv.
 *   L-10 trekk i en godkjent pause hoppes over; trekket fortsetter etter
 *        pausen, aldri dobbelt.
 *   Trekkdag: en ny avtale faar neste trekk den 1.
 *
 * Kjoeres av tests/trekk-l5-l6-l10.mjs (lager og rydder testmedlemmet), mot
 * testbasen og den falske Vippsen. Alle beloep i oere.
 */
declare(strict_types=1);
require __DIR__ . '/nettleser/testdatabase.php';
krev_testdatabase(dirname(__DIR__));
require dirname(__DIR__) . '/app/bootstrap.php';
if (!str_starts_with((string) Config::hent('vipps_base'), 'http://127.0.0.1:')) {
    throw new RuntimeException('Krever lokal falsk Vipps');
}
$s = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$m = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $s['admin']]);
if (!$m || $m['navn'] !== $s['tag'] || !str_starts_with((string) $s['tag'], 'HentingTest-')) {
    throw new RuntimeException('Ukjent fixture');
}

$ok = 0; $feil = 0;
$sjekk = static function (bool $b, string $hva) use (&$ok, &$feil): void {
    if ($b) { $ok++; echo "  ✓ $hva\n"; } else { $feil++; echo "  ✗ $hva\n"; }
};
$del = static function (string $navn, callable $f) use (&$feil): void {
    echo "\n== $navn ==\n";
    try { $f(); } catch (Throwable $e) { $feil++; echo '  ✗ krasjet: ' . get_class($e) . ': ' . $e->getMessage() . "\n"; }
};

$styr = __DIR__ . '/';
$styrfil = static function (string $n, string $v) use ($styr): void {
    if ($v === '') { @unlink($styr . $n); } else { file_put_contents($styr . $n, $v); }
};
$loggLengde = static fn(): int => is_file($styr . '.falsk-vipps.jsonl') ? count(file($styr . '.falsk-vipps.jsonl')) : 0;
$poster = static function (string $agr, int $fra) use ($styr): array {
    $ut = [];
    foreach (array_slice(file($styr . '.falsk-vipps.jsonl'), $fra) as $l) {
        $k = json_decode($l, true);
        if (($k['metode'] ?? '') === 'POST' && str_contains((string) ($k['sti'] ?? ''), $agr . '/charges')) { $ut[] = $k['kropp']; }
    }
    return $ut;
};

$plan = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 0 AND aktiv = 1 ORDER BY pris_ore LIMIT 1');
$PRIS = 199000; // kr 1 990,00
$nye = [];
$nyAvtale = static function (string $agr, string $neste, array $ekstra = []) use ($s, $plan, $PRIS, &$nye): array {
    $i = DB::settInn('subscriptions', ['member_id' => $s['admin'], 'plan' => $plan, 'pris_ore' => $PRIS,
        'status' => 'aktiv', 'vipps_agreement_id' => $agr, 'neste_trekk' => $neste, 'trekk_dag' => 1] + $ekstra);
    $nye[] = $i;
    $a = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $i]);
    $a['epost'] = ''; $a['navn'] = $s['tag'];
    return $a;
};
$avtaleNaa = static fn(int $id): array => DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $id]);
$rader = static fn(int $id): array => DB::alle('SELECT * FROM payments WHERE subscription_id = :s ORDER BY id', ['s' => $id]);
$nokkelFor = static fn(array $a): string => substr(hash('sha256', 'trekk:' . $a['id'] . ':'
    . (new DateTimeImmutable((string) $a['neste_trekk']))->format('Y-m')), 0, 36);

$styrfil('.trekk-idempotens', 'ja');
try {
    // ────────────────────────────────────────────────────────────────────
    $del('L-5: «opprettet» uten charge-id henger ikke', function () use ($sjekk, $nyAvtale, $avtaleNaa, $rader, $nokkelFor, $s, $PRIS, $loggLengde, $poster): void {
        // Kjoeringen ba Vipps om trekket (som ble laget), men doede foer
        // svaret ble lagret. Raden staar «opprettet» uten charge-id, 20 min.
        $a = $nyAvtale('agr_L5A_' . $s['tag'], '2026-11-01');
        $nokkel = $nokkelFor($a);
        $kropp = Medlemskap::trekkForsok($a, null, $nokkel, '2026-10-29')[0]['kropp'];
        $chargeId = Vipps::belastAvtale((string) $a['vipps_agreement_id'], $kropp, $nokkel);
        $pid = DB::settInn('payments', ['vipps_reference' => Vipps::nyReferanse('MED'), 'type' => 'recurring_charge',
            'formal' => 'medlemskap', 'member_id' => $s['admin'], 'subscription_id' => (int) $a['id'],
            'belop_ore' => $PRIS, 'status' => 'opprettet', 'idempotency_key' => $nokkel, 'gjelder_fra' => '2026-11-01',
            'trekk_foresporsel' => json_encode(['forsok' => [['nokkel' => $nokkel, 'kropp' => $kropp]]])]);
        DB::kjor('UPDATE payments SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE) WHERE id = :i', ['i' => $pid]);
        $fra = $loggLengde();

        $ut = Medlemskap::trekk($a, '2026-10-29');
        $p = $rader((int) $a['id']);
        $etter = $avtaleNaa((int) $a['id']);
        $sjekk(str_starts_with($ut, 'bedt om trekk til 2026-11-01'), "hengende trekk proeves igjen (svar: $ut)");
        $sjekk(count($p) === 1 && $p[0]['status'] === 'venter' && $p[0]['vipps_psp_ref'] === $chargeId,
            'samme rad faar charge-id-en Vipps alt hadde laget');
        $sjekk((int) $p[0]['belop_ore'] === 199000, 'beloep 199000 oere (kr 1 990,00)');
        $sjekk(count(Vipps::trekkPaaAvtale((string) $a['vipps_agreement_id'])) === 1, 'Vipps har ett trekk, ikke to');
        $po = $poster((string) $a['vipps_agreement_id'], $fra);
        $sjekk(count($po) === 1 && $po[0] === $kropp, 'nytt forsoek sendte noeyaktig samme innhold (samme noekkel)');
        $sjekk($etter['neste_trekk'] === '2026-12-01' && $etter['siste_trekk'] === '2026-11-01',
            'neste_trekk flyttet til 1. desember sammen med charge-id-en');
        $sjekk(Medlemskap::trekk($a, '2026-10-29') === 'alt fort' && count($rader((int) $a['id'])) === 1,
            'samme runde en gang til: «alt fort», ingen ny rad for november');

        // En «opprettet» som er fersk, kan en annen kjoering vaere midt i.
        $b = $nyAvtale('agr_L5B_' . $s['tag'], '2026-11-01');
        $nb = $nokkelFor($b);
        DB::settInn('payments', ['vipps_reference' => Vipps::nyReferanse('MED'), 'type' => 'recurring_charge',
            'formal' => 'medlemskap', 'member_id' => $s['admin'], 'subscription_id' => (int) $b['id'],
            'belop_ore' => $PRIS, 'status' => 'opprettet', 'idempotency_key' => $nb, 'gjelder_fra' => '2026-11-01']);
        $fra = $loggLengde();
        $utB = Medlemskap::trekk($b, '2026-10-29');
        $sjekk($utB === 'bestilles alt' && $poster((string) $b['vipps_agreement_id'], $fra) === []
            && $avtaleNaa((int) $b['id'])['neste_trekk'] === '2026-11-01',
            "fersk «opprettet» (under 15 min) roeres ikke (svar: $utB)");
    });

    // ────────────────────────────────────────────────────────────────────
    $del('L-6: foerste trekk foeres selv om oppslaget feilet', function () use ($sjekk, $nye, $avtaleNaa, $rader, $s, $plan, $PRIS, $styrfil): void {
        $v = Vipps::opprettAvtale($plan, $PRIS, 'Medlemskap test', 'http://127.0.0.1/retur', null, 'maaned');
        // Kjoept 25. september (etter den 20.), godkjent 26. september.
        $id = DB::settInn('subscriptions', ['member_id' => $s['admin'], 'plan' => $plan, 'pris_ore' => $PRIS,
            'status' => 'venter', 'vipps_agreement_id' => $v['avtaleId'], 'created_at' => '2026-09-25 10:00:00']);
        $GLOBALS['l6_id'] = $id;
        $a = $avtaleNaa($id);
        $styrfil('.avtale-status', 'ACTIVE');
        $styrfil('.trekkliste-feiler', 'ja');
        $ny = Medlemskap::oppdaterFraVipps($a, '2026-09-26 08:00:00');
        $styrfil('.trekkliste-feiler', '');
        $etter = $avtaleNaa($id);
        $sjekk($ny === 'aktiv' && $rader($id) === [], 'oppslaget feilet: avtalen er aktiv, men foerste trekk er ikke foert');
        $sjekk($etter['neste_trekk'] === '2026-11-01' && (int) $etter['trekk_dag'] === 1,
            "trekkdag: ny avtale faar neste trekk 1. november og trekkdag 1 (fikk {$etter['neste_trekk']}, dag {$etter['trekk_dag']})");

        $antall = Medlemskap::foerManglendeForsteTrekk();
        $p = $rader($id);
        $sjekk($antall >= 1 && count($p) === 1, 'neste trekkrunde foerer foerste trekk');
        $sjekk(count($p) === 1 && (int) $p[0]['belop_ore'] === 199000 && $p[0]['status'] === 'betalt'
            && $p[0]['type'] === 'recurring_charge', 'beloep 199000 oere, betalt (Vipps: CHARGED)');
        $sjekk(count($p) === 1 && $p[0]['gjelder_fra'] === '2026-10-01', 'kjoept 25.09: trekket gjelder oktober');
        Medlemskap::foerManglendeForsteTrekk();
        $sjekk(count($rader($id)) === 1, 'en runde til gir ikke en rad til');

        // Codex 02.10: oppslaget feilet fortsatt da oktobertrekket ble
        // bestilt. Foerste trekk (kjoept 10. september) foeres likevel, og
        // paa september — ikke paa dagen det etterfoeres.
        $v2 = Vipps::opprettAvtale($plan, $PRIS, 'Medlemskap test', 'http://127.0.0.1/retur', null, 'maaned');
        $id2 = DB::settInn('subscriptions', ['member_id' => $s['admin'], 'plan' => $plan, 'pris_ore' => $PRIS,
            'status' => 'aktiv', 'vipps_agreement_id' => $v2['avtaleId'], 'created_at' => '2026-09-10 10:00:00',
            'neste_trekk' => '2026-11-01', 'trekk_dag' => 1]);
        $GLOBALS['l6b_id'] = $id2;
        DB::settInn('payments', ['vipps_reference' => Vipps::nyReferanse('MED'), 'vipps_psp_ref' => 'chg_okt_' . $id2,
            'type' => 'recurring_charge', 'formal' => 'medlemskap', 'member_id' => $s['admin'], 'subscription_id' => $id2,
            'belop_ore' => $PRIS, 'status' => 'venter', 'idempotency_key' => 'okt-' . $id2, 'gjelder_fra' => '2026-10-01']);
        Medlemskap::foerManglendeForsteTrekk();
        $init = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND idempotency_key LIKE 'init:%'", ['s' => $id2]);
        $sjekk($init !== null && (int) $init['belop_ore'] === 199000 && $init['gjelder_fra'] === '2026-09-10'
            && count($rader($id2)) === 2 && $init['created_at'] === '2026-09-10 10:00:00',
            'senere trekk finnes: foerste trekk foeres likevel, 199000 oere for september (gjelder_fra ' . ($init['gjelder_fra'] ?? '-') . ')');

        // Kontrollor 02.10: avklarte avtaler slaas ikke opp hver natt.
        $sjekket = static fn(int $sid): ?string => DB::verdi('SELECT forste_trekk_sjekket FROM subscriptions WHERE id = :i', ['i' => $sid]);
        $sjekk($sjekket($id) !== null && $sjekket($id2) !== null, 'avtaler der foerste trekk ble foert, merkes avklart');
        // Foerste trekk staar alt paa en ordinaer rad (samme charge-id).
        $v3 = Vipps::opprettAvtale($plan, $PRIS, 'Medlemskap test', 'http://127.0.0.1/retur', null, 'maaned');
        $initId = (string) (Vipps::trekkPaaAvtale($v3['avtaleId'])[0]['id'] ?? '');
        $id3 = DB::settInn('subscriptions', ['member_id' => $s['admin'], 'plan' => $plan, 'pris_ore' => $PRIS,
            'status' => 'aktiv', 'vipps_agreement_id' => $v3['avtaleId'], 'created_at' => '2026-09-20 10:00:00',
            'neste_trekk' => '2026-11-01', 'trekk_dag' => 1]);
        $GLOBALS['l6c_id'] = $id3;
        DB::settInn('payments', ['vipps_reference' => Vipps::nyReferanse('MED'), 'vipps_psp_ref' => $initId,
            'type' => 'recurring_charge', 'formal' => 'medlemskap', 'member_id' => $s['admin'], 'subscription_id' => $id3,
            'belop_ore' => $PRIS, 'status' => 'betalt', 'idempotency_key' => 'ordinaer-' . $id3, 'gjelder_fra' => '2026-09-01']);
        Medlemskap::foerManglendeForsteTrekk();
        $sjekk($initId !== '' && count($rader($id3)) === 1 && $sjekket($id3) !== null,
            'foerste trekk staar alt paa en ordinaer rad: ingen ny rad, avtalen merkes avklart');
        $logg = __DIR__ . '/.falsk-vipps.jsonl';
        $fraLinje = count(file($logg));
        Medlemskap::foerManglendeForsteTrekk();
        $oppslag = 0;
        foreach (array_slice(file($logg), $fraLinje) as $l) {
            $k = json_decode($l, true);
            if (($k['metode'] ?? '') === 'GET' && str_contains((string) ($k['sti'] ?? ''), $v3['avtaleId'] . '/charges')) { $oppslag++; }
        }
        $sjekk($oppslag === 0, 'neste natt slaas den avklarte avtalen ikke opp hos Vipps');
        // Avtale fra foer foerste trekk ved godkjenning (8. september) er aldri kandidat.
        $id4 = DB::settInn('subscriptions', ['member_id' => $s['admin'], 'plan' => $plan, 'pris_ore' => $PRIS,
            'status' => 'aktiv', 'vipps_agreement_id' => 'agr_GAMMEL_' . $s['tag'], 'created_at' => '2026-09-05 10:00:00',
            'neste_trekk' => '2026-11-05']);
        $GLOBALS['l6d_id'] = $id4;
        $sjekk(!in_array($id4, array_map('intval', array_column(Medlemskap::utenForsteTrekk(), 'id')), true),
            'avtale fra 5. september (foer initialCharge) slaas ikke opp');
    });

    // ────────────────────────────────────────────────────────────────────
    $del('Trekkdag den 1.: ny avtale godkjent 15. september', function () use ($sjekk, $avtaleNaa, $rader, $s, $plan, $PRIS, $styrfil): void {
        $v = Vipps::opprettAvtale($plan, $PRIS, 'Medlemskap test', 'http://127.0.0.1/retur', null, 'maaned');
        $id = DB::settInn('subscriptions', ['member_id' => $s['admin'], 'plan' => $plan, 'pris_ore' => $PRIS,
            'status' => 'venter', 'vipps_agreement_id' => $v['avtaleId'], 'created_at' => '2026-09-10 10:00:00']);
        $GLOBALS['dag_id'] = $id;
        $styrfil('.avtale-status', 'ACTIVE');
        Medlemskap::oppdaterFraVipps($avtaleNaa($id), '2026-09-15 08:00:00');
        $etter = $avtaleNaa($id);
        $p = $rader($id);
        $sjekk($etter['neste_trekk'] === '2026-10-01' && (int) $etter['trekk_dag'] === 1,
            "kjoept 10.09, godkjent 15.09: neste trekk 1. oktober, ikke 15. oktober (fikk {$etter['neste_trekk']})");
        $sjekk(count($p) === 1 && (int) $p[0]['belop_ore'] === 199000 && $p[0]['gjelder_fra'] === null,
            'foerste trekk 199000 oere gjelder september (kjoepsmaaneden)');
        $sjekk(Medlemskap::nesteTrekkdato('2026-10-01', (int) $etter['trekk_dag']) === '2026-11-01',
            'og deretter 1. november');
    });

    // ────────────────────────────────────────────────────────────────────
    $del('L-10: trekk i pausen hoppes over, aldri dobbelt', function () use ($sjekk, $nyAvtale, $avtaleNaa, $rader, $s, $loggLengde, $poster): void {
        $frys = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2026-10-20',
            'til_dato' => '2026-12-10', 'status' => 'godkjent']);
        $GLOBALS['l10_frys'][] = $frys;
        $a = $nyAvtale('agr_L10A_' . $s['tag'], '2026-11-01');
        $fra = $loggLengde();
        $ut = Medlemskap::trekk($a, '2026-10-29');
        $etter = $avtaleNaa((int) $a['id']);
        $sjekk($rader((int) $a['id']) === [] && $poster((string) $a['vipps_agreement_id'], $fra) === [],
            "1. november i pausen: ingen rad og ingen bestilling hos Vipps (svar: $ut)");
        $sjekk($etter['neste_trekk'] === '2026-12-01',
            "bare november hoppes over naa; desember sjekkes naar den kommer (fikk {$etter['neste_trekk']})");
        // 15-dagersregelen: frysen dekker bare 1.–10. desember (10 dager).
        $etter['epost'] = ''; $etter['navn'] = $s['tag'];
        $utDes = Medlemskap::trekk($etter, '2026-11-28');
        $etter = $avtaleNaa((int) $a['id']);
        $p = $rader((int) $a['id']);
        $sjekk($utDes === 'bedt om trekk til 2026-12-01' && count($p) === 1 && (int) $p[0]['belop_ore'] === 199000
            && $p[0]['gjelder_fra'] === '2026-12-01' && $etter['neste_trekk'] === '2027-01-01',
            "frys dekker 10 dager av desember (< 15): desember trekkes, 199000 oere (svar: $utDes)");
        $sjekk((int) DB::verdi('SELECT COALESCE(SUM(belop_ore),0) FROM payments WHERE subscription_id = :s', ['s' => (int) $a['id']]) === 199000,
            'sum for november–desember er 199000 oere — november gratis, ingen etterbetaling');

        // En frys som bare er soekt om, stopper ikke trekket.
        $frys2 = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2027-03-01',
            'til_dato' => '2027-03-31', 'status' => 'sokt']);
        $GLOBALS['l10_frys'][] = $frys2;
        $b = $nyAvtale('agr_L10B_' . $s['tag'], '2027-03-01');
        $ut3 = Medlemskap::trekk($b, '2027-02-26');
        $sjekk($ut3 === 'bedt om trekk til 2027-03-01' && count($rader((int) $b['id'])) === 1,
            'frys som bare er soekt om: trekket gaar som vanlig');

        // Codex 02.10: runden er forsinket, og frysen er alt satt til
        // «avsluttet» etter at perioden var over. Trekket i pausen hoppes
        // likevel over.
        $GLOBALS['l10_frys'][] = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2027-05-01',
            'til_dato' => '2027-05-20', 'status' => 'avsluttet', 'updated_at' => '2027-05-21 03:00:00']);
        $c = $nyAvtale('agr_L10C_' . $s['tag'], '2027-05-01');
        $ut4 = Medlemskap::trekk($c, '2027-05-22');
        $sjekk($rader((int) $c['id']) === [] && $avtaleNaa((int) $c['id'])['neste_trekk'] === '2027-06-01',
            "forsinket runde etter avsluttet pause: mai hoppes over, neste 1. juni (svar: $ut4)");

        // En frys verkstedet avbrot foer trekkdatoen, stopper ikke trekket.
        $GLOBALS['l10_frys'][] = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2027-07-20',
            'til_dato' => '2027-08-31', 'status' => 'avsluttet', 'updated_at' => '2027-07-25 10:00:00']);
        $d = $nyAvtale('agr_L10D_' . $s['tag'], '2027-08-01');
        $ut5 = Medlemskap::trekk($d, '2027-07-29');
        $sjekk($ut5 === 'bedt om trekk til 2027-08-01' && count($rader((int) $d['id'])) === 1,
            'pause avbrutt 25.07: trekket 1. august gaar som vanlig');

        // Avbrutt midt i: 1.–19. november (19 dager) laa i pausen, desember ikke.
        $GLOBALS['l10_frys'][] = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2027-10-20',
            'til_dato' => '2027-12-10', 'status' => 'avsluttet', 'updated_at' => '2027-11-20 10:00:00']);
        $e = $nyAvtale('agr_L10E_' . $s['tag'], '2027-11-01');
        $ut6 = Medlemskap::trekk($e, '2027-11-21');
        $sjekk($rader((int) $e['id']) === [] && $avtaleNaa((int) $e['id'])['neste_trekk'] === '2027-12-01',
            "pause avbrutt 20.11, forsinket runde: november (19 dager) hoppes over (svar: $ut6)");
        $e2 = $avtaleNaa((int) $e['id']) + ['epost' => '', 'navn' => $s['tag']];
        $ut7 = Medlemskap::trekk($e2, '2027-11-28');
        $sjekk($ut7 === 'bedt om trekk til 2027-12-01' && count($rader((int) $e['id'])) === 1,
            "… og 1. desember trekkes som vanlig (svar: $ut7)");

        // Codex 02.10: november hoppet over i oktober; pausen avbrytes 15.11
        // FOER desember behandles. Desember skal trekkes.
        $GLOBALS['l10_frys'][] = $fF = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2029-10-20',
            'til_dato' => '2029-12-10', 'status' => 'godkjent']);
        $f = $nyAvtale('agr_L10F_' . $s['tag'], '2029-11-01');
        Medlemskap::trekk($f, '2029-10-29');
        DB::kjor("UPDATE medlem_frys SET status = 'avsluttet', updated_at = '2029-11-15 09:00:00' WHERE id = :i", ['i' => $fF]);
        $f2 = $avtaleNaa((int) $f['id']) + ['epost' => '', 'navn' => $s['tag']];
        $ut8 = Medlemskap::trekk($f2, '2029-11-28');
        $sjekk($f2['neste_trekk'] === '2029-12-01' && $ut8 === 'bedt om trekk til 2029-12-01' && count($rader((int) $f['id'])) === 1,
            "pause avbrutt etter at november var hoppet over: desember trekkes (svar: $ut8)");

        // Avsluttet 1. oktober kl. 00.30 norsk tid (30.09 22.30 UTC): siste
        // pausedag er 30. september, ikke 29.
        $GLOBALS['l10_frys'][] = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2028-09-01',
            'til_dato' => '2028-10-31', 'status' => 'avsluttet', 'updated_at' => '2028-09-30 22:30:00']);
        $sjekk(Medlemskap::pauseDager((int) $s['admin'], '2028-09-01') === 30
            && Medlemskap::pauseDager((int) $s['admin'], '2028-10-01') === 0,
            'avslutning like etter midnatt: pausen gjelder til og med 30. september (norsk dato)');

        // Kontrollor 02.10, 15-dagersregelen.
        $GLOBALS['l10_frys'][] = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2030-10-31',
            'til_dato' => '2030-11-01', 'status' => 'godkjent']);
        $g = $nyAvtale('agr_L10G_' . $s['tag'], '2030-11-01');
        $ut9 = Medlemskap::trekk($g, '2030-10-29');
        $sjekk($ut9 === 'bedt om trekk til 2030-11-01' && count($rader((int) $g['id'])) === 1
            && (int) $rader((int) $g['id'])[0]['belop_ore'] === 199000,
            "frys 31.10–1.11 (1 dag av november): november trekkes, 199000 oere (svar: $ut9)");
        $GLOBALS['l10_frys'][] = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2030-12-02',
            'til_dato' => '2030-12-30', 'status' => 'godkjent']);
        $h = $nyAvtale('agr_L10H_' . $s['tag'], '2030-12-01');
        $ut10 = Medlemskap::trekk($h, '2030-11-28');
        $sjekk($rader((int) $h['id']) === [] && $avtaleNaa((int) $h['id'])['neste_trekk'] === '2031-01-01',
            "frys 2.12–30.12 (29 dager), selv om 1. desember er utenfor: desember gratis (svar: $ut10)");
        // Grensen: 15 dager hoppes over, 14 trekkes.
        $GLOBALS['l10_frys'][] = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2031-02-01',
            'til_dato' => '2031-02-15', 'status' => 'godkjent']);
        $GLOBALS['l10_frys'][] = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2031-04-01',
            'til_dato' => '2031-04-14', 'status' => 'godkjent']);
        $sjekk(Medlemskap::pauseDager((int) $s['admin'], '2031-02-01') === 15
            && Medlemskap::pauseDager((int) $s['admin'], '2031-04-01') === 14,
            'grensen: 15 dager (hoppes over) og 14 dager (trekkes) telles riktig');
        $i = $nyAvtale('agr_L10I_' . $s['tag'], '2031-02-01');
        $j = $nyAvtale('agr_L10J_' . $s['tag'], '2031-04-01');
        Medlemskap::trekk($i, '2031-01-29');
        Medlemskap::trekk($j, '2031-03-29');
        $sjekk($rader((int) $i['id']) === [] && count($rader((int) $j['id'])) === 1,
            'februar med 15 pausedager hoppes over; april med 14 trekkes');
    });

    // ────────────────────────────────────────────────────────────────────
    $del('L-12 + L-5: feilet uten charge-id + manuell betaling → ingen dobbel', function () use ($sjekk, $nyAvtale, $avtaleNaa, $rader, $s, $PRIS, $styrfil, $loggLengde, $poster): void {
        $manuell = static fn(array $a, string $fra = '2032-11-01') => DB::settInn('payments', ['vipps_reference' => 'MAN-' . $a['id'], 'type' => 'epayment',
            'formal' => 'medlemskap', 'member_id' => $s['admin'], 'subscription_id' => (int) $a['id'], 'belop_ore' => $PRIS,
            'status' => 'betalt', 'idempotency_key' => 'man-' . $a['id'], 'gjelder_fra' => $fra]);
        $antallHos = static fn(array $a): int => count(Vipps::trekkPaaAvtale((string) $a['vipps_agreement_id']));

        // a) Nettbrudd: trekket kom aldri til Vipps. November betales saa i
        //    verkstedet. Neste runde: ingen ny bestilling, maaneden hoppes over.
        $a = $nyAvtale('agr_L12A_' . $s['tag'], '2032-11-01');
        $styrfil('.trekk-feiler', 'ja');
        try { Medlemskap::trekk($a, '2032-10-29'); } catch (RuntimeException) {}
        $styrfil('.trekk-feiler', '');
        $manuell($a);
        $fra = $loggLengde();
        $ut = Medlemskap::trekk($a, '2032-10-30');
        $r = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'recurring_charge'", ['s' => (int) $a['id']]);
        $sjekk(str_starts_with($ut, 'betalt fra foer') && $poster((string) $a['vipps_agreement_id'], $fra) === []
            && $antallHos($a) === 0 && $r['status'] === 'avbrutt'
            && $avtaleNaa((int) $a['id'])['neste_trekk'] === '2032-12-01',
            "feilet uten charge-id + manuell betaling, ikke hos Vipps: ingen ny bestilling (svar: $ut)");
        $sjekk((int) DB::verdi("SELECT COALESCE(SUM(belop_ore),0) FROM payments WHERE subscription_id = :s AND status IN ('betalt','venter')",
            ['s' => (int) $a['id']]) === 199000, '… november koster 199000 oere, én gang');

        // b) Svaret ble borte: trekket LIGGER hos Vipps. November betales saa
        //    i verkstedet. Neste runde skal finne trekket (ikke hoppe over det
        //    og la pengene ligge ufoert) og aldri bestille et nytt.
        $b = $nyAvtale('agr_L12B_' . $s['tag'], '2033-11-01');
        $styrfil('.trekk-svar-tapt', 'ja');
        try { Medlemskap::trekk($b, '2033-10-29'); } catch (RuntimeException) {}
        $styrfil('.trekk-svar-tapt', '');
        $hos = Vipps::trekkPaaAvtale((string) $b['vipps_agreement_id']);
        $manuell($b, '2033-11-01');
        $fra = $loggLengde();
        $ut2 = Medlemskap::trekk($b, '2033-10-30');
        $r2 = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'recurring_charge'", ['s' => (int) $b['id']]);
        $sjekk(count($hos) === 1 && $poster((string) $b['vipps_agreement_id'], $fra) === [] && $antallHos($b) === 1
            && $r2['vipps_psp_ref'] === (string) $hos[0]['id'] && $r2['status'] === 'venter',
            "feilet uten charge-id + manuell betaling, trekket finnes hos Vipps: foeres, ikke nytt (svar: $ut2)");
    });

    // ────────────────────────────────────────────────────────────────────
    $del('Frys-beskjeden til verkstedet: avtalen stoppes ikke', function () use ($sjekk): void {
        $ny = 'Måneder frysen dekker minst 15 dager av, trekkes ikke. Medlemmet har tilgang ut den betalte perioden, og trekkene fortsetter av seg selv etterpå.';
        $rot = dirname(__DIR__);
        foreach (['api/admin/frys.php', 'admin-ny/administrasjon.js', 'lissom-2108.html'] as $fil) {
            $t = (string) file_get_contents($rot . '/' . $fil);
            $sjekk(str_contains($t, $ny)
                && !str_contains($t, 'den må stoppes, og medlemmet setter opp en ny')
                && !str_contains($t, 'Frys stopper ikke Vipps-trekk')
                && !str_contains($t, 'Vipps-trekk må følges opp separat')
                && !str_contains($t, 'Trekket stopper ikke av seg selv'),
                "$fil: sier at trekket i pausen hoppes over, ikke at avtalen maa stoppes");
        }
    });
} finally {
    foreach (['.trekk-idempotens', '.trekkliste-feiler', '.avtale-status', '.idag'] as $f) { @unlink($styr . $f); }
    $alle = array_merge($nye, array_map('intval', array_filter([$GLOBALS['l6_id'] ?? null, $GLOBALS['l6b_id'] ?? null, $GLOBALS['l6c_id'] ?? null, $GLOBALS['l6d_id'] ?? null, $GLOBALS['dag_id'] ?? null])));
    foreach ($alle as $sid) {
        DB::kjor("DELETE FROM notifications WHERE ref_type = 'medlemskap' AND ref_id IN (SELECT id FROM payments WHERE subscription_id = :s)", ['s' => $sid]);
        DB::kjor('DELETE FROM payments WHERE subscription_id = :s', ['s' => $sid]);
        DB::kjor('DELETE FROM subscriptions WHERE id = :i', ['i' => $sid]);
    }
    foreach ($GLOBALS['l10_frys'] ?? [] as $f) { DB::kjor('DELETE FROM medlem_frys WHERE id = :i', ['i' => $f]); }
}

echo "\n──────────────────────────────────────────────\n";
echo "$ok av " . ($ok + $feil) . " trekkontroller (L-5, L-6, L-10, trekkdag) gikk gjennom\n";
exit($feil === 0 ? 0 : 1);
