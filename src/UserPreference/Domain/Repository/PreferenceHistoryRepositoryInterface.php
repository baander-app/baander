<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\PreferenceHistory;

interface PreferenceHistoryRepositoryInterface
{
    /**
     * @return PreferenceHistory[]
     */
    public function findByUserAndType(Uuid $userId, string $preferenceType, int $limit = 20): array;

    public function findByUserAndTypeAndVersion(Uuid $userId, string $preferenceType, int $version): ?PreferenceHistory;

    public function save(PreferenceHistory $model): void;
}
