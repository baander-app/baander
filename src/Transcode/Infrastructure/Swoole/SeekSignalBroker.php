<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Async;

/**
 * Inter-coroutine signal broker for playback position changes.
 *
 * Holds one Swoole\Coroutine\Channel per transcode job. The encoding loop
 * polls via waitForSignal() with a short timeout. Event listeners push signals
 * via signal() from HTTP worker coroutines. This allows the encoding loop to
 * react to seeks and pauses without blocking.
 */
final class SeekSignalBroker
{
    /** @var array<string, \Swoole\Coroutine\Channel> */
    private array $channels = [];

    public function open(Uuid $jobId): void
    {
        $key = $jobId->toString();
        if (!isset($this->channels[$key])) {
            $this->channels[$key] = new \Swoole\Coroutine\Channel(16);
        }
    }

    public function signal(Uuid $jobId, float $position, string $action): void
    {
        $key = $jobId->toString();
        $channel = $this->channels[$key] ?? null;
        if ($channel !== null) {
            $channel->push(['position' => $position, 'action' => $action], 0.001);
        }
    }

    /**
     * Wait for a signal, with timeout.
     *
     * Avoids blocking Channel->pop() because Swoole 6.x can stall a coroutine
     * inside Channel->pop($timeout) under load and never return from the timeout.
     * Instead we poll isEmpty() and sleep, which always yields to the scheduler.
     *
     * @return array{position: float, action: string}|null
     */
    public function waitForSignal(Uuid $jobId, float $timeout = 0.5): ?array
    {
        $key = $jobId->toString();
        $channel = $this->channels[$key] ?? null;
        if ($channel === null) {
            return null;
        }

        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $latest = null;
            while (!$channel->isEmpty()) {
                $signal = $channel->pop(0.001);
                if (is_array($signal)) {
                    $latest = $signal;
                }
            }

            if ($latest !== null) {
                return $latest;
            }

            Async::sleep(0.05);
        }

        return null;
    }

    public function close(Uuid $jobId): void
    {
        $key = $jobId->toString();
        if (isset($this->channels[$key])) {
            $this->channels[$key]->close();
            unset($this->channels[$key]);
        }
    }
}
