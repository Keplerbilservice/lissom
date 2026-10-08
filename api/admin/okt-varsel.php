<?php
/**
 * Beskjed til alle paameldte paa én dato, med de malene som finnes fra foer.
 * Brukes av den nye adminen (/ny-admin, Kalender og Kurs).
 *
 *   POST handling=forhandsvis { oktId, mal: pamelding_flyttet|kurspaaminnelse, fra?, til? }
 *        → { aktiv, kanal, emne, tekst, epostTekst, smsTekst, epost, sms, antall, naas,
 *            ikkeNaadd: [navn], paaminnelseSendt }   ingenting sendes
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
 * sender én til dagen foer (eieren 08.10: én SMS per anledning). Feltet tas
 * med én UPDATE … WHERE paaminnelse_sendt_at IS NULL foer noe legges i koen
 * (to klikk, to faner eller cron samtidig gir bare én utsending), og slippes
 * igjen hvis ingenting gikk ut — da kan cron fortsatt sende sin.
 *
 * Mottakerne er de som staar paa lista: betalt og aktive reservasjoner
 * (Booking::aktivSql, samme regel som plassene). Malens kanal avgjoer om det
 * blir e-post, SMS eller begge, og bare det som faktisk ble lagt i koen telles.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
krev_admin();

$handling = Foresporsel::tekst('handling');
$oktId = Foresporsel::heltall('oktId');

$smsKol = DB::harKolonne('courses', 'sms_paaminnelse') ? 'c.sms_paaminnelse' : '0 AS sms_paaminnelse';
$harSendtKol = DB::harKolonne('course_sessions', 'paaminnelse_sendt_at');
$sendtKol = $harSendtKol ? 'cs.paaminnelse_sendt_at' : 'NULL AS paaminnelse_sendt_at';
$okt = DB::en(
    "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.status, c.tittel, {$smsKol}, {$sendtKol}
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
      WHERE b.course_session_id = :o AND " . Booking::aktivSql('b') . "
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

// Malen slik den staar, ogsaa naar den er av (da gaar ingenting ut).
$malRad = static fn(string $navn): ?array
    => DB::en('SELECT * FROM notification_templates WHERE navn = :n', ['n' => $navn]);

// Hvem malen naar fram til, og hvordan. Samme regler som Varsel::mal():
// kanalen paa malen, SMS bare naar SMS er satt opp, og en ren SMS-mal faller
// tilbake til e-post. Paaminnelsen faar telefonen bare naar kurset har
// SMS-paaminnelse paa (som i bin/cron.php).
$mottakerFor = static function (string $mal, array $d) use ($okt): array {
    $epost = trim((string) ($d['epost'] ?? ''));
    $tlf = trim((string) ($d['telefon'] ?? ''));
    if ($mal === 'kurspaaminnelse' && (int) $okt['sms_paaminnelse'] !== 1) {
        $tlf = '';
    }
    return [
        'navn'    => (string) ($d['navn'] ?? ''),
        'epost'   => $epost !== '' ? $epost : null,
        'telefon' => $tlf !== '' ? $tlf : null,
    ];
};
$veiFor = static function (?array $m, array $mottaker): array {
    if ($m === null || (int) ($m['aktiv'] ?? 0) !== 1) {
        return ['epost' => false, 'sms' => false];
    }
    $kanal = (string) $m['kanal'];
    $harEpost = !empty($mottaker['epost']) && filter_var((string) $mottaker['epost'], FILTER_VALIDATE_EMAIL) !== false;
    $harSms = !empty($mottaker['telefon']) && Varsel::smsMulig() && normaliser_telefon((string) $mottaker['telefon']) !== '';
    $e = in_array($kanal, ['epost', 'epost_sms'], true) && $harEpost;
    $s = in_array($kanal, ['sms', 'epost_sms'], true) && $harSms;
    if (!$e && !$s && $kanal === 'sms' && $harEpost) {
        $e = true;
    }
    return ['epost' => $e, 'sms' => $s];
};
$iOsloTid = static fn(?string $u): string => ($u !== null && $u !== '') ? Booking::norskDato($u) : '';

if ($handling === 'forhandsvis') {
    $mal = Foresporsel::tekst('mal');
    if (!in_array($mal, ['pamelding_flyttet', 'kurspaaminnelse'], true)) {
        Svar::feil('Ukjent mal.');
    }
    $m = $malRad($mal);
    $forste = $deltakere[0] ?? ['navn' => ''];
    $f = $felterFor($mal, $forste);
    $f['navn'] = $fornavn((string) ($f['navn'] ?? ''));
    $f['fornavn'] = $f['fornavn'] ?? $f['navn'];
    $epostN = 0;
    $smsN = 0;
    $naas = 0;
    $ikkeNaadd = [];
    foreach ($deltakere as $d) {
        $v = $veiFor($m, $mottakerFor($mal, $d));
        $epostN += (int) $v['epost'];
        $smsN += (int) $v['sms'];
        if ($v['epost'] || $v['sms']) {
            $naas++;
        } else {
            $ikkeNaadd[] = (string) $d['navn'];
        }
    }
    // Det som faktisk gaar ut: e-posten i det felles oppsettet naar malen har
    // det (migrasjon 227), ellers malens tekst. SMS-en er malens tekst.
    $smsTekst = $m !== null ? Varsel::flett((string) $m['tekst'], $f) : '';
    $epostTekst = $smsTekst;
    if ($m !== null && Varsel::harOppsett($m)) {
        $epostTekst = (string) Varsel::oppsett($m, $f, (string) ($m['gruppe'] ?? 'system'))[0];
    }
    Svar::ok([
        'aktiv'      => $m !== null && (int) $m['aktiv'] === 1,
        'kanal'      => (string) ($m['kanal'] ?? ''),
        'emne'       => $m !== null ? Varsel::flett((string) $m['emne'], $f) : '',
        'tekst'      => $epostN > 0 ? $epostTekst : $smsTekst,
        'epostTekst' => $epostTekst,
        'smsTekst'   => $smsTekst,
        'antall'     => count($deltakere),
        'naas'       => $naas,
        'epost'      => $epostN,
        'sms'        => $smsN,
        'ikkeNaadd'  => $ikkeNaadd,
        'paaminnelseSendt' => $iOsloTid($okt['paaminnelse_sendt_at'] ?? null),
    ]);
}

if ($handling === 'flyttet') {
    $m = $malRad('pamelding_flyttet');
    if ($m === null || (int) $m['aktiv'] !== 1) {
        Svar::feil('Meldingen «Ny dato på kurset» er slått av under Innstillinger › Meldinger. Ingen fikk beskjed.', 409);
    }
    $sendt = 0;
    $ikkeNaadd = [];
    foreach ($deltakere as $d) {
        $mottaker = $mottakerFor('pamelding_flyttet', $d);
        $v = $veiFor($m, $mottaker);
        $lagt = ($v['epost'] || $v['sms'])
            ? Varsel::mal('pamelding_flyttet', $mottaker, $felterFor('pamelding_flyttet', $d), 'booking', (int) $d['id'])
            : 0;
        if ($lagt > 0) {
            $sendt++;
        } else {
            $ikkeNaadd[] = (string) $d['navn'];
        }
    }
    revider('okt_flyttet_varslet', 'course_session', $oktId, ['sendt' => $sendt, 'fra' => $fraRaa]);
    Svar::ok(['sendt' => $sendt, 'uten' => count($ikkeNaadd), 'ikkeNaadd' => $ikkeNaadd]);
}

if ($handling === 'paaminnelse') {
    if ((string) $okt['status'] === 'avlyst') {
        Svar::feil('Datoen er avlyst.');
    }
    $m = $malRad('kurspaaminnelse');
    if ($m === null || (int) $m['aktiv'] !== 1) {
        Svar::feil('Meldingen «Påminnelse før kurset» er slått av under Innstillinger › Meldinger. Ingen fikk påminnelse.', 409);
    }
    // Ta datoen foer noe legges i koen. Treffer ikke UPDATE-en, har noen
    // (et klikk til, en annen fane eller cron) alt sendt den.
    $tatt = null;
    if ($harSendtKol) {
        $tatt = gmdate('Y-m-d H:i:s');
        $rader = DB::kjor(
            'UPDATE course_sessions SET paaminnelse_sendt_at = :t WHERE id = :i AND paaminnelse_sendt_at IS NULL',
            ['t' => $tatt, 'i' => $oktId]
        )->rowCount();
        if ($rader !== 1) {
            $naar = (string) DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $oktId]);
            Svar::feil('Påminnelsen er alt sendt ' . $iOsloTid($naar) . '.', 409);
        }
    }
    $sendt = 0;
    $ikkeNaadd = [];
    try {
        foreach ($deltakere as $d) {
            $mottaker = $mottakerFor('kurspaaminnelse', $d);
            $v = $veiFor($m, $mottaker);
            $lagt = ($v['epost'] || $v['sms'])
                ? Varsel::mal('kurspaaminnelse', $mottaker, $felterFor('kurspaaminnelse', $d), 'course_session', $oktId)
                : 0;
            if ($lagt > 0) {
                $sendt++;
            } else {
                $ikkeNaadd[] = (string) $d['navn'];
            }
        }
    } finally {
        // Gikk ingenting ut, slippes datoen igjen: cron skal fortsatt kunne
        // sende sin dagen foer.
        if ($tatt !== null && $sendt === 0) {
            DB::kjor(
                'UPDATE course_sessions SET paaminnelse_sendt_at = NULL WHERE id = :i AND paaminnelse_sendt_at = :t',
                ['i' => $oktId, 't' => $tatt]
            );
        }
    }
    revider('paaminnelse_sendt_manuelt', 'course_session', $oktId, ['sendt' => $sendt]);
    if ($sendt === 0) {
        Svar::feil('Ingen av de påmeldte har e-post eller telefon som påminnelsen kan sendes til.', 409);
    }
    Svar::ok([
        'sendt'     => $sendt,
        'uten'      => count($ikkeNaadd),
        'ikkeNaadd' => $ikkeNaadd,
        'sendtAt'   => $tatt !== null ? $iOsloTid($tatt) : '',
    ]);
}

Svar::feil('Ukjent handling.');
