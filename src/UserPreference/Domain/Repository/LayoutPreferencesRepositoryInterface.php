<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Infrastructure\Doctrine\Entity\LayoutPreferencesEntity;

interface LayoutPreferencesRepositoryInterface
{
    public function findByUserId(Uuid $userId): ?LayoutPreferencesEntity;

    public function save(LayoutPreferencesEntity $entity): void;
}
