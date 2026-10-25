<?php
/**
 * Hit sendes kunden tilbake fra Vipps.
 *
 * Vi stoler ALDRI paa at kunden kom tilbake som bevis paa at det er betalt —
 * hen kan ha trykket avbryt, eller lukket appen. Vi sporr Vipps direkte.
 * Webhooken er den egentlige kilden; dette er sikkerhetsnettet naar den er treg.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$referanse = Foresporsel::tekst('ref');
// Hvor kunden skal tilbake. Forsida som foer — eller en side hos oss, naar
// kjoepet ble gjort der («Ta med barn» sendes tilbake til Min side). Bare
// en sti som begynner med én skraastrek, saa ingen kan sende folk ut av
// nettstedet gjennom adressen.
//
// Kurssida og gavekortsida (til=/kurs/<adresse>, til=/gavekort, satt der
// kjoepet startet) faar utfallet som «?betaling=»: de tegnes paa serveren,
// og bare med ?betaling= sender Nett::kan() dem videre til appen, som viser
// utfallet og teller kjoepet. Tallene for maalingen staar fortsatt etter #,
// saa de verken naar serveren, loggene eller GA4 som en del av adressen.
// Forsida er som foer: «/#betaling=».
$til = Foresporsel::tekst('til');
$til = preg_match('#^/[a-z0-9\-/]*$#', $til) === 1 ? $til : '/';
$tilbake = static fn(string $utfall, string $ekstra = ''): never => Svar::omdiriger(
    $til === '/'
        ? Config::nettsted() . '/#betaling=' . $utfall . $ekstra
        : Config::nettsted() . $til . '?betaling=' . $utfall . ($ekstra !== '' ? '#' . ltrim($ekstra, '&') : '')
);

if ($referanse === '') {
    $tilbake('ukjent');
}

$betaling = DB::en(
    'SELECT id, status, belop_ore, formal FROM payments WHERE vipps_reference = :r',
    ['r' => $referanse]
);
if ($betaling === null) {
    $tilbake('ukjent');
}

/**
 * Det besoksmaalingen trenger for aa telle kjopet, og ikke noe mer.
 *
 * Uten belopet ser en solgt kursplass til 2 800 kroner ut som et gavekort
 * til 300 — og da sier statistikken ingenting om hva som faktisk lonner seg.
 * Vipps-referansen sendes ikke med; vaart eget radnummer holder for aa
 * kjenne igjen det samme kjopet to ganger.
 */
$kvittering = static function (array $b): string {
    $ut = '&kjop=' . (int) $b['id']
        . '&belop=' . number_format((int) $b['belop_ore'] / 100, 2, '.', '')
        . '&slag=' . rawurlencode((string) $b['formal']);
    // Kanalene serveren har sendt, holder paa med eller skal til aa sende
    // kjoepet til (Maaling::kanalStatus() — webhooken kan ha satt «betalt»
    // mens kallet til GA4 fortsatt gaar). Dit sender nettleseren ikke det
    // samme kjoepet — se maalKjop(). «forsokt» tar med det som feilet: da
    // sender nettleseren purchase som reserve, men aldri Ads-hendelsene.
    try {
        $st = Maaling::kanalStatusFor((int) $b['id']);
        if ($st['server'] !== []) {
            $ut .= '&server=' . implode('.', $st['server']);
        }
        if ($st['forsokt'] !== []) {
            $ut .= '&forsokt=' . implode('.', $st['forsokt']);
        }
    } catch (Throwable $e) {
        logg_feil('Fant ikke maalingen for betaling ' . (int) $b['id'], $e);
    }
    return $ut;
};

if ($betaling['status'] === 'betalt') {
    $tilbake('ok', $kvittering($betaling)); // webhooken rakk det først
}

try {
    $status = Vipps::hentBetaling($referanse);
} catch (Throwable $e) {
    logg_feil('Statusoppslag ved retur feilet for ' . $referanse, $e);
    // Betalingen kan likevel ha gaatt gjennom. Cron sjekker paa nytt om noen minutter.
    $tilbake('venter');
}

DB::oppdater('payments', [
    'siste_payload' => json_encode($status, JSON_UNESCAPED_UNICODE),
], ['id' => $betaling['id']]);

$tilstand = strtoupper((string) ($status['state'] ?? ''));

if ($tilstand === 'AUTHORIZED') {
    // Samme regel som webhooken og cron: Vipps::anvendTilstand() trekker det
    // som gjenstaar og markerer betalt bare naar trekket gikk. Foer ble
    // «betalt» satt ogsaa naar trekket feilet — plassen var kundens, uten at
    // pengene var trukket. Gikk trekket ikke, venter kunden; cron proever igjen.
    Vipps::anvendTilstand($referanse, $status);
    $naa = (string) DB::verdi('SELECT status FROM payments WHERE id = :i', ['i' => $betaling['id']]);
    if ($naa === 'betalt') {
        $tilbake('ok', $kvittering($betaling));
    }
    $tilbake('venter');
}

if ($tilstand === 'TERMINATED' || $tilstand === 'ABORTED' || $tilstand === 'EXPIRED') {
    DB::oppdater('payments', ['status' => 'avbrutt'], ['id' => $betaling['id']]);
    $tilbake('avbrutt');
}

$tilbake('venter');
