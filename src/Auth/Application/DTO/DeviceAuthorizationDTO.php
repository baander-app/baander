<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * The device authorization response (RFC 8628 section 3.2).
 */
final readonly class DeviceAuthorizationDTO
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $verificationUri,
        public string $verificationUriComplete,
        public int $expiresIn,
        public int $interval,
    ) {
    }
}
