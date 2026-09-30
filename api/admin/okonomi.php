<?php
/**
 * Okonomiskjermen.
 *
 * Ukesfordelingen, hva pengene kom fra, og hvilke betalingsinnstillinger som
 * faktisk staar i secrets.php. Alt sto som fast tekst i designfila og var
 * tomt paa den ekte siden — «kr. 96 200,- fra kurs og events» uansett hvor
 * mye som var solgt, og et Tripletex-kort som sa «Tilkoblet» uten at det
 * finnes noen Tripletex-kobling.
 *
 * Alle grenser regnes i norsk tid og gjores om til UTC. Klokka 00.30 den
 * forste er fortsatt forrige maaned i UTC, og en betaling ville havnet i feil
 * maaned.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
// Regnskapsfoereren slipper inn her. Eieren, 1. september: «jeg oensker aa
// lage en bruker log in til min regnskapsoerer». Hun ser OEkonomi og
// betalingene; resten av admin er stengt for rollen.
krev_regnskap();

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$naa  = new DateTimeImmutable('now', $oslo);

$tilUtc = static fn(DateTimeImmutable $d): string => $d->setTimezone($utc)->format('Y-m-d H:i:s');

$MAANEDER = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
             'juli', 'august', 'september', 'oktober', 'november', 'desember'];

// Samme kilde som Oversikt og dagsoppgjoret (Omsetning), saa alle tre viser
// samme tall. Eieren 29.09.2026: et kurs betalt i verkstedet manglet her.
$sumMellom = static function (string $fra, string $til): int {
    return array_sum(Omsetning::perFormal($fra, $til));
};

// --- Denne maaneden og forrige --------------------------------------------
$mndStart  = $naa->modify('first day of this month')->setTime(0, 0);
$nesteMnd  = $mndStart->modify('+1 month');
$forrigeMnd = $mndStart->modify('-1 month');

$naaSum     = $sumMellom($tilUtc($mndStart), $tilUtc($nesteMnd));
$forrigeSum = $sumMellom($tilUtc($forrigeMnd), $tilUtc($mndStart));

// Omsetningen er UTEN mva (eieren, 30. september 2026: «kontoen total maa
// vaere alt uten mva, det er dette som er omsetning»). Mva-en for seg. De
// gamle feltene (omsetning, omsetningOre) er fortsatt med mva.
$naaMva     = Omsetning::sumUtenMva(Omsetning::perFormal($tilUtc($mndStart), $tilUtc($nesteMnd)));
$forrigeEks = Omsetning::sumUtenMva(Omsetning::perFormal($tilUtc($forrigeMnd), $tilUtc($mndStart)))['eksOre'];

// ── Sammenlikningen med forrige maaned ────────────────────────────────
//
// Eieren, 23. september 2026, med «+4073900 % mot august» paa skjermen:
// «se paa prosentokningen mot august, for svada».
//
// Her sto det bare «$forrigeSum > 0». August hadde én krone. Da er
// regnestykket 40 740 mot 1, og prosenten blir fire millioner — matematisk
// riktig og fullstendig meningsloest.
//
// En prosent trenger et grunnlag som taaler aa deles paa. Under tusen kroner
// er en maaned saa godt som tom, og da er det ikke en oekning i prosent — det
// er at forrige maaned ikke var noe. Da staar beloepet i stedet, som er det
// eneste som faktisk sier noe: «mot kr. 1,- i august».
//
// «+100 % mot juli» naar juli var null er ikke et tall, det er en divisjon.
const SAMMENLIKNBART_ORE = 100000;

$endring = null;
$mndNavn  = $MAANEDER[(int) $forrigeMnd->format('n') - 1];
if ($forrigeSum >= SAMMENLIKNBART_ORE) {
    // Paa omsetningen uten mva, som tallet det staar under.
    $pst = $forrigeEks > 0 ? (int) round(($naaMva['eksOre'] - $forrigeEks) / $forrigeEks * 100) : 0;
    $endring = ($pst >= 0 ? '+' : '') . $pst . ' % mot ' . $mndNavn;
} elseif ($forrigeSum > 0) {
    $endring = 'mot ' . Booking::kroner($forrigeSum) . ' i ' . $mndNavn;
}

// --- Aatte uker bakover ----------------------------------------------------
//
// Uke for uke, med mandag som start slik ISO-uka gaar. Uker helt uten
// betalinger tas med som null — ellers ville grafen skjult en stille uke ved
// aa flytte de andre inntil hverandre.
$uker = [];
$mandag = $naa->modify('monday this week')->setTime(0, 0);
for ($i = 7; $i >= 0; $i--) {
    $fra = $mandag->modify('-' . $i . ' weeks');
    $til = $fra->modify('+1 week');
    $uker[] = [
        'uke'    => 'U' . $fra->format('W'),
        'ore'    => $sumMellom($tilUtc($fra), $tilUtc($til)),
        'fraDag' => $fra->format('j.n.'),
    ];
}
$topp = max(array_map(static fn($u) => $u['ore'], $uker));

// --- Hva pengene kom fra ---------------------------------------------------
$FORMAL = [
    'booking'    => 'Kurs og events',
    'ordre'      => 'Butikk',
    'gavekort'   => 'Gavekort',
    'medlemskap' => 'Medlemskap',
];

$perFormal = Omsetning::perFormal($tilUtc($mndStart), $tilUtc($nesteMnd));

$kilder = [];
foreach ($FORMAL as $nokkel => $navn) {
    if (($perFormal[$nokkel] ?? 0) !== 0) {
        // Beloepet uten mva og mva-en for seg, for kontoene som har mva
        // (eieren, 30. september 2026). «sum» er fortsatt brutto.
        $mva = Omsetning::mvaFor($nokkel, $perFormal[$nokkel]);
        $kilder[] = [
            'navn'  => $navn,
            'sum'   => Booking::kroner($perFormal[$nokkel]),
            'andel' => $naaMva['eksOre'] > 0 ? (int) round(Omsetning::mvaFor($nokkel, $perFormal[$nokkel])['eksOre'] / $naaMva['eksOre'] * 100) : 0,
            'ore'   => $perFormal[$nokkel],
            'eksOre'  => $mva['eksOre'],
            'mvaOre'  => $mva['mvaOre'],
            'mvaSats' => $mva['mvaSats'],
            'eks'     => Booking::kroner($mva['eksOre']),
            'mva'     => Booking::kroner($mva['mvaOre']),
        ];
    }
}

// --- Innstillingene --------------------------------------------------------
//
// Verdier fra secrets.php, maskert. Poenget er ikke aa vise noekkelen, men aa
// svare paa «staar den riktige inne?» — en tom noekkel ser lik ut som en feil
// noekkel naar begge vises som prikker.
$maskert = static function (string $verdi, int $synlig = 4): string {
    if ($verdi === '') {
        return 'Ikke satt';
    }
    return str_repeat('•', 12) . mb_substr($verdi, -$synlig);
};

$base = (string) Config::hent('vipps_base', '');
$erProd = str_contains($base, '//api.vipps.no');

$vippsFelter = [
    // Betalingen kan ha sitt eget sett noekler, paa sin egen salgsenhet.
    // Sto det bare ett sett her, kunne man tro at betalingen brukte
    // innloggingens noekler naar den i virkeligheten bruker sine egne.
    ['navn' => 'Salgsenhet, betaling', 'verdi' => Vipps::betalingNokler()['msn'] ?: 'Ikke satt'],
    ['navn' => 'Salgsenhet, innlogging', 'verdi' => (string) Config::hent('vipps_msn', '') ?: 'Ikke satt'],
    ['navn' => 'Egne nøkler til betaling', 'verdi' => Vipps::egneBetalingsnokler() ? 'Ja' : 'Nei — deler med innloggingen'],
    ['navn' => 'Client ID',        'verdi' => $maskert(Vipps::betalingNokler()['client_id'], 4)],
    ['navn' => 'Client secret',    'verdi' => $maskert(Vipps::betalingNokler()['client_secret'])],
    ['navn' => 'Subscription key', 'verdi' => $maskert(Vipps::betalingNokler()['sub_key'])],
    ['navn' => 'Webhook-hemmelighet', 'verdi' => $maskert((string) Config::hent('vipps_webhook_secret', ''))],
    // Returadressen settes ikke i secrets.php — den regnes ut av nettstedets
    // egen adresse. Feltet leste likevel secrets, og sto derfor som «Ikke
    // satt» selv paa en side der Vipps-innlogging virket.
    ['navn' => 'Retur-adresse',    'verdi' => Vipps::returAdresse()],
    ['navn' => 'Miljø',            'verdi' => $erProd ? 'Produksjon' : 'Test'],
];

// Hva vi faktisk vet virker: har det kommet en betaling gjennom, saa virker
// ePayment. Har noen logget inn med Vipps, saa virker Login. Vi paastaar
// ingenting vi ikke har sett skje.
$harBetaling = (int) DB::verdi("SELECT COUNT(*) FROM payments WHERE status = 'betalt' AND type = 'epayment'");
$harTrekk    = (int) DB::verdi("SELECT COUNT(*) FROM payments WHERE status = 'betalt' AND type = 'recurring_charge'");
$harVippsBruker = (int) DB::verdi('SELECT COUNT(*) FROM members WHERE vipps_sub IS NOT NULL');
$satt        = static fn(string $n): bool => (string) Config::hent($n, '') !== '';

$vippsProdukter = [
    [
        'navn' => 'ePayment', 'hva' => 'Kurs, events, butikk og gavekort',
        'status' => $harBetaling > 0 ? 'I bruk' : ($satt('vipps_client_id') ? 'Satt opp — ikke brukt ennå' : 'Mangler nøkler'),
        'tone'   => $harBetaling > 0 ? 'success' : ($satt('vipps_client_id') ? 'neutral' : 'warning'),
    ],
    [
        'navn' => 'Recurring', 'hva' => 'Månedstrekk for medlemskap',
        'status' => $harTrekk > 0 ? 'I bruk' : 'Ikke koblet opp',
        'tone'   => $harTrekk > 0 ? 'success' : 'neutral',
    ],
    [
        // Samme regel som over: vi paastaar ikke at noe virker for vi har
        // sett det skje. Statusen sto foer paa en noekkel som aldri settes,
        // og meldte «Mangler retur-adresse» paa et oppsett som var i bruk.
        'navn' => 'Login', 'hva' => 'Logg inn med Vipps',
        'status' => $harVippsBruker > 0
            ? 'I bruk'
            : ($satt('vipps_client_id') ? 'Satt opp — ingen har logget inn ennå' : 'Mangler nøkler'),
        'tone'   => $harVippsBruker > 0 ? 'success' : ($satt('vipps_client_id') ? 'neutral' : 'warning'),
    ],
];

Svar::json([
    'maaned'    => $MAANEDER[(int) $naa->format('n') - 1],
    // Maskinlesbar utgave av samme maaned. Eksporten og maanedsrapporten
    // trenger den for aa be om riktig periode.
    'periode'   => $mndStart->format('Y-m'),
    'aar'       => (int) $mndStart->format('Y'),
    'omsetning' => Booking::kroner($naaSum),
    'omsetningOre' => $naaSum,
    'omsetningEks'    => Booking::kroner($naaMva['eksOre']),
    'omsetningEksOre' => $naaMva['eksOre'],
    'mvaOre'          => $naaMva['mvaOre'],
    'mva'             => Booking::kroner($naaMva['mvaOre']),
    'endring'   => $endring,
    'uker'      => array_map(static fn($u) => [
        'uke'   => $u['uke'],
        'sum'   => Booking::kroner($u['ore']),
        // Kort utgave til soylene.
        //
        // Eieren, 23. september 2026: «se paa teksten som ikke passer i
        // pillene». Aatte soyler paa en telefonskjerm gir rundt 35 piksler
        // hver, og «kr. 26 820,-» er tre ganger saa bredt. Teksten sto med
        // «white-space: nowrap» og rant derfor inn i naboen — tallene laa
        // oppaa hverandre og ingen av dem var til aa lese.
        //
        // «26,8k» er til aa lese paa ett blikk, og den fulle summen staar i
        // «sum» for den som trenger den.
        'kort'  => Booking::kortKroner($u['ore']),
        'fraDag' => $u['fraDag'],
        // Hoyden i prosent av den hoyeste uka. Er alt null, blir alle null,
        // og grafen viser en flat linje framfor aatte like hoye soyler.
        'h'     => $topp > 0 ? max(2, (int) round($u['ore'] / $topp * 100)) : 0,
    ], $uker),
    'harUker'   => $topp > 0,
    'kilder'    => $kilder,
    'vipps'     => ['produkter' => $vippsProdukter, 'felter' => $vippsFelter],
    // Ingen regnskapskobling finnes. Kortet sa «Tilkoblet» med oppdiktede
    // tokens; naa sier det som er.
    'regnskap'  => ['tilkoblet' => false],
]);
