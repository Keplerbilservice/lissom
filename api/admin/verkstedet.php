<?php
/**
 * Verkstedet: notatene, paaminnelsene, vaktene og brenningene.
 *
 *   GET  ?fra=2026-09-01&til=2026-09-30
 *   POST handling=notat        { tekst }
 *   POST handling=paaminnelse  { tekst, frist? }
 *   POST handling=paaminnelseGjort { id, gjort }
 *   POST handling=paaminnelseVekk  { id }
 *   POST handling=vakt         { id?, kursholderId, dato, fra, til, notat? }
 *   POST handling=vaktVekk     { id }
 *   POST handling=brenning     { id?, slag, ovn?, dato, fra, sluttDato?, til, notat? }
 *   POST handling=brenningVekk { id }
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * Notatet og paaminnelsene sto i kalenderen og virket — men laa i
 * «localStorage», altsaa i nettleseren paa den maskinen de ble skrevet paa.
 * Skrev eieren en paaminnelse paa telefonen, fantes den ikke paa PC-en, og
 * toemte hun nettleserdataene var den borte. Det er ikke til aa se paa
 * skjermen, og det er nettopp derfor det maatte rettes: hun skrev noe hun
 * trodde var lagret.
 *
 * Vaktene og brenningene er nye. Kalenderen har hatt farger for begge siden
 * den ble hentet inn, men ingen av delene fantes.
 *
 * ── Personlig og felles ───────────────────────────────────────────────
 *
 * Notatet og paaminnelsene hoerer til den som skrev dem — «Notater til meg
 * selv». Vaktene og brenningene er verkstedets: de gjelder alle, og staar i
 * kalenderen for alle.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();
$megId = (int) $admin['id'];

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');

/** «2026-09-02» + «17:30» i norsk tid → UTC for lagring. Null naar tullete. */
$tilUtc = static function (string $dato, string $klokke) use ($oslo, $utc): ?string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dato) !== 1
        || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $klokke) !== 1) {
        return null;
    }
    return (new DateTimeImmutable($dato . ' ' . $klokke, $oslo))
        ->setTimezone($utc)->format('Y-m-d H:i:s');
};

$iOslo = static function (?string $t, string $f) use ($oslo, $utc): string {
    return $t === null ? ''
        : (new DateTimeImmutable($t, $utc))->setTimezone($oslo)->format($f);
};

// ------------------------------------------------------------------ lesing
if (Foresporsel::metode() === 'GET') {
    $dag = static function (string $iso, int $skift) use ($oslo, $utc): string {
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) === 1
            ? new DateTimeImmutable($iso . ' 00:00:00', $oslo)
            : new DateTimeImmutable('today', $oslo);
        return $d->modify(($skift >= 0 ? '+' : '') . $skift . ' days')
                 ->setTimezone($utc)->format('Y-m-d H:i:s');
    };
    $fra = $dag(Foresporsel::tekst('fra'), -7);
    $til = $dag(Foresporsel::tekst('til'), 8);

    Svar::json([
        'notat' => (string) (DB::verdi(
            'SELECT tekst FROM verksted_notater WHERE member_id = :m', ['m' => $megId]
        ) ?? ''),
        'paaminnelser' => array_map(static fn(array $r): array => [
            'id'    => (int) $r['id'],
            'tekst' => (string) $r['tekst'],
            'gjort' => (int) $r['gjort'] === 1,
            'frist' => (string) ($r['frist'] ?? ''),
        ], DB::alle(
            'SELECT id, tekst, gjort, frist FROM verksted_paaminnelser
              WHERE member_id = :m ORDER BY gjort, frist IS NULL, frist, id',
            ['m' => $megId]
        )),
        'vakter' => array_map(static fn(array $v): array => [
            'id'       => (int) $v['id'],
            'holderId' => (int) $v['kursholder_id'],
            'holder'   => (string) ($v['navn'] ?? ''),
            'dato'     => $iOslo((string) $v['start_tid'], 'Y-m-d'),
            'fra'      => $iOslo((string) $v['start_tid'], 'H:i'),
            'til'      => $iOslo((string) $v['slutt_tid'], 'H:i'),
            'notat'    => (string) ($v['notat'] ?? ''),
        ], DB::alle(
            'SELECT v.*, k.navn FROM vakter v
        LEFT JOIN kursholdere k ON k.id = v.kursholder_id
             WHERE v.start_tid >= :f AND v.start_tid < :t
          ORDER BY v.start_tid',
            ['f' => $fra, 't' => $til]
        )),
        'brenninger' => array_map(static fn(array $b): array => [
            'id'        => (int) $b['id'],
            'slag'      => (string) $b['slag'],
            'ovn'       => (string) ($b['ovn'] ?? ''),
            'dato'      => $iOslo((string) $b['start_tid'], 'Y-m-d'),
            'fra'       => $iOslo((string) $b['start_tid'], 'H:i'),
            'sluttDato' => $iOslo((string) $b['slutt_tid'], 'Y-m-d'),
            'til'       => $iOslo((string) $b['slutt_tid'], 'H:i'),
            'notat'     => (string) ($b['notat'] ?? ''),
        ], DB::alle(
            'SELECT * FROM brenninger WHERE start_tid >= :f AND start_tid < :t
          ORDER BY start_tid',
            ['f' => $fra, 't' => $til]
        )),
        'kursholdere' => array_map(static fn(array $h): array => [
            'id' => (int) $h['id'], 'navn' => (string) $h['navn'],
        ], DB::alle('SELECT id, navn FROM kursholdere WHERE aktiv = 1 ORDER BY navn')),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

/** Slagene en brenning kan ha. Fritekst ville gitt ti skrivemaater for det samme. */
const BRENNSLAG = ['raabrann', 'glasurbrann', 'annet'];

switch (Foresporsel::tekst('handling')) {

    // ------------------------------------------------------------ notatet
    case 'notat':
        $tekst = mb_substr(trim(Foresporsel::tekst('tekst')), 0, 4000);
        // Tomt notat er en gyldig tilstand — da er raden borte, ikke tom.
        if ($tekst === '') {
            DB::kjor('DELETE FROM verksted_notater WHERE member_id = :m', ['m' => $megId]);
            Svar::ok(['beskjed' => 'Notatet er tømt.']);
        }
        DB::kjor(
            'INSERT INTO verksted_notater (member_id, tekst) VALUES (:m, :t)
             ON DUPLICATE KEY UPDATE tekst = VALUES(tekst)',
            ['m' => $megId, 't' => $tekst]
        );
        Svar::ok(['beskjed' => 'Notatet er lagret.']);

    // ------------------------------------------------------ paaminnelsene
    case 'paaminnelse':
        $tekst = mb_substr(trim(Foresporsel::tekst('tekst')), 0, 300);
        if ($tekst === '') {
            Svar::feil('Skriv hva du skal huske.');
        }
        $frist = trim(Foresporsel::tekst('frist'));
        if ($frist !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $frist) !== 1) {
            Svar::feil('Skriv fristen som 2026-09-02.');
        }
        $id = DB::settInn('verksted_paaminnelser', [
            'member_id' => $megId, 'tekst' => $tekst,
            'frist' => $frist !== '' ? $frist : null,
        ]);
        Svar::ok(['id' => $id, 'beskjed' => 'Påminnelsen er lagret.']);

    case 'paaminnelseGjort':
        $id = Foresporsel::heltall('id');
        $rad = DB::en(
            'SELECT id FROM verksted_paaminnelser WHERE id = :i AND member_id = :m',
            ['i' => $id, 'm' => $megId]
        );
        if ($rad === null) {
            Svar::feil('Fant ikke påminnelsen.', 404);
        }
        DB::oppdater('verksted_paaminnelser',
            ['gjort' => Foresporsel::tekst('gjort') === 'ja' ? 1 : 0], ['id' => $id]);
        Svar::ok([]);

    case 'paaminnelseVekk':
        $id = Foresporsel::heltall('id');
        // Bare sine egne. Uten «member_id» i betingelsen kunne en admin slettet
        // en annens paaminnelse ved aa gjette nummeret.
        DB::kjor('DELETE FROM verksted_paaminnelser WHERE id = :i AND member_id = :m',
                 ['i' => $id, 'm' => $megId]);
        Svar::ok(['beskjed' => 'Påminnelsen er borte.']);

    // ------------------------------------------------------------- vakter
    case 'vakt':
        $holder = Foresporsel::heltall('kursholderId');
        if (DB::en('SELECT id FROM kursholdere WHERE id = :i AND aktiv = 1', ['i' => $holder]) === null) {
            Svar::feil('Velg hvem som har vakta.');
        }
        $dato  = Foresporsel::tekst('dato');
        $start = $tilUtc($dato, Foresporsel::tekst('fra'));
        $slutt = $tilUtc($dato, Foresporsel::tekst('til'));
        if ($start === null || $slutt === null) {
            Svar::feil('Skriv dato som 2026-09-02 og klokkeslett som 10:00.');
        }
        if ($slutt <= $start) {
            Svar::feil('Vakta må slutte etter at den begynner.');
        }

        $id = Foresporsel::heltall('id');
        $felter = ['kursholder_id' => $holder, 'start_tid' => $start, 'slutt_tid' => $slutt,
                   'notat' => mb_substr(trim(Foresporsel::tekst('notat')), 0, 300) ?: null];
        if ($id > 0) {
            DB::oppdater('vakter', $felter, ['id' => $id]);
        } else {
            $id = DB::settInn('vakter', $felter);
        }
        revider('vakt_lagret', 'vakt', $id, ['start' => $start]);
        Svar::ok(['id' => $id, 'beskjed' => 'Vakta er lagret.']);

    case 'vaktVekk':
        $id = Foresporsel::heltall('id');
        DB::kjor('DELETE FROM vakter WHERE id = :i', ['i' => $id]);
        revider('vakt_slettet', 'vakt', $id);
        Svar::ok(['beskjed' => 'Vakta er tatt bort.']);

    // --------------------------------------------------------- brenninger
    case 'brenning':
        $slag = Foresporsel::tekst('slag');
        if (!in_array($slag, BRENNSLAG, true)) {
            Svar::feil('Velg råbrann, glasurbrann eller annet.');
        }
        $dato  = Foresporsel::tekst('dato');
        // En brenning gaar ofte over natta. Slutter den dagen etter, staar
        // datoen med — ellers regnes den som samme dag.
        $sDato = Foresporsel::tekst('sluttDato') ?: $dato;
        $start = $tilUtc($dato, Foresporsel::tekst('fra'));
        $slutt = $tilUtc($sDato, Foresporsel::tekst('til'));
        if ($start === null || $slutt === null) {
            Svar::feil('Skriv dato som 2026-09-02 og klokkeslett som 10:00.');
        }
        if ($slutt <= $start) {
            Svar::feil('Brenningen må være ferdig etter at den begynner. Går den over natta, sett sluttdatoen til dagen etter.');
        }

        $id = Foresporsel::heltall('id');
        $felter = [
            'slag' => $slag, 'start_tid' => $start, 'slutt_tid' => $slutt,
            'ovn'   => mb_substr(trim(Foresporsel::tekst('ovn')), 0, 64) ?: null,
            'notat' => mb_substr(trim(Foresporsel::tekst('notat')), 0, 300) ?: null,
        ];
        if ($id > 0) {
            DB::oppdater('brenninger', $felter, ['id' => $id]);
        } else {
            $id = DB::settInn('brenninger', $felter);
        }
        revider('brenning_lagret', 'brenning', $id, ['slag' => $slag, 'start' => $start]);
        Svar::ok(['id' => $id, 'beskjed' => 'Brenningen er lagret.']);

    case 'brenningVekk':
        $id = Foresporsel::heltall('id');
        DB::kjor('DELETE FROM brenninger WHERE id = :i', ['i' => $id]);
        revider('brenning_slettet', 'brenning', $id);
        Svar::ok(['beskjed' => 'Brenningen er tatt bort.']);

    default:
        Svar::feil('Ukjent handling.');
}
