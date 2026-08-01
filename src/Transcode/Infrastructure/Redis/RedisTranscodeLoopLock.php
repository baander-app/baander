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
 * and renewal. A lock that is not released will expire via TTL, preventing a
 * crashed worker from permanently blocking a job.
 */
final class RedisTranscodeLoopLock implements TranscodeLoopLockInterface
{
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
                $current = $redis->get($key);
                if ($current !== $token) {
                    return false;
                }

                return (bool) $redis->set($key, $token, ['XX', 'EX' => max(1, $ttlSeconds)]);
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
                $current = $redis->get($key);
                if ($current === $token) {
                    $redis->del($key);
                }
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
