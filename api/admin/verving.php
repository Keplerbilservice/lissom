<?php
/**
 * Markedsfoering › Vervepremie.
 *
 *   GET                         timene og «Vervet saa langt»
 *   POST handling=timer  { timer }   antall timer den som verver faar
 *
 * Bryteren (Vis/verving) lagres som de andre bryterne, gjennom
 * api/admin/innhold.php. Se app/lib/verving.php.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if (!Verving::klar()) {
    Svar::feil('Migrasjon 224 er ikke kjørt. Trykk ⚙ Kjør oppdateringer.');
}

if (Foresporsel::metode() === 'GET') {
    Svar::json([
        'paa'   => Verving::paa(),
        'timer' => Verving::timer(),
        'liste' => Verving::liste(),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (Foresporsel::tekst('handling') !== 'timer') {
    Svar::feil('Ukjent handling.');
}

$timer = (int) (Foresporsel::kropp()['timer'] ?? 0);
if ($timer < 1 || $timer > 100) {
    Svar::feil('Skriv et antall timer mellom 1 og 100.');
}

$for = Verving::timer();
Verving::settTimer($timer, (int) $admin['id']);
revider('verving_timer', 'innstilling', null, ['fra' => $for, 'til' => $timer]);

Svar::ok(['timer' => Verving::timer(), 'beskjed' => 'Lagret']);
