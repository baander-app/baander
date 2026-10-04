<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class EqDeviceProfileState
{
    /** @param array<array-key, mixed> $payload */
    public function __construct(
        public Uuid $id,
        public Uuid $userId,
        public string $name,
        public string $icon,
        public ?string $deviceId,
        public array $payload,
        public bool $isDefault,
        public int $sortOrder,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
