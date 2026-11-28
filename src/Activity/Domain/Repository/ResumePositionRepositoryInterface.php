<?php

declare(strict_types=1);

namespace App\Activity\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

interface ResumePositionRepositoryInterface
{
    /** Requires the caller's transaction and title lock. Returns whether this report was accepted. */
    public function record(
        Uuid $userId,
        Uuid $videoId,
        ?float $positionSeconds,
        DateTimeImmutable $reportedAt,
        bool $force,
        int $clockSkewSeconds = 0,
    ): bool;

    /** @param list<Uuid> $videoIds */
    public function clear(Uuid $userId, array $videoIds, DateTimeImmutable $clearedAt): void;
}
