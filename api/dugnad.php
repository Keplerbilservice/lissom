<?php
/**
 * Dugnad, sett fra medlemmet (Min side).
 *
 *   GET                       status: bryteren, dugnaden som gaar naa, ferdige denne maaneden
 *   POST handling=sporr       be om dugnad (tekst = hva medlemmet vil gjoere)
 *   POST handling=inn         stemple inn dugnad (krever godkjent foresporsel)
 *   POST handling=ut          stemple ut — tida gaar til godkjenning
 *   POST handling=trekk       trekke en foresporsel som ikke er svart paa
 *
 * Krever aktivt medlemskap. Tida trekker ikke fra medlemskapet; den legges
 * til naar verkstedet har godkjent jobben. Se app/lib/dugnad.php.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem = krev_aktivt_medlem();
$id = (int) $medlem['id'];

if (!Dugnad::klar()) {
    Svar::json(['paa' => false, 'klar' => false]);
}
Dugnad::lukkGlemte();
// Per medlem: står dugnad på «Utvalgte», ser bare de valgte den (og den som
// har fått en jobb av verkstedet). Migrasjon 214.
$paa = Dugnad::synligFor($medlem);

if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();
    Rate::sjekk('dugnad', maks: 30, vindu: 600, nokkel: (string) $id);
    if (!$paa) {
        Svar::feil('Dugnad er ikke åpnet for medlemmene nå.');
    }
    $handling = Foresporsel::tekst('handling');
    $aktiv = Dugnad::aktiv($id);
    $navn = trim((string) ($medlem['navn'] ?? '')) ?: 'Et medlem';

    if ($handling === 'sporr') {
        if ($aktiv !== null) {
            Svar::feil('Du har alt en dugnad som ikke er avsluttet.');
        }
        $tekst = trim(mb_substr(Foresporsel::tekst('tekst'), 0, 500));
        if (mb_strlen($tekst) < 5) {
            Svar::feil('Skriv kort hva du vil gjøre — for eksempel «vaske gulvet i glasurrommet».');
        }
        $nyId = DB::settInn('dugnad', ['member_id' => $id, 'tekst' => $tekst]);
        revider('dugnad_spurt', 'member', $id, ['dugnad' => $nyId]);
        // Verkstedet faar beskjed. Godkjenningen skjer under Ubesvarte.
        try {
            Varsel::malTilAdmin('intern_dugnad_sporsmal', ['navn' => $navn, 'tekst' => $tekst], 'dugnad', $nyId);
        } catch (Throwable $e) {
            logg_feil('Fikk ikke sendt dugnadsforespørsel til verkstedet', $e);
        }
        Svar::ok(['beskjed' => 'Forespørselen er sendt. Du får e-post når verkstedet har svart.']);
    }

    if ($handling === 'trekk') {
        if ($aktiv === null || (string) $aktiv['status'] !== 'venter') {
            Svar::feil('Det er ingen forespørsel å trekke.');
        }
        DB::kjor('DELETE FROM dugnad WHERE id = :i', ['i' => (int) $aktiv['id']]);
        revider('dugnad_trukket', 'member', $id, ['dugnad' => (int) $aktiv['id']]);
        Svar::ok(['beskjed' => 'Forespørselen er trukket.']);
    }

    if ($handling === 'inn') {
        if ($aktiv === null || (string) $aktiv['status'] !== 'godkjent') {
            Svar::feil('Dugnaden er ikke godkjent ennå.');
        }
        DB::oppdater('dugnad', ['status' => 'pagar', 'inn_tid' => gmdate('Y-m-d H:i:s')], ['id' => (int) $aktiv['id']]);
        revider('dugnad_inn', 'member', $id, ['dugnad' => (int) $aktiv['id']]);
        Svar::ok(['beskjed' => 'Du er stemplet inn som dugnad. Husk å stemple ut når du er ferdig.']);
    }

    if ($handling === 'ut') {
        if ($aktiv === null || (string) $aktiv['status'] !== 'pagar') {
            Svar::feil('Du er ikke stemplet inn som dugnad.');
        }
        $min = max(1, (int) ((time() - strtotime((string) $aktiv['inn_tid'] . ' UTC')) / 60));
        DB::oppdater('dugnad', [
            'status'   => 'til_godkjenning',
            'ut_tid'   => gmdate('Y-m-d H:i:s'),
            'minutter' => $min,
        ], ['id' => (int) $aktiv['id']]);
        revider('dugnad_ut', 'member', $id, ['dugnad' => (int) $aktiv['id'], 'minutter' => $min]);
        try {
            Varsel::malTilAdmin('intern_dugnad_ferdig', [
                'navn'     => $navn,
                'tekst'    => (string) $aktiv['tekst'],
                'varighet' => Dugnad::varighet($min),
                'forslag'  => Dugnad::kvarterTimer(Dugnad::kvarter($min)),
            ], 'dugnad', (int) $aktiv['id']);
        } catch (Throwable $e) {
            logg_feil('Fikk ikke sendt dugnadstid til verkstedet', $e);
        }
        Svar::ok(['beskjed' => 'Takk! Du jobbet ' . Dugnad::varighet($min) . '. Tida legges til timene dine når verkstedet har godkjent jobben.']);
    }

    Svar::feil('Ukjent handling.');
}

// -------------------------------------------------------------------- lesing
$aktiv = Dugnad::aktiv($id);
Svar::json([
    'klar'   => true,
    'paa'    => $paa,
    'aktiv'  => $aktiv === null ? null : Dugnad::ut($aktiv),
    'ferdige' => array_map(static fn(array $d): array => [
        'id'    => (int) $d['id'],
        'tekst' => (string) $d['tekst'],
        'dag'   => Booking::norskDatoKort((string) $d['godkjent_at']),
        'timer' => Dugnad::kvarterTimer((int) $d['godkjent_minutter']),
    ], Dugnad::ferdigeDenneManeden($id)),
    'tilgodeTimer' => Stempling::timer(Dugnad::minutterTilgode($medlem)),
    'overforing'   => Dugnad::overforing(),
]);
