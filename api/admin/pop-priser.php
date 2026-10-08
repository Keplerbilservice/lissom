<?php
/**
 * Paint on Pots-prisene til den nye adminen (/ny-admin, Kurs og Kalender).
 *
 *   GET  { nivaer: [{ id, navn, prisOre, pris, gjenstander }], depositumKurs: [kursId, …] }
 *
 * Samme kilde som nettsida og kassa: PopPris::nivaer() (tabellen pop_prisnivaer)
 * og «beløp ved booking» paa kurset (courses.depositum). Skjermen regner ingen
 * priser selv; den viser nivaaene og lar kassa ta betalt for gjenstanden.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
krev_admin();

// Skjermen skal aldri faa 500 for prisene: uten tabellene (migrasjonen ikke
// kjoert) eller ved en feil blir lista tom, og kassa tar betalt som foer.
$nivaer = [];
$depositum = [];
try {
    $nivaer = PopPris::nivaer();
    $depositum = DB::harKolonne('courses', 'depositum')
        ? array_map('intval', array_column(DB::alle('SELECT id FROM courses WHERE depositum = 1'), 'id'))
        : [];
} catch (Throwable $e) {
    logg('Pop-priser: fikk ikke lest nivaaene', ['feil' => $e->getMessage()]);
}

Svar::json([
    'nivaer'        => $nivaer,
    'depositumKurs' => $depositum,
]);
