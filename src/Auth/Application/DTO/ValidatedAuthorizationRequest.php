<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Model\User;

/**
 * An authorization request that passed every check and may be granted.
 */
final readonly class ValidatedAuthorizationRequest
{
    /** @param Scope[] $scopes The requested scopes inside the allowlist; empty means the default scopes */
    public function __construct(
        public Client $client,
        public User $user,
        public string $redirectUri,
        public string $codeChallenge,
        public string $codeChallengeMethod,
        public array $scopes,
    ) {
    }

    /**
     * The scopes a token issued for this request carries.
     *
     * @return string[]
     */
    public function effectiveScopes(): array
    {
        $scopes = $this->scopes === [] ? Scope::defaultScopes() : $this->scopes;

        return array_map(static fn (Scope $scope): string => $scope->toString(), $scopes);
    }
}
