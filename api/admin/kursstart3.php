<?php
/**
 * «Start kurset» i fire steg (kalenderplanen, bølge 2; «Deltakerne» først,
 * eieren 7. oktober 2026) — det veilederen trenger
 * for én økt, og Vipps-kravet.
 *
 *   GET  ?okt=7                deltakerne med det som står igjen og Vipps-kravet,
 *                              og statusen til e-postene etter kurset (bare lesing)
 *   POST handling=qr           { bookingId } QR-kode for det som står igjen (KursstartKrav::visQr)
 *   POST handling=kursbevis-sms { okt, paa } «Send også på SMS» for én økt (migrasjon 259)
 *   POST handling=krav         avvist: «Send Vipps-krav» er slått av (eieren, 3. oktober 2026)
 *
 * Tekstene (de fem kortene) leses fortsatt fra api/admin/kursstart.php, som
 * ikke rører penger. Alt her står bak bryteren «Vis/kursstart3» (content_blocks,
 * mangler raden = av). Kontant går som før til api/admin/kursbetaling.php.
 *
 * Eieren, 3. oktober 2026: Vipps nekter salgsenheten å sende krav
 * (PUSH_MESSAGE). Kravet er slått av her; i stedet «Vis QR-kode» per
 * deltaker (ePayment med userFlow QR). Krav som alt finnes, følges fortsatt
 * opp (statusen under, stopp ved kontant, webhooken). Pengelogikken står i
 * app/lib/kursstartkrav.php.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (!KursstartKrav::paa()) {
    Svar::feil('«Start kurset» i tre steg er ikke slått på.', 403);
}

if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();
    $handling = Foresporsel::tekst('handling');
    if ($handling === 'krav') {
        Svar::feil('«Send Vipps-krav» er slått av. Bruk «Vis QR-kode» eller kontant.', 409);
    }
    // Eieren, 8. oktober 2026: kursbeviset også på SMS, bryter per økt.
    if ($handling === 'kursbevis-sms') {
        if (!DB::harKolonne('course_sessions', 'kursbevis_sms')) {
            Svar::feil('Dette krever en oppdatering av databasen. Trykk ⚙ Kjør oppdateringer.', 503);
        }
        $okt = Foresporsel::heltall('okt');
        if ($okt <= 0) {
            Svar::feil('Mangler økta.');
        }
        $paa = Foresporsel::tekst('paa') === 'ja' ? 1 : 0;
        DB::oppdater('course_sessions', ['kursbevis_sms' => $paa], ['id' => $okt]);
        revider('kursbevis_sms', 'course_session', $okt, ['paa' => $paa]);
        Svar::ok(['paa' => $paa === 1]);
    }
    if ($handling !== 'qr') {
        Svar::feil('Ukjent handling.');
    }
    $bookingId = Foresporsel::heltall('bookingId');
    if ($bookingId <= 0) {
        Svar::feil('Mangler påmeldingen.');
    }
    try {
        $r = KursstartKrav::visQr($bookingId);
    } catch (RuntimeException $e) {
        $kode = (int) $e->getCode();
        // Vipps sa nei eller svarte ikke: et svar, ikke en feil hos oss.
        if ($kode === KursstartKrav::VIPPS_NEI) {
            Svar::json(['ok' => false, 'feil' => $e->getMessage()]);
        }
        Svar::feil($e->getMessage(), $kode >= 400 && $kode < 600 ? $kode : 500);
    }
    Svar::ok($r);
}

// Deltakerne med det som står igjen å betale og Vipps-kravet, og statusen til
// e-postene som går av seg selv. Ingenting sendes herfra.
// Kolonner som kommer med senere migrasjoner, tåles at mangler.
$oktId = Foresporsel::heltall('okt');
if ($oktId <= 0) {
    Svar::feil('Mangler økta.');
}

$okt = DB::en(
    'SELECT cs.id, cs.start_tid, cs.slutt_tid, c.tittel, '
    . (DB::harKolonne('course_sessions', 'kursbevis_sms') ? 'cs.kursbevis_sms' : '0 AS kursbevis_sms') . '
       FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
      WHERE cs.id = :i',
    ['i' => $oktId]
);
if ($okt === null) {
    Svar::feil('Fant ikke økta.', 404);
}

$allergi = DB::harKolonne('bookings', 'allergier') ? 'b.allergier' : "''";
// Steg 1 «Deltakerne» (eieren, 7. oktober 2026): e-post og mobil rett i lista,
// og om kursbeviset er trukket tilbake. E-posten leses slik kursbeviset sendes
// (bin/cron.php anmeldelser): medlemmets, ellers den på påmeldingen.
$sperret = DB::harKolonne('bookings', 'bevis_sperret') ? 'b.bevis_sperret' : '0';
$maate = DB::harKolonne('bookings', 'betalt_maate') ? 'b.betalt_maate' : "''";
$rader = DB::alle(
    "SELECT b.id, b.status, b.belop_ore, b.antall, b.created_at, {$allergi} AS merknad,
            {$sperret} AS bevis_sperret, {$maate} AS betalt_maate,
            COALESCE(m.navn, b.gjest_navn) AS navn,
            COALESCE(m.epost, b.gjest_epost) AS epost,
            COALESCE(NULLIF(m.telefon, ''), b.gjest_telefon) AS telefon
       FROM bookings b
  LEFT JOIN members m ON m.id = b.member_id
      WHERE b.course_session_id = :s AND b.status <> 'avbestilt'
   ORDER BY b.id",
    ['s' => $oktId]
);
// Krav som venter: statusen hentes fra Vipps (ikke oftere enn hvert femte
// sekund per krav; updated_at skrives baade i lokal tid og UTC, derfor
// GREATEST), saa «Betalt» kommer fram selv om webhooken er sen.
// Samme behandling som cron og webhooken (Vipps::synkroniser).
if ($rader !== [] && DB::harKolonne('payments', 'booking_id')) {
    foreach (DB::alle(
        "SELECT vipps_reference FROM payments
          WHERE booking_id IN (" . implode(',', array_map(static fn($r) => (int) $r['id'], $rader)) . ")
            AND type = 'epayment' AND vipps_reference LIKE 'KS-%' AND status = 'venter'
            AND updated_at < DATE_SUB(GREATEST(NOW(), UTC_TIMESTAMP()), INTERVAL 5 SECOND)
          LIMIT 10"
    ) as $v) {
        Vipps::synkroniser((string) $v['vipps_reference']);
    }
}
$krav = KursstartKrav::statusFor(array_map(static fn($r) => (int) $r['id'], $rader));
// Samme «ny»-grense som kalender.php: siste døgn, i UTC.
$nyGrense = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d H:i:s');
$deltakere = [];
foreach ($rader as $r) {
    $id = (int) $r['id'];
    $skyldig = KursstartKrav::skyldig($id, (int) $r['belop_ore'], (string) $r['status']);
    // «Møtt» (steg 1): status «ikke_mott» = ikke møtt, alt annet = møtt. Ingen
    // ny kolonne. Krysses «Møtt» på igjen, settes statusen tilbake: betalt når
    // plassen er gjort opp (betalt fullt, uten beløp, eller ført med en måte),
    // ellers reservert. Kursbeviset går bare til betalte (Booking::bevisLenke,
    // cron «anmeldelser»), aldri til ikke møtt.
    $forStatus = null;
    if ((string) $r['status'] === 'ikke_mott') {
        $bet = Booking::betalingerFor($id);
        $m = (string) ($r['betalt_maate'] ?? '');
        $gjortOpp = (int) $r['belop_ore'] <= 0
            || ($bet['rader'] !== [] && $bet['sum'] >= (int) $r['belop_ore'])
            || Booking::maateGirPenger($m) || in_array($m, ['Gratis', 'Gavekort'], true);
        $forStatus = $gjortOpp ? 'betalt' : 'reservert';
    }
    $deltakere[] = [
        'bookingId'  => $id,
        'navn'       => (string) $r['navn'],
        // Samme ord som kalenderen (kalender.php).
        'status'     => [
            'betalt'    => 'Betalt',
            'reservert' => 'Ikke betalt',
            'refundert' => 'Refundert',
            'ikke_mott' => 'Møtte ikke opp',
        ][(string) $r['status']] ?? 'Ikke betalt',
        'statusKode' => (string) $r['status'],
        'mott'       => (string) $r['status'] !== 'ikke_mott',
        'forStatus'  => $forStatus,
        'epost'      => trim((string) ($r['epost'] ?? '')),
        'telefon'    => trim((string) ($r['telefon'] ?? '')),
        'bevisSperret' => !empty($r['bevis_sperret']),
        'antall'     => (int) $r['antall'],
        'merknad'    => trim((string) ($r['merknad'] ?? '')),
    // «ny»: meldt på siste døgn, samme grense som kalenderen (kalender.php).
    'ny'         => (string) $r['created_at'] >= $nyGrense,
        'skyldigOre' => $skyldig,
        'skyldig'    => Booking::kroner($skyldig),
        'harTlf'     => KursstartKrav::telefon((string) ($r['telefon'] ?? '')) !== null,
        // «venter» = kravet ligger i Vipps-appen og er ikke betalt ennå.
        'krav'       => $krav[$id]['status'] ?? '',
        // «qr» = QR-koden fra «Vis QR-kode», «krav» = et gammelt Vipps-krav.
        'kravFlyt'   => $krav[$id]['flyt'] ?? '',
    ];
}

// E-postene etter kurset: bare status, ingen send-knapper.
$iOslo = static function (?string $utc): string {
    if ($utc === null || $utc === '') {
        return '';
    }
    $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Oslo'));
    return $d->format('j.n.') . ' kl. ' . $d->format('H:i');
};
$malPaa = static fn(string $navn): bool => (int) (DB::verdi(
    'SELECT aktiv FROM notification_templates WHERE navn = :n', ['n' => $navn]
) ?? 0) === 1;
$sendtAt = static function (string $kol) use ($oktId): ?string {
    if (!DB::harKolonne('course_sessions', $kol)) {
        return null;
    }
    $v = DB::verdi("SELECT {$kol} FROM course_sessions WHERE id = :i", ['i' => $oktId]);
    return $v !== null && $v !== false ? (string) $v : null;
};
$status = static function (bool $paa, ?string $sendt, string $planlagt, string $av = 'Slått av') use ($iOslo): array {
    if ($sendt !== null) {
        return ['status' => 'Sendt ' . $iOslo($sendt), 'tone' => 'good'];
    }
    return $paa ? ['status' => $planlagt, 'tone' => ''] : ['status' => $av, 'tone' => 'warn'];
};

$lenke = trim((string) Config::hent('anmeldelse_lenke', '')) !== '';
$dager = max(1, min(14, (int) Config::hent('fortsett_dager', '3')));
$bookingIder = array_map(static fn($r) => (int) $r['id'], $rader);
$klarSendt = ($bookingIder !== [] && DB::harTabell('notifications'))
    ? (int) DB::verdi(
        "SELECT COUNT(DISTINCT ref_id) FROM notifications
          WHERE mal = 'ferdig_brent' AND ref_type = 'booking'
            AND ref_id IN (" . implode(',', $bookingIder) . ')'
    ) : 0;

$eposter = [
    ['navn' => 'Påminnelse', 'naar' => 'Dagen før kurset']
        + $status($malPaa('kurspaaminnelse'), $sendtAt('paaminnelse_sendt_at'), 'Planlagt'),
    ['navn' => 'Google-anmeldelse', 'naar' => 'Sendes neste dag kl. 10']
        + $status($malPaa('anmeldelse') && $lenke, $sendtAt('anmeldelse_sendt_at'), 'Planlagt',
            $lenke ? 'Slått av' : 'Mangler lenke'),
    ['navn' => 'Medlemstilbud', 'naar' => $dager . ' dager etter kurset']
        + $status($malPaa('fortsett'), $sendtAt('fortsett_sendt_at'), 'Planlagt'),
    ['navn' => 'Keramikken er klar', 'naar' => 'Ca. 3 uker etter · e-post og Min side']
        + ($klarSendt > 0
            ? ['status' => 'Sendt til ' . $klarSendt . ' av ' . count($bookingIder), 'tone' => 'good']
            : ($malPaa('ferdig_brent')
                ? ['status' => 'Når du melder den klar', 'tone' => '']
                : ['status' => 'Slått av', 'tone' => 'warn'])),
];

Svar::json([
    'paa'       => KursstartKrav::paa(),
    'okt'       => ['id' => $oktId, 'tittel' => (string) $okt['tittel']],
    // «Send også på SMS» i Etter kurset. null = SMS er ikke satt opp, eller
    // migrasjon 259 mangler: da vises ingen bryter.
    'kursbevisSms' => (Varsel::smsMulig() && DB::harKolonne('course_sessions', 'kursbevis_sms'))
        ? (int) $okt['kursbevis_sms'] === 1 : null,
    'deltakere' => $deltakere,
    'eposter'   => $eposter,
]);
