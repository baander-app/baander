<?php

declare(strict_types=1);

namespace App\UserPreference\Application\DTO;

use App\Shared\Domain\Model\Setting\SettingDefinition;

/**
 * One user setting for one user: the stored choice, the effective value, and
 * the value the setting would have after a reset.
 */
final readonly class UserSettingEntry
{
    /**
     * @param mixed                $storedValue the raw stored value, also when it is no longer allowed
     * @param bool|int|string|null $choice      the stored value while it is allowed; null otherwise, as users see it
     */
    public function __construct(
        public SettingDefinition $definition,
        public mixed $storedValue,
        public bool|int|string|null $choice,
        public bool|int|string $value,
        public bool|int|string $resetValue,
        public UserSettingSource $source,
    ) {
    }

    public function storedValueValid(): bool
    {
        return $this->storedValue === null || $this->choice !== null;
    }
}
