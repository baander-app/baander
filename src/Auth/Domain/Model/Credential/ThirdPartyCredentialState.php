<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\Credential;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for ThirdPartyCredential aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class ThirdPartyCredentialState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $userId,
        public string $provider,
        public ?string $accessToken,
        public ?string $refreshToken,
        public ?DateTimeImmutable $expiresAt,
        public array $metadata,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
