<?php
/**
 * Medlemssoknader — les og behandle.
 *
 * GET  ?status=venter   Lister soknader.
 * POST {id, vedtak: godkjent|avslatt, type?, begrunnelse?}
 *
 * Godkjenning setter medlemmets status. Uten godkjenning her kommer ingen inn
 * i medlemsdelen, uansett hvor mange ganger de logger inn med Vipps.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = Foresporsel::tekst('status') ?: 'venter';
    if (!in_array($status, ['venter', 'godkjent', 'avslatt', 'alle'], true)) {
        $status = 'venter';
    }

    $rader = $status === 'alle'
        ? DB::alle('SELECT s.*, m.status AS medlem_status FROM membership_applications s
                      JOIN members m ON m.id = s.member_id
                  ORDER BY s.status = "venter" DESC, s.id DESC LIMIT 200')
        : DB::alle('SELECT s.*, m.status AS medlem_status FROM membership_applications s
                      JOIN members m ON m.id = s.member_id
                     WHERE s.status = :s ORDER BY s.id DESC LIMIT 200', ['s' => $status]);

    Svar::json([
        'soknader' => array_map(static fn(array $r): array => [
            'id'        => (int) $r['id'],
            'medlemId'  => (int) $r['member_id'],
            'navn'      => (string) $r['navn'],
            'epost'     => (string) ($r['epost'] ?? ''),
            'telefon'   => (string) ($r['telefon'] ?? ''),
            'type'      => (string) ($r['onsket_type'] ?? ''),
            'erfaring'  => (string) ($r['erfaring'] ?? ''),
            'melding'   => (string) ($r['melding'] ?? ''),
            'status'    => (string) $r['status'],
            'sendt'     => Booking::norskDatoKort($r['created_at']),
        ], $rader),
        'venter' => (int) DB::verdi('SELECT COUNT(*) FROM membership_applications WHERE status = "venter"'),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$id     = Foresporsel::heltall('id');
$vedtak = Foresporsel::tekst('vedtak');
if (!in_array($vedtak, ['godkjent', 'avslatt'], true)) {
    Svar::feil('Ukjent vedtak.');
}

$soknad = DB::en('SELECT * FROM membership_applications WHERE id = :id', ['id' => $id]);
if (!$soknad) {
    Svar::feil('Fant ikke søknaden.', 404);
}
if ($soknad['status'] !== 'venter') {
    Svar::feil('Søknaden er allerede behandlet.');
}

$type        = mb_substr(Foresporsel::tekst('type') ?: (string) ($soknad['onsket_type'] ?? ''), 0, 64);
$begrunnelse = mb_substr(Foresporsel::tekst('begrunnelse'), 0, 500);

DB::iTransaksjon(static function () use ($soknad, $vedtak, $type, $begrunnelse, $admin): void {
    DB::oppdater('membership_applications', [
        'status'       => $vedtak,
        'behandlet_av' => $admin['id'],
        'behandlet_at' => gmdate('Y-m-d H:i:s'),
        'begrunnelse'  => $begrunnelse !== '' ? $begrunnelse : null,
    ], ['id' => $soknad['id']]);

    if ($vedtak === 'godkjent') {
        // «prove» og ikke «aktiv»: medlemskapet begynner å løpe først når
        // betalingsavtalen er på plass. Tilgangen er den samme.
        DB::oppdater('members', [
            'status'          => 'prove',
            'medlemskap_type' => $type !== '' ? $type : null,
            'start_dato'      => gmdate('Y-m-d'),
        ], ['id' => $soknad['member_id']]);
    }
});

$navn = (string) $soknad['navn'];
if ($vedtak === 'godkjent') {
    Varsel::epost(
        (string) $soknad['epost'],
        'Velkommen som medlem hos Lissom',
        "Hei {$navn},\n\nSøknaden din er godkjent. Logg inn på lissom.no, så finner du "
        . "medlemsdelen på Min side — timer, interne kurs og samlinger, og muligheten "
        . "til å legge ut egne arbeider for salg.\n\n"
        . "Vi går gjennom dørkode og ordensregler første gang du kommer.\n\n"
        . "Hilsen Lissom Keramikk & Håndverk\nNordre Løkkevei 15, 3120 Nøtterøy"
    );
    if ($soknad['telefon']) {
        Varsel::sms((string) $soknad['telefon'], 'Hei ' . $navn . '! Medlemskapet ditt hos Lissom er godkjent. Logg inn på lissom.no for å se Min side.');
    }
} else {
    Varsel::epost(
        (string) $soknad['epost'],
        'Om søknaden din til Lissom',
        "Hei {$navn},\n\nTakk for søknaden. Vi har dessverre ikke anledning til å ta deg "
        . "opp som medlem nå."
        . ($begrunnelse !== '' ? "\n\n{$begrunnelse}" : '')
        . "\n\nDu er hjertelig velkommen på kurs og arrangementer hos oss, og du kan søke "
        . "igjen senere.\n\nHilsen Lissom Keramikk & Håndverk"
    );
}

revider('medlemssoknad_' . $vedtak, 'membership_application', (int) $soknad['id'], ['medlem' => (int) $soknad['member_id']]);

Svar::ok(['status' => $vedtak]);
