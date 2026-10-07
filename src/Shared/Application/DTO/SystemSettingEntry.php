<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use App\Shared\Domain\Model\Setting\SettingDefinition;

/**
 * The administrator's view of one server-wide setting. A stored value that is
 * no longer allowed is kept visible and marked invalid, so an operator can see
 * why the default applies.
 */
final readonly class SystemSettingEntry
{
    public function __construct(
        public SettingDefinition $definition,
        public bool|int|string $value,
        public mixed $storedValue,
        public bool $storedValueValid,
    ) {
    }

    public function isExplicit(): bool
    {
        return $this->storedValue !== null;
    }
}
