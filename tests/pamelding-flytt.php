<?php
/**
 * L-7, L-8 og L-9 fra pengeflyt-revisjonen (eieren, 2. oktober 2026).
 *
 *   L-7  Flytting av en paamelding til en dato med annen pris: beloepet
 *        foelger prisen (rabatten i samme forhold), betalt status regnes
 *        paa nytt — skyldig i Kasse, eller for mye betalt sagt fra om.
 *        Samme pris: urort.
 *   L-8  «Gi plass» fra ventelista og flytting tar plassen under laas: to
 *        samtidige paa siste plass gir én booking. Et medlem paa ventelista
 *        (samme e-post/telefon) faar plassen som medlem, med medlemsrabatt.
 *   L-9  Full refusjon fra Vipps-portalen (REFUNDED): plassen frigis, og en
 *        plass med flere betalinger staar ikke som betalt for penger som er
 *        gitt tilbake.
 *
 * Ekte endepunkter mot to lokale PHP-servere og en isolert testbase. Ingen
 * Vipps-kall (REFUNDED-tilstanden gis direkte), ingen e-post eller SMS sendes
 * (varsler blir bare liggende i koen i testbasen, og ryddes). Alle beloep i oere.
 *
 * Kjor:  php tests/pamelding-flytt.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

$servere = []; $porter = []; $kurs = []; $okter = []; $medlemmer = []; $vente = []; $admin = 0;
$tag = 'FLYTT-' . strtoupper(bin2hex(random_bytes(3)));
$logg = sys_get_temp_dir() . '/lissom-flytt-' . bin2hex(random_bytes(4)) . '.log';

function parallelt(array $kall): array
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

try {
    $admin = DB::settInn('members', ['navn' => 'Flytt Admin', 'epost' => strtolower($tag) . '@lissom.test', 'rolle' => 'admin']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token), 'maate' => 'passord',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);

    $nyttKurs = static function (string $navn, int $pris) use ($tag, &$kurs): int {
        $k = DB::settInn('courses', ['slug' => strtolower($tag . '-' . $navn), 'tittel' => "Flytt $navn", 'type' => 'kurs',
            'pris_ore' => $pris, 'kapasitet' => 10, 'status' => 'publisert']);
        $kurs[] = $k; return $k;
    };
    $nyOkt = static function (int $k, int $plasser, int $dager) use (&$okter): int {
        $o = DB::settInn('course_sessions', ['course_id' => $k, 'start_tid' => gmdate('Y-m-d H:i:s', time() + $dager * 86400 + count($okter) * 3600),
            'kapasitet' => $plasser]);
        $okter[] = $o; return $o;
    };
    $kA = $nyttKurs('A', 50000); $kB = $nyttKurs('B', 70000); $kC = $nyttKurs('C', 30000);
    $oA = $nyOkt($kA, 10, 10); $oA2 = $nyOkt($kA, 10, 11); $oB = $nyOkt($kB, 10, 12); $oC = $nyOkt($kC, 10, 13);

    $plass = static function (int $okt, int $kursId, array $f) use ($oA): int {
        return DB::settInn('bookings', $f + ['course_id' => $kursId, 'course_session_id' => $okt,
            'gjest_navn' => 'Flytt ' . bin2hex(random_bytes(2)), 'antall' => 1, 'status' => 'betalt']);
    };
    $betaling = static function (int $b, array $f): int {
        $id = DB::settInn('payments', $f + ['vipps_reference' => Vipps::nyReferanse('T'), 'type' => 'epayment',
            'formal' => 'booking', 'status' => 'betalt', 'idempotency_key' => Vipps::uuid(), 'booking_id' => $b]);
        return $id;
    };
    $flytt = static fn(int $b, int $til): array => parallelt([[$GLOBALS['porter'][0], '/api/admin/pamelding.php',
        ['handling' => 'flytt', 'id' => $b, 'oktId' => $til], $GLOBALS['token']]])[0];
    $rad = static fn(int $b): array => DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => $b]);

    for ($i = 0; $i < 2; $i++) {
        $s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
        $port = (int) substr($adr, strrpos($adr, ':') + 1); $porter[] = $port;
        $p = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot],
            [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pipes, $rot);
        fclose($pipes[0]); $servere[] = $p;
        $klar = false; for ($v = 0; $v < 40; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }
        sjekk('HTTP-server ' . $i . ' klar', $klar);
    }
    $GLOBALS['porter'] = $porter; $GLOBALS['token'] = $token;

    // ── L-7 ────────────────────────────────────────────────────────────
    echo "\n── L-7: flytting til en dato med annen pris ──────────────────\n";

    // a) Vipps-betalt 50000 → kurs til 70000: skyldig 20000.
    $b = $plass($oA, $kA, ['belop_ore' => 50000]);
    $p = $betaling($b, ['belop_ore' => 50000]);
    DB::oppdater('bookings', ['payment_id' => $p], ['id' => $b]);
    [$kode, $svar] = $flytt($b, $oB);
    $r = $rad($b);
    sjekk('a) Vipps 50000 → kurs 70000: flyttet', $kode === 200 && (int) $r['course_session_id'] === $oB, json_encode($svar, JSON_UNESCAPED_UNICODE));
    sjekk('a) … beloep 70000, status reservert, skyldig 20000 (synlig i Kasse)',
        (int) $r['belop_ore'] === 70000 && $r['status'] === 'reservert' && ($svar['skyldigOre'] ?? null) === 20000
        && max(0, (int) $r['belop_ore'] - Booking::betalingerFor($b)['sum']) === 20000,
        "beloep {$r['belop_ore']}, status {$r['status']}");
    sjekk('a) … Vipps-betalingen urort (50000, betalt), plassen holdes', (int) DB::verdi('SELECT belop_ore FROM payments WHERE id = :p', ['p' => $p]) === 50000
        && $r['reservert_til'] === null && (int) $r['payment_id'] === $p);
    // Resten tas inn fra «Ikke betalt»-kortet (status betalt + maate).
    [$kode, $svar] = parallelt([[$porter[0], '/api/admin/pamelding.php',
        ['handling' => 'status', 'id' => $b, 'status' => 'betalt', 'maate' => 'Kontant'], $token]])[0];
    $rest = DB::alle("SELECT belop_ore FROM payments WHERE booking_id = :b AND type = 'manuell'", ['b' => $b]);
    sjekk('a) … resten tatt inn i «Ikke betalt»: én kontant-rad 20000, plassen betalt',
        $kode === 200 && count($rest) === 1 && (int) $rest[0]['belop_ore'] === 20000 && $rad($b)['status'] === 'betalt',
        json_encode([$kode, $rest, $rad($b)['status']]));

    // b) Vipps-betalt 50000 → kurs til 30000: 20000 for mye.
    $b = $plass($oA, $kA, ['belop_ore' => 50000]);
    $p = $betaling($b, ['belop_ore' => 50000]);
    DB::oppdater('bookings', ['payment_id' => $p], ['id' => $b]);
    [$kode, $svar] = $flytt($b, $oC);
    $r = $rad($b);
    sjekk('b) Vipps 50000 → kurs 30000: beloep 30000, betalt, 20000 for mye sagt fra om',
        $kode === 200 && (int) $r['belop_ore'] === 30000 && $r['status'] === 'betalt' && ($svar['forMyeOre'] ?? null) === 20000
        && str_contains((string) ($svar['beskjed'] ?? ''), 'for mye'),
        json_encode($svar, JSON_UNESCAPED_UNICODE));

    // c) Samme pris: som i dag.
    $b = $plass($oA, $kA, ['belop_ore' => 45000]);
    $p = $betaling($b, ['belop_ore' => 45000]);
    DB::oppdater('bookings', ['payment_id' => $p], ['id' => $b]);
    $antBet = (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE booking_id = :b', ['b' => $b]);
    [$kode, $svar] = $flytt($b, $oA2);
    $r = $rad($b);
    sjekk('c) samme pris (50000 → 50000): beloep 45000 urort, betalt, ingen nye betalinger',
        $kode === 200 && (int) $r['belop_ore'] === 45000 && $r['status'] === 'betalt'
        && (int) DB::verdi('SELECT COUNT(*) FROM payments WHERE booking_id = :b', ['b' => $b]) === $antBet,
        json_encode($svar, JSON_UNESCAPED_UNICODE));

    // d) Medlemsrabatt 40000 (betalt i Kasse) → 70000: 56000, skyldig 16000.
    $b = $plass($oA, $kA, ['belop_ore' => 40000, 'rabatt_prosent' => 20, 'betalt_maate' => 'Kontant']);
    $p = Booking::manuellBetaling($b, 40000, 'Kontant');
    Booking::settBetaltStatus($b);
    [$kode, $svar] = $flytt($b, $oB);
    $r = $rad($b);
    sjekk('d) medlemsrabatt 40000 → kurs 70000: beloep 56000 (rabatten foelger), skyldig 16000',
        $kode === 200 && (int) $r['belop_ore'] === 56000 && $r['status'] === 'reservert' && ($svar['skyldigOre'] ?? null) === 16000,
        "beloep {$r['belop_ore']}, status {$r['status']} " . json_encode($svar, JSON_UNESCAPED_UNICODE));

    // d2) Kursprisen er hevet etter kjoepet (500 → 600). Rabatten paa plassen
    //     (20 %) gjelder, ikke forholdet til dagens pris: 70000 × 0,8 = 56000.
    $kF = $nyttKurs('F', 50000); $oF = $nyOkt($kF, 10, 14);
    $b = $plass($oF, $kF, ['belop_ore' => 40000, 'rabatt_prosent' => 20]);
    $p = $betaling($b, ['belop_ore' => 40000]);
    DB::oppdater('bookings', ['payment_id' => $p], ['id' => $b]);
    DB::oppdater('courses', ['pris_ore' => 60000], ['id' => $kF]);
    [$kode, $svar] = $flytt($b, $oB);
    $r = $rad($b);
    sjekk('d2) kursprisen hevet etter kjoepet: 20 % av 70000 → 56000, skyldig 16000',
        $kode === 200 && (int) $r['belop_ore'] === 56000 && ($svar['skyldigOre'] ?? null) === 16000, "beloep {$r['belop_ore']}");

    // d3) Beloep satt for haand (30000 paa et kurs til 50000, ingen rabatt
    //     lagret): samme forhold, 30000 × 70000 / 50000 = 42000.
    $b = $plass($oA, $kA, ['belop_ore' => 30000, 'status' => 'reservert', 'betalt_maate' => 'Ikke betalt']);
    [$kode, $svar] = $flytt($b, $oB);
    $r = $rad($b);
    sjekk('d3) for haand 30000 av 50000 → 42000 av 70000, fortsatt reservert, skyldig 42000',
        $kode === 200 && (int) $r['belop_ore'] === 42000 && $r['status'] === 'reservert' && ($svar['skyldigOre'] ?? null) === 42000,
        "beloep {$r['belop_ore']}");

    // e) Kontant lagt inn for haand, uten bilag → 70000. Omsetningen bakover
    //    i tid skal ikke endre seg.
    $da = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
    $b = $plass($oA, $kA, ['belop_ore' => 50000, 'betalt_maate' => 'Kontant', 'created_at' => $da]);
    $fra = gmdate('Y-m-d 00:00:00', time() - 3 * 86400); $til = gmdate('Y-m-d 00:00:00', time() - 2 * 86400);
    $foer = (int) (Omsetning::perFormal($fra, $til)['booking'] ?? 0);
    [$kode, $svar] = $flytt($b, $oB);
    $r = $rad($b);
    $etter = (int) (Omsetning::perFormal($fra, $til)['booking'] ?? 0);
    $bil = DB::en('SELECT belop_ore, maate, created_at, type FROM payments WHERE booking_id = :b', ['b' => $b]);
    sjekk('e) kontant uten bilag 50000 → 70000: beloep 70000, reservert, skyldig 20000',
        $kode === 200 && (int) $r['belop_ore'] === 70000 && $r['status'] === 'reservert' && ($svar['skyldigOre'] ?? null) === 20000,
        "beloep {$r['belop_ore']}, status {$r['status']} " . json_encode($svar, JSON_UNESCAPED_UNICODE));
    sjekk('e) … det betalte (50000 kontant) foert med samme dato', $bil !== null && (int) $bil['belop_ore'] === 50000
        && $bil['maate'] === 'Kontant' && $bil['type'] === 'manuell' && $bil['created_at'] === $da, json_encode($bil));
    sjekk("e) … omsetningen den dagen er uendret ($foer øre)", $foer === $etter && $foer >= 50000, "$foer → $etter");

    // f) Gratis forblir gratis.
    $b = $plass($oA, $kA, ['belop_ore' => 0, 'betalt_maate' => 'Gratis']);
    [$kode, $svar] = $flytt($b, $oB);
    $r = $rad($b);
    sjekk('f) gratis plass → kurs 70000: beloep 0, fortsatt betalt', $kode === 200 && (int) $r['belop_ore'] === 0 && $r['status'] === 'betalt');

    // g) Betaling paa vei i Vipps: flyttingen med ny pris venter.
    $b = $plass($oA, $kA, ['belop_ore' => 50000, 'status' => 'reservert', 'reservert_til' => gmdate('Y-m-d H:i:s', time() + 600)]);
    $p = $betaling($b, ['belop_ore' => 50000, 'status' => 'opprettet']);
    DB::oppdater('bookings', ['payment_id' => $p], ['id' => $b]);
    [$kode, $svar] = $flytt($b, $oB);
    $r = $rad($b);
    sjekk('g) betaling paa vei i Vipps: flytting med ny pris avvises, plassen urort', $kode === 409 && (int) $r['course_session_id'] === $oA
        && (int) $r['belop_ore'] === 50000, (string) $kode);

    // ── L-8 ────────────────────────────────────────────────────────────
    echo "\n── L-8: siste plass under laas ───────────────────────────────\n";
    for ($runde = 1; $runde <= 3; $runde++) {
        $kD = $nyttKurs("D$runde", 50000);
        $siste = $nyOkt($kD, 1, 20 + $runde);
        $w = [];
        foreach (['A', 'B'] as $hvem) {
            $w[] = DB::settInn('waitlist', ['course_id' => $kD, 'course_session_id' => $siste, 'navn' => "Venter $hvem$runde", 'posisjon' => 1]);
        }
        $vente = array_merge($vente, $w);
        sjekk("runde $runde: én ledig plass foer", Booking::ledigePlasser($siste) === 1);
        $svar = parallelt([
            [$porter[0], '/api/admin/venteliste.php', ['handling' => 'gi-plass', 'id' => $w[0], 'oktId' => $siste], $token],
            [$porter[1], '/api/admin/venteliste.php', ['handling' => 'gi-plass', 'id' => $w[1], 'oktId' => $siste], $token],
        ]);
        $lyktes = count(array_filter($svar, static fn($s) => $s[0] === 200 && ($s[1]['ok'] ?? false) === true));
        $plasser = (int) DB::verdi("SELECT COUNT(*) FROM bookings WHERE course_session_id = :o AND status <> 'avbestilt'", ['o' => $siste]);
        sjekk("runde $runde: to samtidige «gi plass» paa siste plass → én booking", $lyktes === 1 && $plasser === 1,
            "lyktes $lyktes, plasser $plasser " . json_encode(array_column($svar, 0)));

        // Flytting: to plasser flyttes samtidig til en dato med én plass.
        $mal = $nyOkt($kA, 1, 30 + $runde);
        $b1 = $plass($oA, $kA, ['belop_ore' => 50000]); $b2 = $plass($oA, $kA, ['belop_ore' => 50000]);
        $svar = parallelt([
            [$porter[0], '/api/admin/pamelding.php', ['handling' => 'flytt', 'id' => $b1, 'oktId' => $mal], $token],
            [$porter[1], '/api/admin/pamelding.php', ['handling' => 'flytt', 'id' => $b2, 'oktId' => $mal], $token],
        ]);
        $lyktes = count(array_filter($svar, static fn($s) => $s[0] === 200 && ($s[1]['ok'] ?? false) === true));
        $plasser = (int) DB::verdi("SELECT COUNT(*) FROM bookings WHERE course_session_id = :o AND status <> 'avbestilt'", ['o' => $mal]);
        sjekk("runde $runde: to samtidige flyttinger til siste plass → én", $lyktes === 1 && $plasser === 1,
            "lyktes $lyktes, plasser $plasser");
    }

    // Medlem paa ventelista beholder medlemsrabatten.
    $plan = (string) DB::verdi('SELECT navn FROM membership_plans WHERE aktiv = 1 AND engangs = 0 ORDER BY sortering LIMIT 1');
    $epost = strtolower($tag) . '-medlem@lissom.test';
    $m = DB::settInn('members', ['navn' => 'Flytt Medlem', 'epost' => $epost, 'telefon' => '+4799' . random_int(100000, 999999),
        'rolle' => 'medlem', 'status' => 'aktiv', 'medlemskap_type' => $plan, 'start_dato' => gmdate('Y-m-d'), 'betaler_ikke' => 1]);
    $medlemmer[] = $m;
    sjekk('medlemmet faar medlemsrabatt (forutsetning)', Booking::faarMedlemsrabatt(DB::en('SELECT * FROM members WHERE id = :m', ['m' => $m])));
    $w = DB::settInn('waitlist', ['course_id' => $kA, 'course_session_id' => $oA, 'navn' => 'Flytt Medlem', 'epost' => $epost, 'posisjon' => 1]);
    $vente[] = $w;
    [$kode, $svar] = parallelt([[$porter[0], '/api/admin/venteliste.php', ['handling' => 'gi-plass', 'id' => $w, 'oktId' => $oA], $token]])[0];
    $r = DB::en("SELECT * FROM bookings WHERE course_session_id = :o AND gjest_navn = 'Flytt Medlem'", ['o' => $oA]);
    sjekk('medlem (samme e-post) faar plassen som medlem: member_id satt, 40000 (20 % av 50000)',
        $kode === 200 && $r !== null && (int) $r['member_id'] === $m && (int) $r['belop_ore'] === 40000,
        json_encode([$kode, $r['member_id'] ?? null, $r['belop_ore'] ?? null]));
    // L-7 h) Medlem paa et gratis kurs flyttes til kurs til 70000: full pris
    //        med medlemsrabatt, 56000, skyldig 56000.
    $kH = $nyttKurs('H', 0); $oH = $nyOkt($kH, 10, 15);
    $b = $plass($oH, $kH, ['belop_ore' => 0, 'member_id' => $m]);
    [$kode, $svar] = $flytt($b, $oB);
    $r = $rad($b);
    sjekk('h) medlem fra gratis kurs → 70000: 56000 med medlemsrabatt, reservert, skyldig 56000',
        $kode === 200 && (int) $r['belop_ore'] === 56000 && $r['status'] === 'reservert' && ($svar['skyldigOre'] ?? null) === 56000,
        "beloep {$r['belop_ore']}, status {$r['status']}");
    sjekk('h) … rabatten (20 %) lagret med beloepet', abs((float) $r['rabatt_prosent'] - 20.0) < 0.01, (string) $r['rabatt_prosent']);

    // Gjest uten treff: full pris, ingen kobling.
    $w = DB::settInn('waitlist', ['course_id' => $kA, 'course_session_id' => $oA, 'navn' => 'Flytt Gjest',
        'epost' => strtolower($tag) . '-gjest@lissom.test', 'posisjon' => 2]);
    $vente[] = $w;
    parallelt([[$porter[0], '/api/admin/venteliste.php', ['handling' => 'gi-plass', 'id' => $w, 'oktId' => $oA], $token]]);
    $r = DB::en("SELECT * FROM bookings WHERE course_session_id = :o AND gjest_navn = 'Flytt Gjest'", ['o' => $oA]);
    sjekk('kontroll: gjest uten treff → gjest, 50000', $r !== null && $r['member_id'] === null && (int) $r['belop_ore'] === 50000);

    // ── L-9 ────────────────────────────────────────────────────────────
    echo "\n── L-9: REFUNDED fra Vipps-portalen ──────────────────────────\n";
    $kE = $nyttKurs('E', 50000);
    $oE = $nyOkt($kE, 1, 40);
    $b = $plass($oE, $kE, ['belop_ore' => 50000]);
    $p = $betaling($b, ['belop_ore' => 50000]);
    DB::oppdater('bookings', ['payment_id' => $p], ['id' => $b]);
    $ref = (string) DB::verdi('SELECT vipps_reference FROM payments WHERE id = :p', ['p' => $p]);
    sjekk('Vipps-plass 50000: dato full foer refusjonen', Booking::ledigePlasser($oE) === 0);
    $refusjon = ['state' => 'REFUNDED', 'aggregate' => ['refundedAmount' => ['value' => 50000]], 'hendelsesbelop_ore' => 50000];
    Vipps::anvendTilstand($ref, $refusjon, true);
    Vipps::anvendTilstand($ref, $refusjon, true); // samme hendelse to ganger
    sjekk('full portalrefusjon 50000: plassen refundert og ledig (to ganger: ingen endring)',
        $rad($b)['status'] === 'refundert' && Booking::ledigePlasser($oE) === 1
        && DB::verdi('SELECT status FROM payments WHERE id = :p', ['p' => $p]) === 'refundert');

    // Delt: 50000 Vipps + 20000 kontant paa en plass til 70000. Vipps-delen
    // refunderes i portalen. Plassen kan ikke staa som betalt.
    $b = $plass($oB, $kB, ['belop_ore' => 70000]);
    $p = $betaling($b, ['belop_ore' => 50000]);
    DB::oppdater('bookings', ['payment_id' => $p], ['id' => $b]);
    Booking::manuellBetaling($b, 20000, 'Kontant');
    Booking::settBetaltStatus($b);
    sjekk('delt 50000 Vipps + 20000 kontant: betalt foer refusjonen', $rad($b)['status'] === 'betalt' && (int) $rad($b)['payment_id'] !== $p);
    $ref = (string) DB::verdi('SELECT vipps_reference FROM payments WHERE id = :p', ['p' => $p]);
    Vipps::anvendTilstand($ref, $refusjon, true);
    $bet = Booking::betalingerFor($b);
    sjekk('delt: Vipps-delen refundert i portalen → reservert, skyldig 50000 (20000 kontant staar)',
        $rad($b)['status'] === 'reservert' && max(0, 70000 - $bet['sum']) === 50000 && $bet['sum'] === 20000,
        "status {$rad($b)['status']}, sum {$bet['sum']}");

    // Vipps-raden naas bare gjennom booking_id (payment_id tom), og ingen
    // annen betaling staar: plassen refunderes og frigis.
    $oG = $nyOkt($kE, 1, 41);
    $b = $plass($oG, $kE, ['belop_ore' => 50000]);
    $p = $betaling($b, ['belop_ore' => 50000]);
    $ref = (string) DB::verdi('SELECT vipps_reference FROM payments WHERE id = :p', ['p' => $p]);
    Vipps::anvendTilstand($ref, $refusjon, true);
    sjekk('bare booking_id, ingen annen betaling: refundert og ledig', $rad($b)['status'] === 'refundert'
        && Booking::ledigePlasser($oG) === 1, (string) $rad($b)['status']);
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($servere as $p) { proc_terminate($p); proc_close($p); }
    foreach ($okter as $o) {
        foreach (array_column(DB::alle('SELECT id FROM bookings WHERE course_session_id = :o', ['o' => $o]), 'id') as $b) {
            DB::kjor('UPDATE bookings SET payment_id = NULL WHERE id = :b', ['b' => $b]);
            DB::kjor('DELETE FROM payments WHERE booking_id = :b', ['b' => $b]);
            DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'booking' AND objekt_id = :b", ['b' => $b]);
            DB::kjor("DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id = :b", ['b' => $b]);
        }
        DB::kjor('DELETE FROM bookings WHERE course_session_id = :o', ['o' => $o]);
    }
    foreach ($vente as $w) {
        DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'waitlist' AND objekt_id = :w", ['w' => $w]);
        DB::kjor('DELETE FROM waitlist WHERE id = :w', ['w' => $w]);
    }
    foreach ($okter as $o) { DB::kjor('DELETE FROM course_sessions WHERE id = :o', ['o' => $o]); }
    foreach ($kurs as $k) { DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $k]); }
    foreach ($medlemmer as $m) { DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $m]); }
    if ($admin) {
        DB::kjor('DELETE FROM audit_log WHERE member_id = :m', ['m' => $admin]);
        DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => $admin]);
        DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $admin]);
    }
    @unlink($logg);
}
echo "\n  $ok ok, $feil feil\n";
exit($feil === 0 ? 0 : 1);
