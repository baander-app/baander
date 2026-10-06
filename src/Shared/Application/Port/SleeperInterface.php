<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Pauses the current execution context without blocking a Swoole worker when inside a coroutine.
 */
interface SleeperInterface
{
    public function sleep(float $seconds): void;
}
