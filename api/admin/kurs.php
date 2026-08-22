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
            'om'         => $k['beskrivelse'],
            'instruktor' => $k['instruktor'],
            'bekreftelse'=> $k['bekreftelse_tekst'],
            'datoer'     => array_map(static fn($o) => [
                'oktId'     => (int) $o['id'],
                'naar'      => Booking::norskDato((string) $o['start_tid']),
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
            Svar::feil('Kurset maa ha en tittel.');
        }
        if ($pris < 0) {
            Svar::feil('Prisen kan ikke vaere negativ.');
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
                ? "Datoen er avlyst. {$antall} har betalt og maa refunderes manuelt under Okonomi."
                : 'Datoen er avlyst.',
        ]);

    default:
        Svar::feil('Ukjent handling.');
}
