<?php
/**
 * Medlemskapene som kan kjopes.
 *
 *   GET                    alle planer, ogsaa de som er tatt ned
 *   POST handling=lagre    ny eller endret plan
 *   POST handling=slett    fjern en plan
 *
 * Planene laa bare i databasen, lagt inn av en migrasjon. Skulle prisen paa
 * «30 timer» endres, matte noen skrive SQL — og verkstedet kunne verken lage
 * et nytt medlemskap eller ta et gammelt ut av salg.
 *
 * Navnet er noekkelen, og medlemmene peker paa den med navnet sitt. Endres
 * navnet, maa de peke et nytt sted, ellers mister de medlemskapet sitt.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

/**
 * Kolonnene migrasjon 032 la til.
 *
 * De kom i en egen migrasjon, og en migrasjon kan staa ukjort. Skrev vi til
 * dem uansett, svarte basen «Unknown column 'merke'» og HELE lagringen falt —
 * prisen ble ikke lagret heller. Leste vi fra dem, kom det tomt tilbake, og
 * kortet ute paa nettsiden mistet bade bilde og tekst.
 *
 * Derfor spor vi basen for vi rorer dem. Mangler de, virker skjermen som for:
 * pris, timer og binding lagres, og admin sier fra at det staar en oppdatering
 * igjen.
 */
const TEKSTKOLONNER = ['merke', 'undertekst', 'beskrivelse', 'punkter', 'passer_for', 'bilde', 'fremhevet'];

$harTekst = static function (): bool {
    static $svar = null;
    if ($svar === null) {
        $svar = DB::harKolonne('membership_plans', 'beskrivelse');
    }
    return $svar;
};

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle('SELECT * FROM membership_plans ORDER BY sortering, navn');

    // Hvor mange som staar paa hver plan. Det avgjor om den kan slettes,
    // og det er verdt aa se for man endrer prisen.
    $brukt = [];
    foreach (DB::alle(
        "SELECT medlemskap_type AS plan, COUNT(*) AS antall
           FROM members
          WHERE medlemskap_type IS NOT NULL AND anonymisert_at IS NULL
       GROUP BY medlemskap_type"
    ) as $r) {
        $brukt[(string) $r['plan']] = (int) $r['antall'];
    }

    Svar::json([
        // Sier fra til admin at teksten ikke kan lagres for migrasjonen er
        // kjort. Uten dette ser skjermen komplett ut, og feilen dukker
        // forst opp naar noen trykker Lagre.
        'tekstMulig' => $harTekst(),
        'planer' => array_map(static fn($p) => [
            'navn'      => (string) $p['navn'],
            'pris'      => (int) $p['pris_ore'] / 100,
            'prisTekst' => Booking::kroner((int) $p['pris_ore']),
            'intervall' => (string) $p['intervall'],
            'timer'     => $p['timer'] !== null ? (int) $p['timer'] : null,
            'binding'   => (int) $p['binding_mnd'],
            'engangs'   => (bool) $p['engangs'],
            'sortering' => (int) $p['sortering'],
            'aktiv'     => (bool) $p['aktiv'],
            'medlemmer' => $brukt[(string) $p['navn']] ?? 0,
            // Teksten kunden leser paa kortet. Uten den kunne verkstedet
            // endre prisen, men ikke ett ord om hva de faar for den.
            'merke'       => (string) ($p['merke'] ?? ''),
            'undertekst'  => (string) ($p['undertekst'] ?? ''),
            'beskrivelse' => (string) ($p['beskrivelse'] ?? ''),
            'punkter'     => (string) ($p['punkter'] ?? ''),
            'passerFor'   => (string) ($p['passer_for'] ?? ''),
            'bilde'       => (string) ($p['bilde'] ?? ''),
            'fremhevet'   => !empty($p['fremhevet']),
        ], $rader),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$kropp    = Foresporsel::kropp();
$handling = Foresporsel::tekst('handling', 'lagre');

/** Hvor mange som staar paa denne planen akkurat naa. */
$brukesAv = static function (string $navn): int {
    return (int) DB::verdi(
        "SELECT (SELECT COUNT(*) FROM members
                  WHERE medlemskap_type = :a AND anonymisert_at IS NULL)
              + (SELECT COUNT(*) FROM subscriptions
                  WHERE plan = :b AND status IN ('venter','aktiv'))",
        ['a' => $navn, 'b' => $navn]
    );
};

if ($handling === 'lagre') {
    $navn    = mb_substr(trim((string) ($kropp['navn'] ?? '')), 0, 64);
    $foer    = mb_substr(trim((string) ($kropp['foer'] ?? '')), 0, 64);
    $pris    = max(0, (int) round((float) ($kropp['pris'] ?? 0) * 100));
    $timerRa = trim((string) ($kropp['timer'] ?? ''));
    $timer   = $timerRa === '' ? null : max(0, (int) $timerRa);
    $binding = max(0, (int) ($kropp['binding'] ?? 0));
    $engangs = !empty($kropp['engangs']) ? 1 : 0;
    $aktiv   = array_key_exists('aktiv', $kropp) ? (!empty($kropp['aktiv']) ? 1 : 0) : 1;

    if ($navn === '') {
        Svar::feil('Medlemskapet må ha et navn.');
    }

    // Ett kort kan vaere fremhevet — det morke, midt i rekka. Er to
    // fremhevet, er ingen av dem det, saa den settes bare paa denne og
    // tas av de andre lenger nede.
    $fremhevet = !empty($kropp['fremhevet']) ? 1 : 0;

    $felter = [
        'pris_ore'    => $pris,
        'timer'       => $timer,
        'binding_mnd' => $binding,
        'engangs'     => $engangs,
        'aktiv'       => $aktiv,
        // Maa medlemmet ha fast trekk i Vipps, eller kan hen gjore opp selv?
        // Kolonna kom med migrasjon 081; er den ikke kjort, tas feltet ut
        // lenger nede saa resten av lagringen gaar gjennom.
        //
        // Skrives bare naar feltet faktisk er med i kallet. Sto det her
        // ubetinget, slo planskjemaet — som ikke kjenner feltet — det av
        // igjen hver gang planen ble lagret. AArsmedlemskapet krever fast
        // trekk, og det ville falt bort i det noen rettet en skrivefeil.
        'krever_fast_trekk' => !empty($kropp['fastTrekk']) ? 1 : 0,
        'sortering'   => (int) ($kropp['sortering'] ?? 0),
        'merke'       => mb_substr(trim((string) ($kropp['merke'] ?? '')), 0, 40),
        'undertekst'  => mb_substr(trim((string) ($kropp['undertekst'] ?? '')), 0, 120),
        'beskrivelse' => mb_substr(trim((string) ($kropp['beskrivelse'] ?? '')), 0, 400),
        'punkter'     => implode("\n", Medlemskap::punkter((string) ($kropp['punkter'] ?? ''))),
        'passer_for'  => mb_substr(trim((string) ($kropp['passerFor'] ?? '')), 0, 200),
        'bilde'       => mb_substr(trim((string) ($kropp['bilde'] ?? '')), 0, 200),
        'fremhevet'   => $fremhevet,
    ];

    if (!array_key_exists('fastTrekk', $kropp)
        || !DB::harKolonne('membership_plans', 'krever_fast_trekk')) {
        unset($felter['krever_fast_trekk']);
    }

    // Staar migrasjonen ukjort, lagrer vi det vi kan i stedet for aa la alt
    // falle. Prisen skal kunne endres selv om teksten maa vente.
    if (!$harTekst()) {
        foreach (TEKSTKOLONNER as $k) {
            unset($felter[$k]);
        }
    }

    // Ny plan.
    if ($foer === '') {
        if (DB::en('SELECT navn FROM membership_plans WHERE navn = :n', ['n' => $navn]) !== null) {
            Svar::feil('Det finnes alt et medlemskap som heter «' . $navn . '».');
        }
        DB::settInn('membership_plans', $felter + ['navn' => $navn, 'intervall' => 'maaned']);
        if ($fremhevet === 1 && $harTekst()) {
            DB::kjor('UPDATE membership_plans SET fremhevet = 0 WHERE navn <> :n', ['n' => $navn]);
        }
        revider('plan_opprettet', 'plan', null, ['navn' => $navn]);
        Svar::ok([
            'tekstMulig' => $harTekst(),
            'beskjed' => '«' . $navn . '» er lagt ut.'
                . ($harTekst() ? '' : ' Teksten ble ikke lagret: kjør databaseoppdateringene under Oversikt → Vedlikehold først.'),
        ]);
    }

    if (DB::en('SELECT navn FROM membership_plans WHERE navn = :n', ['n' => $foer]) === null) {
        Svar::feil('Fant ikke medlemskapet.', 404);
    }

    // Navnebytte.
    //
    // Medlemmene peker paa planen med navnet sitt, ikke med et nummer. Byttes
    // navnet uten at de foelger med, staar de igjen med et medlemskap som
    // ikke finnes — timegrensa forsvinner og Min side sier «Ingen».
    if ($navn !== $foer) {
        if (DB::en('SELECT navn FROM membership_plans WHERE navn = :n', ['n' => $navn]) !== null) {
            Svar::feil('Det finnes alt et medlemskap som heter «' . $navn . '».');
        }
        DB::kjor('UPDATE membership_plans SET navn = :ny WHERE navn = :gammel',
                 ['ny' => $navn, 'gammel' => $foer]);
        DB::kjor('UPDATE members SET medlemskap_type = :ny WHERE medlemskap_type = :gammel',
                 ['ny' => $navn, 'gammel' => $foer]);
        DB::kjor('UPDATE subscriptions SET plan = :ny WHERE plan = :gammel',
                 ['ny' => $navn, 'gammel' => $foer]);
    }

    DB::oppdater('membership_plans', $felter, ['navn' => $navn]);
    if ($fremhevet === 1 && $harTekst()) {
        DB::kjor('UPDATE membership_plans SET fremhevet = 0 WHERE navn <> :n', ['n' => $navn]);
    }
    revider('plan_endret', 'plan', null, ['navn' => $navn, 'foer' => $foer]);

    Svar::ok([
        'tekstMulig' => $harTekst(),
        'beskjed' => ($navn !== $foer
            ? 'Medlemskapet heter nå «' . $navn . '», og medlemmene følger med.'
            : 'Endringene er lagret.')
            . ($harTekst() ? '' : ' Teksten ble ikke lagret: kjør databaseoppdateringene under Oversikt → Vedlikehold først.'),
    ]);
}

if ($handling === 'slett') {
    $navn = mb_substr(trim((string) ($kropp['navn'] ?? '')), 0, 64);
    if (DB::en('SELECT navn FROM membership_plans WHERE navn = :n', ['n' => $navn]) === null) {
        Svar::feil('Fant ikke medlemskapet.', 404);
    }

    // Staar noen paa planen, slettes den ikke — da ville de mistet
    // timegrensa og prisen sin. Den tas ut av salg i stedet, som er det
    // sletting egentlig handler om her.
    $antall = $brukesAv($navn);
    if ($antall > 0) {
        DB::oppdater('membership_plans', ['aktiv' => 0], ['navn' => $navn]);
        revider('plan_tatt_ned', 'plan', null, ['navn' => $navn, 'medlemmer' => $antall]);
        Svar::ok([
            'slettet' => false,
            'beskjed' => $antall === 1
                ? 'Ett medlem står på dette medlemskapet, så det er tatt ut av salg i stedet for slettet. De beholder prisen og timene sine.'
                : $antall . ' medlemmer står på dette medlemskapet, så det er tatt ut av salg i stedet for slettet. De beholder prisen og timene sine.',
        ]);
    }

    DB::kjor('DELETE FROM membership_plans WHERE navn = :n', ['n' => $navn]);
    revider('plan_slettet', 'plan', null, ['navn' => $navn]);
    Svar::ok(['slettet' => true, 'beskjed' => '«' . $navn . '» er slettet.']);
}

Svar::feil('Ukjent handling.');
