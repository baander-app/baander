<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\AudioPreferences;

interface AudioPreferencesRepositoryInterface
{
    public function findByUserId(Uuid $userId): ?AudioPreferences;

    public function save(AudioPreferences $model): void;
}
