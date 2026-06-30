<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;

interface AccentColorRepositoryInterface
{
    public function getAccentColor(Uuid $userId): ?string;

    public function setAccentColor(Uuid $userId, string $color): void;
}
