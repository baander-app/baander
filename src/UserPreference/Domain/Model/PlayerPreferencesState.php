<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class PlayerPreferencesState
{
    /** @param array<array-key, mixed> $payload */
    public function __construct(
        public Uuid $id,
        public Uuid $userId,
        public array $payload,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
