<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface AccentColorPortInterface
{
    public function getAccentColor(Uuid $userId): string;

    public function setAccentColor(Uuid $userId, string $color): void;
}
