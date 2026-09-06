<?php
/**
 * Paameldinger lagt inn for haand.
 *
 *   POST handling=legg-til   { oktId, navn, epost, telefon, antall,
 *                              betaltMaate, belop, notat, varsle }
 *   POST handling=fjern      { id }
 *   POST handling=flytt      { id, oktId }   samme person, ny dato
 *   POST handling=til-venteliste { id }    gir fra seg plassen, staar i koen
 *   POST handling=status     { id, status }   betalt | reservert | ikke_mott
 *   POST handling=endre      { id, antall?, belop? }   retter antall og sum
 *   POST handling=bevis      { id, navn?, kurs?, sperret? }  retter kursbeviset
 *
 * Ikke alle bestiller paa nett. Noen ringer, noen staar i doera. De maa staa
 * paa samme deltakerliste som alle andre — ellers foerer verkstedet to
 * lister, og den ene stemmer aldri.
 *
 * En manuell paamelding er en helt vanlig booking. Den har ingen betaling
 * knyttet til seg, men den opptar en plass, den teller i kapasiteten, og den
 * kommer med paa deltakerlista og i beskjedene.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$admin = krev_admin();

// Maatene en paamelding kan foeres med. Alle bokforer at noe alt ER gjort opp,
// eller at det ikke er det — ingen av dem sender noe.
//
// «Vippskrav» sto her til 6. september 2026. Eieren: «vippskrav skal slettes»,
// og paa spoersmaal om hvor: overalt. Den kan ikke lenger velges. Gamle
// paameldinger beholder maaten sin i basen — «betalt_maate» er en tekst, og
// lista her sier bare hva som kan settes NAA.
//
// «Vipps» kom i tillegg til «Vipps i verkstedet» da valget paa okta ble kortet
// ned. Begge staar: dagsoppgjoret foerer dem samme sted uansett.
const MAATER = ['Kontant', 'Vipps', 'Vipps i verkstedet', 'Gavekort',
                'Ikke betalt', 'Faktura', 'Betaler ved oppmøte', 'Gratis'];

$handling = Foresporsel::tekst('handling', 'legg-til');
$id       = Foresporsel::heltall('id');

// ------------------------------------------------------------ fjern plass
//
// Raden slettes ikke. En avbestilt booking frigir plassen, men beholder
// sporet — hvem som var paameldt og naar det ble endret.
if ($handling === 'fjern') {
    $b = DB::en(
        'SELECT b.id, b.gjest_navn, b.member_id, b.payment_id, b.status,
                p.status AS betalingsstatus
           FROM bookings b
      LEFT JOIN payments p ON p.id = b.payment_id
          WHERE b.id = :i',
        ['i' => $id]
    );
    if ($b === null) {
        Svar::feil('Fant ikke påmeldingen.');
    }

    // Her sto det at enhver booking med en betalingsrad var betalt gjennom
    // Vipps. Det stemmer ikke: raden lages naar betalingen *startes*, og blir
    // liggende ogsaa naar kunden avbroet eller aldri kom tilbake fra Vipps.
    // En ubetalt paamelding kunne dermed ikke avbestilles i det hele tatt —
    // den ble staaende under «Nye paameldinger» for alltid, med beskjed om aa
    // refundere noe ingen hadde betalt.
    //
    // Det som betyr noe er om pengene faktisk er trukket.
    $BETALT = ['autorisert', 'betalt', 'delvis_refundert'];
    if ($b['payment_id'] !== null && in_array((string) $b['betalingsstatus'], $BETALT, true)) {
        Svar::feil('Denne er betalt gjennom Vipps. Bruk refusjon, ikke sletting.');
    }

    DB::oppdater('bookings', [
        'status'       => 'avbestilt',
        'avbestilt_at' => gmdate('Y-m-d H:i:s'),
    ], ['id' => $id]);

    revider('pamelding_fjernet', 'booking', $id,
            ['navn' => $b['gjest_navn'], 'betaling' => (string) ($b['betalingsstatus'] ?? 'ingen')]);
    Svar::ok(['beskjed' => 'Plassen er frigitt.']);
}

// -------------------------------------------------- fra kurset til koen
//
// Eieren, 6. september: «jeg vil kunne dra deltakere ut av kortet i kalender,
// og jeg vil legge paa 1. venteliste 2. bytt dato».
//
// Hun gir fra seg plassen — den blir ledig for andre — og staar i koen til
// den samme kvelden. Notatet og betalingen roeres ikke: paa spoersmaal om
// penger valgte eieren «Tillat, la betalingen staa».
//
// Ett unntak, og det er det samme som «fjern» alt har: er pengene trukket
// gjennom Vipps, skal plassen refunderes, ikke slettes. Da staar det ekte
// penger bak, og de skal ikke bli liggende uten en plass.
if ($handling === 'til-venteliste') {
    $b = DB::en(
        'SELECT b.id, b.course_id, b.course_session_id, b.status, b.payment_id,
                p.status AS betalingsstatus,
                COALESCE(m.navn, b.gjest_navn) AS navn,
                COALESCE(m.epost, b.gjest_epost) AS epost,
                COALESCE(m.telefon, b.gjest_telefon) AS telefon,
                cs.start_tid, c.tittel
           FROM bookings b
      LEFT JOIN members m ON m.id = b.member_id
      LEFT JOIN payments p ON p.id = b.payment_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
      LEFT JOIN courses c ON c.id = b.course_id
          WHERE b.id = :i',
        ['i' => $id]
    );
    if ($b === null) {
        Svar::feil('Fant ikke påmeldingen.');
    }
    if ((string) $b['status'] === 'avbestilt') {
        Svar::feil('Denne er alt avbestilt.');
    }
    if ((int) ($b['course_session_id'] ?? 0) <= 0) {
        Svar::feil('Påmeldingen står ikke på en dato.');
    }
    $BETALT_VIPPS = ['autorisert', 'betalt', 'delvis_refundert'];
    if ($b['payment_id'] !== null && in_array((string) $b['betalingsstatus'], $BETALT_VIPPS, true)) {
        Svar::feil('Denne er betalt gjennom Vipps. Bruk refusjon, ikke ventelista.');
    }
    if (trim((string) ($b['navn'] ?? '')) === '') {
        Svar::feil('Påmeldingen har ikke noe navn å sette på lista.');
    }

    $kursId = (int) $b['course_id'];
    $oktId  = (int) $b['course_session_id'];

    // Staar hun der alt, skal hun ikke havne to ganger i koen.
    $finnes = DB::en(
        "SELECT id FROM waitlist
          WHERE course_id = :k AND course_session_id = :o
            AND status IN ('venter','varslet')
            AND (epost = :e OR (telefon IS NOT NULL AND telefon = :t))
          LIMIT 1",
        ['k' => $kursId, 'o' => $oktId,
         'e' => (string) ($b['epost'] ?? ''),
         't' => ($b['telefon'] ?? '') !== '' ? $b['telefon'] : null]
    );

    if ($finnes === null) {
        $posisjon = 1 + (int) DB::verdi(
            "SELECT COUNT(*) FROM waitlist
              WHERE course_id = :k AND course_session_id = :o
                AND status IN ('venter','varslet')",
            ['k' => $kursId, 'o' => $oktId]
        );
        DB::settInn('waitlist', [
            'course_id'         => $kursId,
            'course_session_id' => $oktId,
            'navn'              => (string) $b['navn'],
            'epost'             => ($b['epost'] ?? '') !== '' ? $b['epost'] : null,
            'telefon'           => ($b['telefon'] ?? '') !== '' ? $b['telefon'] : null,
            'posisjon'          => $posisjon,
        ]);
    }

    // Plassen frigis. Samme felt som «fjern» setter.
    DB::oppdater('bookings', [
        'status'       => 'avbestilt',
        'avbestilt_at' => gmdate('Y-m-d H:i:s'),
    ], ['id' => $id]);

    revider('pamelding_til_venteliste', 'booking', $id, [
        'okt'  => $oktId,
        'kurs' => (string) ($b['tittel'] ?? ''),
    ]);

    Svar::ok(['beskjed' => $b['navn'] . ' står nå på ventelista for '
                        . $b['tittel'] . ' '
                        . Booking::norskDato((string) $b['start_tid'])
                        . '. Plassen er ledig igjen.']);
}

// ------------------------------------------------------------ flytt plass
//
// Folk blir syke, og en kveld passer ikke lenger. Uten dette matte
// verkstedet avbestille og legge inn paa nytt — og da mistet man betalingen,
// notatet og sporet av at det var den samme personen.
if ($handling === 'flytt') {
    $tilOkt = Foresporsel::heltall('oktId');

    $b = DB::en(
        'SELECT b.id, b.antall, b.course_id, b.course_session_id, b.status,
                COALESCE(m.navn, b.gjest_navn) AS navn,
                COALESCE(m.epost, b.gjest_epost) AS epost,
                fra.start_tid AS fra_tid, frak.tittel AS fra_kurs
           FROM bookings b
      LEFT JOIN members m ON m.id = b.member_id
      LEFT JOIN course_sessions fra ON fra.id = b.course_session_id
      LEFT JOIN courses frak ON frak.id = fra.course_id
          WHERE b.id = :i',
        ['i' => $id]
    );
    if ($b === null) {
        Svar::feil('Fant ikke påmeldingen.');
    }
    if ($b['status'] === 'avbestilt') {
        Svar::feil('Denne er avbestilt. Legg personen til på nytt i stedet.');
    }

    $okt = DB::en(
        'SELECT cs.id, cs.course_id, cs.start_tid, cs.status, c.tittel
           FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id
          WHERE cs.id = :o',
        ['o' => $tilOkt]
    );
    if ($okt === null) {
        Svar::feil('Fant ikke datoen.');
    }
    if ($okt['status'] === 'avlyst') {
        Svar::feil('Den datoen er avlyst.');
    }
    if ((int) $okt['id'] === (int) $b['course_session_id']) {
        Svar::feil('Personen står allerede på den datoen.');
    }

    // Plassen maa finnes. Uten sjekken kunne man flytte fem personer inn paa
    // en kveld med to plasser, og verkstedet oppdaget det den kvelden.
    $ledige = Booking::ledigePlasser($tilOkt);
    $trenger = max(1, (int) $b['antall']);
    if ($ledige < $trenger) {
        Svar::feil($ledige <= 0
            ? 'Den datoen er full.'
            : 'Det er bare ' . $ledige . ' plass' . ($ledige === 1 ? '' : 'er')
              . ' igjen, og denne påmeldingen trenger ' . $trenger . '.');
    }

    // Kurset foelger datoen. Flyttes noen til et annet kurs, skal
    // paameldingen hore til det kurset — ellers staar den i feil liste.
    DB::oppdater('bookings', [
        'course_session_id' => $tilOkt,
        'course_id'         => (int) $okt['course_id'],
    ], ['id' => $id]);

    revider('pamelding_flyttet', 'booking', $id, [
        'fra' => (int) $b['course_session_id'],
        'til' => $tilOkt,
    ]);

    // ── Deltakeren skal vite det ────────────────────────────────────────
    //
    // Flyttingen sendte ingenting for. Svaret sa «Husk aa gi beskjed.», og da
    // sto det paa at noen faktisk husket. Gjorde ingen det, motte deltakeren
    // opp paa en kveld hun ikke lenger var satt opp paa.
    //
    // Eieren, 6. september, om «Bytt dato» i kalenderen: e-post hver gang, og
    // trykket i bekreftelsen er det som sender den. Har hun ingen adresse,
    // sendes ingenting — og da sier svaret det, i stedet for aa paastaa at
    // beskjeden er gitt.
    $tilTekst = Booking::norskDato((string) $okt['start_tid']);
    $varslet = false;
    if (($b['epost'] ?? '') !== '') {
        Varsel::mal('pamelding_flyttet', ['epost' => $b['epost']], [
            'navn'  => (string) ($b['navn'] ?: ''),
            'kurs'  => (string) $okt['tittel'],
            'fra'   => $b['fra_tid'] ? Booking::norskDato((string) $b['fra_tid']) : '',
            'til'   => $tilTekst,
            'lenke' => Config::nettsted() . '/min-side',
        ], 'booking', $id);
        $varslet = true;
    }

    Svar::ok(['beskjed' => ($b['navn'] ?: 'Påmeldingen') . ' er flyttet til '
                         . $okt['tittel'] . ' ' . $tilTekst . '. '
                         . ($varslet ? 'Beskjeden er sendt.' : 'Husk å gi beskjed.')]);
}

// ---------------------------------------------------------- endre status
if ($handling === 'status') {
    $status = Foresporsel::tekst('status');
    if (!in_array($status, ['betalt', 'reservert', 'ikke_mott'], true)) {
        Svar::feil('Ukjent status.');
    }
    if (DB::en('SELECT id FROM bookings WHERE id = :i', ['i' => $id]) === null) {
        Svar::feil('Fant ikke påmeldingen.');
    }

    $felt = [
        'status'        => $status,
        // En reservasjon lagt inn for haand skal ikke frigis av seg selv.
        // Verkstedet vet hvem det er, og rydder selv.
        'reservert_til' => null,
    ];

    // Hvordan den ble gjort opp.
    //
    // Kortet «Ikke betalt» paa Oversikt krever inn med ett trykk, og da maa
    // maaten foelge med — ellers staar plassen som betalt uten at noe sier
    // hvor pengene kom fra, og dagsoppgjoret vet ikke hvilken motkonto den
    // hoerer til. Bare naar den settes til betalt: en plass som settes
    // tilbake til reservert har ikke lenger en maate.
    $nyMaate = Foresporsel::tekst('maate');
    if ($status === 'betalt' && in_array($nyMaate, MAATER, true)) {
        $felt['betalt_maate'] = $nyMaate;
    }

    DB::oppdater('bookings', $felt, ['id' => $id]);

    revider('pamelding_status', 'booking', $id,
            ['status' => $status] + ($nyMaate !== '' ? ['maate' => $nyMaate] : []));
    Svar::ok(['beskjed' => 'Statusen er endret.']);
}

// ---------------------------------------------------------------- endre
//
// Retter antallet plasser, og beloepet.
//
// Eieren, 5. september: «Jeg har et kurs som jeg har meldt paa 10 personer.
// Saa kom det bare 6 stk. Jeg kan legge til flere osv, men ikke redigere
// antall som kom. Legg til dette, paa samme sted som jeg legger til.» Og:
// «Jeg maa endre antall og jeg maa kunne overstyre pris ved aa taste inn.»
//
// Fem handlinger fantes — legg til, fjern, flytt, status, kursbevis — og
// ingen av dem kunne rette et tall som var skrevet inn feil, eller som ble
// feil fordi ikke alle moette.
//
// «bookings.antall» styrer fire ting: plassene som er opptatt paa datoen,
// summen paa deltakerlista, beloepet, og hva verkstedet har krav paa. Derfor
// er beloepet med her: eieren skal ikke maatte rette antallet ett sted og
// pengene et annet.
//
// Beloepet foelger antallet naar det ikke tastes inn, og lar seg overstyre
// naar det gjor det. En plass som er gitt bort staar da paa null uten at
// antallet maa lyve om hvor mange som kom.
if ($handling === 'endre') {
    $rad = DB::en(
        'SELECT b.id, b.antall, b.belop_ore, b.course_session_id, b.status
           FROM bookings b WHERE b.id = :i',
        ['i' => $id]
    );
    if ($rad === null) {
        Svar::feil('Fant ikke påmeldingen.');
    }

    $felt = [];
    $nyttAntall = $rad['antall'];

    // Antallet. Samme grenser som naar plassen legges inn: minst én, hoyst
    // tjue. Null plasser er ikke en paamelding — den fjernes.
    if (Foresporsel::tekst('antall') !== '') {
        $nyttAntall = Foresporsel::heltall('antall');
        if ($nyttAntall < 1 || $nyttAntall > 20) {
            Svar::feil('Antallet må være mellom 1 og 20. Skal plassen bort, fjern den i stedet.');
        }
        $felt['antall'] = $nyttAntall;
    }

    // Beloepet. Tomt felt og et endret antall betyr «regn det ut paa nytt»:
    // prisen paa datoen gaar foran prisen paa kurset, samme uttrykk som naar
    // plassen legges inn — se lenger nede — saa de to ikke kan bli uenige.
    $belopRaa = trim(Foresporsel::tekst('belop'));
    if ($belopRaa !== '') {
        $belop = (int) round((float) str_replace(',', '.', $belopRaa) * 100);
        if ($belop < 0 || $belop > 10000000) {
            Svar::feil('Beløpet må være mellom 0 og 100 000 kroner.');
        }
        $felt['belop_ore'] = $belop;
    } elseif (isset($felt['antall'])) {
        $prisKol = DB::harKolonne('course_sessions', 'pris_ore')
            ? 'COALESCE(cs.pris_ore, c.pris_ore)' : 'c.pris_ore';
        $pris = DB::verdi(
            "SELECT {$prisKol} FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :i",
            ['i' => (int) $rad['course_session_id']]
        );
        if ($pris !== null) {
            $felt['belop_ore'] = (int) $pris * $nyttAntall;
        }
    }

    if ($felt === []) {
        Svar::feil('Ingenting å endre.');
    }

    DB::oppdater('bookings', $felt, ['id' => $id]);

    // Loggen skal si hva som sto for og hva som staar naa. Et tall som
    // endrer seg uten spor er det samme som et tall ingen kan etterproeve.
    revider('pamelding_endret', 'booking', $id, [
        'antall_for' => (int) $rad['antall'],
        'antall_naa' => (int) ($felt['antall'] ?? $rad['antall']),
        'belop_for'  => (int) $rad['belop_ore'],
        'belop_naa'  => (int) ($felt['belop_ore'] ?? $rad['belop_ore']),
    ]);

    Svar::ok([
        'beskjed' => 'Påmeldingen er rettet.',
        'antall'  => (int) ($felt['antall'] ?? $rad['antall']),
        'belop'   => Booking::kroner((int) ($felt['belop_ore'] ?? $rad['belop_ore'])),
    ]);
}

// ------------------------------------------------------------------ bevis
//
// Kursbeviset bygges av paameldingen, og det er riktig — helt til noe er feil.
// Er navnet stavet feil, eller staar det feil kurs paa arket, hadde verkstedet
// ingen vei til aa rette det. Og gikk noen fra kurset for tidlig, kunne
// beviset ikke trekkes.
if ($handling === 'bevis') {
    if (!DB::harKolonne('bookings', 'bevis_navn')) {
        Svar::feil('Retting av kursbevis krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.', 503);
    }
    if (DB::en('SELECT id FROM bookings WHERE id = :i', ['i' => $id]) === null) {
        Svar::feil('Fant ikke påmeldingen.');
    }

    $data = [];
    if (array_key_exists('navn', Foresporsel::kropp())) {
        $data['bevis_navn'] = mb_substr(trim(Foresporsel::tekst('navn')), 0, 191) ?: null;
    }
    if (array_key_exists('kurs', Foresporsel::kropp())) {
        $data['bevis_kurs'] = mb_substr(trim(Foresporsel::tekst('kurs')), 0, 191) ?: null;
    }
    if (array_key_exists('sperret', Foresporsel::kropp())) {
        $data['bevis_sperret'] = Foresporsel::tekst('sperret') === 'ja' ? 1 : 0;
    }
    if (!$data) {
        Svar::feil('Ingenting å endre.');
    }

    DB::oppdater('bookings', $data, ['id' => $id]);
    revider('kursbevis_endret', 'booking', $id, $data);

    Svar::ok(['beskjed' => array_key_exists('bevis_sperret', $data)
        ? ($data['bevis_sperret'] ? 'Kursbeviset er trukket tilbake.' : 'Kursbeviset er tilgjengelig igjen.')
        : 'Kursbeviset er rettet.']);
}

// -------------------------------------------------------------- legg til
$oktId  = Foresporsel::heltall('oktId');
$navn   = mb_substr(Foresporsel::tekst('navn'), 0, 191);
$antall = max(1, min(20, Foresporsel::heltall('antall', 1)));

if ($navn === '') {
    Svar::feil('Deltakeren må ha et navn.');
}

// Prisen paa datoen gaar foran prisen paa kurset.
//
// «Prisen kan avvike paa én dato» — det er en egen kolonne, og nettsida og
// Booking::forOkt() har alltid lest den. Her sto bare kursets pris, saa en
// dato med egen pris ble ført til feil sum naar beløpsfeltet sto tomt. Samme
// uttrykk som app/lib/booking.php, saa de to ikke kan bli uenige.
$prisKol = DB::harKolonne('course_sessions', 'pris_ore')
    ? 'COALESCE(cs.pris_ore, c.pris_ore)' : 'c.pris_ore';

$okt = DB::en(
    "SELECT cs.id, cs.course_id, cs.start_tid, c.tittel, {$prisKol} AS pris_ore
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
      WHERE cs.id = :i AND cs.status <> :a",
    ['i' => $oktId, 'a' => 'avlyst']
);
if ($okt === null) {
    Svar::feil('Velg en dato som finnes.');
}

$epost   = mb_substr(Foresporsel::tekst('epost'), 0, 191);
$telefon = normaliser_telefon(Foresporsel::tekst('telefon'));

if ($epost !== '' && !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
    Svar::feil('E-postadressen ser ikke riktig ut.');
}

$maate = Foresporsel::tekst('betaltMaate');
if (!in_array($maate, MAATER, true)) {
    // Staar det ingenting, er ingenting betalt. Sto paa «Kontant», og da ble
    // en paamelding uten valgt maate bokfoert som gjort opp. Eieren, 6.
    // september: «jeg valgte ingen betalingsmaaten, men hun kom inn som
    // betalt, det stemmer ikke! Default maa vaere ikke betalt.»
    $maate = 'Ikke betalt';
}

// Belopet: tomt felt betyr prisen paa datoen. «Gratis» er null kroner,
// uansett hva som staar i feltet — ellers ville en fribillett kunnet vise en
// sum i regnskapet.
$belopRaa = Foresporsel::tekst('belop');
$belop = $maate === 'Gratis'
    ? 0
    : ($belopRaa === '' ? (int) $okt['pris_ore'] * $antall : Foresporsel::heltall('belop') * 100);
if ($belop < 0 || $belop > 10000000) {
    Svar::feil('Beløpet må være mellom 0 og 100 000 kroner.');
}

// «Betaler ved oppmote» er ikke betalt enda. Resten er gjort opp i det
// oyeblikket eieren registrerer dem. «Vippskrav» staar igjen i lista selv om
// maaten ikke kan velges lenger: gamle rader har den, og de skal fortsatt
// telle som ubetalt.
// «Ikke betalt» sier det rett ut: plassen er gitt, pengene er ikke kommet.
// Den staar som reservert til den er gjort opp, og dukker opp paa kortet
// «Ikke betalt» paa Oversikt til den er det.
$status = in_array($maate, ['Betaler ved oppmøte', 'Vippskrav', 'Ikke betalt'], true)
    ? 'reservert' : 'betalt';

// ── Gavekortet ───────────────────────────────────────────────────────
//
// Et gavekort er ikke en maate aa notere paa — det er penger som alt er
// betalt inn, og som skal trekkes fra kortet. Gjor vi ikke det, staar kortet
// med full saldo og kan brukes om igjen, og gavekortgjelda blir aldri
// nedskrevet.
//
// Kortet finnes for plassen legges inn. Er koden ukjent, utgaatt eller har
// for lite igjen, skjer ingenting — det er verre aa ha en plass som ser
// betalt ut enn en som ikke ble lagt inn.
$kort = null;
if ($maate === 'Gavekort') {
    $kort = Booking::finnGavekort(Foresporsel::tekst('kode'));
    if ($kort === null) {
        Svar::feil('Fant ikke gavekortet. Sjekk koden — den kan være brukt opp '
                 . 'eller gått ut på dato.');
    }
    if ($kort['saldo_ore'] < $belop) {
        Svar::feil('Gavekortet har bare ' . Booking::kroner($kort['saldo_ore'])
                 . ' igjen, og plassen koster ' . Booking::kroner($belop)
                 . '. Ta resten på en annen måte.');
    }
}

// Er noen alt paameldt med samme navn paa samme dato, er det trolig et
// dobbelttrykk. Vi legger ikke inn to.
$fra = DB::en(
    "SELECT id FROM bookings
      WHERE course_session_id = :o AND gjest_navn = :n AND status <> 'avbestilt'",
    ['o' => $oktId, 'n' => $navn]
);
if ($fra !== null) {
    Svar::feil($navn . ' står alt på denne datoen.');
}

$ledige = Booking::ledigePlasser($oktId);

$bookingId = DB::iTransaksjon(static function () use ($okt, $oktId, $navn, $epost, $telefon, $antall, $belop, $status, $maate, $admin): int {
    return DB::settInn('bookings', [
        'course_id'         => (int) $okt['course_id'],
        'course_session_id' => $oktId,
        'member_id'         => null,
        'gjest_navn'        => $navn,
        'gjest_epost'       => $epost !== '' ? $epost : null,
        'gjest_telefon'     => $telefon !== '' ? $telefon : null,
        'antall'            => $antall,
        'belop_ore'         => $belop,
        'status'            => $status,
        'betalt_maate'      => $maate,
        'lagt_inn_av'       => (int) $admin['id'],
        'notat'             => mb_substr(Foresporsel::tekst('notat'), 0, 255) ?: null,
        'reservert_til'     => null,
    ]);
});

// ── Trekket fra gavekortet ───────────────────────────────────────────
//
// Beloepet henges paa en betalingsrad slik en nettbetaling gjor, saa
// Booking::trekkGavekort() kan gjore jobben sin — den samme som ved et kjop
// paa nettsida, med det samme sporet i «gift_card_uses». Raden er «manuell»
// og null kroner i penger: det kom ingen penger inn i dag, kortet ble brukt.
if ($maate === 'Gavekort' && $kort !== null) {
    $betalingId = DB::settInn('payments', [
        'vipps_reference' => 'GAVE-' . strtoupper(bin2hex(random_bytes(4))),
        'type'            => 'manuell',
        'formal'          => 'booking',
        'belop_ore'       => 0,
        'gavekort_id'     => $kort['id'],
        'gavekort_ore'    => $belop,
        'status'          => 'betalt',
        'booking_id'      => DB::harKolonne('payments', 'booking_id') ? $bookingId : null,
        'idempotency_key' => Vipps::uuid(),
    ]);
    DB::oppdater('bookings', ['payment_id' => $betalingId], ['id' => $bookingId]);
    Booking::trekkGavekort($betalingId);
    revider('gavekort_brukt', 'booking', $bookingId,
            ['kort' => $kort['id'], 'belop' => $belop]);
}

// Bekreftelse sendes bare naar eieren ber om det, og bare naar vi har en
// adresse aa sende til. En som melder seg paa i doera venter ikke e-post.
$varslet = false;
if (Foresporsel::tekst('varsle') === 'ja' && $epost !== '') {
    Booking::sendBekreftelse($bookingId);
    $varslet = true;
}

revider('pamelding_lagt_inn', 'booking', $bookingId, [
    'navn' => $navn, 'okt' => $oktId, 'maate' => $maate, 'belop_ore' => $belop,
]);

// Eieren bestemmer over sitt eget rom. Vi stopper ikke en niende deltaker —
// men vi sier fra, saa det ikke skjer uten at noen ser det.
$advarsel = $antall > $ledige
    ? ' Merk: datoen er nå overbooket med ' . ($antall - $ledige) . '.'
    : '';

Svar::ok([
    'id'      => $bookingId,
    'beskjed' => $navn . ' er lagt til på ' . $okt['tittel'] . ' '
                . Booking::norskDato((string) $okt['start_tid']) . '.'

                . ($kort !== null
                    ? ' Betalt med gavekort ' . $kort['kode'] . '. Igjen på kortet: '
                      . Booking::kroner(max(0, $kort['saldo_ore'] - $belop)) . '.'
                    : '')
                . ($varslet ? ' Bekreftelse er sendt.' : '')
                . $advarsel,
]);
