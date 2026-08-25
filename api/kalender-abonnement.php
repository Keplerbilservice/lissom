<?php
/**
 * Programmet som kalenderabonnement.
 *
 *   /api/kalender-abonnement.php?nokkel=...
 *
 * iPhone, Android og Outlook kan abonnere paa en adresse som gir ut
 * text/calendar, og henter den paa nytt av seg selv. Da staar kursene i
 * kalenderen paa telefonen uten at noen skriver dem inn to steder — og en
 * dato som avlyses i admin blir borte fra telefonen ved neste oppdatering.
 *
 * ── Om noekkelen ──────────────────────────────────────────────────────
 *
 * Telefonen sender ingen innlogging naar den henter feeden. Den kjenner
 * bare adressen. Derfor ligger tilgangen i selve adressen, som en lang
 * tilfeldig noekkel — det er slik Google, Outlook og de andre gjor det.
 *
 * Det betyr ogsaa: den som har adressen, har programmet. Deles den videre,
 * deles programmet. Noekkelen kan byttes fra admin, og da slutter alle gamle
 * adresser aa virke paa én gang.
 *
 * Noekkelen er ikke den samme som cron_nokkel. Byttes den ene, skal ikke den
 * andre slutte aa virke.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$rett = trim((string) Config::hent('kalender_nokkel', ''));
$gitt = Foresporsel::tekst('nokkel');

// 404 og ikke 403: vi bekrefter ikke at feeden finnes for den som gjetter.
if ($rett === '' || $gitt === '' || !hash_equals($rett, $gitt)) {
    Svar::feil('Fant ikke siden.', 404);
}

$utc = new DateTimeZone('UTC');

// Det som ligger foran oss, og litt bak — en kalender uten forrige uke er
// vanskelig aa kjenne seg igjen i.
$okter = DB::alle(
    "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.status,
            c.tittel, c.type, c.tema,
            COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet,
            (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
              WHERE b.course_session_id = cs.id AND b.status = 'betalt') AS pameldte
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
      WHERE cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)
        AND cs.start_tid < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 400 DAY)
   ORDER BY cs.start_tid"
);

/** Tekst slik iCalendar vil ha den: komma, semikolon og linjeskift roemmes. */
$tekst = static function (string $s): string {
    return str_replace(
        ['\\', "\r\n", "\n", ',', ';'],
        ['\\\\', '\\n', '\\n', '\\,', '\;'],
        trim($s)
    );
};

/**
 * Bretting.
 *
 * Standarden tillater 75 oktetter per linje, og brekker vi feil, faller
 * norske tegn fra hverandre. Derfor telles bytes, og vi bryter aldri midt i
 * et tegn.
 */
$brett = static function (string $linje): string {
    if (strlen($linje) <= 73) {
        return $linje . "\r\n";
    }
    $ut = '';
    $igjen = $linje;
    $forst = true;
    while ($igjen !== '') {
        $maks = $forst ? 73 : 72;
        if (strlen($igjen) <= $maks) {
            $ut .= ($forst ? '' : ' ') . $igjen . "\r\n";
            break;
        }
        $bit = substr($igjen, 0, $maks);
        // Ikke midt i et UTF-8-tegn: trekk tilbake til en tegngrense.
        while ($bit !== '' && (ord($igjen[strlen($bit)]) & 0xC0) === 0x80) {
            $bit = substr($bit, 0, -1);
        }
        $ut .= ($forst ? '' : ' ') . $bit . "\r\n";
        $igjen = substr($igjen, strlen($bit));
        $forst = false;
    }
    return $ut;
};

$naa = gmdate('Ymd\THis\Z');
$sted = trim((string) Config::hent('verksted_adresse', 'Lissom Keramikk & Håndverk, Teie'));

$linjer = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Lissom Keramikk//Program//NO',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-WR-CALNAME:Lissom — program',
    'X-WR-TIMEZONE:Europe/Oslo',
    // Hvor ofte telefonen bor hente paa nytt. Begge staar: den ene er
    // standarden, den andre er den Apple og Outlook faktisk leser.
    'REFRESH-INTERVAL;VALUE=DURATION:PT2H',
    'X-PUBLISHED-TTL:PT2H',
];

foreach ($okter as $o) {
    $type     = (string) $o['type'];
    $pameldte = (int) $o['pameldte'];

    // Samme regel som Program paa Oversikt: en drop-in ingen har meldt seg
    // paa er en aapen dor, ikke en avtale. Kurs og samlinger staar uansett.
    if ($type === 'dropin' && $pameldte === 0) {
        continue;
    }

    $start = new DateTimeImmutable((string) $o['start_tid'], $utc);
    $slutt = $o['slutt_tid'] !== null
        ? new DateTimeImmutable((string) $o['slutt_tid'], $utc)
        : $start->modify('+3 hours');

    $kapasitet = (int) $o['kapasitet'];
    $om = $pameldte . ' av ' . $kapasitet . ' plasser'
        . ($pameldte >= $kapasitet && $kapasitet > 0 ? ' — fullbooket' : '')
        . ($o['tema'] !== null && $o['tema'] !== '' ? "\n" . $o['tema'] : '');

    $linjer[] = 'BEGIN:VEVENT';
    // Fast id per okt, slik at en endring oppdaterer hendelsen framfor aa
    // legge til en ny ved siden av den gamle.
    $linjer[] = 'UID:okt-' . (int) $o['id'] . '@lissom.no';
    $linjer[] = 'DTSTAMP:' . $naa;
    $linjer[] = 'DTSTART:' . $start->format('Ymd\THis\Z');
    $linjer[] = 'DTEND:' . $slutt->format('Ymd\THis\Z');
    $linjer[] = 'SUMMARY:' . $tekst((string) $o['tittel']);
    $linjer[] = 'DESCRIPTION:' . $tekst($om);
    $linjer[] = 'LOCATION:' . $tekst($sted);
    // Avlyste datoer sendes med, merket avlyst. Uten dem ville de blitt
    // staaende igjen paa telefonen for alltid — feeden sier bare hva som
    // finnes, ikke hva som er borte.
    $linjer[] = 'STATUS:' . ($o['status'] === 'avlyst' ? 'CANCELLED' : 'CONFIRMED');
    // Ingen LAST-MODIFIED: okter har ingen endringstid i basen. Feeden
    // serveres hel hver gang, saa klienten ser endringen uansett.
    $linjer[] = 'END:VEVENT';
}

$linjer[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="lissom-program.ics"');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

foreach ($linjer as $l) {
    echo $brett($l);
}
