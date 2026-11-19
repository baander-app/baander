<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequest;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Model\User;

/**
 * A pushed authorization request that passed every check for the signed-in user and may be granted.
 */
final readonly class ValidatedAuthorizationRequest
{
    public function __construct(
        public Client $client,
        public User $user,
        public PushedAuthorizationRequest $pushedRequest,
    ) {
    }

    /**
     * The scopes a token issued for this request carries.
     *
     * @return string[]
     */
    public function effectiveScopes(): array
    {
        $scopes = $this->pushedRequest->getScopes();

        return array_map(static fn (Scope $scope): string => $scope->toString(), $scopes === [] ? Scope::defaultScopes() : $scopes);
    }
}
