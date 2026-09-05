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
            // ── Naar avtalen og medlemskapet staar paa hver sin plan ──────
            //
            // Bytter verkstedet plan paa et medlem som har fast trekk i Vipps,
            // flyttes «members.medlemskap_type», men «subscriptions.plan» blir
            // staaende — se api/admin/medlemmer.php: Vipps eier beloepet paa en
            // godkjent avtale, og vi kan ikke skrive det om herfra.
            //
            // Det er riktig. Feilen var at Min side leste navnet og prisen fra
            // AVTALEN og timene fra MEDLEMSRADEN, og dermed viste det verste av
            // begge: «Mini 15 · kr. 1 790,- · 15 timer i måneden» rett over «35
            // av 35 timer». Eieren, 4. september, med bilde fra lissom.no:
            // «Viser feil medlemskap».
            //
            // Naa sier svaret begge deler hver for seg. Medlemskapet er det
            // medlemmet HAR — det styrer timer og tilgang. Avtalen er det som
            // trekkes. Skjermen kan si det som det er.
            $harPlan = trim((string) ($medlem['medlemskap_type'] ?? ''));
            $avtalePlan = trim((string) $a['plan']);
            $fastTrekk = trim((string) ($a['vipps_agreement_id'] ?? '')) !== '';
            $min = [
                // Planen medlemmet staar paa. Mangler den, er avtalen det
                // naermeste vi har — da er det ingen uenighet aa melde.
                'plan'       => $harPlan !== '' ? $harPlan : $avtalePlan,
                // Prisen paa planen medlemmet staar paa. Trekkes det et annet
                // beloep, staar det for seg under.
                'pris'       => (static function () use ($harPlan, $a): string {
                    $p = $harPlan !== '' ? Medlemskap::planUansett($harPlan) : null;
                    return Booking::kroner((int) ($p['pris_ore'] ?? $a['pris_ore']));
                })(),
                // Avtalen, naar den staar paa noe annet enn medlemskapet.
                'avtalePlan' => $harPlan !== '' && $avtalePlan !== '' && $avtalePlan !== $harPlan
                    ? $avtalePlan : null,
                'avtalePris' => Booking::kroner((int) $a['pris_ore']),
                'fastTrekk'  => $fastTrekk,
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
                    $plan = Medlemskap::planUansett((string) $a['plan']);
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

        // ── Kontaktopplysningene fra medlemskapskortet ──────────────
        //
        // Eieren, 3. september: «er det mulig aa faa samme kort paa
        // medlemskap som paa kurs?» Kortet har naa de samme feltene som
        // kursbookingen — e-post og telefon, fylt ut fra kontoen og mulig
        // aa rette for man betaler.
        //
        // Feltene maa bety noe. Medlemsvarslene «medlemstrekk_varsel» og
        // «medlemskap_fornyet» gaar til members.epost, og nummeret Vipps
        // faar naar avtalen eller betalingen opprettes er members.telefon.
        // Derfor lagres en rettelse paa kontoen, og avtalen opprettes med
        // det nye nummeret — ikke det gamle.
        //
        // Kallet virker som for uten feltene: «Forny» paa Min side og
        // medlemskapet i kassa sender dem ikke, og da roeres kontoen ikke.
        $epost   = mb_substr(Foresporsel::tekst('epost'), 0, 191);
        $telefon = mb_substr(Foresporsel::tekst('telefon'), 0, 32);
        $endring = [];
        if ($epost !== '') {
            if (!filter_var($epost, FILTER_VALIDATE_EMAIL)) {
                Svar::feil('Vi trenger en gyldig e-postadresse.');
            }
            if ($epost !== (string) ($medlem['epost'] ?? '')) {
                $endring['epost'] = $epost;
            }
        }
        if ($telefon !== '') {
            $nummer = normaliser_telefon($telefon);
            if ($nummer === '') {
                Svar::feil('Vi trenger et gyldig mobilnummer.');
            }
            if ($nummer !== (string) ($medlem['telefon'] ?? '')) {
                $endring['telefon'] = $nummer;
            }
        }
        if ($endring !== []) {
            DB::oppdater('members', $endring, ['id' => (int) $medlem['id']]);
            // startAvtale() og startEngangs() leser $medlem['telefon'].
            // Uten dette ville Vipps faatt det gamle nummeret.
            $medlem = $endring + $medlem;
            revider('medlem_kontakt_rettet', 'member', (int) $medlem['id'],
                ['felt' => array_keys($endring)]);
        }

        // ── Fast trekk finnes bare paa aarsavtalen ─────────────────────────────
        //
        // Eieren, 3. september: «jeg vil ikke ha dette alternativet paa noen andre
        // steder enn paa aarsavtalen».
        //
        // Her leste vi «betaling» fra kallet. Foerst ble alt som ikke sa «selv» til
        // trekk; saa snudde vi det, saa bare et uttrykkelig «trekk» ga trekk. Begge
        // deler lot en loepende avtale bli opprettet paa et medlemskap som ikke skal
        // ha en — det var bare vanskeligere aa treffe.
        //
        // Naa avgjor planen alene. Feltet leses ikke lenger: krever planen fast
        // trekk, blir det trekk; ellers vanlig Vipps. En gammel fane, et kall som
        // sender «trekk», en pille som ble staaende igjen i en cache — ingenting av
        // det kan lage en avtale mer.
        //
        // «krever_fast_trekk» staar i basen, satt av migrasjon 081 paa alt med tolv
        // maaneders bindingstid. I dag er det bare aarsmedlemskapet. En engangsplan
        // kan uansett ikke ha fast trekk, og faar det ikke her heller.
        $betaling = Medlemskap::kreverFastTrekk($plan) ? 'trekk' : 'selv';

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
