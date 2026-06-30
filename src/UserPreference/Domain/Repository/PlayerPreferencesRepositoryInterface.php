<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Infrastructure\Doctrine\Entity\PlayerPreferencesEntity;

interface PlayerPreferencesRepositoryInterface
{
    public function findByUserId(Uuid $userId): ?PlayerPreferencesEntity;

    public function save(PlayerPreferencesEntity $entity): void;
}
