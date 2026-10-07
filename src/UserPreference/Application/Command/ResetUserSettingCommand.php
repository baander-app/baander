<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Command;

/**
 * Removes a user's explicit choice so the setting follows its default again.
 */
final readonly class ResetUserSettingCommand
{
    public function __construct(
        public string $userId,
        public string $key,
    ) {
    }
}
