<?php
/**
 * Butikkjop.
 *
 * Nettleseren sender hvilke varer og hvor mange — aldri hva de koster.
 * Summen regnes ut fra prisene i databasen, ellers kunne hvem som helst
 * endret belopet i utviklerverktoyet for betaling.
 *
 * Varene hentes i verkstedet. Ingen forsendelse.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
Rate::sjekk('ordre', maks: 10, vindu: 600);

$linjer = Foresporsel::kropp()['linjer'] ?? null;
if (!is_array($linjer) || $linjer === []) {
    Svar::feil('Handlekurven er tom.');
}
if (count($linjer) > 50) {
    Svar::feil('For mange varer i én bestilling.');
}

$medlem = Sesjon::medlem();
$navn    = mb_substr(Foresporsel::tekst('navn'), 0, 191);
$epost   = mb_substr(Foresporsel::tekst('epost'), 0, 191);
$telefon = normaliser_telefon(Foresporsel::tekst('telefon'));

if ($medlem !== null) {
    $navn    = $navn !== '' ? $navn : (string) $medlem['navn'];
    $epost   = $epost !== '' ? $epost : (string) ($medlem['epost'] ?? '');
    $telefon = $telefon !== '' ? $telefon : normaliser_telefon((string) ($medlem['telefon'] ?? ''));
}

if ($navn === '') {
    Svar::feil('Vi trenger navnet ditt.');
}
if ($epost === '' || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
    Svar::feil('Vi trenger en gyldig e-postadresse — kvitteringen sendes dit.');
}

// --- Sett sammen ordren fra databasens priser ----------------------------
$rader = [];
$sum = 0;

foreach ($linjer as $l) {
    $id = (int) ($l['id'] ?? 0);
    $antall = max(1, min(50, (int) ($l['antall'] ?? 1)));

    $vare = DB::en(
        "SELECT id, tittel, pris_ore, lager, kun_medlemmer
           FROM products WHERE id = :i AND status = 'publisert'",
        ['i' => $id]
    );
    if ($vare === null) {
        Svar::feil('En av varene finnes ikke lenger. Last siden på nytt.', 409);
    }
    // «Kun for medlemmer» betyr godkjent medlem — ikke bare innlogget.
    if ((int) $vare['kun_medlemmer'] === 1 && ($medlem === null || !er_aktivt_medlem($medlem))) {
        Svar::feil('En av varene er kun for medlemmer.', 403);
    }
    if ($vare['lager'] !== null && (int) $vare['lager'] < $antall) {
        Svar::feil('Vi har ikke nok igjen av «' . $vare['tittel'] . '».', 409);
    }

    $sum += (int) $vare['pris_ore'] * $antall;
    $rader[] = ['vare' => $vare, 'antall' => $antall];
}

if ($sum <= 0) {
    Svar::feil('Bestillingen har ingen sum.');
}

// --- Levering ------------------------------------------------------------
//
// Kassa viste «Inkludert frakt kr. 89,-» og la belopet til i totalen paa
// skjermen. Det gikk aldri hit: kroppen bar bare varelinjene, og summen ble
// regnet av varene alene. Bestilte noen med sending, betalte verkstedet
// portoen selv — og adressen kom ingen steder.
//
// Prisen hentes fra basen, ikke fra nettleseren. Det er den samme regelen
// som ellers i api/ordre.php: kunden kan si *hva* hun vil ha, aldri hva det
// koster. Se api/betal.php for det ene stedet der det motsatte gjelder, og
// hvorfor.
$levering = Foresporsel::tekst('levering') === 'pakke' ? 'pakke' : 'hent';
$fraktOre = 0;
$mottaker = null;
$adresse = $postnr = $poststed = null;

if ($levering === 'pakke') {
    $fraktOre = (int) (DB::harTabell('innstillinger')
        ? (DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => 'frakt_ore']) ?? 0)
        : 0);

    $mottaker = mb_substr(trim(Foresporsel::tekst('mottaker')), 0, 191);
    $adresse  = mb_substr(trim(Foresporsel::tekst('adresse')), 0, 191);
    $postnr   = preg_replace('/\D+/', '', Foresporsel::tekst('postnr')) ?? '';
    $poststed = mb_substr(trim(Foresporsel::tekst('poststed')), 0, 100);

    // En pakke uten adresse er ingen pakke. Bedre aa stoppe her enn aa ta
    // imot pengene og ikke vite hvor varene skal.
    //
    // Navnet ogsaa: en adresse uten mottaker er ikke noe Posten kan levere
    // paa. Kunden kan skrive et annet navn enn sitt eget — butikken har
    // gaveinnpakning, og da er det gaven som skal fram, ikke kjoperen.
    // Staar mottakeren tom, er det kjoeperen som skal ha pakken. Navnet hens
    // er alt krevd lenger oppe, saa her kan det ikke bli staaende tomt.
    if ($mottaker === '') {
        $mottaker = $navn;
    }
    if ($adresse === '') {
        Svar::feil('Vi trenger gateadressen pakken skal til.');
    }
    if (strlen($postnr) !== 4) {
        Svar::feil('Postnummeret skal ha fire siffer.');
    }
    if ($poststed === '') {
        Svar::feil('Vi trenger poststedet.');
    }
    $sum += $fraktOre;
}

// --- Gavekort ------------------------------------------------------------
//
// Feltet i kassa var ikke koblet til noe, og saldoen ble aldri trukket ned.
// Kortet kunne kjopes, men ikke brukes.
//
// Beloepet regnes ut her og legges paa betalingen. Selve trekket skjer forst
// naar betalingen er bekreftet — en handlekurv som blir forlatt i Vipps skal
// ikke spise av saldoen.
$gavekortId = null;
$gavekortOre = 0;
$kode = trim(Foresporsel::tekst('gavekort'));
if ($kode !== '') {
    $kort = Booking::finnGavekort($kode);
    if ($kort === null) {
        Svar::feil('Fant ikke gavekortet, eller det er brukt opp.');
    }
    $gavekortId = $kort['id'];
    $gavekortOre = min($kort['saldo_ore'], $sum);
}
$aBetale = $sum - $gavekortOre;

$referanse = Vipps::nyReferanse('LIS');
$ordrenr = 'B-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

// Ordren lagres for vi sender kunden til Vipps, slik at webhooken alltid har
// noe aa slaa opp i.
// Gaveinnpakning og hilsen. Kolonnene kommer med migrasjon 041; uten den
// skal en bestilling fortsatt gaa gjennom, bare uten gaveopplysningene.
$gavefelt = [];
if (DB::harKolonne('orders', 'gave')) {
    $gavefelt = [
        // JSON sender true, et skjema sender «1». Begge skal bety det samme.
        'gave'        => in_array(Foresporsel::kropp()['gave'] ?? Foresporsel::tekst('gave'), [true, 1, '1', 'true'], true) ? 1 : 0,
        'gave_hilsen' => mb_substr(trim(Foresporsel::tekst('hilsen')), 0, 300) ?: null,
    ];
}

// Leveringsfeltene kom med migrasjon 095. Er den ikke kjort, skal en
// bestilling fortsatt gaa gjennom — bare uten dem, som for.
$leveringsfelt = DB::harKolonne('orders', 'levering')
    ? ['levering' => $levering, 'frakt_ore' => $fraktOre,
       'adresse' => $adresse ?: null, 'postnr' => $postnr ?: null, 'poststed' => $poststed ?: null]
    : [];
// Mottakeren kom med migrasjon 098. Uten den lagres resten som for.
if (DB::harKolonne('orders', 'mottaker')) {
    $leveringsfelt['mottaker'] = ($mottaker ?? '') !== '' ? $mottaker : null;
}

$opprettet = DB::iTransaksjon(static function () use ($rader, $sum, $aBetale, $gavekortId, $gavekortOre, $navn, $epost, $telefon, $medlem, $referanse, $ordrenr, $gavefelt, $leveringsfelt, $fraktOre): array {
    $betalingsfelt = [
        'vipps_reference' => $referanse,
        'type'            => 'epayment',
        'formal'          => 'ordre',
        'member_id'       => $medlem['id'] ?? null,
        // Beloepet paa betalingen er det kunden faktisk skal betale. Summen
        // paa ordren er hele kjopet — differansen er gavekortet.
        'belop_ore'       => $aBetale,
        'status'          => 'opprettet',
        'idempotency_key' => Vipps::uuid(),
    ];
    // Kolonnene kommer med migrasjon 040. Uten den skal en bestilling uten
    // gavekort fortsatt gaa gjennom.
    if (DB::harKolonne('payments', 'gavekort_id')) {
        $betalingsfelt['gavekort_id'] = $gavekortId;
        $betalingsfelt['gavekort_ore'] = $gavekortOre;
    }
    $paymentId = DB::settInn('payments', $betalingsfelt);

    $ordreId = DB::settInn('orders', [
        'ordrenr'       => $ordrenr,
        'member_id'     => $medlem['id'] ?? null,
        'kunde_navn'    => $navn,
        'kunde_epost'   => $epost,
        'kunde_telefon' => $telefon !== '' ? $telefon : null,
        'sum_ore'       => $sum,
        'status'        => 'ny',
        'payment_id'    => $paymentId,
    ] + $gavefelt + $leveringsfelt);

    // Frakten som en egen linje. Da stemmer linjene med summen paa ordren,
    // og kvitteringen viser hva portoen kostet framfor aa gjemme den i
    // varene. product_id er null — frakt er ingen vare i butikken.
    if ($fraktOre > 0) {
        DB::settInn('order_lines', [
            'order_id'   => $ordreId,
            'product_id' => null,
            'tittel'     => 'Frakt — sendt som pakke',
            'antall'     => 1,
            'pris_ore'   => $fraktOre,
        ]);
    }

    foreach ($rader as $r) {
        DB::settInn('order_lines', [
            'order_id'   => $ordreId,
            'product_id' => $r['vare']['id'],
            // Tittelen kopieres inn: varen kan endre navn senere, men
            // kvitteringen skal vise hva kunden faktisk kjopte.
            'tittel'     => $r['vare']['tittel'],
            'antall'     => $r['antall'],
            'pris_ore'   => (int) $r['vare']['pris_ore'],
        ]);
    }

    return ['ordreId' => $ordreId, 'paymentId' => $paymentId];
});

// Dekker gavekortet hele kjopet, er det ingenting aa betale. Aa sende noen
// til Vipps for null kroner ville vaert en omvei til en feilmelding.
// Ingenting igjen aa betale — eller mindre enn Vipps godtar. Under én krone
// finnes det ingen vei gjennom Vipps, og aa runde opp ville vaert aa kreve
// mer enn kunden skylder. Da er resten dekket.
if ($aBetale < Vipps::MINSTE_BELOP_ORE) {
    Booking::markerBetalt($referanse);
    revider('ordre_opprettet', 'order', $opprettet['ordreId'], ['sum_ore' => $sum, 'gavekort_ore' => $gavekortOre]);
    Svar::ok([
        'url'        => null,
        'referanse'  => $referanse,
        'ordrenr'    => $ordrenr,
        'sum'        => Booking::kroner($sum),
        'gavekort'   => Booking::kroner($gavekortOre),
        'aBetale'    => Booking::kroner(0),
        'ferdig'     => true,
        'beskjed'    => 'Gavekortet dekket hele bestillingen.',
    ]);
}

try {
    $betaling = Vipps::opprettBetaling(
        $referanse,
        $aBetale,
        'Lissom — bestilling ' . $ordrenr,
        Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
        $telefon
    );
} catch (Throwable $e) {
    DB::oppdater('payments', ['status' => 'feilet'], ['id' => $opprettet['paymentId']]);
    DB::oppdater('orders', ['status' => 'kansellert'], ['id' => $opprettet['ordreId']]);
    logg_feil('Kunne ikke starte betaling for ordre ' . $ordrenr, $e);
    Svar::feil('Fikk ikke startet betalingen. Prøv igjen om litt.', 502);
}

DB::oppdater('payments', ['status' => 'venter'], ['id' => $opprettet['paymentId']]);
revider('ordre_opprettet', 'order', $opprettet['ordreId'], ['sum_ore' => $sum]);

Svar::ok([
    'url'       => $betaling['url'],
    'referanse' => $referanse,
    'ordrenr'   => $ordrenr,
    'sum'       => Booking::kroner($sum),
    'gavekort'  => Booking::kroner($gavekortOre),
    'aBetale'   => Booking::kroner($aBetale),
]);
