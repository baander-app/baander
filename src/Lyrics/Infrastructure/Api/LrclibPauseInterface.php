<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Api;

use Baander\RedisRateLimiter\RateLimiterUnavailableException;

/**
 * The pause LRCLIB asks for when it answers that requests come too fast. Every process reads
 * the same pause, so none sends LRCLIB anything until it is over.
 */
interface LrclibPauseInterface
{
    /**
     * Seconds until the pause ends; zero when none is in effect.
     *
     * @throws RateLimiterUnavailableException when the pause cannot be read
     */
    public function secondsLeft(): float;

    /**
     * Pause every process for the given seconds from now. A pause in effect that ends later
     * stays as it is: a later answer never shortens it.
     *
     * @return float seconds until the pause now in effect ends
     *
     * @throws RateLimiterUnavailableException when the pause cannot be stored
     */
    public function pauseFor(int $seconds): float;
}
