<?php
/**
 * Beskjed til deltakere, medlemmer eller én enkelt person.
 *
 *   POST { til: "okt", oktId, tekst, ogsaaSms }     alle paameldte paa en dato
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
krev_admin();

$til   = Foresporsel::tekst('til', 'okt');
$tekst = trim(Foresporsel::tekst('tekst'));
$sms   = Foresporsel::tekst('ogsaaSms') === 'ja';
$emne  = mb_substr(Foresporsel::tekst('emne', 'Beskjed fra Lissom'), 0, 191);

if (mb_strlen($tekst) < 3) {
    Svar::feil('Skriv en melding først.');
}
if (mb_strlen($tekst) > 4000) {
    Svar::feil('Meldingen er for lang.');
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
        "SELECT COALESCE(m.navn, b.gjest_navn) AS navn,
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
            "SELECT navn, epost, telefon FROM members
              WHERE status = 'prove' AND anonymisert_at IS NULL"
        );
        $hvem = 'medlemmer på prøve';
    } elseif ($type !== '') {
        $mottakere = DB::alle(
            "SELECT navn, epost, telefon FROM members
              WHERE status IN ('aktiv','prove') AND anonymisert_at IS NULL
                AND medlemskap_type = :t",
            ['t' => $type]
        );
        $hvem = 'medlemmer med ' . $type;
    } else {
        $mottakere = DB::alle(
            "SELECT navn, epost, telefon FROM members
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

    if (!empty($m['epost'])) {
        Varsel::epost((string) $m['epost'], $emne, $personlig . "\n\nHilsen Lissom Keramikk", $refType, $refId);
        $epost++;
    }
    if ($sms && !empty($m['telefon'])) {
        if (Varsel::sms((string) $m['telefon'], $personlig, $refType, $refId) > 0) {
            $antallSms++;
        } elseif (empty($m['epost'])) {
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
