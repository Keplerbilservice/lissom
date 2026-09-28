<?php
/**
 * Timepakken og svarene etter «Ikke nå», mot databasen.
 *
 * Eieren, 28. september 2026: 6 timer for kr 800, pris og timer i admin.
 * Kan bare kjoepes naar maanedens timer er brukt opp, og ikke paa Prøv
 * Lissom. Timene gaar ikke ut, foelger med til neste maaned og brukes
 * etter maanedens timer.
 *
 * Kjor:  php tests/timepakke.php
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

echo "\n── Timepakke ────────────────────────────────────────────────\n";

sjekk('tabellene finnes (migrasjon 232)', DB::harTabell('timepakker') && DB::harTabell('timepakke_bruk') && DB::harTabell('timer_svar'));
sjekk('standard: 6 timer', Timepakke::timer() === 6, (string) Timepakke::timer());
sjekk('standard: kr 800', Timepakke::prisOre() === 80000, (string) Timepakke::prisOre());

$prove = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 1 AND aktiv = 1 ORDER BY sortering LIMIT 1');
$basis = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 0 AND aktiv = 1 AND timer IS NOT NULL ORDER BY timer LIMIT 1');
$basisTimer = (int) DB::verdi('SELECT timer FROM membership_plans WHERE navn = :n', ['n' => $basis]);
sjekk('planene finnes', $prove !== '' && $basis !== '' && $basisTimer > 0, "$prove | $basis");

$tag = 'tp-' . bin2hex(random_bytes(3));
$nytt = static function (string $status, string $plan) use ($tag): int {
    return DB::settInn('members', [
        'navn' => 'Timepakke Test', 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'telefon' => '+479' . random_int(1000000, 9999999),
        'rolle' => 'medlem', 'status' => $status, 'medlemskap_type' => $plan, 'start_dato' => gmdate('Y-m-d'),
    ]);
};
$medlem = static fn(int $id): array => DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);
$stemple = static function (int $id, int $min, ?string $inn = null): void {
    $inn ??= gmdate('Y-m-d H:i:s', max(strtotime(Stempling::manedStart()) + 60, time() - $min * 60 - 60));
    DB::settInn('check_ins', [
        'member_id' => $id, 'inn_tid' => $inn,
        'ut_tid' => gmdate('Y-m-d H:i:s', strtotime($inn) + $min * 60), 'minutter' => $min,
    ]);
};

// ── Vanlig medlem med timer igjen: kan ikke kjoepe ───────────────────
$a = $nytt('aktiv', $basis);
sjekk('timer igjen: kan ikke kjoepe', Timepakke::hvorforIkke($medlem($a)) === 'Timepakken kan kjøpes når timene er brukt opp.',
    Timepakke::hvorforIkke($medlem($a)));
$avvist = '';
try { Timepakke::start($medlem($a)); } catch (RuntimeException $e) { $avvist = $e->getMessage(); }
sjekk('… og serveren avviser kjoepet', $avvist !== '');

// ── Brukt opp: kan kjoepe ─────────────────────────────────────────────
$stemple($a, $basisTimer * 60 + 90);
sjekk('brukt opp: kan kjoepe', Timepakke::hvorforIkke($medlem($a)) === '', Timepakke::hvorforIkke($medlem($a)));

// ── Prøv Lissom: aldri ────────────────────────────────────────────────
$p = $nytt('aktiv', $prove);
$stemple($p, 11 * 60);
sjekk('Prøv Lissom brukt opp: kan ikke kjoepe', Timepakke::hvorforIkke($medlem($p)) === 'Timepakken gjelder ikke Prøv Lissom.',
    Timepakke::hvorforIkke($medlem($p)));
$avvist = '';
try { Timepakke::start($medlem($p)); } catch (RuntimeException $e) { $avvist = $e->getMessage(); }
sjekk('… serveren avviser kjoepet med Prøv Lissom', $avvist === 'Timepakken gjelder ikke Prøv Lissom.', $avvist);
$p2 = $nytt('prove', $prove);
sjekk('status «prove» er ogsaa Prøv Lissom', Timepakke::erProve($medlem($p2)));

// ── En betalt pakke: timene legges paa, overtimene trekkes fra ────────
$ref = Vipps::nyReferanse('TP');
$bet = DB::settInn('payments', ['vipps_reference' => $ref, 'type' => 'epayment', 'formal' => 'medlemskap',
    'member_id' => $a, 'belop_ore' => 80000, 'status' => 'venter', 'idempotency_key' => Vipps::uuid()]);
$pakke = DB::settInn('timepakker', ['member_id' => $a, 'timer' => 6, 'pris_ore' => 80000, 'status' => 'venter', 'payment_id' => $bet]);
sjekk('en ubetalt pakke gir ingen timer', Timepakke::tilgodeMin($a) === 0);
sjekk('betalingen gaar gjennom markerBetalt', Booking::markerBetalt($ref));
sjekk('pakken er betalt', DB::verdi('SELECT status FROM timepakker WHERE id = :i', ['i' => $pakke]) === 'betalt');
sjekk('360 pakkeminutter til gode', Timepakke::tilgodeMin($a) === 360, (string) Timepakke::tilgodeMin($a));
$tak = Medlemskap::timerMedGaver($medlem($a));
sjekk('taket = planen + 6', (float) $tak === (float) ($basisTimer + 6), (string) $tak);
sjekk('taket uten pakke er planen', (float) Medlemskap::timerMedGaver($medlem($a), false) === (float) $basisTimer);
$adm = Timepakke::forAdmin($a);
sjekk('admin: pakken staar der', count($adm['kjopt']) === 1 && $adm['kjopt'][0]['timer'] === 6);
sjekk('admin: 90 minutter over foer kjoepet er trukket — 270 igjen', $adm['igjenMin'] === 270, (string) $adm['igjenMin']);
sjekk('med pakketimer igjen: kan ikke kjoepe ny', Timepakke::hvorforIkke($medlem($a)) !== '');

// ── Maaneden er over: bruken skrives, resten foelger med ──────────────
$b = $nytt('aktiv', $basis);
$forrige = (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->modify('first day of last month')->setTime(12, 0)
    ->setTimezone(new DateTimeZone('UTC'));
$bet2 = DB::settInn('payments', ['vipps_reference' => Vipps::nyReferanse('TP'), 'type' => 'epayment', 'formal' => 'medlemskap',
    'member_id' => $b, 'belop_ore' => 80000, 'status' => 'betalt', 'idempotency_key' => Vipps::uuid()]);
DB::settInn('timepakker', ['member_id' => $b, 'timer' => 6, 'pris_ore' => 80000, 'status' => 'betalt',
    'payment_id' => $bet2, 'betalt_at' => $forrige->format('Y-m-d H:i:s')]);
$stemple($b, $basisTimer * 60 + 120, $forrige->modify('+1 day')->format('Y-m-d H:i:s'));
Timepakke::lukkMaaneder();
$brukt = (int) DB::verdi('SELECT minutter FROM timepakke_bruk WHERE member_id = :m', ['m' => $b]);
sjekk('forrige maaned: 120 pakkeminutter brukt', $brukt === 120, (string) $brukt);
sjekk('240 minutter foelger med til denne maaneden', Timepakke::tilgodeMin($b) === 240, (string) Timepakke::tilgodeMin($b));
Timepakke::lukkMaaneder();
sjekk('kjoert to ganger: én rad', (int) DB::verdi('SELECT COUNT(*) FROM timepakke_bruk WHERE member_id = :m', ['m' => $b]) === 1);

// ── Svarene etter «Ikke nå» ───────────────────────────────────────────
$foer = Timepakke::svarTelling()['For dyrt'] ?? 0;
Timepakke::lagreSvar($p, 'For dyrt', 'Litt mye nå', 'prove');
Timepakke::lagreSvar($p, 'noe annet', '', 'vanlig');
$svar = Timepakke::svarFor($p);
sjekk('to svar lagret', count($svar) === 2);
sjekk('ukjent grunn lagres uten grunn', $svar[0]['grunn'] === '');
sjekk('fritekst og vindu lagres', $svar[1]['tekst'] === 'Litt mye nå' && $svar[1]['vindu'] === 'Prøv Lissom');
sjekk('opptellingen per grunn', (Timepakke::svarTelling()['For dyrt'] ?? 0) === $foer + 1);

// ── Rydd ──────────────────────────────────────────────────────────────
DB::kjor("DELETE FROM payments WHERE member_id IN ($a, $b) OR id IN ($bet, $bet2)");
DB::kjor("DELETE FROM members WHERE epost LIKE :t", ['t' => $tag . '%']);

$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
