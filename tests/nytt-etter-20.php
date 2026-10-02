<?php
/**
 * Nytt medlemskap kjoept etter den 20., og Prøv Lissom som er over.
 *
 * Eieren, 2. oktober 2026:
 *  - Johanna kjoepte Mini 15 (gjoer opp selv) 29. september, betalte full
 *    pris for to dager og sto «Forfalt 1. oktober». Et NYTT medlemskap
 *    kjoept den 21. eller senere gjelder neste kalendermaaned; tilgang med
 *    en gang, maanedstimene telles for neste maaned.
 *  - Ida hadde Prøv Lissom som sluttet 30. september og sto baade som
 *    «Betalt» og «Venter paa betaling». Den skal staa som sluttet.
 *
 * Kjoeres mot en lokal testbase (lissom_test*) og en falsk Vipps som denne
 * fila starter selv. Ingen ekte Vipps, ingen e-post, ingen SMS.
 *
 *   php tests/nytt-etter-20.php
 */

declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
krev_testdatabase(dirname(__DIR__));

// ── Falsk Vipps ───────────────────────────────────────────────────────────
$port = (int) (getenv('NYTT20_VIPPS_PORT') ?: 8137);
$node = getenv('NODE') ?: 'node';
$nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$env = getenv();
$env['FALSK_VIPPS_PORT'] = (string) $port;
$falsk = proc_open([$node, __DIR__ . '/falsk-vipps.mjs'], [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $rør, null, $env);
if (!is_resource($falsk)) {
    fwrite(STDERR, "Fikk ikke startet den falske Vippsen.\n");
    exit(1);
}
$klar = false;
for ($i = 0; $i < 50 && !$klar; $i++) {
    usleep(100_000);
    $s = @fsockopen('127.0.0.1', $port, $en, $to, 0.2);
    if ($s) { fclose($s); $klar = true; }
}
if (!$klar) {
    proc_terminate($falsk);
    fwrite(STDERR, "Den falske Vippsen svarte ikke paa port {$port}.\n");
    exit(1);
}
putenv('LISSOM_VIPPS_BASE=http://127.0.0.1:' . $port);
$avtaleStyr = __DIR__ . '/.avtale-status';
register_shutdown_function(static function () use ($falsk, $avtaleStyr): void {
    @unlink($avtaleStyr);
    if (is_resource($falsk)) {
        proc_terminate($falsk);
    }
});

require dirname(__DIR__) . '/app/bootstrap.php';

if (Config::vippsBase() !== 'http://127.0.0.1:' . $port) {
    fwrite(STDERR, "Vipps-adressen er ikke den falske. Stopper.\n");
    exit(1);
}

$ok = 0;
$feil = [];
function sjekk(string $hva, bool $bra, string $detalj = ''): void
{
    global $ok, $feil;
    if ($bra) { $ok++; echo "  ✓ {$hva}\n"; return; }
    $feil[] = $hva . ($detalj !== '' ? ' — ' . $detalj : '');
    echo "  ✗ {$hva}" . ($detalj !== '' ? " — {$detalj}" : '') . "\n";
}

// ── Rydding ──────────────────────────────────────────────────────────────
$rydd = static function (): void {
    $ider = array_map('intval', array_column(
        DB::alle("SELECT id FROM members WHERE epost LIKE 'nytt20-%@example.test'"), 'id'));
    if ($ider === []) {
        return;
    }
    $inn = implode(',', $ider);
    DB::kjor("DELETE FROM timepakker WHERE member_id IN ({$inn})");
    DB::kjor("DELETE FROM check_ins WHERE member_id IN ({$inn})");
    DB::kjor("DELETE FROM notifications WHERE mottaker LIKE 'nytt20-%@example.test'");
    DB::kjor("DELETE FROM payments WHERE member_id IN ({$inn})");
    DB::kjor("DELETE FROM subscriptions WHERE member_id IN ({$inn})");
    DB::kjor("DELETE FROM members WHERE id IN ({$inn})");
};
$rydd();

// Planene slik de staar i basen — ingen navn eller priser skrevet av.
$planer = DB::alle('SELECT * FROM membership_plans WHERE aktiv = 1 ORDER BY sortering');
$selv = null; $fast = null; $prove = null;
foreach ($planer as $p) {
    if ((int) $p['engangs'] === 1) { $prove ??= $p; continue; }
    if ((int) ($p['krever_fast_trekk'] ?? 0) === 1) { $fast ??= $p; continue; }
    $selv ??= $p;
}
if ($selv === null || $fast === null || $prove === null) {
    fwrite(STDERR, "Fant ikke de tre plantypene i basen.\n");
    exit(1);
}

$nr = 0;
$nyttMedlem = static function (string $plan, string $status = 'aktiv', array $mer = []) use (&$nr): array {
    $nr++;
    $id = (int) DB::settInn('members', array_merge([
        'navn' => 'Nytt20 Test ' . $nr, 'epost' => 'nytt20-' . $nr . '-' . bin2hex(random_bytes(3)) . '@example.test',
        'rolle' => 'medlem', 'status' => $status, 'medlemskap_type' => $plan,
        'vipps_sub' => 'test-nytt20-' . bin2hex(random_bytes(4)),
    ], $mer));
    return DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);
};
$betaling = static function (int $medlemId, ?int $sub, string $laget, ?string $fra, int $ore, string $status = 'betalt'): int {
    $rad = [
        'vipps_reference' => 'NYTT20-' . bin2hex(random_bytes(6)),
        'type' => 'epayment', 'formal' => 'medlemskap', 'member_id' => $medlemId,
        'belop_ore' => $ore, 'status' => $status, 'idempotency_key' => Vipps::uuid(),
        'created_at' => $laget,
    ];
    if ($sub !== null) { $rad['subscription_id'] = $sub; }
    if ($fra !== null) { $rad['gjelder_fra'] = $fra; }
    return (int) DB::settInn('payments', $rad);
};

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');

echo "Nytt medlemskap etter den 20.\n", str_repeat('─', 46), "\n";

// ── 1. Regelen: dag 20 mot dag 21, i norsk tid ─────────────────────────────
echo "\n== Regelen ==\n";
$g = static fn(string $kjopt, ?string $naa = null) => Medlemskap::gjelderFraNytt(0, (string) $selv['navn'], $kjopt, $naa ?? $kjopt);
sjekk('kjoept 20. september 23.59 norsk tid teller september',
    $g('2026-09-20 21:59:59') === null);
sjekk('kjoept 21. september 00.00 norsk tid gjelder fra 1. oktober',
    $g('2026-09-20 22:00:00') === '2026-10-01', (string) $g('2026-09-20 22:00:00'));
sjekk('Johanna: kjoept 29. september gjelder fra 1. oktober',
    $g('2026-09-29 10:00:00') === '2026-10-01');
sjekk('maanedsskiftet: 31. oktober 23.30 norsk tid gjelder fra 1. november',
    $g('2026-10-31 22:30:00') === '2026-11-01', (string) $g('2026-10-31 22:30:00'));
sjekk('… mens 1. november 00.30 norsk tid (31.10 i UTC) teller november',
    $g('2026-10-31 23:30:00') === null);
sjekk('desember → januar: kjoept 28. desember gjelder fra 1. januar',
    $g('2026-12-28 10:00:00') === '2027-01-01', (string) $g('2026-12-28 10:00:00'));
sjekk('engangsplanen (Prøv Lissom) gjelder fortsatt kjoepsmaaneden',
    Medlemskap::gjelderFraNytt(0, (string) $prove['navn'], '2026-09-29 10:00:00', '2026-09-29 10:00:00') === null);
sjekk('kommer foerste betaling foerst etter neste maaned, teller den maaneden den betales',
    $g('2026-09-29 10:00:00', '2026-11-02 10:00:00') === null);

// Oppgradering / bytte: hen har alt en betalt periode som loeper.
$mOpp = $nyttMedlem((string) $selv['navn']);
$betaling((int) $mOpp['id'], null, '2026-09-05 10:00:00', null, (int) $selv['pris_ore']);
sjekk('et medlem med betalt periode som loeper er ikke et nytt medlemskap',
    Medlemskap::gjelderFraNytt((int) $mOpp['id'], (string) $selv['navn'], '2026-09-25 10:00:00', '2026-09-25 10:00:00') === null);
// Fra Prøv Lissom til et loepende medlemskap: proeveperioden teller ikke.
$mFraProve = $nyttMedlem((string) $prove['navn'], 'prove');
$sProve = (int) DB::settInn('subscriptions', ['member_id' => $mFraProve['id'], 'plan' => $prove['navn'],
    'pris_ore' => $prove['pris_ore'], 'status' => 'aktiv', 'vipps_agreement_id' => null]);
$betaling((int) $mFraProve['id'], $sProve, '2026-09-05 10:00:00', null, (int) $prove['pris_ore']);
sjekk('fra Prøv Lissom til et loepende medlemskap etter den 20. er nytt',
    Medlemskap::gjelderFraNytt((int) $mFraProve['id'], (string) $selv['navn'], '2026-09-25 10:00:00', '2026-09-25 10:00:00') === '2026-10-01');

// ── 2. Johanna: gjoer opp selv, kjoept 29. september ──────────────────────
echo "\n== Gjoer opp selv (Johanna) ==\n";
$mJ = $nyttMedlem((string) $selv['navn']);
$fraJ = Medlemskap::gjelderFraNytt((int) $mJ['id'], (string) $selv['navn'], '2026-09-29 10:00:00', '2026-09-29 10:00:00');
$pJ = $betaling((int) $mJ['id'], null, '2026-09-29 10:00:00', $fraJ, (int) $selv['pris_ore']);
$radJ = DB::en('SELECT * FROM payments WHERE id = :i', ['i' => $pJ]);
sjekk('betalingen gjelder oktober', (string) $radJ['gjelder_fra'] === '2026-10-01');
sjekk('beloepet er planens fulle pris for oktober, i oere (' . Booking::kroner((int) $selv['pris_ore']) . ')',
    (int) $radJ['belop_ore'] === (int) $selv['pris_ore']);
sjekk('dekket til 1. november, ikke 1. oktober', Medlemskap::dekkerTil($radJ) === '2026-11-01');
sjekk('tilgang fra kjoepsdagen 29. september', Medlemskap::harBetaltPeriode($mJ, '2026-09-29'));
sjekk('… og 30. september (gratis resten av maaneden)', Medlemskap::harBetaltPeriode($mJ, '2026-09-30'));
sjekk('… og hele oktober', Medlemskap::harBetaltPeriode($mJ, '2026-10-01') && Medlemskap::harBetaltPeriode($mJ, '2026-10-31'));
sjekk('ingen tilgang dagen foer kjoepet', !Medlemskap::harBetaltPeriode($mJ, '2026-09-28'));
sjekk('forfaller 1. november', !Medlemskap::harBetaltPeriode($mJ, '2026-11-01'));

// Vakten: en betaling fram i tid som IKKE har formen, aapner ingenting naa.
$mVakt = $nyttMedlem((string) $selv['navn']);
$betaling((int) $mVakt['id'], null, '2026-09-15 10:00:00', '2026-10-01', (int) $selv['pris_ore']);
sjekk('kjoept 15. med gjelder_fra 1. neste maaned gir ikke tilgang i september',
    !Medlemskap::harBetaltPeriode($mVakt, '2026-09-20'));
$mVakt2 = $nyttMedlem((string) $selv['navn']);
$betaling((int) $mVakt2['id'], null, '2026-09-25 10:00:00', '2026-11-01', (int) $selv['pris_ore']);
sjekk('gjelder_fra to maaneder fram gir ikke tilgang i september',
    !Medlemskap::harBetaltPeriode($mVakt2, '2026-09-26'));
$mVakt3 = $nyttMedlem((string) $selv['navn']);
$betaling((int) $mVakt3['id'], null, '2026-09-25 10:00:00', '2026-10-01', (int) $selv['pris_ore'], 'venter');
sjekk('en betaling som ikke er gjennomfoert gir ikke tilgang',
    !Medlemskap::harBetaltPeriode($mVakt3, '2026-09-26'));

// startEngangs() bruker den samme regelen (mot den falske Vippsen).
$mStart = $nyttMedlem((string) $selv['navn'], 'ingen');
$forventet = Medlemskap::gjelderFraNytt((int) $mStart['id'], (string) $selv['navn']);
$ut = Medlemskap::startEngangs($mStart, (string) $selv['navn']);
$radStart = DB::en('SELECT * FROM payments WHERE subscription_id = :s', ['s' => (int) $ut['id']]);
sjekk('startEngangs() setter gjelder_fra etter regelen (i dag: '
        . ($forventet ?? 'ingen, kjoepsmaaneden') . ')',
    $radStart !== null && ($radStart['gjelder_fra'] ?? null) === $forventet
    && (int) $radStart['belop_ore'] === (int) $selv['pris_ore']);
sjekk('… og med idempotensnoekkel', trim((string) ($radStart['idempotency_key'] ?? '')) !== '');

// ── 3. Fast trekk (aarsmedlemskapet) ──────────────────────────────────────
echo "\n== Fast trekk ==\n";
file_put_contents($avtaleStyr, 'ACTIVE');
$fastScenario = static function (string $kjopt) use ($nyttMedlem, $fast): array {
    $m = $nyttMedlem((string) $fast['navn'], 'ingen');
    $ut = Medlemskap::startAvtale($m, (string) $fast['navn']);
    DB::oppdater('subscriptions', ['created_at' => $kjopt], ['id' => (int) $ut['id']]);
    $a = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => (int) $ut['id']]);
    $naa = (new DateTimeImmutable($kjopt, new DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d H:i:s');
    $status = Medlemskap::oppdaterFraVipps($a, $naa);
    $a = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => (int) $ut['id']]);
    $forste = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND idempotency_key LIKE 'init:%'", ['s' => (int) $a['id']]);
    if ($forste !== null) {
        DB::oppdater('payments', ['created_at' => $naa], ['id' => (int) $forste['id']]);
        $forste = DB::en('SELECT * FROM payments WHERE id = :i', ['i' => (int) $forste['id']]);
    }
    return [$m, $a, $forste, $status];
};

[$mF, $aF, $forsteF, $stF] = $fastScenario('2026-09-25 08:00:00');
sjekk('avtalen er aktiv etter godkjenning', $stF === 'aktiv');
sjekk('foerste trekk (ved godkjenning 25. september) gjelder oktober',
    $forsteF !== null && (string) $forsteF['gjelder_fra'] === '2026-10-01', (string) ($forsteF['gjelder_fra'] ?? 'mangler'));
sjekk('neste trekk er 1. november — trekkdag den 1. (eieren 2.10), ikke oktober', (string) $aF['neste_trekk'] === '2026-11-01', (string) $aF['neste_trekk']);
sjekk('tilgang med en gang', Medlemskap::harBetaltPeriode(DB::en('SELECT * FROM members WHERE id = :i', ['i' => $mF['id']]), '2026-09-25'));
// Trekkrunden ber om november — og bare én gang.
$aT = $aF + ['navn' => (string) $mF['navn'], 'epost' => '', 'telefon' => ''];
Medlemskap::trekk($aT);
$nov = DB::en("SELECT * FROM payments WHERE subscription_id = :s AND type = 'recurring_charge' AND idempotency_key NOT LIKE 'init:%'",
    ['s' => (int) $aF['id']]);
sjekk('trekket 1. november gjelder november', $nov !== null && (string) $nov['gjelder_fra'] === '2026-11-01',
    (string) ($nov['gjelder_fra'] ?? 'mangler'));
sjekk('… med samme beloep som avtalen (' . Booking::kroner((int) $fast['pris_ore']) . ')',
    $nov !== null && (int) $nov['belop_ore'] === (int) $aF['pris_ore']);
sjekk('… og samme runde to ganger gir ikke et trekk til', Medlemskap::trekk($aT) === 'alt fort');
$maaneder = array_map(static fn(array $r): string => Medlemskap::dekkerTil($r),
    DB::alle("SELECT created_at, gjelder_fra FROM payments WHERE subscription_id = :s", ['s' => (int) $aF['id']]));
sjekk('ingen maaned er betalt to ganger', count($maaneder) === count(array_unique($maaneder)), implode(', ', $maaneder));

[$mF20, $aF20, $forsteF20] = $fastScenario('2026-09-20 08:00:00');
sjekk('kjoept 20. september: foerste trekk teller september (som foer)',
    $forsteF20 !== null && $forsteF20['gjelder_fra'] === null);
sjekk('… og neste trekk er 1. oktober (trekkdag den 1.)', (string) $aF20['neste_trekk'] === '2026-10-01', (string) $aF20['neste_trekk']);

[$mFDes, $aFDes, $forsteFDes] = $fastScenario('2026-12-28 09:00:00');
sjekk('desember → januar: trekk 28. desember gjelder januar, neste 1. februar',
    $forsteFDes !== null && (string) $forsteFDes['gjelder_fra'] === '2027-01-01'
    && (string) $aFDes['neste_trekk'] === '2027-02-01', ($forsteFDes['gjelder_fra'] ?? '-') . ' / ' . $aFDes['neste_trekk']);
@unlink($avtaleStyr);

// ── 4. Registrert i Kassa (meldt inn i verkstedet) ────────────────────────
echo "\n== Betalt i verkstedet ==\n";
$forrigeMnd = (new DateTimeImmutable('now', $oslo))->modify('first day of this month')->modify('-1 month');
$denne1 = (new DateTimeImmutable('now', $oslo))->modify('first day of this month')->format('Y-m-d');
$kjopFor = $forrigeMnd->setDate((int) $forrigeMnd->format('Y'), (int) $forrigeMnd->format('n'), 25)->setTime(12, 0)
    ->setTimezone($utc)->format('Y-m-d H:i:s');
$mK = $nyttMedlem((string) $selv['navn']);
$sK = (int) DB::settInn('subscriptions', ['member_id' => $mK['id'], 'plan' => $selv['navn'],
    'pris_ore' => $selv['pris_ore'], 'status' => 'aktiv', 'vipps_agreement_id' => null, 'created_at' => $kjopFor]);
sjekk('foerste betaling for et medlemskap startet den 25. forrige maaned gjelder denne maaneden',
    Medlemskap::gjelderFraForsteBetaling((int) $mK['id']) === $denne1);
$betaling((int) $mK['id'], $sK, gmdate('Y-m-d H:i:s'), $denne1, (int) $selv['pris_ore']);
sjekk('… men neste betaling paa samme avtale er ikke foerste, og faar ingen', Medlemskap::gjelderFraForsteBetaling((int) $mK['id']) === null);

// ── 5. Timene dobles ikke ─────────────────────────────────────────────────
echo "\n== Maanedstimer ==\n";
$manedStart = Stempling::manedStart();
$innFor = $forrigeMnd->setDate((int) $forrigeMnd->format('Y'), (int) $forrigeMnd->format('n'), 27)->setTime(10, 0)
    ->setTimezone($utc)->format('Y-m-d H:i:s');
$innNaa = (new DateTimeImmutable($manedStart, $utc))->modify('+5 minutes')->format('Y-m-d H:i:s');
$stemple = static function (int $m, string $inn, int $min): void {
    DB::settInn('check_ins', ['member_id' => $m, 'inn_tid' => $inn,
        'ut_tid' => (new DateTimeImmutable($inn, new DateTimeZone('UTC')))->modify('+' . $min . ' minutes')->format('Y-m-d H:i:s'),
        'minutter' => $min]);
};
$kjop26 = $forrigeMnd->setDate((int) $forrigeMnd->format('Y'), (int) $forrigeMnd->format('n'), 26)->setTime(12, 0)
    ->setTimezone($utc)->format('Y-m-d H:i:s');
$mT = $nyttMedlem((string) $selv['navn']);
$betaling((int) $mT['id'], null, $kjop26, $denne1, (int) $selv['pris_ore']);
$stemple((int) $mT['id'], $innFor, 180);
$stemple((int) $mT['id'], $innNaa, 60);
sjekk('nytt medlem: tre timer etter kjoepet forrige maaned + én time naa = fire timer denne maaneden',
    Stempling::minutterDenneManeden((int) $mT['id']) === 240, (string) Stempling::minutterDenneManeden((int) $mT['id']));
// «Forny» foer maanedsskiftet: forrige maaned var betalt, og timene der hoerer til den.
$mFo = $nyttMedlem((string) $selv['navn']);
$kjop05 = $forrigeMnd->setDate((int) $forrigeMnd->format('Y'), (int) $forrigeMnd->format('n'), 5)->setTime(12, 0)
    ->setTimezone($utc)->format('Y-m-d H:i:s');
$betaling((int) $mFo['id'], null, $kjop05, null, (int) $selv['pris_ore']);
$betaling((int) $mFo['id'], null, $kjop26, $denne1, (int) $selv['pris_ore']);
$stemple((int) $mFo['id'], $innFor, 180);
$stemple((int) $mFo['id'], $innNaa, 60);
sjekk('«Forny» uendret: bare timene denne maaneden teller (én time)',
    Stempling::minutterDenneManeden((int) $mFo['id']) === 60, (string) Stempling::minutterDenneManeden((int) $mFo['id']));
sjekk('«Forny» uendret: et betalende medlem faar ingen ny gjelder_fra av regelen',
    Medlemskap::gjelderFraNytt((int) $mFo['id'], (string) $selv['navn'], $kjop26, $kjop26) === null);

// ── 6. Prøv Lissom som er over (Ida) ──────────────────────────────────────
echo "\n== Prøv Lissom over ==\n";
$igaar = (new DateTimeImmutable('now', $oslo))->modify('-1 day')->format('Y-m-d');
$idag  = (new DateTimeImmutable('now', $oslo))->format('Y-m-d');
$mI = $nyttMedlem((string) $prove['navn'], 'prove', ['start_dato' => '2026-09-07', 'slutt_dato' => $igaar]);
$sI = (int) DB::settInn('subscriptions', ['member_id' => $mI['id'], 'plan' => $prove['navn'],
    'pris_ore' => $prove['pris_ore'], 'status' => 'aktiv', 'vipps_agreement_id' => null]);
$betaling((int) $mI['id'], $sI, '2026-09-07 10:00:00', null, (int) $prove['pris_ore']);
$siste = Medlemskap::sisteBetalinger([(int) $mI['id']])[(int) $mI['id']] ?? null;
$avtI = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $sI]);
$bI = Medlemskap::betalingsstatus($mI, $avtI, $siste, Medlemskap::sisteTrekk([$sI])[$sI] ?? null);
sjekk('Ida staar som «over», ikke «betalt»', $bI['tilstand'] === 'over', $bI['tilstand']);
sjekk('… med teksten «' . $bI['tekst'] . '»',
    $bI['tekst'] === $prove['navn'] . ' sluttet ' . Booking::norskDatoKort($igaar . ' 12:00:00'));
sjekk('… ikke utestaaende (ute av «Ikke betalt» i Kassa og tellingen), ikke forfalt',
    $bI['utestaaende'] === false && $bI['forfalt'] === false);
sjekk('… og ikke «Venter paa betaling» (admin, Kassa, Min side)',
    Medlemskap::betalingMangler($mI, er_aktivt_medlem($mI)) === false);
sjekk('… og ingen medlemstilgang', !er_aktivt_medlem($mI));
sjekk('proveSluttet() gir sluttdatoen', Medlemskap::proveSluttet($mI) === $igaar);
$mI2 = $nyttMedlem((string) $prove['navn'], 'prove', ['start_dato' => $idag, 'slutt_dato' => $idag]);
$sI2 = (int) DB::settInn('subscriptions', ['member_id' => $mI2['id'], 'plan' => $prove['navn'],
    'pris_ore' => $prove['pris_ore'], 'status' => 'aktiv', 'vipps_agreement_id' => null]);
$betaling((int) $mI2['id'], $sI2, gmdate('Y-m-d H:i:s'), null, (int) $prove['pris_ore']);
$bI2 = Medlemskap::betalingsstatus($mI2, null, Medlemskap::sisteBetalinger([(int) $mI2['id']])[(int) $mI2['id']] ?? null);
sjekk('Prøv Lissom som gjelder i dag er fortsatt betalt', $bI2['tilstand'] === 'betalt'
    && Medlemskap::proveSluttet($mI2) === null && er_aktivt_medlem($mI2));
$mUb = $nyttMedlem((string) $selv['navn']);
sjekk('et loepende medlem uten betaling mangler fortsatt betaling',
    Medlemskap::betalingMangler($mUb, er_aktivt_medlem($mUb)) === true
    && Medlemskap::betalingsstatus($mUb, null, null)['utestaaende'] === true);

// ── 7. Migrasjon 244 ──────────────────────────────────────────────────────
echo "\n== Migrasjon 244 ==\n";
$mig = file_get_contents(dirname(__DIR__) . '/db/migrations/244_nytt_medlemskap_etter_20.sql');
$mm = [];
$lag = static function (string $hva, string $plan, string $laget, ?string $fra = null, string $status = 'betalt') use ($nyttMedlem, $betaling, &$mm): int {
    $m = $nyttMedlem($plan);
    $p = $betaling((int) $m['id'], null, $laget, $fra, 100);
    $mm[$hva] = $p;
    return $p;
};
$lag('johanna', (string) $selv['navn'], '2026-09-29 10:00:00');
$lag('dag21_00', (string) $selv['navn'], '2026-09-20 22:00:00');
$lag('dag20_2359', (string) $selv['navn'], '2026-09-20 21:59:59');
$lag('prove', (string) $prove['navn'], '2026-09-25 10:00:00');
$lag('har_fra', (string) $selv['navn'], '2026-09-25 10:00:00', '2026-10-01');
$lag('okt_05', (string) $selv['navn'], '2026-10-05 10:00:00');
// Fast trekk kjoept 25. september: neste trekk staar alt paa oktober, saa
// foerste trekk skal ikke flyttes (da ville oktober blitt trukket to ganger).
$mVa = $nyttMedlem((string) $fast['navn']);
$sVa = (int) DB::settInn('subscriptions', ['member_id' => $mVa['id'], 'plan' => $fast['navn'], 'pris_ore' => $fast['pris_ore'],
    'status' => 'aktiv', 'vipps_agreement_id' => 'agr_nytt20_' . bin2hex(random_bytes(4)), 'neste_trekk' => '2026-10-25',
    'created_at' => '2026-09-25 08:00:00']);
$mm['fast_trekk'] = $betaling((int) $mVa['id'], $sVa, '2026-09-25 08:05:00', null, 100);
DB::oppdater('payments', ['type' => 'recurring_charge'], ['id' => $mm['fast_trekk']]);
$mTo = $nyttMedlem((string) $selv['navn']);
$mm['to_betalinger'] = $betaling((int) $mTo['id'], null, '2026-09-25 10:00:00', null, 100);
$betaling((int) $mTo['id'], null, '2026-10-01 10:00:00', null, 100);
DB::kobling()->exec($mig);
$etter = static fn(string $hva) => DB::verdi('SELECT gjelder_fra FROM payments WHERE id = :i', ['i' => $mm[$hva]]);
sjekk('migrasjonen setter Johanna-raden til 1. oktober', $etter('johanna') === '2026-10-01');
sjekk('… og 21. september 00.00 norsk tid', $etter('dag21_00') === '2026-10-01');
sjekk('… men ikke 20. september 23.59', $etter('dag20_2359') === null);
sjekk('… ikke Prøv Lissom', $etter('prove') === null);
sjekk('… ikke en rad som alt har gjelder_fra', $etter('har_fra') === '2026-10-01');
sjekk('… ikke dag 5', $etter('okt_05') === null);
sjekk('… og ikke et medlem som alt har betalt igjen (sees paa for haand)', $etter('to_betalinger') === null);
sjekk('… og ikke fast trekk, der neste trekk alt er satt (ingen dobbel oktober)', $etter('fast_trekk') === null
    && DB::verdi('SELECT neste_trekk FROM subscriptions WHERE id = :i', ['i' => $sVa]) === '2026-10-25');
$foer = DB::alle('SELECT id, gjelder_fra FROM payments ORDER BY id');
DB::kobling()->exec($mig);
sjekk('andre kjoering endrer ingenting', DB::alle('SELECT id, gjelder_fra FROM payments ORDER BY id') === $foer);
foreach ($mm as $p) {
    DB::kjor('DELETE FROM payments WHERE id = :i', ['i' => $p]);
}

// ── 8. Migrasjon 246: fra Prøv Lissom til vanlig medlemskap ──────────────
//
// Eieren, 2. oktober 2026: Johanna hadde Prøv Lissom (Vipps 2. september
// 09.34) foer Mini 15 (Vipps 29. september 11.48). 244 hoppet over henne.
echo "\n== Migrasjon 246 (Prøv Lissom → medlem) ==\n";
$mig246 = file_get_contents(dirname(__DIR__) . '/db/migrations/246_prove_til_medlem_etter_20.sql');
$medProve = static function (string $proveTid, ?string $proveSub = 'ja') use ($nyttMedlem, $betaling, $selv, $prove): array {
    $m = $nyttMedlem((string) $selv['navn']);
    $sP = null;
    if ($proveSub !== null) {
        $sP = (int) DB::settInn('subscriptions', ['member_id' => $m['id'], 'plan' => $prove['navn'],
            'pris_ore' => $prove['pris_ore'], 'status' => 'stoppet', 'vipps_agreement_id' => null]);
    }
    $betaling((int) $m['id'], $sP, $proveTid, null, (int) $prove['pris_ore']);
    return $m;
};
$medAvtale = static function (array $m, string $tid) use ($betaling, $selv): int {
    $s = (int) DB::settInn('subscriptions', ['member_id' => $m['id'], 'plan' => $selv['navn'],
        'pris_ore' => $selv['pris_ore'], 'status' => 'aktiv', 'vipps_agreement_id' => null, 'created_at' => $tid]);
    return $betaling((int) $m['id'], $s, $tid, null, (int) $selv['pris_ore']);
};
// Johanna
$mJo = $medProve('2026-09-02 07:34:00');
$pJo = $medAvtale($mJo, '2026-09-29 09:48:00');
// Kontroller
$mVanlig = $nyttMedlem((string) $selv['navn']);
$betaling((int) $mVanlig['id'], null, '2026-08-10 10:00:00', null, (int) $selv['pris_ore']);
$pVanlig = $medAvtale($mVanlig, '2026-09-25 10:00:00');
$mUtenAvtale = $medProve('2026-09-03 10:00:00', null);
$pUtenAvtale = $medAvtale($mUtenAvtale, '2026-09-25 10:00:00');
$mDag20 = $medProve('2026-09-02 10:00:00');
$pDag20 = $medAvtale($mDag20, '2026-09-20 21:59:59');
$mSenere = $medProve('2026-09-02 10:00:00');
$pSenere = $medAvtale($mSenere, '2026-09-25 10:00:00');
$betaling((int) $mSenere['id'], null, '2026-10-01 08:00:00', null, (int) $selv['pris_ore']);

DB::kobling()->exec($mig);
$gf = static fn(int $p) => DB::verdi('SELECT gjelder_fra FROM payments WHERE id = :i', ['i' => $p]);
sjekk('244 treffer ikke Johanna (hun hadde Prøv Lissom foerst)', $gf($pJo) === null);
DB::kobling()->exec($mig246);
sjekk('246: Johanna (Prøv 2.9 + Mini 15 29.9) gjelder fra 1. oktober', $gf($pJo) === '2026-10-01', (string) $gf($pJo));
sjekk('… det samme som koden gir (gjelderFraNytt: Prøv Lissom → vanlig er nytt)',
    Medlemskap::gjelderFraNytt((int) $mJo['id'], (string) $selv['navn'], '2026-09-29 09:48:00', '2026-09-29 09:48:00') === $gf($pJo));
$mJoNaa = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $mJo['id']]);
sjekk('… tilgang naa (2. oktober) og ut oktober', Medlemskap::harBetaltPeriode($mJoNaa, '2026-10-02')
    && Medlemskap::harBetaltPeriode($mJoNaa, '2026-10-31'));
sjekk('… og forfaller 1. november', !Medlemskap::harBetaltPeriode($mJoNaa, '2026-11-01')
    && Medlemskap::dekkerTil(DB::en('SELECT created_at, gjelder_fra FROM payments WHERE id = :i', ['i' => $pJo])) === '2026-11-01');
sjekk('… beloepet staar urort (' . Booking::kroner((int) $selv['pris_ore']) . ')',
    (int) DB::verdi('SELECT belop_ore FROM payments WHERE id = :i', ['i' => $pJo]) === (int) $selv['pris_ore']);
sjekk('246 treffer ikke et medlem med tidligere vanlig betaling', $gf($pVanlig) === null);
sjekk('… ikke Prøv Lissom uten avtale (kan ikke kjennes igjen)', $gf($pUtenAvtale) === null);
sjekk('… ikke kjoep 20. september 23.59', $gf($pDag20) === null);
sjekk('… og ikke den som alt har betalt igjen', $gf($pSenere) === null);
$foer = DB::alle('SELECT id, gjelder_fra FROM payments ORDER BY id');
DB::kobling()->exec($mig246);
sjekk('246 andre kjoering endrer ingenting', DB::alle('SELECT id, gjelder_fra FROM payments ORDER BY id') === $foer);

$rydd();
echo str_repeat('─', 46), "\n", $ok, ' av ', $ok + count($feil), " sjekker gikk gjennom\n";
if ($feil) {
    echo "\nFEIL:\n - ", implode("\n - ", $feil), "\n";
    exit(1);
}
