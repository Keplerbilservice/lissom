<?php
/**
 * Vervepremien, mot databasen.
 *
 * Eieren, 27. september 2026: «verv en venn til aarsmedlemskap og faa 5
 * timer til bruk i verkstedet, jeg maa kunne endre antall timer de faar».
 *
 * Testen lager en verver og venner, en medlemsordre med vervekoden og en
 * avtale, og kaller Verving::premier() slik Medlemskap::oppdaterFraVipps()
 * gjor naar avtalen blir aktiv. Den viser at premien gis én gang, med
 * eierens timetall, og ikke naar bryteren er av, ved selvverving, for en
 * venn som har vaert medlem foer, eller paa et annet medlemskap.
 *
 * Kjor:  php tests/verving.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

echo "\n── Vervepremien ─────────────────────────────────────────────\n";

sjekk('migrasjon 224 er kjort', Verving::klar());

// Bryteren og timene slik de sto, saa testen kan sette dem tilbake.
$forBryter = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/verving'");
$forTimer  = DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'verving_timer'");
sjekk('bryteren staar av etter migrasjonen', !Verving::paa() || $forBryter === 'ja');
$bryter = static function (bool $paa): void {
    DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/verving', :v)
              ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)", ['v' => $paa ? 'ja' : 'nei']);
};

$aar   = (string) DB::verdi('SELECT navn FROM membership_plans WHERE binding_mnd >= 12 ORDER BY id LIMIT 1');
$annen = (string) DB::verdi('SELECT navn FROM membership_plans WHERE binding_mnd < 12 ORDER BY id LIMIT 1');
sjekk('aarsmedlemskapet finnes', $aar !== '');

$sporTag = 'verv-' . bin2hex(random_bytes(3));
$nyttMedlem = static function (string $navn, string $status) use ($sporTag): int {
    return DB::settInn('members', [
        'navn' => $navn, 'epost' => $sporTag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'rolle' => 'medlem', 'status' => $status,
    ]);
};
// En innmelding fra vervelenka, og avtalen den ble til.
$innmelding = static function (int $vennId, string $plan, string $kode): int {
    $avtale = DB::settInn('subscriptions', [
        'member_id' => $vennId, 'plan' => $plan, 'pris_ore' => 100, 'status' => 'venter',
    ]);
    DB::settInn('medlemsordrer', [
        'token' => bin2hex(random_bytes(16)), 'plan' => $plan, 'pris_ore' => 100,
        'medlem_id' => $vennId, 'status' => 'apnet', 'subscription_id' => $avtale,
        'verve_kode' => $kode, 'utloper' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    return $avtale;
};
$gaver = static fn(int $id): int => (int) DB::verdi(
    "SELECT COUNT(*) FROM medlemsgaver WHERE member_id = :m AND type = 'timer'", ['m' => $id]);

$verver = $nyttMedlem('Verver Test', 'aktiv');
$kode   = Verving::kodeFor($verver);
sjekk('medlemmet faar en kode', preg_match('/^[a-z0-9]{8}$/', $kode) === 1, $kode);
sjekk('… den samme hver gang', Verving::kodeFor($verver) === $kode);
sjekk('lenka gaar til medlemskapssida med koden',
    str_ends_with(Verving::lenkeFor($verver), '/medlemskap?verv=' . $kode));

// 1. Bryteren av: ingen premie.
$bryter(false);
$venn1 = $nyttMedlem('Venn En', 'ingen');
$a1 = $innmelding($venn1, $aar, $kode);
sjekk('bryteren av gir ingen premie', Verving::premier($venn1, $a1, $aar) === null && $gaver($verver) === 0);

// 2. Paa, med eierens timetall.
$bryter(true);
Verving::settTimer(7, $verver);
$venn2 = $nyttMedlem('Venn To', 'ingen');
$a2 = $innmelding($venn2, $aar, $kode);
$gave = Verving::premier($venn2, $a2, $aar);
sjekk('premien gis naar vennen blir aarsmedlem', $gave !== null);
$rad = $gave === null ? null : DB::en('SELECT * FROM medlemsgaver WHERE id = :i', ['i' => $gave]);
sjekk('… som en timegave til den som vervet', $rad !== null
    && (int) $rad['member_id'] === $verver && $rad['type'] === 'timer');
sjekk('… med timetallet eieren har satt', $rad !== null && (int) $rad['timer'] === 7, (string) ($rad['timer'] ?? ''));
sjekk('… og gjelder ut maaneden, som timegavene fra admin',
    $rad !== null && $rad['gyldig_til'] === (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-t'));
sjekk('… og staar i «Vervet saa langt»',
    count(array_filter(Verving::liste(), static fn($v) => $v['venn'] === 'Venn To' && $v['timer'] === 7)) === 1);

// 3. Webhook og retur kommer begge: én premie.
sjekk('samme venn to ganger gir én premie',
    Verving::premier($venn2, $a2, $aar) === null && $gaver($verver) === 1);

// 4. Ingen kan verve seg selv.
$a3 = $innmelding($verver, $aar, $kode);
sjekk('ingen kan verve seg selv', Verving::premier($verver, $a3, $aar) === null && $gaver($verver) === 1);

// 5. Vennen har vaert medlem foer.
$venn4 = $nyttMedlem('Venn Fire', 'oppsagt');
DB::settInn('subscriptions', ['member_id' => $venn4, 'plan' => $aar, 'pris_ore' => 100, 'status' => 'stoppet']);
$a4 = $innmelding($venn4, $aar, $kode);
sjekk('en venn som har vaert medlem foer gir ingen premie',
    Verving::premier($venn4, $a4, $aar) === null && $gaver($verver) === 1);

// 6. Et annet medlemskap enn aarsmedlemskapet.
if ($annen !== '') {
    $venn5 = $nyttMedlem('Venn Fem', 'ingen');
    $a5 = $innmelding($venn5, $annen, $kode);
    sjekk('et annet medlemskap gir ingen premie',
        Verving::premier($venn5, $a5, $annen) === null && $gaver($verver) === 1);
}

// 7. Ververen er ikke aktivt medlem lenger.
DB::oppdater('members', ['status' => 'oppsagt'], ['id' => $verver]);
$venn6 = $nyttMedlem('Venn Seks', 'ingen');
$a6 = $innmelding($venn6, $aar, $kode);
sjekk('en verver som ikke er medlem lenger faar ingen premie',
    Verving::premier($venn6, $a6, $aar) === null && $gaver($verver) === 1);

// 8. En kode som ikke finnes.
$venn7 = $nyttMedlem('Venn Sju', 'ingen');
$a7 = $innmelding($venn7, $aar, 'finnesikke');
sjekk('en ukjent kode gir ingen premie', Verving::premier($venn7, $a7, $aar) === null);

// Rydd opp etter oss, og sett bryteren og timene tilbake. Gavene, avtalene
// og vervingene gaar med medlemmene; medlemsordrene har ingen noekkel dit.
DB::kjor('DELETE FROM medlemsordrer WHERE medlem_id IN (SELECT id FROM members WHERE epost LIKE :e)',
    ['e' => $sporTag . '-%']);
DB::kjor('DELETE FROM members WHERE epost LIKE :e', ['e' => $sporTag . '-%']);
if ($forBryter === null || $forBryter === false) {
    DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/verving'");
} else {
    DB::kjor("UPDATE content_blocks SET verdi = :v WHERE nokkel = 'Vis/verving'", ['v' => $forBryter]);
}
if ($forTimer !== null && $forTimer !== false) {
    DB::kjor("UPDATE innstillinger SET verdi = :v WHERE nokkel = 'verving_timer'", ['v' => $forTimer]);
}

echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil === 0 ? 0 : 1);
