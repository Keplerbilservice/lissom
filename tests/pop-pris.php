<?php
/**
 * Paint on Pots: prisnivåer, 100 kr ved booking og oppgjøret i kassa
 * (eieren, «ok, bygg det» 8. oktober 2026, migrasjon 260).
 *
 * Testen lager sitt eget Paint on Pots-kurs og prøver pengene:
 *   - beløpet ved booking er pris × antall, uten grupperabatt og medlemsrabatt
 *   - betalt ved booking, men ikke slått inn i kassa = «Delvis betalt»
 *   - kassa: beløpet blir summen av gjenstandene, det betalte trekkes fra
 *   - aldri lavere beløp enn det som alt er betalt
 *   - kassa venter når betalingen ved booking ikke er ferdig i Vipps
 *   - eldre bookinger (uten depositum_ore) røres ikke av kassa
 *   - avbestilling: senest 24 timer før = full refusjon, senere = beholdes
 *   - bekreftelsen viser betalt beløp og en avbestillingslenke med kode
 *
 *   php tests/pop-pris.php
 *
 * Sender ingenting: ingen e-post, SMS eller betaling. Betalingene er rader
 * i basen, ikke kall til Vipps.
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

if (!PopPris::klar() || !DB::harKolonne('bookings', 'gjenstander_ore') || !DB::harKolonne('courses', 'depositum')) {
    echo "  FEIL  migrasjon 260 er ikke kjoert\n";
    $ferdig = true;
    exit(1);
}

$rydd = static function (): void {
    $kurs = array_column(DB::alle("SELECT id FROM courses WHERE slug = 'testpoppris'"), 'id');
    if ($kurs === []) {
        return;
    }
    $inn = implode(',', array_map('intval', $kurs));
    $bookinger = array_map('intval', array_column(DB::alle("SELECT id FROM bookings WHERE course_id IN ($inn)"), 'id'));
    if ($bookinger !== []) {
        $b = implode(',', $bookinger);
        DB::kjor("DELETE FROM pop_kasselinjer WHERE booking_id IN ($b)");
        DB::kjor("UPDATE bookings SET payment_id = NULL WHERE id IN ($b)");
        DB::kjor("DELETE FROM payments WHERE booking_id IN ($b)");
    }
    DB::kjor("DELETE FROM notifications WHERE mottaker LIKE 'poppris%@example.com'");
    DB::kjor("DELETE FROM bookings WHERE course_id IN ($inn)");
    DB::kjor("DELETE FROM course_sessions WHERE course_id IN ($inn)");
    DB::kjor("DELETE FROM courses WHERE id IN ($inn)");
};
$rydd();

echo "\n── Prisnivåene fra migrasjonen ──────────────────────────────\n";
$nivaer = PopPris::nivaer();
$perNavn = array_column($nivaer, 'prisOre', 'navn');
sjekk('fire nivåer', count($nivaer) >= 4, (string) count($nivaer));
sjekk('Liten 500, Mellom 700, Stor 850, Ekstra stor 1000',
    ($perNavn['Liten'] ?? 0) === 50000 && ($perNavn['Mellom'] ?? 0) === 70000
    && ($perNavn['Stor'] ?? 0) === 85000 && ($perNavn['Ekstra stor'] ?? 0) === 100000, json_encode($perNavn));
try {
    PopPris::lagreNivaer([['navn' => 'Gratis', 'pris' => '0']]);
    sjekk('pris 0 avvises', false, 'slapp gjennom');
} catch (RuntimeException $e) {
    sjekk('pris 0 avvises', true);
}
sjekk('… og lista er urørt', count(PopPris::nivaer()) === count($nivaer));

$kurs = DB::settInn('courses', [
    'slug' => 'testpoppris', 'tittel' => 'Test PoP-pris', 'type' => 'event', 'pris_ore' => 10000,
    'kapasitet' => 20, 'status' => 'publisert', 'folger_apningstid' => 1, 'depositum' => 1, 'avbestilling_timer' => 24,
]);
$start = gmdate('Y-m-d H:i:s', time() + 5 * 86400);
$okt = DB::settInn('course_sessions', ['course_id' => $kurs, 'start_tid' => $start,
    'slutt_tid' => gmdate('Y-m-d H:i:s', time() + 5 * 86400 + 7200), 'status' => 'planlagt']);

echo "\n── Beløpet ved booking ──────────────────────────────────────\n";
$rad = ['pris_ore' => 10000, 'course_id' => $kurs, 'tema' => '', 'slug' => 'testpoppris', 'depositum' => 1];
$p = Booking::belopFor($rad, 3, true);
sjekk('3 personer × 100 kr = 300 kr', $p['netto'] === 30000, (string) $p['netto']);
sjekk('… uten grupperabatt og medlemsrabatt', $p['rabatt'] == 0 && $p['medlemsrabatt'] === false, json_encode($p));
unset($rad['depositum']);
sjekk('depositum leses fra kurset når raden ikke har det', Booking::erDepositum($rad));

// En booking slik reserverOgBetal() lager den, og Vipps-betalingen bekreftet.
$lagBooking = static function (int $antall, ?int $depositum, string $betStatus = 'betalt') use ($kurs, $okt): array {
    $b = DB::settInn('bookings', [
        'course_id' => $kurs, 'course_session_id' => $okt, 'gjest_navn' => 'PoP Test',
        'gjest_epost' => 'poppris' . $antall . '@example.com', 'antall' => $antall,
        'belop_ore' => 10000 * $antall, 'status' => $betStatus === 'betalt' ? 'betalt' : 'reservert',
        'depositum_ore' => $depositum,
    ]);
    $pay = DB::settInn('payments', [
        'vipps_reference' => 'TEST-POP-' . $b . '-' . bin2hex(random_bytes(3)), 'type' => 'epayment', 'formal' => 'booking',
        'belop_ore' => 10000 * $antall, 'status' => $betStatus, 'idempotency_key' => Vipps::uuid(), 'booking_id' => $b,
    ]);
    DB::oppdater('bookings', ['payment_id' => $pay], ['id' => $b]);
    return [$b, $pay];
};

echo "\n── Delvis betalt til kassa har gjort opp ────────────────────\n";
[$b1] = $lagBooking(2, 20000);
$rad1 = DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => $b1]);
sjekk('200 kr betalt, ikke slått inn = Delvis betalt', Malebord::betalingsstatus($rad1, 20000) === 'Delvis betalt');

echo "\n── Kassa: 1 Liten + 1 Stor ──────────────────────────────────\n";
$id = array_column($nivaer, 'id', 'navn');
$r = PopPris::kassa($b1, [$id['Liten'] => 1, $id['Stor'] => 1], null);
sjekk('gjenstandene er 1 350 kr', $r['sumOre'] === 135000, (string) $r['sumOre']);
sjekk('200 kr betalt ved booking er trukket fra: 1 150 kr igjen', $r['skyldigOre'] === 115000, (string) $r['skyldigOre']);
$etter = DB::en('SELECT belop_ore, gjenstander_ore, status FROM bookings WHERE id = :i', ['i' => $b1]);
sjekk('bookingens beløp er 1 350 kr', (int) $etter['belop_ore'] === 135000);
sjekk('… og står som reservert til resten er betalt', (string) $etter['status'] === 'reservert');
sjekk('… med «Delvis betalt» i admin', Malebord::betalingsstatus(
    DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => $b1]), 20000) === 'Delvis betalt');
Booking::manuellBetaling($b1, 115000, 'Kontant');
$st = Booking::settBetaltStatus($b1);
sjekk('resten betalt kontant: betalt, ingenting skyldig', $st['status'] === 'betalt' && $st['skyldig'] === 0, json_encode($st));
sjekk('… og «Betalt» i admin', Malebord::betalingsstatus(
    DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => $b1]), 135000) === 'Betalt');

$r2 = PopPris::kassa($b1, [$id['Liten'] => 1], null);
$etter2 = DB::en('SELECT belop_ore, gjenstander_ore FROM bookings WHERE id = :i', ['i' => $b1]);
sjekk('gjort om til færre: beløpet går aldri under det som er betalt',
    (int) $etter2['belop_ore'] === 135000 && (int) $etter2['gjenstander_ore'] === 50000 && $r2['skyldigOre'] === 0,
    json_encode($etter2));
sjekk('linjene er byttet ut, ikke lagt til',
    (int) DB::verdi('SELECT COUNT(*) FROM pop_kasselinjer WHERE booking_id = :b', ['b' => $b1]) === 1);

echo "\n── Endre gjenstander beholder prisen de ble slått inn med ───\n";
// Kontrollen 08.10: lagrede linjer beholder sin pris, bare nye får dagens.
[$ls, $s] = PopPris::regnLinjer([7 => 3], [7 => ['navn' => 'Liten', 'prisOre' => 60000]],
    [7 => [['navn' => 'Liten', 'prisOre' => 50000, 'antall' => 2]]]);
sjekk('2 lagret à 500 + 1 ny à 600 = 1 600 kr', $s === 160000 && count($ls) === 2, json_encode($ls));
[, $s] = PopPris::regnLinjer([9 => 1], [], [9 => [['navn' => 'Borte', 'prisOre' => 85000, 'antall' => 2]]]);
sjekk('nivå tatt bort i admin: eksisterende linje kan beholdes/reduseres', $s === 85000);
try {
    PopPris::regnLinjer([9 => 3], [], [9 => [['navn' => 'Borte', 'prisOre' => 85000, 'antall' => 2]]]);
    sjekk('… men ikke økes', false, 'slapp gjennom');
} catch (RuntimeException $e) {
    sjekk('… men ikke økes', true);
}
$felt = PopPris::bookingFelt($kurs, 20000);
sjekk('admin/venteliste får beløp ved booking og frist', ($felt['depositum_ore'] ?? null) === 20000 && ($felt['avbestilling_timer'] ?? null) === 24, json_encode($felt));
sjekk('… og beløpet per person er kursets', PopPris::depositumPerPerson($kurs) === 10000);

echo "\n── Kassa venter og rører ikke eldre bookinger ───────────────\n";
[$b2] = $lagBooking(1, 10000, 'venter');
try {
    PopPris::kassa($b2, [$id['Liten'] => 1], null);
    sjekk('betaling på vei i Vipps: kassa venter', false, 'slapp gjennom');
} catch (RuntimeException $e) {
    sjekk('betaling på vei i Vipps: kassa venter', str_contains($e->getMessage(), 'ikke ferdig'), $e->getMessage());
}
[$b3] = $lagBooking(1, null);
try {
    PopPris::kassa($b3, [$id['Liten'] => 1], null);
    sjekk('booking uten beløp ved booking røres ikke', false, 'slapp gjennom');
} catch (RuntimeException $e) {
    sjekk('booking uten beløp ved booking røres ikke', (int) DB::verdi('SELECT belop_ore FROM bookings WHERE id = :i', ['i' => $b3]) === 10000);
}
try {
    PopPris::kassa($b1, [999999 => 1], null);
    sjekk('ukjent nivå avvises', false, 'slapp gjennom');
} catch (RuntimeException $e) {
    sjekk('ukjent nivå avvises', true);
}

echo "\n── Avbestilling: 24 timer ───────────────────────────────────\n";
sjekk('25 timer før: full refusjon', Booking::avbestillingsregel(25.0, 24)['andel'] === 1.0);
sjekk('akkurat 24 timer før: full refusjon', Booking::avbestillingsregel(24.0, 24)['andel'] === 1.0);
sjekk('23,9 timer før: beholdes', Booking::avbestillingsregel(23.9, 24)['andel'] === 0.0);
sjekk('andre kurs: 2 dager som før', Booking::avbestillingsregel(30.0)['andel'] === 0.0
    && Booking::avbestillingsregel(49.0)['andel'] === 1.0);
sjekk('fristen leses fra kurset', PopPris::avbestillingTimer($kurs) === 24);
sjekk('… men fristen lagret på bookingen går foran',
    PopPris::fristFor(['depositum_ore' => 20000, 'avbestilling_timer' => 48, 'course_id' => $kurs]) === 48);
sjekk('eldre booking på kurset: vilkårenes 2 dager (null)',
    PopPris::fristFor(['depositum_ore' => null, 'avbestilling_timer' => null, 'course_id' => $kurs]) === null);

echo "\n── Resten registreres i «Ta betalt» ─────────────────────────\n";
// Kontrolløren 08.10: «Registrer» avviste alt etter en Vipps-betaling. Paa en
// PoP-booking som er slaatt inn i kassa skal resten kunne registreres.
$kb = (string) file_get_contents(__DIR__ . '/../api/admin/kursbetaling.php');
sjekk('kursbetaling slipper resten gjennom etter kassa',
    str_contains($kb, "AND depositum_ore IS NOT NULL AND gjenstander_ore IS NOT NULL")
    && str_contains($kb, 'if ($viaVipps !== null && !$popRest) {'));
sjekk('kassa tømmer reservert_til', DB::verdi('SELECT reservert_til FROM bookings WHERE id = :i', ['i' => $b1]) === null);

echo "\n── Bekreftelsen ─────────────────────────────────────────────\n";
[$b4] = $lagBooking(2, 20000);
$tekst = PopPris::bekreftelse(DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => $b4]));
sjekk('viser betalt beløp', str_contains($tekst, 'Betalt: 200 kr.'), $tekst);
sjekk('… at det trekkes fra i verkstedet', str_contains($tekst, 'De 200 kronene trekkes fra.'));
sjekk('… og fristen', str_contains($tekst, 'senest 24 timer før'));
$kode = (string) DB::verdi('SELECT avbestill_kode FROM bookings WHERE id = :i', ['i' => $b4]);
sjekk('… og lenka med kode', preg_match('/^[a-f0-9]{32}$/', $kode) === 1 && str_contains($tekst, 'pop-avbestill.php?b=' . $b4 . '&k=' . $kode));
sjekk('riktig kode stemmer', PopPris::kodeStemmer($b4, $kode));
sjekk('feil kode stemmer ikke', !PopPris::kodeStemmer($b4, str_repeat('0', 32)) && !PopPris::kodeStemmer($b1, $kode));
$uten = PopPris::bekreftelse(DB::en('SELECT * FROM bookings WHERE id = :i', ['i' => $b2]));
sjekk('ingenting betalt: ingen «Betalt» og ingen refusjonsløfte',
    str_starts_with($uten, 'Prisen på gjenstandene betaler du i verkstedet.')
    && !str_contains($uten, 'Betalt') && !str_contains($uten, 'tilbake'), $uten);
$pm = (string) file_get_contents(__DIR__ . '/../api/admin/pamelding.php');
sjekk('admin-påmelding med kontant lager betalingsrad for beløpet ved booking',
    str_contains($pm, "Booking::manuellBetaling(\$bookingId, \$belop, \$maate,"));
sjekk('vanlig booking får ingen tekst', PopPris::bekreftelse(['id' => $b3, 'depositum_ore' => null, 'course_id' => $kurs]) === '');

$rydd();
echo "\n$ok OK, $feil FEIL\n";
$ferdig = true;
exit($feil > 0 ? 1 : 0);
