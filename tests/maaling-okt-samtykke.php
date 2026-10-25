<?php
/**
 * Kjøpsmålingen fra serveren: økt og samtykke til GA4.
 *
 * Sporingseksperten, 4. oktober 2026: serverkjøpene havnet som «Unassigned»
 * i GA4 fordi økt-ID-en bare ble lest fra det gamle cookieformatet, og
 * Measurement Protocol sendte ikke samtykket.
 *
 *   1. lesOkt(): begge formatene av _ga_<id> (GS1 og GS2) gir økt-ID og -nummer.
 *   2. sporingFraNettleser(): sid, sn, gclid og «sam» = «ja» lagres; uten
 *      cookies lagres ingenting.
 *   3. ga4Kropp(): consent GRANTED bare med «sam» = «ja», ellers DENIED og
 *      ingen brukerdata. session_id og engagement_time_msec med.
 *   4. refund: samme transaction_id og beløp.
 *
 * Kjor:  php tests/maaling-okt-samtykke.php   (trenger ingen database)
 */
declare(strict_types=1);

require __DIR__ . '/../app/lib/maaling.php';

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

// ── 1. Cookieformatene ──────────────────────────────────────────────────
$ny = Maaling::lesOkt('GS2.1.s1759500000$o3$g1$t1759500100$j60$l0$h0');
sjekk('nytt format (GS2): økt-ID', ($ny['sid'] ?? '') === '1759500000', json_encode($ny));
sjekk('nytt format (GS2): økt-nummer', ($ny['sn'] ?? 0) === 3);
$gml = Maaling::lesOkt('GS1.1.1759400000.7.1.1759400100.0.0.0');
sjekk('gammelt format (GS1): økt-ID', ($gml['sid'] ?? '') === '1759400000', json_encode($gml));
sjekk('gammelt format (GS1): økt-nummer', ($gml['sn'] ?? 0) === 7);
sjekk('nytt format med bare to felt', (Maaling::lesOkt('GS2.1.s1759500000$o12')['sn'] ?? 0) === 12);
sjekk('søppel gir null', Maaling::lesOkt('GS2.1.xyz') === null && Maaling::lesOkt('') === null);

// ── 2. Fra nettleseren ──────────────────────────────────────────────────
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'test';
$_COOKIE = [
    '_ga'          => 'GA1.1.123456789.1759000000',
    '_ga_ABC123XY' => 'GS2.1.s1759500000$o4$g1$t1759500100$j60$l0$h0',
    '_gcl_aw'      => 'GCL.1759500000.Cj0KCQjw_abc-123XYZ',
];
$s = json_decode(Maaling::sporingFraNettleser(), true);
sjekk('cid lest', ($s['cid'] ?? '') === '123456789.1759000000');
sjekk('sid fra nytt format', ($s['sid'] ?? '') === '1759500000', json_encode($s));
sjekk('sn fra nytt format', ($s['sn'] ?? 0) === 4);
sjekk('gclid tatt vare på', ($s['gclid'] ?? '') === 'Cj0KCQjw_abc-123XYZ');
sjekk('samtykke lagret som «ja»', ($s['sam'] ?? '') === 'ja');
$_COOKIE = ['_ga' => 'GA1.1.1.2', '_ga_X' => 'GS1.1.1759400000.2.0.1759400000.0.0.0'];
$s2 = json_decode(Maaling::sporingFraNettleser(), true);
sjekk('sid fra gammelt format', ($s2['sid'] ?? '') === '1759400000' && ($s2['sn'] ?? 0) === 2);
sjekk('ingen gclid uten cookie', !isset($s2['gclid']));
$_COOKIE = [];
sjekk('uten cookies lagres ingenting', Maaling::sporingFraNettleser() === '');

// ── 3. Samtykke i GA4-kroppen ───────────────────────────────────────────
$hvem = ['epost' => 'Kari@Eksempel.no', 'telefon' => '900 00 000', 'navn' => 'Kari Nordmann', 'medlem' => 0, 'tittel' => '', 'slug' => ''];
$p = ['transaction_id' => 'L42', 'value' => 100.0, 'currency' => 'NOK'];
$k = Maaling::ga4Kropp($s, 'purchase', $p, $hvem);
sjekk('consent GRANTED med samtykke', ($k['consent'] ?? null) === ['ad_user_data' => 'GRANTED', 'ad_personalization' => 'GRANTED'], json_encode($k['consent'] ?? null));
sjekk('session_id med', ($k['events'][0]['params']['session_id'] ?? '') === '1759500000');
sjekk('engagement_time_msec med', ($k['events'][0]['params']['engagement_time_msec'] ?? 0) > 0);
sjekk('brukerdata hashet med samtykke', ($k['user_data']['sha256_email_address'] ?? '') === hash('sha256', 'kari@eksempel.no'));
$uten = $s; unset($uten['sam']);
$k2 = Maaling::ga4Kropp($uten, 'purchase', $p, $hvem);
sjekk('consent DENIED uten lagret samtykke', ($k2['consent'] ?? null) === ['ad_user_data' => 'DENIED', 'ad_personalization' => 'DENIED']);
sjekk('ingen brukerdata uten samtykke', !isset($k2['user_data']));
$nei = $s; $nei['sam'] = 'nei';
sjekk('consent DENIED ved «nei»', (Maaling::ga4Kropp($nei, 'purchase', $p, $hvem)['consent']['ad_user_data'] ?? '') === 'DENIED');
sjekk('aldri GRANTED for noe annet enn «ja»', Maaling::ga4Samtykke(['sam' => 'JA'])['ad_user_data'] === 'DENIED'
    && Maaling::ga4Samtykke([])['ad_personalization'] === 'DENIED');
sjekk('ingen kropp uten client_id', Maaling::ga4Kropp(['sam' => 'ja'], 'purchase', $p, $hvem) === []);

// ── 4. Refusjon ─────────────────────────────────────────────────────────
$r = Maaling::ga4Kropp($s, 'refund', ['transaction_id' => 'L42', 'value' => 50.0, 'currency' => 'NOK'], $hvem);
sjekk('refund: hendelsesnavn', ($r['events'][0]['name'] ?? '') === 'refund');
sjekk('refund: samme transaction_id og beløp', ($r['events'][0]['params']['transaction_id'] ?? '') === 'L42'
    && ($r['events'][0]['params']['value'] ?? 0) === 50.0);
$kilde = (string) file_get_contents(__DIR__ . '/../app/lib/booking.php') . (string) file_get_contents(__DIR__ . '/../app/lib/vipps.php');
sjekk('refusjon måles både fra appen og fra webhooken', substr_count($kilde, 'Maaling::refusjon(') === 2);

// ── 5. Kilden fra landingsadressen (lissom_kilde) ───────────────────────
// 25. oktober 2026: kunder fra Facebook/Instagram starter kjøpet i appens
// nettleser og kommer tilbake fra Vipps i en annen. Kilden lagres på
// betalingen ved start, og brukes i serverens kjøp.
$tMs = (time() - 60) * 1000;
$k = Maaling::lesKilde((string) json_encode(['utm_source' => 'facebook', 'utm_medium' => 'paid_social',
    'utm_campaign' => 'Høst<script>', 'fbclid' => 'IwAR2F4-dbP0l7Mn1IawQQGC', 'gclid' => 'kort', 't' => $tMs]));
sjekk('kilde: utm lest og renset', ($k['utm'] ?? []) === ['source' => 'facebook', 'medium' => 'paid_social', 'campaign' => 'Høstscript'], json_encode($k));
sjekk('kilde: fbclid urørt', $k['fbclid'] === 'IwAR2F4-dbP0l7Mn1IawQQGC');
sjekk('kilde: ugyldig gclid kastet', $k['gclid'] === '');
sjekk('kilde: tidspunkt i ms', $k['t'] === $tMs);
sjekk('kilde: søppel gir tomt', Maaling::lesKilde('{x')['utm'] === [] && Maaling::lesKilde('')['fbclid'] === '');
sjekk('kilde: tid fra framtida kastet', Maaling::lesKilde((string) json_encode(['fbclid' => 'IwAR2F4-dbP0l7Mn', 't' => (time() + 999999) * 1000]))['t'] === 0);

$_COOKIE = [
    '_ga'          => 'GA1.1.555.1759000000',
    'lissom_kilde' => (string) json_encode(['utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'utm_campaign' => 'host',
        'fbclid' => 'IwAR2F4-dbP0l7Mn1IawQQGC', 'gclid' => 'Cj0KCQjw_fra-url', 't' => $tMs]),
];
$s3 = json_decode(Maaling::sporingFraNettleser(), true);
sjekk('sporing: utm lagret på betalingen', ($s3['utm']['source'] ?? '') === 'facebook' && ($s3['utm']['campaign'] ?? '') === 'host', json_encode($s3));
sjekk('sporing: fbc bygd fra fbclid (fb.1.<ms>.<fbclid>)', ($s3['fbc'] ?? '') === 'fb.1.' . $tMs . '.IwAR2F4-dbP0l7Mn1IawQQGC', (string) ($s3['fbc'] ?? ''));
sjekk('sporing: gclid fra adressen når _gcl_aw mangler', ($s3['gclid'] ?? '') === 'Cj0KCQjw_fra-url');
$_COOKIE['_fbc'] = 'fb.1.1700000000000.FRA-PIKSELEN';
$_COOKIE['_gcl_aw'] = 'GCL.1759500000.Cj0KCQjw_fra-cookie';
$s4 = json_decode(Maaling::sporingFraNettleser(), true);
sjekk('sporing: pikselens _fbc går foran', ($s4['fbc'] ?? '') === 'fb.1.1700000000000.FRA-PIKSELEN');
sjekk('sporing: _gcl_aw går foran', ($s4['gclid'] ?? '') === 'Cj0KCQjw_fra-cookie');
$_COOKIE = ['annet' => '1'];
sjekk('uten samtykke-cookies og uten kilde lagres ingenting', Maaling::sporingFraNettleser() === '');

// ── 6. Kjøpet til GA4: Ads-hendelse og campaign_details ─────────────────
$medOkt = $s + ['utm' => ['source' => 'facebook', 'medium' => 'paid_social', 'campaign' => 'host']];
$kk = Maaling::ga4KjopKropp($medOkt, 'L77', 1590.0, 'booking', 'Dreiekurs', $hvem);
$navn = array_map(static fn(array $e): string => $e['name'], $kk['events'] ?? []);
sjekk('kjøp med økt: purchase + booking_fullfort, ingen campaign_details', $navn === ['purchase', 'booking_fullfort'], json_encode($navn));
sjekk('Ads-hendelsen: samme transaction_id og beløp', ($kk['events'][1]['params']['transaction_id'] ?? '') === 'L77'
    && ($kk['events'][1]['params']['value'] ?? 0) === 1590.0 && ($kk['events'][1]['params']['session_id'] ?? '') === '1759500000');
$utenOkt = $medOkt; unset($utenOkt['sid'], $utenOkt['sn']);
$ku = Maaling::ga4KjopKropp($utenOkt, 'L78', 500.0, 'gavekort', 'Gavekort', $hvem);
$navnU = array_map(static fn(array $e): string => $e['name'], $ku['events'] ?? []);
sjekk('kjøp uten økt: campaign_details først', $navnU === ['campaign_details', 'purchase', 'gavekort_kjopt'], json_encode($navnU));
$cd = $ku['events'][0]['params'] ?? [];
$tid = $cd['timestamp_micros'] ?? PHP_INT_MAX;
unset($cd['timestamp_micros']);
sjekk('campaign_details: kilde, medium, kampanje', $cd === ['campaign' => 'host', 'source' => 'facebook', 'medium' => 'paid_social'], json_encode($cd));
sjekk('campaign_details: timestamp_micros i params, før kjøpet', $tid < time() * 1000000 && !isset($ku['events'][0]['timestamp_micros']));
sjekk('ordre: ingen Ads-hendelse', array_map(static fn(array $e): string => $e['name'], Maaling::ga4KjopKropp($s, 'L79', 10.0, 'ordre', 'Butikk', $hvem)['events']) === ['purchase']);
sjekk('uten client_id: ingen kropp', Maaling::ga4KjopKropp(['sam' => 'ja', 'fbp' => 'fb.1.1.1'], 'L80', 10.0, 'booking', 'X', $hvem) === []);

// ── 7. Én kilde: hva serveren sender, sier returen til nettleseren ──────
// Kontrolløren 25.10: server= bare for kanaler som faktisk svarte 2xx.
sjekk('server= bare for kanaler som er lagret som sendt', Maaling::serverSendte(['cid' => '1.2', 'fbp' => 'fb.1.1.1', 'sendt' => ['ga' => 1761370000, 'meta' => 1761370000]]) === ['ga' => true, 'meta' => true]);
sjekk('GA4 feilet: bare Meta', Maaling::serverSendte(['cid' => '1.2', 'fbp' => 'fb.1.1.1', 'sendt' => ['meta' => 1761370000]]) === ['ga' => false, 'meta' => true]);
sjekk('oppsett alene (nøkkel, client_id, fbp) gir ikke server=', Maaling::serverSendte(['cid' => '1.2', 'fbp' => 'fb.1.1.1']) === ['ga' => false, 'meta' => false]);
sjekk('uten sporing: ingenting', Maaling::serverSendte([]) === ['ga' => false, 'meta' => false]);
$mk = (string) file_get_contents(__DIR__ . '/../app/lib/maaling.php');
$mk = str_replace("\r\n", "\n", $mk);
sjekk('utfallet lagres per kanal etter 2xx', str_contains($mk, 'if (self::tilGa4($sporing, $id, $belop, $formal, $vare, $hvem)) {')
    && str_contains($mk, 'if (self::tilMeta($sporing, $id, $belop, $formal, $vare, $hvem)) {')
    && str_contains($mk, 'self::lagreSendt($betalingId, $gikk);')
    && substr_count($mk, "            return false;\n        }\n        return true;") === 2);

$rot = __DIR__ . '/..';
$retur = (string) file_get_contents($rot . '/api/betaling-retur.php');
sjekk('returen sier «server=» til nettleseren, fra lagret utfall', str_contains($retur, "'&server=' . implode('.', \$sender)")
    && str_contains($retur, 'Maaling::serverSendte('));
sjekk('returen: annen side enn forsida får ?betaling=', str_contains($retur, "\$til . '?betaling=' . \$utfall"));
sjekk('returen: forsida som før (#betaling=)', str_contains($retur, "'/#betaling=' . \$utfall . \$ekstra"));
sjekk('booking går tilbake til kurssida', str_contains((string) file_get_contents($rot . '/app/lib/booking.php'), "'&til=' . rawurlencode('/kurs/' . \$okt['slug'])"));
sjekk('gavekort går tilbake til gavekortsida', str_contains((string) file_get_contents($rot . '/api/gavekort.php'), "'&til=' . rawurlencode('/gavekort')"));
sjekk('?betaling= sender serversida til appen', str_contains((string) file_get_contents($rot . '/app/nett/nett.php'), "'kolleksjon', 'betaling']"));
$side = (string) file_get_contents($rot . '/lissom-2108.html');
sjekk('nettleseren hopper over kanalene serveren tok', str_contains($side, "typeof window.gtag === 'function' && !uten.ga")
    && str_contains($side, "typeof window.fbq === 'function' && !uten.meta")
    && str_contains($side, '}, hvem, utenom);'));
sjekk('appen leser ?betaling=', str_contains($side, 'sok.match(/[?&]betaling=([a-z]+)/)'));
sjekk('kilden huskes bare etter «ja» (appen og nett.js)', preg_match("~samtykke\\(\\) !== 'ja'\\) return;\\R    this\\.huskKilde\\(\\);~", $side) === 1
    && preg_match("~if \\(intern\\(\\)\\) return;\\R    huskKilde\\(\\);~", (string) file_get_contents($rot . '/nett.js')) === 1);
$lett = (string) file_get_contents($rot . '/lissom-2108-uten-admin.html');
sjekk('kundeutgaven er laget på nytt', str_contains($lett, 'huskKilde') && str_contains($lett, 'server=([a-z.]+)'));

echo "\n  $ok OK, $feil FEIL\n";
exit($feil === 0 ? 0 : 1);
