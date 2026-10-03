<?php
/**
 * Tekstene paa nettsiden.
 *
 *   GET                 alle lagrede tekster
 *   POST                lagre endringer  { endringer: { nokkel: verdi, ... } }
 *
 * Noklene er de samme som admin-panelet bruker, f.eks. «Forside/0/Overskrift».
 * Tekster som aldri er endret ligger ikke her — da gjelder den som staar i
 * designet. Slik ser man ogsaa hva som faktisk er redigert.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

// GET ?fil=seo-kart | standard — kartene admin-ny trenger.
//
// seo-kart.json og innhold-standard.json ligger i webroten, men .htaccess
// stenger alle .json for nettet (403). Admin-ny hentet dem rett og fikk 403
// paa SEO, GEO og Tekster i produksjon (eieren 03.10). De leses her, bak
// krev_admin, i stedet for aa aapne .json for alle.
$fil = Foresporsel::metode() === 'GET' ? (string) ($_GET['fil'] ?? '') : '';
if ($fil !== '') {
    $filer = ['seo-kart' => 'seo-kart.json', 'standard' => 'innhold-standard.json'];
    if (!isset($filer[$fil])) {
        Svar::feil('Ukjent fil.', 404);
    }
    $data = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/' . $filer[$fil]), true);
    if (!is_array($data)) {
        Svar::feil('Fant ikke ' . $filer[$fil] . '.', 500);
    }
    Svar::json($data);
}

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle('SELECT nokkel, verdi FROM content_blocks');
    $ut = [];
    foreach ($rader as $r) {
        $ut[$r['nokkel']] = $r['verdi'];
    }
    // Naar noe sist ble endret. Sidemenyen skrev «Sist publisert: I dag 07:40»
    // som fast tekst — samme klokkeslett uansett naar du saa etter.
    $sist = DB::verdi('SELECT MAX(updated_at) FROM content_blocks');
    $sistTekst = null;
    if ($sist !== null && $sist !== false) {
        $oslo = new DateTimeZone('Europe/Oslo');
        $tid  = (new DateTimeImmutable((string) $sist, new DateTimeZone('UTC')))->setTimezone($oslo);
        $naa  = new DateTimeImmutable('now', $oslo);
        $dag  = $tid->format('Y-m-d');
        $sistTekst = match (true) {
            $dag === $naa->format('Y-m-d')                  => 'I dag ' . $tid->format('H:i'),
            $dag === $naa->modify('-1 day')->format('Y-m-d') => 'I går ' . $tid->format('H:i'),
            default                                          => $tid->format('j.n.Y') . ' ' . $tid->format('H:i'),
        };
    }
    Svar::json(['innhold' => (object) $ut, 'sistEndret' => $sistTekst]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$admin = Sesjon::medlem();

$endringer = Foresporsel::kropp()['endringer'] ?? null;
if (!is_array($endringer) || $endringer === []) {
    Svar::feil('Ingen endringer å lagre.');
}
if (count($endringer) > 200) {
    Svar::feil('For mange endringer i én omgang.');
}

$lagret = 0;
foreach ($endringer as $nokkel => $verdi) {
    $nokkel = mb_substr((string) $nokkel, 0, 191);
    if ($nokkel === '' || !is_scalar($verdi)) {
        continue;
    }

    DB::kjor(
        'INSERT INTO content_blocks (nokkel, verdi, endret_av)
              VALUES (:n, :v, :a)
         ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
        ['n' => $nokkel, 'v' => (string) $verdi, 'a' => $admin['id'] ?? null]
    );
    $lagret++;
}

// Revisjonsloggen far noklene, ikke teksten. Hvem endret hva og naar er det
// interessante; hele brodteksten ville bare fylt opp loggen.
revider('innhold_lagret', null, null, ['nokler' => array_slice(array_keys($endringer), 0, 40)]);

Svar::ok(['lagret' => $lagret]);
