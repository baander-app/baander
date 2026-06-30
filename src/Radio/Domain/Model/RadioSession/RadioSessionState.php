<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\RadioSession;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class RadioSessionState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $userId,
        public ?Uuid $activeStationId,
        public ?string $activeStreamUrl,
        public string $state,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
