<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\LayoutPreferences;

interface LayoutPreferencesRepositoryInterface
{
    public function findByUserId(Uuid $userId): ?LayoutPreferences;

    public function save(LayoutPreferences $model): void;
}
