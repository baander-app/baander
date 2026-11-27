<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

/**
 * Runtime hook flags for the Swoole server, as methods rather than class constants: PHP
 * evaluates every class constant when a class is instantiated or reflected, as the container's
 * resource check does, and Swoole's constants do not exist where the extension is not loaded.
 */
final class HookFlags
{
    /** Agent HTTP and isolated log SQL must yield while waiting for I/O. */
    public static function logQuery(): int
    {
        return SWOOLE_HOOK_NATIVE_CURL | SWOOLE_HOOK_PROC;
    }
}
