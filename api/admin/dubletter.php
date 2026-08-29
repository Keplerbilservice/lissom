<?php

declare(strict_types=1);

/**
 * Dubletter i medlemslista: finn dem, og slaa dem sammen.
 *
 *   GET                          gruppene som ser ut til aa vaere samme person
 *   POST handling=slaa-sammen    { behold, fjern }
 *
 * ── Hvorfor dette finnes ──────────────────────────────────────────────
 *
 * Den samme personen ligger flere ganger: hun booket et kurs som gjest med
 * e-posten sin, meldte seg inn senere med telefonen, og logget inn med Vipps
 * en tredje gang. Da staar timene paa én rad, kursbevisene paa en annen, og
 * ingen av dem viser hele mennesket. «Slettes for haand under Medlemmer» sto
 * det i de aapne punktene — men aa slette den ene er aa miste historikken
 * hennes, ikke aa rydde.
 *
 * ── Hva som regnes som samme person ───────────────────────────────────
 *
 * To niveauer, og de behandles ulikt:
 *
 *   sikker  Samme e-post, eller samme telefonnummer. To personer deler ikke
 *           innboks eller mobil.
 *   mulig   Bare samme navn. «Anne Hansen» er ikke ett menneske, og disse
 *           staar derfor som forslag som maa leses foer de slaas sammen.
 *
 * Ingenting slaas sammen av seg selv. Verktoeyet peker; mennesket bestemmer.
 *
 * ── Hva sammenslaaingen gjor ──────────────────────────────────────────
 *
 * Alt som peker paa den som fjernes, flyttes til den som beholdes — femten
 * tabeller peker hit, fra bookinger og betalinger til innstemplinger og
 * gaver. Lista bygges av «information_schema», ikke skrevet av for haand:
 * den som legger til en tabell neste gang skal ikke trenge aa huske denne
 * fila.
 *
 * Raden som blir igjen slettes ikke. Den anonymiseres, som ved vanlig
 * sletting — bookinger og betalinger er bokforingspliktige, og en
 * fremmednoekkel som peker paa ingenting er verre enn en tom rad.
 *
 * To tabeller kan ikke ta imot alt: «medlemsgave_bruk» har én rad per gave
 * per medlem, og «verksted_notater» ett notat per medlem. Kraesjer det,
 * flyttes det som kan flyttes, resten blir staaende — og svaret sier hvor
 * mange rader det gjaldt. Ingenting slettes for aa gjore plass.
 */

require __DIR__ . '/../_boot.php';

$jeg = krev_admin();

/** @return list<array{tabell:string,kolonne:string}> */
function medlemspekere(): array
{
    $ut = [];
    foreach (DB::alle(
        "SELECT table_name AS t, column_name AS k
           FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND column_name IN ('member_id', 'registrert_av')
            AND table_name <> 'members'
       ORDER BY table_name, column_name"
    ) as $r) {
        $ut[] = ['tabell' => (string) $r['t'], 'kolonne' => (string) $r['k']];
    }
    return $ut;
}

// ---------------------------------------------------------------- lesing
if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle(
        "SELECT id, navn, epost, telefon, rolle, status, medlemskap_type,
                start_dato, created_at, siste_innlogging, vipps_sub
           FROM members
          WHERE anonymisert_at IS NULL
       ORDER BY id"
    );

    $navnNok = static fn(string $n): string
        => trim(preg_replace('/\s+/u', ' ', mb_strtolower($n)) ?? '');
    $epostNok = static fn(?string $e): string => trim(mb_strtolower((string) $e));

    // Grupper paa hver noekkel for seg, saa vi vet HVORFOR to hoerer sammen.
    $grupper = [];
    $legg = static function (string $nokkel, string $slag, int $id) use (&$grupper, &$plassholder): void {
        $grupper[$slag . ':' . $nokkel]['slag'] = $slag;
        $grupper[$slag . ':' . $nokkel]['ider'][$id] = true;
        $grupper[$slag . ':' . $nokkel]['plassholder'] = isset($plassholder[$nokkel]);
    };
    // Et nummer eller en adresse mange deler er som regel en plassholder —
    // verkstedets eget nummer skrevet inn paa gjestepaameldinger, eller
    // «post@» paa alle. Da er det ikke ett menneske, og gruppa skal ikke staa
    // som et sikkert funn. Fire rader er romslig for en ekte dublett.
    $mange = 4;
    $antallPer = ['epost' => [], 'telefon' => []];
    foreach ($rader as $r) {
        $e0 = $epostNok($r['epost']);
        $t0 = normaliser_telefon((string) ($r['telefon'] ?? ''));
        if ($e0 !== '') {
            $antallPer['epost'][$e0] = ($antallPer['epost'][$e0] ?? 0) + 1;
        }
        if ($t0 !== '' && $t0 !== '+') {
            $antallPer['telefon'][$t0] = ($antallPer['telefon'][$t0] ?? 0) + 1;
        }
    }
    $plassholder = [];

    foreach ($rader as $r) {
        $id = (int) $r['id'];
        $e = $epostNok($r['epost']);
        $t = normaliser_telefon((string) ($r['telefon'] ?? ''));
        $n = $navnNok((string) $r['navn']);
        if ($e !== '') {
            if (($antallPer['epost'][$e] ?? 0) > $mange) {
                $plassholder[$e] = true;
            }
            $legg($e, 'epost', $id);
        }
        if ($t !== '' && $t !== '+') {
            if (($antallPer['telefon'][$t] ?? 0) > $mange) {
                $plassholder[$t] = true;
            }
            $legg($t, 'telefon', $id);
        }
        // Navn alene er et forslag, ikke et funn. «Slettet medlem» er ikke et
        // navn — det er hva anonymiseringen skriver, og alle deler det.
        if ($n !== '' && $n !== 'slettet medlem' && $n !== 'gjest') {
            $legg($n, 'navn', $id);
        }
    }

    // Hva som henger paa hvert medlem. Det er dette som avgjor hvem som skal
    // beholdes — den med historikk, ikke den som ble laget sist.
    $tell = static function (string $sql): array {
        $ut = [];
        foreach (DB::alle($sql) as $r) {
            $ut[(int) $r['m']] = (int) $r['n'];
        }
        return $ut;
    };
    $bookinger = $tell('SELECT member_id AS m, COUNT(*) AS n FROM bookings
                         WHERE member_id IS NOT NULL GROUP BY member_id');
    $betalinger = $tell('SELECT member_id AS m, COUNT(*) AS n FROM payments
                          WHERE member_id IS NOT NULL GROUP BY member_id');
    $stemplinger = DB::harTabell('check_ins')
        ? $tell('SELECT member_id AS m, COUNT(*) AS n FROM check_ins GROUP BY member_id')
        : [];
    $avtaler = $tell('SELECT member_id AS m, COUNT(*) AS n FROM subscriptions GROUP BY member_id');

    $etMedlem = static function (array $r) use ($bookinger, $betalinger, $stemplinger, $avtaler): array {
        $id = (int) $r['id'];
        return [
            'id'         => $id,
            'navn'       => (string) $r['navn'],
            'epost'      => (string) ($r['epost'] ?? ''),
            'telefon'    => (string) ($r['telefon'] ?? ''),
            'erAdmin'    => $r['rolle'] === 'admin',
            'status'     => (string) $r['status'],
            'medlemskap' => (string) ($r['medlemskap_type'] ?? ''),
            'harVipps'   => trim((string) ($r['vipps_sub'] ?? '')) !== '',
            'laget'      => Booking::norskDatoKort((string) $r['created_at']),
            'sistInne'   => $r['siste_innlogging']
                            ? Booking::norskDatoKort((string) $r['siste_innlogging']) : '',
            'bookinger'  => $bookinger[$id] ?? 0,
            'betalinger' => $betalinger[$id] ?? 0,
            'timer'      => $stemplinger[$id] ?? 0,
            'avtaler'    => $avtaler[$id] ?? 0,
        ];
    };
    $etterId = [];
    foreach ($rader as $r) {
        $etterId[(int) $r['id']] = $r;
    }

    // Slaa sammen grupper som deler medlemmer: er A og B samme e-post, og B og
    // C samme telefon, er alle tre samme person. Uten dette maatte man slaatt
    // sammen to ganger og sett den tredje dukke opp igjen etterpaa.
    $samlet = [];
    foreach ($grupper as $g) {
        $ider = array_keys($g['ider']);
        if (count($ider) < 2) {
            continue;
        }
        $traff = null;
        foreach ($samlet as $i => $s) {
            if (array_intersect($ider, $s['ider']) !== []) {
                $traff = $i;
                break;
            }
        }
        if ($traff === null) {
            $samlet[] = ['slag' => [$g['slag']], 'ider' => $ider,
                         'plass' => !empty($g['plassholder'])];
            continue;
        }
        $samlet[$traff]['plass'] = ($samlet[$traff]['plass'] ?? false)
                                   || !empty($g['plassholder']);
        $samlet[$traff]['ider'] = array_values(array_unique(
            array_merge($samlet[$traff]['ider'], $ider)
        ));
        $samlet[$traff]['slag'][] = $g['slag'];
    }

    $ut = [];
    foreach ($samlet as $s) {
        $slag = array_values(array_unique($s['slag']));
        // Er de bare like i navnet, er det et forslag. Deler de e-post eller
        // telefon, er det samme person.
        $sikker = (in_array('epost', $slag, true) || in_array('telefon', $slag, true))
                  && empty($s['plass']);
        $medlemmer = [];
        foreach ($s['ider'] as $id) {
            if (isset($etterId[$id])) {
                $medlemmer[] = $etMedlem($etterId[$id]);
            }
        }
        if (count($medlemmer) < 2) {
            continue;
        }
        // Hvem foreslaas beholdt: mest historikk, saa eldst. Den eldste raden
        // er den kunden selv har brukt lengst.
        usort($medlemmer, static function (array $a, array $b): int {
            $vekt = static fn(array $m): int => $m['bookinger'] * 4 + $m['betalinger'] * 4
                                              + $m['avtaler'] * 8 + $m['timer']
                                              + ($m['erAdmin'] ? 1000 : 0);
            return $vekt($b) <=> $vekt($a) ?: $a['id'] <=> $b['id'];
        });
        $ut[] = [
            'nokkel'    => implode('-', array_column($medlemmer, 'id')),
            'sikker'    => $sikker,
            'grunn'     => $sikker
                ? ('Samme ' . implode(' og samme ', array_map(
                    static fn(string $s2): string => $s2 === 'epost' ? 'e-post' : $s2,
                    array_values(array_filter($slag, static fn($x) => $x !== 'navn'))
                  )) . '.')
                : (!empty($s['plass'])
                    ? 'Deler e-post eller telefon med mange andre rader — det er som regel '
                      . 'et nummer eller en adresse som er skrevet inn som plassholder, ikke én person. '
                      . 'Les gjennom før du slår dem sammen.'
                    : 'Samme navn. Sjekk at det faktisk er den samme personen før du slår dem sammen.'),
            'medlemmer' => $medlemmer,
        ];
    }
    // Sikre funn foerst — de er det som faktisk skal ryddes.
    usort($ut, static fn(array $a, array $b): int => ($b['sikker'] <=> $a['sikker'])
        ?: ($a['medlemmer'][0]['navn'] <=> $b['medlemmer'][0]['navn']));

    Svar::json([
        'grupper' => $ut,
        'sikre'   => count(array_filter($ut, static fn($g) => $g['sikker'])),
        'mulige'  => count(array_filter($ut, static fn($g) => !$g['sikker'])),
        'tabeller' => count(medlemspekere()),
    ]);
}

// ------------------------------------------------------------- skriving
Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (Foresporsel::tekst('handling') !== 'slaa-sammen') {
    Svar::feil('Ukjent handling.');
}

$behold = Foresporsel::heltall('behold');
$fjern  = Foresporsel::heltall('fjern');

if ($behold <= 0 || $fjern <= 0 || $behold === $fjern) {
    Svar::feil('Velg hvilken rad som skal beholdes, og hvilken som skal slås sammen inn i den.');
}

$a = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $behold]);
$b = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $fjern]);
if ($a === null || $b === null) {
    Svar::feil('Fant ikke begge medlemmene.', 404);
}
if ($b['anonymisert_at'] !== null) {
    Svar::feil('Den raden er alt ryddet bort.');
}
// En admin slaas ikke bort. Er begge admin, maa den som skal bort settes ned
// til vanlig medlem forst — det er et bevisst valg, ikke noe dette gjor selv.
if ($b['rolle'] === 'admin') {
    Svar::feil('Den raden er en administrator. Endre rollen først hvis den skal slås sammen inn i en annen.');
}
if ((int) $b['id'] === (int) $jeg['id']) {
    Svar::feil('Du kan ikke slå sammen din egen konto inn i en annen.');
}

// Alt eller ingenting.
//
// Uten transaksjonen kunne dette stanse midtveis: foerste forsoek flyttet
// innstemplingene og falt saa paa en unik noekkel — bookingene sto paa den
// nye raden, mens den gamle fortsatt sto som et aktivt medlem. Halvveis er
// verre enn ikke gjort, for da vet ingen hvor historikken er.
[$flyttet, $staarIgjen, $fyll] = DB::iTransaksjon(static function () use ($behold, $fjern, $a, $b): array {

// Flyttingen. Hver tabell for seg, saa én som ikke tar imot ikke stopper
// resten — og saa vi kan si hva som ble staaende igjen.
$flyttet = [];
$staarIgjen = [];
foreach (medlemspekere() as $p) {
    $t = $p['tabell'];
    $k = $p['kolonne'];
    // Navnene kommer fra information_schema, ikke fra en forespoersel.
    if (!preg_match('/^[a-z_]+$/', $t) || !preg_match('/^[a-z_]+$/', $k)) {
        continue;
    }
    $for = (int) DB::verdi("SELECT COUNT(*) FROM `{$t}` WHERE `{$k}` = :i", ['i' => $fjern]);
    if ($for === 0) {
        continue;
    }
    // «IGNORE»: to tabeller har en unik noekkel som inkluderer medlemmet, og
    // da kan raden ikke flyttes. Den blir staaende framfor aa bli slettet.
    DB::kjor("UPDATE IGNORE `{$t}` SET `{$k}` = :ny WHERE `{$k}` = :gml",
             ['ny' => $behold, 'gml' => $fjern]);
    $etter = (int) DB::verdi("SELECT COUNT(*) FROM `{$t}` WHERE `{$k}` = :i", ['i' => $fjern]);
    if ($for - $etter > 0) {
        $flyttet[$t . '.' . $k] = $for - $etter;
    }
    if ($etter > 0) {
        $staarIgjen[$t . '.' . $k] = $etter;
    }
}

// Det som mangler paa raden vi beholder, fylles fra den vi rydder bort. En
// gjesterad har ofte telefonnummeret medlemsraden mangler.
$fyll = [];
foreach (['epost', 'telefon', 'medlemskap_type', 'timer_per_mnd', 'start_dato',
          'vipps_sub', 'recurring_agreement_id'] as $felt) {
    $harA = $a[$felt] !== null && trim((string) $a[$felt]) !== '';
    $harB = $b[$felt] !== null && trim((string) $b[$felt]) !== '';
    if (!$harA && $harB) {
        $fyll[$felt] = $b[$felt];
    }
}
// Notatene slaas sammen framfor at det ene skriver over det andre.
$notatB = trim((string) ($b['notat'] ?? ''));
if ($notatB !== '') {
    $notatA = trim((string) ($a['notat'] ?? ''));
    $fyll['notat'] = $notatA === '' ? $notatB : ($notatA . "\n\n" . $notatB);
}
// Rekkefolgen er ikke likegyldig. «vipps_sub», «brukernavn» og
// «recurring_agreement_id» er unike i members: kopierte vi dem over foer den
// gamle raden var toemt, kolliderte de med seg selv, og hele
// sammenslaaingen falt med en raa SQL-feil midt i — etter at bookingene alt
// var flyttet. Derfor toemmes den gamle raden foerst, og verdiene vi tok
// vare paa over settes inn etterpaa.
//
// Raden slettes ikke; den anonymiseres, som ved vanlig sletting. Sesjonene
// fjernes saa ingen kan logge inn paa den.
DB::kjor('DELETE FROM sessions WHERE member_id = :i', ['i' => $fjern]);
DB::oppdater('members', [
    'navn'            => 'Slått sammen',
    'epost'           => null,
    'telefon'         => null,
    'vipps_sub'       => null,
    'brukernavn'      => null,
    'passord_hash'    => null,
    'recurring_agreement_id' => null,
    'status'          => 'ingen',
    'notat'           => 'Slått sammen med medlem ' . $behold . ' ' . date('d.m.Y') . '.',
    'anonymisert_at'  => gmdate('Y-m-d H:i:s'),
], ['id' => $fjern]);

if ($fyll !== []) {
    DB::oppdater('members', $fyll, ['id' => $behold]);
}

    return [$flyttet, $staarIgjen, $fyll];
});

revider('medlemmer_slatt_sammen', 'member', $behold, [
    'fjernet'    => $fjern,
    'navn'       => (string) $b['navn'],
    'flyttet'    => $flyttet,
    'staarIgjen' => $staarIgjen,
    'fylt'       => array_keys($fyll),
    'av'         => (int) $jeg['id'],
]);

$antall = array_sum($flyttet);
$beskjed = $antall === 0
    ? 'Radene er slått sammen. Det var ingenting å flytte.'
    : ($antall === 1
        ? 'Radene er slått sammen. Én oppføring er flyttet over.'
        : 'Radene er slått sammen. ' . $antall . ' oppføringer er flyttet over.');
if ($staarIgjen !== []) {
    $beskjed .= ' ' . array_sum($staarIgjen) . ' kunne ikke flyttes fordi den samme '
              . 'oppføringen alt fantes — de står urørt på den gamle raden.';
}

Svar::ok([
    'beskjed'    => $beskjed,
    'flyttet'    => $flyttet,
    'staarIgjen' => $staarIgjen,
    'fylt'       => array_keys($fyll),
]);
