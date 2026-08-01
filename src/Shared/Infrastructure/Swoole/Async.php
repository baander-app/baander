<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

/**
 * Context-aware async primitives.
 *
 * Automatically selects between Swoole coroutine and blocking PHP calls
 * so developers don't need to know which execution context they're in.
 */
final class Async
{
    public static function sleep(float $seconds): void
    {
        if (self::inCoroutine()) {
            \Swoole\Coroutine::sleep($seconds);
        } else {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    public static function inCoroutine(): bool
    {
        return \extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }
}
