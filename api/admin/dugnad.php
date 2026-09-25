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
        // «Gi dugnad» og «Utvalgte» (migrasjon 214). Medlemmene man kan gi
        // en jobb: de med et medlemskap som gaar.
        'utvalgte' => Dugnad::utvalgte(),
        'medlemmer' => array_map(static fn(array $m): array => [
            'id'        => (int) $m['id'],
            'navn'      => (string) $m['navn'],
            'serDugnad' => !empty($m['ser_dugnad']),
        ], DB::alle(
            "SELECT id, navn, " . (DB::harKolonne('members', 'ser_dugnad') ? 'ser_dugnad' : '0 AS ser_dugnad') . "
               FROM members
              WHERE status IN ('prove','aktiv','pause') AND TRIM(COALESCE(navn, '')) <> ''
           ORDER BY navn"
        )),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');

// ── Gi dugnad ──────────────────────────────────────────────────────────
//
//   POST handling=gi { tekst, medlemmer: [id, …], epost: 'ja'|'nei' }
//
// Eieren, 25. september 2026: «tildele enkeltpersoner i medlemmer
// dugnadsarbeid». Jobben er godkjent med en gang — medlemmet kan stemple inn
// uten aa spoerre foerst. Har medlemmet alt en dugnad som ikke er avsluttet,
// hoppes hen over og nevnes i svaret.
if ($handling === 'gi') {
    if (!DB::harKolonne('dugnad', 'tildelt')) {
        Svar::feil('Vedlikeholdet må kjøres først (oppdatering 214).');
    }
    $tekst = trim(mb_substr(Foresporsel::tekst('tekst'), 0, 500));
    if (mb_strlen($tekst) < 3) {
        Svar::feil('Skriv hva som skal gjøres.');
    }
    $raa = Foresporsel::kropp()['medlemmer'] ?? [];
    $ider = array_values(array_unique(array_filter(array_map('intval', is_array($raa) ? $raa : []))));
    if ($ider === []) {
        Svar::feil('Velg minst ett medlem.');
    }
    $sendEpost = Foresporsel::tekst('epost') !== 'nei';
    $gitt = [];
    $hoppet = [];
    foreach ($ider as $mid) {
        $m = DB::en('SELECT id, navn, epost FROM members WHERE id = :i', ['i' => $mid]);
        if ($m === null) {
            continue;
        }
        $mNavn = trim((string) $m['navn']) ?: 'Medlemmet';
        if (Dugnad::aktiv($mid) !== null) {
            $hoppet[] = $mNavn;
            continue;
        }
        $nyId = DB::settInn('dugnad', [
            'member_id' => $mid,
            'tekst'     => $tekst,
            'status'    => 'godkjent',
            'tildelt'   => 1,
            'svart_av'  => (int) $jeg['id'],
            'svart_at'  => gmdate('Y-m-d H:i:s'),
        ]);
        revider('dugnad_gitt', 'member', $mid, ['dugnad' => $nyId]);
        $gitt[] = $mNavn;
        $mEpost = trim((string) ($m['epost'] ?? ''));
        if ($sendEpost && $mEpost !== '' && filter_var($mEpost, FILTER_VALIDATE_EMAIL)) {
            try {
                Varsel::mal('dugnad_godkjent', ['epost' => $mEpost, 'navn' => $mNavn], [
                    'fornavn' => explode(' ', $mNavn)[0],
                    'tekst'   => $tekst,
                    'svar'    => '',
                ], 'dugnad', $nyId);
            } catch (Throwable $e) {
                logg_feil('Fikk ikke sendt dugnaden til medlemmet', $e);
            }
        }
    }
    if ($gitt === []) {
        Svar::feil($hoppet !== []
            ? implode(', ', $hoppet) . ' har alt en dugnad som ikke er avsluttet.'
            : 'Fant ingen av medlemmene.');
    }
    Svar::ok(['beskjed' => 'Dugnaden er gitt til ' . implode(', ', $gitt) . '.'
        . ($sendEpost ? ' E-post er sendt.' : '')
        . ($hoppet !== [] ? ' ' . implode(', ', $hoppet) . ' har alt en dugnad som ikke er avsluttet, og fikk ikke denne.' : '')]);
}
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
    $timer = Dugnad::kvarterTimer($min);
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
