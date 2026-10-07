<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\Uuid;

/**
 * The signed-in user approves or denies a client's authorization request (RFC 6749 section 4.1.1).
 *
 * The handler answers with an AuthorizationResponseDTO, which carries a code
 * on approval and access_denied on denial, or throws an OAuthProtocolException.
 */
final readonly class CreateAuthorizationCodeCommand
{
    /**
     * @param string[] $scopes
     * @param string|null $redirectUri Optional only when the client registered exactly one redirect URI
     * @param bool $approved The user's decision
     */
    public function __construct(
        public Uuid $userId,
        public string $responseType,
        public string $clientId,
        public ?string $redirectUri,
        public ?string $codeChallenge,
        public ?string $codeChallengeMethod,
        public array $scopes = [],
        public bool $approved = true,
    ) {
    }
}
