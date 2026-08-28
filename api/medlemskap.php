<?php
/**
 * Medlemskap: start, status og oppsigelse.
 *
 *   GET                      planene, og min egen avtale
 *   POST handling=start      { plan }   → url til Vipps
 *   POST handling=siOpp      stopper avtalen i Vipps
 *
 * Avtalen godkjennes i Vipps én gang, og belastes deretter hver maaned av
 * cron. Vi setter den aldri aktiv selv — vi sporr Vipps.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$planer = static fn(): array => array_map(static fn($p) => [
    'navn'     => $p['navn'],
    'pris'     => Booking::kroner((int) $p['pris_ore']),
    'prisOre'  => (int) $p['pris_ore'],
    'periode'  => (int) $p['engangs'] === 1 ? 'engangs' : 'per måned',
    'timer'    => $p['timer'] === null ? null : (int) $p['timer'],
    'binding'  => (int) $p['binding_mnd'],
    'engangs'  => (bool) $p['engangs'],
    // Krever planen fast trekk, faar ikke medlemmet velge betalingsmaate.
    'fastTrekk' => Medlemskap::kreverFastTrekk($p),
    // Teksten kunden leser. Den staar i basen fordi verkstedet skal kunne
    // skrive den om selv — «for 30 dager» sier ingenting om de ti timene.
    'merke'      => (string) ($p['merke'] ?? ''),
    'undertekst' => (string) ($p['undertekst'] ?? ''),
    'beskrivelse'=> (string) ($p['beskrivelse'] ?? ''),
    'punkter'    => Medlemskap::punkter($p['punkter'] ?? null),
    'passerFor'  => (string) ($p['passer_for'] ?? ''),
    'bilde'      => (string) ($p['bilde'] ?? ''),
    'fremhevet'  => !empty($p['fremhevet']),
], Medlemskap::planer());

// ------------------------------------------------------------------ lesing
if (Foresporsel::metode() === 'GET') {
    $medlem = Sesjon::medlem();
    $min = null;

    if ($medlem !== null) {
        // Til visning tar vi ogsaa med en avtale som er stoppet eller utloept.
        // Medlemskap::avtale() svarer bare paa «loeper det en avtale naa», og
        // med den alene sa Min side «Aktivt» i det sekundet medlemmet hadde
        // sagt opp — avtalen falt ut av svaret, og kortet gikk tilbake til
        // standardteksten.
        $a = Medlemskap::avtale((int) $medlem['id'])
            ?? DB::en(
                'SELECT * FROM subscriptions WHERE member_id = :m ORDER BY id DESC LIMIT 1',
                ['m' => (int) $medlem['id']]
            );
        if ($a !== null) {
            $min = [
                'plan'       => $a['plan'],
                'pris'       => Booking::kroner((int) $a['pris_ore']),
                'status'     => $a['status'],
                'nesteTrekk' => $a['neste_trekk']
                    ? Booking::norskDatoKort((string) $a['neste_trekk'] . ' 12:00:00') : null,
                'binding'    => $a['binding_til']
                    ? Booking::norskDatoKort((string) $a['binding_til'] . ' 12:00:00') : null,
                // Hvorfor det ikke gaar, naar det ikke gaar. Min side viser
                // teksten framfor en knapp som ikke kan trykkes.
                'hindring'   => $a['status'] === 'aktiv'
                    ? Medlemskap::hvorforIkkeSiOpp($a) : 'Medlemskapet løper ikke nå.',
                'kanSiOpp'   => $a['status'] === 'aktiv' && Medlemskap::hvorforIkkeSiOpp($a) === null,
                'bundetTil'  => $a['binding_til']
                    ? Booking::norskDatoKort((string) $a['binding_til'] . ' 12:00:00') : null,
                'sagtOpp'    => !empty($a['sagt_opp_at']),
                'slutter'    => !empty($a['slutter'])
                    ? Booking::norskDatoKort((string) $a['slutter'] . ' 12:00:00') : null,
            ];
        }
    }

    Svar::json(['planer' => $planer(), 'min' => $min]);
}

// ----------------------------------------------------------------- skriving
Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$medlem = krev_medlem();

switch (Foresporsel::tekst('handling')) {

    case 'start':
        Rate::sjekk('medlemsavtale', maks: 5, vindu: 600);
        try {
            $ut = Medlemskap::startAvtale($medlem, Foresporsel::tekst('plan'));
        } catch (RuntimeException $e) {
            Svar::feil($e->getMessage());
        }
        revider('medlemsavtale_startet', 'subscription', $ut['id'], ['plan' => Foresporsel::tekst('plan')]);
        Svar::ok(['url' => $ut['url']]);

    case 'siOpp':
        $a = Medlemskap::avtale((int) $medlem['id']);
        if ($a === null) {
            Svar::feil('Du har ingen løpende avtale.');
        }
        // Bindingstida og «én oppsigelse om gangen» ligger i regelen selv, saa
        // den gjelder uansett hvem som kaller — ogsaa herfra.
        try {
            Medlemskap::siOpp($a);
        } catch (RuntimeException $e) {
            Svar::feil($e->getMessage());
        }
        $slutter = DB::verdi('SELECT slutter FROM subscriptions WHERE id = :i', ['i' => (int) $a['id']]);
        revider('medlemsavtale_sagt_opp', 'subscription', (int) $a['id'], ['slutter' => $slutter]);
        Svar::ok(['beskjed' => 'Medlemskapet er sagt opp, og gjelder ut '
            . Booking::norskDatoKort((string) $slutter . ' 12:00:00') . '.']);

    // Kunden kan ha godkjent i appen uten aa komme tilbake til nettsiden.
    // Denne lar Min side sporre Vipps paa nytt.
    case 'sjekk':
        $a = Medlemskap::avtale((int) $medlem['id']);
        if ($a === null) {
            Svar::feil('Du har ingen avtale å sjekke.');
        }
        $status = Medlemskap::oppdaterFraVipps($a);
        Svar::ok(['status' => $status]);

    default:
        Svar::feil('Ukjent handling.');
}
