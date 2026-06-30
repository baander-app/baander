<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Domain\Model\OAuth\ValueObject\Scope;

/**
 * Maps OAuth 2.0 scope identifier strings to domain Scope value objects.
 */
final class ScopeResolver
{
    /**
     * Converts an array of scope identifier strings into domain Scope value objects.
     *
     * Invalid scope strings are silently skipped.
     *
     * @param string[] $scopeIdentifiers
     *
     * @return Scope[]
     */
    public function resolve(array $scopeIdentifiers): array
    {
        $scopes = [];

        foreach ($scopeIdentifiers as $identifier) {
            try {
                $scopes[] = new Scope($identifier);
            } catch (\InvalidArgumentException) {
                // Skip invalid scope identifiers.
                continue;
            }
        }

        return $scopes;
    }
}
