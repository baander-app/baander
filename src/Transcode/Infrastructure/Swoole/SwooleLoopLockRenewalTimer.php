<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

final class SwooleLoopLockRenewalTimer implements LoopLockRenewalTimerInterface
{
    public function tick(int $intervalMs, \Closure $callback): int
    {
        $id = \Swoole\Timer::tick($intervalMs, $callback);
        // Native Swoole may return false; usable timer IDs are positive.
        if ($id < 1) {
            throw new \RuntimeException('Unable to schedule transcode loop lock renewal.');
        }

        return $id;
    }

    public function clear(int $id): void
    {
        \Swoole\Timer::clear($id);
    }
}
