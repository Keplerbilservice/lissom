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

$bookingId = Foresporsel::heltall('bookingId');

// ── Lenka i bekreftelsen (Paint on Pots, migrasjon 260) ─────────────────
//
// Eieren, 8. oktober 2026: bekreftelsen har en lenke for aa avbestille, og
// de fleste som booker Paint on Pots har ingen konto. Lenka har en personlig
// kode (bookings.avbestill_kode) og virker uten innlogging — som kursbeviset.
// Uten kode gjelder det som foer: bare den innloggede, og bare egne plasser.
$kode = Foresporsel::tekst('k');
$medKode = $kode !== '' && PopPris::kodeStemmer($bookingId, $kode);
if ($kode !== '' && !$medKode) {
    Rate::sjekk('avbestill-kode', maks: 10, vindu: 3600);
    Svar::feil('Lenken virker ikke.', 404);
}
$medlem = $medKode ? null : krev_medlem();
Rate::sjekk('avbestill', maks: 10, vindu: 3600,
    nokkel: $medlem !== null ? (string) $medlem['id'] : 'booking-' . $bookingId);

$b = DB::en(
    'SELECT b.*, c.tittel, c.type, cs.start_tid, cs.status AS okt_status, ' . Booking::avlystAtFelt('cs') . ',
            p.vipps_reference, p.belop_ore AS betalt_ore,
            p.refundert_ore, p.status AS betalingsstatus, p.id AS pid,
            m.navn AS m_navn, m.epost AS m_epost, m.telefon AS m_telefon
       FROM bookings b
       JOIN courses c ON c.id = b.course_id
  LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
  LEFT JOIN payments p ON p.id = b.payment_id
  LEFT JOIN members m ON m.id = b.member_id
      WHERE b.id = :i' . ($medlem !== null ? ' AND b.member_id = :m' : ''),
    $medlem !== null ? ['i' => $bookingId, 'm' => $medlem['id']] : ['i' => $bookingId]
);

if ($b === null) {
    Svar::feil('Fant ikke plassen din.', 404);
}
// Hvem kvitteringen gaar til: medlemmet, eller den som booket.
$kontakt = $medlem !== null
    ? ['navn' => (string) $medlem['navn'], 'epost' => $medlem['epost'], 'telefon' => $medlem['telefon']]
    : [
        'navn'    => (string) ($b['m_navn'] ?: $b['gjest_navn']),
        'epost'   => $b['m_epost'] ?: $b['gjest_epost'],
        'telefon' => $b['m_telefon'] ?: $b['gjest_telefon'],
    ];
if ($b['status'] === 'avbestilt' || $b['status'] === 'refundert') {
    Svar::feil('Denne plassen er allerede avbestilt.', 409);
}
// Gjort opp i kassa (Paint on Pots): da er det ikke en avbestilling lenger,
// verken fra lenka eller fra Min side.
if (($b['depositum_ore'] ?? null) !== null && ($b['gjenstander_ore'] ?? null) !== null) {
    Svar::feil('Denne plassen kan ikke avbestilles her lenger. Ta kontakt med oss.', 409);
}
// Lenka i bekreftelsen avbestiller bare en plass som ikke har vaert ennaa,
// og som ikke er gjort opp i kassa.
if ($medKode && (!in_array((string) $b['status'], ['betalt', 'reservert'], true)
    || $b['start_tid'] === null || strtotime((string) $b['start_tid'] . ' UTC') <= time()
    || ($b['gjenstander_ore'] ?? null) !== null)) {
    Svar::feil('Denne plassen kan ikke avbestilles her lenger. Ta kontakt med oss.', 409);
}

// --- Betalingene paa plassen ----------------------------------------------
//
// Kontrolloeren, 2. oktober 2026: etter et delt oppgjoer (kontant + gavekort
// i Ta betalt) peker «bookings.payment_id» paa den siste manuelle raden. Da
// ble bare den lest, og gavekortdelen paa den andre raden ble aldri gitt
// tilbake. Alle betalingene paa plassen leses (Booking::betalingerFor()).
// Delingen etter vei staar i Booking::tilbakeFor() (kontrolloeren 9. oktober
// 2026: ingen kopi). Min side viser det samme tallet foer kunden trykker.
$tilbake = Booking::tilbakeFor($bookingId, $b['pid'] !== null ? (int) $b['pid'] : null);
$vippsRader = $tilbake['vipps'];      // betalt i Vipps: refunderes dit
$manuellOre = $tilbake['manuell'];    // kontant / Vipps i verkstedet: tilbake for haand
$uavklartOre = $tilbake['uavklart'];  // betaling som ikke er bekreftet: ordnes for haand
$gavedeler = $tilbake['gave'];        // betaling-id => oere trukket fra kortet
$kortIder = $tilbake['kort'];

// --- Hvor mye skal tilbake? ----------------------------------------------
$betalt = $manuellOre + $uavklartOre + array_sum(array_map(static fn(array $p): int => (int) $p['belop_ore'], $vippsRader));
$gavekort = array_sum($gavedeler);
$timerIgjen = $b['start_tid'] ? (strtotime((string) $b['start_tid']) - time()) / 3600 : null;

// ── Datoen er avlyst av Lissom ──────────────────────────────────────────
//
// Eieren, 9. oktober 2026: har Lissom avlyst datoen, faar kunden alt tilbake
// uansett hvor lenge det er igjen — 2-dagersregelen gjelder bare naar kunden
// selv avbestiller. Samme refusjonsloeype som ellers (Vipps tilbake til
// Vipps, gavekort tilbake til kortet). Kontant/kort betalt i kassa blir en
// sak i «Må gjøres» i /ny-admin (avlyst_tilbakebetal, migrasjon 271).
// Bare datoer avlyst etter migrasjon 271 (avlyst_at): eldre avlyste datoer
// ble ordnet for haand og oppfoerer seg som foer (kontrolloeren 9. oktober).
// Statusen leses paa nytt under laas i $claim, og avvises om den er endret.
$avlystDato = Booking::avlystAvLissom($b['okt_status'] ?? null, $b['avlyst_at'] ?? null);
// Uten tabellen for saken i Må gjøres ville kontantdelen blitt borte stille.
if ($avlystDato && $manuellOre > 0 && !DB::harTabell('avlyst_tilbakebetal')) {
    Svar::feil('Kjør oppdateringene først.', 503);
}

if ($betalt === 0 && $gavekort === 0) {
    $andel = 0.0;
    $regel = 'Ingenting var belastet.';
} elseif ($avlystDato) {
    $andel = 1.0;
    $regel = 'Lissom har avlyst datoen.';
} else {
    // Selve regelen staar i Booking::avbestillingsregel(). Den samme brukes
    // av api/mine-plasser.php, som forteller kunden hva hen faar — sto den to
    // steder, kunne de si hver sitt om de samme pengene.
    // Paint on Pots har sin egen frist (courses.avbestilling_timer), men bare
    // for bookinger med beloep ved booking (migrasjon 260). Eldre bookinger og
    // andre kurs: null, og vilkaarenes 2 dager gjelder som foer.
    $r = Booking::avbestillingsregel($timerIgjen, PopPris::fristFor($b));
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
$claim = static function () use ($bookingId, $medlem, $b, &$harClaim, $gavedeler, $andel, &$gaveGitt, $avlystDato, $manuellOre): void {
    // Med kode: den samme koden som ble sjekket over, saa en byttet kode
    // ikke kan avbestille.
    $endret = DB::kjor(
    "UPDATE bookings SET status = 'avbestilt', avbestilt_at = UTC_TIMESTAMP()
      WHERE id = :i AND " . ($medlem !== null ? 'member_id = :m' : 'avbestill_kode = :m') . "
        AND status = :s AND avbestilt_at IS NULL"
        // Kassa kan ha slaatt inn gjenstandene mens dette sto paa.
        . (DB::harKolonne('bookings', 'gjenstander_ore') ? ' AND (depositum_ore IS NULL OR gjenstander_ore IS NULL)' : ''),
    ['i' => $bookingId, 'm' => $medlem !== null ? $medlem['id'] : (string) $b['avbestill_kode'], 's' => $b['status']]
    )->rowCount();
    if ($endret !== 1) {
        throw new RuntimeException('Denne plassen er allerede avbestilt.', 409);
    }
    // Datoen under laas (kontrolloeren 9. oktober 2026): plassen maa staa paa
    // samme dato som da andelen ble regnet, og datoen maa vaere avlyst — eller
    // ikke — som da. Ble plassen flyttet (Min side i en annen fane) eller
    // datoen gjenopprettet/avlyst i mellomtiden, rulles alt tilbake.
    $naa = DB::en('SELECT course_session_id FROM bookings WHERE id = :i', ['i' => $bookingId]);
    if ((int) ($naa['course_session_id'] ?? 0) !== (int) ($b['course_session_id'] ?? 0)) {
        throw new RuntimeException('Påmeldingen ble endret i mellomtiden. Last siden på nytt.', 409);
    }
    if ($b['course_session_id'] !== null) {
        $okt = DB::en('SELECT status, ' . Booking::avlystAtFelt('cs') . ' FROM course_sessions cs WHERE cs.id = :o FOR UPDATE',
            ['o' => (int) $b['course_session_id']]);
        if (Booking::avlystAvLissom($okt['status'] ?? null, $okt['avlyst_at'] ?? null) !== $avlystDato) {
            throw new RuntimeException('Påmeldingen ble endret i mellomtiden. Last siden på nytt.', 409);
        }
    }
    // I samme transaksjon som avbestillingen: den som faar plassen avbestilt,
    // er den eneste som gir gavekortdelene tilbake, og bare én gang.
    foreach ($gavedeler as $pid => $gave) {
        $tilbake = (int) round($gave * $andel);
        if ($tilbake > 0) {
            $gaveGitt += Booking::gavekortTilbake((int) $pid, $tilbake);
        }
    }
    // Avlyst dato, betalt kontant/kort i kassa: en sak i «Må gjøres» i
    // /ny-admin («Tilbakebetal X kr til NN»). I samme transaksjon som
    // avbestillingen, og bare én per plass (primaernoekkel).
    //
    // Beloepet regnes paa nytt under laasen paa paameldingen (UPDATE over),
    // med laasende lesing av betalingene: annullering i admin
    // (kursbetaling.php) tar den samme laasen, saa en kontantbetaling som
    // annulleres samtidig, gir aldri en sak uten innbetaling. Er beloepet
    // endret siden det ble lest, rulles alt tilbake (kontrolloeren runde 3).
    if ($avlystDato) {
        $manuellNaa = (int) DB::verdi(
            "SELECT COALESCE(SUM(belop_ore), 0) FROM payments
              WHERE (booking_id = :b OR id = :p) AND type = 'manuell' AND status = 'betalt'
                AND annullert_at IS NULL AND belop_ore > 0
                FOR UPDATE",
            ['b' => $bookingId, 'p' => (int) ($b['payment_id'] ?? 0)]
        );
        if ($manuellNaa !== $manuellOre) {
            throw new RuntimeException('Påmeldingen ble endret i mellomtiden. Last siden på nytt.', 409);
        }
        if ($manuellNaa > 0 && DB::harTabell('avlyst_tilbakebetal')) {
            DB::kjor('INSERT IGNORE INTO avlyst_tilbakebetal (booking_id, belop_ore) VALUES (:b, :o)',
                ['b' => $bookingId, 'o' => $manuellNaa]);
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
            Svar::feil($e->getMessage(), 409);
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
    } catch (RuntimeException $e) {
        Svar::feil($e->getCode() === 409 ? $e->getMessage() : 'Denne plassen er allerede avbestilt.', 409);
    }
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
    'epost'   => $kontakt['epost'],
    'telefon' => $kontakt['telefon'],
], [
    'navn'  => $kontakt['navn'],
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
