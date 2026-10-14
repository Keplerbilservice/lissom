<?php
declare(strict_types=1);
// Test-only HTTP router. The test process supplies synthetic SQLite and key files.
// Never includes application bootstrap, sessions, secrets, Tikk or notification services.
require __DIR__.'/../../app/integrasjon/vendor/autoload.php';
require __DIR__.'/../../app/lib/chatgptkurser.php';
use Kepler\Integrasjon\{OAuth,HttpController,LissomTools};
$dbFile=getenv('MCP_FIXTURE_DB');$keyFile=getenv('MCP_FIXTURE_KEY');
if (!$dbFile || !$keyFile) { http_response_code(503); exit; }
$db=new PDO('sqlite:'.$dbFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE IF NOT EXISTS mcp_oauth_state (kind TEXT,key_hash TEXT,value_json TEXT,expires_at INTEGER,revoked INTEGER DEFAULT 0,PRIMARY KEY(kind,key_hash));
CREATE TABLE IF NOT EXISTS members (id INTEGER PRIMARY KEY,rolle TEXT,anonymisert_at TEXT);
CREATE TABLE IF NOT EXISTS courses (id INTEGER PRIMARY KEY,tittel TEXT,slug TEXT,status TEXT,bilde TEXT);
CREATE TABLE IF NOT EXISTS rate_limits (nokkel TEXT,vindu_start TEXT,antall INTEGER,PRIMARY KEY(nokkel,vindu_start));
CREATE TABLE IF NOT EXISTS audit_log (member_id INTEGER,handling TEXT,objekt_type TEXT,objekt_id INTEGER,detaljer TEXT);
INSERT OR IGNORE INTO members VALUES (1,'admin',NULL);
INSERT OR IGNORE INTO courses VALUES (7,'Syntetisk HTTP-kurs','syntetisk-http','publisert',NULL);");
$issuer='https://fixture.example';$secret=str_repeat('fixture-not-production-',3);
$oauth=new OAuth($db,$issuer,$issuer.'/integrasjon/mcp','lissom:courses:read',file_get_contents($keyFile),$secret,
    static function(int $id) use($db): bool {
        $q=$db->prepare('SELECT rolle,anonymisert_at FROM members WHERE id=?');$q->execute([$id]);$m=$q->fetch(PDO::FETCH_ASSOC);
        return $m && $m['rolle']==='admin' && $m['anonymisert_at']===null;
    });
$factory=new Nyholm\Psr7\Factory\Psr17Factory();
$creator=new Nyholm\Psr7Server\ServerRequestCreator($factory,$factory,$factory,$factory);
$request=$creator->fromGlobals();
// This fixture simulates HTTPS termination. TLS and Apache rewrites are NOT tested here.
$request=$request->withUri($request->getUri()->withScheme('https')->withHost('fixture.example')->withPort(null));
$controller=new HttpController($db,$oauth,$secret,
    static fn():?int => ($_SERVER['HTTP_X_FIXTURE_ADMIN'] ?? '') === 'yes' ? 1 : null,
    static fn($r):bool => $r->getHeaderLine('Origin')===$issuer,static fn($p):bool => true,
    static fn($a)=>LissomTools::server($db,$oauth,$a,$secret),'Lissom','Kun lesing av kurs.');
$response=$controller->handle($request);
http_response_code($response->getStatusCode());
foreach($response->getHeaders() as $name=>$values) foreach($values as $value) header($name.': '.$value,false);
echo $response->getBody();
