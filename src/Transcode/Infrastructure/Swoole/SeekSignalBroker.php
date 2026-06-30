<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;

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
        if ($channel !== null && !$channel->isClosed()) {
            $channel->push(['position' => $position, 'action' => $action], 0.001);
        }
    }

    /**
     * Wait for a signal, with timeout.
     *
     * @return array{position: float, action: string}|null
     */
    public function waitForSignal(Uuid $jobId, float $timeout = 0.5): ?array
    {
        $key = $jobId->toString();
        $channel = $this->channels[$key] ?? null;
        if ($channel === null || $channel->isClosed()) {
            return null;
        }

        // Drain all pending signals, keep only the latest
        $latest = null;
        while (!$channel->isEmpty()) {
            $signal = $channel->pop(0.001);
            if (is_array($signal)) {
                $latest = $signal;
            }
        }

        return $latest;
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
