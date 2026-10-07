<?php

declare(strict_types=1);

namespace App\Shared\Application\Command;

/**
 * Sets several server-wide settings at once. Values are raw input — decoded
 * JSON or CLI strings — and are parsed against each setting's definition.
 */
final readonly class UpdateSystemSettingsCommand
{
    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(
        public array $values,
    ) {
    }
}
