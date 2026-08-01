<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\PlayerPreferences;

interface PlayerPreferencesRepositoryInterface
{
    public function findByUserId(Uuid $userId): ?PlayerPreferences;

    public function save(PlayerPreferences $model): void;
}
