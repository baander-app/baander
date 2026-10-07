<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\DTO\SystemSettingEntry;

/**
 * Renders setting values for the settings commands as their JSON literals,
 * so `true` and `"true"` stay distinguishable.
 */
final class SettingValueFormatter
{
    public static function format(mixed $value): string
    {
        return $value === null ? '-' : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function stored(SystemSettingEntry $entry): string
    {
        if (!$entry->isExplicit()) {
            return '(default)';
        }

        return self::format($entry->storedValue) . ($entry->storedValueValid ? '' : ' (invalid)');
    }
}
