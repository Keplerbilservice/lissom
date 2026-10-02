<?php
/**
 * Vakta sine datasjekker L4–L13 (Vaktdata::regler()), mot testdatabasen.
 *
 * Eieren, 2. oktober 2026: ja til daglig datasjekk. Hver regel faar én
 * konstruert feil som skal gi et funn, og de kjente sakene skal gi roedt:
 *
 *   - nytt medlemskap betalt i slutten av forrige maaned, telt for den
 *     (29. september)                                  → L5
 *   - Prøv Lissom som er utloept, men staar som prove  → L4 og L6
 *   - trekk som henger paa «venter»                    → L7
 *
 * Et medlem som har betalt for maaneden, skal ikke gi noe funn. Funnene
 * skal aldri ha e-post eller telefon. Testen leser bare via Vaktdata, og
 * rydder sine egne rader etterpaa.
 *
 * Kjor:  php tests/vaktdata-regler.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
krev_testdatabase(dirname(__DIR__));
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/betalt-fixture.php';

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

$tag = 'VaktRegel-' . bin2hex(random_bytes(4));
$oslo = new DateTimeZone('Europe/Oslo');
$idag = (new DateTimeImmutable('now', $oslo))->format('Y-m-d');
$mndStart = (new DateTimeImmutable($idag, $oslo))->modify('first day of this month')->format('Y-m-d');
$utc = static fn(string $osloTid): string => (new DateTimeImmutable($osloTid, $oslo))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$dagerSiden = static fn(int $n): string => gmdate('Y-m-d H:i:s', time() - $n * 86400);

$vanlig = $engangs = $fast = null;
foreach (DB::alle('SELECT * FROM membership_plans') as $p) {
    if ((int) $p['engangs'] === 1) { $engangs ??= $p; }
    elseif ((int) ($p['krever_fast_trekk'] ?? 0) === 1) { $fast ??= $p; }
    elseif ((int) $p['aktiv'] === 1) { $vanlig ??= $p; }
}
if ($vanlig === null || $engangs === null || $fast === null) {
    throw new RuntimeException('Testbasen mangler planer (vanlig, engangs, fast trekk).');
}

$medlemmer = []; $betalinger = []; $avtaler = [];
$nytt = static function (string $navn, string $status, string $plan, array $mer = []) use ($tag, &$medlemmer): int {
    $n = count($medlemmer) + 1;
    $id = DB::settInn('members', array_merge([
        'navn' => $navn . ' ' . $tag, 'epost' => $tag . '-' . $n . '@example.test',
        'telefon' => '+479' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'status' => $status, 'medlemskap_type' => $plan, 'start_dato' => gmdate('Y-m-d'),
    ], $mer));
    $medlemmer[] = $id;
    return $id;
};
$betal = static function (array $felt) use (&$betalinger): int {
    $id = DB::settInn('payments', array_merge([
        'type' => 'epayment', 'formal' => 'medlemskap', 'status' => 'betalt', 'belop_ore' => 100000,
        'vipps_reference' => 'TEST-' . bin2hex(random_bytes(10)), 'idempotency_key' => Vipps::uuid(),
    ], $felt));
    $betalinger[] = $id;
    return $id;
};

// ── Riktige data ──────────────────────────────────────────────────────
$riktig = $nytt('Riktig Betalt', 'aktiv', $vanlig['navn']);
test_betalt_medlem($riktig);
foreach (DB::alle('SELECT id FROM payments WHERE member_id = :m', ['m' => $riktig]) as $r) { $betalinger[] = (int) $r['id']; }

// Har sagt opp, men har betalt tid igjen: ikke L9.
$oppsagt = $nytt('Oppsagt Med Tid', 'oppsagt', $vanlig['navn']);
$betal(['member_id' => $oppsagt, 'gjelder_fra' => $idag, 'created_at' => $dagerSiden(1)]);

// Sa opp, og perioden de betalte for er over: vanlig avslutning, ikke L9.
$avsluttet = $nytt('Oppsagt Avsluttet', 'oppsagt', $vanlig['navn']);
$betal(['member_id' => $avsluttet, 'created_at' => $utc((new DateTimeImmutable($mndStart))->modify('-20 days')->format('Y-m-d') . ' 12:00:00'),
    'gjelder_fra' => (new DateTimeImmutable($mndStart))->modify('-1 month')->format('Y-m-d')]);

// Byttet til en annen plan samme maaned: to betalinger, men ikke L8.
$bytte = $nytt('Byttet Plan', 'aktiv', $fast['navn']);
$gammel = DB::settInn('subscriptions', ['member_id' => $bytte, 'plan' => $vanlig['navn'],
    'pris_ore' => (int) $vanlig['pris_ore'], 'status' => 'stoppet']);
$nyAvtale = DB::settInn('subscriptions', ['member_id' => $bytte, 'plan' => $fast['navn'],
    'pris_ore' => (int) $fast['pris_ore'], 'status' => 'aktiv', 'vipps_agreement_id' => 'TEST-' . $tag . '-bytte']);
$betal(['member_id' => $bytte, 'subscription_id' => $gammel, 'gjelder_fra' => $mndStart, 'created_at' => $dagerSiden(1)]);
$betal(['member_id' => $bytte, 'subscription_id' => $nyAvtale, 'gjelder_fra' => $idag]);

// ── Konstruerte feil, én per regel ───────────────────────────────────
// L4 + L6: Prøv Lissom utloept, betalt, staar fortsatt som prove (kjent sak).
$proveUte = $nytt('Prove Utlopt', 'prove', $engangs['navn'], [
    'start_dato' => (new DateTimeImmutable($mndStart))->modify('-1 month')->format('Y-m-d'),
    'slutt_dato' => (new DateTimeImmutable($mndStart))->modify('-1 day')->format('Y-m-d')]);
$betal(['member_id' => $proveUte, 'belop_ore' => (int) $engangs['pris_ore'],
    'created_at' => $utc((new DateTimeImmutable($mndStart))->modify('-1 month')->format('Y-m-d') . ' 12:00:00')]);
// L6 alene: prove uten betaling, sluttdato passert.
$proveUbetalt = $nytt('Prove Ubetalt', 'prove', $engangs['navn'], [
    'slutt_dato' => (new DateTimeImmutable($idag))->modify('-3 days')->format('Y-m-d')]);

// L5: foerste betaling to dager foer maanedsskiftet, uten gjelder_fra (kjent sak 29.09).
$kort = $nytt('Kort Periode', 'aktiv', $vanlig['navn']);
$kortDag = (new DateTimeImmutable($mndStart))->modify('-2 days')->format('Y-m-d');
$betal(['member_id' => $kort, 'created_at' => $utc($kortDag . ' 12:00:00')]);

// L7: trekk som har staatt paa «venter» i ti dager (kjent sak).
$henger = $nytt('Trekk Henger', 'aktiv', $vanlig['navn']);
$hengerAvtale = DB::settInn('subscriptions', ['member_id' => $henger, 'plan' => $vanlig['navn'],
    'pris_ore' => (int) $vanlig['pris_ore'], 'status' => 'aktiv', 'vipps_agreement_id' => 'TEST-' . $tag . '-henger']);
$avtaler[] = $hengerAvtale;
$hengerTrekk = $betal(['member_id' => $henger, 'subscription_id' => $hengerAvtale, 'type' => 'recurring_charge',
    'status' => 'venter', 'created_at' => $dagerSiden(10)]);

// L8: to betalinger for samme maaned.
$dobbel = $nytt('Dobbel Betalt', 'aktiv', $vanlig['navn']);
$betal(['member_id' => $dobbel, 'gjelder_fra' => $mndStart, 'created_at' => $dagerSiden(1)]);
$betal(['member_id' => $dobbel, 'gjelder_fra' => $idag, 'created_at' => gmdate('Y-m-d H:i:s')]);

// L9: betalt i gaar, men staar som «ingen».
$ikkeAktiv = $nytt('Betalt Ikke Aktiv', 'ingen', $vanlig['navn']);
$betal(['member_id' => $ikkeAktiv, 'gjelder_fra' => $idag, 'created_at' => $dagerSiden(1)]);

// L10: aktiv paa en plan som krever fast trekk, uten avtale.
$utenAvtale = $nytt('Uten Avtale', 'aktiv', $fast['navn']);

// L11: autorisert for ti dager siden, aldri trukket.
$autorisert = $betal(['member_id' => null, 'formal' => 'booking', 'status' => 'autorisert',
    'created_at' => $dagerSiden(10)]);

// L12: betalt betaling paa en reservert paamelding — og omvendt.
$kurs = DB::settInn('courses', ['slug' => strtolower($tag), 'tittel' => 'TEST ' . $tag, 'type' => 'kurs',
    'pris_ore' => 100000, 'kapasitet' => 8, 'status' => 'publisert']);
$reservert = DB::settInn('bookings', ['course_id' => $kurs, 'gjest_navn' => 'Gjest Reservert ' . $tag,
    'gjest_epost' => $tag . '-gjest@example.test', 'antall' => 1, 'belop_ore' => 100000, 'status' => 'reservert']);
$betal(['formal' => 'booking', 'booking_id' => $reservert]);
$avbrutt = $betal(['formal' => 'booking', 'status' => 'avbrutt']);
$betaltUten = DB::settInn('bookings', ['course_id' => $kurs, 'gjest_navn' => 'Gjest Betalt ' . $tag,
    'antall' => 1, 'belop_ore' => 100000, 'status' => 'betalt', 'payment_id' => $avbrutt]);
// Riktig: betalt paamelding med betalt betaling.
$riktigBet = $betal(['formal' => 'booking']);
$riktigBooking = DB::settInn('bookings', ['course_id' => $kurs, 'gjest_navn' => 'Gjest Riktig ' . $tag,
    'antall' => 1, 'belop_ore' => 100000, 'status' => 'betalt', 'payment_id' => $riktigBet]);

// L13: saldo som ikke gaar opp, og aktivt kort med ubetalt betaling.
$kortSaldo = DB::settInn('gift_cards', ['kode' => 'TEST-' . $tag . '-A', 'opprinnelig_ore' => 50000,
    'saldo_ore' => 30000, 'gyldig_til' => gmdate('Y-m-d', strtotime('+1 year')), 'kjoper_navn' => 'Kjoper Saldo ' . $tag,
    'kjoper_epost' => $tag . '-kjoper@example.test', 'status' => 'aktivt']);
$gaveBet = $betal(['formal' => 'gavekort', 'status' => 'venter', 'belop_ore' => 50000]);
$kortUbetalt = DB::settInn('gift_cards', ['kode' => 'TEST-' . $tag . '-B', 'opprinnelig_ore' => 50000,
    'saldo_ore' => 50000, 'gyldig_til' => gmdate('Y-m-d', strtotime('+1 year')), 'kjoper_navn' => 'Kjoper Ubetalt ' . $tag,
    'payment_id' => $gaveBet, 'status' => 'aktivt']);
// Riktig: brukt delvis, med uttak som gaar opp.
$gaveOk = $betal(['formal' => 'gavekort', 'belop_ore' => 50000]);
$kortRiktig = DB::settInn('gift_cards', ['kode' => 'TEST-' . $tag . '-C', 'opprinnelig_ore' => 50000,
    'saldo_ore' => 20000, 'gyldig_til' => gmdate('Y-m-d', strtotime('+1 year')), 'kjoper_navn' => 'Kjoper Riktig ' . $tag,
    'payment_id' => $gaveOk, 'status' => 'aktivt']);
DB::settInn('gift_card_uses', ['gift_card_id' => $kortRiktig, 'belop_ore' => 30000, 'ref_type' => 'booking', 'ref_id' => $riktigBooking]);

// ── Kjoer reglene ────────────────────────────────────────────────────
echo "\n── Vaktdata: reglene L4–L13 ─────────────────────────────────\n";
$funn = Vaktdata::regler();
$har = static fn(string $regel, int $id): bool => (bool) array_filter($funn,
    static fn(array $f): bool => $f['regel'] === $regel && $f['id'] === $id);
$egne = array_values(array_filter($funn, static fn(array $f): bool => str_contains($f['navn'], $tag)
    || str_contains($f['tekst'], (string) $autorisert)));
$vis = implode(' | ', array_map(static fn(array $f): string => $f['regel'] . '#' . $f['id'] . ' ' . $f['tekst'], $egne));

sjekk('ingen regel krasjet', !array_filter($funn, static fn(array $f): bool => $f['tekst'] === 'Regelen kunne ikke kjøres'),
    implode(' | ', array_column(array_filter($funn, static fn($f) => $f['id'] === 0), 'regel')));
sjekk('L4 status_uenig: utløpt Prøv Lissom som admin kaller betalt', $har('status_uenig', $proveUte), $vis);
sjekk('L5 forste_betaling_kort_periode: nytt medlemskap ' . $kortDag . ' telt for den måneden', $har('forste_betaling_kort_periode', $kort), $vis);
sjekk('L6 prove_utlopt: utløpt Prøv Lissom (betalt)', $har('prove_utlopt', $proveUte), $vis);
sjekk('L6 prove_utlopt: utløpt prøve uten betaling', $har('prove_utlopt', $proveUbetalt), $vis);
sjekk('L7 trekk_henger: trekk på «venter» i ti dager', $har('trekk_henger', $hengerTrekk), $vis);
sjekk('L8 dobbel_betaling_periode: to betalinger samme måned', $har('dobbel_betaling_periode', $dobbel), $vis);
sjekk('L9 betalt_ikke_aktiv: betalt, men «ingen»', $har('betalt_ikke_aktiv', $ikkeAktiv), $vis);
sjekk('L10 mangler_avtale: ' . $fast['navn'] . ' uten Vipps-avtale', $har('mangler_avtale', $utenAvtale), $vis);
sjekk('L11 autorisert_ikke_trukket: autorisert for ti dager siden', $har('autorisert_ikke_trukket', $autorisert), $vis);
sjekk('L12 booking_betaling_uenig: betalt betaling, reservert påmelding', $har('booking_betaling_uenig', $reservert), $vis);
sjekk('L12 booking_betaling_uenig: betalt påmelding, avbrutt betaling', $har('booking_betaling_uenig', $betaltUten), $vis);
sjekk('L13 gavekort_saldo: saldo går ikke opp', $har('gavekort_saldo', $kortSaldo), $vis);
sjekk('L13 gavekort_saldo: aktivt kort med ubetalt betaling', $har('gavekort_saldo', $kortUbetalt), $vis);

echo "\n── De riktige gir ingen funn ────────────────────────────────\n";
$omId = static fn(int $id, array $regler): bool => (bool) array_filter($funn,
    static fn(array $f): bool => $f['id'] === $id && in_array($f['regel'], $regler, true));
$medlemsregler = ['status_uenig', 'forste_betaling_kort_periode', 'prove_utlopt', 'dobbel_betaling_periode',
    'betalt_ikke_aktiv', 'mangler_avtale'];
sjekk('medlem som har betalt for måneden', !$omId($riktig, $medlemsregler), $vis);
sjekk('oppsagt med betalt tid igjen', !$omId($oppsagt, ['betalt_ikke_aktiv']), $vis);
sjekk('oppsagt der den betalte perioden er over', !$omId($avsluttet, ['betalt_ikke_aktiv']), $vis);
sjekk('bytte til en annen plan samme måned er ikke dobbel betaling', !$omId($bytte, ['dobbel_betaling_periode']), $vis);
sjekk('Kort Periode er ikke dobbelt betalt', !$omId($kort, ['dobbel_betaling_periode']), $vis);
sjekk('betalt påmelding med betalt betaling', !$omId($riktigBooking, ['booking_betaling_uenig']), $vis);
sjekk('gavekort med uttak som går opp', !$omId($kortRiktig, ['gavekort_saldo']), $vis);

echo "\n── Personvern og form ───────────────────────────────────────\n";
$json = json_encode($funn, JSON_UNESCAPED_UNICODE);
$kontakt = DB::alle('SELECT epost, telefon FROM members WHERE epost LIKE :e', ['e' => $tag . '-%']);
$lekker = array_filter($kontakt, static fn(array $k): bool => str_contains($json, (string) $k['epost'])
    || str_contains($json, (string) $k['telefon']));
sjekk('ingen e-post eller telefon i funnene', $lekker === [] && !str_contains($json, '@example.test'));
sjekk('hvert funn har regel, id, navn og tekst — og ingenting annet', !array_filter($funn,
    static fn(array $f): bool => array_keys($f) !== ['regel', 'id', 'navn', 'tekst'] || !is_int($f['id'])));
$v = Vaktdata::sjekk();
sjekk('sjekk() har fortsatt «avvik» som liste av tekst, og «funn» ved siden av',
    isset($v['avvik'], $v['funn']) && !array_filter($v['avvik'], static fn($a) => !is_string($a)) && is_array($v['funn']));
sjekk('virkedager: fredag til mandag er én', Vaktdata::virkedagerSiden('2026-10-02 10:00:00', '2026-10-05') === 1);
sjekk('virkedager: mandag til mandag er fem', Vaktdata::virkedagerSiden('2026-09-28 10:00:00', '2026-10-05') === 5);

$api = file_get_contents(dirname(__DIR__) . '/api/vakt-data.php');
sjekk('endepunktet godtar X-Vakt-Nokkel', str_contains($api, "HTTP_X_VAKT_NOKKEL"));
sjekk('endepunktet har bryteren vakt_data_paa (409)', str_contains($api, "'vakt_data_paa'") && str_contains($api, '409'));
$lib = file_get_contents(dirname(__DIR__) . '/app/lib/vaktdata.php');
sjekk('reglene skriver aldri til basen og kaller aldri Vipps', !preg_match(
    '/DB::(settInn|oppdater|kjor)|synkroniser|anvendTilstand|Vipps::|INSERT |UPDATE |DELETE /', $lib));

// ── Rydd ──────────────────────────────────────────────────────────────
DB::kjor('DELETE FROM gift_card_uses WHERE gift_card_id IN (' . (int) $kortRiktig . ')');
DB::kjor('DELETE FROM gift_cards WHERE id IN (' . implode(',', [$kortSaldo, $kortUbetalt, $kortRiktig]) . ')');
DB::kjor('DELETE FROM bookings WHERE course_id = :k', ['k' => $kurs]);
DB::kjor('DELETE FROM payments WHERE id IN (' . implode(',', array_map('intval', array_unique($betalinger))) . ')');
DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kurs]);
$in = implode(',', array_map('intval', $medlemmer));
DB::kjor("DELETE FROM payments WHERE member_id IN ($in)");
DB::kjor("DELETE FROM subscriptions WHERE member_id IN ($in)");
DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'member' AND objekt_id IN ($in)");
DB::kjor("DELETE FROM members WHERE id IN ($in)");

$ferdig = true;
echo "\n  $ok ok, $feil feil\n";
exit($feil === 0 ? 0 : 1);
