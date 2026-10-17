<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\RadioSource;

use App\Radio\Domain\ValueObject\SyncConfig;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class RadioSourceState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public string $name,
        public string $type,
        public SyncConfig $syncConfig,
        public bool $isActive,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
