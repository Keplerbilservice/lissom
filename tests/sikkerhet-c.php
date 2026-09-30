<?php
/**
 * Codex-gjennomgangen 30. september 2026, gruppe C, mot databasen.
 *
 *   1. Vipps-innlogging: state maa komme tilbake til nettleseren som
 *      startet den (cookie med hash av state).
 *   2. Nytt passord i admin avslutter alle andre sesjoner paa kontoen —
 *      men ikke den som byttet.
 *   3. Private bilder (deltaker, feilrapport, referanse, uprovet salg) sendes
 *      med «Cache-Control: private».
 *   4. En planlagt frys setter ikke et oppsagt medlemskap paa pause, og
 *      aapner det derfor ikke igjen som aktivt.
 *
 * Kjor:  php tests/sikkerhet-c.php
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

$tag = 'sikc-' . bin2hex(random_bytes(3));
$nytt = static function (string $status) use ($tag): int {
    return DB::settInn('members', [
        'navn' => 'Test ' . $tag, 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'telefon' => '+479' . random_int(1000000, 9999999),
        'rolle' => 'medlem', 'status' => $status,
    ]);
};
$rydd = [];

// ── 1. Vipps-state bundet til nettleseren ────────────────────────────────
echo "\n── Vipps-innlogging ─────────────────────────────────────────\n";
$state = bin2hex(random_bytes(32));
unset($_COOKIE[Vipps::STATE_COOKIE]);
sjekk('uten cookie avvises state', Vipps::stateTilhorerNettleser($state) === false);
Vipps::bindState($state);
sjekk('cookien holder ikke state i klartekst', ($_COOKIE[Vipps::STATE_COOKIE] ?? '') !== $state);
sjekk('samme nettleser godtas', Vipps::stateTilhorerNettleser($state) === true);
sjekk('cookien kan ikke brukes to ganger', Vipps::stateTilhorerNettleser($state) === false);
Vipps::bindState(bin2hex(random_bytes(32)));
sjekk('en annens state avvises', Vipps::stateTilhorerNettleser($state) === false);
$cb = (string) file_get_contents(__DIR__ . '/../api/vipps-callback.php');
$li = (string) file_get_contents(__DIR__ . '/../api/vipps-login.php');
sjekk('callback sjekker nettleseren', str_contains($cb, 'Vipps::stateTilhorerNettleser($state)'));
sjekk('innloggingen binder state', str_contains($li, 'Vipps::bindState($state);'));

// ── 2. Nytt passord avslutter andre sesjoner ─────────────────────────────
echo "\n── Passordbytte ─────────────────────────────────────────────\n";
$m = $nytt('ingen');
$rydd[] = $m;
$tokens = [];
foreach ([1, 2, 3] as $_) {
    $t = bin2hex(random_bytes(32));
    $tokens[] = $t;
    DB::settInn('sessions', [
        'token_hash' => hash('sha256', $t), 'member_id' => $m,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
}
$annen = $nytt('ingen');
$rydd[] = $annen;
$tAnnen = bin2hex(random_bytes(32));
DB::settInn('sessions', ['token_hash' => hash('sha256', $tAnnen), 'member_id' => $annen,
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);

$_COOKIE[Sesjon::COOKIE] = $tokens[0];
$slettet = Sesjon::avsluttAndreFor($m);
sjekk('to andre sesjoner avsluttet', $slettet === 2, (string) $slettet);
sjekk('egen sesjon beholdt', (int) DB::verdi('SELECT COUNT(*) FROM sessions WHERE token_hash = :h',
    ['h' => hash('sha256', $tokens[0])]) === 1);
sjekk('andres sesjoner uroert', (int) DB::verdi('SELECT COUNT(*) FROM sessions WHERE member_id = :m',
    ['m' => $annen]) === 1);
// Admin bytter passordet til en annen: da har ingen av hans sesjoner vaar cookie.
$_COOKIE[Sesjon::COOKIE] = $tAnnen;
Sesjon::avsluttAndreFor($m);
sjekk('bytte for en annen avslutter alle hans', (int) DB::verdi('SELECT COUNT(*) FROM sessions WHERE member_id = :m',
    ['m' => $m]) === 0);
unset($_COOKIE[Sesjon::COOKIE]);
$br = (string) file_get_contents(__DIR__ . '/../api/admin/brukere.php');
sjekk('brukere.php avslutter sesjoner ved nytt passord',
    (bool) preg_match('/isset\(\$data\[\'passord_hash\'\]\)\)\s*\{\s*Sesjon::avsluttAndreFor\(\(int\) \$id\);/', $br));

// ── 3. Private bilder ────────────────────────────────────────────────────
echo "\n── Bilder ───────────────────────────────────────────────────\n";
$bi = (string) file_get_contents(__DIR__ . '/../api/bilde.php');
sjekk('lever() kan si private', str_contains($bi, "header('Cache-Control: ' . (\$privat ? 'private' : 'public') . ', max-age=31536000, immutable');"));
sjekk('tre private kall (deltaker, feil, referanse)', substr_count($bi, 'lever($sti, true);') === 3);
sjekk('uprovet salg er privat', str_contains($bi, "lever(\$sti, \$rad !== null && \$rad['status'] !== 'publisert');"));
sjekk('artikkelbilder er fortsatt offentlige', substr_count($bi, "    lever(\$sti);\n") + substr_count($bi, "    lever(\$sti);\r\n") === 1);

// ── 4. Frys og oppsagt medlemskap ────────────────────────────────────────
echo "\n── Frys ─────────────────────────────────────────────────────\n";
if (!Frys::klar()) {
    sjekk('tabellen medlem_frys finnes', false);
} else {
    $idag = date('Y-m-d');
    $frys = static function (int $medlem, string $fra, string $til, string $for) {
        return DB::settInn('medlem_frys', [
            'member_id' => $medlem, 'fra_dato' => $fra, 'til_dato' => $til,
            'status' => 'godkjent', 'status_for' => $for, 'begrunnelse' => 'test',
        ]);
    };
    // Aktiv da frysen ble godkjent, sagt opp foer startdagen.
    $oppsagt = $nytt('oppsagt');
    $rydd[] = $oppsagt;
    $f1 = $frys($oppsagt, $idag, date('Y-m-d', strtotime('+10 days')), 'aktiv');
    Frys::startForfalte();
    sjekk('oppsagt medlem settes ikke paa pause',
        DB::verdi('SELECT status FROM members WHERE id = :i', ['i' => $oppsagt]) === 'oppsagt');
    DB::oppdater('medlem_frys', ['fra_dato' => date('Y-m-d', strtotime('-20 days')),
        'til_dato' => date('Y-m-d', strtotime('-1 day'))], ['id' => $f1]);
    Frys::gjenapneForfalte();
    sjekk('oppsagt medlem blir ikke aktivt naar frysen er over',
        DB::verdi('SELECT status FROM members WHERE id = :i', ['i' => $oppsagt]) === 'oppsagt');

    // Et loepende medlemskap fryses og kommer tilbake til det samme.
    $prove = $nytt('prove');
    $rydd[] = $prove;
    $f2 = $frys($prove, $idag, date('Y-m-d', strtotime('+10 days')), 'aktiv');
    Frys::startForfalte();
    sjekk('prøvemedlem settes paa pause',
        DB::verdi('SELECT status FROM members WHERE id = :i', ['i' => $prove]) === 'pause');
    sjekk('status_for er statusen paa startdagen',
        DB::verdi('SELECT status_for FROM medlem_frys WHERE id = :i', ['i' => $f2]) === 'prove');
    DB::oppdater('medlem_frys', ['fra_dato' => date('Y-m-d', strtotime('-20 days')),
        'til_dato' => date('Y-m-d', strtotime('-1 day'))], ['id' => $f2]);
    Frys::gjenapneForfalte();
    sjekk('prøvemedlem tilbake til prove',
        DB::verdi('SELECT status FROM members WHERE id = :i', ['i' => $prove]) === 'prove');
}

// ── Rydd ─────────────────────────────────────────────────────────────────
foreach ($rydd as $id) {
    DB::kjor('DELETE FROM sessions WHERE member_id = :m', ['m' => $id]);
    if (Frys::klar()) {
        DB::kjor('DELETE FROM medlem_frys WHERE member_id = :m', ['m' => $id]);
    }
    DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $id]);
}

$ferdig = true;
echo "\n  $ok OK, $feil feil\n";
exit($feil > 0 ? 1 : 0);
