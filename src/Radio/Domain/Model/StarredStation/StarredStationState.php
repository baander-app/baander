<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\StarredStation;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class StarredStationState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $userId,
        public readonly Uuid $stationId,
        public readonly DateTimeImmutable $starredAt,
    ) {
    }
}
