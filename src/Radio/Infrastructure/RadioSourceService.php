<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure;

use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Domain\Model\RadioSource\RadioSource;
use App\Radio\Domain\Repository\RadioSource\RadioSourceRepositoryInterface;
use App\Radio\Domain\ValueObject\SyncConfig;
use App\Shared\Domain\Model\Uuid;

final class RadioSourceService implements RadioSourcePortInterface
{
    public function __construct(
        private readonly RadioSourceRepositoryInterface $repository,
    ) {
    }

    public function listSources(): array
    {
        $sources = $this->repository->findAll();

        return array_map($this->sourceToArray(...), $sources);
    }

    public function createSource(string $name, string $type, string $syncUrl, array $syncConfig, ?string $syncSchedule = null): array
    {
        $source = RadioSource::create(
            name: $name,
            type: $type,
            syncConfig: new SyncConfig(
                syncUrl: $syncUrl,
                schedule: $syncSchedule,
                config: $syncConfig,
            ),
        );

        $this->repository->save($source);

        return $this->sourceToArray($source);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceToArray(RadioSource $source): array
    {
        $syncConfig = $source->getSyncConfig();

        return [
            'id' => $source->getId()->toString(),
            'name' => $source->getName(),
            'type' => $source->getType(),
            'syncUrl' => $syncConfig->syncUrl,
            'syncConfig' => $syncConfig->config,
            'syncSchedule' => $syncConfig->schedule,
            'isActive' => $source->isActive(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
