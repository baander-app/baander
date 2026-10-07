<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

/**
 * A client refreshes its token pair at the token endpoint (RFC 6749 section 6).
 *
 * The handler authenticates the client and rotates the pair like first-party refresh.
 */
final readonly class ExchangeRefreshTokenCommand
{
    public function __construct(
        public string $clientId,
        public ?string $clientSecret,
        public ?string $refreshToken,
        public string $dpopJkt,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $clientFingerprint = null,
    ) {
    }
}
