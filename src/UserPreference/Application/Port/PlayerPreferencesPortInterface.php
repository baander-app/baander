<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PlayerPreferencesPortInterface
{
    public function getForUser(Uuid $userId): ?array;

    public function saveForUser(Uuid $userId, array $payload, int $version): int;

    public function getVersion(Uuid $userId): ?int;

    /**
     * @return array<int, array{version: int, payload: array, created_at: string}>
     */
    public function getHistory(Uuid $userId, int $limit = 20): array;

    public function rollbackTo(Uuid $userId, int $version): array;
}
