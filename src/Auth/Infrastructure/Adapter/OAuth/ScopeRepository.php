<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Adapter\OAuth;

use App\Auth\Application\ScopeAllowlist;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * Anti-corruption layer implementing the league/oauth2-server ScopeRepositoryInterface.
 *
 * Scopes have no domain repository equivalent -- this adapter creates scope entities
 * on demand. If scope persistence with descriptions becomes needed, a domain
 * ScopeRepository can be added and this adapter can delegate to it.
 *
 * Enforces the scope allowlist to prevent privilege escalation: user-bound grant
 * types cannot request privileged scopes like 'admin'.
 */
final class ScopeRepository implements ScopeRepositoryInterface
{
    public function __construct(
        private readonly ScopeAllowlist $scopeAllowlist,
    ) {
    }

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return new ScopeEntity($identifier, '');
    }

    /**
     * Filter scopes against the allowlist for the given grant type.
     *
     * @param ScopeEntityInterface[] $scopes
     *
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        $identifiers = array_map(
            static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
            $scopes,
        );

        $filtered = $this->scopeAllowlist->filter($identifiers, $grantType);

        return array_map(
            fn (string $identifier): ScopeEntityInterface => $this->getScopeEntityByIdentifier($identifier),
            $filtered,
        );
    }
}
