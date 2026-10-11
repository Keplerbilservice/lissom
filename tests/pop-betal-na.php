<?php
/**
 * Paint on Pots: «Betal nå med Vipps» eller «Betal ved oppmøte» (eieren, GO
 * 11. oktober 2026, skissen GvVvGvKzJex3L2qkNcoLzc). Ingen 100 kr ved booking.
 *
 * Ett scenario per pengeflyt, med ekte api/book.php, api/pop-avbestill.php og
 * api/avbestill.php over HTTP mot den isolerte testbasen. Vipps er en lokal
 * stubb (LISSOM_VIPPS_BASE) som skriver ned hvert kall og beløpet i det:
 *
 *   1. betal nå: 1 Liten + 1 Mellom = 1 200 kr til Vipps, gjenstandene lagret
 *   2. ved oppmøte: 0 kr, ingen betaling, plassen reservert (ikke «betalt»)
 *   3. avbestilling i tide: hele 1 200 kr tilbake (ikke 100 kr)
 *   4. for sen avbestilling: beløpet beholdes
 *   5. kassa: gjenstandene ferdig utfylt, 1 200 kr trekkes fra, kan rettes
 *   6. gammel booking med 100 kr: trekkes fra i kassa som før
 *
 *   php tests/pop-betal-na.php
 *
 * Sender ingenting: ingen e-post, SMS eller ekte betaling.
 */

declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void
{
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

if (!PopPris::klar() || !DB::harKolonne('bookings', 'gjenstander_ore') || !DB::harKolonne('courses', 'depositum')) {
    echo "  FEIL  migrasjon 260 er ikke kjoert\n";
    exit(1);
}

$mappe = sys_get_temp_dir() . '/lissom-popbetal-' . bin2hex(random_bytes(6));
mkdir($mappe);
$logg = $mappe . '/vipps.log';
touch($logg);
// Falsk Vipps: skriver ned metode, sti, kropp og idempotensnøkkel.
file_put_contents($mappe . '/vipps.php', <<<'PHP'
<?php
$sti = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$k = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
file_put_contents(getenv('POP_STUB_LOGG'), json_encode([
    'm' => $_SERVER['REQUEST_METHOD'] ?? '', 'sti' => $sti, 'kropp' => $k,
    'idem' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '',
]) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($sti === '/accesstoken/get') { echo json_encode(['access_token' => 'stub', 'expires_in' => 3600]); return; }
if ($sti === '/epayment/v1/payments') {
    http_response_code(201);
    echo json_encode(['reference' => $k['reference'] ?? 'x', 'redirectUrl' => 'https://vipps.example/betal/' . ($k['reference'] ?? 'x')]);
    return;
}
if (preg_match('#/(capture|refund|cancel)$#', $sti)) { echo json_encode(['ok' => true]); return; }
http_response_code(404);
echo '{}';
PHP);

$servere = [];
$ledigPort = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $a = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($a, strrpos($a, ':') + 1);
};
$startServer = static function (array $kommando, array $env, int $port) use (&$servere, $mappe, $rot): bool {
    $p = proc_open($kommando, [0 => ['pipe', 'r'], 1 => ['file', $mappe . '/server.log', 'a'], 2 => ['file', $mappe . '/server.log', 'a']],
        $pipes, $rot, $env);
    if (!is_resource($p)) {
        return false;
    }
    fclose($pipes[0]);
    $servere[] = $p;
    for ($i = 0; $i < 60; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $en, $to, 0.1);
        if ($s) { fclose($s); return true; }
        usleep(50000);
    }
    return false;
};
$ini = php_ini_loaded_file();
$pVipps = $ledigPort();
$pWeb = $ledigPort();
$env = getenv();
$env['POP_STUB_LOGG'] = $logg;
$env['LISSOM_VIPPS_BASE'] = 'http://127.0.0.1:' . $pVipps;
$medIni = static fn(array $a): array => $ini ? array_merge([PHP_BINARY, '-c', $ini], $a) : array_merge([PHP_BINARY], $a);

$kall = static function (string $metode, string $sti, ?array $data = null) use (&$pWeb): array {
    $c = curl_init('http://127.0.0.1:' . $pWeb . $sti);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30];
    if ($metode === 'POST') {
        $opt += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data ?? []),
                 CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json']];
    }
    curl_setopt_array($c, $opt);
    $kropp = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$status, json_decode($kropp, true), $kropp];
};
$vippsKall = static function () use ($logg): array {
    return array_values(array_filter(array_map(static fn(string $l) => json_decode($l, true),
        file($logg, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])));
};

$kurs = 0;
$okter = [];
$bookinger = [];
try {
    sjekk('falsk Vipps klar', $startServer($medIni(['-S', '127.0.0.1:' . $pVipps, $mappe . '/vipps.php']), $env, $pVipps));
    sjekk('webserver klar', $startServer($medIni(['-S', '127.0.0.1:' . $pWeb, '-t', $rot]), $env, $pWeb));

    $kurs = DB::settInn('courses', [
        'slug' => 'testpopbetal-' . bin2hex(random_bytes(3)), 'tittel' => 'Test PoP betal', 'type' => 'event', 'pris_ore' => 10000,
        'kapasitet' => 20, 'status' => 'publisert', 'folger_apningstid' => 1, 'depositum' => 1, 'avbestilling_timer' => 24,
    ]);
    $lagOkt = static function (int $sekunder) use ($kurs, &$okter): int {
        $id = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => gmdate('Y-m-d H:i:s', time() + $sekunder),
            'slutt_tid' => gmdate('Y-m-d H:i:s', time() + $sekunder + 7200), 'status' => 'planlagt']);
        $okter[] = $id;
        return $id;
    };
    $okt = $lagOkt(5 * 86400);        // om fem dager: i tide
    $oktSen = $lagOkt(10 * 3600);     // om ti timer: for sent (frist 24 t)

    $nivaa = array_column(PopPris::nivaer(), null, 'navn');
    $liten = $nivaa['Liten']; $mellom = $nivaa['Mellom']; $stor = $nivaa['Stor'];
    $valg1200 = [(string) $liten['id'] => 1, (string) $mellom['id'] => 1];
    sjekk('Liten 500 + Mellom 700 = 1 200 kr', $liten['prisOre'] + $mellom['prisOre'] === 120000);

    $bestill = static function (int $oktId, array $ekstra) use ($kall, &$bookinger): array {
        $epost = 'popbetal-' . bin2hex(random_bytes(4)) . '@example.com';
        $r = $kall('POST', '/api/book.php', $ekstra + [
            'oktId' => $oktId, 'antall' => 2, 'navn' => 'PoP Betal Test', 'epost' => $epost, 'telefon' => '+4791234567',
        ]);
        $b = DB::en('SELECT * FROM bookings WHERE gjest_epost = :e', ['e' => $epost]);
        if ($b !== null) {
            $bookinger[] = (int) $b['id'];
        }
        return [$r, $b];
    };
    $betal = static function (array $b): string {
        $ref = (string) DB::verdi('SELECT vipps_reference FROM payments WHERE id = :p', ['p' => (int) $b['payment_id']]);
        Booking::markerBetalt($ref);   // som returen/webhooken etter GET-sjekk mot Vipps
        return $ref;
    };

    echo "\n── 1. Betal nå: 1 Liten + 1 Mellom ──────────────────────────\n";
    $foer = count($vippsKall());
    [$r, $b1] = $bestill($okt, ['gjenstander' => $valg1200]);
    sjekk('book.php svarer med Vipps-adresse', $r[0] === 200 && ($r[1]['betaling'] ?? false) === true
        && str_starts_with((string) ($r[1]['url'] ?? ''), 'https://vipps.example/betal/'), $r[2]);
    sjekk('bookingen: beløp 1 200 kr, betalt ved booking 1 200 kr',
        $b1 !== null && (int) $b1['belop_ore'] === 120000 && (int) $b1['depositum_ore'] === 120000, json_encode($b1));
    sjekk('… ingen rabatt, frist 24 t lagret, ikke slått inn i kassa',
        $b1 !== null && (float) $b1['rabatt_prosent'] == 0 && (int) $b1['avbestilling_timer'] === 24 && $b1['gjenstander_ore'] === null);
    $p1 = DB::en('SELECT * FROM payments WHERE id = :p', ['p' => (int) ($b1['payment_id'] ?? 0)]);
    sjekk('betalingsraden er 1 200 kr og venter i Vipps', $p1 !== null && (int) $p1['belop_ore'] === 120000 && $p1['status'] === 'venter');
    $opprett = array_values(array_filter(array_slice($vippsKall(), $foer),
        static fn($k) => $k['m'] === 'POST' && $k['sti'] === '/epayment/v1/payments'));
    sjekk('Vipps ble bedt om nøyaktig 120000 øre, én gang',
        count($opprett) === 1 && (int) ($opprett[0]['kropp']['amount']['value'] ?? 0) === 120000, json_encode($opprett));
    sjekk('… med idempotensnøkkel', count($opprett) === 1 && $opprett[0]['idem'] !== '');
    $linjer = DB::alle('SELECT nivaa_id, navn, pris_ore, antall, gjenstand FROM pop_kasselinjer WHERE booking_id = :b ORDER BY id', ['b' => (int) $b1['id']]);
    sjekk('gjenstandene er lagret på bookingen (1 Liten, 1 Mellom)', count($linjer) === 2
        && (int) $linjer[0]['nivaa_id'] === $liten['id'] && (int) $linjer[0]['pris_ore'] === 50000 && (int) $linjer[0]['antall'] === 1
        && (int) $linjer[1]['nivaa_id'] === $mellom['id'] && (int) $linjer[1]['pris_ore'] === 70000 && $linjer[1]['gjenstand'] === null,
        json_encode($linjer));
    $betal($b1);
    $b1 = DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $b1['id']]);
    sjekk('betalt i Vipps: bookingen står som betalt', $b1['status'] === 'betalt');
    $tekst = PopPris::bekreftelse($b1);
    sjekk('bekreftelsen: «Betalt: 1 200 kr. Maler dere noe annet, justeres det i verkstedet.»',
        str_starts_with($tekst, 'Betalt: 1 200 kr. Maler dere noe annet, justeres det i verkstedet. Avbestiller du senest 24 timer før')
        && !str_contains($tekst, 'trekkes fra'), $tekst);

    echo "\n── Serveren bestemmer prisen ────────────────────────────────\n";
    $foer = count($vippsKall());
    [$r] = $bestill($okt, []);
    sjekk('betal nå uten gjenstander avvises', $r[0] === 409 && ($r[1]['feil'] ?? '') === 'Velg minst én gjenstand.', $r[2]);
    [$r] = $bestill($okt, ['gjenstander' => ['999999' => 1]]);
    sjekk('ukjent nivå avvises', $r[0] === 409, $r[2]);
    [$r] = $bestill($okt, ['gjenstander' => [(string) $liten['id'] => 51]]);
    sjekk('mer enn 50 av ett nivå avvises', $r[0] === 409, $r[2]);
    [$r] = $bestill($okt, ['gjenstander' => [['nivaaId' => $liten['id'], 'antall' => 30], ['nivaaId' => $liten['id'], 'antall' => 30]]]);
    sjekk('… også når samme nivå kommer i to rader (30 + 30)', $r[0] === 409, $r[2]);
    [$r] = $bestill($okt, ['gjenstander' => [(string) $liten['id'] => 1], 'prisOre' => 100, 'belop' => 1]);
    $b = DB::en('SELECT belop_ore FROM bookings WHERE id = :i', ['i' => end($bookinger)]);
    sjekk('beløp fra nettleseren brukes ikke (Liten = 500 kr)', $r[0] === 200 && (int) ($b['belop_ore'] ?? 0) === 50000);
    sjekk('avviste bestillinger kalte ikke Vipps', count(array_filter(array_slice($vippsKall(), $foer),
        static fn($k) => $k['sti'] === '/epayment/v1/payments')) === 1);

    echo "\n── 2. Betal ved oppmøte: 0 kr ───────────────────────────────\n";
    $foer = count($vippsKall());
    [$r, $b2] = $bestill($okt, ['betaling' => 'oppmote', 'gjenstander' => $valg1200, 'gavekort' => 'FINNESIKKE']);
    sjekk('book.php: ingen tur til Vipps', $r[0] === 200 && ($r[1]['betaling'] ?? true) === false, $r[2]);
    sjekk('bookingen: 0 kr, reservert uten frist og med uten_forskudd (ikke «betalt»)',
        $b2 !== null && (int) $b2['belop_ore'] === 0 && (int) $b2['depositum_ore'] === 0 && $b2['status'] === 'reservert'
        && (int) $b2['uten_forskudd'] === 1 && $b2['payment_id'] === null && $b2['reservert_til'] === null, json_encode($b2));
    $st = Booking::settBetaltStatus((int) $b2['id']);
    sjekk('… og blir ikke «betalt» av 0 av 0 kr', $st['status'] === 'reservert'
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b2['id']]) === 'reservert', json_encode($st));
    sjekk('ingen betalingsrad og ingen kall til Vipps',
        (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE booking_id = :b', ['b' => (int) $b2['id']]) === 0
        && count($vippsKall()) === $foer);
    sjekk('ingen gjenstander lagret ved oppmøte',
        (int) DB::verdi('SELECT COUNT(*) FROM pop_kasselinjer WHERE booking_id = :b', ['b' => (int) $b2['id']]) === 0);
    sjekk('admin ser «Betaler ved besøket», ikke «Gratis»', Malebord::betalingsstatus($b2, 0) === 'Betaler ved besøket');
    $t2 = PopPris::bekreftelse($b2);
    sjekk('bekreftelsen: «Prisen på gjenstandene betaler du i verkstedet.» + lenke, ingen refusjonsløfte',
        str_starts_with($t2, 'Prisen på gjenstandene betaler du i verkstedet.') && str_contains($t2, 'pop-avbestill.php?b=' . $b2['id'])
        && !str_contains($t2, 'tilbake'), $t2);

    echo "\n── Oppmøte avbestilles når som helst ────────────────────────\n";
    [$r, $b2s] = $bestill($oktSen, ['betaling' => 'oppmote']);
    PopPris::bekreftelse($b2s);
    $kode2 = (string) DB::verdi('SELECT avbestill_kode FROM bookings WHERE id = :i', ['i' => (int) $b2s['id']]);
    [, , $side] = $kall('GET', '/api/pop-avbestill.php?b=' . $b2s['id'] . '&k=' . $kode2);
    sjekk('10 timer før, ingenting betalt: ingen tekst om at beløpet beholdes, knappen står',
        !str_contains($side, 'beholdes') && !str_contains($side, 'tilbake på Vipps') && str_contains($side, 'id="avbestill"'),
        substr(strip_tags($side), 0, 300));
    $foer = count($vippsKall());
    $r = $kall('POST', '/api/avbestill.php', ['bookingId' => (int) $b2s['id'], 'k' => $kode2]);
    sjekk('… avbestilt, «Plassen er avbestilt.», ingen Vipps-kall', $r[0] === 200 && ($r[1]['beskjed'] ?? '') === 'Plassen er avbestilt.'
        && count($vippsKall()) === $foer
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b2s['id']]) === 'avbestilt', $r[2]);

    echo "\n── 3. Avbestilling i tide: hele beløpet tilbake ─────────────\n";
    PopPris::bekreftelse($b1);
    $kode = (string) DB::verdi('SELECT avbestill_kode FROM bookings WHERE id = :i', ['i' => (int) $b1['id']]);
    [, , $side] = $kall('GET', '/api/pop-avbestill.php?b=' . $b1['id'] . '&k=' . $kode);
    sjekk('avbestillingssida lover 1 200 kr tilbake på Vipps', str_contains($side, 'Du får 1 200 kr tilbake på Vipps.'), substr(strip_tags($side), 0, 300));
    $foer = count($vippsKall());
    $r = $kall('POST', '/api/avbestill.php', ['bookingId' => (int) $b1['id'], 'k' => $kode]);
    sjekk('avbestilt med lenka', $r[0] === 200 && ($r[1]['ok'] ?? false) === true && ($r[1]['manuelt'] ?? true) === false, $r[2]);
    $ref = array_values(array_filter(array_slice($vippsKall(), $foer), static fn($k) => str_ends_with($k['sti'], '/refund')));
    sjekk('Vipps-refusjon på 120000 øre (ikke 10000)', count($ref) === 1 && (int) ($ref[0]['kropp']['modificationAmount']['value'] ?? 0) === 120000,
        json_encode($ref));
    sjekk('… med idempotensnøkkel', count($ref) === 1 && $ref[0]['idem'] !== '');
    sjekk('refusjonsjournalen: 1 200 kr', (int) DB::verdi('SELECT COALESCE(SUM(amount_ore), 0) FROM payment_refunds WHERE payment_id = :p',
        ['p' => (int) $b1['payment_id']]) === 120000);
    sjekk('betalingen er refundert i sin helhet', (int) DB::verdi('SELECT refundert_ore FROM payments WHERE id = :p',
        ['p' => (int) $b1['payment_id']]) === 120000);
    $foer = count($vippsKall());
    $r = $kall('POST', '/api/avbestill.php', ['bookingId' => (int) $b1['id'], 'k' => $kode]);
    sjekk('nytt trykk: avvist, ingen ny refusjon', $r[0] === 409 && count($vippsKall()) === $foer, $r[2]);

    echo "\n── 4. For sen avbestilling: beløpet beholdes ────────────────\n";
    [$r, $b4] = $bestill($oktSen, ['gjenstander' => $valg1200]);
    sjekk('booket 10 timer før, 1 200 kr', $r[0] === 200 && (int) ($b4['belop_ore'] ?? 0) === 120000, $r[2]);
    $betal($b4);
    PopPris::bekreftelse($b4);
    $kode4 = (string) DB::verdi('SELECT avbestill_kode FROM bookings WHERE id = :i', ['i' => (int) $b4['id']]);
    [, , $side] = $kall('GET', '/api/pop-avbestill.php?b=' . $b4['id'] . '&k=' . $kode4);
    sjekk('sida sier at beløpet beholdes', str_contains($side, 'Det er mindre enn 24 timer igjen, så beløpet beholdes.'));
    $foer = count($vippsKall());
    $r = $kall('POST', '/api/avbestill.php', ['bookingId' => (int) $b4['id'], 'k' => $kode4]);
    sjekk('avbestilt uten refusjon', $r[0] === 200 && count($vippsKall()) === $foer
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b4['id']]) === 'avbestilt', $r[2]);
    sjekk('… betalingen står urørt (1 200 kr, ingenting refundert)',
        (int) DB::verdi('SELECT COALESCE(SUM(amount_ore), 0) FROM payment_refunds WHERE payment_id = :p', ['p' => (int) $b4['payment_id']]) === 0
        && DB::verdi('SELECT status FROM payments WHERE id = :p', ['p' => (int) $b4['payment_id']]) === 'betalt');

    echo "\n── 5. Kassa: 1 200 kr trekkes fra ───────────────────────────\n";
    [$r, $b5] = $bestill($okt, ['gjenstander' => $valg1200]);
    $betal($b5);
    $k = PopPris::kassaData((int) $b5['id']);
    $utfylt = [];
    foreach ($k['rader'] as $rad) {
        $n = array_sum(array_column($rad['lagret'], 'antall'));
        if ($n > 0) { $utfylt[$rad['nokkel']] = [$n, $rad['aktiv']]; }
    }
    sjekk('kassa viser gjenstandene ferdig utfylt (kan økes)',
        ($utfylt[$liten['id'] . '|'] ?? null) === [1, true] && ($utfylt[$mellom['id'] . '|'] ?? null) === [1, true], json_encode($utfylt));
    sjekk('… betalt 1 200 kr, betalt ved booking 1 200 kr', $k['betaltOre'] === 120000 && $k['depositumOre'] === 120000);
    $f = PopPris::forhandsvis((int) $b5['id'], [$liten['id'] . '|' => 1, $mellom['id'] . '|' => 1]);
    sjekk('samme gjenstander i kassa: 0 kr å betale', $f['sumOre'] === 120000 && $f['skyldigOre'] === 0, json_encode($f));
    $kr = PopPris::kassa((int) $b5['id'], [['nivaaId' => $liten['id'], 'gjenstand' => '', 'antall' => 1],
        ['nivaaId' => $stor['id'], 'gjenstand' => '', 'antall' => 1]], null);
    sjekk('Monica retter Mellom til Stor: 1 350 − 1 200 = 150 kr igjen', $kr['sumOre'] === 135000 && $kr['skyldigOre'] === 15000, json_encode($kr));
    $kr = PopPris::kassa((int) $b5['id'], [$liten['id'] . '|' => 2], null);
    sjekk('færre enn betalt: beløpet går ikke under 1 200 kr, ingenting skyldig', $kr['sumOre'] === 100000 && $kr['skyldigOre'] === 0
        && (int) DB::verdi('SELECT belop_ore FROM bookings WHERE id = :i', ['i' => (int) $b5['id']]) === 120000, json_encode($kr));
    $kr = PopPris::kassa((int) $b5['id'], [$liten['id'] . '|' => 1, $mellom['id'] . '|' => 1], null);
    sjekk('tilbake til det kunden valgte: betalt, 0 kr igjen', $kr['sumOre'] === 120000 && $kr['skyldigOre'] === 0
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b5['id']]) === 'betalt');
    PopPris::bekreftelse($b5);
    $r = $kall('POST', '/api/avbestill.php', ['bookingId' => (int) $b5['id'],
        'k' => (string) DB::verdi('SELECT avbestill_kode FROM bookings WHERE id = :i', ['i' => (int) $b5['id']])]);
    sjekk('gjort opp i kassa: kan ikke avbestilles med lenka', $r[0] === 409, $r[2]);
    $k2 = PopPris::kassaData((int) $b2['id']);
    sjekk('oppmøte i kassa: ingenting betalt, gjenstandene slås inn der', $k2['betaltOre'] === 0 && $k2['depositumOre'] === 0);
    $kr = PopPris::kassa((int) $b2['id'], [$liten['id'] . '|' => 1, $mellom['id'] . '|' => 1], null);
    sjekk('… 1 200 kr å betale i verkstedet', $kr['sumOre'] === 120000 && $kr['skyldigOre'] === 120000, json_encode($kr));
    $b2k = DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $b2['id']]);
    sjekk('… står som reservert og «Ikke betalt» til pengene er inne', $b2k['status'] === 'reservert'
        && Malebord::betalingsstatus($b2k, 0) === 'Betaler ved besøket' && (int) $b2k['belop_ore'] === 120000);

    // Kontrolløren, runde 2: gjort opp i kassa med 0 kr (100 % rabatt) skal
    // bli betalt — men aldri før gjenstandene er slått inn.
    [$r, $b2r] = $bestill($okt, ['betaling' => 'oppmote']);
    sjekk('oppmøte, ikke gjort opp: fortsatt reservert', Booking::settBetaltStatus((int) $b2r['id'])['status'] === 'reservert');
    // Rabatten kassa fører på påmeldingen (KasseJustering, migrasjon 269).
    DB::oppdater('bookings', ['kasse_rabatt_ore' => 50000], ['id' => (int) $b2r['id']]);
    $kr = PopPris::kassa((int) $b2r['id'], [$liten['id'] . '|' => 1], null);
    $b2rk = DB::en('SELECT status, belop_ore FROM bookings WHERE id = :i', ['i' => (int) $b2r['id']]);
    sjekk('oppmøte gjort opp i kassa med 100 % rabatt (0 kr): betalt', $b2rk['status'] === 'betalt' && (int) $b2rk['belop_ore'] === 0,
        json_encode($b2rk));

    echo "\n── 6. Gammel booking med 100 kr ─────────────────────────────\n";
    $b6 = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $okt, 'gjest_navn' => 'PoP Gammel',
        'gjest_epost' => 'popbetal-gammel@example.com', 'antall' => 2, 'belop_ore' => 20000, 'status' => 'betalt',
        'depositum_ore' => 20000, 'avbestilling_timer' => 24]);
    $bookinger[] = $b6;
    $p6 = DB::settInn('payments', ['vipps_reference' => 'TEST-POPBETAL-' . bin2hex(random_bytes(4)), 'type' => 'epayment',
        'formal' => 'booking', 'belop_ore' => 20000, 'status' => 'betalt', 'idempotency_key' => Vipps::uuid(), 'booking_id' => $b6]);
    DB::oppdater('bookings', ['payment_id' => $p6], ['id' => $b6]);
    $t6 = PopPris::bekreftelse(DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => $b6]));
    sjekk('bekreftelsen som før: «Betalt: 200 kr.»', str_contains($t6, 'Betalt: 200 kr.') && str_contains($t6, 'De 200 kronene trekkes fra.'), $t6);
    $kr = PopPris::kassa($b6, [$liten['id'] . '|' => 1, $mellom['id'] . '|' => 1], null);
    sjekk('kassa: 1 200 − 200 = 1 000 kr igjen', $kr['sumOre'] === 120000 && $kr['betaltOre'] === 20000 && $kr['skyldigOre'] === 100000, json_encode($kr));
    sjekk('… betalt ved booking står urørt (200 kr)', (int) DB::verdi('SELECT depositum_ore FROM bookings WHERE id = :i', ['i' => $b6]) === 20000);

    echo "\n── Admin og andre veier ─────────────────────────────────────\n";
    sjekk('ingen fast beløp per person lenger (admin, venteliste, kassa)', PopPris::depositumPerPerson($kurs) === 0);
    sjekk('… og bookingfeltene blir 0 kr ved booking', (PopPris::bookingFelt($kurs, 0)['depositum_ore'] ?? null) === 0);
    $pm = (string) file_get_contents($rot . '/api/admin/pamelding.php');
    sjekk('nytt antall i admin regner ikke om beløpet på Paint on Pots',
        str_contains($pm, "|| (\$pr['gjenstander_ore'] === null && (int) \$pr['depositum'] !== 1);"));
} catch (Throwable $e) {
    sjekk('uventet feil', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    foreach ($servere as $p) { proc_terminate($p); proc_close($p); }
    foreach ($bookinger as $b) {
        $pids = array_map('intval', array_column(DB::alle('SELECT id FROM payments WHERE booking_id = :b', ['b' => $b]), 'id'));
        DB::kjor('UPDATE bookings SET payment_id = NULL WHERE id = :b', ['b' => $b]);
        foreach ($pids as $p) {
            DB::kjor('DELETE FROM payment_refunds WHERE payment_id = :p', ['p' => $p]);
            DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $p]);
        }
        DB::kjor('DELETE FROM pop_kasselinjer WHERE booking_id = :b', ['b' => $b]);
        DB::kjor("DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id = :b", ['b' => $b]);
        DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'booking' AND objekt_id = :b", ['b' => $b]);
        DB::kjor('DELETE FROM bookings WHERE id = :b', ['b' => $b]);
    }
    foreach ($okter as $o) { DB::kjor('DELETE FROM course_sessions WHERE id = :i', ['i' => $o]); }
    if ($kurs) { DB::kjor('DELETE FROM courses WHERE id = :i', ['i' => $kurs]); }
    foreach (glob($mappe . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($mappe);
}

echo "\n$ok OK, $feil FEIL\n";
exit($feil > 0 ? 1 : 0);
