<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\ChannelStatsProviderInterface;
use ReflectionProperty;
use Swoole\Coroutine\Channel;

/**
 * Exposes SeekSignalBroker's channel stats to the CoroutineStatsProvider.
 */
final class SeekSignalChannelStats implements ChannelStatsProviderInterface
{
    public function __construct(
        private readonly SeekSignalBroker $broker,
    ) {
    }

    public function getChannelStats(): array
    {
        $ref = new ReflectionProperty($this->broker, 'channels');
        /** @var array<string, Channel> $channels */
        $channels = $ref->getValue($this->broker);

        $result = [];
        foreach ($channels as $key => $channel) {
            $stats = $channel->stats();
            // Swoole\Coroutine\Channel has no isClosed() method.
            // A closed channel returns empty stats with all zeros.
            $isEmpty = $channel->isEmpty();
            $result[] = [
                'name'         => "seek_signal::$key",
                'consumer_num' => $stats['consumer_num'] ?? 0,
                'producer_num' => $stats['producer_num'] ?? 0,
                'queue_num'    => $stats['queue_num'] ?? 0,
                'capacity'     => $channel->capacity ?? 0,
                'closed'       => $isEmpty && ($stats['consumer_num'] ?? 0) === 0 && ($stats['producer_num'] ?? 0) === 0,
            ];
        }

        return $result;
    }
}
