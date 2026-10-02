<?php
/**
 * Avbestilling av egen plass.
 *
 * Reglene staar i vilkaarene, og regnes ut her framfor aa overlates til
 * kunden: mer enn to dager for kursstart gir full refusjon, naermere enn det
 * gir ingen.
 *
 * Eieren, 3. september, med kursvilkaarene sine: «Ved avbestilling mer enn 2
 * dager for kursstart refunderes kursavgiften fullt ut. Ved avbestilling
 * mindre enn 2 dager for kursstart refunderes ikke kursavgiften.» Og:
 * «jeg vil at mine regler skal gjelde».
 *
 * Foer sto det tre trinn her — alt over fjorten dager, halvparten mellom
 * fjorten og sju, ingenting naermere. Det var strengere enn vilkaarene sier
 * naa: en som avbestilte fem dager for fikk ingenting, der teksten lover alt
 * tilbake. Regelen og teksten maa si det samme, ellers lover nettsida noe
 * kassa ikke gjor.
 *
 * Beloepet regnes alltid ut fra det som faktisk ble betalt, aldri fra noe
 * nettleseren sender.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$medlem = krev_medlem();
Rate::sjekk('avbestill', maks: 10, vindu: 3600, nokkel: (string) $medlem['id']);

$bookingId = Foresporsel::heltall('bookingId');

$b = DB::en(
    'SELECT b.*, c.tittel, c.type, cs.start_tid, p.vipps_reference, p.belop_ore AS betalt_ore,
            p.refundert_ore, p.status AS betalingsstatus, p.id AS pid
       FROM bookings b
       JOIN courses c ON c.id = b.course_id
  LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
  LEFT JOIN payments p ON p.id = b.payment_id
      WHERE b.id = :i AND b.member_id = :m',
    ['i' => $bookingId, 'm' => $medlem['id']]
);

if ($b === null) {
    Svar::feil('Fant ikke plassen din.', 404);
}
if ($b['status'] === 'avbestilt' || $b['status'] === 'refundert') {
    Svar::feil('Denne plassen er allerede avbestilt.', 409);
}

// --- Betalingene paa plassen ----------------------------------------------
//
// Kontrolloeren, 2. oktober 2026: etter et delt oppgjoer (kontant + gavekort
// i Ta betalt) peker «bookings.payment_id» paa den siste manuelle raden. Da
// ble bare den lest, og gavekortdelen paa den andre raden ble aldri gitt
// tilbake. Alle betalingene paa plassen leses (Booking::betalingerFor()).
$ider = array_map(static fn(array $r): int => (int) $r['id'], Booking::betalingerFor($bookingId)['rader']);
if ($ider === [] && $b['pid'] !== null) {
    $ider = [(int) $b['pid']];
}
$rader = $ider === [] ? [] : DB::alle(
    'SELECT * FROM payments WHERE id IN (' . implode(',', array_unique($ider)) . ') ORDER BY id'
);

$vippsRader = [];   // betalt i Vipps: refunderes dit
$manuellOre = 0;    // kontant / Vipps i verkstedet: tilbake for haand
$uavklartOre = 0;   // betaling som ikke er bekreftet: ordnes for haand
$gavedeler = [];    // betaling-id => oere trukket fra kortet
$kortIder = [];
foreach ($rader as $p) {
    if ($p['annullert_at'] !== null) {
        continue;
    }
    // L-2: gavekortdelen er betalt, den ogsaa — det som faktisk ble trukket
    // fra kortet for akkurat denne betalingen.
    $gave = Booking::gavekortBrukt((int) $p['id']);
    if ($gave > 0) {
        $gavedeler[(int) $p['id']] = $gave;
        $kortIder[] = (int) $p['gavekort_id'];
    }
    $penger = (int) $p['belop_ore'];
    if ($penger <= 0) {
        continue;
    }
    $st = (string) $p['status'];
    if ((string) $p['type'] !== 'manuell' && trim((string) $p['vipps_reference']) !== ''
        && in_array($st, ['betalt', 'delvis_refundert'], true)) {
        $vippsRader[] = $p;
    } elseif ((string) $p['type'] === 'manuell' && $st === 'betalt') {
        $manuellOre += $penger;
    } elseif (in_array($st, ['opprettet', 'venter', 'autorisert'], true)) {
        $uavklartOre += $penger;
    }
}

// --- Hvor mye skal tilbake? ----------------------------------------------
$betalt = $manuellOre + $uavklartOre + array_sum(array_map(static fn(array $p): int => (int) $p['belop_ore'], $vippsRader));
$gavekort = array_sum($gavedeler);
$timerIgjen = $b['start_tid'] ? (strtotime((string) $b['start_tid']) - time()) / 3600 : null;

if ($betalt === 0 && $gavekort === 0) {
    $andel = 0.0;
    $regel = 'Ingenting var belastet.';
} else {
    // Selve regelen staar i Booking::avbestillingsregel(). Den samme brukes
    // av api/mine-plasser.php, som forteller kunden hva hen faar — sto den to
    // steder, kunne de si hver sitt om de samme pengene.
    $r = Booking::avbestillingsregel($timerIgjen);
    $andel = $r['andel'];
    $regel = $r['regel'];
}

// Kontant og ubekreftet etter samme regel, men tilbake for haand.
$forHaand = (int) round($manuellOre * $andel) + (int) round($uavklartOre * $andel);
$refunderes = $forHaand;
$gaveGitt = 0;
$refundert = false;
$manuelt = $forHaand > 0;

$harClaim = false;
$claim = static function () use ($bookingId, $medlem, $b, &$harClaim, $gavedeler, $andel, &$gaveGitt): void {
    $endret = DB::kjor(
    "UPDATE bookings SET status = 'avbestilt', avbestilt_at = UTC_TIMESTAMP()
      WHERE id = :i AND member_id = :m AND status = :s AND avbestilt_at IS NULL",
    ['i' => $bookingId, 'm' => $medlem['id'], 's' => $b['status']]
    )->rowCount();
    if ($endret !== 1) {
        throw new RuntimeException('Denne plassen er allerede avbestilt.', 409);
    }
    // I samme transaksjon som avbestillingen: den som faar plassen avbestilt,
    // er den eneste som gir gavekortdelene tilbake, og bare én gang.
    foreach ($gavedeler as $pid => $gave) {
        $tilbake = (int) round($gave * $andel);
        if ($tilbake > 0) {
            $gaveGitt += Booking::gavekortTilbake((int) $pid, $tilbake);
        }
    }
    $harClaim = true;
};

// --- Selve refusjonen -----------------------------------------------------
$vippsGitt = 0;
$vippsFull = $vippsRader !== [];
foreach ($vippsRader as $p) {
    $onsket = (int) round((int) $p['belop_ore'] * $andel);
    if ($onsket <= 0) {
        $vippsFull = false;
        continue;
    }
    $medClaim = !$harClaim;
    try {
        // Bookingclaim, gavekortdelene og varig refusjonsintent committes i
        // samme transaksjon (den foerste refusjonen). Kortene laases foerst.
        $resultat = Booking::refunderBetaling((int) $p['id'], $onsket, null,
            $medClaim ? $claim : null, $medClaim ? $kortIder : []);
        $vippsGitt += $resultat['belop'];
        if ($resultat['gjenstaar'] !== 0) {
            $vippsFull = false;
            $manuelt = true;
        }
    } catch (Throwable $e) {
        if ($medClaim && !$harClaim && $e instanceof RuntimeException && $e->getMessage() === 'Refusjon behandles allerede.') {
            Svar::feil('Noe gikk galt. Prøv igjen, eller ta kontakt med oss.', 409);
        }
        if ($medClaim && $e->getCode() === 409) {
            Svar::feil('Denne plassen er allerede avbestilt.', 409);
        }
        if ($medClaim && !$harClaim) { throw $e; }
        $avbestilt = DB::verdi('SELECT avbestilt_at FROM bookings WHERE id = :i', ['i' => $bookingId]);
        if ($avbestilt === null) { throw $e; }
        // Avbestillingen staar uansett. Pengene ordnes for haand framfor aa
        // late som ingenting skjedde — kunden har jo mistet plassen.
        logg_feil('Refusjon feilet ved avbestilling av booking ' . $bookingId, $e);
        $refunderes += $onsket;
        $vippsFull = false;
        $manuelt = true;
    }
}
$refunderes += $vippsGitt;
if (!$harClaim) {
    try {
        DB::iTransaksjon(static function () use ($claim, $kortIder): void {
            Booking::laasKort($kortIder);
            $claim();
        });
    } catch (RuntimeException $e) { Svar::feil('Denne plassen er allerede avbestilt.', 409); }
}
$refundert = $vippsFull && $vippsGitt > 0 && !$manuelt;

if ($refundert) {
    DB::oppdater('bookings', ['status' => 'refundert'], ['id' => $bookingId]);
}

revider('avbestilling', 'booking', $bookingId, [
    'refundert_ore' => $vippsGitt,
    'for_haand_ore' => $refunderes - $vippsGitt,
    'gavekort_tilbake_ore' => $gaveGitt,
    'manuelt'       => $manuelt,
]);

Varsel::mal('avbestilling', [
    'epost'   => $medlem['epost'],
    'telefon' => $medlem['telefon'],
], [
    'navn'  => (string) $medlem['navn'],
    'kurs'  => (string) $b['tittel'] . ($b['start_tid'] ? ' — ' . Booking::norskDato((string) $b['start_tid']) : ''),
    // Refusjonen staar bare i e-posten naar den faktisk skjer. Tomme felt
    // fjerner avsnittet og radene (Varsel::oppsett()).
    'belop'        => $refunderes > 0 ? Booking::kroner($refunderes) : '',
    'refusjon'     => $refundert
        ? 'Pengene er på vei tilbake til deg på Vipps.'
        : ($manuelt ? 'Refusjonen tar vi manuelt — du hører fra oss i løpet av kort tid.' : ''),
    'refusjonstid' => $refundert ? 'Vanligvis innen tre virkedager' : '',
], 'booking', $bookingId);

Svar::ok([
    'regel'      => $regel,
    'refunderes' => Booking::kroner($refunderes),
    // Tall, ingen ny tekst: kundeteksten om gavekortet venter paa eieren.
    'gavekortTilbakeOre' => $gaveGitt,
    'manuelt'    => $manuelt,
    'beskjed'    => $manuelt
        ? 'Plassen er avbestilt. Refusjonen måtte vi ta manuelt — du hører fra oss i løpet av kort tid.'
        : ($refundert
            ? 'Plassen er avbestilt. Pengene er på vei tilbake til Vipps, vanligvis innen tre virkedager.'
            : 'Plassen er avbestilt.'),
]);
