<?php
/**
 * Serverer ett dokument fra verkstedet.
 *
 *   GET ?id=<id>        aapne — vises i nettleseren om typen kan vises
 *   GET ?id=<id>&ned=1  last ned — Word maa den veien, den kan ikke vises
 *
 * Filene ligger utenfor det som publiseres, saa de maa gaa gjennom PHP. Det
 * er ikke bare en ulempe: her kan vi la vaere aa levere en kontrakt til en
 * som ikke skal ha den.
 *
 * Hvem faar hva:
 *   admin        alt.
 *   medlem       bare dokumenter i kort som er slaatt paa for medlemmer.
 *   alle andre   ingenting. Ogsaa 404 — da roeper vi ikke at fila finnes.
 *
 * Merk at bryteren leses paa HVER forespoersel. Slaar eieren av et kort, er
 * lenkene medlemmene alt har sett doede med det samme.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

if (!Dokumenter::klar()) {
    Svar::feil('Fant ikke dokumentet.', 404);
}

$dok = Dokumenter::en(Foresporsel::heltall('id'));
if ($dok === null) {
    Svar::feil('Fant ikke dokumentet.', 404);
}

if (!Sesjon::erAdmin()) {
    // Kortet maa vaere slaatt paa, OG den som spor maa vaere medlem.
    $medlem = Sesjon::medlem();
    if (((int) $dok['vis_medlem']) !== 1 || $medlem === null || !er_aktivt_medlem($medlem)) {
        Svar::feil('Fant ikke dokumentet.', 404);
    }
}

$sti = Dokumenter::sti($dok);
if (!is_file($sti)) {
    Svar::feil('Fant ikke dokumentet.', 404);
}

$mime = (string) $dok['mime'];
$ned  = Foresporsel::tekst('ned') === '1' || !Dokumenter::kanVises($mime);

// Navnet nedlastingen faar. Sammensatt av det eieren kjenner igjen og
// endelsen fila faktisk har — aldri av noe som ble lastet opp.
$endelse  = strtolower(Dokumenter::etikett($mime));
$filnavn  = preg_replace('/[^\p{L}\p{N} ._-]+/u', '', (string) $dok['originalnavn']) ?: 'dokument';
$filnavn .= '.' . $endelse;

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($sti));
header(
    'Content-Disposition: ' . ($ned ? 'attachment' : 'inline')
    . '; filename="' . str_replace('"', '', $filnavn) . '"'
);
// Ikke mellomlagres av noen andre enn nettleseren selv: hva som er lov aa se
// avhenger av hvem som spor, og det kan endre seg naar som helst.
header('Cache-Control: private, max-age=0, no-store');
header('X-Content-Type-Options: nosniff');
readfile($sti);
exit;
