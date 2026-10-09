<?php
/**
 * Skisser, mot databasen: hvem som ser og endrer hva, deling, bryterne og
 * versjonene. Se app/lib/skisser.php.
 *
 * Eieren, 30. september 2026: medlemmer og kursdeltakere kan tegne selv;
 * egne tavler ser bare de selv og admin; admin kan dele sine tavler.
 * Bryterne er av fra start, og av betyr at serveren også stopper.
 * Eieren, 9. oktober 2026: admin ser bare sine egne tavler, ikke medlemmenes
 * (404 også med direkte id).
 *
 * Kjor:  php tests/skisser.php
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
function kaster(callable $f): string {
    try { $f(); } catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
    return '';
}

echo "\n── Skisser ──────────────────────────────────────────────────\n";

sjekk('tabellene finnes (migrasjon 236)', Skisser::klar() && DB::harTabell('skisse_versjoner') && DB::harTabell('skisse_bilder'));

$bryter = static function (string $nokkel, ?string $verdi): void {
    if ($verdi === null) {
        DB::kjor('DELETE FROM content_blocks WHERE nokkel = :k', ['k' => 'Vis/' . $nokkel]);
        return;
    }
    DB::kjor('INSERT INTO content_blocks (nokkel, verdi) VALUES (:k, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
        ['k' => 'Vis/' . $nokkel, 'v' => $verdi]);
};
$lagret = [];
foreach (['skisser', 'skissermedlemmer', 'skisserdeltakere'] as $k) {
    $v = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :k', ['k' => 'Vis/' . $k]);
    $lagret[$k] = ($v === null || $v === false) ? null : (string) $v;
}

$bryter('skissermedlemmer', 'nei');
$bryter('skisserdeltakere', 'nei');
sjekk('medlemsbryteren kan slås av', DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/skissermedlemmer'") === 'nei');
sjekk('deltakerbryteren kan slås av', DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/skisserdeltakere'") === 'nei');

require __DIR__ . '/betalt-fixture.php';
$tag = 'sk-' . bin2hex(random_bytes(3));
$person = static function (string $rolle, string $status) use ($tag): array {
    $id = DB::settInn('members', [
        'navn' => 'Skisse ' . $rolle . ' ' . $status, 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'telefon' => '+479' . random_int(1000000, 9999999), 'rolle' => $rolle, 'status' => $status,
    ]);
    if ($rolle === 'medlem' && $status === 'aktiv') test_betalt_medlem($id);
    return DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);
};
$admin = $person('admin', 'aktiv');
$a = $person('medlem', 'aktiv');
$b = $person('medlem', 'aktiv');
$gjest = $person('medlem', 'ingen');   // verken medlem eller deltaker
$deltaker = $person('medlem', 'ingen');

// Deltakeren trenger en betalt påmelding.
$kurs = DB::en("SELECT id FROM courses ORDER BY id LIMIT 1");
if ($kurs !== null) {
    DB::settInn('bookings', ['member_id' => (int) $deltaker['id'], 'course_id' => (int) $kurs['id'], 'status' => 'betalt',
        'gjest_navn' => 'Skisse deltaker', 'belop_ore' => 0]);
}
sjekk('deltakeren regnes som deltaker', Skisser::erDeltaker($deltaker));
sjekk('… og medlemmet som medlem, ikke deltaker', Skisser::erMedlem($a) && !Skisser::erDeltaker($a));

// ── Av fra start: bare admin slipper inn ─────────────────────────────
$bryter('skisser', null);
$bryter('skissermedlemmer', 'nei');
$bryter('skisserdeltakere', 'nei');
sjekk('modulen står på uten rad', Skisser::modulPaa());
sjekk('bryterne av: medlemmet slipper ikke inn', !Skisser::slippInn($a));
sjekk('bryterne av: deltakeren slipper ikke inn', !Skisser::slippInn($deltaker));
sjekk('admin slipper inn', Skisser::slippInn($admin));

// ── Medlemmer på ──────────────────────────────────────────────────────
$bryter('skissermedlemmer', 'ja');
sjekk('medlemsbryteren på: medlemmet slipper inn', Skisser::slippInn($a));
sjekk('… men ikke deltakeren', !Skisser::slippInn($deltaker));
sjekk('… og ikke den som verken er medlem eller deltaker', !Skisser::slippInn($gjest));
$bryter('skisserdeltakere', 'ja');
sjekk('deltakerbryteren på: deltakeren slipper inn', Skisser::slippInn($deltaker));

// Eieren, 30. september 2026: medlemsbryteren bestemmer for alle medlemmer,
// ogsaa et medlem som er paameldt et kurs.
$medDeltaker = $person('medlem', 'aktiv');
if ($kurs !== null) {
    DB::settInn('bookings', ['member_id' => (int) $medDeltaker['id'], 'course_id' => (int) $kurs['id'], 'status' => 'betalt',
        'gjest_navn' => 'Skisse medlem på kurs', 'belop_ore' => 0]);
}
$bryter('skissermedlemmer', 'nei');
sjekk('medlem på kurs: medlemsbryteren av stenger, selv med deltakerbryteren på', !Skisser::slippInn($medDeltaker));
sjekk('… mens deltakeren som ikke er medlem fortsatt slipper inn', Skisser::slippInn($deltaker));
$bryter('skissermedlemmer', 'ja');
sjekk('… og med medlemsbryteren på slipper medlemmet inn igjen', Skisser::slippInn($medDeltaker));

// ── Egne tavler er private ────────────────────────────────────────────
$tA = Skisser::ny($a, 'Kari sin');
$tB = Skisser::ny($b, 'Ola sin');
$idListe = static fn(array $m): array => array_map(static fn($t) => $t['id'], Skisser::liste($m));
sjekk('A ser sin egen tavle', in_array($tA, $idListe($a), true));
sjekk('A ser ikke tavla til B', !in_array($tB, $idListe($a), true));
sjekk('A får ikke hente tavla til B', Skisser::hent($tB, $a) === null);
sjekk('A får ikke lagre på tavla til B', str_contains(kaster(static fn() => Skisser::lagreSide($tB, 1, '{"objects":[]}', $a)), 'Fant ikke'));
sjekk('A får ikke slette tavla til B', kaster(static fn() => Skisser::slett($tB, $a)) !== '' && DB::en('SELECT id FROM skisser WHERE id = :i', ['i' => $tB]) !== null);
sjekk('admin ser ikke medlemmenes tavler i lista', !in_array($tA, $idListe($admin), true) && !in_array($tB, $idListe($admin), true));
sjekk('admin får ikke hente en medlemstavle med direkte id', Skisser::hent($tA, $admin) === null);
sjekk('admin får ikke lagre på en medlemstavle', str_contains(kaster(static fn() => Skisser::lagreSide($tA, 1, '{"objects":[]}', $admin)), 'Fant ikke'));
sjekk('admin får ikke gi nytt navn til en medlemstavle', str_contains(kaster(static fn() => Skisser::giNavn($tA, 'x', $admin)), 'Fant ikke'));
sjekk('admin får ikke slette en medlemstavle', kaster(static fn() => Skisser::slett($tA, $admin)) !== '' && DB::en('SELECT id FROM skisser WHERE id = :i', ['i' => $tA]) !== null);
sjekk('admin ser ikke versjonene til en medlemstavle', Skisser::versjoner($tA, 1, $admin) === []);
sjekk('admin kan ikke dele en medlemstavle', str_contains(kaster(static fn() => Skisser::del($tA, true, false, $admin)), 'Fant ikke'));
sjekk('et medlem kan ikke dele', kaster(static fn() => Skisser::del($tA, true, true, $a)) !== '');

// ── Lagring og versjoner ──────────────────────────────────────────────
$s1 = '{"version":"6.9.1","objects":[{"type":"Path","path":[["M",0,0],["L",10,10]]}]}';
$s2 = '{"version":"6.9.1","objects":[{"type":"Path","path":[["M",0,0],["L",20,20]]}]}';
Skisser::lagreSide($tA, 1, $s1, $a);
Skisser::lagreSide($tA, 1, $s2, $a);
$t = Skisser::hent($tA, $a);
sjekk('siden er lagret', ($t['sider'][0]['data'] ?? '') === $s2);
$v = Skisser::versjoner($tA, 1, $a);
sjekk('forrige utgave ligger i versjonene', count($v) === 1 && $v[0]['data'] === $s1, (string) count($v));
sjekk('B ser ikke versjonene til A', Skisser::versjoner($tA, 1, $b) === []);
for ($i = 0; $i < Skisser::VERSJONER + 5; $i++) {
    Skisser::lagreSide($tA, 1, '{"objects":[],"n":' . $i . '}', $a);
}
sjekk('bare de siste ' . Skisser::VERSJONER . ' versjonene blir liggende', count(Skisser::versjoner($tA, 1, $a)) === Skisser::VERSJONER,
    (string) count(Skisser::versjoner($tA, 1, $a)));
sjekk('ugyldig JSON avvises', kaster(static fn() => Skisser::lagreSide($tA, 1, '{ikke json', $a)) !== '');
Skisser::lagreSide($tA, 2, '{"objects":[]}', $a);
sjekk('ny side 2', count(Skisser::hent($tA, $a)['sider']) === 2);
Skisser::slettSide($tA, 1, $a);
$sider = Skisser::hent($tA, $a)['sider'];
sjekk('slett side 1: side 2 rykker opp', count($sider) === 1 && $sider[0]['nr'] === 1);
sjekk('siste side kan ikke slettes', kaster(static fn() => Skisser::slettSide($tA, 1, $a)) !== '');

// ── Admin deler en tavle ──────────────────────────────────────────────
$tV = Skisser::ny($admin, 'Verkstedets');
sjekk('admin ser sin egen tavle', in_array($tV, $idListe($admin), true) && Skisser::hent($tV, $admin) !== null
    && Skisser::hent($tV, $admin)['kanEndre'] === true);
$admin2 = $person('admin', 'aktiv');
sjekk('en annen admin ser ikke tavla', !in_array($tV, $idListe($admin2), true) && Skisser::hent($tV, $admin2) === null);
sjekk('udelt: A ser ikke verkstedets tavle', !in_array($tV, $idListe($a), true));
Skisser::del($tV, true, false, $admin);
sjekk('delt med medlemmer: A ser den', in_array($tV, $idListe($a), true));
sjekk('… men deltakeren gjør ikke', !in_array($tV, $idListe($deltaker), true));
sjekk('… og A kan ikke endre den', ($r = Skisser::hent($tV, $a)) !== null && $r['kanEndre'] === false
    && str_contains(kaster(static fn() => Skisser::lagreSide($tV, 1, '{"objects":[]}', $a)), 'se på'));
Skisser::del($tV, false, true, $admin);
sjekk('delt med deltakere: deltakeren ser den', in_array($tV, $idListe($deltaker), true));
sjekk('… og A gjør ikke lenger', !in_array($tV, $idListe($a), true));
$bryter('skisserdeltakere', 'nei');
sjekk('deltakerbryteren av: den delte tavla er borte for deltakeren', Skisser::hent($tV, $deltaker) === null);

// ── Bilder følger tavla ───────────────────────────────────────────────
$fil = bin2hex(random_bytes(16)) . '.jpg';
Skisser::leggTilBilde($tA, $fil, $a);
sjekk('eieren ser bildet', Skisser::kanSeBilde($fil, $a));
sjekk('B ser ikke bildet', !Skisser::kanSeBilde($fil, $b));
sjekk('uten innlogging ser ingen bildet', !Skisser::kanSeBilde($fil, null));
sjekk('admin ser ikke bildet på en medlemstavle', !Skisser::kanSeBilde($fil, $admin));
sjekk('B kan ikke legge bilde på tavla til A', kaster(static fn() => Skisser::leggTilBilde($tA, bin2hex(random_bytes(16)) . '.jpg', $b)) !== '');

// ── Modulen av: alt stopper ───────────────────────────────────────────
$bryter('skisser', 'nei');
sjekk('modulen av: admin slipper heller ikke inn', !Skisser::slippInn($admin) && !Skisser::modulPaa());
sjekk('modulen av: medlemmet slipper ikke inn', !Skisser::slippInn($a));

// Endepunktene sjekker bryterne før de gjør noe (403).
$api = (string) file_get_contents(__DIR__ . '/../api/skisser.php');
$apiAdmin = (string) file_get_contents(__DIR__ . '/../api/admin/skisser.php');
sjekk('api/skisser.php stopper med 403 når bryterne er av',
    str_contains($api, "if (!Skisser::slippInn(\$medlem)) {\n    Svar::feil('Skisser er ikke slått på.', 403);")
    || str_contains(str_replace("\r\n", "\n", $api), "if (!Skisser::slippInn(\$medlem)) {\n    Svar::feil('Skisser er ikke slått på.', 403);"));
sjekk('api/admin/skisser.php stopper med 403 når modulen er av',
    str_contains(str_replace("\r\n", "\n", $apiAdmin), "if (!Skisser::modulPaa()) {\n    Svar::feil('Skisser er slått av under Synlighet.', 403);"));
sjekk('api/admin/skisser.php krever admin', str_contains($apiAdmin, '$admin = krev_admin();'));

// ── Rydd opp ──────────────────────────────────────────────────────────
foreach ([[$tA, $a], [$tB, $b], [$tV, $admin]] as [$id, $eier]) {
    try { Skisser::slett($id, $eier); } catch (Throwable $e) { /* alt borte */ }
}
$bryter('skisser', 'nei'); // slett() trenger ikke modulen, men sett bryterne tilbake
foreach ($lagret as $k => $v) { $bryter($k, $v); }
$ids = array_map(static fn($m) => (int) $m['id'], [$admin, $admin2, $a, $b, $gjest, $deltaker, $medDeltaker]);
DB::kjor('DELETE FROM bookings WHERE member_id IN (' . implode(',', $ids) . ')');
DB::kjor('DELETE FROM payments WHERE member_id IN (' . implode(',', $ids) . ')');
DB::kjor('DELETE FROM members WHERE id IN (' . implode(',', $ids) . ')');

$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
