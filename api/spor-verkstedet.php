<?php
/**
 * Spør verkstedet — AI-en som svarer fra dokumentene, og bare fra dem.
 *
 *   GET                     hva som er tilgjengelig, og hva det har kostet
 *   POST { sporsmal, kategorier? }
 *
 * Eieren, 10. september 2026: «vil det vaere muliget aa koble til en ai faq
 * med kun denne infoen?»
 *
 * «Kun denne infoen» er hele poenget, og det er derfor dette ikke er en
 * vanlig chat: modellen faar teksten fra dokumentene og ingenting annet — ikke
 * medlemmer, ikke betalinger, ikke kurs — og har beskjed om aa si fra naar
 * svaret ikke staar der. Den skal ikke fylle hullene med noe den kan fra for.
 *
 * Ingen ny AI-kobling. Det er app/lib/ai.php som brukes, med samme noekkel,
 * samme logg og samme maanedstak som teksten under Markedsfoering.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem  = krev_medlem();
$erAdmin = Sesjon::erAdmin();

if (!$erAdmin) {
    if (!er_aktivt_medlem($medlem)) {
        Svar::feil('Denne delen er for medlemmer. Du melder deg inn fra Min side.', 403, ['ikkeMedlem' => true]);
    }
    if (!Dokumenter::faqForMedlem()) {
        Svar::feil('Fant ikke siden.', 404);
    }
}

if (!Dokumenter::klar()) {
    Svar::feil('Dette krever en oppdatering av databasen.');
}

if (Foresporsel::metode() === 'GET') {
    Svar::json([
        'kategorier' => Dokumenter::kategorier(!$erAdmin),
        'ai'         => $erAdmin ? AI::status() : ['klar' => AI::tilgjengelig()],
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$sporsmal = Foresporsel::tekst('sporsmal');
if ($sporsmal === '') {
    Svar::feil('Skriv et spørsmål først.');
}
if (mb_strlen($sporsmal) > 500) {
    Svar::feil('Spørsmålet er for langt. Prøv å si det kortere.');
}

// Hvert kall koster penger. Ti i timen per person er rikelig til aa jobbe,
// og lite nok til at en som holder knappen inne ikke tommer maanedstaket.
Rate::sjekk('spor-verkstedet', 10, 3600, 'medlem:' . (int) $medlem['id']);

// Kortene den faar lese. Et medlem naar uansett bare dem som er slaatt paa
// for medlemmer — kunnskap() sperrer for det, ikke lista som kommer inn.
$valgte = [];
foreach ((array) (Foresporsel::kropp()['kategorier'] ?? []) as $k) {
    if (is_numeric($k)) {
        $valgte[] = (int) $k;
    }
}

$kilder = Dokumenter::kunnskap(!$erAdmin, $valgte);
if ($kilder === []) {
    Svar::ok([
        'svar'   => 'Dette står ikke i dokumentene. Legg inn et dokument som svarer på det, så finner jeg det neste gang.',
        'kilder' => [],
    ]);
}

// De dokumentene som ligner mest paa spoersmaalet foerst, og bare saa mange
// som faar plass. Fram til 11. september gikk alt inn i kortenes rekkefoelge
// og ble kuttet paa 200 000 tegn — med fem haandboeker og 57
// monteringsguider ble de siste aldri lest. Se Dokumenter::utvalg().
//
// 60 000 tegn, ikke 200 000. Eieren, 11. september 2026: «Fikk ikke kontakt
// med serveren» / snurrer lenge. 200 000 tegn er rundt 55 000 tokens — det
// tar 15–40 sekunder og koster rundt 3 kr per spoersmaal. Utvalget legger de
// riktige dokumentene foerst, saa 60 000 (rundt 15 000 tokens, en fjerdedel
// av tida og prisen) holder til det spoersmaalet gjelder.
$utvalg   = Dokumenter::utvalg($kilder, $sporsmal, 60000);
$kunnskap = $utvalg['tekst'];
// Bare det modellen faktisk fikk se kan staa som kilde.
$kilder   = $utvalg['kilder'];

$system = <<<TXT
Du svarer på spørsmål om keramikkverkstedet Lissom, og du har ÉN kilde:
dokumentene som står under. Ingenting annet.

Reglene, i rekkefølge:

1. Står svaret i dokumentene, svar kort og konkret på norsk bokmål.
2. Står det ikke der, svar nøyaktig dette og ingenting mer:
   «Dette står ikke i dokumentene. Legg inn et dokument som svarer på det, så
   finner jeg det neste gang.»
3. Du skal ALDRI fylle ut med noe du kan fra før om keramikk. Er dokumentet
   uenig med det du har lært et annet sted, er det dokumentet som gjelder.
4. Gjetter du, er svaret feil. En som står i verkstedet med en glasurbøtte
   skal kunne stole på det du sier.

Svar med JSON og ingenting annet, på formen:
{"svar": "...", "kilder": ["Navnet på dokumentet", "..."]}

«kilder» er navnene på de dokumentene svaret faktisk kom fra — ikke alle.
Fant du ikke svaret, skal «kilder» være en tom liste.
TXT;

$bruker = "DOKUMENTENE:\n\n" . $kunnskap . "\n\n---\n\nSPØRSMÅLET:\n" . $sporsmal;

// Kallet til modellen kan ta lenger enn webhotellets 30 sekunder. Da doede
// PHP midt i, og skjermen sa «Fikk ikke kontakt med serveren» — selv om
// svaret var paa vei. Se ogsaa max_execution_time i .user.ini.
@set_time_limit(150);

try {
    $data = AI::sporJson($system, $bruker, 'Spør verkstedet', 1500);
} catch (RuntimeException $e) {
    Svar::feil($e->getMessage());
}

$svar = trim((string) ($data['svar'] ?? ''));
if ($svar === '') {
    $svar = 'Dette står ikke i dokumentene. Legg inn et dokument som svarer på det, så finner jeg det neste gang.';
}

// Bare kilder som faktisk finnes blant dem den fikk se. Modellen kan finne
// paa et navn; da skal det ikke staa under svaret som om det var et dokument.
$kjente = array_column($kilder, 'navn');
$brukte = [];
foreach ((array) ($data['kilder'] ?? []) as $k) {
    $n = trim((string) $k);
    if ($n !== '' && in_array($n, $kjente, true) && !in_array($n, $brukte, true)) {
        $brukte[] = $n;
    }
}

Svar::ok([
    'svar'    => $svar,
    'kilder'  => $brukte,
    'kostnad' => $erAdmin ? Booking::kroner(AI::sisteKostnad()) : null,
]);
