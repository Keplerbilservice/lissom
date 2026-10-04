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

echo "\n  $ok OK, $feil FEIL\n";
exit($feil === 0 ? 0 : 1);
