<?php
/**
 * Foresporsler fra nettsiden — les og merk som besvart.
 *
 *   GET                     lister dem, med svarene som er sendt
 *   POST {id, status}       merker en som besvart eller ubesvart igjen
 *   POST handling=svar      { id, tekst }  svarer den som spurte
 *
 * Foer kunne en henvendelse bare merkes som besvart — selve svaret skulle
 * skrives i e-postklienten, utenfor systemet. I admin kunne man dermed sende
 * en ny melding til alle medlemmer, eller huke av at man hadde svart, men
 * ikke faktisk svare den som spurte.
 *
 * Naa gaar svaret ut som e-post (og SMS naar det er satt opp), det lagres paa
 * henvendelsen, og henvendelsen merkes som besvart i samme slengen.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rader = DB::alle(
        "SELECT id, navn, epost, telefon, type, antall, melding, status, created_at
           FROM enquiries
       ORDER BY status = 'ubesvart' DESC, id DESC
          LIMIT 200"
    );

    // Svarene som er sendt, samlet per henvendelse. Uten dem staar det bare
    // «Besvart» uten aa vise hva som faktisk ble sagt — og da maa man lete i
    // e-postklienten for aa vite hvor saken staar.
    $svar = [];
    if (DB::harTabell('foresporsel_svar')) {
        $oslo = new DateTimeZone('Europe/Oslo');
        foreach (DB::alle(
            'SELECT s.enquiry_id, s.tekst, s.created_at, s.sendt_epost, s.sendt_sms, m.navn
               FROM foresporsel_svar s
          LEFT JOIN members m ON m.id = s.member_id
           ORDER BY s.id'
        ) as $r) {
            $svar[(int) $r['enquiry_id']][] = [
                'tekst' => (string) $r['tekst'],
                'av'    => (string) ($r['navn'] ?? 'Verkstedet'),
                'tid'   => (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))
                            ->setTimezone($oslo)->format('j.n. H:i'),
                'kanal' => $r['sendt_epost'] ? 'e-post' : ($r['sendt_sms'] ? 'SMS' : 'ikke sendt'),
            ];
        }
    }

    // ── Beskjeder som er sendt ────────────────────────────────────────
    //
    // Kortet «Sendt til medlemmene» sto med tekst fra designfila: fire
    // oppdiktede beskjeder i forhaandsvisningen, og ingenting paa den ekte
    // sida. Beskjedene fantes — de laa i varselkoen — men ingen kunne se dem
    // igjen etterpaa, og overskrifta sa «medlemmene» ogsaa naar du kom fra
    // deltakerne.
    //
    // Én rad per beskjed, ikke per mottaker: samme emne, samme gruppe, samme
    // minutt er den samme beskjeden sendt til flere.
    $sendte = [];
    foreach (DB::alle(
        "SELECT ref_type, ref_id, emne, MIN(tekst) AS tekst,
                MIN(created_at) AS naar,
                COUNT(*) AS antall,
                SUM(status = 'sendt') AS ute,
                SUM(status = 'feilet') AS feilet
           FROM notifications
          WHERE ref_type IN ('beskjed', 'beskjed-medlem', 'beskjed-okt', 'beskjed-en')
       GROUP BY ref_type, ref_id, emne, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')
       ORDER BY naar DESC
          LIMIT 40"
    ) as $r) {
        $okt = null;
        if ($r['ref_type'] === 'beskjed-okt' && $r['ref_id']) {
            $okt = DB::en(
                'SELECT c.tittel, cs.start_tid FROM course_sessions cs
                   JOIN courses c ON c.id = cs.course_id WHERE cs.id = :i',
                ['i' => (int) $r['ref_id']]
            );
        }

        $sendte[] = [
            // «beskjed» uten hale er de som ble sendt for gruppa ble lagret.
            // De gikk til medlemmene — det var den eneste veien da.
            'gruppe'  => $r['ref_type'] === 'beskjed-okt' ? 'deltakere'
                       : ($r['ref_type'] === 'beskjed-en' ? 'en' : 'medlemmer'),
            'tittel'  => (string) $r['emne'],
            'tekst'   => (string) $r['tekst'],
            'til'     => $okt !== null
                ? $okt['tittel'] . ' — ' . Booking::norskDato((string) $okt['start_tid'])
                : ($r['ref_type'] === 'beskjed-en' ? 'Én mottaker' : 'Medlemmene'),
            'tid'     => Booking::norskDato((string) $r['naar']),
            'antall'  => (int) $r['antall'],
            // Hva som faktisk gikk ut. «Sendt til 12» er ikke sant hvis fire
            // av dem feilet, og det er verkstedet som blir spurt.
            'levert'  => (int) $r['ute'] . ' av ' . (int) $r['antall'],
            'feilet'  => (int) $r['feilet'],
        ];
    }

    Svar::json([
        'sendte' => $sendte,
        'foresporsler' => array_map(static fn(array $r): array => [
            'id'      => (int) $r['id'],
            'navn'    => (string) $r['navn'],
            'epost'   => (string) ($r['epost'] ?? ''),
            'tlf'     => (string) ($r['telefon'] ?? ''),
            'hva'     => trim(((string) ($r['type'] ?? 'Forespørsel'))
                        . ($r['antall'] ? ', ' . $r['antall'] . ' personer' : '')),
            'tekst'   => (string) ($r['melding'] ?? 'Ingen detaljer oppgitt'),
            'tid'     => Booking::norskDato((string) $r['created_at']),
            'status'  => $r['status'] === 'ubesvart' ? 'Ubesvart' : 'Besvart',
            'svar'    => $svar[(int) $r['id']] ?? [],
            'tone'    => $r['status'] === 'ubesvart' ? 'warning' : 'neutral',
        ], $rader),
        'ubesvarte' => (int) DB::verdi("SELECT COUNT(*) FROM enquiries WHERE status = 'ubesvart'"),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (Foresporsel::tekst('handling') === 'svar') {
    if (!DB::harTabell('foresporsel_svar')) {
        Svar::feil('Svar krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
    }
    $id = Foresporsel::heltall('id');
    $f = DB::en('SELECT id, navn, epost, telefon, melding FROM enquiries WHERE id = :id', ['id' => $id]);
    if ($f === null) {
        Svar::feil('Fant ikke forespørselen.', 404);
    }
    $tekst = trim(Foresporsel::tekst('tekst'));
    if ($tekst === '') {
        Svar::feil('Skriv svaret først.');
    }

    $epost = trim((string) ($f['epost'] ?? ''));
    $tlf   = trim((string) ($f['telefon'] ?? ''));
    if ($epost === '' && $tlf === '') {
        Svar::feil('Denne henvendelsen har verken e-post eller telefon. Da finnes det ingen vei tilbake til avsenderen.');
    }

    // Spoersmaalet foelger med i svaret. Det kan ha gaatt dager, og «ja, det
    // gaar fint» uten sammenheng er ubrukelig for den som spurte.
    $kropp = 'Hei' . ($f['navn'] ? ' ' . $f['navn'] : '') . ",\n\n"
        . $tekst . "\n\n"
        . "— Lissom Keramikk & Håndverk\n"
        . Config::nettsted() . "\n\n"
        . "-- Du skrev til oss:\n"
        . trim((string) $f['melding']) . "\n";

    $sendtEpost = false;
    $sendtSms = false;
    if ($epost !== '') {
        Varsel::epost($epost, 'Svar fra Lissom Keramikk', $kropp, 'enquiry', $id);
        $sendtEpost = true;
    }
    // SMS bare naar det ikke finnes en e-postadresse. Ellers faar folk det
    // samme to ganger, og et langt svar hoerer uansett hjemme i en e-post.
    if (!$sendtEpost && $tlf !== '' && Varsel::smsMulig()) {
        Varsel::sms($tlf, 'Svar fra Lissom: ' . $tekst, 'enquiry', $id);
        $sendtSms = true;
    }

    $svarId = DB::settInn('foresporsel_svar', [
        'enquiry_id'  => $id,
        'member_id'   => $admin['id'],
        'tekst'       => $tekst,
        'sendt_epost' => $sendtEpost ? 1 : 0,
        'sendt_sms'   => $sendtSms ? 1 : 0,
    ]);

    DB::oppdater('enquiries', [
        'status'     => 'besvart',
        'besvart_at' => gmdate('Y-m-d H:i:s'),
        'besvart_av' => $admin['id'],
    ], ['id' => $id]);

    revider('foresporsel_svart', 'enquiry', $id, ['svar' => $svarId]);

    Svar::ok([
        'svarId'  => $svarId,
        'beskjed' => $sendtEpost
            ? 'Svaret er sendt til ' . $epost . '.'
            : ($sendtSms
                ? 'Svaret er sendt som SMS til ' . $tlf . '.'
                : 'Svaret er lagret, men kunne ikke sendes — sett opp e-post under Markedsføring → E-post og SMS.'),
    ]);
}

$id = Foresporsel::heltall('id');
$status = Foresporsel::tekst('status') === 'ubesvart' ? 'ubesvart' : 'besvart';

if (!DB::en('SELECT id FROM enquiries WHERE id = :id', ['id' => $id])) {
    Svar::feil('Fant ikke forespørselen.', 404);
}

DB::oppdater('enquiries', [
    'status'     => $status,
    'besvart_at' => $status === 'besvart' ? gmdate('Y-m-d H:i:s') : null,
    'besvart_av' => $status === 'besvart' ? $admin['id'] : null,
], ['id' => $id]);

revider('foresporsel_' . $status, 'enquiry', $id);
Svar::ok(['status' => $status]);
