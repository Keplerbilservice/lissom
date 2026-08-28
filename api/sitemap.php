<?php
/**
 * Sidekartet, bygget av det som faktisk ligger ute.
 *
 * Var en fil med atten faste adresser. Kursene sto ikke i den, for de fantes
 * ikke som egne adresser — alt laa under /kurs. Naa har hvert kurs sin egen,
 * og da maa sidekartet kunne endre seg naar Monica legger ut et nytt kurs
 * eller tar et bort. En fil vi maa huske aa redigere, blir feil samme dagen
 * noen glemmer det.
 *
 * Adressene her maa vaere de samme som staar som canonical i <head>. Peker de
 * to ulike steder, velger Google selv hvilken som teller.
 *
 * Faller databasen ut, sendes de faste sidene likevel. Et sidekart uten
 * kursene er mye bedre enn en 500 der Google forventer XML.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

const ROT = 'https://lissom.no';

/** @var list<array{sti:string,prioritet:string,frekvens:string}> */
$faste = [
    ['',                              '1.0', 'weekly'],
    ['/kurs',                         '0.9', 'weekly'],
    ['/events',                       '0.9', 'weekly'],
    ['/kalender',                     '0.9', 'daily'],
    ['/drop-in',                      '0.8', 'weekly'],
    ['/medlemskap',                   '0.8', 'monthly'],
    ['/butikk',                       '0.7', 'weekly'],
    ['/paint-on-pots',                '0.7', 'monthly'],
    ['/bedrift',                      '0.6', 'monthly'],
    ['/gavekort',                     '0.6', 'monthly'],
    ['/om-oss',                       '0.6', 'monthly'],
    ['/kontakt',                      '0.6', 'monthly'],
    ['/nyheter',                      '0.6', 'weekly'],
    ['/nyttig-info',                  '0.5', 'monthly'],
    ['/nyttig-info/brennetabell',     '0.4', 'yearly'],
    ['/nyttig-info/medlemsinfo',      '0.4', 'yearly'],
    ['/nyttig-info/trivselsregler',   '0.4', 'yearly'],
    ['/personvern',                   '0.2', 'yearly'],
    ['/vilkar',                       '0.2', 'yearly'],
];

$idag = date('Y-m-d');
$linjer = [];

foreach ($faste as [$sti, $prioritet, $frekvens]) {
    $linjer[] = [ROT . ($sti === '' ? '/' : $sti), $idag, $frekvens, $prioritet];
}

// Kursene. Bare de som er publisert, aapne for alle, og som enten har en
// dato liggende ute eller skal staa uten. Et kurs uten noe av delene er en
// side med ingenting paa — den skal ikke inviteres inn i soket.
try {
    $utenDato = DB::harKolonne('courses', 'vis_uten_dato') ? 'vis_uten_dato' : '0 AS vis_uten_dato';

    $kurs = DB::alle(
        "SELECT c.slug, {$utenDato},
                (SELECT COUNT(*) FROM course_sessions cs
                  WHERE cs.course_id = c.id AND cs.status = 'planlagt'
                    AND cs.start_tid > UTC_TIMESTAMP()) AS kommende,
                (SELECT MAX(cs2.updated_at) FROM course_sessions cs2
                  WHERE cs2.course_id = c.id) AS sist_endret,
                c.updated_at
           FROM courses c
          WHERE c.status = 'publisert'
            AND COALESCE(c.tema, '') <> 'Kun for medlemmer'
            AND c.slug IS NOT NULL AND c.slug <> ''
       ORDER BY c.tittel"
    );

    foreach ($kurs as $k) {
        if ((int) $k['kommende'] === 0 && (int) ($k['vis_uten_dato'] ?? 0) === 0) {
            continue;
        }
        $endret = $k['sist_endret'] ?: $k['updated_at'];
        $linjer[] = [
            ROT . '/kurs/' . rawurlencode((string) $k['slug']),
            $endret ? date('Y-m-d', strtotime((string) $endret)) : $idag,
            'weekly',
            '0.8',
        ];
    }
} catch (Throwable) {
    // De faste sidene gaar ut uansett.
}

// Varene i butikken. Hver av dem har sin egen adresse; uten dem her ville
// ingen kopp blitt funnet, og butikken var én side som skulle rangere paa
// alt den inneholdt.
//
// Bare det som er aapent for alle. Medlemsvarene — leire, ekstra brenning —
// er verkstedets interne hylle og har ingen offentlig side.
//
// Utsolgte staar likevel. En vare uten lager kommer ofte igjen, og en side
// som forsvinner og kommer tilbake er verre enn en som sier «utsolgt».
try {
    foreach (DB::alle(
        "SELECT id, tittel, created_at FROM products
          WHERE status = 'publisert' AND kun_medlemmer = 0
       ORDER BY tittel"
    ) as $v) {
        $linjer[] = [
            ROT . Lenker::vare((int) $v['id'], (string) $v['tittel']),
            $v['created_at'] ? date('Y-m-d', strtotime((string) $v['created_at'])) : $idag,
            'weekly',
            '0.6',
        ];
    }
} catch (Throwable) {
    // De faste sidene gaar ut uansett.
}

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($linjer as [$adresse, $endret, $frekvens, $prioritet]) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($adresse, ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . $endret . "</lastmod>\n";
    echo '    <changefreq>' . $frekvens . "</changefreq>\n";
    echo '    <priority>' . $prioritet . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
