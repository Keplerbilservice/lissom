<?php
/**
 * Det siste som selges, selges én gang.
 *
 * Codex-gjennomgangen 30. september 2026 fant fire steder der to kjop som
 * kom samtidig — eller en betaling som kom sent — kunne gi bort det samme to
 * ganger:
 *
 *   1. Gavekortet: saldoen ble sjekket i kassa, men trukket foerst naar
 *      betalingen var i havn. To kjop med samme kort kunne begge bruke hele
 *      saldoen.
 *   2. En betaling som kom etter at plassen var sluppet, satte bookingen til
 *      betalt uten aa se om plassen fortsatt var der.
 *   3. Oekter som deler en ressurs (skivene): bare oekta ble laast, ikke
 *      ressursen, saa to kjop paa hver sin oekt kunne begge faa den siste.
 *   4. Butikken: lageret ble sjekket linje for linje, og ikke mot det som
 *      allerede var paa vei i Vipps.
 *
 * Kjor:  php tests/kjopslaas.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

$merke = 'KJOPSLAAS-' . strtoupper(bin2hex(random_bytes(3)));
$ref = static fn(): string => 'KL-' . strtoupper(bin2hex(random_bytes(6)));

// ── 1. Gavekortet holder det som er paa vei ────────────────────────────
echo "\n── Gavekort som er i bruk i et annet kjop ───────────────────────\n";

$kortId = DB::settInn('gift_cards', [
    'kode' => $merke . '-GK', 'opprinnelig_ore' => 50000, 'saldo_ore' => 50000,
    'gyldig_til' => gmdate('Y-m-d', time() + 86400 * 365),
    'status' => 'aktivt', 'opprinnelse' => 'gitt',
]);
sjekk('et ubrukt kort dekker hele saldoen', Booking::gavekortDekker($kortId, 50000));

$vent = DB::settInn('payments', [
    'vipps_reference' => $ref(), 'type' => 'epayment', 'formal' => 'ordre',
    'belop_ore' => 1000, 'gavekort_id' => $kortId, 'gavekort_ore' => 40000,
    'status' => 'venter', 'idempotency_key' => Vipps::uuid(),
]);
sjekk('et kjop paa vei i Vipps holder beloepet sitt', !Booking::gavekortDekker($kortId, 20000));
sjekk('… resten kan fortsatt brukes', Booking::gavekortDekker($kortId, 10000));

DB::kjor('UPDATE payments SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) WHERE id = :i', ['i' => $vent]);
sjekk('en betaling som har staatt aapen over en time holder ikke lenger', Booking::gavekortDekker($kortId, 50000));

DB::kjor("UPDATE payments SET status = 'avbrutt', created_at = UTC_TIMESTAMP() WHERE id = :i", ['i' => $vent]);
sjekk('en avbrutt betaling holder ingenting', Booking::gavekortDekker($kortId, 50000));

// ── 3. Ressursen laases, ikke bare oekta ──────────────────────────────
echo "\n── To oekter som deler skivene ──────────────────────────────────\n";

$ressurs = DB::settInn('ressurser', ['navn' => $merke, 'antall' => 2]);
Booking::glemTak();
$kurs = DB::settInn('courses', ['slug' => strtolower($merke), 'tittel' => $merke, 'type' => 'kurs',
    'pris_ore' => 50000, 'kapasitet' => 1, 'status' => 'publisert', 'ressurs_id' => $ressurs]);
$dag = gmdate('Y-m-d', time() + 86400 * 20);
$oktA = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => "$dag 10:00:00",
    'slutt_tid' => "$dag 12:00:00", 'kapasitet' => 1]);
$oktB = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => "$dag 11:00:00",
    'slutt_tid' => "$dag 13:00:00", 'kapasitet' => 1]);

// En annen kobling holder ressursen, som et kjop midt i transaksjonen sin.
$annen = new PDO('mysql:host=' . Config::hent('db_vert', 'localhost') . ';dbname=' . Config::krev('db_navn') . ';charset=utf8mb4',
    Config::krev('db_bruker'), Config::krev('db_passord'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$annen->beginTransaction();
$annen->query('SELECT id FROM ressurser WHERE id = ' . (int) $ressurs . ' FOR UPDATE')->fetchAll();

DB::kjor('SET SESSION innodb_lock_wait_timeout = 1');
$ventet = false;
try {
    DB::iTransaksjon(static fn() => Booking::ledigePlasser($oktB, true));
} catch (Throwable $e) {
    $ventet = str_contains($e->getMessage(), 'Lock wait timeout');
}
sjekk('et kjop paa nabooekta venter paa ressursen', $ventet);
$annen->rollBack();

sjekk('… og slipper til naar den andre er ferdig',
    DB::iTransaksjon(static fn() => Booking::ledigePlasser($oktB, true)) === 1);

// ── 2. Betaling etter at plassen er sluppet ───────────────────────────
echo "\n── Betaling som kommer etter fristen ────────────────────────────\n";

$lagBooking = static function (int $okt, string $status, ?string $frist, ?string $avbestilt, int $gk = 0)
    use ($ref, $kortId): array {
    $r = $ref();
    $p = DB::settInn('payments', [
        'vipps_reference' => $r, 'type' => 'epayment', 'formal' => 'booking',
        'belop_ore' => 50000 - $gk, 'status' => 'venter', 'idempotency_key' => Vipps::uuid(),
        'gavekort_id' => $gk > 0 ? $kortId : null, 'gavekort_ore' => $gk,
    ]);
    $b = DB::settInn('bookings', [
        'course_id' => (int) DB::verdi('SELECT course_id FROM course_sessions WHERE id = :i', ['i' => $okt]),
        'course_session_id' => $okt, 'gjest_navn' => 'Kjopslaas Test', 'gjest_epost' => 'kl@lissom.test',
        'antall' => 1, 'belop_ore' => 50000, 'status' => $status, 'payment_id' => $p,
        'reservert_til' => $frist, 'avbestilt_at' => $avbestilt,
    ]);
    return ['ref' => $r, 'p' => $p, 'b' => $b];
};
$status = static fn(int $b): string => (string) DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $b]);
$pstatus = static fn(int $p): string => (string) DB::verdi('SELECT status FROM payments WHERE id = :i', ['i' => $p]);
$for = static fn(int $min): string => gmdate('Y-m-d H:i:s', time() + $min * 60);

// Innenfor fristen: som foer.
$x = $lagBooking($oktA, 'reservert', $for(15), null);
Booking::markerBetalt($x['ref']);
sjekk('betalt innenfor fristen blir betalt', $status($x['b']) === 'betalt');

// Fristen ute, cron har sluppet den, og plassen er tatt av en annen.
$annenB = $lagBooking($oktB, 'betalt', null, null);
$y = $lagBooking($oktB, 'avbestilt', $for(-30), $for(-25), 10000);
$saldoFor = (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]);
Booking::markerBetalt($y['ref']);
sjekk('sluppet og plassen tatt: bookingen vekkes ikke', $status($y['b']) === 'avbestilt', $status($y['b']));
sjekk('… betalingen staar som betalt, saa den kan refunderes', $pstatus($y['p']) === 'betalt');
sjekk('… gavekortet trekkes ikke for en plass kunden ikke fikk',
    (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]) === $saldoFor);
sjekk('… og det staar i revisjonsloggen',
    DB::verdi("SELECT id FROM audit_log WHERE handling = 'betalt_uten_plass' AND objekt_id = :b",
        ['b' => $y['b']]) !== null);

// Eieren, 30. september 2026: «Bare varsle, refunder for haand».
$beskjed = DB::en(
    "SELECT emne, tekst FROM notifications
      WHERE kanal = 'epost' AND ref_type = 'booking' AND ref_id = :b
        AND emne = 'Må refunderes: betalt etter at plassen var borte'",
    ['b' => $y['b']]
);
sjekk('… verkstedet faar beskjed om aa refundere for haand', $beskjed !== null);
sjekk('… med referansen, saa den finnes i Kasse › Betalinger',
    $beskjed !== null && str_contains((string) $beskjed['tekst'], 'Referanse: ' . $y['ref'])
    && str_contains((string) $beskjed['tekst'], 'Refunder under Kasse › Betalinger — søk på referansen.'));
sjekk('… og beloepet er det som ble betalt i Vipps (gavekortet er ikke trukket)',
    $beskjed !== null && str_contains((string) $beskjed['tekst'], 'Beløp: ' . Booking::kroner(40000)),
    (string) ($beskjed['tekst'] ?? ''));
sjekk('… uten at noe refunderes av seg selv', $pstatus($y['p']) === 'betalt'
    && (int) DB::verdi('SELECT refundert_ore FROM payments WHERE id = :i', ['i' => $y['p']]) === 0);

// Plassen er fri igjen: da faar den som betalte sent den.
DB::oppdater('bookings', ['status' => 'avbestilt'], ['id' => $annenB['b']]);
$z = $lagBooking($oktB, 'avbestilt', $for(-30), $for(-25));
Booking::markerBetalt($z['ref']);
sjekk('sluppet, men plassen fortsatt ledig: bookingen blir betalt', $status($z['b']) === 'betalt', $status($z['b']));
sjekk('… og er ikke lenger merket avbestilt',
    DB::verdi('SELECT avbestilt_at FROM bookings WHERE id = :i', ['i' => $z['b']]) === null);
DB::oppdater('bookings', ['status' => 'avbestilt'], ['id' => $z['b']]);

// Avbestilt for haand foer fristen gikk ut: vekkes aldri.
DB::oppdater('bookings', ['status' => 'avbestilt'], ['id' => $x['b']]);
$w = $lagBooking($oktA, 'avbestilt', $for(10), $for(-1));
Booking::markerBetalt($w['ref']);
sjekk('avbestilt for haand: vekkes ikke selv med ledig plass', $status($w['b']) === 'avbestilt', $status($w['b']));

// Reservert, fristen ute, cron har ikke kjort ennaa, plassen ledig.
$v = $lagBooking($oktA, 'reservert', $for(-5), null);
Booking::markerBetalt($v['ref']);
sjekk('fristen ute foer cron, plassen ledig: blir betalt', $status($v['b']) === 'betalt', $status($v['b']));

// ── 4. Butikken ──────────────────────────────────────────────────────
echo "\n── Butikkens lager ──────────────────────────────────────────────\n";

$vare = DB::settInn('products', ['tittel' => $merke . ' kopp', 'pris_ore' => 20000, 'lager' => 1, 'status' => 'publisert']);
$port = 8190 + random_int(0, 9);
$rot = dirname(__DIR__);
$tom = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $rot], [['pipe', 'r'], ['file', $tom, 'w'], ['file', $tom, 'w']], $ror);
for ($i = 0; $i < 30; $i++) {
    if (@fsockopen('127.0.0.1', $port)) { break; }
    usleep(200000);
}
$post = static function (array $kropp) use ($port): array {
    $kontekst = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($kropp), 'ignore_errors' => true, 'timeout' => 20,
    ]]);
    $svar = (string) @file_get_contents("http://127.0.0.1:$port/api/ordre.php", false, $kontekst);
    $kode = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $kode = (int) $m[1]; }
    }
    return [$kode, json_decode($svar, true) ?? $svar];
};
// Endepunktet tillater ti kjop per ti minutter; testene skal ikke stoppe paa det.
DB::kjor('DELETE FROM rate_limits');
$kunde = ['navn' => 'Kjopslaas Test', 'epost' => 'kl@lissom.test', 'telefon' => '91234567'];

[$k, $svar] = $post($kunde + ['linjer' => [['id' => $vare, 'antall' => 1], ['id' => $vare, 'antall' => 1]]]);
sjekk('samme vare paa to linjer teller sammen', $k === 409, "$k " . json_encode($svar, JSON_UNESCAPED_UNICODE));

// En annen kunde er i Vipps med den siste koppen.
$pv = DB::settInn('payments', ['vipps_reference' => $ref(), 'type' => 'epayment', 'formal' => 'ordre',
    'belop_ore' => 20000, 'status' => 'venter', 'idempotency_key' => Vipps::uuid()]);
$ov = DB::settInn('orders', ['ordrenr' => 'B-KL-' . bin2hex(random_bytes(3)), 'kunde_navn' => 'Annen',
    'sum_ore' => 20000, 'status' => 'ny', 'payment_id' => $pv]);
DB::settInn('order_lines', ['order_id' => $ov, 'product_id' => $vare, 'tittel' => 'kopp', 'antall' => 1, 'pris_ore' => 20000]);

$kort2 = DB::settInn('gift_cards', ['kode' => $merke . '-GK2', 'opprinnelig_ore' => 20000, 'saldo_ore' => 20000,
    'gyldig_til' => gmdate('Y-m-d', time() + 86400 * 365), 'status' => 'aktivt', 'opprinnelse' => 'gitt']);
[$k, $svar] = $post($kunde + ['linjer' => [['id' => $vare, 'antall' => 1]], 'gavekort' => $merke . '-GK2']);
sjekk('den siste koppen er holdt av en som er i Vipps', $k === 409, "$k " . json_encode($svar, JSON_UNESCAPED_UNICODE));
sjekk('… med den samme meldingen som foer', is_array($svar) && str_contains((string) ($svar['feil'] ?? json_encode($svar)), 'Vi har ikke nok igjen'),
    json_encode($svar, JSON_UNESCAPED_UNICODE));

// Den andre ga opp for over en time siden: koppen er ledig igjen.
DB::kjor('UPDATE payments SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) WHERE id = :i', ['i' => $pv]);
[$k, $svar] = $post($kunde + ['linjer' => [['id' => $vare, 'antall' => 1]], 'gavekort' => $merke . '-GK2']);
sjekk('en gammel, aapen betaling holder ikke koppen', $k === 200, "$k " . json_encode($svar, JSON_UNESCAPED_UNICODE));
sjekk('… og lageret er trukket', (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $vare]) === 0);

// Gavekortet i butikken: et annet kjop paa vei holder saldoen.
DB::kjor('UPDATE products SET lager = 5 WHERE id = :i', ['i' => $vare]);
$kort3 = DB::settInn('gift_cards', ['kode' => $merke . '-GK3', 'opprinnelig_ore' => 30000, 'saldo_ore' => 30000,
    'gyldig_til' => gmdate('Y-m-d', time() + 86400 * 365), 'status' => 'aktivt', 'opprinnelse' => 'gitt']);
DB::settInn('payments', ['vipps_reference' => $ref(), 'type' => 'epayment', 'formal' => 'ordre',
    'belop_ore' => 5000, 'gavekort_id' => $kort3, 'gavekort_ore' => 25000,
    'status' => 'venter', 'idempotency_key' => Vipps::uuid()]);
[$k, $svar] = $post($kunde + ['linjer' => [['id' => $vare, 'antall' => 1]], 'gavekort' => $merke . '-GK3']);
sjekk('gavekort i bruk i et annet kjop stoppes i butikken', $k === 400
    && str_contains(json_encode($svar, JSON_UNESCAPED_UNICODE), 'Fant ikke gavekortet, eller det er brukt opp.'),
    "$k " . json_encode($svar, JSON_UNESCAPED_UNICODE));

proc_terminate($srv);

echo "\n──────────────────────────────────────────────\n";
echo ($ok + $feil) . " sjekker, $ok gikk gjennom" . ($feil ? ", $feil feilet" : '') . "\n";
exit($feil > 0 ? 1 : 0);
