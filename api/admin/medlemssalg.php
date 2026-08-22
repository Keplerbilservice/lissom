<?php
/**
 * Godkjenning av medlemmenes salg.
 *
 *   GET                       alt, med det som venter forst
 *   POST handling=godkjenn    { id }
 *   POST handling=avvis       { id, grunn }
 *   POST handling=skjul       { id }
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
    'medlem'   => $r['medlemsnavn'],
    'bilde'    => $r['bilde'] ? '/api/bilde.php?salg=' . rawurlencode((string) $r['bilde']) : null,
    'pris'     => Booking::kroner((int) $r['pris_ore']),
    'kategori' => $r['kategori'] ?: 'Annet',
    'antall'   => (int) $r['antall'],
    'vipps'    => $r['vippsnummer'],
    'kontakt'  => $r['kontakt'],
    'levering' => 'Leveres etter avtale',
    'status'   => $r['status'],
], DB::alle(
    "SELECT ms.*, m.navn AS medlemsnavn
       FROM member_sales ms
       JOIN members m ON m.id = ms.member_id
      ORDER BY ms.status = 'til_godkjenning' DESC, ms.id DESC"
));

if (Foresporsel::metode() === 'GET') {
    Svar::json(['salg' => $hent()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$id  = Foresporsel::heltall('id');
$rad = DB::en(
    'SELECT ms.*, m.navn, m.epost, m.telefon
       FROM member_sales ms JOIN members m ON m.id = ms.member_id
      WHERE ms.id = :i',
    ['i' => $id]
);
if ($rad === null) {
    Svar::feil('Fant ikke varen.', 404);
}

$si = static function (array $rad, string $emne, string $tekst): void {
    if (!empty($rad['epost'])) {
        Varsel::epost((string) $rad['epost'], $emne, $tekst, 'medlemssalg', (int) $rad['id']);
    }
};

switch (Foresporsel::tekst('handling')) {

    case 'godkjenn':
        DB::oppdater('member_sales', ['status' => 'publisert', 'avvist_grunn' => null], ['id' => $id]);
        $si($rad, '«' . $rad['tittel'] . '» er ute i butikken',
            'Hei ' . $rad['navn'] . "!\n\n«" . $rad['tittel'] . '» er godkjent og ligger nå i butikken på lissom.no.'
            . "\n\nKjoperen betaler direkte til Vippsnummeret ditt, og tar kontakt for aa avtale overlevering.");
        revider('medlemssalg_godkjent', 'member_sale', $id, ['tittel' => $rad['tittel']]);
        Svar::ok(['salg' => $hent(), 'beskjed' => $rad['tittel'] . ' er ute i butikken.']);

    case 'avvis':
        $grunn = mb_substr(Foresporsel::tekst('grunn'), 0, 255);
        DB::oppdater('member_sales', ['status' => 'avvist', 'avvist_grunn' => $grunn ?: null], ['id' => $id]);
        // Vi sier fra. En vare som bare blir borte uten et ord er verre enn et
        // nei — selgeren vet ikke om noe er galt eller om ingen har sett paa den.
        $si($rad, '«' . $rad['tittel'] . '» ble ikke lagt ut',
            'Hei ' . $rad['navn'] . "!\n\nVi har sett paa «" . $rad['tittel'] . '», og legger den ikke ut slik den er naa.'
            . ($grunn !== '' ? "\n\nGrunn: " . $grunn : '')
            . "\n\nDu kan legge den ut paa nytt fra Min side naar du vil.");
        revider('medlemssalg_avvist', 'member_sale', $id, ['tittel' => $rad['tittel'], 'grunn' => $grunn]);
        Svar::ok(['salg' => $hent(), 'beskjed' => $rad['tittel'] . ' er avvist, og selgeren har fått beskjed.']);

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

    default:
        Svar::feil('Ukjent handling.');
}
