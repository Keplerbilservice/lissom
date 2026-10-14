<?php
declare(strict_types=1);
namespace Kepler\Integrasjon;

use Psr\Http\Message\{ServerRequestInterface,ResponseInterface};
use Nyholm\Psr7\Response;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Transport\Http\Middleware\{CorsMiddleware,DnsRebindingProtectionMiddleware};
use League\OAuth2\Server\Exception\OAuthServerException;

/** All routes run per request under ordinary PHP hosting; durable state is in SQL. */
final class HttpController
{
    public function __construct(private \PDO $db,private OAuth $oauth,private string $internalKey,
        private \Closure $currentAdmin,private \Closure $sameOrigin,private \Closure $limiter,
        private \Closure $serverFactory,private string $systemName,private string $consentDescription) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleRequest($request)->withHeader('X-Robots-Tag','noindex, nofollow, noarchive');
    }
    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $host = parse_url($this->oauth->issuer,PHP_URL_HOST);
            if ($request->getUri()->getHost() !== $host || $request->getUri()->getScheme() !== 'https'
                || ($request->getHeaderLine('Origin') !== '' && $request->getHeaderLine('Origin') !== $this->oauth->issuer)) {
                return $this->json(['error'=>'Forbidden origin or host'],403);
            }
            if (!($this->limiter)($request->getUri()->getPath())) return $this->json(['error'=>'Rate limit'],429)->withHeader('Retry-After','60');
            $path = $request->getUri()->getPath(); $method = $request->getMethod();
            if ($method === 'GET' && $path === '/.well-known/oauth-authorization-server') return $this->json($this->oauth->metadata());
            if ($method === 'GET' && $path === '/.well-known/oauth-protected-resource') return $this->json([
                'resource'=>$this->oauth->resource,'authorization_servers'=>[$this->oauth->issuer],
                'scopes_supported'=>[$this->oauth->scope],'bearer_methods_supported'=>['header']]);
            if ($path === '/integrasjon/oauth/register' && $method === 'POST') return $this->register($request);
            if ($path === '/integrasjon/oauth/authorize' && in_array($method,['GET','POST'],true)) return $this->authorize($request);
            if ($path === '/integrasjon/oauth/token' && $method === 'POST') return $this->oauth->token($request,new Response());
            if ($path === '/integrasjon/mcp') return $this->mcp($request);
            return $this->json(['error'=>'Not found'],404);
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse(new Response())->withHeader('Cache-Control','no-store');
        } catch (\Throwable) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return $this->json(['error'=>'Integrasjonen kunne ikke behandle forespørselen.'],500);
        }
    }
    private function register(ServerRequestInterface $request): ResponseInterface
    {
        $raw = $request->getBody()->read(4097);
        if (strlen($raw)>4096) return $this->json(['error'=>'Request too large'],413);
        try { $body = json_decode($raw,true,8,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return $this->json(['error'=>'Invalid registration'],400); }
        if(!is_array($body) || array_is_list($body))return $this->json(['error'=>'Invalid registration'],400);
        $redirects = $body['redirect_uris'] ?? null;
        $grants=$body['grant_types'] ?? ['authorization_code'];
        if (!is_array($redirects) || !array_is_list($redirects) || count($redirects)<1 || count($redirects)>3
            || ($body['token_endpoint_auth_method'] ?? 'none') !== 'none'
            || !is_array($grants) || !array_is_list($grants) || !in_array('authorization_code',$grants,true)
            || count($grants)>2 || count(array_filter($grants,'is_string'))!==count($grants)
            || array_diff($grants,['authorization_code','refresh_token']) !== []
            || count(array_unique($grants))!==count($grants)
            || ($body['response_types'] ?? ['code']) !== ['code']) return $this->json(['error'=>'Invalid registration'],400);
        foreach ($redirects as $redirect) {
            if (!is_string($redirect) || !preg_match('~^https://chatgpt\.com/(?:connector_platform_oauth_redirect|connector/oauth/[A-Za-z0-9_-]{1,200})$~D',$redirect)) {
                return $this->json(['error'=>'Redirect URI not allowed'],400);
            }
        }
        // Registration grants no permissions; only explicit, authenticated admin consent does.
        $name = 'ChatGPT';
        $id = $this->oauth->store->register($name,$redirects,$grants);
        return $this->json(['client_id'=>$id,'client_name'=>$name,'redirect_uris'=>$redirects,
            'token_endpoint_auth_method'=>'none','grant_types'=>$grants,'response_types'=>['code']],201);
    }
    private function authorize(ServerRequestInterface $request): ResponseInterface
    {
        $actor = ($this->currentAdmin)();
        if (!is_int($actor) || $actor<1) return $this->json(['error'=>'Logg inn som administrator i dette systemet først.'],401);
        if ($request->getMethod() === 'GET') {
            $this->oauth->validateAuthorization($request);
            $pending = bin2hex(random_bytes(32)); $csrf = bin2hex(random_bytes(32));
            $this->oauth->state->put('consent',$pending,['actor'=>$actor,'csrf'=>hash('sha256',$csrf),
                'query'=>$request->getQueryParams()],time()+300);
            $action = htmlspecialchars($this->oauth->issuer.'/integrasjon/oauth/authorize',ENT_QUOTES,'UTF-8');
            $name = htmlspecialchars($this->systemName,ENT_QUOTES,'UTF-8');
            $description = htmlspecialchars($this->consentDescription,ENT_QUOTES,'UTF-8');
            $html = '<!doctype html><html lang="nb"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                .'<title>Koble ChatGPT til '.$name.'</title><main><h1>Koble ChatGPT til '.$name.'?</h1>'
                .'<p>'.$description.'</p><p>Innloggingen kan fornyes automatisk i opptil 24 timer. Administratorrettighetene kontrolleres på nytt ved bruk.</p>'
                .'<form method="post" action="'.$action.'"><input type="hidden" name="pending" value="'.$pending.'">'
                .'<input type="hidden" name="csrf" value="'.$csrf.'"><button name="approve" value="yes">Tillat lesing</button> '
                .'<button name="approve" value="no">Avbryt</button></form></main></html>';
            return new Response(200,['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'no-store',
                'Content-Security-Policy'=>"default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
                'Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff'],$html);
        }
        if (!($this->sameOrigin)($request)) return $this->json(['error'=>'Forbidden origin'],403);
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_string($body['pending'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$body['pending'])
            || !is_string($body['csrf'] ?? null) || !in_array($body['approve'] ?? null,['yes','no'],true)) {
            return $this->json(['error'=>'Invalid consent'],400);
        }
        $pending = $this->oauth->state->get('consent',$body['pending']);
        if (!$pending || $pending['actor'] !== $actor || !hash_equals($pending['csrf'],hash('sha256',$body['csrf']))) {
            return $this->json(['error'=>'Consent expired or invalid'],403);
        }
        $this->db->beginTransaction();
        try {
            if (!$this->oauth->state->consume('consent',$body['pending'])) throw OAuthServerException::accessDenied();
            $response = $this->oauth->authorize($request->withQueryParams($pending['query']),new Response(),$actor,$body['approve']==='yes');
            $q = $this->db->prepare('INSERT INTO audit_log (member_id,handling,objekt_type,objekt_id,detaljer) VALUES (:actor,:action,:type,NULL,:details)');
            $q->execute(['actor'=>$actor,'action'=>'chatgpt_kobling_samtykke','type'=>'integration',
                'details'=>json_encode(['scope'=>$this->oauth->scope,'approved'=>$body['approve']==='yes'],JSON_THROW_ON_ERROR)]);
            $this->db->commit(); return $response->withHeader('Cache-Control','no-store')->withHeader('Referrer-Policy','no-referrer');
        } catch (\Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    private function mcp(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') return $this->json(['error'=>'Method not allowed'],405)->withHeader('Allow','POST');
        $raw = $request->getBody()->read(8193);
        if (strlen($raw)>8192) return $this->json(['error'=>'Request too large'],413);
        try { $rpc = json_decode($raw,true,16,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return $this->json(['error'=>'Invalid JSON'],400); }
        if (!is_array($rpc) || array_is_list($rpc)) return $this->json(['error'=>'One JSON-RPC request required'],400);
        $actor = null;
        if (($rpc['method'] ?? null) === 'tools/call') {
            try { $actor = $this->oauth->actor($request); }
            catch (\Throwable) {
                return $this->json(['error'=>'Authorization required'],401)->withHeader('WWW-Authenticate',
                    'Bearer resource_metadata="'.$this->oauth->issuer.'/.well-known/oauth-protected-resource", scope="'.$this->oauth->scope.'"');
            }
        }
        $request->getBody()->rewind();
        $server = ($this->serverFactory)($actor);
        return $server->run(new StreamableHttpTransport($request,middleware:[
            new CorsMiddleware(allowedOrigins:[$this->oauth->issuer]),
            new DnsRebindingProtectionMiddleware(allowedHosts:[parse_url($this->oauth->issuer,PHP_URL_HOST)]),
        ],maxBodyBytes:8192))->withHeader('Cache-Control','no-store');
    }
    private function json(array $data,int $status=200): ResponseInterface
    {
        return new Response($status,['Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'no-store',
            'X-Content-Type-Options'=>'nosniff'],json_encode($data,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
