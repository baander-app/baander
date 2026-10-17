<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\Passkey;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use DateTimeImmutable;

/**
 * Internal state for Passkey aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class PasskeyState
{
    /** @param array<array-key, mixed> $data */
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public string $name,
        public readonly string $credentialId,
        public readonly array $data,
        public int $counter,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $lastUsedAt = null,
    ) {
    }
}
