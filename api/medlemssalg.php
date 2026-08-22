<?php
/**
 * Medlemmenes salg.
 *
 *   GET                    varene som er godkjent og ute i butikken
 *   GET ?mine=ja           mine egne, uansett status (krever innlogging)
 *   POST (multipart)       legg ut en vare — gaar til godkjenning
 *   POST handling=trekk    ta ned min egen vare
 *
 * Salget er en avtale mellom kjoper og selger. Betalingen gaar direkte til
 * selgerens eget Vippsnummer; Lissom formidler, og rorer aldri pengene. Derfor
 * staar bade Vippsnummeret og kontaktopplysningen paa varen — det er selgeren
 * selv som oppgir dem, og uten dem er det ingen handel.
 *
 * E-postadressen til selgeren vises aldri av seg selv. Bare det feltet
 * selgeren fyller ut som «kontakt».
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

const MAKS_PER_MEDLEM = 20;
const KATEGORIER = ['Kopper', 'Boller', 'Fat', 'Annet'];

$ut = static fn(array $r, bool $eier): array => [
    'id'        => (int) $r['id'],
    'tittel'    => $r['tittel'],
    'tekst'     => $r['beskrivelse'],
    'laget'     => 'Laget av ' . ($r['produsent'] ?: 'et medlem'),
    'bilde'     => $r['bilde'] ? '/api/bilde.php?salg=' . rawurlencode((string) $r['bilde']) : null,
    'pris'      => Booking::kroner((int) $r['pris_ore']),
    'kategori'  => $r['kategori'] ?: 'Annet',
    'antall'    => (int) $r['antall'],
    'vipps'     => $r['vippsnummer'],
    'kontakt'   => $r['kontakt'],
    'levering'  => 'Leveres etter avtale',
    'status'    => $r['status'],
    // Grunnen til at noe ble avvist skal bare selgeren se.
    'avvist'    => $eier ? $r['avvist_grunn'] : null,
];

// ------------------------------------------------------------------ lesing
if (Foresporsel::metode() === 'GET') {
    if (Foresporsel::tekst('mine') === 'ja') {
        $m = krev_medlem();
        $rader = DB::alle(
            'SELECT * FROM member_sales WHERE member_id = :m ORDER BY id DESC',
            ['m' => $m['id']]
        );
        Svar::json(['mine' => array_map(static fn($r) => $ut($r, true), $rader)]);
    }

    $rader = DB::alle(
        "SELECT ms.*, m.navn AS medlemsnavn
           FROM member_sales ms
           JOIN members m ON m.id = ms.member_id
          WHERE ms.status = 'publisert' AND m.anonymisert_at IS NULL
          ORDER BY ms.id DESC"
    );
    Svar::json(['varer' => array_map(static fn($r) => $ut($r, false), $rader)], 200, 30);
}

// ----------------------------------------------------------------- skriving
Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$medlem = krev_aktivt_medlem();

// ---- ta ned min egen vare
if (Foresporsel::tekst('handling') === 'trekk' || ($_POST['handling'] ?? '') === 'trekk') {
    $id = (int) ($_POST['id'] ?? Foresporsel::heltall('id'));
    $rad = DB::en('SELECT * FROM member_sales WHERE id = :i', ['i' => $id]);
    if ($rad === null || (int) $rad['member_id'] !== (int) $medlem['id']) {
        Svar::feil('Fant ikke varen din.', 404);
    }
    DB::oppdater('member_sales', ['status' => 'skjult'], ['id' => $id]);
    Svar::ok(['beskjed' => $rad['tittel'] . ' er tatt ned.']);
}

// ---- legg ut en vare
//
// Skjemaet sendes som multipart fordi det har med et bilde. Feltene ligger
// derfor i $_POST, ikke i JSON-kroppen.
$felt = static fn(string $n): string => trim((string) ($_POST[$n] ?? ''));

$tittel    = mb_substr($felt('tittel'), 0, 191);
$produsent = mb_substr($felt('produsent'), 0, 96);
$tekst     = mb_substr($felt('tekst'), 0, 2000);
$vipps     = preg_replace('/\D+/', '', $felt('vipps')) ?? '';
$kontakt   = mb_substr($felt('kontakt'), 0, 191);
$kategori  = in_array($felt('kategori'), KATEGORIER, true) ? $felt('kategori') : 'Annet';
$pris      = (int) preg_replace('/\D+/', '', $felt('pris'));
$antall    = max(1, min(99, (int) $felt('antall') ?: 1));

foreach ([
    'et navn paa varen'      => $tittel,
    'en beskrivelse'         => $tekst,
    'navnet som skal vises'  => $produsent,
    'et Vippsnummer'         => $vipps,
    'en maate aa naa deg paa' => $kontakt,
] as $hva => $verdi) {
    if ($verdi === '') {
        Svar::feil('Vi mangler ' . $hva . '.');
    }
}

// Norske mobilnumre er aatte siffer. Et Vippsnummer som ikke gaar til noen er
// verre enn ingen vare: kjoperen sender pengene til en fremmed.
if (strlen($vipps) === 10 && str_starts_with($vipps, '47')) {
    $vipps = substr($vipps, 2);
}
if (!preg_match('/^[49]\d{7}$/', $vipps)) {
    Svar::feil('Vippsnummeret maa vaere et norsk mobilnummer paa aatte siffer.');
}
if ($pris < 1 || $pris > 100000) {
    Svar::feil('Prisen maa vaere mellom 1 og 100 000 kroner.');
}

$antallMine = (int) DB::verdi(
    "SELECT COUNT(*) FROM member_sales WHERE member_id = :m AND status IN ('til_godkjenning','publisert')",
    ['m' => $medlem['id']]
);
if ($antallMine >= MAKS_PER_MEDLEM) {
    Svar::feil('Du har ' . $antallMine . ' varer ute alt. Ta ned noen for du legger ut flere.');
}

$bilde = null;
if (isset($_FILES['bilde']) && ($_FILES['bilde']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $bilde = Bilder::taImot($_FILES['bilde'], 'medlemssalg');
    } catch (RuntimeException $e) {
        Svar::feil($e->getMessage());
    }
}

$id = DB::settInn('member_sales', [
    'member_id'   => (int) $medlem['id'],
    'tittel'      => $tittel,
    'produsent'   => $produsent,
    'beskrivelse' => $tekst,
    'bilde'       => $bilde,
    'pris_ore'    => $pris * 100,
    'kategori'    => $kategori,
    'antall'      => $antall,
    'vippsnummer' => $vipps,
    'kontakt'     => $kontakt,
    'status'      => 'til_godkjenning',
]);

// Verkstedet skal vite at det ligger noe og venter.
Varsel::epost(
    (string) Config::hent('epost_fra', 'post@lissom.no'),
    'Ny vare til godkjenning',
    $produsent . ' har lagt ut «' . $tittel . '» til ' . Booking::kroner($pris * 100)
        . ".\n\nGodkjenn eller avvis under Admin → Butikk.",
    'medlemssalg',
    $id
);

revider('medlemssalg_lagt_ut', 'member_sale', $id, ['tittel' => $tittel]);

Svar::ok([
    'id'      => $id,
    'beskjed' => 'Takk! «' . $tittel . '» ligger til godkjenning. Du får beskjed når den er ute i butikken.',
]);
