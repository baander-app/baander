<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\LayoutPreferencesPortInterface;
use App\UserPreference\Domain\Model\LayoutPreferences;
use App\UserPreference\Domain\Model\PreferenceHistory;
use App\UserPreference\Domain\Repository\LayoutPreferencesRepositoryInterface;
use App\UserPreference\Domain\Repository\PreferenceHistoryRepositoryInterface;

final class LayoutPreferencesAdapter implements LayoutPreferencesPortInterface
{
    private const PREFERENCE_TYPE = 'layout';

    public function __construct(
        private readonly LayoutPreferencesRepositoryInterface $repository,
        private readonly PreferenceHistoryRepositoryInterface $historyRepository,
    ) {
    }

    public function getForUser(Uuid $userId): ?array
    {
        $model = $this->repository->findByUserId($userId);

        return $model?->getPayload();
    }

    public function saveForUser(Uuid $userId, array $payload, int $version): int
    {
        $model = $this->repository->findByUserId($userId);

        if ($model !== null) {
            $newVersion = $version + 1;
            $model->updatePayload($payload, $newVersion);
        } else {
            $newVersion = 1;
            $model = LayoutPreferences::create($userId, $payload, $newVersion);
        }

        $this->repository->save($model);
        $this->createHistorySnapshot($userId, $newVersion, $payload);

        return $newVersion;
    }

    public function getVersion(Uuid $userId): ?int
    {
        $model = $this->repository->findByUserId($userId);

        return $model?->getVersion();
    }

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
        $historyEntry = PreferenceHistory::create(
            $userId,
            self::PREFERENCE_TYPE,
            $version,
            $payload,
        );

        $this->historyRepository->save($historyEntry);
    }
}
