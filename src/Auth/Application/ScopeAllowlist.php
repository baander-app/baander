<?php

declare(strict_types=1);

namespace App\Auth\Application;

/**
 * Enforces scope allowlists per grant type to prevent privilege escalation.
 *
 * User grant types (authorization_code, device_code, refresh_token) are restricted
 * to a safe set of scopes. The client_credentials grant has its own allowlist that
 * can include privileged scopes like 'admin'.
 */
final readonly class ScopeAllowlist
{
    /**
     * @param string[] $userGrants     Scopes allowed for user-bound grant types
     * @param string[] $clientCredentials Scopes allowed for client_credentials grant
     */
    public function __construct(
        private array $userGrants,
        private array $clientCredentials,
    ) {
    }

    /**
     * Filter scopes against the allowlist for the given grant type.
     *
     * Silently drops any scopes not present in the allowlist.
     *
     * @param string[] $requestedScopes Scopes to filter
     * @param string $grantType        OAuth 2.0 grant type identifier
     *
     * @return string[] Only the scopes present in the allowlist, preserving order
     */
    public function filter(array $requestedScopes, string $grantType): array
    {
        $allowlist = $this->getAllowlistForGrantType($grantType);
        $set = array_flip($allowlist);

        return array_values(array_filter(
            $requestedScopes,
            static fn (string $scope): bool => array_key_exists($scope, $set),
        ));
    }

    /**
     * Get the allowlist for a specific grant type.
     *
     * @return string[]
     */
    public function getAllowlistForGrantType(string $grantType): array
    {
        return match ($grantType) {
            'client_credentials' => $this->clientCredentials,
            default => $this->userGrants,
        };
    }

    /**
     * @return string[]
     */
    public function getUserGrants(): array
    {
        return $this->userGrants;
    }

    /**
     * @return string[]
     */
    public function getClientCredentials(): array
    {
        return $this->clientCredentials;
    }
}
