<?php
/**
 * Godkjenning av medlemmenes salg.
 *
 *   GET                       alt, med det som venter forst
 *   POST handling=godkjenn    { id }
 *   POST handling=avvis       { id, grunn }
 *   POST handling=skjul       { id }
 *   POST handling=slett       { id }
 *   POST handling=endre       { id, tittel, beskrivelse, pris, kategori, antall }  (ny-admin › Varer, 09.10.2026)
 *
 * Varen vises ikke i butikken for noen har sett paa den. Det er verkstedets
 * navn den henger under, og et bilde eller en pris kan vaere feil.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

$hent = static fn(): array => array_map(static fn($r) => [
    'id'       => (int) $r['id'],
    'tittel'   => $r['tittel'],
    'tekst'    => $r['beskrivelse'],
    'laget'    => 'Laget av ' . ($r['produsent'] ?: 'et medlem'),
    // Er medlemsraden borte, mangler navnet — men varen skal staa.
    'medlem'   => $r['medlemsnavn'] ?: 'Ukjent medlem',
    'bilde'    => $r['bilde'] ? '/api/bilde.php?salg=' . rawurlencode((string) $r['bilde']) : null,
    'pris'     => Booking::kroner((int) $r['pris_ore']),
    // Hele kroner til «Rediger» i /ny-admin › Varer (09.10.2026).
    'prisKr'   => intdiv((int) $r['pris_ore'], 100),
    'kategori' => $r['kategori'] ?: 'Annet',
    'antall'   => (int) $r['antall'],
    'vipps'    => $r['vippsnummer'],
    'kontakt'  => $r['kontakt'],
    'levering' => 'Leveres etter avtale',
    'status'   => $r['status'],
    // Naar varen ble lagt inn — raden i admin viser det (24. september 2026).
    'dato'     => !empty($r['created_at']) ? date('j.n.Y', strtotime((string) $r['created_at'])) : '',
], DB::alle(
    // ── LEFT JOIN, ikke JOIN ──────────────────────────────────────────
    //
    // Eieren, 19. september 2026: «vi har faatt 6 eposter til godkjenning,
    // men saa er det bare en ting til godkjenning» — og etter at skjermen
    // begynte aa hente lista paa nytt: «ingenting til godkjenning».
    //
    // Med en indre kobling forsvinner en vare helt ut av lista dersom
    // medlemsraden ikke finnes — slettet, slaatt sammen, eller hva det
    // maatte vaere. Varen ligger i basen med «til_godkjenning», e-posten
    // gikk ut, og likevel er den ikke aa se noe sted. Da kan den heller
    // aldri godkjennes eller avvises.
    //
    // En vare skal ikke kunne gjemme seg bak en manglende medlemsrad.
    "SELECT ms.*, m.navn AS medlemsnavn
       FROM member_sales ms
       LEFT JOIN members m ON m.id = ms.member_id
      ORDER BY ms.status = 'til_godkjenning' DESC, ms.id DESC"
));

if (Foresporsel::metode() === 'GET') {
    Svar::json(['salg' => $hent()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$id  = Foresporsel::heltall('id');
$rad = DB::en(
    // Samme grunn som i lista over: uten LEFT JOIN svarer denne «Fant ikke
    // varen» paa noe som staar der. Da kunne den hverken godkjennes eller
    // avvises — den ville bare bli liggende.
    'SELECT ms.*, m.navn, m.epost, m.telefon
       FROM member_sales ms
       LEFT JOIN members m ON m.id = ms.member_id
      WHERE ms.id = :i',
    ['i' => $id]
);
if ($rad === null) {
    Svar::feil('Fant ikke varen.', 404);
}

// Teksten ligger i malene «medlemsvare_godkjent» og «medlemsvare_avvist»,
// som Monica kan endre selv under Maler.
$si = static function (array $rad, string $mal, array $felter = []): void {
    if (!empty($rad['epost'])) {
        Varsel::mal($mal, ['epost' => (string) $rad['epost']],
            ['navn' => (string) $rad['navn'], 'tittel' => (string) $rad['tittel']] + $felter,
            'medlemssalg', (int) $rad['id']);
    }
};

switch (Foresporsel::tekst('handling')) {

    case 'godkjenn':
        DB::oppdater('member_sales', ['status' => 'publisert', 'avvist_grunn' => null], ['id' => $id]);
        $si($rad, 'medlemsvare_godkjent');
        revider('medlemssalg_godkjent', 'member_sale', $id, ['tittel' => $rad['tittel']]);
        Svar::ok(['salg' => $hent(), 'beskjed' => $rad['tittel'] . ' er ute i butikken.']);

    case 'avvis':
        $grunn = mb_substr(Foresporsel::tekst('grunn'), 0, 255);
        DB::oppdater('member_sales', ['status' => 'avvist', 'avvist_grunn' => $grunn ?: null], ['id' => $id]);
        // Vi sier fra. En vare som bare blir borte uten et ord er verre enn et
        // nei — selgeren vet ikke om noe er galt eller om ingen har sett paa den.
        $si($rad, 'medlemsvare_avvist',
            ['grunn' => $grunn !== '' ? "\n\nGrunn: " . $grunn : '']);
        revider('medlemssalg_avvist', 'member_sale', $id, ['tittel' => $rad['tittel'], 'grunn' => $grunn]);
        Svar::ok(['salg' => $hent(), 'beskjed' => $rad['tittel'] . ' er avvist, og selgeren har fått beskjed.']);

    // «Legg ut igjen» paa en vare som er skjult eller avvist. Samme vei ut i
    // butikken som «Godkjenn», og selgeren faar den samme beskjeden — den
    // sier at varen er ute, og det er sant begge veier.
    case 'skjul':
        DB::oppdater('member_sales', ['status' => 'skjult'], ['id' => $id]);
        revider('medlemssalg_skjult', 'member_sale', $id, ['tittel' => $rad['tittel']]);
        Svar::ok(['salg' => $hent(), 'beskjed' => $rad['tittel'] . ' er tatt ut av butikken.']);

    case 'slett':
        // Bildet ryddes med. Ellers blir det liggende igjen paa serveren for
        // alltid, uten noe som peker paa det.
        if ($rad['bilde']) {
            Bilder::slett((string) $rad['bilde'], 'medlemssalg');
        }
        DB::kjor('DELETE FROM member_sales WHERE id = :i', ['i' => $id]);
        revider('medlemssalg_slettet', 'member_sale', $id, ['tittel' => $rad['tittel']]);
        Svar::ok(['salg' => $hent(), 'beskjed' => $rad['tittel'] . ' er slettet.']);

    // Rediger fra /ny-admin › Varer › Medlemskolleksjon (eieren 09.10.2026).
    // Statusen og selgerens Vipps-nummer roeres ikke; prisen er i hele kroner.
    case 'endre':
        $tittel = trim(mb_substr(Foresporsel::tekst('tittel'), 0, 191));
        $pris = Foresporsel::heltall('pris');
        $antall = Foresporsel::heltall('antall', 1);
        if ($tittel === '') {
            Svar::feil('Varen må ha et navn.');
        }
        if ($pris < 0 || $pris > 100000) {
            Svar::feil('Prisen må være mellom 0 og 100 000 kroner.');
        }
        if ($antall < 1 || $antall > 999) {
            Svar::feil('Antallet må være mellom 1 og 999.');
        }
        DB::oppdater('member_sales', [
            'tittel'      => $tittel,
            'beskrivelse' => trim(Foresporsel::tekst('beskrivelse')) ?: null,
            'pris_ore'    => $pris * 100,
            'kategori'    => trim(mb_substr(Foresporsel::tekst('kategori'), 0, 32)) ?: null,
            'antall'      => $antall,
        ], ['id' => $id]);
        revider('medlemssalg_endret', 'member_sale', $id, ['tittel' => $tittel]);
        Svar::ok(['salg' => $hent(), 'beskjed' => $tittel . ' er lagret.']);

    default:
        Svar::feil('Ukjent handling.');
}
