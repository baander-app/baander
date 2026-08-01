<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

/**
 * Interface for services that manage Swoole\Coroutine\Channel instances.
 * Implementations are tagged with app.swoole.channel_provider and collected
 * by CoroutineStatsProvider for dashboard display.
 */
interface ChannelStatsProviderInterface
{
    /**
     * @return list<array{name: string, consumer_num: int, producer_num: int, queue_num: int, capacity: int, closed: bool}>
     */
    public function getChannelStats(): array;
}
