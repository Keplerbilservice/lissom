<?php
/**
 * Gaven medlemmet har faatt, og innloesningen av den.
 *
 *   GET                    gaven som gjelder for meg naa, eller null
 *   POST handling=bruk     { navn?, epost?, dato?, beskjed? }  loeser den inn
 *
 * Kortet oeverst paa Min side sto med fast tekst og ble vist til alle,
 * uansett om noen hadde gitt dem noe. «Send invitasjon» aapnet et skjema,
 * lukket det igjen og satte en bryter i nettleseren — verkstedet fikk aldri
 * vite at noen hadde invitert en venn.
 *
 * En gave gitt til «alle medlemmer» er én rad. Det er medlemsgave_bruk som
 * sier hvem som har loest inn sin, saa den ikke kan brukes om igjen.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem = krev_aktivt_medlem();
$megId  = (int) $medlem['id'];

if (!DB::harTabell('medlemsgaver')) {
    // Uten tabellen finnes ingen gave. Det er ikke en feil for medlemmet.
    Svar::json(['gave' => null]);
}

$oslo = new DateTimeZone('Europe/Oslo');
$idag = (new DateTimeImmutable('now', $oslo))->format('Y-m-d');

/**
 * Min gave: den nyeste som gjelder meg eller alle, som ikke er trukket,
 * ikke utloept, og som jeg ikke alt har loest inn.
 */
$minGave = static function () use ($megId, $idag): ?array {
    return DB::en(
        'SELECT g.* FROM medlemsgaver g
          WHERE (g.member_id = :m OR g.member_id IS NULL)
            AND g.status = :aktiv
            AND g.gyldig_til >= :idag
            AND NOT EXISTS (SELECT 1 FROM medlemsgave_bruk b
                             WHERE b.gave_id = g.id AND b.member_id = :m2)
       ORDER BY g.member_id IS NULL, g.id DESC
          LIMIT 1',
        ['m' => $megId, 'm2' => $megId, 'idag' => $idag, 'aktiv' => 'aktiv']
    );
};

/** Teksten paa kortet. Samme regel som i admin. */
$tittel = static fn(array $g): string => match ($g['type']) {
    'timer'    => ((int) $g['timer']) . ' ekstra timer',
    'gavekort' => 'Gavekort på ' . Booking::kroner((int) $g['belop_ore']),
    default    => 'Ta med en venn',
};

if (Foresporsel::metode() === 'GET') {
    $g = $minGave();
    if ($g === null) {
        Svar::json(['gave' => null]);
    }
    Svar::json(['gave' => [
        'id'        => (int) $g['id'],
        'type'      => (string) $g['type'],
        'tittel'    => $tittel($g),
        'hilsen'    => (string) ($g['hilsen'] ?? ''),
        'gyldigTil' => Booking::norskDatoKort((string) $g['gyldig_til'] . ' 12:00:00'),
        // Bare «Ta med en venn» har et skjema aa fylle ut. De andre vises som
        // et kort medlemmet tar med til verkstedet.
        'harSkjema' => $g['type'] === 'venn',
    ]]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (Foresporsel::tekst('handling') !== 'bruk') {
    Svar::feil('Ukjent handling.');
}

$g = $minGave();
if ($g === null) {
    Svar::feil('Du har ingen gave å løse inn.');
}

$beskjed = trim(implode("\n", array_filter([
    Foresporsel::tekst('navn')  !== '' ? 'Vennens navn: '   . Foresporsel::tekst('navn')  : '',
    Foresporsel::tekst('epost') !== '' ? 'Vennens e-post: ' . Foresporsel::tekst('epost') : '',
    Foresporsel::tekst('dato')  !== '' ? 'Ønsket dato: '    . Foresporsel::tekst('dato')  : '',
    Foresporsel::tekst('beskjed'),
])));

// Uniknoekkelen paa (gave_id, member_id) gjor at to raske trykk ikke gir to
// innloesninger. Kommer den andre gjennom, er gaven alt brukt.
try {
    DB::settInn('medlemsgave_bruk', [
        'gave_id'   => (int) $g['id'],
        'member_id' => $megId,
        'beskjed'   => $beskjed !== '' ? $beskjed : null,
    ]);
} catch (PDOException $e) {
    Svar::feil('Gaven er allerede løst inn.');
}

    Varsel::malTilAdmin('intern_gave_lost_inn', [
        'tittel'  => $tittel($g),
        'navn'    => (string) $medlem['navn'],
        'kontakt' => (string) ($medlem['epost'] ?: $medlem['telefon']),
        'beskjed' => $beskjed !== '' ? $beskjed : 'Ingen flere opplysninger.',
    ], 'medlemsgave', (int) $g['id']);

revider('gave_brukt', 'medlemsgave', (int) $g['id']);

Svar::ok(['beskjed' => $g['type'] === 'venn'
    ? 'Invitasjonen er sendt til verkstedet. Vi tar kontakt for å avtale dato.'
    : 'Verkstedet har fått beskjed. Ta gaven med neste gang du er innom.']);
