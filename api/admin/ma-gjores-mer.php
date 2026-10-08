<?php
/**
 * Må gjøres på I dag — idé 3, 4, 5 og 7 (eieren 08.10.2026, visningen
 * «ideer-visning»). Egen fil, så oversikt.php ikke vokser.
 *
 *   GET                              { saker, dekker, mandag, grenser }
 *   GET  ?ovn=1                      brenninger som ikke er merket ferdig + kursdatoer å velge
 *   POST handling=skjul    { nokkel }            skjul saken i 7 dager (Vent en uke / Ikke nå)
 *   POST handling=ferdig   { id, okter[] }       merk brenningen ferdig, med kursdatoene i ovnen
 *   POST handling=hentet   { brenningId }        alle i brenningen er hentet
 *   POST handling=grenser  { andel, dager, innom }
 *
 * 3  Ovnen er ferdig: en brenning merket ferdig, med kursdatoer koblet til
 *    (brenning_okter), gir «Ovnen er ferdig: N klar til henting» i Verksted.
 *    Kursdatoene den dekker tas ut av den vanlige henting-saken («dekker»).
 *    Utsendingen er den som finnes (ferdigbrent.php meld-alle).
 * 4  Kurs som fylles tregt: under «andel» % fylt og under «dager» dager igjen.
 *    Én sak per kurs med datoene under; Paint on Pots og kurs som følger
 *    åpningstiden er ikke med. «Vent en uke» skjuler hele kurset (tregtkurs:<id>).
 * 5  Medlemmer som ikke har vært innom: Aktivitet::lave(innom), uten frosne.
 * 7  Mandagsoppsummering: bare mandager (Oslo). Omsetning fra Omsetning.
 *
 * Bryter: content_blocks «Vis/magjoresmer» = «nei» stopper alt her.
 * Migrasjon 266 kan mangle: da hoppes ovn og skjul over, resten virker.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

const STD_ANDEL = 50;
const STD_DAGER = 21;
const STD_INNOM = 21;
const SKJUL_DAGER = 7;

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');

$paa = static function (): bool {
    try {
        return (string) (DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/magjoresmer'") ?? '') !== 'nei';
    } catch (Throwable) {
        return true;
    }
};

$innstilling = static function (string $nokkel, int $std, int $min, int $maks): int {
    if (!DB::harTabell('innstillinger')) {
        return $std;
    }
    $v = DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => $nokkel]);
    return is_numeric($v) ? max($min, min($maks, (int) $v)) : $std;
};
$grenser = static fn(): array => [
    'andel' => $innstilling('ma_gjores_tregt_andel', STD_ANDEL, 1, 100),
    'dager' => $innstilling('ma_gjores_tregt_dager', STD_DAGER, 1, 120),
    'innom' => $innstilling('ma_gjores_ikke_innom_dager', STD_INNOM, 1, 365),
];

$harSkjul = static fn(): bool => DB::harTabell('ma_gjores_skjul');
$harOvn = static fn(): bool => DB::harTabell('brenning_okter') && DB::harKolonne('brenninger', 'ferdig_at');

$slagNavn = static fn(string $s): string => ['raabrann' => 'Råbrenning', 'glasurbrann' => 'Glasurbrenning'][$s] ?? 'Brenning';

/** «i dag 07:40», «i går 07:40» eller «6.10 07:40» (UTC inn). */
$naarKort = static function (string $t) use ($oslo, $utc): string {
    $d = (new DateTimeImmutable($t, $utc))->setTimezone($oslo);
    $idag = new DateTimeImmutable('today', $oslo);
    $dag = $d->format('Y-m-d');
    $pre = $dag === $idag->format('Y-m-d') ? 'i dag'
        : ($dag === $idag->modify('-1 day')->format('Y-m-d') ? 'i går' : $d->format('j.n'));
    return $pre . ' ' . $d->format('H:i');
};

// ── Sakene ──────────────────────────────────────────────────────────────

/** Idé 3: brenninger merket ferdig, med de som kan hente. */
$ovnSaker = static function () use ($harOvn, $slagNavn, $naarKort): array {
    if (!$harOvn()) {
        return ['saker' => [], 'dekker' => []];
    }
    $saker = [];
    $dekker = [];
    $hentetKol = DB::harKolonne('bookings', 'hentet_at');
    foreach (DB::alle(
        "SELECT id, slag, ovn, ferdig_at FROM brenninger
          WHERE ferdig_at IS NOT NULL AND ferdig_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 4 WEEK)
       ORDER BY ferdig_at DESC LIMIT 20"
    ) as $b) {
        $okter = DB::alle(
            "SELECT cs.id, cs.hentemelding_at, c.tittel FROM brenning_okter bo
               JOIN course_sessions cs ON cs.id = bo.course_session_id
               JOIN courses c ON c.id = cs.course_id
              WHERE bo.brenning_id = :b",
            ['b' => (int) $b['id']]
        );
        if ($okter === []) {
            continue;
        }
        foreach ($okter as $o) {
            $dekker[] = (int) $o['id'];
        }
        // Når alle kursdatoene har fått «Klar til henting», går resten via Klar til henting.
        if (array_filter($okter, static fn($o) => $o['hentemelding_at'] === null) === []) {
            continue;
        }
        $ider = array_map(static fn($o) => (int) $o['id'], $okter);
        $plass = implode(',', array_fill(0, count($ider), '?'));
        $folk = DB::alle(
            "SELECT COALESCE(m.navn, b.gjest_navn) AS navn, b.antall
               FROM bookings b LEFT JOIN members m ON m.id = b.member_id
              WHERE b.course_session_id IN ($plass) AND b.status IN ('betalt','reservert')"
            . ($hentetKol ? ' AND b.hentet_at IS NULL' : '')
            . ' ORDER BY COALESCE(m.navn, b.gjest_navn)',
            $ider
        );
        if ($folk === []) {
            continue;
        }
        $navn = array_map(static fn($p) => trim((string) $p['navn'] ?: 'Uten navn')
            . ((int) $p['antall'] > 1 ? ' (' . (int) $p['antall'] . ')' : ''), $folk);
        $n = count($folk);
        $saker[] = [
            'gruppe' => 'Verksted', 'type' => 'ovn', 'id' => (int) $b['id'], 'teller' => true,
            'tittel' => 'Ovnen er ferdig: ' . $n . ' klar til henting',
            'under'  => $slagNavn((string) $b['slag']) . ', ferdig ' . $naarKort((string) $b['ferdig_at']),
            'melding' => $slagNavn((string) $b['slag']) . ($b['ovn'] ? ' i ' . $b['ovn'] : '')
                . ' ble ferdig ' . $naarKort((string) $b['ferdig_at']) . '. Klar til henting: '
                . implode(', ', $navn) . '.',
            'rute'   => 'henting',
            'okter'  => array_values(array_map(static fn($o) => (int) $o['id'],
                array_filter($okter, static fn($o) => $o['hentemelding_at'] === null))),
            'kurs'   => implode(', ', array_unique(array_map(static fn($o) => (string) $o['tittel'], $okter))),
            'antall' => $n,
        ];
    }
    return ['saker' => $saker, 'dekker' => array_values(array_unique($dekker))];
};

/** Skjulte nøkler (Vent en uke / Ikke nå) som fortsatt gjelder. */
$skjulte = static function () use ($harSkjul): array {
    if (!$harSkjul()) {
        return [];
    }
    return array_flip(array_map('strval', array_column(
        DB::alle('SELECT nokkel FROM ma_gjores_skjul WHERE skjult_til > UTC_TIMESTAMP()'), 'nokkel')));
};

/** Idé 4: kommende kursdatoer som fylles tregt. */
$tregeKurs = static function (array $g) use ($oslo, $utc): array {
    $okter = DB::alle(
        "SELECT cs.id, cs.start_tid, COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet,
                c.id AS kurs_id, c.tittel, c.slug
           FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
          WHERE cs.status = 'planlagt' AND c.status = 'publisert'
            AND cs.start_tid > UTC_TIMESTAMP()
            AND cs.start_tid < DATE_ADD(UTC_TIMESTAMP(), INTERVAL :d DAY)
            -- Paint on Pots og kurs som følger åpningstiden er ikke kurs som «fylles» (eieren 08.10).
            AND c.slug <> 'paint-on-pots'
            AND LOWER(COALESCE(c.tema, '')) NOT LIKE '%paint on pots%'
            AND LOWER(c.tittel) NOT LIKE 'paint on pots%'"
            . (DB::harKolonne('courses', 'folger_apningstid') ? ' AND COALESCE(c.folger_apningstid, 0) = 0' : '') . "
       ORDER BY cs.start_tid LIMIT 60",
        ['d' => $g['dager']]
    );
    $ledige = Booking::ledigePlasserFlere(array_map(static fn($o) => (int) $o['id'], $okter));
    $idag = new DateTimeImmutable('today', $oslo);
    $ut = [];
    foreach ($okter as $o) {
        $kap = (int) $o['kapasitet'];
        if ($kap <= 0) {
            continue;
        }
        $tatt = max(0, $kap - (int) ($ledige[(int) $o['id']] ?? 0));
        if ($tatt * 100 >= $g['andel'] * $kap) {
            continue;
        }
        $start = (new DateTimeImmutable((string) $o['start_tid'], $utc))->setTimezone($oslo);
        $dager = (int) $idag->diff($start->setTime(0, 0))->format('%a');
        $ut[] = [
            'oktId' => (int) $o['id'], 'kursId' => (int) $o['kurs_id'], 'tittel' => (string) $o['tittel'],
            'slug' => (string) $o['slug'], 'dato' => $start->format('j.n'), 'tatt' => $tatt,
            'kapasitet' => $kap, 'dager' => $dager, 'start' => (string) $o['start_tid'],
        ];
    }
    return $ut;
};

/**
 * Én sak per kurs (eieren 08.10): flere økter samme kurs og dato slås sammen
 * til én dato, og alle datoene til kurset står i én sak.
 */
$perKurs = static function (array $okter): array {
    $kurs = [];
    foreach ($okter as $o) {
        $k = $o['kursId'];
        $kurs[$k] ??= ['kursId' => $k, 'tittel' => $o['tittel'], 'slug' => $o['slug'],
            'start' => $o['start'], 'dager' => $o['dager'], 'datoer' => []];
        $d = &$kurs[$k]['datoer'][$o['dato']];
        $d ??= ['dato' => $o['dato'], 'tatt' => 0, 'kapasitet' => 0, 'start' => $o['start']];
        $d['tatt'] += $o['tatt'];
        $d['kapasitet'] += $o['kapasitet'];
        unset($d);
    }
    foreach ($kurs as &$k) {
        $k['datoer'] = array_values($k['datoer']);
        $f = $k['datoer'][0];
        // Feltene første dato har, så mandagsflisen og meldingen virker som før.
        $k += ['dato' => $f['dato'], 'tatt' => $f['tatt'], 'kapasitet' => $f['kapasitet']];
    }
    unset($k);
    return array_values($kurs);
};

/** Idé 5: aktive medlemmer uten innstempling på «innom» dager, uten frosne. */
$ikkeInnom = static function (array $g): array {
    $liste = Aktivitet::lave($g['innom']);
    if ($liste === [] || !DB::harTabell('medlem_frys')) {
        return $liste;
    }
    $frosne = array_flip(array_map('intval', array_column(DB::alle(
        "SELECT DISTINCT member_id FROM medlem_frys
          WHERE status = 'godkjent' AND fra_dato <= CURDATE() AND til_dato >= CURDATE()"
    ), 'member_id')));
    return array_values(array_filter($liste, static fn($m) => !isset($frosne[(int) $m['id']])));
};

$navnListe = static function (array $navn, int $maks = 3): string {
    $vis = array_slice($navn, 0, $maks);
    $rest = count($navn) - count($vis);
    return implode(', ', $vis) . ($rest > 0 ? ' og ' . $rest . ' til' : '');
};

// ── Lesing ──────────────────────────────────────────────────────────────
if (Foresporsel::metode() === 'GET' && Foresporsel::tekst('ovn') !== '') {
    if (!$paa() || !$harOvn()) {
        Svar::json(['klar' => false, 'brenninger' => [], 'okter' => []]);
    }
    $hentetKol = DB::harKolonne('bookings', 'hentet_at');
    Svar::json([
        'klar' => true,
        'brenninger' => array_map(static fn($b) => [
            'id' => (int) $b['id'], 'slag' => $slagNavn((string) $b['slag']), 'ovn' => (string) ($b['ovn'] ?? ''),
            'start' => Booking::norskDato((string) $b['start_tid']), 'slutt' => Booking::norskDato((string) $b['slutt_tid']),
        ], DB::alle(
            "SELECT id, slag, ovn, start_tid, slutt_tid FROM brenninger
              WHERE ferdig_at IS NULL AND start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)
                AND start_tid < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY)
           ORDER BY start_tid DESC"
        )),
        // Kursdatoene som kan ligge i ovnen: over, siste 8 uker, ikke meldt, med deltakere.
        'okter' => array_map(static fn($o) => [
            'id' => (int) $o['id'], 'tittel' => (string) $o['tittel'],
            'naar' => Booking::norskDato((string) $o['start_tid']), 'deltakere' => (int) $o['deltakere'],
        ], array_values(array_filter(DB::alle(
            "SELECT cs.id, cs.start_tid, c.tittel,
                    (SELECT COUNT(*) FROM bookings b WHERE b.course_session_id = cs.id
                        AND b.status IN ('betalt','reservert')" . ($hentetKol ? ' AND b.hentet_at IS NULL' : '') . ") AS deltakere
               FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
              WHERE cs.status IN ('planlagt','gjennomfort') AND cs.hentemelding_at IS NULL
                AND COALESCE(cs.slutt_tid, cs.start_tid) < UTC_TIMESTAMP()
                AND cs.start_tid > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 WEEK)
           ORDER BY cs.start_tid DESC LIMIT 40"
        ), static fn($o) => (int) $o['deltakere'] > 0))),
    ]);
}

if (Foresporsel::metode() === 'GET') {
    $g = $grenser();
    if (!$paa()) {
        Svar::json(['saker' => [], 'dekker' => [], 'mandag' => null, 'grenser' => $g]);
    }
    $saker = [];
    $dekker = [];
    $skjult = [];
    $trege = [];
    $borte = [];
    $trygt = static function (string $hva, callable $fn): void {
        try {
            $fn();
        } catch (Throwable $e) {
            logg_feil('Må gjøres (mer): ' . $hva, $e);
        }
    };
    $trygt('skjul', static function () use (&$skjult, $skjulte): void { $skjult = $skjulte(); });
    $trygt('ovn', static function () use (&$saker, &$dekker, $ovnSaker): void {
        $o = $ovnSaker();
        $saker = array_merge($saker, $o['saker']);
        $dekker = $o['dekker'];
    });
    $trygt('tregt', static function () use (&$saker, &$trege, $tregeKurs, $perKurs, $g, &$skjult): void {
        foreach ($perKurs($tregeKurs($g)) as $k) {
            if (isset($skjult['tregtkurs:' . $k['kursId']])) {
                continue;
            }
            $trege[] = $k;
            $linjer = array_map(static fn($d) => $d['dato'] . ': ' . $d['tatt'] . ' av ' . $d['kapasitet'], $k['datoer']);
            $lange = array_map(static fn($d) => Booking::norskDato($d['start']) . ' (' . $d['tatt'] . ' av '
                . $d['kapasitet'] . ' plasser)', $k['datoer']);
            $saker[] = [
                'gruppe' => 'Kurs', 'type' => 'tregt', 'id' => $k['kursId'], 'teller' => true,
                'nokkel' => 'tregtkurs:' . $k['kursId'],
                'tittel' => $k['tittel'] . ' fylles tregt',
                'under'  => implode(' · ', $linjer),
                'melding' => $k['tittel'] . ' fylles tregt: ' . implode(', ', $lange) . '. Første dato er om '
                    . $k['dager'] . ' dager.',
                'rute' => 'kurs', 'kursId' => $k['kursId'], 'kurs' => $k['tittel'],
                'dato' => implode(', ', array_map(static fn($d) => Booking::norskDato($d['start']), $k['datoer'])),
                'url'  => Config::nettsted() . '/kurs/' . rawurlencode($k['slug']),
            ];
        }
    });
    $trygt('ikkeinnom', static function () use (&$saker, &$borte, $ikkeInnom, $g, &$skjult, $navnListe): void {
        if (isset($skjult['ikkeinnom'])) {
            return;
        }
        $borte = $ikkeInnom($g);
        if ($borte === []) {
            return;
        }
        $uker = intdiv($g['innom'], 7);
        $tid = $g['innom'] % 7 === 0 ? $uker . ' ' . ($uker === 1 ? 'uke' : 'uker') : $g['innom'] . ' dager';
        $n = count($borte);
        $saker[] = [
            'gruppe' => 'Medlemmer', 'type' => 'ikkeinnom', 'id' => 0, 'teller' => true,
            'tittel' => $n . ' ' . ($n === 1 ? 'medlem' : 'medlemmer') . ' ikke innom på ' . $tid,
            'under'  => $navnListe(array_column($borte, 'navn')),
            'melding' => implode(', ', array_map(static fn($m) => $m['navn'] . ' (' . $m['dager'] . ' dager)', $borte))
                . ' har ikke stemplet inn.',
            'rute' => 'folk',
            'medlemmer' => array_map(static fn($m) => [
                'id' => $m['id'], 'navn' => $m['navn'], 'epost' => $m['epost'], 'telefon' => $m['telefon'],
            ], $borte),
        ];
    });

    // Idé 7: mandagsoppsummering. ?mandag=1 viser den andre dager (for test).
    $mandag = null;
    $naa = new DateTimeImmutable('now', $oslo);
    if ($naa->format('N') === '1' || Foresporsel::tekst('mandag') === '1') {
        $trygt('mandag', static function () use (&$mandag, $naa, $utc, $trege, $borte, $navnListe): void {
            $denne = $naa->modify('monday this week')->setTime(0, 0);
            $forrige = $denne->modify('-7 days');
            $for2 = $denne->modify('-14 days');
            $u = static fn(DateTimeImmutable $d): string => $d->setTimezone($utc)->format('Y-m-d H:i:s');
            $eks = static fn(DateTimeImmutable $fra, DateTimeImmutable $til): int =>
                (int) Omsetning::sumUtenMva(Omsetning::perFormal($u($fra), $u($til)))['eksOre'];
            $sum = $eks($forrige, $denne);
            $for = $eks($for2, $forrige);
            $endring = $for > 0 ? (int) round(($sum - $for) / $for * 100) : null;

            $nye = DB::alle(
                "SELECT navn FROM members WHERE rolle <> 'admin' AND status IN ('prove','aktiv')
                    AND start_dato >= :f AND start_dato < :t ORDER BY start_dato",
                ['f' => $forrige->format('Y-m-d'), 't' => $denne->format('Y-m-d')]
            );
            $solgt = DB::en(
                "SELECT COALESCE(SUM(antall),0) AS plasser, COUNT(DISTINCT course_session_id) AS kurs
                   FROM bookings WHERE status = 'betalt' AND created_at >= :f AND created_at < :t",
                ['f' => $u($forrige), 't' => $u($denne)]
            ) ?? ['plasser' => 0, 'kurs' => 0];
            $min = (int) DB::verdi(
                'SELECT COALESCE(SUM(minutter),0) FROM check_ins WHERE inn_tid >= :f AND inn_tid < :t',
                ['f' => $u($forrige), 't' => $u($denne)]
            );
            $fornavn = array_map(static fn($r) => explode(' ', trim((string) $r['navn']))[0], $nye);
            $mandag = [
                'uke'      => (int) $forrige->format('W'),
                'eksOre'   => $sum,
                'endring'  => $endring,
                'trege'    => count($trege),
                'tregtForst' => $trege[0] ?? null,
                'ikkeInnom' => count($borte),
                'nye'      => count($nye),
                'nyeNavn'  => $navnListe($fornavn),
                'plasser'  => (int) $solgt['plasser'],
                'kurs'     => (int) $solgt['kurs'],
                'timer'    => (int) round($min / 60),
            ];
        });
    }

    Svar::json(['saker' => $saker, 'dekker' => $dekker, 'mandag' => $mandag, 'grenser' => $g]);
}

// ── Skriving ────────────────────────────────────────────────────────────
Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
if (!$paa()) {
    Svar::feil('Denne funksjonen er slått av.', 409);
}
$kreverOppdatering = 'Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.';
$handling = Foresporsel::tekst('handling');

if ($handling === 'skjul') {
    if (!$harSkjul()) {
        Svar::feil($kreverOppdatering);
    }
    $nokkel = Foresporsel::tekst('nokkel');
    if (preg_match('/^(tregt:\d+|tregtkurs:\d+|ikkeinnom)$/', $nokkel) !== 1) {
        Svar::feil('Ukjent sak.');
    }
    DB::kjor(
        'INSERT INTO ma_gjores_skjul (nokkel, skjult_til, av) VALUES (?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . SKJUL_DAGER . ' DAY), ?)
         ON DUPLICATE KEY UPDATE skjult_til = VALUES(skjult_til), av = VALUES(av)',
        [$nokkel, (int) $admin['id']]
    );
    revider('ma_gjores_skjult', null, null, ['nokkel' => $nokkel]);
    Svar::ok(['beskjed' => 'Skjult i en uke.']);
}

if ($handling === 'ferdig') {
    if (!$harOvn()) {
        Svar::feil($kreverOppdatering);
    }
    $id = Foresporsel::heltall('id');
    if (DB::verdi('SELECT id FROM brenninger WHERE id = :i', ['i' => $id]) === null) {
        Svar::feil('Fant ikke brenningen.', 404);
    }
    $okter = Foresporsel::kropp()['okter'] ?? [];
    $okter = array_values(array_unique(array_filter(array_map('intval', is_array($okter) ? $okter : []))));
    DB::kjor('UPDATE brenninger SET ferdig_at = COALESCE(ferdig_at, UTC_TIMESTAMP()) WHERE id = :i', ['i' => $id]);
    foreach ($okter as $o) {
        if (DB::verdi('SELECT id FROM course_sessions WHERE id = :i', ['i' => $o]) !== null) {
            DB::kjor('INSERT IGNORE INTO brenning_okter (brenning_id, course_session_id) VALUES (?, ?)', [$id, $o]);
        }
    }
    revider('brenning_ferdig', 'brenning', $id, ['okter' => $okter]);
    Svar::ok(['beskjed' => 'Brenningen er merket ferdig.']);
}

if ($handling === 'hentet') {
    if (!$harOvn() || !DB::harKolonne('bookings', 'hentet_at')) {
        Svar::feil($kreverOppdatering);
    }
    $id = Foresporsel::heltall('brenningId');
    $n = DB::kjor(
        "UPDATE bookings b JOIN brenning_okter bo ON bo.course_session_id = b.course_session_id
            SET b.hentet_at = UTC_TIMESTAMP()
          WHERE bo.brenning_id = :i AND b.status IN ('betalt','reservert') AND b.hentet_at IS NULL",
        ['i' => $id]
    );
    revider('brenning_hentet', 'brenning', $id);
    Svar::ok(['beskjed' => 'Merket som hentet.', 'antall' => $n->rowCount()]);
}

if ($handling === 'grenser') {
    if (!DB::harTabell('innstillinger')) {
        Svar::feil($kreverOppdatering);
    }
    $ny = [
        'ma_gjores_tregt_andel'      => max(1, min(100, Foresporsel::heltall('andel', STD_ANDEL))),
        'ma_gjores_tregt_dager'      => max(1, min(120, Foresporsel::heltall('dager', STD_DAGER))),
        'ma_gjores_ikke_innom_dager' => max(1, min(365, Foresporsel::heltall('innom', STD_INNOM))),
    ];
    foreach ($ny as $k => $v) {
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            [$k, (string) $v, (int) $admin['id']]
        );
    }
    revider('ma_gjores_grenser', null, null, $ny);
    Svar::ok(['beskjed' => 'Grensene er lagret.', 'grenser' => $grenser()]);
}

Svar::feil('Ukjent handling.');
