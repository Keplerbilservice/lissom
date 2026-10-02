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
        $sjekk($init !== null && (int) $init['belop_ore'] === 199000 && $init['gjelder_fra'] === '2026-09-01'
            && count($rader($id2)) === 2,
            'senere trekk finnes: foerste trekk foeres likevel, 199000 oere for september (gjelder_fra ' . ($init['gjelder_fra'] ?? '-') . ')');
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
        $sjekk($etter['neste_trekk'] === '2027-01-01',
            "1. november og 1. desember hoppes over; neste trekk 1. januar (fikk {$etter['neste_trekk']})");
        $etter['epost'] = ''; $etter['navn'] = $s['tag'];
        $ut2 = Medlemskap::trekk($etter, '2026-12-29');
        $p = $rader((int) $a['id']);
        $sjekk($ut2 === 'bedt om trekk til 2027-01-01' && count($p) === 1 && (int) $p[0]['belop_ore'] === 199000
            && $p[0]['gjelder_fra'] === '2027-01-01', 'etter pausen: ett trekk 1. januar paa 199000 oere');
        $sjekk((int) DB::verdi('SELECT COALESCE(SUM(belop_ore),0) FROM payments WHERE subscription_id = :s', ['s' => (int) $a['id']]) === 199000,
            'sum for november–januar er 199000 oere — ingen etterbetaling for pausen');

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

        // Avbrutt midt i: 1. november laa i pausen, 1. desember ikke.
        $GLOBALS['l10_frys'][] = DB::settInn('medlem_frys', ['member_id' => $s['admin'], 'fra_dato' => '2027-10-20',
            'til_dato' => '2027-12-10', 'status' => 'avsluttet', 'updated_at' => '2027-11-15 10:00:00']);
        $e = $nyAvtale('agr_L10E_' . $s['tag'], '2027-11-01');
        $ut6 = Medlemskap::trekk($e, '2027-11-16');
        $sjekk($rader((int) $e['id']) === [] && $avtaleNaa((int) $e['id'])['neste_trekk'] === '2027-12-01',
            "pause avbrutt 15.11, forsinket runde: 1. november hoppes over, 1. desember trekkes (svar: $ut6)");
    });
} finally {
    foreach (['.trekk-idempotens', '.trekkliste-feiler', '.avtale-status', '.idag'] as $f) { @unlink($styr . $f); }
    $alle = array_merge($nye, array_map('intval', array_filter([$GLOBALS['l6_id'] ?? null, $GLOBALS['l6b_id'] ?? null, $GLOBALS['dag_id'] ?? null])));
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
