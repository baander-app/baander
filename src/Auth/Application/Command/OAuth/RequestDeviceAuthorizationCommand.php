<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Auth\Application\DTO\ClientCredentials;

/**
 * A device client asks for a device code and a user code (RFC 8628 section 3.1),
 * authenticating as it does at the token endpoint.
 */
final readonly class RequestDeviceAuthorizationCommand
{
    /** @param string[] $scopes */
    public function __construct(
        public ClientCredentials $credentials,
        public array $scopes = [],
    ) {
    }
}
