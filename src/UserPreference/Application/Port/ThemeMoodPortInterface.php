<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface ThemeMoodPortInterface
{
    public function getThemeMood(Uuid $userId): string;

    public function setThemeMood(Uuid $userId, string $mood): void;
}
