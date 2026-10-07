<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\Exception\UnknownSettingException;

/**
 * Reads the effective value of a server-wide setting: the stored value while it
 * is still allowed, otherwise the definition's default. Every call reads the
 * store afresh, so a long-running worker sees an admin's change.
 */
interface SystemSettingsPortInterface
{
    /**
     * @throws UnknownSettingException when no system setting has the key
     */
    public function get(string $key): bool|int|string;
}
