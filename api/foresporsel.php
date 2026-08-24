<?php
/**
 * Foresporsler fra nettsiden.
 *
 * Aapent endepunkt med vilje: den som vil sporre om et utdrikningslag eller en
 * skoleklasse skal ikke tvinges gjennom innlogging forst. Derfor ogsaa
 * ratebegrensning, og lengdegrenser paa alt.
 *
 * Verkstedet faar den paa e-post med en gang, og den blir liggende i admin til
 * den er besvart. E-post alene er ikke nok: den drukner.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

// Mine egne henvendelser, med svarene.
//
// Et medlem som skrev til Monica fikk «Du faar svar paa e-post», og saa
// ingenting mer der de skrev. Svaret gikk ut, men samtalen fantes ikke paa
// Min side. Her henter vi den innloggedes egne henvendelser og det som er
// svart paa dem.
if (Foresporsel::metode() === 'GET') {
    $medlem = krev_medlem();
    $epost = trim((string) ($medlem['epost'] ?? ''));
    $tlf   = normaliser_telefon((string) ($medlem['telefon'] ?? ''));
    if ($epost === '' && $tlf === '') {
        Svar::json(['samtaler' => []]);
    }

    $mine = DB::alle(
        'SELECT id, type, melding, status, created_at
           FROM enquiries
          WHERE (epost <> \'\' AND epost = :e) OR (telefon <> \'\' AND telefon = :t)
       ORDER BY id DESC
          LIMIT 20',
        ['e' => $epost, 't' => $tlf]
    );

    $svar = [];
    if ($mine !== [] && DB::harTabell('foresporsel_svar')) {
        $ider = implode(',', array_map(static fn($r) => (int) $r['id'], $mine));
        foreach (DB::alle(
            'SELECT enquiry_id, tekst, created_at FROM foresporsel_svar
              WHERE enquiry_id IN (' . $ider . ') ORDER BY id'
        ) as $r) {
            $svar[(int) $r['enquiry_id']][] = $r;
        }
    }

    $oslo = new DateTimeZone('Europe/Oslo');
    $naar = static fn(string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone($oslo)->format('j.n. H:i');

    Svar::json(['samtaler' => array_map(static fn(array $f): array => [
        'id'      => (int) $f['id'],
        'hva'     => (string) ($f['type'] ?? 'Melding'),
        'tekst'   => (string) $f['melding'],
        'tid'     => $naar((string) $f['created_at']),
        'besvart' => $f['status'] === 'besvart',
        'svar'    => array_map(static fn(array $r): array => [
            'tekst' => (string) $r['tekst'],
            'tid'   => $naar((string) $r['created_at']),
        ], $svar[(int) $f['id']] ?? []),
    ], $mine)]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
Rate::sjekk('foresporsel', maks: 5, vindu: 3600);

$navn    = mb_substr(Foresporsel::tekst('navn'), 0, 191);
$kontakt = mb_substr(Foresporsel::tekst('kontakt'), 0, 191);
$type    = mb_substr(Foresporsel::tekst('type'), 0, 64);
$antall  = mb_substr(Foresporsel::tekst('antall'), 0, 32);
$melding = mb_substr(Foresporsel::tekst('melding'), 0, 2000);

// Er avsenderen innlogget, vet vi hvem det er. Da skal vi ikke be dem skrive
// inn navn og e-post én gang til — og et medlem som sender beskjed fra Min
// side har ikke noe kontaktfelt aa fylle ut i det hele tatt. Uten dette ble
// hver eneste beskjed fra Min side avvist med «fyll inn navn og e-post».
$avsender = Sesjon::medlem();
if ($avsender !== null) {
    if ($navn === '') {
        $navn = (string) $avsender['navn'];
    }
    if ($kontakt === '') {
        $kontakt = (string) ($avsender['epost'] ?: $avsender['telefon'] ?: '');
    }
}

if ($navn === '' || $kontakt === '') {
    Svar::feil('Fyll inn navn og e-post eller telefon, så kan vi svare deg.');
}

// Folk skriver enten e-post eller telefon i det samme feltet. Vi tar imot
// begge deler framfor aa be dem gjette hva vi vil ha.
$erEpost = filter_var($kontakt, FILTER_VALIDATE_EMAIL) !== false;
$telefon = $erEpost ? '' : normaliser_telefon($kontakt);

if (!$erEpost && $telefon === '') {
    Svar::feil('Kontaktopplysningen ser ikke ut som en e-postadresse eller et telefonnummer.');
}

$id = DB::settInn('enquiries', [
    'navn'    => $navn,
    'epost'   => $erEpost ? $kontakt : null,
    'telefon' => $telefon !== '' ? $telefon : null,
    'type'    => $type !== '' ? $type : null,
    'antall'  => $antall !== '' ? $antall : null,
    'melding' => $melding !== '' ? $melding : null,
    'ip'      => Foresporsel::ipBinaer(),
]);

$oppsummering = "Navn: {$navn}\n"
    . 'Kontakt: ' . $kontakt . "\n"
    . ($type !== '' ? "Type: {$type}\n" : '')
    . ($antall !== '' ? "Antall: {$antall}\n" : '')
    . "\n" . ($melding !== '' ? $melding : 'Ingen detaljer oppgitt.') . "\n\n"
    . 'Se den under Admin → Beskjeder på ' . Config::nettsted() . '/admin/beskjeder';

Varsel::epost(
    (string) Config::hent('varsel_epost', 'monica@lissom.no'),
    'Ny forespørsel fra ' . $navn,
    $oppsummering,
    'enquiry',
    $id
);

if ($erEpost) {
    Varsel::epost(
        $kontakt,
        'Vi har fått forespørselen din',
        "Hei {$navn},\n\nTakk for at du tok kontakt. Vi ser på forespørselen og svarer "
        . "så snart vi kan — som regel samme dag.\n\nDette er det du sendte oss:\n\n"
        . ($melding !== '' ? $melding : 'Ingen detaljer oppgitt.')
        . "\n\nHilsen Lissom Keramikk & Håndverk\nNordre Løkkevei 15, 3120 Nøtterøy\n+47 94 13 46 01",
        'enquiry',
        $id
    );
}

revider('foresporsel_mottatt', 'enquiry', $id, ['type' => $type]);

Svar::ok(['id' => $id, 'beskjed' => 'Takk! Vi svarer så snart vi kan.']);
