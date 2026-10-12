<?php
/**
 * Medlemsoppfølging i ny admin (eieren 12. oktober 2026, visningen
 * «Medlemmer og oppfølging»). Logikken ligger i Oppfolging (app/lib).
 *
 *   GET                         { klar, prove, lav, ble, innstillinger }
 *   GET  ?medlem=ID             { notater, paaminnelse }   medlemskortet
 *   POST handling=snakket       { medlemId, sak: prove|lav, notat }
 *   POST handling=paaminnelse   { medlemId, paa: ja|nei }   bryteren per medlem
 *   POST handling=innstillinger { adminMobil?, proveDager?, savnerDager? }
 *
 * Bryterne for SMS-ene er malene (maler.php «lagre» aktiv ja/nei).
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();
$kreverOppdatering = 'Vedlikeholdet må kjøres først (oppdatering 273).';

$innstillinger = static fn(): array => [
    'adminMobil'  => Oppfolging::adminMobil(),
    'proveDager'  => Oppfolging::proveDager(),
    'savnerDager' => Oppfolging::savnerDager(),
    'lavDager'    => Aktivitet::dager(),
];

if (Foresporsel::metode() === 'GET') {
    $id = Foresporsel::heltall('medlem');
    if ($id > 0) {
        $sendt = Oppfolging::klar() ? (Oppfolging::savnerSendt([$id])[$id] ?? null) : null;
        Svar::json([
            'klar'    => Oppfolging::klar(),
            'notater' => Oppfolging::notater($id),
            'paaminnelse' => [
                'antall' => $sendt['antall'] ?? 0,
                'sist'   => $sendt === null ? null
                    : Oppfolging::dagMaaned((new DateTimeImmutable($sendt['sist'], new DateTimeZone('UTC')))
                        ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d')),
            ],
        ]);
    }
    $trygt = static function (callable $fn, mixed $tom): mixed {
        try {
            return $fn();
        } catch (Throwable $e) {
            logg_feil('Medlemsoppfølging (lesing)', $e);
            return $tom;
        }
    };
    $prove = $trygt(static fn() => Oppfolging::proveSlutterSnart(), []);
    Svar::json([
        'klar'  => Oppfolging::klar(),
        'prove' => array_map(static fn($p) => [
            'id'         => $p['id'],
            'navn'       => $p['navn'],
            'telefon'    => Oppfolging::visTelefon($p['telefon']),
            'epost'      => $p['epost'],
            'dagerIgjen' => $p['dagerIgjen'],
            'slutt'      => Oppfolging::langDato($p['slutt']),
            'brukt'      => Stempling::timer($p['bruktMin']),
            'timer'      => $p['timer'] === null ? null : str_replace('.', ',', (string) $p['timer']),
        ], $prove),
        'lav' => array_map(static fn($m) => [
            'id' => $m['id'], 'navn' => $m['navn'], 'dager' => $m['dager'],
            'telefon' => $m['telefon'], 'epost' => $m['epost'],
        ], $trygt(static fn() => Oppfolging::lavAktivitet(), [])),
        // I dag trenger ikke tallet (det hentes hvert minutt der).
        'ble'   => Foresporsel::tekst('del') === 'idag' ? null : $trygt(static fn() => Oppfolging::bleMedlem(), null),
        'innstillinger' => $innstillinger(),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$handling = Foresporsel::tekst('handling');

if ($handling === 'innstillinger') {
    $k = Foresporsel::kropp();
    $ny = [];
    if (isset($k['proveDager'])) {
        $ny['proveDager'] = (int) $k['proveDager'];
    }
    if (isset($k['savnerDager'])) {
        $ny['savnerDager'] = (int) $k['savnerDager'];
    }
    if (array_key_exists('adminMobil', $k)) {
        $ny['adminMobil'] = (string) $k['adminMobil'];
    }
    if (isset($k['lavDager'])) {
        Aktivitet::settDager((int) $k['lavDager'], (int) $admin['id']);
    }
    try {
        Oppfolging::lagreInnstillinger($ny, (int) $admin['id']);
    } catch (InvalidArgumentException $e) {
        Svar::feil($e->getMessage());
    }
    revider('oppfolging_innstillinger', null, null, $ny);
    Svar::ok(['beskjed' => 'Lagret.', 'innstillinger' => $innstillinger()]);
}

if (!Oppfolging::klar()) {
    Svar::feil($kreverOppdatering);
}

$id = Foresporsel::heltall('medlemId');
$medlem = DB::en('SELECT id, navn FROM members WHERE id = :i', ['i' => $id]);
if ($medlem === null) {
    Svar::feil('Fant ikke medlemmet.', 404);
}

if ($handling === 'snakket') {
    $sak = Foresporsel::tekst('sak');
    if (!in_array($sak, ['prove', 'lav'], true)) {
        Svar::feil('Ukjent sak.');
    }
    Oppfolging::snakketMed($id, $sak, Foresporsel::tekst('notat'), (int) $admin['id']);
    revider('oppfolging_snakket', 'member', $id, ['sak' => $sak]);
    Svar::ok(['beskjed' => 'Lagret på medlemskortet.']);
}

if ($handling === 'paaminnelse') {
    $paa = Foresporsel::tekst('paa') === 'ja';
    DB::oppdater('members', ['paaminnelse_sms' => $paa ? 1 : 0], ['id' => $id]);
    revider('oppfolging_paaminnelse', 'member', $id, ['paa' => $paa]);
    Svar::ok(['beskjed' => 'Lagret.']);
}

Svar::feil('Ukjent handling.');
