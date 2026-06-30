<?php

declare(strict_types=1);

namespace App\Discovery\Application\Port;

use App\Discovery\Domain\Model\ServerInstance;
use App\Shared\Domain\Model\PublicId;

interface ServerInstancePortInterface
{
    public function register(
        string $serverUrl,
        string $name,
        string $version,
        string $apiKey,
    ): ServerInstance;

    public function findByPublicId(PublicId $publicId): ?ServerInstance;

    public function findByServerUrl(string $serverUrl): ?ServerInstance;

    public function heartbeat(PublicId $publicId): void;

    /** @return ServerInstance[] */
    public function findStale(int $thresholdSeconds = 300): array;
}
