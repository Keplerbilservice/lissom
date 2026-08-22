<?php
/**
 * Drop-in: aapningstider, regler og pris.
 *
 *   GET                       tidene, regelen og prisen
 *   POST handling=lagreTider  { tider: [{ ukedag, fra, til, kapasitet }] }
 *   POST handling=lagreRegel  { tekst, pris, plasser }
 *   POST handling=lagUtOkter  { uker }  lager bookbare okter av tidene
 *
 * Pris og kapasitet ligger paa drop-in-kurset, ikke i en egen tabell. Ellers
 * ville prisen staatt to steder, og den kunden trekkes ville vaert den andre
 * enn den eieren ser.
 *
 * Regelteksten ligger i content_blocks, som resten av tekstene eieren endrer.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

const DROPIN_SLUG  = 'drop-in';
const DROPIN_REGEL = 'Dropin/regel';

$kurs = DB::en("SELECT * FROM courses WHERE slug = :s", ['s' => DROPIN_SLUG]);
if ($kurs === null) {
    Svar::feil('Fant ikke drop-in-kurset. Kjør databaseoppdateringen først.', 500);
}

$hentTider = static fn(): array => array_map(static fn($t) => [
    'id'        => (int) $t['id'],
    'ukedag'    => (int) $t['ukedag'],
    'fra'       => substr((string) $t['fra'], 0, 5),
    'til'       => substr((string) $t['til'], 0, 5),
    'kapasitet' => $t['kapasitet'] === null ? null : (int) $t['kapasitet'],
], DB::alle('SELECT * FROM dropin_tider WHERE aktiv = 1 ORDER BY ukedag, fra'));

// ---------------------------------------------------------------- lesing
if (Foresporsel::metode() === 'GET') {
    Svar::json([
        'tider' => $hentTider(),
        'regel' => (string) (DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => DROPIN_REGEL]) ?? ''),
        'pris'      => (int) $kurs['pris_ore'] / 100,
        'kapasitet' => (int) $kurs['kapasitet'],
        'okter'     => (int) DB::verdi(
            "SELECT COUNT(*) FROM course_sessions
              WHERE course_id = :c AND status = 'planlagt' AND start_tid > UTC_TIMESTAMP()",
            ['c' => $kurs['id']]
        ),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

switch (Foresporsel::tekst('handling')) {

    // ------------------------------------------------------------ tidene
    case 'lagreTider':
        $inn = Foresporsel::kropp()['tider'] ?? null;
        if (!is_array($inn)) {
            Svar::feil('Mangler tidene.');
        }
        if (count($inn) > 40) {
            Svar::feil('For mange åpningstider.');
        }

        $rene = [];
        foreach ($inn as $t) {
            $dag = (int) ($t['ukedag'] ?? 0);
            $fra = (string) ($t['fra'] ?? '');
            $til = (string) ($t['til'] ?? '');

            if ($dag < 1 || $dag > 7) {
                continue;
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $fra) || !preg_match('/^\d{2}:\d{2}$/', $til)) {
                continue;
            }
            if ($til <= $fra) {
                Svar::feil('«Til» må være etter «fra» — sjekk ' . $fra . '–' . $til . '.');
            }
            $rene[] = [
                'ukedag'    => $dag,
                'fra'       => $fra . ':00',
                'til'       => $til . ':00',
                'kapasitet' => isset($t['kapasitet']) && $t['kapasitet'] !== '' && $t['kapasitet'] !== null
                                ? max(1, min(99, (int) $t['kapasitet'])) : null,
            ];
        }

        // Hele settet byttes ut i én transaksjon. Halvveis lagring ville gitt
        // aapningstider som ikke stemmer med noe.
        DB::iTransaksjon(static function () use ($rene): void {
            DB::kjor('UPDATE dropin_tider SET aktiv = 0');
            foreach ($rene as $t) {
                DB::kjor(
                    'INSERT INTO dropin_tider (ukedag, fra, til, kapasitet, aktiv)
                          VALUES (:d, :f, :t, :k, 1)
                     ON DUPLICATE KEY UPDATE til = VALUES(til), kapasitet = VALUES(kapasitet), aktiv = 1',
                    ['d' => $t['ukedag'], 'f' => $t['fra'], 't' => $t['til'], 'k' => $t['kapasitet']]
                );
            }
        });

        revider('dropin_tider_lagret', null, null, ['antall' => count($rene)]);
        Svar::ok(['tider' => $hentTider(), 'beskjed' => count($rene) . ' åpningstider er lagret.']);

    // ------------------------------------------------------------ regelen
    case 'lagreRegel':
        $tekst = mb_substr(Foresporsel::tekst('tekst'), 0, 2000);
        $pris  = Foresporsel::heltall('pris');
        $plass = Foresporsel::heltall('plasser');

        if ($pris < 0 || $pris > 20000) {
            Svar::feil('Prisen må være mellom 0 og 20 000 kroner.');
        }

        DB::kjor(
            'INSERT INTO content_blocks (nokkel, verdi, endret_av) VALUES (:n, :v, :a)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            ['n' => DROPIN_REGEL, 'v' => $tekst, 'a' => Sesjon::medlem()['id'] ?? null]
        );

        DB::oppdater('courses', [
            'pris_ore'  => $pris * 100,
            'kapasitet' => max(1, min(99, $plass ?: (int) $kurs['kapasitet'])),
        ], ['id' => $kurs['id']]);

        revider('dropin_regel_lagret', 'course', (int) $kurs['id'], ['pris' => $pris]);
        Svar::ok(['beskjed' => 'Reglene og prisen er lagret.']);

    // -------------------------------------------------------- lag ut okter
    //
    // Lager bookbare okter av aapningstidene, framover i tid. Okter som alt
    // er laget av en aapningstid og ikke har paameldte ryddes forst, saa
    // endrede tider slaar gjennom. Okter lagt inn for haand rores ikke.
    case 'lagUtOkter':
        $uker = max(1, min(26, Foresporsel::heltall('uker', 8)));
        $tider = DB::alle('SELECT * FROM dropin_tider WHERE aktiv = 1 ORDER BY ukedag, fra');
        if ($tider === []) {
            Svar::feil('Sett opp åpningstider først.');
        }

        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $naa  = new DateTimeImmutable('now', $oslo);

        $fjernet = DB::kjor(
            "DELETE cs FROM course_sessions cs
              WHERE cs.course_id = :c
                AND cs.fra_dropin_tid IS NOT NULL
                AND cs.start_tid > UTC_TIMESTAMP()
                AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.course_session_id = cs.id)",
            ['c' => $kurs['id']]
        )->rowCount();

        $laget = 0;
        for ($d = 0; $d < $uker * 7; $d++) {
            $dag = $naa->modify('+' . $d . ' days');
            foreach ($tider as $t) {
                if ((int) $dag->format('N') !== (int) $t['ukedag']) {
                    continue;
                }
                [$tf, $mf] = array_map('intval', explode(':', (string) $t['fra']));
                [$tt, $mt] = array_map('intval', explode(':', (string) $t['til']));

                $start = $dag->setTime($tf, $mf);
                if ($start <= $naa) {
                    continue;   // i dag, men klokka er passert
                }

                DB::kjor(
                    'INSERT IGNORE INTO course_sessions
                        (course_id, start_tid, slutt_tid, kapasitet, fra_dropin_tid)
                     VALUES (:c, :s, :e, :k, :t)',
                    [
                        'c' => $kurs['id'],
                        's' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                        'e' => $dag->setTime($tt, $mt)->setTimezone($utc)->format('Y-m-d H:i:s'),
                        'k' => $t['kapasitet'],
                        't' => $t['id'],
                    ]
                );
                $laget++;
            }
        }

        revider('dropin_okter_laget', 'course', (int) $kurs['id'], ['uker' => $uker, 'laget' => $laget]);
        Svar::ok([
            'beskjed' => $laget . ' drop-in-tider er lagt ut for de neste ' . $uker . ' ukene.'
                . ($fjernet > 0 ? ' ' . $fjernet . ' gamle uten påmeldte ble ryddet bort.' : ''),
        ]);

    default:
        Svar::feil('Ukjent handling.');
}
