<?php
/**
 * Bytte av medlemskap og dagens omsetning, mot databasen.
 *
 * Eieren, 29. september 2026: Johanna hadde betalt og blitt trukket for
 * Mini 15, men sto fortsatt med Prøv Lissom i admin — og et kurs betalt i
 * verkstedet (kr 2 800) sto ikke i dagens omsetning. «hvorfor fanges det
 * ikke opp?», og «kan det skje med noen av de andre medlemskapene?».
 *
 *   1. Prøv Lissom → hvert loepende medlemskap: planen som vises er den nye,
 *      én aktiv avtale, og «over Prøv Lissom» staar ikke lenger paa lista.
 *   2. Loepende → et annet (opp, ned) og det samme (fornyelse): sperres paa
 *      serveren, og ingenting endres — planen staar, én aktiv avtale.
 *   3. Omsetningen: et kurs betalt i verkstedet uten betalingsrad er med,
 *      og Oversikt, Okonomi og dagsoppgjoret leser samme kilde.
 *   4. Vaktdata::sjekk() finner et medlem med feil plan, to aktive avtaler
 *      og en betalt paamelding som mangler i omsetningen — og ingenting
 *      paa de riktige.
 *
 * Kjor:  php tests/planbytte.php
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

echo "\n── Bytte av medlemskap ──────────────────────────────────────\n";

$tag = 'bytte-' . bin2hex(random_bytes(3));
$nytt = static function (string $navn, string $status, string $plan) use ($tag): int {
    return DB::settInn('members', [
        'navn' => $navn, 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'telefon' => '+479' . random_int(1000000, 9999999),
        'rolle' => 'medlem', 'status' => $status, 'medlemskap_type' => $plan !== '' ? $plan : null,
        'start_dato' => gmdate('Y-m-d'),
    ]);
};
$medlem  = static fn(int $id): array => DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);
$aktive  = static fn(int $id): array => array_column(DB::alle(
    "SELECT plan FROM subscriptions WHERE member_id = :m AND status = 'aktiv'", ['m' => $id]), 'plan');
$listeRegel = static function (array $m): ?int {
    // Samme regel som «proveOver» i api/admin/medlemmer.php: bare mens hen
    // staar paa proeveperioden.
    return Medlemskap::erEngangs((string) ($m['medlemskap_type'] ?? '')) ? 1 : null;
};

$prove   = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 1 AND aktiv = 1 ORDER BY sortering LIMIT 1');
$loepende = array_column(DB::alle(
    'SELECT navn, krever_fast_trekk FROM membership_plans WHERE engangs = 0 AND aktiv = 1 ORDER BY sortering'), null, 'navn');
sjekk('planene finnes', $prove !== '' && count($loepende) >= 2, $prove . ' | ' . implode(', ', array_keys($loepende)));

$adm = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/api/admin/medlemmer.php'));
sjekk('lista viser «over Prøv Lissom» bare paa proeveperioden',
    str_contains($adm, "if (!Medlemskap::erEngangs((string) (\$m['medlemskap_type'] ?? ''))) {\n            return null;"));
sjekk('… og timene over staar i endringsloggen etter byttet',
    str_contains($adm, "if (\$h === 'medlemskap_erstattet') {"));

// ── 1. Prøv Lissom → hvert loepende medlemskap ────────────────────────
foreach ($loepende as $navn => $p) {
    $id = $nytt('Prove til ' . $navn, 'aktiv', $prove);
    DB::settInn('subscriptions', ['member_id' => $id, 'plan' => $prove, 'pris_ore' => 99000, 'status' => 'aktiv']);
    $pris = (int) DB::verdi('SELECT pris_ore FROM membership_plans WHERE navn = :n', ['n' => $navn]);
    if ((int) $p['krever_fast_trekk'] === 1) {
        // Fast trekk blir aktivt naar Vipps har godkjent — samme to steg som
        // oppdaterFraVipps() gjoer: den gamle erstattes, medlemmet foelger.
        $ny = DB::settInn('subscriptions', ['member_id' => $id, 'plan' => $navn, 'pris_ore' => $pris,
            'status' => 'aktiv', 'vipps_agreement_id' => 'agr-' . $tag . '-' . $id]);
        Medlemskap::erstattProve($id, $ny, $navn);
        DB::oppdater('members', ['status' => 'aktiv', 'medlemskap_type' => $navn], ['id' => $id]);
    } else {
        $ny = DB::settInn('subscriptions', ['member_id' => $id, 'plan' => $navn, 'pris_ore' => $pris, 'status' => 'venter']);
        Medlemskap::betaltEngangs($ny);
    }
    $m = $medlem($id);
    sjekk("$prove → $navn: planen som vises er $navn", $m['medlemskap_type'] === $navn, (string) $m['medlemskap_type']);
    sjekk("… én aktiv avtale, paa $navn", $aktive($id) === [$navn], implode(',', $aktive($id)));
    sjekk('… ikke lenger «over Prøv Lissom» paa lista', $listeRegel($m) === null);
    sjekk('… og ikke Prøv-vinduet paa Min side', !Timepakke::erProve($m));
}

// ── 2. Loepende → annet (opp, ned) og samme (fornyelse) ───────────────
$navnene = array_keys($loepende);
foreach ($navnene as $fra) {
    foreach ($navnene as $til) {
        $id = $nytt("$fra til $til", 'aktiv', $fra);
        DB::settInn('subscriptions', ['member_id' => $id, 'plan' => $fra, 'pris_ore' => 100000, 'status' => 'aktiv',
            'vipps_agreement_id' => (int) $loepende[$fra]['krever_fast_trekk'] === 1 ? 'agr-' . $tag . '-' . $id : null]);
        $svar = $kaster(static fn() => (int) $loepende[$til]['krever_fast_trekk'] === 1
            ? Medlemskap::startAvtale($medlem($id), $til)
            : Medlemskap::startEngangs($medlem($id), $til));
        $m = $medlem($id);
        $hva = $fra === $til ? 'fornyelse' : 'bytte';
        $sperret = 'Du har alt et medlemskap. Si det opp først, eller bytt fra Min side.';
        if (Medlemskap::erStorre($til, $fra)) {
            // Eieren, 29. september 2026: oppgradering til et stoerre
            // medlemskap gjelder fra i dag. Serveren sperrer ikke (Vipps kan
            // ikke naas herfra, saa betalingen startes ikke), og naar det nye
            // er betalt, stopper det gamle i dag uten refusjon.
            sjekk("$fra → $til (oppgradering): sperres ikke", $svar !== $sperret, $svar);
            $pris = (int) DB::verdi('SELECT pris_ore FROM membership_plans WHERE navn = :n', ['n' => $til]);
            DB::kjor("DELETE FROM subscriptions WHERE member_id = :m AND status = 'venter'", ['m' => $id]);
            $ny = DB::settInn('subscriptions', ['member_id' => $id, 'plan' => $til, 'pris_ore' => $pris, 'status' => 'venter',
                'binding_til' => gmdate('Y-m-d', strtotime('+2 months'))]);
            Medlemskap::betaltEngangs($ny);
            $m = $medlem($id);
            $gml = DB::en("SELECT status, slutter FROM subscriptions WHERE member_id = :m AND plan = :p AND id <> :n",
                ['m' => $id, 'p' => $fra, 'n' => $ny]) ?? [];
            sjekk("… betalt: planen er $til, én aktiv avtale", $m['medlemskap_type'] === $til && $aktive($id) === [$til],
                $m['medlemskap_type'] . ' / ' . implode(',', $aktive($id)));
            sjekk('… det gamle er stoppet i dag, uten refusjon',
                ($gml['status'] ?? '') === 'stoppet' && ($gml['slutter'] ?? '') === (string) DB::verdi('SELECT CURDATE()'),
                json_encode($gml));
            continue;
        }
        sjekk("$fra → $til ($hva): sperres paa serveren", $svar === $sperret, $svar === '' ? 'ble ikke sperret' : $svar);
        sjekk("… planen staar paa $fra, én aktiv avtale",
            $m['medlemskap_type'] === $fra && $aktive($id) === [$fra], $m['medlemskap_type'] . ' / ' . implode(',', $aktive($id)));
    }
}

// ── 2b. «Forny» paa et medlemskap som gjores opp selv ─────────────────
echo "\n── Forny ────────────────────────────────────────────────────\n";
$selv = array_key_first(array_filter($loepende, static fn($p) => (int) $p['krever_fast_trekk'] === 0));
$id = $nytt('Forny ' . $selv, 'aktiv', $selv);
$av = DB::settInn('subscriptions', ['member_id' => $id, 'plan' => $selv, 'pris_ore' => 179000, 'status' => 'aktiv']);
$betal = static function (int $id, int $av, string $naar, ?string $fra) use ($tag): void {
    $rad = ['vipps_reference' => 'MED-' . $tag . '-' . bin2hex(random_bytes(3)), 'type' => 'epayment', 'formal' => 'medlemskap',
        'member_id' => $id, 'subscription_id' => $av, 'belop_ore' => 179000, 'status' => 'betalt',
        'idempotency_key' => bin2hex(random_bytes(8)), 'created_at' => $naar . ' 12:00:00'];
    if ($fra !== null) { $rad['gjelder_fra'] = $fra; }
    DB::settInn('payments', $rad);
};
$idag = gmdate('Y-m-d');
$betal($id, $av, gmdate('Y-m-d', strtotime('-20 days')), null);
$siste = Medlemskap::sisteBetalinger([$id])[$id];
$forrigeSlutt = (new DateTimeImmutable(gmdate('Y-m-d', strtotime('-20 days'))))->modify('first day of next month')->format('Y-m-d');
sjekk('uten gjelder_fra dekker betalingen betalingsmåneden', Medlemskap::dekkerTil($siste) === $forrigeSlutt,
    Medlemskap::dekkerTil($siste));
// Fornyelsen betalt i dag, gjeldende fra der forrige slutter.
$betal($id, $av, $idag, $forrigeSlutt);
$siste = Medlemskap::sisteBetalinger([$id])[$id];
$nesteSlutt = gmdate('Y-m-d', strtotime($forrigeSlutt . ' +1 month'));
sjekk('fornyelse foer forfall dekker fra der forrige slutter', Medlemskap::dekkerTil($siste) === $nesteSlutt,
    Medlemskap::dekkerTil($siste));
$b = Medlemskap::betalingsstatus($medlem($id), DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $av]), $siste);
sjekk('… admin sier betalt, neste ' . $nesteSlutt, $b['tilstand'] === 'betalt'
    && str_contains($b['tekst'], Booking::norskDatoKort($nesteSlutt . ' 12:00:00')), $b['tekst']);
sjekk('… og det er fortsatt én aktiv avtale', $aktive($id) === [$selv], implode(',', $aktive($id)));
$api = file_get_contents(dirname(__DIR__) . '/api/medlemskap.php');
sjekk('«Forny» paa samme plan gaar til fornyPeriode(), ikke en ny avtale', str_contains($api, 'Medlemskap::fornyPeriode($medlem, $naa)'));
$lib = file_get_contents(dirname(__DIR__) . '/app/lib/medlemskap.php');
sjekk('fornyPeriode() regner fra der forrige betaling slutter',
    // Pengehull 3 (4. oktober 2026): «der forrige slutter» er den foerste
    // maaneden fra denne som ingen betaling dekker (alle betalte maaneder,
    // ikke bare den siste). Atferden testes i tests/pengehull.php.
    str_contains($lib, "\$gjelderFra = \$d->format('Y-m-d') === \$denne ? \$idag : \$d->format('Y-m-d');")
    && str_contains($lib, "\$rad['gjelder_fra'] = \$gjelderFra;"));

// ── 3. Omsetningen ────────────────────────────────────────────────────
echo "\n── Omsetningen ──────────────────────────────────────────────\n";
$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$dag  = (new DateTimeImmutable('now', $oslo))->setTime(0, 0);
$fra  = $dag->setTimezone($utc)->format('Y-m-d H:i:s');
$til  = $dag->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

$forKurs = Omsetning::perFormal($fra, $til)['booking'] ?? 0;
$kurs = DB::settInn('courses', ['slug' => $tag, 'tittel' => 'TEST ' . $tag, 'type' => 'kurs',
    'pris_ore' => 280000, 'kapasitet' => 8, 'status' => 'publisert']);
$okt = DB::settInn('course_sessions', ['course_id' => $kurs,
    'start_tid' => gmdate('Y-m-d', time() + 864000) . ' 10:00:00', 'kapasitet' => 8]);
// Betalt i verkstedet, uten betalingsrad — slik booking 36 sto 29.09.
$manuell = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $okt,
    'gjest_navn' => 'Test Manuell', 'antall' => 1, 'belop_ore' => 280000, 'status' => 'betalt',
    'betalt_maate' => 'Vipps']);
$etterKurs = Omsetning::perFormal($fra, $til)['booking'] ?? 0;
sjekk('et kurs betalt i verkstedet uten betalingsrad er med i dagens omsetning',
    $etterKurs - $forKurs === 280000, ($etterKurs - $forKurs) . ' oere');

foreach (['api/admin/oversikt.php', 'api/admin/okonomi.php', 'api/admin/dagsoppgjor.php'] as $fil) {
    $k = file_get_contents(dirname(__DIR__) . '/' . $fil);
    sjekk("$fil leser omsetningen fra Omsetning",
        str_contains($k, 'Omsetning::') && !preg_match('/SUM\(belop_ore\s*-\s*refundert_ore\)/', $k));
}

// ── 4. Vakta ──────────────────────────────────────────────────────────
echo "\n── Vakta ────────────────────────────────────────────────────\n";
$v = Vaktdata::sjekk();
$om = static fn(string $s): bool => (bool) array_filter($v['avvik'], static fn($a) => str_contains($a, $s));
sjekk('de riktige medlemmene gir ingen avvik', !$om($tag) && !array_filter($v['avvik'],
    static fn($a) => str_contains($a, 'Prove til ') || str_contains($a, ' til ')), implode(' | ', $v['avvik']));
sjekk('et kurs betalt i verkstedet gir ingen avvik', !$om('påmelding ' . $manuell . ' '));

$feilPlan = $nytt('Feil Plan ' . $tag, 'aktiv', $prove);
DB::settInn('subscriptions', ['member_id' => $feilPlan, 'plan' => $navnene[0], 'pris_ore' => 100000, 'status' => 'aktiv']);
$to = $nytt('To Avtaler ' . $tag, 'aktiv', $navnene[0]);
DB::settInn('subscriptions', ['member_id' => $to, 'plan' => $navnene[0], 'pris_ore' => 100000, 'status' => 'aktiv']);
DB::settInn('subscriptions', ['member_id' => $to, 'plan' => $navnene[1], 'pris_ore' => 100000, 'status' => 'aktiv']);
$pay = DB::settInn('payments', ['belop_ore' => 150000, 'status' => 'venter', 'type' => 'epayment',
    'formal' => 'booking', 'vipps_reference' => 'V-' . $tag, 'idempotency_key' => 'i-' . $tag]);
$mangler = DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $okt,
    'gjest_navn' => 'Test Mangler', 'antall' => 1, 'belop_ore' => 150000, 'status' => 'betalt', 'payment_id' => $pay]);
$v = Vaktdata::sjekk();
$om = static fn(string $s): bool => (bool) array_filter($v['avvik'], static fn($a) => str_contains($a, $s));
sjekk('vakta finner et medlem som vises med feil plan', $om('Feil Plan ' . $tag . ') vises som'), implode(' | ', $v['avvik']));
sjekk('… et medlem med to aktive avtaler', $om('To Avtaler ' . $tag . ') har 2 aktive avtaler'));
sjekk('… og en betalt paamelding som mangler i omsetningen', $om('påmelding ' . $mangler . ' '));

// ── Rydd ──────────────────────────────────────────────────────────────
DB::kjor('DELETE FROM bookings WHERE course_id = :k', ['k' => $kurs]);
DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pay]);
DB::kjor('DELETE FROM course_sessions WHERE course_id = :k', ['k' => $kurs]);
DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kurs]);
$ider = array_column(DB::alle('SELECT id FROM members WHERE epost LIKE :e', ['e' => $tag . '-%']), 'id');
if ($ider !== []) {
    $in = implode(',', array_map('intval', $ider));
    DB::kjor("DELETE FROM payments WHERE member_id IN ($in)");
    DB::kjor("DELETE FROM subscriptions WHERE member_id IN ($in)");
    DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'member' AND objekt_id IN ($in)");
    DB::kjor("DELETE FROM members WHERE id IN ($in)");
}

$ferdig = true;
echo "\n  $ok ok, $feil feil\n";
exit($feil === 0 ? 0 : 1);
