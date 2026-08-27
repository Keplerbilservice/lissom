<?php
/**
 * Kursholderne, og timene de foerer.
 *
 *   GET                      lista, med timer denne maaneden
 *   POST handling=lagre      { id?, navn, rolle, epost, telefon, kurs, timesats, vises }
 *   POST handling=slett      { id }        settes som sluttet, slettes ikke
 *   POST handling=timer      { id, dato, timer, hva, notat? }
 *   POST handling=slettTime  { timeId }
 *
 * Skjermen viste tre faste navn med timetall ingen hadde foert, og knappene
 * aapnet dialoger som lukket seg igjen. Det fantes ingen tabell bak.
 *
 * En kursholder slettes ikke: timene hens er foert arbeid, og en slettet rad
 * ville tatt dem med seg. «Slett» setter aktiv = 0, og da forsvinner hen fra
 * lista uten at historikken gjor det.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if (!DB::harTabell('kursholdere')) {
    Svar::feil('Kursholdere krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.', 503);
}

$oslo = new DateTimeZone('Europe/Oslo');
$naa  = new DateTimeImmutable('now', $oslo);
$maanedStart = $naa->format('Y-m-01');

if (Foresporsel::metode() === 'GET') {
    $holdere = DB::alle(
        'SELECT h.*,
                (SELECT COALESCE(SUM(t.timer), 0) FROM kursholder_timer t
                  WHERE t.kursholder_id = h.id AND t.dato >= :fra) AS timer_mnd
           FROM kursholdere h
          WHERE h.aktiv = 1
       ORDER BY h.navn',
        ['fra' => $maanedStart]
    );

    $siste = DB::alle(
        'SELECT t.id, t.kursholder_id, t.dato, t.timer, t.hva, h.navn
           FROM kursholder_timer t JOIN kursholdere h ON h.id = t.kursholder_id
       ORDER BY t.dato DESC, t.id DESC LIMIT 30'
    );

    $mnd = static function (string $iso): string {
        $m = ['01'=>'januar','02'=>'februar','03'=>'mars','04'=>'april','05'=>'mai','06'=>'juni',
              '07'=>'juli','08'=>'august','09'=>'september','10'=>'oktober','11'=>'november','12'=>'desember'];
        return $m[substr($iso, 5, 2)] ?? '';
    };

    Svar::json([
        'kursholdere' => array_map(static fn(array $h): array => [
            'id'        => (int) $h['id'],
            'navn'      => (string) $h['navn'],
            'rolle'     => (string) ($h['rolle'] ?? ''),
            'epost'     => (string) ($h['epost'] ?? ''),
            'telefon'   => (string) ($h['telefon'] ?? ''),
            'kurs'      => (string) ($h['kurs'] ?? ''),
            'timesats'  => $h['timesats_ore'] === null ? '' : (string) ((int) $h['timesats_ore'] / 100),
            'vises'     => (bool) $h['vises_paa_nett'],
            'timerMnd'  => rtrim(rtrim(number_format((float) $h['timer_mnd'], 1, ',', ''), '0'), ','),
        ], $holdere),
        'maaned' => $mnd($maanedStart),
        'timer'  => array_map(static fn(array $t): array => [
            'id'    => (int) $t['id'],
            'hvem'  => (string) $t['navn'],
            'dato'  => (new DateTimeImmutable((string) $t['dato']))->format('j.n.Y'),
            'timer' => rtrim(rtrim(number_format((float) $t['timer'], 1, ',', ''), '0'), ','),
            'hva'   => (string) ($t['hva'] ?? ''),
        ], $siste),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$handling = Foresporsel::tekst('handling', 'lagre');

if ($handling === 'lagre') {
    $navn = trim(Foresporsel::tekst('navn'));
    if ($navn === '') {
        Svar::feil('Skriv inn navnet.');
    }
    $sats = trim(Foresporsel::tekst('timesats'));
    $felt = [
        'navn'          => mb_substr($navn, 0, 191),
        'rolle'         => mb_substr(trim(Foresporsel::tekst('rolle')), 0, 96) ?: null,
        'epost'         => mb_substr(trim(Foresporsel::tekst('epost')), 0, 191) ?: null,
        'telefon'       => mb_substr(trim(Foresporsel::tekst('telefon')), 0, 32) ?: null,
        'kurs'          => mb_substr(trim(Foresporsel::tekst('kurs')), 0, 300) ?: null,
        // Timesatsen lagres i oere, som alle andre beloep i basen.
        'timesats_ore'  => $sats === '' ? null : (int) round((float) str_replace(',', '.', $sats) * 100),
        'vises_paa_nett' => Foresporsel::tekst('vises') === 'ja' ? 1 : 0,
    ];

    $id = Foresporsel::heltall('id');
    if ($id > 0) {
        DB::oppdater('kursholdere', $felt, ['id' => $id]);
        revider('kursholder_endret', 'kursholder', $id);
        Svar::ok(['id' => $id, 'beskjed' => $navn . ' er oppdatert.']);
    }
    $id = DB::settInn('kursholdere', $felt);
    revider('kursholder_lagt_til', 'kursholder', $id);
    Svar::ok(['id' => $id, 'beskjed' => $navn . ' er lagt til.']);
}

if ($handling === 'slett') {
    $id = Foresporsel::heltall('id');
    $h = DB::en('SELECT id, navn FROM kursholdere WHERE id = :i', ['i' => $id]);
    if ($h === null) {
        Svar::feil('Fant ikke kursholderen.', 404);
    }
    // Ikke slettet — sluttet. Timene er foert arbeid.
    DB::oppdater('kursholdere', ['aktiv' => 0], ['id' => $id]);
    revider('kursholder_sluttet', 'kursholder', $id);
    $timer = (int) DB::verdi('SELECT COUNT(*) FROM kursholder_timer WHERE kursholder_id = :i', ['i' => $id]);
    Svar::ok(['beskjed' => $h['navn'] . ' er tatt ut av lista.'
        . ($timer > 0 ? ' De ' . $timer . ' timeføringene står igjen i regnskapet.' : '')]);
}

if ($handling === 'timer') {
    $id = Foresporsel::heltall('id');
    if (DB::en('SELECT id FROM kursholdere WHERE id = :i AND aktiv = 1', ['i' => $id]) === null) {
        Svar::feil('Velg en kursholder først.');
    }
    $dato = trim(Foresporsel::tekst('dato')) ?: $naa->format('Y-m-d');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dato) !== 1) {
        Svar::feil('Skriv datoen som 2026-09-02.');
    }
    $timer = (float) str_replace(',', '.', Foresporsel::tekst('timer'));
    if ($timer <= 0 || $timer > 24) {
        Svar::feil('Timer må være mellom 0 og 24.');
    }

    $timeId = DB::settInn('kursholder_timer', [
        'kursholder_id' => $id,
        'dato'          => $dato,
        'timer'         => $timer,
        'hva'           => mb_substr(trim(Foresporsel::tekst('hva')), 0, 96) ?: null,
        'notat'         => mb_substr(trim(Foresporsel::tekst('notat')), 0, 300) ?: null,
        'lagt_inn_av'   => (int) ($admin['id'] ?? 0) ?: null,
    ]);
    revider('kursholder_timer', 'kursholder', $id, ['timer' => $timer, 'dato' => $dato]);

    $sum = (float) DB::verdi(
        'SELECT COALESCE(SUM(timer), 0) FROM kursholder_timer WHERE kursholder_id = :i AND dato >= :fra',
        ['i' => $id, 'fra' => $maanedStart]
    );
    Svar::ok([
        'timeId'  => $timeId,
        'beskjed' => rtrim(rtrim(number_format($timer, 1, ',', ''), '0'), ',') . ' timer er ført. '
                   . 'Denne måneden: ' . rtrim(rtrim(number_format($sum, 1, ',', ''), '0'), ',') . '.',
    ]);
}

if ($handling === 'slettTime') {
    $timeId = Foresporsel::heltall('timeId');
    if (DB::en('SELECT id FROM kursholder_timer WHERE id = :i', ['i' => $timeId]) === null) {
        Svar::feil('Fant ikke føringen.', 404);
    }
    DB::kjor('DELETE FROM kursholder_timer WHERE id = :i', ['i' => $timeId]);
    revider('kursholder_time_slettet', 'kursholder_timer', $timeId);
    Svar::ok(['beskjed' => 'Føringen er fjernet.']);
}

Svar::feil('Ukjent handling.');
