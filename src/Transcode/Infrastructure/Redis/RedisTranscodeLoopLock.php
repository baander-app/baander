<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Redis;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Transcode\Application\Port\TranscodeLoopLeaseInterface;
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
    public function __construct(
        private readonly RedisClientFactory $redis,
        private readonly string $keyPrefix = 'transcode:loop',
    ) {
    }

    public function acquire(Uuid $jobId, int $ttlSeconds): ?TranscodeLoopLeaseInterface
    {
        $key = $this->key($jobId);
        $token = $this->generateToken();

        try {
            $acquired = (bool) $this->redis->borrow(function (\Redis $redis) use ($key, $token, $ttlSeconds): bool {
                return (bool) $redis->set($key, $token, ['NX', 'EX' => max(1, $ttlSeconds)]);
            });
        } catch (Throwable) {
            // Fail closed when Redis is unavailable: do not start a loop.
            return null;
        }

        return $acquired ? new RedisTranscodeLoopLease($this->redis, $jobId, $key, $token) : null;
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
