<?php
/**
 * Grupperabatt-nivaaene.
 *
 *   GET                     nivaaene
 *   POST handling=lagre     { nivaer: [{ id, min, prosent, gjelder, aktiv }] }
 *
 * Nivaaene laa i nettleseren til den som saa paa dem. Serveren visste
 * ingenting om dem og trakk full pris, mens bookingsiden viste rabatt.
 * Naa er dette den ene kilden — bade skjermen og prisen leser herfra.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

$hent = static fn(): array => array_map(static fn($r) => [
    'id'      => (int) $r['id'],
    'min'     => (int) $r['min_antall'],
    'prosent' => (float) $r['prosent'],
    'gjelder' => $r['gjelder'],
    'aktiv'   => (bool) $r['aktiv'],
], DB::alle('SELECT * FROM discount_tiers ORDER BY gjelder, min_antall'));

if (Foresporsel::metode() === 'GET') {
    Svar::json([
        'nivaer' => $hent(),
        // Hvilke kurs et nivaa kan gjelde for. «alle» og «dreiing» er faste;
        // resten er de publiserte kursene, med slug som noekkel.
        'maal' => array_merge(
            [
                ['verdi' => 'alle',    'navn' => 'Alle kurs og workshops'],
                ['verdi' => 'dreiing', 'navn' => 'Alle dreiekurs'],
            ],
            array_map(static fn($k) => ['verdi' => $k['slug'], 'navn' => $k['tittel']],
                DB::alle("SELECT slug, tittel FROM courses WHERE status = 'publisert' AND type <> 'dropin' ORDER BY tittel"))
        ),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$inn = Foresporsel::kropp()['nivaer'] ?? null;
if (!is_array($inn)) {
    Svar::feil('Mangler nivåene.');
}
if (count($inn) > 40) {
    Svar::feil('For mange rabattnivåer.');
}

$rene = [];
foreach ($inn as $n) {
    $min = (int) ($n['min'] ?? 0);
    $pst = (float) ($n['prosent'] ?? 0);

    // To plasser er det minste som er en gruppe. Hundre prosent er gratis, og
    // det skal settes som pris — ikke som rabatt.
    if ($min < 2 || $min > 99) {
        continue;
    }
    if ($pst <= 0 || $pst > 90) {
        Svar::feil('Rabatten må være mellom 1 og 90 prosent.');
    }

    $rene[] = [
        'min_antall' => $min,
        'prosent'    => round($pst, 2),
        'gjelder'    => mb_substr((string) ($n['gjelder'] ?? 'alle'), 0, 191) ?: 'alle',
        'aktiv'      => !empty($n['aktiv']) ? 1 : 0,
    ];
}

// Hele settet byttes ut i én transaksjon. Halvveis lagring ville gitt priser
// som verken stemmer med det gamle eller det nye.
DB::iTransaksjon(static function () use ($rene): void {
    DB::kjor('DELETE FROM discount_tiers');
    foreach ($rene as $r) {
        DB::settInn('discount_tiers', $r);
    }
});

revider('rabatter_lagret', null, null, ['antall' => count($rene)]);
Svar::ok(['nivaer' => $hent(), 'beskjed' => count($rene) . ' rabattnivåer er lagret.']);
