<?php
/**
 * «Ferdig glassert» — keramikken som er brent og skal hentes.
 *
 *   GET                          kursdatoene, med tall per dato
 *   GET  ?oktId=12               deltakerne på én dato
 *   POST handling=meld-en        { bookingId }        beskjed til én
 *   POST handling=meld-alle      { oktId }            til alle som mangler
 *   POST handling=notat          { bookingId, notat } internt notat
 *   POST handling=hentet         { bookingId, hentet } kryss av for hentet
 *   POST handling=angre          { oktId }            ta linja av nettsida
 *
 * Nivået var kursdatoen: ett trykk sendte til alle, og ett tidspunkt ble
 * lagret på økta. Da fantes det ingen måte å se hvem som hadde fått beskjed
 * og hvem som ikke hadde — og feilet en e-post, forsvant det i stillhet.
 *
 * Nå er nivået deltakeren. Hvem som har fått beskjed leses av meldingskøen
 * framfor å lagres på nytt et sted: notifications vet allerede mottaker,
 * kanal, emne, tekst, tidspunkt og om det gikk eller feilet. Beskjeden sendes
 * med booking-id i ref_id, og da er hele historikken der — også når noe
 * sendes flere ganger.
 *
 * course_sessions.hentemelding_at står som før. Den styrer linja på
 * lissom.no/ferdigbrent, som er noe annet enn hvem som har fått e-post.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (!DB::harKolonne('course_sessions', 'hentemelding_at')) {
    Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
}
$harDeltakernivaa = DB::harTabell('deltaker_bilder') && DB::harKolonne('bookings', 'internt_notat');

/**
 * Hvor lenge arbeidene oppbevares, og dermed hvor lenge meldingen staar.
 *
 * To uker. Sto som tre her og i malen, mens spoersmaal og svar paa nettsiden
 * sa to — en kunde som leste SMS-en trodde hen hadde en uke ekstra. Eieren,
 * 1. september: «to uker».
 *
 * Merk at dette er noe annet enn brennetida. Den er to til fire uker, og
 * staar i Kursmal::HENTING.
 */
const UKER_OPPBEVARING = 2;

/** Malen som sier at keramikken er ferdig. Har ligget i basen siden 002. */
const MAL = 'ferdig_brent';

/**
 * Hva meldingskøen vet om denne deltakeren.
 *
 * Én rad per utsendelse, nyeste først. Vi lagrer ikke «sendt» noe annet sted:
 * da ville det stått to sannheter, og de ville før eller siden spriket.
 *
 * @return array<int, array<string, mixed>>
 */
function meldingshistorikk(int $bookingId): array
{
    if (!DB::harTabell('notifications')) {
        return [];
    }
    return DB::alle(
        "SELECT kanal, mottaker, emne, tekst, status, feilmelding, sendt_at, created_at
           FROM notifications
          WHERE mal = :m AND ref_type = 'booking' AND ref_id = :b
       ORDER BY id DESC",
        ['m' => MAL, 'b' => $bookingId]
    );
}

/**
 * Statusen til én deltaker, regnet ut av det som finnes.
 *
 * Rekkefølgen betyr noe: er noe hentet, er det hentet, uansett hva køen sier
 * om e-posten. Og har én kanal gått gjennom, har hen fått beskjed — selv om
 * SMS-en feilet.
 */
function deltakerstatus(array $historikk, ?string $hentetAt): array
{
    if ($hentetAt !== null) {
        return ['status' => 'Hentet', 'tone' => 'nøytral'];
    }
    if ($historikk === []) {
        return ['status' => 'Ikke sendt', 'tone' => 'venter'];
    }
    foreach ($historikk as $h) {
        if ($h['status'] === 'sendt') {
            return ['status' => 'Sendt beskjed', 'tone' => 'god'];
        }
    }
    foreach ($historikk as $h) {
        if ($h['status'] === 'ko') {
            return ['status' => 'I kø', 'tone' => 'venter'];
        }
    }
    return ['status' => 'Utsendelse feilet', 'tone' => 'feil'];
}

/** Bildene deltakeren har lastet opp. */
function deltakerbilder(int $bookingId): array
{
    if (!DB::harTabell('deltaker_bilder')) {
        return [];
    }
    return array_map(
        static fn($b) => [
            'id'   => (int) $b['id'],
            'url'  => 'api/bilde.php?deltaker=' . $b['fil'],
            'naar' => Booking::norskDato((string) $b['created_at']),
            'egen' => $b['lastet_opp_av'] !== null,
        ],
        DB::alle('SELECT id, fil, lastet_opp_av, created_at FROM deltaker_bilder
                   WHERE booking_id = :b ORDER BY id',
                 ['b' => $bookingId])
    );
}

/** Deltakerne på én kursdato, med alt som hører til hver av dem. */
function deltakerne(int $oktId, bool $medDetaljer): array
{
    $kolonner = 'b.id, b.antall, b.status, b.created_at, '
              . 'COALESCE(m.navn, b.gjest_navn) AS navn, '
              . 'COALESCE(m.epost, b.gjest_epost) AS epost, '
              . 'COALESCE(m.telefon, b.gjest_telefon) AS telefon';
    if (DB::harKolonne('bookings', 'internt_notat')) {
        $kolonner .= ', b.internt_notat, b.hentet_at';
    }

    $rader = DB::alle(
        "SELECT $kolonner
           FROM bookings b
      LEFT JOIN members m ON m.id = b.member_id
          WHERE b.course_session_id = :o AND b.status IN ('betalt', 'reservert')
       ORDER BY COALESCE(m.navn, b.gjest_navn)",
        ['o' => $oktId]
    );

    $ut = [];
    foreach ($rader as $r) {
        $id = (int) $r['id'];
        $hist = meldingshistorikk($id);
        $st = deltakerstatus($hist, $r['hentet_at'] ?? null);
        $siste = $hist[0] ?? null;

        $d = [
            'id'       => $id,
            'navn'     => $r['navn'] ?: 'Uten navn',
            'epost'    => $r['epost'] ?: '',
            'telefon'  => $r['telefon'] ?: '',
            'antall'   => (int) $r['antall'],
            'status'   => $st['status'],
            'tone'     => $st['tone'],
            'sendt'    => $st['status'] === 'Sendt beskjed',
            // Lagt i koen, ikke sendt enda. Verkstedet har gjort sitt; koen
            // staar for resten. Da skal ikke «send til alle» sende paa nytt.
            'venter'   => $st['status'] === 'I kø',
            'kanSende' => ($r['epost'] ?? '') !== '' || ($r['telefon'] ?? '') !== '',
            'hentet'   => ($r['hentet_at'] ?? null) !== null,
            'notat'    => (string) ($r['internt_notat'] ?? ''),
            'bilder'   => deltakerbilder($id),
            // Siste utsendelse, som det som vises på raden.
            'sisteNaar'  => $siste ? Booking::norskDato((string) ($siste['sendt_at'] ?: $siste['created_at'])) : null,
            'sisteKanal' => $siste ? ($siste['kanal'] === 'sms' ? 'SMS' : 'E-post') : null,
            'sisteFeil'  => $siste && $siste['status'] === 'feilet'
                              ? ((string) $siste['feilmelding'] ?: 'Ukjent feil') : null,
        ];

        if ($medDetaljer) {
            $d['historikk'] = array_map(static fn($h) => [
                'kanal'     => $h['kanal'] === 'sms' ? 'SMS' : 'E-post',
                'mottaker'  => (string) $h['mottaker'],
                'emne'      => (string) ($h['emne'] ?? ''),
                'tekst'     => (string) $h['tekst'],
                'status'    => $h['status'] === 'sendt' ? 'Sendt'
                             : ($h['status'] === 'feilet' ? 'Feilet' : 'I kø'),
                // feilmelding staar igjen fra forrige forsoek ogsaa naar
                // neste gikk gjennom. Da hoerer den ikke hjemme i en linje
                // som sier «Sendt».
                'feil'      => $h['status'] === 'feilet' ? (string) ($h['feilmelding'] ?? '') : '',
                'naar'      => Booking::norskDato((string) ($h['sendt_at'] ?: $h['created_at'])),
            ], $hist);
        }

        $ut[] = $d;
    }
    return $ut;
}

// ── Deltakerne på én dato ──────────────────────────────────────────────────
if (Foresporsel::metode() === 'GET' && Foresporsel::tekst('oktId') !== '') {
    $oktId = Foresporsel::heltall('oktId');
    $okt = DB::en(
        "SELECT cs.id, cs.start_tid, cs.hentemelding_at, c.tittel, c.tema
           FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id
          WHERE cs.id = :i",
        ['i' => $oktId]
    );
    if ($okt === null) {
        Svar::feil('Fant ikke datoen.', 404);
    }

    $mal = DB::en('SELECT emne, tekst FROM notification_templates WHERE navn = :n', ['n' => MAL]);

    Svar::json([
        'ok'    => true,
        'okt'   => [
            'oktId'  => $oktId,
            'tittel' => $okt['tittel'],
            'tema'   => $okt['tema'],
            'naar'   => Booking::norskDato((string) $okt['start_tid']),
            'meldt'  => $okt['hentemelding_at'] !== null,
        ],
        'deltakere' => deltakerne($oktId, true),
        // Teksten som faktisk sendes, så bekreftelsen kan vise den.
        'melding'   => [
            'emne'  => (string) ($mal['emne'] ?? ''),
            'tekst' => (string) ($mal['tekst'] ?? ''),
        ],
        'uker' => UKER_OPPBEVARING,
    ]);
}

// ── Kursdatoene ────────────────────────────────────────────────────────────
if (Foresporsel::metode() === 'GET') {
    // Bare det som faktisk er ferdig, og ikke for lenge siden. Et kurs fra i
    // fjor hører ikke hjemme i en liste over noe som skal gjøres i dag.
    $okter = DB::alle(
        "SELECT cs.id, cs.start_tid, cs.hentemelding_at, c.tittel, c.tema
           FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id
          WHERE cs.status = 'planlagt'
            AND COALESCE(cs.slutt_tid, cs.start_tid) < UTC_TIMESTAMP()
            AND cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 16 WEEK)
            AND c.type <> 'dropin'
       ORDER BY cs.start_tid DESC"
    );

    $ut = [];
    foreach ($okter as $o) {
        $deltakere = deltakerne((int) $o['id'], false);
        // En dato ingen kom på har ingen keramikk å hente.
        if ($deltakere === []) {
            continue;
        }
        $sendt = 0;
        $venter = 0;
        $bilder = 0;
        $feilet = 0;
        foreach ($deltakere as $d) {
            if ($d['sendt'] || $d['hentet']) {
                $sendt++;
            } elseif ($d['venter']) {
                $venter++;
            }
            if ($d['status'] === 'Utsendelse feilet') {
                $feilet++;
            }
            $bilder += count($d['bilder']);
        }
        $antall = count($deltakere);
        // Den som staar i koen mangler ikke beskjed — den er sendt fra
        // verkstedet, og koen har den. Ellers ville kurset ligget som
        // «ingen har faatt beskjed» til koen tomte seg, og et nytt trykk
        // ville ikke gjort noe.
        $mangler = $antall - $sendt - $venter;

        $ut[] = [
            'oktId'     => (int) $o['id'],
            'tittel'    => $o['tittel'],
            'tema'      => $o['tema'],
            'naar'      => Booking::norskDato((string) $o['start_tid']),
            'deltakere' => $antall,
            'sendt'     => $sendt,
            'mangler'   => $mangler,
            'feilet'    => $feilet,
            'bilder'    => $bilder,
            // Hele kurset er ferdig først når alle har fått beskjed.
            'venter'    => $venter,
            // Ferdig foerst naar alle faktisk har faatt den — koen teller
            // ikke her, for den kan enda feile.
            'ferdig'    => $sendt === $antall,
            'status'    => $mangler === 0
                             ? ($sendt === $antall
                                 ? 'Alle har fått beskjed'
                                 : $venter . ' ' . ($venter === 1 ? 'beskjed står' : 'beskjeder står') . ' i kø')
                             : ($sendt + $venter > 0
                                 ? $mangler . ' av ' . $antall . ' mangler beskjed'
                                 : 'Ingen har fått beskjed'),
            'meldt'     => $o['hentemelding_at'] !== null,
            'meldtNaar' => $o['hentemelding_at']
                             ? Booking::norskDato((string) $o['hentemelding_at'])
                             : null,
        ];
    }

    Svar::json([
        'ok'    => true,
        'okter' => $ut,
        'uker'  => UKER_OPPBEVARING,
        'klar'  => $harDeltakernivaa,
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');

/**
 * Sender beskjeden til én deltaker, og sier om noe ble lagt i kø.
 *
 * Sendes med booking-id i ref_id. Det er hele grunnlaget for å vite hvem som
 * har fått beskjed — og for at en ny utsendelse legger seg ved siden av den
 * forrige framfor å viske den ut.
 */
function meldEn(array $b, string $kurs, string $naar): bool
{
    if (($b['epost'] ?? '') === '' && ($b['telefon'] ?? '') === '') {
        return false;
    }
    $for = (int) DB::verdi(
        "SELECT COUNT(*) FROM notifications WHERE mal = :m AND ref_type = 'booking' AND ref_id = :b",
        ['m' => MAL, 'b' => (int) $b['id']]
    );
    Varsel::mal(MAL, [
        'navn'    => $b['navn'] ?: 'du',
        'epost'   => $b['epost'] ?: null,
        'telefon' => $b['telefon'] ?: null,
    ], [
        'navn' => $b['navn'] ?: 'du',
        'kurs' => $kurs,
        'dato' => $naar,
    ], 'booking', (int) $b['id']);

    // Varsel::mal() sier ikke fra om den fikk lagt noe i kø. Vi teller
    // radene før og etter i stedet — da vet vi det sikkert.
    $etter = (int) DB::verdi(
        "SELECT COUNT(*) FROM notifications WHERE mal = :m AND ref_type = 'booking' AND ref_id = :b",
        ['m' => MAL, 'b' => (int) $b['id']]
    );
    return $etter > $for;
}

/** Én deltaker, med navn og kontakt slik meldEn() vil ha det. */
function hentDeltaker(int $bookingId): ?array
{
    return DB::en(
        "SELECT b.id, b.course_session_id,
                COALESCE(m.navn, b.gjest_navn) AS navn,
                COALESCE(m.epost, b.gjest_epost) AS epost,
                COALESCE(m.telefon, b.gjest_telefon) AS telefon,
                c.tittel, cs.start_tid
           FROM bookings b
      LEFT JOIN members m ON m.id = b.member_id
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
          WHERE b.id = :i AND b.status IN ('betalt', 'reservert')",
        ['i' => $bookingId]
    );
}

// ── Beskjed til én ─────────────────────────────────────────────────────────
if ($handling === 'meld-en') {
    $b = hentDeltaker(Foresporsel::heltall('bookingId'));
    if ($b === null) {
        Svar::feil('Fant ikke deltakeren.', 404);
    }
    $naar = Booking::norskDato((string) ($b['start_tid'] ?? ''));
    if (!meldEn($b, (string) $b['tittel'], $naar)) {
        Svar::feil($b['navn'] . ' har verken e-post eller telefon, og må få beskjed på annen måte.');
    }
    revider('ferdigbrent_meldt_en', 'booking', (int) $b['id'], ['kurs' => $b['tittel']]);
    Svar::ok([
        'beskjed'   => 'Beskjed lagt i kø til ' . $b['navn'] . '.',
        'deltakere' => deltakerne((int) $b['course_session_id'], true),
    ]);
}

// ── Beskjed til alle som mangler ───────────────────────────────────────────
//
// Bare de som ikke har fått. Den som alt har beskjed, skal ikke få den to
// ganger fordi noen trykte på nytt.
if ($handling === 'meld-alle') {
    $oktId = Foresporsel::heltall('oktId');
    $okt = DB::en(
        "SELECT cs.id, cs.start_tid, c.tittel FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id WHERE cs.id = :i",
        ['i' => $oktId]
    );
    if ($okt === null) {
        Svar::feil('Fant ikke datoen.', 404);
    }
    $naar = Booking::norskDato((string) $okt['start_tid']);

    $sendt = 0;
    $uten = [];
    $ikoe = 0;
    foreach (deltakerne($oktId, false) as $d) {
        if ($d['sendt'] || $d['hentet']) {
            continue;
        }
        // Ligger beskjeden alt i koen, er den sendt herfra. Et nytt trykk
        // skal ikke legge den inn en gang til — da ville deltakeren faatt
        // to like e-poster fordi noen var utaalmodig.
        if ($d['venter']) {
            $ikoe++;
            continue;
        }
        $b = hentDeltaker((int) $d['id']);
        if ($b === null) {
            continue;
        }
        if (meldEn($b, (string) $okt['tittel'], $naar)) {
            $sendt++;
        } else {
            $uten[] = $d['navn'];
        }
    }

    // Linja på nettsida settes når noen faktisk har fått beskjed.
    if ($sendt > 0) {
        DB::kjor(
            'UPDATE course_sessions SET hentemelding_at = COALESCE(hentemelding_at, UTC_TIMESTAMP()),
                    hentemelding_av = :a WHERE id = :i',
            ['a' => (Sesjon::medlem()['id'] ?? null), 'i' => $oktId]
        );
    }

    revider('ferdigbrent_meldt_alle', 'course_session', $oktId,
            ['kurs' => $okt['tittel'], 'sendt' => $sendt]);

    $tekst = $sendt === 0
        ? ($ikoe > 0 ? 'Alle beskjedene ligger alt i kø. Ingen fikk den to ganger.' : 'Ingen fikk beskjed.')
        : ($sendt === 1 ? 'Én deltaker har fått beskjed.' : $sendt . ' deltakere har fått beskjed.');
    if ($sendt > 0 && $ikoe > 0) {
        $tekst .= ' ' . $ikoe . ' lå alt i kø og fikk den ikke på nytt.';
    }
    if ($uten !== []) {
        $tekst .= ' ' . implode(', ', $uten) . ' står uten e-post og telefon og må kontaktes selv.';
    }
    if ($sendt > 0) {
        $tekst .= ' Meldingen står på lissom.no/ferdigbrent i ' . UKER_OPPBEVARING . ' uker.';
    }

    Svar::ok([
        'beskjed'   => $tekst,
        'sendt'     => $sendt,
        'deltakere' => deltakerne($oktId, true),
    ]);
}

// ── Internt notat ──────────────────────────────────────────────────────────
//
// Bare for verkstedet. Ingen kundevendt endepunkt leser denne kolonnen.
if ($handling === 'notat') {
    if (!DB::harKolonne('bookings', 'internt_notat')) {
        Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
    }
    $id = Foresporsel::heltall('bookingId');
    $b = DB::en('SELECT id, course_session_id FROM bookings WHERE id = :i', ['i' => $id]);
    if ($b === null) {
        Svar::feil('Fant ikke deltakeren.', 404);
    }
    $notat = mb_substr(trim((string) (Foresporsel::kropp()['notat'] ?? '')), 0, 2000);
    DB::oppdater('bookings', ['internt_notat' => $notat !== '' ? $notat : null], ['id' => $id]);
    revider('ferdigbrent_notat', 'booking', $id);
    Svar::ok([
        'beskjed'   => $notat === '' ? 'Notatet er tømt.' : 'Notatet er lagret.',
        'deltakere' => deltakerne((int) $b['course_session_id'], true),
    ]);
}

// ── Hentet ─────────────────────────────────────────────────────────────────
if ($handling === 'hentet') {
    if (!DB::harKolonne('bookings', 'hentet_at')) {
        Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
    }
    $id = Foresporsel::heltall('bookingId');
    $b = DB::en('SELECT id, course_session_id FROM bookings WHERE id = :i', ['i' => $id]);
    if ($b === null) {
        Svar::feil('Fant ikke deltakeren.', 404);
    }
    $paa = (string) (Foresporsel::kropp()['hentet'] ?? '') === 'ja';
    DB::oppdater('bookings', ['hentet_at' => $paa ? gmdate('Y-m-d H:i:s') : null], ['id' => $id]);
    revider('ferdigbrent_hentet', 'booking', $id, ['hentet' => $paa]);
    Svar::ok([
        'beskjed'   => $paa ? 'Merket som hentet.' : 'Merket som ikke hentet.',
        'deltakere' => deltakerne((int) $b['course_session_id'], true),
    ]);
}

// ── Ta meldingen ned fra nettsida ──────────────────────────────────────────
//
// Trykket du feil kurs, skal linja bort med det samme. E-postene som alt er
// sendt kan vi ikke hente tilbake — det står i svaret, og historikken beholdes.
if ($handling === 'angre') {
    $oktId = Foresporsel::heltall('oktId');
    DB::kjor('UPDATE course_sessions SET hentemelding_at = NULL, hentemelding_av = NULL WHERE id = :i',
             ['i' => $oktId]);
    revider('hentemelding_fjernet', 'course_session', $oktId);
    Svar::ok(['beskjed' => 'Meldingen er tatt ned fra nettsiden. E-post som alt er sendt, står som sendt.']);
}

Svar::feil('Ukjent handling.');
