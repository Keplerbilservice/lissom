<?php
/**
 * Kurs og datoer.
 *
 *   GET                     alle kurs, ogsaa kladder
 *   POST handling=lagre     opprett eller endre et kurs
 *   POST handling=nydato    legg til en dato
 *   POST handling=plasser   endre antall plasser paa én dato
 *   POST handling=endredato endre tidspunktet paa én dato
 *   POST handling=avlys     avlys en dato
 *   POST handling=slett     fjern et kurs (avlyses om noen er paameldt)
 *   POST handling=bekreftelseStandard  lagre teksten nye kurs fylles ut med
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
            'bilde'      => (string) ($k['bilde'] ?? ''),
            'bilder'     => (static function ($raa): array {
                $l = json_decode((string) $raa, true);
                return is_array($l) ? array_values(array_filter(array_map('strval', $l))) : [];
            })($k['bilder'] ?? null),
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
    }, $kurs),
    // Standardteksten nye kurs fylles ut med. Ligger i innstillinger, saa
    // eieren kan endre den uten en ny utlegging av nettsiden.
    'bekreftelseStandard' => (string) Config::hent('kurs_bekreftelse', ''),
    ]);
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
        if (array_key_exists('visUtenDato', Foresporsel::kropp())
            && DB::harKolonne('courses', 'vis_uten_dato')) {
            $data['vis_uten_dato'] = Foresporsel::tekst('visUtenDato') === 'ja' ? 1 : 0;
        }

        // Bildene fra steg 3. Foerste er hovedbildet; hele lista er karusellen
        // paa kurssida. Bare naar feltet er med — et skjema som ikke kjenner
        // bilder skal ikke toemme dem.
        //
        // Bare filnavn vi selv har lagt ut: basename() klipper bort alt som
        // ligner en sti eller en adresse utenfra.
        if (array_key_exists('bilder', Foresporsel::kropp())) {
            $rene = [];
            foreach ((array) (Foresporsel::kropp()['bilder'] ?? []) as $f) {
                $navn = basename(trim((string) $f));
                if ($navn !== '') {
                    $rene[] = mb_substr($navn, 0, 191);
                }
            }
            $data['bilde'] = $rene[0] ?? null;
            if (DB::harKolonne('courses', 'bilder')) {
                $data['bilder'] = $rene ? json_encode(array_values($rene), JSON_UNESCAPED_SLASHES) : null;
            }
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
        // Tabellen kommer med migrasjon 029. Uten den er det bedre aa si hva
        // som mangler enn aa la kallet doe paa en manglende tabell.
        if (!DB::harTabell('kurs_serier')) {
            Svar::feil('Faste ukedager krever en oppdatering av databasen. Kjør vedlikeholdet under Oversikt først.');
        }
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
        if (!DB::harTabell('kurs_serier')) {
            Svar::feil('Faste ukedager krever en oppdatering av databasen. Kjør vedlikeholdet under Oversikt først.');
        }
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
    // ------------------------------------------------- antall plasser
    //
    // Plassene settes paa kurset, men den enkelte datoen kan avvike: en
    // kveld med to instruktoerer tar flere, en med sykdom faerre. Uten
    // dette matte man endre hele kurset for aa gi plass til én til paa
    // torsdag — og da gjaldt det alle datoene.
    case 'plasser':
        $oktId = Foresporsel::heltall('oktId');
        $okt = DB::en(
            'SELECT cs.id, cs.course_id, c.kapasitet AS kurskapasitet
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :o',
            ['o' => $oktId]
        );
        if ($okt === null) {
            Svar::feil('Fant ikke datoen.', 404);
        }

        $kropp = Foresporsel::kropp();
        $raa = trim((string) ($kropp['kapasitet'] ?? ''));
        // Tomt betyr «foelg kurset». Da er det ett sted aa endre plassene
        // for alle datoene, framfor et tall som maa rettes hver gang.
        $kapasitet = $raa === '' ? null : max(0, (int) $raa);

        // Plassene kan ikke settes lavere enn dem som alt har betalt. Da
        // ville lista vist «9 av 8», og neste kunde faatt en plass som ikke
        // finnes.
        $solgt = (int) DB::verdi(
            "SELECT COALESCE(SUM(antall), 0) FROM bookings
              WHERE course_session_id = :o AND status IN ('betalt','reservert')",
            ['o' => $oktId]
        );
        if ($kapasitet !== null && $kapasitet < $solgt) {
            Svar::feil('Det står allerede ' . $solgt . ' på denne datoen. Sett minst så mange plasser.');
        }

        DB::oppdater('course_sessions', ['kapasitet' => $kapasitet], ['id' => $oktId]);
        revider('dato_plasser', 'course_session', $oktId, ['kapasitet' => $kapasitet]);

        Svar::ok([
            'kapasitet' => $kapasitet ?? (int) $okt['kurskapasitet'],
            'ledige'    => Booking::ledigePlasser($oktId),
            'beskjed'   => $kapasitet === null
                ? 'Datoen følger kursets antall plasser igjen.'
                : 'Datoen har nå ' . $kapasitet . ' plasser.',
        ]);

    // ------------------------------------------------- flytte en dato
    //
    // Datoene kunne legges til, faa flere plasser og avlyses — men ikke
    // rettes. Ble klokkeslettet feil, var eneste vei aa avlyse og lage den
    // paa nytt, og da mistet de paameldte plassen sin. Naa flyttes den.
    case 'endredato':
        $oktId = Foresporsel::heltall('oktId');
        $okt = DB::en(
            'SELECT cs.id, cs.start_tid, cs.slutt_tid, c.tittel
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :o',
            ['o' => $oktId]
        );
        if ($okt === null) {
            Svar::feil('Fant ikke datoen.', 404);
        }

        $start = $tilUtc(Foresporsel::tekst('start'));
        $slutt = $tilUtc(Foresporsel::tekst('slutt'));
        if ($start === null) {
            Svar::feil('Skriv datoen som 2026-09-02 17:30.');
        }
        if ($slutt !== null && $slutt <= $start) {
            Svar::feil('Slutt må være etter start.');
        }

        DB::oppdater(
            'course_sessions',
            ['start_tid' => $start, 'slutt_tid' => $slutt],
            ['id' => $oktId]
        );
        revider('dato_flyttet', 'course_session', $oktId, [
            'fra' => (string) $okt['start_tid'],
            'til' => $start,
        ]);

        // De som alt staar paa lista faar ingen beskjed av seg selv. Det maa
        // sies her, ikke oppdages naar noen moeter opp paa feil klokkeslett.
        $berort = (int) DB::verdi(
            "SELECT COALESCE(SUM(antall), 0) FROM bookings
              WHERE course_session_id = :o AND status IN ('betalt','reservert')",
            ['o' => $oktId]
        );

        Svar::ok([
            'naar'    => Booking::norskDato($start),
            'berort'  => $berort,
            'beskjed' => $berort === 0
                ? 'Datoen er flyttet til ' . Booking::norskDato($start) . '.'
                : 'Datoen er flyttet til ' . Booking::norskDato($start) . '. '
                  . $berort . ($berort === 1 ? ' påmeldt' : ' påmeldte')
                  . ' får ikke beskjed automatisk — send den under Påmeldte.',
        ]);

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

    // ------------------------------------------- standard bekreftelsestekst
    //
    // Teksten eieren slipper aa skrive paa nytt for hvert kurs. Lagres naar
    // hun sier at akkurat denne skal vaere standarden.
    case 'bekreftelseStandard':
        $tekst = trim(mb_substr(Foresporsel::tekst('tekst'), 0, 4000));
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
            ['n' => 'kurs_bekreftelse', 'v' => $tekst]
        );
        Config::glemBasen();
        revider('bekreftelse_standard', 'innstilling', 0, ['lengde' => mb_strlen($tekst)]);
        Svar::ok([
            'bekreftelseStandard' => $tekst,
            'beskjed' => $tekst === ''
                ? 'Standardteksten er tømt. Nye kurs starter med et tomt felt.'
                : 'Teksten er lagret som standard for nye kurs.',
        ]);

    // ------------------------------------------------- slett et kurs
    //
    // «Slett» fjernet bare raden fra skjermbildet. Kurset var tilbake ved
    // neste sidelasting, for serveren hadde aldri hort om det.
    case 'slett':
        $kursId = Foresporsel::heltall('id');
        $kurs = DB::en('SELECT id, tittel FROM courses WHERE id = :i', ['i' => $kursId]);
        if ($kurs === null) {
            Svar::feil('Fant ikke kurset.', 404);
        }

        // Har noen meldt seg paa, kan raden ikke forsvinne: bookingene og
        // betalingene peker paa den, og de er bokforingspliktige. Kurset
        // avlyses i stedet — det er det sletting egentlig betyr her.
        $pameldte = (int) DB::verdi(
            "SELECT COUNT(*) FROM bookings
              WHERE course_id = :c AND status IN ('betalt','reservert')",
            ['c' => $kursId]
        );

        if ($pameldte > 0) {
            DB::oppdater('courses', ['status' => 'avlyst'], ['id' => $kursId]);
            DB::kjor(
                "UPDATE course_sessions SET status = 'avlyst'
                  WHERE course_id = :c AND status = 'planlagt'",
                ['c' => $kursId]
            );
            revider('kurs_avlyst', 'course', $kursId, ['pameldte' => $pameldte]);
            Svar::ok([
                'slettet' => false,
                'beskjed' => $pameldte === 1
                    ? 'Én har meldt seg på, så kurset er avlyst og tatt av nettsiden i stedet for slettet. Husk å gi beskjed og refundere.'
                    : $pameldte . ' har meldt seg på, så kurset er avlyst og tatt av nettsiden i stedet for slettet. Husk å gi beskjed og refundere.',
            ]);
        }

        // Ingen paameldte: datoene og eventuelle faste ukedager foelger med.
        if (DB::harTabell('kurs_serier')) {
            DB::kjor('DELETE FROM kurs_serier WHERE course_id = :c', ['c' => $kursId]);
        }
        DB::kjor('DELETE FROM course_sessions WHERE course_id = :c', ['c' => $kursId]);
        DB::kjor('DELETE FROM courses WHERE id = :i', ['i' => $kursId]);
        revider('kurs_slettet', 'course', $kursId, ['tittel' => $kurs['tittel']]);
        Svar::ok(['slettet' => true, 'beskjed' => '«' . $kurs['tittel'] . '» er slettet.']);

    default:
        Svar::feil('Ukjent handling.');
}
