<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

/**
 * A device polls the token endpoint with its device code (RFC 8628 section 3.4).
 */
final readonly class ExchangeDeviceCodeCommand
{
    /**
     * @param string $dpopJkt Thumbprint of the DPoP proof key the tokens are bound to (RFC 9449)
     */
    public function __construct(
        public string $clientId,
        public ?string $clientSecret,
        public ?string $deviceCode,
        public string $dpopJkt,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $clientFingerprint = null,
    ) {
    }
}
