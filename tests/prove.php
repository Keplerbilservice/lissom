<?php
/**
 * Prøv Lissom (engangsplanen), mot databasen.
 *
 * Eieren, 28. september 2026. Johanna hadde Prøv Lissom, timene var brukt
 * opp, og «Forny» ble avvist: «Du har alt et medlemskap». Min side sa
 * «Bundet til 2. nov.». Reglene som testes:
 *
 *   1. Sluttdato = siste dag i kjoepsmaaneden, fra én kilde.
 *   2. Kan bare kjoepes én gang — serveren avviser et nytt kjoep.
 *   3. En engangsplan er aldri bundet og aldri loepende.
 *   4. Et nytt medlemskap erstatter den (én gang, uten refusjon), gjelder
 *      alt denne maaneden, og timer stemplet ut over de ti trekkes fra det.
 *      Oekta som paagaar, teller paa det nye.
 *   5. Et loepende medlemskap sperrer fortsatt.
 *   6. Migrasjon 231 gir dem uten sluttdato en — men ikke medlem 10.
 *
 * Kjor:  php tests/prove.php
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
$kaster = static function (callable $f): string {
    try { $f(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};

echo "\n── Prøv Lissom ──────────────────────────────────────────────\n";

$prove = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 1 AND aktiv = 1 ORDER BY sortering LIMIT 1');
$basis = (string) DB::verdi("SELECT navn FROM membership_plans WHERE engangs = 0 AND aktiv = 1 AND krever_fast_trekk = 0 AND timer IS NOT NULL ORDER BY timer DESC LIMIT 1");
$aar   = (string) DB::verdi('SELECT navn FROM membership_plans WHERE krever_fast_trekk = 1 AND aktiv = 1 ORDER BY sortering LIMIT 1');
sjekk('planene finnes', $prove !== '' && $basis !== '' && $aar !== '', "$prove | $basis | $aar");
$proveTimer = (int) DB::verdi('SELECT timer FROM membership_plans WHERE navn = :n', ['n' => $prove]);

// ── 1. Sluttdatoen ────────────────────────────────────────────────────
sjekk('kjoept 2. september gjelder ut 30. september', Medlemskap::proveSlutt('2026-09-02') === '2026-09-30',
    Medlemskap::proveSlutt('2026-09-02'));
sjekk('kjoept 10. februar gjelder ut 28. februar', Medlemskap::proveSlutt('2027-02-10') === '2027-02-28');
sjekk('uten dato: siste dag i denne maaneden',
    Medlemskap::proveSlutt() === (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->modify('last day of this month')->format('Y-m-d'));

$tag = 'prove-' . bin2hex(random_bytes(3));
$nytt = static function (string $navn, string $status, string $plan) use ($tag): int {
    return DB::settInn('members', [
        'navn' => $navn, 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'telefon' => '+479' . random_int(1000000, 9999999),
        'rolle' => 'medlem', 'status' => $status, 'medlemskap_type' => $plan !== '' ? $plan : null,
        'start_dato' => gmdate('Y-m-d'),
    ]);
};
$medlem = static fn(int $id): array => DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);

// ── 3. Aldri bundet ───────────────────────────────────────────────────
$jo = $nytt('Johanna Test', 'aktiv', $prove);
$gammel = DB::settInn('subscriptions', [
    'member_id' => $jo, 'plan' => $prove, 'pris_ore' => 99000, 'status' => 'aktiv',
    // Slik raden sto for Johanna: to maaneder fram, fra en regel planen ikke har.
    'binding_til' => gmdate('Y-m-d', strtotime('+2 months')),
]);
DB::oppdater('members', ['slutt_dato' => Medlemskap::proveSlutt()], ['id' => $jo]);
$av = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $gammel]);
sjekk('engangsplanen er aldri bundet, uansett hva raden sier', Medlemskap::bindingTil($av) === null);
sjekk('… og oppsigelsen sperres ikke av en binding', Medlemskap::hvorforIkkeSiOpp($av) === null);
$mini = DB::en("SELECT * FROM membership_plans WHERE engangs = 0 AND binding_mnd > 0 AND aktiv = 1 LIMIT 1");
if ($mini !== null) {
    $bundet = ['plan' => $mini['navn'], 'binding_til' => '2099-01-01'];
    sjekk('en plan med binding er fortsatt bundet', Medlemskap::bindingTil($bundet) === '2099-01-01');
}
$kode = file_get_contents(dirname(__DIR__) . '/api/medlemskap.php');
sjekk('Min side leser bindingen fra samme kilde',
    substr_count($kode, 'Medlemskap::bindingTil($a)') === 1 && !str_contains($kode, "'bundetTil'  => \$a['binding_til']"));
sjekk('… og proeveperioden kan ikke sies opp paa Min side', str_contains($kode, "&& !\$engangs && Medlemskap::hvorforIkkeSiOpp"));

// ── 2. Én gang ────────────────────────────────────────────────────────
sjekk('den som har hatt den, har hatt den', Medlemskap::harHattProve($jo));
$melding = $prove . ' kan bare kjøpes én gang. Velg et annet medlemskap.';
$svar = $kaster(static fn() => Medlemskap::startEngangs($medlem($jo), $prove));
sjekk('nytt kjoep avvises paa serveren', $svar === $melding, $svar);
$svar = $kaster(static fn() => Medlemskap::startIVerkstedet($medlem($jo), $prove));
sjekk('… ogsaa ved betaling i verkstedet', $svar === $melding, $svar);
$ny1 = $nytt('Aldri Hatt', 'ingen', '');
sjekk('den som aldri har hatt den, har ikke hatt den', !Medlemskap::harHattProve($ny1));
DB::settInn('subscriptions', ['member_id' => $ny1, 'plan' => $prove, 'pris_ore' => 99000, 'status' => 'venter']);
sjekk('… heller ikke med et forsoek som aldri ble betalt', !Medlemskap::harHattProve($ny1));

// ── 5. Et loepende medlemskap sperrer fortsatt ────────────────────────
$fast = $nytt('Fast Trekk', 'aktiv', $aar);
DB::settInn('subscriptions', ['member_id' => $fast, 'plan' => $aar, 'pris_ore' => 199000, 'status' => 'aktiv',
    'vipps_agreement_id' => 'agr-' . $tag]);
$svar = $kaster(static fn() => Medlemskap::startEngangs($medlem($fast), $basis));
sjekk('et medlem med fast trekk sperres som foer', $svar === 'Du har alt et medlemskap. Si det opp først, eller bytt fra Min side.', $svar);
$svar = $kaster(static fn() => Medlemskap::startAvtale($medlem($fast), $aar));
sjekk('… ogsaa for en ny avtale', $svar === 'Du har alt et medlemskap. Si det opp først, eller bytt fra Min side.', $svar);

// ── 4. Oppgraderingen ─────────────────────────────────────────────────
// Tolv timer stemplet denne maaneden, og en oekt som paagaar naa.
$mstart = Stempling::manedStart();
$t = static fn(int $min): string => gmdate('Y-m-d H:i:s', strtotime($mstart . ' UTC') + $min * 60);
foreach ([1, 3, 5] as $i) {
    DB::settInn('check_ins', ['member_id' => $jo, 'inn_tid' => $t($i), 'ut_tid' => $t($i + 1), 'minutter' => 240]);
}
$innNaa = max(strtotime($mstart . ' UTC') + 600, time() - 1800);
DB::settInn('check_ins', ['member_id' => $jo, 'inn_tid' => gmdate('Y-m-d H:i:s', $innNaa)]);
$paagaar = intdiv(time() - $innNaa, 60);
$for = Stempling::minutterDenneManeden($jo);
sjekk('foer oppgraderingen: alle timene teller paa proeveperioden', abs($for - (720 + $paagaar)) <= 1, (string) $for);

$ny = DB::settInn('subscriptions', ['member_id' => $jo, 'plan' => $basis, 'pris_ore' => 249000, 'status' => 'venter']);
DB::settInn('payments', ['member_id' => $jo, 'subscription_id' => $ny, 'belop_ore' => 249000, 'status' => 'betalt',
    'type' => 'epayment', 'formal' => 'medlemskap', 'vipps_reference' => 'MED-' . $tag,
    'idempotency_key' => 'idem-' . $tag]);
Medlemskap::betaltEngangs($ny);
Medlemskap::betaltEngangs($ny);   // returen og webhooken kommer begge
Medlemskap::erstattProve($jo, $ny, $basis);

$gRad = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $gammel]);
$nRad = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $ny]);
$mRad = $medlem($jo);
sjekk('det nye medlemskapet er aktivt', $nRad['status'] === 'aktiv');
sjekk('proeveperioden er avsluttet', $gRad['status'] === 'stoppet' && $gRad['slutter'] === gmdate('Y-m-d'),
    $gRad['status'] . ' ' . $gRad['slutter']);
$logg = (int) DB::verdi("SELECT COUNT(*) FROM audit_log WHERE handling = 'medlemskap_erstattet' AND objekt_id = :m", ['m' => $jo]);
sjekk('… nøyaktig én gang, i endringsloggen', $logg === 1, (string) $logg);
$det = json_decode((string) DB::verdi("SELECT detaljer FROM audit_log WHERE handling = 'medlemskap_erstattet' AND objekt_id = :m", ['m' => $jo]), true);
sjekk('… uten refusjon, og med timene paa proeveperioden', ($det['refusjon'] ?? '') === 'ingen' && ($det['timerBrukt'] ?? '') !== '',
    json_encode($det, JSON_UNESCAPED_UNICODE));
sjekk('medlemmet staar paa det nye, uten proevens sluttdato',
    $mRad['medlemskap_type'] === $basis && $mRad['slutt_dato'] === null && $mRad['status'] === 'aktiv');
$svar = $kaster(static fn() => Medlemskap::startEngangs($medlem($jo), $prove));
sjekk('etter oppgraderingen kan Prøv Lissom ikke kjoepes igjen', $svar === $melding, $svar);

// Eieren vurderer selv timene over de ti — de trekkes ikke fra det nye.
$etter = Stempling::minutterDenneManeden($jo);
sjekk('det nye gjelder alt denne maaneden, og proevetimene teller ikke paa det', $etter <= 1, (string) $etter);
$tak = (int) DB::verdi('SELECT timer FROM membership_plans WHERE navn = :n', ['n' => $basis]);
sjekk('… saa hele timetallet i det nye staar igjen', Medlemskap::timerMedGaver($medlem($jo)) === $tak);
$over = Medlemskap::proveOverMin($medlem($jo), $etter);
$ventetOver = 720 + $paagaar - $proveTimer * 60;
sjekk('timene over ' . $proveTimer . ' staar paa medlemmet til admin', abs($over - $ventetOver) <= 2, "$over, ventet $ventetOver");
$adm = file_get_contents(dirname(__DIR__) . '/api/admin/medlemmer.php');
sjekk('… i medlemslista i admin', str_contains($adm, "'proveOver' => (static function () use (\$m, \$brukt): ?string {"));
$paaProve = $nytt('Staar Paa Proeve', 'aktiv', $prove);
DB::settInn('check_ins', ['member_id' => $paaProve, 'inn_tid' => $t(1), 'ut_tid' => $t(2), 'minutter' => $proveTimer * 60 + 90]);
sjekk('… ogsaa mens hen staar paa proeveperioden',
    Medlemskap::proveOverMin($medlem($paaProve), Stempling::minutterDenneManeden($paaProve)) === 90);

// Brukte hen fire av ti, starter det nye paa null — ingenting foelger med.
$lite = $nytt('Brukte Lite', 'aktiv', $prove);
$lg = DB::settInn('subscriptions', ['member_id' => $lite, 'plan' => $prove, 'pris_ore' => 99000, 'status' => 'aktiv']);
DB::settInn('check_ins', ['member_id' => $lite, 'inn_tid' => $t(1), 'ut_tid' => $t(2), 'minutter' => 240]);
$ln = DB::settInn('subscriptions', ['member_id' => $lite, 'plan' => $basis, 'pris_ore' => 249000, 'status' => 'venter']);
Medlemskap::betaltEngangs($ln);
sjekk('brukte hen fire timer, starter det nye paa null', Stempling::minutterDenneManeden($lite) === 0,
    (string) Stempling::minutterDenneManeden($lite));

// ── 6. Migrasjon 231 ──────────────────────────────────────────────────
$mig = file_get_contents(dirname(__DIR__) . '/db/migrations/231_prove_sluttdato.sql');
sjekk('migrasjonen er godkjent av eieren', str_starts_with($mig, '-- godkjent av eieren: 2026-09-28'));
sjekk('… og hopper over medlem 10 (Ida Kristine) paa id', str_contains($mig, 'AND m.id <> 10;'));
$gml = $nytt('Gammel Proeve', 'aktiv', $prove);
DB::settInn('subscriptions', ['member_id' => $gml, 'plan' => $prove, 'pris_ore' => 99000, 'status' => 'aktiv',
    'binding_til' => '2026-11-02', 'created_at' => '2026-09-02 10:15:00']);
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $mig)))) as $sql) {
    DB::kjor($sql);
}
sjekk('kjoept 2. september uten sluttdato faar 30. september',
    (string) DB::verdi('SELECT slutt_dato FROM members WHERE id = :i', ['i' => $gml]) === '2026-09-30');
sjekk('… og bindingen paa proeveavtalen er borte',
    DB::verdi('SELECT binding_til FROM subscriptions WHERE member_id = :i', ['i' => $gml]) === null);

// ── Rydd ──────────────────────────────────────────────────────────────
$ider = array_column(DB::alle('SELECT id FROM members WHERE epost LIKE :e', ['e' => $tag . '-%']), 'id');
if ($ider !== []) {
    $in = implode(',', array_map('intval', $ider));
    DB::kjor("DELETE FROM payments WHERE member_id IN ($in)");
    DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'member' AND objekt_id IN ($in)");
    DB::kjor("DELETE FROM members WHERE id IN ($in)");
}

$ferdig = true;
echo "\n  $ok ok, $feil feil\n";
exit($feil === 0 ? 0 : 1);
