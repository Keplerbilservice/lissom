<?php
/**
 * Hvem som er paameldt. Den siden dere kommer til aa bruke oftest.
 *
 *   ?oktId=5   deltakerne paa en bestemt dato
 *   uten       alle kommende okter med antall paameldte
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
krev_admin();

$oktId = Foresporsel::heltall('oktId');

// ── Kursbevisene, samlet ────────────────────────────────────────────────
//
//   ?bevis=1
//
// Bevisene laa bare inne i hver enkelt person: skulle verkstedet finne det
// beviset noen ringte om, matte de foerst vite hvem personen var, og saa
// aapne ruta hennes. Her staar de samlet — hele veien tilbake, ikke bare
// det som ligger framfor oss — slik at det gaar an aa soke opp ett bevis.
//
// Ingen ny sannhet: det er de samme paameldingene, med de samme reglene for
// naar et bevis finnes, og de rettes med det samme kallet som for.
if (Foresporsel::heltall('bevis') === 1) {
    $bevisFelt = DB::harKolonne('bookings', 'bevis_navn')
        ? 'b.bevis_navn, b.bevis_kurs, b.bevis_sperret,' : '';

    $rader = DB::alle(
        "SELECT b.id, b.member_id, b.status, {$bevisFelt}
                COALESCE(m.navn, b.gjest_navn) AS navn,
                c.tittel, c.type, c.tema, cs.start_tid, cs.slutt_tid
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
      LEFT JOIN members m ON m.id = b.member_id
          WHERE b.status = 'betalt'
            AND c.type <> 'dropin'
            AND (c.tema IS NULL OR c.tema <> 'Drop-in')
            AND COALESCE(cs.slutt_tid, cs.start_tid) IS NOT NULL
            AND COALESCE(cs.slutt_tid, cs.start_tid) < UTC_TIMESTAMP()
       ORDER BY COALESCE(cs.slutt_tid, cs.start_tid) DESC, b.id DESC
          LIMIT 300"
    );

    Svar::json([
        'bevis' => array_map(static fn($d) => [
            'id'        => (int) $d['id'],
            'medlemId'  => $d['member_id'] !== null ? (int) $d['member_id'] : null,
            // Navnet slik det faktisk staar paa arket: rettelsen gaar foran.
            'navn'      => trim((string) ($d['bevis_navn'] ?? '')) ?: (string) $d['navn'],
            'tittel'    => trim((string) ($d['bevis_kurs'] ?? '')) ?: (string) $d['tittel'],
            // Naar kurset var, ikke naar det sluttet: «tirsdag 18. august,
            // 21:00» er sluttiden, og det er ikke slik noen husker kvelden.
            'dato'      => Booking::norskDato((string) ($d['start_tid'] ?: $d['slutt_tid'])),
            'bevisNavn' => (string) ($d['bevis_navn'] ?? ''),
            'bevisKurs' => (string) ($d['bevis_kurs'] ?? ''),
            'sperret'   => !empty($d['bevis_sperret']),
            // Sperrede bevis har ingen lenke — den ville svart 404.
            'url'       => empty($d['bevis_sperret'])
                ? '/api/kursbevis.php?booking=' . (int) $d['id']
                : null,
        ], $rader),
    ]);
}

/** Samme regel som paa Min side: betalt, gjennomfort, og ikke drop-in. */
$kursbevis = static function (array $d): ?string {
    if (($d['status'] ?? '') !== 'betalt') {
        return null;
    }
    // Er beviset trukket, skal lenken bort ogsaa her. Ellers staar knappen
    // igjen i deltakerlista og svarer 404 naar den trykkes.
    if (!empty($d['bevis_sperret'])) {
        return null;
    }
    // Drop-in er ikke et kurs — det er to timer i verkstedet med ditt eget
    // arbeid, og det er ingenting aa bevise. Interne samlinger er kurs, og
    // de gir bevis som alle andre: et medlem som har vaert paa glasurkveld
    // har vaert paa kurs, selv om samlingen ikke sto i den aapne lista.
    if ((string) ($d['tema'] ?? '') === 'Drop-in') {
        return null;
    }
    $slutt = $d['slutt_tid'] ?: $d['start_tid'];
    if ($slutt === null || strtotime((string) $slutt) > time()) {
        return null;
    }
    return '/api/kursbevis.php?booking=' . (int) $d['id'];
};

if ($oktId <= 0) {
    // Tidene ligger i UTC, som resten av basen. Skjemaet skriver norsk tid,
    // saa de regnes om her — én gang, i stedet for i hvert felt.
    $iOslo = static function (string $utc, string $format): string {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'))
            ->format($format);
    };

    // Om okta er laget av en regel, og om kurset den hoerer til er ute paa
    // nettsida. Begge to trengs for aa vise «Planlagte kurs» slik kalenderen
    // viser det samme: de automatiske tidene samles til én linje, og en dato
    // paa et kurs som ikke er publisert sier fra om at den ikke er ute.
    $autoKol = (DB::harKolonne('course_sessions', 'fra_apningstid')
                    ? ', cs.fra_apningstid' : ', 0 AS fra_apningstid')
             . (DB::harKolonne('course_sessions', 'fra_dropin_tid')
                    ? ', cs.fra_dropin_tid' : ', NULL AS fra_dropin_tid');

    $okter = DB::alle(
        "SELECT cs.id, cs.start_tid, cs.slutt_tid, c.tittel, c.type, c.tema,
                c.status AS kurs_status{$autoKol},
                COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet,
                (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
                  WHERE b.course_session_id = cs.id AND b.status = 'betalt') AS betalt,
                (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
                  WHERE b.course_session_id = cs.id AND b.status = 'reservert'
                    AND (b.reservert_til IS NULL OR b.reservert_til > UTC_TIMESTAMP())) AS reservert
           FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id
          WHERE cs.status <> 'avlyst' AND cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
          ORDER BY cs.start_tid"
    );

    $ledigeKart = Booking::ledigePlasserFlere(
        array_map(static fn(array $o): int => (int) $o['id'], $okter)
    );

    // Flat liste over alle deltakere framover — det admin-siden viser.
    // Rettelsene paa kursbeviset kom med migrasjon 045.
    $bevisKol = DB::harKolonne('bookings', 'bevis_navn')
        ? 'b.bevis_navn, b.bevis_kurs, b.bevis_sperret,' : '';
    // Kolonnen kommer med migrasjon 057.
    $allergiKol = DB::harKolonne('bookings', 'allergier') ? 'b.allergier,' : '';

    $alle = DB::alle(
        "SELECT b.id, b.antall, b.status, b.belop_ore, b.folge_medlem,
                b.betalt_maate, b.notat, b.lagt_inn_av, {$bevisKol} {$allergiKol}
                b.member_id, b.course_session_id,
                COALESCE(m.navn, b.gjest_navn) AS navn,
                COALESCE(m.epost, b.gjest_epost) AS epost,
                COALESCE(m.telefon, b.gjest_telefon) AS telefon,
                c.tittel, c.tema, cs.start_tid, cs.slutt_tid,
                p.vipps_reference, p.status AS betalingsstatus
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
      LEFT JOIN members m ON m.id = b.member_id
      LEFT JOIN payments p ON p.id = b.payment_id
          WHERE b.status IN ('betalt','reservert')
            AND (cs.start_tid IS NULL OR cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY))
          ORDER BY cs.start_tid, b.id"
    );

    Svar::json([
        'deltakere' => array_map(static fn($d) => [
            'id'      => (int) $d['id'],
            // Hvem raden gjelder, ikke bare hva den heter.
            //
            // Uten disse to kunne admin se en deltaker, men ikke slaa opp
            // hva hen selv ser paa Min side, og ikke hoppe til datoen for
            // aa legge til noen. Navn er ingen noekkel — to kan hete det
            // samme, og en gjest har ingen konto i det hele tatt.
            'medlemId' => $d['member_id'] !== null ? (int) $d['member_id'] : null,
            'oktId'    => $d['course_session_id'] !== null ? (int) $d['course_session_id'] : null,
            'navn'    => $d['navn'],
            'epost'   => $d['epost'],
            'tlf'     => $d['telefon'],
            'kurs'    => $d['tittel'],
            'dato'    => $d['start_tid'] ? Booking::norskDato((string) $d['start_tid']) : 'Uten dato',
            'sum'     => Booking::kroner((int) $d['belop_ore']),
            // Hvordan den ble gjort opp. Manuelle paameldinger har det
            // skrevet i klartekst; nettbestillinger gikk gjennom Vipps.
            'maate'   => $d['vipps_reference'] ? 'Vipps' : ($d['betalt_maate'] ?: '—'),
            'manuell' => $d['lagt_inn_av'] !== null,
            'notat'   => $d['notat'],
            // Det deltakeren selv har oppgitt om allergier.
            //
            // «harAllergier» staar for seg: kurslista skal kunne merke raden
            // uten aa vise innholdet for noen har aapnet deltakeren. Det er
            // helseopplysninger, og de skal ikke ligge og lyse i en tabell
            // som er oppe paa en skjerm i verkstedet.
            'harAllergier' => trim((string) ($d['allergier'] ?? '')) !== '',
            'allergier'    => (string) ($d['allergier'] ?? ''),
            'status'  => $d['status'] === 'betalt' ? 'Betalt' : 'Ubetalt',
            'antall'  => (int) $d['antall'],
            'folge'   => $d['folge_medlem'],
            'referanse' => $d['vipps_reference'],
            // Kursbevis for gjennomforte, betalte kurs. Verkstedet skal kunne
            // skrive ut for noen som ikke faar det til selv.
            'kursbevis' => $kursbevis($d),
            // Og rette det. Beviset bygges av paameldingen, saa et navn som
            // ble stavet feil ved paamelding sto feil paa arket. Rettingen
            // laa bare inne i personruta — og en gjest uten konto har ingen.
            'bevisMulig'   => (string) ($d['tema'] ?? '') !== 'Drop-in'
                && $d['start_tid'] !== null
                && strtotime((string) ($d['slutt_tid'] ?: $d['start_tid'])) < time(),
            'bevisNavn'    => (string) ($d['bevis_navn'] ?? ''),
            'bevisKurs'    => (string) ($d['bevis_kurs'] ?? ''),
            'bevisSperret' => !empty($d['bevis_sperret']),
        ], $alle),
        // Ledige plasser paa alle oektene i én sporring. Sto det ett kall
        // per oekt, var det tre sporringer per dato — og datoene lages naa av
        // aapningstidene, saa de blir mange.
        'okter' => array_map(static fn($o) => [
        'oktId'     => (int) $o['id'],
        'tittel'    => $o['tittel'],
        'naar'      => Booking::norskDato((string) $o['start_tid']),
        'betalt'    => (int) $o['betalt'],
        'reservert' => (int) $o['reservert'],
        'kapasitet' => (int) $o['kapasitet'],
        'ledige'    => $ledigeKart[(int) $o['id']] ?? 0,
        // Hva slags oekt det er. Uten dette kunne ikke admin skille kurs,
        // event og drop-in fra hverandre naar noen skal registreres paa én
        // av dem — alt sto i én lang liste.
        'type'      => (string) ($o['type'] ?? 'kurs'),
        'tema'      => (string) ($o['tema'] ?? ''),
        // Datoen og klokkeslettet slik de staar i et dato- og et tidsfelt,
        // i norsk tid. «naar» over er ferdig skrevet for aa leses; disse er
        // for aa kunne rettes. Uten dem maatte skjemaet tolke «tirsdag 25.
        // august, 10:00» tilbake til tall, og det er en feilkilde uten
        // grunn.
        'dato'      => $iOslo((string) $o['start_tid'], 'Y-m-d'),
        'fra'       => $iOslo((string) $o['start_tid'], 'H:i'),
        'til'       => $o['slutt_tid'] === null ? '' : $iOslo((string) $o['slutt_tid'], 'H:i'),
        // Laget av en regel — aapningstida eller ukereglene under Drop-in.
        // De gir mange like rader paa samme dag, og samles til én linje.
        'auto'      => (int) ($o['fra_apningstid'] ?? 0) === 1
                    || ($o['fra_dropin_tid'] ?? null) !== null,
        // Er kurset ute paa nettsida? To datoer som ser like ut, der den ene
        // hoerer til et kurs som ligger som utkast, er ikke to like datoer.
        'publisert' => (string) ($o['kurs_status'] ?? 'publisert') === 'publisert',
    ], $okter),
    ]);
}

$okt = DB::en(
    'SELECT cs.id, cs.start_tid, c.tittel FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id WHERE cs.id = :id',
    ['id' => $oktId]
);
if ($okt === null) {
    Svar::feil('Fant ikke datoen.', 404);
}

$deltakere = DB::alle(
    "SELECT b.id, b.antall, b.status, b.belop_ore, b.created_at, b.folge_medlem,
            b.betalt_maate, b.notat, b.lagt_inn_av,
            COALESCE(m.navn, b.gjest_navn) AS navn,
            COALESCE(m.epost, b.gjest_epost) AS epost,
            COALESCE(m.telefon, b.gjest_telefon) AS telefon,
            p.vipps_reference, p.status AS betalingsstatus
       FROM bookings b
  LEFT JOIN members m ON m.id = b.member_id
  LEFT JOIN payments p ON p.id = b.payment_id
      WHERE b.course_session_id = :id
        AND b.status <> 'avbestilt'
      ORDER BY b.created_at",
    ['id' => $oktId]
);

Svar::json([
    'okt' => [
        'oktId'  => (int) $okt['id'],
        'tittel' => $okt['tittel'],
        'naar'   => Booking::norskDato((string) $okt['start_tid']),
    ],
    'deltakere' => array_map(static fn($d) => [
        'id'        => (int) $d['id'],
        'navn'      => $d['navn'],
        'epost'     => $d['epost'],
        'telefon'   => $d['telefon'],
        'antall'    => (int) $d['antall'],
        'status'    => $d['status'],
        'belop'     => Booking::kroner((int) $d['belop_ore']),
        'referanse' => $d['vipps_reference'],
        'maate'     => $d['vipps_reference'] ? 'Vipps' : ($d['betalt_maate'] ?: '—'),
        'manuell'   => $d['lagt_inn_av'] !== null,
        'notat'     => $d['notat'],
        'folge'     => $d['folge_medlem'],
    ], $deltakere),
]);
