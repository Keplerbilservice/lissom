<?php
/**
 * Plakatene — /nyttig-info/brennetabell, /nyttig-info/medlemsinfo og
 * /nyttig-info/trivselsregler. Skjermene fra lissom-2108.html via Mal;
 * punktene som plakatPunkter() (det eieren har lagret under Plakat/<navn>,
 * ellers standarden), og cone-tabellen som coneTabell().
 */

declare(strict_types=1);

$lagret = Nett::lagret();
$punkter = static function (string $nokkel, array $standard) use ($lagret): array {
    $raa = $lagret['Plakat/' . $nokkel] ?? '';
    if ($raa !== '') {
        $l = json_decode($raa, true);
        if (is_array($l) && $l !== []) {
            return array_values(array_map('strval', $l));
        }
    }
    return $standard;
};

$sider = [
    '/nyttig-info/brennetabell'   => 'Plakat – Cone til grader',
    '/nyttig-info/medlemsinfo'    => 'Plakat – Informasjon til medlemmer',
    '/nyttig-info/trivselsregler' => 'Plakat – Trivselsregler',
];
$skjerm = $sider[Nett::$adresse] ?? null;
if ($skjerm === null) {
    return null;
}

$verdier = ['sant' => true];
if ($skjerm === 'Plakat – Cone til grader') {
    $d = [
        ['10', 1305, 2381, 'HØYBRENNING'], ['9', 1280, 2336, 'HØYBRENNING'],
        ['8', 1263, 2305, null], ['7', 1240, 2264, null],
        ['6', 1222, 2232, 'MELLOMBRENNING'], ['5', 1196, 2185, 'MELLOMBRENNING'],
        ['4', 1186, 2157, null], ['3', 1168, 2134, null], ['2', 1162, 2124, null], ['1', 1154, 2109, null],
        ['01', 1137, 2079, null], ['02', 1120, 2048, null], ['03', 1101, 2014, null],
        ['04', 1060, 1940, 'LAVBRENNING'], ['05', 1046, 1915, 'LAVBRENNING'], ['06', 999, 1830, 'LAVBRENNING'],
        ['07', 984, 1803, null], ['08', 956, 1751, null], ['09', 923, 1693, null],
        ['010', 894, 1641, null], ['011', 894, 1641, null], ['012', 884, 1623, null],
        ['013', 852, 1566, null], ['014', 838, 1540, null], ['015', 804, 1479, null],
        ['016', 792, 1458, null], ['017', 747, 1377, null],
        ['018', 717, 1323, 'OVERGLASUR OG LUSTER'], ['019', 683, 1261, 'OVERGLASUR OG LUSTER'],
        ['020', 635, 1175, 'OVERGLASUR OG LUSTER'], ['021', 614, 1137, 'OVERGLASUR OG LUSTER'],
        ['022', 600, 1112, 'OVERGLASUR OG LUSTER'],
    ];
    $lblBase = 'display:flex;align-items:center;font-size:11px;font-weight:700;letter-spacing:var(--tracking-caps);text-transform:uppercase;color:var(--lissom-brown);line-height:1.35;';
    $grupper = [];
    foreach ($d as $i => $r) {
        if ($r[3] !== null) { $grupper[$r[3]][] = $i; }
    }
    $midtAv = [];
    foreach ($grupper as $g => $rader) { $midtAv[$g] = $rader[intdiv(count($rader) - 1, 2)]; }
    $tusen = static fn(int $n): string => (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{00A0}", (string) $n);
    $rader = [];
    foreach ($d as $i => [$cone, $c, $f, $g]) {
        $first = $i === 0;
        $lbl = ($g !== null && $midtAv[$g] === $i) ? ($g === 'OVERGLASUR OG LUSTER' ? 'Overglasur og luster' : mb_substr($g, 0, 1) . mb_strtolower(mb_substr($g, 1))) : '';
        $rader[] = [
            'cone' => $cone, 'c' => $tusen($c), 'f' => $tusen($f), 'lblL' => $lbl, 'lblR' => $lbl,
            'lblLStil' => $lblBase . 'justify-content:flex-end;text-align:right;padding-right:16px;' . ($g !== null ? 'border-right:2px solid var(--lissom-brown);' : ''),
            'lblRStil' => $lblBase . 'padding-left:16px;' . ($g !== null ? 'border-left:2px solid var(--lissom-brown);' : ''),
            'tubeStil' => 'display:flex;align-items:center;justify-content:center;background:var(--lissom-yellow);border-left:3px solid var(--lissom-brown);border-right:3px solid var(--lissom-brown);box-sizing:border-box;' . ($first ? 'border-top:3px solid var(--lissom-brown);border-radius:64px 64px 0 0;padding-top:10px;' : ''),
            'coneStil' => $g !== null ? 'background:var(--lissom-brown);color:var(--clay-50);padding:2px 13px;border-radius:999px;font-family:var(--font-display);font-weight:800;font-size:15px;' : 'font-weight:700;color:var(--lissom-brown);font-size:14px;',
        ];
    }
    $verdier['coneRader'] = $rader;
} elseif ($skjerm === 'Plakat – Informasjon til medlemmer') {
    $verdier['medlemsInfoPunkter'] = $punkter('medlemsinfo', [
        'Alle må lage egne råbrente plater til å sette under alt som skal glasurbrennes.',
        'Bruk av verksted, transparent glasur og brenning følger med i alle medlemsskapene.',
        'Dersom man har mye som skal brennes og ikke vil vente, kan man kjøpe egen brenning kun til sine egne ting.',
        'Det er kun underglasurer som kan benyttes før råbrenning. Glasurer skal KUN brukes etter råbrenning!',
        'Leire, farger og andre glasurer holder man selv, og oppbevarer det i sin egen hylle og på eget ansvar.',
        'Vær klar over at det du har laget kan gå i stykker, enten under brenning eller ved uhell!',
    ]);
} else {
    $liste = $punkter('trivsel', [
        'Bruk gjerne innesko, tøfler kan lånes.',
        'Rydde og vaske etter seg på bord, skiver, vekt og verktøy.',
        'Vaske opp det man har bruk selv, og legge alt tilbake på plass.',
        'Slukke alt lys i verkstedet.',
        'Skru av radio/musikk.',
        'Slukke alt lys i gangene, unntatt lyset i den lille gangen ved inngangsdøren.',
        'Sjekke tavlen om det er andre på huset. Er det noen inne, la lyset i gangene stå på.',
        'Sjekke om bakdøren er låst før du går.',
        'Låse dørene og legge nøklene tilbake i nøkkelboksene.',
    ]);
    $verdier['trivselsPunkter'] = array_map(static fn(int $i, string $t): array => ['nr' => $i + 1, 'tekst' => $t], array_keys($liste), $liste);
}

return [
    'kropp' => Mal::tegn($skjerm, $verdier, ['goNyttig' => '/nyttig-info']),
    'aktiv' => 'Nyttig info',
];
