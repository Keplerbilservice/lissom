<?php
/**
 * Skisser på Min side — for medlemmer og kursdeltakere.
 *
 * Se app/lib/skisser.php for reglene og app/lib/skisser_api.php for
 * handlingene. Bryterne ⊙ Synlighet → «Skisser for medlemmer» og «Skisser for
 * kursdeltakere» avgjør hvem som slipper inn; står begge av, svarer serveren
 * 403 til alle andre enn admin.
 *
 *   GET ?sjekk=1       { paa } — skal flisen på Min side vises?
 *   GET ?bilde=<fil>   et bilde fra en tavle du får se
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

if (Foresporsel::metode() === 'GET' && Foresporsel::tekst('bilde') !== '') {
    SkisserApi::leverBilde(Foresporsel::tekst('bilde'));
}

if (Foresporsel::metode() === 'GET' && Foresporsel::tekst('sjekk') === '1') {
    $m = Sesjon::medlem();
    Svar::json(['ok' => true, 'paa' => $m !== null && Skisser::slippInn($m)]);
}

$medlem = krev_medlem();

if (!Skisser::slippInn($medlem)) {
    Svar::feil('Skisser er ikke slått på.', 403);
}

SkisserApi::haandter($medlem);
