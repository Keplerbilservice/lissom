<?php
/**
 * E-postene i det felles oppsettet, mot databasen.
 *
 * Eieren, 27. september 2026: «ikke bra nok, jeg vil ha med knapper og kort»,
 * «la gemini vurdere tekstene også», og «en avbestilling fra admin, må sende
 * epost til kunden». Migrasjon 227 la inn de godkjente tekstene med
 * overskrift, avsnitt, faktakort, knapp og sekundaer lenke.
 *
 * Testen sender noen maler gjennom Varsel::mal() og Varsel::malTilAdmin()
 * og leser det som ble lagt i varselkoen: knappen peker dit den skal, kortet
 * har radene sine og ingen tomme, avmeldingslenka er med i medlems-
 * invitasjonen, ingen «#» som adresse, signaturen foelger gruppa, og
 * ren-tekst-delen sier det samme. Til slutt avbestillingen: uten refusjon
 * ingen refusjonsrad, og ingen e-post naar malen er slaatt av.
 *
 * Kjor:  php tests/eposter.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

// Stopper testen paa en feil, skal kjoringen feile — ikke se groenn ut.
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

echo "\n── E-postene i det felles oppsettet ─────────────────────────\n";

sjekk('migrasjon 227 er kjort', DB::harKolonne('notification_templates', 'overskrift'));

// Signaturen: på for «ordre», av for «system». Det som sto, settes tilbake.
$innst = static function (string $n, ?string $v): void {
    if ($v === null) {
        DB::kjor('DELETE FROM innstillinger WHERE nokkel = :n', ['n' => $n]);
    } else {
        DB::kjor('INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
            ['n' => $n, 'v' => $v]);
    }
};
$for = [];
foreach (['epost_signatur', 'epost_signatur_ordre', 'epost_signatur_system'] as $n) {
    $v = DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => $n]);
    $for[$n] = $v === null ? null : (string) $v;
}
$innst('epost_signatur', '<p>Hilsen Monica<br>Lissom Keramikk, Teie</p>');
$innst('epost_signatur_ordre', '1');
$innst('epost_signatur_system', '0');
Config::glemBasen();

$aktivFor = [];
foreach (['ordrebekreftelse', 'fortsett', 'butikkordre', 'avbestilling', 'intern_ny_vare', 'anmeldelse'] as $n) {
    $aktivFor[$n] = DB::verdi('SELECT aktiv FROM notification_templates WHERE navn = :n', ['n' => $n]);
    DB::kjor('UPDATE notification_templates SET aktiv = 1 WHERE navn = :n', ['n' => $n]);
}

$merke = 'eposttest-' . bin2hex(random_bytes(4));
$til = static fn(string $n): string => $n . '-' . $merke . '@example.com';
$siste = static function (string $mottaker): ?array {
    return DB::en("SELECT * FROM notifications WHERE mottaker = :m AND kanal = 'epost' ORDER BY id DESC LIMIT 1", ['m' => $mottaker]);
};

// ── Kurspåmelding (gruppe ordre: signatur på) ──────────────────────────
Varsel::mal('ordrebekreftelse', ['epost' => $til('ordre')], [
    'navn' => 'Kari Nordmann', 'kurs' => 'Nybegynner dreiekurs',
    'naar' => 'lørdag 10. oktober, 09:30', 'kursinfo' => "Samling 1\nSamling 2", 'betaling' => '',
]);
$r = $siste($til('ordre'));
$html = (string) ($r['html'] ?? '');
$tekst = (string) ($r['tekst'] ?? '');
sjekk('kurspåmeldingen ligger i køen med HTML', $r !== null && $html !== '');
sjekk('… med overskrift og knapp til Min side',
    str_contains($html, 'Plassen din er klar') && str_contains($html, 'href="https://lissom.no/min-side"'));
sjekk('… og kortet har kurset og tida', str_contains($html, 'Nybegynner dreiekurs') && str_contains($html, 'lørdag 10. oktober, 09:30'));
sjekk('… ingen adresse er «#»', preg_match('~href="#"~', $html) !== 1);
sjekk('… ingen plassholdere igjen', !str_contains($html, '{{') && preg_match('~\{[a-z_]+\}~', $html) !== 1);
sjekk('… {navn} er fornavnet', str_contains($html, 'Hei Kari') && !str_contains($html, 'Kari Nordmann'));
sjekk('… signaturen er med (ordre)', str_contains($html, 'Hilsen Monica<br>Lissom Keramikk, Teie'));
sjekk('… og hilsenen står ikke to ganger', substr_count($html, 'Hilsen') === 1);
sjekk('ren tekst: overskrift, kort og knapp', str_contains($tekst, 'Plassen din er klar')
    && str_contains($tekst, 'Kurs: Nybegynner dreiekurs') && str_contains($tekst, 'https://lissom.no/min-side'));
sjekk('… og signaturen som tekst', str_contains($tekst, "-- \nHilsen Monica"));

// ── Butikkbestilling (gruppe system: signatur av) ──────────────────────
Varsel::mal('butikkordre', ['epost' => $til('butikk')], [
    'navn' => 'Kari', 'varelinjer' => '1 × Kopp — kr 350', 'sum' => 'kr 350', 'betaling' => '',
]);
$r = $siste($til('butikk'));
$html = (string) ($r['html'] ?? '');
sjekk('butikkbestillingen: tom {betaling} gir ingen tom rad', $r !== null
    && str_contains($html, '1 × Kopp') && preg_match('~Betaling\s*</td>~u', $html) !== 1);
sjekk('… uten signatur, fordi den er slått av for system', !str_contains($html, 'Hilsen Monica<br>'));
sjekk('… og ingen knapp når malen ikke har én', !str_contains($html, 'padding: 14px 28px'));

// ── Gavekortet til mottakeren: nye tekster og merkelappen ─────────────
// Eieren, 27. september 2026: stryk «verkstedtid», litt følelse av gave.
$gkFor = DB::verdi('SELECT aktiv FROM notification_templates WHERE navn = :n', ['n' => 'gavekort_mottaker']);
DB::kjor('UPDATE notification_templates SET aktiv = 1 WHERE navn = :n', ['n' => 'gavekort_mottaker']);
Varsel::mal('gavekort_mottaker', ['epost' => $til('gave')], [
    'belop' => 'kr 1 490', 'hilsen' => '«Gratulerer med dagen!»', 'kode' => 'LISSOM-TEST', 'gyldig' => '27. september 2029',
]);
$r = $siste($til('gave'));
$html = (string) ($r['html'] ?? '');
sjekk('gavekortet: overskriften er «Et gavekort til deg»', $r !== null && str_contains($html, 'Et gavekort til deg'));
sjekk('… «verkstedtid» står ikke i e-posten', $r !== null && stripos($html . (string) ($r['tekst'] ?? ''), 'verkstedtid') === false);
sjekk('… merkelappen står ved faktakortet, med full adresse',
    str_contains($html, 'src="https://lissom.no/e-post-gavelapp.png"') && str_contains($html, 'LISSOM-TEST'));
sjekk('… og bare på gavekortet', !str_contains((string) (($siste($til('butikk')) ?? [])['html'] ?? ''), 'e-post-gavelapp.png'));
DB::kjor('UPDATE notification_templates SET aktiv = :a WHERE navn = :n', ['a' => (int) $gkFor, 'n' => 'gavekort_mottaker']);

// ── Medlemsinvitasjonen: avmeldingslenka er med ───────────────────────
Varsel::mal('fortsett', ['epost' => $til('fortsett')], [
    'navn' => 'Kari', 'visste' => 'Visste du at? Du kan prøve medlemskapet.', 'avmelding' => 'https://lissom.no/avmelding?k=abc',
], null, null, '<p>gammel html som ikke skal brukes</p>');
$r = $siste($til('fortsett'));
$html = (string) ($r['html'] ?? '');
sjekk('invitasjonen bruker oppsettet, ikke den gamle HTML-en',
    !str_contains($html, 'gammel html') && str_contains($html, 'Fortsett der kurset slapp'));
sjekk('… avmeldingslenka er med', str_contains($html, 'href="https://lissom.no/avmelding?k=abc"')
    && str_contains((string) ($r['tekst'] ?? ''), 'https://lissom.no/avmelding?k=abc'));
sjekk('… «Visste du at?» står i teksten', str_contains($html, 'Visste du at?'));

// ── Anmeldelsen: kursbeviset blir en lenke ────────────────────────────
Varsel::mal('anmeldelse', ['epost' => $til('anm')], [
    'navn' => 'Kari', 'fornavn' => 'Kari', 'kurs' => 'Dreiekurs', 'lenke' => 'https://g.page/r/test/review',
    'kursbevis' => "Her er kursbeviset ditt fra Dreiekurs:\nhttps://lissom.no/kursbevis?t=1",
]);
$r = $siste($til('anm'));
$html = (string) ($r['html'] ?? '');
sjekk('anmeldelsen: knappen går til Google', str_contains($html, 'href="https://g.page/r/test/review"'));
sjekk('… kursbeviset kan trykkes på', str_contains($html, 'href="https://lissom.no/kursbevis?t=1"'));

// ── Til verkstedet: samme oppsett, uten signatur ──────────────────────
Varsel::malTilAdmin('intern_ny_vare', [
    'produsent' => 'Eirin', 'tittel' => 'Skål ' . $merke, 'pris' => 'kr 400', 'status' => 'Venter på godkjenning',
], 'medlemssalg', 999999);
$r = DB::en("SELECT * FROM notifications WHERE emne LIKE :e ORDER BY id DESC LIMIT 1",
    ['e' => '%' . $merke . '%']);
$html = (string) ($r['html'] ?? '');
sjekk('varsel om ny vare: én mal med statusen i kortet', $r !== null
    && str_contains($html, 'Venter på godkjenning') && str_contains($html, 'Skål ' . $merke));
sjekk('… uten signatur', !str_contains($html, 'Hilsen Monica<br>'));
sjekk('… den gamle malen for «gikk rett ut» er borte',
    (int) DB::verdi("SELECT COUNT(*) FROM notification_templates WHERE navn = 'intern_ny_vare_ute'") === 0);

// ── Avbestillingen ────────────────────────────────────────────────────
// Fra admin («fjern» i Påmeldte) refunderes ingenting: refusjonsfeltene er
// tomme, og da skal verken setningen eller radene om penger stå der.
Varsel::mal('avbestilling', ['epost' => $til('fjern1')], [
    'navn' => 'Ola Test', 'kurs' => 'Eposttest', 'belop' => '', 'refusjon' => '', 'refusjonstid' => '',
], 'booking', 999998);
$r = $siste($til('fjern1'));
$html = (string) ($r['html'] ?? '');
sjekk('avbestilling uten refusjon: e-post i køen', $r !== null && str_contains($html, 'Vi har nå avbestilt plassen din.'));
sjekk('… uten refusjonsrad eller løfte om penger',
    !str_contains($html, 'Refusjon') && !str_contains($html, 'Vipps') && !str_contains($html, 'Behandlingstid'));

Varsel::mal('avbestilling', ['epost' => $til('fjern2')], [
    'navn' => 'Ola Test', 'kurs' => 'Eposttest', 'belop' => 'kr 1 000',
    'refusjon' => 'Pengene er på vei tilbake til deg på Vipps.', 'refusjonstid' => 'Vanligvis innen tre virkedager',
], 'booking', 999998);
$html = (string) (($siste($til('fjern2')) ?? [])['html'] ?? '');
sjekk('avbestilling med refusjon: beløp, tid og setning er med',
    str_contains($html, 'kr 1 000') && str_contains($html, 'Vanligvis innen tre virkedager') && str_contains($html, 'på vei tilbake'));

$kode = (string) file_get_contents(dirname(__DIR__) . '/api/admin/pamelding.php');
sjekk('«fjern» i Påmeldte sender avbestillingsmalen til kunden, uten refusjon',
    str_contains($kode, "Varsel::mal('avbestilling', ['epost' => (string) \$k['epost']")
    && str_contains($kode, "'refusjon'     => '',"));

DB::kjor("UPDATE notification_templates SET aktiv = 0 WHERE navn = 'avbestilling'");
Varsel::mal('avbestilling', ['epost' => $til('fjern3')], [
    'navn' => 'Ola', 'kurs' => 'Eposttest', 'belop' => '', 'refusjon' => '', 'refusjonstid' => '',
], 'booking', 999998);
sjekk('malen slått av i Tekst maler: ingen e-post', $siste($til('fjern3')) === null);

// ── Logoen i toppen (eieren, 27. september 2026: «det bør jo være med
//    logoen vår i alle disse epostenes header, kaffekoppen er en del») ──
$ordreHtml = (string) (($siste($til('ordre')) ?? [])['html'] ?? '');
sjekk('logoen står i toppen, med absolutt adresse og alt-tekst',
    str_contains($ordreHtml, '<img src="https://lissom.no/e-post-logo-topp.png"')
    && str_contains($ordreHtml, 'alt="Lissom Keramikk &amp; Håndverk"'));
sjekk('… logofila finnes', is_file(dirname(__DIR__) . '/e-post-logo-topp.png'));
// Varslene til verkstedet (malTilAdmin) gaar gjennom det samme oppsettet.
sjekk('… i oppsettet alle e-postene bruker, ogsaa de interne',
    str_contains((string) file_get_contents(APP_DIR . '/epost/oppsett.html'), 'src="https://lissom.no/e-post-logo-topp.png"')
    && !str_contains((string) file_get_contents(APP_DIR . '/epost/oppsett.html'), 'font-size: 36px; color: #4D1D12; line-height: 1; padding-bottom: 8px;">lissom<'));

// ── Samlingskort (eieren, 27. september 2026: «jeg vil ha dreiekurs, kort
//    på dag 1, og eget på dag 2» — i e-postbekreftelsen) ────────────────
$toSamlinger = (string) json_encode([
    ['nr' => '1', 'dato' => 'Lørdag 10. oktober', 'tid' => '09:30–13:00', 'tittel' => 'Sentrere og dreie',
     'beskrivelse' => 'Du lærer å sentrere leiren.'],
    ['nr' => '2', 'dato' => 'Søndag 11. oktober', 'tid' => '09:30–13:00', 'tittel' => 'Trimme og dekorere',
     'beskrivelse' => 'Du trimmer foten.'],
], JSON_UNESCAPED_UNICODE);
Varsel::mal('ordrebekreftelse', ['epost' => $til('samling2')], [
    'navn' => 'Kari Nordmann', 'kurs' => 'Nybegynner dreiekurs',
    'naar' => 'lørdag 10. – søndag 11. oktober, 09:30', 'kursinfo' => '', 'betaling' => '',
    Varsel::SAMLINGER => $toSamlinger,
]);
$r = $siste($til('samling2'));
$html = (string) ($r['html'] ?? '');
$tekst = (string) ($r['tekst'] ?? '');
sjekk('to samlinger: to kort med tittel og dato',
    substr_count($html, 'SAMLING ') === 2 && str_contains($html, 'SAMLING 1') && str_contains($html, 'SAMLING 2')
    && str_contains($html, 'Sentrere og dreie') && str_contains($html, 'Søndag 11. oktober kl. 09:30–13:00'));
sjekk('… under mellomtittelen «Dette skal vi gjøre»', str_contains($html, 'Dette skal vi gjøre'));
sjekk('… og uten «Tid»-rad i faktakortet', !str_contains($html, 'lørdag 10. – søndag 11. oktober, 09:30'));
sjekk('… ren tekst har samlingene som linjer', str_contains($tekst, 'Samling 2 – Trimme og dekorere')
    && str_contains($tekst, 'Søndag 11. oktober kl. 09:30–13:00'));
sjekk('… ingen plassholdere igjen', !str_contains($html, '{{') && !str_contains($html, 'samlingskort_json'));

Varsel::mal('ordrebekreftelse', ['epost' => $til('samling1')], [
    'navn' => 'Kari Nordmann', 'kurs' => 'Paint on Pots',
    'naar' => 'torsdag 1. oktober, 17:00', 'kursinfo' => '', 'betaling' => '',
    Varsel::SAMLINGER => (string) json_encode([['nr' => '1', 'dato' => 'Torsdag 1. oktober', 'tid' => '17:00–19:00', 'tittel' => 'Mal', 'beskrivelse' => '']]),
]);
$html = (string) (($siste($til('samling1')) ?? [])['html'] ?? '');
sjekk('én samling: som før, med «Tid»-raden og uten samlingskort',
    str_contains($html, 'torsdag 1. oktober, 17:00') && !str_contains($html, 'SAMLING 1') && !str_contains($html, 'Dette skal vi gjøre'));

// ── Rydd ──────────────────────────────────────────────────────────────
DB::kjor('DELETE FROM notifications WHERE mottaker LIKE :m OR emne LIKE :e',
    ['m' => '%' . $merke . '%', 'e' => '%' . $merke . '%']);
foreach ($aktivFor as $n => $a) {
    if ($a !== null) {
        DB::kjor('UPDATE notification_templates SET aktiv = :a WHERE navn = :n', ['a' => (int) $a, 'n' => $n]);
    }
}
foreach ($for as $n => $v) {
    $innst($n, $v);
}
Config::glemBasen();

// Eieren, 27. september 2026: «det står du er påmeldt Nybegynner dreiekurs,
// det er ikke stor N her».
sjekk('kursnavnet får liten forbokstav midt i setningen',
    Varsel::flett('Du er påmeldt {kurs}', ['kurs' => 'Nybegynner dreiekurs']) === 'Du er påmeldt nybegynner dreiekurs');
sjekk('… men beholder den først i en setning og alene i kortet',
    Varsel::flett('Takk. {kurs} starter snart', ['kurs' => 'Nybegynner dreiekurs']) === 'Takk. Nybegynner dreiekurs starter snart'
    && Varsel::flett('{kurs}', ['kurs' => 'Nybegynner dreiekurs']) === 'Nybegynner dreiekurs');
sjekk('… og navn med egne store bokstaver står som de er',
    Varsel::flett('Du er påmeldt {kurs}', ['kurs' => 'Paint on Pots']) === 'Du er påmeldt Paint on Pots');

echo "\n{$ok} i orden, {$feil} feil\n";
$ferdig = true;
exit($feil > 0 ? 1 : 0);
