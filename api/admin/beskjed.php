<?php
/**
 * Beskjed til deltakere, medlemmer eller én enkelt person.
 *
 *   POST { til: "okt", oktId, tekst, ogsaaSms }     alle paameldte paa en dato
 *   kursbevis: "ja" (alle grupper)                 hver faar lenken til kursbeviset for sitt
 *                                                  siste fullfoerte kurs (eieren, 8. oktober 2026:
 *                                                  «uavhengig av deltakere og medlemmer kunne sende ut
 *                                                  kursbevis»). Da gaar det ikke ut automatisk etterpaa
 *                                                  (Booking::bevisSendtManuelt, bin/cron.php anmeldelser).
 *   POST { til: "medlemmer", tekst, ogsaaSms }      alle aktive medlemmer
 *   POST { til: "medlemmer", type: "30 timer" }     bare den medlemskapstypen
 *   POST { til: "en", navn, epost, telefon }        én mottaker, ogsaa uten medlemskap
 *
 * Meldingene legges i varselkoen som alt annet, saa de taaler at e-posten er
 * treg og kan forsokes paa nytt. Admin ser i oversikten om noe ikke kom fram.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$jeg = krev_admin();

$til   = Foresporsel::tekst('til', 'okt');
$tekst = trim(Foresporsel::tekst('tekst'));
$sms   = Foresporsel::tekst('ogsaaSms') === 'ja';
// «Bare SMS» fra den nye adminen (/ny-admin, «Send beskjed» med valget SMS):
// e-posten hoppes over. Uten feltet er alt som foer.
$bareSms = Foresporsel::tekst('bareSms') === 'ja';
if ($bareSms) {
    $sms = true;
}
$medBevis = Foresporsel::tekst('kursbevis') === 'ja';
$emne  = mb_substr(Foresporsel::tekst('emne', 'Beskjed fra Lissom'), 0, 191);
// Bildet og bildeteksten til et nyhetsbrev. En vanlig beskjed sender ingen
// bilde; da staar feltene tomme og brevet er ren tekst som for.
$bilde      = mb_substr(Foresporsel::tekst('bilde'), 0, 255);
$bildetekst = mb_substr(Foresporsel::tekst('bildetekst'), 0, 255);
$knappTekst = mb_substr(Foresporsel::tekst('knappTekst'), 0, 60);
$knappUrl   = mb_substr(Foresporsel::tekst('knappUrl'), 0, 500);
// Adressen skal peke ut paa nettet, ikke kjore noe i e-postleseren.
if ($knappUrl !== '' && !preg_match('~^https?://~i', $knappUrl)) {
    $knappUrl = '';
}

// Forhaandsvisning: den samme HTML-en, uten aa sende noe.
//
// Skjermen kunne tegnet sin egen etterlikning, men da ville de to sagt noe
// forskjellig etter hvert. Dette er noeyaktig det som gaar ut.
if (Foresporsel::tekst('handling') === 'forhandsvis') {
    Svar::ok(['html' => Oppsett::epost(
        $emne ?: 'Overskriften kommer her',
        $tekst !== '' ? $tekst : 'Teksten kommer her.',
        $bilde, $bildetekst,
        ['tekst' => $knappTekst, 'url' => $knappUrl]
    )]);
}

if (mb_strlen($tekst) < 3) {
    Svar::feil('Skriv en melding først.');
}
if (mb_strlen($tekst) > 4000) {
    Svar::feil('Meldingen er for lang.');
}

// «Legg ut» fra Medlemmene i admin-ny (eieren, 8. oktober 2026): beskjeden
// legges bare paa oppslagstavla paa Min side, uten e-post og uten SMS. Samme
// tabell som utsendingen under skriver til. «Legg ut og send SMS» gaar den
// vanlige veien (til=medlemmer, ogsaaSms=ja).
// Ett sted som skriver til tavla, brukt av begge veiene.
$tilTavla = static function (string $type) use ($emne, $tekst, $jeg): void {
    DB::settInn('medlemsbeskjeder', [
        'tittel' => $emne,
        'tekst'  => $tekst,
        'type'   => mb_substr($type, 0, 64),
        'av'     => mb_substr((string) ($jeg['navn'] ?? ''), 0, 191),
    ]);
};
if (Foresporsel::tekst('handling') === 'tavle') {
    try {
        $tilTavla('');
    } catch (Throwable $e) {
        logg('Beskjed: fikk ikke lagt ut paa Min side', ['feil' => $e->getMessage()]);
        Svar::feil('Fikk ikke lagt ut beskjeden. Prøv igjen.', 500);
    }
    revider('beskjed_tavle', null, null, ['emne' => $emne]);
    Svar::ok(['beskjed' => 'Lagt ut på Min side.']);
}

/** @var list<array{navn:string,epost:?string,telefon:?string}> $mottakere */
$mottakere = [];
$hvem = '';

if ($til === 'okt') {
    $oktId = Foresporsel::heltall('oktId');
    $okt = DB::en(
        'SELECT cs.id, cs.start_tid, c.tittel FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id WHERE cs.id = :i',
        ['i' => $oktId]
    );
    if ($okt === null) {
        Svar::feil('Fant ikke datoen.', 404);
    }

    $mottakere = DB::alle(
        "SELECT b.id AS booking_id, COALESCE(m.navn, b.gjest_navn) AS navn,
                COALESCE(m.epost, b.gjest_epost) AS epost,
                COALESCE(m.telefon, b.gjest_telefon) AS telefon
           FROM bookings b
      LEFT JOIN members m ON m.id = b.member_id
          WHERE b.course_session_id = :o AND b.status = 'betalt'",
        ['o' => $oktId]
    );
    $hvem = $okt['tittel'] . ' — ' . Booking::norskDato((string) $okt['start_tid']);

} elseif ($til === 'medlemmer') {
    // Uten type: alle aktive. Med type: bare den ene medlemskapstypen, eller
    // «prove» for dem som er paa proeve.
    $type = Foresporsel::tekst('type');

    if ($type === 'prove') {
        $mottakere = DB::alle(
            "SELECT id AS member_id, navn, epost, telefon FROM members
              WHERE status = 'prove' AND anonymisert_at IS NULL"
        );
        $hvem = 'medlemmer på prøve';
    } elseif ($type !== '') {
        $mottakere = DB::alle(
            "SELECT id AS member_id, navn, epost, telefon FROM members
              WHERE status IN ('aktiv','prove') AND anonymisert_at IS NULL
                AND medlemskap_type = :t",
            ['t' => $type]
        );
        $hvem = 'medlemmer med ' . $type;
    } else {
        $mottakere = DB::alle(
            "SELECT id AS member_id, navn, epost, telefon FROM members
              WHERE status IN ('aktiv','prove') AND anonymisert_at IS NULL"
        );
        $hvem = 'alle aktive medlemmer';
    }

} elseif ($til === 'en') {
    // Én mottaker, skrevet inn for haand. Trengs for aa svare noen som ikke er
    // medlem — en som har spurt om et kurs, eller staar paa venteliste.
    $navn    = mb_substr(Foresporsel::tekst('navn'), 0, 191);
    $epostTil = mb_substr(Foresporsel::tekst('epost'), 0, 191);
    $tlfTil  = normaliser_telefon(Foresporsel::tekst('telefon'));

    if ($epostTil === '' && $tlfTil === '') {
        Svar::feil('Skriv inn e-post eller telefonnummer til den du vil sende til.');
    }
    if ($epostTil !== '' && !filter_var($epostTil, FILTER_VALIDATE_EMAIL)) {
        Svar::feil('E-postadressen ser ikke riktig ut.');
    }

    $mottakere = [[
        'navn'    => $navn !== '' ? $navn : 'der',
        'epost'   => $epostTil !== '' ? $epostTil : null,
        'telefon' => $tlfTil !== '' ? $tlfTil : null,
    ]];
    $hvem = $navn !== '' ? $navn : ($epostTil !== '' ? $epostTil : $tlfTil);

} else {
    Svar::feil('Ukjent mottakergruppe.');
}

if ($mottakere === []) {
    Svar::feil('Ingen å sende til i denne gruppa.', 409);
}

// Hvor mange som faar den, uten aa sende noe. Kursboost spoer om dette
// foer «Send til medlemmene», saa bekreftelsen kan si tallet (eieren, 27.
// september 2026). Samme utvalg som utsendingen under — ikke en kopi.
if (Foresporsel::tekst('handling') === 'antall') {
    Svar::ok([
        'antall' => count(array_filter($mottakere, static fn($m) => !empty($m['epost']))),
        'hvem'   => $hvem,
    ]);
}

// Fra kursboost: samme del skal ikke kunne sendes to ganger ved et uhell.
// Kursboost::merk() skriver ned at den er gjort etter at koen har tatt imot.
$kursboostId = Foresporsel::heltall('kursboost');
if ($kursboostId > 0 && Kursboost::gjort($kursboostId, 'medlemmer') !== null) {
    Svar::feil('Denne meldingen er alt sendt til medlemmene.', 409);
}

// Hvem beskjeden gikk til, lagret paa varselet.
//
// Sendte beskjeder kunne ikke finnes igjen: koen visste hvem som fikk e-post,
// men ikke om det var deltakerne paa en kveld eller alle medlemmene. Da sto
// «Sendt til medlemmene» over lista uansett hvor du kom fra, og deltakerne og
// medlemmene ble det samme.
$refType = $til === 'okt' ? 'beskjed-okt'
         : ($til === 'en' ? 'beskjed-en' : 'beskjed-medlem');
$refId   = $til === 'okt' ? ($oktId ?? null) : null;

$epost = 0;
$antallSms = 0;
/** @var list<string> $utenVei Mottakere beskjeden ikke naadde. */
$utenVei = [];

foreach ($mottakere as $m) {
    $personlig = str_replace('{navn}', (string) $m['navn'], $tekst);
    // Kursbeviset, med den korte lenken (.htaccess /k/) som i SMS-en etter
    // kurset. Uten bevis (kurset er ikke over, eller beviset er trukket) faar
    // mottakeren beskjeden uten lenke.
    $bevisBooking = !empty($m['booking_id']) ? (int) $m['booking_id']
        : ($medBevis ? Booking::sisteBevisBooking((int) ($m['member_id'] ?? 0), (string) ($m['epost'] ?? ''), (string) ($m['telefon'] ?? '')) : 0);
    if ($medBevis && $bevisBooking > 0) {
        $bevis = Booking::bevisLenke($bevisBooking);
        if ($bevis !== null) {
            $personlig .= "\n\nKursbeviset ditt: "
                . (string) preg_replace('~api/kursbevis\.php\?booking=(\d+)&k=([a-f0-9]{32})~', 'k/$1.$2', $bevis);
        }
    }

    if (!$bareSms && !empty($m['epost'])) {
        // Oppsettet: bildet oeverst, overskriften, avsnittene og en
        // eventuell knapp. Uten bilde og knapp blir det den samme teksten
        // som for, bare i en ramme som taaler aa bli aapnet i Outlook.
        $html = Oppsett::epost($emne, $personlig, $bilde, $bildetekst,
            ['tekst' => $knappTekst, 'url' => $knappUrl]);
        Varsel::epost((string) $m['epost'], $emne,
            $personlig . "\n\nHilsen Lissom Keramikk", $refType, $refId, 'system', $html);
        $epost++;
    }
    if ($sms && !empty($m['telefon'])) {
        if (Varsel::sms((string) $m['telefon'], $personlig, $refType, $refId) > 0) {
            $antallSms++;
        } elseif ($bareSms || empty($m['epost'])) {
            // Verken e-post eller SMS naadde fram. Da maa hun faa vite hvem
            // det gjelder — ellers tror hun beskjeden gikk ut til alle.
            $utenVei[] = trim(((string) $m['navn']) . ' (' . (string) $m['telefon'] . ')');
        }
    }
}

revider('beskjed_sendt', null, null, ['til' => $hvem, 'epost' => $epost, 'sms' => $antallSms]);

// Mottakere uten e-post og telefon gir ingenting aa sende. «Lagt i ko: 0
// e-post» leses som at det gikk bra — det gjorde det ikke.
if ($epost === 0 && $antallSms === 0) {
    Svar::feil(
        $sms && !Varsel::smsMulig() && $utenVei !== []
            ? count($mottakere) . ' mottakere i ' . $hvem . ', men ingen av dem har e-postadresse, '
              . 'og SMS er ikke satt opp. Disse må kontaktes direkte: '
              . implode(', ', array_slice($utenVei, 0, 20))
              . (count($utenVei) > 20 ? ' og ' . (count($utenVei) - 20) . ' til' : '') . '.'
            : count($mottakere) . ' mottakere i ' . $hvem
              . ', men ingen av dem har e-post eller telefonnummer registrert.',
        409
    );
}

// Oppslagstavla paa Min side.
//
// Eieren, 13. september 2026: «paa min side, saa ser det ut til at beskjeder
// til medlemmene ikke vises». Kortet «Beskjeder — Fra verkstedet» har staatt
// paa forsiden hele tiden, men beskjeden ble bare sendt — aldri lagret. Var
// e-posten lest og slettet, fantes den ingen steder.
//
// Den lagres etter at noe faktisk gikk ut: gikk ingenting, har svaret over
// alt avbrutt med en feil, og da skal det ikke staa noe paa tavla heller.
//
// Bare beskjeder til medlemmene. Deltakerne paa en kursdato har ingen Min
// side, og én enkelt mottaker er ikke en oppslagstavle.
if ($til === 'medlemmer') {
    try {
        $tilTavla(Foresporsel::tekst('type'));
    } catch (Throwable $e) {
        // Tavla er ikke verdt en feilmelding til den som nettopp sendte:
        // e-postene er alt i koen. Den havner i loggen i stedet.
        logg('Beskjed: fikk ikke lagret til Min side', ['feil' => $e->getMessage()]);
    }
}

if ($kursboostId > 0) {
    Kursboost::merk($kursboostId, 'medlemmer', ['epost' => $epost, 'sms' => $antallSms]);
    revider('kursboost_sendt', 'ai_utkast', $kursboostId, ['del' => 'medlemmer', 'epost' => $epost]);
}

$beskjed = sprintf(
    'Lagt i kø: %d e-post%s til %s. De sendes i løpet av et minutt eller to.',
    $epost,
    $antallSms > 0 ? " og {$antallSms} SMS" : '',
    $hvem
);

// Ble SMS huket av uten at SMS er satt opp, skal det staa her — ikke bare
// mangle fra tallet. «Lagt i ko: 12 e-post» leses som at alt gikk ut.
if ($sms && !Varsel::smsMulig()) {
    $beskjed .= ' SMS er ikke satt opp, så ingen SMS ble sendt.';
    if ($utenVei !== []) {
        $beskjed .= ' Disse har verken e-post eller fikk SMS, og må kontaktes direkte: '
            . implode(', ', array_slice($utenVei, 0, 20))
            . (count($utenVei) > 20 ? ' og ' . (count($utenVei) - 20) . ' til' : '') . '.';
    }
}

Svar::ok([
    'hvem'     => $hvem,
    'epost'    => $epost,
    'sms'      => $antallSms,
    'uten_vei' => $utenVei,
    'beskjed'  => $beskjed,
]);
