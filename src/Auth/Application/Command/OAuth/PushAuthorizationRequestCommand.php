<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Auth\Application\DTO\ClientCredentials;

/**
 * A client pushes its authorization request on the back channel (RFC 9126 section 2).
 *
 * The handler answers with a PushedAuthorizationRequestDTO or throws an OAuthProtocolException.
 */
final readonly class PushAuthorizationRequestCommand
{
    /**
     * @param string[] $scopes
     * @param string|null $redirectUri Optional only when the client registered exactly one redirect URI
     * @param string|null $proofJkt    Thumbprint of the key of the verified DPoP proof the request carried, if any
     * @param string|null $dpopJkt     The dpop_jkt parameter (RFC 9449 section 10), if sent
     */
    public function __construct(
        public ClientCredentials $credentials,
        public ?string $responseType,
        public ?string $redirectUri,
        public array $scopes,
        public ?string $state,
        public ?string $codeChallenge,
        public ?string $codeChallengeMethod,
        public ?string $proofJkt,
        public ?string $dpopJkt,
    ) {
    }
}
