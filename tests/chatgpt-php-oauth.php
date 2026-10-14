<?php
declare(strict_types=1);
require __DIR__.'/../app/integrasjon/vendor/autoload.php';
require __DIR__.'/../app/lib/chatgptkurser.php';
use Kepler\Integrasjon\{OAuth,HttpController,LissomTools};
use Nyholm\Psr7\ServerRequest;

$mysqlDsn = getenv('MCP_FIXTURE_MYSQL_DSN') ?: '';
if ($mysqlDsn !== '' && !preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{4,5};dbname=lissom_mcp_fixture$/D',$mysqlDsn)) {
    throw new RuntimeException('Only dedicated loopback fixture database allowed');
}
$db = new PDO($mysqlDsn ?: 'sqlite::memory:',$mysqlDsn ? 'fixture' : null,$mysqlDsn ? 'fixture' : null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($mysqlDsn) $db->exec(file_get_contents(__DIR__.'/../db/migrations/278_chatgpt_integrasjon.sql'));
else $db->exec('CREATE TABLE mcp_oauth_state (kind TEXT,key_hash TEXT,value_json TEXT,expires_at INTEGER,revoked INTEGER DEFAULT 0,PRIMARY KEY(kind,key_hash))');
$db->exec("
CREATE TABLE members (id INTEGER PRIMARY KEY,rolle TEXT,anonymisert_at TEXT);
CREATE TABLE courses (id INTEGER PRIMARY KEY,tittel TEXT,slug TEXT,status TEXT,bilde TEXT);
CREATE TABLE rate_limits (nokkel VARCHAR(191),vindu_start VARCHAR(32),antall INTEGER,PRIMARY KEY(nokkel,vindu_start));
CREATE TABLE audit_log (member_id INTEGER,handling TEXT,objekt_type TEXT,objekt_id INTEGER,detaljer TEXT);
INSERT INTO members VALUES (1,'admin',NULL),(2,'medlem',NULL);
INSERT INTO courses VALUES (7,'Syntetisk kurs','syntetisk','publisert',NULL);");
$keyOptions = ['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA];
if (PHP_OS_FAMILY === 'Windows') $keyOptions['config'] = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
$rsa = openssl_pkey_new($keyOptions);
if (!$rsa) throw new RuntimeException('Fixture RSA generation failed');
if (!openssl_pkey_export($rsa,$private,null,$keyOptions)) throw new RuntimeException('Fixture RSA export failed');
$secret = str_repeat('fixture-not-production-',3); $issuer = 'https://fixture.example';
$allowed = static function(int $id) use($db): bool {
    $q=$db->prepare('SELECT rolle,anonymisert_at FROM members WHERE id=?');$q->execute([$id]);$m=$q->fetch(PDO::FETCH_ASSOC);
    return $m && $m['rolle']==='admin' && $m['anonymisert_at']===null;
};
$oauth = new OAuth($db,$issuer,$issuer.'/integrasjon/mcp','lissom:courses:read',$private,$secret,$allowed);
$actor = 1;
$controller = new HttpController($db,$oauth,$secret,static function() use(&$actor) {return $actor;},
    static fn($r)=>$r->getHeaderLine('Origin')===$issuer,static fn($p)=>true,
    static fn($a)=>LissomTools::server($db,$oauth,$a,$secret),'Lissom','Kun lesing av kurs.');
$checks=0;
function check(bool $ok,string $label): void { global $checks; if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++; }
function req(string $path,string $method='GET',array $query=[],?array $body=null,array $headers=[]): ServerRequest {
    global $issuer;
    $r=new ServerRequest($method,$issuer.$path,$headers);
    return $r->withQueryParams($query)->withParsedBody($body);
}
$registration = ['redirect_uris'=>['https://chatgpt.com/connector/oauth/fixture'],'token_endpoint_auth_method'=>'none','grant_types'=>['authorization_code','refresh_token']];
$request = new ServerRequest('POST',$issuer.'/integrasjon/oauth/register',[],json_encode($registration));
$response=$controller->handle($request);check($response->getStatusCode()===201,'public client registration');
$client=json_decode((string)$response->getBody(),true)['client_id'];
$bad=new ServerRequest('POST',$issuer.'/integrasjon/oauth/register',[],json_encode(['redirect_uris'=>['https://evil.example/callback']]));
check($controller->handle($bad)->getStatusCode()===400,'untrusted redirect rejected');
$verifier=str_repeat('v',64);$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
$query=['client_id'=>$client,'redirect_uri'=>$registration['redirect_uris'][0],'response_type'=>'code','scope'=>$oauth->scope,
    'resource'=>$oauth->resource,'state'=>'synthetic-client-state-123456','code_challenge'=>$challenge,'code_challenge_method'=>'S256'];
$actor=null;check($controller->handle(req('/integrasjon/oauth/authorize','GET',$query))->getStatusCode()===401,'login required');$actor=1;
check($controller->handle(req('/integrasjon/oauth/authorize','GET',array_replace($query,['code_challenge_method'=>'plain'])))->getStatusCode()===400,'PKCE plain rejected');
check($controller->handle(req('/integrasjon/oauth/authorize','GET',array_replace($query,['resource'=>'https://other.example/mcp'])))->getStatusCode()===400,'other resource rejected');
$response=$controller->handle(req('/integrasjon/oauth/authorize','GET',$query));check($response->getStatusCode()===200,'consent displayed');
$html=(string)$response->getBody();preg_match('/name="pending" value="([a-f0-9]+)"/',$html,$pending);preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$csrf);
$consent=['pending'=>$pending[1],'csrf'=>$csrf[1],'approve'=>'yes'];
check($controller->handle(req('/integrasjon/oauth/authorize','POST',[],array_replace($consent,['csrf'=>'wrong']),['Origin'=>$issuer]))->getStatusCode()===403,'consent CSRF rejected');
check($controller->handle(req('/integrasjon/oauth/authorize','POST',[],$consent,['Origin'=>'https://evil.example']))->getStatusCode()===403,'foreign origin rejected');
$response=$controller->handle(req('/integrasjon/oauth/authorize','POST',[],$consent,['Origin'=>$issuer]));
check($response->getStatusCode()===302,'confirmed consent redirects');
parse_str(parse_url($response->getHeaderLine('Location'),PHP_URL_QUERY),$redirect);
check($redirect['state']===$query['state'] && $redirect['iss']===$issuer,'state and issuer echoed');
check($controller->handle(req('/integrasjon/oauth/authorize','POST',[],$consent,['Origin'=>$issuer]))->getStatusCode()===403,'consent single use');
$body=['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>$registration['redirect_uris'][0],
    'code'=>$redirect['code'],'code_verifier'=>$verifier,'resource'=>$oauth->resource];
check($controller->handle(req('/integrasjon/oauth/token','POST',[],array_replace($body,['code_verifier'=>str_repeat('x',64)])))->getStatusCode()===400,'wrong verifier rejected');
check($controller->handle(req('/integrasjon/oauth/token','POST',[],array_replace($body,['redirect_uri'=>'https://chatgpt.com/connector/oauth/other'])))->getStatusCode()===400,'redirect exact match');
$response=$controller->handle(req('/integrasjon/oauth/token','POST',[],$body));check($response->getStatusCode()===200,'code exchanged');
$tokens=json_decode((string)$response->getBody(),true);check(isset($tokens['access_token'],$tokens['refresh_token'])&&$tokens['expires_in']<=600,'short token with rotating refresh');
check($controller->handle(req('/integrasjon/oauth/token','POST',[],$body))->getStatusCode()===400,'authorization code single use');
$authenticated=req('/integrasjon/mcp','POST',[],null,['Authorization'=>'Bearer '.$tokens['access_token']]);
check($oauth->actor($authenticated)===1,'signed token validates');
$rpcHeaders=['Accept'=>'application/json, text/event-stream','Content-Type'=>'application/json'];
$rpc=static function(array $body,array $extra=[]) use($issuer,$rpcHeaders): ServerRequest {
    return new ServerRequest('POST',$issuer.'/integrasjon/mcp',[...$rpcHeaders,...$extra],json_encode($body,JSON_THROW_ON_ERROR));
};
$init=$controller->handle($rpc(['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>[
    'protocolVersion'=>'2025-11-25','capabilities'=>new stdClass(),'clientInfo'=>['name'=>'sql-fixture','version'=>'1.0']]]));
check($init->getStatusCode()===200,'MCP initialize');
$session=$init->getHeaderLine('Mcp-Session-Id');check($session!=='' && $oauth->state->get('session',$session)!==null,'MCP session persisted in SQL');
$sessionHeaders=['Mcp-Session-Id'=>$session,'MCP-Protocol-Version'=>'2025-11-25'];
$controller->handle($rpc(['jsonrpc'=>'2.0','method'=>'notifications/initialized'], $sessionHeaders));
$listed=$controller->handle($rpc(['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/list'], $sessionHeaders));
$listedData=json_decode((string)$listed->getBody(),true);
check(count($listedData['result']['tools'] ?? [])===2,'only two tools');
$readHeaders=[...$sessionHeaders,'Authorization'=>'Bearer '.$tokens['access_token']];
$read=$controller->handle($rpc(['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call','params'=>['name'=>'lissom_get_course','arguments'=>['id'=>7]]],$readHeaders));
$readData=json_decode((string)$read->getBody(),true);
check(($readData['result']['structuredContent']['courses'][0]['id'] ?? null)===7,'course read through SQL-backed MCP');
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE handling='chatgpt_kurs_lest'")->fetchColumn()===1,'MCP read audit');
$refreshBody=['grant_type'=>'refresh_token','client_id'=>$client,'refresh_token'=>$tokens['refresh_token'],'resource'=>$oauth->resource];
check($controller->handle(req('/integrasjon/oauth/token','POST',[],array_replace($refreshBody,['resource'=>'https://other.example/mcp'])))->getStatusCode()===400,'refresh resource bound');
$refreshed=$controller->handle(req('/integrasjon/oauth/token','POST',[],$refreshBody));
check($refreshed->getStatusCode()===200,'silent refresh succeeds');
$fresh=json_decode((string)$refreshed->getBody(),true);
check($fresh['refresh_token']!==$tokens['refresh_token'],'refresh rotated');
try{$oauth->actor($authenticated);throw new RuntimeException('Old access token accepted');}catch(League\OAuth2\Server\Exception\OAuthServerException){check(true,'old access revoked after refresh');}
$authenticated=req('/integrasjon/mcp','POST',[],null,['Authorization'=>'Bearer '.$fresh['access_token']]);
check($oauth->actor($authenticated)===1,'refreshed token works');
check((int)$db->query("SELECT MAX(expires_at) FROM mcp_oauth_state WHERE kind='refresh'")->fetchColumn()
    <=(int)$db->query("SELECT MAX(expires_at) FROM mcp_oauth_state WHERE kind='grant'")->fetchColumn(),'refresh cannot extend original 24-hour grant');
$other = new OAuth($db,'https://kepler-fixture.example','https://kepler-fixture.example/integrasjon/mcp','kepler:jobs:read',$private,$secret,$allowed);
try{$other->actor($authenticated);throw new RuntimeException('Cross-system token accepted');}catch(League\OAuth2\Server\Exception\OAuthServerException){check(true,'cross-system token rejected');}
$db->exec("UPDATE members SET rolle='medlem' WHERE id=1");
try{$oauth->actor($authenticated);throw new RuntimeException('Revoked role accepted');}catch(League\OAuth2\Server\Exception\OAuthServerException){check(true,'role rechecked');}
$db->exec("UPDATE members SET rolle='admin' WHERE id=1");
$rawStore=implode('', $db->query($mysqlDsn ? 'SELECT CONCAT(key_hash,value_json) FROM mcp_oauth_state' : 'SELECT key_hash||value_json FROM mcp_oauth_state')->fetchAll(PDO::FETCH_COLUMN));
check(!str_contains($rawStore,$tokens['access_token'])&&!str_contains($rawStore,$fresh['refresh_token'])
    &&!str_contains($rawStore,$tokens['refresh_token'])&&!str_contains($rawStore,$redirect['code'])
    &&!str_contains($rawStore,$consent['csrf']),'no raw credentials in SQL');
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE handling='chatgpt_kobling_samtykke'")->fetchColumn()===1,'consent audit');
check($controller->handle(req('/integrasjon/oauth/token','POST',[],$refreshBody))->getStatusCode()===400,'refresh replay denied');
try{$oauth->actor($authenticated);throw new RuntimeException('Replayed family still accepted');}catch(League\OAuth2\Server\Exception\OAuthServerException){check(true,'refresh reuse revokes whole grant');}
$response=$controller->handle(req('/integrasjon/oauth/authorize','GET',$query));
$html=(string)$response->getBody();
preg_match('/name="pending" value="([a-f0-9]+)"/',$html,$deniedPending);
preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$deniedCsrf);
$deniedConsent=['pending'=>$deniedPending[1],'csrf'=>$deniedCsrf[1],'approve'=>'no'];
$response=$controller->handle(req('/integrasjon/oauth/authorize','POST',[],$deniedConsent,['Origin'=>$issuer]));
parse_str(parse_url($response->getHeaderLine('Location'),PHP_URL_QUERY),$deniedRedirect);
check($response->getStatusCode()===302 && ($deniedRedirect['error']??null)==='access_denied'
    && ($deniedRedirect['state']??null)===$query['state'] && ($deniedRedirect['iss']??null)===$issuer
    && !isset($deniedRedirect['code']),'refused consent returns state and issuer without code');
check($controller->handle(req('/integrasjon/oauth/authorize','POST',[],$deniedConsent,['Origin'=>$issuer]))->getStatusCode()===403,'refused consent consumed');
$audits=$db->query("SELECT detaljer FROM audit_log WHERE handling='chatgpt_kobling_samtykke'")->fetchAll(PDO::FETCH_COLUMN);
check(count(array_filter($audits,static fn($a)=>json_decode($a,true)['approved']===false))===1,'refused consent audited');
echo "$checks PHP OAuth-kontroller OK (isolert ".($mysqlDsn ? 'MySQL/MariaDB' : 'SQLite').", syntetisk innlogging).\n";
