<?php
/**
 * Paint on Pots: malebordet (eieren 7. og 8. oktober 2026).
 *
 * Testen lager et eget malebord-kurs med sin egen ressurs (3 plasser) og
 * et verkstedkurs paa en annen ressurs, og proever:
 *   - ankomst hvert kvarter innenfor tidene, hele besoeket maa faa plass
 *   - personer til stede SAMTIDIG telles, ikke alle dagens bestillinger
 *   - verkstedet og PoP teller ikke mot hverandre
 *   - ressursen slått av = ingen plassgrense
 *   - unntak per dato: fullt fra et klokkeslett, «Ingen PoP», andre tider
 *   - bookinger som alt er gjort, og oektene deres, roeres aldri
 *   - ingen faste bolker legges ut, og katalogen viser én dato per dag
 *
 *   php tests/malebord.php
 *
 * Sender ingenting: ingen e-post, SMS eller betaling.
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$ferdig = false;
register_shutdown_function(static function () use (&$ferdig): void {
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

if (!Malebord::klar() || !DB::harKolonne('courses', 'folger_apningstid') || !DB::harTabell('kurs_ukeplan')) {
    echo "  FEIL  migrasjon 257/258 er ikke kjoert\n";
    $ferdig = true;
    exit(1);
}

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$dato = (new DateTimeImmutable('now', $oslo))->modify('+20 days')->format('Y-m-d');
$dato2 = (new DateTimeImmutable($dato, $oslo))->modify('+1 day')->format('Y-m-d');
$u = static fn(string $klokke, string $d = '') => (new DateTimeImmutable(($d ?: $dato) . ' ' . $klokke, $oslo))->setTimezone($utc)->format('Y-m-d H:i:s');

$rydd = static function () use ($dato, $dato2): void {
    $kurs = array_column(DB::alle("SELECT id FROM courses WHERE slug IN ('testmalebord','testverksted-mb')"), 'id');
    if ($kurs !== []) {
        $inn = implode(',', array_map('intval', $kurs));
        $bet = array_filter(array_map('intval', array_column(DB::alle("SELECT payment_id FROM bookings WHERE course_id IN ($inn)"), 'payment_id')));
        DB::kjor("DELETE FROM notifications WHERE mottaker LIKE 'malebord%@example.com'");
        DB::kjor("DELETE FROM bookings WHERE course_id IN ($inn)");
        if ($bet !== []) {
            DB::kjor('DELETE FROM payments WHERE id IN (' . implode(',', $bet) . ')');
        }
        DB::kjor("DELETE FROM course_sessions WHERE course_id IN ($inn)");
        DB::kjor("DELETE FROM kurs_ukeplan WHERE course_id IN ($inn)");
        DB::kjor("DELETE FROM courses WHERE id IN ($inn)");
    }
    DB::kjor("DELETE FROM ressurser WHERE navn IN ('Test malebord','Test verksted mb')");
    DB::kjor('DELETE FROM pop_dager WHERE dato IN (:a, :b)', ['a' => $dato, 'b' => $dato2]);
};
$rydd();

$resPop = DB::settInn('ressurser', ['navn' => 'Test malebord', 'antall' => 3, 'aktiv' => 1]);
$resVerk = DB::settInn('ressurser', ['navn' => 'Test verksted mb', 'antall' => 4, 'aktiv' => 1]);
$felt = ['type' => 'event', 'pris_ore' => 45000, 'kapasitet' => 12, 'status' => 'publisert'];
$pop = DB::settInn('courses', $felt + ['slug' => 'testmalebord', 'tittel' => 'Test malebord', 'folger_apningstid' => 1, 'ressurs_id' => $resPop]);
if (DB::harKolonne('courses', 'plass_minutter')) {
    DB::oppdater('courses', ['plass_minutter' => 120], ['id' => $pop]);
}
$verk = DB::settInn('courses', $felt + ['slug' => 'testverksted-mb', 'tittel' => 'Test verksted mb', 'folger_apningstid' => 0, 'ressurs_id' => $resVerk, 'kapasitet' => 4]);
for ($d = 1; $d <= 7; $d++) {
    DB::settInn('kurs_ukeplan', ['course_id' => $pop, 'ukedag' => $d, 'fra' => '10:00:00', 'til' => '20:00:00']);
}

$okt = static function (int $kurs, string $fra, string $til, string $d = '') use ($u): int {
    return DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $u($fra, $d), 'slutt_tid' => $u($til, $d), 'status' => 'planlagt']);
};
$book = static function (int $kurs, int $oktId, int $antall, string $status = 'betalt', ?string $maate = 'Kontant'): int {
    return DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $oktId, 'gjest_navn' => 'Malebord test',
        'gjest_epost' => 'malebord@example.com', 'antall' => $antall, 'belop_ore' => 45000 * $antall,
        'status' => $status, 'betalt_maate' => $maate]);
};
$tider = static fn(int $antall = 1, string $d = '') => array_column(Malebord::kvarter($pop, $d ?: $dato, $antall)['tider'], 'ledige', 'tid');

echo "\n── Ankomst hvert kvarter, hele besoeket innenfor ───────────\n";
$t = $tider();
sjekk('første ankomst 10:00, siste 18:00 (besøket varer 2 t, stenger 20:00)', array_key_first($t) === '10:00' && array_key_last($t) === '18:00', json_encode(array_keys($t)));
sjekk('hvert kvarter: 33 tider', count($t) === 33, (string) count($t));
sjekk('alle har 3 ledige', array_unique(array_values($t)) === [3]);
$k = Malebord::kvarter($pop, $dato, 1);
sjekk('sluttiden står ved hver tid (10:00–12:00)', ($k['tider'][0]['slutt'] ?? '') === '12:00');

echo "\n── Personer til stede samtidig ──────────────────────────────\n";
$a = $okt($pop, '12:00', '14:00');
$bA = $book($pop, $a, 2);
$c = $okt($pop, '14:00', '16:00');
$bC = $book($pop, $c, 2, 'reservert', 'Betaler ved oppmøte');
$t = $tider();
sjekk('12:00 har 1 ledig (2 av 3 sitter der)', ($t['12:00'] ?? null) === 1, json_encode($t['12:00'] ?? null));
sjekk('13:00 har 1 ledig: 2 samtidig, ikke 2 + 2 = 4', ($t['13:00'] ?? null) === 1, json_encode($t['13:00'] ?? null));
sjekk('10:00 har 3 ledige (går før 12:00)', ($t['10:00'] ?? null) === 3);
sjekk('16:00 har 3 ledige (de andre har gått)', ($t['16:00'] ?? null) === 3);
$t2 = $tider(2);
sjekk('for 2 personer er 13:00 ikke ledig', !isset($t2['13:00']));
sjekk('ledigePlasser på økta 12:00 er 1', Booking::ledigePlasser($a) === 1, (string) Booking::ledigePlasser($a));
sjekk('flere bestillinger kan ha samme ankomsttid (samme økt gjenbrukes)', Apent::oktForTid($pop, $dato . ' 12:00') === $a);

echo "\n── Verkstedet og PoP er adskilt ─────────────────────────────\n";
$v = $okt($verk, '12:00', '14:00');
$book($verk, $v, 4);
$t = $tider();
sjekk('fullt verksted endrer ikke PoP (12:00 fortsatt 1 ledig)', ($t['12:00'] ?? null) === 1);
sjekk('verkstedkurset er fullt av sine egne, ikke av PoP', Booking::ledigePlasser($v) === 0);
DB::oppdater('bookings', ['status' => 'avbestilt'], ['course_session_id' => $v]);
sjekk('… og PoP-bookingene tar ikke verkstedplasser (4 ledige)', Booking::ledigePlasser($v) === 4, (string) Booking::ledigePlasser($v));

echo "\n── Plassgrensen er ressursen ────────────────────────────────\n";
sjekk('grensen leses fra ressursen (3)', Malebord::stoler($pop) === 3 && !Malebord::utenGrense($pop));
DB::oppdater('ressurser', ['aktiv' => 0], ['id' => $resPop]);
Malebord::glem();
$t = $tider(40);
sjekk('ressursen slått av = ingen plassgrense: 40 personer kl. 13:00 går', isset($t['13:00']));
sjekk('maks antall uten grense er ' . Malebord::MAKS_ANTALL, Malebord::maksAntall($pop) === Malebord::MAKS_ANTALL);
DB::oppdater('ressurser', ['aktiv' => 1, 'antall' => 1], ['id' => $resPop]);
Malebord::glem();
sjekk('færre plasser enn booket: økta blir bare full (0, ikke negativ)', Booking::ledigePlasser($a) === 0);
sjekk('… og bookingene står', (int) DB::verdi('SELECT COUNT(*) FROM bookings WHERE id IN (' . $bA . ',' . $bC . ") AND status IN ('betalt','reservert')") === 2);
DB::oppdater('ressurser', ['antall' => 3], ['id' => $resPop]);
Malebord::glem();

echo "\n── Unntak per dato ──────────────────────────────────────────\n";
Malebord::settDag($dato, 'fullt', '13:00', null, null);
$t = $tider();
sjekk('fullt fra 13:00: ingen tider fra 13:00', array_filter(array_keys($t), static fn($x) => $x >= '13:00') === []);
sjekk('… tider før står', isset($t['10:00']));
sjekk('… økta 12:00 er ikke sperret', Booking::ledigePlasser($a) === 1);
sjekk('… økta 14:00 har 0 ledige', Booking::ledigePlasser($c) === 0);
$feilmelding = '';
try { Apent::oktForTid($pop, $dato . ' 15:00'); } catch (RuntimeException $e) { $feilmelding = $e->getMessage(); }
sjekk('… og kan ikke bookes', $feilmelding !== '', $feilmelding);
Malebord::settDag($dato, 'stengt', null, null, null);
$k = Malebord::kvarter($pop, $dato, 1);
sjekk('«Ingen PoP»: ingen tider og ikke noe vindu', $k['tider'] === [] && $k['vindu'] === '');
sjekk('… dagen er ikke i katalogen', !in_array($dato, array_column(Malebord::katalogDager($pop, 3), 'dagIso'), true));
$forste = Apent::forsteLedige($pop, $dato, 1);
sjekk('… neste ledige er dagen etter', ($forste['dato'] ?? '') === $dato2, json_encode($forste));
Apent::leggUtPaaApneTider();
sjekk('… øktene med bookinger står', (int) DB::verdi('SELECT COUNT(*) FROM course_sessions WHERE id IN (' . $a . ',' . $c . ')') === 2);
sjekk('… og bookingene er urørt', (int) DB::verdi('SELECT SUM(antall) FROM bookings WHERE id IN (' . $bA . ',' . $bC . ')') === 4);
$avvist = false;
try { Malebord::settDag($dato, 'tider', '10:10', '18:00', null); } catch (InvalidArgumentException $e) { $avvist = true; }
sjekk('andre tider må stå på hele kvarter (10:10 avvises)', $avvist);
Malebord::settDag($dato, 'tider', '15:00', '18:00', null);
$k = Malebord::kvarter($pop, $dato, 1);
sjekk('andre tider 15–18: vinduet er 15:00–18:00', $k['vindu'] === "15:00\u{2013}18:00", $k['vindu']);
sjekk('… første tid 15:00, siste 16:00',
    ($k['tider'][0]['tid'] ?? '') === '15:00' && (end($k['tider'])['tid'] ?? '') === '16:00', json_encode(array_column($k['tider'], 'tid')));
Malebord::apne($dato);
sjekk('åpne igjen: vanlige tider', array_key_first($tider()) === '10:00');

echo "\n── Ingen faste bolker, én dato per dag ──────────────────────\n";
Apent::leggUtPaaApneTider();
$tomme = (int) DB::verdi(
    'SELECT COUNT(*) FROM course_sessions cs WHERE cs.course_id = :k
       AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.course_session_id = cs.id)', ['k' => $pop]);
sjekk('utleggingen lager ingen økter uten booking', $tomme === 0, (string) $tomme);
$dager = Malebord::katalogDager($pop, 3);
$dagen = array_values(array_filter($dager, static fn($d) => $d['dagIso'] === $dato))[0] ?? null;
sjekk('katalogen har dagen med åpningstida', ($dagen['klokke'] ?? '') === "10:00\u{2013}20:00" && ($dagen['oktId'] ?? -1) === 0, json_encode($dagen));
sjekk('én dato per dag', count($dager) === count(array_unique(array_column($dager, 'dagIso'))));

echo "\n── Admin: dagslista ─────────────────────────────────────────\n";
$r = Malebord::reservasjoner($pop, $dato);
$perId = array_column($r, null, 'bookingId');
sjekk('to reservasjoner med ankomst og slutt', count($r) === 2 && ($perId[$bA]['fra'] ?? '') === '12:00' && ($perId[$bA]['til'] ?? '') === '14:00');
sjekk('betalt kontant står som Betalt', ($perId[$bA]['betaling'] ?? '') === 'Betalt', $perId[$bA]['betaling'] ?? '');
sjekk('betal ved oppmøte står ikke som betalt', ($perId[$bC]['betaling'] ?? '') !== 'Betalt', $perId[$bC]['betaling'] ?? '');

echo "\n── Antallet kuttes ikke i stillhet ──────────────────────────\n";
$bookFil = (string) file_get_contents(__DIR__ . '/../api/book.php');
sjekk('book.php avviser i stedet for min(10, …)', !str_contains($bookFil, 'min(10, Foresporsel::heltall(\'antall\'') && str_contains($bookFil, 'Malebord::MAKS_ANTALL'));

echo "\n── Beløpet er pris × antall hele veien gjennom book.php ─────\n";
// Ekte endepunkt i en egen php -S, mot en falsk Vipps. Sender ingen ekte
// betaling, e-post eller SMS (e-postene gaar til @example.com i testmiljoet).
DB::oppdater('ressurser', ['antall' => 20, 'aktiv' => 1], ['id' => $resPop]);
if (DB::harKolonne('courses', 'fra_pris')) {
    DB::oppdater('courses', ['fra_pris' => 1], ['id' => $pop]);   // som Paint on Pots: ingen grupperabatt
}
Malebord::glem();
$rot = dirname(__DIR__);
$pv = random_int(20000, 29000);
$pw = $pv + 1;
$logg = sys_get_temp_dir() . '/malebord-test.log';
$miljo = array_merge(getenv(), ['FALSK_VIPPS_PORT' => (string) $pv, 'LISSOM_VIPPS_BASE' => "http://127.0.0.1:$pv"]);
$ut = [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']];
$prosesser = [
    proc_open(['node', $rot . '/tests/falsk-vipps.mjs'], $ut, $r1, $rot, $miljo),
    proc_open([PHP_BINARY, '-S', "127.0.0.1:$pw", '-t', $rot, $rot . '/tests/nettleser/ruter.php'], $ut, $r2, $rot, $miljo),
];
foreach ([$pv, $pw] as $port) {
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $en, $to, 0.2); $i++) {
        usleep(150000);
    }
}
$post = static function (array $kropp) use ($pw): array {
    $svar = @file_get_contents("http://127.0.0.1:$pw/api/book.php", false, stream_context_create(['http' => [
        'method' => 'POST', 'ignore_errors' => true, 'timeout' => 30,
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => json_encode($kropp),
    ]]));
    return json_decode((string) $svar, true) ?: ['raa' => substr((string) $svar, 0, 300)];
};
$pris = (int) DB::verdi('SELECT pris_ore FROM courses WHERE id = :k', ['k' => $pop]);
$felles = ['kursId' => $pop, 'navn' => 'Malebord Belop', 'telefon' => '41234567', 'harAllergier' => 'nei'];
foreach ([[3, 'oppmote', '17:00'], [12, '', '17:30']] as [$n, $betaling, $kl]) {
    $r = $post($felles + ['antall' => $n, 'tid' => $dato2 . ' ' . $kl, 'betaling' => $betaling,
                          'epost' => 'malebord' . $n . '@example.com']);
    $id = (int) ($r['bookingId'] ?? 0);
    if ($id === 0 && isset($r['referanse'])) {
        $id = (int) DB::verdi('SELECT b.id FROM bookings b JOIN payments p ON p.id = b.payment_id WHERE p.vipps_reference = :r', ['r' => $r['referanse']]);
    }
    $b = DB::en('SELECT antall, belop_ore, payment_id, status FROM bookings WHERE id = :i', ['i' => $id]) ?? [];
    $hva = $betaling === 'oppmote' ? 'betal ved besøket' : 'Vipps (falsk)';
    sjekk("$n personer, $hva: bestillingen er lagret", ($r['ok'] ?? false) === true && $id > 0, json_encode($r, JSON_UNESCAPED_UNICODE));
    sjekk("… antallet er $n", (int) ($b['antall'] ?? 0) === $n);
    sjekk("… beløpet er pris × $n", (int) ($b['belop_ore'] ?? 0) === $pris * $n, (string) ($b['belop_ore'] ?? ''));
    if ($betaling === '') {
        $p = DB::en('SELECT belop_ore, status FROM payments WHERE id = :i', ['i' => (int) ($b['payment_id'] ?? 0)]) ?? [];
        sjekk('… Vipps blir bedt om pris × ' . $n, (int) ($p['belop_ore'] ?? 0) === $pris * $n, json_encode($p));
        sjekk('… og ingenting står som betalt før Vipps har bekreftet', ($b['status'] ?? '') === 'reservert' && ($p['status'] ?? '') !== 'betalt');
    } else {
        sjekk('… og ingen betaling er laget', ($b['payment_id'] ?? null) === null);
    }
}
foreach ($prosesser as $pr) {
    if (is_resource($pr)) { proc_terminate($pr); }
}

$rydd();
Malebord::glem();
$ferdig = true;
echo "\n  $ok ok, $feil feil\n";
exit($feil === 0 ? 0 : 1);
