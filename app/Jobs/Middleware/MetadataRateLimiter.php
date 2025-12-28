<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Container\Container;

/**
 * Rate limiting middleware for metadata-related jobs.
 *
 * Prevents overwhelming external APIs like MusicBrainz by limiting
 * how frequently metadata jobs can be processed.
 */
class MetadataRateLimiter
{
    protected RateLimiter $limiter;

    public function __construct(
        private readonly int $perSecond = 1,
        private readonly string $key = 'metadata-jobs',
    ) {
        $this->limiter = Container::getInstance()->make(RateLimiter::class);
    }

    /**
     * Process the queued job.
     */
    public function handle(mixed $job, Closure $next): mixed
    {
        // Check if we've exceeded the rate limit
        if ($this->limiter->tooManyAttempts($this->key, $this->perSecond)) {
            // Release the job back to the queue with a delay
            return $job->release($this->getTimeUntilNextRetry());
        }

        // Record this attempt
        $this->limiter->hit($this->key, $this->getDecaySeconds());

        return $next($job);
    }

    /**
     * Get the number of seconds until the next retry.
     */
    protected function getTimeUntilNextRetry(): int
    {
        return $this->limiter->availableIn($this->key) + 1;
    }

    /**
     * Get the decay time in seconds.
     */
    protected function getDecaySeconds(): int
    {
        // If we allow X jobs per second, decay should be 1 second
        return 1;
    }

    /**
     * Prepare the object for serialization.
     */
    public function __sleep(): array
    {
        return [
            'perSecond',
            'key',
        ];
    }

    /**
     * Prepare the object after unserialization.
     */
    public function __wakeup(): void
    {
        $this->limiter = Container::getInstance()->make(RateLimiter::class);
    }
}
