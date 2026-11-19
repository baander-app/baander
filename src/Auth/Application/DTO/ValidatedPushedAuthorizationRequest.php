<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;

/**
 * Pushed authorization request parameters that passed every check that does not depend on a user.
 */
final readonly class ValidatedPushedAuthorizationRequest
{
    /** @param list<Scope> $scopes The requested scopes inside the allowlist; empty means the default scopes */
    public function __construct(
        public Client $client,
        public string $redirectUri,
        public string $codeChallenge,
        public string $codeChallengeMethod,
        public array $scopes,
    ) {
    }
}
