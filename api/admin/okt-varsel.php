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
 * sender én til dagen foer (eieren 08.10: én SMS per anledning). Paaminnelsen
 * gaar gjennom Paaminnelse (app/lib/paaminnelse.php), felles med cron: samme
 * mottakere (bare betalte, og 14-dagersregelen — kontrolloeren 9. oktober
 * 2026), og oekta tas og meldingene legges i koen i én transaksjon. To klikk,
 * to faner eller cron samtidig gir én utsending; feiler noe midt i, rulles
 * alt tilbake (Codex 9. oktober 2026).
 *
 * «Ny dato paa kurset» gaar til dem som staar paa lista: betalt og aktive
 * reservasjoner (Booking::aktivSql, samme regel som plassene), paa e-post som
 * i pamelding.php. Hver beskjed faar en utsendingsnoekkel (paamelding + ny
 * dato + kanal, migrasjon 270) i samme transaksjon som koeleggingen, saa et
 * nytt trykk eller en annen fane ikke gir samme beskjed to ganger. Bare det
 * som faktisk ble lagt i koen telles.
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

$fornavn = static fn(string $navn): string => Paaminnelse::fornavn($navn);

// Paaminnelsen: de samme mottakerne og feltene som cron (Paaminnelse).
$paaminnelseMottakere = static fn(): array => Paaminnelse::mottakere($oktId);
$paaminnelseNaar = Paaminnelse::naar($oktId, (string) $okt['start_tid'], (string) ($okt['slutt_tid'] ?? ''));

$felterFor = static function (string $mal, array $d) use ($okt, $fraTekst, $tilTekst, $paaminnelseNaar): array {
    if ($mal === 'pamelding_flyttet') {
        return [
            'navn'  => (string) ($d['navn'] ?? ''),
            'kurs'  => (string) $okt['tittel'],
            'fra'   => $fraTekst,
            'til'   => $tilTekst !== '' ? $tilTekst : Booking::norskDato((string) $okt['start_tid']),
            'lenke' => Config::nettsted() . '/min-side',
        ];
    }
    return Paaminnelse::felter($okt, Paaminnelse::navn($d), $paaminnelseNaar, Paaminnelse::sted());
};

// Malen slik den staar, ogsaa naar den er av (da gaar ingenting ut).
$malRad = static fn(string $navn): ?array
    => DB::en('SELECT * FROM notification_templates WHERE navn = :n', ['n' => $navn]);

// Hvem malen naar fram til. «Ny dato paa kurset» gaar paa e-post, som i
// pamelding.php (flytting av én person) — derfor ingen telefon her, og
// Innstillinger › Meldinger tilbyr ikke SMS paa den (meldinger.php).
$mottakerFor = static function (string $mal, array $d) use ($okt): array {
    if ($mal === 'kurspaaminnelse') {
        return Paaminnelse::mottaker($okt, $d);
    }
    $epost = trim((string) ($d['epost'] ?? ''));
    return [
        'navn'    => (string) ($d['navn'] ?? ''),
        'epost'   => $epost !== '' ? $epost : null,
        'telefon' => null,
    ];
};
$veiFor = static fn(?array $m, array $mottaker): array => Paaminnelse::veier($m, $mottaker);
$navnPaa = static fn(string $mal, array $d): string
    => $mal === 'kurspaaminnelse' ? Paaminnelse::navn($d) : (string) ($d['navn'] ?? '');
$iOsloTid = static fn(?string $u): string => ($u !== null && $u !== '') ? Booking::norskDato($u) : '';

if ($handling === 'forhandsvis') {
    $mal = Foresporsel::tekst('mal');
    if (!in_array($mal, ['pamelding_flyttet', 'kurspaaminnelse'], true)) {
        Svar::feil('Ukjent mal.');
    }
    $m = $malRad($mal);
    $liste = $mal === 'kurspaaminnelse' ? $paaminnelseMottakere() : $deltakere;
    $forste = $liste[0] ?? ['navn' => '', 'gjest_navn' => ''];
    $f = $felterFor($mal, $forste);
    $f['navn'] = $fornavn((string) ($f['navn'] ?? ''));
    $f['fornavn'] = $f['fornavn'] ?? $f['navn'];
    $epostN = 0;
    $smsN = 0;
    $naas = 0;
    $ikkeNaadd = [];
    foreach ($liste as $d) {
        $v = $veiFor($m, $mottakerFor($mal, $d));
        $epostN += (int) $v['epost'];
        $smsN += (int) $v['sms'];
        if ($v['epost'] || $v['sms']) {
            $naas++;
        } else {
            $ikkeNaadd[] = $navnPaa($mal, $d);
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
        'antall'     => count($liste),
        // Paaminnelsen: de paa lista som ikke faar den (ikke betalt, eller
        // paameldt for under 14 dager siden), saa tallet ikke ser feil ut.
        'ikkeMed'    => $mal === 'kurspaaminnelse' ? max(0, count($deltakere) - count($liste)) : 0,
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
    // Utsendingsnoekkelen (migrasjon 270). Uten tabellen sendes det som foer,
    // men fortsatt atomisk og laast per dato.
    $harNokler = DB::harTabell('varsel_utsendinger');
    $sendt = 0;
    $alt = 0;
    $ikkeNaadd = [];
    $pdo = DB::kobling();
    $pdo->beginTransaction();
    try {
        // Laas datoen: to faner samtidig venter paa hverandre, og noekkelen
        // gjelder datoen slik den staar naa (etter flyttingen).
        $start = (string) DB::verdi('SELECT start_tid FROM course_sessions WHERE id = :i FOR UPDATE', ['i' => $oktId]);
        foreach ($deltakere as $d) {
            $mottaker = $mottakerFor('pamelding_flyttet', $d);
            $v = $veiFor($m, $mottaker);
            if (!$v['epost'] && !$v['sms']) {
                $ikkeNaadd[] = (string) $d['navn'];
                continue;
            }
            if ($harNokler) {
                $nye = 0;
                foreach (['epost', 'sms'] as $k) {
                    if ($v[$k]) {
                        $nye += DB::kjor(
                            'INSERT IGNORE INTO varsel_utsendinger (nokkel) VALUES (:n)',
                            ['n' => 'flyttet:' . (int) $d['id'] . ':' . $start . ':' . $k]
                        )->rowCount();
                    }
                }
                if ($nye === 0) {
                    $alt++;
                    continue;
                }
            }
            $lagt = Varsel::mal('pamelding_flyttet', $mottaker, $felterFor('pamelding_flyttet', $d), 'booking', (int) $d['id']);
            if ($lagt > 0) {
                $sendt++;
            } else {
                $ikkeNaadd[] = (string) $d['navn'];
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    revider('okt_flyttet_varslet', 'course_session', $oktId, ['sendt' => $sendt, 'alt' => $alt, 'fra' => $fraRaa]);
    Svar::ok(['sendt' => $sendt, 'alleredeSendt' => $alt, 'uten' => count($ikkeNaadd), 'ikkeNaadd' => $ikkeNaadd]);
}

if ($handling === 'paaminnelse') {
    if ((string) $okt['status'] === 'avlyst') {
        Svar::feil('Datoen er avlyst.');
    }
    $m = $malRad('kurspaaminnelse');
    if ($m === null || (int) $m['aktiv'] !== 1) {
        Svar::feil('Meldingen «Påminnelse før kurset» er slått av under Innstillinger › Meldinger. Ingen fikk påminnelse.', 409);
    }
    // Ta datoen og legg i koen i én transaksjon (Paaminnelse::send). Treffer
    // ikke UPDATE-en, har noen (et klikk til, en annen fane eller cron) alt
    // sendt den. Gikk ingenting ut, rulles det tilbake: cron kan sende sin.
    $r = Paaminnelse::send($okt, true);
    if (!$r['tatt']) {
        $naar = (string) DB::verdi('SELECT paaminnelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $oktId]);
        Svar::feil('Påminnelsen er alt sendt ' . $iOsloTid($naar) . '.', 409);
    }
    revider('paaminnelse_sendt_manuelt', 'course_session', $oktId, ['sendt' => $r['sendt']]);
    if ($r['sendt'] === 0) {
        Svar::feil('Ingen av de påmeldte har e-post eller telefon som påminnelsen kan sendes til.', 409);
    }
    Svar::ok([
        'sendt'     => $r['sendt'],
        'uten'      => count($r['ikkeNaadd']),
        'ikkeNaadd' => $r['ikkeNaadd'],
        'sendtAt'   => $iOsloTid($r['sendtAt']),
    ]);
}

Svar::feil('Ukjent handling.');
