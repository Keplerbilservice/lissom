<?php
/**
 * Kurs og datoer.
 *
 *   GET                     alle kurs, ogsaa kladder
 *   POST handling=lagre     opprett eller endre et kurs
 *   POST handling=nydato    legg til en dato
 *   POST handling=avlys     avlys en dato
 *
 * Prisen som settes her er den kunden faktisk trekkes. Nettleseren sender
 * aldri belop ved booking — den slaas opp herfra.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

// ---------------------------------------------------------------- lesing
if (Foresporsel::metode() === 'GET') {
    $kurs = DB::alle('SELECT * FROM courses ORDER BY status, tittel');

    Svar::json(['kurs' => array_map(static function ($k) {
        $okter = DB::alle(
            'SELECT id, start_tid, slutt_tid, kapasitet, status
               FROM course_sessions WHERE course_id = :c ORDER BY start_tid',
            ['c' => $k['id']]
        );
        return [
            'id'         => (int) $k['id'],
            'slug'       => $k['slug'],
            'tittel'     => $k['tittel'],
            'type'       => $k['type'],
            'tema'       => $k['tema'],
            'pris'       => (int) $k['pris_ore'] / 100,
            'kapasitet'  => (int) $k['kapasitet'],
            'sms'        => (bool) $k['sms_paaminnelse'],
            'status'     => $k['status'],
            'visUtenDato'=> (bool) ($k['vis_uten_dato'] ?? 0),
            'serier'     => Serier::forKurs((int) $k['id']),
            'om'         => $k['beskrivelse'],
            'instruktor' => $k['instruktor'],
            'bekreftelse'=> $k['bekreftelse_tekst'],
            'datoer'     => array_map(static fn($o) => [
                'oktId'     => (int) $o['id'],
                'naar'      => Booking::norskPeriode((string) $o['start_tid'], $o['slutt_tid'] ?? null),
                'startUtc'  => $o['start_tid'],
                'status'    => $o['status'],
                'ledige'    => Booking::ledigePlasser((int) $o['id']),
            ], $okter),
        ];
    }, $kurs)]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling', 'lagre');

/** «2026-09-02 17:30» i norsk tid → «2026-09-02 15:30:00» UTC for lagring. */
$tilUtc = static function (string $norsk): ?string {
    $norsk = trim($norsk);
    if ($norsk === '') {
        return null;
    }
    try {
        $d = new DateTimeImmutable($norsk, new DateTimeZone('Europe/Oslo'));
    } catch (Throwable) {
        return null;
    }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
};

switch ($handling) {

    // ------------------------------------------------------------ lagre kurs
    case 'lagre':
        $id     = Foresporsel::heltall('id');
        $tittel = mb_substr(Foresporsel::tekst('tittel'), 0, 191);
        $pris   = Foresporsel::heltall('pris');           // kroner

        if ($tittel === '') {
            Svar::feil('Kurset må ha en tittel.');
        }
        if ($pris < 0) {
            Svar::feil('Prisen kan ikke være negativ.');
        }

        $data = [
            'tittel'            => $tittel,
            'type'              => in_array(Foresporsel::tekst('type'), ['kurs', 'event', 'dropin', 'workshop'], true)
                                    ? Foresporsel::tekst('type') : 'kurs',
            'tema'              => mb_substr(Foresporsel::tekst('tema'), 0, 64) ?: null,
            'pris_ore'          => $pris * 100,
            'kapasitet'         => max(1, min(999, Foresporsel::heltall('kapasitet', 8))),
            'sms_paaminnelse'   => Foresporsel::tekst('sms') === 'nei' ? 0 : 1,
            'beskrivelse'       => Foresporsel::tekst('om') ?: null,
            // Navnet paa kursbeviset. Tomt betyr Monica, som staar i malen.
            'instruktor'        => mb_substr(Foresporsel::tekst('instruktor'), 0, 191) ?: null,
            'bekreftelse_tekst' => Foresporsel::tekst('bekreftelse') ?: null,
            'status'            => in_array(Foresporsel::tekst('status'), ['kladd', 'publisert', 'avlyst'], true)
                                    ? Foresporsel::tekst('status') : 'kladd',
        ];

        // Vis kurset paa nettsida ogsaa naar det ikke har datoer — da staar
        // det med «Kontakt oss» framfor en bookingknapp.
        //
        // Bare naar feltet faktisk er med. Sto det her uansett, ville et
        // skjema som ikke kjenner feltet — kursredigeringen — slaatt det av
        // igjen hver gang kurset ble lagret, uten at noen ba om det.
        if (array_key_exists('visUtenDato', Foresporsel::kropp())) {
            $data['vis_uten_dato'] = Foresporsel::tekst('visUtenDato') === 'ja' ? 1 : 0;
        }

        if ($id > 0) {
            DB::oppdater('courses', $data, ['id' => $id]);
            revider('kurs_endret', 'course', $id, ['tittel' => $tittel]);
            Svar::ok(['id' => $id]);
        }

        // Ny: lag en slug som ikke kolliderer med en eksisterende.
        $grunn = strtolower(strtr($tittel, [
            'æ' => 'ae', 'ø' => 'o', 'å' => 'a', 'Æ' => 'ae', 'Ø' => 'o', 'Å' => 'a',
        ]));
        $grunn = trim(preg_replace('/[^a-z0-9]+/', '-', $grunn) ?? '', '-') ?: 'kurs';
        $slug = $grunn;
        $n = 2;
        while (DB::en('SELECT id FROM courses WHERE slug = :s', ['s' => $slug]) !== null) {
            $slug = $grunn . '-' . $n++;
        }

        $nyId = DB::settInn('courses', $data + ['slug' => $slug]);
        revider('kurs_opprettet', 'course', $nyId, ['tittel' => $tittel]);
        Svar::ok(['id' => $nyId, 'slug' => $slug]);

    // ------------------------------------------------------------- ny dato
    case 'nydato':
        $kursId = Foresporsel::heltall('kursId');
        $start = $tilUtc(Foresporsel::tekst('start'));
        $slutt = $tilUtc(Foresporsel::tekst('slutt'));

        if ($kursId <= 0 || DB::en('SELECT id FROM courses WHERE id = :i', ['i' => $kursId]) === null) {
            Svar::feil('Ukjent kurs.');
        }
        if ($start === null) {
            Svar::feil('Skriv datoen som 2026-09-02 17:30.');
        }

        $oktId = DB::settInn('course_sessions', [
            'course_id' => $kursId,
            'start_tid' => $start,
            'slutt_tid' => $slutt,
            'kapasitet' => Foresporsel::heltall('kapasitet') ?: null,
        ]);
        revider('dato_lagt_til', 'course_session', $oktId, ['kurs' => $kursId, 'start' => $start]);
        Svar::ok(['oktId' => $oktId, 'naar' => Booking::norskDato($start)]);

    // ------------------------------------------------- fast ukedag (serie)
    //
    // «Hver torsdag 10:00» framfor én dato av gangen. Cron fyller paa
    // framover, saa kurset ikke gaar tomt og forsvinner fra nettsida.
    case 'serie':
        $kursId = Foresporsel::heltall('kursId');
        if ($kursId <= 0 || DB::en('SELECT id FROM courses WHERE id = :i', ['i' => $kursId]) === null) {
            Svar::feil('Ukjent kurs.');
        }
        $ukedag = Foresporsel::heltall('ukedag');
        if ($ukedag < 1 || $ukedag > 7) {
            Svar::feil('Velg en ukedag.');
        }
        $klokke = static function (string $t): ?string {
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1 ? $t . ':00' : null;
        };
        $fra = $klokke(Foresporsel::tekst('fra'));
        $til = $klokke(Foresporsel::tekst('til'));
        if ($fra === null || $til === null) {
            Svar::feil('Skriv klokkeslettene som 10:00 og 13:00.');
        }

        DB::kjor(
            'INSERT INTO kurs_serier (course_id, ukedag, fra, til, kapasitet, uker_fram, aktiv)
             VALUES (:c, :d, :f, :t, :k, :u, 1)
             ON DUPLICATE KEY UPDATE til = VALUES(til), kapasitet = VALUES(kapasitet),
                                     uker_fram = VALUES(uker_fram), aktiv = 1',
            [
                'c' => $kursId, 'd' => $ukedag, 'f' => $fra, 't' => $til,
                'k' => Foresporsel::heltall('kapasitet') ?: null,
                'u' => max(1, min(52, Foresporsel::heltall('ukerFram', 8))),
            ]
        );
        $laget = Serier::fyllPaa($kursId);
        revider('serie_lagret', 'course', $kursId, ['ukedag' => $ukedag, 'fra' => $fra]);
        Svar::ok([
            'serier'  => Serier::forKurs($kursId),
            'beskjed' => $laget > 0
                ? $laget . ($laget === 1 ? ' dato' : ' datoer') . ' er lagt ut framover.'
                : 'Datoene lå ute fra før.',
        ]);

    // Fjern en fast ukedag. Oktene som alt er lagt ut, blir staaende — folk
    // kan ha booket dem, og de skal avlyses én og én med «avlys».
    case 'serieAv':
        $serieId = Foresporsel::heltall('serieId');
        $serie = DB::en('SELECT course_id FROM kurs_serier WHERE id = :i', ['i' => $serieId]);
        if ($serie === null) {
            Svar::feil('Fant ikke gjentakelsen.');
        }
        DB::kjor('DELETE FROM kurs_serier WHERE id = :i', ['i' => $serieId]);
        revider('serie_fjernet', 'course', (int) $serie['course_id'], ['serie' => $serieId]);
        Svar::ok([
            'serier'  => Serier::forKurs((int) $serie['course_id']),
            'beskjed' => 'Datoene som alt ligger ute blir stående. Avlys dem enkeltvis hvis de skal bort.',
        ]);

    // --------------------------------------------------------------- avlys
    case 'avlys':
        $oktId = Foresporsel::heltall('oktId');
        $antall = (int) DB::verdi(
            "SELECT COUNT(*) FROM bookings WHERE course_session_id = :o AND status = 'betalt'",
            ['o' => $oktId]
        );

        DB::oppdater('course_sessions', ['status' => 'avlyst'], ['id' => $oktId]);
        revider('dato_avlyst', 'course_session', $oktId, ['betalte_bookinger' => $antall]);

        Svar::ok([
            'betalte' => $antall,
            'beskjed' => $antall > 0
                ? "Datoen er avlyst. {$antall} har betalt og må refunderes manuelt under Økonomi."
                : 'Datoen er avlyst.',
        ]);

    default:
        Svar::feil('Ukjent handling.');
}
