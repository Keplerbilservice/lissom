<?php
/**
 * Tallene paa admin-forsiden. Alt hentes fra databasen — ingenting er anslag.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

/**
 * Adressen kalenderabonnementet ligger paa.
 *
 * Telefonen sender ingen innlogging naar den henter feeden — den kjenner
 * bare adressen. Derfor ligger tilgangen i selve adressen, som en lang
 * tilfeldig noekkel. Slik gjor Google, Outlook og de andre det ogsaa.
 *
 * Noekkelen lages foerste gang eieren ber om adressen, ikke i en migrasjon:
 * en tilfeldig verdi som staar i en fil i kodelageret, er den samme for alle
 * som har lest fila.
 */
$kalenderAdresse = static function (bool $lagNy = false): string {
    if (!DB::harTabell('innstillinger')) {
        return '';
    }
    $n = trim((string) Config::hent('kalender_nokkel', ''));
    if ($n === '' || $lagNy) {
        $n = bin2hex(random_bytes(24));
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            ['kalender_nokkel', $n, (int) (Sesjon::medlem()['id'] ?? 0) ?: null]
        );
        Config::glemBasen();
    }
    return Config::nettsted() . '/api/kalender-abonnement.php?nokkel=' . $n;
};

// Ny noekkel. Da slutter alle gamle adresser aa virke paa én gang — det er
// hele poenget med aa kunne bytte den.
if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();

    // ── Rekkefolgen paa kortene ────────────────────────────────────────────
    //
    // Verkstedet drar kortene dit de vil ha dem, og da skal de ligge der i
    // morgen ogsaa. Lagres som ei liste med navn: kort som kommer til senere
    // havner bakerst av seg selv, og kort som forsvinner blir bare staaende
    // igjen i lista uten aa gjore noe.
    if (Foresporsel::tekst('handling') === 'kortrekkefolge') {
        if (!DB::harTabell('innstillinger')) {
            Svar::feil('Migrasjon 036 er ikke kjørt. Kjør vedlikehold først.');
        }
        $raa = Foresporsel::kropp()['rekkefolge'] ?? [];
        if (!is_array($raa)) {
            Svar::feil('Mangler rekkefølgen.');
        }
        // Navn og ikke noe annet, og ikke flere enn det kan finnes kort.
        $navn = [];
        foreach (array_slice($raa, 0, 40) as $n) {
            if (is_string($n) && trim($n) !== '') {
                $navn[] = mb_substr(trim($n), 0, 60);
            }
        }
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            ['oversikt_kortrekkefolge', json_encode($navn, JSON_UNESCAPED_UNICODE),
             (int) (Sesjon::medlem()['id'] ?? 0) ?: null]
        );
        Svar::ok(['beskjed' => 'Rekkefølgen er lagret.']);
    }

    if (Foresporsel::tekst('handling') !== 'kalendernokkel') {
        Svar::feil('Ukjent handling.');
    }
    if (!DB::harTabell('innstillinger')) {
        Svar::feil('Migrasjon 036 er ikke kjørt. Kjør vedlikehold først.');
    }
    $adresse = $kalenderAdresse(true);
    revider('kalendernokkel_byttet');
    Svar::ok([
        'adresse' => $adresse,
        'beskjed' => 'Ny adresse laget. Den gamle virker ikke lenger — '
                   . 'abonnementer som bruker den må settes opp på nytt.',
    ]);
}

Foresporsel::krevMetode('GET');

$kroner = static fn(int $ore): string => Booking::kroner($ore);

// --- Omsetning ------------------------------------------------------------
//
// Tidspunktene i basen er UTC. «I dag» og «denne maneden» maa likevel folge
// norsk kalender: klokka 00.30 i Oslo er fortsatt gaardagen i UTC, og da ville
// et salg havnet paa feil dag. Grensene regnes derfor ut i Oslo-tid her, og
// gjores om til UTC for de gaar inn i sporringen.
$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$naa  = new DateTimeImmutable('now', $oslo);

$dagStart = $naa->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
$mndStart = $naa->modify('first day of this month')->setTime(0, 0)
                ->setTimezone($utc)->format('Y-m-d H:i:s');

$sum = static function (string $fra): int {
    return (int) DB::verdi(
        "SELECT COALESCE(SUM(belop_ore - refundert_ore), 0) FROM payments
          WHERE status = 'betalt' AND created_at >= :fra",
        ['fra' => $fra]
    );
};

// Fordelingen per formal. Uten den star det bare en sum, og eieren kan ikke se
// hva pengene kom fra.
$FORMAL = [
    'booking'    => 'Kurs og events',
    'dropin'     => 'Drop-in',
    'ordre'      => 'Butikk',
    'gavekort'   => 'Gavekort',
    'medlemskap' => 'Medlemskap',
];

$linjer = static function (string $fra) use ($FORMAL): array {
    $rader = DB::alle(
        "SELECT formal, SUM(belop_ore - refundert_ore) AS sum FROM payments
          WHERE status = 'betalt' AND created_at >= :fra
          GROUP BY formal",
        ['fra' => $fra]
    );
    $etter = [];
    foreach ($rader as $r) {
        $ore = (int) $r['sum'];
        if ($ore === 0) {
            continue;
        }
        $etter[(string) $r['formal']] = $ore;
    }
    // Fast rekkefolge, slik at listene ikke hopper rundt fra dag til dag.
    $ut = [];
    foreach ($FORMAL as $nokkel => $navn) {
        if (isset($etter[$nokkel])) {
            $ut[] = ['navn' => $navn, 'verdi' => Booking::kroner($etter[$nokkel])];
        }
    }
    return $ut;
};

$betaltIdag = $sum($dagStart);
$betaltMnd  = $sum($mndStart);

// --- Bookinger ------------------------------------------------------------
$nyeBookinger = (int) DB::verdi(
    "SELECT COUNT(*) FROM bookings
      WHERE status = 'betalt' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
);
$ubetalte = (int) DB::verdi(
    "SELECT COUNT(*) FROM bookings
      WHERE status = 'reservert' AND reservert_til > UTC_TIMESTAMP()"
);

// --- Kommende okter -------------------------------------------------------
//
// Fra midnatt i dag, ikke fra «naa». Programmet for i dag skal vise hele
// dagen — ogsaa oktene som allerede er i gang eller nettopp ferdig.
$kommende = DB::alle(
    "SELECT cs.id, cs.start_tid, cs.slutt_tid, c.tittel, c.type,
            COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet,
            cs.manuelt_opptatt
              + (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
                  WHERE b.course_session_id = cs.id AND b.status = 'betalt') AS pameldte
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
      WHERE cs.status = 'planlagt' AND cs.start_tid >= :fra
      ORDER BY cs.start_tid
      LIMIT 60",
    ['fra' => $dagStart]
);

// --- Ting som trenger oppmerksomhet --------------------------------------
$varsler = [];

$feiledeVarsler = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'feilet'");
if ($feiledeVarsler > 0) {
    $varsler[] = $feiledeVarsler === 1
        ? 'Ett varsel kom ikke fram — sjekk e-postoppsettet'
        : $feiledeVarsler . ' varsler kom ikke fram — sjekk e-postoppsettet';
}

$iKo = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'ko' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)");
if ($iKo > 0) {
    $varsler[] = $iKo === 1
        ? 'Ett varsel har ligget i kø i over en halvtime'
        : $iKo . ' varsler har ligget i kø i over en halvtime';
}

$hengende = (int) DB::verdi(
    "SELECT COUNT(*) FROM payments
      WHERE status IN ('venter','autorisert')
        AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)"
);
if ($hengende > 0) {
    $varsler[] = $hengende === 1
        ? 'Én betaling har hengt i over en time'
        : $hengende . ' betalinger har hengt i over en time';
}

$venteliste = (int) DB::verdi("SELECT COUNT(*) FROM waitlist WHERE status = 'venter'");

// --- Siste paameldinger ---------------------------------------------------
$nyeste = DB::alle(
    "SELECT b.id, b.antall, b.status, b.belop_ore, b.created_at,
            COALESCE(m.navn, b.gjest_navn) AS navn,
            COALESCE(m.epost, b.gjest_epost) AS epost,
            c.tittel, cs.start_tid, p.vipps_reference
       FROM bookings b
       JOIN courses c ON c.id = b.course_id
  LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
  LEFT JOIN members m ON m.id = b.member_id
  LEFT JOIN payments p ON p.id = b.payment_id
      WHERE b.status IN ('betalt','reservert')
        -- Tre dager, ikke lenger.
        --
        -- «Nye paameldinger» er det som har skjedd siden sist du saa etter.
        -- Sto den samme paameldingen der i to uker, var den ikke ny lenger —
        -- den var bare et tall som ikke gikk ned. Eieren, 30. august: «nye
        -- paameldinger vises kun i 3 dager».
        --
        -- Om den er betalt, staar paa kurset. Derfor er det ingenting aa
        -- gjore fra dette kortet ut over aa se det — og da skal det tomme seg
        -- selv.
        AND b.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)
        -- Bare paameldinger til noe som ikke har vaert.
        --
        -- Kortet tok de tolv siste uansett dato. Paa et verksted med jevn
        -- paagang gaar de ut av seg selv, men her kunne en paamelding til et
        -- kurs som ble holdt i forrige maaned bli staaende i ukevis under
        -- «Nye paameldinger» — og det er ikke nytt, og det er ingenting aa
        -- gjore med det.
        AND (cs.start_tid IS NULL
             OR COALESCE(cs.slutt_tid, cs.start_tid) > UTC_TIMESTAMP())
      ORDER BY b.id DESC
      LIMIT 12"
);

Svar::json([
    // Hva som faktisk er skrudd paa.
    //
    // SMS-malene laa i admin som om de gikk ut. Uten leverandoer i
    // secrets.php gjor de ikke det, og da lovet skjermen noe verkstedet
    // ikke holdt. Naa staar det «ikke aktivert» der SMS tilbys.
    'kanaler' => [
        'sms' => Varsel::smsMulig(),
    ],
    'nyeste' => array_map(static fn($b) => [
        // Uten id-en kunne raden aapnes, men ikke gjores noe med. En
        // paamelding til et kurs uten dato ble staaende her for alltid: den
        // har ingen dato aa finne den igjen paa under Paameldte heller.
        'id'        => (int) $b['id'],
        'navn'      => $b['navn'],
        'epost'     => $b['epost'],
        'hva'       => $b['tittel'] . ((int) $b['antall'] > 1 ? ', ' . $b['antall'] . ' plasser' : ''),
        'naar'      => $b['start_tid'] ? Booking::norskDato((string) $b['start_tid']) : '',
        'tid'       => Booking::norskDato((string) $b['created_at']),
        'belop'     => Booking::kroner((int) $b['belop_ore']),
        'status'    => $b['status'] === 'betalt' ? 'Betalt' : 'Ubetalt',
        'referanse' => $b['vipps_reference'],
    ], $nyeste),
    'omsetning' => [
        'idag'       => $kroner($betaltIdag),
        'maned'      => $kroner($betaltMnd),
        'linjerIdag' => $linjer($dagStart),
        'linjerMnd'  => $linjer($mndStart),
    ],
    'bookinger' => [
        'sisteUke' => $nyeBookinger,
        'ubetalte' => $ubetalte,
    ],
    // ── Medlemmene, og hvem som ikke har betalt ───────────────────────
    //
    // Eieren, 2. september: «jeg kan ikke se paa min side paa et medlem om det
    // er betalt for medlemskapet eller ikke». Tallet hoerer hjemme her, der
    // han ser det uten aa gaa og lete — «Aktive medlemmer» sto med «N
    // registrerte totalt» under seg, som ingen trenger aa vite hver dag.
    //
    // Regnestykket er Medlemskap::betalingsstatus(), det samme som
    // medlemslista bruker. To regnestykker ville kunne svare hver sitt.
    'medlemmer' => (static function (): array {
        $aktive = DB::alle(
            "SELECT id, status, medlemskap_type, start_dato"
            . (DB::harKolonne('members', 'betaler_ikke')
                ? ', betaler_ikke, betaler_ikke_grunn'
                : ', 0 AS betaler_ikke, NULL AS betaler_ikke_grunn')
            . " FROM members
                WHERE status IN ('prove','aktiv','pause') AND anonymisert_at IS NULL"
        );
        $ider = array_map(static fn(array $m): int => (int) $m['id'], $aktive);
        $siste = Medlemskap::sisteBetalinger($ider);

        // Nyeste avtale per medlem, i ett oppslag.
        $avtaler = [];
        if ($ider !== []) {
            $inn = implode(',', $ider);
            foreach (DB::alle(
                "SELECT s.id, s.member_id, s.vipps_agreement_id, s.neste_trekk, s.siste_trekk, s.status
                   FROM subscriptions s
                   JOIN (SELECT member_id, MAX(id) AS siste FROM subscriptions
                          WHERE member_id IN ({$inn}) GROUP BY member_id) n ON n.siste = s.id"
            ) as $r) {
                $avtaler[(int) $r['member_id']] = $r;
            }
        }

        // Trekkene, for dem som har fast trekk. Se kommentaren i
        // Medlemskap::sisteTrekk(): «siste_trekk» settes naar trekket BES OM,
        // ikke naar pengene kommer.
        $trekkene = Medlemskap::sisteTrekk(
            array_map(static fn(array $a): int => (int) $a['id'], $avtaler)
        );

        $mndStart  = gmdate('Y-m-01');
        $ubetalte  = 0;
        $fri       = 0;
        $nye       = 0;
        $nyeUbet   = 0;
        foreach ($aktive as $m) {
            $a = $avtaler[(int) $m['id']] ?? null;
            $b = Medlemskap::betalingsstatus(
                $m,
                $a,
                $siste[(int) $m['id']] ?? null,
                $a === null ? null : ($trekkene[(int) $a['id']] ?? null)
            );
            if ($b['tilstand'] === 'fri') {
                $fri++;
            } elseif (!empty($b['utestaaende'])) {
                // «utestaaende», ikke «forfalt». Eieren, 2. september: de skal
                // telles «helt til pengene er inne» — ogsaa et trekk som er
                // bestilt og ikke forfalt enda.
                $ubetalte++;
            }
            if ((string) ($m['start_dato'] ?? '') >= $mndStart) {
                $nye++;
                if (!empty($b['utestaaende'])) {
                    $nyeUbet++;
                }
            }
        }
        return [
            'aktive'      => (int) DB::verdi("SELECT COUNT(*) FROM members WHERE status = 'aktiv'"),
            'totalt'      => (int) DB::verdi('SELECT COUNT(*) FROM members WHERE anonymisert_at IS NULL'),
            'ubetalte'    => $ubetalte,
            'fritatt'     => $fri,
            'nyeDenneMnd' => $nye,
            'nyeUbetalte' => $nyeUbet,
        ];
    })(),
    // ── Koer ingen sto vakt over ──────────────────────────────────────
    //
    // To ting kunne bli liggende i ukevis uten at noe sa fra: et medlem som
    // har lagt en gjenstand ut for salg og venter paa aa bli godkjent, og et
    // medlem som har sokt om aa fryse medlemskapet sitt. Begge har hver sin
    // skjerm i admin, men ingen vei dit fra Oversikt — og da maa man vite at
    // de finnes for aa gaa og se etter.
    //
    // «harTabell» fordi begge kom med senere migrasjoner. Er de ikke kjort,
    // svarer endepunktet null i stedet for aa doe.
    'koer' => [
        'medlemsvarer' => DB::harTabell('member_sales')
            ? (int) DB::verdi("SELECT COUNT(*) FROM member_sales WHERE status = 'til_godkjenning'")
            : 0,
        'frys' => DB::harTabell('medlem_frys')
            ? (int) DB::verdi("SELECT COUNT(*) FROM medlem_frys WHERE status = 'sokt'")
            : 0,
    ],
    // ── De mest populaere kursene ─────────────────────────────────────
    //
    // Eieren 30. august: «her vil jeg se mine mest populaere kurs». Regnet
    // av plassene som faktisk er solgt siste tolv maaneder, ikke av hvor
    // mange datoer et kurs har eller hvor ofte det staar i kalenderen —
    // drop-in ville da ligget oeverst hver eneste gang.
    //
    // Avbestilte og avlyste teller ikke: en plass som ble refundert er
    // ikke en plass noen kjopte.
    'populaere' => array_map(static fn(array $r): array => [
        'tittel'  => (string) $r['tittel'],
        'plasser' => (int) $r['plasser'],
        'kjop'    => (int) $r['kjop'],
    ], DB::alle(
        "SELECT c.tittel,
                COALESCE(SUM(b.antall), 0) AS plasser,
                COUNT(*) AS kjop
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
          WHERE b.status IN ('betalt', 'reservert')
            AND b.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 MONTH)
          GROUP BY c.id, c.tittel
         HAVING plasser > 0
       ORDER BY plasser DESC, c.tittel
          LIMIT 6"
    )),
    'venteliste' => $venteliste,
    'varsler'    => $varsler,
    // Om utsendingen er skrudd paa. Ligger her fordi denne hentes paa hver
    // adminskjerm — da kan kortet som peker til oppsettet vise hva som
    // gjelder, uten et eget kall.
    'utsending'  => [
        'epost' => trim((string) Config::hent('smtp_vert', '')) !== '',
        'sms'   => Varsel::smsMulig(),
    ],
    // Adressen telefonen kan abonnere paa. Lages foerste gang den spos etter.
    'kalenderAdresse' => $kalenderAdresse(),
    // Rekkefolgen verkstedet har dratt kortene i. Tom liste betyr «som den
    // er bygget» — ingen har flyttet paa noe enda.
    'kortrekkefolge' => (static function (): array {
        if (!DB::harTabell('innstillinger')) {
            return [];
        }
        $raa = DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'oversikt_kortrekkefolge'");
        $liste = is_string($raa) ? json_decode($raa, true) : null;
        return is_array($liste) ? array_values(array_filter($liste, 'is_string')) : [];
    })(),
    // ── Ikke betalt ────────────────────────────────────────────────────
    //
    // Plasser som er lagt inn for haand og staar som «reservert»: noen har
    // faatt plassen, men pengene er ikke kommet. Eieren, 29. august: han vil
    // ha et kort som varsler om dem, saa han kan kreve dem inn derfra.
    //
    // Bare det som er lagt inn for haand. En nettbestilling som staar som
    // reservert venter paa Vipps og ordner seg selv — eller faller bort naar
    // reservasjonen gaar ut.
    'ubetalte' => array_map(static function (array $r) use ($oslo, $utc): array {
        return [
            'id'      => (int) $r['id'],
            'navn'    => (string) $r['gjest_navn'],
            'kurs'    => (string) $r['tittel'],
            'naar'    => Booking::norskDato((string) $r['start_tid']),
            'belop'   => Booking::kroner((int) $r['belop_ore']),
            'telefon' => (string) ($r['gjest_telefon'] ?? ''),
            'maate'   => (string) ($r['betalt_maate'] ?? ''),
            'dager'   => (int) $r['dager'],
        ];
    }, DB::alle(
        "SELECT b.id, b.gjest_navn, b.gjest_telefon, b.belop_ore, b.betalt_maate,
                c.tittel, cs.start_tid,
                DATEDIFF(UTC_DATE(), DATE(b.created_at)) AS dager
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
          WHERE b.status = 'reservert'
            AND b.payment_id IS NULL
            AND b.lagt_inn_av IS NOT NULL
            AND b.belop_ore > 0
       ORDER BY b.created_at"
    )),

    'kommende'   => array_map(static function ($o) use ($oslo, $utc) {
        // startTid gaar med som ren ISO-tid i Oslo-sone, slik at nettleseren
        // kan sortere okten paa dag, uke og maaned uten aa tolke norsk tekst.
        $start = (new DateTimeImmutable((string) $o['start_tid'], $utc))->setTimezone($oslo);

        return [
            'oktId'     => (int) $o['id'],
            'tittel'    => $o['tittel'],
            // Programmet skjuler en drop-in ingen har meldt seg paa. Da maa
            // det vite hva slags oekt det er.
            'type'      => (string) $o['type'],
            'naar'      => Booking::norskDato((string) $o['start_tid']),
            'startTid'  => $start->format('c'),
            'klokke'    => $start->format('H:i'),
            'pameldte'  => (int) $o['pameldte'],
            'kapasitet' => (int) $o['kapasitet'],
        ];
    }, $kommende),
]);
