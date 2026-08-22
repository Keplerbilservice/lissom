<?php
/**
 * Oppskrifter, artikler og lenker.
 *
 *   GET                            alt tre
 *   POST type=oppskrift  lagre     { id, navn, oppskriftType, temperatur, raavarer, notat }
 *   POST type=artikkel   lagre     { id, tittel, dato, ingress, bilde, innhold }
 *   POST type=lenke      lagre     { id, navn, url, om }
 *   POST type=...        slett     { id }
 *
 * Tre skjermer i admin holdt bare paa det som ble skrevet til siden ble
 * lastet paa nytt. Ett endepunkt for alle tre — de har samme form: en liste
 * eieren redigerer, uten noe annet system rundt seg.
 *
 * Artiklene er offentlige og hentes av api/nyttig.php. Oppskriftene er kun
 * for verkstedet, og ligger bare her bak admin.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

$hent = static fn(): array => [
    'oppskrifter' => array_map(static fn($o) => [
        'id'         => (int) $o['id'],
        'navn'       => $o['navn'],
        'type'       => $o['type'],
        'temperatur' => $o['temperatur'],
        'raavarer'   => json_decode((string) $o['raavarer'], true) ?: [],
        'notat'      => $o['notat'],
    ], DB::alle('SELECT * FROM recipes ORDER BY navn')),

    'artikler' => array_map(static fn($a) => [
        'id'      => (int) $a['id'],
        'tittel'  => $a['tittel'],
        'dato'    => $a['dato'],
        'ingress' => $a['ingress'],
        'bilde'   => $a['bilde'],
        'innhold' => $a['innhold'],
        'status'  => $a['status'],
    ], DB::alle('SELECT * FROM articles ORDER BY sortering, id DESC')),

    'lenker' => array_map(static fn($l) => [
        'id'   => (int) $l['id'],
        'navn' => $l['navn'],
        'url'  => $l['url'],
        'om'   => $l['om'],
    ], DB::alle('SELECT * FROM links ORDER BY sortering, navn')),
];

if (Foresporsel::metode() === 'GET') {
    Svar::json($hent());
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$type     = Foresporsel::tekst('type');
$handling = Foresporsel::tekst('handling', 'lagre');
$id       = Foresporsel::heltall('id');

$TABELL = ['oppskrift' => 'recipes', 'artikkel' => 'articles', 'lenke' => 'links'];
if (!isset($TABELL[$type])) {
    Svar::feil('Ukjent type.');
}
$tabell = $TABELL[$type];

// ---------------------------------------------------------------- sletting
if ($handling === 'slett') {
    if ($id <= 0) {
        Svar::feil('Mangler hva som skal slettes.');
    }
    DB::kjor("DELETE FROM {$tabell} WHERE id = :i", ['i' => $id]);
    revider($type . '_slettet', $type, $id);
    Svar::ok(['beskjed' => 'Slettet.', ...$hent()]);
}

// ----------------------------------------------------------------- lagring
switch ($type) {

    case 'oppskrift':
        $navn = mb_substr(Foresporsel::tekst('navn'), 0, 191);
        $raa  = Foresporsel::kropp()['raavarer'] ?? null;

        if ($navn === '') {
            Svar::feil('Oppskriften må ha et navn.');
        }
        if (!is_array($raa) || $raa === []) {
            Svar::feil('Legg inn minst én råvare.');
        }

        // [["Kvarts", 25], ...]. Vi tar bare rader med navn og et tall over
        // null — en rad uten mengde er ingen raavare.
        $rene = [];
        foreach ($raa as $rad) {
            if (!is_array($rad) || count($rad) < 2) {
                continue;
            }
            $r = trim((string) $rad[0]);
            $m = (float) $rad[1];
            if ($r !== '' && $m > 0) {
                $rene[] = [mb_substr($r, 0, 96), round($m, 2)];
            }
        }
        if ($rene === []) {
            Svar::feil('Legg inn minst én råvare med mengde.');
        }
        if (count($rene) > 60) {
            Svar::feil('For mange råvarer i én oppskrift.');
        }

        $data = [
            'navn'       => $navn,
            'type'       => Foresporsel::tekst('oppskriftType') === 'Engobe' ? 'Engobe' : 'Glasur',
            'temperatur' => mb_substr(Foresporsel::tekst('temperatur'), 0, 64) ?: null,
            'raavarer'   => json_encode($rene, JSON_UNESCAPED_UNICODE),
            'notat'      => Foresporsel::tekst('notat') ?: null,
        ];
        $unik = ['felt' => 'navn', 'verdi' => $navn];
        break;

    case 'artikkel':
        $tittel = mb_substr(Foresporsel::tekst('tittel'), 0, 191);
        if ($tittel === '') {
            Svar::feil('Artikkelen må ha en tittel.');
        }
        $data = [
            'tittel'  => $tittel,
            'dato'    => mb_substr(Foresporsel::tekst('dato'), 0, 64) ?: null,
            'ingress' => Foresporsel::tekst('ingress') ?: null,
            'bilde'   => mb_substr(Foresporsel::tekst('bilde'), 0, 255) ?: null,
            'innhold' => Foresporsel::tekst('innhold') ?: null,
            'status'  => Foresporsel::tekst('status') === 'kladd' ? 'kladd' : 'publisert',
        ];
        $unik = ['felt' => 'tittel', 'verdi' => $tittel];
        break;

    default:    // lenke
        $navn = mb_substr(Foresporsel::tekst('navn'), 0, 191);
        $url  = mb_substr(Foresporsel::tekst('url'), 0, 500);

        if ($navn === '' || $url === '') {
            Svar::feil('Lenka må ha navn og adresse.');
        }
        // Bare http og https. Uten dette kunne en lenke peke paa javascript:
        // og kjore kode i nettleseren til den som trykker.
        if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            Svar::feil('Adressen må begynne med http:// eller https://.');
        }
        $data = [
            'navn' => $navn,
            'url'  => $url,
            'om'   => mb_substr(Foresporsel::tekst('om'), 0, 500) ?: null,
        ];
        $unik = ['felt' => 'url', 'verdi' => $url];
        break;
}

// Navnet (eller adressen) er unikt. Finnes raden fra for, er det den som
// skal endres — ellers ville lagring med samme navn stoppet paa noekkelen.
$fra = DB::en("SELECT id FROM {$tabell} WHERE {$unik['felt']} = :v", ['v' => $unik['verdi']]);
if ($id <= 0 && $fra !== null) {
    $id = (int) $fra['id'];
}
if ($id > 0 && $fra !== null && (int) $fra['id'] !== $id) {
    Svar::feil('En annen oppforing heter alt det samme.');
}

if ($id > 0) {
    DB::oppdater($tabell, $data, ['id' => $id]);
    revider($type . '_endret', $type, $id);
    Svar::ok(['id' => $id, 'beskjed' => 'Lagret.', ...$hent()]);
}

$nyId = DB::settInn($tabell, $data);
revider($type . '_opprettet', $type, $nyId);
Svar::ok(['id' => $nyId, 'beskjed' => 'Lagret.', ...$hent()]);
