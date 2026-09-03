<?php
/**
 * llms.txt — sida forklart for en AI som skal svare noen.
 *
 * ── Hva fila er ────────────────────────────────────────────────────────
 *
 * En forespurt konvensjon (llmstxt.org): ett sted der et nettsted sier hva
 * det er, i klartekst, uten meny og markup. Sporer noen ChatGPT eller
 * Perplexity «hvor kan jeg ta keramikkurs i Tonsberg», er det denne fila og
 * sidene den peker paa som avgjor om Lissom kan siteres.
 *
 * Konvensjonen er ung og ingen har lovet aa lese den. Fila koster lite, og
 * det som staar i den er det samme vi uansett vil at folk skal finne.
 *
 * ── Hvorfor den er generert ────────────────────────────────────────────
 *
 * Den var en fil i rota, skrevet for haand i august. Da et tilbud ble tatt ned
 * 1. september, ble den staaende og fortelle AI-ene om et tilbud som ikke
 * finnes lenger — i to avsnitt. Vaktene aapner skjermer i en nettleser og
 * saa den ikke.
 *
 * Det er den samme lærdommen som sidekartet: en fil noen maa huske aa
 * redigere, blir feil samme dagen noen glemmer det. Derfor bygges den av det
 * som faktisk ligger ute, slik api/sitemap.php gjor.
 *
 * Faller databasen ut, gaar de faste avsnittene likevel. En llms.txt uten
 * kurslista er mye bedre enn en 500 der en robot forventer tekst.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

const ROT = 'https://lissom.no';

$ut = [];
$ut[] = '# Lissom Keramikk & Håndverk';
$ut[] = '';
$ut[] = '> Keramikkverksted på Teie ved Tønsberg. Kurs i dreiing og plateteknikk,';
$ut[] = '> kreative kvelder som Sip & Clay, Date Night og Paint on Pots, og';
$ut[] = '> medlemskap med fast plass i verkstedet. Vi tar imot folk fra hele Vestfold.';
$ut[] = '';
$ut[] = 'Adresse: Nordre Løkkevei 15, 3120 Nøtterøy';
$ut[] = 'Telefon: +47 94 13 46 01';
$ut[] = 'E-post: monica@lissom.no';
$ut[] = 'Språk: norsk (bokmål)';
$ut[] = '';

// ── Kursene som faktisk ligger ute ─────────────────────────────────────
//
// Med pris og neste dato. Det er nettopp de opplysningene en AI trenger for
// aa kunne svare noe konkret: «et nybegynnerkurs koster 2 800 kroner og
// neste gang er 9. september». Uten dem kan den bare si at stedet finnes.
$ut[] = '## Kurs og events';
$ut[] = '';
try {
    $utenDato = DB::harKolonne('courses', 'vis_uten_dato') ? 'c.vis_uten_dato' : '0 AS vis_uten_dato';
    $kurs = DB::alle(
        "SELECT c.slug, c.tittel, c.pris_ore, c.beskrivelse, {$utenDato},
                (SELECT MIN(cs.start_tid) FROM course_sessions cs
                  WHERE cs.course_id = c.id AND cs.status = 'planlagt'
                    AND cs.start_tid > UTC_TIMESTAMP()) AS neste,
                (SELECT COUNT(*) FROM course_sessions cs2
                  WHERE cs2.course_id = c.id AND cs2.status = 'planlagt'
                    AND cs2.start_tid > UTC_TIMESTAMP()) AS kommende
           FROM courses c
          WHERE c.status = 'publisert'
            AND COALESCE(c.tema, '') <> 'Kun for medlemmer'
            AND c.slug IS NOT NULL AND c.slug <> ''
       ORDER BY c.tittel"
    );

    $oslo = new DateTimeZone('Europe/Oslo');
    $mnd = [1 => 'januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli',
            'august', 'september', 'oktober', 'november', 'desember'];

    foreach ($kurs as $k) {
        if ((int) $k['kommende'] === 0 && (int) ($k['vis_uten_dato'] ?? 0) === 0) {
            continue;
        }
        $bit = [];
        if ((int) $k['pris_ore'] > 0) {
            $bit[] = 'kr. ' . number_format((int) $k['pris_ore'] / 100, 0, ',', ' ') . ',-';
        }
        if ($k['neste'] !== null) {
            $d = (new DateTimeImmutable((string) $k['neste'], new DateTimeZone('UTC')))->setTimezone($oslo);
            $bit[] = 'neste ' . (int) $d->format('j') . '. ' . $mnd[(int) $d->format('n')];
            $n = (int) $k['kommende'];
            if ($n > 1) { $bit[] = $n . ' datoer ute'; }
        }
        // Foerste setning av beskrivelsen. En AI siterer helst noe kort og
        // helt — ikke tre avsnitt den maa klippe i selv.
        $om = trim(strip_tags((string) ($k['beskrivelse'] ?? '')));
        if ($om !== '') {
            $punktum = strcspn($om, '.');
            $om = trim(substr($om, 0, min($punktum + 1, 160)));
            if ($om !== '') { $bit[] = rtrim($om, '.'); }
        }
        $ut[] = '- [' . $k['tittel'] . '](' . ROT . '/kurs/' . rawurlencode((string) $k['slug']) . ')'
              . ($bit ? ': ' . implode(' · ', $bit) : '');
    }
} catch (Throwable) {
    // De faste avsnittene gaar ut uansett.
}
$ut[] = '';
$ut[] = '- [Alle kurs og events](' . ROT . '/kurs): hele lista, med datoer og ledige plasser';
$ut[] = '- [Events](' . ROT . '/events): Sip & Clay, Date Night og Paint on Pots';
$ut[] = '- [Paint on Pots](' . ROT . '/paint-on-pots): mal ferdigbrent keramikk, passer også barn';
$ut[] = '- [Kalender](' . ROT . '/kalender): alt som skjer, uke for uke';
$ut[] = '- [Bedrift og event](' . ROT . '/bedrift): teambuilding, julebord og kick-off';
$ut[] = '';
$ut[] = 'Hvert kurs har sin egen side under /kurs/<navn>. Sidekartet på';
$ut[] = ROT . '/sitemap.xml er bygget av det som faktisk ligger ute.';
$ut[] = '';

// ── Sporsmaal og svar, skrevet av eieren ───────────────────────────────
//
// Dette er GEO-skjermen i admin: ett kort svar per side, med pris, sted og
// varighet i selve setningen, slik at den kan siteres alene. Det er den
// eneste teksten i fila et menneske har formulert med en AI som leser i
// tankene — resten er lister bygget av basen.
//
// Bare sidene som faktisk har baade sporsmaal og svar. Et sporsmaal uten
// svar er verre enn ingenting: det lover noe som ikke staar der.
$GEO_SIDER = [
    'forside'      => '/',
    'kurs'         => '/kurs',
    'events'       => '/events',
    'medlemskap'   => '/medlemskap',
    'butikk'       => '/butikk',
    'gavekort'     => '/gavekort',
    'omoss'        => '/om-oss',
    'kontakt'      => '/kontakt',
    'bedrift'      => '/bedrift',
    'kalender'     => '/kalender',
    'kursoversikt' => '/kurs',
    'nyttig'       => '/nyttig-info',
    'nyheter'      => '/nyheter',
    'paintonpots'  => '/paint-on-pots',
];

// De seks kurs- og eventsidene har ingen fast adresse: de bor under
// /kurs/<slug>, og slugen kan endre seg. Derfor slaas den opp paa tittelen
// her i stedet for aa staa som en konstant som blir feil den dagen kurset
// doper om seg. Finner vi den ikke, faller sida ut — en «Kilde:» som peker
// feil er verre enn ingen kilde.
$GEO_KURS = [
    'dreiekurs'   => 'Nybegynner dreiekurs',
    'boller'      => 'Lag din egen bolle',
    'fat'         => 'Store fat kurs',
    'workshop'    => 'Keramikk workshop',
    'datenight'   => 'Date Night',
    'sipclay'     => 'Sip & Clay',
];
try {
    foreach (DB::alle(
        "SELECT tittel, slug FROM courses
          WHERE status = 'publisert' AND slug IS NOT NULL AND slug <> ''"
    ) as $c) {
        foreach ($GEO_KURS as $id => $tittel) {
            if ((string) $c['tittel'] === $tittel) {
                $GEO_SIDER[$id] = '/kurs/' . rawurlencode((string) $c['slug']);
            }
        }
    }
} catch (Throwable) {
    // Da staar de fjorten faste sidene alene.
}

try {
    $svar = [];
    foreach (DB::alle("SELECT nokkel, verdi FROM content_blocks WHERE nokkel LIKE 'GEO/%'") as $r) {
        $id = substr((string) $r['nokkel'], 4);
        if (!isset($GEO_SIDER[$id])) {
            continue;
        }
        $d = json_decode((string) $r['verdi'], true);
        if (!is_array($d)) {
            continue;
        }
        $sp = trim((string) ($d['sporsmal'] ?? ''));
        $sv = trim((string) ($d['kortSvar'] ?? ''));
        if ($sp === '' || $sv === '') {
            continue;
        }
        $svar[] = [$sp, $sv, trim((string) ($d['fakta'] ?? '')), $GEO_SIDER[$id]];
    }

    if ($svar !== []) {
        $ut[] = '## Spørsmål og svar';
        $ut[] = '';
        foreach ($svar as [$sp, $sv, $fakta, $sti]) {
            $ut[] = '### ' . $sp;
            $ut[] = '';
            $ut[] = $sv;
            foreach (preg_split('/\r?\n/', $fakta) ?: [] as $linje) {
                $linje = trim($linje);
                if ($linje !== '') {
                    $ut[] = '- ' . $linje;
                }
            }
            $ut[] = '';
            $ut[] = 'Kilde: ' . ROT . $sti;
            $ut[] = '';
        }
    }
} catch (Throwable) {
    // Resten av fila gaar ut uansett.
}

// ── Aapningstider ──────────────────────────────────────────────────────
//
// «Naar har dere aapent» er et av de vanligste spoersmaalene en AI faar, og
// et av de faa den ikke kan gjette. Verkstedet har ingen faste tider — det
// er aapent naar det gaar kurs — og det er i seg selv svaret.
$ut[] = '## Åpningstider';
$ut[] = '';
$ut[] = 'Verkstedet er åpent når det går kurs eller event. Medlemmer har';
$ut[] = 'døgnåpen tilgang med egen dørkode. Se ' . ROT . '/kalender for';
$ut[] = 'hvilke dager som er åpne framover.';
$ut[] = '';

$ut[] = '## Medlemskap og butikk';
$ut[] = '';
try {
    foreach (DB::alle(
        "SELECT navn, pris_ore, timer, intervall FROM membership_plans
          WHERE aktiv = 1 ORDER BY pris_ore"
    ) as $p) {
        $d = [];
        if ((int) $p['pris_ore'] > 0) {
            $d[] = 'kr. ' . number_format((int) $p['pris_ore'] / 100, 0, ',', ' ') . ',- per '
                 . ((string) ($p['intervall'] ?? 'maaned') === 'aar' ? 'år' : 'måned');
        }
        $d[] = $p['timer'] === null ? 'fri tilgang' : ((int) $p['timer']) . ' timer';
        $ut[] = '- ' . $p['navn'] . ': ' . implode(' · ', $d);
    }
} catch (Throwable) {
    // Uten planene staar lenkene under uansett.
}
$ut[] = '- [Medlemskap](' . ROT . '/medlemskap): fast plass i verkstedet, egen hylle og dørkode';
$ut[] = '- [Butikk](' . ROT . '/butikk): håndlaget keramikk fra verkstedet';
$ut[] = '- [Gavekort](' . ROT . '/gavekort): gjelder alle kurs, events og varer';
$ut[] = '';

$ut[] = '## Om oss';
$ut[] = '';
$ut[] = '- [Om verkstedet](' . ROT . '/om-oss)';
$ut[] = '- [Kontakt](' . ROT . '/kontakt)';
$ut[] = '- [Spørsmål og svar](' . ROT . '/sporsmal-og-svar): det folk lurer på før de melder seg på';
$ut[] = '- [Nyttig info](' . ROT . '/nyttig-info): brennetabell, cone-temperaturer og trivselsregler';
$ut[] = '- [Guider og nyheter](' . ROT . '/nyheter)';
$ut[] = '';
$ut[] = '## Vilkår';
$ut[] = '';
$ut[] = '- [Personvern](' . ROT . '/personvern)';
$ut[] = '- [Salgsvilkår](' . ROT . '/vilkar)';
$ut[] = '';
$ut[] = 'Booking og betaling går gjennom Vipps. Admin, Min side og API-et er lukket';
$ut[] = 'og skal ikke gjennomsøkes — se ' . ROT . '/robots.txt.';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo implode("\n", $ut) . "\n";
