<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server\Runtime\HMR;

use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;

final class HotModuleReloadTimer
{
    private ?int $timerId = null;

    /** @var array<int, true> */
    private array $pendingTimers = [];

    private bool $ticking = false;
    private bool $stopped = false;

    public function __construct(private readonly Swoole $swoole) {}

    /** @param callable(): void $onTick */
    public function start(int $intervalMs, callable $onTick): void
    {
        $this->stop();
        $this->stopped = false;
        $timerId = $this->swoole->tick($intervalMs, function () use ($onTick): void {
            if ($this->stopped || $this->ticking) {
                return;
            }

            $this->ticking = true;
            try {
                $onTick();
            } finally {
                $this->ticking = false;
            }
        });
        $this->timerId = is_int($timerId) && $timerId > 0 ? $timerId : null;
    }

    /** @param callable(): void $callback */
    public function after(int $delayMs, callable $callback): bool
    {
        if ($this->stopped) {
            return false;
        }

        $timerId = null;
        $timerId = Timer::after($delayMs, function () use ($callback, &$timerId): void {
            if (is_int($timerId)) {
                unset($this->pendingTimers[$timerId]);
            }
            if (!$this->stopped) {
                $callback();
            }
        });
        if (!is_int($timerId) || $timerId <= 0) {
            return false;
        }

        $this->pendingTimers[$timerId] = true;
        return true;
    }

    public function stop(): void
    {
        $this->stopped = true;
        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }
        foreach (array_keys($this->pendingTimers) as $timerId) {
            Timer::clear($timerId);
        }
        $this->pendingTimers = [];
    }
}
