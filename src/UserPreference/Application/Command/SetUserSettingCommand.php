<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Command;

/**
 * Stores a user's explicit choice for one setting. The value is raw input —
 * decoded JSON or a CLI string — and is parsed against the definition.
 */
final readonly class SetUserSettingCommand
{
    public function __construct(
        public string $userId,
        public string $key,
        public mixed $value,
    ) {
    }
}
