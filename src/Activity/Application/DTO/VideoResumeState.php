<?php

declare(strict_types=1);

namespace App\Activity\Application\DTO;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class VideoResumeState
{
    public function __construct(
        public Uuid $videoId,
        public ?float $positionSeconds,
        public ?DateTimeImmutable $lastPlayedAt,
    ) {}
}
