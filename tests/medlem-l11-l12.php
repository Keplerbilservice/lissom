<?php
/**
 * L-11, L-12 og laaserekkefoelgen i markerBetalt (pengeflyt-revisjonen,
 * eieren 2. oktober 2026).
 *
 *  - L-11: «slutter» er siste dag med tilgang. Avtalen stoppes foerst dagen
 *    etter (norsk tid), og medlemmet settes «oppsagt» bare naar ingen annen
 *    avtale loeper.
 *  - L-12: manuell medlemsbetaling i admin faar gjelder_fra, nektes (409) for
 *    en periode som alt er betalt, sier fra om fast trekk i Vipps, og et
 *    dobbelttrykk gir én betaling.
 *  - markerBetalt laaser gavekortet foer betalingen (som laasKort() ellers).
 *
 * Ekte endepunkter (to PHP-servere) mot en isolert testbase. Ingen Vipps,
 * ingen e-post, ingen SMS.   php tests/medlem-l11-l12.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
$oppsett = krev_testdatabase($rot);

// ── Hjelpeprosess: markerBetalt i en egen tilkobling ─────────────────────
if (($argv[1] ?? '') === '--marker') {
    require $rot . '/app/bootstrap.php';
    Booking::markerBetalt((string) $argv[2]);
    exit(0);
}

require $rot . '/app/bootstrap.php';

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

$servere = []; $porter = []; $medlemmer = []; $kortene = []; $betalinger = []; $admin = 0;
$tag = 'L1112-' . strtoupper(bin2hex(random_bytes(3)));
$logg = sys_get_temp_dir() . '/lissom-l1112-' . bin2hex(random_bytes(4)) . '.log';
$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$idag = (new DateTimeImmutable('now', $oslo))->format('Y-m-d');
$igaar = (new DateTimeImmutable('now', $oslo))->modify('-1 day')->format('Y-m-d');
$denne = (new DateTimeImmutable('now', $oslo))->modify('first day of this month')->format('Y-m-d');
$forrige = (new DateTimeImmutable('now', $oslo))->modify('first day of last month')->format('Y-m-d');
$neste = (new DateTimeImmutable('now', $oslo))->modify('first day of next month')->format('Y-m-d');
$MND = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];
$denneTekst = $MND[(int) substr($denne, 5, 2) - 1] . ' ' . substr($denne, 0, 4);

function kall(array $kall): array
{
    $m = curl_multi_init(); $h = [];
    foreach ($kall as [$port, $sti, $data, $token]) {
        $c = curl_init('http://127.0.0.1:' . $port . $sti);
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . Config::nettsted(),
            'Cookie: lissom_sesjon=' . $token]]);
        curl_multi_add_handle($m, $c); $h[] = $c;
    }
    do { curl_multi_exec($m, $aktiv); if ($aktiv) { curl_multi_select($m, 0.05); } } while ($aktiv);
    $ut = [];
    foreach ($h as $c) {
        $ut[] = [curl_getinfo($c, CURLINFO_RESPONSE_CODE), json_decode((string) curl_multi_getcontent($c), true)];
        curl_multi_remove_handle($m, $c); curl_close($c);
    }
    curl_multi_close($m);
    return $ut;
}

/** Medlem med aktiv avtale kjoept den 5. for tre maaneder siden (ikke «nytt etter 20.»). */
function nyttMedlem(string $plan, string $navn, ?string $avtaleId = null): array
{
    global $medlemmer, $tag, $oslo, $utc;
    $kjopt = (new DateTimeImmutable('now', $oslo))->modify('first day of this month')->modify('-3 months')
        ->modify('+4 days')->setTime(12, 0);
    $m = DB::settInn('members', ['navn' => $navn, 'epost' => strtolower($tag) . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'rolle' => 'medlem', 'status' => 'aktiv', 'medlemskap_type' => $plan, 'start_dato' => $kjopt->format('Y-m-d')]);
    $medlemmer[] = $m;
    $s = DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $plan, 'pris_ore' => 50000, 'status' => 'aktiv',
        'vipps_agreement_id' => $avtaleId, 'created_at' => $kjopt->setTimezone($utc)->format('Y-m-d H:i:s')]);
    return [$m, $s];
}

function rader(int $m): array
{
    return DB::alle("SELECT id, type, belop_ore, gavekort_ore, gjelder_fra, status FROM payments WHERE member_id = :m AND formal = 'medlemskap' ORDER BY id", ['m' => $m]);
}

try {
    $plan = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 0 AND aktiv = 1 AND krever_fast_trekk = 0 ORDER BY pris_ore LIMIT 1');
    $proveplan = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 1 ORDER BY navn LIMIT 1');
    sjekk('testplanene finnes', $plan !== '' && $proveplan !== '', "$plan / $proveplan");

    // ══ L-11: avslutningsdagen ═══════════════════════════════════════════
    echo "\n── L-11: tilgang ut siste dag ──\n";
    [$m1, $s1] = nyttMedlem($plan, 'L11 Sluttdag');
    DB::oppdater('subscriptions', ['sagt_opp_at' => gmdate('Y-m-d H:i:s'), 'slutter' => $idag], ['id' => $s1]);
    $ider = array_map('intval', array_column(Medlemskap::tilAvslutning(), 'id'));
    sjekk("slutter = i dag ($idag): står IKKE til avslutning (tilgang ut dagen)", !in_array($s1, $ider, true));
    DB::oppdater('subscriptions', ['slutter' => $igaar], ['id' => $s1]);
    $ider = array_map('intval', array_column(Medlemskap::tilAvslutning(), 'id'));
    sjekk("slutter = i går ($igaar): står til avslutning", in_array($s1, $ider, true));
    DB::oppdater('subscriptions', ['slutter' => '2026-01-31'], ['id' => $s1]);
    sjekk('slutter 31.01, i dag 31.01 (Oslo): ikke stoppet ennå',
        !in_array($s1, array_map('intval', array_column(Medlemskap::tilAvslutning('2026-01-31'), 'id')), true));
    sjekk('slutter 31.01, i dag 01.02: stoppes',
        in_array($s1, array_map('intval', array_column(Medlemskap::tilAvslutning('2026-02-01'), 'id')), true));

    // Avslutning med en annen avtale som loeper: ikke «oppsagt».
    [$m2, $s2gml] = nyttMedlem($plan, 'L11 To avtaler');
    $s2ny = DB::settInn('subscriptions', ['member_id' => $m2, 'plan' => $plan, 'pris_ore' => 50000, 'status' => 'aktiv']);
    DB::oppdater('subscriptions', ['slutter' => $igaar, 'sagt_opp_at' => gmdate('Y-m-d H:i:s')], ['id' => $s2gml]);
    Medlemskap::avslutt(DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $s2gml]));
    sjekk('gammel avtale stoppet', DB::verdi('SELECT status FROM subscriptions WHERE id = :i', ['i' => $s2gml]) === 'stoppet');
    sjekk('… medlemmet er fortsatt aktiv (ny avtale løper)', DB::verdi('SELECT status FROM members WHERE id = :i', ['i' => $m2]) === 'aktiv');
    Medlemskap::avslutt(DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $s2ny]));
    sjekk('… og «oppsagt» når den siste stoppes', DB::verdi('SELECT status FROM members WHERE id = :i', ['i' => $m2]) === 'oppsagt');

    // ══ L-12: manuell medlemsbetaling ════════════════════════════════════
    $admin = DB::settInn('members', ['navn' => 'L1112 Admin', 'epost' => strtolower($tag) . '-admin@lissom.test', 'rolle' => 'admin']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token), 'maate' => 'passord',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    for ($i = 0; $i < 2; $i++) {
        $s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
        $port = (int) substr($adr, strrpos($adr, ':') + 1); $porter[] = $port;
        $p = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot],
            [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pipes, $rot);
        fclose($pipes[0]); $servere[] = $p;
        $klar = false; for ($v = 0; $v < 40; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }
        sjekk('HTTP-server ' . $i . ' klar', $klar);
    }
    $API = '/api/admin/medlemmer.php';
    $kontant = static fn(int $m): array => ['handling' => 'betaling', 'medlemId' => $m, 'maate' => 'Kontant', 'belop' => '500'];

    echo "\n── L-12.1: kontant 500 kr, denne måneden ──\n";
    [$a, $aS] = nyttMedlem($plan, 'L12 Kontant');
    $svar = kall([[$porter[0], $API, $kontant($a), $token]]);
    $r = rader($a);
    sjekk('godtas (200)', $svar[0][0] === 200 && ($svar[0][1]['ok'] ?? false) === true, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk('én rad, 50000 øre', count($r) === 1 && (int) $r[0]['belop_ore'] === 50000, json_encode($r));
    sjekk("gjelder_fra = $denne", ($r[0]['gjelder_fra'] ?? null) === $denne, (string) ($r[0]['gjelder_fra'] ?? 'NULL'));
    sjekk('ingen Vipps-advarsel uten avtale i Vipps', ($svar[0][1]['advarsel'] ?? null) === null);

    echo "\n── L-12.2: samme periode én gang til ──\n";
    $svar = kall([[$porter[0], $API, $kontant($a), $token]]);
    sjekk('nektes (409)', $svar[0][0] === 409, $svar[0][0] . ' ' . json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    $tekst = (string) ($svar[0][1]['feil'] ?? $svar[0][1]['melding'] ?? json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk("teksten sier perioden ($denneTekst)", str_contains($tekst, 'allerede betalt medlemskapet for ' . $denneTekst), $tekst);
    sjekk('fortsatt én rad', count(rader($a)) === 1, (string) count(rader($a)));
    // Annullert feilregistrering («Angre feilregistrering») sperrer ikke.
    DB::oppdater('payments', ['annullert_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) rader($a)[0]['id']]);
    $svar = kall([[$porter[0], $API, $kontant($a), $token]]);
    sjekk('etter annullering kan perioden registreres på nytt (200)', $svar[0][0] === 200, (string) $svar[0][0]);

    echo "\n── L-12.3: dobbelttrykk (to samtidige) ──\n";
    foreach ([1, 2, 3] as $runde) {
        [$b] = nyttMedlem($plan, "L12 Dobbel $runde");
        $svar = kall([[$porter[0], $API, $kontant($b), $token], [$porter[1], $API, $kontant($b), $token]]);
        $lyktes = count(array_filter($svar, static fn($s) => $s[0] === 200));
        $nektet = count(array_filter($svar, static fn($s) => $s[0] === 409));
        $r = rader($b);
        sjekk("runde $runde: én godtas, én nektes (409)", $lyktes === 1 && $nektet === 1, json_encode(array_column($svar, 0)));
        sjekk("runde $runde: én rad, 50000 øre", count($r) === 1 && array_sum(array_column($r, 'belop_ore')) === 50000, json_encode($r));
    }

    echo "\n── L-12.4: delt betaling (kontant 300 + Vipps 200) ──\n";
    $delt = static fn(int $m): array => ['handling' => 'betaling', 'medlemId' => $m, 'deler' => [
        ['maate' => 'Kontant', 'belop' => '300'], ['maate' => 'Vipps', 'belop' => '200']]];
    [$c] = nyttMedlem($plan, 'L12 Delt');
    $svar = kall([[$porter[0], $API, $delt($c), $token]]);
    $r = rader($c);
    sjekk('godtas (200)', $svar[0][0] === 200, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk('to rader, sum 50000 øre (30000 + 20000)', count($r) === 2 && array_column($r, 'belop_ore') === [30000, 20000], json_encode($r));
    sjekk("… begge gjelder_fra = $denne", count(array_filter($r, static fn($x) => $x['gjelder_fra'] === $denne)) === 2, json_encode(array_column($r, 'gjelder_fra')));
    $svar = kall([[$porter[0], $API, $delt($c), $token]]);
    sjekk('en ny delt betaling for samme periode nektes (409)', $svar[0][0] === 409, (string) $svar[0][0]);
    sjekk('… fortsatt to rader', count(rader($c)) === 2);
    [$c2] = nyttMedlem($plan, 'L12 Delt dobbel');
    $svar = kall([[$porter[0], $API, $delt($c2), $token], [$porter[1], $API, $delt($c2), $token]]);
    sjekk('delt dobbelttrykk: én godtas', count(array_filter($svar, static fn($s) => $s[0] === 200)) === 1, json_encode(array_column($svar, 0)));
    sjekk('… to rader, sum 50000 øre', count(rader($c2)) === 2 && array_sum(array_column(rader($c2), 'belop_ore')) === 50000);

    echo "\n── L-12.5: perioden er alt trukket i Vipps ──\n";
    [$d, $dS] = nyttMedlem($plan, 'L12 Trukket', 'agr-' . strtolower($tag));
    $betalinger[] = DB::settInn('payments', ['vipps_reference' => $tag . '-TREKK', 'type' => 'recurring_charge', 'formal' => 'medlemskap',
        'member_id' => $d, 'subscription_id' => $dS, 'belop_ore' => 50000, 'status' => 'betalt', 'gjelder_fra' => $denne,
        'idempotency_key' => $tag . '-trekk']);
    $svar = kall([[$porter[0], $API, $kontant($d), $token]]);
    sjekk('manuell betaling for samme måned nektes (409)', $svar[0][0] === 409, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk('… bare trekket står (50000 øre)', count(rader($d)) === 1);

    echo "\n── L-12.6: kjøpt etter den 20. — resten av måneden er betalt ──\n";
    [$e, $eS] = nyttMedlem($plan, 'L12 Forskuttert');
    $kjopt21 = (new DateTimeImmutable($denne . ' 12:00', $oslo))->modify('+20 days')->setTimezone($utc)->format('Y-m-d H:i:s');
    $betalinger[] = DB::settInn('payments', ['vipps_reference' => $tag . '-FORSK', 'type' => 'epayment', 'formal' => 'medlemskap',
        'member_id' => $e, 'subscription_id' => $eS, 'belop_ore' => 50000, 'status' => 'betalt', 'gjelder_fra' => $neste,
        'idempotency_key' => $tag . '-forsk', 'created_at' => $kjopt21]);
    $svar = kall([[$porter[0], $API, $kontant($e), $token]]);
    sjekk('ny betaling i kjøpsmåneden nektes (409)', $svar[0][0] === 409, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk("… og meldingen gjelder måneden som skulle betales ($denneTekst)",
        str_contains((string) ($svar[0][1]['feil'] ?? ''), 'for ' . $denneTekst . '.'), (string) ($svar[0][1]['feil'] ?? ''));

    echo "\n── Punkt 4: «Forny» etter den 20. sperrer ikke kjøpsmåneden ──\n";
    // Samme form som et nytt kjøp etter den 20., men ikke den første
    // betalingen på avtalen: den dekker bare sin egen måned.
    [$fo, $foS] = nyttMedlem($plan, 'L12 Forny etter 20');
    $betalinger[] = DB::settInn('payments', ['vipps_reference' => $tag . '-FO1', 'type' => 'epayment', 'formal' => 'medlemskap',
        'member_id' => $fo, 'subscription_id' => $foS, 'belop_ore' => 50000, 'status' => 'betalt', 'gjelder_fra' => $forrige,
        'idempotency_key' => $tag . '-fo1', 'created_at' => (new DateTimeImmutable($forrige . ' 12:00', $oslo))->setTimezone($utc)->format('Y-m-d H:i:s')]);
    $betalinger[] = DB::settInn('payments', ['vipps_reference' => $tag . '-FO2', 'type' => 'epayment', 'formal' => 'medlemskap',
        'member_id' => $fo, 'subscription_id' => $foS, 'belop_ore' => 50000, 'status' => 'betalt', 'gjelder_fra' => $neste,
        'idempotency_key' => $tag . '-fo2', 'created_at' => $kjopt21]);
    $svar = kall([[$porter[0], $API, $kontant($fo), $token]]);
    sjekk("denne måneden ($denneTekst) kan betales (200)", $svar[0][0] === 200, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk("… raden gjelder $denne, 50000 øre", count(array_filter(rader($fo), static fn($x) => $x['gjelder_fra'] === $denne && (int) $x['belop_ore'] === 50000)) === 1,
        json_encode(rader($fo)));

    echo "\n── Punkt 2: fast trekk underveis i Vipps ──\n";
    foreach (['venter', 'opprettet'] as $st) {
        [$tv, $tvS] = nyttMedlem($plan, "L12 Trekk $st", 'agr-' . $st . '-' . strtolower($tag));
        $betalinger[] = DB::settInn('payments', ['vipps_reference' => $tag . '-TV-' . $st, 'type' => 'recurring_charge', 'formal' => 'medlemskap',
            'member_id' => $tv, 'subscription_id' => $tvS, 'belop_ore' => 50000, 'status' => $st, 'gjelder_fra' => $denne,
            'idempotency_key' => $tag . '-tv-' . $st]);
        $svar = kall([[$porter[0], $API, $kontant($tv), $token]]);
        sjekk("trekk «{$st}» for $denneTekst: manuell betaling nektes (409)", $svar[0][0] === 409, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
        sjekk('… med norsk tekst om trekket', str_contains((string) ($svar[0][1]['feil'] ?? ''), 'fast trekk i Vipps for ' . $denneTekst . ' er underveis'),
            (string) ($svar[0][1]['feil'] ?? ''));
        sjekk('… og ingen manuell rad', count(rader($tv)) === 1);
    }
    // Feilet MED trekk-id: Vipps har svart at det ikke ble trukket.
    [$tf, $tfS] = nyttMedlem($plan, 'L12 Trekk feilet', 'agr-feilet-' . strtolower($tag));
    $betalinger[] = DB::settInn('payments', ['vipps_reference' => $tag . '-TF', 'type' => 'recurring_charge', 'formal' => 'medlemskap',
        'member_id' => $tf, 'subscription_id' => $tfS, 'belop_ore' => 50000, 'status' => 'feilet', 'gjelder_fra' => $denne,
        'vipps_psp_ref' => 'chg-' . strtolower($tag), 'idempotency_key' => $tag . '-tf']);
    $svar = kall([[$porter[0], $API, $kontant($tf), $token]]);
    sjekk('feilet trekk med trekk-id: manuell betaling godtas (200)', $svar[0][0] === 200, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk('… én manuell rad på 50000 øre', count(array_filter(rader($tf), static fn($x) => $x['type'] === 'manuell' && (int) $x['belop_ore'] === 50000)) === 1);
    // Feilet UTEN trekk-id (b): svaret kom aldri, trekket kan ligge hos Vipps.
    [$tu, $tuS] = nyttMedlem($plan, 'L12 Trekk feilet uten id', 'agr-utenid-' . strtolower($tag));
    $betalinger[] = DB::settInn('payments', ['vipps_reference' => $tag . '-TU', 'type' => 'recurring_charge', 'formal' => 'medlemskap',
        'member_id' => $tu, 'subscription_id' => $tuS, 'belop_ore' => 50000, 'status' => 'feilet', 'gjelder_fra' => $denne,
        'idempotency_key' => $tag . '-tu']);
    $svar = kall([[$porter[0], $API, $kontant($tu), $token]]);
    sjekk('feilet trekk UTEN trekk-id: manuell betaling nektes (409)', $svar[0][0] === 409
        && str_contains((string) ($svar[0][1]['feil'] ?? ''), 'fast trekk i Vipps for ' . $denneTekst), json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk('… og ingen manuell rad', count(rader($tu)) === 1);

    echo "\n── Funn 2: Prøv Lissom uten avtalerad er ikke en vanlig måned ──\n";
    // Medlemmet betalte Prøv Lissom uten avtale denne måneden, og har nå et
    // vanlig medlemskap. Betalingen uten avtale leses som Prøv Lissom (planen
    // på medlemmet) og sperrer ikke den vanlige måneden.
    [$pv, $pvS] = nyttMedlem($proveplan, 'L12 Prøve uten avtale betalt');
    DB::kjor('DELETE FROM subscriptions WHERE id = :s', ['s' => $pvS]);
    DB::oppdater('members', ['medlemskap_type' => $proveplan], ['id' => $pv]);
    $betalinger[] = DB::settInn('payments', ['vipps_reference' => $tag . '-PV', 'type' => 'manuell', 'formal' => 'medlemskap',
        'member_id' => $pv, 'belop_ore' => 30000, 'status' => 'betalt', 'idempotency_key' => $tag . '-pv']);
    sjekk('Prøv Lissom-betaling uten avtale teller ikke som vanlig måned',
        Medlemskap::betalingForMaaned($pv, substr($denne, 0, 7)) === null);
    DB::oppdater('members', ['medlemskap_type' => $plan], ['id' => $pv]);
    sjekk('… mens samme betaling teller når medlemmet står på et vanlig medlemskap (ingen avtale)',
        Medlemskap::betalingForMaaned($pv, substr($denne, 0, 7)) !== null);

    echo "\n── Punkt 3: trekk() hopper over en måned som er betalt i verkstedet ──\n";
    [$tr, $trS] = nyttMedlem($plan, 'L12 Trekk betalt manuelt', 'agr-man-' . strtolower($tag));
    $trekkDag = (new DateTimeImmutable($denne))->modify('+24 days')->format('Y-m-d');
    DB::oppdater('subscriptions', ['neste_trekk' => $trekkDag, 'trekk_dag' => 25], ['id' => $trS]);
    $betalinger[] = DB::settInn('payments', ['vipps_reference' => $tag . '-TRM', 'type' => 'manuell', 'formal' => 'medlemskap',
        'member_id' => $tr, 'subscription_id' => $trS, 'belop_ore' => 50000, 'status' => 'betalt', 'gjelder_fra' => $denne,
        'idempotency_key' => $tag . '-trm']);
    $fikk = '';
    try {
        $fikk = Medlemskap::trekk(DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $trS]));
    } catch (Throwable $ex) {
        $fikk = 'kastet: ' . $ex->getMessage();
    }
    $nesteTrekk = (string) DB::verdi('SELECT neste_trekk FROM subscriptions WHERE id = :i', ['i' => $trS]);
    sjekk('trekket bestilles ikke (ingen Vipps-kall)', $fikk === 'betalt fra foer', $fikk);
    sjekk('… ingen trekkrad; bare den manuelle (50000 øre)', count(rader($tr)) === 1 && rader($tr)[0]['type'] === 'manuell', json_encode(rader($tr)));
    sjekk('… neste trekk flyttet en måned (' . (new DateTimeImmutable($neste))->modify('+24 days')->format('Y-m-d') . ')',
        $nesteTrekk === (new DateTimeImmutable($neste))->modify('+24 days')->format('Y-m-d'), $nesteTrekk);

    echo "\n── L-12.7: forrige måned betalt, denne ikke ──\n";
    [$f, $fS] = nyttMedlem($plan, 'L12 Forrige');
    $betalinger[] = DB::settInn('payments', ['vipps_reference' => $tag . '-FORR', 'type' => 'manuell', 'formal' => 'medlemskap',
        'member_id' => $f, 'subscription_id' => $fS, 'belop_ore' => 50000, 'status' => 'betalt', 'gjelder_fra' => $forrige,
        'idempotency_key' => $tag . '-forr']);
    $svar = kall([[$porter[0], $API, $kontant($f), $token]]);
    sjekk('godtas (200) — ny periode', $svar[0][0] === 200, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk("… den nye raden gjelder $denne", (rader($f)[1]['gjelder_fra'] ?? null) === $denne, json_encode(rader($f)));

    echo "\n── L-12.8: aktiv Vipps-avtale gir advarsel ──\n";
    [$g] = nyttMedlem($plan, 'L12 Vippsavtale', 'agr2-' . strtolower($tag));
    $svar = kall([[$porter[0], $API, $kontant($g), $token]]);
    sjekk('godtas (200)', $svar[0][0] === 200, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk('… med advarsel om fast trekk', str_contains((string) ($svar[0][1]['advarsel'] ?? ''), 'fast trekk i Vipps')
        && str_contains((string) ($svar[0][1]['beskjed'] ?? ''), 'fast trekk i Vipps'), json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));

    echo "\n── L-12.9: gavekort 500 kr ──\n";
    [$h] = nyttMedlem($plan, 'L12 Gavekort');
    $k1 = DB::settInn('gift_cards', ['kode' => $tag . '-G1', 'opprinnelig_ore' => 50000, 'saldo_ore' => 50000,
        'gyldig_til' => gmdate('Y-m-d', time() + 365 * 86400), 'status' => 'aktivt']); $kortene[] = $k1;
    $k2 = DB::settInn('gift_cards', ['kode' => $tag . '-G2', 'opprinnelig_ore' => 50000, 'saldo_ore' => 50000,
        'gyldig_til' => gmdate('Y-m-d', time() + 365 * 86400), 'status' => 'aktivt']); $kortene[] = $k2;
    $gave = static fn(int $m, string $kode): array => ['handling' => 'betaling', 'medlemId' => $m, 'maate' => 'Gavekort', 'kode' => $kode, 'belop' => '500'];
    $svar = kall([[$porter[0], $API, $gave($h, $tag . '-G1'), $token]]);
    $r = rader($h);
    sjekk('godtas; gavekort_ore 50000, belop_ore 0, kortet 0', $svar[0][0] === 200 && count($r) === 1
        && (int) $r[0]['gavekort_ore'] === 50000 && (int) $r[0]['belop_ore'] === 0
        && (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :k', ['k' => $k1]) === 0, json_encode($r));
    $svar = kall([[$porter[0], $API, $gave($h, $tag . '-G2'), $token]]);
    sjekk('samme periode med et nytt kort nektes (409)', $svar[0][0] === 409, (string) $svar[0][0]);
    sjekk('… det andre kortet er urørt (50000 øre)', (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :k', ['k' => $k2]) === 50000);

    echo "\n── L-12.10: Prøv Lissom betales én gang ──\n";
    [$p1, $p1S] = nyttMedlem($proveplan, 'L12 Prøve');
    DB::oppdater('members', ['medlemskap_type' => $proveplan], ['id' => $p1]);
    $svar = kall([[$porter[0], $API, $kontant($p1), $token]]);
    sjekk('første betaling godtas (200)', $svar[0][0] === 200, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    sjekk('… uten gjelder_fra (engangsplanen gjelder kjøpsdagen)', count(rader($p1)) === 1 && rader($p1)[0]['gjelder_fra'] === null, json_encode(rader($p1)));
    $svar = kall([[$porter[0], $API, $kontant($p1), $token]]);
    sjekk('andre betaling nektes (409)', $svar[0][0] === 409, (string) $svar[0][0]);
    // Meldt inn for haand uten avtalerad (Codex P2): ogsaa da én gang.
    [$p2, $p2S] = nyttMedlem($proveplan, 'L12 Prøve uten avtale');
    DB::kjor('DELETE FROM subscriptions WHERE id = :s', ['s' => $p2S]);
    $svar = kall([[$porter[0], $API, $kontant($p2), $token], [$porter[1], $API, $kontant($p2), $token]]);
    sjekk('uten avtalerad: dobbelttrykk gir én betaling', count(array_filter($svar, static fn($s) => $s[0] === 200)) === 1
        && count(rader($p2)) === 1, json_encode(array_column($svar, 0)));
    $svar = kall([[$porter[0], $API, $kontant($p2), $token]]);
    sjekk('… og en ny betaling senere nektes (409)', $svar[0][0] === 409, (string) $svar[0][0]);

    // ══ markerBetalt: kortet foer betalingen ═════════════════════════════
    echo "\n── markerBetalt låser gavekortet før betalingen ──\n";
    $kM = DB::settInn('gift_cards', ['kode' => $tag . '-MB', 'opprinnelig_ore' => 50000, 'saldo_ore' => 50000,
        'gyldig_til' => gmdate('Y-m-d', time() + 365 * 86400), 'status' => 'aktivt']); $kortene[] = $kM;
    $ref = $tag . '-MB';
    $pM = DB::settInn('payments', ['vipps_reference' => $ref, 'type' => 'epayment', 'formal' => 'booking',
        'belop_ore' => 20000, 'gavekort_id' => $kM, 'gavekort_ore' => 30000, 'status' => 'opprettet', 'idempotency_key' => $tag . '-mb']);
    $betalinger[] = $pM;
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $oppsett['db_vert'], $oppsett['db_port'] ?? 3306, $oppsett['db_navn']);
    $holder = new PDO($dsn, $oppsett['db_bruker'], $oppsett['db_passord'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $holder->beginTransaction();
    $holder->query('SELECT id FROM gift_cards WHERE id = ' . $kM . ' FOR UPDATE')->fetchAll();
    $nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--marker', $ref],
        [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $logg, 'a']], $pp, $rot);
    fclose($pp[0]);
    usleep(1_500_000);
    $sjekker = new PDO($dsn, $oppsett['db_bruker'], $oppsett['db_passord'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sjekker->beginTransaction();
    $ledig = true;
    try {
        $sjekker->query('SELECT id FROM payments WHERE id = ' . $pM . ' FOR UPDATE NOWAIT')->fetchAll();
    } catch (PDOException $ex) {
        $ledig = false;
    }
    $sjekker->rollBack();
    sjekk('mens kortet er låst av en annen, venter markerBetalt UTEN å holde betalingen', $ledig);
    $holder->commit();
    $slutt = time() + 30;
    while (proc_get_status($proc)['running'] && time() < $slutt) { usleep(100_000); }
    proc_close($proc);
    sjekk('… og fullfører etterpå: betalt, kortet trukket 30000 øre (50000 → 20000)',
        DB::verdi('SELECT status FROM payments WHERE id = :p', ['p' => $pM]) === 'betalt'
        && (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :k', ['k' => $kM]) === 20000,
        DB::verdi('SELECT status FROM payments WHERE id = :p', ['p' => $pM]) . ' / ' . DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :k', ['k' => $kM]));
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage() . ' @ ' . $e->getLine());
} finally {
    foreach ($servere as $p) { proc_terminate($p); proc_close($p); }
    foreach ($medlemmer as $m) {
        foreach (array_column(DB::alle('SELECT id FROM payments WHERE member_id = :m', ['m' => $m]), 'id') as $pid) {
            DB::kjor('DELETE FROM gift_card_uses WHERE payment_id = :p', ['p' => $pid]);
        }
        DB::kjor('DELETE FROM payments WHERE member_id = :m', ['m' => $m]);
        DB::kjor('DELETE FROM subscriptions WHERE member_id = :m', ['m' => $m]);
        DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'member' AND objekt_id = :m", ['m' => $m]);
        DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $m]);
    }
    foreach ($betalinger as $pid) {
        DB::kjor('DELETE FROM gift_card_uses WHERE payment_id = :p', ['p' => $pid]);
        DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pid]);
    }
    foreach ($kortene as $k) {
        DB::kjor('DELETE FROM gift_card_uses WHERE gift_card_id = :k', ['k' => $k]);
        DB::kjor('DELETE FROM payments WHERE gavekort_id = :k', ['k' => $k]);
        DB::kjor('DELETE FROM gift_cards WHERE id = :k', ['k' => $k]);
    }
    if ($admin) {
        DB::kjor('DELETE FROM audit_log WHERE member_id = :m', ['m' => $admin]);
        DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => $admin]);
        DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $admin]);
    }
    @unlink($logg);
}
echo "\n  $ok ok, $feil feil\n";
exit($feil === 0 ? 0 : 1);
