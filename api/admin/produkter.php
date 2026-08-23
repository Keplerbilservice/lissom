<?php
/**
 * Varene i butikken.
 *
 *   GET                          alle varer, ogsaa kladder
 *   POST handling=lagre          opprett eller endre en vare
 *   POST handling=bilde          bytt bildet paa en vare
 *   POST handling=slett          fjern en vare
 *
 * Prisen som settes her er den kunden faktisk trekkes. Nettleseren sender
 * aldri belop ved kjop — den sender hvilke varer, og serveren regner ut
 * summen selv.
 *
 * Radnummeret er identiteten, ikke navnet. Et verksted lager ti kopper som
 * alle heter «Kopp» — de er ikke den samme varen. Lagres en vare uten id, blir
 * det en ny rad, ogsaa om navnet finnes fra for.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

// ---------------------------------------------------------------- lesing
if (Foresporsel::metode() === 'GET') {
    $varer = DB::alle('SELECT * FROM products ORDER BY kun_medlemmer, kategori, tittel');

    // Siste kjop av medlemsvarer — leire og ekstra brenning. Sto som fire
    // oppdiktede kjop med navn og kvitteringsnummer, ogsaa paa den ekte siden.
    $internkjop = DB::alle(
        "SELECT o.ordrenr, o.created_at, o.sum_ore,
                COALESCE(m.navn, o.kunde_navn) AS navn,
                GROUP_CONCAT(CONCAT(ol.antall, ' × ', ol.tittel) ORDER BY ol.id SEPARATOR ', ') AS hva
           FROM orders o
           JOIN order_lines ol ON ol.order_id = o.id
           JOIN products pr ON pr.id = ol.product_id AND pr.kun_medlemmer = 1
      LEFT JOIN members m ON m.id = o.member_id
           JOIN payments p ON p.id = o.payment_id AND p.status = 'betalt'
          GROUP BY o.id
          ORDER BY o.id DESC
          LIMIT 20"
    );

    Svar::json([
        'internkjop' => array_map(static fn($k) => [
            'navn' => $k['navn'] ?: 'Gjest',
            'hva'  => $k['hva'],
            'tid'  => Booking::norskDato((string) $k['created_at']),
            'sum'  => Booking::kroner((int) $k['sum_ore']),
            'ref'  => $k['ordrenr'],
        ], $internkjop),
        'varer' => array_map(static fn($v) => [
        'id'           => (int) $v['id'],
        'tittel'       => $v['tittel'],
        'beskrivelse'  => $v['beskrivelse'],
        'bilde'        => $v['bilde'],
        'kategori'     => $v['kategori'],
        'pris'         => (int) $v['pris_ore'] / 100,
        'mva'          => (int) $v['mva_prosent'],
        'lager'        => $v['lager'] === null ? null : (int) $v['lager'],
        'kunMedlemmer' => (bool) $v['kun_medlemmer'],
        'status'       => $v['status'],
    ], $varer)]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling', 'lagre');
$id = Foresporsel::heltall('id');

// ------------------------------------------------------------------ bildet
//
// For seg, fordi «lagre» krever navn og pris. Aa sende hele varen fram og
// tilbake bare for aa bytte bilde er en unodig sjanse til aa skrive over noe
// som ble endret imens — og et navn som kom tomt tilbake ville slettet det.
if ($handling === 'bilde') {
    if ($id <= 0 || DB::en('SELECT id FROM products WHERE id = :i', ['i' => $id]) === null) {
        Svar::feil('Fant ikke varen.');
    }
    $bilde = mb_substr(Foresporsel::tekst('bilde'), 0, 255);
    // Tomt betyr «ingen egen» — da faller varen tilbake paa standardbildet.
    DB::oppdater('products', ['bilde' => $bilde !== '' ? $bilde : null], ['id' => $id]);
    revider('vare_bilde', 'product', $id, ['bilde' => $bilde]);
    Svar::ok(['beskjed' => $bilde !== '' ? 'Bildet er byttet.' : 'Bildet er fjernet.']);
}

// ---------------------------------------------------------------- sletting
if ($handling === 'slett') {
    if ($id <= 0) {
        Svar::feil('Mangler hvilken vare.');
    }
    $vare = DB::en('SELECT tittel FROM products WHERE id = :i', ['i' => $id]);
    if ($vare === null) {
        Svar::feil('Fant ikke varen.');
    }

    // Varer som ligger i en ordre kan ikke slettes — da ville gamle
    // kvitteringer mistet linjene sine. De skjules i stedet.
    $brukt = (int) DB::verdi('SELECT COUNT(*) FROM order_lines WHERE product_id = :i', ['i' => $id]);
    if ($brukt > 0) {
        DB::oppdater('products', ['status' => 'kladd'], ['id' => $id]);
        revider('vare_skjult', 'product', $id, ['tittel' => $vare['tittel'], 'ordrelinjer' => $brukt]);
        Svar::ok(['beskjed' => $vare['tittel'] . ' er tatt ut av butikken. Varen er solgt for, saa den slettes ikke.']);
    }

    DB::kjor('DELETE FROM products WHERE id = :i', ['i' => $id]);
    revider('vare_slettet', 'product', $id, ['tittel' => $vare['tittel']]);
    Svar::ok(['beskjed' => $vare['tittel'] . ' er slettet.']);
}

// ----------------------------------------------------------------- lagring
$tittel = mb_substr(Foresporsel::tekst('tittel'), 0, 191);
$pris   = Foresporsel::heltall('pris');           // kroner

if ($tittel === '') {
    Svar::feil('Varen må ha et navn.');
}
if ($pris < 0 || $pris > 100000) {
    Svar::feil('Prisen må være mellom 0 og 100 000 kroner.');
}

$lagerRaa = Foresporsel::tekst('lager');

$data = [
    'tittel'        => $tittel,
    'beskrivelse'   => Foresporsel::tekst('beskrivelse') ?: null,
    'bilde'         => mb_substr(Foresporsel::tekst('bilde'), 0, 255) ?: null,
    'kategori'      => mb_substr(Foresporsel::tekst('kategori'), 0, 64) ?: null,
    'pris_ore'      => $pris * 100,
    'mva_prosent'   => max(0, min(25, Foresporsel::heltall('mva', 25))),
    // Tomt felt betyr «ikke lagerstyrt», ikke «null paa lager».
    'lager'         => $lagerRaa === '' ? null : max(0, Foresporsel::heltall('lager')),
    'kun_medlemmer' => Foresporsel::tekst('kunMedlemmer') === 'ja' ? 1 : 0,
    'status'        => in_array(Foresporsel::tekst('status'), ['kladd', 'publisert', 'utsolgt'], true)
                        ? Foresporsel::tekst('status') : 'publisert',
];

// Navnet avgjor ingenting. Tidligere ble en vare uten id slaatt sammen med
// en som alt het det samme, og den forste ble stille overskrevet — to like
// kopper kunne ikke ligge ute samtidig.
if ($id > 0) {
    if (DB::en('SELECT id FROM products WHERE id = :i', ['i' => $id]) === null) {
        Svar::feil('Fant ikke varen.');
    }
    DB::oppdater('products', $data, ['id' => $id]);
    revider('vare_endret', 'product', $id, ['tittel' => $tittel]);
    Svar::ok(['id' => $id, 'beskjed' => $tittel . ' er lagret.']);
}

$nyId = DB::settInn('products', $data);
revider('vare_opprettet', 'product', $nyId, ['tittel' => $tittel]);
Svar::ok(['id' => $nyId, 'beskjed' => $tittel . ' er lagt ut i butikken.']);
