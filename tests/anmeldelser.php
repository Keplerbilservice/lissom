<?php
/**
 * Google-anmeldelsene paa forsida, med et stubbet svar fra Places.
 *
 * Eieren, 29. september 2026: nederst paa forsida, bare 4 og 5 stjerner,
 * maks 4 kort. Snittet og antallet er Googles tall for alle anmeldelsene.
 * Forsida spoer aldri Google selv; uten data vises ingen seksjon.
 *
 * Kjor:  php tests/anmeldelser.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
if (!defined('NETT_ROT')) { define('NETT_ROT', dirname(__DIR__)); }
require_once APP_DIR . '/nett/nett.php';

$ferdig = false;
register_shutdown_function(static function () use (&$ferdig): void {
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

echo "\n── Google-anmeldelser ───────────────────────────────────────\n";

$rydd = static function (): void {
    DB::kjor("DELETE FROM innstillinger WHERE nokkel IN ('google_places_nokkel','google_place_id','google_anmeldelser','google_anmeldelser_feil')");
    DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/anmeldelser'");
    Config::glemBasen();
};
$rydd();

$forside = static function (): string {
    $_SERVER['REQUEST_URI'] = '/';
    $s = require __DIR__ . '/../app/nett/sider/forside.php';
    return (string) $s['kropp'];
};

// Uten noekkel: ingenting hentes, ingen seksjon.
$kall = [];
$stubb = static function (string $metode, string $url, ?array $kropp, array $hode) use (&$kall): array {
    $kall[] = [$metode, $url, $hode];
    if (str_contains($url, 'searchText')) {
        return ['status' => 200, 'json' => ['places' => [['id' => 'ChIJtest123', 'displayName' => ['text' => 'Lissom Keramikk']]]]];
    }
    $r = static fn(int $n, string $t, string $navn, string $dager) => [
        'rating' => $n, 'text' => ['text' => $t . ' (oversatt)'], 'originalText' => ['text' => $t],
        'authorAttribution' => ['displayName' => $navn, 'uri' => 'https://www.google.com/maps/contrib/1'],
        'publishTime' => gmdate('Y-m-d\TH:i:s\Z', time() - (int) $dager * 86400),
        'relativePublishTimeDescription' => 'for lenge siden',
    ];
    return ['status' => 200, 'json' => [
        'rating' => 4.86, 'userRatingCount' => 87, 'googleMapsUri' => 'https://maps.google.com/?cid=42',
        'reviews' => [
            $r(5, 'Fantastisk dreiekurs', 'Kari N.', '15'),
            $r(2, 'Ikke fornøyd', 'Ola S.', '3'),
            $r(4, 'Koselig verksted', 'Per H.', '40'),
            $r(5, 'Beste gaven', 'Liv M.', '100'),
            $r(5, 'Anbefales', 'Siri A.', '200'),
        ],
    ]];
};
$r = Anmeldelser::oppdater($stubb);
sjekk('uten nøkkel: hentes ikke', !$r['ok'] && $kall === [], $r['feil']);
sjekk('uten data: ingen seksjon på forsiden', !str_contains($forside(), 'data-anmeldelser'));

// Med noekkel: sted-ID slaas opp én gang, og lagres.
Anmeldelser::lagre('google_places_nokkel', 'test-nokkel');
Config::glemBasen();
$r = Anmeldelser::oppdater($stubb);
sjekk('med nøkkel: hentet', $r['ok'], $r['feil']);
sjekk('sted-ID slått opp med searchText', str_contains($kall[0][1] ?? '', 'places:searchText'));
sjekk('detaljene ber om rating, antall, anmeldelser og lenke',
    in_array('X-Goog-FieldMask: rating,userRatingCount,reviews,googleMapsUri', $kall[1][2] ?? [], true));
sjekk('nøkkelen sendes i hodet, ikke i adressen', !str_contains($kall[1][1] ?? '', 'test-nokkel')
    && in_array('X-Goog-Api-Key: test-nokkel', $kall[1][2] ?? [], true));
sjekk('sted-ID lagret', DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'google_place_id'") === 'ChIJtest123');

$kall = [];
Anmeldelser::oppdater($stubb);
sjekk('andre gang: ikke slått opp på nytt', count($kall) === 1 && !str_contains($kall[0][1], 'searchText'));

$d = Anmeldelser::lagret();
sjekk('bare 4 og 5 stjerner', array_filter($d['kort'], static fn($k) => $k['stjerner'] < 4) === []);
sjekk('maks 4 kort', count($d['kort']) === 4, (string) count($d['kort']));
sjekk('teksten er den originale, ikke oversatt', $d['kort'][0]['tekst'] === 'Fantastisk dreiekurs');
sjekk('snitt og antall er Googles tall for alle', $d['rating'] === 4.9 && $d['antall'] === 87);

$html = $forside();
sjekk('seksjonen vises på forsiden', str_contains($html, 'data-anmeldelser'));
sjekk('kicker og overskrift', str_contains($html, 'Google-anmeldelser') && str_contains($html, 'Hva sier andre om oss'));
sjekk('snittlinja', str_contains($html, '4,9 av 5 · 87 anmeldelser på Google'));
sjekk('fire kort', substr_count($html, 'data-anm-kort') === 4);
sjekk('2-stjerners vises ikke', !str_contains($html, 'Ikke fornøyd'));
sjekk('tiden regnes ut på norsk', str_contains($html, 'for 2 uker siden'));
sjekk('«Les alle på Google» går til Google-profilen', str_contains($html, 'Les alle på Google')
    && str_contains($html, 'href="https://maps.google.com/?cid=42"'));
sjekk('Google er kreditert', str_contains($html, 'Kilde: Google Maps'));
sjekk('ingen bilder fra Google', !preg_match('~<img[^>]+googleusercontent~', $html));

// Google svarer med feil: forrige svar blir staaende.
$feilStubb = static fn(): array => ['status' => 403, 'json' => ['error' => ['message' => 'API key not valid.']]];
$r = Anmeldelser::oppdater($feilStubb);
sjekk('feil fra Google: meldingen huskes', !$r['ok'] && Anmeldelser::status()['feil'] === 'API key not valid.');
sjekk('feil fra Google: forrige svar står', str_contains($forside(), '4,9 av 5'));

// Bryteren under Synlighet.
DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/anmeldelser', 'nei') ON DUPLICATE KEY UPDATE verdi = 'nei'");
(fn() => self::$lagret = null)->bindTo(null, Nett::class)();
sjekk('bryteren av: ingen seksjon', !str_contains($forside(), 'data-anmeldelser'));

$rydd();
$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
