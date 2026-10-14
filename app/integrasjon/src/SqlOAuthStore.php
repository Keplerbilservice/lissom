<?php
declare(strict_types=1);
namespace Kepler\Integrasjon;

use League\OAuth2\Server\Entities\{ClientEntityInterface, AccessTokenEntityInterface, AuthCodeEntityInterface, RefreshTokenEntityInterface, ScopeEntityInterface};
use League\OAuth2\Server\Repositories\{ClientRepositoryInterface, AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, RefreshTokenRepositoryInterface, ScopeRepositoryInterface};
use League\OAuth2\Server\Exception\{OAuthServerException, UniqueTokenIdentifierConstraintViolationException};

final class SqlOAuthStore implements ClientRepositoryInterface, AccessTokenRepositoryInterface,
    AuthCodeRepositoryInterface, RefreshTokenRepositoryInterface, ScopeRepositoryInterface
{
    private ?array $grant = null;
    public bool $reuseDetected = false;
    public function __construct(private SqlState $state, private string $issuer, private string $resource, private string $scope,
        private \Closure $actorAllowed) {}
    public function resetGrant(): void {$this->grant=null;$this->reuseDetected=false;}
    public function register(string $name, array $redirects,array $grants): string
    {
        $id = bin2hex(random_bytes(32));
        $this->state->put('client',$id,['name' => $name,'redirects' => $redirects,'grants'=>$grants],time()+86400*365);
        return $id;
    }
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $data = $this->state->get('client',$clientIdentifier); if (!$data) return null;
        return new Client($clientIdentifier,$data['name'],$data['redirects'],$data['grants']);
    }
    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $client=$this->getClientEntity($clientIdentifier);
        return $clientSecret === null && in_array($grantType,['authorization_code','refresh_token'],true)
            && $client !== null && $client->supportsGrantType($grantType);
    }
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        if ($identifier !== $this->scope) return null;
        $scope = new Scope(); $scope->setIdentifier($identifier); return $scope;
    }
    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        if (!in_array($grantType,['authorization_code','refresh_token'],true) || count($scopes) !== 1 || $scopes[0]->getIdentifier() !== $this->scope) {
            throw OAuthServerException::invalidScope($this->scope);
        }
        if ($grantType==='authorization_code') {
            $code=$authCodeId!==null ? $this->state->get('code',$authCodeId) : null;
            $this->grant=$code ? $this->state->get('grant',$code['grant']) : null;
        }
        if (!$this->grant || $this->grant['actor']!==$userIdentifier || $this->grant['client']!==$clientEntity->getIdentifier()
            || $this->grant['expires']<=time() || !$userIdentifier || !ctype_digit($userIdentifier)
            || !($this->actorAllowed)((int)$userIdentifier)) throw OAuthServerException::accessDenied();
        return $scopes;
    }
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        if(!$this->grant)throw OAuthServerException::accessDenied();
        $token = new AccessToken($this->issuer,$this->resource,$this->grant['id'],$this->grant['expires']); $token->setClient($clientEntity);
        $token->setUserIdentifier($userIdentifier); foreach ($scopes as $scope) $token->addScope($scope); return $token;
    }
    public function persistNewAccessToken(AccessTokenEntityInterface $token): void
    {
        if(!$token instanceof AccessToken)throw new \LogicException('Resource-bound token required');
        $expiry=min($token->getExpiryDateTime()->getTimestamp(),$token->grantExpires);
        $token->setExpiryDateTime(new \DateTimeImmutable('@'.$expiry));
        $this->persist('access',$token->getIdentifier(),['actor' => $token->getUserIdentifier(),'grant'=>$token->grantId,
            'resource' => $this->resource,'scopes' => array_map(fn($s) => $s->getIdentifier(),$token->getScopes())],$expiry);
    }
    private function persist(string $kind,string $id,array $data,int $expires): void
    {
        try { $this->state->put($kind,$id,$data,$expires); }
        catch (\PDOException $e) {
            if (in_array((string)$e->getCode(),['23000','23505'],true)) throw UniqueTokenIdentifierConstraintViolationException::create();
            throw $e;
        }
    }
    public function revokeAccessToken(string $tokenId): void { $this->state->consume('access',$tokenId); }
    public function isAccessTokenRevoked(string $tokenId): bool {
        $access=$this->state->get('access',$tokenId);
        return !$access || !$this->state->get('grant',$access['grant']);
    }
    public function access(string $tokenId): ?array { return $this->state->get('access',$tokenId); }
    public function getNewAuthCode(): AuthCodeEntityInterface { return new AuthCode(); }
    public function persistNewAuthCode(AuthCodeEntityInterface $code): void
    {
        $grantId=$code->getIdentifier();$expiry=time()+86400;
        $this->persist('grant',$grantId,['id'=>$grantId,'actor'=>$code->getUserIdentifier(),'client'=>$code->getClient()->getIdentifier(),
            'resource'=>$this->resource,'expires'=>$expiry],$expiry);
        $this->persist('code',$code->getIdentifier(),['actor' => $code->getUserIdentifier(),'resource' => $this->resource,'grant'=>$grantId],$code->getExpiryDateTime()->getTimestamp());
    }
    public function revokeAuthCode(string $codeId): void
    {
        if (!$this->state->consume('code',$codeId)) throw OAuthServerException::invalidGrant('Code already consumed');
    }
    public function isAuthCodeRevoked(string $codeId): bool { return $this->state->get('code',$codeId) === null; }
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface { return new RefreshToken(); }
    public function persistNewRefreshToken(RefreshTokenEntityInterface $token): void {
        $access=$token->getAccessToken();
        if(!$access instanceof AccessToken)throw new \LogicException('Resource-bound token required');
        $expiry=min($token->getExpiryDateTime()->getTimestamp(),$access->grantExpires);
        $token->setExpiryDateTime(new \DateTimeImmutable('@'.$expiry));
        $this->persist('refresh',$token->getIdentifier(),['grant'=>$access->grantId],$expiry);
    }
    public function revokeRefreshToken(string $tokenId): void {
        if(!$this->state->consume('refresh',$tokenId)) {
            if($this->grant){$this->state->consume('grant',$this->grant['id']);$this->reuseDetected=true;}
            throw OAuthServerException::invalidRefreshToken('Already used');
        }
    }
    public function isRefreshTokenRevoked(string $tokenId): bool {
        $record=$this->state->record('refresh',$tokenId);
        if(!$record || $record['expires']<=time())return true;
        $this->grant=$this->state->get('grant',$record['value']['grant']);
        if($record['revoked']){
            if($this->grant){$this->state->consume('grant',$this->grant['id']);$this->reuseDetected=true;}
            return true;
        }
        return $this->grant===null;
    }
}
