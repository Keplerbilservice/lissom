<?php
/**
 * Medlemsregisteret.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
krev_admin();

$sok = Foresporsel::tekst('sok');
$hvor = 'anonymisert_at IS NULL';
$param = [];

if ($sok !== '') {
    $hvor .= ' AND (navn LIKE :s OR epost LIKE :s OR telefon LIKE :s)';
    $param['s'] = '%' . $sok . '%';
}

$medlemmer = DB::alle(
    "SELECT id, navn, epost, telefon, rolle, medlemskap_type, status,
            start_dato, timer_per_mnd, created_at
       FROM members
      WHERE {$hvor}
      ORDER BY navn
      LIMIT 500",
    $param
);

Svar::json(['medlemmer' => array_map(static fn($m) => [
    'id'         => (int) $m['id'],
    'navn'       => $m['navn'],
    'epost'      => $m['epost'],
    'telefon'    => $m['telefon'],
    'erAdmin'    => $m['rolle'] === 'admin',
    'medlemskap' => $m['medlemskap_type'],
    'status'     => $m['status'],
    'startDato'  => $m['start_dato'],
    'timer'      => $m['timer_per_mnd'],
], $medlemmer)]);
