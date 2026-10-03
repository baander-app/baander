<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

interface LoopLockRenewalTimerInterface
{
    public function tick(int $intervalMs, \Closure $callback): int;

    public function clear(int $id): void;
}
