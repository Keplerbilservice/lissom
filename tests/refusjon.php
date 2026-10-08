<?php
/** Reell DB-regresjon, med lokal Vipps-stubbe og ingen utsendinger. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/nettleser/testdatabase.php';
$LISSOM_SECRETS = krev_testdatabase(dirname(__DIR__));
$navn = $LISSOM_SECRETS['db_navn'];
$port = $LISSOM_SECRETS['db_port'] ?? 3306;
require dirname(__DIR__) . '/app/config.php';
require dirname(__DIR__) . '/app/lib/db.php';
require dirname(__DIR__) . '/app/lib/booking.php';
// Booking::plasserEtterFullRefusjon legger tilbake PoP-lager (migrasjon 262).
require dirname(__DIR__) . '/app/lib/poppris.php';


final class Vipps
{
    public static array $calls = [];
    public static bool $fail = false;
    public static function refunder(string $ref, int $amount, string $op): void
    {
        self::$calls[] = [$ref, $amount, $op];
        if (self::$fail) { throw new RuntimeException('Simulert usikkert nettverkssvar'); }
    }
    public static function refunderTrekk(string $agreement, string $charge, int $amount, string $ref, string $op): void
    {
        self::$calls[] = [$agreement, $charge, $amount, $ref, $op];
        if (self::$fail) { throw new RuntimeException('Simulert usikkert nettverkssvar'); }
    }
}
function sjekk(bool $ok, string $navn): void {
    if (!$ok) { throw new RuntimeException($navn); }
    echo "OK: $navn\n";
}
$ids = [];
$memberId = $subscriptionId = $courseId = $bookingId = 0;
try {
    $lag = static function (int $refunded = 0) use (&$ids): int {
        $id = DB::settInn('payments', ['vipps_reference' => 'refund-test-' . bin2hex(random_bytes(8)),
            'formal' => 'booking', 'type' => 'epayment', 'belop_ore' => 10000,
            'refundert_ore' => $refunded, 'status' => $refunded ? 'delvis_refundert' : 'betalt',
            'idempotency_key' => bin2hex(random_bytes(16))]);
        $ids[] = $id; return $id;
    };
    $id = $lag(3000);
    $r = Booking::refunderBetaling($id, 10000);
    sjekk($r['belop'] === 7000 && $r['gjenstaar'] === 0, 'Full refusjon begrenses til rest');
    $calls = count(Vipps::$calls);
    Booking::refunderBetaling($id);
    sjekk(count(Vipps::$calls) === $calls, 'Duplikat full refusjon sender ikke nytt kall');
    $ref = (string) DB::verdi('SELECT vipps_reference FROM payments WHERE id=:i', ['i'=>$id]);
    sjekk(!Booking::markerBetalt($ref), 'Gammel CAPTURED ignoreres etter full refusjon');
    sjekk(DB::verdi('SELECT status FROM payments WHERE id=:i', ['i'=>$id]) === 'refundert', 'Refusjonsstatus bevares');

    $id2 = $lag();
    Vipps::$fail = true;
    try { Booking::refunderBetaling($id2, 2500); throw new LogicException('Feil uteble'); }
    catch (RuntimeException $e) { sjekk($e->getMessage() === 'Simulert usikkert nettverkssvar', 'Nettverksfeil videresendes'); }
    $first = Vipps::$calls[count(Vipps::$calls)-1];
    sjekk(DB::verdi("SELECT status FROM payment_refunds WHERE payment_id=:p", ['p'=>$id2]) === 'pending', 'Usikkert utfall beholdes varig');
    Vipps::$fail = false;
    $recoveryToken = bin2hex(random_bytes(16));
    $r = Booking::refunderBetaling($id2, 9000, $recoveryToken);
    sjekk(Vipps::$calls[count(Vipps::$calls)-1] === $first, 'Retry bruker samme operasjon og beloep');
    sjekk($r['refundert'] === 2500 && $r['gjenstaar'] === 7500, 'Retry oeker ikke refusjonen');
    $recoveryCalls = count(Vipps::$calls);
    sjekk(Booking::refunderBetaling($id2, 9000, $recoveryToken) === $r, 'Admin kan replaye adoptert kundeoperasjon');
    sjekk(count(Vipps::$calls) === $recoveryCalls, 'Adoptert token sender ikke ny refusjon');
    $ref = (string) DB::verdi('SELECT vipps_reference FROM payments WHERE id=:i', ['i'=>$id2]);
    sjekk(!Booking::markerBetalt($ref), 'Gammel CAPTURED ignoreres etter delrefusjon');

    $id3 = $lag();
    $token = bin2hex(random_bytes(16));
    $r3 = Booking::refunderBetaling($id3, 2000, $token);
    $beforeReplay = count(Vipps::$calls);
    sjekk(Booking::refunderBetaling($id3, 2000, $token) === $r3, 'Tapt HTTP-svar etter commit returnerer samme resultat');
    sjekk(count(Vipps::$calls) === $beforeReplay, 'Klient-retry sender ingen ekstra delrefusjon');
    try { Booking::refunderBetaling($id3, 3000, $token); throw new LogicException('Token beloep endret'); }
    catch (RuntimeException $e) { sjekk($e->getMessage() === 'Operasjonen har et annet beloep.', 'Token kan ikke gjenbrukes med annet beloep'); }
    $r4 = Booking::refunderBetaling($id3, 2000, bin2hex(random_bytes(16)));
    sjekk($r4['refundert'] === 4000, 'Ny klientintensjon kan gi ny delrefusjon');

    $memberId = DB::settInn('members', ['navn' => 'Refusjonstest', 'epost' => 'refund-'.bin2hex(random_bytes(5)).'@e2e.lissom.test']);
    $subscriptionId = DB::settInn('subscriptions', ['member_id'=>$memberId, 'plan'=>'test', 'pris_ore'=>10000,
        'vipps_agreement_id'=>'agreement-'.bin2hex(random_bytes(6))]);
    $recurringId = $lag(1000);
    DB::oppdater('payments', ['type'=>'recurring_charge', 'subscription_id'=>$subscriptionId, 'vipps_psp_ref'=>'charge-test'], ['id'=>$recurringId]);
    $recurringToken = bin2hex(random_bytes(16));
    $rc = Booking::refunderBetaling($recurringId, 9000, $recurringToken);
    $rcCall = Vipps::$calls[count(Vipps::$calls)-1];
    sjekk(count($rcCall) === 5 && $rcCall[1] === 'charge-test' && $rcCall[2] === 9000, 'Avtaletrekk bruker egen Vipps-rute med stabil operasjon');
    $rcCount = count(Vipps::$calls);
    sjekk(Booking::refunderBetaling($recurringId, 9000, $recurringToken) === $rc && count(Vipps::$calls) === $rcCount, 'Fullt refundert avtaletrekk kan replayes etter commit');

    $courseId = DB::settInn('courses', ['slug'=>'refund-test-'.bin2hex(random_bytes(6)), 'tittel'=>'Refusjonstest', 'pris_ore'=>10000, 'kapasitet'=>8]);
    $claimPayment = $lag();
    $bookingId = DB::settInn('bookings', ['course_id'=>$courseId, 'member_id'=>$memberId, 'payment_id'=>$claimPayment,
        'belop_ore'=>10000, 'status'=>'betalt']);
    $claim = static function () use (&$bookingId): void {
        $n = DB::kjor("UPDATE bookings SET status='avbestilt', avbestilt_at=UTC_TIMESTAMP() WHERE id=:i AND status='betalt'", ['i'=>$bookingId])->rowCount();
        if ($n !== 1) { throw new RuntimeException('Claim finnes allerede', 409); }
    };
    try {
        Booking::refunderBetaling($claimPayment, 10000, null, static function () use ($claim): void {
            $claim(); throw new RuntimeException('Feil foer journalcommit');
        });
        throw new LogicException('Rollback feil uteble');
    } catch (RuntimeException $e) { sjekk($e->getMessage() === 'Feil foer journalcommit', 'Feil foer journalcommit oppdages'); }
    sjekk(DB::verdi('SELECT status FROM bookings WHERE id=:i', ['i'=>$bookingId]) === 'betalt'
        && (int)DB::verdi('SELECT COUNT(*) FROM payment_refunds WHERE payment_id=:i', ['i'=>$claimPayment]) === 0, 'Bookingclaim og journal rulles tilbake sammen');
    Vipps::$fail = true;
    try { Booking::refunderBetaling($claimPayment, 10000, null, $claim); }
    catch (RuntimeException $e) { sjekk($e->getMessage() === 'Simulert usikkert nettverkssvar', 'Feil etter intentcommit oppdages'); }
    sjekk(DB::verdi('SELECT status FROM bookings WHERE id=:i', ['i'=>$bookingId]) === 'avbestilt'
        && DB::verdi('SELECT status FROM payment_refunds WHERE payment_id=:i', ['i'=>$claimPayment]) === 'pending', 'Avbestilling etter nettverksfeil har varig gjenopptakbar journal');
    Vipps::$fail = false;
    Booking::refunderBetaling($claimPayment, 10000, bin2hex(random_bytes(16)));
    sjekk(DB::verdi('SELECT status FROM bookings WHERE id=:i', ['i'=>$bookingId]) === 'refundert', 'Admin fullfoerer avbestillingens eksisterende operasjon');

    $other = new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$navn.';charset=utf8mb4',
        Config::krev('db_bruker'), Config::krev('db_passord'));
    $lock = 'lissom-refund-'.$id2;
    $st = $other->prepare('SELECT GET_LOCK(?, 0)'); $st->execute([$lock]);
    sjekk((int)$st->fetchColumn() === 1, 'Annen forbindelse eier betalingslaasen');
    $before = count(Vipps::$calls);
    try { Booking::refunderBetaling($id2); throw new LogicException('Laas oversett'); }
    catch (RuntimeException $e) { sjekk($e->getMessage() === 'Refusjon behandles allerede.', 'Samtidig refusjon avvises'); }
    sjekk(count(Vipps::$calls) === $before, 'Samtidig kall naar ikke Vipps');
    $st=$other->prepare('SELECT RELEASE_LOCK(?)'); $st->execute([$lock]);
    echo "Refusjonsregresjoner bestod.\n";
} finally {
    if ($bookingId) { DB::kjor('DELETE FROM bookings WHERE id=:i', ['i'=>$bookingId]); }
    foreach ($ids as $id) {
        DB::kjor('DELETE FROM payment_refunds WHERE payment_id=:i', ['i'=>$id]);
        DB::kjor('DELETE FROM payments WHERE id=:i', ['i'=>$id]);
    }
    if ($subscriptionId) { DB::kjor('DELETE FROM subscriptions WHERE id=:i', ['i'=>$subscriptionId]); }
    if ($courseId) { DB::kjor('DELETE FROM courses WHERE id=:i', ['i'=>$courseId]); }
    if ($memberId) { DB::kjor('DELETE FROM members WHERE id=:i', ['i'=>$memberId]); }
}
