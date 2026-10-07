<?php

declare(strict_types=1);

namespace App\Auth\Application\Query\OAuth;

use App\Shared\Domain\Model\Uuid;

/**
 * Validates an authorization request (RFC 6749 section 4.1.1) before the signed-in user decides on it.
 *
 * The handler answers with an AuthorizationRequestDTO or an OAuthProtocolException.
 */
final readonly class GetAuthorizationRequestQuery
{
    /** @param string[] $scopes */
    public function __construct(
        public Uuid $userId,
        public string $responseType,
        public string $clientId,
        public ?string $redirectUri,
        public ?string $codeChallenge,
        public ?string $codeChallengeMethod,
        public array $scopes = [],
    ) {
    }
}
