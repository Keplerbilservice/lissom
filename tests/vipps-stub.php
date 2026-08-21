<?php
/**
 * Etterligner Vipps, kun for testing.
 *
 * Betalingskjeden er den delen som er vanskeligst aa prove: den krever en
 * salgsenhet med ePayment, ekte penger og en telefon. Denne stubben lar oss
 * kjore hele flyten — booking, retur, webhook, refusjon — uten noe av det.
 *
 *   php -S 127.0.0.1:8144 tests/vipps-stub.php
 */

declare(strict_types=1);

header('Content-Type: application/json');
$sti = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$kropp = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

if ($sti === '/accesstoken/get') {
    echo json_encode(['access_token' => 'stub-token', 'expires_in' => 3600]);
    exit;
}

if ($sti === '/epayment/v1/payments') {
    http_response_code(201);
    echo json_encode([
        'reference'   => $kropp['reference'] ?? 'ukjent',
        'redirectUrl' => 'https://vipps.example/betal/' . ($kropp['reference'] ?? 'x'),
    ]);
    exit;
}

// Statusoppslag: alltid autorisert, med belopet stubben ble bedt om.
if (preg_match('#^/epayment/v1/payments/([^/]+)$#', $sti, $m)) {
    echo json_encode([
        'reference' => $m[1],
        'state'     => 'AUTHORIZED',
        'aggregate' => ['authorizedAmount' => ['currency' => 'NOK', 'value' => 100]],
    ]);
    exit;
}

if (preg_match('#/(capture|refund|cancel)$#', $sti)) {
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['feil' => 'ukjent sti: ' . $sti]);
