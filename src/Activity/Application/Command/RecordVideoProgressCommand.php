<?php

declare(strict_types=1);

namespace App\Activity\Application\Command;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class RecordVideoProgressCommand
{
    public function __construct(
        public Uuid $memberId,
        public PublicId $videoPublicId,
        public string $titleKind,
        public PublicId $titlePublicId,
        public float $positionSeconds,
        public VideoProgressAction $action,
        public DateTimeImmutable $reportedAt,
    ) {}
}
