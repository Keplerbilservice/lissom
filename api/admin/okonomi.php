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
krev_admin();

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$naa  = new DateTimeImmutable('now', $oslo);

$tilUtc = static fn(DateTimeImmutable $d): string => $d->setTimezone($utc)->format('Y-m-d H:i:s');

$MAANEDER = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
             'juli', 'august', 'september', 'oktober', 'november', 'desember'];

$sumMellom = static function (string $fra, string $til): int {
    return (int) DB::verdi(
        "SELECT COALESCE(SUM(belop_ore - refundert_ore), 0) FROM payments
          WHERE status IN ('betalt','delvis_refundert')
            AND created_at >= :fra AND created_at < :til",
        ['fra' => $fra, 'til' => $til]
    );
};

// --- Denne maaneden og forrige --------------------------------------------
$mndStart  = $naa->modify('first day of this month')->setTime(0, 0);
$nesteMnd  = $mndStart->modify('+1 month');
$forrigeMnd = $mndStart->modify('-1 month');

$naaSum     = $sumMellom($tilUtc($mndStart), $tilUtc($nesteMnd));
$forrigeSum = $sumMellom($tilUtc($forrigeMnd), $tilUtc($mndStart));

// Sammenlikningen gir bare mening naar det var noe aa sammenlikne med.
// «+100 % mot juli» naar juli var null er ikke et tall, det er en divisjon.
$endring = null;
if ($forrigeSum > 0) {
    $pst = (int) round(($naaSum - $forrigeSum) / $forrigeSum * 100);
    $endring = ($pst >= 0 ? '+' : '') . $pst . ' % mot ' . $MAANEDER[(int) $forrigeMnd->format('n') - 1];
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
    'dropin'     => 'Drop-in',
    'ordre'      => 'Butikk',
    'gavekort'   => 'Gavekort',
    'medlemskap' => 'Medlemskap',
];

$perFormal = [];
foreach (DB::alle(
    "SELECT formal, SUM(belop_ore - refundert_ore) AS sum FROM payments
      WHERE status IN ('betalt','delvis_refundert')
        AND created_at >= :fra AND created_at < :til
      GROUP BY formal",
    ['fra' => $tilUtc($mndStart), 'til' => $tilUtc($nesteMnd)]
) as $r) {
    $perFormal[(string) $r['formal']] = (int) $r['sum'];
}

$kilder = [];
foreach ($FORMAL as $nokkel => $navn) {
    if (($perFormal[$nokkel] ?? 0) !== 0) {
        $kilder[] = [
            'navn'  => $navn,
            'sum'   => Booking::kroner($perFormal[$nokkel]),
            'andel' => $naaSum > 0 ? (int) round($perFormal[$nokkel] / $naaSum * 100) : 0,
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
    'endring'   => $endring,
    'uker'      => array_map(static fn($u) => [
        'uke'   => $u['uke'],
        'sum'   => Booking::kroner($u['ore']),
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
