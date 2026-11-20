<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Redis;

use App\Lyrics\Infrastructure\Api\LrclibPauseInterface;
use Baander\RedisRateLimiter\RateLimiterUnavailableException;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;

/**
 * Keeps the pause as one Redis key that expires when the pause ends. Web and worker processes
 * read the same key whatever their environment, a cache clear does not end the pause, and
 * Redis alone measures it, so the processes' clocks need not agree.
 */
final readonly class RedisLrclibPause implements LrclibPauseInterface
{
    /** Makes the key expire after the asked milliseconds unless it lasts longer already; answers the milliseconds left. */
    private const string PAUSE = <<<'LUA'
        local left = redis.call('PTTL', KEYS[1])
        local asked = tonumber(ARGV[1])
        if left >= asked then
            return left
        end
        redis.call('SET', KEYS[1], '1', 'PX', asked)
        return asked
        LUA;

    public function __construct(
        private RedisClientFactory $redis,
        private string $keyPrefix = 'lyrics:lrclib',
    ) {
    }

    public function secondsLeft(): float
    {
        $key = $this->key();

        return $this->seconds(static fn (\Redis $redis): mixed => $redis->pttl($key));
    }

    public function pauseFor(int $seconds): float
    {
        if ($seconds < 1) {
            return $this->secondsLeft();
        }
        $arguments = [$this->key(), (string) ($seconds * 1000)];

        return $this->seconds(static fn (\Redis $redis): mixed => $redis->eval(self::PAUSE, $arguments, 1));
    }

    /**
     * The seconds a command answers in milliseconds. A key that is absent, or that has no
     * expiry, is no pause.
     *
     * @param callable(\Redis): mixed $command
     */
    private function seconds(callable $command): float
    {
        try {
            $milliseconds = $this->redis->borrow($command);
        } catch (\RedisException | RedisPoolExhaustedException) {
            // The original error can contain endpoints or command arguments.
            throw new RateLimiterUnavailableException();
        }
        if (!is_int($milliseconds)) {
            throw new RateLimiterUnavailableException();
        }

        return max(0, $milliseconds) / 1000;
    }

    private function key(): string
    {
        return $this->keyPrefix . ':pause';
    }
}
