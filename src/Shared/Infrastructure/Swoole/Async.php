<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

/**
 * Context-aware async primitives.
 *
 * Automatically selects between Swoole coroutine and blocking PHP calls
 * so developers don't need to know which execution context they're in.
 */
final class Async
{
    /**
     * Outside a coroutine a signal ends this sleep early; sleepThroughSignals() lasts its full time.
     *
     * @throws CoroutineCanceledException when another coroutine cancels this one while it sleeps
     *         (Swoole\Coroutine::cancel()), so the cancelled work stops here
     */
    public static function sleep(float $seconds): void
    {
        if (self::inCoroutine()) {
            if (\Swoole\Coroutine::sleep($seconds) === false && \Swoole\Coroutine::isCanceled()) {
                throw new CoroutineCanceledException('The coroutine was cancelled while it slept.');
            }
        } else {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    /**
     * Sleep the full time although signals arrive meanwhile.
     *
     * Outside a coroutine a signal ends a blocking sleep early, and a Messenger consumer's
     * keepalive alarm arrives every 30 seconds. A wait that has to last, such as the wait for a
     * turn under a rate limit or for the end of a pause, sleeps through this method: after each
     * wake it reads a monotonic clock and sleeps again for what is left. Signal handlers still
     * run when their signal arrives. A wait of zero or less returns at once.
     *
     * In a coroutine no signal ends a sleep, so there it sleeps as sleep() does.
     *
     * @throws CoroutineCanceledException when another coroutine cancels this one while it sleeps
     */
    public static function sleepThroughSignals(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if (self::inCoroutine()) {
            self::sleep($seconds);

            return;
        }
        $wakeAt = hrtime(true) + (int) ceil($seconds * 1e9);
        while (($nanosecondsLeft = $wakeAt - hrtime(true)) > 0) {
            usleep((int) ceil($nanosecondsLeft / 1000));
        }
    }

    public static function inCoroutine(): bool
    {
        return \extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }
}
