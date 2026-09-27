<?php
/**
 * Galleriet paa forsida, mot databasen.
 *
 * Eieren, 27. september 2026: butikkfeltet blir et galleri med medlemmenes
 * godkjente bilder, med verkstedets egne fyllbilder naar det er faerre
 * medlemsbilder enn plasser. Et medlemsbilde staar en maaned fra det foerst
 * ble vist, og viker da for et som venter — venter ingen, blir det staaende.
 * Se app/lib/galleri.php. Godkjenningen gjennom admin proves i
 * tests/galleri.sh.
 *
 * Kjor:  php tests/galleri.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

// Stopper testen paa en feil, skal kjoringen feile — ikke se groenn ut.
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

echo "\n── Galleriet paa forsida ────────────────────────────────────\n";

sjekk('migrasjon 225 er kjort', Galleri::klar());

// Andre galleribilder i basen settes til side mens testen gaar, og tilbake
// etterpaa. Testen skal bare se sine egne.
$andre = array_map('intval', array_column(DB::alle('SELECT id FROM medlemsforslag WHERE galleri = 1'), 'id'));
if ($andre) {
    DB::kjor('UPDATE medlemsforslag SET galleri = 0 WHERE id IN (' . implode(',', $andre) . ')');
}
$fyllFil = dirname(__DIR__) . '/galleri-fyll.json';
$fyllFoer = is_file($fyllFil) ? (string) file_get_contents($fyllFil) : null;
file_put_contents($fyllFil, '[]');

$medlem = DB::settInn('members', ['navn' => 'Galleri Testesen', 'epost' => 'galleri-' . bin2hex(random_bytes(3)) . '@lissom.test', 'rolle' => 'medlem']);
$mine = [];
$nytt = static function (string $tekst, string $status = 'galleri', int $galleri = 1, string $type = 'bilde') use ($medlem, &$mine): int {
    $id = DB::settInn('medlemsforslag', [
        'member_id' => $medlem, 'type' => $type, 'fil' => bin2hex(random_bytes(16)) . ($type === 'bilde' ? '.jpg' : '.mp4'),
        'tekst' => $tekst, 'status' => $status, 'galleri' => $galleri,
        'galleri_godkjent_at' => gmdate('Y-m-d H:i:s', time() - 3600 + count($mine)),
    ]);
    $mine[] = $id;
    return $id;
};
$vist = static fn(int $id): ?string => DB::verdi('SELECT galleri_vist_fra FROM medlemsforslag WHERE id = :i', ['i' => $id]);
$iGalleri = static fn(int $id): bool => (int) DB::verdi('SELECT galleri FROM medlemsforslag WHERE id = :i', ['i' => $id]) === 1;

try {
    // ── Tittelen ──────────────────────────────────────────────────────
    sjekk('tittelen er foerste linje, uten hashtagger',
        Galleri::tittel("Min første bolle #lissom #keramikk\nMer tekst her") === 'Min første bolle');
    sjekk('… og kortes ned paa et ord',
        mb_strlen(Galleri::tittel(str_repeat('ordene ', 20))) <= 49
        && str_ends_with(Galleri::tittel(str_repeat('ordene ', 20)), '…'));

    // ── For faa bilder: varene staar ─────────────────────────────────
    $a = $nytt('Bolle i sandglasur');
    $b = $nytt('Stort fat');
    $c = $nytt('Kopper til hytta');
    sjekk('tre bilder og ingen fyllbilder: forsida viser varene', Galleri::kort() === []);

    // Bare godkjente vises: et avvist eller ventende forslag med galleri=1
    // skal ikke med, selv om feltet skulle vaere satt.
    $avvist = $nytt('Avvist bilde', 'avvist');
    $venter = $nytt('Ventende bilde', 'venter');
    $video  = $nytt('En video', 'galleri', 1, 'video');
    $kort = Galleri::kort();
    sjekk('… ogsaa naar avviste, ventende og videoer er merket for galleriet', $kort === [],
        count($kort) . ' kort');

    // ── Fire godkjente: galleriet ────────────────────────────────────
    $d = $nytt('Liten vase');
    $kort = Galleri::kort();
    sjekk('fire godkjente bilder: galleriet vises', count($kort) === 4, count($kort) . ' kort');
    $titler = array_column($kort, 'tittel');
    sjekk('… bare de godkjente', !in_array('Avvist bilde', $titler, true)
        && !in_array('Ventende bilde', $titler, true) && !in_array('En video', $titler, true));
    sjekk('… med fornavnet under', ($kort[0]['navn'] ?? '') === 'Galleri');
    sjekk('… og bildet fra forslaget', str_starts_with((string) ($kort[0]['bilde'] ?? ''), '/api/bilde.php?forslag='));
    sjekk('hvert bilde har faatt plass', $vist($a) !== null && $vist($d) !== null);

    // ── Fyllbildene ──────────────────────────────────────────────────
    DB::kjor('UPDATE medlemsforslag SET galleri = 0 WHERE id IN (' . implode(',', [$c, $d]) . ')');
    file_put_contents($fyllFil, json_encode([
        ['fil' => 'uploads_foto-bolle-400.jpg', 'tittel' => 'Boller til glasering'],
        ['fil' => 'uploads_foto-storefat-400.jpg', 'tittel' => 'Store fat'],
        ['fil' => '../app/secrets.php', 'tittel' => 'Skal ikke med'],
        ['fil' => 'https://example.com/x.jpg', 'tittel' => 'Heller ikke'],
    ]));
    $kort = Galleri::kort();
    sjekk('to medlemsbilder og to fyllbilder: galleriet vises', count($kort) === 4, count($kort) . ' kort');
    sjekk('… medlemsbildene foerst', ($kort[0]['fyll'] ?? true) === false && ($kort[1]['fyll'] ?? true) === false);
    sjekk('… fyllbildene med «Lissom» som navn', ($kort[2]['navn'] ?? '') === 'Lissom' && ($kort[3]['fyll'] ?? false) === true);
    sjekk('… og bare vanlige bildefiler fra lista', !in_array('Skal ikke med', array_column($kort, 'tittel'), true)
        && !in_array('Heller ikke', array_column($kort, 'tittel'), true));

    // Fyllbildene viker: med alle plassene fulle av medlemsbilder er de borte.
    DB::kjor('UPDATE medlemsforslag SET galleri = 1 WHERE id IN (' . implode(',', [$c, $d]) . ')');
    for ($i = count($mine); $i < 20 && count(array_filter(Galleri::kort(), static fn($k) => !$k['fyll'])) < Galleri::PLASSER; $i++) {
        $nytt('Fyll plass ' . $i);
    }
    $kort = Galleri::kort();
    sjekk('fulle plasser: ingen fyllbilder', count($kort) === Galleri::PLASSER
        && array_filter($kort, static fn($k) => $k['fyll']) === [], count($kort) . ' kort');

    // ── Maanedsbyttet ────────────────────────────────────────────────
    // Det eldste bildet har staatt i 40 dager, og ingen venter: det staar.
    DB::kjor('UPDATE medlemsforslag SET galleri_vist_fra = :t WHERE id = :i',
        ['t' => gmdate('Y-m-d H:i:s', time() - 40 * 86400), 'i' => $a]);
    Galleri::fordel();
    sjekk('en maaned gammelt, og ingen venter: bildet blir staaende', $iGalleri($a));

    // Et nytt bilde venter paa plass (plassene er fulle): det gamle viker.
    $ny = $nytt('Nytt bilde som venter');
    sjekk('det nye venter paa plass', $vist($ny) === null);
    Galleri::fordel();
    sjekk('en maaned gammelt, og et venter: det gamle byttes ut', !$iGalleri($a));
    sjekk('… og det som ventet har faatt plass', $vist($ny) !== null);

    // Et bilde som bare har staatt i ti dager, viker ikke.
    DB::kjor('UPDATE medlemsforslag SET galleri_vist_fra = :t WHERE id = :i',
        ['t' => gmdate('Y-m-d H:i:s', time() - 10 * 86400), 'i' => $b]);
    $ny2 = $nytt('Enda et som venter');
    Galleri::fordel();
    sjekk('ti dager gammelt viker ikke, selv om et venter', $iGalleri($b) && $vist($ny2) === null);

    // Det som har ventet lengst kommer foerst.
    DB::kjor('UPDATE medlemsforslag SET galleri_vist_fra = :t WHERE id = :i',
        ['t' => gmdate('Y-m-d H:i:s', time() - 45 * 86400), 'i' => $b]);
    $ny3 = $nytt('Ventet kortest');
    Galleri::fordel();
    sjekk('det som har ventet lengst faar plassen foerst', $vist($ny2) !== null && $vist($ny3) === null);
} finally {
    if ($mine) {
        DB::kjor('DELETE FROM medlemsforslag WHERE id IN (' . implode(',', array_map('intval', $mine)) . ')');
    }
    DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $medlem]);
    if ($andre) {
        DB::kjor('UPDATE medlemsforslag SET galleri = 1 WHERE id IN (' . implode(',', $andre) . ')');
    }
    if ($fyllFoer === null) { @unlink($fyllFil); } else { file_put_contents($fyllFil, $fyllFoer); }
}

echo "\n── $ok gikk gjennom, $feil feilet\n";
$ferdig = true;
exit($feil === 0 ? 0 : 1);
