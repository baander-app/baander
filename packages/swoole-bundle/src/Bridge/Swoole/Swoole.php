<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Swoole;

use Assert\Assertion;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swoole\Runtime;
use Swoole\Thread;
use Swoole\Timer;

final class Swoole
{
    /**
     * Composite key multiplier: separates thread ID from coroutine ID in the composite key.
     *
     * Must exceed Swoole's default max_coroutine (100_000) to guarantee uniqueness.
     * Referenced by RecursiveOwnerMutex for thread extraction via intdiv().
     */
    public const int CONTEXT_KEY_MULTIPLIER = 1_000_000;

    private bool $threadMode = false;

    public function tick(int $intervalMs, callable $callbackFunction, mixed ...$params): int|bool
    {
        return Timer::tick($intervalMs, $callbackFunction, ...$params);
    }

    public function cpuCoresCount(): int
    {
        return swoole_cpu_num();
    }

    public function waitGroup(int $delta = 0): WaitGroup
    {
        return new WaitGroup($delta);
    }

    public function enableCoroutines(int $flags = SWOOLE_HOOK_ALL): void
    {
        Runtime::enableCoroutine($flags);
        /** @phpstan-ignore-line */
    }

    public function disableCoroutines(): void
    {
        Runtime::enableCoroutine(0);
        /** @phpstan-ignore-line */
    }

    /**
     * Returns a composite execution context key unique across threads and coroutines.
     *
     * In thread mode: Thread::getId() * CONTEXT_KEY_MULTIPLIER + Coroutine::getCid()
     * In process mode: Coroutine::getCid() (unchanged behavior)
     *
     * This is the single source of truth for execution context identity.
     * All service pool keying, mutex ownership, and service release flow through this method.
     *
     * The multiplier exceeds Swoole's default max_coroutine (100_000),
     * ensuring uniqueness even at maximum coroutine count per thread.
     * Symfony Configuration caps max_coroutine at 100_000, so this is safe.
     */
    public function getCoroutineId(): int
    {
        $cId = Coroutine::getCid();

        if ($this->isThreadMode()) {
            return Thread::getId() * self::CONTEXT_KEY_MULTIPLIER + $cId;
        }

        return $cId;
    }

    /**
     * Returns the raw Coroutine::getCid() without thread context.
     *
     * Use this when checking Swoole primitive state (e.g., "are we inside a coroutine?"),
     * NOT for pool keying or mutex ownership.
     *
     * @see CoroutinePool::isInCoroutineContext() for the primary consumer
     */
    public function getRawCoroutineId(): int
    {
        return Coroutine::getCid();
    }

    /**
     * Returns the current thread ID, or 0 if not in thread mode.
     */
    public function getThreadId(): int
    {
        if (!$this->isThreadMode()) {
            return 0;
        }

        return Thread::getId();
    }

    /**
     * Checks whether the server is running in Swoole thread mode (SWOOLE_THREAD).
     *
     * Thread mode is a configuration decision: the server is started with
     * running_mode: thread, which sets the SWOOLE_THREAD flag on the server
     * constructor. This method returns true only when setThreadMode(true) has
     * been called during server configuration (by SwooleExtension).
     */
    public function isThreadMode(): bool
    {
        return $this->threadMode;
    }

    /**
     * Sets whether the server is running in thread mode.
     * Called by SwooleExtension based on the configured running_mode.
     */
    public function setThreadMode(bool $threadMode): void
    {
        $this->threadMode = $threadMode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCoroutineOptions(): array
    {
        return Coroutine::getOptions();
    }

    /**
     * @return array<string, int>
     */
    public function getRunningModes(): array
    {
        return [
            'process' => \defined('SWOOLE_PROCESS') ? \SWOOLE_PROCESS : 2,
            'reactor' => \defined('SWOOLE_BASE') ? \SWOOLE_BASE : 1,
            'thread'  => \defined('SWOOLE_THREAD') ? \SWOOLE_THREAD : 4,
        ];
    }

    public function getRunningModeFor(string $modeName): int
    {
        $runningModes = $this->getRunningModes();
        Assertion::inArray($modeName, array_keys($runningModes));

        return $runningModes[$modeName];
    }

    public function supportsRunningMode(string $modeName): bool
    {
        return array_key_exists($modeName, $this->getRunningModes());
    }
}
