<?php
/**
 * Det nye admin (/admin2) — det lille det trenger som det gamle ikke har.
 *
 * Eieren, 30. september 2026: det nye admin bygges VED SIDEN AV det gamle,
 * og det byttes naar alt er klart. Tallene paa «I dag» kommer fra de samme
 * endepunktene som Oversikt i det gamle admin (oversikt.php, foresporsler.php,
 * venteliste.php osv.), med uendret svarformat — to regnestykker skal ikke
 * kunne svare hver sitt. Her ligger bare:
 *
 *   GET                         hvem jeg er, modulbryterne og hurtigvalgene mine
 *   POST handling=hurtigvalg    { valg: [...] } lagrer hurtigvalgene mine
 *
 * Modulbryterne ligger i content_blocks som de andre bryterne under ⊙ Synlighet:
 *
 *   Vis/admin2          pilla «Prøv nytt admin» i det gamle admin. Av fra start;
 *                       bare «ja» er paa. Siden /admin2 virker uansett for admin.
 *   Vis/admin2_<modul>  hver modul (idag, kurs, folk, penger, mer). Mangler
 *                       raden, er den paa.
 *
 * Hurtigvalgene lagres per admin-bruker i innstillinger
 * (admin2_hurtigvalg_<medlem-id>), som en liste med noekler. Eieren valgte
 * 30. september at han velger dem selv («Tilpass»).
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();
$id = (int) $admin['id'];

const ADMIN2_MODULER = ['idag', 'kurs', 'folk', 'penger', 'mer'];
const ADMIN2_HURTIGVALG = ['startkurs', 'tabetalt', 'nykursdato', 'dagsoppgjor', 'nyttkurs', 'melding', 'tildeltakere', 'leggut', 'kasse', 'skisser', 'arskalender'];
const ADMIN2_STANDARD = ['startkurs', 'tabetalt', 'nykursdato', 'dagsoppgjor'];

$nokkel = 'admin2_hurtigvalg_' . $id;

$bryter = static function (string $n): ?string {
    $v = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => 'Vis/' . $n]);
    return $v === null || $v === false ? null : (string) $v;
};

$hurtigvalg = static function () use ($nokkel): array {
    if (!DB::harTabell('innstillinger')) {
        return ADMIN2_STANDARD;
    }
    $raa = DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => $nokkel]);
    $liste = is_string($raa) ? json_decode($raa, true) : null;
    if (!is_array($liste)) {
        return ADMIN2_STANDARD;
    }
    return array_values(array_filter($liste, static fn($v) => is_string($v) && in_array($v, ADMIN2_HURTIGVALG, true)));
};

if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();
    if (Foresporsel::tekst('handling') !== 'hurtigvalg') {
        Svar::feil('Ukjent handling.');
    }
    if (!DB::harTabell('innstillinger')) {
        Svar::feil('Migrasjon 036 er ikke kjørt. Kjør vedlikehold først.');
    }
    $raa = Foresporsel::kropp()['valg'] ?? [];
    if (!is_array($raa)) {
        Svar::feil('Mangler valgene.');
    }
    $valg = [];
    foreach ($raa as $v) {
        if (is_string($v) && in_array($v, ADMIN2_HURTIGVALG, true) && !in_array($v, $valg, true)) {
            $valg[] = $v;
        }
    }
    DB::kjor(
        'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
        [$nokkel, json_encode($valg, JSON_UNESCAPED_UNICODE), $id ?: null]
    );
    Svar::ok(['hurtigvalg' => $valg, 'beskjed' => 'Hurtigvalgene er lagret.']);
}

Foresporsel::krevMetode('GET');

$moduler = [];
foreach (ADMIN2_MODULER as $m) {
    $moduler[$m] = $bryter('admin2_' . $m) !== 'nei';
}

Svar::json([
    'ok'         => true,
    'meg'        => ['id' => $id, 'navn' => (string) ($admin['navn'] ?? '')],
    'prove'      => $bryter('admin2') === 'ja',
    'moduler'    => $moduler,
    'hurtigvalg' => $hurtigvalg(),
    'alleHurtigvalg' => ADMIN2_HURTIGVALG,
]);
