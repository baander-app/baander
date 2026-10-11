<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use Swoole\Runtime;

/** Suspends Swoole's coroutine hooks around a call. */
final class SwooleHooks
{
    /**
     * Runs $action with these Swoole coroutine hooks off and returns its result. The hooks that
     * were on are back on afterwards, also when $action throws. Without the Swoole extension
     * $action simply runs.
     *
     * @template T
     * @param \Closure(): T $action
     * @return T
     */
    public static function without(int $hooks, \Closure $action): mixed
    {
        if (!class_exists(Runtime::class)) {
            return $action();
        }
        $flags = Runtime::getHookFlags();
        $hooked = ($flags & $hooks) !== 0;
        if ($hooked) {
            Runtime::setHookFlags($flags & ~$hooks);
        }
        try {
            return $action();
        } finally {
            if ($hooked) {
                Runtime::setHookFlags($flags);
            }
        }
    }
}
