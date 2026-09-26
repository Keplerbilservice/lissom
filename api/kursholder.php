<?php
/**
 * Kursholderen, sett fra Min side.
 *
 *   GET                              kursene hen holder, og timene denne maaneden
 *   POST handling=inn    { oktId }   stemple inn paa en kursdato (i dag)
 *   POST handling=ut     { oktId }   stemple ut — tida blir forslaget
 *   POST handling=bekreft { id, timer }  bekrefte (eller endre og bekrefte) timene
 *
 * Eieren, 26. september 2026 (GO paa skissen): kursholderen ser kursene sine
 * paa Min side og stempler der. Kursets lengde er forslaget; hen bekrefter
 * eller endrer. Kursholderen kjennes igjen paa e-posten (Kursholder::forMedlem).
 * Stemplingen her er egen, og trekker ikke fra medlemstimene.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem = krev_medlem();
$holder = Kursholder::forMedlem($medlem);
if ($holder === null || !Kursholder::timerKlar()) {
    Svar::json(['erHolder' => false]);
}
$hid = (int) $holder['id'];
$oslo = new DateTimeZone('Europe/Oslo');
$utc = new DateTimeZone('UTC');
$kl = static fn(?string $t): string => $t === null ? ''
    : (new DateTimeImmutable($t, $utc))->setTimezone($oslo)->format('H:i');
$kvarter = static fn(int $min): float => max(0.25, round($min / 15) * 15 / 60);

if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();
    Rate::sjekk('kursholder', maks: 60, vindu: 600, nokkel: (string) $hid);
    $handling = Foresporsel::tekst('handling');

    if ($handling === 'inn' || $handling === 'ut') {
        $oktId = Foresporsel::heltall('oktId');
        $o = DB::en(
            'SELECT cs.id, cs.start_tid, c.tittel FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :i AND cs.kursholder_id = :h',
            ['i' => $oktId, 'h' => $hid]
        );
        if ($o === null) {
            Svar::feil('Fant ikke kurset.', 404);
        }
        $dato = (new DateTimeImmutable((string) $o['start_tid'], $utc))->setTimezone($oslo)->format('Y-m-d');
        $rad = DB::en('SELECT * FROM kursholder_timer WHERE kursholder_id = :h AND session_id = :s', ['h' => $hid, 's' => $oktId]);
        $naa = gmdate('Y-m-d H:i:s');
        if ($handling === 'inn') {
            if ($dato !== (new DateTimeImmutable('now', $oslo))->format('Y-m-d')) {
                Svar::feil('Du kan bare stemple inn på kurset samme dag.');
            }
            if ($rad !== null && (string) $rad['status'] === 'bekreftet') {
                Svar::feil('Timene for dette kurset er alt bekreftet.');
            }
            $felt = ['inn_tid' => $naa, 'ut_tid' => null, 'kilde' => 'stemplet', 'status' => 'forslag'];
            if ($rad === null) {
                DB::settInn('kursholder_timer', $felt + [
                    'kursholder_id' => $hid, 'session_id' => $oktId, 'dato' => $dato,
                    'timer' => 0, 'hva' => mb_substr((string) $o['tittel'], 0, 96),
                ]);
            } else {
                DB::oppdater('kursholder_timer', $felt, ['id' => (int) $rad['id']]);
            }
            revider('kursholder_inn', 'course_session', $oktId, ['kursholder' => $hid]);
            Svar::ok(['beskjed' => 'Du er stemplet inn. Husk å stemple ut når du er ferdig.']);
        }
        if ($rad === null || $rad['inn_tid'] === null || $rad['ut_tid'] !== null) {
            Svar::feil('Du er ikke stemplet inn på dette kurset.');
        }
        $min = max(1, (int) ((time() - strtotime((string) $rad['inn_tid'] . ' UTC')) / 60));
        DB::oppdater('kursholder_timer', ['ut_tid' => $naa, 'timer' => $kvarter($min)], ['id' => (int) $rad['id']]);
        revider('kursholder_ut', 'course_session', $oktId, ['kursholder' => $hid, 'minutter' => $min]);
        Svar::ok(['beskjed' => 'Du er stemplet ut. Bekreft timene under «Timene mine».']);
    }

    if ($handling === 'bekreft') {
        $id = Foresporsel::heltall('id');
        $rad = DB::en('SELECT * FROM kursholder_timer WHERE id = :i AND kursholder_id = :h', ['i' => $id, 'h' => $hid]);
        if ($rad === null) {
            Svar::feil('Fant ikke timene.', 404);
        }
        $raa = str_replace(',', '.', Foresporsel::tekst('timer'));
        $timer = $raa === '' ? (float) $rad['timer'] : (float) $raa;
        if (!is_numeric($raa === '' ? '1' : $raa) || $timer <= 0 || $timer > 24) {
            Svar::feil('Skriv et timetall mellom 0,25 og 24.');
        }
        DB::oppdater('kursholder_timer', ['timer' => round($timer * 4) / 4, 'status' => 'bekreftet'], ['id' => $id]);
        revider('kursholder_timer_bekreftet', 'kursholder', $hid, ['timer' => $timer, 'rad' => $id]);
        Svar::ok(['beskjed' => 'Timene er bekreftet.']);
    }

    Svar::feil('Ukjent handling.');
}

// ------------------------------------------------------------------ lesing
Kursholder::lagForslag($hid);

$idag = (new DateTimeImmutable('now', $oslo))->format('Y-m-d');
$okter = DB::alle(
    "SELECT cs.id, cs.start_tid, cs.slutt_tid, c.tittel,
            (SELECT COALESCE(SUM(b.antall), 0) FROM bookings b
              WHERE b.course_session_id = cs.id AND b.status IN ('betalt','reservert')) AS pameldte
       FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
      WHERE cs.kursholder_id = :h AND cs.status = 'planlagt'
        AND COALESCE(cs.slutt_tid, cs.start_tid) > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 HOUR)
        AND cs.start_tid < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 21 DAY)
   ORDER BY cs.start_tid LIMIT 12",
    ['h' => $hid]
);
$stemplet = [];
foreach (DB::alle('SELECT session_id, inn_tid, ut_tid FROM kursholder_timer WHERE kursholder_id = :h AND inn_tid IS NOT NULL', ['h' => $hid]) as $r) {
    $stemplet[(int) $r['session_id']] = $r;
}
$varighet = static function (?int $min): string {
    if ($min === null) {
        return '';
    }
    $t = intdiv($min, 60);
    $m = $min % 60;
    return ($t ? $t . ' t' : '') . ($m ? ($t ? ' ' : '') . $m . ' min' : '');
};

$mnd = (new DateTimeImmutable('now', $oslo))->format('Y-m-01');
$timer = DB::alle(
    "SELECT * FROM kursholder_timer WHERE kursholder_id = :h AND (dato >= :m OR status = 'forslag')
   ORDER BY dato DESC, id DESC LIMIT 40",
    ['h' => $hid, 'm' => $mnd]
);

Svar::json([
    'erHolder' => true,
    'navn'     => (string) $holder['navn'],
    'betaling' => (string) ($holder['betaling'] ?? 'lonn'),
    'kurs'     => array_map(static function (array $o) use ($stemplet, $kl, $varighet, $idag, $oslo, $utc): array {
        $s = $stemplet[(int) $o['id']] ?? null;
        $dato = (new DateTimeImmutable((string) $o['start_tid'], $utc))->setTimezone($oslo);
        return [
            'id'       => (int) $o['id'],
            'tittel'   => (string) $o['tittel'],
            'naar'     => ($dato->format('Y-m-d') === $idag ? 'I dag' : Booking::norskDatoKort((string) $o['start_tid']))
                          . ' ' . $kl((string) $o['start_tid']) . ($o['slutt_tid'] ? '–' . $kl((string) $o['slutt_tid']) : ''),
            'pameldte' => (int) $o['pameldte'],
            'varighet' => $varighet(Kursholder::minutterFor((int) $o['id'])),
            'iDag'     => $dato->format('Y-m-d') === $idag,
            'inne'     => $s !== null && $s['ut_tid'] === null,
            'inn'      => $s !== null ? $kl((string) $s['inn_tid']) : '',
        ];
    }, $okter),
    'timer' => array_map(static fn(array $r): array => [
        'id'      => (int) $r['id'],
        'dag'     => Booking::norskDatoKort((string) $r['dato']),
        'hva'     => (string) ($r['hva'] ?? ''),
        'timer'   => (float) $r['timer'],
        'status'  => (string) $r['status'],
        'kilde'   => (string) $r['kilde'],
        'tid'     => $r['inn_tid'] !== null ? $kl((string) $r['inn_tid']) . ($r['ut_tid'] !== null ? '–' . $kl((string) $r['ut_tid']) : '') : '',
        'inne'    => $r['inn_tid'] !== null && $r['ut_tid'] === null,
    ], $timer),
]);
