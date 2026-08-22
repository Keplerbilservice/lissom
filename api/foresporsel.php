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
