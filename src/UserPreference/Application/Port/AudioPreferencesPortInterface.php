<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface AudioPreferencesPortInterface
{
    /** @return array{payload: array<string, mixed>, version: int}|null */
    public function getSnapshotForUser(Uuid $userId): ?array;

    /** @return array<string, mixed>|null */
    public function getForUser(Uuid $userId): ?array;

    /** @param array<string, mixed> $payload */
    public function saveForUser(Uuid $userId, array $payload, int $version): int;

    public function getVersion(Uuid $userId): ?int;

    /**
     * @return array<int, array{version: int, payload: array<string, mixed>, created_at: string}>
     */
    public function getHistory(Uuid $userId, int $limit = 20): array;

    /** @return array{payload: array<string, mixed>, version: int} */
    public function rollbackTo(Uuid $userId, int $version): array;
}
