<?php

declare(strict_types=1);

namespace App\Auth\Application;

/**
 * Scopes that password and passkey login may grant.
 *
 * Requested scopes outside the allowlist are dropped, so a login request
 * cannot escalate its token's privileges.
 */
final readonly class ScopeAllowlist
{
    /**
     * @param string[] $scopes Scopes login may grant
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
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }
}
