<?php
declare(strict_types=1);
namespace Kepler\Integrasjon;

use League\OAuth2\Server\Entities\{ClientEntityInterface, ScopeEntityInterface, UserEntityInterface, AuthCodeEntityInterface, AccessTokenEntityInterface,RefreshTokenEntityInterface};
use League\OAuth2\Server\Entities\Traits\{EntityTrait, ClientTrait, ScopeTrait, TokenEntityTrait, AuthCodeTrait, AccessTokenTrait,RefreshTokenTrait};

final class Client implements ClientEntityInterface
{
    use EntityTrait, ClientTrait;
    public function __construct(string $id,string $name,array $redirects,private array $grantTypes)
    {
        $this->setIdentifier($id); $this->name = $name; $this->redirectUri = $redirects;
    }
    public function supportsGrantType(string $grantType): bool {return in_array($grantType,$this->grantTypes,true);}
}
final class Scope implements ScopeEntityInterface { use EntityTrait, ScopeTrait; }
final class User implements UserEntityInterface { use EntityTrait; }
final class AuthCode implements AuthCodeEntityInterface { use EntityTrait, TokenEntityTrait, AuthCodeTrait; }
final class RefreshToken implements RefreshTokenEntityInterface { use EntityTrait,RefreshTokenTrait; }

final class AccessToken implements AccessTokenEntityInterface
{
    use EntityTrait, TokenEntityTrait, AccessTokenTrait;
    public function __construct(private string $issuer, private string $resource,
        public readonly string $grantId,public readonly int $grantExpires) {}
    public function toString(): string
    {
        $this->initJwtConfiguration();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->jwtConfiguration->builder()->issuedBy($this->issuer)->permittedFor($this->resource)
            ->identifiedBy($this->getIdentifier())->issuedAt($now)->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())->relatedTo((string) $this->getUserIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->withClaim('scope', implode(' ', array_map(fn($scope) => $scope->getIdentifier(), $this->getScopes())))
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey())->toString();
    }
}
