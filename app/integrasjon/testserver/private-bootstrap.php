<?php
declare(strict_types=1);
// Dedicated test bootstrap: no production secrets, application tick or outgoing services.
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');
ini_set('display_errors','0');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
$testManifest = json_decode(file_get_contents(__DIR__.'/deployment.json'),true,8,JSON_THROW_ON_ERROR);
if (($_SERVER['HTTP_HOST'] ?? '') !== $testManifest['host'] || ($_SERVER['HTTPS'] ?? '') !== 'on') {
    http_response_code(403); exit('Kun Lissom – TEST.');
}
if (!is_file(__DIR__.'/secrets.php')) {
    http_response_code(503); exit('Testoppsettet mangler.');
}
$LISSOM_SECRETS = require __DIR__.'/secrets.php';
if (!is_array($LISSOM_SECRETS)
    || ($LISSOM_SECRETS['db_navn'] ?? null) !== $testManifest['database']
    || ($LISSOM_SECRETS['db_bruker'] ?? null) !== $testManifest['database_user']
    || ($LISSOM_SECRETS['db_vert'] ?? null) !== 'localhost'
    || ($LISSOM_SECRETS['mcp_issuer'] ?? null) !== 'https://'.$testManifest['host']
    || ($LISSOM_SECRETS['mcp_test_mode'] ?? null) !== true
    || ($LISSOM_SECRETS['miljo'] ?? null) !== 'produksjon'
    || ($LISSOM_SECRETS['tillatte_opphav'] ?? null) !== ['https://'.$testManifest['host']]) {
    http_response_code(503); exit('Testoppsettet er ikke isolert.');
}
// "produksjon" above selects secure cookies and generic errors, not the production DB.
define('APP_DIR',__DIR__.'/app');
require APP_DIR.'/config.php';
foreach (['db','http','session','ratelimit','logg','auth'] as $testLibrary) require APP_DIR.'/lib/'.$testLibrary.'.php';
set_exception_handler(static function(Throwable $e): void {
    http_response_code(500); header('Content-Type: application/json');
    echo '{"feil":"Lissom – TEST kunne ikke behandle forespørselen."}';
});
