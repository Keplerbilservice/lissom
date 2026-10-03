<?php
/**
 * Vipps-krav fra «Start kurset» (kalenderplanen, bølge 2) — scenarioer mot den
 * falske Vippsen. Ingen ekte Vipps, e-post eller SMS.
 *
 *   (a) kravet opprettes én gang, med riktig beløp, nummer, tekst og nøkkel
 *   (b) dobbeltklikk — to samtidige forespørsler — gir ett krav
 *   (c) nytt forsøk etter tapt svar bruker samme referanse og samme nøkkel
 *   (d) betalt via webhooken: trekket tas én gang, påmeldingen blir betalt,
 *       ingen ny bekreftelse sendes, og en gjentatt webhook trekker ikke igjen
 *   (e) kontant mens kravet venter: kravet avbrytes først, og en sen
 *       godkjenning trekker ikke
 *   (f) kontant mens kravet venter, men kunden rakk å betale: kontanten nektes
 *   (g) plassen gjort opp en annen vei mens kravet ventet: godkjenningen
 *       slippes i stedet for å trekkes (ingen dobbel betaling)
 *   (h) 400 fra Vipps (salgsenheten har ikke lov): tydelig feil, ingen rad
 *       igjen, og kontant virker
 *   (i) mangler mobilnummer, og delvis betalt: kravet gjelder det som står igjen
 *
 * Kjøres av tests/kursstart-krav.mjs via tests/nettleser/kjor.sh (falsk Vipps
 * og testserver på). Alt merkes «KsKravTest-» og ryddes etterpå.
 */
declare(strict_types=1);
require __DIR__ . '/nettleser/testdatabase.php';
krev_testdatabase(dirname(__DIR__));
require dirname(__DIR__) . '/app/bootstrap.php';
if (!str_starts_with((string) Config::hent('vipps_base'), 'http://127.0.0.1:')) {
    throw new RuntimeException('Krever lokal falsk Vipps');
}

// Barneprosessen i (p): holder låsen per påmelding i noen sekunder.
if (($argv[1] ?? '') === 'hold') {
    KursstartKrav::laas((int) $argv[2]);
    echo "låst
";
    fflush(STDOUT);
    sleep((int) $argv[3]);
    KursstartKrav::slipp((int) $argv[2]);
    exit;
}

// Barneprosessen i (b): sender ett krav og skriver svaret.
if (($argv[1] ?? '') === 'send') {
    try {
        echo json_encode(KursstartKrav::send((int) $argv[2]), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['feil' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$styr = static fn(string $navn): string => __DIR__ . '/' . $navn;
$forStyr = [];
foreach (['.betaling-status', '.krav-400', '.avbryt-nei'] as $n) {
    $forStyr[$n] = is_file($styr($n)) ? (string) file_get_contents($styr($n)) : null;
}
$sett = static function (string $navn, ?string $verdi) use ($styr): void {
    if ($verdi === null) { @unlink($styr($navn)); } else { file_put_contents($styr($navn), $verdi); }
};

$tag = 'KsKravTest-' . bin2hex(random_bytes(3));
$ferdig = false;
$kurs = 0; $okt = 0; $bIder = []; $admin = 0; $gave = 0;
$bryterFor = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/kursstart3'");
$bryterFor = $bryterFor === null || $bryterFor === false ? null : (string) $bryterFor;
register_shutdown_function(static function () use (&$ferdig, &$kurs, &$okt, &$bIder, &$admin, &$gave, $forStyr, $sett, $bryterFor): void {
    foreach ($forStyr as $n => $v) { $sett($n, $v); }
    try {
        if ($bryterFor === null) { DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/kursstart3'"); }
        else { DB::kjor("UPDATE content_blocks SET verdi = :v WHERE nokkel = 'Vis/kursstart3'", ['v' => $bryterFor]); }
    } catch (Throwable $e) {}
    $inn = implode(',', array_map('intval', $bIder ?: [0]));
    foreach ([
        "DELETE FROM vipps_webhook_events WHERE referanse IN (SELECT vipps_reference FROM payments WHERE booking_id IN ({$inn}))",
        "DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id IN ({$inn})",
        "DELETE FROM audit_log WHERE objekt_type = 'booking' AND objekt_id IN ({$inn})",
        "UPDATE bookings SET payment_id = NULL WHERE id IN ({$inn})",
        "DELETE FROM payments WHERE booking_id IN ({$inn})",
        "DELETE FROM bookings WHERE id IN ({$inn})",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    }
    try { DB::kjor('DELETE FROM course_sessions WHERE id = :i', ['i' => $okt]); } catch (Throwable $e) {}
    try { DB::kjor('DELETE FROM courses WHERE id = :i', ['i' => $kurs]); } catch (Throwable $e) {}
    try { DB::kjor('DELETE FROM gift_card_uses WHERE gift_card_id = :g', ['g' => $gave]); } catch (Throwable $e) {}
    try { DB::kjor('DELETE FROM gift_cards WHERE id = :g', ['g' => $gave]); } catch (Throwable $e) {}
    try { DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => $admin]); DB::kjor('DELETE FROM audit_log WHERE member_id = :m', ['m' => $admin]); DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $admin]); } catch (Throwable $e) {}
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

$logg = __DIR__ . '/.falsk-vipps.jsonl';
$lengde = static fn(): int => is_file($logg) ? count(file($logg)) : 0;
/** Kallene til den falske Vippsen siden $fra som gjelder referansen. */
$kall = static function (int $fra, string $ref, string $hva) use ($logg): array {
    if (!is_file($logg)) { return []; }
    $ut = [];
    foreach (array_slice(file($logg), $fra) as $l) {
        $k = json_decode($l, true);
        $sti = (string) ($k['sti'] ?? '');
        $treff = match ($hva) {
            'opprett' => ($k['metode'] ?? '') === 'POST' && $sti === '/epayment/v1/payments' && ($k['kropp']['reference'] ?? '') === $ref,
            'capture' => ($k['metode'] ?? '') === 'POST' && $sti === '/epayment/v1/payments/' . $ref . '/capture',
            'cancel'  => ($k['metode'] ?? '') === 'POST' && $sti === '/epayment/v1/payments/' . $ref . '/cancel',
            default   => false,
        };
        if ($treff) { $ut[] = $k; }
    }
    return $ut;
};

$oslo = new DateTimeZone('Europe/Oslo');
$start = (new DateTimeImmutable('today 18:00', $oslo))->modify('+12 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$kurs = DB::settInn('courses', ['slug' => strtolower($tag), 'tittel' => "$tag Dreiekurs", 'type' => 'kurs', 'pris_ore' => 50000, 'kapasitet' => 12, 'status' => 'publisert']);
$okt = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $start, 'kapasitet' => 12]);
$b = static function (string $navn, ?string $tlf, int $belop = 50000, string $status = 'reservert') use ($kurs, $okt, $tag, &$bIder): int {
    $id = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $okt, 'gjest_navn' => "$navn $tag",
        'gjest_epost' => strtolower($navn) . '.' . $tag . '@e2e.lissom.test', 'gjest_telefon' => $tlf,
        'antall' => 1, 'belop_ore' => $belop, 'status' => $status]);
    $bIder[] = $id;
    return $id;
};
$krav = static fn(int $booking): array => DB::alle(
    "SELECT * FROM payments WHERE booking_id = :b AND vipps_reference LIKE 'KS-%' ORDER BY id", ['b' => $booking]);
$bStatus = static fn(int $id): string => (string) DB::verdi('SELECT status FROM bookings WHERE id = :i', ['i' => $id]);
$sum = static fn(int $id): int => Booking::betalingerFor($id)['sum'];
$kontant = static function (int $booking): ?string {
    // Samme rekkefølge som kursbetaling.php handling=registrer.
    $stopp = KursstartKrav::stoppVentende($booking);
    if ($stopp !== null) { return $stopp; }
    $b = DB::en('SELECT belop_ore, status FROM bookings WHERE id = :i', ['i' => $booking]);
    $rest = KursstartKrav::skyldig($booking, (int) $b['belop_ore'], (string) $b['status']);
    DB::iTransaksjon(static fn() => Booking::manuellBetaling($booking, $rest, 'Kontant'));
    Booking::settBetaltStatus($booking);
    return null;
};

// Webhooken, signert som Vipps gjør det, mot testserveren.
$adresse = (string) (getenv('E2E_ADRESSE') ?: '');
$hemmelighet = (string) Config::hent('vipps_webhook_secret', '');
$webhook = static function (string $ref, string $navn, string $eventId) use ($adresse, $hemmelighet): int {
    $u = parse_url($adresse);
    $vert = $u['host'] . ':' . $u['port'];
    $kropp = json_encode(['msn' => '123456', 'reference' => $ref, 'name' => $navn, 'eventId' => $eventId,
        'pspReference' => 'psp-' . $eventId, 'amount' => ['currency' => 'NOK', 'value' => 0], 'success' => true]);
    $hash = base64_encode(hash('sha256', $kropp, true));
    $dato = gmdate('D, d M Y H:i:s') . ' GMT';
    $sig = base64_encode(hash_hmac('sha256', "POST\n/api/vipps-webhook.php\n{$dato};{$vert};{$hash}", $hemmelighet, true));
    $c = curl_init($adresse . '/api/vipps-webhook.php');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $kropp, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_RESOLVE => [$vert . ':127.0.0.1'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-ms-date: ' . $dato, 'x-ms-content-sha256: ' . $hash,
            'Authorization: HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=' . $sig]]);
    curl_exec($c);
    $s = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return $s;
};
if ($adresse === '' || $hemmelighet === '') {
    throw new RuntimeException('Krever E2E_ADRESSE og vipps_webhook_secret (kjør via tests/nettleser/kjor.sh)');
}

$sett('.krav-400', null);
$sett('.betaling-status', 'CREATED');

// ── (a) ett krav ────────────────────────────────────────────────────────
echo "\n── (a) kravet opprettes én gang ─────────────────────────────\n";
$A = $b('Anna', '912 34 567');
$fra = $lengde();
$r = KursstartKrav::send($A);
$k = $krav($A);
$ref = (string) ($k[0]['vipps_reference'] ?? '');
$opprett = $kall($fra, $ref, 'opprett');
sjekk('én KS-rad, venter, 500 kr, knyttet til påmeldingen', count($k) === 1 && $k[0]['status'] === 'venter'
    && (int) $k[0]['belop_ore'] === 50000 && $k[0]['type'] === 'epayment' && $k[0]['formal'] === 'booking', json_encode($k));
sjekk('ett opprettelseskall til Vipps', count($opprett) === 1);
$kropp = $opprett[0]['kropp'] ?? [];
sjekk('PUSH_MESSAGE til 4791234567, uten returnUrl', ($kropp['userFlow'] ?? '') === 'PUSH_MESSAGE'
    && ($kropp['customer']['phoneNumber'] ?? '') === '4791234567' && !isset($kropp['returnUrl']), json_encode($kropp));
sjekk('beløpet 50000 øre i NOK', ($kropp['amount']['value'] ?? 0) === 50000 && ($kropp['amount']['currency'] ?? '') === 'NOK');
sjekk('kundeteksten «Kurs — Lissom Keramikk · {kurs} {dato}»',
    str_starts_with((string) ($kropp['paymentDescription'] ?? ''), 'Kurs — Lissom Keramikk · ' . $tag . ' Dreiekurs ')
    && preg_match('/ \d{1,2}\. [a-zø]+ \d{4}$/u', (string) ($kropp['paymentDescription'] ?? '')) === 1, (string) ($kropp['paymentDescription'] ?? ''));
sjekk('idempotensnøkkelen er den på raden', ($opprett[0]['nokkel'] ?? '') === $k[0]['idempotency_key']);
sjekk('svaret sier sendt', $r['ny'] === true && str_contains($r['beskjed'], 'er sendt til'), $r['beskjed']);
sjekk('påmeldingen står fortsatt ubetalt', $bStatus($A) === 'reservert');

// ── (b) dobbeltklikk ────────────────────────────────────────────────────
echo "\n── (b) dobbeltklikk gir ett krav ────────────────────────────\n";
$fra = $lengde();
$r2 = KursstartKrav::send($A);
sjekk('andre trykk: ingen ny opprettelse, «alt sendt»', count($kall($fra, $ref, 'opprett')) === 0 && $r2['ny'] === false
    && str_contains($r2['beskjed'], 'alt sendt'), $r2['beskjed']);
sjekk('fortsatt én KS-rad', count($krav($A)) === 1);
$B = $b('Berit', '+47 913 00 000');
$fra = $lengde();
$php = PHP_BINARY;
$prosesser = [];
foreach ([1, 2] as $_) {
    $prosesser[] = proc_open([$php, __FILE__, 'send', (string) $B], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ror);
    $pipes[] = $ror;
}
$svar = [];
foreach ($prosesser as $i => $p) {
    $svar[] = json_decode((string) stream_get_contents($pipes[$i][1]), true);
    proc_close($p);
}
$kB = $krav($B);
$refB = (string) ($kB[0]['vipps_reference'] ?? '');
sjekk('to samtidige forespørsler: én KS-rad', count($kB) === 1, json_encode($svar, JSON_UNESCAPED_UNICODE));
sjekk('… og ett opprettelseskall', count($kall($fra, $refB, 'opprett')) === 1);
sjekk('… det ene svaret sier sendt, det andre «alt sendt»', count(array_filter($svar, static fn($s) => ($s['ny'] ?? null) === true)) === 1
    && count(array_filter($svar, static fn($s) => ($s['ny'] ?? null) === false)) === 1, json_encode($svar, JSON_UNESCAPED_UNICODE));

// ── (c) nytt forsøk etter tapt svar ─────────────────────────────────────
echo "\n── (c) samme nøkkel ved nytt forsøk ─────────────────────────\n";
$C0 = $b('Cecilie', '91300001');
$refC0 = Vipps::nyReferanse('KS');
$nokkel = Vipps::uuid();
DB::settInn('payments', ['vipps_reference' => $refC0, 'type' => 'epayment', 'formal' => 'booking', 'booking_id' => $C0,
    'belop_ore' => 50000, 'status' => 'opprettet', 'idempotency_key' => $nokkel]);
$fra = $lengde();
KursstartKrav::send($C0);
$o = $kall($fra, $refC0, 'opprett');
sjekk('sendt på nytt med samme referanse og nøkkel', count($o) === 1 && ($o[0]['nokkel'] ?? '') === $nokkel);
sjekk('ingen ny rad; raden venter', count($krav($C0)) === 1 && $krav($C0)[0]['status'] === 'venter');

// ── (d) betalt via webhook ──────────────────────────────────────────────
echo "\n── (d) betalt via webhooken ─────────────────────────────────\n";
$sett('.betaling-status', 'AUTHORIZED');
$fra = $lengde();
$eid = 'ks-' . bin2hex(random_bytes(6));
$h = $webhook($ref, 'AUTHORIZED', $eid);
sjekk('webhooken svarer 200', $h === 200, (string) $h);
$cap = $kall($fra, $ref, 'capture');
sjekk('ett trekk på 50000 øre', count($cap) === 1 && ($cap[0]['kropp']['modificationAmount']['value'] ?? 0) === 50000);
sjekk('kravet er betalt', (string) DB::verdi('SELECT status FROM payments WHERE vipps_reference = :r', ['r' => $ref]) === 'betalt');
sjekk('påmeldingen er betalt (markerBetalt)', $bStatus($A) === 'betalt');
sjekk('betalt sum = 500 kr, ikke mer', $sum($A) === 50000, (string) $sum($A));
sjekk('ingen ny bekreftelse til kunden eller verkstedet', (int) DB::verdi(
    "SELECT COUNT(*) FROM notifications WHERE ref_type = 'booking' AND ref_id = :b", ['b' => $A]) === 0);
$fra = $lengde();
$webhook($ref, 'AUTHORIZED', $eid);
sjekk('samme webhook igjen: ingen nytt trekk', count($kall($fra, $ref, 'capture')) === 0);
$feilTekst = static function (callable $f): string {
    try { $f(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); }
};
$t = $feilTekst(static fn() => KursstartKrav::send($A));
sjekk('«Send Vipps-krav» etter betaling: nektet, ingen nytt krav', str_contains($t, 'alt gjort opp') && count($krav($A)) === 1, $t);

// ── (e) kontant mens kravet venter ──────────────────────────────────────
echo "\n── (e) kontant mens kravet venter: kravet avbrytes ──────────\n";
$sett('.betaling-status', 'CREATED');
$E = $b('Eva', '91300002');
KursstartKrav::send($E);
$refE = (string) $krav($E)[0]['vipps_reference'];
$fra = $lengde();
$stopp = $kontant($E);
$avb = $kall($fra, $refE, 'cancel');
sjekk('kontant registrert', $stopp === null && $bStatus($E) === 'betalt', (string) $stopp);
sjekk('kravet ble avbrutt hos Vipps med cancelTransactionOnly', count($avb) === 1 && ($avb[0]['kropp']['cancelTransactionOnly'] ?? null) === true);
sjekk('kravet står avbrutt', $krav($E)[0]['status'] === 'avbrutt');
$sett('.betaling-status', 'AUTHORIZED');
$fra = $lengde();
$webhook($refE, 'AUTHORIZED', 'ks-' . bin2hex(random_bytes(6)));
sjekk('sen godkjenning: ingen trekk', count($kall($fra, $refE, 'capture')) === 0);
sjekk('betalt sum = 500 kr (bare kontanten)', $sum($E) === 50000 && $krav($E)[0]['status'] === 'avbrutt', (string) $sum($E));

// ── (f) kunden rakk å betale ────────────────────────────────────────────
echo "\n── (f) kontant nektes når kunden alt har betalt ─────────────\n";
$sett('.betaling-status', 'CREATED');
$F = $b('Frida', '91300003');
KursstartKrav::send($F);
$refF = (string) $krav($F)[0]['vipps_reference'];
$sett('.betaling-status', 'AUTHORIZED');
$fra = $lengde();
$stopp = $kontant($F);
sjekk('kontanten nektes med tydelig beskjed', $stopp !== null && str_contains($stopp, 'alt betalt'), (string) $stopp);
sjekk('Vipps-betalingen er trukket og påmeldingen betalt', count($kall($fra, $refF, 'capture')) === 1 && $bStatus($F) === 'betalt');
sjekk('ingen kontantrad; betalt sum = 500 kr', $sum($F) === 50000 && (int) DB::verdi(
    "SELECT COUNT(*) FROM payments WHERE booking_id = :b AND type = 'manuell'", ['b' => $F]) === 0);

// ── (g) gjort opp en annen vei mens kravet ventet ───────────────────────
echo "\n── (g) godkjenning etter at plassen er gjort opp: slippes ──\n";
// B har et krav som venter (fra b). Kontant føres uten sperra (som en annen vei inn).
DB::iTransaksjon(static fn() => Booking::manuellBetaling($B, 50000, 'Kontant'));
Booking::settBetaltStatus($B);
$fra = $lengde();
$webhook($refB, 'AUTHORIZED', 'ks-' . bin2hex(random_bytes(6)));
sjekk('ingen trekk', count($kall($fra, $refB, 'capture')) === 0);
sjekk('reservasjonen slippes hos Vipps', count($kall($fra, $refB, 'cancel')) === 1);
sjekk('kravet står avbrutt; betalt sum = 500 kr', $krav($B)[0]['status'] === 'avbrutt' && $sum($B) === 50000);

// ── (h) 400 fra Vipps ───────────────────────────────────────────────────
echo "\n── (h) 400 fra Vipps: feilmelding, og kontant virker ────────\n";
$sett('.krav-400', 'ja');
$H = $b('Hilde', '91300004');
$t = $feilTekst(static fn() => KursstartKrav::send($H));
sjekk('feilmeldingen sier hva som skjedde og hva du gjør', str_contains($t, 'Fikk ikke sendt Vipps-kravet')
    && str_contains($t, 'Salgsenheten har ikke lov') && str_contains($t, 'kontant'), $t);
sjekk('… bare norsk: ikke Vipps sin engelske tekst eller MSN', !str_contains($t, 'ErrorCode') && !str_contains($t, 'MSN') && !str_contains($t, 'sales unit'), $t);
sjekk('ingen KS-rad igjen', count($krav($H)) === 0);
$stopp = $kontant($H);
sjekk('kontant virker etterpå', $stopp === null && $bStatus($H) === 'betalt' && $sum($H) === 50000, (string) $stopp);
$sett('.krav-400', null);

// ── (i) uten nummer, og delvis betalt ───────────────────────────────────
echo "\n── (i) uten mobilnummer, og delvis betalt ───────────────────\n";
$I = $b('Ida', null);
$t = $feilTekst(static fn() => KursstartKrav::send($I));
sjekk('uten nummer: nektet, ingen rad', str_contains($t, 'Mangler mobilnummer') && count($krav($I)) === 0, $t);
$sett('.betaling-status', 'CREATED');
$J = $b('Jorunn', '91300005');
DB::iTransaksjon(static fn() => Booking::manuellBetaling($J, 20000, 'Kontant'));
Booking::settBetaltStatus($J);
$fra = $lengde();
KursstartKrav::send($J);
$refJ = (string) $krav($J)[0]['vipps_reference'];
sjekk('kravet gjelder resten: 300 kr', (int) $krav($J)[0]['belop_ore'] === 30000
    && ($kall($fra, $refJ, 'opprett')[0]['kropp']['amount']['value'] ?? 0) === 30000);
$sett('.betaling-status', 'AUTHORIZED');
$webhook($refJ, 'AUTHORIZED', 'ks-' . bin2hex(random_bytes(6)));
sjekk('etter betaling: 200 + 300 = 500 kr, påmeldingen betalt', $sum($J) === 50000 && $bStatus($J) === 'betalt', (string) $sum($J));
$lang = KursstartKrav::beskrivelse(str_repeat('Veldig langt kursnavn ', 8), $start);
sjekk('kundeteksten holder seg innenfor 100 tegn og beholder datoen', mb_strlen($lang) <= 100 && preg_match('/ \d{1,2}\. [a-zø]+ \d{4}$/u', $lang) === 1, $lang);
sjekk('mobilnummer: 8 sifre, +47, 0047 og ugyldig', KursstartKrav::telefon('912 34 567') === '4791234567'
    && KursstartKrav::telefon('+47 912 34 567') === '4791234567' && KursstartKrav::telefon('0047 91234567') === '4791234567'
    && KursstartKrav::telefon('123') === null);

// ── Kontrolløren 3. oktober 2026 (STOPP på 9a9d2ea) og brukertesten ─────
$eid = static fn(): string => 'ks-' . bin2hex(random_bytes(6));
$kravRad = static fn(int $booking): array => $krav($booking)[0] ?? [];

// ── (j) pengene er trukket, men plassen var gjort opp: bokføres, aldri slippes
echo "\n── (j) trukket etter at plassen var gjort opp: bokføres ─────\n";
$sett('.betaling-status', 'CREATED');
$K = $b('Kari', '91300006');
KursstartKrav::send($K);
$refK = (string) $kravRad($K)['vipps_reference'];
Vipps::trekk($refK, 50000, 0);   // trukket hos Vipps, svaret kom aldri fram til oss
DB::iTransaksjon(static fn() => Booking::manuellBetaling($K, 50000, 'Kontant'));
Booking::settBetaltStatus($K);
$sett('.betaling-status', 'AUTHORIZED');
$fra = $lengde();
$h = $webhook($refK, 'AUTHORIZED', $eid());
sjekk('webhooken svarer 200', $h === 200, (string) $h);
sjekk('ingen nytt trekk, og ingenting «sluppet»', count($kall($fra, $refK, 'capture')) === 0 && count($kall($fra, $refK, 'cancel')) === 0);
sjekk('kravet er bokført som betalt (pengene er tatt)', $kravRad($K)['status'] === 'betalt');
sjekk('… og merket «Må refunderes» for verkstedet', str_contains((string) $kravRad($K)['kommentar'], 'Må refunderes'), (string) $kravRad($K)['kommentar']);
sjekk('summen viser det som faktisk er tatt: 1 000 kr', $sum($K) === 100000, (string) $sum($K));

// ── (k) Vipps bekrefter ikke avbruddet: raden står, ingenting trekkes ────
echo "\n── (k) avbrudd uten bekreftelse: ikke avbrutt, ikke trukket ─\n";
$sett('.betaling-status', 'CREATED');
$L = $b('Lars', '91300007');
KursstartKrav::send($L);
$refL = (string) $kravRad($L)['vipps_reference'];
DB::iTransaksjon(static fn() => Booking::manuellBetaling($L, 50000, 'Kontant'));
Booking::settBetaltStatus($L);
$sett('.betaling-status', 'AUTHORIZED');
$sett('.avbryt-nei', 'ja');
$idL = $eid();
$fra = $lengde();
$h = $webhook($refL, 'AUTHORIZED', $idL);
sjekk('webhooken svarer 503, så Vipps sender igjen', $h === 503, (string) $h);
sjekk('… ingen trekk, og raden står fortsatt «venter»', count($kall($fra, $refL, 'capture')) === 0 && $kravRad($L)['status'] === 'venter', (string) $kravRad($L)['status']);
$sett('.avbryt-nei', null);
$fra = $lengde();
$h = $webhook($refL, 'AUTHORIZED', $idL);
sjekk('samme hendelse igjen: sluppet og bekreftet, raden avbrutt', $h === 200 && $kravRad($L)['status'] === 'avbrutt'
    && count($kall($fra, $refL, 'capture')) === 0 && $sum($L) === 50000, $h . ' ' . $kravRad($L)['status']);

// ── (l) nytt forsøk med samme nøkkel får 400: raden blir stående ────────
echo "\n── (l) 400 på nytt forsøk: raden slettes ikke ───────────────\n";
$M = $b('Mona', '91300008');
$refM = Vipps::nyReferanse('KS');
DB::settInn('payments', ['vipps_reference' => $refM, 'type' => 'epayment', 'formal' => 'booking', 'booking_id' => $M,
    'belop_ore' => 50000, 'status' => 'opprettet', 'idempotency_key' => Vipps::uuid()]);
$sett('.krav-400', 'ja');
$t = $feilTekst(static fn() => KursstartKrav::send($M));
$sett('.krav-400', null);
sjekk('feil til skjermen, og raden står som «opprettet»', str_contains($t, 'Fikk ikke sendt Vipps-kravet') && count($krav($M)) === 1
    && $kravRad($M)['status'] === 'opprettet', $t);

// ── (m) KS-krav uten rad hos oss: slippes, trekkes aldri ────────────────
echo "\n── (m) KS-krav uten rad: slippes ────────────────────────────\n";
$refX = 'KS-' . gmdate('ymd') . '-X' . strtoupper(bin2hex(random_bytes(4)));
Vipps::opprettBetaling($refX, 40000, 'Test uten rad', 'http://lokal.invalid', '4791300009', true);
$sett('.betaling-status', 'AUTHORIZED');
$fra = $lengde();
$h = $webhook($refX, 'AUTHORIZED', $eid());
sjekk('ingen trekk, sluppet hos Vipps', $h === 200 && count($kall($fra, $refX, 'capture')) === 0 && count($kall($fra, $refX, 'cancel')) === 1, (string) $h);
DB::kjor('DELETE FROM vipps_webhook_events WHERE referanse = :r', ['r' => $refX]);

// ── (n) kravet er større enn resten (prisen satt ned mens det ventet) ───
echo "\n── (n) kravet er større enn resten: slippes ─────────────────\n";
$sett('.betaling-status', 'CREATED');
$N = $b('Nina', '91300010');
KursstartKrav::send($N);
$refN = (string) $kravRad($N)['vipps_reference'];
DB::kjor('UPDATE bookings SET belop_ore = 30000 WHERE id = :i', ['i' => $N]);   // som pamelding.php «endre»
$sett('.betaling-status', 'AUTHORIZED');
$fra = $lengde();
$webhook($refN, 'AUTHORIZED', $eid());
sjekk('500 kr-kravet trekkes ikke når 300 kr står igjen', count($kall($fra, $refN, 'capture')) === 0 && $kravRad($N)['status'] === 'avbrutt');
sjekk('… påmeldingen står ubetalt med 300 kr igjen', $bStatus($N) === 'reservert'
    && KursstartKrav::skyldig($N, 30000, 'reservert') === 30000);

// ── Admin over HTTP (kursbetaling.php, pamelding.php, kursstart3.php) ───
$admin = DB::settInn('members', ['navn' => $tag . ' admin', 'epost' => strtolower($tag) . '.admin@e2e.lissom.test', 'rolle' => 'admin', 'status' => 'aktiv']);
$token = bin2hex(random_bytes(32));
DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
$adminReq = static function (string $fil, array $kropp) use ($adresse, $token) {
    $u = parse_url($adresse);
    $vert = $u['host'] . ':' . $u['port'];
    $c = curl_init($adresse . '/api/admin/' . $fil);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($kropp), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_RESOLVE => [$vert . ':127.0.0.1'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . $adresse, 'Cookie: lissom_sesjon=' . $token]]);
    return $c;
};
$adminKall = static function (string $fil, array $kropp) use ($adminReq): array {
    $c = $adminReq($fil, $kropp);
    $svar = (string) curl_exec($c);
    $s = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$s, json_decode($svar, true) ?: []];
};
$kontantRader = static fn(int $b): int => (int) DB::verdi(
    "SELECT COUNT(*) FROM payments WHERE booking_id = :b AND type = 'manuell' AND status = 'betalt'", ['b' => $b]);

// ── (o) kontant og webhook samtidig: én betaling ────────────────────────
echo "\n── (o) kontant og godkjenning samtidig: bare én betaling ────\n";
$sett('.betaling-status', 'CREATED');
$O = $b('Ola', '91300012');
KursstartKrav::send($O);
$refO = (string) $kravRad($O)['vipps_reference'];
$sett('.betaling-status', 'AUTHORIZED');
$fra = $lengde();
$mh = curl_multi_init();
$u = parse_url($adresse); $vert = $u['host'] . ':' . $u['port'];
$wk = json_encode(['msn' => '123456', 'reference' => $refO, 'name' => 'AUTHORIZED', 'eventId' => $eid(),
    'pspReference' => 'psp-o', 'amount' => ['currency' => 'NOK', 'value' => 0], 'success' => true]);
$hash = base64_encode(hash('sha256', $wk, true)); $dato = gmdate('D, d M Y H:i:s') . ' GMT';
$sig = base64_encode(hash_hmac('sha256', "POST\n/api/vipps-webhook.php\n{$dato};{$vert};{$hash}", $hemmelighet, true));
$cw = curl_init($adresse . '/api/vipps-webhook.php');
curl_setopt_array($cw, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $wk, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    CURLOPT_RESOLVE => [$vert . ':127.0.0.1'], CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-ms-date: ' . $dato,
    'x-ms-content-sha256: ' . $hash, 'Authorization: HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=' . $sig]]);
$ck = $adminReq('kursbetaling.php', ['handling' => 'registrer', 'bookingId' => $O, 'maate' => 'Kontant']);
curl_multi_add_handle($mh, $ck); curl_multi_add_handle($mh, $cw);
do { curl_multi_exec($mh, $aktive); curl_multi_select($mh, 1.0); } while ($aktive > 0);
$sk = (int) curl_getinfo($ck, CURLINFO_RESPONSE_CODE); $sw = (int) curl_getinfo($cw, CURLINFO_RESPONSE_CODE);
curl_multi_close($mh);
sjekk('kontanten nektes (400/409), webhooken 200', in_array($sk, [400, 409], true) && $sw === 200, "kontant $sk, webhook $sw");
sjekk('ett trekk, ingen kontantrad, betalt 500 kr', count($kall($fra, $refO, 'capture')) === 1 && $kontantRader($O) === 0
    && $sum($O) === 50000 && $bStatus($O) === 'betalt', (string) $sum($O));

// ── (p) kursbetaling.php holder låsen gjennom registreringen ────────────
echo "\n── (p) kontant venter på låsen per påmelding ────────────────\n";
$P = $b('Pia', '91300013');
$holder = proc_open([PHP_BINARY, __FILE__, 'hold', (string) $P, '3'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $hr);
$linje = trim((string) fgets($hr[1]));
$t0 = microtime(true);
[$st] = $adminKall('kursbetaling.php', ['handling' => 'registrer', 'bookingId' => $P, 'maate' => 'Kontant']);
$tid = microtime(true) - $t0;
proc_close($holder);
sjekk('kontanten venter til låsen er sluppet, og går så gjennom', $linje === 'låst' && $tid >= 2.0 && $st === 200
    && $sum($P) === 50000, sprintf('%s, %.1f s, HTTP %d', $linje, $tid, $st));

// ── (q) «Ikke betalt»-kortet og gavekortet stopper kravet først ─────────
echo "\n── (q) Ikke betalt-kortet og gavekortet stopper kravet ──────\n";
$sett('.betaling-status', 'CREATED');
$Q = $b('Quinn', '91300014');
KursstartKrav::send($Q);
[$st] = $adminKall('pamelding.php', ['handling' => 'status', 'id' => $Q, 'status' => 'betalt', 'maate' => 'Kontant']);
sjekk('Kontant: kravet avbrutt, én betaling på 500 kr', $st === 200 && $kravRad($Q)['status'] === 'avbrutt' && $sum($Q) === 50000
    && $bStatus($Q) === 'betalt', "HTTP $st, " . $kravRad($Q)['status'] . ', ' . $sum($Q));
$R = $b('Rita', '91300015');
KursstartKrav::send($R);
$kode = 'KSK' . strtoupper(bin2hex(random_bytes(5)));
$gave = DB::settInn('gift_cards', ['kode' => $kode, 'opprinnelig_ore' => 100000, 'saldo_ore' => 100000,
    'gyldig_til' => gmdate('Y-m-d', time() + 365 * 86400), 'status' => 'aktivt', 'kjoper_navn' => $tag]);
[$st] = $adminKall('pamelding.php', ['handling' => 'status', 'id' => $R, 'status' => 'betalt', 'maate' => 'Gavekort', 'kode' => $kode]);
sjekk('Gavekort: kravet avbrutt, kortet trukket 500 kr', $st === 200 && $kravRad($R)['status'] === 'avbrutt' && $bStatus($R) === 'betalt'
    && (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :g', ['g' => $gave]) === 50000, "HTTP $st, " . $kravRad($R)['status']);
$S = $b('Siri', '91300016');
KursstartKrav::send($S);
$sett('.betaling-status', 'AUTHORIZED');
[$st, $j] = $adminKall('pamelding.php', ['handling' => 'status', 'id' => $S, 'status' => 'betalt', 'maate' => 'Kontant']);
sjekk('kunden rakk å betale: kortet nektes (409), bare Vipps', $st === 409 && $kontantRader($S) === 0 && $sum($S) === 50000
    && str_contains((string) ($j['feil'] ?? ''), 'alt betalt'), "HTTP $st");

// ── (r) kursstart3.php: 400 fra Vipps gir 200 med ok:false, bare norsk ──
echo "\n── (r) kursstart3.php: Vipps sier nei → 200 og norsk tekst ──\n";
DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/kursstart3', 'ja') ON DUPLICATE KEY UPDATE verdi = 'ja'");
$sett('.krav-400', 'ja');
$T = $b('Tone', '91300017');
[$st, $j] = $adminKall('kursstart3.php', ['handling' => 'krav', 'bookingId' => $T]);
$sett('.krav-400', null);
sjekk('HTTP 200 med ok:false (ikke 502)', $st === 200 && ($j['ok'] ?? null) === false, "HTTP $st");
sjekk('… bare den norske teksten', str_contains((string) ($j['feil'] ?? ''), 'Salgsenheten har ikke lov')
    && !str_contains((string) ($j['feil'] ?? ''), 'ErrorCode') && !str_contains((string) ($j['feil'] ?? ''), 'MSN'), (string) ($j['feil'] ?? ''));

$ferdig = true;
echo "\n  $ok av " . ($ok + $feil) . " kursstart-krav-kontroller bestått\n";
exit($feil === 0 ? 0 : 1);
