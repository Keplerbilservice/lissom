<?php
/**
 * Gavekorttrekket skal alltid etterlate et spor.
 *
 * ── Hvorfor denne finnes ────────────────────────────────────────────────
 *
 * «gift_card_uses» er baade kvitteringen og sperren: raden sier hva kortet
 * ble brukt paa, og den er det Booking::trekkGavekort() slaar opp for aa se
 * om trekket alt er gjort. Kommer webhooken og returen fra Vipps begge, eller
 * trykker eieren to ganger, er det den raden som stopper det andre trekket.
 *
 * ref_type hadde tre verdier: booking, ordre, medlemskap. Den siste peker paa
 * en avtale i «subscriptions» — og et medlemskap gjort opp for haand har ikke
 * alltid en avtale. «Prov Lissom» betales én gang og har ingen.
 *
 * Maalt 7. september 2026: kortet ble da trukket — saldoen gikk ned — men
 * ingen rad ble skrevet, fordi det ikke fantes noe aa peke paa. Trekket var
 * usporbart, og et nytt trykk trakk kortet en gang til.
 *
 * Migrasjon 148 la til «betaling», som peker paa betalingsraden selv. Den
 * finnes alltid.
 *
 * Kjor:  php tests/gavekortspor.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

echo "\n── Sporet etter et gavekorttrekk ────────────────────────────\n";

$kolonne = (string) DB::verdi(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gift_card_uses'
        AND COLUMN_NAME = 'ref_type'"
);
sjekk('migrasjon 148 er kjort: ref_type kan peke paa en betaling',
    str_contains($kolonne, "'betaling'"), $kolonne);

// ── Et medlemskap uten loepende avtale ───────────────────────────────
DB::kjor("DELETE FROM gift_cards WHERE kode = 'SPOR-TEST'");
$kortId = DB::settInn('gift_cards', [
    'kode' => 'SPOR-TEST', 'opprinnelig_ore' => 100000, 'saldo_ore' => 100000,
    'gyldig_til' => gmdate('Y-m-d', time() + 86400 * 365),
    'status' => 'aktivt', 'opprinnelse' => 'gitt',
]);

$medlemId = DB::settInn('members', [
    'navn' => 'Spor Testperson', 'epost' => 'spor-' . bin2hex(random_bytes(3)) . '@lissom.test',
    'rolle' => 'medlem',
]);

// Betalingsraden slik api/admin/medlemmer.php lager den: manuell, medlemskap,
// null kroner i penger, og beloepet paa gavekortfeltene. Ingen
// «subscription_id» — det er nettopp tilfellet som ikke hadde noe aa peke paa.
$betalingId = DB::settInn('payments', [
    'vipps_reference' => 'SPOR-' . strtoupper(bin2hex(random_bytes(4))),
    'type' => 'manuell', 'formal' => 'medlemskap', 'member_id' => $medlemId,
    'belop_ore' => 0, 'gavekort_id' => $kortId, 'gavekort_ore' => 40000,
    'status' => 'betalt', 'idempotency_key' => Vipps::uuid(),
]);

Booking::trekkGavekort($betalingId);

$saldo = (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]);
sjekk('kortet trekkes', $saldo === 60000, 'saldo: ' . $saldo);

$bruk = DB::en(
    'SELECT * FROM gift_card_uses WHERE gift_card_id = :k ORDER BY id DESC LIMIT 1',
    ['k' => $kortId]
);
sjekk('… og trekket setter et spor', $bruk !== null);
sjekk('… som peker paa betalingsraden',
    $bruk !== null && (string) $bruk['ref_type'] === 'betaling'
    && (int) $bruk['ref_id'] === $betalingId,
    $bruk === null ? 'ingen rad' : $bruk['ref_type'] . ':' . $bruk['ref_id']);
sjekk('… med beloepet som ble trukket',
    $bruk !== null && (int) $bruk['belop_ore'] === 40000);

// ── Og det skal ikke kunne skje to ganger ────────────────────────────
//
// Dette er hele grunnen til at sporet maa finnes. Uten raden fant sperren
// ingenting, og et nytt trykk trakk kortet en gang til.
Booking::trekkGavekort($betalingId);
$saldo2 = (int) DB::verdi('SELECT saldo_ore FROM gift_cards WHERE id = :i', ['i' => $kortId]);
sjekk('kortet trekkes ikke to ganger for det samme', $saldo2 === 60000, 'saldo: ' . $saldo2);
sjekk('… og det ble ikke satt to spor',
    (int) DB::verdi('SELECT COUNT(*) FROM gift_card_uses WHERE gift_card_id = :k',
        ['k' => $kortId]) === 1);

// ── En kursplass peker fortsatt paa bookingen ────────────────────────
//
// «betaling» er reserven, ikke regelen. Har trekket noe ekte aa peke paa,
// skal det peke dit — ellers ville sporet blitt uleselig for den som leter
// etter en ordre eller et kurs.
$okt = DB::en('SELECT id, course_id FROM course_sessions ORDER BY id DESC LIMIT 1');
if ($okt !== null) {
    $bookingId = DB::settInn('bookings', [
        'course_id' => (int) $okt['course_id'], 'course_session_id' => (int) $okt['id'],
        'gjest_navn' => 'Spor Kursplass', 'antall' => 1, 'belop_ore' => 25000,
        'status' => 'betalt', 'betalt_maate' => 'Gavekort',
    ]);
    $bet2 = DB::settInn('payments', [
        'vipps_reference' => 'SPOR-' . strtoupper(bin2hex(random_bytes(4))),
        'type' => 'manuell', 'formal' => 'booking',
        'belop_ore' => 0, 'gavekort_id' => $kortId, 'gavekort_ore' => 25000,
        'status' => 'betalt', 'idempotency_key' => Vipps::uuid(),
        'booking_id' => DB::harKolonne('payments', 'booking_id') ? $bookingId : null,
    ]);
    DB::oppdater('bookings', ['payment_id' => $bet2], ['id' => $bookingId]);
    Booking::trekkGavekort($bet2);

    $b2 = DB::en('SELECT * FROM gift_card_uses WHERE gift_card_id = :k ORDER BY id DESC LIMIT 1',
        ['k' => $kortId]);
    sjekk('en kursplass peker paa bookingen, ikke paa betalingen',
        $b2 !== null && (string) $b2['ref_type'] === 'booking' && (int) $b2['ref_id'] === $bookingId,
        $b2 === null ? 'ingen rad' : $b2['ref_type'] . ':' . $b2['ref_id']);
    DB::kjor('DELETE FROM gift_card_uses WHERE gift_card_id = :k', ['k' => $kortId]);
    DB::kjor('UPDATE bookings SET payment_id = NULL WHERE id = :i', ['i' => $bookingId]);
    DB::kjor('DELETE FROM bookings WHERE id = :i', ['i' => $bookingId]);
    DB::kjor('DELETE FROM payments WHERE id = :i', ['i' => $bet2]);
} else {
    sjekk('en kursplass peker paa bookingen, ikke paa betalingen', false, 'fant ingen okt aa henge den paa');
}

// Rydd opp.
DB::kjor('DELETE FROM gift_card_uses WHERE gift_card_id = :k', ['k' => $kortId]);
DB::kjor('DELETE FROM payments WHERE id = :i', ['i' => $betalingId]);
DB::kjor('DELETE FROM gift_cards WHERE id = :i', ['i' => $kortId]);
DB::kjor('DELETE FROM members WHERE id = :i', ['i' => $medlemId]);

echo "\n──────────────────────────────────────────────\n";
echo ($ok + $feil) . " sjekker, $ok gikk gjennom" . ($feil ? ", $feil feilet" : '') . "\n";
exit($feil > 0 ? 1 : 0);
