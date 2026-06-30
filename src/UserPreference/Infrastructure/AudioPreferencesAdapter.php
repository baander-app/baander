<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\AudioPreferencesPortInterface;
use App\UserPreference\Domain\Repository\AudioPreferencesRepositoryInterface;
use App\UserPreference\Domain\Repository\PreferenceHistoryRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\AudioPreferencesEntity;
use App\UserPreference\Infrastructure\Doctrine\Entity\PreferenceHistoryEntity;

final class AudioPreferencesAdapter implements AudioPreferencesPortInterface
{
    private const PREFERENCE_TYPE = 'audio';

    public function __construct(
        private readonly AudioPreferencesRepositoryInterface $repository,
        private readonly PreferenceHistoryRepositoryInterface $historyRepository,
    ) {
    }

    public function getForUser(Uuid $userId): ?array
    {
        $entity = $this->repository->findByUserId($userId);

        return $entity?->getPayload();
    }

    public function saveForUser(Uuid $userId, array $payload, int $version): int
    {
        $entity = $this->repository->findByUserId($userId);

        if ($entity !== null) {
            $newVersion = $version + 1;
            $entity->setPayload($payload);
            $entity->setVersion($newVersion);
        } else {
            $newVersion = 1;
            $entity = new AudioPreferencesEntity(Uuid::generate());
            $entity->setUserId($userId);
            $entity->setPayload($payload);
            $entity->setVersion($newVersion);
        }

        $this->repository->save($entity);
        $this->createHistorySnapshot($userId, $newVersion, $payload);

        return $newVersion;
    }

    public function getVersion(Uuid $userId): ?int
    {
        $entity = $this->repository->findByUserId($userId);

        return $entity?->getVersion();
    }

    public function getHistory(Uuid $userId, int $limit = 20): array
    {
        $entries = $this->historyRepository->findByUserAndType($userId, self::PREFERENCE_TYPE, $limit);

        return array_map(
            fn (PreferenceHistoryEntity $entry): array => [
                'version' => $entry->getVersion(),
                'payload' => $entry->getPayload(),
                'created_at' => $entry->getCreatedAt()->format(\DATE_ATOM),
            ],
            $entries,
        );
    }

    public function rollbackTo(Uuid $userId, int $version): array
    {
        $historyEntry = $this->historyRepository->findByUserAndTypeAndVersion(
            $userId,
            self::PREFERENCE_TYPE,
            $version,
        );

        if ($historyEntry === null) {
            throw new \InvalidArgumentException(
                sprintf('No history entry found for version %d.', $version),
            );
        }

        $payload = $historyEntry->getPayload();
        $this->saveForUser($userId, $payload, $version);

        return $payload;
    }

    private function createHistorySnapshot(Uuid $userId, int $version, array $payload): void
    {
        $historyEntry = new PreferenceHistoryEntity(Uuid::generate());
        $historyEntry->setUserId($userId);
        $historyEntry->setPreferenceType(self::PREFERENCE_TYPE);
        $historyEntry->setVersion($version);
        $historyEntry->setPayload($payload);

        $this->historyRepository->save($historyEntry);
    }
}
