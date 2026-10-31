<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Component\Concurrency;

use Closure;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use Throwable;

final class SerialQueue
{
    public const int DEFAULT_CAPACITY = 1024;
    public const float DEFAULT_IDLE_TIMEOUT_SECONDS = 1.0;
    private const float WAIT_TIMEOUT_SECONDS = 5.0;

    private ?Channel $channel = null;
    private bool $consuming = false;
    private ?int $consumerContextId = null;
    private int $queued = 0;
    private ?string $failure = null;

    /**
     * @param Closure(mixed): void $consumer
     * @param Closure(): void|null $consumerRelease Releases the consumer's resource before it exits.
     */
    public function __construct(
        private readonly Swoole $swoole,
        private readonly Closure $consumer,
        private readonly int $capacity = self::DEFAULT_CAPACITY,
        private readonly ?Closure $consumerRelease = null,
        private readonly float $idleTimeout = self::DEFAULT_IDLE_TIMEOUT_SECONDS,
    ) {
        if ($capacity < 1 || $idleTimeout <= 0) {
            throw new InvalidArgumentException('Serial queue capacity and idle timeout must be positive.');
        }
    }

    public function submit(mixed $payload): void
    {
        if ($this->swoole->getRawCoroutineId() === -1) {
            if ($this->consuming) {
                throw new RuntimeException('Cannot submit to an active serial queue outside a coroutine.');
            }
            try {
                ($this->consumer)($payload);
            } finally {
                ($this->consumerRelease)?->__invoke();
            }

            return;
        }

        $this->queued++;
        try {
            $this->push($payload);
        } catch (Throwable $error) {
            $this->queued--;
            throw $error;
        }
        try {
            $this->startConsumer();
        } catch (Throwable $error) {
            $this->channel?->pop(self::WAIT_TIMEOUT_SECONDS);
            $this->queued--;
            throw $error;
        }
    }

    /** @param Closure(): void $task */
    public function runInConsumer(Closure $task): void
    {
        if ($this->consumerRelease === null) {
            throw new LogicException('Handing over a task requires a pinned consumer.');
        }
        $contextId = $this->swoole->getCoroutineId();
        if ($this->consumerContextId === $contextId || !$this->consuming) {
            $task();
            $this->throwPendingFailure();

            return;
        }
        if ($this->swoole->getRawCoroutineId() === -1) {
            throw new RuntimeException('Cannot touch an active serial queue consumer outside a coroutine.');
        }

        $acknowledgement = new Channel(1);
        $this->push(new TaskMarker($task, $acknowledgement));
        $result = $this->wait($acknowledgement);
        if ($result instanceof Throwable) {
            throw $result;
        }
    }

    public function drain(): void
    {
        $contextId = $this->swoole->getCoroutineId();
        if ($this->consumerContextId === $contextId) {
            return;
        }
        if (!$this->consuming) {
            if ($this->queued > 0) {
                throw new RuntimeException('Serial queue contains unconsumed payloads.');
            }
            $this->throwPendingFailure();

            return;
        }
        if ($this->swoole->getRawCoroutineId() === -1) {
            throw new RuntimeException('Cannot drain an active serial queue outside a coroutine.');
        }

        $acknowledgement = new Channel(1);
        $this->push(new DrainMarker($acknowledgement));
        $result = $this->wait($acknowledgement);
        if (is_string($result)) {
            throw new RuntimeException($result);
        }
    }

    public function isEmpty(): bool
    {
        return $this->queued === 0;
    }

    private function push(mixed $payload): void
    {
        $this->channel ??= new Channel($this->capacity);
        if (!$this->channel->push($payload, self::WAIT_TIMEOUT_SECONDS)) {
            throw new RuntimeException('Unable to enqueue serial work.');
        }
    }

    private function wait(Channel $acknowledgement): mixed
    {
        $result = $acknowledgement->pop(self::WAIT_TIMEOUT_SECONDS);
        if ($result === false) {
            throw new RuntimeException('Serial queue acknowledgement timed out.');
        }

        return $result;
    }

    private function startConsumer(): void
    {
        if ($this->consuming) {
            return;
        }
        $this->consuming = true;
        $id = Coroutine::create(function (): void {
            $this->consumerContextId = $this->swoole->getCoroutineId();
            try {
                $this->consume();
            } finally {
                $this->consumerContextId = null;
                $this->consuming = false;
            }
        });
        if ($id === false) {
            $this->consuming = false;
            throw new RuntimeException('Unable to create serial queue consumer coroutine.');
        }
    }

    private function consume(): void
    {
        $channel = $this->channel;
        if ($channel === null) {
            return;
        }
        while (true) {
            $payload = $channel->pop($this->idleTimeout);
            if ($payload === false && $channel->errCode !== 0) {
                $this->releaseConsumer();
                // Releasing a resource can yield while a producer enqueues more work.
                if ($channel->isEmpty()) {
                    return;
                }
                continue;
            }
            if ($payload instanceof DrainMarker) {
                $payload->acknowledge($this->takeFailure());
            } elseif ($payload instanceof TaskMarker) {
                $taskError = null;
                try {
                    $payload->run();
                    $failure = $this->takeFailure();
                    if ($failure !== null) {
                        $taskError = new RuntimeException($failure);
                    }
                } catch (Throwable $error) {
                    $taskError = $error;
                }
                $payload->acknowledge($taskError);
            } else {
                $this->queued--;
                try {
                    ($this->consumer)($payload);
                } catch (Throwable $error) {
                    $this->report($error);
                }
            }
            unset($payload);
            if ($this->consumerRelease === null && $channel->isEmpty()) {
                return;
            }
        }
    }

    private function releaseConsumer(): void
    {
        try {
            ($this->consumerRelease)?->__invoke();
        } catch (Throwable $error) {
            $this->report($error);
        }
    }

    private function report(Throwable $error): void
    {
        $message = sprintf('Serially queued work failed: %s: %s', $error::class, $error->getMessage());
        $this->failure ??= $message;
        error_log($message);
    }

    private function takeFailure(): ?string
    {
        $failure = $this->failure;
        $this->failure = null;

        return $failure;
    }

    private function throwPendingFailure(): void
    {
        $failure = $this->takeFailure();
        if ($failure !== null) {
            throw new RuntimeException($failure);
        }
    }
}
