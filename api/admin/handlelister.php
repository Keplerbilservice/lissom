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
        "SELECT h.id, h.member_id, h.product_id, h.antall, h.status, h.pris_ore, h.order_id,
                p.tittel, p.artikkelnr, p.leverandor_id,
                m.navn AS medlemsnavn, m.telefon,
                l.navn AS leverandor
           FROM handleliste_linjer h
           JOIN products p ON p.id = h.product_id
           JOIN members  m ON m.id = h.member_id
      LEFT JOIN leverandorer l ON l.id = p.leverandor_id
          WHERE h.status IN ('sendt', 'fjernet')
          ORDER BY p.artikkelnr, p.tittel, m.navn"
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
        $n = (int) $l['product_id'];
        if (!isset($varer[$n])) {
            $varer[$n] = [
                'produktId'  => $n,
                'nummer'     => (string) $l['artikkelnr'],
                'navn'       => (string) $l['tittel'],
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

    return [
        'klar'         => true,
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

if ($handling === 'fjern' || $handling === 'angre') {
    $produktId = Foresporsel::heltall('produktId');
    $til = $handling === 'fjern' ? 'fjernet' : 'sendt';
    $fra = $handling === 'fjern' ? 'sendt' : 'fjernet';
    DB::kjor(
        "UPDATE handleliste_linjer SET status = :til
          WHERE product_id = :p AND status = :fra AND order_id IS NULL",
        ['til' => $til, 'p' => $produktId, 'fra' => $fra]
    );
    revider('handleliste_' . $handling, 'product', $produktId);
    Svar::ok(handleliste_bilde());
}

if ($handling === 'pris') {
    $produktId = Foresporsel::heltall('produktId');
    $kroner = (float) str_replace(',', '.', Foresporsel::tekst('kroner'));
    if ($kroner < 0 || $kroner > 100000) {
        Svar::feil('Prisen må være mellom null og 100 000 kroner.');
    }
    DB::kjor(
        "UPDATE handleliste_linjer SET pris_ore = :pris
          WHERE product_id = :p AND status = 'sendt' AND order_id IS NULL",
        ['pris' => (int) round($kroner * 100), 'p' => $produktId]
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
            "SELECT h.id, h.antall, h.pris_ore, p.tittel, p.id AS pid
               FROM handleliste_linjer h JOIN products p ON p.id = h.product_id
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
                    'product_id' => (int) $l['pid'],
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
                'Handleliste — Lissom Keramikk',
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

    $perMedlem = [];
    foreach ($linjer as $l) {
        $perMedlem[(int) $l['mid']]['navn'] = handleliste_fornavn((string) $l['medlemsnavn']);
        $perMedlem[(int) $l['mid']]['linjer'][] = $l;
    }

    $nummer = 'B-' . gmdate('ym') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
    [$emne, $tekst, $html] = handleliste_bestilling($nummer, (string) $lev['navn'], $perMedlem);

    Varsel::epost((string) $lev['epost'], $emne, $tekst, 'leverandor', $leverandorId, 'system', $html);
    DB::kjor(
        'UPDATE handleliste_linjer SET bestilt_at = NOW(), status = \'ferdig\' WHERE id IN ('
        . implode(',', array_map(static fn($l) => (int) $l['id'], $linjer)) . ')'
    );
    revider('handleliste_bestilt', 'leverandor', $leverandorId, ['nummer' => $nummer, 'linjer' => count($linjer)]);

    Svar::ok(handleliste_bilde() + ['bestilling' => $nummer, 'til' => (string) $lev['epost']]);
}

Svar::feil('Ukjent handling.');

/**
 * Bestillingen, som e-post.
 *
 * Delt opp per medlem, med navnet over varene deres. Merkingen staar én gang
 * oeverst framfor paa hver linje — eieren, 14. september: «be om at hver
 * bestilling merkes med navn».
 *
 * @param array<int,array{navn:string,linjer:list<array<string,mixed>>}> $perMedlem
 * @return array{0:string,1:string,2:string}
 */
function handleliste_bestilling(string $nummer, string $leverandor, array $perMedlem): array
{
    $emne = 'Bestilling ' . $nummer . ' — Lissom Keramikk & Håndverk';
    $apning = 'Hei! Vi vil gjerne bestille varene under. Bestillingen er delt opp'
            . ' per person — vi ber om at hver bestilling pakkes for seg og merkes med navnet.';
    $slutt  = 'Gi beskjed om noe ikke er på lager, så tar vi det ut av bestillingen.';

    $tekst = $apning . "\n\n";
    foreach ($perMedlem as $m) {
        $tekst .= $m['navn'] . "\n";
        foreach ($m['linjer'] as $l) {
            $tekst .= '  ' . str_pad((string) $l['artikkelnr'], 10) . ' ' . $l['tittel']
                    . ' — ' . (int) $l['antall'] . " stk\n";
        }
        $tekst .= "\n";
    }
    $tekst .= $slutt . "\n\nVennlig hilsen\nLissom Keramikk & Håndverk";

    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $bolker = '';
    foreach ($perMedlem as $m) {
        $bolker .= '<tr><td style="padding:16px 22px 4px 22px;font-family:Georgia,serif;font-size:16px;'
                 . 'font-weight:bold;color:#4D1D12;border-top:2px solid #E8DBC8">' . $e($m['navn']) . '</td></tr>';
        foreach ($m['linjer'] as $l) {
            $bolker .= '<tr><td style="padding:4px 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#2E1002">'
                     . '<span style="color:#7A6558">' . $e((string) $l['artikkelnr']) . '</span> &nbsp; '
                     . $e((string) $l['tittel'])
                     . ' &nbsp;—&nbsp; <b>' . (int) $l['antall'] . ' stk</b></td></tr>';
        }
    }

    $html = '<!DOCTYPE html><html lang="no"><head><meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($emne) . '</title></head>'
      . '<body style="margin:0;padding:0;background-color:#FBF6EE">'
      . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#FBF6EE">'
      . '<tr><td align="center" style="padding:32px 16px">'
      . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background-color:#FFFFFF;border:1px solid #E8DBC8;border-radius:12px">'
      . '<tr><td style="padding:22px 22px 6px 22px;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:2px;'
      . 'text-transform:uppercase;color:#A2502B;font-weight:bold">Lissom Keramikk &amp; Håndverk</td></tr>'
      . '<tr><td style="padding:0 22px 16px 22px;font-family:Georgia,serif;font-size:22px;font-weight:bold;color:#4D1D12">'
      . 'Bestilling ' . $e($nummer) . '</td></tr>'
      . '<tr><td style="padding:0 22px 16px 22px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#2E1002;'
      . 'border-bottom:1px solid #E8DBC8">Til ' . $e($leverandor) . '<br>Dato ' . date('d.m.Y')
      . '<br>Leveres til Nordre Løkkevei 15, 3120 Nøtterøy</td></tr>'
      . '<tr><td style="padding:18px 22px 0 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:23px;color:#2E1002">'
      . $e($apning) . '</td></tr>'
      . $bolker
      . '<tr><td style="padding:20px 22px 0 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:23px;color:#2E1002;'
      . 'border-top:2px solid #E8DBC8">' . $e($slutt) . '</td></tr>'
      . '<tr><td style="padding:14px 22px 24px 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#2E1002">'
      . 'Vennlig hilsen<br>Lissom Keramikk &amp; Håndverk</td></tr>'
      . '</table></td></tr></table></body></html>';

    return [$emne, $tekst, $html];
}
