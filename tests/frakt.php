<?php
/**
 * Frakten fra Pakke-Express paa samlebestillingen.
 *
 * Eieren, 30. september 2026: fraktprisene fra Pakke-Express (tilbud
 * 24.09.2026) er «til bruk på handlelisten innkjøp», delt paa medlemmene
 * etter vekt per vare, og admin kan rette totalvekten.
 *
 * Testen leser oppsettet migrasjon 237 la inn, sjekker vektklassene paa
 * grensene, energitillegget, at delingen alltid gaar opp i eksakt beloep,
 * og at en linje uten vekt stopper frakten til totalen er rettet.
 *
 *   php tests/frakt.php
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

echo "\n── Oppsettet fra migrasjon 237 ─────────────────────────────\n";
sjekk('basen har feltene', Frakt::klar());
$o = Frakt::oppsett();
sjekk('oppsettet finnes', $o !== null);
if ($o === null) { $ferdig = true; exit(1); }
sjekk('to soner: Tønsberg og omegn, Oslo/Bærum',
    array_column($o['soner'], 'navn') === ['Tønsberg og omegn', 'Oslo/Bærum'], json_encode($o['soner'], JSON_UNESCAPED_UNICODE));
sjekk('seks vektklasser, siste til 400 kg', count($o['klasser']) === 6 && end($o['klasser'])['tilKg'] === 400);
sjekk('energitillegget er 9,5 %', $o['energiProsent'] === 9.5);
sjekk('bud og ekspress 20 kr per km er lagret', $o['budKrPerKm'] === 20);

echo "\n── Vektklassene på grensene (Tønsberg og omegn) ────────────\n";
$grunn = static fn(int $g, string $s = 'tonsberg') => Frakt::beregn($o, $s, $g)['grunnOre'] ?? null;
foreach ([[3000, 13000], [3001, 16000], [4000, 16000], [10000, 16000], [10001, 22500], [11000, 22500],
          [30000, 22500], [30001, 35000], [31000, 35000], [100000, 35000], [100001, 50000], [101000, 50000],
          [200000, 50000], [200001, 85000], [201000, 85000], [400000, 85000]] as [$g, $forventet]) {
    sjekk(Frakt::kg($g) . ' kg gir kr ' . ($forventet / 100), $grunn($g) === $forventet, (string) $grunn($g));
}
$over = Frakt::beregn($o, 'tonsberg', 400001);
sjekk('over 400 kg avvises med melding', !$over['ok'] && str_contains((string) $over['feil'], 'over 400 kg'), (string) ($over['feil'] ?? ''));
sjekk('Oslo/Bærum 0–3 kg er kr 275', $grunn(2500, 'oslo') === 27500);
sjekk('Oslo/Bærum 201–400 kg er kr 1 150', $grunn(300000, 'oslo') === 115000);
sjekk('klassen skrives «4–10 kg»', (Frakt::beregn($o, 'tonsberg', 5000)['klasse'] ?? '') === '4–10 kg');
sjekk('ukjent sone avvises', !Frakt::beregn($o, 'bergen', 1000)['ok']);

echo "\n── Energitillegget ─────────────────────────────────────────\n";
$b = Frakt::beregn($o, 'tonsberg', 2000);
sjekk('kr 130 + 9,5 % = kr 12,35 i tillegg', $b['energiOre'] === 1235, (string) $b['energiOre']);
sjekk('… og kr 142,35 til sammen', $b['sumOre'] === 14235, (string) $b['sumOre']);
$b = Frakt::beregn($o, 'oslo', 150000);
sjekk('kr 1 000 + 9,5 % = kr 1 095', $b['sumOre'] === 109500, (string) $b['sumOre']);

echo "\n── Delingen går alltid opp ─────────────────────────────────\n";
$d = Frakt::fordel(100, [1 => 1, 2 => 1, 3 => 1]);
sjekk('100 øre på tre like: 34 + 33 + 33', array_sum($d) === 100 && max($d) === 34 && min($d) === 33, json_encode($d));
$d = Frakt::fordel(14235, [7 => 1000, 8 => 2000, 9 => 3333]);
sjekk('kr 142,35 etter vekt går opp i øret', array_sum($d) === 14235, json_encode($d));
sjekk('… og den tyngste betaler mest', $d[9] > $d[8] && $d[8] > $d[7]);
$ettAvHver = true;
for ($i = 0; $i < 300; $i++) {
    $sum = random_int(1, 200000);
    $v = [];
    for ($j = 1; $j <= random_int(1, 7); $j++) { $v[$j] = random_int(0, 50000); }
    if (array_sum(Frakt::fordel($sum, $v)) !== $sum) { $ettAvHver = false; break; }
}
sjekk('300 tilfeldige delinger går opp i eksakt beløp', $ettAvHver);
sjekk('bare nuller deles likt', Frakt::fordel(90, [1 => 0, 2 => 0]) === [1 => 45, 2 => 45]);

echo "\n── Kilo i feltene ──────────────────────────────────────────\n";
sjekk('«2,5» er 2500 g', Frakt::gramFraKg('2,5') === 2500);
sjekk('tomt er ukjent', Frakt::gramFraKg('') === null);
$kastet = false;
try { Frakt::gramFraKg('mye'); } catch (InvalidArgumentException $e) { $kastet = true; }
sjekk('tull avvises', $kastet);
sjekk('2500 g skrives «2,5»', Frakt::kg(2500) === '2,5');

echo "\n── En samlebestilling med vekt per vare ────────────────────\n";
$tag = 'frakt' . bin2hex(random_bytes(3));
$rydd = static function () use ($tag): void {
    DB::kjor("DELETE h FROM handleliste_linjer h JOIN members m ON m.id = h.member_id WHERE m.epost LIKE :e", ['e' => "%$tag%"]);
    DB::kjor('DELETE FROM members WHERE epost LIKE :e', ['e' => "%$tag%"]);
    DB::kjor('DELETE FROM products WHERE tittel LIKE :t', ['t' => "$tag%"]);
    DB::kjor('DELETE FROM leverandorer WHERE navn LIKE :n', ['n' => "$tag%"]);
};
$rydd();
$lev = DB::settInn('leverandorer', ['navn' => "$tag Leverandør", 'epost' => '', 'bestillingsmaate' => 'epost', 'aktiv' => 1, 'frakt_sone_standard' => 'tonsberg']);
$medlem = static fn(string $n) => DB::settInn('members', ['navn' => $n, 'epost' => "$n.$tag@lissom.test", 'telefon' => '+4790000' . random_int(100, 999), 'rolle' => 'medlem', 'status' => 'aktiv']);
$a = $medlem('Anne');
$b2 = $medlem('Berit');
$vare = static fn(string $t, ?int $g) => DB::settInn('products', ['tittel' => "$tag $t", 'pris_ore' => 25000, 'mva_prosent' => 25, 'kun_medlemmer' => 1, 'status' => 'publisert', 'leverandor_id' => $lev, 'kan_bestilles' => 1, 'vekt_g' => $g]);
$leire = $vare('Leire', 2000);
$glasur = $vare('Engobe', 1000);
$linje = static fn(int $m, ?int $p, int $antall, ?string $tekst = null, ?int $g = null) => DB::settInn('handleliste_linjer', [
    'member_id' => $m, 'product_id' => $p, 'tekst' => $tekst, 'antall' => $antall, 'status' => 'sendt', 'pris_ore' => 25000,
    'leverandor_id' => $p === null ? $lev : null, 'vekt_g' => $g, 'opprettet' => date('Y-m-d H:i:s'),
]);
$linje($a, $leire, 2);   // 4 kg
$linje($b2, $glasur, 1); // 1 kg

$bilde = Frakt::perLeverandor()[$lev] ?? null;
sjekk('leverandøren med sone er med', $bilde !== null);
sjekk('totalvekten er 5 kg', ($bilde['vektG'] ?? 0) === 5000, (string) ($bilde['vektG'] ?? ''));
sjekk('klassen er 4–10 kg', ($bilde['klasse'] ?? '') === '4–10 kg');
sjekk('frakten er kr 160 + 9,5 % = kr 175,20', ($bilde['sumOre'] ?? 0) === 17520, (string) ($bilde['sumOre'] ?? ''));
sjekk('delt etter vekt', ($bilde['deling'] ?? '') === 'vekt');
sjekk('Anne (4 kg) betaler kr 140,16', ($bilde['andeler'][$a] ?? 0) === 14016, json_encode($bilde['andeler'] ?? []));
sjekk('Berit (1 kg) betaler kr 35,04', ($bilde['andeler'][$b2] ?? 0) === 3504);
sjekk('andelene går opp i frakten', array_sum($bilde['andeler'] ?? []) === ($bilde['sumOre'] ?? -1));
sjekk('Min side: Anne ser andelen sin', Frakt::andelFor($a) === 14016);

echo "\n── En linje uten vekt stopper frakten ──────────────────────\n";
$onske = $linje($b2, null, 1, "$tag Stor sekk", null);
$bilde = Frakt::perLeverandor()[$lev];
sjekk('frakten regnes ikke', !$bilde['ok'] && $bilde['andeler'] === []);
sjekk('advarselen sier at én linje mangler vekt', str_contains($bilde['advarsel'], '1 varelinje mangler vekt'), $bilde['advarsel']);
sjekk('Min side viser ingen frakt mens den mangler', Frakt::andelFor($a) === 0);

DB::oppdater('leverandorer', ['frakt_vekt_g' => 8000], ['id' => $lev]);
$bilde = Frakt::perLeverandor()[$lev];
sjekk('rettet totalvekt (8 kg) gjør at frakten regnes', $bilde['ok'] && $bilde['brukG'] === 8000);
sjekk('… delt etter beløp når en linje mangler vekt', $bilde['deling'] === 'belop');
sjekk('… og går opp i øret', array_sum($bilde['andeler']) === $bilde['sumOre']);

DB::oppdater('leverandorer', ['frakt_vekt_g' => null], ['id' => $lev]);
DB::oppdater('handleliste_linjer', ['vekt_g' => 12000], ['id' => $onske]);
$bilde = Frakt::perLeverandor()[$lev];
sjekk('vekt på ønsket (12 kg) gir 17 kg og klassen 11–30 kg', $bilde['ok'] && $bilde['vektG'] === 17000 && $bilde['klasse'] === '11–30 kg', $bilde['klasse'] ?? '');
sjekk('… delt etter vekt igjen', $bilde['deling'] === 'vekt');

echo "\n── Oslo og over 400 kg ─────────────────────────────────────\n";
DB::oppdater('leverandorer', ['frakt_sone' => 'oslo'], ['id' => $lev]);
$bilde = Frakt::perLeverandor()[$lev];
sjekk('sonen for bestillingen går foran standarden', $bilde['sone'] === 'oslo' && $bilde['grunnOre'] === 42500);
DB::oppdater('leverandorer', ['frakt_vekt_g' => 401000], ['id' => $lev]);
$bilde = Frakt::perLeverandor()[$lev];
sjekk('over 400 kg: ingen frakt, og en advarsel', !$bilde['ok'] && str_contains($bilde['advarsel'], 'over 400 kg'), $bilde['advarsel']);

echo "\n── Uten sone er leverandøren ikke Pakke-Express ────────────\n";
DB::oppdater('leverandorer', ['frakt_sone' => null, 'frakt_sone_standard' => null, 'frakt_vekt_g' => null], ['id' => $lev]);
sjekk('da regnes ingen frakt automatisk', !isset(Frakt::perLeverandor()[$lev]));

$rydd();
$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
