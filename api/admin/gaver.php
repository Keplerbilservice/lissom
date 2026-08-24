<?php
/**
 * Gaver fra verkstedet til medlemmene.
 *
 *   GET                       gavene som gjelder naa, og de som er trukket
 *   POST handling=lagre       { type, mottaker, medlemId?, timer?, belop?, hilsen? }
 *   POST handling=trekk       { id }
 *
 * Skjermen «Gi gave» fylte ut et skjema, sa «Gaven er sendt til alle
 * medlemmer» og lagret den i nettleseren til den som trykket. Gaven fantes
 * altsaa bare hos Monica selv.
 *
 * En gave slettes ikke, den trekkes. Har noen alt loest inn sin, staar det
 * igjen hvem det var.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if (!DB::harTabell('medlemsgaver')) {
    Svar::feil('Gaver krever en oppdatering av databasen. Kjør vedlikeholdet under Oversikt først.', 503);
}

$oslo = new DateTimeZone('Europe/Oslo');
$idag = (new DateTimeImmutable('now', $oslo))->format('Y-m-d');

/** Teksten paa kortet. Samme regel i admin og paa Min side. */
function gave_tittel(array $g): string
{
    return match ($g['type']) {
        'timer'    => ((int) $g['timer']) . ' ekstra timer',
        'gavekort' => 'Gavekort på ' . Booking::kroner((int) $g['belop_ore']),
        default    => 'Ta med en venn',
    };
}

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle(
        'SELECT g.*, m.navn AS mottaker_navn,
                (SELECT COUNT(*) FROM medlemsgave_bruk b WHERE b.gave_id = g.id) AS brukt
           FROM medlemsgaver g
      LEFT JOIN members m ON m.id = g.member_id
       ORDER BY g.id DESC
          LIMIT 100'
    );

    Svar::json(['gaver' => array_map(static fn(array $g): array => [
        'id'        => (int) $g['id'],
        'tittel'    => gave_tittel($g),
        'type'      => (string) $g['type'],
        'mottaker'  => $g['member_id'] === null ? 'Alle medlemmer' : (string) $g['mottaker_navn'],
        'hilsen'    => (string) ($g['hilsen'] ?? ''),
        'gyldigTil' => Booking::norskDatoKort((string) $g['gyldig_til'] . ' 12:00:00'),
        'utloept'   => (string) $g['gyldig_til'] < $idag,
        'trukket'   => $g['status'] === 'trukket',
        'brukt'     => (int) $g['brukt'],
    ], $rader)]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');

if ($handling === 'trekk') {
    $id = (int) (Foresporsel::kropp()['id'] ?? 0);
    if ($id <= 0 || DB::en('SELECT id FROM medlemsgaver WHERE id = :i', ['i' => $id]) === null) {
        Svar::feil('Fant ikke gaven.');
    }
    DB::oppdater('medlemsgaver', ['status' => 'trukket'], ['id' => $id]);
    revider('gave_trukket', 'medlemsgave', $id);
    Svar::ok(['beskjed' => 'Gaven er trukket tilbake.']);
}

if ($handling !== 'lagre') {
    Svar::feil('Ukjent handling.');
}

$type = match (Foresporsel::tekst('type')) {
    'Ekstra timer' => 'timer',
    'Gavekort'     => 'gavekort',
    default        => 'venn',
};

// «Alle medlemmer» er én gave gitt til gruppa, ikke én rad per medlem. Da
// ser ogsaa den som blir medlem i morgen den, og den kan trekkes i ett grep.
$medlemId = null;
if (Foresporsel::tekst('mottaker') === 'Ett medlem') {
    $medlemId = (int) (Foresporsel::kropp()['medlemId'] ?? 0);
    if ($medlemId <= 0) {
        // Skjermen sender navnet paa medlemmet, ikke id-en. Slaa det opp.
        $navn = Foresporsel::tekst('medlem');
        $rad = $navn === '' ? null : DB::en('SELECT id FROM members WHERE navn = :n ORDER BY id LIMIT 1', ['n' => $navn]);
        $medlemId = $rad === null ? 0 : (int) $rad['id'];
    }
    if ($medlemId <= 0 || DB::en('SELECT id FROM members WHERE id = :i', ['i' => $medlemId]) === null) {
        Svar::feil('Velg hvem gaven skal gå til.');
    }
}

$timer = null;
$belop = null;
if ($type === 'timer') {
    $timer = max(1, (int) (Foresporsel::kropp()['timer'] ?? 0));
} elseif ($type === 'gavekort') {
    $kroner = max(1, (int) (Foresporsel::kropp()['belop'] ?? 0));
    $belop  = $kroner * 100;
}

// «Gaven gjelder ut inneværende måned», staar det paa skjermen. Da skal
// datoen komme derfra og ikke fra en gjetning.
$gyldigTil = (new DateTimeImmutable('now', $oslo))->format('Y-m-t');

$id = DB::settInn('medlemsgaver', [
    'member_id'  => $medlemId,
    'type'       => $type,
    'timer'      => $timer,
    'belop_ore'  => $belop,
    'hilsen'     => Foresporsel::tekst('hilsen') ?: null,
    'gyldig_til' => $gyldigTil,
    'gitt_av'    => (int) $admin['id'],
]);

revider('gave_gitt', 'medlemsgave', $id);

$gave = DB::en('SELECT * FROM medlemsgaver WHERE id = :i', ['i' => $id]);
$til  = $medlemId === null
    ? 'alle medlemmer'
    : (string) (DB::verdi('SELECT navn FROM members WHERE id = :i', ['i' => $medlemId]) ?? 'medlemmet');

Svar::ok([
    'id'      => $id,
    'beskjed' => gave_tittel($gave) . ' er gitt til ' . $til
        . ', og vises på Min side ut ' . Booking::norskDatoKort($gyldigTil . ' 12:00:00') . '.',
]);
