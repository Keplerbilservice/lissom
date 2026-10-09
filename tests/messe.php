<?php
/**
 * Messevisningen (/messe) og skjemaet bak QR-koden (/bedrift/tilbud)
 * (eieren, GO 9. oktober 2026: «legg dette ut på en link, med samme PIN som kassen»).
 *
 *   A  /messe uten opplåsing viser bare PIN-skjermen; feil PIN avvises på
 *      serveren; kassens PIN gir en informasjonskapsel i 30 dager, og da
 *      kommer visningen. Endret PIN eller tuklet kapsel låser igjen. Ingen
 *      PIN i klientkoden. For mange forsøk = 429.
 *   B  /bedrift/tilbud lager en forespørsel (enquiries) med e-post og
 *      telefon, som står i «Må gjøres» i ny admin; Svar og Ferdig virker.
 *      E-posten til verkstedet legges i køen (ingen sendes).
 *   C  Bryteren «Vis/messe» = nei: /messe, api/messe.php og skjemaet svarer 404.
 *
 * Ekte endepunkter (php -S med tests/nettleser/ruter.php) mot en isolert
 * testbase. Ingen ekte e-post eller SMS.
 *   php tests/messe.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$rot = dirname(__DIR__);
krev_testdatabase($rot);
require $rot . '/app/bootstrap.php';

$s = stream_socket_server('tcp://127.0.0.1:0'); $adr = stream_socket_get_name($s, false); fclose($s);
$port = (int) substr($adr, strrpos($adr, ':') + 1);
$logg = sys_get_temp_dir() . '/lissom-messe-' . bin2hex(random_bytes(4)) . '.log';
$server = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-S', '127.0.0.1:' . $port, '-t', $rot, $rot . '/tests/nettleser/ruter.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $logg, 'a'], 2 => ['file', $logg, 'a']], $pp, $rot);
fclose($pp[0]);
$klar = false;
for ($v = 0; $v < 60; $v++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); if ($f) { fclose($f); $klar = true; break; } usleep(50000); }

$tag = 'MESSE-' . strtoupper(bin2hex(random_bytes(3)));
$medlemmer = []; $foresp = []; $ferdig = false;
$bryterFor = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/messe'");

register_shutdown_function(static function () use (&$ferdig, &$medlemmer, &$foresp, $server, $bryterFor, $logg): void {
    if (is_resource($server)) { proc_terminate($server); }
    try {
        if ($bryterFor === null || $bryterFor === false) { DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/messe'"); }
        else { DB::kjor("UPDATE content_blocks SET verdi = :v WHERE nokkel = 'Vis/messe'", ['v' => (string) $bryterFor]); }
    } catch (Throwable $e) {}
    $m = implode(',', array_map('intval', $medlemmer ?: [0]));
    $f = implode(',', array_map('intval', $foresp ?: [0]));
    foreach ([
        "DELETE FROM notifications WHERE ref_type = 'enquiry' AND ref_id IN ($f)",
        "DELETE FROM foresporsel_svar WHERE enquiry_id IN ($f)",
        "DELETE FROM enquiries WHERE id IN ($f)",
        "DELETE FROM sessions WHERE member_id IN ($m)",
        "DELETE FROM audit_log WHERE member_id IN ($m)",
        "DELETE FROM rate_limits WHERE nokkel LIKE 'messe-pin%' OR nokkel LIKE 'foresporsel%'",
        "DELETE FROM members WHERE id IN ($m)",
    ] as $sql) {
        try { DB::kjor($sql); } catch (Throwable $e) { echo '  (opprydding: ' . $e->getMessage() . ")\n"; }
    }
    @unlink($logg);
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $n, bool $v, string $mer = ''): void
{ global $ok, $feil; if ($v) { $ok++; echo "  OK    $n\n"; } else { $feil++; echo "  FEIL  $n" . ($mer !== '' ? "  — $mer" : '') . "\n"; } }

/** @return array{0:int,1:mixed,2:string,3:string} status, json, hoder, kropp */
function kall(string $sti, ?array $data = null, string $cookie = ''): array
{
    global $port;
    $c = curl_init('http://127.0.0.1:' . $port . $sti);
    $hode = ['Origin: ' . Config::nettsted()];
    if ($cookie !== '') { $hode[] = 'Cookie: ' . $cookie; }
    $valg = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true];
    if ($data !== null) {
        $hode[] = 'Content-Type: application/json';
        $valg[CURLOPT_POST] = true;
        $valg[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    $valg[CURLOPT_HTTPHEADER] = $hode;
    curl_setopt_array($c, $valg);
    $raa = (string) curl_exec($c);
    $hl = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    $st = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    $kropp = substr($raa, $hl);
    return [$st, json_decode($kropp, true), substr($raa, 0, $hl), $kropp];
}
$kapsel = static function (string $hoder): ?string {
    return preg_match('/^Set-Cookie:\s*(lissom_messe=[^;\r\n]+)/mi', $hoder, $m) === 1 ? $m[1] : null;
};
$tekst = static fn(array $r): string => $r[0] . ' ' . mb_substr($r[3], 0, 200);

try {
    sjekk('HTTP-serveren klar', $klar);
    sjekk('migrasjon 263 er kjørt (kasse-PIN)', KasseTilgang::klar());
    DB::kjor("DELETE FROM content_blocks WHERE nokkel = 'Vis/messe'");
    DB::kjor("DELETE FROM rate_limits WHERE nokkel LIKE 'messe-pin%' OR nokkel LIKE 'foresporsel%'");

    $nytt = static function (array $felt) use (&$medlemmer, $tag): int {
        $id = DB::settInn('members', $felt + ['epost' => strtolower($tag) . '-' . bin2hex(random_bytes(3)) . '@lissom.test', 'status' => 'ingen']);
        $medlemmer[] = $id;
        return $id;
    };
    // En pin som ikke er i bruk i testbasen.
    $pin = null;
    for ($f = 0; $f < 50 && $pin === null; $f++) {
        $p = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        if (KasseTilgang::personForPin($p) === null) { $pin = $p; }
    }
    $monica = $nytt(['navn' => 'Monica ' . $tag, 'brukernavn' => strtolower($tag), 'rolle' => 'admin']);
    KasseTilgang::settPin($monica, $pin);

    echo "\n── A  /messe krever kassens PIN ───────────────────────────────\n";
    $r = kall('/messe');
    sjekk('uten opplåsing: PIN-skjermen', $r[0] === 200 && str_contains($r[3], 'Skriv PIN-koden for messevisning'), $tekst($r));
    sjekk('… ikke visningen', !str_contains($r[3], 'Grenseløs, Tønsberg.') && !str_contains($r[3], 'grenselos.mp4'));
    sjekk('… noindex (meta og X-Robots-Tag)', str_contains($r[3], 'noindex') && stripos($r[2], 'X-Robots-Tag: noindex') !== false);
    sjekk('… ingen PIN i klientkoden', !str_contains($r[3], '"' . $pin . '"') && !str_contains($r[3], "'" . $pin . "'") && !str_contains($r[3], '1234'));

    $feilPin = $pin === '0000' ? '0001' : '0000';
    if (KasseTilgang::personForPin($feilPin) !== null) { $feilPin = str_pad((string) (((int) $pin + 5000) % 10000), 4, '0', STR_PAD_LEFT); }
    $r = kall('/api/messe.php', ['pin' => $feilPin]);
    sjekk('feil PIN: 400 «Feil PIN – prøv igjen», ingen kapsel', $r[0] === 400 && ($r[1]['feilPin'] ?? false) === true
        && ($r[1]['feil'] ?? '') === 'Feil PIN – prøv igjen' && $kapsel($r[2]) === null, $tekst($r));
    $r = kall('/api/messe.php', ['pin' => 'abcd']);
    sjekk('ugyldig PIN: 400', $r[0] === 400 && $kapsel($r[2]) === null, $tekst($r));

    $r = kall('/api/messe.php', ['pin' => $pin]);
    $k = $kapsel($r[2]);
    sjekk('kassens PIN: 200 og kapsel', $r[0] === 200 && $k !== null, $tekst($r));
    sjekk('… kapselen varer 30 dager, HttpOnly', preg_match('/Max-Age=(\d+)/i', $r[2], $mm) === 1 && abs((int) $mm[1] - 30 * 86400) < 120
        && stripos($r[2], 'httponly') !== false, $r[2]);
    sjekk('… PIN-en står ikke i kapselen', $k !== null && !str_contains($k, $pin . '.') && !str_contains($k, '.' . $pin));
    sjekk('… revidert (messe_laast_opp)', (int) DB::verdi("SELECT COUNT(*) FROM audit_log WHERE handling = 'messe_laast_opp' AND objekt_id = :m", ['m' => $monica]) === 1);

    $r = kall('/messe', null, (string) $k);
    sjekk('med kapselen: visningen', $r[0] === 200 && str_contains($r[3], 'Grenseløs, Tønsberg.') && str_contains($r[3], 'Hva vil du se?'), $tekst($r));
    sjekk('… QR lokalt (vendor), ingen CDN', str_contains($r[3], '/vendor/qrcode-2.0.4.js') && !str_contains($r[3], 'cdnjs') && !str_contains($r[3], 'fonts.googleapis'));
    sjekk('… filmen og bildene fra assets/messe', str_contains($r[3], '/assets/messe/grenselos.mp4') && str_contains($r[3], '/assets/messe/monica-verksted.jpg'));
    sjekk('… ingen «NY»-merker eller prototypedeler', !str_contains($r[3], 'class="ny"') && !str_contains($r[3], 'Forslaget') && !str_contains($r[3], 'Ok, bygg det'));
    foreach (['assets/messe/grenselos.mp4', 'assets/messe/monica-verksted.jpg', 'assets/messe/monica-verksted.jpg.webp',
              'assets/messe/samtale-mild.jpg', 'assets/messe/produkt-reklame.jpg.webp', 'vendor/qrcode-2.0.4.js'] as $fil) {
        sjekk('… finnes: ' . $fil, is_file($rot . '/' . $fil));
    }
    sjekk('… filmen er under 8 MB', filesize($rot . '/assets/messe/grenselos.mp4') <= 8 * 1024 * 1024);

    [$navn, $verdi] = explode('=', (string) $k, 2);
    $tuklet = $navn . '=' . substr($verdi, 0, -1) . (substr($verdi, -1) === 'a' ? 'b' : 'a');
    $r = kall('/messe', null, $tuklet);
    sjekk('tuklet kapsel: PIN-skjermen', str_contains($r[3], 'Skriv PIN-koden for messevisning'), $tekst($r));

    $nyPin = str_pad((string) (((int) $pin + 1) % 10000), 4, '0', STR_PAD_LEFT);
    if (KasseTilgang::personForPin($nyPin) !== null) { $nyPin = str_pad((string) (((int) $pin + 7) % 10000), 4, '0', STR_PAD_LEFT); }
    KasseTilgang::settPin($monica, $nyPin);
    $r = kall('/messe', null, (string) $k);
    sjekk('endret PIN: den gamle kapselen gjelder ikke', str_contains($r[3], 'Skriv PIN-koden for messevisning'), $tekst($r));
    KasseTilgang::settPin($monica, null);
    $r = kall('/api/messe.php', ['pin' => $nyPin]);
    sjekk('fjernet PIN: avvises', $r[0] === 400, $tekst($r));

    DB::kjor("DELETE FROM rate_limits WHERE nokkel LIKE 'messe-pin%'");
    $siste = 0;
    for ($f = 0; $f < Messe::PIN_FORSOK + 1; $f++) { $siste = kall('/api/messe.php', ['pin' => $feilPin])[0]; }
    sjekk('for mange forsøk: 429', $siste === 429, (string) $siste);
    DB::kjor("DELETE FROM rate_limits WHERE nokkel LIKE 'messe-pin%'");

    echo "\n── B  /bedrift/tilbud lager forespørsel ───────────────────────\n";
    $r = kall('/bedrift/tilbud');
    sjekk('skjemaet: 200, noindex', $r[0] === 200 && str_contains($r[3], 'Be om tilbud') && stripos($r[2], 'X-Robots-Tag: noindex') !== false, $tekst($r));
    foreach (['Navn', 'Bedrift', 'Telefon', 'E-post', 'Servise', 'Firmagaver', 'Firmakveld / kurs', 'Medlemskap',
              'Hva (f.eks. kopp, asjett)', 'Antall', '+ Legg til linje', 'Kommentar', 'Send', 'Takk! Monica tar kontakt.', '/api/foresporsel.php'] as $t) {
        sjekk('… har «' . $t . '»', str_contains($r[3], $t));
    }
    $r = kall('/bedrift');
    sjekk('/bedrift (bedriftssiden) er urørt', $r[0] === 200 && !str_contains($r[3], 'Fortell oss litt om dere, så tar Monica kontakt.'), $tekst($r));

    $epost = strtolower($tag) . '@firma.test';
    $melding = "Bedrift: Firma {$tag}\n\nTelefon: 941 34 601\n\nInteressert i: Servise, Firmagaver\n\nHva og antall:\n- kopp: 40\n- asjett: 40\n\nKommentar:\nHvit glasur";
    $r = kall('/api/foresporsel.php', ['navn' => 'Kari ' . $tag, 'kontakt' => $epost, 'telefon' => '941 34 601',
        'type' => 'Bedrift: Servise, Firmagaver', 'antall' => '', 'melding' => $melding]);
    sjekk('sendt: 200', $r[0] === 200 && ($r[1]['ok'] ?? false) === true, $tekst($r));
    $id = (int) ($r[1]['id'] ?? 0);
    if ($id > 0) { $foresp[] = $id; }
    $rad = DB::en('SELECT * FROM enquiries WHERE id = :i', ['i' => $id]) ?? [];
    sjekk('… forespørselen lagret med e-post og telefon', ($rad['epost'] ?? '') === $epost && ($rad['telefon'] ?? '') === normaliser_telefon('94134601')
        && ($rad['type'] ?? '') === 'Bedrift: Servise, Firmagaver' && str_contains((string) ($rad['melding'] ?? ''), '- kopp: 40')
        && ($rad['status'] ?? '') === 'ubesvart', json_encode($rad, JSON_UNESCAPED_UNICODE));
    sjekk('… e-post til verkstedet i køen (intern_ny_foresporsel), ikke sendt herfra',
        (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = 'enquiry' AND ref_id = :i", ['i' => $id]) >= 1);

    $admin = $nytt(['navn' => 'Admin ' . $tag, 'rolle' => 'admin']);
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $t), 'maate' => 'passord', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $adminK = 'lissom_sesjon=' . $t;
    $r = kall('/api/admin/oversikt.php', null, $adminK);
    $sak = array_values(array_filter($r[1]['maGjores'] ?? [], static fn($s) => ($s['type'] ?? '') === 'henvendelse' && (int) ($s['id'] ?? 0) === $id))[0] ?? null;
    sjekk('… står i «Må gjøres» i ny admin', $sak !== null && str_contains((string) $sak['tittel'], 'Kari ' . $tag), json_encode($sak, JSON_UNESCAPED_UNICODE));

    $r = kall('/api/admin/foresporsler.php', ['handling' => 'svar', 'id' => $id, 'tekst' => 'Hei! Vi tar en prat. ' . $tag], $adminK);
    sjekk('Svar virker', $r[0] === 200, $tekst($r));
    $r = kall('/api/admin/foresporsler.php', ['id' => $id, 'status' => 'besvart'], $adminK);
    sjekk('Ferdig virker (besvart, ute av «Må gjøres»)', $r[0] === 200 && DB::verdi('SELECT status FROM enquiries WHERE id = :i', ['i' => $id]) === 'besvart', $tekst($r));
    $r = kall('/api/admin/oversikt.php', null, $adminK);
    sjekk('… ikke lenger i «Må gjøres»', array_filter($r[1]['maGjores'] ?? [], static fn($s) => ($s['type'] ?? '') === 'henvendelse' && (int) ($s['id'] ?? 0) === $id) === []);

    $r = kall('/api/foresporsel.php', ['navn' => 'Uten kontakt', 'kontakt' => '', 'melding' => 'x']);
    sjekk('uten kontakt: avvist (400)', $r[0] === 400, $tekst($r));

    echo "\n── C  Bryteren «Vis/messe» ────────────────────────────────────\n";
    DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/messe', 'nei') ON DUPLICATE KEY UPDATE verdi = 'nei'");
    sjekk('av: /messe 404', kall('/messe')[0] === 404);
    sjekk('av: api/messe.php 404', kall('/api/messe.php', ['pin' => $feilPin])[0] === 404);
    sjekk('av: /bedrift/tilbud 404', kall('/bedrift/tilbud')[0] === 404);
    DB::kjor("UPDATE content_blocks SET verdi = 'ja' WHERE nokkel = 'Vis/messe'");
    sjekk('på igjen: /messe 200', kall('/messe')[0] === 200);
} catch (Throwable $e) {
    sjekk('uventet unntak', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

$ferdig = true;
echo "\n  $ok OK, $feil FEIL\n";
exit($feil === 0 ? 0 : 1);
