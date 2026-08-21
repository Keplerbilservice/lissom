<?php
/**
 * Tallene paa admin-forsiden. Alt hentes fra databasen — ingenting er anslag.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
krev_admin();

$kroner = static fn(int $ore): string => Booking::kroner($ore);

// --- Omsetning ------------------------------------------------------------
$betaltIdag = (int) DB::verdi(
    "SELECT COALESCE(SUM(belop_ore - refundert_ore), 0) FROM payments
      WHERE status = 'betalt' AND created_at >= UTC_DATE()"
);
$betaltMnd = (int) DB::verdi(
    "SELECT COALESCE(SUM(belop_ore - refundert_ore), 0) FROM payments
      WHERE status = 'betalt' AND created_at >= DATE_FORMAT(UTC_DATE(), '%Y-%m-01')"
);

// --- Bookinger ------------------------------------------------------------
$nyeBookinger = (int) DB::verdi(
    "SELECT COUNT(*) FROM bookings
      WHERE status = 'betalt' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
);
$ubetalte = (int) DB::verdi(
    "SELECT COUNT(*) FROM bookings
      WHERE status = 'reservert' AND reservert_til > UTC_TIMESTAMP()"
);

// --- Kommende okter -------------------------------------------------------
$kommende = DB::alle(
    "SELECT cs.id, cs.start_tid, c.tittel,
            COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet,
            (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
              WHERE b.course_session_id = cs.id AND b.status = 'betalt') AS pameldte
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
      WHERE cs.status = 'planlagt' AND cs.start_tid > UTC_TIMESTAMP()
      ORDER BY cs.start_tid
      LIMIT 8"
);

// --- Ting som trenger oppmerksomhet --------------------------------------
$varsler = [];

$feiledeVarsler = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'feilet'");
if ($feiledeVarsler > 0) {
    $varsler[] = $feiledeVarsler . ' varsel kom ikke fram';
}

$iKo = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'ko' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)");
if ($iKo > 0) {
    $varsler[] = $iKo . ' varsel har ligget i ko i over en halvtime';
}

$hengende = (int) DB::verdi(
    "SELECT COUNT(*) FROM payments
      WHERE status IN ('venter','autorisert')
        AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)"
);
if ($hengende > 0) {
    $varsler[] = $hengende . ' betaling har hengt i over en time';
}

$venteliste = (int) DB::verdi("SELECT COUNT(*) FROM waitlist WHERE status = 'venter'");

// --- Siste paameldinger ---------------------------------------------------
$nyeste = DB::alle(
    "SELECT b.id, b.antall, b.status, b.belop_ore, b.created_at,
            COALESCE(m.navn, b.gjest_navn) AS navn,
            COALESCE(m.epost, b.gjest_epost) AS epost,
            c.tittel, cs.start_tid, p.vipps_reference
       FROM bookings b
       JOIN courses c ON c.id = b.course_id
  LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
  LEFT JOIN members m ON m.id = b.member_id
  LEFT JOIN payments p ON p.id = b.payment_id
      WHERE b.status IN ('betalt','reservert')
      ORDER BY b.id DESC
      LIMIT 12"
);

Svar::json([
    'nyeste' => array_map(static fn($b) => [
        'navn'      => $b['navn'],
        'epost'     => $b['epost'],
        'hva'       => $b['tittel'] . ((int) $b['antall'] > 1 ? ', ' . $b['antall'] . ' plasser' : ''),
        'naar'      => $b['start_tid'] ? Booking::norskDato((string) $b['start_tid']) : '',
        'tid'       => Booking::norskDato((string) $b['created_at']),
        'belop'     => Booking::kroner((int) $b['belop_ore']),
        'status'    => $b['status'] === 'betalt' ? 'Betalt' : 'Ubetalt',
        'referanse' => $b['vipps_reference'],
    ], $nyeste),
    'omsetning' => [
        'idag'  => $kroner($betaltIdag),
        'maned' => $kroner($betaltMnd),
    ],
    'bookinger' => [
        'sisteUke' => $nyeBookinger,
        'ubetalte' => $ubetalte,
    ],
    'medlemmer' => [
        'aktive' => (int) DB::verdi("SELECT COUNT(*) FROM members WHERE status = 'aktiv'"),
        'totalt' => (int) DB::verdi('SELECT COUNT(*) FROM members WHERE anonymisert_at IS NULL'),
    ],
    'venteliste' => $venteliste,
    'varsler'    => $varsler,
    'kommende'   => array_map(static fn($o) => [
        'oktId'     => (int) $o['id'],
        'tittel'    => $o['tittel'],
        'naar'      => Booking::norskDato((string) $o['start_tid']),
        'pameldte'  => (int) $o['pameldte'],
        'kapasitet' => (int) $o['kapasitet'],
    ], $kommende),
]);
