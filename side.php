<?php
/**
 * Nettsida, med riktig hode allerede i svaret.
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * Nettsida er én fil som bytter skjerm i nettleseren. Tittel, beskrivelse,
 * canonical og delingstekst ble satt av JavaScript etter at sida var lastet.
 * Det virker for et menneske. Det virker ikke for det som ikke kjorer
 * skript, og maalt 28. august var det slik:
 *
 *   /kurs, /medlemskap, /butikk, /paint-on-pots, /drop-in
 *   → samme tittel «Keramikkurs i Tonsberg», ingen canonical,
 *     ingen strukturerte data. Seks adresser som ser ut som samme side.
 *
 * Hvem det gjelder:
 *
 *   * Forhaandsvisningen naar noen deler en lenke paa Instagram, Facebook,
 *     Messenger eller Slack. Deler du kurssida, sto det «Keramikkurs i
 *     Tonsberg» og forsidas bilde. Hver gang.
 *   * AI-assistentene. De henter raa HTML og kjorer ikke skript.
 *   * Bing og DuckDuckGo, som er vesentlig svakere paa JavaScript enn Google.
 *
 * Google selv kjorer skript og fikk det stort sett riktig. Men gjengivelsen
 * er en egen, utsatt runde — og gaar den galt, er det dette svaret Google
 * har.
 *
 * ── Hvordan ───────────────────────────────────────────────────────────
 *
 * Hodet byttes ut for fila gaar ut. Verdiene kommer fra seo-kart.json, som
 * bin/seokart.mjs leser ut av nettsida selv — saa serveren og nettleseren
 * sier det samme. Har eieren endret SEO under Nettsiden → Innhold, ligger
 * det i content_blocks og gaar foran.
 *
 * JavaScript setter de samme verdiene paa nytt naar sida er lastet. Det er
 * med vilje: da er dette bunnen og skjermen fasiten, og en side som bytter
 * uten aa laste paa nytt faar fortsatt riktig tittel.
 *
 * Gaar noe galt her — mangler kartet, er databasen nede — sendes fila ut
 * slik den er. Nettsida skal aldri falle fordi et metatag ikke lot seg
 * sette.
 */

declare(strict_types=1);

const SIDE_FIL  = __DIR__ . '/lissom-2108.html';
const SIDE_KART = __DIR__ . '/seo-kart.json';
const ROT       = 'https://lissom.no';

// Markorene rundt hodet som byttes. De staar i lissom-2108.html og er det
// eneste bindeleddet mellom denne fila og den — endres de der, sier
// bin/seosjekk.mjs fra.
const MERKE_START = '<!-- seo:start -->';
const MERKE_SLUTT = '<!-- seo:slutt -->';

$html = @file_get_contents(SIDE_FIL);
if ($html === false) {
    http_response_code(500);
    exit('Nettsiden mangler.');
}

/** Sender fila ut slik den er, og avslutter. */
$ut = static function (string $html): never {
    header('Content-Type: text/html; charset=UTF-8');
    // Samme regel som .htaccess ga .html-fila: behold den, men spor forst.
    header('Cache-Control: no-cache, must-revalidate');
    echo $html;
    exit;
};

$start = strpos($html, MERKE_START);
$slutt = strpos($html, MERKE_SLUTT);
if ($start === false || $slutt === false || $slutt < $start) {
    $ut($html);
}

$sti = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$sti = rtrim($sti, '/');
if ($sti === '') { $sti = '/'; }

$kart = json_decode((string) @file_get_contents(SIDE_KART), true);
if (!is_array($kart) || !isset($kart['stier'], $kart['sider'])) {
    $ut($html);
}

// ── Hvilken side er dette ───────────────────────────────────────────────
$d = null;

// Kurssidene: /kurs/<slug>. Teksten er kursets egen, hentet fra basen, og
// bygges som seoKurs() i nettsida gjor det — samme kutt ved siste punktum,
// samme reservetekst naar kurset ikke har noen beskrivelse.
if (preg_match('~^/kurs/([a-z0-9\-]+)$~i', $sti, $treff) === 1) {
    try {
        require __DIR__ . '/api/_boot.php';
        $k = DB::en(
            "SELECT tittel, beskrivelse, bilde FROM courses
              WHERE slug = :s AND status = 'publisert'
                AND COALESCE(tema, '') <> 'Kun for medlemmer'",
            ['s' => $treff[1]]
        );
        if ($k !== null) {
            $navn = (string) $k['tittel'];
            $meta = trim((string) preg_replace('/\s+/u', ' ', (string) ($k['beskrivelse'] ?? '')));
            if (mb_strlen($meta) > 158) {
                $kort = mb_substr($meta, 0, 158);
                $punktum = mb_strrpos($kort, '. ');
                $meta = ($punktum !== false && $punktum > 60)
                    ? mb_substr($kort, 0, $punktum + 1)
                    : trim($kort) . ' …';
            }
            if ($meta === '') {
                $meta = $navn . ' hos Lissom Keramikk på Teie ved Tønsberg. Leire, verktøy '
                      . 'og brenning er inkludert. Se ledige datoer og book plass.';
            }
            $bilde = trim((string) ($k['bilde'] ?? ''));
            $d = [
                'tittel'        => $navn . ' i Tønsberg | Lissom Keramikk',
                'meta'          => $meta,
                'canonical'     => ROT . '/kurs/' . rawurlencode($treff[1]),
                'ogTittel'      => $navn . ' i Tønsberg | Lissom Keramikk',
                'ogBeskrivelse' => $meta,
                'delingsbilde'  => $bilde !== '' ? ROT . '/' . ltrim($bilde, '/') : '',
                'index'         => 'Index',
            ];
        }
    } catch (Throwable) {
        // Basen er nede, eller kurset finnes ikke. Da staar hodet som det gjor.
    }
}

if ($d === null) {
    $id = $kart['stier'][$sti] ?? null;
    if ($id !== null && isset($kart['sider'][$id])) {
        $d = $kart['sider'][$id];
        // Det eieren har lagret under Nettsiden → Innhold gaar foran.
        try {
            require_once __DIR__ . '/api/_boot.php';
            $lagret = DB::verdi(
                'SELECT verdi FROM content_blocks WHERE nokkel = :n',
                ['n' => 'SEO/' . $id]
            );
            $egne = json_decode((string) $lagret, true);
            if (is_array($egne)) {
                foreach ($egne as $felt => $verdi) {
                    if (is_string($verdi) && trim($verdi) !== '') { $d[$felt] = $verdi; }
                }
            }
        } catch (Throwable) {
            // Ferdigteksten staar. Den er bedre enn ingen.
        }
    }
}

// ── Hodet ───────────────────────────────────────────────────────────────
//
// Adresser vi ikke kjenner — /kasse, /min-side, en skrivefeil — skal ikke i
// soket. Ellers ville hver feilskrevne adresse blitt en egen side, alle med
// samme innhold.
$ikkeISoket = $d === null || strtolower((string) ($d['index'] ?? 'Index')) === 'noindex';

$tittel = (string) ($d['tittel'] ?? 'Keramikkurs i Tønsberg | Lissom Keramikk');
$meta   = (string) ($d['meta'] ?? '');
$canon  = (string) ($d['canonical'] ?? '');
$ogT    = (string) ($d['ogTittel'] ?? $tittel);
$ogB    = (string) ($d['ogBeskrivelse'] ?? $meta);
$ogBilde = (string) ($d['delingsbilde'] ?? '');
if ($ogBilde === '') { $ogBilde = ROT . '/delingsbilde.jpg'; }
$ogAlt  = (string) ($d['altTekst'] ?? 'Deltaker former en bolle på dreieskiva hos Lissom Keramikk i Tønsberg');

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$hode = MERKE_START . "\n"
    . '<title>' . $e($tittel) . "</title>\n"
    . ($meta !== '' ? '<meta name="description" content="' . $e($meta) . '">' . "\n" : '')
    . '<meta name="robots" content="' . ($ikkeISoket ? 'noindex,nofollow' : 'index,follow') . '">' . "\n"
    . ($canon !== '' ? '<link rel="canonical" href="' . $e($canon) . '">' . "\n" : '')
    . '<meta property="og:type" content="website">' . "\n"
    . '<meta property="og:site_name" content="Lissom Keramikk &amp; Håndverk">' . "\n"
    . '<meta property="og:locale" content="nb_NO">' . "\n"
    . '<meta property="og:url" content="' . $e($canon !== '' ? $canon : ROT . '/') . '">' . "\n"
    . '<meta property="og:title" content="' . $e($ogT) . '">' . "\n"
    . ($ogB !== '' ? '<meta property="og:description" content="' . $e($ogB) . '">' . "\n" : '')
    . '<meta property="og:image" content="' . $e($ogBilde) . '">' . "\n"
    . '<meta property="og:image:width" content="1200">' . "\n"
    . '<meta property="og:image:height" content="675">' . "\n"
    . '<meta property="og:image:alt" content="' . $e($ogAlt) . '">' . "\n"
    . '<meta name="twitter:card" content="summary_large_image">' . "\n"
    . MERKE_SLUTT;

$ut(substr_replace($html, $hode, $start, $slutt + strlen(MERKE_SLUTT) - $start));
