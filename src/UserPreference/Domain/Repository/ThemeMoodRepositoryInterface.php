<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;

interface ThemeMoodRepositoryInterface
{
    public function getThemeMood(Uuid $userId): ?string;

    public function setThemeMood(Uuid $userId, string $mood): void;
}
