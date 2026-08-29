<?php
/**
 * Feilrapportene, for den som skal gjore noe med dem.
 *
 *   GET                        Lister, nyeste forst.
 *   POST handling=status       Merker én som lest eller lukket.
 *   POST handling=lukkAlle     Lukker alt som er lest.
 *   POST handling=bryter       Setter datoen «meld inn feil» staar paa til.
 *
 * «tekst» i listesvaret er hele bunken satt sammen som ren tekst. Den er
 * til for aa limes inn i en samtale med den som skal rette feilen — da
 * folger nettleser, skjermbredde og side med av seg selv, i stedet for aa
 * bli gjenfortalt fra hukommelsen.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if (!DB::harTabell('feilrapporter')) {
    Svar::ok(['rapporter' => [], 'nye' => 0, 'tekst' => '', 'apenTil' => '',
              'mangler' => true]);
}

$apenTil = static fn(): string => trim((string) DB::verdi(
    'SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => 'feilmelding_til']
));

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle(
        'SELECT f.*, m.navn AS medlem_navn
           FROM feilrapporter f
      LEFT JOIN members m ON m.id = f.member_id
          WHERE f.status <> :lukket
       ORDER BY f.slag = :melding DESC, f.sist_sett DESC
          LIMIT 100',
        ['lukket' => 'lukket', 'melding' => 'melding']
    );

    $kortNettleser = static function (string $ua): string {
        // Hele user agent-strengen er stoy. Det som betyr noe er hvilken
        // nettleser og hvilket operativsystem — det er der forskjellene
        // sitter, og det er dem man maa gjenskape for aa se feilen.
        $n = preg_match('~(Firefox|Edg|OPR|Chrome|Safari)/([\d]+)~', $ua, $m) === 1
            ? ($m[1] === 'Edg' ? 'Edge' : ($m[1] === 'OPR' ? 'Opera' : $m[1])) . ' ' . $m[2]
            : 'ukjent nettleser';
        $os = str_contains($ua, 'iPhone') ? 'iPhone'
            : (str_contains($ua, 'iPad') ? 'iPad'
            : (str_contains($ua, 'Android') ? 'Android'
            : (str_contains($ua, 'Mac OS X') ? 'Mac'
            : (str_contains($ua, 'Windows') ? 'Windows' : ''))));
        // Chrome paa iPhone er Safari under panseret. Sier vi «Chrome»,
        // leter man etter feilen paa feil sted.
        if (($os === 'iPhone' || $os === 'iPad') && str_starts_with($n, 'Chrome')) {
            $n = 'Safari (i Chrome-app)';
        }
        return trim($n . ($os !== '' ? ' på ' . $os : ''));
    };

    $ut = array_map(static function (array $r) use ($kortNettleser): array {
        return [
            'id'        => (int) $r['id'],
            'slag'      => (string) $r['slag'],
            'melding'   => (string) ($r['melding'] ?? ''),
            'kontakt'   => (string) ($r['kontakt'] ?? ''),
            'feiltekst' => (string) ($r['feiltekst'] ?? ''),
            'kilde'     => (string) ($r['kilde'] ?? ''),
            'side'      => (string) ($r['side'] ?? ''),
            'nettleser' => $kortNettleser((string) ($r['nettleser'] ?? '')),
            'skjerm'    => (string) ($r['skjerm'] ?? ''),
            'navn'      => (string) ($r['medlem_navn'] ?? ''),
            'rolle'     => (string) ($r['rolle'] ?? ''),
            'antall'    => (int) $r['antall'],
            'status'    => (string) $r['status'],
            'sistSett'  => (string) $r['sist_sett'],
        ];
    }, $rader);

    // Bunken som tekst. Det som staar her er det man trenger for aa lete
    // etter feilen — ikke mer, saa den kan limes inn hvor som helst.
    $linjer = [];
    foreach ($ut as $r) {
        $hode = $r['slag'] === 'melding' ? 'MELDT INN' : 'FANGET AUTOMATISK';
        if ($r['antall'] > 1) {
            $hode .= ' (' . $r['antall'] . ' ganger)';
        }
        $linjer[] = '— ' . $hode . ' ' . substr($r['sistSett'], 0, 16);
        if ($r['melding'] !== '')   { $linjer[] = '  «' . $r['melding'] . '»'; }
        if ($r['feiltekst'] !== '') { $linjer[] = '  Feil: ' . $r['feiltekst']; }
        if ($r['kilde'] !== '')     { $linjer[] = '  Kilde: ' . $r['kilde']; }
        $linjer[] = '  Side: ' . ($r['side'] !== '' ? $r['side'] : 'ukjent')
                  . ' · ' . $r['nettleser']
                  . ($r['skjerm'] !== '' ? ' · ' . $r['skjerm'] : '');
        if ($r['navn'] !== '')    { $linjer[] = '  Innlogget: ' . $r['navn']; }
        if ($r['kontakt'] !== '') { $linjer[] = '  Kontakt: ' . $r['kontakt']; }
        $linjer[] = '';
    }

    Svar::ok([
        'rapporter' => $ut,
        'nye'       => count(array_filter($ut, static fn(array $r): bool => $r['status'] === 'ny')),
        'tekst'     => $linjer === [] ? '' : "Feilrapporter fra lissom.no\n\n" . implode("\n", $linjer),
        'apenTil'   => $apenTil(),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');

if ($handling === 'bryter') {
    // Tom verdi slaar den av. Ellers en dato, og ikke lenger fram enn et
    // halvt aar — en knapp som staar paa til 2099 er ikke tidsbegrenset.
    $til = trim(Foresporsel::tekst('til'));
    if ($til !== '') {
        if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $til) !== 1) {
            Svar::feil('Skriv datoen som 2026-09-30.');
        }
        if ($til < date('Y-m-d')) {
            Svar::feil('Datoen har allerede vært.');
        }
        if ($til > date('Y-m-d', strtotime('+6 months'))) {
            Svar::feil('Sett den høyst et halvt år fram.');
        }
    }
    DB::kjor(
        'INSERT INTO innstillinger (nokkel, verdi, endret_av)
              VALUES (:n, :v, :a)
         ON DUPLICATE KEY UPDATE verdi = :v2, endret_av = :a2',
        ['n' => 'feilmelding_til', 'v' => $til, 'a' => (int) $admin['id'],
         'v2' => $til, 'a2' => (int) $admin['id']]
    );
    Config::glemBasen();
    Svar::ok(['apenTil' => $til]);
}

if ($handling === 'lukkAlle') {
    $n = DB::kjor('UPDATE feilrapporter SET status = :l WHERE status <> :l2',
                  ['l' => 'lukket', 'l2' => 'lukket'])->rowCount();
    Svar::ok(['lukket' => $n]);
}

if ($handling !== 'status') {
    Svar::feil('Ukjent handling.');
}

$id = Foresporsel::heltall('id');
$ny = Foresporsel::tekst('status');
if (!in_array($ny, ['ny', 'lest', 'lukket'], true)) {
    Svar::feil('Ukjent status.');
}
if (DB::kjor('UPDATE feilrapporter SET status = :s WHERE id = :id',
             ['s' => $ny, 'id' => $id])->rowCount() === 0) {
    Svar::feil('Fant ikke rapporten.', 404);
}

Svar::ok(['id' => $id, 'status' => $ny]);
