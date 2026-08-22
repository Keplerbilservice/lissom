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

// Nodluke-numrene i secrets.php gir admin ved kjoring uten at kolonnen
// nodvendigvis er satt. Uten dette ville eieren sett seg selv som vanlig
// medlem i lista, mens hen faktisk har admin-tilgang.
$nodluker = Config::adminNumre();

// Brukte minutter denne maaneden, per medlem. Ett oppslag for hele lista —
// ikke ett per rad. Maanedsgrensa folger norsk kalender.
$fra = Stempling::manedStart();
Stempling::lukkGlemte();

$brukt = [];
foreach (DB::alle(
    "SELECT member_id,
            COALESCE(SUM(COALESCE(minutter, TIMESTAMPDIFF(MINUTE, inn_tid, UTC_TIMESTAMP()))), 0) AS min
       FROM check_ins WHERE inn_tid >= :fra GROUP BY member_id",
    ['fra' => $fra]
) as $r) {
    $brukt[(int) $r['member_id']] = (int) $r['min'];
}

$inne = [];
foreach (DB::alle('SELECT member_id FROM check_ins WHERE ut_tid IS NULL') as $r) {
    $inne[(int) $r['member_id']] = true;
}

Svar::json(['medlemmer' => array_map(static fn($m) => [
    'id'         => (int) $m['id'],
    'navn'       => $m['navn'],
    'epost'      => $m['epost'],
    'telefon'    => $m['telefon'],
    'erAdmin'    => $m['rolle'] === 'admin'
                    || ($m['telefon'] !== null && in_array(normaliser_telefon((string) $m['telefon']), $nodluker, true)),
    'medlemskap' => $m['medlemskap_type'],
    'status'     => $m['status'],
    'startDato'  => $m['start_dato'],
    // Planen bestemmer timetallet, medlemsraden overstyrer. «timer_per_mnd»
    // alene sto tom for alle — se Medlemskap::timerFor().
    'timer'      => Medlemskap::timerFor($m),
    'bruktTimer' => Stempling::timer($brukt[(int) $m['id']] ?? 0),
    'bruktMin'   => $brukt[(int) $m['id']] ?? 0,
    'erInne'     => isset($inne[(int) $m['id']]),
], $medlemmer)]);
