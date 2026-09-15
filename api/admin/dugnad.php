<?php
/**
 * Dugnad, sett fra verkstedet (Admin → Ubesvarte → Dugnad).
 *
 *   GET                                   alt som venter, og de siste ferdige
 *   POST handling=godkjenn  id  [svar]    medlemmet kan stemple inn dugnad
 *   POST handling=avslaa    id  [svar]    nei — medlemmet faar beskjed
 *   POST handling=godkjenn_tid id timer   tida legges til (rundes til kvarter)
 *   POST handling=avvis_tid    id  [svar] tida legges ikke til
 *
 * Eieren, 15. september 2026: «Medlemmene maa foresporre og faa godkjenning
 * av Monica foer de faar stemplet inn» — og tida «skal godkjennes» etterpaa.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$jeg = krev_admin();

if (!Dugnad::klar()) {
    Svar::feil('Migrasjon 189 er ikke kjørt. Kjør oppdateringen først, så kommer dugnadene fram her.');
}
Dugnad::lukkGlemte();

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle(
        "SELECT d.*, m.navn, m.epost
           FROM dugnad d JOIN members m ON m.id = d.member_id
          WHERE d.status IN ('venter','godkjent','pagar','til_godkjenning')
             OR d.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)
       ORDER BY FIELD(d.status, 'til_godkjenning', 'venter', 'pagar', 'godkjent', 'ferdig', 'avvist', 'avslatt'), d.id DESC
          LIMIT 200"
    );
    Svar::json([
        'paa'        => Dugnad::paa(),
        'overforing' => Dugnad::overforing(),
        'dugnader'   => array_map(static function (array $d): array {
            $ut = Dugnad::ut($d);
            $ut['medlemId'] = (int) $d['member_id'];
            $ut['navn']     = (string) $d['navn'];
            $ut['epost']    = (string) ($d['epost'] ?? '');
            return $ut;
        }, $rader),
        'venter' => (int) DB::verdi("SELECT COUNT(*) FROM dugnad WHERE status IN ('venter','til_godkjenning')"),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');
$id = Foresporsel::heltall('id');
$d = DB::en('SELECT d.*, m.navn, m.epost FROM dugnad d JOIN members m ON m.id = d.member_id WHERE d.id = :i', ['i' => $id]);
if ($d === null) {
    Svar::feil('Fant ikke dugnaden.', 404);
}
$medlemId = (int) $d['member_id'];
$navn = trim((string) $d['navn']) ?: 'Medlemmet';
$fornavn = explode(' ', trim((string) $d['navn']))[0] ?: 'Hei';
$epost = trim((string) ($d['epost'] ?? ''));
$svar = trim(mb_substr(Foresporsel::tekst('svar'), 0, 500));
$naa = gmdate('Y-m-d H:i:s');

/** E-post til medlemmet, av en mal. Har det ingen adresse, staar svaret paa Min side likevel. */
$siFra = static function (string $mal, array $felter) use ($epost, $id, $navn, $fornavn, $d, $svar): void {
    if ($epost === '' || !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    try {
        Varsel::mal($mal, ['epost' => $epost, 'navn' => $navn], $felter + [
            'fornavn' => $fornavn,
            'tekst'   => (string) $d['tekst'],
            // Linja staar tom naar verkstedet ikke skrev noe — da blir det
            // ingen tom «Fra verkstedet:» i e-posten.
            'svar'    => $svar !== '' ? "\nFra verkstedet: " . $svar . "\n" : '',
        ], 'dugnad', $id);
    } catch (Throwable $e) {
        logg_feil('Fikk ikke sendt dugnadssvar til medlemmet', $e);
    }
};

if ($handling === 'godkjenn') {
    if ((string) $d['status'] !== 'venter') {
        Svar::feil('Forespørselen er allerede behandlet.');
    }
    DB::oppdater('dugnad', ['status' => 'godkjent', 'svar' => $svar ?: null, 'svart_av' => (int) $jeg['id'], 'svart_at' => $naa], ['id' => $id]);
    revider('dugnad_godkjent', 'member', $medlemId, ['dugnad' => $id]);
    $siFra('dugnad_godkjent', []);
    Svar::ok(['beskjed' => $navn . ' kan nå stemple inn dugnad. ' . ($epost !== '' ? 'E-post er sendt.' : 'Medlemmet har ingen e-post — svaret står på Min side.')]);
}

if ($handling === 'avslaa') {
    if ((string) $d['status'] !== 'venter') {
        Svar::feil('Forespørselen er allerede behandlet.');
    }
    DB::oppdater('dugnad', ['status' => 'avslatt', 'svar' => $svar ?: null, 'svart_av' => (int) $jeg['id'], 'svart_at' => $naa], ['id' => $id]);
    revider('dugnad_avslatt', 'member', $medlemId, ['dugnad' => $id]);
    $siFra('dugnad_avslatt', []);
    Svar::ok(['beskjed' => 'Forespørselen er avslått.' . ($epost !== '' ? ' E-post er sendt.' : '')]);
}

if ($handling === 'godkjenn_tid') {
    if (!in_array((string) $d['status'], ['til_godkjenning', 'pagar'], true)) {
        Svar::feil('Tida er allerede behandlet.');
    }
    // Timer kan skrives som «1,5» eller «1.25». Tom = forslaget (stemplet
    // tid rundet til kvarter). Rundes alltid til kvarter.
    $raa = str_replace(',', '.', Foresporsel::tekst('timer'));
    $min = $raa === '' || !is_numeric($raa)
        ? Dugnad::kvarter((int) ($d['minutter'] ?? 0))
        : Dugnad::kvarter((int) round((float) $raa * 60));
    if ($min <= 0 || $min > 24 * 60) {
        Svar::feil('Skriv et timetall mellom 0,25 og 24.');
    }
    DB::oppdater('dugnad', [
        'status'            => 'ferdig',
        'ut_tid'            => $d['ut_tid'] ?? $naa,
        'minutter'          => $d['minutter'] ?? $min,
        'godkjent_minutter' => $min,
        'godkjent_av'       => (int) $jeg['id'],
        'godkjent_at'       => $naa,
        'svar'              => $svar ?: ($d['svar'] ?? null),
    ], ['id' => $id]);
    revider('dugnad_tid_godkjent', 'member', $medlemId, ['dugnad' => $id, 'minutter' => $min]);
    $timer = Stempling::timer($min);
    $siFra('dugnad_tid_godkjent', ['timer' => $timer]);
    Svar::ok(['beskjed' => $timer . ' timer er lagt til hos ' . $navn . '.' . ($epost !== '' ? ' E-post er sendt.' : '')]);
}

if ($handling === 'avvis_tid') {
    if (!in_array((string) $d['status'], ['til_godkjenning', 'pagar'], true)) {
        Svar::feil('Tida er allerede behandlet.');
    }
    DB::oppdater('dugnad', ['status' => 'avvist', 'ut_tid' => $d['ut_tid'] ?? $naa, 'godkjent_av' => (int) $jeg['id'], 'godkjent_at' => $naa, 'svar' => $svar ?: ($d['svar'] ?? null)], ['id' => $id]);
    revider('dugnad_tid_avvist', 'member', $medlemId, ['dugnad' => $id]);
    $siFra('dugnad_tid_avvist', []);
    Svar::ok(['beskjed' => 'Tida ble ikke lagt til.' . ($epost !== '' ? ' E-post er sendt.' : '')]);
}

Svar::feil('Ukjent handling.');
