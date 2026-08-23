<?php
/**
 * Hvem som er paameldt. Den siden dere kommer til aa bruke oftest.
 *
 *   ?oktId=5   deltakerne paa en bestemt dato
 *   uten       alle kommende okter med antall paameldte
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
krev_admin();

$oktId = Foresporsel::heltall('oktId');

/** Samme regel som paa Min side: betalt, gjennomfort, og et kurs. */
$kursbevis = static function (array $d): ?string {
    if (($d['status'] ?? '') !== 'betalt') {
        return null;
    }
    if (in_array((string) ($d['tema'] ?? ''), ['Drop-in', 'Kun for medlemmer'], true)) {
        return null;
    }
    $slutt = $d['slutt_tid'] ?: $d['start_tid'];
    if ($slutt === null || strtotime((string) $slutt) > time()) {
        return null;
    }
    return '/api/kursbevis.php?booking=' . (int) $d['id'];
};

if ($oktId <= 0) {
    $okter = DB::alle(
        "SELECT cs.id, cs.start_tid, c.tittel,
                COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet,
                (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
                  WHERE b.course_session_id = cs.id AND b.status = 'betalt') AS betalt,
                (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
                  WHERE b.course_session_id = cs.id AND b.status = 'reservert'
                    AND (b.reservert_til IS NULL OR b.reservert_til > UTC_TIMESTAMP())) AS reservert
           FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id
          WHERE cs.status <> 'avlyst' AND cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
          ORDER BY cs.start_tid"
    );

    // Flat liste over alle deltakere framover — det admin-siden viser.
    $alle = DB::alle(
        "SELECT b.id, b.antall, b.status, b.belop_ore, b.folge_medlem,
                b.betalt_maate, b.notat, b.lagt_inn_av,
                b.member_id, b.course_session_id,
                COALESCE(m.navn, b.gjest_navn) AS navn,
                COALESCE(m.epost, b.gjest_epost) AS epost,
                COALESCE(m.telefon, b.gjest_telefon) AS telefon,
                c.tittel, c.tema, cs.start_tid, cs.slutt_tid,
                p.vipps_reference, p.status AS betalingsstatus
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
      LEFT JOIN members m ON m.id = b.member_id
      LEFT JOIN payments p ON p.id = b.payment_id
          WHERE b.status IN ('betalt','reservert')
            AND (cs.start_tid IS NULL OR cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY))
          ORDER BY cs.start_tid, b.id"
    );

    Svar::json([
        'deltakere' => array_map(static fn($d) => [
            'id'      => (int) $d['id'],
            // Hvem raden gjelder, ikke bare hva den heter.
            //
            // Uten disse to kunne admin se en deltaker, men ikke slaa opp
            // hva hen selv ser paa Min side, og ikke hoppe til datoen for
            // aa legge til noen. Navn er ingen noekkel — to kan hete det
            // samme, og en gjest har ingen konto i det hele tatt.
            'medlemId' => $d['member_id'] !== null ? (int) $d['member_id'] : null,
            'oktId'    => $d['course_session_id'] !== null ? (int) $d['course_session_id'] : null,
            'navn'    => $d['navn'],
            'epost'   => $d['epost'],
            'tlf'     => $d['telefon'],
            'kurs'    => $d['tittel'],
            'dato'    => $d['start_tid'] ? Booking::norskDato((string) $d['start_tid']) : 'Uten dato',
            'sum'     => Booking::kroner((int) $d['belop_ore']),
            // Hvordan den ble gjort opp. Manuelle paameldinger har det
            // skrevet i klartekst; nettbestillinger gikk gjennom Vipps.
            'maate'   => $d['vipps_reference'] ? 'Vipps' : ($d['betalt_maate'] ?: '—'),
            'manuell' => $d['lagt_inn_av'] !== null,
            'notat'   => $d['notat'],
            'status'  => $d['status'] === 'betalt' ? 'Betalt' : 'Ubetalt',
            'antall'  => (int) $d['antall'],
            'folge'   => $d['folge_medlem'],
            'referanse' => $d['vipps_reference'],
            // Kursbevis for gjennomforte, betalte kurs. Verkstedet skal kunne
            // skrive ut for noen som ikke faar det til selv.
            'kursbevis' => $kursbevis($d),
        ], $alle),
        'okter' => array_map(static fn($o) => [
        'oktId'     => (int) $o['id'],
        'tittel'    => $o['tittel'],
        'naar'      => Booking::norskDato((string) $o['start_tid']),
        'betalt'    => (int) $o['betalt'],
        'reservert' => (int) $o['reservert'],
        'kapasitet' => (int) $o['kapasitet'],
        'ledige'    => Booking::ledigePlasser((int) $o['id']),
    ], $okter),
    ]);
}

$okt = DB::en(
    'SELECT cs.id, cs.start_tid, c.tittel FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id WHERE cs.id = :id',
    ['id' => $oktId]
);
if ($okt === null) {
    Svar::feil('Fant ikke datoen.', 404);
}

$deltakere = DB::alle(
    "SELECT b.id, b.antall, b.status, b.belop_ore, b.created_at, b.folge_medlem,
            b.betalt_maate, b.notat, b.lagt_inn_av,
            COALESCE(m.navn, b.gjest_navn) AS navn,
            COALESCE(m.epost, b.gjest_epost) AS epost,
            COALESCE(m.telefon, b.gjest_telefon) AS telefon,
            p.vipps_reference, p.status AS betalingsstatus
       FROM bookings b
  LEFT JOIN members m ON m.id = b.member_id
  LEFT JOIN payments p ON p.id = b.payment_id
      WHERE b.course_session_id = :id
        AND b.status <> 'avbestilt'
      ORDER BY b.created_at",
    ['id' => $oktId]
);

Svar::json([
    'okt' => [
        'oktId'  => (int) $okt['id'],
        'tittel' => $okt['tittel'],
        'naar'   => Booking::norskDato((string) $okt['start_tid']),
    ],
    'deltakere' => array_map(static fn($d) => [
        'id'        => (int) $d['id'],
        'navn'      => $d['navn'],
        'epost'     => $d['epost'],
        'telefon'   => $d['telefon'],
        'antall'    => (int) $d['antall'],
        'status'    => $d['status'],
        'belop'     => Booking::kroner((int) $d['belop_ore']),
        'referanse' => $d['vipps_reference'],
        'maate'     => $d['vipps_reference'] ? 'Vipps' : ($d['betalt_maate'] ?: '—'),
        'manuell'   => $d['lagt_inn_av'] !== null,
        'notat'     => $d['notat'],
        'folge'     => $d['folge_medlem'],
    ], $deltakere),
]);
