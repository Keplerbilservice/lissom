<?php
/**
 * Et vindu inn i testbasen for nettlesertestene (bare fra kommandolinja).
 *
 *   php tests/nettleser/db.php '<sql>' '<json-parametre>'   → JSON-rader
 *   php tests/nettleser/db.php --php '<kode>'                 → JSON av retur
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$a = $argv[1] ?? '';
if ($a === '--php') {
    $f = eval('return (function () { ' . ($argv[2] ?? '') . ' })();');
    echo json_encode($f, JSON_UNESCAPED_UNICODE);
    exit;
}
$p = json_decode($argv[2] ?? '{}', true) ?: [];
if (preg_match('~^\s*(SELECT|SHOW)~i', $a)) {
    echo json_encode(DB::alle($a, $p), JSON_UNESCAPED_UNICODE);
} else {
    DB::kjor($a, $p);
    echo json_encode(['ok' => true]);
}
