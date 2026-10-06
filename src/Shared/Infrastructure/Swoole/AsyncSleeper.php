<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use App\Shared\Application\Port\SleeperInterface;

final class AsyncSleeper implements SleeperInterface
{
    public function sleep(float $seconds): void
    {
        Async::sleep($seconds);
    }
}
