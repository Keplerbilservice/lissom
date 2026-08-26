<?php
/**
 * Ventelista.
 *
 *   GET                    hvem som venter, per kurs
 *   POST handling=varsle   gi beskjed om ledig plass  { id }
 *   POST handling=fjern    ta noen av lista           { id }
 *   POST handling=gi-plass  sett hen rett inn paa en dato { id, oktId }
 *
 * Varsling sender SMS og e-post med en frist. Plassen holdes ikke av
 * automatisk — den forste som booker, faar den. Det staar ogsaa i meldingen,
 * saa ingen tror de har en reservasjon de ikke har.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle(
        "SELECT w.*, c.tittel, c.slug
           FROM waitlist w
           JOIN courses c ON c.id = w.course_id
          WHERE w.status IN ('venter','varslet')
          ORDER BY c.tittel, w.posisjon"
    );

    /**
     * Datoene denne personen kan settes rett inn paa.
     *
     * Ventelista fantes for aa varsle om en ledig plass og haape at hen booket
     * for noen andre rakk det. Men verkstedet vet ofte hvem som skal ha
     * plassen — og da skal de kunne gi den, ikke sende en beskjed om aa
     * kappes om den.
     *
     * Bare datoer det faktisk er plass paa. En liste med fulle kvelder er en
     * knapp som sier nei.
     */
    $datoer = static function (int $kursId): array {
        $ut = [];
        foreach (DB::alle(
            "SELECT cs.id, cs.start_tid, cs.course_id, c.tittel
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt'
                AND cs.start_tid > UTC_TIMESTAMP()
                AND c.type <> 'dropin'
           ORDER BY cs.start_tid
              LIMIT 60"
        ) as $o) {
            $ledige = Booking::ledigePlasser((int) $o['id']);
            if ($ledige <= 0) {
                continue;
            }
            $ut[] = [
                'oktId'  => (int) $o['id'],
                'kurs'   => (string) $o['tittel'],
                'naar'   => Booking::norskDato((string) $o['start_tid']),
                'ledige' => $ledige,
                // Er datoen paa kurset hen faktisk venter paa? Da hoerer den
                // hjemme foerst i lista.
                'eget'   => (int) $o['course_id'] === $kursId,
            ];
        }
        // Hennes eget kurs foerst, resten etter dato. Rekkefolgen er en
        // anbefaling, ikke en sperre — alt som har plass staar der.
        usort($ut, static fn($a, $b) => ($b['eget'] <=> $a['eget']));
        return $ut;
    };

    Svar::json(['venteliste' => array_map(static function ($w) use ($datoer) {
        $valg = $datoer((int) $w['course_id']);
        return [
            'id'       => (int) $w['id'],
            'navn'     => $w['navn'],
            'epost'    => $w['epost'],
            'telefon'  => $w['telefon'],
            'kurs'     => $w['tittel'],
            'kursId'   => (int) $w['course_id'],
            'posisjon' => (int) $w['posisjon'],
            'status'   => $w['status'] === 'varslet' ? 'Varslet' : 'Venter',
            'varslet'  => $w['varslet_at'] ? Booking::norskDato((string) $w['varslet_at']) : null,
            'siden'    => Booking::norskDato((string) $w['created_at']),
            // Datoene hen kan settes rett inn paa, og den forste av dem.
            'datoer'   => $valg,
            'kanGiPlass' => $valg !== [],
        ];
    }, $rader)]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$id = Foresporsel::heltall('id');
$rad = DB::en(
    'SELECT w.*, c.tittel, c.slug FROM waitlist w JOIN courses c ON c.id = w.course_id WHERE w.id = :i',
    ['i' => $id]
);
if ($rad === null) {
    Svar::feil('Fant ikke oppforingen.', 404);
}

switch (Foresporsel::tekst('handling')) {

    case 'varsle':
        $frist = gmdate('Y-m-d H:i:s', time() + 24 * 3600);
        $lenke = Config::nettsted() . '/kurs';

        Varsel::mal('venteliste_ledig', [
            // Navnet er med for at et varsel som maa tas for haand skal si
            // hvem det gjelder, ikke bare et telefonnummer.
            'navn'    => $rad['navn'],
            'epost'   => $rad['epost'],
            'telefon' => $rad['telefon'],
        ], [
            'navn'  => (string) $rad['navn'],
            'kurs'  => (string) $rad['tittel'],
            'dato'  => '',
            'lenke' => $lenke,
        ], 'waitlist', $id);

        DB::oppdater('waitlist', [
            'status'     => 'varslet',
            'varslet_at' => gmdate('Y-m-d H:i:s'),
            'frist_at'   => $frist,
        ], ['id' => $id]);

        revider('venteliste_varslet', 'waitlist', $id, ['kurs' => $rad['tittel']]);

        Svar::ok([
            'beskjed' => 'Beskjed lagt i kø til ' . $rad['navn']
                . '. Plassen holdes ikke av automatisk — første som booker, får den.',
        ]);

    // ── Gi plassen ────────────────────────────────────────────────────
    //
    // «Varsle» sier fra at det er ledig, og saa er det foerste mann til
    // moella. Det er riktig naar flere staar og venter paa det samme. Men
    // ofte vet verkstedet hvem som skal ha plassen — og da er et varsel en
    // omvei, og en risiko for at feil person rekker det.
    //
    // Dette er en helt vanlig paamelding, som en lagt inn for haand: den
    // opptar en plass, teller i kapasiteten og staar paa deltakerlista.
    // Reservert og ikke betalt — hen har ikke betalt noe enda, og det skal
    // ikke se ut som hen har.
    case 'gi-plass':
        $oktId = Foresporsel::heltall('oktId');

        $okt = DB::en(
            "SELECT cs.id, cs.course_id, cs.start_tid, cs.status, c.tittel, c.pris_ore
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :o",
            ['o' => $oktId]
        );
        if ($okt === null) {
            Svar::feil('Velg en dato som finnes.');
        }
        // Her sto en sperre mot datoer paa andre kurs. Den var riktig saa
        // lenge lista bare tilbod hennes eget — men sier hun at hun heller
        // vil paa plateteknikk til uka, skal hun kunne settes rett inn der.
        // Bookinga gaar uansett paa kurset datoen hoerer til, ikke paa det
        // hun sto og ventet paa.
        if ($okt['status'] === 'avlyst') {
            Svar::feil('Den datoen er avlyst.');
        }

        // Plassen maa finnes. Uten sjekken kunne to fra lista faa den samme
        // stolen, og det oppdages foerst den kvelden.
        if (Booking::ledigePlasser($oktId) < 1) {
            Svar::feil('Den datoen er full nå. Velg en annen, eller varsle i stedet.');
        }

        $bookingId = DB::iTransaksjon(static function () use ($okt, $oktId, $rad): int {
            return DB::settInn('bookings', [
                'course_id'         => (int) $okt['course_id'],
                'course_session_id' => $oktId,
                'member_id'         => null,
                'gjest_navn'        => $rad['navn'],
                'gjest_epost'       => $rad['epost'] ?: null,
                'gjest_telefon'     => $rad['telefon'] ?: null,
                'antall'            => 1,
                'belop_ore'         => (int) $okt['pris_ore'],
                // Ikke betalt enda. «Betalt» her ville satt et beloep i
                // regnskapet for penger som ikke har kommet.
                'status'            => 'reservert',
                'betalt_maate'      => 'Betaler ved oppmøte',
                'notat'             => 'Fra ventelista',
                'reservert_til'     => null,
            ]);
        });

        DB::oppdater('waitlist', ['status' => 'booket'], ['id' => $id]);

        // Hen skal vite det. En plass ingen har fortalt om, er ingen plass.
        $varslet = false;
        if (($rad['epost'] ?? '') !== '' || ($rad['telefon'] ?? '') !== '') {
            // Egen mal for en plass som er gitt. «venteliste_ledig» sier
            // «foerst til moella — book her», og det er feil naar stolen alt
            // er hennes: beskjeden ba henne kappes om noe hun hadde faatt.
            // Faller tilbake paa den gamle om migrasjon 054 ikke er kjort.
            Varsel::mal(
                DB::verdi("SELECT navn FROM notification_templates WHERE navn = 'venteliste_tildelt' AND aktiv = 1")
                    ? 'venteliste_tildelt' : 'venteliste_ledig',
            [
                'navn'    => $rad['navn'],
                'epost'   => $rad['epost'],
                'telefon' => $rad['telefon'],
            ], [
                'navn'  => (string) $rad['navn'],
                // Kurset hun fikk, ikke det hun sto paa lista til. Fikk hun
                // plass paa noe annet, ville beskjeden ellers sagt feil kurs.
                'kurs'  => (string) $okt['tittel'],
                'dato'  => Booking::norskDato((string) $okt['start_tid']),
                'lenke' => Config::nettsted() . '/min-side',
            ], 'booking', $bookingId);
            $varslet = true;
        }

        revider('venteliste_gitt_plass', 'waitlist', $id, [
            'booking' => $bookingId,
            'okt'     => $oktId,
            'kurs'    => $okt['tittel'],
            // Sto hun paa lista til noe annet, skal det staa hva.
            'ventet_paa' => (int) $okt['course_id'] === (int) $rad['course_id']
                ? null : $rad['tittel'],
        ]);

        $annet = (int) $okt['course_id'] !== (int) $rad['course_id'];
        Svar::ok([
            'beskjed' => $rad['navn'] . ' har fått plass på ' . $okt['tittel'] . ' '
                       . Booking::norskDato((string) $okt['start_tid'])
                       . '. Står som reservert til betalingen er gjort opp.'
                       . ($annet ? ' Hen sto på ventelista til ' . $rad['tittel']
                                 . ', og er tatt av den.' : '')
                       . ($varslet ? ' Beskjed er lagt i kø.' : ''),
        ]);

    case 'fjern':
        DB::oppdater('waitlist', ['status' => 'fjernet'], ['id' => $id]);
        revider('venteliste_fjernet', 'waitlist', $id);
        Svar::ok(['beskjed' => $rad['navn'] . ' er tatt av lista.']);

    default:
        Svar::feil('Ukjent handling.');
}
