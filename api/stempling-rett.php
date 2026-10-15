<?php
/** Medlemmet ser egne økter og sender forslag. Ingen direkte endring i timekontoen. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';

$medlem = krev_medlem();
$id = (int) $medlem['id'];
if (!DB::harTabell('stempling_rettinger')) {
    Svar::feil('Verkstedtid er ikke klar ennå. Si fra i verkstedet.');
}
if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();
    Rate::sjekk('stempling-rett', maks: 5, vindu: 3600, nokkel: (string) $id);
    try {
        $sakId = StemplingRett::foresla($id, Foresporsel::heltall('oktId'),
            Foresporsel::tekst('dato'), Foresporsel::tekst('inn'), Foresporsel::tekst('ut'),
            Foresporsel::tekst('forklaring'), Foresporsel::heltall('sakId'));
    } catch (InvalidArgumentException $e) {
        Svar::feil($e->getMessage());
    }
    try {
        Varsel::malTilAdmin('intern_ny_foresporsel', [
            'navn' => (string) ($medlem['navn'] ?? 'Medlem'),
            'oppsummering' => 'Forslag om verkstedtid ' . Foresporsel::tekst('dato')
                . ': ' . Foresporsel::tekst('inn') . '–' . Foresporsel::tekst('ut')
                . ".\n" . Foresporsel::tekst('forklaring')
                . "\n\nÅpne saken i dagens admin: https://lissom.no/admin#idag",
        ], 'stempling_retting', $sakId);
    } catch (Throwable $e) {
        logg_feil('Kunne ikke varsle om forslag til verkstedtid', $e);
    }
    Svar::ok(['sakId' => $sakId, 'beskjed' => 'Forslaget er sendt til verkstedet. Timene endres først etter kontroll.']);
}
Svar::json(StemplingRett::oversikt($id));
