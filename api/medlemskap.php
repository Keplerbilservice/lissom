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
    // Oppsigelsestida hoerer sammen med bindingstida, og innmeldinga skal si
    // begge — riktig for det medlemskapet man holder paa aa velge. Den sto
    // som «2 maaneder» for alle, ogsaa for aarsavtalen med tolv.
    'oppsigelse' => (int) ($p['oppsigelse_mnd'] ?? 1),
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
    // Den utfyllende teksten paa medlemskapssida, og «Viktig aa vite» under
    // den (migrasjon 127). Staar de tomt — eller staar migrasjonen ukjort —
    // ser sida ut noeyaktig som for.
    'langtekst'  => (string) ($p['langtekst'] ?? ''),
    'viktig'     => Medlemskap::punkter($p['viktig'] ?? null),
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
                // Hvilken dato en oppsigelse i dag ville landet paa.
                //
                // Bekreftelsen sa «det gjelder ut utgangen av oppsigelsestida»
                // naar ingen oppsigelse loep ennaa — altsaa akkurat naar
                // medlemmet skulle bestemme seg. Naa staar datoen der, regnet
                // av den samme regelen som utfoerer den.
                'sluttHvisOppsagt' => Booking::norskDatoKort(
                    Medlemskap::sluttdato($a) . ' 12:00:00'
                ),
                // Oppsigelsestida hoerer til planen, ikke til teksten. «Én
                // maaned» sto fast i bekreftelsen; har en plan to, loy den.
                'oppsigelseMnd' => (static function () use ($a): int {
                    $plan = Medlemskap::plan((string) $a['plan']);
                    return $plan === null ? 1 : max(0, (int) ($plan['oppsigelse_mnd'] ?? 1));
                })(),
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

        // Fast trekk eller én betaling — planen bestemmer.
        //
        // Eieren, 1. september: «Funker det paa alle medlemskap?»
        //
        // Det gjorde det ikke. Denne opprettet alltid en loepende avtale i
        // Vipps, uansett hvilken plan det var. For «Prov Lissom» — ti timer
        // i lopet av tretti dager, som skal betales én gang — ville det gitt
        // et trekk hver maaned for noe som er over. Innmeldingen i
        // api/bli-medlem.php har alltid skilt paa dette; her sto skillet
        // ikke.
        //
        // Regelen er den samme som der: krever planen fast trekk, er valget
        // tatt. Er den en engangsplan, kan den ikke ha fast trekk. Ellers
        // gjelder fast trekk, som for.
        $planNavn = Foresporsel::tekst('plan');
        $plan     = $planNavn === '' ? null : Medlemskap::plan($planNavn);
        if ($plan === null) {
            Svar::feil('Ukjent medlemskap.');
        }

        $betaling = Foresporsel::tekst('betaling') === 'selv' ? 'selv' : 'trekk';
        if (Medlemskap::kreverFastTrekk($plan)) {
            $betaling = 'trekk';
        }
        if ((int) ($plan['engangs'] ?? 0) === 1) {
            $betaling = 'selv';
        }

        try {
            $ut = $betaling === 'trekk'
                ? Medlemskap::startAvtale($medlem, $planNavn)
                : Medlemskap::startEngangs($medlem, $planNavn);
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
