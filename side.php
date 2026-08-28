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
// Den samme sida uten adminpanelet — 582 kB mindre. Laget av
// bin/utenadmin.mjs, kontrollert av bin/adminsjekk.mjs.
const SIDE_LETT = __DIR__ . '/lissom-2108-uten-admin.html';
const SIDE_KART = __DIR__ . '/seo-kart.json';
// Toppen av forsida, tegnet ferdig av bin/forhaandstegn.mjs.
const SIDE_TOPP = __DIR__ . '/forside-topp.html';
const ROT       = 'https://lissom.no';

// Markorene rundt hodet som byttes. De staar i lissom-2108.html og er det
// eneste bindeleddet mellom denne fila og den — endres de der, sier
// bin/seosjekk.mjs fra.
const MERKE_START = '<!-- seo:start -->';
const MERKE_SLUTT = '<!-- seo:slutt -->';

/**
 * Adminpanelet sendes bare til den som er admin.
 *
 * 30 adminskjermer, 582 kB markup, som hver eneste besokende lastet ned og
 * aldri fikk se. De laa bak en «sc-if» — skjult, men sendt.
 *
 * De aller fleste har ingen sesjonscookie i det hele tatt. Da vet vi svaret
 * uten aa spore basen, og det er den billige veien: ingen tilkobling, ingen
 * sporring. Finnes cookien, maa vi sporre — og da er det verdt det.
 *
 * Er noe i veien — mangler fila, er basen nede — sendes hele sida. Den
 * tunge utgaven virker alltid; den lette er en besparelse, ikke en
 * forutsetning.
 */
/**
 * Laster resten av koden — én gang, og i sin egen skygge.
 *
 * «require» paa toppnivaa deler variabler med fila som krever. bootstrap.php
 * og api/_boot.php har begge en adressevariabel av sin egen, og den skrev
 * over vaar: adressen ble plutselig «/home/user/lissom/app/secrets.php», og
 * alle varesidene falt tilbake til forsidas tittel. Inne i en lukking blir
 * de variablene lukkingens, ikke vaare. Klasser og konstanter er globale
 * uansett, saa DB og Lenker er der etterpaa.
 *
 * Den lastes forst naar noe faktisk trenger basen. Er secrets.php borte,
 * stopper bootstrap — og da skal nettsida likevel gaa ut, med hodet den har.
 */
$lastBackend = static function (): void {
    require_once __DIR__ . '/api/_boot.php';
};

// Navnet paa sesjonscookien. Staar som Sesjon::COOKIE i app/lib/session.php,
// men den klassen finnes ikke for backend er lastet — og hele poenget her er
// aa slippe aa laste den for den som ikke har noen cookie. bin/adminsjekk.mjs
// kontrollerer at de to er like.
const SIDE_COOKIE = 'lissom_sesjon';

$erAdmin = false;
if (($_COOKIE[SIDE_COOKIE] ?? '') !== '') {
    try {
        $lastBackend();
        $erAdmin = Sesjon::erAdmin();
    } catch (Throwable) {
        $erAdmin = true;   // I tvil: send alt. Da mangler ingenting.
    }
}

$html = false;
if (!$erAdmin) {
    $html = @file_get_contents(SIDE_LETT);
}
if ($html === false) {
    $html = @file_get_contents(SIDE_FIL);
}
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

$adresse = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$adresse = rtrim($adresse, '/');
if ($adresse === '') { $adresse = '/'; }

$kart = json_decode((string) @file_get_contents(SIDE_KART), true);
if (!is_array($kart) || !isset($kart['stier'], $kart['sider'])) {
    $ut($html);
}

// ── Hvilken side er dette ───────────────────────────────────────────────
$d = null;

// Kurssidene: /kurs/<slug>. Teksten er kursets egen, hentet fra basen, og
// bygges som seoKurs() i nettsida gjor det — samme kutt ved siste punktum,
// samme reservetekst naar kurset ikke har noen beskrivelse.
if (preg_match('~^/kurs/([a-z0-9\-]+)$~i', $adresse, $treff) === 1) {
    try {
        $lastBackend();
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

// Varene i butikken: /butikk/<id>-<navn>. Tallet er det som gjelder; navnet
// bak staar der for menneskene. Er navnet utdatert, er det fortsatt riktig
// vare — og canonical peker paa adressen slik den heter naa.
if ($d === null && str_starts_with($adresse, '/butikk/')) {
    try {
        $lastBackend();
        $vareId = Lenker::vareId($adresse);
        $v = $vareId === null ? null : DB::en(
            "SELECT id, tittel, beskrivelse, bilde, pris_ore, lager, kun_medlemmer
               FROM products WHERE id = :i AND status = 'publisert'",
            ['i' => $vareId]
        );
        // Medlemsvarene — leire, ekstra brenning — er verkstedets interne
        // hylle. De skal ikke ha en side i soket, og ikke en adresse noen
        // kan dele. Da er det ingen side her.
        if ($v !== null && (int) $v['kun_medlemmer'] === 0) {
            $navn = (string) $v['tittel'];
            $meta = trim((string) preg_replace('/\s+/u', ' ', (string) ($v['beskrivelse'] ?? '')));
            if (mb_strlen($meta) > 158) {
                $kort = mb_substr($meta, 0, 158);
                $punktum = mb_strrpos($kort, '. ');
                $meta = ($punktum !== false && $punktum > 60)
                    ? mb_substr($kort, 0, $punktum + 1)
                    : trim($kort) . ' …';
            }
            if ($meta === '') {
                $meta = $navn . ' — håndlaget keramikk fra verkstedet på Teie i Tønsberg. '
                      . 'Hvert stykke er dreid for hånd, så farge og form varierer litt.';
            }
            $bilde = trim((string) ($v['bilde'] ?? ''));
            $d = [
                'tittel'        => $navn . ' — håndlaget keramikk | Lissom',
                'meta'          => $meta,
                'canonical'     => ROT . Lenker::vare((int) $v['id'], $navn),
                'ogTittel'      => $navn . ' — håndlaget keramikk | Lissom',
                'ogBeskrivelse' => $meta,
                'delingsbilde'  => $bilde !== '' ? ROT . '/' . ltrim($bilde, '/') : '',
                'altTekst'      => $navn . ', håndlaget keramikk fra Lissom i Tønsberg',
                'index'         => 'Index',
            ];
        }
    } catch (Throwable) {
        // Basen er nede, eller varen finnes ikke. Da staar hodet som det gjor.
    }
}

if ($d === null) {
    $id = $kart['stier'][$adresse] ?? null;
    if ($id !== null && isset($kart['sider'][$id])) {
        $d = $kart['sider'][$id];
        // Det eieren har lagret under Nettsiden → Innhold gaar foran.
        try {
            $lastBackend();
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
// Delingsbildet. Har sida sitt eget — et kurs, en kopp — er det det som
// skal opp naar lenken deles. Ellers verkstedets faste.
//
// Maalene sendes bare for det faste. De er 1200 x 675; et produktbilde er
// kvadratisk, og oppgitte maal som ikke stemmer faar Facebook til aa hoppe
// over bildet helt. Uten maal henter den bildet og finner dem selv.
$ogBilde = (string) ($d['delingsbilde'] ?? '');
$egetBilde = $ogBilde !== '';
if (!$egetBilde) { $ogBilde = ROT . '/delingsbilde.jpg'; }
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
    . ($egetBilde ? '' : '<meta property="og:image:width" content="1200">' . "\n"
        . '<meta property="og:image:height" content="675">' . "\n")
    . '<meta property="og:image:alt" content="' . $e($ogAlt) . '">' . "\n"
    . '<meta name="twitter:card" content="summary_large_image">' . "\n"
    . MERKE_SLUTT;

$html = substr_replace($html, $hode, $start, $slutt + strlen(MERKE_SLUTT) - $start);

// ── Toppen av forsida, ferdig tegnet ────────────────────────────────────
//
// Nettsida er én fil som dc-runtime bygger om til React etter at den er
// lastet. Fram til det er ferdig staar <x-dc> med «display:none», og den
// besokende ser ingenting. PageSpeed 28. august, mobil: tid til foerste
// byte 130 ms, forsinkelse for gjengivelse 2450 ms.
//
// Menylinja og heroen — alt over skjermkanten — ligger derfor ferdig tegnet
// i forside-topp.html, laget av bin/forhaandstegn.mjs fra den samme malen
// og de samme stilene. Den limes inn rett etter <body>, nettleseren tegner
// den med det samme, og skriptet i hodet bytter den mot den ekte i samme
// bilde naar dc-runtime er ferdig.
//
// Bare forsida. De andre sidene har sine egne topper, og de er ikke tegnet.
//
// Mangler fila, gaar sida ut uten. Da er den treg, ikke odelagt.
if ($adresse === '/') {
    $topp = @file_get_contents(SIDE_TOPP);
    if (is_string($topp) && $topp !== '') {
        // Teksten eieren har skrevet under Nettsiden → Innhold.
        //
        // Det som staar i fila er verdien slik den var da den ble bygget.
        // Hvert felt er merket «data-innh="Forside/0/Overskrift"», og
        // innholdet byttes mot det som ligger i basen. Endrer eieren
        // teksten, endres ogsaa forhaandstegningen — uten ny bygging.
        //
        // «??» og ikke «||», samme regel som innh() i nettsida: tommer
        // eieren et felt med vilje, skal teksten bort, ikke komme tilbake.
        try {
            $lastBackend();
            $rader = DB::alle(
                "SELECT nokkel, verdi FROM content_blocks WHERE nokkel LIKE 'Forside/0/%'"
            );
            $lagret = [];
            foreach ($rader as $r) { $lagret[(string) $r['nokkel']] = (string) $r['verdi']; }
            if ($lagret !== []) {
                $topp = (string) preg_replace_callback(
                    '~(<span class="sc-interp" data-innh="([^"]*)">)(.*?)(</span>)~su',
                    static function (array $m) use ($lagret): string {
                        $n = htmlspecialchars_decode($m[2], ENT_QUOTES);
                        if (!array_key_exists($n, $lagret)) { return $m[0]; }
                        return $m[1]
                             . htmlspecialchars($lagret[$n], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                             . $m[4];
                    },
                    $topp
                );
            }
        } catch (Throwable) {
            // Basen er nede. Da staar teksten fra byggingen, og den er
            // riktig helt til noen har endret den.
        }

        // Etter </head>, ikke bare etter foerste «<body>» i fila: ordet
        // staar ogsaa i en kommentar og i et skript lenger oppe, og en
        // strpos uten startpunkt traff kommentaren.
        $hode = strpos($html, '</head>');
        $kropp = $hode === false ? false : strpos($html, '<body>', $hode);
        if ($kropp !== false) {
            $html = substr_replace($html, '<body>' . "\n" . $topp, $kropp, strlen('<body>'));
        }
    }
}

$ut($html);
