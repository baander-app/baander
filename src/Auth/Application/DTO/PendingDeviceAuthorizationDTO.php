<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

use DateTimeImmutable;

/**
 * What the user sees before approving a device: the requesting client and scopes.
 */
final readonly class PendingDeviceAuthorizationDTO
{
    /** @param string[] $scopes */
    public function __construct(
        public string $userCode,
        public string $clientId,
        public string $clientName,
        public array $scopes,
        public ?DateTimeImmutable $expiresAt,
    ) {
    }
}
