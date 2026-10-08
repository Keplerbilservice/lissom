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

// Samme kilde som dagsoppgjoret: Omsetning::perFormal(). Foer 29.09.2026
// summerte denne bare betalingsradene, og et kurs betalt i verkstedet uten
// betalingsrad falt ut av dagens omsetning (eieren: kr 2 800 manglet).
$sum = static function (string $fra, ?string $til = null): int {
    return array_sum(Omsetning::perFormal($fra, $til ?? '9999-12-31 00:00:00'));
};
// Omsetningen er UTEN mva (eieren, 30. september 2026: «kontoen total maa
// vaere alt uten mva, det er dette som er omsetning»). Mva-en for seg.
$eks = static function (string $fra, ?string $til = null): array {
    return Omsetning::sumUtenMva(Omsetning::perFormal($fra, $til ?? '9999-12-31 00:00:00'));
};

// Fordelingen per formal. Uten den star det bare en sum, og eieren kan ikke se
// hva pengene kom fra.
$FORMAL = [
    'booking'    => 'Kurs og events',
    'ordre'      => 'Butikk',
    'gavekort'   => 'Gavekort',
    'medlemskap' => 'Medlemskap',
];

$linjer = static function (string $fra) use ($FORMAL): array {
    $etter = Omsetning::perFormal($fra, '9999-12-31 00:00:00');
    // Fast rekkefolge, slik at listene ikke hopper rundt fra dag til dag.
    $ut = [];
    foreach ($FORMAL as $nokkel => $navn) {
        if (isset($etter[$nokkel])) {
            // «ore» er med saa Oversikt kan tegne fordelingen som en stripe.
            // eksOre / mvaOre / mvaSats: beloepet uten mva og mva-en for seg,
            // for kontoene som har mva (eieren, 30. september 2026). Lagt til
            // ved siden av de gamle feltene; «ore» er fortsatt brutto.
            $ut[] = ['navn' => $navn, 'verdi' => Booking::kroner($etter[$nokkel]), 'ore' => $etter[$nokkel], 'nokkel' => $nokkel]
                + Omsetning::mvaFor($nokkel, $etter[$nokkel]);
        }
    }
    return $ut;
};

// Hvor pengene kommer fra (Penger, eieren 8. oktober 2026): omsetningen uten
// mva per kilde, med salgene bak. Se Omsetning::perKilde(). Erstatter
// betalingene per formaal med mva (3. oktober), som hoerer til regnskapet.
$kilder = static fn(string $fra, string $til = '9999-12-31 00:00:00'): array => Omsetning::perKilde($fra, $til);

$betaltIdag = $sum($dagStart);
$eksIdag    = $eks($dagStart);
$eksMnd     = $eks($mndStart);
$betaltMnd  = $sum($mndStart);

// Sammenligningen paa Oversikt (eieren, 26. september 2026: «en liten
// sammenligning mot sist maned»). Forrige maned fram til samme dag og
// klokkeslett, saa midten av maneden ikke sammenlignes med en hel maned.
// «I dag» sammenlignes med samme ukedag forrige uke, fram til samme tid.
$forrigeMndStartOslo = $naa->modify('first day of last month')->setTime(0, 0);
$dagerInn = (int) $naa->format('j') - 1;
$forrigeMndTilOslo = $forrigeMndStartOslo->modify('+' . $dagerInn . ' days')
    ->setTime((int) $naa->format('H'), (int) $naa->format('i'));
if ($forrigeMndTilOslo->format('n') !== $forrigeMndStartOslo->format('n')) {
    // 31. mot en maned med 30 dager: hele forrige maned.
    $forrigeMndTilOslo = $naa->modify('first day of this month')->setTime(0, 0);
}
$betaltForrigeMnd = $sum(
    $forrigeMndStartOslo->setTimezone($utc)->format('Y-m-d H:i:s'),
    $forrigeMndTilOslo->setTimezone($utc)->format('Y-m-d H:i:s')
);
$eksForrigeMnd = $eks(
    $forrigeMndStartOslo->setTimezone($utc)->format('Y-m-d H:i:s'),
    $forrigeMndTilOslo->setTimezone($utc)->format('Y-m-d H:i:s')
)['eksOre'];
$uke = $naa->modify('-7 days');
$betaltForrigeUkedag = $sum(
    $uke->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
    $uke->setTimezone($utc)->format('Y-m-d H:i:s')
);
$eksForrigeUkedag = $eks(
    $uke->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
    $uke->setTimezone($utc)->format('Y-m-d H:i:s')
)['eksOre'];
$MND_NAVN = [1 => 'januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];

// --- Bookinger ------------------------------------------------------------
$nyeBookinger = (int) DB::verdi(
    "SELECT COUNT(*) FROM bookings
      WHERE status = 'betalt' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
);
$ubetalte = (int) DB::verdi(
    "SELECT COUNT(*) FROM bookings
      WHERE status = 'reservert' AND reservert_til > UTC_TIMESTAMP()"
);

// Paameldingene bak «Siste sju dager» — de samme som tallet over teller.
// Eieren, 24. september 2026: kortet skal kunne trykkes paa, og gaa til
// «Nye paameldinger». Den lista viser tre dager (eieren, 30. august), og den
// teller ogsaa paa «Venter paa deg»; derfor en egen liste her, ikke en
// lengre «nyeste».
$sisteUkeListe = DB::alle(
    "SELECT b.id, b.antall, b.status, b.belop_ore, b.created_at,
            COALESCE(m.navn, b.gjest_navn) AS navn,
            COALESCE(m.epost, b.gjest_epost) AS epost,
            c.tittel, cs.start_tid, p.vipps_reference
       FROM bookings b
       JOIN courses c ON c.id = b.course_id
  LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
  LEFT JOIN members m ON m.id = b.member_id
  LEFT JOIN payments p ON p.id = b.payment_id
      WHERE b.status = 'betalt' AND b.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
      ORDER BY b.id DESC
      LIMIT 200"
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
        -- En aapen plass ingen har booket, er ikke et kurs i dag. Eieren,
        -- 24. september 2026: «kortet i dag viser 2 kurs» — Paint on Pots
        -- 0 av 12 sto ved siden av det ekte kurset. Samme regel som
        -- kalenderen, se Apent::skjulUtenBooking().
        AND " . Apent::skjulUtenBooking('cs') . "
      ORDER BY cs.start_tid
      LIMIT 60",
    ['fra' => $dagStart]
);

// --- Ting som trenger oppmerksomhet --------------------------------------
$varsler = [];
$hengendeListe = [];

// Kvitteringer og paaminnelser som ikke kom fram, og som ligger i ko.
//
// Begge har vaert talt opp her hele tiden, og begge har vaert usynlige:
// «varsler» hadde ingen plass i skjermbildet. Naa har de hvert sitt kort paa
// Verkstedet, ved siden av «Henger i Vipps» — og tallene under er det de
// kortene leser.
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

// Betalinger som henger — med navn.
//
// Her sto det bare et tall: «Én betaling har hengt i over en time». Det er
// den beskjeden Monica faar naar en kunde ringer og sier at hun bestilte og
// ikke ble paameldt — og av et tall kan hun ikke finne ut hvem det er.
// Eieren, 4. september: navn, beloep og kurs.
//
// «opprettet» staar med her ogsaa. Alle kanalene setter «venter» med én gang
// Vipps har svart, saa en rad blir bare staaende paa «opprettet» hvis PHP
// doer akkurat mellom de to linjene. Da var den usynlig i dette varselet, og
// nettopp den raden er den som trenger et menneske.
//
// Maanedstrekkene staar utenfor: de gjores opp fra avtalen sin i
// «medlemstrekk», og de henger ikke paa samme maate.
$hengendeRader = DB::alle(
    "SELECT p.id, p.belop_ore, p.formal, p.status,
            TIMESTAMPDIFF(HOUR, p.created_at, UTC_TIMESTAMP()) AS timer,
            COALESCE(m.navn, b.gjest_navn, o.kunde_navn, '') AS navn,
            COALESCE(c.tittel, '') AS kurs
       FROM payments p
  LEFT JOIN members  m ON m.id = p.member_id
  LEFT JOIN bookings b ON b.id = p.booking_id
  LEFT JOIN orders   o ON o.id = p.order_id
  LEFT JOIN courses  c ON c.id = b.course_id
      WHERE p.status IN ('opprettet','venter','autorisert')
        AND p.type <> 'recurring_charge'
        AND p.created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
   ORDER BY p.created_at
      LIMIT 20"
);
$hengende = count($hengendeRader);
if ($hengende > 0) {
    // Navnet forst — det er det Monica leter etter. Staar det ikke noe navn
    // paa raden, sier vi hva slags betaling det er i stedet for aa lyve.
    // Radene faar samme form som resten av betalingslista — navn, hva, tid,
    // sum — saa skjermen kan tegne dem med det samme oppsettet den alt har.
    // Ett radformat, ikke to.
    $SLAG = ['booking' => 'Kursplass', 'gavekort' => 'Gavekort',
             'ordre' => 'Bestilling', 'medlemskap' => 'Medlemskap'];
    $navnet = static function (array $r) use ($SLAG): string {
        $n = trim((string) $r['navn']);
        // Staar det ikke noe navn paa raden, sier vi hva slags betaling det
        // er. Et tomt felt ser ut som en feil i skjermen, ikke som en rad
        // uten kunde.
        return $n !== '' ? $n : 'Ukjent (' . strtolower($SLAG[(string) $r['formal']] ?? 'betaling') . ')';
    };
    $tiden = static fn(int $t): string => $t < 24
        ? ($t === 1 ? 'hengt i én time' : 'hengt i ' . $t . ' timer')
        : ((int) floor($t / 24) === 1 ? 'hengt i ett døgn'
                                      : 'hengt i ' . (int) floor($t / 24) . ' døgn');
    foreach ($hengendeRader as $r) {
        $hva = $SLAG[(string) $r['formal']] ?? 'Betaling';
        $kurs = trim((string) $r['kurs']);
        $hengendeListe[] = [
            'id'        => (int) $r['id'],
            'navn'      => $navnet($r),
            'hva'       => $hva . ($kurs !== '' ? ' · ' . $kurs : ''),
            'tid'       => $tiden((int) $r['timer']),
            'sum'       => Booking::kroner((int) $r['belop_ore']),
            'timer'     => (int) $r['timer'],
            'status'    => (string) $r['status'],
        ];
    }
    // Linja i varselet: den som har hengt lengst, med navn, hva og sum.
    $f = $hengendeListe[0];
    $forste = $f['navn'] . ' · ' . $f['hva'] . ' · ' . $f['sum'];
    $varsler[] = $hengende === 1
        ? 'Én betaling har hengt i over en time: ' . $forste
        : $hengende . ' betalinger har hengt i over en time: ' . $forste
          . ' — og ' . ($hengende - 1) . ' til';
}

// ── Hvor mange som venter ──────────────────────────────────────────────
//
// «venter» OG «varslet». Her sto bare «venter», og da var dette det eneste
// stedet i systemet som talte annerledes: kalenderen, Venteliste-skjermen,
// Min side og medlemsruta bruker alle IN ('venter','varslet'). Hadde du
// varslet noen om en ledig plass, sto hun i kalenderen, men var ute av
// tallet her.
//
// Eieren, 9. september 2026, da det ble meldt: «fiks det».
//
// «varslet» betyr at beskjeden er sendt og plassen holdes til fristen —
// personen staar fortsatt i koen og har ikke faatt plassen. Hun venter, og
// skal telles.
$venteliste = (int) DB::verdi(
    "SELECT COUNT(*) FROM waitlist WHERE status IN ('venter', 'varslet')"
);

// --- Innboksen: kommentarer som venter paa svar (eieren, 3. oktober 2026) --
//
// Samme telling som «Venter på deg (n)» i innboksen: raden staar «venter» til
// noen har svart eller trykket «Ferdig» (ikkeSvar). Uten migrasjon 250 er
// tallet 0, og I dag viser ingenting.
$innboksVenter = 0;
try {
    $innboksVenter = count(Kommentarsvar::ventende());
} catch (Throwable $e) {
    logg_feil('Kunne ikke telle innboksen', $e);
}

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
        -- Ikke reservasjoner som har gaatt ut paa tid.
        --
        -- Paameldingen lages som «reservert» FOR kunden sendes til Vipps, og
        -- holder plassen i noen minutter. Trykker hun «Avbryt» i Vipps, blir
        -- raden staaende — den gaar bare ut paa tid, den slettes ikke. Kortet
        -- tok den likevel med i tre dager, merket «Ubetalt». Eieren,
        -- 3. september: «her er det kun personer som er paameldt, ikke jeg som
        -- trykket avbryt».
        --
        -- Samme regel som kapasiteten bruker (Booking::ledige) og som
        -- «Reserverte plasser» lenger oppe i denne fila. En paamelding lagt
        -- inn i admin har ingen frist, og staar som for.
        AND (b.status = 'betalt'
             OR b.reservert_til IS NULL
             OR b.reservert_til > UTC_TIMESTAMP())
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

// ── Betalingsstatus per medlem, regnet én gang ─────────────────────────
//
// To steder trenger den: tallet i «Medlemmer»-kortet, og radene i «Ikke
// betalt». Regnet hvert sitt sted kunne de svart hver sitt om det samme
// medlemmet — kortet sagt at Eirin skylder, tallet sagt at ingen gjor det.
//
// Selve regelen ligger i Medlemskap::betalingsstatus(), som medlemslista
// ogsaa bruker. Den staar ett sted, og bare der.
$medlemsstatus = (static function (): array {
    $aktive = DB::alle(
        "SELECT id, navn, epost, status, medlemskap_type, start_dato, slutt_dato"
        . (DB::harKolonne('members', 'betaler_ikke')
            ? ', betaler_ikke, betaler_ikke_grunn'
            : ', 0 AS betaler_ikke, NULL AS betaler_ikke_grunn')
        . " FROM members
            WHERE status IN ('prove','aktiv','pause','oppsagt')
              AND anonymisert_at IS NULL"
    );
    $ider = array_map(static fn(array $m): int => (int) $m['id'], $aktive);
    $siste = Medlemskap::sisteBetalinger($ider);

    // Nyeste avtale per medlem, i ett oppslag. «pris_ore» er prisen da
    // avtalen ble inngaatt — den, ikke dagens pris, er det medlemmet skylder.
    $avtaler = [];
    if ($ider !== []) {
        $inn = implode(',', $ider);
        foreach (DB::alle(
            "SELECT s.id, s.member_id, s.plan, s.pris_ore, s.vipps_agreement_id,
                    s.neste_trekk, s.siste_trekk, s.status
               FROM subscriptions s
               JOIN (SELECT member_id, MAX(id) AS siste FROM subscriptions
                      WHERE member_id IN ({$inn}) GROUP BY member_id) n ON n.siste = s.id"
        ) as $r) {
            $avtaler[(int) $r['member_id']] = $r;
        }
    }

    // Se kommentaren i Medlemskap::sisteTrekk(): «siste_trekk» settes naar
    // trekket BES OM, ikke naar pengene kommer.
    $trekkene = Medlemskap::sisteTrekk(
        array_map(static fn(array $a): int => (int) $a['id'], $avtaler)
    );

    // Prisen paa planen, for medlemmer uten avtale — de som gjor opp selv.
    $planpris = [];
    foreach (DB::alle('SELECT navn, pris_ore FROM membership_plans') as $p) {
        $planpris[(string) $p['navn']] = (int) $p['pris_ore'];
    }

    $mndStart = gmdate('Y-m-01');
    $ut = ['ubetalte' => 0, 'fri' => 0, 'nye' => 0, 'nyeUbet' => 0, 'rader' => [], 'tilstand' => []];

    foreach ($aktive as $m) {
        $a = $avtaler[(int) $m['id']] ?? null;
        $b = Medlemskap::betalingsstatus(
            $m,
            $a,
            $siste[(int) $m['id']] ?? null,
            $a === null ? null : ($trekkene[(int) $a['id']] ?? null)
        );
        // Betalingspilla paa «Nye paameldinger» og «Dagens bestillinger»
        // (nye medlemskap) — den samme regelen, ikke en til.
        $ut['tilstand'][(int) $m['id']] = (string) $b['tilstand'];

        // ── Den som har sagt opp, men ikke gjort opp ────────────────────
        //
        // Eieren, 9. september 2026: «kasse viser to ubetalte, disse er
        // riktig, men gina boerjeson staar ogsaa som ubetalt, men ikke paa
        // denne oversikten».
        //
        // Grunnen var denne lista: den hentet bare proeve, aktiv og pause.
        // Et medlem som sa opp mens noe sto ubetalt forsvant fra kortet som
        // skulle minne om aa kreve det inn — mens pilla paa medlemsraden,
        // som ikke har en slik sperre, fortsatte aa si «Ubetalt». De to var
        // uenige om det samme medlemmet.
        //
        // Oppsagte er derfor med naa, men BARE i radene. Tallene under —
        // «fri», «nye» og «nyeUbet» — beskriver de loepende medlemskapene,
        // og en oppsagt hoerer ikke hjemme i dem. Han valgte «Ja, ta dem
        // med».
        $oppsagt = (string) ($m['status'] ?? '') === 'oppsagt';

        if ($oppsagt) {
            if (!empty($b['utestaaende'])) {
                $ut['ubetalte']++;
            }
        } elseif ($b['tilstand'] === 'fri') {
            $ut['fri']++;
        } elseif (!empty($b['utestaaende'])) {
            // «utestaaende», ikke «forfalt». Eieren, 2. september: de skal
            // telles «helt til pengene er inne» — ogsaa et trekk som er
            // bestilt og ikke forfalt enda.
            $ut['ubetalte']++;

            // Samme regel gir raden i «Ikke betalt». Eieren spurte om dem to
            // ganger: forst «det maa de jo gjore, helt til pengene er inne»,
            // og saa «hvorfor vises ikke de to som er ubetalte i oversikten».
            // Prisen paa avtalen gjelder bare naar avtalen loeper.
            //
            // «pris_ore» er det medlemmet godkjente i Vipps, og den gaar
            // foran dagens pris — men bare saa lenge avtalen faktisk
            // trekker. En rad som staar «venter» ble aldri godkjent, og en
            // som er stoppet trekker ingenting. Da er det medlemskapet
            // personen staar paa som gjelder.
            //
            // Eieren, 4. september: «jeg byttet medlemskap for eirin, men
            // det endrer ikke pris». Byttet virket; det var denne raden som
            // fortsatte aa svare for den gamle avtalen.
            $loeper = $a !== null && (string) $a['status'] === 'aktiv';
            $plan = (string) (($loeper ? $a['plan'] : null) ?? $m['medlemskap_type'] ?? '');
            $pris = $loeper
                ? (int) $a['pris_ore']
                : ($planpris[$plan] ?? 0);
            $ut['rader'][] = [
                'id'    => (int) $m['id'],
                'navn'  => (string) $m['navn'],
                'plan'  => $plan,
                'pris'  => $pris,
                // Det betalingsstatusen sier: «Skulle vaert trukket 1.
                // september», «Ingen betaling registrert», og saa videre.
                // Uten den staar raden der uten aa si hvorfor.
                'hvorfor'   => (string) $b['tekst'],
                'forfalt'   => !empty($b['forfalt']),
                'startDato' => (string) ($m['start_dato'] ?? ''),
                // Slik at raden sier hvorfor den staar der naar medlemskapet
                // ikke loeper lenger. Uten den ser den ut som alle de andre.
                'oppsagt'   => $oppsagt,
            ];
        }

        if (!$oppsagt && (string) ($m['start_dato'] ?? '') >= $mndStart) {
            $ut['nye']++;
            if (!empty($b['utestaaende'])) {
                $ut['nyeUbet']++;
            }
        }
    }

    // De forfalte forst: der har pengene faktisk gaatt over tiden.
    usort($ut['rader'], static fn(array $x, array $y): int
        => ($y['forfalt'] <=> $x['forfalt']) ?: strcmp($x['navn'], $y['navn']));

    return $ut;
})();

// ── Nye medlemskap ──────────────────────────────────────────────────────
//
// Eieren, 2. oktober 2026: «det meldte seg på et nytt medlem, men det vises
// ikke under nye påmeldinger». Lista tok bare kursplasser. Her er
// medlemskapene, fra kildene alle veiene inn skriver til:
//
//   - nettsida og innmeldingslenka (betalt, fast trekk, Prøv Lissom og
//     «betal i verkstedet») — en rad i «subscriptions»
//   - lagt inn i admin — ingen avtale, men «medlem_meldt_inn» i revisjonen
//
// Samme regel for avbrutt Vipps som kursplassene: en avtale som er godkjent
// eller gjort opp står med. En som venter på Vipps står bare like lenge som
// en reservasjon holder plassen (20 minutter, Booking::RESERVASJON_MINUTTER),
// og bare så lenge betalingen ikke er avbrutt eller feilet. Avslått og
// utløpt står aldri med.
//
// Bare helt nye medlemmer: den første avtalen, eller første gang hen ble lagt
// inn i admin. Planbytte og avtale sendt fra admin til et medlem som alt
// fantes, står ikke (eieren, 2. oktober 2026). Én rad per medlem. Pilla er
// den samme som medlemslista (Medlemskap::betalingsstatus).
$nyeMedlemskap = static function (string $fra) use ($medlemsstatus): array {
    $MERKE = ['betalt' => 'Betalt', 'bestilt' => 'Bestilt', 'forfalt' => 'Forfalt',
              'fri' => 'Fri', 'over' => 'Sluttet'];
    $pille = static fn(int $id): string
        => $MERKE[$medlemsstatus['tilstand'][$id] ?? ''] ?? 'Ikke betalt';
    $rader = [];

    foreach (DB::alle(
        "SELECT s.member_id, s.plan, s.pris_ore, s.created_at, m.navn, m.epost
           FROM subscriptions s
           JOIN members m ON m.id = s.member_id
          WHERE s.created_at >= :fra
            AND m.anonymisert_at IS NULL
            AND (s.status = 'aktiv'
                 -- IN og ikke likhetstegn: tests/backend.php leter etter det i
                 -- denne fila for ventelista (waitlist). Dette er avtalen.
                 OR (s.status IN ('venter')
                     AND s.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE)
                     AND NOT EXISTS (SELECT 1 FROM payments p
                                      WHERE p.subscription_id = s.id
                                        AND p.status IN ('avbrutt','feilet'))))
            -- Bare HELT NYE medlemmer (eieren, 2. oktober 2026): hadde hen en
            -- avtale fra foer, er dette et planbytte eller en avtale sendt
            -- fra admin, og det skal ikke staa her. Heller ikke den som ble
            -- lagt inn i admin foer avtalen kom.
            AND NOT EXISTS (SELECT 1 FROM subscriptions s0
                             WHERE s0.member_id = s.member_id AND s0.id < s.id
                               -- Bare en avtale som har vaert godkjent eller betalt teller.
                               -- Et avbrutt forsoek staar ogsaa som «stoppet» (avlysForsok),
                               -- og den kunden er fortsatt ny (kontrolloeren 02.10).
                               AND (s0.status = 'aktiv'
                                    OR s0.siste_trekk IS NOT NULL OR s0.neste_trekk IS NOT NULL
                                    OR EXISTS (SELECT 1 FROM payments p0
                                                WHERE p0.subscription_id = s0.id
                                                  AND p0.status IN ('betalt','delvis_refundert','refundert'))))
            AND NOT EXISTS (SELECT 1 FROM audit_log a0
                             WHERE a0.handling = 'medlem_meldt_inn' AND a0.objekt_type = 'member'
                               AND a0.objekt_id = s.member_id AND a0.created_at < s.created_at)
          -- Ingen LIMIT her: én rad per medlem tas ut under, og en grense
          -- foer det kunne kuttet medlemmer fra «Dagens bestillinger»
          -- (kontrolloeren 02.10). Tredagerslista begrenses i blandNye.
          ORDER BY s.created_at DESC, s.id DESC",
        ['fra' => $fra]
    ) as $s) {
        $mid = (int) $s['member_id'];
        if (isset($rader[$mid])) {
            continue;
        }
        $rader[$mid] = [
            'medlemId' => $mid, 'navn' => (string) $s['navn'], 'epost' => $s['epost'],
            'plan' => (string) $s['plan'], 'ore' => (int) $s['pris_ore'],
            'naar' => (string) $s['created_at'], 'status' => $pille($mid),
        ];
    }

    $planpris = [];
    foreach (DB::alle('SELECT navn, pris_ore FROM membership_plans') as $p) {
        $planpris[(string) $p['navn']] = (int) $p['pris_ore'];
    }
    foreach (DB::alle(
        "SELECT n.member_id, n.naar, m.navn, m.epost, m.medlemskap_type
           FROM (SELECT a.objekt_id AS member_id, MIN(a.created_at) AS naar
                   FROM audit_log a
                  WHERE a.handling = 'medlem_meldt_inn' AND a.objekt_type = 'member'
                    AND a.created_at >= :fra
                  GROUP BY a.objekt_id) n
           JOIN members m ON m.id = n.member_id
          WHERE m.status IN ('prove','aktiv') AND m.anonymisert_at IS NULL
            -- Bare helt nye: ikke lagt inn i admin foer, og ingen avtale
            -- foer (eieren, 2. oktober 2026).
            AND NOT EXISTS (SELECT 1 FROM audit_log a0
                             WHERE a0.handling = 'medlem_meldt_inn' AND a0.objekt_type = 'member'
                               AND a0.objekt_id = n.member_id AND a0.created_at < :fra2)
            AND NOT EXISTS (SELECT 1 FROM subscriptions s0
                             WHERE s0.member_id = n.member_id AND s0.created_at < n.naar
                               -- Bare en avtale som har vaert godkjent eller betalt teller.
                               -- Et avbrutt forsoek staar ogsaa som «stoppet» (avlysForsok),
                               -- og den kunden er fortsatt ny (kontrolloeren 02.10).
                               AND (s0.status = 'aktiv'
                                    OR s0.siste_trekk IS NOT NULL OR s0.neste_trekk IS NOT NULL
                                    OR EXISTS (SELECT 1 FROM payments p0
                                                WHERE p0.subscription_id = s0.id
                                                  AND p0.status IN ('betalt','delvis_refundert','refundert'))))
          ORDER BY n.naar DESC",
        ['fra' => $fra, 'fra2' => $fra]
    ) as $a) {
        $mid = (int) $a['member_id'];
        if (isset($rader[$mid])) {
            continue;
        }
        $plan = (string) ($a['medlemskap_type'] ?? '');
        $rader[$mid] = [
            'medlemId' => $mid, 'navn' => (string) $a['navn'], 'epost' => $a['epost'],
            'plan' => $plan, 'ore' => $planpris[$plan] ?? 0,
            'naar' => (string) $a['naar'], 'status' => $pille($mid),
        ];
    }

    $rader = array_values($rader);
    usort($rader, static fn(array $x, array $y): int => strcmp($y['naar'], $x['naar']));
    return $rader;
};

// ── Dagens bestillinger ─────────────────────────────────────────────────
//
// Eieren, 27. september 2026: «jeg vil at du alltid har dagens omsetning
// øverst, klikkbar, så jeg kan gå inn å se på den, deretter dagens bestilling
// selv om den ikke er betalt». Alt som er bestilt i dag (norsk dag): kurs,
// butikk, gavekort og medlemskap, nyeste først.
//
// Ikke med: det kunden avbrøt i Vipps (betalingen er avbrutt eller feilet,
// eller reservasjonen har gått ut) — det er ingen bestilling. Samme regel som
// «Nye påmeldinger» over. Betal ved oppmøte og det som er lagt inn i admin har
// ingen Vipps-betaling, og står med.
$dagensBestillinger = (static function () use ($dagStart, $nyeMedlemskap): array {
    $oslo = new DateTimeZone('Europe/Oslo');
    $klokke = static fn(string $utc): string
        => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($oslo)->format('H:i');
    $avbrutt = "(p.status IS NOT NULL AND p.status IN ('avbrutt','feilet'))";
    $rader = [];

    foreach (DB::alle(
        "SELECT b.id, b.antall, b.status, b.belop_ore, b.created_at, b.course_session_id,
                COALESCE(m.navn, b.gjest_navn) AS navn, c.tittel, s.start_tid, s.slutt_tid
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions s ON s.id = b.course_session_id
      LEFT JOIN members m ON m.id = b.member_id
      LEFT JOIN payments p ON p.id = b.payment_id
          WHERE b.created_at >= :fra
            AND b.status IN ('betalt','reservert','refundert','ikke_mott')
            AND NOT $avbrutt
            AND (b.status <> 'reservert' OR b.reservert_til IS NULL OR b.reservert_til > UTC_TIMESTAMP())",
        ['fra' => $dagStart]
    ) as $b) {
        $rader[] = [
            'slag'   => 'kurs', 'id' => (int) $b['id'],
            'oktId'  => $b['course_session_id'] !== null ? (int) $b['course_session_id'] : null,
            'naar'   => (string) $b['created_at'], 'kl' => $klokke((string) $b['created_at']),
            'hva'    => (string) $b['tittel'] . ((int) $b['antall'] > 1 ? ' · ' . (int) $b['antall'] . ' plasser' : ''),
            // Kursdatoen og ledige plasser paa den (eieren 04.10.2026, GO):
            // samme dato som Kurs › Datoer, ledige fylles inn under.
            'kursNaar' => $b['start_tid'] !== null ? Booking::norskPeriode((string) $b['start_tid'], $b['slutt_tid'] ?? null) : null,
            'ledige'   => null,
            'belop'  => Booking::kroner((int) $b['belop_ore']),
            'navn'   => (string) $b['navn'],
            'status' => match ((string) $b['status']) {
                'betalt', 'ikke_mott' => 'Betalt', 'refundert' => 'Refundert', default => 'Ikke betalt',
            },
        ];
    }
    // Ledige plasser regnes for alle kursdatoene i én runde, med samme regel som resten av huset.
    $okter = array_values(array_filter(array_column($rader, 'oktId')));
    if ($okter !== []) {
        $ledige = Booking::ledigePlasserFlere($okter);
        foreach ($rader as &$r) {
            if ($r['oktId'] !== null && isset($ledige[$r['oktId']])) {
                $r['ledige'] = max(0, (int) $ledige[$r['oktId']]);
            }
        }
        unset($r);
    }

    if (DB::harTabell('orders')) {
        foreach (DB::alle(
            "SELECT o.id, o.ordrenr, o.kunde_navn, o.sum_ore, o.status, o.created_at,
                    (SELECT GROUP_CONCAT(CONCAT(l.antall, ' × ', l.tittel) SEPARATOR ', ')
                       FROM order_lines l WHERE l.order_id = o.id) AS linjer
               FROM orders o
          LEFT JOIN payments p ON p.id = o.payment_id
              WHERE o.created_at >= :fra AND o.status <> 'kansellert' AND NOT $avbrutt",
            ['fra' => $dagStart]
        ) as $o) {
            $rader[] = [
                'slag'   => 'ordre', 'id' => (int) $o['id'], 'ordrenr' => (string) $o['ordrenr'],
                'naar'   => (string) $o['created_at'], 'kl' => $klokke((string) $o['created_at']),
                'hva'    => (string) ($o['linjer'] ?: $o['ordrenr']),
                'belop'  => Booking::kroner((int) $o['sum_ore']),
                'navn'   => (string) $o['kunde_navn'],
                'status' => match ((string) $o['status']) {
                    'betalt', 'klar', 'hentet' => 'Betalt', 'refundert' => 'Refundert', default => 'Ikke betalt',
                },
            ];
        }
    }

    foreach (DB::alle(
        "SELECT g.id, g.kode, g.opprinnelig_ore, g.kjoper_navn, g.status, g.created_at, p.status AS pstatus
           FROM gift_cards g
      LEFT JOIN payments p ON p.id = g.payment_id
          WHERE g.created_at >= :fra AND NOT $avbrutt",
        ['fra' => $dagStart]
    ) as $g) {
        $rader[] = [
            'slag'   => 'gavekort', 'id' => (int) $g['id'], 'kode' => (string) $g['kode'],
            'naar'   => (string) $g['created_at'], 'kl' => $klokke((string) $g['created_at']),
            'hva'    => 'Gavekort',
            'belop'  => Booking::kroner((int) $g['opprinnelig_ore']),
            'navn'   => (string) ($g['kjoper_navn'] ?? ''),
            'status' => in_array((string) $g['pstatus'], ['refundert', 'delvis_refundert'], true) ? 'Refundert'
                : (in_array((string) $g['status'], ['aktivt', 'brukt'], true) ? 'Betalt' : 'Ikke betalt'),
        ];
    }

    // Medlemskapene. Her sto bare «medlemsordrer» — innmeldingslenka. Et
    // medlemskap kjøpt på nettsida, Prøv Lissom eller et medlem lagt inn i
    // admin kom aldri med (eieren, 2. oktober 2026). Samme kilde som «Nye
    // påmeldinger» nå: se $nyeMedlemskap.
    foreach ($nyeMedlemskap($dagStart) as $m) {
        $rader[] = [
            'slag'   => 'medlemskap', 'id' => $m['medlemId'], 'medlemId' => $m['medlemId'],
            'naar'   => $m['naar'], 'kl' => $klokke($m['naar']),
            'hva'    => 'Medlemskap · ' . $m['plan'],
            'belop'  => Booking::kroner($m['ore']),
            'navn'   => $m['navn'],
            'status' => $m['status'],
        ];
    }

    usort($rader, static fn(array $a, array $b): int => strcmp($b['naar'], $a['naar']));
    return $rader;
})();

// «Nye paameldinger»: kursplassene og de nye medlemskapene i én liste, nyeste
// foerst. Samme tre dager som kursplassene (se $nyeste). Medlemskapet har
// plannavnet der kursnavnet ellers staar, og «slag» sier hva raden er, saa
// skjermene kan aapne medlemmet i stedet for paameldingen.
$blandNye = static function (array $kursRader, array $raa) use ($nyeMedlemskap): array {
    $alle = [];
    foreach ($kursRader as $i => $r) {
        $alle[] = [(string) ($raa[$i]['created_at'] ?? ''), ['slag' => 'kurs'] + $r];
    }
    $tre = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
    foreach (array_slice($nyeMedlemskap($tre), 0, 12) as $m) {
        $alle[] = [$m['naar'], [
            'slag'      => 'medlemskap',
            'id'        => 0,
            'medlemId'  => $m['medlemId'],
            'navn'      => $m['navn'],
            'epost'     => $m['epost'],
            'hva'       => $m['plan'],
            'naar'      => '',
            'tid'       => Booking::norskDato($m['naar']),
            'belop'     => Booking::kroner($m['ore']),
            'status'    => $m['status'],
            'referanse' => null,
        ]];
    }
    usort($alle, static fn(array $x, array $y): int => strcmp($y[0], $x[0]));
    return array_map(static fn(array $x): array => $x[1], $alle);
};

// ── Må gjøres (I dag, eieren 08.10.2026: «Ok, bygg det») ──────────────────
//
// Én samlet liste over det som venter på verkstedet, gruppert i seks fliser:
// Meldinger, Medlemmer, Betaling, Kurs, Verksted og Butikk. Hver sak:
// {gruppe, type, id, tittel, under, rute}. «teller» = false betyr at saken
// står i arket, men ikke gir rødt merke (Ta ut leire er en snarvei, ikke noe
// som venter). Kilder som ikke finnes (migrasjon ikke kjørt) hoppes over.
// Uleste i medlemschatten telles fra chat_lest (migrasjon 265, idé 1 08.10).
$maGjores = (static function () use ($medlemsstatus, $nyeste): array {
    $ut = [];
    $kort = static function (?string $t, int $n = 90): string {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $t) ?? '');
        return mb_strlen($t) > $n ? rtrim(mb_substr($t, 0, $n - 1)) . '…' : $t;
    };
    $sak = static function (string $gruppe, string $type, int $id, string $tittel, string $under, string $rute, array $mer = []) use (&$ut): void {
        $ut[] = $mer + ['gruppe' => $gruppe, 'type' => $type, 'id' => $id, 'tittel' => $tittel,
                 'under' => $under, 'rute' => $rute, 'teller' => true];
    };
    $trygt = static function (string $hva, callable $fn): void {
        try {
            $fn();
        } catch (Throwable $e) {
            logg_feil('Må gjøres: ' . $hva, $e);
        }
    };

    // Meldinger: medlemschatten (uleste for denne admin), henvendelser,
    // innboksen (Instagram/Facebook) og feilmeldinger.
    $trygt('medlemschat', static function () use ($sak, $kort): void {
        // Idé 1 (eieren 08.10.2026): uten migrasjon 265 finnes ingen
        // lest-status, og da er chatten ikke med, som foer.
        if (!DB::harTabell('chat_lest') || !DB::harTabell('chat_meldinger')) {
            return;
        }
        $meg = (int) (Sesjon::medlem()['id'] ?? 0);
        $lest = DB::verdi('SELECT sist_lest_id FROM chat_lest WHERE member_id = :m', ['m' => $meg]);
        // Aldri aapnet: bare de tre siste dagene teller, ikke hele historikken.
        $vilkar = $lest === null ? 'c.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)' : 'c.id > :l';
        $param = ['m' => $meg] + ($lest === null ? [] : ['l' => (int) $lest]);
        $fra = "FROM chat_meldinger c JOIN members m ON m.id = c.member_id
                 WHERE {$vilkar} AND c.member_id <> :m AND c.slettet_at IS NULL";
        $n = (int) (DB::verdi("SELECT COUNT(*) {$fra}", $param) ?? 0);
        if ($n === 0) {
            return;
        }
        $siste = DB::en("SELECT c.id, c.tekst, c.created_at, m.navn {$fra} ORDER BY c.id DESC LIMIT 1", $param);
        $tid = (new DateTimeImmutable((string) $siste['created_at'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i');
        $sak('Meldinger', 'chat', 0, 'Medlemschat: ' . $n . ' uleste',
            (string) $siste['navn'] . ', ' . $tid . ': «' . $kort((string) $siste['tekst'], 70) . '»', 'chat',
            ['siste' => (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM chat_meldinger'), 'antall' => $n]);
    });
    $trygt('henvendelser', static function () use ($sak, $kort): void {
        foreach (DB::alle("SELECT id, navn, melding FROM enquiries WHERE status = 'ubesvart' ORDER BY id DESC LIMIT 20") as $r) {
            $sak('Meldinger', 'henvendelse', (int) $r['id'], 'Henvendelse fra ' . ((string) $r['navn'] ?: 'nettsiden'),
                $kort($r['melding']), 'foresporsler');
        }
    });
    $trygt('innboks', static function () use ($sak, $kort): void {
        $v = Kommentarsvar::ventende();
        if ($v !== []) {
            $sak('Meldinger', 'innboks', 0, count($v) . ' ubesvarte på Instagram og Facebook',
                '«' . $kort((string) ($v[0]['kommentar'] ?? ''), 70) . '»', 'innboks');
        }
    });
    $trygt('feilmeldinger', static function () use ($sak, $kort): void {
        if (!DB::harTabell('feilrapporter')) {
            return;
        }
        foreach (DB::alle(
            "SELECT f.id, f.melding, m.navn FROM feilrapporter f LEFT JOIN members m ON m.id = f.member_id
              WHERE f.slag = 'melding' AND f.status = 'ny' ORDER BY f.sist_sett DESC LIMIT 20"
        ) as $r) {
            $sak('Meldinger', 'feil', (int) $r['id'], 'Feilmelding fra ' . ((string) ($r['navn'] ?? '') ?: 'et medlem'),
                '«' . $kort($r['melding'], 70) . '»', 'feilmeldinger');
        }
    });

    // Medlemmer: søknader, frys og medlemsbidrag.
    $trygt('søknader', static function () use ($sak): void {
        if (!DB::harTabell('membership_applications')) {
            return;
        }
        foreach (DB::alle("SELECT id, navn, onsket_type FROM membership_applications WHERE status IN ('venter') ORDER BY id DESC LIMIT 20") as $r) {
            $type = (string) ($r['onsket_type'] ?? '');
            $sak('Medlemmer', 'soknad', (int) $r['id'], 'Ny søknad om medlemskap',
                (string) $r['navn'] . ($type !== '' ? ', ' . $type : ''), 'soknader');
        }
    });
    $trygt('frys', static function () use ($sak): void {
        if (!DB::harTabell('medlem_frys')) {
            return;
        }
        foreach (DB::alle(
            "SELECT f.id, f.fra_dato, f.til_dato, m.navn FROM medlem_frys f JOIN members m ON m.id = f.member_id
              WHERE f.status = 'sokt' ORDER BY f.id DESC LIMIT 20"
        ) as $r) {
            $sak('Medlemmer', 'frys', (int) $r['id'], 'Ønsker å fryse medlemskapet',
                (string) $r['navn'] . ', ' . Booking::norskDatoKort((string) $r['fra_dato'])
                . '–' . Booking::norskDatoKort((string) $r['til_dato']), 'frys');
        }
    });
    $trygt('medlemsbidrag', static function () use ($sak): void {
        if (!DB::harTabell('medlemsforslag')) {
            return;
        }
        foreach (DB::alle(
            "SELECT f.id, f.type, m.navn FROM medlemsforslag f JOIN members m ON m.id = f.member_id
              WHERE f.status IN ('venter','godkjent') ORDER BY f.id DESC LIMIT 20"
        ) as $r) {
            $bilde = (string) $r['type'] === 'bilde';
            $sak('Medlemmer', 'bidrag', (int) $r['id'], 'Medlemsbidrag til godkjenning',
                (string) $r['navn'] . ($bilde ? ' har lastet opp et bilde' : ' har lastet opp en video'),
                'medlemsbidrag', ['bilde' => $bilde]);
        }
    });

    // Betaling: medlemmer som mangler betaling (samme regnestykke som Folk).
    foreach ($medlemsstatus['rader'] as $m) {
        $sak('Betaling', 'betaling', (int) $m['id'], $m['navn'] . ' mangler betaling',
            implode(' · ', array_filter([(string) $m['plan'], (string) $m['hvorfor']])), 'folk?person=' . (int) $m['id']);
    }

    // Kurs: nye påmeldinger (siste tre dager) og ledig plass med folk på
    // venteliste. «Sett» ligger på serveren fra migrasjon 265 (idé 2, 08.10);
    // uten den huskes det i nettleseren som før (settPaaServer=false).
    $settId = [];
    $trygt('sett', static function () use (&$settId, $nyeste): void {
        $ider = array_map(static fn(array $b): int => (int) $b['id'], $nyeste);
        if ($ider !== [] && DB::harTabell('pamelding_sett')) {
            $settId = array_flip(array_map('intval', array_column(DB::alle(
                'SELECT booking_id FROM pamelding_sett WHERE booking_id IN (' . implode(',', $ider) . ')'
            ), 'booking_id')));
        }
    });
    foreach ($nyeste as $b) {
        if (isset($settId[(int) $b['id']])) {
            continue;
        }
        $sak('Kurs', 'pamelding', (int) $b['id'], 'Ny påmelding: ' . (string) $b['navn'],
            implode(' · ', array_filter([(string) $b['tittel'],
                $b['start_tid'] ? Booking::norskDato((string) $b['start_tid']) : '',
                $b['status'] === 'betalt' ? 'Betalt' : 'Ikke betalt'])), 'pameldte?booking=' . (int) $b['id']);
    }
    $trygt('venteliste', static function () use ($sak): void {
        $rader = DB::alle(
            "SELECT w.id, w.navn, w.course_id, w.course_session_id, c.tittel, cs.start_tid
               FROM waitlist w JOIN courses c ON c.id = w.course_id
          LEFT JOIN course_sessions cs ON cs.id = w.course_session_id
              WHERE w.status IN ('venter', 'varslet') ORDER BY w.posisjon LIMIT 100"
        );
        if ($rader === []) {
            return;
        }
        $kommende = DB::alle(
            "SELECT id, course_id FROM course_sessions
              WHERE status = 'planlagt' AND start_tid > UTC_TIMESTAMP() ORDER BY start_tid LIMIT 120"
        );
        $ledige = Booking::ledigePlasserFlere(array_map(static fn(array $o): int => (int) $o['id'], $kommende));
        $kursLedig = [];
        foreach ($kommende as $o) {
            if (($ledige[(int) $o['id']] ?? 0) > 0) {
                $kursLedig[(int) $o['course_id']] = true;
            }
        }
        foreach ($rader as $w) {
            $okt = $w['course_session_id'] !== null ? (int) $w['course_session_id'] : null;
            $ledig = $okt !== null ? (($ledige[$okt] ?? 0) > 0) : isset($kursLedig[(int) $w['course_id']]);
            if (!$ledig) {
                continue;
            }
            $navn = trim((string) $w['navn']);
            $sak('Kurs', 'venteliste', (int) $w['id'], 'Plass ledig: ' . (string) $w['tittel'],
                $navn . ' står på ventelisten' . ($w['start_tid'] ? ' · ' . Booking::norskDato((string) $w['start_tid']) : ''),
                'venteliste', ['fornavn' => explode(' ', $navn)[0]]);
        }
    });

    // Verksted: klar til henting, leirebestillingen og dugnad som venter.
    $trygt('henting', static function () use ($sak): void {
        foreach (DB::alle(
            "SELECT cs.id, cs.start_tid, c.tittel,
                    (SELECT COUNT(*) FROM bookings b
                      WHERE b.course_session_id = cs.id AND b.status IN ('betalt','reservert')) AS deltakere
               FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt' AND cs.hentemelding_at IS NULL
                AND COALESCE(cs.slutt_tid, cs.start_tid) < UTC_TIMESTAMP()
                AND cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 16 WEEK)
           ORDER BY cs.start_tid DESC LIMIT 20"
        ) as $o) {
            if ((int) $o['deltakere'] === 0) {
                continue;
            }
            $sak('Verksted', 'henting', (int) $o['id'], 'Klar til henting: ' . (string) $o['tittel'],
                Booking::norskDato((string) $o['start_tid']) . ' · ' . (int) $o['deltakere'] . ' deltakere',
                'henting?okt=' . (int) $o['id']);
        }
    });
    $trygt('leirebestilling', static function () use ($sak): void {
        if (!DB::harTabell('handleliste_linjer')) {
            return;
        }
        $r = DB::en("SELECT COUNT(*) AS linjer, COUNT(DISTINCT member_id) AS medlemmer
                       FROM handleliste_linjer WHERE status = 'sendt' AND bestilt_at IS NULL");
        if ((int) ($r['linjer'] ?? 0) === 0) {
            return;
        }
        $frist = Lager::leireFrist();
        $sak('Verksted', 'leire', 0,
            $frist['tekst'] !== '' ? 'Leirebestilling: frist ' . $frist['tekst'] : 'Leirebestilling ikke sendt',
            (int) $r['linjer'] . ' linjer fra ' . (int) $r['medlemmer'] . ' medlemmer', 'handlelister');
    });
    $trygt('dugnad', static function () use ($sak, $kort): void {
        if (!DB::harTabell('dugnad')) {
            return;
        }
        foreach (DB::alle(
            "SELECT d.id, d.tekst, d.status, m.navn FROM dugnad d JOIN members m ON m.id = d.member_id
              WHERE d.status IN ('venter','til_godkjenning') ORDER BY d.id DESC LIMIT 20"
        ) as $r) {
            $sak('Verksted', 'dugnad', (int) $r['id'],
                $r['status'] === 'venter' ? 'Dugnad venter på godkjenning' : 'Dugnadstimer til godkjenning',
                (string) $r['navn'] . ': ' . $kort($r['tekst'], 60), 'dugnad');
        }
    });

    // Butikk: lite på lager, og «Ta ut leire» som snarvei (uten rødt merke).
    $trygt('lager', static function () use ($sak): void {
        foreach (Lager::bestillMer() as $v) {
            $sak('Butikk', 'lager', (int) $v['id'], 'Lite på lager: ' . $v['vare'], (string) $v['linje'], 'butikk',
                ['vare' => (string) $v['vare']]);
        }
        $leire = Lager::leireListe();
        if ($leire !== []) {
            $sak('Butikk', 'taut', 0, 'Ta ut leire', count($leire) . ' slags leire på lager', 'butikk', ['teller' => false]);
        }
    });

    return $ut;
})();

Svar::json([
    // Må gjøres på I dag: én samlet liste (se $maGjores over).
    'maGjores' => $maGjores,
    // «Sett» paa paameldinger lagres paa serveren (migrasjon 265, idé 2).
    'settPaaServer' => DB::harTabell('pamelding_sett'),
    'dagensBestillinger' => $dagensBestillinger,
    // Hva som faktisk er skrudd paa.
    //
    // SMS-malene laa i admin som om de gikk ut. Uten leverandoer i
    // secrets.php gjor de ikke det, og da lovet skjermen noe verkstedet
    // ikke holdt. Naa staar det «ikke aktivert» der SMS tilbys.
    'kanaler' => [
        'sms' => Varsel::smsMulig(),
    ],
    'nyeste' => $blandNye(array_map($paameldingRad = static fn($b) => [
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
        // Samme to ord som betalingspilla bruker ellers i systemet, og som
        // deltakerraden i kalenderen fikk 6. september. Her sto «Ubetalt»,
        // og da het den samme tilstanden to ting paa to skjermer.
        'status'    => $b['status'] === 'betalt' ? 'Betalt' : 'Ikke betalt',
        'referanse' => $b['vipps_reference'],
    ], $nyeste), $nyeste),
    'sisteUkeListe' => array_map($paameldingRad, $sisteUkeListe),
    'omsetning' => [
        'idag'       => $kroner($betaltIdag),
        'maned'      => $kroner($betaltMnd),
        'linjerIdag' => $linjer($dagStart),
        'linjerMnd'  => $linjer($mndStart),
        // Hvor pengene kommer fra, uten mva, per periode (Penger, eieren
        // 8. oktober 2026). «forrige» er hele forrige maaned.
        'kilder' => [
            'idag'    => $kilder($dagStart),
            'maned'   => $kilder($mndStart),
            'forrige' => $kilder(
                $forrigeMndStartOslo->setTimezone($utc)->format('Y-m-d H:i:s'),
                $naa->modify('first day of this month')->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s')
            ),
        ],
        'idagOre'    => $betaltIdag,
        'manedOre'   => $betaltMnd,
        'forrigeMndOre'    => $betaltForrigeMnd,
        'forrigeMndNavn'   => $MND_NAVN[(int) $forrigeMndStartOslo->format('n')],
        'forrigeUkedagOre' => $betaltForrigeUkedag,
        // Omsetningen UTEN mva, og mva-en for seg (eieren, 30. september 2026).
        // Lagt til ved siden av de gamle feltene, som fortsatt er med mva.
        'idagEksOre'          => $eksIdag['eksOre'],
        'manedEksOre'         => $eksMnd['eksOre'],
        'idagMvaOre'          => $eksIdag['mvaOre'],
        'manedMvaOre'         => $eksMnd['mvaOre'],
        'forrigeMndEksOre'    => $eksForrigeMnd,
        'forrigeUkedagEksOre' => $eksForrigeUkedag,
    ],
    // ── Hvem som er i huset ───────────────────────────────────────────
    //
    // Eieren, 7. september 2026: «jeg onsker at admin skal kunne se hvem som
    // er i verksted, ogsaa de som har valgt aa ikke vise meg for medlemmer».
    //
    // Sidemenyen i admin har hatt et tall — «3 medlemmer» — men ingen navn.
    // Navnene laa bare i api/stempling.php, som medlemmene leser, og der er
    // de skjulte tatt bort med vilje.
    //
    // Haken heter «Vis meg for andre medlemmer». Den er et loefte til de andre
    // MEDLEMMENE, ikke til verkstedet — den som driver stedet maa vite hvem
    // som er i huset, av samme grunn som en brannliste finnes.
    //
    // Samme spoerring som medlemmene leser; se Stempling::alleInne(), som
    // inneNa() ogsaa bygger paa. To spoerringer kunne svart hver sitt om den
    // samme kvelden.
    'verkstedet' => Stempling::alleInne(),
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
    'medlemmer' => [
        'aktive'      => count(array_filter(DB::alle("SELECT * FROM members WHERE status = 'aktiv'"), 'er_aktivt_medlem')),
        'totalt'      => (int) DB::verdi('SELECT COUNT(*) FROM members WHERE anonymisert_at IS NULL'),
        // Alle fire kommer fra $medlemsstatus, som er regnet én gang lenger
        // oppe. Kortet «Ikke betalt» leser de samme radene, saa tallet og
        // lista aldri kan si hver sitt om det samme medlemmet.
        'ubetalte'    => $medlemsstatus['ubetalte'],
        'fritatt'     => $medlemsstatus['fri'],
        'nyeDenneMnd' => $medlemsstatus['nye'],
        'nyeUbetalte' => $medlemsstatus['nyeUbet'],
    ],
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
        // Dugnad som venter paa Monica: nye foresporsler og tid til
        // godkjenning (migrasjon 189). Pilla paa kalenderen viser tallet.
        'dugnad' => DB::harTabell('dugnad')
            ? (int) DB::verdi("SELECT COUNT(*) FROM dugnad WHERE status IN ('venter','til_godkjenning')")
            : 0,
        // Dugnad som er gitt eller godkjent og ikke ferdig: kortet «Dugnad» paa
        // Oversikt. Eieren, 26. september 2026.
        'dugnadIGang' => DB::harTabell('dugnad')
            ? (int) DB::verdi("SELECT COUNT(*) FROM dugnad WHERE status IN ('godkjent','pagar')")
            : 0,
        // Handlelister som er sendt inn og ikke bestilt (migrasjon 184): antall
        // medlemmer med linjer som venter. Pilla paa kalenderen viser tallet.
        'handleliste' => DB::harTabell('handleliste_linjer')
            ? (int) DB::verdi("SELECT COUNT(DISTINCT member_id) FROM handleliste_linjer WHERE status = 'sendt' AND bestilt_at IS NULL")
            : 0,
        // Butikkordrer som er betalt, men ikke gjort klare eller hentet.
        // Kortet «Butikk» paa dashboardet viser tallet — eieren,
        // 15. september 2026: «butikk er en viktig aa ha i dashboard».
        'butikk' => (int) DB::verdi("SELECT COUNT(*) FROM orders WHERE status = 'betalt'"),
        // Medlemmenes forslag til Instagram som venter paa verkstedet
        // (migrasjon 207). Eieren, 24. september 2026.
        'medlemsforslag' => DB::harTabell('medlemsforslag')
            ? (int) DB::verdi("SELECT COUNT(*) FROM medlemsforslag WHERE status IN ('venter','godkjent')")
            : 0,
    ],
    // ── De mest populaere kursene ─────────────────────────────────────
    //
    // Eieren 30. august: «her vil jeg se mine mest populaere kurs». Regnet
    // av plassene som faktisk er solgt siste tolv maaneder, ikke av hvor
    // mange datoer et kurs har eller hvor ofte det staar i kalenderen.
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
    'innboksVenter' => $innboksVenter,
    // «Bestill mer» og «Lav aktivitet» (eieren, 28. september 2026). Bare
    // for verkstedet — se app/lib/lager.php og app/lib/aktivitet.php.
    'bestillMer'   => Lager::bestillMer(),
    // Ta ut leire på I dag (eieren 04.10.2026).
    'taUtLeire'    => Lager::leireListe(),
    'lavAktivitet' => ['antall' => count(Aktivitet::lave()), 'dager' => Aktivitet::dager()],
    'varsler'    => $varsler,
    'hengende'   => $hengendeListe,
    // Tallene kortene leser. De sto bare inni tekstene i «varsler», og et
    // kort kan ikke lese et tall ut av en setning.
    'varselTall' => [
        'feilet' => $feiledeVarsler,
        'iKo'    => $iKo,
    ],
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
    // Lagt inn for haand, eller en nettbestilling der fristen er ute.
    //
    // Her sto det bare «lagt inn for haand», med den begrunnelsen at en
    // nettbestilling «venter paa Vipps og ordner seg selv — eller faller bort
    // naar reservasjonen gaar ut». Den siste halvdelen stemte ikke: raden
    // faller ikke bort. Den beholder status «reservert» for alltid, og
    // Paameldte lister den — den skjermen spor ikke etter «reservert_til» —
    // mens dette kortet med vilje saa bort fra den.
    //
    // Eieren, 9. september 2026: «gina boerjsenson ligger under paamelte, her
    // staar hun som ubetalt ... hun dukker ikke opp i kassen som ubetalt,
    // hvorfor?» Han valgte «Vis dem i Kassa naar reservasjonen er utloept».
    //
    // De ferske staar fortsatt utenfor: en bestilling som ble lagt inn for
    // fem minutter siden venter faktisk paa Vipps, og skal ikke kreves inn.
    //
    // En nettbestilling UTEN frist er med paa samme vilkaar. Den var det
    // verste tilfellet: den holder plassen sin for alltid — se
    // Booking::ledigeRegnet(), der «reservert_til IS NULL» teller som en
    // levende reservasjon — og den ville aldri gaa ut paa tid heller. Uten
    // dette var den usynlig i det ene kortet som skulle fange den opp.
    //
    // Eieren, 9. september 2026, spurt om nettopp den: «Ta dem med i "Ikke
    // betalt"».
    //
    // Medlemskapene staar her ogsaa. Eieren spurte om dem to ganger — forst
    // «dverken hun eller eiriin kommer opp i kortet ikke betalt paa
    // oversikten, og det maa de jo gjore, helt til pengene er inne», og saa
    // «hvorfor vises ikke de to som er ubetalte i oversikten». Kortet var
    // bygget 29. august for kursplasser alene, for medlemmene hadde noen
    // betalingsstatus i det hele tatt.
    //
    // Butikken kom 4. september: «her skal alle som ikke har betalt vises, om
    // det er butikk, kurs eller medlemskap». Kassa kan foere et salg som
    // «Ikke betalt» — varen gaar ut av doera, pengene kommer senere.
    //
    // «slag» skiller dem: en kursplass merkes betalt med paameldingen sin,
    // et medlemskap med en betalingsrad paa medlemmet, og et butikksalg med
    // «gjorOpp» i kassa. Skjermen trenger aa vite hvilken av delene raden er.
    'ubetalte' => array_merge(array_map(static function (array $r) use ($oslo, $utc): array {
        return [
            'slag'    => 'booking',
            'id'      => (int) $r['id'],
            'navn'    => (string) $r['gjest_navn'],
            'kurs'    => (string) $r['tittel'],
            'naar'    => Booking::norskDato((string) $r['start_tid']),
            'belop'   => Booking::kroner((int) $r['belop_ore']),
            'belopOre' => (int) $r['belop_ore'],
            // «Ta betalt» (eieren, 24. september 2026): steget regner
            // pris × antall minus rabatt, og kan endre alle tre.
            'antall'  => (int) ($r['antall'] ?? 1),
            'prisOre' => (int) ($r['pris_ore'] ?? 0),
            'rabatt'  => (float) ($r['rabatt_prosent'] ?? 0),
            'telefon' => (string) ($r['gjest_telefon'] ?? ''),
            'maate'   => (string) ($r['betalt_maate'] ?? ''),
            'dager'   => (int) $r['dager'],
        ];
    }, DB::alle(
        "SELECT b.id, b.gjest_navn, b.gjest_telefon, b.belop_ore, b.betalt_maate,
                b.antall, " . (DB::harKolonne('bookings', 'rabatt_prosent') ? 'b.rabatt_prosent' : '0') . " AS rabatt_prosent,
                " . (DB::harKolonne('course_sessions', 'pris_ore') ? 'COALESCE(cs.pris_ore, c.pris_ore)' : 'c.pris_ore') . " AS pris_ore,
                c.tittel, cs.start_tid,
                DATEDIFF(UTC_DATE(), DATE(b.created_at)) AS dager
           FROM bookings b
           JOIN courses c ON c.id = b.course_id
      LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
      LEFT JOIN payments p2 ON p2.id = b.payment_id
          WHERE b.status = 'reservert'
            -- Ikke «har ingen betalingsrad», men «har ingen betaling».
            --
            -- Her sto «b.payment_id IS NULL». En kursbooking fra nettsiden
            -- faar ALLTID en betalingsrad naar kunden sendes til Vipps — se
            -- Booking, der raden lages med status «opprettet» for bookingen
            -- settes inn. Raden blir liggende ogsaa naar kunden avbroet
            -- eller aldri kom tilbake. Med den gamle betingelsen kom derfor
            -- ingen vanlig nettpaamelding med, uansett hvor lenge den hadde
            -- staatt ubetalt.
            --
            -- De tre som betyr at pengene er i orden er de samme som
            -- api/admin/pamelding.php bruker naar den nekter aa sette en
            -- betalt paamelding paa venteliste. Staar de to ulikt, sier
            -- systemet to ting om den samme betalingen.
            AND (b.payment_id IS NULL
                 OR p2.status NOT IN ('autorisert', 'betalt', 'delvis_refundert'))
            AND b.belop_ore > 0
            AND (b.lagt_inn_av IS NOT NULL
                 OR b.reservert_til IS NULL
                 OR b.reservert_til <= UTC_TIMESTAMP())
       ORDER BY b.created_at"
    )), array_map(static function (array $m): array {
        return [
            'slag'  => 'medlem',
            'id'    => $m['id'],
            'navn'  => $m['navn'],
            // Der kursraden sier hvilket kurs og naar, sier medlemsraden
            // hvilket medlemskap og hvorfor den staar her: «Skulle vaert
            // trukket 1. september», «Ingen betaling registrert».
            'kurs'  => $m['plan'],
            'naar'  => $m['hvorfor'],
            // Finner vi ingen pris — medlemmet har hverken avtale eller en
            // plan vi kjenner igjen — skal det staa tomt, ikke «kr. 0,-».
            // Null kroner leses som «skylder ingenting», og det er det
            // motsatte av hva raden staar her for aa si.
            'belop' => $m['pris'] > 0 ? Booking::kroner($m['pris']) : '',
            'belopOre' => $m['pris'],
            'telefon' => '',
            'maate'   => '',
            // Hvor lenge medlemskapet har loept. Kursraden teller dager siden
            // paameldingen; her er det dager siden medlemmet startet.
            'dager' => $m['startDato'] !== ''
                ? max(0, (int) ((new DateTimeImmutable('today'))
                    ->diff(new DateTimeImmutable($m['startDato']))->days))
                : 0,
            'forfalt' => $m['forfalt'],
            // Medlemskapet loeper ikke lenger, men pengene staar ute. Uten
            // dette ser raden ut som alle de andre, og man ringer en som
            // alt har sagt opp uten aa vite det.
            'oppsagt' => !empty($m['oppsagt']),
        ];
    }, $medlemsstatus['rader']), array_map(static function (array $o): array {
        return [
            'slag'  => 'ordre',
            'id'    => (int) $o['id'],
            'navn'  => (string) $o['kunde_navn'],
            // Der kursraden sier hvilket kurs og medlemsraden hvilken plan,
            // sier butikkraden hva som ble handlet — og ordrenummeret, saa
            // den er til aa finne igjen i Nettbutikk.
            'kurs'  => (string) ($o['hva'] ?? '') !== ''
                ? (string) $o['hva'] : 'Salg over disk',
            'naar'  => (string) $o['ordrenr'],
            'belop' => Booking::kroner((int) $o['sum_ore']),
            'belopOre' => (int) $o['sum_ore'],
            'telefon' => (string) ($o['kunde_telefon'] ?? ''),
            'maate'   => (string) ($o['betalt_maate'] ?? ''),
            'dager'   => (int) $o['dager'],
            // Bestilt i nettbutikken med «Betal ved henting». Den kan
            // annulleres herfra — varene tilbake paa lager, kunden faar e-post.
            'henteordre' => (int) ($o['henteordre'] ?? 0) === 1,
        ];
    }, DB::alle(
        // Butikken. Eieren, 4. september: «her skal alle som ikke har betalt
        // vises, om det er butikk, kurs eller medlemskap».
        //
        // «payment_id IS NULL» er hele skillet. Et forlatt Vipps-forsoek har
        // alltid en betalingsrad — den ble laget for kunden ble sendt til
        // Vipps — og er ikke en gjeld, men et salg som aldri ble noe av.
        // Eieren om nettopp dem: «avbrutt er ikke et salg og skal ikke vises
        // noen sted». Et salg foert som «Ikke betalt» i kassa har ingen rad,
        // og er en gjeld til noen trykker «Kontant» eller «Vipps».
        "SELECT o.id, o.ordrenr, o.kunde_navn, o.kunde_telefon, o.sum_ore, o.betalt_maate,
                " . (DB::harKolonne('orders', 'uten_forskudd') ? 'o.uten_forskudd' : '0') . " AS henteordre,
                DATEDIFF(UTC_DATE(), DATE(o.created_at)) AS dager,
                (SELECT GROUP_CONCAT(CONCAT(ol.antall, ' × ', ol.tittel)
                          ORDER BY ol.id SEPARATOR ', ')
                   FROM order_lines ol WHERE ol.order_id = o.id) AS hva
           FROM orders o
          WHERE o.payment_id IS NULL
            AND (o.betalt_maate = 'Ikke betalt'"
            // Henteordrene fra nettbutikken («Betal ved henting») er ogsaa en
            // gjeld til de er gjort opp. De sto ingen steder der de kunne
            // gjores opp eller annulleres (eieren, 28. september 2026).
            . (DB::harKolonne('orders', 'uten_forskudd') ? " OR o.uten_forskudd = 1" : '')
            . ")
            AND o.status NOT IN ('kansellert', 'refundert')
            AND o.sum_ore > 0
       ORDER BY o.created_at"
    ))),

    'kommende'   => array_map(static function ($o) use ($oslo, $utc) {
        // startTid gaar med som ren ISO-tid i Oslo-sone, slik at nettleseren
        // kan sortere okten paa dag, uke og maaned uten aa tolke norsk tekst.
        $start = (new DateTimeImmutable((string) $o['start_tid'], $utc))->setTimezone($oslo);

        return [
            'oktId'     => (int) $o['id'],
            'tittel'    => $o['tittel'],
            'type'      => (string) $o['type'],
            'naar'      => Booking::norskDato((string) $o['start_tid']),
            'startTid'  => $start->format('c'),
            'klokke'    => $start->format('H:i'),
            'pameldte'  => (int) $o['pameldte'],
            'kapasitet' => (int) $o['kapasitet'],
        ];
    }, $kommende),
]);
