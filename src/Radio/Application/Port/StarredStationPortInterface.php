<?php

declare(strict_types=1);

namespace App\Radio\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface StarredStationPortInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listStarred(Uuid $userId): array;

    /**
     * @return array<string, mixed>
     */
    public function star(Uuid $userId, Uuid $stationId): array;

    public function unstar(Uuid $userId, Uuid $stationId): void;
}
