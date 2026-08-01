<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Repository;

use App\Discovery\Domain\Model\ServerInstance;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface ServerInstanceRepositoryInterface
{
    public function save(ServerInstance $server): void;

    public function persist(ServerInstance $server): void;

    public function flush(): void;

    public function findByUuid(Uuid $uuid): ?ServerInstance;

    public function findByPublicId(PublicId $publicId): ?ServerInstance;

    public function findByServerUrl(string $serverUrl): ?ServerInstance;

    /**
     * Find servers that have not sent a heartbeat within the given threshold.
     *
     * @return ServerInstance[]
     */
    public function findStale(int $thresholdSeconds = 300): array;

    public function delete(ServerInstance $server): void;
}
