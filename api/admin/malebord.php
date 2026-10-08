<?php
/**
 * Paint on Pots: malebordet i admin, samlet.
 *
 *   GET  ?uke=2026-10-12&dato=2026-10-14
 *        innstillinger, i dag, uka (mandag; tom = denne uka) og dagslista
 *   POST handling=dag      { dato, status: apen|fullt|stengt|tider, fra?, til? }
 *   POST handling=restenAvDagen     dagens dato fullt fra naa
 *   POST handling=apne     { dato } unntaket slettes, dagen er som vanlig
 *   POST handling=flytt    { bookingId, dato, tid } ny ankomsttid, sjekket mot
 *                          tilgjengeligheten foer den lagres
 *   POST handling=sjekk    { bookingId, antall } er det plass til nytt antall?
 *
 * Eieren, 7. og 8. oktober 2026. Plassgrensen staar paa ressursen «Paint on
 * Pots» under Ressurser og lagres ikke her. Antall, betaling, avbestilling og
 * oppmoete gaar gjennom pamelding.php og «Ta betalt» som for alle andre
 * paameldinger. Ingenting her sender e-post eller SMS.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

$kurs = Malebord::kurs();
if ($kurs === null) {
    Svar::feil('Fant ikke Paint on Pots-kurset.', 404);
}
$kursId = (int) $kurs['id'];
$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$naa  = new DateTimeImmutable('now', $oslo);
$idag = $naa->format('Y-m-d');

$gyldigDato = static function (string $d): bool {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
        return false;
    }
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y);
};
$gyldigKlokke = static fn (string $t): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;

$svar = static function () use (&$kurs, $kursId, $naa, $idag, $gyldigDato): array {
    $kurs = Malebord::kurs() ?? $kurs;
    $uke = Foresporsel::tekst('uke');
    $mandag = $gyldigDato($uke)
        ? new DateTimeImmutable($uke, new DateTimeZone('Europe/Oslo'))
        : $naa->setTime(0, 0);
    $mandag = $mandag->modify('-' . ((int) $mandag->format('N') - 1) . ' days');
    $dato = Foresporsel::tekst('dato');
    $dato = $gyldigDato($dato) ? $dato : $idag;
    $iDag = Malebord::dag($idag);
    $valgt = Malebord::dag($dato);
    return [
        'klar'     => Malebord::klar(),
        'kurs'     => $kurs,
        'idag'     => [
            'dato'   => $idag,
            'status' => $iDag['status'] ?? 'apen',
            'fra'    => $iDag['fra'] ?? null,
            'til'    => $iDag['til'] ?? null,
            'okter'  => Malebord::okterPaaDag($kursId, $idag),
        ],
        'mandag'   => $mandag->format('Y-m-d'),
        'uke'      => Malebord::uke($kursId, $mandag->format('Y-m-d')),
        'dag'      => [
            'dato'      => $dato,
            'status'    => $valgt['status'] ?? null,
            'fra'       => $valgt['fra'] ?? null,
            'til'       => $valgt['til'] ?? null,
            'vindu'     => Malebord::kvarter($kursId, $dato, 1)['vindu'],
            'reservasjoner' => Malebord::reservasjoner($kursId, $dato),
        ],
    ];
};

if (Foresporsel::metode() === 'GET') {
    Svar::json($svar());
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$adminId = (int) ($admin['id'] ?? 0) ?: null;
$krevKlar = static function (): void {
    if (!Malebord::klar()) {
        Svar::feil('Kjør oppdateringene først (⚙ Kjør oppdateringer).', 409);
    }
};

/** Bookingen, og at den hoerer til Paint on Pots. */
$finnBooking = static function (int $id) use ($kursId): array {
    $b = DB::en(
        'SELECT b.id, b.antall, b.status, b.course_session_id, cs.course_id
           FROM bookings b JOIN course_sessions cs ON cs.id = b.course_session_id
          WHERE b.id = :i',
        ['i' => $id]
    );
    if ($b === null || (int) $b['course_id'] !== $kursId) {
        Svar::feil('Fant ikke reservasjonen.');
    }
    if (!in_array((string) $b['status'], ['betalt', 'reservert', 'ikke_mott'], true)) {
        Svar::feil('Reservasjonen er ikke aktiv.');
    }
    return $b;
};

switch (Foresporsel::tekst('handling')) {
    case 'dag':
        $krevKlar();
        $dato = Foresporsel::tekst('dato');
        $status = Foresporsel::tekst('status');
        if (!$gyldigDato($dato)) {
            Svar::feil('Skriv datoen som 2026-10-14.');
        }
        if ($dato < $idag) {
            Svar::feil('Dagen har vært.');
        }
        if ($status === 'apen') {
            Malebord::apne($dato);
        } elseif ($status === 'fullt' || $status === 'stengt') {
            Malebord::settDag($dato, $status, null, null, $adminId);
        } elseif ($status === 'tider') {
            $fra = Foresporsel::tekst('fra');
            $til = Foresporsel::tekst('til');
            if (!$gyldigKlokke($fra) || !$gyldigKlokke($til)) {
                Svar::feil('Skriv klokkeslettene som 17:00 og 20:00.');
            }
            if ($til <= $fra) {
                Svar::feil('«Til» må være etter «Fra».');
            }
            Malebord::settDag($dato, 'tider', $fra, $til, $adminId);
        } else {
            Svar::feil('Velg Åpen, Fullt, Ingen PoP eller Andre tider.');
        }
        revider('pop_dag', 'pop_dager', null, ['dato' => $dato, 'status' => $status]);
        Svar::ok($svar());

    case 'restenAvDagen':
        $krevKlar();
        $fra = $naa->format('H:i');
        Malebord::settDag($idag, 'fullt', $fra, null, $adminId);
        revider('pop_fullt_resten', 'pop_dager', null, ['dato' => $idag, 'fra' => $fra]);
        Svar::ok($svar() + ['beskjed' => 'Fullt fra ' . $fra . ' i dag. Åpner av seg selv i morgen.']);

    case 'apne':
        $krevKlar();
        $dato = Foresporsel::tekst('dato');
        if (!$gyldigDato($dato)) {
            Svar::feil('Skriv datoen som 2026-10-14.');
        }
        Malebord::apne($dato);
        revider('pop_apnet', 'pop_dager', null, ['dato' => $dato]);
        Svar::ok($svar() + ['beskjed' => 'Åpent igjen.']);

    case 'sjekk':
        // Er det plass til et nytt antall paa den samme tida? Selve endringen
        // gjoeres av pamelding.php (endre), som regner beloepet.
        $b = $finnBooking(Foresporsel::heltall('bookingId'));
        $antall = Foresporsel::heltall('antall');
        if ($antall < 1 || $antall > Malebord::MAKS_ANTALL) {
            Svar::feil('Velg mellom 1 og ' . Malebord::MAKS_ANTALL . ' personer.');
        }
        $okt = DB::en('SELECT start_tid, slutt_tid FROM course_sessions WHERE id = :i', ['i' => (int) $b['course_session_id']]);
        $slutt = $okt['slutt_tid'] ?? (new DateTimeImmutable((string) $okt['start_tid'], $utc))
            ->modify('+' . Apent::plassMinutter($kursId) . ' minutes')->format('Y-m-d H:i:s');
        $ledige = Malebord::ledige($kursId, (string) $okt['start_tid'], (string) $slutt, (int) $b['id']);
        if ($ledige < $antall) {
            Svar::feil('Det er ikke plass til ' . $antall . ' da. Ledig: ' . $ledige . '.', 409);
        }
        Svar::ok(['ledige' => $ledige]);

    case 'flytt':
        // Ny ankomsttid paa samme reservasjon. Sjekkes mot tilgjengeligheten
        // (uten reservasjonen selv) foer den lagres. Prisen er den samme —
        // det er det samme kurset — saa beloep og betalinger roeres ikke.
        $b = $finnBooking(Foresporsel::heltall('bookingId'));
        $dato = Foresporsel::tekst('dato');
        $tid  = Foresporsel::tekst('tid');
        if (!$gyldigDato($dato) || !$gyldigKlokke($tid)) {
            Svar::feil('Velg dag og klokkeslett.');
        }
        $svarDag = Malebord::kvarter($kursId, $dato, (int) $b['antall'], (int) $b['id']);
        if (!in_array($tid, array_column($svarDag['tider'], 'tid'), true)) {
            Svar::feil('Det er ikke plass til ' . (int) $b['antall'] . ' kl. ' . $tid . ' den dagen.', 409);
        }
        $fraOkt = (int) $b['course_session_id'];
        $tilOkt = DB::iTransaksjon(static function () use ($kursId, $dato, $tid, $b): int {
            $okt = Apent::oktForTid($kursId, $dato . ' ' . $tid);
            DB::oppdater('bookings', ['course_session_id' => $okt], ['id' => (int) $b['id']]);
            return $okt;
        });
        // Den gamle oekta tas bort bare naar den ble laget av en bestilling
        // og ingen andre bookinger (heller ikke avbestilte) peker paa den.
        if ($fraOkt !== $tilOkt && DB::harKolonne('course_sessions', 'fra_apningstid')) {
            DB::kjor(
                'DELETE FROM course_sessions
                  WHERE id = :i AND fra_apningstid = 1
                    AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.course_session_id = :i2)',
                ['i' => $fraOkt, 'i2' => $fraOkt]
            );
        }
        revider('pop_flyttet', 'booking', (int) $b['id'], ['fra' => $fraOkt, 'til' => $tilOkt, 'dato' => $dato, 'tid' => $tid]);
        Svar::ok($svar() + ['beskjed' => 'Flyttet til ' . $tid . '.']);

    default:
        Svar::feil('Ukjent handling.');
}
