<?php
declare(strict_types=1);
// Disabled unless explicitly configured in the server's secret environment.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); http_response_code(405); echo '{"error":"Metoden er ikke tillatt."}'; exit;
}
$secret = getenv('LISSOM_MCP_BRIDGE_SECRET') ?: '';
$actors = json_decode(getenv('LISSOM_MCP_ACTORS') ?: '{}', true);
if (strlen($secret) < 32 || !is_array($actors) || $actors === []) {
    http_response_code(503); echo '{"error":"Integrasjonen er ikke konfigurert."}'; exit;
}
require __DIR__ . '/../_boot.php';
require_once APP_DIR . '/lib/chatgptkurser.php';
try {
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    $result = (new ChatgptKurser(DB::kobling(), $secret, $actors))->les($raw ?: '', [
        'timestamp' => $_SERVER['HTTP_X_LISSOM_TIMESTAMP'] ?? '',
        'nonce' => $_SERVER['HTTP_X_LISSOM_NONCE'] ?? '',
        'signature' => $_SERVER['HTTP_X_LISSOM_SIGNATURE'] ?? '',
    ], time());
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $code = in_array($e->getCode(), [400, 401, 403, 409, 503], true) ? $e->getCode() : 500;
    http_response_code($code);
    // Never disclose PDO diagnostics, keys, request content or member details.
    echo json_encode(['error' => $code === 500 ? 'Integrasjonen kunne ikke lese kurs.' : $e->getMessage()]);
}
