<?php
/**
 * Samlebestillingen: medlemmenes handlelister slaatt sammen til én.
 *
 *   GET                          lista, oppgjoret per medlem og leverandorene
 *   POST handling=fjern          { produktId }             ikke paa lager
 *   POST handling=angre          { produktId }             tilbake i lista
 *   POST handling=pris           { produktId, kroner }     stykkpris
 *   POST handling=krav           ett Vipps-krav per medlem
 *   POST handling=bestill        { leverandorId }          e-post til leverandoren
 *   POST handling=leverandor     { id, epost, bestillingsmaate }
 *   POST handling=nyleverandor   { navn, epost }             ny leverandoer
 *   POST handling=gebyr          { prosent }
 *
 * Eieren, 13. september 2026: «listen som oversendes admin maa slaas sammen
 * til en liste, her maa admin kunne trykke vekk produkter, ikke paa lager.
 * Maa kunne legge inn pris pr varelinje, kunne kreve inn betaling ved vipps.»
 *
 * Lista er samlet per artikkelnummer — det er slik den bestilles. Oppgjoret er
 * delt per medlem — det er slik den betales. Bestillingen ut er ogsaa delt per
 * medlem: eieren, 14. september, «lag heller bestillingen pr medlem ikke samle
 * pr produkt, og be om at hver bestilling merkes med navn».
 *
 * Ingen pris ligger i koden. Gebyrsatsen staar i innstillinger, og stykkprisen
 * skriver admin inn naar leverandoren har sagt hva den koster.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

$klar = DB::harTabell('handleliste_linjer') && DB::harTabell('leverandorer');
if (!$klar) {
    if (Foresporsel::metode() === 'GET') {
        Svar::json(['klar' => false, 'varer' => [], 'medlemmer' => [], 'leverandorer' => [], 'gebyr' => 0]);
    }
    Svar::feil('Handlelista er ikke satt opp ennå. Kjør oppdateringen av databasen først.');
}

/** Gebyrsatsen i prosent. Staar i basen, ikke i koden. */
function handleliste_gebyr(): float
{
    $v = DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'handleliste_gebyr_prosent'");
    $p = (float) str_replace(',', '.', (string) $v);
    return $p > 0 && $p <= 100 ? $p : 0.0;
}

/**
 * Beloep med oere naar det har oere.
 *
 * Booking::kroner runder til hele kroner, og det stemmer overalt ellers:
 * prisene i butikken settes i hele kroner. Her kommer stykkprisen fra
 * leverandoren, og gebyret er en prosent av den — da blir det oere. Sto det
 * «kr. 397,-» paa skjermen mens kravet lyder paa 396,90, ville tallene ikke
 * vaert de samme, og det er penger.
 */
function handleliste_kroner(int $ore): string
{
    if ($ore % 100 === 0) {
        return Booking::kroner($ore);
    }
    return "kr.\u{a0}" . number_format($ore / 100, 2, ',', "\u{a0}");
}

/** Alle linjene som ligger til behandling, med medlem og vare. */
function handleliste_linjer(): array
{
    return DB::alle(
        // Oensker i fritekst (migrasjon 191) har ingen vare: LEFT JOIN, og
        // teksten staar der tittelen ellers staar.
        "SELECT h.id, h.member_id, h.product_id, h.antall, h.status, h.pris_ore, h.order_id,
                COALESCE(p.tittel, h.tekst) AS tittel, COALESCE(p.artikkelnr, '') AS artikkelnr, p.leverandor_id,
                m.navn AS medlemsnavn, m.telefon,
                l.navn AS leverandor
           FROM handleliste_linjer h
      LEFT JOIN products p ON p.id = h.product_id
           JOIN members  m ON m.id = h.member_id
      LEFT JOIN leverandorer l ON l.id = p.leverandor_id
          WHERE h.status IN ('sendt', 'fjernet')
          ORDER BY (h.product_id IS NULL), p.artikkelnr, p.tittel, h.tekst, m.navn"
    );
}

/** Fornavnet. Bestillingen til leverandoren merkes med det som staar her. */
function handleliste_fornavn(string $navn): string
{
    $navn = trim($navn);
    if ($navn === '') {
        return 'et medlem';
    }
    $biter = preg_split('/\s+/', $navn) ?: [$navn];
    $siste = count($biter) > 1 ? ' ' . mb_substr((string) end($biter), 0, 1) . '.' : '';
    return $biter[0] . $siste;
}

/** Bildet skjermen tegner. */
function handleliste_bilde(): array
{
    $linjer = handleliste_linjer();
    $gebyr  = handleliste_gebyr();

    // Samlet per vare — det er slik den bestilles.
    $varer = [];
    foreach ($linjer as $l) {
        // Et oenske er sin egen linje: negativ «produktId» = linje-id, saa
        // fjern/pris/angre treffer akkurat den (se handleliste_hvor()).
        $n = $l['product_id'] === null ? -(int) $l['id'] : (int) $l['product_id'];
        if (!isset($varer[$n])) {
            $varer[$n] = [
                'produktId'  => $n,
                'nummer'     => (string) $l['artikkelnr'],
                'navn'       => (string) $l['tittel'] . ($l['product_id'] === null ? ' (ønske)' : ''),
                'leverandor' => (string) ($l['leverandor'] ?? ''),
                'antall'     => 0,
                'prisOre'    => $l['pris_ore'] === null ? null : (int) $l['pris_ore'],
                'fjernet'    => $l['status'] === 'fjernet',
                'hvem'       => [],
                'kravSendt'  => false,
            ];
        }
        $varer[$n]['antall'] += (int) $l['antall'];
        $varer[$n]['hvem'][] = handleliste_fornavn((string) $l['medlemsnavn']) . ' ' . (int) $l['antall'];
        if ($l['order_id'] !== null) {
            $varer[$n]['kravSendt'] = true;
        }
    }
    foreach ($varer as &$v) {
        $v['hvem'] = implode(' · ', $v['hvem']);
        $v['sumOre'] = $v['prisOre'] === null || $v['fjernet'] ? null : $v['prisOre'] * $v['antall'];
        $v['sum'] = $v['sumOre'] === null ? '—' : handleliste_kroner($v['sumOre']);
        $v['pris'] = $v['prisOre'] === null ? '' : number_format($v['prisOre'] / 100, 2, ',', '');
    }
    unset($v);

    // Delt per medlem — det er slik den betales.
    $medlemmer = [];
    foreach ($linjer as $l) {
        if ($l['status'] === 'fjernet' || $l['pris_ore'] === null) {
            continue;
        }
        $n = (int) $l['member_id'];
        if (!isset($medlemmer[$n])) {
            $medlemmer[$n] = [
                'medlemId' => $n,
                'navn'     => (string) $l['medlemsnavn'],
                'harTlf'   => trim((string) ($l['telefon'] ?? '')) !== '',
                'varerOre' => 0,
                'kravSendt'=> false,
            ];
        }
        $medlemmer[$n]['varerOre'] += (int) $l['pris_ore'] * (int) $l['antall'];
        if ($l['order_id'] !== null) {
            $medlemmer[$n]['kravSendt'] = true;
        }
    }
    foreach ($medlemmer as &$m) {
        $m['gebyrOre'] = (int) round($m['varerOre'] * $gebyr / 100);
        $m['sumOre']   = $m['varerOre'] + $m['gebyrOre'];
        $m['varer']    = handleliste_kroner($m['varerOre']);
        $m['gebyr']    = handleliste_kroner($m['gebyrOre']);
        $m['sum']      = handleliste_kroner($m['sumOre']);
    }
    unset($m);

    $lev = DB::alle('SELECT id, navn, epost, bestillingsmaate FROM leverandorer WHERE aktiv = 1 ORDER BY navn');

    // Hvor mange som har sendt inn, uansett om prisen er satt. Oppgjoret
    // under teller bare dem som har en pris — det er noe annet.
    $harSendt = [];
    foreach ($linjer as $l) {
        if ($l['status'] === 'sendt') {
            $harSendt[(int) $l['member_id']] = true;
        }
    }

    return [
        'klar'         => true,
        'antallMedlemmer' => count($harSendt),
        'varer'        => array_values($varer),
        'medlemmer'    => array_values($medlemmer),
        'leverandorer' => array_map(static fn($l) => [
            'id'     => (int) $l['id'],
            'navn'   => (string) $l['navn'],
            'epost'  => (string) $l['epost'],
            'maate'  => (string) $l['bestillingsmaate'],
        ], $lev),
        'gebyr'        => $gebyr,
    ];
}

if (Foresporsel::metode() === 'GET') {
    Svar::json(handleliste_bilde());
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');

/** WHERE-biten som treffer en vare (alle linjene) eller ett oenske (én linje). */
function handleliste_hvor(int $produktId): array
{
    return $produktId < 0
        ? ['sql' => 'id = :p AND product_id IS NULL', 'p' => -$produktId]
        : ['sql' => 'product_id = :p', 'p' => $produktId];
}

if ($handling === 'fjern' || $handling === 'angre') {
    $produktId = Foresporsel::heltall('produktId');
    $til = $handling === 'fjern' ? 'fjernet' : 'sendt';
    $fra = $handling === 'fjern' ? 'sendt' : 'fjernet';
    $hvor = handleliste_hvor($produktId);
    DB::kjor(
        "UPDATE handleliste_linjer SET status = :til
          WHERE {$hvor['sql']} AND status = :fra AND order_id IS NULL",
        ['til' => $til, 'p' => $hvor['p'], 'fra' => $fra]
    );
    revider('handleliste_' . $handling, $produktId < 0 ? 'handleliste_onske' : 'product', abs($produktId));
    Svar::ok(handleliste_bilde());
}

if ($handling === 'pris') {
    $produktId = Foresporsel::heltall('produktId');
    $kroner = (float) str_replace(',', '.', Foresporsel::tekst('kroner'));
    if ($kroner < 0 || $kroner > 100000) {
        Svar::feil('Prisen må være mellom null og 100 000 kroner.');
    }
    $hvor = handleliste_hvor($produktId);
    DB::kjor(
        "UPDATE handleliste_linjer SET pris_ore = :pris
          WHERE {$hvor['sql']} AND status = 'sendt' AND order_id IS NULL",
        ['pris' => (int) round($kroner * 100), 'p' => $hvor['p']]
    );
    Svar::ok(handleliste_bilde());
}

if ($handling === 'gebyr') {
    $prosent = (float) str_replace(',', '.', Foresporsel::tekst('prosent'));
    if ($prosent < 0 || $prosent > 100) {
        Svar::feil('Gebyret må være mellom null og 100 prosent.');
    }
    DB::kjor(
        'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (:n, :v, :a)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
        ['n' => 'handleliste_gebyr_prosent', 'v' => (string) $prosent, 'a' => (int) $admin['id']]
    );
    Svar::ok(handleliste_bilde());
}

if ($handling === 'leverandor') {
    $id = Foresporsel::heltall('id');
    $rad = DB::en('SELECT id FROM leverandorer WHERE id = :i', ['i' => $id]);
    if ($rad === null) {
        Svar::feil('Fant ikke leverandøren.');
    }
    $epost = mb_substr(trim(Foresporsel::tekst('epost')), 0, 191);
    if ($epost !== '' && !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        Svar::feil('Det er ikke en gyldig e-postadresse.');
    }
    $maate = Foresporsel::tekst('bestillingsmaate') === 'api' ? 'api' : 'epost';
    DB::oppdater('leverandorer', ['epost' => $epost, 'bestillingsmaate' => $maate], ['id' => $id]);
    Svar::ok(handleliste_bilde());
}

// En ny leverandoer. Eieren, 15. september 2026: «jeg vil også kunne legge
// til Scan-Form info@scan-form.no». Navnet er unikt (uq_leverandor_navn);
// finnes det fra foer — ogsaa som deaktivert — vekkes raden i stedet for aa
// feile, og adressen settes om den er oppgitt.
if ($handling === 'nyleverandor') {
    $navn = mb_substr(trim(Foresporsel::tekst('navn')), 0, 191);
    if ($navn === '') {
        Svar::feil('Leverandøren må ha et navn.');
    }
    $epost = mb_substr(trim(Foresporsel::tekst('epost')), 0, 191);
    if ($epost !== '' && !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        Svar::feil('Det er ikke en gyldig e-postadresse.');
    }
    $rad = DB::en('SELECT id, epost FROM leverandorer WHERE navn = :n', ['n' => $navn]);
    if ($rad !== null) {
        DB::oppdater('leverandorer', ['aktiv' => 1, 'epost' => $epost !== '' ? $epost : (string) $rad['epost']], ['id' => (int) $rad['id']]);
        $id = (int) $rad['id'];
    } else {
        $id = DB::settInn('leverandorer', ['navn' => $navn, 'epost' => $epost, 'bestillingsmaate' => 'epost', 'aktiv' => 1]);
    }
    revider('leverandor_ny', 'leverandor', $id, ['navn' => $navn]);
    Svar::ok(handleliste_bilde() + ['lagtTil' => $navn]);
}

// ----------------------------------------------------------------- kravet
//
// Ett krav per medlem, ikke ett per vare. Kravet er en helt vanlig ordre med
// en betaling, slik kassa og nettbutikken ogsaa gjor det — da dukker den opp
// i omsetningen og i betalingslista uten en egen tabell aa holde i takt.
//
// Vipps-kravet er PUSH_MESSAGE: det kommer i appen til medlemmet, uten at
// hun staar foran skjermen. Salgsenheten maa ha lov til det; har den ikke
// det, sier Vipps fra, og meldingen sendes videre som den er.
if ($handling === 'krav') {
    $bilde = handleliste_bilde();
    $gebyr = handleliste_gebyr();
    $sendt = [];
    $feilet = [];

    foreach ($bilde['medlemmer'] as $m) {
        if ($m['kravSendt'] || $m['sumOre'] <= 0) {
            continue;
        }
        $medlemId = (int) $m['medlemId'];
        $telefon = (string) DB::verdi('SELECT telefon FROM members WHERE id = :i', ['i' => $medlemId]);
        if (trim($telefon) === '') {
            $feilet[] = $m['navn'] . ': mangler telefonnummer.';
            continue;
        }

        $linjer = DB::alle(
            "SELECT h.id, h.antall, h.pris_ore, COALESCE(p.tittel, h.tekst) AS tittel, p.id AS pid
               FROM handleliste_linjer h LEFT JOIN products p ON p.id = h.product_id
              WHERE h.member_id = :m AND h.status = 'sendt'
                AND h.pris_ore IS NOT NULL AND h.order_id IS NULL",
            ['m' => $medlemId]
        );
        if ($linjer === []) {
            continue;
        }

        $referanse = Vipps::nyReferanse('HL');
        $ordrenr = 'H-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $sum = (int) $m['sumOre'];

        $ordreId = DB::iTransaksjon(static function () use ($sum, $m, $ordrenr, $referanse, $linjer, $gebyr, $medlemId): int {
            $betalingId = DB::settInn('payments', [
                'vipps_reference' => $referanse,
                'type'            => 'epayment',
                'formal'          => 'ordre',
                'member_id'       => $medlemId,
                'belop_ore'       => $sum,
                'status'          => 'opprettet',
                'idempotency_key' => Vipps::uuid(),
            ]);
            $id = DB::settInn('orders', [
                'ordrenr'      => $ordrenr,
                'member_id'    => $medlemId,
                'kunde_navn'   => (string) $m['navn'],
                'sum_ore'      => $sum,
                'status'       => 'ny',
                'betalt_maate' => 'Vipps',
                'payment_id'   => $betalingId,
            ]);
            if (DB::harKolonne('payments', 'order_id')) {
                DB::oppdater('payments', ['order_id' => $id], ['id' => $betalingId]);
            }
            foreach ($linjer as $l) {
                DB::settInn('order_lines', [
                    'order_id'   => $id,
                    'product_id' => $l['pid'] === null ? null : (int) $l['pid'],
                    'tittel'     => (string) $l['tittel'],
                    'antall'     => (int) $l['antall'],
                    'pris_ore'   => (int) $l['pris_ore'],
                ]);
            }
            if ((int) $m['gebyrOre'] > 0) {
                DB::settInn('order_lines', [
                    'order_id'   => $id,
                    'product_id' => null,
                    'tittel'     => 'Administrasjonsgebyr ' . rtrim(rtrim(number_format($gebyr, 1, ',', ''), '0'), ',') . ' %',
                    'antall'     => 1,
                    'pris_ore'   => (int) $m['gebyrOre'],
                ]);
            }
            return $id;
        });

        try {
            Vipps::opprettBetaling(
                $referanse,
                $sum,
                Vipps::beskrivelse('Handleliste — Lissom Keramikk', (string) $m['navn']),
                Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
                $telefon,
                true
            );
        } catch (Throwable $e) {
            DB::kjor('DELETE FROM order_lines WHERE order_id = :o', ['o' => $ordreId]);
            $pid = DB::verdi('SELECT payment_id FROM orders WHERE id = :o', ['o' => $ordreId]);
            DB::kjor('DELETE FROM orders WHERE id = :o', ['o' => $ordreId]);
            if ($pid) { DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $pid]); }
            logg_feil('Fikk ikke sendt handlelistekrav til medlem ' . $medlemId, $e);
            $feilet[] = $m['navn'] . ': ' . $e->getMessage();
            continue;
        }

        DB::oppdater('payments', ['status' => 'venter'], ['vipps_reference' => $referanse]);
        DB::kjor(
            'UPDATE handleliste_linjer SET order_id = :o WHERE id IN ('
            . implode(',', array_map(static fn($l) => (int) $l['id'], $linjer)) . ')',
            ['o' => $ordreId]
        );
        revider('handleliste_krav', 'order', $ordreId, ['medlem' => $medlemId, 'belop' => $sum]);
        $sendt[] = $m['navn'];
    }

    Svar::ok(handleliste_bilde() + [
        'sendt'  => $sendt,
        'feilet' => $feilet,
    ]);
}

// ------------------------------------------------------- bestillingen ut
if ($handling === 'bestill') {
    $leverandorId = Foresporsel::heltall('leverandorId');
    $lev = DB::en('SELECT * FROM leverandorer WHERE id = :i', ['i' => $leverandorId]);
    if ($lev === null) {
        Svar::feil('Fant ikke leverandøren.');
    }
    if ((string) $lev['bestillingsmaate'] === 'api') {
        Svar::feil('«' . $lev['navn'] . '» står på API, og det finnes ikke noe API å sende til ennå. Sett den på e-post.');
    }
    if (trim((string) $lev['epost']) === '') {
        Svar::feil('«' . $lev['navn'] . '» mangler e-postadresse. Legg den inn først.');
    }

    $linjer = DB::alle(
        "SELECT h.id, h.antall, p.tittel, p.artikkelnr, m.navn AS medlemsnavn, m.id AS mid
           FROM handleliste_linjer h
           JOIN products p ON p.id = h.product_id
           JOIN members  m ON m.id = h.member_id
          WHERE h.status = 'sendt' AND p.leverandor_id = :l AND h.bestilt_at IS NULL
          ORDER BY m.navn, p.artikkelnr",
        ['l' => $leverandorId]
    );
    if ($linjer === []) {
        Svar::feil('Det er ingenting å bestille hos ' . $lev['navn'] . ' nå.');
    }

    // Bestillingen gjor linjene ferdige. Er prisen ikke satt, faar medlemmet
    // aldri noe krav — varene ville vaert kjopt inn uten at noen betalte dem.
    $utenPris = (int) DB::verdi(
        "SELECT COUNT(*) FROM handleliste_linjer h JOIN products p ON p.id = h.product_id
          WHERE h.status = 'sendt' AND p.leverandor_id = :l AND h.bestilt_at IS NULL
            AND h.pris_ore IS NULL",
        ['l' => $leverandorId]
    );
    if ($utenPris > 0) {
        Svar::feil('Noen varelinjer hos ' . $lev['navn'] . ' mangler pris. Sett prisen først, så blir kravet riktig.');
    }

    $perMedlem = [];
    foreach ($linjer as $l) {
        $perMedlem[(int) $l['mid']]['navn'] = handleliste_fornavn((string) $l['medlemsnavn']);
        $perMedlem[(int) $l['mid']]['linjer'][] = $l;
    }

    $nummer = 'B-' . gmdate('ym') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);

    // Teksten ligger som mal, ikke her. Eieren, 1. september 2026: «hvorfor
    // kan ikke alle vaere redigerbare?» — da kan han endre ordlyden overfor
    // leverandoren uten at noen roerer koden. Varelinjene flettes inn.
    Varsel::mal(
        'leverandorbestilling',
        ['epost' => (string) $lev['epost']],
        [
            'nummer'     => $nummer,
            'leverandor' => (string) $lev['navn'],
            'dato'       => date('d.m.Y'),
            'varer'      => handleliste_varetekst($perMedlem),
        ],
        'leverandor',
        $leverandorId
    );

    DB::kjor(
        'UPDATE handleliste_linjer SET bestilt_at = NOW(), status = \'ferdig\' WHERE id IN ('
        . implode(',', array_map(static fn($l) => (int) $l['id'], $linjer)) . ')'
    );
    revider('handleliste_bestilt', 'leverandor', $leverandorId, ['nummer' => $nummer, 'linjer' => count($linjer)]);

    Svar::ok(handleliste_bilde() + ['bestilling' => $nummer, 'til' => (string) $lev['epost']]);
}

Svar::feil('Ukjent handling.');

/**
 * Varelinjene i bestillingen, delt opp per medlem.
 *
 * Eieren, 14. september 2026: «lag heller bestillingen pr medlem ikke samle
 * pr produkt, og be om at hver bestilling merkes med navn». Navnet staar over
 * varene som hoerer til, og merkingen bes om én gang i malen — ikke paa hver
 * eneste linje.
 *
 * @param array<int,array{navn:string,linjer:list<array<string,mixed>>}> $perMedlem
 */
function handleliste_varetekst(array $perMedlem): string
{
    $ut = '';
    foreach ($perMedlem as $m) {
        $ut .= $m['navn'] . "\n";
        foreach ($m['linjer'] as $l) {
            $ut .= '  ' . str_pad((string) $l['artikkelnr'], 10) . ' ' . $l['tittel']
                 . ' — ' . (int) $l['antall'] . " stk\n";
        }
        $ut .= "\n";
    }
    return rtrim($ut);
}
