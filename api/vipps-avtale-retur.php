<?php
/**
 * Kunden er tilbake fra Vipps etter aa ha godkjent (eller avvist) en avtale.
 *
 * Vi tar ikke returen som bevis paa noe. Vi sporr Vipps om status, og sender
 * kunden videre til Min side. Kom hen aldri tilbake, tar cron det neste gang.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

// Hvem kom tilbake?
//
// Sesjonen forst. Har hun ingen — hun godkjente i Vipps-appen, som aapner sin
// egen nettleser, eller hun kom tilbake i en annen fane — sto det tidligere
// bare «if ($medlem !== null)», og da gjorde denne sida INGENTING. Avtalen
// ble liggende paa «venter» til trekkrunden spurte Vipps, opptil et dogn
// senere.
//
// Derfor staar medlemsnummeret i returadressen (se Medlemskap::startAvtale).
// Det er ingen hemmelighet, og det gir ingen tilgang: alt som skjer er at vi
// sporr Vipps om en avtale vi selv har opprettet, og sender henne til Min
// side. Svaret er det samme uansett hvem som ber.
$medlem = Sesjon::medlem();
$medlemId = $medlem !== null
    ? (int) $medlem['id']
    : Foresporsel::heltall('m');

// En fremmed kan skrive hva som helst i adressen. Det koster oss et oppslag
// mot Vipps hver gang, saa det er verdt en grense.
if ($medlem === null && $medlemId > 0) {
    Rate::sjekk('avtale-retur', maks: 30, vindu: 600);
}

// Det besoksmaalingen trenger for aa telle et nytt medlemskap — og bare
// naar det ble nytt akkurat naa. Sto avtalen alt som aktiv (cron rakk det
// foerst, eller returlenka aapnes en gang til), sendes ingenting med: da
// er det ikke en fullfoert innmelding, det er en side som lastes paa nytt.
// Samme form som api/betaling-retur.php, saa nettsida kan telle det likt.
// (Eieren, 12. september 2026: Google Ads-konverteringene.)
$kvittering = '';

if ($medlemId > 0) {
    $a = Medlemskap::avtale($medlemId);
    if ($a !== null) {
        try {
            $for = (string) $a['status'];
            $ny = Medlemskap::oppdaterFraVipps($a);
            if ($ny === 'aktiv' && $for !== 'aktiv') {
                $kvittering = '&kjop=A' . (int) $a['id']
                    . '&belop=' . number_format((int) $a['pris_ore'] / 100, 2, '.', '')
                    . '&slag=medlemskap';
            }
        } catch (Throwable $e) {
            // Kommer vi ikke fram til Vipps, skal hun likevel videre. Runden
            // tar den neste gang.
            logg_feil('Fikk ikke sjekket avtalen ved retur fra Vipps', $e);
        }
    }
}

header('Location: ' . Config::nettsted() . '/min-side?avtale=1' . $kvittering);
exit;
