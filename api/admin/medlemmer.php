<?php
/**
 * Medlemsregisteret.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$jeg = krev_admin();

// ── Meld inn et medlem for haand ───────────────────────────────────────
//
//   POST handling=meld-inn   { navn, epost, telefon, type, notat, medlemId? }
//   POST handling=avslutt    { medlemId }   medlemskapet tar slutt i dag
//   POST handling=gjenapne   { medlemId }   aktivt igjen
//   POST handling=slett      { medlemId }   personopplysningene fjernes
//   POST handling=notat      { medlemId, notat }   annen info om personen
//   POST handling=knytt      { medlemId, bookingId }  gjestepaamelding til konto
//
// Ikke alle soker paa nett. Noen staar i doera, noen ringer, og noen har
// vaert paa kurs i et halvt aar for de bestemmer seg. Uten dette matte
// verkstedet be dem gaa hjem og fylle ut et skjema.
//
// Det opprettes ingen Vipps-avtale her. En manuell innmelding betales slik
// verkstedet avtaler det — faktura, kontant, eller ingenting. Skal trekket
// gaa av seg selv, maa medlemmet sette det opp selv fra Min side.
if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();

    $handling = Foresporsel::tekst('handling', 'meld-inn');

    // ── Avslutt, ta inn igjen, slett ───────────────────────────────────
    //
    // Et medlemskap tok aldri slutt av seg selv med mindre Vipps-avtalen
    // stoppet. En som var meldt inn for haand sto som aktiv i all
    // evighet, og lista blandet dem som betaler med dem som sluttet i
    // fjor.
    if (in_array($handling, ['avslutt', 'gjenapne', 'slett'], true)) {
        $id = Foresporsel::heltall('medlemId');
        $m = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);
        if ($m === null) {
            Svar::feil('Fant ikke medlemmet.', 404);
        }
        if ((int) $m['id'] === (int) $jeg['id'] && $handling === 'slett') {
            Svar::feil('Du kan ikke slette din egen konto.');
        }
        // Ingen med admintilgang slettes herfra.
        //
        // Medlemslista skjuler alt «Slett» paa admin-rader — men serveren
        // gjorde det ikke, og et loefte skjermen gir uten at serveren holder
        // det, er ikke et loefte. Et kall sendt direkte slettet raden.
        //
        // Adminkontoer hoerer hjemme under Brukere. Der staar reglene som
        // hoerer til dem: den siste admin-en kan verken slettes eller settes
        // ned, og noedluke-numrene i secrets.php teller med i den vurderingen.
        // To doerer til den samme slettingen, med regler bare bak den ene, er
        // hvordan man laaser seg ute av sitt eget adminpanel.
        if ($handling === 'slett' && $m['rolle'] === 'admin') {
            Svar::feil('Denne kontoen har tilgang til admin. '
                     . 'Slike kontoer håndteres under Brukere, ikke herfra.');
        }

        // «plan» maa vaere med: den bestemmer oppsigelsestida.
        $avtale = DB::en(
            "SELECT id, plan, vipps_agreement_id FROM subscriptions
              WHERE member_id = :m AND status = 'aktiv' LIMIT 1",
            ['m' => $id]
        );

        if ($handling === 'avslutt') {
            // Én regel for oppsigelse, uansett hvem som sier opp.
            //
            // Her sto to: medlemmet som sa opp selv fikk maaneden det hadde
            // betalt for, mens verkstedet som sa opp for dem satte sluttdatoen
            // til i dag — og medlemmet mistet tilgangen samme sekund. Sier
            // eieren opp for noen som har ringt, skal det telle likt.
            //
            // Datoen er den samme regelen som paa Min side: siste dag i
            // maaneden oppsigelsen kommer, pluss oppsigelsestida. Se
            // Medlemskap::sluttdato().
            $slutter = Medlemskap::sluttdato($avtale ?? ['plan' => (string) ($m['medlemskap_type'] ?? '')]);

            // Loeper det en avtale i Vipps, stoppes den ikke naa: medlemmet
            // skal trekkes ut oppsigelsestida, og cron stopper avtalen den
            // dagen den gaar ut — samme vei som naar medlemmet sier opp selv.
            // Her ble hele avslutningen avvist i stedet, saa eieren maatte
            // inn i Vipps for haand og medlemskapet ble haengende.
            if ($avtale !== null) {
                DB::oppdater('subscriptions', [
                    'sagt_opp_at' => gmdate('Y-m-d H:i:s'),
                    'slutter'     => $slutter,
                ], ['id' => (int) $avtale['id']]);
            }

            // Statusen staar til datoen er ute. Cron setter «oppsagt» naar
            // sluttdatoen har passert — baade for dem med avtale og dem som
            // er meldt inn for haand.
            DB::oppdater('members', ['slutt_dato' => $slutter], ['id' => $id]);

            revider('medlem_avsluttet', 'member', $id,
                    ['av' => (int) $jeg['id'], 'slutter' => $slutter,
                     'avtale' => $avtale === null ? null : (int) $avtale['id']]);

            $naar = Booking::norskDatoKort($slutter . ' 12:00:00');
            Svar::ok(['beskjed' => ($m['navn'] ?: 'Medlemmet') . ' er sagt opp, og medlemskapet '
                                 . 'gjelder ut ' . $naar . '.'
                                 . ($avtale !== null
                                     ? ' Vipps-avtalen løper til da og stoppes automatisk.'
                                     : '')
                                 . ' Historikken og kursbevisene er beholdt.']);
        }

        if ($handling === 'gjenapne') {
            // Oppsigelsen trekkes tilbake, ikke bare statusen. Uten dette ble
            // «slutter» staaende paa avtalen, og cron stoppet den i Vipps paa
            // den gamle datoen — et medlem som var aapnet igjen ville mistet
            // trekket uten at noe sa fra.
            DB::kjor(
                "UPDATE subscriptions SET sagt_opp_at = NULL, slutter = NULL
                  WHERE member_id = :m AND status = 'aktiv'",
                ['m' => $id]
            );
            DB::oppdater('members', [
                'status'     => 'aktiv',
                'start_dato' => date('Y-m-d'),
                'slutt_dato' => null,
            ], ['id' => $id]);
            revider('medlem_gjenapnet', 'member', $id, ['av' => (int) $jeg['id']]);
            Svar::ok(['beskjed' => ($m['navn'] ?: 'Medlemmet') . ' er aktivt medlem igjen.']);
        }

        // Sletting.
        //
        // Bookinger og betalinger er bokforingspliktige og maa bli staaende
        // — fremmednoklene peker paa raden. Vi fjerner derfor personen, ikke
        // raden: navn, kontaktinfo og innlogging tommes, og anonymisert_at
        // settes. Resten av koden ser allerede etter den kolonnen, saa
        // personen forsvinner fra lister, beskjeder og innlogging.
        if ($avtale !== null) {
            Svar::feil('Medlemmet har en løpende Vipps-avtale. Si den opp før du sletter.');
        }

        $harHistorikk = (int) DB::verdi(
            'SELECT (SELECT COUNT(*) FROM bookings WHERE member_id = :a)
                  + (SELECT COUNT(*) FROM payments WHERE member_id = :b)',
            ['a' => $id, 'b' => $id]
        ) > 0;

        DB::kjor('DELETE FROM sessions WHERE member_id = :id', ['id' => $id]);

        // Vi proever aa slette raden, og lar basen si nei.
        //
        // Sjekken over teller paameldinger og betalinger. Men ti tabeller
        // peker paa members — chat, innstemplinger, timer, gaver, soknader,
        // medlemssalg, abonnementer — og pekte én av dem hit, feilet
        // slettingen med en raa SQL-feil og et 500-svar. Det som sto igjen
        // paa skjermen var «Gikk ikke», uten et ord om hvorfor.
        //
        // Aa telle opp alle ti hadde virket i dag og vaert feil igjen neste
        // gang noen legger til en tabell. Basen vet allerede hvem som peker
        // hit; vi spor den i stedet, og anonymiserer naar svaret er nei.
        if (!$harHistorikk) {
            try {
                DB::kjor('DELETE FROM members WHERE id = :id', ['id' => $id]);
                revider('medlem_slettet', 'member', $id, ['navn' => $m['navn']]);
                Svar::ok(['beskjed' => 'Medlemmet er slettet.']);
            } catch (Throwable $e) {
                // Noe hoerer til personen likevel. Da anonymiseres raden, som
                // under — det er den samme trygge utgangen.
                logg('Medlem kunne ikke slettes helt, anonymiseres i stedet', ['id' => $id]);
            }
        }

        DB::oppdater('members', [
            'navn'            => 'Slettet medlem',
            'epost'           => null,
            'telefon'         => null,
            'vipps_sub'       => null,
            'brukernavn'      => null,
            'passord_hash'    => null,
            'notat'           => null,
            'medlemskap_type' => null,
            'status'          => 'ingen',
            'anonymisert_at'  => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
        revider('medlem_anonymisert', 'member', $id);
        Svar::ok(['beskjed' => 'Personopplysningene er slettet. Kjøpene står igjen uten navn, '
                             . 'slik bokføringsloven krever.']);
    }

    // ── Annen info om personen ────────────────────────────────────────
    //
    // Feltet «notat» fantes fra for, men bare i innmeldingsskjemaet: sto det
    // noe der, kunne det aldri endres etterpaa. Alt verkstedet fikk vite om
    // en person i ettertid — allergier, at hen ikke vil staa paa bilder,
    // hvem hen kommer sammen med — matte skrives paa en lapp ved siden av.
    //
    // Det er internt. Det staar ikke paa Min side, og det sendes ikke til
    // noen. Skal personen ha en beskjed, gaar den gjennom Beskjeder.
    if ($handling === 'notat') {
        $id = Foresporsel::heltall('medlemId');
        if (DB::en('SELECT id FROM members WHERE id = :i', ['i' => $id]) === null) {
            Svar::feil('Fant ikke personen.', 404);
        }
        $tekst = mb_substr(trim(Foresporsel::tekst('notat')), 0, 1000);
        DB::oppdater('members', ['notat' => $tekst !== '' ? $tekst : null], ['id' => $id]);
        revider('medlem_notat', 'member', $id);
        Svar::ok(['beskjed' => $tekst !== '' ? 'Infoen er lagret.' : 'Infoen er fjernet.']);
    }

    // ── Knytt en gjestepaamelding til kontoen ─────────────────────────
    //
    // Bestilte noen plassen for de opprettet konto — eller la verkstedet dem
    // inn for haand — staar paameldingen i navnet til en gjest. Da ser ikke
    // personen kurset paa Min side, og kursbeviset kan hen ikke hente selv,
    // enda det er samme menneske med samme e-post.
    //
    // Vi gjetter ikke: knytningen gjores av verkstedet, og bare naar e-posten
    // eller telefonen er den samme. To personer kan dele en adresse, og da er
    // det ikke systemet som skal bestemme hvem kurset tilhorer.
    if ($handling === 'knytt') {
        $id  = Foresporsel::heltall('medlemId');
        $bid = Foresporsel::heltall('bookingId');

        $m = DB::en('SELECT id, epost, telefon FROM members WHERE id = :i', ['i' => $id]);
        if ($m === null) {
            Svar::feil('Fant ikke personen.', 404);
        }
        $b = DB::en('SELECT id, member_id, gjest_epost, gjest_telefon FROM bookings WHERE id = :i', ['i' => $bid]);
        if ($b === null) {
            Svar::feil('Fant ikke påmeldingen.', 404);
        }
        if ($b['member_id'] !== null) {
            Svar::feil('Påmeldingen hører alt til en konto.');
        }

        $epost = trim((string) ($m['epost'] ?? ''));
        $tlf   = trim((string) ($m['telefon'] ?? ''));
        $sammeEpost = $epost !== '' && strcasecmp($epost, (string) ($b['gjest_epost'] ?? '')) === 0;
        $sammeTlf   = $tlf !== '' && $tlf === (string) ($b['gjest_telefon'] ?? '');
        if (!$sammeEpost && !$sammeTlf) {
            Svar::feil('Påmeldingen står på en annen e-post og et annet telefonnummer. '
                     . 'Da kan den ikke knyttes hit automatisk.');
        }

        DB::oppdater('bookings', ['member_id' => $id], ['id' => $bid]);
        revider('pamelding_knyttet', 'booking', $bid, ['medlem' => $id]);

        Svar::ok(['beskjed' => 'Påmeldingen er knyttet til kontoen. '
                             . 'Nå ser personen kurset og kursbeviset på Min side.']);
    }

    if ($handling !== 'meld-inn') {
        Svar::feil('Ukjent handling.');
    }

    $navn    = mb_substr(trim(Foresporsel::tekst('navn')), 0, 191);
    $epost   = mb_substr(trim(Foresporsel::tekst('epost')), 0, 191);
    $telefon = normaliser_telefon(Foresporsel::tekst('telefon'));
    $type    = mb_substr(trim(Foresporsel::tekst('type')), 0, 64);
    $notat   = mb_substr(trim(Foresporsel::tekst('notat')), 0, 1000);
    $id      = Foresporsel::heltall('medlemId');

    if ($navn === '' && $id <= 0) {
        Svar::feil('Vi trenger navnet.');
    }
    if ($epost !== '' && !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        Svar::feil('E-postadressen ser ikke riktig ut.');
    }

    $plan = $type !== '' ? Medlemskap::plan($type) : null;
    if ($type !== '' && $plan === null) {
        Svar::feil('Ukjent medlemskap.');
    }

    // Samme person to ganger er verre enn ingen. Er hen alt i basen — som
    // gjest paa et kurs, eller innlogget med Vipps — brukes den raden.
    $fra = null;
    if ($id > 0) {
        $fra = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);
        if ($fra === null) {
            Svar::feil('Fant ikke personen.', 404);
        }
    } elseif ($telefon !== '') {
        $fra = DB::en('SELECT * FROM members WHERE telefon = :t LIMIT 1', ['t' => $telefon]);
    }
    if ($fra === null && $epost !== '') {
        $fra = DB::en('SELECT * FROM members WHERE epost = :e LIMIT 1', ['e' => $epost]);
    }

    // En proveperiode er engangs og varer en maaned. Uten sluttdato sto den
    // som aktiv for alltid, og «Prov Lissom» ble et gratis medlemskap.
    $prove = $plan !== null && (int) ($plan['engangs'] ?? 0) === 1;

    $felter = [
        'medlemskap_type' => $type !== '' ? $type : null,
        'status'          => $prove ? 'prove' : 'aktiv',
        'start_dato'      => date('Y-m-d'),
        'slutt_dato'      => $prove ? date('Y-m-d', strtotime('+1 month')) : null,
        // «timer_per_mnd» settes IKKE her.
        //
        // Kolonnen paa planen heter «timer»; «timer_per_mnd» staar paa
        // medlemmet og fantes ikke i oppslaget — PHP skrev en advarsel midt
        // i JSON-svaret, og verdien ble null hver gang.
        //
        // Den skal vaere null. Medlemsraden er en overstyring for én person;
        // er den tom, bestemmer planen (se Medlemskap::timerFor). Kopierte
        // vi timetallet inn her, ville medlemmet beholdt det gamle den dagen
        // planen endres fra 30 til 35 timer.
    ];
    if ($navn !== '')    { $felter['navn'] = $navn; }
    if ($epost !== '')   { $felter['epost'] = $epost; }
    if ($telefon !== '') { $felter['telefon'] = $telefon; }
    if ($notat !== '')   { $felter['notat'] = $notat; }

    if ($fra !== null) {
        DB::oppdater('members', $felter, ['id' => (int) $fra['id']]);
        $medlemId = (int) $fra['id'];
        $nytt = false;
    } else {
        $medlemId = DB::settInn('members', $felter);
        $nytt = true;
    }

    revider('medlem_meldt_inn', 'member', $medlemId, [
        'type' => $type, 'nytt' => $nytt, 'av' => (int) $jeg['id'],
    ]);

    Svar::ok([
        'id'      => $medlemId,
        'nytt'    => $nytt,
        'beskjed' => ($nytt ? 'Medlemmet er lagt inn.' : 'Personen sto der fra før og er nå medlem.')
                   . ' Betalingen går ikke av seg selv — den avtaler dere selv.',
    ]);
}

Foresporsel::krevMetode('GET');

// ── Én person, slik hen selv ser det ────────────────────────────────────
//
// Verkstedet kunne se paameldinger per kurs, men ikke alt én person har
// gjort — og dermed heller ikke hva som faktisk staar paa Min side hos den
// som ringer og lurer paa kursbeviset sitt.
if (Foresporsel::heltall('person') > 0 || Foresporsel::heltall('booking') > 0) {
    $pid = Foresporsel::heltall('person');
    $bid = Foresporsel::heltall('booking');

    // ── Hvem ruta gjelder ──────────────────────────────────────────────
    //
    // Ruta ble bygget av «members», og bare av den. En kursdeltaker uten
    // konto — de fleste av dem — hadde dermed ingen rute aa aapne: navnet i
    // deltakerlista var dodt, og verkstedet kom ikke inn til historikken,
    // kursbeviset eller notatet.
    //
    // Naa kan ruta ogsaa aapnes av en paamelding. Da er det bookingen som
    // sier hvem det er, og alt annet slaas opp paa e-post og telefon — de
    // samme feltene deltakerlista gjenkjenner folk paa fra for.
    $erGjest = false;
    if ($pid > 0) {
        $m = DB::en('SELECT id, navn, epost, telefon, rolle, medlemskap_type, status, notat FROM members WHERE id = :i', ['i' => $pid]);
        if ($m === null) {
            Svar::feil('Fant ikke personen.', 404);
        }
    } else {
        $b = DB::en(
            'SELECT b.id, b.member_id, b.gjest_navn, b.gjest_epost, b.gjest_telefon, b.internt_notat
               FROM bookings b WHERE b.id = :i',
            ['i' => $bid]
        );
        if ($b === null) {
            Svar::feil('Fant ikke påmeldingen.', 404);
        }
        // Har paameldingen en konto paa seg, er det den vi aapner — da er det
        // den samme personen, og hen skal ikke staa to steder.
        if ($b['member_id'] !== null) {
            $pid = (int) $b['member_id'];
            $m = DB::en('SELECT id, navn, epost, telefon, rolle, medlemskap_type, status, notat FROM members WHERE id = :i', ['i' => $pid]);
        }
        if ($pid <= 0 || $m === null) {
            $erGjest = true;
            $pid = 0;
            $m = [
                'id' => 0,
                'navn' => (string) $b['gjest_navn'],
                'epost' => (string) ($b['gjest_epost'] ?? ''),
                'telefon' => (string) ($b['gjest_telefon'] ?? ''),
                'rolle' => 'gjest',
                'medlemskap_type' => null,
                'status' => 'ingen',
                // Gjesten har ingen medlemsrad aa notere paa. Notatet fra
                // paameldingen staar i stedet — det er det som finnes.
                'notat' => (string) ($b['internt_notat'] ?? ''),
            ];
        }
    }

    // Rettelsene paa kursbeviset kom med migrasjon 045.
    $bevisFelt = DB::harKolonne('bookings', 'bevis_navn')
        ? 'b.bevis_navn, b.bevis_kurs, b.bevis_sperret,' : '';

    // Paameldinger gjort som gjest hoerer ogsaa hjemme her.
    //
    // Ringer noen om kurset sitt, er det den personen du slaar opp — ikke
    // kontoen. Men historikken leste bare paameldinger med member_id, og
    // bestilte du plassen for du opprettet konto, eller la verkstedet deg
    // inn for haand, sto raden uten. Da var kurset usynlig i personruta,
    // og kursbeviset kunne ikke rettes derfra — enda det sto med navnet
    // ditt i deltakerlista.
    //
    // E-post og telefon er det verkstedet gjenkjenner folk paa fra for; det
    // er de samme feltene deltakerlista slaar sammen paa.
    $epost = trim((string) ($m['epost'] ?? ''));
    $tlf   = trim((string) ($m['telefon'] ?? ''));

    $ogsaa = [];
    $param = ['m' => $pid];
    if ($epost !== '') { $ogsaa[] = 'b.gjest_epost = :e';   $param['e'] = $epost; }
    if ($tlf !== '')   { $ogsaa[] = 'b.gjest_telefon = :t'; $param['t'] = $tlf; }
    $gjester = $ogsaa === []
        ? ''
        : ' OR (b.member_id IS NULL AND (' . implode(' OR ', $ogsaa) . '))';

    // En gjest har ingen konto. Da er e-posten og telefonen alt vi har — og
    // finnes ingen av delene, er det bare den ene paameldingen vi vet om.
    if ($erGjest && $ogsaa === []) {
        $gjester = ' OR b.id = :bid';
        $param['bid'] = $bid;
    }

    $rader = DB::alle(
        "SELECT b.id, b.member_id, b.antall, b.status, b.belop_ore, b.created_at, {$bevisFelt}
                c.tittel, c.type, cs.start_tid, p.vipps_reference
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
      LEFT JOIN payments p ON p.id = b.payment_id
          WHERE (b.member_id = :m{$gjester})
       ORDER BY cs.start_tid IS NULL, cs.start_tid DESC, b.id DESC",
        $param
    );

    $naa = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    // ── Betalingene, paa tvers av paameldingene ────────────────────────
    //
    // Statusen sto paa hver rad i historikken, men ikke hvem som registrerte
    // den, naar, eller hvor mye. Etter fase 1 finnes det, og da hoerer det
    // hjemme her: det er dette man blir ringt om.
    $bookingIder = array_map(static fn(array $b): int => (int) $b['id'], $rader);
    $betalinger = [];
    if ($bookingIder !== [] && DB::harKolonne('payments', 'booking_id')) {
        $inn = implode(',', $bookingIder);
        // Begge pekerne. «payments.booking_id» kom med migrasjon 084, mens
        // «bookings.payment_id» har pekt paa Vipps-betalingen siden dag én —
        // og 084 fylte den nye bare for det som fantes da den kjorte. Leser
        // vi bare den nye, mangler Vipps-betalingene i lista.
        $betalinger = DB::alle(
            "SELECT p.id, b.id AS booking_id, p.type, p.belop_ore, p.status,
                    p.maate, p.kommentar, p.annullert_at, p.created_at,
                    c.tittel, r.navn AS registrert_navn
               FROM payments p
               JOIN bookings b ON (b.id = p.booking_id OR b.payment_id = p.id)
          LEFT JOIN courses c ON c.id = b.course_id
          LEFT JOIN members r ON r.id = p.registrert_av
              WHERE b.id IN ({$inn})
           ORDER BY p.id DESC"
        );
    }

    // ── Ventelistene hen staar paa ─────────────────────────────────────
    //
    // «Staar jeg fortsatt paa lista?» er et vanlig sporsmaal, og svaret laa
    // ingen steder i personruta.
    $ventelister = [];
    if ($epost !== '' || $tlf !== '') {
        $vHvor = [];
        $vParam = [];
        if ($epost !== '') { $vHvor[] = 'w.epost = :e';   $vParam['e'] = $epost; }
        if ($tlf !== '')   { $vHvor[] = 'w.telefon = :t'; $vParam['t'] = $tlf; }
        $ventelister = DB::alle(
            "SELECT w.id, w.posisjon, w.status, w.created_at, c.tittel, cs.start_tid
               FROM waitlist w
               JOIN courses c ON c.id = w.course_id
          LEFT JOIN course_sessions cs ON cs.id = w.course_session_id
              WHERE w.status IN ('venter','varslet') AND (" . implode(' OR ', $vHvor) . ")
           ORDER BY cs.start_tid IS NULL, cs.start_tid",
            $vParam
        );
    }

    // ── Hva som er gjort med hen ───────────────────────────────────────
    //
    // «revider()» har skrevet flittig til audit_log hele tiden — hvem, naar,
    // og detaljene som JSON — men ingen skjerm har lest det. Endringsloggen
    // fantes altsaa, den manglet bare et sted aa vises.
    $logg = [];
    $loggHvor = [];
    $loggParam = [];
    if ($pid > 0) {
        $loggHvor[] = "(a.objekt_type = 'member' AND a.objekt_id = :p)";
        $loggParam['p'] = $pid;
    }
    if ($bookingIder !== []) {
        $loggHvor[] = "(a.objekt_type = 'booking' AND a.objekt_id IN (" . implode(',', $bookingIder) . '))';
    }
    if ($loggHvor !== []) {
        $logg = DB::alle(
            'SELECT a.handling, a.detaljer, a.created_at, m.navn AS av
               FROM audit_log a
          LEFT JOIN members m ON m.id = a.member_id
              WHERE ' . implode(' OR ', $loggHvor) . '
           ORDER BY a.id DESC LIMIT 40',
            $loggParam
        );
    }

    /** Handlingene skrevet slik et menneske sier dem. */
    $loggTekst = static function (string $h): string {
        return match ($h) {
            'pamelding_lagt_inn'    => 'Lagt inn for hånd',
            'pamelding_fjernet'     => 'Avbestilt',
            'pamelding_flyttet'     => 'Flyttet til en annen dato',
            'pamelding_status'      => 'Status endret',
            'betaling_registrert'   => 'Betaling registrert',
            'betaling_annullert'    => 'Betaling annullert',
            'kursbevis_endret'      => 'Kursbevis rettet',
            'venteliste_gitt_plass' => 'Fikk plass fra ventelista',
            'medlem_meldt_inn'      => 'Meldt inn som medlem',
            'medlem_avsluttet'      => 'Medlemskapet avsluttet',
            'medlem_notat'          => 'Notat endret',
            default                 => ucfirst(str_replace('_', ' ', $h)),
        };
    };

    Svar::json([
        'person' => [
            'id'         => (int) $m['id'],
            'navn'       => $m['navn'],
            'epost'      => $m['epost'] ?: '',
            'telefon'    => $m['telefon'] ?: '',
            'medlemskap' => $m['medlemskap_type'] ?: 'Ingen',
            'status'     => $m['status'],
            // Uten konto er det ingen Min side aa vise, ingen medlemskap aa
            // endre, og notatet hoerer til paameldingen. Skjermen maa vite
            // det — ellers tilbyr den ting som ikke finnes.
            'gjest'      => $erGjest,
            // Det verkstedet selv har notert. Internt, og bare her.
            'notat'      => (string) ($m['notat'] ?? ''),
        ],
        'historikk' => array_map(static function (array $b) use ($naa): array {
            $holdt = $b['start_tid'] !== null
                && new DateTimeImmutable((string) $b['start_tid'], new DateTimeZone('UTC')) < $naa;
            return [
                'id'      => (int) $b['id'],
                'tittel'  => $b['tittel'],
                'naar'    => $b['start_tid'] ? Booking::norskDato((string) $b['start_tid']) : 'Uten dato',
                'sum'     => Booking::kroner((int) $b['belop_ore'])
                             . ((int) $b['antall'] > 1 ? ' · ' . $b['antall'] . ' plasser' : ''),
                'status'  => match ((string) $b['status']) {
                    'betalt'    => 'Betalt',
                    'reservert' => 'Reservert — ikke betalt',
                    'avbestilt' => 'Avbestilt',
                    default     => (string) $b['status'],
                },
                'betalt'  => (string) $b['status'] === 'betalt',
                // Samme regel som paa Min side: bevis naar kurset er holdt og
                // betalt, og drop-in er ikke et kurs.
                'kursbevis' => ($holdt && (string) $b['status'] === 'betalt' && (string) $b['type'] !== 'dropin'
                                && empty($b['bevis_sperret']))
                    ? '/api/kursbevis.php?booking=' . (int) $b['id']
                    : null,
                // Kan kurset gi et bevis i det hele tatt? Drop-in gjor det
                // ikke, og da er det ingenting aa rette heller.
                'bevisMulig'  => $holdt && (string) $b['type'] !== 'dropin',
                'bevisSperret' => !empty($b['bevis_sperret']),
                'bevisNavn'   => (string) ($b['bevis_navn'] ?? ''),
                'bevisKurs'   => (string) ($b['bevis_kurs'] ?? ''),
                'referanse' => $b['vipps_reference'],
                // Sto paameldingen i navnet til en gjest, ligger den her
                // fordi e-posten eller telefonen er den samme — men den er
                // ikke knyttet til kontoen. Da ser ikke personen den paa Min
                // side, og kursbeviset kan hen ikke hente selv.
                'losRad'    => $b['member_id'] === null,
            ];
        }, $rader),

        // Betalingene, med hvem som registrerte dem og naar.
        'betalinger' => array_map(static fn(array $p): array => [
            'belop'     => Booking::kroner((int) $p['belop_ore']),
            'kurs'      => (string) ($p['tittel'] ?? ''),
            'maate'     => (string) $p['type'] === 'manuell'
                             ? ((string) ($p['maate'] ?? '') ?: 'Ukjent') : 'Vipps',
            'av'        => (string) ($p['registrert_navn'] ?? ''),
            'naar'      => Booking::norskDato((string) $p['created_at']),
            'kommentar' => (string) ($p['kommentar'] ?? ''),
            'annullert' => $p['annullert_at'] !== null,
        ], $betalinger),

        // Ventelistene hen staar paa, med kvelden det gjelder.
        'ventelister' => array_map(static fn(array $w): array => [
            'kurs'     => (string) $w['tittel'],
            'naar'     => $w['start_tid'] !== null
                            ? Booking::norskDato((string) $w['start_tid']) : 'Hele kurset',
            'posisjon' => (int) $w['posisjon'],
            'status'   => (string) $w['status'] === 'varslet' ? 'Varslet' : 'Venter',
            'siden'    => Booking::norskDato((string) $w['created_at']),
        ], $ventelister),

        // Endringsloggen. Sto skrevet hele tiden, men ble aldri lest.
        'logg' => array_map(static fn(array $a): array => [
            'hva'  => $loggTekst((string) $a['handling']),
            'av'   => (string) ($a['av'] ?? ''),
            'naar' => Booking::norskDato((string) $a['created_at']),
        ], $logg),
    ]);
}

$sok = Foresporsel::tekst('sok');
$hvor = 'anonymisert_at IS NULL';
$param = [];

if ($sok !== '') {
    $hvor .= ' AND (navn LIKE :s OR epost LIKE :s OR telefon LIKE :s)';
    $param['s'] = '%' . $sok . '%';
}

$medlemmer = DB::alle(
    "SELECT id, navn, epost, telefon, rolle, medlemskap_type, status,
            start_dato, timer_per_mnd, created_at
       FROM members
      WHERE {$hvor}
      ORDER BY navn
      LIMIT 500",
    $param
);

// Nodluke-numrene i secrets.php gir admin ved kjoring uten at kolonnen
// nodvendigvis er satt. Uten dette ville eieren sett seg selv som vanlig
// medlem i lista, mens hen faktisk har admin-tilgang.
$nodluker = Config::adminNumre();

// Brukte minutter denne maaneden, per medlem. Ett oppslag for hele lista —
// ikke ett per rad. Maanedsgrensa folger norsk kalender.
$fra = Stempling::manedStart();
Stempling::lukkGlemte();

$brukt = [];
foreach (DB::alle(
    "SELECT member_id,
            COALESCE(SUM(COALESCE(minutter, TIMESTAMPDIFF(MINUTE, inn_tid, UTC_TIMESTAMP()))), 0) AS min
       FROM check_ins WHERE inn_tid >= :fra GROUP BY member_id",
    ['fra' => $fra]
) as $r) {
    $brukt[(int) $r['member_id']] = (int) $r['min'];
}

$inne = [];
// Hvor mange kurs hver av dem har betalt for.
//
// Uten dette er en kursdeltaker og en tom konto det samme i lista: begge
// staar som «Ikke medlem». Tallet er det som skiller dem — og det er ogsaa
// det som avgjor om noen har et kursbevis aa hente.
$kurs = [];
foreach (DB::alle(
    "SELECT b.member_id, COUNT(*) AS antall
       FROM bookings b
      WHERE b.member_id IS NOT NULL AND b.status = 'betalt'
      GROUP BY b.member_id"
) as $r) {
    $kurs[(int) $r['member_id']] = (int) $r['antall'];
}

foreach (DB::alle('SELECT member_id FROM check_ins WHERE ut_tid IS NULL') as $r) {
    $inne[(int) $r['member_id']] = true;
}

// ── Bindingstid og oppsigelse, per medlem ──────────────────────────────
//
// Eieren: «jeg maa paa en eller annen maate ha oversikt over disse to
// maanedene og den ene maaneden med oppsigelsestid».
//
// Én sporring for alle, ikke én per medlem. Den nyeste avtalen teller — det
// er den som loper.
$avtaler = [];
foreach (DB::alle(
    "SELECT s.member_id, s.plan, s.status, s.binding_til, s.sagt_opp_at, s.slutter,
            s.neste_trekk, s.vipps_agreement_id
       FROM subscriptions s
       JOIN (SELECT member_id, MAX(id) AS siste FROM subscriptions GROUP BY member_id) n
         ON n.siste = s.id"
) as $r) {
    $avtaler[(int) $r['member_id']] = $r;
}

$idag = gmdate('Y-m-d');
$dato = static fn(?string $d): ?string => $d ? Booking::norskDatoKort($d . ' 12:00:00') : null;

/** Hva staar det om bindingen og oppsigelsen paa dette medlemmet? */
$avtaleInfo = static function (int $id) use ($avtaler, $idag, $dato): array {
    $a = $avtaler[$id] ?? null;
    if ($a === null) {
        return ['fastTrekk' => false, 'bundetTil' => null, 'bundet' => false,
                'sagtOpp' => null, 'slutter' => null, 'iOppsigelse' => false, 'avtale' => null];
    }
    $bundet = $a['binding_til'] !== null && (string) $a['binding_til'] >= $idag;
    return [
        // Tom avtale-id betyr «gjor opp selv» — ingen automatiske trekk.
        'fastTrekk'   => trim((string) ($a['vipps_agreement_id'] ?? '')) !== '',
        'bundetTil'   => $dato($a['binding_til']),
        'bundet'      => $bundet,
        'sagtOpp'     => $a['sagt_opp_at'] ? $dato(substr((string) $a['sagt_opp_at'], 0, 10)) : null,
        'slutter'     => $dato($a['slutter']),
        'iOppsigelse' => $a['slutter'] !== null && (string) $a['slutter'] >= $idag,
        'avtale'      => (string) $a['status'],
    ];
};

Svar::json(['medlemmer' => array_map(static fn($m) => [
    'id'         => (int) $m['id'],
    'navn'       => $m['navn'],
    'epost'      => $m['epost'],
    'telefon'    => $m['telefon'],
    'erAdmin'    => $m['rolle'] === 'admin'
                    || ($m['telefon'] !== null && in_array(normaliser_telefon((string) $m['telefon']), $nodluker, true)),
    'medlemskap' => $m['medlemskap_type'],
    'status'     => $m['status'],
    'startDato'  => $m['start_dato'],
    // Planen bestemmer timetallet, medlemsraden overstyrer. «timer_per_mnd»
    // alene sto tom for alle — se Medlemskap::timerFor().
    'timer'      => Medlemskap::timerFor($m),
    'bruktTimer' => Stempling::timer($brukt[(int) $m['id']] ?? 0),
    'bruktMin'   => $brukt[(int) $m['id']] ?? 0,
    'erInne'     => isset($inne[(int) $m['id']]),
    'antallKurs' => $kurs[(int) $m['id']] ?? 0,
] + $avtaleInfo((int) $m['id']), $medlemmer),
    // Medlemskapene som finnes, saa innmelding for haand kan tilby de
    // samme valgene som nettsida — ikke en liste skrevet av paa nytt.
    'planer' => array_map(static fn($p) => [
        'navn'  => (string) $p['navn'],
        // Kolonnen paa planen heter «timer». «timer_per_mnd» staar paa
        // medlemmet, og finnes ikke her — oppslaget ga null for hver plan,
        // saa innmelding for haand tilbod medlemskap uten timetall.
        'timer' => $p['timer'] !== null ? (int) $p['timer'] : null,
        'pris'  => Booking::kroner((int) $p['pris_ore']),
    ], Medlemskap::planer()),
]);
