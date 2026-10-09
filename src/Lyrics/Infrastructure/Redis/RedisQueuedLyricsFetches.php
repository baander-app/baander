<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Redis;

use App\Lyrics\Application\Port\QueuedLyricsFetchesInterface;
use App\Shared\Application\Port\QueuedJobWorkInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;

/**
 * Keeps the queued marks, the cancelled runs and the runs of finished jobs as Redis keys that
 * expire on their own. Redis errors propagate: a bulk run that cannot mark its songs fails
 * instead of queueing duplicates, and a fetch that cannot read its run's state is retried.
 *
 * It is also the job monitor's way to cancel the fetches of a finished bulk fetch job: the job
 * has queued work while its run is recorded, and cancelling it records the run cancelled for
 * as long as the record would have lasted, until a day after the run's last fetch is due.
 */
final readonly class RedisQueuedLyricsFetches implements QueuedLyricsFetchesInterface, QueuedJobWorkInterface
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

    public function recordJobRun(string $jobId, Uuid $runId, int $ttlSeconds): void
    {
        $key = $this->key('job_run', $jobId);
        $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->setex($key, max(1, $ttlSeconds), $runId->toString()));
    }

    public function hasQueuedWork(string $jobId): bool
    {
        $key = $this->key('job_run', $jobId);

        return $this->redis->borrow(static fn (\Redis $redis): bool => (int) $redis->exists($key) > 0);
    }

    public function cancelQueuedWork(string $jobId): bool
    {
        $key = $this->key('job_run', $jobId);
        [$runId, $ttl] = $this->redis->borrow(static fn (\Redis $redis): array => [$redis->get($key), (int) $redis->ttl($key)]);
        // Gone, or expiring in this second: the run's last fetch was due a day ago.
        if (!is_string($runId) || $ttl < 1) {
            return false;
        }

        $cancelledKey = $this->key('cancelled_run', $runId);
        $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->setex($cancelledKey, $ttl, '1'));

        return true;
    }

    private function key(string $kind, Uuid|string $id): string
    {
        return sprintf('%s:%s:%s', $this->keyPrefix, $kind, $id instanceof Uuid ? $id->toString() : $id);
    }
}
