<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

/**
 * Redeems an authorization code at the token endpoint (RFC 6749 section 4.1.3, RFC 7636 section 4.5).
 */
final readonly class ExchangeAuthorizationCodeCommand
{
    /**
     * @param string $dpopJkt Thumbprint of the DPoP proof key the tokens are bound to (RFC 9449)
     */
    public function __construct(
        public string $clientId,
        public ?string $clientSecret,
        public ?string $code,
        public ?string $redirectUri,
        public ?string $codeVerifier,
        public string $dpopJkt,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $clientFingerprint = null,
    ) {
    }
}
