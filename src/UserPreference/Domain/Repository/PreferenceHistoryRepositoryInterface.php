<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Infrastructure\Doctrine\Entity\PreferenceHistoryEntity;

interface PreferenceHistoryRepositoryInterface
{
    /**
     * @return PreferenceHistoryEntity[]
     */
    public function findByUserAndType(Uuid $userId, string $preferenceType, int $limit = 20): array;

    public function findByUserAndTypeAndVersion(Uuid $userId, string $preferenceType, int $version): ?PreferenceHistoryEntity;

    public function save(PreferenceHistoryEntity $entity): void;
}
