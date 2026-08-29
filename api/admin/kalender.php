<?php
/**
 * Kalenderen i admin — alt som skjer, samlet.
 *
 *   GET ?fra=2026-09-01&til=2026-09-30
 *
 * ── Hvorfor et eget endepunkt ─────────────────────────────────────────
 *
 * Kalenderen skal tegne en maaned om gangen med okter, deltakere,
 * ventelister og innsjekk. Hentet den det fra de skjermene som finnes —
 * kurs.php, pameldte.php, venteliste.php — ville den maattet gjore tre kall
 * og sette sammen svarene selv, og de tre er bygget for hver sine lister.
 *
 * Dette er derfor et LESEENDEPUNKT og ingenting annet. Alt som skal endres,
 * gaar til endepunktene som alt finnes: kurs.php for datoer, pamelding.php
 * for deltakere, venteliste.php for koen. Vi lager ikke en ny vei til aa
 * gjore det samme — da ville to steder hatt hver sine regler for hva som
 * skjer naar en dato med fem paameldte flyttes.
 *
 * ── Feltnavnene ───────────────────────────────────────────────────────
 *
 * Svaret har noeyaktig de navnene kalenderen i designfila lager selv, saa
 * det bare er datakilden som byttes. Én hendelse:
 *
 *   { id, dato, tid, slutt, tittel, type, holder, kap, pameldt,
 *     deltakere: [{ navn, status, merknad, medlemId, gjest, bookingId }],
 *     venteliste: [{ navn, posisjon, varslet }],
 *     nye, avlyst, stengt }
 *
 * «type» er kurs | event | pop | dropin | verksted | vakt | brenning.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
krev_admin();

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');

/** «2026-09-01» → start av dagen i UTC. Tomt eller tull faller til i dag. */
$dagStart = static function (string $iso, int $skiftDager) use ($oslo, $utc): string {
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) === 1
        ? new DateTimeImmutable($iso . ' 00:00:00', $oslo)
        : (new DateTimeImmutable('today', $oslo));
    return $d->modify(($skiftDager >= 0 ? '+' : '') . $skiftDager . ' days')
             ->setTimezone($utc)->format('Y-m-d H:i:s');
};

// Kalenderen henter én maaned av gangen, men tegner ogsaa dagene fra
// naboemaanedene som faller innenfor uka. Derfor litt slingringsmonn i
// begge ender — det er billigere enn et kall til.
$fra = $dagStart(Foresporsel::tekst('fra'), -7);
$til = $dagStart(Foresporsel::tekst('til'), 8);
if ($til <= $fra) {
    Svar::feil('«til» må være etter «fra».');
}

$iOslo = static function (string $utcTid, string $format) use ($oslo, $utc): string {
    return (new DateTimeImmutable($utcTid, $utc))->setTimezone($oslo)->format($format);
};

// ── Oektene ─────────────────────────────────────────────────────────────
$harHolder = DB::harKolonne('course_sessions', 'kursholder_id');
$holderKol = $harHolder ? ', h.navn AS holder' : ", '' AS holder";
$holderBli = $harHolder ? 'LEFT JOIN kursholdere h ON h.id = cs.kursholder_id' : '';

$okter = DB::alle(
    "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.status, cs.course_id,
            COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet,
            c.tittel, c.type, c.tema {$holderKol}
       FROM course_sessions cs
       JOIN courses c ON c.id = cs.course_id
       {$holderBli}
      WHERE cs.start_tid >= :fra AND cs.start_tid < :til
   ORDER BY cs.start_tid",
    ['fra' => $fra, 'til' => $til]
);

$oktIder = array_map(static fn(array $o): int => (int) $o['id'], $okter);

// ── Deltakerne, i ett kall ──────────────────────────────────────────────
//
// Ett oppslag per okt ville blitt hundre spoerringer for én maaned.
$deltakere = [];
if ($oktIder !== []) {
    $inn = implode(',', $oktIder);
    $allergi = DB::harKolonne('bookings', 'allergier') ? 'b.allergier' : "''";
    foreach (DB::alle(
        "SELECT b.id, b.course_session_id, b.member_id, b.status, b.antall,
                b.created_at, {$allergi} AS merknad,
                COALESCE(m.navn, b.gjest_navn) AS navn,
                COALESCE(m.epost, b.gjest_epost) AS epost,
                COALESCE(m.telefon, b.gjest_telefon) AS telefon
           FROM bookings b
      LEFT JOIN members m ON m.id = b.member_id
          WHERE b.course_session_id IN ({$inn})
            AND b.status <> 'avbestilt'
       ORDER BY b.id"
    ) as $b) {
        $deltakere[(int) $b['course_session_id']][] = $b;
    }
}

// ── Ventelistene ────────────────────────────────────────────────────────
$venter = [];
if ($oktIder !== []) {
    $inn = implode(',', $oktIder);
    foreach (DB::alle(
        "SELECT w.id, w.course_session_id, w.navn, w.posisjon, w.status
           FROM waitlist w
          WHERE w.course_session_id IN ({$inn})
            AND w.status IN ('venter','varslet')
       ORDER BY w.posisjon"
    ) as $w) {
        $venter[(int) $w['course_session_id']][] = $w;
    }
}

// ── Nye paameldinger ────────────────────────────────────────────────────
//
// Merket paa brikka som sier at noe har skjedd siden sist. «Siste doegn» er
// grovt, men det er slik Oversikt teller det fra for — og to steder skal si
// det samme.
$nyGrense = (new DateTimeImmutable('now', $utc))->modify('-1 day')->format('Y-m-d H:i:s');

// ── Medlemmer i verkstedet ──────────────────────────────────────────────
//
// Én grå brikke per dag: hvor mange som var innom. Innstemplingene ligger i
// check_ins — den med understrek; «checkins» uten er en tom tabell fra
// 001_init som ingenting bruker.
$verksted = [];
foreach (DB::alle(
    'SELECT DATE(inn_tid) AS dag, COUNT(DISTINCT member_id) AS antall,
            MIN(inn_tid) AS forste, MAX(COALESCE(ut_tid, inn_tid)) AS siste
       FROM check_ins
      WHERE inn_tid >= :fra AND inn_tid < :til
   GROUP BY DATE(inn_tid)',
    ['fra' => $fra, 'til' => $til]
) as $v) {
    $verksted[] = [
        'id'     => 'verksted-' . $v['dag'],
        'dato'   => $iOslo((string) $v['forste'], 'Y-m-d'),
        'tid'    => $iOslo((string) $v['forste'], 'H:i'),
        'slutt'  => $iOslo((string) $v['siste'], 'H:i'),
        'tittel' => (int) $v['antall'] . ((int) $v['antall'] === 1
                      ? ' medlem innsjekket' : ' medlemmer innsjekket'),
        'type'   => 'verksted',
        'holder' => '',
        'kursId' => 0,
        'kap'    => 0,
        'pameldt'=> 0,
        'deltakere' => [],
        'venteliste' => [],
        'nye'    => 0,
        'avlyst' => false,
        'intern' => false,
        'oktId'  => 0,
    ];
}

// ── Brenningene ─────────────────────────────────────────────────────────
//
// Kalenderen har hatt en farge for «brenning» siden den ble hentet inn, men
// den fantes ikke i basen for migrasjon 088. En ovn som er opptatt til fredag
// er noe man maa vite naar man setter opp et kurs.
//
// «vakt» sto her ogsaa en kort stund. Eieren: «det er ingen andre vakter
// utenom kursholdere» — den som er i verkstedet, er der fordi hun holder et
// kurs, og det staar alt paa oekta. En egen vaktbrikke ville dublert hver
// eneste rad.
$brenninger = [];
if (DB::harTabell('brenninger')) {
    $slagNavn = ['raabrann' => 'Råbrann', 'glasurbrann' => 'Glasurbrann', 'annet' => 'Brenning'];
    foreach (DB::alle(
        'SELECT * FROM brenninger WHERE start_tid >= :fra AND start_tid < :til
      ORDER BY start_tid',
        ['fra' => $fra, 'til' => $til]
    ) as $br) {
        $samme = $iOslo((string) $br['start_tid'], 'Y-m-d')
               === $iOslo((string) $br['slutt_tid'], 'Y-m-d');
        $brenninger[] = [
            'id'        => 'brenning-' . (int) $br['id'],
            'brennId'   => (int) $br['id'],
            'dato'      => $iOslo((string) $br['start_tid'], 'Y-m-d'),
            'tid'       => $iOslo((string) $br['start_tid'], 'H:i'),
            // Gaar den over natta, sier sluttida hvilken dag den er ute —
            // ellers ville «18:00–09:00» sett ut som en feil.
            'slutt'     => $samme
                ? $iOslo((string) $br['slutt_tid'], 'H:i')
                : $iOslo((string) $br['slutt_tid'], 'H:i') . ' ' . $iOslo((string) $br['slutt_tid'], 'j.n.'),
            'tittel'    => ($slagNavn[(string) $br['slag']] ?? 'Brenning')
                           . ((string) ($br['ovn'] ?? '') !== '' ? ' · ' . $br['ovn'] : ''),
            'type'      => 'brenning',
            'holder'    => '',
            'kursId' => 0, 'kap' => 0, 'pameldt' => 0, 'oktId' => 0,
            'deltakere' => [], 'venteliste' => [], 'nye' => 0,
            'avlyst' => false, 'intern' => false,
            'merknad'   => (string) ($br['notat'] ?? ''),
        ];
    }
}

// ── Stengte dager ───────────────────────────────────────────────────────
//
// «apningstider» er den manuelle overstyringen fra for: en rad for dagen
// betyr at den gjelder, uansett hva kalenderen ellers sier. Kalenderen
// trenger bare aa vite hvilke dager som er stengt.
$stengt = [];
foreach (DB::alle(
    'SELECT dato, merknad FROM apningstider
      WHERE stengt = 1 AND dato >= :fra AND dato < :til',
    ['fra' => substr($fra, 0, 10), 'til' => substr($til, 0, 10)]
) as $d) {
    $stengt[(string) $d['dato']] = (string) ($d['merknad'] ?? '');
}

/**
 * Brikketypen. Kalenderen farger etter denne.
 *
 * Temaet er det sikreste naar det er satt, men Paint on Pots har det ikke
 * alltid — kurset er eldre enn temafeltet. Da er tittelen det vi har, og den
 * er stabil: det heter det samme paa nettsida.
 */
$typeFor = static function (array $o): string {
    $tema   = mb_strtolower(trim((string) ($o['tema'] ?? '')));
    $tittel = mb_strtolower(trim((string) $o['tittel']));
    if ($tema === 'paint on pots' || str_starts_with($tittel, 'paint on pots')) {
        return 'pop';
    }
    if ((string) $o['type'] === 'dropin' || $tema === 'drop-in') {
        return 'dropin';
    }
    if ((string) $o['type'] === 'event' || $tema === 'events') {
        return 'event';
    }
    return 'kurs';
};

$hendelser = [];
foreach ($okter as $o) {
    $id  = (int) $o['id'];
    $mine = $deltakere[$id] ?? [];

    $pameldt = 0;
    $rader = [];
    $nye = 0;
    foreach ($mine as $b) {
        $pameldt += (int) $b['antall'];
        $erNy = (string) $b['created_at'] >= $nyGrense;
        if ($erNy) {
            $nye++;
        }
        $rader[] = [
            'navn'    => (string) $b['navn'],
            'status'  => (string) $b['status'] === 'betalt' ? 'Betalt' : 'Reservert',
            // Det deltakeren selv har oppgitt. Kalenderen viser antallet paa
            // brikka og teksten forst naar okta er aapnet — det er
            // helseopplysninger, og de skal ikke lyse paa en skjerm som staar
            // oppe i verkstedet.
            'merknad' => trim((string) ($b['merknad'] ?? '')),
            'ny'      => $erNy,
            // Navnet skal kunne klikkes, ogsaa for dem uten konto.
            // Deltakerruta aapnes av kontoen naar den finnes, ellers av
            // paameldingen.
            'medlemId'  => $b['member_id'] !== null ? (int) $b['member_id'] : 0,
            'gjest'     => $b['member_id'] === null,
            'bookingId' => (int) $b['id'],
            // Kontaktopplysningene, saa deltakerruta kan vise dem framfor aa
            // regne dem ut av navnet. Den gjorde noeyaktig det: «kari.nordmann
            // @epost.no» og et telefonnummer laget av lengden paa navnet.
            // Ringte noen det nummeret, ringte de en fremmed.
            'epost'     => trim((string) ($b['epost'] ?? '')),
            'tlf'       => trim((string) ($b['telefon'] ?? '')),
        ];
    }

    $ko = [];
    foreach ($venter[$id] ?? [] as $w) {
        $ko[] = [
            // Nummeret paa raden i koen. Uten det kan kalenderen vise hvem
            // som staar der, men ikke gi noen plassen: venteliste.php maa
            // vite hvilken rad det gjelder.
            'id'       => (int) $w['id'],
            'navn'     => (string) $w['navn'],
            'posisjon' => (int) $w['posisjon'],
            'varslet'  => (string) $w['status'] === 'varslet',
        ];
    }

    $hendelser[] = [
        'id'     => (string) $id,
        'oktId'  => $id,
        // Kurset datoen hoerer til. Kalenderen trenger det for aa legge en ny
        // dato paa det samme kurset uten aa gaa veien om navnet — to kurs kan
        // hete nesten det samme, og et navn er ikke en identitet.
        'kursId' => (int) $o['course_id'],
        'dato'   => $iOslo((string) $o['start_tid'], 'Y-m-d'),
        'tid'    => $iOslo((string) $o['start_tid'], 'H:i'),
        'slutt'  => $o['slutt_tid'] !== null ? $iOslo((string) $o['slutt_tid'], 'H:i') : '',
        'tittel' => (string) $o['tittel'],
        'type'   => $typeFor($o),
        'holder' => (string) ($o['holder'] ?? ''),
        'kap'    => (int) $o['kapasitet'],
        'pameldt'=> $pameldt,
        'deltakere' => $rader,
        'venteliste' => $ko,
        'nye'    => $nye,
        'avlyst' => (string) $o['status'] === 'avlyst',
        // Interne samlinger staar graa i ukekalenderen i dag. Skillet skal
        // ikke forsvinne fordi kalenderen byttes — men det er ikke en egen
        // brikketype, bare en merking paa en event.
        'intern' => (string) ($o['tema'] ?? '') === 'Kun for medlemmer',
    ];
}

Svar::json([
    'hendelser' => array_merge($hendelser, $verksted, $brenninger),
    'stengte'   => $stengt,
    // Kursholderne, saa kolonnene i dagsvisningen kan settes opp uten et
    // kall til. «standard» er den som vanligvis holder kursene — den staar
    // som spalte ogsaa paa en dag der ingen har noe planlagt, mens de andre
    // bare kommer fram naar de faktisk har en okt, eller naar de velges.
    'kursholdere' => DB::harTabell('kursholdere')
        ? array_map(static fn(array $h): array => [
            'id'       => (int) $h['id'],
            'navn'     => (string) $h['navn'],
            'standard' => isset($h['standard']) && (int) $h['standard'] === 1,
        ], DB::alle(DB::harKolonne('kursholdere', 'standard')
            ? 'SELECT id, navn, standard FROM kursholdere WHERE aktiv = 1 ORDER BY navn'
            : 'SELECT id, navn FROM kursholdere WHERE aktiv = 1 ORDER BY navn'))
        : [],
    'fra' => substr($fra, 0, 10),
    'til' => substr($til, 0, 10),
]);
