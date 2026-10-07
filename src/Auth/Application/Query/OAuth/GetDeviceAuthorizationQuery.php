<?php

declare(strict_types=1);

namespace App\Auth\Application\Query\OAuth;

/**
 * Looks up a pending device authorization request by the user code the device displays.
 */
final readonly class GetDeviceAuthorizationQuery
{
    public function __construct(
        public string $userCode,
    ) {
    }
}
