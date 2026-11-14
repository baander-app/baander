<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Redis;

use App\Lyrics\Application\DTO\BulkFetchLyricsResult;
use App\Lyrics\Application\DTO\BulkLyricsRunTally;
use App\Lyrics\Application\DTO\LyricsFetchOutcome;
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
 *
 * A run's tally is one hash: a count per outcome, named by its LyricsFetchOutcome value, the
 * walk's queued count, finish time and job, and one `song:<id>` field per counted fetch, which
 * keeps a redelivered fetch from counting twice. Its scripts write atomically, and a fetch
 * counts only while the hash exists, so no tally is left without an expiry. A separate key
 * names the run whose walk finished last and expires with its tally.
 */
final readonly class RedisQueuedLyricsFetches implements QueuedLyricsFetchesInterface, QueuedJobWorkInterface
{
    private const string START_RUN = <<<'LUA'
        redis.call('HSET', KEYS[1], 'queued', 0)
        redis.call('EXPIRE', KEYS[1], ARGV[1])
        return 1
        LUA;

    /** Adds to the cause counts, which a fetch can also raise, instead of setting them. */
    private const string FINISH_RUN = <<<'LUA'
        redis.call('HINCRBY', KEYS[1], ARGV[2], ARGV[3])
        redis.call('HINCRBY', KEYS[1], ARGV[4], ARGV[5])
        redis.call('HSET', KEYS[1], 'queued', ARGV[6], 'finished_at', ARGV[7], 'job_id', ARGV[8])
        redis.call('EXPIRE', KEYS[1], ARGV[9])
        redis.call('SET', KEYS[2], ARGV[1], 'EX', ARGV[9])
        return 1
        LUA;

    private const string RECORD_OUTCOME = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 0 then
            return 0
        end
        if redis.call('HSETNX', KEYS[1], ARGV[1], ARGV[2]) == 0 then
            return 0
        end
        redis.call('HINCRBY', KEYS[1], ARGV[2], 1)
        return 1
        LUA;

    /** The walk's fields, which latestRun() reads with the outcome counts; it never reads the per-song fields. */
    private const array RUN_FIELDS = ['queued', 'finished_at', 'job_id'];

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

    public function startRun(Uuid $runId, int $ttlSeconds): void
    {
        $key = $this->key('run', $runId);
        $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->eval(self::START_RUN, [$key, (string) max(1, $ttlSeconds)], 1));
    }

    public function finishRun(Uuid $runId, BulkFetchLyricsResult $walk, ?string $jobId, int $ttlSeconds): void
    {
        $arguments = [
            $this->key('run', $runId),
            $this->latestRunKey(),
            $runId->toString(),
            LyricsFetchOutcome::NoArtist->value,
            (string) $walk->skippedNoArtist,
            LyricsFetchOutcome::NoLength->value,
            (string) $walk->skippedNoLength,
            (string) $walk->queued,
            (string) time(),
            $jobId ?? '',
            (string) max(1, $ttlSeconds),
        ];
        $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->eval(self::FINISH_RUN, $arguments, 2));
    }

    public function recordOutcome(Uuid $runId, Uuid $songId, LyricsFetchOutcome $outcome): void
    {
        $arguments = [$this->key('run', $runId), 'song:' . $songId->toString(), $outcome->value];
        $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->eval(self::RECORD_OUTCOME, $arguments, 1));
    }

    public function latestRun(): ?BulkLyricsRunTally
    {
        $latestKey = $this->latestRunKey();
        $outcomes = array_map(static fn (LyricsFetchOutcome $outcome): string => $outcome->value, LyricsFetchOutcome::cases());
        $fields = $this->redis->borrow(function (\Redis $redis) use ($latestKey, $outcomes): array|false {
            $runId = $redis->get($latestKey);

            return is_string($runId) ? $redis->hMGet($this->key('run', $runId), [...self::RUN_FIELDS, ...$outcomes]) : false;
        });
        // No run finished within its tally's lifetime, or the tally expired just after the pointer was read.
        if (!is_array($fields) || !is_numeric($fields['finished_at'] ?? null)) {
            return null;
        }

        $jobId = $fields['job_id'] ?? '';

        return BulkLyricsRunTally::fromCounts(
            is_string($jobId) && $jobId !== '' ? $jobId : null,
            new \DateTimeImmutable('@' . $fields['finished_at']),
            (int) $fields['queued'],
            array_map(static fn (mixed $count): int => (int) $count, array_intersect_key($fields, array_flip($outcomes))),
        );
    }

    private function latestRunKey(): string
    {
        return $this->keyPrefix . ':latest_run';
    }

    private function key(string $kind, Uuid|string $id): string
    {
        return sprintf('%s:%s:%s', $this->keyPrefix, $kind, $id instanceof Uuid ? $id->toString() : $id);
    }
}
