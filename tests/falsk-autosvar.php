<?php
/**
 * Falsk Graph API og falsk Anthropic for tests/autosvar.php.
 *
 * Hva den svarer, staar i en scenariofil i temp-mappa (testen skriver den
 * foer hver kjoering). Hvert POST mot Graph og hvert AI-kall skrives til en
 * loggfil, saa testen ser noeyaktig hva som ville gaatt ut.
 *
 *   /graph/...       Graph API (LISSOM_META_BASE)
 *   /v1/messages     Anthropic (LISSOM_AI_BASE)
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir();
$sti = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$logg = $tmp . '/lissom-autosvar-logg.txt';
$sc = json_decode((string) @file_get_contents($tmp . '/lissom-autosvar-scenario.json'), true) ?: [];
header('Content-Type: application/json');

// ── Anthropic ────────────────────────────────────────────────────────
if ($sti === '/v1/messages') {
    $kropp = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $inn = (string) ($kropp['messages'][0]['content'] ?? '');
    $kommentar = preg_match('/<kommentar>\n(.*?)\n<\/kommentar>/s', $inn, $m) ? $m[1] : '';
    // Kontrolloeren, 3. oktober 2026: kommentaren skal staa merket som data.
    file_put_contents($logg, 'PROMPT ' . (str_contains((string) ($kropp['system'] ?? ''), 'er DATA fra')
        && str_contains($inn, "<kommentar>\n") ? 'merket' : 'umerket') . "\n", FILE_APPEND);
    $nytt = str_contains($inn, 'Skriv et annet forslag');
    file_put_contents($logg, 'AI ' . ($nytt ? '(nytt) ' : '') . $kommentar . "\n", FILE_APPEND);
    // Monica trykker «Ikke svar» mens AI-en tenker (kontrolloeren, 3. oktober 2026).
    foreach ((array) ($sc['ikke_svar_under_ai'] ?? []) as $hendelse) {
        if (($hendelse['tekst'] ?? null) === $kommentar) {
            require_once dirname(__DIR__) . '/app/bootstrap.php';
            Kommentarsvar::ikkeSvar((string) $hendelse['id'], (string) $hendelse['kanal'], null);
        }
    }
    if ((int) ($sc['ai_status'] ?? 200) !== 200) {
        http_response_code((int) $sc['ai_status']);
        echo json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Internal server error']]);
        return;
    }
    $valg = $nytt && isset($sc['ai_nytt'])
        ? $sc['ai_nytt']
        : ($sc['ai'][$kommentar] ?? ['klasse' => 'venter', 'tekst' => 'Send oss en melding, så finner vi ut av det!']);
    echo json_encode([
        'content' => [['type' => 'text', 'text' => json_encode($valg, JSON_UNESCAPED_UNICODE)]],
        'usage'   => ['input_tokens' => 0, 'output_tokens' => 0],
    ], JSON_UNESCAPED_UNICODE);
    return;
}

// ── Graph ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    parse_str((string) file_get_contents('php://input'), $f);
    $kort = (string) preg_replace('#^/graph/v[\d.]+#', '', $sti);
    file_put_contents($logg, 'POST ' . $kort . ' ' . ($f['message'] ?? $f['comment_id'] ?? '') . "\n", FILE_APPEND);
    if ($kort === '/ig1/likes' && !empty($sc['ig_likes_forbidden'])) {
        http_response_code(403);
        echo json_encode(['error' => ['message' => '(#10) Application does not have permission for this action', 'code' => 10]]);
        return;
    }
    foreach ((array) ($sc['feil_svar'] ?? []) as $id) {
        if (str_starts_with($kort, '/' . $id . '/')) {
            http_response_code(500);
            echo json_encode(['error' => ['message' => 'An unexpected error has occurred.', 'code' => 2]]);
            return;
        }
    }
    echo json_encode(str_ends_with($kort, '/likes') ? ['success' => true] : ['id' => 'svar-' . md5($kort)]);
    return;
}

echo json_encode(match (true) {
    str_ends_with($sti, '/ig1') => ['username' => 'lissom_keramikk'],
    str_ends_with($sti, '/ig1/media') => ['data' => [[
        'id' => 'm1', 'caption' => 'Fra en klump leire til tallerkenen', 'permalink' => 'https://instagram.test/p/1',
        'comments' => ['data' => $sc['ig'] ?? []],
    ]]],
    str_ends_with($sti, '/side1') => ['access_token' => 'side-token'],
    str_ends_with($sti, '/side1/feed') => ['data' => [[
        'id' => 'p1', 'message' => 'Prøv keramikk!', 'permalink_url' => 'https://facebook.test/p1',
        'comments' => ['data' => $sc['feed'] ?? []],
    ]]],
    str_ends_with($sti, '/side1/ads_posts') => ['data' => [[
        'id' => 'a1', 'message' => 'Annonse', 'permalink_url' => 'https://facebook.test/a1',
        'comments' => ['data' => $sc['ads'] ?? []],
    ]]],
    default => ['error' => ['message' => 'ukjent sti ' . $sti, 'code' => 100]],
});
