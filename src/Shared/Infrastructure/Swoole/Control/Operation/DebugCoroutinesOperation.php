<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use App\Shared\Infrastructure\Swoole\CoroutineStatsProvider;

/** One HTTP worker's coroutine and channel statistics. Runs in every worker. */
final readonly class DebugCoroutinesOperation implements ServerControlOperation
{
    public const string NAME = 'debug.coroutines';

    public function __construct(
        private CoroutineStatsProvider $coroutineStats,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function fansOut(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function handle(array $payload): array
    {
        return $this->coroutineStats->getStats();
    }
}
