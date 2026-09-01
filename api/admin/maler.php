<?php
/**
 * Malene for alt som sendes ut.
 *
 *   GET                      alle malene, med feltene hver av dem kan bruke
 *   POST handling=lagre      { navn, emne, tekst, aktiv }
 *   POST handling=slett      { navn, bekreftet? }
 *
 * ── Hvorfor denne finnes ─────────────────────────────────────────────
 *
 * Eieren, 1. september: «hvorfor kan ikke alle vaere redigerbare? og ligge i
 * et eget kort paa oversikt som heter maler».
 *
 * Tekstene laa to steder: ni i «notification_templates», tjue skrevet rett
 * inn i PHP-en. Men selv de ni kunne ingen endre — lista paa varselskjermen
 * var designdata, og dialogen viste teksten uten aa lagre den. Det fantes
 * ikke noe endepunkt i det hele tatt.
 *
 * Migrasjon 112 flyttet de tjue inn i tabellen. Dette er endepunktet som gjor
 * dem redigerbare.
 *
 * ── Feltene ──────────────────────────────────────────────────────────
 *
 * Eieren: «jeg vil ha en oversikt over komandoer som jeg kan kopiere, slike
 * som denne {varelinjer} slik at jeg faktisk kan legge inne selv».
 *
 * Hver mal har sine egne. «{varelinjer}» finnes i butikkbestillingen og ingen
 * andre steder; skrives den i velkomstbrevet, staar den igjen som raa tekst i
 * e-posten kunden faar. Derfor foelger lista med hver enkelt mal, og
 * lagringen sier fra om et felt som ikke finnes.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (!DB::harTabell('notification_templates')) {
    Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.', 503);
}

$harGruppe = DB::harKolonne('notification_templates', 'gruppe');

/** Malene koden faktisk kaller. De kan slettes, men bare med et uttrykkelig ja. */
$IBRUK = Maler::iBruk();

$hent = static function () use ($harGruppe, $IBRUK): array {
    $rader = DB::alle('SELECT * FROM notification_templates ORDER BY navn');
    return array_map(static function (array $m) use ($harGruppe, $IBRUK): array {
        $navn = (string) $m['navn'];
        return [
            'navn'    => $navn,
            'tittel'  => Maler::tittel($navn),
            'kanal'   => (string) $m['kanal'],
            'gruppe'  => $harGruppe ? (string) $m['gruppe'] : 'system',
            'emne'    => (string) ($m['emne'] ?? ''),
            'tekst'   => (string) $m['tekst'],
            'aktiv'   => (int) $m['aktiv'] === 1,
            // Sendes den av koden, kan den ikke slettes — da ville meldingen
            // stilltiende sluttet aa gaa ut.
            'iBruk'   => in_array($navn, $IBRUK, true),
            'hvor'    => Maler::hvor($navn),
            'felter'  => Maler::felter($navn),
        ];
    }, $rader);
};

if (Foresporsel::metode() === 'GET') {
    Svar::json(['maler' => $hent()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');
$navn = mb_substr(Foresporsel::tekst('navn'), 0, 64);
if ($navn === '') {
    Svar::feil('Hvilken mal?');
}
$mal = DB::en('SELECT * FROM notification_templates WHERE navn = :n', ['n' => $navn]);
if ($mal === null) {
    Svar::feil('Fant ikke malen.', 404);
}

// ── Slett ────────────────────────────────────────────────────────────
//
// En mal koden kaller kan ikke slettes. Varsel::mal() skriver en linje i
// loggen og gaar videre naar malen mangler — altsaa ville en slettet
// butikkbekreftelse betydd at ingen kunder fikk kvittering, uten at noe sa
// fra. Den kan slaas av i stedet, og da er det et valg noen har tatt.
if ($handling === 'slett') {
    // Her sto en sperre: maler koden kaller kunne ikke slettes, bare slaas
    // av. Eieren, 1. september: «jeg oensker mulighet til aa slette de malene
    // jeg selv vil».
    //
    // Han faar det. En mal som sendes automatisk krever likevel et
    // uttrykkelig ja, for foelgen er at meldingen slutter aa gaa ut — en
    // kunde som bestiller faar ingen kvittering, og ingenting sier fra.
    // Skjermen spoer foerst, og sender «bekreftet» hit.
    $sendesAutomatisk = in_array($navn, $IBRUK, true);
    if ($sendesAutomatisk && Foresporsel::tekst('bekreftet') !== 'ja') {
        Svar::feil(Maler::tittel($navn) . ' sendes automatisk av systemet. Slettes den, '
            . 'slutter meldingen å gå ut — bekreft at det er det du vil.', 409);
    }
    DB::kjor('DELETE FROM notification_templates WHERE navn = :n', ['n' => $navn]);
    revider('mal_slettet', 'mal', null, ['navn' => $navn, 'sendes_automatisk' => $sendesAutomatisk]);
    Svar::ok(['maler' => $hent(), 'beskjed' => Maler::tittel($navn) . ' er slettet.'
        . ($sendesAutomatisk ? ' Meldingen går ikke lenger ut.' : '')]);
}

if ($handling !== 'lagre') {
    Svar::feil('Ukjent handling.');
}

// ── Lagre ────────────────────────────────────────────────────────────
$emne  = mb_substr(Foresporsel::tekst('emne'), 0, 191);
$tekst = trim(Foresporsel::tekst('tekst'));
$aktiv = Foresporsel::tekst('aktiv') === 'nei' ? 0 : 1;

if ($tekst === '') {
    Svar::feil('Teksten kan ikke være tom. Skal malen ikke sendes, slå den av i stedet.');
}
if (mb_strlen($tekst) > 20000) {
    Svar::feil('Teksten er for lang.');
}

// Et felt som ikke finnes staar igjen som «{varelinjer}» i e-posten kunden
// faar. Da er det bedre aa si fra her.
$kjente = array_column(Maler::felter($navn), 'felt');
preg_match_all('/\{([a-zA-Z_]+)\}/', $emne . ' ' . $tekst, $funn);
$ukjente = array_values(array_unique(array_diff($funn[1], $kjente)));
if ($ukjente !== []) {
    Svar::feil('Denne malen kjenner ikke {' . implode('}, {', $ukjente) . '}. '
        . ($kjente === []
            ? 'Den har ingen felter — skriv teksten uten krøllparenteser.'
            : 'Den kan bruke: {' . implode('}, {', $kjente) . '}.'));
}

DB::oppdater('notification_templates', [
    'emne'  => $emne !== '' ? $emne : null,
    'tekst' => $tekst,
    'aktiv' => $aktiv,
], ['navn' => $navn]);

revider('mal_endret', 'mal', null, ['navn' => $navn, 'aktiv' => $aktiv]);
Svar::ok(['maler' => $hent(), 'beskjed' => Maler::tittel($navn) . ' er lagret.']);
