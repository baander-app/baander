<?php

declare(strict_types=1);

namespace App\Shared\Application\Command;

/**
 * Removes the explicit value of a server-wide setting so it follows its default.
 */
final readonly class ResetSystemSettingCommand
{
    public function __construct(
        public string $key,
    ) {
    }
}
