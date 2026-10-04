<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\AudioPreferencesPortInterface;
use App\UserPreference\Application\Port\PreferenceWriterPortInterface;
use App\UserPreference\Domain\Model\PreferenceHistory;
use App\UserPreference\Domain\Repository\AudioPreferencesRepositoryInterface;
use App\UserPreference\Domain\Repository\PreferenceHistoryRepositoryInterface;

final class AudioPreferencesAdapter implements AudioPreferencesPortInterface
{
    private const PREFERENCE_TYPE = 'audio';

    public function __construct(
        private readonly AudioPreferencesRepositoryInterface $repository,
        private readonly PreferenceHistoryRepositoryInterface $historyRepository,
        private readonly PreferenceWriterPortInterface $writer,
    ) {
    }

    public function getSnapshotForUser(Uuid $userId): ?array
    {
        $model = $this->repository->findByUserId($userId);

        return $model === null ? null : ['payload' => $model->getPayload(), 'version' => $model->getVersion()];
    }

    /** @return array<string, mixed>|null */
    public function getForUser(Uuid $userId): ?array
    {
        $model = $this->repository->findByUserId($userId);

        return $model?->getPayload();
    }

    /** @param array<string, mixed> $payload */
    public function saveForUser(Uuid $userId, array $payload, int $version): int
    {
        return $this->writer->saveForUser(self::PREFERENCE_TYPE, $userId, $payload, $version);
    }

    public function getVersion(Uuid $userId): ?int
    {
        $model = $this->repository->findByUserId($userId);

        return $model?->getVersion();
    }

    /** @return list<array{version: int, payload: array<string, mixed>, created_at: string}> */
    public function getHistory(Uuid $userId, int $limit = 20): array
    {
        $entries = $this->historyRepository->findByUserAndType($userId, self::PREFERENCE_TYPE, $limit);

        return array_map(
            fn (PreferenceHistory $entry): array => [
                'version' => $entry->getVersion(),
                'payload' => $entry->getPayload(),
                'created_at' => $entry->getCreatedAt()->format(\DATE_ATOM),
            ],
            $entries,
        );
    }

    /** @return array{payload: array<string, mixed>, version: int} */
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
        $newVersion = $this->saveForUser($userId, $payload, $this->getVersion($userId) ?? 0);

        return ['payload' => $payload, 'version' => $newVersion];
    }
}
