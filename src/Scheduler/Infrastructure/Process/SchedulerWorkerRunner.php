<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Process;

use App\Scheduler\Application\Port\SchedulerWorkerAuthorityInterface;
use App\Scheduler\Application\Service\SchedulerOccurrenceRelay;
use App\Scheduler\Application\Service\SchedulerRecoveryPoller;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/** CLI cadence for bounded batches; database and transport calls have no wall-clock deadline here. */
final readonly class SchedulerWorkerRunner
{
    private Closure $clock;
    private Closure $wait;

    /**
     * @param Closure():float|null $clock
     * @param Closure(float):void|null $wait
     */
    public function __construct(
        private SchedulerRecoveryPoller $recovery,
        private SchedulerOccurrenceRelay $relay,
        private SchedulerWorkerAuthorityInterface $authority,
        private LoggerInterface $logger,
        ?Closure $clock = null,
        ?Closure $wait = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->wait = $wait ?? static function (float $seconds): void {
            usleep(max(1, (int) ceil($seconds * 1e6)));
        };
    }

    /**
     * Own TERM/INT while running, including signals received during blocking I/O.
     * Unconsumed pending signals keep their original disposition when the prior mask is restored.
     */
    public function runUntilSignalled(): void
    {
        if (PHP_SAPI !== 'cli' || (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0)) {
            throw new \LogicException('Scheduler worker requires CLI outside an active coroutine.');
        }
        if (!function_exists('pcntl_sigprocmask') || !function_exists('pcntl_sigtimedwait')) {
            throw new \LogicException('Scheduler worker requires synchronous process signal support.');
        }
        $signals = [SIGTERM, SIGINT];
        $previousMask = [];
        if (!pcntl_sigprocmask(SIG_BLOCK, $signals, $previousMask)) {
            throw new \RuntimeException('Scheduler worker could not block stop signals.');
        }
        $stopped = false;
        try {
            $this->run(static function () use (&$stopped, $signals): bool {
                if ($stopped) {
                    return true;
                }
                $info = [];
                // PHP 8.4+ requires a positive timeout; one nanosecond polls queued signals.
                // Timeout leaves pcntl's last_error unchanged. Observe this call's
                // warnings instead, without leaking diagnostics or changing the caller's handler.
                $failed = false;
                set_error_handler(static function () use (&$failed): bool {
                    $failed = true;
                    return true;
                }, E_WARNING);
                try {
                    $signal = pcntl_sigtimedwait($signals, $info, 0, 1);
                } finally {
                    restore_error_handler();
                }
                if ($failed) {
                    throw new \RuntimeException('Scheduler worker could not observe stop signals.');
                }
                if (in_array($signal, $signals, true)) {
                    $stopped = true;
                    return true;
                }
                if ($signal === false) {
                    return false;
                }
                throw new \RuntimeException('Scheduler worker could not observe stop signals.');
            });
        } finally {
            if (!pcntl_sigprocmask(SIG_SETMASK, $previousMask)) {
                throw new \RuntimeException('Scheduler worker could not restore its signal mask.');
            }
        }
    }

    /** @param Closure():bool $stopRequested */
    public function run(Closure $stopRequested): void
    {
        if (PHP_SAPI !== 'cli' || (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0)) {
            throw new \LogicException('Scheduler worker requires CLI outside an active coroutine.');
        }
        $lastTime = null;
        $recoveryAt = 0.0;
        $relayAt = 0.0;
        while (!$this->shouldStop($stopRequested)) {
            $now = $this->readTime($lastTime);
            if ($now >= $recoveryAt) {
                if ($this->shouldStop($stopRequested)) {
                    return;
                }
                $this->authority->assertActive();
                if ($this->shouldStop($stopRequested)) {
                    return;
                }
                try {
                    $this->recovery->recoverPending(10, 60);
                } catch (Throwable) {
                    $this->logger->error('Scheduler worker recovery pass failed.');
                }
                $recoveryAt = $this->nextRun($this->readTime($lastTime));
            }
            $now = $this->readTime($lastTime);
            if ($now >= $relayAt) {
                if ($this->shouldStop($stopRequested)) {
                    return;
                }
                $this->authority->assertActive();
                if ($this->shouldStop($stopRequested)) {
                    return;
                }
                try {
                    $this->relay->dispatchPending(100);
                } catch (Throwable) {
                    $this->logger->error('Scheduler worker relay pass failed.');
                }
                $relayAt = $this->nextRun($this->readTime($lastTime));
            }
            if ($this->shouldStop($stopRequested)) {
                return;
            }
            $now = $this->readTime($lastTime);
            $remaining = min($recoveryAt, $relayAt) - $now;
            if ($remaining > 0) {
                ($this->wait)(min(0.1, $remaining));
            }
        }
    }

    /**
     * Signal state can change between calls, including during a phase.
     * @param Closure():bool $stopRequested
     * @phpstan-impure
     */
    private function shouldStop(Closure $stopRequested): bool
    {
        return $stopRequested();
    }

    /** @param-out float $lastTime */
    private function readTime(?float &$lastTime): float
    {
        $now = ($this->clock)();
        if (!is_finite($now) || $now < 0 || ($lastTime !== null && $now < $lastTime)) {
            throw new \InvalidArgumentException('Scheduler worker requires finite, non-negative, non-decreasing monotonic time.');
        }
        $lastTime = $now;
        return $now;
    }

    private function nextRun(float $completedAt): float
    {
        $next = $completedAt + 1.0;
        if (!is_finite($next) || $next <= $completedAt) {
            throw new \InvalidArgumentException('Scheduler worker clock cannot represent its next interval.');
        }
        return $next;
    }
}
