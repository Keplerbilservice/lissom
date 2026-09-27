<?php
/**
 * Kursboost-pakken: bilde og én knapp per del.
 *
 *   GET  ?id=<utkast>                        pakken: bilder, valgt, delene
 *   POST handling=bilder    { id }           tre nye bildeforslag (Gemini)
 *   POST handling=velg      { id, bilde }    velg ett av forslagene
 *   POST handling=del       { id, del, tekst }  instagram | facebook | artikkel | nyhetsbrev
 *
 * «Send til medlemmene» gaar gjennom api/admin/beskjed.php med «kursboost»,
 * saa utsendingen er den samme som «Melding til medlemmene». Se
 * app/lib/kursboost.php for hvorfor.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

$id = Foresporsel::metode() === 'GET'
    ? (int) Foresporsel::tekst('id')
    : Foresporsel::heltall('id');
$u = Kursboost::utkast($id);
if ($u === null) {
    Svar::feil('Fant ikke kursboosten.', 404);
}

if (Foresporsel::metode() === 'GET') {
    Svar::ok(['pakke' => Kursboost::pakke($u)]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$handling = Foresporsel::tekst('handling');

try {
    switch ($handling) {
        case 'bilder':
            @set_time_limit(240);
            $r = Kursboost::lagBilder($u);
            revider('kursboost_bilder', 'ai_utkast', $id, ['antall' => count($r['bilder'])]);
            $ore = $r['kostnadOre'];
            Svar::ok([
                'pakke'   => Kursboost::pakke((array) Kursboost::utkast($id)),
                'kostnad' => $ore < 100 ? $ore . ' øre' : Booking::kroner($ore),
            ]);

        case 'velg':
            Kursboost::velgBilde($u, Foresporsel::tekst('bilde'));
            Svar::ok(['pakke' => Kursboost::pakke((array) Kursboost::utkast($id))]);

        case 'del':
            $r = Kursboost::utfor($u, Foresporsel::tekst('del'), (string) (Foresporsel::kropp()['tekst'] ?? ''));
            Svar::ok($r + ['pakke' => Kursboost::pakke((array) Kursboost::utkast($id))]);

        default:
            Svar::feil('Ukjent handling.');
    }
} catch (RuntimeException $e) {
    // Meldingene fra Gemini og Meta er skrevet for aa vises — «taket er
    // naadd», «noekkelen ble ikke godtatt». De skal ikke bli en 500.
    Svar::feil($e->getMessage());
}
