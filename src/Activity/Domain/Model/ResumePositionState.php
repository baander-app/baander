<?php

declare(strict_types=1);

namespace App\Activity\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class ResumePositionState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $userId,
        public readonly Uuid $videoId,
        public ?float $positionSeconds,
        public DateTimeImmutable $reportedAt,
        public ?DateTimeImmutable $lastPlayedAt,
    ) {}
}
