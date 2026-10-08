<?php
/**
 * Varene i butikken.
 *
 *   GET                          alle varer, ogsaa kladder
 *   POST handling=lagre          opprett eller endre en vare
 *   POST handling=bilde          bytt bildet paa en vare
 *   POST handling=slett          fjern en vare
 *   POST handling=fyllPaa        { id, antall } legg varer som kom inn til lageret
 *   POST handling=taUt           { id }         ta én fra lageret, uten betaling
 *   POST handling=bestillMer     { id }         legg varen i handlelista (fyll opp til maks)
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
        // kunMedlemmer = Internt (internbutikken). iNettbutikk = nettbutikken.
        // En vare kan vaere begge, med felles lager (migrasjon 252, eieren 04.10.2026).
        'kunMedlemmer' => (bool) $v['kun_medlemmer'],
        'iNettbutikk'  => Lager::iNettbutikk($v),
        'status'       => $v['status'],
        // Handlelista, migrasjon 184. Er den ikke kjoert, staar feltene tomme
        // og skjemaet viser dem som tomme — det er riktig svar da.
        'artikkelnr'   => (string) ($v['artikkelnr'] ?? ''),
        'leverandorId' => isset($v['leverandor_id']) && $v['leverandor_id'] !== null ? (int) $v['leverandor_id'] : 0,
        'kanBestilles' => (bool) ($v['kan_bestilles'] ?? 0),
        // «Bestill mer», migrasjon 233: minimum og maksimum. null = ikke satt.
        'lagerMin'  => isset($v['lager_min']) && $v['lager_min'] !== null ? (int) $v['lager_min'] : null,
        'lagerMaks' => isset($v['lager_maks']) && $v['lager_maks'] !== null ? (int) $v['lager_maks'] : null,
        'leire'     => (bool) ($v['leire'] ?? 0),
        // Vekt per stk for frakten paa samlebestillingen (migrasjon 237).
        'vektKg'    => isset($v['vekt_g']) && $v['vekt_g'] !== null ? Frakt::kg((int) $v['vekt_g']) : '',
    ], $varer),
    'leverandorer' => DB::harTabell('leverandorer')
        ? array_map(static fn($l) => ['id' => (int) $l['id'], 'navn' => (string) $l['navn']],
                    DB::alle('SELECT id, navn FROM leverandorer WHERE aktiv = 1 ORDER BY navn'))
        : [],
    // Frakten. Sto som «kr. 89,-» skrevet inn i nettleseren, og kunne ikke
    // endres uten aa endre koden. Naa staar den i basen.
    'fraktOre' => (int) (DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => 'frakt_ore']) ?? 0),
    // Lite på lager: varene paa eller under min (eieren 04.10.2026).
    'litePaaLager' => Lager::bestillMer(),
    // Ta ut leire (eieren 04.10.2026).
    'taUtLeire'    => Lager::leireListe(),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling', 'lagre');
$id = Foresporsel::heltall('id');

// ------------------------------------------------------------------ frakt
//
// Hva det koster aa sende en pakke. Ett tall, ett sted — kassa henter det
// fra api/butikk.php, og api/ordre.php legger det paa summen naar kunden
// velger sending. Ingen av dem tar imot et beloep fra nettleseren.
if ($handling === 'frakt') {
    $kr = (int) preg_replace('/\D+/', '', Foresporsel::tekst('frakt'));
    if ($kr < 0 || $kr > 5000) {
        Svar::feil('Frakten må være mellom 0 og 5 000 kroner.');
    }
    DB::kjor(
        'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (:n, :v, :a)
         ON DUPLICATE KEY UPDATE verdi = :v2, endret_av = :a2',
        ['n' => 'frakt_ore', 'v' => (string) ($kr * 100), 'a' => (int) (Sesjon::medlem()['id'] ?? 0) ?: null,
         'v2' => (string) ($kr * 100), 'a2' => (int) (Sesjon::medlem()['id'] ?? 0) ?: null]
    );
    Config::glemBasen();
    revider('frakt_lagret', 'innstilling', null, ['kroner' => $kr]);
    Svar::ok(['beskjed' => 'Frakten er satt til kr. ' . $kr . ',-.', 'fraktOre' => $kr * 100]);
}

// --------------------------------------------------------------- fyll paa
//
// «Fyll på» (eieren 04.10.2026): varer som kom inn legges til lageret.
// Antallet legges til i basen (lager + n), ikke skrevet over — da kan et
// salg som skjer imens ikke forsvinne.
if ($handling === 'fyllPaa') {
    $n = Foresporsel::heltall('antall');
    if ($n < 1 || $n > 100000) {
        Svar::feil('Skriv hvor mange som kom inn.');
    }
    $vare = DB::en('SELECT id, tittel FROM products WHERE id = :i', ['i' => $id]);
    if ($vare === null) {
        Svar::feil('Fant ikke varen.');
    }
    DB::kjor('UPDATE products SET lager = COALESCE(lager, 0) + :n WHERE id = :i', ['n' => $n, 'i' => $id]);
    $naa = (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $id]);
    revider('vare_fylt_paa', 'product', $id, ['antall' => $n, 'lager' => $naa]);
    Svar::ok(['id' => $id, 'lager' => $naa, 'beskjed' => 'Lageret er nå ' . $naa . '.']);
}

// ------------------------------------------------------------ bestill mer
//
// «Bestill mer» rett paa raden i Butikk (eieren 08.10.2026): varen legges i
// handlelista hos leverandoeren som «Verkstedets lager», med antallet som
// fyller opp til «Fyll opp lageret til» — samme regel som naar varen naar
// grensen av seg selv (Lager::tilHandlelista). Sendes med «Send bestilling».
if ($handling === 'bestillMer') {
    $vare = DB::en('SELECT * FROM products WHERE id = :i', ['i' => $id]);
    if ($vare === null || $vare['lager'] === null) {
        Svar::feil('Fant ikke varen.');
    }
    if (empty($vare['leverandor_id'])) {
        Svar::feil('Velg leverandør på varen først.');
    }
    $antall = Lager::aaBestille((int) $vare['lager'], isset($vare['lager_maks']) && $vare['lager_maks'] !== null ? (int) $vare['lager_maks'] : null);
    if ($antall <= 0) {
        Svar::feil('Sett «Fyll opp lageret til» på varen først.');
    }
    if (!Lager::harVerkstedslinjer()) {
        Svar::feil('Dette krever oppdatering 253. Kjør oppdateringene først.');
    }
    Lager::tilHandlelista($vare);
    revider('vare_bestill_mer', 'product', $id, ['antall' => $antall]);
    Svar::ok(['id' => $id, 'antall' => $antall, 'beskjed' => 'Lagt i handlelista: ' . $antall . ' stk.']);
}

// ------------------------------------------------------------------ ta ut
//
// «Ta ut leire» (eieren 04.10.2026, GO): admin tar én pose fra lageret, uten
// betaling. Trekkes i basen bare naar det er noe igjen, saa to trykk samtidig
// ikke kan gi minus. Samme regel for varsel og handleliste som et salg.
if ($handling === 'taUt') {
    $vare = DB::en('SELECT id, tittel FROM products WHERE id = :i AND lager IS NOT NULL', ['i' => $id]);
    if ($vare === null) {
        Svar::feil('Fant ikke varen.');
    }
    $trukket = DB::kjor('UPDATE products SET lager = lager - 1 WHERE id = :i AND lager > 0', ['i' => $id])->rowCount();
    if ($trukket !== 1) {
        Svar::feil('Ingen igjen på lager.');
    }
    $naa = (int) DB::verdi('SELECT lager FROM products WHERE id = :i', ['i' => $id]);
    revider('vare_tatt_ut', 'product', $id, ['tittel' => $vare['tittel'], 'lager' => $naa]);
    Lager::etterSalg($id, 1);
    Svar::ok(['id' => $id, 'lager' => $naa, 'beskjed' => 'Tatt ut 1. Lageret er nå ' . $naa . '.']);
}

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

// Handlelista, migrasjon 184. Artikkelnummeret er leverandorens eget nummer,
// og det er det som staar i bestillingen — derfor foelger det varen og ikke
// bestillingen. «Kan bestilles» er det som avgjor om varen dukker opp i
// handlelista paa Min side; ligger den bare paa lager, hoerer den hjemme i
// kurven som for.
if (DB::harKolonne('products', 'artikkelnr')) {
    $lev = Foresporsel::heltall('leverandorId');
    $data['artikkelnr']    = mb_substr(trim(Foresporsel::tekst('artikkelnr')), 0, 64);
    $data['leverandor_id'] = $lev > 0 && DB::en('SELECT id FROM leverandorer WHERE id = :i', ['i' => $lev]) !== null
                                ? $lev : null;
    $data['kan_bestilles'] = Foresporsel::tekst('kanBestilles') === 'ja' ? 1 : 0;
}

// «Bestill mer», migrasjon 233. Minimum og maksimum; tomt felt = ikke satt (eieren, 28.09).
if (Lager::harGrense()) {
    $minRaa = Foresporsel::tekst('lagerMin');
    $data['lager_min'] = $minRaa === '' ? null : max(0, Foresporsel::heltall('lagerMin'));
    $maksRaa = Foresporsel::tekst('lagerMaks');
    $data['lager_maks'] = $maksRaa === '' ? null : max(0, Foresporsel::heltall('lagerMaks'));
}
// Vekt per stk i kilo, for frakten fra Pakke-Express (migrasjon 237).
// Tomt felt = ukjent. Sendes feltet ikke med, roeres vekten ikke.
if (Frakt::klar() && array_key_exists('vektKg', Foresporsel::kropp())) {
    try {
        $data['vekt_g'] = Frakt::gramFraKg(Foresporsel::tekst('vektKg'));
    } catch (InvalidArgumentException $e) {
        Svar::feil($e->getMessage());
    }
}
// Nettbutikken (migrasjon 252). Sendes «iNettbutikk» med (admin-ny), gjelder
// den. Sendes den ikke (gamle admin har bare «Kun for medlemmer»), roeres
// bryteren ikke: NULL betyr «som foer», og en vare som er begge beholder det.
// Bare hvis varen da ikke ville vaert noe sted (ikke internt, og bryteren
// staar paa 0), settes den tilbake til NULL — altsaa i nettbutikken.
if (Lager::harNettbutikkBryter()) {
    // Ingen av dem = bare i admin (eieren 04.10.2026, GO): varen er paa
    // lager, kan tas ut og varsles, men vises ikke for kunder eller medlemmer.
    if (array_key_exists('iNettbutikk', Foresporsel::kropp())) {
        $data['i_nettbutikk'] = Foresporsel::tekst('iNettbutikk') === 'ja' ? 1 : 0;
    } elseif ($id > 0 && $data['kun_medlemmer'] === 0
        && DB::verdi('SELECT i_nettbutikk FROM products WHERE id = :i', ['i' => $id]) !== null
        && (int) DB::verdi('SELECT i_nettbutikk FROM products WHERE id = :i', ['i' => $id]) === 0) {
        $data['i_nettbutikk'] = null;
    }
}

// Leire, inkludert i Prøv Lissom (eieren, 29. september 2026).
if (DB::harKolonne('products', 'leire')) {
    $data['leire'] = Foresporsel::tekst('leire') === 'ja' ? 1 : 0;
}

// Navnet avgjor ingenting. Tidligere ble en vare uten id slaatt sammen med
// en som alt het det samme, og den forste ble stille overskrevet — to like
// kopper kunne ikke ligge ute samtidig.
// «lagerEndring» fra den nye adminen (/ny-admin › Varer, 8. oktober 2026):
// differansen admin gjorde i arket legges paa det som staar i basen naa, i én
// UPDATE, i stedet for aa skrive et tall som kan vaere lest foer et salg.
// Endret hun ikke lageret, roeres det ikke. Uten feltet er alt som foer.
$lagerEndring = array_key_exists('lagerEndring', Foresporsel::kropp()) ? Foresporsel::heltall('lagerEndring') : null;
if ($id > 0 && $lagerEndring !== null) {
    unset($data['lager']);
}

if ($id > 0) {
    $foer = DB::en('SELECT * FROM products WHERE id = :i', ['i' => $id]);
    if ($foer === null) {
        Svar::feil('Fant ikke varen.');
    }
    DB::oppdater('products', $data, ['id' => $id]);
    if ($lagerEndring !== null && $lagerEndring !== 0) {
        DB::kjor('UPDATE products SET lager = GREATEST(0, COALESCE(lager, 0) + :d) WHERE id = :i', ['d' => $lagerEndring, 'i' => $id]);
    }
    revider('vare_endret', 'product', $id, ['tittel' => $tittel]);
    // Varsling ogsaa naar antall eller min endres for haand (eieren 04.10.2026).
    Lager::etterEndring($id,
        $foer['lager'] === null ? null : (int) $foer['lager'],
        isset($foer['lager_min']) && $foer['lager_min'] !== null ? (int) $foer['lager_min'] : null);
    Svar::ok(['id' => $id, 'beskjed' => $tittel . ' er lagret.']);
}

$nyId = DB::settInn('products', $data);
revider('vare_opprettet', 'product', $nyId, ['tittel' => $tittel]);
Lager::etterEndring($nyId, null, null);
Svar::ok(['id' => $nyId, 'beskjed' => $tittel . ' er lagt ut i butikken.']);
