<?php
/**
 * Broen fra webroten til koden som ligger utenfor den.
 *
 *   public_html/.../api/*.php   ← ligger på nettet
 *   ~/lissom-app/app/           ← ligger utenfor, kan ikke lastes ned
 *
 * Vi teller ikke mappenivåer. Nettsiden kan ligge rett i public_html, i et
 * underdomene, eller i en testmappe — dybden varierer, og en hardkodet sti
 * brekker stille hver gang den flyttes. I stedet går vi oppover fra denne fila
 * til vi finner lissom-app/.
 */

declare(strict_types=1);

$sokt = [];
$mappe = __DIR__;

for ($nivaa = 0; $nivaa < 8; $nivaa++) {
    foreach ([
        $mappe . '/lissom-app/app/bootstrap.php', // på webhotellet
        $mappe . '/app/bootstrap.php',            // lokalt i utviklingsmappa
    ] as $sti) {
        $sokt[] = $sti;
        if (is_file($sti)) {
            require $sti;
            return;
        }
    }

    $opp = dirname($mappe);
    if ($opp === $mappe) {
        break; // nådd toppen av filsystemet
    }
    $mappe = $opp;
}

http_response_code(500);
header('Content-Type: application/json; charset=utf-8');
error_log('Lissom: fant ikke app/bootstrap.php. Lette i: ' . implode(', ', $sokt));
echo json_encode([
    'ok'   => false,
    'feil' => 'Serveren er ikke ferdig satt opp.',
], JSON_UNESCAPED_UNICODE);
exit;
