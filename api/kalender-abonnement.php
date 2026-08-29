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
// Endringstida kommer med migrasjon 059. Uten den staar SEQUENCE paa 0, og
// feeden virker som for.
$harEndret = DB::harKolonne('course_sessions', 'updated_at');

// Ledige tider er ikke avtaler.
//
// Paint on Pots og drop-in legges ut automatisk paa hver eneste aapningstid
// (migrasjon 076). Det er tilbud — «her kan noen komme» — ikke noe som skjer.
// Feeden tok med hver av dem, og telefonen til eieren fylte seg med tomme
// oppforinger: 25 Paint on Pots og 17 drop-in i basen her, ingen med
// paameldte. Da druknet de ekte kursene, og kalenderen ble ubrukelig som det
// den er til: aa se hva som faktisk skjer.
//
// Er noen paameldt, staar oekta der — da er den en avtale. Vanlige kurs staar
// uansett: en kursdato som ligger ute er noe verkstedet skal vaere klar til,
// ogsaa foer den foerste melder seg paa.
$utenLedige = DB::harKolonne('course_sessions', 'fra_apningstid')
    ? "AND (cs.fra_apningstid = 0
           OR (SELECT COALESCE(SUM(b2.antall), 0) FROM bookings b2
                WHERE b2.course_session_id = cs.id AND b2.status = 'betalt') > 0)"
    : '';

$okter = DB::alle(
    "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.status,
            " . ($harEndret ? 'cs.updated_at,' : 'NULL AS updated_at,') . "
            c.tittel, c.type, c.tema,
            COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet,
            (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
              WHERE b.course_session_id = cs.id AND b.status = 'betalt') AS pameldte
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
      WHERE cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)
        AND cs.start_tid < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 400 DAY)
        {$utenLedige}
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
    // En hendelse som slutter naar den begynner har ingen varighet, og vises
    // som et streif i kalenderen. Datoer lagret for sluttiden ble tatt vare
    // paa har det — da gjelder samme reserve som for en dato uten sluttid.
    if ($slutt <= $start) {
        $slutt = $start->modify('+3 hours');
    }

    $kapasitet = (int) $o['kapasitet'];
    $om = $pameldte . ' av ' . $kapasitet . ' plasser'
        . ($pameldte >= $kapasitet && $kapasitet > 0 ? ' — fullbooket' : '')
        . ($o['tema'] !== null && $o['tema'] !== '' ? "\n" . $o['tema'] : '');

    // Hvor mange ganger okta er endret.
    //
    // Apple Kalender og Outlook leser SEQUENCE for aa avgjore om en hendelse
    // de alt har er endret. Sto den ikke i det hele tatt, kunne en flyttet
    // kursdato bli staaende paa telefonen slik den var — feeden serveres hel
    // hver gang, saa de fleste tar den likevel, men det er ikke garantert.
    //
    // Tallet er antall halvtimer siden okta ble laget til den ble endret. Det
    // maa bare vokse naar noe endres, og det gjor dette.
    $sekvens = 0;
    $endret = null;
    if ($o['updated_at'] !== null) {
        try {
            $endret = new DateTimeImmutable((string) $o['updated_at'], $utc);
            // Referansepunktet er fast — 1. januar 2020 — slik at tallet ikke
            // hopper nedover om starttida flyttes bakover. Minutter og ikke
            // halvtimer: rettes en okt to ganger paa en halvtime, skal
            // telefonen se begge.
            $null = new DateTimeImmutable('2020-01-01 00:00:00', $utc);
            $sekvens = max(0, (int) floor(($endret->getTimestamp() - $null->getTimestamp()) / 60));
        } catch (Throwable) {
            $sekvens = 0;
        }
    }

    $linjer[] = 'BEGIN:VEVENT';
    // Fast id per okt, slik at en endring oppdaterer hendelsen framfor aa
    // legge til en ny ved siden av den gamle.
    $linjer[] = 'UID:okt-' . (int) $o['id'] . '@lissom.no';
    $linjer[] = 'DTSTAMP:' . $naa;
    $linjer[] = 'SEQUENCE:' . $sekvens;
    if ($endret !== null) {
        $linjer[] = 'LAST-MODIFIED:' . $endret->format('Ymd\THis\Z');
    }
    $linjer[] = 'DTSTART:' . $start->format('Ymd\THis\Z');
    $linjer[] = 'DTEND:' . $slutt->format('Ymd\THis\Z');
    $linjer[] = 'SUMMARY:' . $tekst((string) $o['tittel']);
    $linjer[] = 'DESCRIPTION:' . $tekst($om);
    $linjer[] = 'LOCATION:' . $tekst($sted);
    // Avlyste datoer sendes med, merket avlyst. Uten dem ville de blitt
    // staaende igjen paa telefonen for alltid — feeden sier bare hva som
    // finnes, ikke hva som er borte.
    $linjer[] = 'STATUS:' . ($o['status'] === 'avlyst' ? 'CANCELLED' : 'CONFIRMED');

    // Et varsel dagen for, klokka atten.
    //
    // Dette er en anbefaling til kalenderen, ikke en push fra oss: et
    // abonnement er henting, ikke sending. Telefonen sporr serveren naar den
    // vil, og Apple bestemmer selv hvor ofte. Vi kan ikke faa en melding til
    // aa dukke opp i det oyeblikket noe endres — men vi kan si fra at
    // hendelsen er verdt et varsel.
    $linjer[] = 'BEGIN:VALARM';
    $linjer[] = 'ACTION:DISPLAY';
    $linjer[] = 'TRIGGER:-P1DT0H0M0S';
    $linjer[] = 'DESCRIPTION:' . $tekst((string) $o['tittel'] . ' i morgen');
    $linjer[] = 'END:VALARM';

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
