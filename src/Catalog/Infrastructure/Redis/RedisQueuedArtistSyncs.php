<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Redis;

use App\Catalog\Application\Port\QueuedArtistSyncsInterface;
use App\Shared\Application\Port\QueuedJobWorkInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;

/**
 * Keeps the queued marks, the answer marks, the cancelled runs, the runs of finished jobs and the
 * shared due time as Redis keys that expire on their own. Redis errors propagate: a producer that
 * cannot mark an artist queues nothing, and a sync that cannot read its run's state is retried.
 *
 * The shared due time is one key holding the latest due time reserved, in milliseconds on the
 * Redis clock. A reservation reads and moves it in one script together with the artist's mark, so
 * two producers never reserve the same step and never queue the same artist twice. It expires one
 * step after that due time, so a queue with nothing pending starts again at once.
 *
 * It is also the job monitor's way to cancel the syncs of a finished admin sync: the job has
 * queued work while its run is recorded, and cancelling it records the run cancelled for as long
 * as the record would have lasted.
 */
final readonly class RedisQueuedArtistSyncs implements QueuedArtistSyncsInterface, QueuedJobWorkInterface
{
    /**
     * KEYS: the shared due time, the artist's mark, and for a first reservation the answer mark.
     * ARGV: the spacing and the least delay in milliseconds, the mark's grace in seconds, and
     * whether an existing mark or an answer mark refuses the reservation.
     */
    private const string RESERVE = <<<'LUA'
        if ARGV[4] == '1' and (redis.call('EXISTS', KEYS[2]) == 1 or redis.call('EXISTS', KEYS[3]) == 1) then
            return -1
        end
        local clock = redis.call('TIME')
        local now = tonumber(clock[1]) * 1000 + math.floor(tonumber(clock[2]) / 1000)
        local latest = tonumber(redis.call('GET', KEYS[1])) or 0
        local due = math.max(now + tonumber(ARGV[2]), latest + tonumber(ARGV[1]))
        redis.call('SET', KEYS[1], string.format('%.0f', due), 'PX', string.format('%.0f', math.max(1, due - now + tonumber(ARGV[1]))))
        redis.call('SET', KEYS[2], '1', 'PX', string.format('%.0f', due - now + tonumber(ARGV[3]) * 1000))
        return due - now
        LUA;

    public function __construct(
        private RedisClientFactory $redis,
        private string $keyPrefix = 'catalog:artist_sync',
    ) {
    }

    public function reserve(Uuid $artistId, string $musicbrainzId, int $spacingMs, int $graceSeconds): ?int
    {
        $delay = $this->run($artistId, $this->answerKey($artistId, $musicbrainzId), $spacingMs, 0, $graceSeconds, refusable: true);

        return $delay < 0 ? null : $delay;
    }

    public function reserveAgain(Uuid $artistId, int $pauseMs, int $spacingMs, int $graceSeconds): int
    {
        return $this->run($artistId, $this->answerKey($artistId, ''), $spacingMs, max(0, $pauseMs), $graceSeconds, refusable: false);
    }

    public function clearQueued(Uuid $artistId): void
    {
        $key = $this->key('queued', $artistId);
        $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->del($key));
    }

    public function markAnswered(Uuid $artistId, string $musicbrainzId, int $ttlSeconds): void
    {
        $key = $this->answerKey($artistId, $musicbrainzId);
        $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->setex($key, max(1, $ttlSeconds), '1'));
    }

    public function isAnswered(Uuid $artistId, string $musicbrainzId): bool
    {
        $key = $this->answerKey($artistId, $musicbrainzId);

        return $this->redis->borrow(static fn (\Redis $redis): bool => (int) $redis->exists($key) > 0);
    }

    public function markRunCancelled(Uuid $runId, int $ttlSeconds): void
    {
        $this->cancel($runId->toString(), max(1, $ttlSeconds));
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
        // Gone, or expiring in this second: the run's last sync was due a day ago.
        if (!is_string($runId) || $ttl < 1) {
            return false;
        }
        $this->cancel($runId, $ttl);

        return true;
    }

    /** Records the run cancelled and sets the shared due time back to now. */
    private function cancel(string $runId, int $ttlSeconds): void
    {
        $cancelled = $this->key('cancelled_run', $runId);
        $pace = $this->paceKey();
        $this->redis->borrow(static function (\Redis $redis) use ($cancelled, $pace, $ttlSeconds): void {
            $redis->setex($cancelled, $ttlSeconds, '1');
            $redis->del($pace);
        });
    }

    private function run(Uuid $artistId, string $answerKey, int $spacingMs, int $leastDelayMs, int $graceSeconds, bool $refusable): int
    {
        $arguments = [
            $this->paceKey(),
            $this->key('queued', $artistId),
            $answerKey,
            (string) max(0, $spacingMs),
            (string) $leastDelayMs,
            (string) max(1, $graceSeconds),
            $refusable ? '1' : '0',
        ];

        return $this->redis->borrow(static fn (\Redis $redis): int => (int) $redis->eval(self::RESERVE, $arguments, 3));
    }

    private function answerKey(Uuid $artistId, string $musicbrainzId): string
    {
        return sprintf('%s:answered:%s:%s', $this->keyPrefix, $artistId->toString(), strtolower(trim($musicbrainzId)));
    }

    private function paceKey(): string
    {
        return $this->keyPrefix . ':pace';
    }

    private function key(string $kind, Uuid|string $id): string
    {
        return sprintf('%s:%s:%s', $this->keyPrefix, $kind, $id instanceof Uuid ? $id->toString() : $id);
    }
}
