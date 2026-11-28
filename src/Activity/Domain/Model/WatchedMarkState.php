<?php

declare(strict_types=1);

namespace App\Activity\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class WatchedMarkState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $userId,
        public readonly ?Uuid $episodeId,
        public readonly ?Uuid $movieId,
        public DateTimeImmutable $watchedAt,
    ) {}
}
