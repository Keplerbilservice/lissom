<?php
/**
 * Beskjed til deltakere eller medlemmer.
 *
 *   POST { til: "okt", oktId, tekst, ogsaaSms }     alle paameldte paa en dato
 *   POST { til: "medlemmer", tekst, ogsaaSms }      alle aktive medlemmer
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
    Svar::feil('Skriv en melding forst.');
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
    $mottakere = DB::alle(
        "SELECT navn, epost, telefon FROM members
          WHERE status IN ('aktiv','prove') AND anonymisert_at IS NULL"
    );
    $hvem = 'alle aktive medlemmer';

} else {
    Svar::feil('Ukjent mottakergruppe.');
}

if ($mottakere === []) {
    Svar::feil('Ingen aa sende til.', 409);
}

$epost = 0;
$antallSms = 0;

foreach ($mottakere as $m) {
    $personlig = str_replace('{navn}', (string) $m['navn'], $tekst);

    if (!empty($m['epost'])) {
        Varsel::epost((string) $m['epost'], $emne, $personlig . "\n\nHilsen Lissom Keramikk", 'beskjed', null);
        $epost++;
    }
    if ($sms && !empty($m['telefon'])) {
        Varsel::sms((string) $m['telefon'], $personlig, 'beskjed', null);
        $antallSms++;
    }
}

revider('beskjed_sendt', null, null, ['til' => $hvem, 'epost' => $epost, 'sms' => $antallSms]);

Svar::ok([
    'hvem'    => $hvem,
    'epost'   => $epost,
    'sms'     => $antallSms,
    'beskjed' => sprintf(
        'Lagt i ko: %d e-post%s til %s. De sendes i lopet av et minutt eller to.',
        $epost,
        $antallSms > 0 ? " og {$antallSms} SMS" : '',
        $hvem
    ),
]);
