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

        // Loper det en avtale i Vipps, blir kunden trukket videre selv om
        // vi setter statusen her. Da ville lista sagt «sluttet» mens
        // pengene fortsatt gikk.
        $avtale = DB::en(
            "SELECT id, vipps_agreement_id FROM subscriptions
              WHERE member_id = :m AND status = 'aktiv' LIMIT 1",
            ['m' => $id]
        );

        if ($handling === 'avslutt') {
            if ($avtale !== null) {
                Svar::feil('Medlemmet har en løpende Vipps-avtale. Den må sies opp først, '
                         . 'ellers fortsetter trekket etter at medlemskapet er avsluttet.');
            }
            DB::oppdater('members', [
                'status'     => 'oppsagt',
                'slutt_dato' => date('Y-m-d'),
            ], ['id' => $id]);
            revider('medlem_avsluttet', 'member', $id, ['av' => (int) $jeg['id']]);
            Svar::ok(['beskjed' => ($m['navn'] ?: 'Medlemmet') . ' står nå som sluttet. '
                                 . 'Historikken og kursbevisene er beholdt.']);
        }

        if ($handling === 'gjenapne') {
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

        if (!$harHistorikk) {
            DB::kjor('DELETE FROM members WHERE id = :id', ['id' => $id]);
            revider('medlem_slettet', 'member', $id, ['navn' => $m['navn']]);
            Svar::ok(['beskjed' => 'Medlemmet er slettet.']);
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
        'timer_per_mnd'   => $plan !== null && $plan['timer_per_mnd'] !== null
                                ? (int) $plan['timer_per_mnd'] : null,
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
if (Foresporsel::heltall('person') > 0) {
    $pid = Foresporsel::heltall('person');
    $m = DB::en('SELECT id, navn, epost, telefon, rolle, medlemskap_type, status FROM members WHERE id = :i', ['i' => $pid]);
    if ($m === null) {
        Svar::feil('Fant ikke personen.', 404);
    }

    $rader = DB::alle(
        "SELECT b.id, b.antall, b.status, b.belop_ore, b.created_at,
                c.tittel, c.type, cs.start_tid, p.vipps_reference
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
      LEFT JOIN payments p ON p.id = b.payment_id
          WHERE b.member_id = :m
       ORDER BY cs.start_tid IS NULL, cs.start_tid DESC, b.id DESC",
        ['m' => $pid]
    );

    $naa = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    Svar::json([
        'person' => [
            'id'         => (int) $m['id'],
            'navn'       => $m['navn'],
            'epost'      => $m['epost'] ?: '',
            'telefon'    => $m['telefon'] ?: '',
            'medlemskap' => $m['medlemskap_type'] ?: 'Ingen',
            'status'     => $m['status'],
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
                'kursbevis' => ($holdt && (string) $b['status'] === 'betalt' && (string) $b['type'] !== 'dropin')
                    ? '/api/kursbevis.php?booking=' . (int) $b['id']
                    : null,
                'referanse' => $b['vipps_reference'],
            ];
        }, $rader),
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
], $medlemmer),
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
