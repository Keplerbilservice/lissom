<?php
/**
 * Kassa på iPaden: hvem står i kassa (Lissom Kasse, eieren 8. oktober 2026).
 *
 *   GET                       er kassa låst opp, og av hvem
 *   POST handling=pin { pin } låser opp med PIN (4 sifre)
 *   POST handling=laas        låser kassa
 *
 * Bare kassekontoen (rollen «kasse») og admin, og bare når «Vis/kasse» er på.
 * Se app/lib/kassetilgang.php.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

KasseTilgang::krevKonto();

if (Foresporsel::metode() === 'GET') {
    $p = KasseTilgang::ulast();
    Svar::json([
        'person'       => $p === null ? null : ['navn' => $p['navn']],
        'laasMinutter' => KasseTilgang::LAAS_MINUTTER,
        // Innlogget admin (ikke kassekontoen): kassa viser «✕ Lukk» tilbake til ny admin (eieren 09.10.2026).
        'admin'        => Sesjon::erAdmin() && !Sesjon::erKasse(),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');
if ($handling === 'laas') {
    KasseTilgang::laas();
    Svar::ok();
}
if ($handling !== 'pin') {
    Svar::feil('Ukjent handling.');
}
$p = KasseTilgang::laasOpp(Foresporsel::tekst('pin'));
Svar::ok(['person' => ['navn' => $p['navn']]]);
