<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

/**
 * One user setting as administrators see it: a stored value that is no longer
 * allowed stays visible and is marked invalid.
 */
final readonly class UserSettingView
{
    /**
     * @param list<array{value: int|string, label: string}> $options     allowed values of an enum setting
     * @param string                                        $source      user, server_default or default
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public array $options,
        public bool $userEditable,
        public mixed $storedValue,
        public bool $storedValueValid,
        public bool|int|string $value,
        public bool|int|string $resetValue,
        public string $source,
    ) {
    }
}
