<?php
/**
 * Foresporsler fra nettsiden — les og merk som besvart.
 *
 *   GET                     lister dem, ubesvarte forst
 *   POST {id, status}       merker en som besvart eller ubesvart igjen
 *
 * Svaret sendes fra e-postklienten din som vanlig. Her holder vi bare styr paa
 * hva som er tatt hand om, slik at ingen blir liggende.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rader = DB::alle(
        "SELECT id, navn, epost, telefon, type, antall, melding, status, created_at
           FROM enquiries
       ORDER BY status = 'ubesvart' DESC, id DESC
          LIMIT 200"
    );

    Svar::json([
        'foresporsler' => array_map(static fn(array $r): array => [
            'id'      => (int) $r['id'],
            'navn'    => (string) $r['navn'],
            'epost'   => (string) ($r['epost'] ?? ''),
            'tlf'     => (string) ($r['telefon'] ?? ''),
            'hva'     => trim(((string) ($r['type'] ?? 'Forespørsel'))
                        . ($r['antall'] ? ', ' . $r['antall'] . ' personer' : '')),
            'tekst'   => (string) ($r['melding'] ?? 'Ingen detaljer oppgitt'),
            'tid'     => Booking::norskDato((string) $r['created_at']),
            'status'  => $r['status'] === 'ubesvart' ? 'Ubesvart' : 'Besvart',
            'tone'    => $r['status'] === 'ubesvart' ? 'warning' : 'neutral',
        ], $rader),
        'ubesvarte' => (int) DB::verdi("SELECT COUNT(*) FROM enquiries WHERE status = 'ubesvart'"),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$id = Foresporsel::heltall('id');
$status = Foresporsel::tekst('status') === 'ubesvart' ? 'ubesvart' : 'besvart';

if (!DB::en('SELECT id FROM enquiries WHERE id = :id', ['id' => $id])) {
    Svar::feil('Fant ikke forespørselen.', 404);
}

DB::oppdater('enquiries', [
    'status'     => $status,
    'besvart_at' => $status === 'besvart' ? gmdate('Y-m-d H:i:s') : null,
    'besvart_av' => $status === 'besvart' ? $admin['id'] : null,
], ['id' => $id]);

revider('foresporsel_' . $status, 'enquiry', $id);
Svar::ok(['status' => $status]);
