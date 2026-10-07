<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

/**
 * A device client asks for a device code and a user code (RFC 8628 section 3.1).
 */
final readonly class RequestDeviceAuthorizationCommand
{
    /** @param string[] $scopes */
    public function __construct(
        public string $clientId,
        public array $scopes = [],
    ) {
    }
}
