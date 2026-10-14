<?php
declare(strict_types=1);
// PHP/MySQL only. This flag suppresses the application's background notification/payment tick.
define('LISSOM_MCP_REQUEST',true);
try {
    require __DIR__.'/../_boot.php';
    ini_set('display_errors','0');
    if (Config::hent('mcp_enabled',false) !== true) {
        http_response_code(503); header('Content-Type: application/json'); header('Cache-Control: no-store');
        echo '{"error":"Integrasjonen er ikke aktivert."}'; exit;
    }
    require APP_DIR.'/integrasjon/vendor/autoload.php';
    require_once APP_DIR.'/lib/chatgptkurser.php';
    $issuer = Config::hent('mcp_issuer');
    if (!is_string($issuer) || !preg_match('~^https://[a-z0-9.-]+$~D',$issuer)) throw new RuntimeException('Invalid issuer configuration');
    $key = Config::hent('mcp_encryption_key');
    $oauth = new Kepler\Integrasjon\OAuth(DB::kobling(),$issuer,$issuer.'/integrasjon/mcp','lissom:courses:read',
        (string)Config::hent('mcp_private_key'),(string)$key,static function(int $id): bool {
            $member = DB::en('SELECT rolle,anonymisert_at FROM members WHERE id = :id',['id'=>$id]);
            return $member !== null && $member['rolle'] === 'admin' && $member['anonymisert_at'] === null;
        });
    $factory = new Nyholm\Psr7\Factory\Psr17Factory();
    $creator = new Nyholm\Psr7Server\ServerRequestCreator($factory,$factory,$factory,$factory);
    $request = $creator->fromGlobals();
    $controller = new Kepler\Integrasjon\HttpController(DB::kobling(),$oauth,(string)$key,
        static function(): ?int {
            $member = Sesjon::medlem();
            return $member !== null && Sesjon::erAdmin() ? (int)$member['id'] : null;
        },
        static fn($r): bool => $r->getHeaderLine('Origin') === $issuer,
        static fn(string $path): bool => Rate::tillat('mcp_request',120,60)
            && ($path !== '/integrasjon/oauth/register' || Rate::tillat('mcp_register',10,3600)),
        static fn(?int $actor) => Kepler\Integrasjon\LissomTools::server(DB::kobling(),$oauth,$actor,(string)$key),
        'Lissom','ChatGPT får lese kurstitler, kurs-ID, status og bilder. Tilkoblingen kan ikke endre kurs eller lese deltakere og betalinger.');
    $response = $controller->handle($request);
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name=>$values) foreach($values as $value) header($name.': '.$value,false);
    echo $response->getBody();
} catch (Throwable) {
    http_response_code(503); header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo '{"error":"Integrasjonen er ikke tilgjengelig."}';
}
