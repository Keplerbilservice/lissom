<?php
/**
 * Ny kasse på iPad (eieren, godkjent design 30. oktober 2026, «Ja, bygg»).
 *
 *   A  «I dag»: alle dagens økter (også der alle har betalt), varene med
 *      «keramikk» (nettbutikken) og «leire» (internbutikken), filtrene i
 *      eierens rekkefølge.
 *   B  «Har betalt»: kurset viser dem som har betalt for seg; i kurven står
 *      bookingen med 0 igjen («Betalt ved booking» trukket fra), og det som
 *      legges til fra prislisten kommer på samme booking — bare det ekstra
 *      betales.
 *   C  Gavekort som betalingsmåte: hele beløpet fra kortet; dekker det ikke
 *      alt, avvises betalingen og ingenting registreres; gavekort kan ikke
 *      kjøpes med gavekort.
 *   D  Kjøpet på Betalt-skjermen: varene med antall, «inkl. mva»/«uten mva»,
 *      mva regnet fra varens mva-innstilling.
 *   E  Unikt stykke solgt i kassa er borte fra nettbutikken med en gang.
 *   F  Dagens oppgjør: Vipps-nummer og Faktura på egne linjer (Faktura bare
 *      med beløp); «Dagens salg» med «Angre»: salget motføres (ingenting
 *      slettes), varene tilbake på lager, påmeldingen skylder igjen, hvem og
 *      når står igjen; to ganger avvises.
 *   G  SMS-kvitteringen: ny tekst (migrasjon 282) med én vare per linje og
 *      uten kundens navn; sendes bare når den bes om, til nummeret i feltet;
 *      kontantkunden bare for sitt eget salg; sperren mot to SMS-er står.
 *   H  Gavekort, alt eller ingenting (kontrolløren 31. oktober 2026): hele
 *      kurven trekkes i én transaksjon med kortet låst og avvises samlet når
 *      saldoen ikke dekker alt; «Angre» på en påmelding gir kortet beløpet
 *      tilbake i samme transaksjon som annulleringen.
 *
 * Ekte endepunkter mot en isolert testbase og tests/falsk-vipps.mjs. Ingen
 * ekte betaling, e-post eller SMS (SMS-en blir liggende i køen, ingen cron).
 *   php tests/kasse-ny.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
$oppsett = krev_testdatabase($rot);

$ledigPort = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
    return (int) substr($adr, strrpos($adr, ':') + 1);
};
$nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$prosesser = [];

$vippsPort = $ledigPort();
$prosesser[] = proc_open(['node', $rot . '/tests/falsk-vipps.mjs'], [0 => ['pipe', 'r'], 1 => ['file', $nul, 'a'], 2 => ['file', $nul, 'a']],
    $vp, $rot, array_merge(getenv(), ['FALSK_VIPPS_PORT' => (string) $vippsPort]));
fclose($vp[0]);
for ($v = 0; $v < 80; $v++) { $f = @fsockopen('127.0.0.1', $vippsPort, $e1, $e2, 0.1); if ($f) { fclose($f); break; } usleep(50000); }
putenv('LISSOM_VIPPS_BASE=http://127.0.0.1:' . $vippsPort);

require $rot . '/app/bootstrap.php';
if (!str_starts_with(Config::vippsBase(), 'http://127.0.0.1:')) {
    fwrite(STDERR, "Vipps-adressen er ikke den falske. Stopper.\n");
    exit(1);
}

$tag = 'KNY-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $kurs = []; $produkter = []; $kort = []; $ferdig = false;
$trigger = 'kny_feil_' . bin2hex(random_bytes(4));
$bryterFor = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/kasse'");
$smsFor = DB::harTabell('innstillinger')
    ? array_column(DB::alle("SELECT nokkel, verdi FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')"), 'verdi', 'nokkel')
    : [];
$logg = sys_get_temp_dir() . '/lissom-kasse-ny-' . bin2hex(random_bytes(4)) . '.log';
$ordreFor = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM orders');
$betalingFor = (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM payments');

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$kurs, &$produkter, &$kort, &$prosesser, $bryterFor, $ordreFor, $betalingFor, $smsFor, $trigger): void {
    foreach ($prosesser as $p) { if (is_resource($p)) { proc_terminate($p); } }
    try { DB::kjor("DROP TRIGGER IF EXISTS `$trigger`"); } catch (Throwable $e) {}
    try {
        if ($bryterFor === null) { DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/kasse'"); }
        else { DB::kjor("UPDATE content_blocks SET verdi = :v WHERE nokkel = 'Vis/kasse'", ['v' => (string) $bryterFor]); }
        if (DB::harTabell('innstillinger')) {
            DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('sveve_bruker', 'sveve_passord', 'sms_leverandor')");
            foreach ($smsFor as $n => $v) { DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v)', ['n' => $n, 'v' => $v]); }
        }
    } catch (Throwable $e) {}
    $k = implode(',', array_map('intval', $kurs ?: [0]));
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $pr = implode(',', array_map('intval', $produkter ?: [0]));
    $g = implode(',', array_map('intval', $kort ?: [0]));
    $b = implode(',', array_map('intval', array_column(DB::alle("SELECT id FROM bookings WHERE course_id IN ($k)"), 'id')) ?: [0]);
    $o = implode(',', array_map('intval', array_column(DB::alle('SELECT id FROM orders WHERE id > :i', ['i' => $ordreFor]), 'id')) ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id IN ($b)",
        "DELETE FROM notifications WHERE ref_type = 'order' AND ref_id IN ($o)",
        "DELETE FROM notifications WHERE ref_type = 'payment' AND ref_id > " . $betalingFor,
        "DELETE FROM pop_kasselinjer WHERE booking_id IN ($b)",
        "DELETE FROM kasse_justeringer WHERE booking_id IN ($b) OR order_id IN ($o) OR registrert_av IN ($m)",
        "UPDATE bookings SET payment_id = NULL WHERE id IN ($b)",
        "UPDATE orders SET payment_id = NULL WHERE id IN ($o)",
        "DELETE FROM order_lines WHERE order_id IN ($o)",
        "DELETE FROM gift_card_uses WHERE gift_card_id IN ($g)",
        "DELETE FROM payments WHERE booking_id IN ($b) OR order_id IN ($o) OR registrert_av IN ($m) OR member_id IN ($m) OR gavekort_id IN ($g)",
        "DELETE FROM gift_cards WHERE id IN ($g)",
        "DELETE FROM orders WHERE id IN ($o)",
        "DELETE FROM bookings WHERE id IN ($b)",
        "DELETE FROM course_sessions WHERE course_id IN ($k)",
        "DELETE FROM courses WHERE id IN ($k)",
        "DELETE FROM products WHERE id IN ($pr)",
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m)",
        "DELETE FROM rate_limits WHERE nokkel LIKE 'kasse-pin%'",
        "DELETE FROM members WHERE id IN ($m)",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    }
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

$port = $ledigPort();
$prosesser[] = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

/** @return array{0:int,1:mixed} status, json */
function kall(string $sti, ?array $data, string $token = ''): array
{
    global $port;
    $c = curl_init('http://127.0.0.1:' . $port . $sti);
    $hode = ['Origin: ' . Config::nettsted()];
    if ($token !== '') { $hode[] = 'Cookie: lissom_sesjon=' . $token; }
    $valg = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30];
    if ($data !== null) {
        $hode[] = 'Content-Type: application/json';
        $valg[CURLOPT_POST] = true;
        $valg[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    $valg[CURLOPT_HTTPHEADER] = $hode;
    curl_setopt_array($c, $valg);
    $raa = (string) curl_exec($c);
    $s = [(int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), json_decode($raa, true)];
    curl_close($c);
    return $s;
}
$K = '/api/kasse/kasse.php';
$P = '/api/kasse/pin.php';
$tekst = static fn(array $s): string => $s[0] . ' ' . json_encode($s[1], JSON_UNESCAPED_UNICODE);
$rent = static fn(string $t): string => str_replace("\u{a0}", ' ', $t);

$sesjon = static function (int $medlem, int $timer = 3): string {
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $medlem, 'token_hash' => hash('sha256', $t), 'maate' => 'passord',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + $timer * 3600)]);
    return $t;
};
$nyttMedlem = static function (array $felt) use (&$medlemmer, $tag): int {
    $id = DB::settInn('members', $felt + ['epost' => strtolower($tag) . '-' . bin2hex(random_bytes(3)) . '@lissom.test', 'status' => 'ingen']);
    $medlemmer[] = $id;
    return $id;
};
$nyVare = static function (array $felt) use (&$produkter, $tag): int {
    $id = DB::settInn('products', ['tittel' => $tag . ' ' . $felt['tittel']] + $felt);
    $produkter[] = $id;
    return $id;
};
$kjop = static function (array $kurv, array $betaler) use ($K, &$kasseToken): array {
    $s = kall($K, ['handling' => 'regn', 'kurv' => $kurv, 'betaler' => $betaler], $kasseToken);
    $deler = $s[1]['deler'] ?? [];
    return ['kurv' => $kurv, 'betaler' => $betaler,
            'nokler' => array_combine(array_column($deler, 'id'), array_map(static fn() => Vipps::uuid(), $deler)) ?: [],
            'forventet' => array_combine(array_column($deler, 'id'), array_column($deler, 'sumOre')) ?: [],
            'regn' => $s];
};
$send = static fn(array $k): array => ['kurv' => $k['kurv'], 'betaler' => $k['betaler'], 'nokler' => $k['nokler'], 'forventet' => $k['forventet']];
$rad = static fn(array $o, string $navn): ?int => array_column($o['rader'], 'ore', 'navn')[$navn] ?? null;

try {
    sjekk('HTTP-serveren klar', $klar);
    DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/kasse', 'ja') ON DUPLICATE KEY UPDATE verdi = 'ja'");
    $kasse = $nyttMedlem(['navn' => $tag . ' iPad', 'rolle' => 'kasse', 'brukernavn' => strtolower($tag) . '-ipad',
        'passord_hash' => password_hash('x' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT)]);
    $monica = $nyttMedlem(['navn' => $tag . ' Monica', 'rolle' => 'medlem', 'brukernavn' => strtolower($tag) . '-monica']);
    $pin = '3' . random_int(100, 999);
    KasseTilgang::settPin($monica, $pin);
    $kasseToken = $sesjon($kasse, 720);
    $s = kall($P, ['handling' => 'pin', 'pin' => $pin], $kasseToken);
    sjekk('kassa låst opp med PIN', $s[0] === 200, $tekst($s));

    $oslo = new DateTimeZone('Europe/Oslo');
    $utc = new DateTimeZone('UTC');
    $kl = static fn(string $t): string => (new DateTimeImmutable('today ' . $t, $oslo))->setTimezone($utc)->format('Y-m-d H:i:s');
    $nyttKurs = static function (string $navn, int $pris, array $mer = []) use (&$kurs, $tag): int {
        $id = DB::settInn('courses', ['slug' => strtolower($tag) . '-' . bin2hex(random_bytes(3)), 'tittel' => $tag . ' ' . $navn,
            'type' => 'kurs', 'pris_ore' => $pris, 'kapasitet' => 20, 'status' => 'publisert'] + $mer);
        $kurs[] = $id;
        return $id;
    };
    $nyOkt = static fn(int $kursId, string $t): int => DB::settInn('course_sessions', ['course_id' => $kursId, 'start_tid' => $kl($t), 'status' => 'planlagt']);

    // ── Testdata ───────────────────────────────────────────────────────
    $niv = PopPris::nivaer();
    $liten = $niv[0] ?? null;
    sjekk('prisnivåene finnes (PopPris::nivaer)', $liten !== null);
    $popKurs = $nyttKurs('Paint on Pots', 10000, ['type' => 'event', 'folger_apningstid' => 1, 'depositum' => 1, 'avbestilling_timer' => 24]);
    $popOkt = $nyOkt($popKurs, '16:00');
    // Sara har betalt alt: beløpet ved booking dekker gjenstanden hun valgte.
    $sara = DB::settInn('bookings', ['course_id' => $popKurs, 'course_session_id' => $popOkt, 'gjest_navn' => $tag . ' Sara',
        'gjest_epost' => strtolower($tag) . '-sara@example.com', 'gjest_telefon' => '+4799887711', 'antall' => 1,
        'belop_ore' => (int) $liten['prisOre'], 'depositum_ore' => (int) $liten['prisOre'], 'status' => 'betalt', 'betalt_maate' => 'Kontant']);
    $p = Booking::manuellBetaling($sara, (int) $liten['prisOre'], 'Kontant', null, null, 'Beløp ved booking');
    DB::oppdater('bookings', ['payment_id' => $p], ['id' => $sara]);
    PopPris::kassa($sara, [['nivaaId' => $liten['id'], 'gjenstand' => '', 'antall' => 1]], $monica);
    Booking::settBetaltStatus($sara);
    // Ola skylder.
    $ola = DB::settInn('bookings', ['course_id' => $popKurs, 'course_session_id' => $popOkt, 'gjest_navn' => $tag . ' Ola',
        'antall' => 1, 'belop_ore' => 20000, 'depositum_ore' => 20000, 'status' => 'reservert', 'betalt_maate' => 'Ikke betalt']);

    $kopp = $nyVare(['tittel' => 'Tekopp', 'kategori' => 'Kopper', 'pris_ore' => 29000, 'lager' => 9, 'mva_prosent' => 25,
        'status' => 'publisert', 'kun_medlemmer' => 0, 'i_nettbutikk' => 1, 'bilde' => 'uploads_vare-8.jpg']);
    $unik = $nyVare(['tittel' => 'Unik vase', 'kategori' => 'Vaser', 'pris_ore' => 120000, 'lager' => 1, 'mva_prosent' => 25,
        'status' => 'publisert', 'kun_medlemmer' => 0, 'i_nettbutikk' => 1]);
    $leire = $nyVare(['tittel' => 'Leire grå', 'kategori' => 'Materialer', 'pris_ore' => 29000, 'lager' => null, 'mva_prosent' => 25,
        'status' => 'publisert', 'kun_medlemmer' => 1, 'i_nettbutikk' => 0]);
    $lissomKol = $nyVare(['tittel' => 'Skål', 'kategori' => 'Lissom kolleksjon', 'pris_ore' => 45000, 'lager' => null, 'mva_prosent' => 25,
        'status' => 'publisert', 'kun_medlemmer' => 0, 'i_nettbutikk' => 1]);

    // ══ A: «I dag» ═════════════════════════════════════════════════════
    echo "\n── A: I dag ──\n";
    $s = kall($K, null, $kasseToken);
    $varer = array_column($s[1]['varer'] ?? [], null, 'id');
    sjekk('Keramikk: varen i nettbutikken er «keramikk», ikke «leire», med bilde', ($varer[$kopp]['keramikk'] ?? null) === true
        && ($varer[$kopp]['leire'] ?? null) === false && ($varer[$kopp]['bilde'] ?? '') === '/uploads_vare-8.jpg', json_encode($varer[$kopp] ?? null));
    sjekk('Leire: internvaren er «leire», ikke «keramikk»', ($varer[$leire]['leire'] ?? null) === true && ($varer[$leire]['keramikk'] ?? null) === false);
    $filtre = $s[1]['filtre'] ?? [];
    $pos = static fn(string $f): int => (int) array_search($f, $filtre, true);
    sjekk('filtrene i eierens rekkefølge: Lissom kolleksjon før Kopper før Vaser',
        count(array_intersect(['Lissom kolleksjon', 'Kopper', 'Vaser'], $filtre)) === 3
        && $pos('Lissom kolleksjon') < $pos('Kopper') && $pos('Kopper') < $pos('Vaser'), json_encode($filtre, JSON_UNESCAPED_UNICODE));
    $popKort = array_values(array_filter($s[1]['kurs'] ?? [], static fn($k) => $k['oktId'] === $popOkt))[0] ?? null;
    sjekk('Dagens kurs: Paint on Pots med 1 skal betale', $popKort !== null && $popKort['skalBetale'] === 1 && $popKort['alleBetalt'] === false, json_encode($popKort));

    // ══ B: «Har betalt» ═══════════════════════════════════════════════
    echo "\n── B: Har betalt ──\n";
    $s = kall($K . '?okt=' . $popOkt, null, $kasseToken);
    sjekk('kurset: Ola skylder, Sara står under «Har betalt» med «Betalt»', $s[0] === 200
        && array_column($s[1]['rader'] ?? [], 'bookingId') === [$ola] && array_column($s[1]['betalte'] ?? [], 'bookingId') === [$sara]
        && (($s[1]['betalte'][0]['pille']['tekst'] ?? '') === 'Betalt'), $tekst($s));
    $per = kall($K, ['handling' => 'person', 'bookingId' => $sara], $kasseToken);
    $lagret = $per[1]['lagret'] ?? [];
    sjekk('personen: gjenstanden hun valgte står lagret', count($lagret) === 1 && ($lagret[0]['antall'] ?? 0) === 1, $tekst($per));
    $pop = array_map(static fn($l) => ['nivaaId' => $l['nivaaId'], 'gjenstand' => $l['gjenstand'], 'antall' => $l['antall']], $lagret);
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['bookingId' => $sara, 'pop' => $pop], 'betaler' => ['bookingId' => $sara]], $kasseToken);
    $vis = $s[1]['betalt'] ?? null;
    $visTekst = array_column($vis['linjer'] ?? [], 'tekst');
    sjekk('i kurven: bookingen med det hun valgte, «Betalt ved booking» trukket fra, 0 kr igjen og ingenting å betale', $s[0] === 200
        && ($s[1]['deler'] ?? null) === [] && ($s[1]['sumOre'] ?? -1) === 0 && $vis !== null
        && array_sum(array_column($vis['linjer'], 'ore')) === 0 && in_array('Betalt ved booking', $visTekst, true), $tekst($s));
    $pop[0]['antall'] = 2;
    $k = $kjop(['bookingId' => $sara, 'pop' => $pop], ['bookingId' => $sara]);
    sjekk('én gjenstand til fra prislisten: samme booking, bare det ekstra (' . $liten['pris'] . ') skal betales',
        ($k['forventet']['booking:' . $sara] ?? 0) === (int) $liten['prisOre'] && count($k['forventet']) === 1, $tekst($k['regn']));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $saraSalg = $s[1] ?? [];
    sjekk('… betalt kontant: påmeldingen er betalt, og betalingene på den er to ganger nivåprisen', $s[0] === 200
        && (int) Booking::betalingerFor($sara)['sum'] === 2 * (int) $liten['prisOre']
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $sara]) === 'betalt', $tekst($s));

    // ══ C: Gavekort som betalingsmåte ═════════════════════════════════
    echo "\n── C: Gavekort ──\n";
    $kortId = DB::settInn('gift_cards', ['kode' => 'E2E-' . strtoupper(bin2hex(random_bytes(4))), 'opprinnelig_ore' => 100000, 'saldo_ore' => 100000,
        'gyldig_til' => gmdate('Y-m-d', strtotime('+1 year')), 'kjoper_navn' => $tag, 'status' => 'aktivt', 'opprinnelse' => 'kjopt']);
    $kort[] = $kortId;
    $kode = (string) DB::verdi('SELECT kode FROM gift_cards WHERE id = :i', ['i' => $kortId]);
    $k = $kjop(['varer' => [['id' => $kopp, 'antall' => 2]]], []);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Gavekort', 'kode' => strtolower(str_replace('-', ' ', $kode))] + $send($k), $kasseToken);
    sjekk('2 tekopper (580 kr) med gavekort: betalt, kortet har 420 kr igjen', $s[0] === 200
        && (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]) === 42000, $tekst($s));
    $gaveSalg = $s[1] ?? [];
    $forBet = (int) DB::verdi('SELECT COUNT(*) FROM payments');
    $k = $kjop(['varer' => [['id' => $kopp, 'antall' => 2]]], []);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Gavekort', 'kode' => $kode] + $send($k), $kasseToken);
    sjekk('580 kr til, kortet har bare 420 kr: avvist med tydelig melding, ingenting registrert, saldoen urørt', $s[0] === 409
        && $rent((string) ($s[1]['feil'] ?? '')) === 'Gavekortet har bare 420 kr igjen og dekker ikke hele beløpet. Velg en annen betalingsmåte.'
        && (int) DB::verdi('SELECT COUNT(*) FROM payments') === $forBet
        && (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]) === 42000, $tekst($s));
    $k = $kjop(['gavekort' => ['200']], []);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Gavekort', 'kode' => $kode] + $send($k), $kasseToken);
    sjekk('et gavekort kan ikke kjøpes med gavekort', $s[0] === 400 && ($s[1]['feil'] ?? '') === 'Gavekort og timepakke kan ikke betales med gavekort.', $tekst($s));
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Gavekort', 'kode' => 'FINNES-IKKE'] + $send($kjop(['varer' => [['id' => $kopp, 'antall' => 1]]], [])), $kasseToken);
    sjekk('ukjent kode avvises', $s[0] === 400 && str_starts_with((string) ($s[1]['feil'] ?? ''), 'Fant ikke gavekortet.'), $tekst($s));

    // ══ D: Kjøpet med mva ═════════════════════════════════════════════
    echo "\n── D: Kjøpet med mva ──\n";
    $kv = $gaveSalg['kvittering'] ?? [];
    $l0 = $kv['linjer'][0] ?? [];
    sjekk('tekopp × 2: navn, antall 2, 580 kr, «inkl. mva»', $rent((string) ($l0['navn'] ?? '')) === $tag . ' Tekopp' && ($l0['antall'] ?? 0) === 2
        && ($l0['ore'] ?? 0) === 58000 && ($l0['mva'] ?? '') === 'inkl. mva', json_encode($kv, JSON_UNESCAPED_UNICODE));
    sjekk('… totalt 580 kr, mva 116 kr (25 % fra varens mva-innstilling)', ($kv['totalOre'] ?? 0) === 58000 && ($kv['mvaOre'] ?? 0) === 11600, json_encode($kv));
    $k = $kjop(['fritt' => [['belop' => '300', 'slag' => 'kurs']]], []);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $kv = $s[1]['kvittering'] ?? [];
    sjekk('«Skriv beløp» Kurs 300 kr: «uten mva», ingen mva', $s[0] === 200 && ($kv['linjer'][0]['mva'] ?? '') === 'uten mva' && ($kv['mvaOre'] ?? -1) === 0, $tekst($s));
    $kvB = $saraSalg['kvittering'] ?? [];
    sjekk('påmeldingen med en gjenstand til: gjenstandene og «Betalt ved booking» trukket fra, totalt det ekstra',
        ($kvB['totalOre'] ?? 0) === (int) $liten['prisOre'] && in_array('Betalt ved booking', array_column($kvB['linjer'] ?? [], 'navn'), true), json_encode($kvB, JSON_UNESCAPED_UNICODE));

    // ══ E: Unikt stykke ═══════════════════════════════════════════════
    echo "\n── E: Unikt stykke ──\n";
    $iNett = static fn(): bool => in_array($unik, array_column(json_decode((string) file_get_contents('http://127.0.0.1:' . $GLOBALS['port'] . '/api/butikk.php'), true)['varer'] ?? [], 'id'), true);
    sjekk('før salget: unik vase i nettbutikken', $iNett());
    $oppFor = KasseKurv::oppgjor();
    $k = $kjop(['varer' => [['id' => $unik, 'antall' => 1]]], []);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    sjekk('solgt i kassa: lageret 1 → 0, og vasen er borte fra nettbutikken med en gang', $s[0] === 200
        && (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $unik]) === 0 && !$iNett(), $tekst($s));
    $s = kall($K, null, $kasseToken);
    $v = array_column($s[1]['varer'] ?? [], null, 'id')[$unik] ?? null;
    sjekk('… i kassa står den med lager 0 (vises grå med «Solgt»)', $v !== null && $v['lager'] === 0 && $v['keramikk'] === true, json_encode($v));
    $s = kall($K, ['handling' => 'regn', 'kurv' => ['varer' => [['id' => $unik, 'antall' => 1]]], 'betaler' => []], $kasseToken);
    sjekk('… og kan ikke selges en gang til', $s[0] === 400, $tekst($s));

    // ══ F: Dagens oppgjør og «Angre» ══════════════════════════════════
    echo "\n── F: Dagens oppgjør og Angre ──\n";
    $o1 = KasseKurv::oppgjor();
    sjekk('linjene: Vipps, Vipps-nummer, Kontant (i den rekkefølgen)', array_slice(array_column($o1['rader'], 'navn'), 0, 3) === ['Vipps', 'Vipps-nummer', 'Kontant'],
        json_encode(array_column($o1['rader'], 'navn'), JSON_UNESCAPED_UNICODE));
    $k = $kjop(['varer' => [['id' => $kopp, 'antall' => 1]]], []);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Vipps'] + $send($k), $kasseToken);
    $vippsSalg = $s[1] ?? [];
    $o2 = KasseKurv::oppgjor();
    sjekk('Vipps-nummer 290 kr: på egen linje «Vipps-nummer», ikke i «Betalt på annen måte»', $s[0] === 200
        && ($rad($o2, 'Vipps-nummer') ?? 0) - ($rad($o1, 'Vipps-nummer') ?? 0) === 29000
        && ($rad($o2, 'Betalt på annen måte') ?? 0) === ($rad($o1, 'Betalt på annen måte') ?? 0), json_encode($o2['rader'], JSON_UNESCAPED_UNICODE));
    $fakturaFor = $rad($o2, 'Faktura');
    Booking::manuellBetaling($ola, 5000, 'Faktura', null, $monica, 'Testdata');
    $o3 = KasseKurv::oppgjor();
    sjekk('Faktura: egen linje når den har beløp (+50 kr), og totalen øker like mye', ($rad($o3, 'Faktura') ?? 0) - ($fakturaFor ?? 0) === 5000
        && $o3['totalOre'] - $o2['totalOre'] === 5000, json_encode($o3['rader'], JSON_UNESCAPED_UNICODE));
    if ($fakturaFor === null) {
        sjekk('… og var ikke med før den hadde beløp', !in_array('Faktura', array_column($o2['rader'], 'navn'), true));
    }

    $salg = $o3['salg'] ?? [];
    $vase = array_values(array_filter($salg, static fn($x) => $x['sumOre'] === 120000))[0] ?? null;
    sjekk('Dagens salg: vasen med klokkeslett, «Kontantkunde» (tom kunde), 1 200 kr, Kontant og «Angre»', $vase !== null
        && preg_match('/^\d\d:\d\d$/', (string) $vase['kl']) === 1 && $vase['kunde'] === '' && $vase['maate'] === 'Kontant' && $vase['kanAngre'] === true,
        json_encode($salg, JSON_UNESCAPED_UNICODE));
    $kontantFor = $o3['kontantOre'];
    $ordre = DB::en("SELECT o.id FROM orders o JOIN order_lines l ON l.order_id = o.id WHERE l.product_id = :p AND o.id > :f", ['p' => $unik, 'f' => $ordreFor]);
    $s = kall($K, ['handling' => 'angre', 'salgId' => $vase['id']], $kasseToken);
    $pay = DB::alle('SELECT status, refundert_ore, belop_ore, annullert_av, annullert_at FROM payments WHERE order_id = :o', ['o' => (int) $ordre['id']]);
    sjekk('Angre: ordren kansellert (ikke slettet), betalingen refundert med hvem og når', $s[0] === 200 && $ordre !== null
        && DB::verdi('SELECT status FROM orders WHERE id = :i', ['i' => (int) $ordre['id']]) === 'kansellert'
        && count($pay) === 1 && $pay[0]['status'] === 'refundert' && (int) $pay[0]['refundert_ore'] === (int) $pay[0]['belop_ore']
        && (int) $pay[0]['annullert_av'] === $monica && $pay[0]['annullert_at'] !== null, $tekst($s) . ' ' . json_encode($pay));
    sjekk('… vasen tilbake på lager (0 → 1) og i nettbutikken igjen', (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $unik]) === 1 && $iNett());
    $o4 = $s[1]['oppgjor'] ?? [];
    sjekk('… oppgjøret riktig: kontant i kassa −1 200 kr', ($o4['kontantOre'] ?? 0) === $kontantFor - 120000, json_encode($o4['kontantOre'] ?? null));
    $vase2 = array_values(array_filter($o4['salg'] ?? [], static fn($x) => $x['id'] === $vase['id']))[0] ?? null;
    sjekk('… salget står igjen i Dagens salg, angret av Monica med klokkeslett, uten «Angre»', $vase2 !== null && $vase2['kanAngre'] === false
        && ($vase2['angret']['hvem'] ?? '') === $tag . ' Monica' && preg_match('/^\d\d:\d\d$/', (string) ($vase2['angret']['kl'] ?? '')) === 1, json_encode($vase2, JSON_UNESCAPED_UNICODE));
    sjekk('… og i revisjonsloggen (kassesalg_angret)', DB::verdi("SELECT COUNT(*) FROM audit_log WHERE handling = 'kassesalg_angret' AND objekt_id = :i", ['i' => $vase['id']]) == 1);
    $s = kall($K, ['handling' => 'angre', 'salgId' => $vase['id']], $kasseToken);
    sjekk('Angre to ganger: avvist', $s[0] === 409 && ($s[1]['feil'] ?? '') === 'Salget er alt angret.', $tekst($s));

    $saraRad = array_values(array_filter($o4['salg'] ?? [], static fn($x) => $x['kunde'] === $tag . ' Sara'))[0] ?? null;
    sjekk('Sara sitt kjøp står med navnet hennes', $saraRad !== null && $saraRad['kanAngre'] === true, json_encode($o4['salg'] ?? [], JSON_UNESCAPED_UNICODE));
    $s = kall($K, ['handling' => 'angre', 'salgId' => (int) ($saraRad['id'] ?? 0)], $kasseToken);
    sjekk('Angre påmeldingen: betalingen annullert, påmeldingen skylder det ekstra igjen', $s[0] === 200
        && (int) Booking::betalingerFor($sara)['sum'] === (int) $liten['prisOre']
        && KursstartKrav::skyldig($sara, (int) DB::verdi('SELECT belop_ore FROM bookings WHERE id = :i', ['i' => $sara]), (string) DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $sara])) === (int) $liten['prisOre'],
        $tekst($s));
    $gave = array_values(array_filter($o4['salg'] ?? [], static fn($x) => $x['maate'] === 'Gavekort'))[0] ?? null;
    $s = kall($K, ['handling' => 'angre', 'salgId' => (int) ($gave['id'] ?? 0)], $kasseToken);
    sjekk('Angre et salg betalt med gavekort: beløpet tilbake på kortet (420 → 1 000 kr)', $s[0] === 200
        && (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]) === 100000, $tekst($s));

    // ══ G: SMS-kvitteringen ═══════════════════════════════════════════
    echo "\n── G: SMS-kvitteringen ──\n";
    $mal = (string) DB::verdi("SELECT tekst FROM notification_templates WHERE navn = 'kassekvittering_sms'");
    sjekk('migrasjon 282: ny tekst med {varer}', $mal === "Takk for handelen hos Lissom!\n{varer}\nBetalt {belop} med {maate} {dato}.\nHilsen oss i Lissom", json_encode($mal, JSON_UNESCAPED_UNICODE));
    foreach (['sveve_bruker' => 'kassetest', 'sveve_passord' => 'kassetest', 'sms_leverandor' => 'sveve'] as $n => $v) {
        DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)', ['n' => $n, 'v' => $v]);
    }
    $smsFoer = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'kassekvittering_sms'");
    $k = $kjop(['varer' => [['id' => $kopp, 'antall' => 2], ['id' => $lissomKol, 'antall' => 1]]], []);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $valg = $s[1]['kvitteringValg'] ?? [];
    sjekk('betalt: ingen SMS sendt av seg selv; «Send kvittering» kan velges for kontantkunden, telefonfeltet tomt', $s[0] === 200
        && (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'kassekvittering_sms'") === $smsFoer
        && ($valg['smsMulig'] ?? false) === true && ($valg['telefon'] ?? null) === '', $tekst($s));
    $bet = $valg['betalinger'] ?? [];
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'sms', 'telefon' => '911 22 333', 'betalinger' => array_merge($bet, $vippsSalg['betalinger'] ?? []), 'betaler' => []], $kasseToken);
    sjekk('kontantkunden kan ikke få kvittering for mer enn sitt eget salg', $s[0] === 409 && ($s[1]['feil'] ?? '') === 'Kvitteringen hører ikke til denne kunden.', $tekst($s));
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'sms', 'telefon' => '911 22 333', 'betalinger' => $bet, 'betaler' => []], $kasseToken);
    $sms = DB::en("SELECT mottaker, tekst FROM notifications WHERE mal = 'kassekvittering_sms' ORDER BY id DESC LIMIT 1");
    $d = new DateTimeImmutable('now', $oslo);
    $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];
    $ventet = "Takk for handelen hos Lissom!\n" . $tag . " Tekopp × 2 580 kr\n" . $tag . " Skål 450 kr\nBetalt 1 030 kr med kontant "
        . (int) $d->format('j') . '. ' . $mnd[(int) $d->format('n') - 1] . ".\nHilsen oss i Lissom";
    sjekk('Send: SMS i kø til nummeret i feltet, én vare per linje, beløp, måte og dato', $s[0] === 200 && $sms !== null
        && normaliser_telefon((string) $sms['mottaker']) === '+4791122333' && $rent(trim((string) $sms['tekst'])) === $ventet,
        $tekst($s) . ' ' . json_encode($sms, JSON_UNESCAPED_UNICODE));
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'sms', 'telefon' => '911 22 333', 'betalinger' => $bet, 'betaler' => []], $kasseToken);
    sjekk('to SMS-er for samme kjøp: avvist', $s[0] === 409 && ($s[1]['feil'] ?? '') === 'Kvittering på SMS er alt sendt for dette kjøpet.', $tekst($s));
    // Kjent kunde: telefonen fylles inn, og navnet står ikke i SMS-en.
    $ola2 = DB::settInn('bookings', ['course_id' => $popKurs, 'course_session_id' => $popOkt, 'gjest_navn' => $tag . ' Kari Nordmann',
        'gjest_telefon' => '+4799887722', 'antall' => 1, 'belop_ore' => 30000, 'status' => 'reservert', 'betalt_maate' => 'Ikke betalt']);
    $k = $kjop(['bookingId' => $ola2], ['bookingId' => $ola2]);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Kontant'] + $send($k), $kasseToken);
    $valg = $s[1]['kvitteringValg'] ?? [];
    sjekk('kjent kunde: telefonen er fylt inn', $s[0] === 200 && ($valg['telefon'] ?? '') === '+4799887722', $tekst($s));
    $s = kall($K, ['handling' => 'kvittering', 'kanal' => 'sms', 'telefon' => $valg['telefon'] ?? '', 'betalinger' => $valg['betalinger'] ?? [], 'betaler' => ['bookingId' => $ola2]], $kasseToken);
    $sms = DB::en("SELECT tekst FROM notifications WHERE mal = 'kassekvittering_sms' ORDER BY id DESC LIMIT 1");
    sjekk('… SMS-en har ikke kundens navn', $s[0] === 200 && $sms !== null && !str_contains((string) $sms['tekst'], 'Kari') && !str_contains((string) $sms['tekst'], 'Nordmann'),
        $tekst($s) . ' ' . json_encode($sms, JSON_UNESCAPED_UNICODE));

    // ══ H: Gavekort, alt eller ingenting ══════════════════════════════
    echo "\n── H: Gavekort, alt eller ingenting ──\n";
    $kortH = DB::settInn('gift_cards', ['kode' => 'E2E-' . strtoupper(bin2hex(random_bytes(4))), 'opprinnelig_ore' => 100000, 'saldo_ore' => 100000,
        'gyldig_til' => gmdate('Y-m-d', strtotime('+1 year')), 'kjoper_navn' => $tag, 'status' => 'aktivt', 'opprinnelse' => 'kjopt']);
    $kort[] = $kortH;
    $kodeH = (string) DB::verdi('SELECT kode FROM gift_cards WHERE id = :i', ['i' => $kortH]);
    $saldoH = static fn(): int => (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortH]);
    $per = DB::settInn('bookings', ['course_id' => $popKurs, 'course_session_id' => $popOkt, 'gjest_navn' => $tag . ' Per',
        'antall' => 1, 'belop_ore' => 30000, 'status' => 'reservert', 'betalt_maate' => 'Ikke betalt']);
    // En åpen betaling (nettsiden) binder 500 kr av kortet: 500 kr er ledig.
    $bundet = DB::settInn('payments', ['vipps_reference' => 'TEST-' . $tag . '-BUNDET', 'type' => 'manuell', 'formal' => 'ordre',
        'maate' => 'Gavekort', 'belop_ore' => 0, 'gavekort_id' => $kortH, 'gavekort_ore' => 50000, 'status' => 'opprettet', 'registrert_av' => $monica, 'idempotency_key' => Vipps::uuid()]);
    $lagerFor = (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $kopp]);
    $k = $kjop(['bookingId' => $per, 'varer' => [['id' => $kopp, 'antall' => 1]]], ['bookingId' => $per]);
    sjekk('kurven: påmeldingen (300 kr) og en tekopp (290 kr) som to deler', array_values($k['forventet']) === [30000, 29000], $tekst($k['regn']));
    $forBet = (int) DB::verdi('SELECT COUNT(*) FROM payments');
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Gavekort', 'kode' => $kodeH] + $send($k), $kasseToken);
    sjekk('590 kr med gavekort når bare 500 kr er ledig: hele kurven avvist samlet, ingen del betalt, saldoen og lageret urørt', $s[0] === 409
        && ($s[1]['feil'] ?? '') === Booking::GAVEKORT_AVVIST
        && (int) DB::verdi('SELECT COUNT(*) FROM payments') === $forBet && $saldoH() === 100000
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $per]) === 'reservert'
        && (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $kopp]) === $lagerFor, $tekst($s));
    DB::kjor('DELETE FROM payments WHERE id = :i', ['i' => $bundet]);
    $k = $kjop(['bookingId' => $per, 'varer' => [['id' => $kopp, 'antall' => 1]]], ['bookingId' => $per]);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Gavekort', 'kode' => $kodeH] + $send($k), $kasseToken);
    $gaveRader = DB::alle("SELECT gavekort_ore FROM payments WHERE gavekort_id = :k AND status = 'betalt' ORDER BY id", ['k' => $kortH]);
    sjekk('… når kortet er ledig: begge delene betalt i ett, kortet 1 000 → 410 kr, påmeldingen betalt, tekoppen ut av lageret', $s[0] === 200
        && $saldoH() === 41000 && array_map('intval', array_column($gaveRader, 'gavekort_ore')) === [30000, 29000]
        && DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $per]) === 'betalt'
        && (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $kopp]) === $lagerFor - 1, $tekst($s) . ' ' . json_encode($gaveRader));

    // «Angre» på en påmelding betalt med gavekort: stopper krediteringen av
    // kortet, skal annulleringen også rulles tilbake (alt eller ingenting).
    $per2 = DB::settInn('bookings', ['course_id' => $popKurs, 'course_session_id' => $popOkt, 'gjest_navn' => $tag . ' Per To',
        'antall' => 1, 'belop_ore' => 10000, 'status' => 'reservert', 'betalt_maate' => 'Ikke betalt']);
    $k = $kjop(['bookingId' => $per2], ['bookingId' => $per2]);
    $s = kall($K, ['handling' => 'betal', 'maate' => 'Gavekort', 'kode' => $kodeH] + $send($k), $kasseToken);
    $salgH = (int) DB::verdi("SELECT MAX(id) FROM audit_log WHERE handling = 'kassesalg_ipad'");
    $betH = (int) DB::verdi("SELECT id FROM payments WHERE booking_id = :b AND maate = 'Gavekort'", ['b' => $per2]);
    sjekk('påmeldingen 100 kr betalt med gavekort: kortet 410 → 310 kr', $s[0] === 200 && $betH > 0 && $saldoH() === 31000, $tekst($s));
    DB::kjor("CREATE TRIGGER `$trigger` BEFORE UPDATE ON gift_cards FOR EACH ROW BEGIN IF NEW.id = $kortH AND NEW.saldo_ore > OLD.saldo_ore THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Syntetisk feil i krediteringen'; END IF; END");
    $s = kall($K, ['handling' => 'angre', 'salgId' => $salgH], $kasseToken);
    DB::kjor("DROP TRIGGER `$trigger`");
    $bet = DB::en('SELECT status, annullert_at FROM payments WHERE id = :i', ['i' => $betH]);
    sjekk('Angre der krediteringen av kortet feiler: ingenting endret, betalingen står betalt, kortet 310 kr, ikke merket angret', $s[0] !== 200
        && $bet['status'] === 'betalt' && $bet['annullert_at'] === null && $saldoH() === 31000
        && DB::verdi("SELECT COUNT(*) FROM audit_log WHERE handling = 'kassesalg_angret' AND objekt_id = :i", ['i' => $salgH]) == 0,
        $tekst($s) . ' ' . json_encode($bet));
    $s = kall($K, ['handling' => 'angre', 'salgId' => $salgH], $kasseToken);
    $bet = DB::en('SELECT status, annullert_at FROM payments WHERE id = :i', ['i' => $betH]);
    sjekk('… nytt «Angre» virker: betalingen annullert og kortet tilbake 310 → 410 kr', $s[0] === 200
        && $bet['status'] === 'avbrutt' && $bet['annullert_at'] !== null && $saldoH() === 41000, $tekst($s) . ' ' . json_encode($bet));

    // ══ Skjermen: tekstene som er godkjent ordrett ════════════════════
    echo "\n── Skjermen ──\n";
    $js = (string) file_get_contents($rot . '/admin-ny/kasse.js');
    foreach (["'Haha, din perverse fjert! Dette er notert og delt på NRK nyheter'", "'Ingen nett – skriv salget på papir og legg det inn senere.'",
              '`Har du sett at ${sum} er kommet inn på Vipps?`', "pille('Ja, registrer'", '`Angre salget på ${x.sum}?`', "pille('Ja, angre'",
              "text:'Send kvittering'", "text:'Har betalt'", "text:'LAGT TIL'", "text:'Dagens oppgjør'", "text:'Alle har betalt'",
              "['pop','Paint on Pots'],['keramikk','Keramikk'],['gavekort','Gavekort'],['leire','Leire']", "text:'KUNDE'", "text:'KJØPET'"] as $t) {
        sjekk('kasse.js: ' . $t, str_contains($js, $t));
    }
    sjekk('kasse.js: «Flere valg», «Del betalingen» og Vipps-QR vises ikke', !str_contains($js, "text:'Flere valg'") && !str_contains($js, "'Del betalingen'")
        && !str_contains($js, "handling:'qr'"));

    $ferdig = true;
} catch (Throwable $e) {
    echo "  FEIL  unntak: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    $feil++;
    $ferdig = true;
}

if (is_file($logg) && preg_match('/(Fatal error|Uncaught)/', (string) file_get_contents($logg))) {
    echo "  FEIL  serverloggen har feil:\n" . substr((string) file_get_contents($logg), 0, 3000) . "\n";
    $feil++;
}
echo "\n" . ($feil === 0 ? "$ok av $ok kontroller bestått (ny kasse)\n" : "$feil av " . ($ok + $feil) . " kontroller FEILET (ny kasse)\n");
exit($feil === 0 ? 0 : 1);
