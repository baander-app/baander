<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Component\Locking\Channel;

use Swoole\Coroutine\Channel;

/**
 * Pool of spare Swoole Channels for recycling across ChannelMutex instances.
 * Replaces the process-global static ChannelMutex::$spareChannels.
 *
 * Registered as a singleton service — all ChannelMutex instances in the same
 * thread share the same pool. In ZTS mode, each thread gets its own service container,
 * so the pool is naturally per-thread.
 */
final class ChannelPool
{
    /** @var array<Channel> */
    private array $spareChannels = [];

    public function provideChannel(): Channel
    {
        return array_shift($this->spareChannels) ?? new Channel(1);
    }

    public function returnChannel(Channel $channel): void
    {
        $this->spareChannels[] = $channel;
    }
}
