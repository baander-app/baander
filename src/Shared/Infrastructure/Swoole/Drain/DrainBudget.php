<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

/**
 * The web server's drain budget, all derived from WEB_DRAIN_SECONDS (D):
 *
 * - the window S = floor((D - 4) / 2), during which workers drain with their client connections
 *   still open;
 * - Swoole's max_wait_time W = floor((D - 4 - S) / 2). After the window Swoole stops the event
 *   workers and then the task workers, each for up to W, and the manager kills what is left at 2W;
 * - the CPU pool's stop takes up to 3 seconds once the workers have gone, and 1 second is margin,
 *   so S + 2W + 4 never exceeds D. The watchdog kills what is still running at D.
 */
final readonly class DrainBudget
{
    public const int MINIMUM_DEADLINE_SECONDS = 8;
    private const int POOL_STOP_AND_MARGIN_SECONDS = 4;

    public int $windowSeconds;
    public int $maxWaitSeconds;

    public function __construct(public int $deadlineSeconds)
    {
        if ($deadlineSeconds < self::MINIMUM_DEADLINE_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'WEB_DRAIN_SECONDS must be at least %d; it is %d.',
                self::MINIMUM_DEADLINE_SECONDS,
                $deadlineSeconds,
            ));
        }

        $this->windowSeconds = intdiv($deadlineSeconds - self::POOL_STOP_AND_MARGIN_SECONDS, 2);
        $this->maxWaitSeconds = intdiv($deadlineSeconds - self::POOL_STOP_AND_MARGIN_SECONDS - $this->windowSeconds, 2);
    }
}
