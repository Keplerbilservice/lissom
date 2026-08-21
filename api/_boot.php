<?php
/**
 * Broen fra webroten til koden som ligger utenfor den.
 *
 * public_html/api/*.php  ← ligger på nettet
 * ../lissom-app/app/     ← ligger utenfor, kan ikke lastes ned
 */

declare(strict_types=1);

$kandidater = [
    // Slik deploy-jobben legger det på webhotellet (cPanel: ~/lissom-app).
    dirname(__DIR__, 2) . '/lissom-app/app/bootstrap.php',
    // Hvis app/ er lagt rett ved siden av public_html.
    dirname(__DIR__, 2) . '/app/bootstrap.php',
    // Lokalt i utviklingsmappa, der alt ligger i samme repo.
    dirname(__DIR__) . '/app/bootstrap.php',
];

foreach ($kandidater as $sti) {
    if (is_file($sti)) {
        require $sti;
        return;
    }
}

http_response_code(500);
header('Content-Type: application/json; charset=utf-8');
error_log('Lissom: fant ikke app/bootstrap.php. Lette i: ' . implode(', ', $kandidater));
echo json_encode(['ok' => false, 'feil' => 'Serveren er ikke ferdig satt opp.'], JSON_UNESCAPED_UNICODE);
exit;
