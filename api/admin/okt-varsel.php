<?php
/**
 * Beskjed til alle paameldte paa én dato, med de malene som finnes fra foer.
 * Brukes av den nye adminen (/ny-admin, Kalender og Kurs).
 *
 *   POST handling=forhandsvis { oktId, mal: pamelding_flyttet|kurspaaminnelse, fra?, til? }
 *        → { aktiv, kanal, emne, tekst, epost, sms, antall }   ingenting sendes
 *   POST handling=flyttet     { oktId, fra }   «Ny dato paa kurset» til hver paameldt
 *   POST handling=paaminnelse { oktId }        «Paaminnelse foer kurset» naa
 *
 * ── Hvorfor et eget endepunkt ────────────────────────────────────────
 *
 * kurs.php «endredato» flytter datoen, men sier selv at de paameldte ikke
 * faar beskjed («send den under Påmeldte»). Malen «pamelding_flyttet» fantes
 * bare for én person om gangen (pamelding.php «flytt»). Paaminnelsen gikk
 * bare fra cron dagen foer. Her sendes de samme malene, med de samme feltene
 * og de samme reglene (e-post for flytting som i pamelding.php, SMS paa
 * paaminnelsen bare naar kurset har det paa, som i bin/cron.php). Ingen nye
 * tekster: malen eieren har skrevet under Maler er det som gaar ut.
 *
 * En paaminnelse sendt for haand setter paaminnelse_sendt_at, saa cron ikke
 * sender én til dagen foer (eieren 08.10: én SMS per anledning).
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
krev_admin();

$handling = Foresporsel::tekst('handling');
$oktId = Foresporsel::heltall('oktId');

$smsKol = DB::harKolonne('courses', 'sms_paaminnelse') ? 'c.sms_paaminnelse' : '0 AS sms_paaminnelse';
$okt = DB::en(
    "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.status, c.tittel, {$smsKol}
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
      WHERE cs.id = :i",
    ['i' => $oktId]
);
if ($okt === null) {
    Svar::feil('Fant ikke datoen.', 404);
}

$deltakere = DB::alle(
    "SELECT b.id, COALESCE(m.navn, b.gjest_navn) AS navn,
            COALESCE(m.epost, b.gjest_epost) AS epost,
            COALESCE(m.telefon, b.gjest_telefon) AS telefon
       FROM bookings b
  LEFT JOIN members m ON m.id = b.member_id
      WHERE b.course_session_id = :o AND b.status IN ('betalt','reservert')
   ORDER BY b.id",
    ['o' => $oktId]
);

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$iOslo = static fn(string $u, string $f): string => (new DateTimeImmutable($u, $utc))->setTimezone($oslo)->format($f);

// «fra» kommer som 2026-10-20 18:00 norsk tid (det skjermen viste foer flyttingen).
$fraTekst = '';
$fraRaa = trim(Foresporsel::tekst('fra'));
if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $fraRaa) === 1) {
    $fraTekst = Booking::norskDato((new DateTimeImmutable($fraRaa, $oslo))->setTimezone($utc)->format('Y-m-d H:i:s'));
}

// Forhaandsvisningen foer flyttingen: «til» er den nye tida (norsk tid), saa
// teksten viser det deltakeren faktisk faar. Ved utsending er datoen alt flyttet.
$tilTekst = '';
$tilRaa = trim(Foresporsel::tekst('til'));
if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $tilRaa) === 1) {
    $tilTekst = Booking::norskDato((new DateTimeImmutable($tilRaa, $oslo))->setTimezone($utc)->format('Y-m-d H:i:s'));
}
if ($fraTekst === '' && Foresporsel::tekst('handling') === 'forhandsvis') {
    $fraTekst = Booking::norskDato((string) $okt['start_tid']);
}

$fornavn = static function (string $navn): string {
    $biter = preg_split('/\s+/u', trim($navn)) ?: [];
    return $biter === [] ? '' : (string) $biter[0];
};

// Naar kurset er: samlingene hvis det gaar over flere dager, ellers dagen og
// klokkeslettet. Samme setning som paaminnelsen fra cron (paaminnelse_naar).
$naar = (static function () use ($okt, $iOslo): string {
    $samlinger = DB::harTabell('okt_samlinger') ? Samlinger::forOkt((int) $okt['id']) : [];
    if (count($samlinger) > 1) {
        $l = [];
        foreach ($samlinger as $i => $s) {
            $l[] = 'Dag ' . ((int) ($s['nummer'] ?: $i + 1)) . ': ' . $s['naar'];
        }
        return implode("\n", $l);
    }
    if (count($samlinger) === 1) {
        return (string) $samlinger[0]['naar'];
    }
    $linje = Booking::norskDato((string) $okt['start_tid']);
    if ((string) ($okt['slutt_tid'] ?? '') !== '') {
        $linje .= '–' . $iOslo((string) $okt['slutt_tid'], 'H:i');
    }
    return $linje;
})();
$ukedagDato = (static function () use ($okt, $iOslo): string {
    $dager = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
    $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];
    $d = (int) $iOslo((string) $okt['start_tid'], 'N');
    return $dager[$d - 1] . ' ' . (int) $iOslo((string) $okt['start_tid'], 'j') . '. ' . $mnd[(int) $iOslo((string) $okt['start_tid'], 'n') - 1];
})();
$sted = trim((string) Config::hent('verksted_adresse', 'Lissom Keramikk & Håndverk, Teie'));

$felterFor = static function (string $mal, array $d) use ($okt, $fraTekst, $tilTekst, $naar, $ukedagDato, $sted, $iOslo, $fornavn): array {
    $navn = (string) ($d['navn'] ?? '');
    if ($mal === 'pamelding_flyttet') {
        return [
            'navn'  => $navn,
            'kurs'  => (string) $okt['tittel'],
            'fra'   => $fraTekst,
            'til'   => $tilTekst !== '' ? $tilTekst : Booking::norskDato((string) $okt['start_tid']),
            'lenke' => Config::nettsted() . '/min-side',
        ];
    }
    return [
        'navn'    => $navn,
        'fornavn' => $fornavn($navn),
        'kurs'    => (string) $okt['tittel'],
        'tid'     => $iOslo((string) $okt['start_tid'], 'H:i'),
        'naar'    => $naar,
        'dato'    => $ukedagDato,
        'sted'    => $sted,
    ];
};

if ($handling === 'forhandsvis') {
    $mal = Foresporsel::tekst('mal');
    if (!in_array($mal, ['pamelding_flyttet', 'kurspaaminnelse'], true)) {
        Svar::feil('Ukjent mal.');
    }
    $m = DB::en('SELECT emne, tekst, kanal, aktiv FROM notification_templates WHERE navn = :n', ['n' => $mal]);
    $forste = $deltakere[0] ?? ['navn' => ''];
    $f = $felterFor($mal, $forste);
    $f['navn'] = $fornavn((string) ($f['navn'] ?? ''));
    $f['fornavn'] = $f['fornavn'] ?? $f['navn'];
    $medSms = $mal === 'kurspaaminnelse' && (int) $okt['sms_paaminnelse'] === 1;
    Svar::ok([
        'aktiv'  => $m !== null && (int) $m['aktiv'] === 1,
        'kanal'  => (string) ($m['kanal'] ?? ''),
        'emne'   => $m !== null ? Varsel::flett((string) $m['emne'], $f) : '',
        'tekst'  => $m !== null ? Varsel::flett((string) $m['tekst'], $f) : '',
        'antall' => count($deltakere),
        'epost'  => count(array_filter($deltakere, static fn($d) => trim((string) $d['epost']) !== '')),
        'sms'    => $mal === 'pamelding_flyttet' ? 0
                    : ($medSms ? count(array_filter($deltakere, static fn($d) => trim((string) $d['telefon']) !== '')) : 0),
    ]);
}

if ($handling === 'flyttet') {
    $sendt = 0;
    foreach ($deltakere as $d) {
        if (trim((string) $d['epost']) === '') {
            continue;
        }
        Varsel::mal('pamelding_flyttet', ['epost' => trim((string) $d['epost'])],
            $felterFor('pamelding_flyttet', $d), 'booking', (int) $d['id']);
        $sendt++;
    }
    revider('okt_flyttet_varslet', 'course_session', $oktId, ['sendt' => $sendt, 'fra' => $fraRaa]);
    Svar::ok(['sendt' => $sendt, 'uten' => count($deltakere) - $sendt]);
}

if ($handling === 'paaminnelse') {
    if ((string) $okt['status'] === 'avlyst') {
        Svar::feil('Datoen er avlyst.');
    }
    $sendt = 0;
    foreach ($deltakere as $d) {
        $epost = trim((string) $d['epost']);
        $tlf = (int) $okt['sms_paaminnelse'] === 1 ? trim((string) $d['telefon']) : '';
        if ($epost === '' && $tlf === '') {
            continue;
        }
        Varsel::mal('kurspaaminnelse', [
            'epost'   => $epost !== '' ? $epost : null,
            'telefon' => $tlf !== '' ? $tlf : null,
        ], $felterFor('kurspaaminnelse', $d), 'course_session', $oktId);
        $sendt++;
    }
    if (DB::harKolonne('course_sessions', 'paaminnelse_sendt_at')) {
        DB::oppdater('course_sessions', ['paaminnelse_sendt_at' => gmdate('Y-m-d H:i:s')], ['id' => $oktId]);
    }
    revider('paaminnelse_sendt_manuelt', 'course_session', $oktId, ['sendt' => $sendt]);
    Svar::ok(['sendt' => $sendt, 'uten' => count($deltakere) - $sendt]);
}

Svar::feil('Ukjent handling.');
