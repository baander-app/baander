<?php

declare(strict_types=1);

namespace App\Session\Domain\Model\Device;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use DateTimeImmutable;

final class DeviceState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $userId,
        public readonly Uuid $deviceId,
        public ?string $name,
        public ?DateTimeImmutable $lastSeenAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
