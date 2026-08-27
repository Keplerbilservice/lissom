<?php
/**
 * Deltakerlista, til utskrift eller til aa ta med i verkstedet.
 *
 *   /api/admin/deltakerliste.php?okt=<id>      én dato
 *   /api/admin/deltakerliste.php?kurs=<id>     alle datoene paa ett kurs
 *   /api/admin/deltakerliste.php               alt framover
 *
 * Knappen «Last ned liste» aapnet en dialog som beskrev «PDF eller Excel» og
 * lukket seg igjen naar man trykket «Last ned». Ingenting ble lastet ned.
 * Verkstedet maatte skrive av navnene fra skjermen.
 *
 * Semikolon som skilletegn og BOM foran — det er det norsk Excel forventer.
 * Komma gir alt i én kolonne, og uten BOM blir «ø» til «Ã¸».
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
krev_admin();

$oktId  = Foresporsel::heltall('okt');
$kursId = Foresporsel::heltall('kurs');

$hvor = ["b.status IN ('betalt','reservert')"];
$param = [];
if ($oktId > 0) {
    $hvor[] = 'cs.id = :okt';
    $param['okt'] = $oktId;
} elseif ($kursId > 0) {
    $hvor[] = 'c.id = :kurs';
    $param['kurs'] = $kursId;
} else {
    // Uten utvalg: det som ligger framfor oss. En liste over alt som har
    // vaert er sjelden det man staar og trenger.
    $hvor[] = 'cs.start_tid >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)';
}

// Allergikolonnen kommer med migrasjon 057. Lista skal virke uten den.
$harAllergi = DB::harKolonne('bookings', 'allergier');

$rader = DB::alle(
    'SELECT c.tittel, cs.start_tid, b.antall, b.status, b.belop_ore, b.folge_medlem,
            ' . ($harAllergi ? 'b.allergier,' : "'' AS allergier,") . '
            COALESCE(m.navn, b.gjest_navn) AS navn,
            COALESCE(m.epost, b.gjest_epost) AS epost,
            COALESCE(m.telefon, b.gjest_telefon) AS telefon
       FROM bookings b
       JOIN course_sessions cs ON cs.id = b.course_session_id
       JOIN courses c ON c.id = cs.course_id
  LEFT JOIN members m ON m.id = b.member_id
      WHERE ' . implode(' AND ', $hvor) . '
   ORDER BY cs.start_tid, navn',
    $param
);

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$navnPaaFil = $oktId > 0 ? 'okt-' . $oktId : ($kursId > 0 ? 'kurs-' . $kursId : 'framover');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="lissom-deltakere-' . $navnPaaFil . '.csv"');
header('Cache-Control: no-store');

$ut = fopen('php://output', 'wb');
fwrite($ut, "\xEF\xBB\xBF");   // BOM, ellers viser Excel ae/oe/aa feil

// Allergier staar med paa lista som tas med inn i verkstedet. Det er der
// den trengs — den som holder kurset maa vite det for kurset begynner, ikke
// etterpaa. Lista er bak innlogging som admin, og skal ikke deles videre.
fputcsv($ut, ['Kurs', 'Dato', 'Klokkeslett', 'Navn', 'E-post', 'Telefon',
              'Plasser', 'Betalt', 'Beløp', 'Følge med medlem',
              'Allergier og viktig informasjon'], ';', '"', '');

$kr = static fn(int $ore): string => number_format($ore / 100, 2, ',', '');

foreach ($rader as $r) {
    $tid = (new DateTimeImmutable((string) $r['start_tid'], $utc))->setTimezone($oslo);
    fputcsv($ut, [
        (string) $r['tittel'],
        $tid->format('d.m.Y'),
        $tid->format('H:i'),
        (string) ($r['navn'] ?? ''),
        (string) ($r['epost'] ?? ''),
        (string) ($r['telefon'] ?? ''),
        (int) $r['antall'],
        $r['status'] === 'betalt' ? 'Ja' : 'Nei',
        $kr((int) $r['belop_ore']),
        (string) ($r['folge_medlem'] ?? ''),
        (string) ($r['allergier'] ?? ''),
    ], ';', '"', '');
}

if ($rader === []) {
    fputcsv($ut, ['Ingen påmeldte i dette utvalget.'], ';', '"', '');
}

fclose($ut);
