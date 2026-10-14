<?php
declare(strict_types=1);
namespace Kepler\Integrasjon;

use League\OAuth2\Server\{AuthorizationServer, ResourceServer, CryptKey};
use League\OAuth2\Server\Grant\{AuthCodeGrant,RefreshTokenGrant};
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Encoding\JoseEncoder;

final class OAuth
{
    public readonly SqlOAuthStore $store;
    public readonly SqlState $state;
    private AuthorizationServer $server;
    private ResourceServer $guard;
    public function __construct(private \PDO $db, public readonly string $issuer, public readonly string $resource,
        public readonly string $scope, string $privateKey, string $encryptionKey,private \Closure $actorAllowed)
    {
        if (!preg_match('~^https://[a-z0-9.-]+(?::[0-9]+)?$~D',$issuer) || $resource!==$issuer.'/integrasjon/mcp'
            || !preg_match('/^[a-zA-Z0-9:_-]+$/D',$scope)) throw new \InvalidArgumentException('Invalid resource configuration');
        if (strlen($encryptionKey) < 32) throw new \InvalidArgumentException('Encryption key too short');
        $key = openssl_pkey_get_private($privateKey); $details = $key ? openssl_pkey_get_details($key) : false;
        if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) throw new \InvalidArgumentException('RSA key required');
        $this->state = new SqlState($db,$issuer);
        $this->store = new SqlOAuthStore($this->state,$issuer,$resource,$scope,$actorAllowed);
        $this->server = new AuthorizationServer($this->store,$this->store,$this->store,new CryptKey($privateKey,null,false),$encryptionKey);
        $grant = new AuthCodeGrant($this->store,$this->store,new \DateInterval('PT5M'));
        $grant->setRefreshTokenTTL(new \DateInterval('P1D'));
        $this->server->enableGrantType($grant,new \DateInterval('PT10M'));
        $refresh = new RefreshTokenGrant($this->store);$refresh->setRefreshTokenTTL(new \DateInterval('P1D'));
        $this->server->enableGrantType($refresh,new \DateInterval('PT10M'));
        $this->guard = new ResourceServer($this->store,new CryptKey($details['key'],null,false));
    }
    public function metadata(): array
    {
        return ['issuer' => $this->issuer,'authorization_endpoint' => $this->issuer.'/integrasjon/oauth/authorize',
            'token_endpoint' => $this->issuer.'/integrasjon/oauth/token','registration_endpoint' => $this->issuer.'/integrasjon/oauth/register',
            'response_types_supported' => ['code'],'grant_types_supported' => ['authorization_code','refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [$this->scope],'authorization_response_iss_parameter_supported' => true];
    }
    public function validateAuthorization(ServerRequestInterface $request): void
    {
        $q = $request->getQueryParams();
        if (($q['resource'] ?? null) !== $this->resource || ($q['scope'] ?? null) !== $this->scope
            || ($q['code_challenge_method'] ?? null) !== 'S256'
            || !is_string($q['code_challenge'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{43}$/D',$q['code_challenge'])
            || !is_string($q['state'] ?? null) || strlen($q['state']) < 16 || strlen($q['state']) > 512) {
            throw OAuthServerException::invalidRequest('resource, scope, state or PKCE');
        }
        $this->server->validateAuthorizationRequest($request);
    }
    public function authorize(ServerRequestInterface $request,ResponseInterface $response,int $actor,bool $approved): ResponseInterface
    {
        $this->validateAuthorization($request);
        if($actor<1 || !($this->actorAllowed)($actor))throw OAuthServerException::accessDenied();
        $auth = $this->server->validateAuthorizationRequest($request);
        $user = new User(); $user->setIdentifier((string)$actor); $auth->setUser($user); $auth->setAuthorizationApproved($approved);
        try {
            $response = $this->server->completeAuthorizationRequest($auth,$response);
        } catch (OAuthServerException $e) {
            // A validated user's explicit refusal is a completed consent, not a rollback.
            if ($approved || $e->getErrorType() !== 'access_denied') throw $e;
            $response = $e->generateHttpResponse($response);
            if ($response->getHeaderLine('Location') === '') throw $e;
        }
        $location = $response->getHeaderLine('Location');
        return $location === '' ? $response : $response->withHeader('Location',$location.(str_contains($location,'?')?'&':'?').'iss='.rawurlencode($this->issuer));
    }
    public function token(ServerRequestInterface $request,ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || ($body['resource'] ?? null) !== $this->resource) throw OAuthServerException::invalidRequest('resource');
        $this->store->resetGrant();
        $this->db->beginTransaction();
        try {
            $result = $this->server->respondToAccessTokenRequest($request,$response);
            $this->db->commit(); return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                if($this->store->reuseDetected)$this->db->commit();else $this->db->rollBack();
            }
            throw $e;
        }
    }
    public function actor(ServerRequestInterface $request): int
    {
        $verified = $this->guard->validateAuthenticatedRequest($request);
        $raw = substr($request->getHeaderLine('Authorization'),7);
        $claims = (new Parser(new JoseEncoder()))->parse($raw)->claims();
        $id = (string)$verified->getAttribute('oauth_user_id');
        $record = $this->store->access((string)$verified->getAttribute('oauth_access_token_id'));
        if ($claims->get('iss') !== $this->issuer || $claims->get('aud') !== [$this->resource]
            || !ctype_digit($id) || (int)$id < 1 || !$record || $record['actor'] !== $id
            || $record['resource'] !== $this->resource || $record['scopes'] !== [$this->scope]
            || $verified->getAttribute('oauth_scopes') !== [$this->scope]) throw OAuthServerException::accessDenied();
        if (!($this->actorAllowed)((int)$id)) throw OAuthServerException::accessDenied();
        return (int)$id;
    }
}
