<?php
/**
 * Godkjenningslenka, ende til ende mot den falske Vippsen.
 *
 * Eieren, 6. september: «men det fungerer ikke, linken virker ikke, saa noe er
 * feil, du maa gjore dette paa en annen maate».
 *
 * Vipps gir avtalen ti minutter. Denne lenka lever i fjorten dager, og lager
 * Vipps-avtalen foerst i det mottakeren trykker. Testen foelger hele veien:
 * noekkelen lages, lenka aapnes, avtalen opprettes i det sekundet, og
 * mottakeren sendes videre til den ferske adressen.
 *
 * Start foerst:
 *   node tests/falsk-vipps.mjs &
 *   LISSOM_VIPPS_BASE=http://127.0.0.1:8125 php -S 127.0.0.1:8126 ekte-ruter.php &
 *
 * Kjor:  php tests/godkjennlenke.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$BASE = getenv('LISSOM_TEST_BASE') ?: 'http://127.0.0.1:8126';
$ok = 0; $feil = 0;

function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

function hent(string $url): array {
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 20,
    ]);
    $r = (string) curl_exec($c);
    $kode = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    $len  = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    return ['kode' => $kode, 'hode' => substr($r, 0, $len), 'kropp' => substr($r, $len)];
}

echo "\n── Godkjenningslenka ────────────────────────────────────────\n";

// Ryddig utgangspunkt: et eget testmedlem, slettet og laget paa nytt.
DB::kjor("DELETE FROM avtale_lenker WHERE plan LIKE 'TEST%'");
$medlemId = (int) (DB::verdi("SELECT id FROM members WHERE epost = 'godkjenn@lissom.test'") ?? 0);
if ($medlemId <= 0) {
    $medlemId = DB::settInn('members', [
        'navn' => 'Godkjenn Testperson', 'epost' => 'godkjenn@lissom.test',
        'telefon' => '4790000099', 'status' => 'aktiv',
    ]);
}
DB::kjor("DELETE FROM avtale_lenker WHERE member_id = :m", ['m' => $medlemId]);
DB::kjor("UPDATE subscriptions SET status = 'stoppet' WHERE member_id = :m", ['m' => $medlemId]);

// Planen som faktisk finnes i basen.
$planNavn = (string) (DB::verdi(
    "SELECT navn FROM membership_plans WHERE krever_fast_trekk = 1 AND aktiv = 1 ORDER BY navn LIMIT 1"
) ?? '');
if ($planNavn === '') {
    $planNavn = (string) (DB::verdi('SELECT navn FROM membership_plans ORDER BY navn LIMIT 1') ?? '');
}
sjekk('finner en plan aa teste med', $planNavn !== '', $planNavn);
DB::oppdater('members', ['medlemskap_type' => $planNavn], ['id' => $medlemId]);

// ── Noekkelen ────────────────────────────────────────────────────────
$lenke = Medlemskap::godkjennLenke($medlemId, $planNavn);
sjekk('lenka staar paa vaart eget domene', str_contains($lenke, '/godkjenn/'), $lenke);
$token = substr($lenke, strrpos($lenke, '/') + 1);
sjekk('… og noekkelen er 32 tegn', strlen($token) === 32, $token);

$igjen = Medlemskap::godkjennLenke($medlemId, $planNavn);
sjekk('… og samme medlem og plan gir samme noekkel', $igjen === $lenke);

$rad = DB::en('SELECT * FROM avtale_lenker WHERE token = :t', ['t' => $token]);
sjekk('… og den lever i fjorten dager',
    $rad !== null && abs(strtotime((string) $rad['utloper']) - (time() + 14 * 86400)) < 120,
    $rad['utloper'] ?? '');

// ── Ukjent noekkel ───────────────────────────────────────────────────
$r = hent($BASE . '/api/godkjenn.php?t=' . str_repeat('a', 32));
sjekk('en ukjent noekkel gir en side, ikke et krasj', $r['kode'] === 200, (string) $r['kode']);
sjekk('… som sier at lenka ikke ble funnet',
    str_contains($r['kropp'], 'Vi fant ikke denne lenka'));
sjekk('… og ikke skal i soket', str_contains($r['hode'], 'X-Robots-Tag: noindex'));

// ── Den ekte veien ───────────────────────────────────────────────────
$forFalsk = @file_get_contents(__DIR__ . '/.falsk-vipps.jsonl') ?: '';
$r = hent($BASE . '/api/godkjenn.php?t=' . $token);
sjekk('lenka sender deg videre', $r['kode'] === 302, (string) $r['kode']);
preg_match('/^Location:\s*(\S+)/mi', $r['hode'], $m);
$videre = $m[1] ?? '';
// Den falske Vippsen svarer med sin egen «vippsConfirmationUrl», akkurat
// som den ekte. Poenget er at vi sender videre til DEN, og ikke til noe vi
// har lagret fra for.
sjekk('… til adressen Vipps ga oss i dette kallet',
    $videre !== '' && !str_contains($videre, '/godkjenn/' . $token), $videre);

$etterFalsk = @file_get_contents(__DIR__ . '/.falsk-vipps.jsonl') ?: '';
$nye = substr($etterFalsk, strlen($forFalsk));
sjekk('… og avtalen ble opprettet i det oyeblikket',
    str_contains($nye, '/recurring/v3/agreements'), substr($nye, 0, 200));

$sub = DB::en(
    "SELECT * FROM subscriptions WHERE member_id = :m ORDER BY id DESC LIMIT 1",
    ['m' => $medlemId]
);
sjekk('… og det staar en avtalerad som venter',
    $sub !== null && (string) $sub['status'] === 'venter', (string) ($sub['status'] ?? 'ingen'));

// ── Utloept noekkel ──────────────────────────────────────────────────
DB::kjor("UPDATE avtale_lenker SET utloper = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE token = :t",
    ['t' => $token]);
$r = hent($BASE . '/api/godkjenn.php?t=' . $token);
sjekk('en utloept lenke sier fra', str_contains($r['kropp'], 'Lenka har gått ut'));
sjekk('… og sier hvor lenge den var gyldig', str_contains($r['kropp'], 'fjorten dager'));
DB::kjor("UPDATE avtale_lenker SET utloper = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 14 DAY) WHERE token = :t",
    ['t' => $token]);

// ── Alt er i orden ───────────────────────────────────────────────────
DB::kjor("UPDATE subscriptions SET status = 'aktiv' WHERE id = :i", ['i' => (int) $sub['id']]);
$r = hent($BASE . '/api/godkjenn.php?t=' . $token);
sjekk('er avtalen alt godkjent, sier sida det', str_contains($r['kropp'], 'Alt er i orden'));
sjekk('… og tilbyr veien til Min side', str_contains($r['kropp'], 'Gå til Min side'));
$brukt = DB::verdi('SELECT brukt_at FROM avtale_lenker WHERE token = :t', ['t' => $token]);
sjekk('… og noekkelen er satt som brukt', $brukt !== null, (string) $brukt);

// Rydd opp.
DB::kjor("UPDATE subscriptions SET status = 'stoppet' WHERE member_id = :m", ['m' => $medlemId]);
DB::kjor('DELETE FROM avtale_lenker WHERE member_id = :m', ['m' => $medlemId]);

echo "\n──────────────────────────────────────────────\n";
echo ($ok + $feil) . " sjekker, $ok gikk gjennom" . ($feil ? ", $feil feilet" : '') . "\n";
exit($feil > 0 ? 1 : 0);
