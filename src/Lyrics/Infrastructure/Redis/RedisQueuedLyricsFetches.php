<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Redis;

use App\Lyrics\Application\Port\QueuedLyricsFetchesInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;

/**
 * Keeps the queued marks and cancelled runs as Redis keys that expire on their own. Redis
 * errors propagate: a bulk run that cannot mark its songs fails instead of queueing
 * duplicates, and a fetch that cannot read its run's state is retried.
 */
final readonly class RedisQueuedLyricsFetches implements QueuedLyricsFetchesInterface
{
    public function __construct(
        private RedisClientFactory $redis,
        private string $keyPrefix = 'lyrics:fetch',
    ) {
    }

    public function markQueued(Uuid $songId, int $ttlSeconds): bool
    {
        $key = $this->key('queued', $songId);

        return $this->redis->borrow(
            static fn (\Redis $redis): bool => $redis->set($key, '1', ['NX', 'EX' => max(1, $ttlSeconds)]) === true,
        );
    }

    public function clearQueued(Uuid $songId): void
    {
        $key = $this->key('queued', $songId);
        $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->del($key));
    }

    public function markRunCancelled(Uuid $runId, int $ttlSeconds): void
    {
        $key = $this->key('cancelled_run', $runId);
        $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->setex($key, max(1, $ttlSeconds), '1'));
    }

    public function isRunCancelled(Uuid $runId): bool
    {
        $key = $this->key('cancelled_run', $runId);

        return $this->redis->borrow(static fn (\Redis $redis): bool => (int) $redis->exists($key) > 0);
    }

    private function key(string $kind, Uuid $id): string
    {
        return sprintf('%s:%s:%s', $this->keyPrefix, $kind, $id->toString());
    }
}
