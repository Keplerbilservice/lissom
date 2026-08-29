<?php
/**
 * Aapningstidene verkstedet styrer for haand.
 *
 *   POST handling=steng   { dato, merknad? }   dagen er stengt
 *   POST handling=aapne   { dato }             overstyringen fjernes
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * Regelen for om verkstedet er aapent staar i api/apningstider.php, og den
 * er: finnes en rad for dagen, gjelder den — ellers regnes aapningstida av
 * kursene som gaar. Raden er altsaa overstyringen: en helligdag, en ferieuke,
 * en dag verkstedet er stengt selv om det staar et kurs i kalenderen.
 *
 * Den raden kunne bare lages i basen. «Steng dagen» i kalenderen sa fra at
 * den ikke var koblet, og en stengt dag maatte foeres et annet sted.
 *
 * ── Hva som ikke skjer her ────────────────────────────────────────────
 *
 * Kursene roeres ikke. En stengt dag med et kurs i er en motsigelse verkstedet
 * skal se, ikke noe vi rydder bort i stillhet: staar det et kurs den dagen,
 * sier svaret fra, og eieren avlyser selv om det er det hun mener.
 *
 * «Aapne» sletter raden framfor aa sette stengt = 0. En rad med stengt = 0 og
 * uten tider ville fortsatt vaert en overstyring — den ville sagt «aapent, men
 * vi vet ikke naar», og skygget for kursene som faktisk gaar.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
krev_admin();

$dato = Foresporsel::tekst('dato');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dato) !== 1) {
    Svar::feil('Skriv datoen som 2026-09-02.');
}
// En dato som ikke finnes — 31. februar — passerer moensteret over.
[$aa, $mm, $dd] = array_map('intval', explode('-', $dato));
if (!checkdate($mm, $dd, $aa)) {
    Svar::feil('Den datoen finnes ikke.');
}

$oslo   = new DateTimeZone('Europe/Oslo');
$norsk  = static function (string $iso) use ($oslo): string {
    $d = new DateTimeImmutable($iso . ' 12:00:00', $oslo);
    $dag = ['Sunday' => 'søndag', 'Monday' => 'mandag', 'Tuesday' => 'tirsdag',
            'Wednesday' => 'onsdag', 'Thursday' => 'torsdag', 'Friday' => 'fredag',
            'Saturday' => 'lørdag'][$d->format('l')] ?? '';
    $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli',
            'august', 'september', 'oktober', 'november', 'desember'];
    return $dag . ' ' . (int) $d->format('j') . '. ' . $mnd[(int) $d->format('n') - 1];
};

switch (Foresporsel::tekst('handling')) {

    case 'steng':
        $merknad = mb_substr(trim(Foresporsel::tekst('merknad')), 0, 191);

        // Kursene som staar den dagen. De avlyses ikke herfra — men eieren
        // skal se dem, saa hun kan ta stilling til dem.
        $fra = (new DateTimeImmutable($dato . ' 00:00:00', $oslo))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $til = (new DateTimeImmutable($dato . ' 00:00:00', $oslo))->modify('+1 day')
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $kurs = (int) DB::verdi(
            "SELECT COUNT(*) FROM course_sessions
              WHERE start_tid >= :f AND start_tid < :t AND status <> 'avlyst'",
            ['f' => $fra, 't' => $til]
        );

        $finnes = DB::en('SELECT id FROM apningstider WHERE dato = :d', ['d' => $dato]);
        if ($finnes !== null) {
            DB::oppdater('apningstider',
                ['stengt' => 1, 'fra' => null, 'til' => null,
                 'merknad' => $merknad !== '' ? $merknad : null],
                ['id' => (int) $finnes['id']]);
        } else {
            DB::settInn('apningstider', [
                'dato' => $dato, 'stengt' => 1,
                'merknad' => $merknad !== '' ? $merknad : null,
            ]);
        }
        revider('dag_stengt', 'apningstider', null, ['dato' => $dato, 'kurs' => $kurs]);

        Svar::ok([
            'stengt'  => true,
            'kurs'    => $kurs,
            'beskjed' => 'Verkstedet står som stengt ' . $norsk($dato) . '.'
                . ($kurs > 0
                    ? ' Merk: ' . $kurs . ($kurs === 1 ? ' kurs går' : ' kurs går')
                      . ' fortsatt den dagen. Avlys dem hvis de ikke skal gå.'
                    : ''),
        ]);

    case 'aapne':
        $finnes = DB::en('SELECT id FROM apningstider WHERE dato = :d', ['d' => $dato]);
        if ($finnes === null) {
            Svar::feil('Den dagen står ikke som stengt.');
        }
        DB::kjor('DELETE FROM apningstider WHERE id = :i', ['i' => (int) $finnes['id']]);
        revider('dag_aapnet', 'apningstider', null, ['dato' => $dato]);

        Svar::ok([
            'stengt'  => false,
            'beskjed' => $norsk($dato) . ' følger kursene igjen.',
        ]);

    default:
        Svar::feil('Ukjent handling.');
}
