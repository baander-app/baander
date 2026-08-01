<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

/**
 * Gathers Swoole coroutine and channel statistics.
 *
 * Coroutine stats come from Swoole\Coroutine::stats().
 * Channel stats come from services that manage Swoole\Coroutine\Channel instances
 * (e.g., SeekSignalBroker). Each provider is tagged with app.swoole.channel_provider.
 */
final class CoroutineStatsProvider
{
    /** @var iterable<ChannelStatsProviderInterface> */
    private iterable $channelProviders;

    /**
     * @param iterable<ChannelStatsProviderInterface> $channelProviders
     */
    public function __construct(iterable $channelProviders = [])
    {
        $this->channelProviders = $channelProviders;
    }

    /**
     * @return array{
     *     coroutines: array{
     *         event_num: int|null,
     *         signal_listener_num: int|null,
     *         aio_task_num: int|null,
     *         aio_worker_num: int|null,
     *         aio_queue_size: int|null,
     *         c_stack_size: int|null,
     *         coroutine_num: int|null,
     *         coroutine_peak_num: int|null,
     *         coroutine_last_cid: int|null,
     *     },
     *     active_cids: list<int>,
     *     channels: list<array{name: string, consumer_num: int, producer_num: int, queue_num: int, capacity: int, closed: bool}>
     * }
     */
    public function getStats(): array
    {
        $coroutineStats = $this->getCoroutineStats();
        $activeCids = $this->getActiveCids();
        $channelStats = $this->getChannelStats();

        return [
            'coroutines' => $coroutineStats,
            'active_cids' => $activeCids,
            'channels' => $channelStats,
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function getCoroutineStats(): array
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            return $this->emptyCoroutineStats();
        }

        $stats = \Swoole\Coroutine::stats();
        if (!is_array($stats) || empty($stats)) {
            return $this->emptyCoroutineStats();
        }

        return [
            'event_num' => $stats['event_num'] ?? null,
            'signal_listener_num' => $stats['signal_listener_num'] ?? null,
            'aio_task_num' => $stats['aio_task_num'] ?? null,
            'aio_worker_num' => $stats['aio_worker_num'] ?? null,
            'aio_queue_size' => $stats['aio_queue_size'] ?? null,
            'c_stack_size' => $stats['c_stack_size'] ?? null,
            'coroutine_num' => $stats['coroutine_num'] ?? null,
            'coroutine_peak_num' => $stats['coroutine_peak_num'] ?? null,
            'coroutine_last_cid' => $stats['coroutine_last_cid'] ?? null,
        ];
    }

    /**
     * @return list<int>
     */
    private function getActiveCids(): array
    {
        if (!class_exists(\Swoole\Coroutine::class) || !method_exists(\Swoole\Coroutine::class, 'listCoroutines')) {
            return [];
        }

        $cids = [];
        foreach (\Swoole\Coroutine::listCoroutines() as $cid) {
            $cids[] = $cid;
        }

        return $cids;
    }

    /**
     * @return list<array{name: string, consumer_num: int, producer_num: int, queue_num: int, capacity: int, closed: bool}>
     */
    private function getChannelStats(): array
    {
        $channels = [];
        foreach ($this->channelProviders as $provider) {
            $channels[] = $provider->getChannelStats();
        }

        return array_merge(...$channels);
    }

    /**
     * @return array<string, int|null>
     */
    private function emptyCoroutineStats(): array
    {
        return [
            'event_num' => null,
            'signal_listener_num' => null,
            'aio_task_num' => null,
            'aio_worker_num' => null,
            'aio_queue_size' => null,
            'c_stack_size' => null,
            'coroutine_num' => null,
            'coroutine_peak_num' => null,
            'coroutine_last_cid' => null,
        ];
    }
}
