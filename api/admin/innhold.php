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

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle('SELECT nokkel, verdi FROM content_blocks');
    $ut = [];
    foreach ($rader as $r) {
        $ut[$r['nokkel']] = $r['verdi'];
    }
    Svar::json(['innhold' => (object) $ut]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$admin = Sesjon::medlem();

$endringer = Foresporsel::kropp()['endringer'] ?? null;
if (!is_array($endringer) || $endringer === []) {
    Svar::feil('Ingen endringer aa lagre.');
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
