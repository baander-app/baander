<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure;

use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Discovery\Domain\Model\ServerInstance;
use App\Discovery\Domain\Repository\ServerInstanceRepositoryInterface;
use App\Shared\Domain\Model\PublicId;

final readonly class ServerInstanceService implements ServerInstancePortInterface
{
    public function __construct(
        private ServerInstanceRepositoryInterface $serverRepository,
    ) {
    }

    public function register(
        string $serverUrl,
        string $name,
        string $version,
        string $apiKey,
    ): ServerInstance {
        $existing = $this->serverRepository->findByServerUrl($serverUrl);
        if ($existing !== null) {
            $existing->updateVersion($version);
            $existing->updateHeartbeat();
            $this->serverRepository->save($existing);

            return $existing;
        }

        $server = ServerInstance::create($serverUrl, $name, $version, $apiKey);
        $this->serverRepository->save($server);

        return $server;
    }

    public function findByPublicId(PublicId $publicId): ?ServerInstance
    {
        return $this->serverRepository->findByPublicId($publicId);
    }

    public function findByServerUrl(string $serverUrl): ?ServerInstance
    {
        return $this->serverRepository->findByServerUrl($serverUrl);
    }

    public function heartbeat(PublicId $publicId): void
    {
        $server = $this->serverRepository->findByPublicId($publicId);
        if ($server !== null) {
            $server->updateHeartbeat();
            $this->serverRepository->save($server);
        }
    }

    /** @return ServerInstance[] */
    public function findStale(int $thresholdSeconds = 300): array
    {
        return $this->serverRepository->findStale($thresholdSeconds);
    }
}
