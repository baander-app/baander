<?php

declare(strict_types=1);

namespace App\Activity\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class ResumePosition
{
    private function __construct(private ResumePositionState $state)
    {
        if ($state->positionSeconds !== null && (!is_finite($state->positionSeconds) || $state->positionSeconds < 0)) {
            throw new InvalidArgumentException('A resume position must be finite and nonnegative.');
        }
    }

    public static function reconstitute(ResumePositionState $state): self
    {
        return new self($state);
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    public function getVideoId(): Uuid
    {
        return $this->state->videoId;
    }

    public function getPositionSeconds(): ?float
    {
        return $this->state->positionSeconds;
    }

    public function getReportedAt(): DateTimeImmutable
    {
        return $this->state->reportedAt;
    }

    public function getLastPlayedAt(): ?DateTimeImmutable
    {
        return $this->state->lastPlayedAt;
    }
}
