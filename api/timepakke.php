<?php
/**
 * Timepakken og svarene etter «Ikke nå» (eieren, 28. september 2026).
 *
 *   POST handling=kjop                          → url til Vipps
 *   POST handling=svar { grunn, fritekst, vindu } lagrer svaret i vindu 4
 *
 * Reglene staar i app/lib/timepakke.php.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$medlem = krev_medlem();

switch (Foresporsel::tekst('handling')) {
    case 'kjop':
        Rate::sjekk('timepakke', maks: 5, vindu: 600);
        try {
            $ut = Timepakke::start($medlem);
        } catch (RuntimeException $e) {
            Svar::feil($e->getMessage());
        }
        revider('timepakke_startet', 'member', (int) $medlem['id'], ['timepakke' => $ut['id']]);
        Svar::ok(['url' => $ut['url']]);

    case 'svar':
        Rate::sjekk('timer_svar', maks: 10, vindu: 600);
        try {
            Timepakke::lagreSvar(
                (int) $medlem['id'],
                Foresporsel::tekst('grunn'),
                Foresporsel::tekst('fritekst'),
                Foresporsel::tekst('vindu')
            );
        } catch (RuntimeException $e) {
            Svar::feil($e->getMessage());
        }
        Svar::ok(['beskjed' => 'Takk']);

    default:
        Svar::feil('Ukjent handling.');
}
