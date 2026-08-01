<?php

declare(strict_types=1);

namespace App\Radio\Domain\Repository\StarredStation;

use App\Radio\Domain\Model\StarredStation\StarredStation;
use App\Shared\Domain\Model\Uuid;

interface StarredStationRepositoryInterface
{
    public function find(Uuid $id): ?StarredStation;

    public function findByUserId(Uuid $userId): array;

    public function findByUserIdAndStationId(Uuid $userId, Uuid $stationId): ?StarredStation;

    public function save(StarredStation $starred): void;

    public function remove(StarredStation $starred): void;
}
