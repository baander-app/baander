<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;

/**
 * Swoole runs each tick in a new coroutine that the swoole bundle does not manage.
 * A renewal that loses ownership logs through the pooled transcode logger, so each
 * tick releases its coroutine's pooled services when it ends.
 */
final class SwooleLoopLockRenewalTimer implements LoopLockRenewalTimerInterface
{
    /** The container passes the CoWrapper; it is optional only for tests. */
    public function __construct(
        private readonly ?CoWrapper $coWrapper = null,
    ) {}

    public function tick(int $intervalMs, \Closure $callback): int
    {
        $id = \Swoole\Timer::tick($intervalMs, function () use ($callback): void {
            $this->coWrapper?->defer();
            $callback();
        });
        // Native Swoole may return false; usable timer IDs are positive.
        if ($id < 1) {
            throw new \RuntimeException('Unable to schedule transcode loop lock renewal.');
        }

        return $id;
    }

    public function clear(int $id): void
    {
        \Swoole\Timer::clear($id);
    }
}
