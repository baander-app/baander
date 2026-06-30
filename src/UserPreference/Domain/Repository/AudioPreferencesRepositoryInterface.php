<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Infrastructure\Doctrine\Entity\AudioPreferencesEntity;

interface AudioPreferencesRepositoryInterface
{
    public function findByUserId(Uuid $userId): ?AudioPreferencesEntity;

    public function save(AudioPreferencesEntity $entity): void;
}
