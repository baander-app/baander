<?php

declare(strict_types=1);

namespace App\Auth\Application;

use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;

/**
 * Scopes a user token may carry, from login, the authorization code grant or the
 * device grant.
 *
 * Requested scopes outside the allowlist are dropped, so a request cannot escalate
 * its token's privileges. A delegated request left with nothing to grant is refused.
 */
final readonly class ScopeAllowlist
{
    /**
     * @param string[] $scopes Scopes a user token may carry
     */
    public function __construct(
        private array $scopes,
    ) {
    }

    /**
     * @param string[] $requestedScopes
     *
     * @return string[] Only the allowed scopes, preserving order
     */
    public function filter(array $requestedScopes): array
    {
        $set = array_flip($this->scopes);

        return array_values(array_filter(
            $requestedScopes,
            static fn (string $scope): bool => array_key_exists($scope, $set),
        ));
    }

    /**
     * The scopes a delegated grant will carry: the requested scopes the allowlist holds,
     * each once. Requested scopes outside it are dropped.
     *
     * @param string[] $requestedScopes
     *
     * @return list<Scope>
     *
     * @throws OAuthProtocolException invalid_scope when none of the requested scopes can be granted
     */
    public function grantable(array $requestedScopes): array
    {
        $granted = array_values(array_unique($this->filter($requestedScopes)));
        if ($granted === []) {
            throw OAuthProtocolException::invalidScope(sprintf(
                'None of the requested scopes can be granted; request at least one of: %s.',
                implode(', ', $this->scopes),
            ));
        }

        return array_map(static fn (string $scope): Scope => new Scope($scope), $granted);
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }
}
