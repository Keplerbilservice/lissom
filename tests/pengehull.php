<?php
/**
 * Fire pengehull (betalingseksperten, 4. oktober 2026). Én regresjon per hull.
 *
 *   1  Fryst medlem som aldri har betalt: staar som skyldig bare naar det
 *      finnes en maaned aa betale (og den kan betales: Kassa, og Min side
 *      uten fast trekk). Er maanedene fritatt av frysen, staar det «Fryst».
 *   2  Kassa og «Forny» kan ikke betale samme maaned: «Forny» lagrer raden
 *      under medlemslaasen, og Kassa nekter (409) mens en egenbetaling for
 *      maaneden er paa vei i Vipps.
 *   3  Skyldig ser alle ubetalte maaneder: februar forsvinner ikke naar mars
 *      er betalt — status er forfalt, Kassa tar februar, og «Forny» lager
 *      ikke en ny betaling for en maaned som alt er betalt.
 *   4  pamelding.php «endre»: ekstra plasser paa en betalt paamelding gir
 *      restbeloep (som flytting, L-7). «fjern» nekter en Vipps-betalt plass
 *      ogsaa naar betalingen bare peker hit fra payments.booking_id.
 *
 * Ekte endepunkter (to PHP-servere) mot en isolert testbase. Vipps er
 * tests/vipps-stub.php (testen stopper om adressen ikke er stubben). Ingen
 * e-post eller SMS sendes.   php tests/pengehull.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
$oppsett = krev_testdatabase($rot);

// Falsk Vipps for «Forny» (opprettBetaling). Settes foer bootstrap, og arves
// av hjelpeprosessen under.
if (($argv[1] ?? '') !== '--forny') {
    $s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
    $vippsPort = (int) substr($adr, strrpos($adr, ':') + 1);
    $falskVipps = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $vippsPort, __DIR__ . '/vipps-stub.php'],
        [0 => ['pipe', 'r'], 1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
         2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a']], $vpipes, $rot);
    fclose($vpipes[0]);
    for ($v = 0; $v < 50; $v++) { $f = @fsockopen('127.0.0.1', $vippsPort, $e1, $e2, 0.1); if ($f) { fclose($f); break; } usleep(50000); }
    putenv('LISSOM_VIPPS_BASE=http://127.0.0.1:' . $vippsPort);
    register_shutdown_function(static function () use ($falskVipps): void { if (is_resource($falskVipps)) { proc_terminate($falskVipps); } });
}

require $rot . '/app/bootstrap.php';
if (!str_starts_with(Config::vippsBase(), 'http://127.0.0.1:')) {
    fwrite(STDERR, "Vipps-adressen er ikke den falske. Stopper.\n");
    exit(1);
}

// ── Hjelpeprosess: «Forny» i en egen tilkobling ──────────────────────────
if (($argv[1] ?? '') === '--forny') {
    $m = DB::en('SELECT * FROM members WHERE id = :i', ['i' => (int) $argv[2]]);
    $a = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => (int) $argv[3]]);
    try {
        $a === null ? Medlemskap::startEngangs($m, (string) $argv[4]) : Medlemskap::fornyPeriode($m, $a);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }
    exit(0);
}

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

$servere = []; $porter = []; $medlemmer = []; $kurs = []; $okter = []; $admin = 0;
$tag = 'HULL-' . strtoupper(bin2hex(random_bytes(3)));
$logg = sys_get_temp_dir() . '/lissom-hull-' . bin2hex(random_bytes(4)) . '.log';
$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$naa  = new DateTimeImmutable('now', $oslo);
$idag = $naa->format('Y-m-d');
$denne = $naa->modify('first day of this month')->format('Y-m-d');
$forrige = $naa->modify('first day of last month')->format('Y-m-d');
$toSiden = $naa->modify('first day of this month')->modify('-2 months')->format('Y-m-d');
$neste = $naa->modify('first day of next month')->format('Y-m-d');
$sisteINeste = $naa->modify('last day of next month')->format('Y-m-d');

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

/** Medlem med avtale laget den 5. for tre maaneder siden (ikke «nytt etter 20.»). */
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

/** Betalt medlemsbetaling for maaneden $fra (gjelder_fra), registrert midt i den. */
function betalt(int $m, int $s, string $fra, string $type = 'manuell', string $status = 'betalt', ?string $psp = null): int
{
    global $tag, $oslo, $utc;
    $laget = (new DateTimeImmutable($fra . ' 12:00', $oslo))->modify('+9 days');
    if ($laget > new DateTimeImmutable('now', $oslo)) {
        $laget = new DateTimeImmutable('now', $oslo);
    }
    return DB::settInn('payments', ['vipps_reference' => $tag . '-' . bin2hex(random_bytes(4)), 'type' => $type,
        'formal' => 'medlemskap', 'member_id' => $m, 'subscription_id' => $s, 'belop_ore' => 50000, 'status' => $status,
        'gjelder_fra' => $fra, 'vipps_psp_ref' => $psp, 'idempotency_key' => Vipps::uuid(),
        'created_at' => $laget->setTimezone($utc)->format('Y-m-d H:i:s')]);
}

function rader(int $m): array
{
    return DB::alle("SELECT id, type, belop_ore, gjelder_fra, status FROM payments WHERE member_id = :m AND formal = 'medlemskap' ORDER BY id", ['m' => $m]);
}

$medlem = static fn(int $id): array => DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);
$avtale = static fn(int $id): array => DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $id]);
$frys = static fn(int $m, string $fra, string $til): int => DB::settInn('medlem_frys', [
    'member_id' => $m, 'fra_dato' => $fra, 'til_dato' => $til, 'status' => 'godkjent', 'status_for' => 'aktiv']);

try {
    $plan = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 0 AND aktiv = 1 AND krever_fast_trekk = 0 ORDER BY pris_ore LIMIT 1');
    sjekk('testplanen finnes', $plan !== '', $plan);

    $admin = DB::settInn('members', ['navn' => 'Hull Admin', 'epost' => strtolower($tag) . '-admin@lissom.test', 'rolle' => 'admin']);
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
    $feiltekst = static fn(array $svar): string => (string) ($svar[1]['feil'] ?? $svar[1]['melding'] ?? json_encode($svar[1], JSON_UNESCAPED_UNICODE));

    // ══ Hull 1: fryst, aldri betalt ══════════════════════════════════════
    echo "\n── Hull 1a: fryst hele maaneden, aldri betalt → «Fryst», ikke skyldig ──\n";
    [$a1, $a1S] = nyttMedlem($plan, 'Hull1 Fryst hele');
    $frys($a1, $denne, $sisteINeste);
    $b = Medlemskap::betalingsstatusFor($medlem($a1));
    sjekk('status «fryst», ikke utestaaende', $b['tilstand'] === 'fryst' && $b['utestaaende'] === false, json_encode($b, JSON_UNESCAPED_UNICODE));
    sjekk('skyldigMaaned er null', Medlemskap::skyldigMaaned($medlem($a1)) === null);
    $svar = kall([[$porter[0], $API, $kontant($a1), $token]]);
    sjekk('Kassa nekter (409, skylder ingenting) — samme svar som statusen', $svar[0][0] === 409
        && str_contains($feiltekst($svar[0]), 'skylder ingenting'), $svar[0][0] . ' ' . $feiltekst($svar[0]));

    echo "\n── Hull 1b: fryst én dag (under 15), aldri betalt → skyldig og kan betales ──\n";
    [$a2, $a2S] = nyttMedlem($plan, 'Hull1 Fryst kort Kassa');
    $frys($a2, $idag, $idag);
    $b = Medlemskap::betalingsstatusFor($medlem($a2));
    sjekk('utestaaende', $b['utestaaende'] === true, json_encode($b, JSON_UNESCAPED_UNICODE));
    sjekk("skyldigMaaned = $denne (foer: null)", Medlemskap::skyldigMaaned($medlem($a2)) === $denne,
        (string) Medlemskap::skyldigMaaned($medlem($a2)));
    $svar = kall([[$porter[0], $API, $kontant($a2), $token]]);
    $r = rader($a2);
    sjekk("Kassa tar betalt: 200, én rad 50000 øre, gjelder_fra $denne", $svar[0][0] === 200 && count($r) === 1
        && (int) $r[0]['belop_ore'] === 50000 && $r[0]['gjelder_fra'] === $denne, $svar[0][0] . ' ' . json_encode($r));
    // Min side (gjoer opp selv): «Forny» betaler maaneden som skyldes.
    [$a3, $a3S] = nyttMedlem($plan, 'Hull1 Fryst kort Min side');
    $frys($a3, $idag, $idag);
    $ut = null; $f = '';
    try { $ut = Medlemskap::fornyPeriode($medlem($a3), $avtale($a3S)); } catch (Throwable $e) { $f = $e->getMessage(); }
    $r = rader($a3);
    $planPris = (int) DB::verdi('SELECT pris_ore FROM membership_plans WHERE navn = :n', ['n' => $plan]);
    sjekk("Min side «Forny» godtas, raden gjelder $denne (planprisen, $planPris øre)", $ut !== null && count($r) === 1
        && $r[0]['gjelder_fra'] === $denne && (int) $r[0]['belop_ore'] === $planPris, $f . ' ' . json_encode($r));

    echo "\n── Hull 1c: fast trekk, fryst hele maaneden, aldri trukket → «Fryst» ──\n";
    [$a4, $a4S] = nyttMedlem($plan, 'Hull1 Fast trekk', 'agr-' . strtolower($tag) . '-1c');
    $frys($a4, $denne, $sisteINeste);
    $b = Medlemskap::betalingsstatusFor($medlem($a4));
    sjekk('status «fryst», ikke utestaaende (Kassa ville nektet)', $b['tilstand'] === 'fryst' && $b['utestaaende'] === false,
        json_encode($b, JSON_UNESCAPED_UNICODE));

    // ══ Hull 2: Kassa og egenbetaling samme maaned ═══════════════════════
    echo "\n── Hull 2a: «Forny» er paa vei i Vipps → Kassa nekter samme maaned ──\n";
    [$c1, $c1S] = nyttMedlem($plan, 'Hull2 Paa vei');
    $ut = Medlemskap::fornyPeriode($medlem($c1), $avtale($c1S));
    $r = rader($c1);
    sjekk("«Forny» lager én ventende betaling for $denne", count($r) === 1 && $r[0]['status'] === 'venter'
        && substr((string) $r[0]['gjelder_fra'], 0, 7) === substr($denne, 0, 7), json_encode($r));
    $svar = kall([[$porter[0], $API, $kontant($c1), $token], [$porter[1], $API, $kontant($c1), $token]]);
    sjekk('Kassa nekter begge (409 «Betalingen pågår i Vipps»)', $svar[0][0] === 409 && $svar[1][0] === 409
        && str_contains($feiltekst($svar[0]), 'Betalingen pågår i Vipps'), json_encode(array_column($svar, 0)) . ' ' . $feiltekst($svar[0]));
    sjekk('… ingen manuell rad', count(rader($c1)) === 1);
    // Utloept i Vipps (eldre enn en halvtime): da kan verkstedet ta betalt.
    DB::oppdater('payments', ['created_at' => gmdate('Y-m-d H:i:s', time() - 31 * 60)], ['id' => (int) $r[0]['id']]);
    $svar = kall([[$porter[0], $API, $kontant($c1), $token]]);
    sjekk('etter en halvtime godtar Kassa (200)', $svar[0][0] === 200, $svar[0][0] . ' ' . $feiltekst($svar[0]));

    echo "\n── Hull 2b: Kassa har betalt maaneden → «Forny» gjelder neste ──\n";
    [$c2, $c2S] = nyttMedlem($plan, 'Hull2 Kassa foerst');
    $svar = kall([[$porter[0], $API, $kontant($c2), $token]]);
    Medlemskap::fornyPeriode($medlem($c2), $avtale($c2S));
    $r = rader($c2);
    sjekk("Kassa $denne, «Forny» $neste — aldri to for samme maaned", $svar[0][0] === 200 && count($r) === 2
        && $r[0]['gjelder_fra'] === $denne && $r[1]['gjelder_fra'] === $neste, json_encode($r));

    echo "\n── Hull 2c: «Forny» venter paa medlemslaasen (samme som Kassa) ──\n";
    [$c3, $c3S] = nyttMedlem($plan, 'Hull2 Laas');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $oppsett['db_vert'], $oppsett['db_port'] ?? 3306, $oppsett['db_navn']);
    $holder = new PDO($dsn, $oppsett['db_bruker'], $oppsett['db_passord'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $holder->beginTransaction();
    $holder->query('SELECT id FROM members WHERE id = ' . $c3 . ' FOR UPDATE')->fetchAll();
    $nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--forny', (string) $c3, (string) $c3S],
        [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $logg, 'a']], $pp, $rot);
    fclose($pp[0]);
    usleep(1_500_000);
    sjekk('mens medlemmet er laast, er ingen betaling lagret', count(rader($c3)) === 0, json_encode(rader($c3)));
    // Kassa registrerer maaneden mens «Forny» venter (holder = Kassa).
    $holder->exec("INSERT INTO payments (vipps_reference, type, formal, member_id, subscription_id, belop_ore, status, gjelder_fra, idempotency_key)
                   VALUES ('{$tag}-KASSA', 'manuell', 'medlemskap', {$c3}, {$c3S}, 50000, 'betalt', '{$denne}', '" . Vipps::uuid() . "')");
    $holder->commit();
    $slutt = time() + 30;
    while (proc_get_status($proc)['running'] && time() < $slutt) { usleep(100_000); }
    proc_close($proc);
    $r = rader($c3);
    sjekk("etter laasen: Kassa $denne, «Forny» $neste (ikke samme maaned)", count($r) === 2
        && $r[0]['gjelder_fra'] === $denne && $r[1]['gjelder_fra'] === $neste, json_encode($r));

    echo "\n── Hull 2d: nytt medlemskap (startEngangs) venter paa den samme laasen ──\n";
    [$c4, $c4S] = nyttMedlem($plan, 'Hull2 Nytt');
    DB::kjor('DELETE FROM subscriptions WHERE id = :s', ['s' => $c4S]);
    DB::oppdater('members', ['status' => 'ingen'], ['id' => $c4]);
    $holder = new PDO($dsn, $oppsett['db_bruker'], $oppsett['db_passord'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $holder->beginTransaction();
    $holder->query('SELECT id FROM members WHERE id = ' . $c4 . ' FOR UPDATE')->fetchAll();
    $proc = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--forny', (string) $c4, '0', $plan],
        [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $logg, 'a']], $pp, $rot);
    fclose($pp[0]);
    usleep(1_500_000);
    $avtaler = static fn(int $m): int => (int) DB::verdi('SELECT COUNT(*) FROM subscriptions WHERE member_id = :m', ['m' => $m]);
    sjekk('mens medlemmet er laast, er verken avtale eller betaling lagret', $avtaler($c4) === 0 && count(rader($c4)) === 0,
        $avtaler($c4) . ' / ' . json_encode(rader($c4)));
    $holder->commit();
    $slutt = time() + 30;
    while (proc_get_status($proc)['running'] && time() < $slutt) { usleep(100_000); }
    proc_close($proc);
    sjekk('etter laasen: én avtale og én ventende betaling', $avtaler($c4) === 1 && count(rader($c4)) === 1
        && rader($c4)[0]['status'] === 'venter', json_encode(rader($c4)));
    $svar = kall([[$porter[0], $API, $kontant($c4), $token]]);
    sjekk('Kassa nekter mens den er paa vei (409)', $svar[0][0] === 409, $svar[0][0] . ' ' . $feiltekst($svar[0]));

    // ══ Hull 3: februar forsvinner ikke naar mars er betalt ══════════════
    // Faste datoer i 2027 (etter utrullingsvernet HULL_FRA = 2026-10), med
    // «i dag» satt: 15. mars 2027.
    $J = '2027-01-01'; $F = '2027-02-01'; $M = '2027-03-01'; $A = '2027-04-01'; $D = '2027-03-15';
    echo "\n── Hull 3a: februar ubetalt, januar og mars betalt (i dag $D) ──\n";
    [$d1, $d1S] = nyttMedlem($plan, 'Hull3 Hull');
    betalt($d1, $d1S, $J);
    betalt($d1, $d1S, $M);
    $b = Medlemskap::betalingsstatusFor($medlem($d1), $D);
    sjekk('status forfalt og utestaaende (foer: «betalt»)', $b['tilstand'] === 'forfalt' && $b['utestaaende'] === true,
        json_encode($b, JSON_UNESCAPED_UNICODE));
    sjekk("skyldigMaaned = $F", Medlemskap::skyldigMaaned($medlem($d1), $D) === $F, (string) Medlemskap::skyldigMaaned($medlem($d1), $D));
    Medlemskap::fornyPeriodePaa($medlem($d1), $avtale($d1S), $D);
    $r = rader($d1);
    sjekk("«Forny» betaler den eldste ($F) først", end($r)['gjelder_fra'] === $F, json_encode(end($r)));
    DB::oppdater('payments', ['status' => 'betalt'], ['id' => (int) end($r)['id']]);
    $b = Medlemskap::betalingsstatusFor($medlem($d1), $D);
    sjekk('… deretter betalt, ingenting skyldig', $b['tilstand'] === 'betalt' && Medlemskap::skyldigMaaned($medlem($d1), $D) === null,
        json_encode($b, JSON_UNESCAPED_UNICODE));
    $siste = Medlemskap::sisteBetalinger([$d1])[$d1];
    sjekk("«siste» er perioden $M (ikke den sist registrerte), betalt til $A", Medlemskap::dekkerTil($siste) === $A,
        Medlemskap::dekkerTil($siste));
    DB::oppdater('payments', ['created_at' => gmdate('Y-m-d H:i:s', time() - 31 * 60)], ['member_id' => $d1, 'status' => 'venter']);
    Medlemskap::fornyPeriodePaa($medlem($d1), $avtale($d1S), $D);
    $r = rader($d1);
    sjekk("neste «Forny» gjelder $A (ikke $M igjen)", end($r)['gjelder_fra'] === $A, json_encode(end($r)));

    echo "\n── Hull 3b: fast trekk — feilet trekk i februar skjules ikke av mars ──\n";
    [$d2, $d2S] = nyttMedlem($plan, 'Hull3 Trekk', 'agr-' . strtolower($tag) . '-3b');
    betalt($d2, $d2S, $J, 'recurring_charge', 'betalt', 'psp-1');
    betalt($d2, $d2S, $F, 'recurring_charge', 'feilet', 'psp-2');
    betalt($d2, $d2S, $M, 'recurring_charge', 'betalt', 'psp-3');
    $b = Medlemskap::betalingsstatusFor($medlem($d2), $D);
    sjekk('status forfalt (foer: «Trukket … betalt»)', $b['tilstand'] === 'forfalt' && $b['utestaaende'] === true,
        json_encode($b, JSON_UNESCAPED_UNICODE));
    sjekk("skyldigMaaned = $F", Medlemskap::skyldigMaaned($medlem($d2), $D) === $F, (string) Medlemskap::skyldigMaaned($medlem($d2), $D));

    echo "\n── Hull 3c: februar fritatt av frys → ikke skyldig ──\n";
    [$d3, $d3S] = nyttMedlem($plan, 'Hull3 Fritatt');
    betalt($d3, $d3S, $J);
    betalt($d3, $d3S, $M);
    $frys($d3, $F, '2027-02-28');
    $b = Medlemskap::betalingsstatusFor($medlem($d3), $D);
    sjekk('status betalt, skyldigMaaned null', $b['tilstand'] === 'betalt' && Medlemskap::skyldigMaaned($medlem($d3), $D) === null,
        json_encode($b, JSON_UNESCAPED_UNICODE));

    echo "\n── Hull 3d: et ventende/stoppet forsøk flytter ikke gjeldsstarten ──\n";
    [$d4, $d4S] = nyttMedlem($plan, 'Hull3 Forsok');
    betalt($d4, $d4S, $J);
    betalt($d4, $d4S, $M);
    DB::settInn('subscriptions', ['member_id' => $d4, 'plan' => $plan, 'pris_ore' => 50000, 'status' => 'stoppet',
        'created_at' => '2027-03-10 12:00:00']);
    sjekk("skyldigMaaned = $F også med et nyere, stoppet forsøk", Medlemskap::skyldigMaaned($medlem($d4), $D) === $F,
        (string) Medlemskap::skyldigMaaned($medlem($d4), $D));
    sjekk('… og status forfalt', Medlemskap::betalingsstatusFor($medlem($d4), $D)['tilstand'] === 'forfalt');

    echo "\n── Utrullingsvern: hull før oktober 2026 telles ikke ──\n";
    [$u1, $u1S] = nyttMedlem($plan, 'Hull Utrulling');
    // August 2026 uten gjelder_fra (som før migrasjonen), september ubetalt, oktober betalt.
    DB::settInn('payments', ['vipps_reference' => $tag . '-AUG', 'type' => 'manuell', 'formal' => 'medlemskap', 'member_id' => $u1,
        'subscription_id' => $u1S, 'belop_ore' => 50000, 'status' => 'betalt', 'idempotency_key' => Vipps::uuid(),
        'created_at' => '2026-08-10 10:00:00']);
    betalt($u1, $u1S, '2026-10-01');
    $b = Medlemskap::betalingsstatusFor($medlem($u1), '2026-10-20');
    sjekk('september 2026 gir ikke «Forfalt»: status betalt, ingenting skyldig', $b['tilstand'] === 'betalt'
        && Medlemskap::skyldigMaaned($medlem($u1), '2026-10-20') === null, json_encode($b, JSON_UNESCAPED_UNICODE));
    sjekk('… men november ubetalt etter oktober skyldes som før (i dag 5.11.2026)',
        Medlemskap::skyldigMaaned($medlem($u1), '2026-11-05') === '2026-11-01',
        (string) Medlemskap::skyldigMaaned($medlem($u1), '2026-11-05'));

    echo "\n── Kontrollør 1: avbrutt Vipps, så Kassa — står ikke fast på måneden ──\n";
    [$k1, $k1S] = nyttMedlem($plan, 'Hull Avbrutt');
    DB::oppdater('subscriptions', ['status' => 'stoppet'], ['id' => $k1S]);
    $ny = DB::settInn('subscriptions', ['member_id' => $k1, 'plan' => $plan, 'pris_ore' => 50000, 'status' => 'venter']);
    DB::settInn('payments', ['vipps_reference' => $tag . '-AVBR', 'type' => 'epayment', 'formal' => 'medlemskap', 'member_id' => $k1,
        'subscription_id' => $ny, 'belop_ore' => 50000, 'status' => 'avbrutt', 'gjelder_fra' => $denne, 'idempotency_key' => Vipps::uuid()]);
    sjekk("før Kassa: skyldigMaaned = $denne", Medlemskap::skyldigMaaned($medlem($k1)) === $denne, (string) Medlemskap::skyldigMaaned($medlem($k1)));
    $svar = kall([[$porter[0], $API, $kontant($k1), $token]]);
    sjekk("Kassa registrerer $denne (200)", $svar[0][0] === 200, $svar[0][0] . ' ' . $feiltekst($svar[0]));
    $nesteMidt = (new DateTimeImmutable($neste))->modify('+14 days')->format('Y-m-d');
    sjekk("i dag: ingenting skyldig; neste måned ($nesteMidt): $neste, ikke $denne",
        Medlemskap::skyldigMaaned($medlem($k1)) === null && Medlemskap::skyldigMaaned($medlem($k1), $nesteMidt) === $neste,
        var_export(Medlemskap::skyldigMaaned($medlem($k1)), true) . ' / ' . var_export(Medlemskap::skyldigMaaned($medlem($k1), $nesteMidt), true));

    // ══ Hull 4: pamelding.php ════════════════════════════════════════════
    echo "\n── Hull 4: «endre» og «fjern» ──\n";
    $k = DB::settInn('courses', ['slug' => strtolower($tag . '-kurs'), 'tittel' => 'Hull kurs', 'type' => 'kurs',
        'pris_ore' => 50000, 'kapasitet' => 10, 'status' => 'publisert']);
    $kurs[] = $k;
    $o = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => gmdate('Y-m-d H:i:s', time() + 10 * 86400), 'kapasitet' => 10]);
    $okter[] = $o;
    $plass = static fn(array $f): int => DB::settInn('bookings', $f + ['course_id' => $k, 'course_session_id' => $o,
        'gjest_navn' => 'Hull ' . bin2hex(random_bytes(2)), 'antall' => 1, 'status' => 'betalt', 'belop_ore' => 50000]);
    $vipps = static fn(int $b, int $ore, string $status = 'betalt'): int => DB::settInn('payments', ['vipps_reference' => Vipps::nyReferanse('T'),
        'type' => 'epayment', 'formal' => 'booking', 'status' => $status, 'belop_ore' => $ore, 'idempotency_key' => Vipps::uuid(), 'booking_id' => $b]);
    $PAM = '/api/admin/pamelding.php';
    $bok = static fn(int $b): array => DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => $b]);

    // a) Betalt kontant for haand (uten rad), 1 → 2 plasser.
    $b1 = $plass(['betalt_maate' => 'Kontant']);
    $svar = kall([[$porter[0], $PAM, ['handling' => 'endre', 'id' => $b1, 'antall' => 2], $token]]);
    $rad = $bok($b1);
    $bet = Booking::betalingerFor($b1);
    sjekk('a) 1 → 2 plasser: beloep 100000, status reservert (foer: «betalt»)', $svar[0][0] === 200
        && (int) $rad['belop_ore'] === 100000 && $rad['status'] === 'reservert', $svar[0][0] . ' ' . json_encode($rad));
    sjekk('a) … det betalte er foert (én kontant-rad 50000), restbeloep 50000 i «Ikke betalt»',
        count($bet['rader']) === 1 && $bet['sum'] === 50000 && ($svar[0][1]['skyldigOre'] ?? null) === 50000
        && str_contains((string) ($svar[0][1]['beskjed'] ?? ''), 'Skyldig: '), json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));

    // a2) Kontant for haand, men med en mislykket Vipps-rad fra foer (kontrolloeren 2).
    $b1b = $plass(['betalt_maate' => 'Kontant']);
    $vipps($b1b, 50000, 'feilet');
    $svar = kall([[$porter[0], $PAM, ['handling' => 'endre', 'id' => $b1b, 'antall' => 2], $token]]);
    $bet = Booking::betalingerFor($b1b);
    sjekk('a2) mislykket Vipps-rad + kontant: kontanten føres (sum 50000), skyldig 50000 — ikke 100000',
        $svar[0][0] === 200 && $bet['sum'] === 50000 && ($svar[0][1]['skyldigOre'] ?? null) === 50000,
        $svar[0][0] . ' ' . json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));
    // Samme i flyttingen: til en dato med pris 70000.
    $k2 = DB::settInn('courses', ['slug' => strtolower($tag . '-kurs2'), 'tittel' => 'Hull kurs 2', 'type' => 'kurs',
        'pris_ore' => 70000, 'kapasitet' => 10, 'status' => 'publisert']);
    $kurs[] = $k2;
    $o2 = DB::settInn('course_sessions', ['course_id' => $k2, 'start_tid' => gmdate('Y-m-d H:i:s', time() + 11 * 86400), 'kapasitet' => 10]);
    $okter[] = $o2;
    $b1c = $plass(['betalt_maate' => 'Kontant']);
    $vipps($b1c, 50000, 'feilet');
    $svar = kall([[$porter[0], $PAM, ['handling' => 'flytt', 'id' => $b1c, 'oktId' => $o2], $token]]);
    sjekk('a3) flytting med mislykket Vipps-rad: kontanten føres, skyldig 20000 — ikke 70000',
        $svar[0][0] === 200 && Booking::betalingerFor($b1c)['sum'] === 50000 && ($svar[0][1]['skyldigOre'] ?? null) === 20000,
        $svar[0][0] . ' ' . json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));

    // b) Vipps-betalt 2 plasser, ned til 1: 50000 for mye.
    $b2 = $plass(['antall' => 2, 'belop_ore' => 100000]);
    $p2 = $vipps($b2, 100000);
    DB::oppdater('bookings', ['payment_id' => $p2], ['id' => $b2]);
    $svar = kall([[$porter[0], $PAM, ['handling' => 'endre', 'id' => $b2, 'antall' => 1], $token]]);
    sjekk('b) Vipps 2 → 1 plass: betalt, 50000 for mye sagt fra om', $svar[0][0] === 200 && $bok($b2)['status'] === 'betalt'
        && ($svar[0][1]['forMyeOre'] ?? null) === 50000, json_encode($svar[0][1], JSON_UNESCAPED_UNICODE));

    // c) Gratis er gratis: status urort.
    $b3 = $plass(['betalt_maate' => 'Gratis', 'belop_ore' => 0]);
    $svar = kall([[$porter[0], $PAM, ['handling' => 'endre', 'id' => $b3, 'antall' => 2], $token]]);
    sjekk('c) gratis plass: fortsatt betalt, ingen betalingsrad', $svar[0][0] === 200 && $bok($b3)['status'] === 'betalt'
        && Booking::betalingerFor($b3)['rader'] === []);

    // d) Betaling paa vei i Vipps: endring av beloepet nektes.
    $b4 = $plass(['status' => 'reservert']);
    $vipps($b4, 50000, 'venter');
    $svar = kall([[$porter[0], $PAM, ['handling' => 'endre', 'id' => $b4, 'antall' => 2], $token]]);
    sjekk('d) Vipps-betaling paa vei: 409, antallet urort', $svar[0][0] === 409 && (int) $bok($b4)['antall'] === 1,
        $svar[0][0] . ' ' . $feiltekst($svar[0]));

    // e) «fjern»: Vipps-betalt, men bookings.payment_id er tom (bare payments.booking_id).
    $b5 = $plass([]);
    $vipps($b5, 50000);
    $svar = kall([[$porter[0], $PAM, ['handling' => 'fjern', 'id' => $b5], $token]]);
    sjekk('e) fjern nektes («betalt gjennom Vipps»), plassen staar', $svar[0][0] !== 200
        && str_contains($feiltekst($svar[0]), 'betalt gjennom Vipps') && $bok($b5)['status'] === 'betalt',
        $svar[0][0] . ' ' . $feiltekst($svar[0]));
    // «til-venteliste» har den samme sjekken.
    $b7 = $plass(['gjest_epost' => strtolower($tag) . '-vente@lissom.test']);
    $vipps($b7, 50000);
    $svar = kall([[$porter[0], $PAM, ['handling' => 'til-venteliste', 'id' => $b7], $token]]);
    sjekk('e) til-venteliste nektes («betalt gjennom Vipps»), plassen staar, ingen paa ventelista', $svar[0][0] !== 200
        && str_contains($feiltekst($svar[0]), 'betalt gjennom Vipps') && $bok($b7)['status'] === 'betalt'
        && (int) DB::verdi('SELECT COUNT(*) FROM waitlist WHERE course_session_id = :o', ['o' => $o]) === 0,
        $svar[0][0] . ' ' . $feiltekst($svar[0]));
    // Ubetalt plass kan fortsatt fjernes.
    $b6 = $plass(['status' => 'reservert', 'betalt_maate' => 'Ikke betalt']);
    $svar = kall([[$porter[0], $PAM, ['handling' => 'fjern', 'id' => $b6], $token]]);
    sjekk('e) … en ubetalt plass fjernes som foer', $svar[0][0] === 200 && $bok($b6)['status'] === 'avbestilt', (string) $svar[0][0]);
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($servere as $p) { proc_terminate($p); proc_close($p); }
    foreach ($okter as $o) {
        foreach (array_column(DB::alle('SELECT id FROM bookings WHERE course_session_id = :o', ['o' => $o]), 'id') as $b) {
            DB::kjor('UPDATE bookings SET payment_id = NULL WHERE id = :b', ['b' => $b]);
            DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'payment' AND objekt_id IN (SELECT id FROM payments WHERE booking_id = :b)", ['b' => $b]);
            DB::kjor('DELETE FROM payments WHERE booking_id = :b', ['b' => $b]);
            DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'booking' AND objekt_id = :b", ['b' => $b]);
            DB::kjor("DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id = :b", ['b' => $b]);
        }
        DB::kjor('DELETE FROM bookings WHERE course_session_id = :o', ['o' => $o]);
        DB::kjor('DELETE FROM waitlist WHERE course_session_id = :o', ['o' => $o]);
        DB::kjor('DELETE FROM course_sessions WHERE id = :o', ['o' => $o]);
    }
    foreach ($kurs as $k) { DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $k]); }
    foreach ($medlemmer as $m) {
        $avt = array_column(DB::alle('SELECT id FROM subscriptions WHERE member_id = :m', ['m' => $m]), 'id');
        foreach ($avt as $sid) {
            DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'subscription' AND objekt_id = :s", ['s' => (int) $sid]);
        }
        DB::kjor('DELETE FROM payments WHERE member_id = :m', ['m' => $m]);
        DB::kjor('DELETE FROM subscriptions WHERE member_id = :m', ['m' => $m]);
        DB::kjor('DELETE FROM medlem_frys WHERE member_id = :m', ['m' => $m]);
        DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'member' AND objekt_id = :m", ['m' => $m]);
        DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $m]);
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
