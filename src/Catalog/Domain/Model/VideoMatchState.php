<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class VideoMatchState
{
    public function __construct(
        public Uuid $id,
        public ?Uuid $seriesId,
        public ?Uuid $movieId,
        public string $status,
        public string $source,
        public DateTimeImmutable $dueAt,
        public ?Uuid $claimId = null,
        public ?DateTimeImmutable $claimExpiresAt = null,
        public ?DateTimeImmutable $nextDueAt = null,
        public ?string $reason = null,
        public ?int $bestScore = null,
        public ?DateTimeImmutable $checkedAt = null,
    ) {}
}
