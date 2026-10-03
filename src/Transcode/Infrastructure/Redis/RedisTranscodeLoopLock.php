<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Redis;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Transcode\Application\Port\TranscodeLoopLockInterface;
use Throwable;

/**
 * Redis-backed distributed loop lock for transcode jobs.
 *
 * Uses SET NX EX for acquisition and a per-acquisition owner token for release
 * and renewal. Owner comparison and mutation execute atomically in Redis so a
 * stale owner cannot overwrite or delete a successor's lease. A lock that is
 * not released expires via TTL.
 */
final class RedisTranscodeLoopLock implements TranscodeLoopLockInterface
{
    private const string RENEW_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return 0
LUA;

    private const string RELEASE_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    /** @var array<string, string> jobId -> owner token */
    private array $tokens = [];

    public function __construct(
        private readonly RedisClientFactory $redis,
        private readonly string $keyPrefix = 'transcode:loop',
    ) {
    }

    public function acquire(Uuid $jobId, int $ttlSeconds): bool
    {
        $key = $this->key($jobId);
        $token = $this->generateToken();

        try {
            $acquired = (bool) $this->redis->borrow(function (\Redis $redis) use ($key, $token, $ttlSeconds): bool {
                return (bool) $redis->set($key, $token, ['NX', 'EX' => max(1, $ttlSeconds)]);
            });
        } catch (Throwable) {
            // Fail closed when Redis is unavailable: do not start a loop.
            return false;
        }

        if ($acquired) {
            $this->tokens[$jobId->toString()] = $token;
        }

        return $acquired;
    }

    public function renew(Uuid $jobId, int $ttlSeconds): bool
    {
        $key = $this->key($jobId);
        $token = $this->tokens[$jobId->toString()] ?? null;

        if ($token === null) {
            return false;
        }

        try {
            return (bool) $this->redis->borrow(function (\Redis $redis) use ($key, $token, $ttlSeconds): bool {
                return $redis->eval(self::RENEW_SCRIPT, [$key, $token, max(1, $ttlSeconds)], 1) === 1;
            });
        } catch (Throwable) {
            return false;
        }
    }

    public function release(Uuid $jobId): void
    {
        $key = $this->key($jobId);
        $token = $this->tokens[$jobId->toString()] ?? null;
        unset($this->tokens[$jobId->toString()]);

        if ($token === null) {
            return;
        }

        try {
            $this->redis->borrow(function (\Redis $redis) use ($key, $token): void {
                $redis->eval(self::RELEASE_SCRIPT, [$key, $token], 1);
            });
        } catch (Throwable) {
            // Key will expire via TTL; do not throw on shutdown/recovery paths.
        }
    }

    private function key(Uuid $jobId): string
    {
        return sprintf('%s:%s', $this->keyPrefix, $jobId->toString());
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
