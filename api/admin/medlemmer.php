<?php
/**
 * Medlemsregisteret.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
krev_admin();

// ── Én person, slik hen selv ser det ────────────────────────────────────
//
// Verkstedet kunne se paameldinger per kurs, men ikke alt én person har
// gjort — og dermed heller ikke hva som faktisk staar paa Min side hos den
// som ringer og lurer paa kursbeviset sitt.
if (Foresporsel::heltall('person') > 0) {
    $pid = Foresporsel::heltall('person');
    $m = DB::en('SELECT id, navn, epost, telefon, rolle, medlemskap_type, status FROM members WHERE id = :i', ['i' => $pid]);
    if ($m === null) {
        Svar::feil('Fant ikke personen.', 404);
    }

    $rader = DB::alle(
        "SELECT b.id, b.antall, b.status, b.belop_ore, b.created_at,
                c.tittel, c.type, cs.start_tid, p.vipps_reference
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
      LEFT JOIN payments p ON p.id = b.payment_id
          WHERE b.member_id = :m
       ORDER BY cs.start_tid IS NULL, cs.start_tid DESC, b.id DESC",
        ['m' => $pid]
    );

    $naa = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    Svar::json([
        'person' => [
            'id'         => (int) $m['id'],
            'navn'       => $m['navn'],
            'epost'      => $m['epost'] ?: '',
            'telefon'    => $m['telefon'] ?: '',
            'medlemskap' => $m['medlemskap_type'] ?: 'Ingen',
            'status'     => $m['status'],
        ],
        'historikk' => array_map(static function (array $b) use ($naa): array {
            $holdt = $b['start_tid'] !== null
                && new DateTimeImmutable((string) $b['start_tid'], new DateTimeZone('UTC')) < $naa;
            return [
                'id'      => (int) $b['id'],
                'tittel'  => $b['tittel'],
                'naar'    => $b['start_tid'] ? Booking::norskDato((string) $b['start_tid']) : 'Uten dato',
                'sum'     => Booking::kroner((int) $b['belop_ore'])
                             . ((int) $b['antall'] > 1 ? ' · ' . $b['antall'] . ' plasser' : ''),
                'status'  => match ((string) $b['status']) {
                    'betalt'    => 'Betalt',
                    'reservert' => 'Reservert — ikke betalt',
                    'avbestilt' => 'Avbestilt',
                    default     => (string) $b['status'],
                },
                'betalt'  => (string) $b['status'] === 'betalt',
                // Samme regel som paa Min side: bevis naar kurset er holdt og
                // betalt, og drop-in er ikke et kurs.
                'kursbevis' => ($holdt && (string) $b['status'] === 'betalt' && (string) $b['type'] !== 'dropin')
                    ? '/api/kursbevis.php?booking=' . (int) $b['id']
                    : null,
                'referanse' => $b['vipps_reference'],
            ];
        }, $rader),
    ]);
}

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
// Hvor mange kurs hver av dem har betalt for.
//
// Uten dette er en kursdeltaker og en tom konto det samme i lista: begge
// staar som «Ikke medlem». Tallet er det som skiller dem — og det er ogsaa
// det som avgjor om noen har et kursbevis aa hente.
$kurs = [];
foreach (DB::alle(
    "SELECT b.member_id, COUNT(*) AS antall
       FROM bookings b
      WHERE b.member_id IS NOT NULL AND b.status = 'betalt'
      GROUP BY b.member_id"
) as $r) {
    $kurs[(int) $r['member_id']] = (int) $r['antall'];
}

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
    'antallKurs' => $kurs[(int) $m['id']] ?? 0,
], $medlemmer)]);
