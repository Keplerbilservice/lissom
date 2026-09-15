<?php
/**
 * «Ta med barn» — tillegg paa medlemskapet, sett fra medlemmet (Min side).
 *
 *   GET                      bryteren, prisen, det som er aktivt, hva som kan kjoepes
 *   POST handling=kjop       maaned, barnNavn, barnAlder, vilkaar=ja → Vipps-adresse
 *
 * Kjoepet er en vanlig ordre med én linje og en Vipps-betaling, som i
 * internbutikken. Tillegget blir aktivt naar Vipps sier at pengene er i havn
 * (Booking::markerBetalt → Tillegg::aktiverForOrdre). Se app/lib/tillegg.php.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem = krev_aktivt_medlem();
$id = (int) $medlem['id'];

if (!Tillegg::klar()) {
    Svar::json(['klar' => false, 'paa' => false]);
}
$paa = Tillegg::paa();
$prisOre = Tillegg::prisOre();

if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();
    Rate::sjekk('tillegg', maks: 10, vindu: 600, nokkel: (string) $id);
    if (!$paa) {
        Svar::feil('«Ta med barn» er ikke åpnet for medlemmene nå.');
    }
    if (Foresporsel::tekst('handling') !== 'kjop') {
        Svar::feil('Ukjent handling.');
    }
    if ($prisOre < Vipps::MINSTE_BELOP_ORE) {
        Svar::feil('Prisen er ikke satt. Si fra til verkstedet.');
    }
    $maaned = Foresporsel::tekst('maaned');
    if (!in_array($maaned, Tillegg::kjopbare(), true)) {
        Svar::feil('Den måneden kan ikke kjøpes nå.');
    }
    if (Tillegg::aktivt($id, $maaned) !== null) {
        Svar::feil('Du har alt «Ta med barn» for ' . Tillegg::maanedNavn($maaned) . '.');
    }
    $barnNavn = trim(mb_substr(Foresporsel::tekst('barnNavn'), 0, 120));
    $barnAlder = Foresporsel::heltall('barnAlder', -1);
    if (mb_strlen($barnNavn) < 2) {
        Svar::feil('Skriv barnets navn.');
    }
    if ($barnAlder < 0 || $barnAlder > Tillegg::MAKS_ALDER) {
        Svar::feil('Tillegget gjelder barn opptil ' . Tillegg::MAKS_ALDER . ' år.');
    }
    if (Foresporsel::tekst('vilkaar') !== 'ja') {
        Svar::feil('Du må godta vilkårene for å kjøpe.');
    }

    $navn = trim((string) ($medlem['navn'] ?? '')) ?: 'Medlem';
    $epost = trim((string) ($medlem['epost'] ?? ''));
    $telefon = trim((string) ($medlem['telefon'] ?? ''));
    $referanse = Vipps::nyReferanse('BRN');
    $ordrenr = 'T-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $tittel = 'Ta med barn — ' . Tillegg::maanedNavn($maaned) . ' (' . $barnNavn . ', ' . $barnAlder . ' år)';

    $opprettet = DB::iTransaksjon(static function () use ($id, $navn, $epost, $telefon, $referanse, $ordrenr, $prisOre, $tittel, $maaned, $barnNavn, $barnAlder): array {
        $betalingId = DB::settInn('payments', [
            'vipps_reference' => $referanse,
            'type'            => 'epayment',
            'formal'          => 'ordre',
            'member_id'       => $id,
            'belop_ore'       => $prisOre,
            'status'          => 'opprettet',
            'idempotency_key' => Vipps::uuid(),
        ]);
        $ordreId = DB::settInn('orders', [
            'ordrenr'       => $ordrenr,
            'member_id'     => $id,
            'kunde_navn'    => $navn,
            'kunde_epost'   => $epost !== '' ? $epost : null,
            'kunde_telefon' => $telefon !== '' ? $telefon : null,
            'sum_ore'       => $prisOre,
            'status'        => 'ny',
            'payment_id'    => $betalingId,
        ]);
        DB::settInn('order_lines', [
            'order_id'   => $ordreId,
            'product_id' => null,
            'tittel'     => $tittel,
            'antall'     => 1,
            'pris_ore'   => $prisOre,
        ]);
        // Et forlatt kjoep for samme maaned skal ikke bli staaende som «venter».
        DB::kjor("UPDATE medlem_tillegg SET status = 'avbrutt' WHERE member_id = :m AND maaned = :mnd AND status = 'venter'", ['m' => $id, 'mnd' => $maaned]);
        $tilleggId = DB::settInn('medlem_tillegg', [
            'member_id'            => $id,
            'type'                 => 'barn',
            'maaned'               => $maaned,
            'barn_navn'            => $barnNavn,
            'barn_alder'           => $barnAlder,
            'pris_ore'             => $prisOre,
            'order_id'             => $ordreId,
            'status'               => 'venter',
            'vilkaar_akseptert_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return ['ordreId' => $ordreId, 'betalingId' => $betalingId, 'tilleggId' => $tilleggId];
    });

    try {
        $betaling = Vipps::opprettBetaling(
            $referanse,
            $prisOre,
            'Lissom — ta med barn, ' . Tillegg::maanedNavn($maaned),
            Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse) . '&til=' . rawurlencode('/min-side'),
            $telefon !== '' ? $telefon : null
        );
    } catch (Throwable $e) {
        DB::oppdater('payments', ['status' => 'feilet'], ['id' => $opprettet['betalingId']]);
        DB::oppdater('orders', ['status' => 'kansellert'], ['id' => $opprettet['ordreId']]);
        Tillegg::avbrytForOrdre($opprettet['ordreId']);
        logg_feil('Kunne ikke starte betaling for tillegg ' . $ordrenr, $e);
        Svar::feil('Fikk ikke startet betalingen. Prøv igjen om litt.', 502);
    }
    DB::oppdater('payments', ['status' => 'venter'], ['id' => $opprettet['betalingId']]);
    revider('tillegg_barn_kjop', 'member', $id, ['tillegg' => $opprettet['tilleggId'], 'maaned' => $maaned, 'ordre' => $opprettet['ordreId']]);
    Svar::ok(['url' => $betaling['url'], 'ordrenr' => $ordrenr]);
}

// -------------------------------------------------------------------- lesing
$aktive = [];
foreach (Tillegg::kjopbare() as $mnd) {
    $a = Tillegg::aktivt($id, $mnd);
    if ($a !== null) {
        $aktive[] = Tillegg::ut($a);
    }
}
$naa = Tillegg::aktivt($id);
Svar::json([
    'klar'      => true,
    'paa'       => $paa,
    'pris'      => Booking::kroner($prisOre),
    'prisOre'   => $prisOre,
    'maksAlder' => Tillegg::MAKS_ALDER,
    'aktivt'    => $naa === null ? null : Tillegg::ut($naa),
    'aktive'    => $aktive,
    // Maanedene som kan kjoepes naa, uten dem som alt er aktive.
    'kanKjope'  => array_values(array_map(
        static fn(string $mnd): array => ['maaned' => $mnd, 'navn' => Tillegg::maanedNavn($mnd)],
        array_filter(Tillegg::kjopbare(), static fn(string $mnd): bool => Tillegg::aktivt($id, $mnd) === null)
    )),
]);
