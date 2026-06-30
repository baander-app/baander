<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Component\Locking\Channel;

use Swoole\Coroutine\Channel;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
use RuntimeException;

final class ChannelMutex implements Mutex
{
    private bool $isAcquired = false;

    /**
     * @var array<Channel>
     */
    private array $channels = [];

    /**
     * @param float $acquireTimeout Seconds to wait for pool availability before failing.
     *   0 = wait forever (legacy behavior). Set to >0 to prevent hard deadlocks.
     */
    public function __construct(
        private readonly ?ChannelPool $channelPool = null,
        private readonly float $acquireTimeout = 0,
    ) {}

    public function acquire(): void
    {
        if (!$this->isAcquired) {
            $this->isAcquired = true;

            return;
        }

        $channel = $this->provideBlockingChannel();

        if ($this->acquireTimeout > 0) {
            $result = $channel->pop($this->acquireTimeout);

            if ($result === false) {
                throw new RuntimeException(sprintf(
                    'Service pool mutex acquire timed out after %.1fs — pool is exhausted. '
                    . 'Consider increasing max_service_instances or reducing long-lived connections.',
                    $this->acquireTimeout,
                ));
            }
        } else {
            $channel->pop();
        }
    }

    public function release(): void
    {
        if (count($this->channels) === 0) {
            $this->isAcquired = false;

            return;
        }

        $nextChannel = array_shift($this->channels);

        if ($this->channelPool !== null) {
            $this->channelPool->returnChannel($nextChannel);
        }

        $nextChannel->push(true);
    }

    public function isAcquired(): bool
    {
        return $this->isAcquired;
    }

    private function provideBlockingChannel(): Channel
    {
        $channel = $this->channelPool?->provideChannel() ?? new Channel(1);
        $this->channels[] = $channel;

        return $channel;
    }
}
