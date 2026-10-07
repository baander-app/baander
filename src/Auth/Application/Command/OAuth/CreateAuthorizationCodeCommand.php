<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\Uuid;

/**
 * The signed-in user authorizes a client at the authorization endpoint (RFC 6749 section 4.1.1).
 *
 * The handler answers with an AuthorizationCodeDTO or an OAuthProtocolException.
 */
final readonly class CreateAuthorizationCodeCommand
{
    /**
     * @param string[] $scopes
     * @param string|null $redirectUri Optional only when the client registered exactly one redirect URI
     */
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
