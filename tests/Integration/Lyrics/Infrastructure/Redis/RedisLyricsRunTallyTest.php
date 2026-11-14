<?php

declare(strict_types=1);

namespace App\Tests\Integration\Lyrics\Infrastructure\Redis;

use App\Lyrics\Application\DTO\BulkFetchLyricsResult;
use App\Lyrics\Application\DTO\LyricsFetchOutcome;
use App\Lyrics\Infrastructure\Redis\RedisQueuedLyricsFetches;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * A bulk lyrics run's tally in Redis: the walk's skipped counts and each fetch's outcome,
 * counted once per song, for the latest run while it lasts.
 *
 * @group integration
 * @group redis
 */
final class RedisLyricsRunTallyTest extends TestCase
{
    private const string PREFIX = 'lyrics:fetch:test-tally';

    private RedisClientFactory $redis;
    private RedisQueuedLyricsFetches $fetches;

    protected function setUp(): void
    {
        $this->redis = new RedisClientFactory($_ENV['REDIS_URL'] ?? 'redis://redis:6379');
        $this->fetches = new RedisQueuedLyricsFetches($this->redis, self::PREFIX);
    }

    protected function tearDown(): void
    {
        $this->redis->borrow(static function (\Redis $redis): void {
            $keys = $redis->keys(self::PREFIX . ':*');
            if (is_array($keys) && $keys !== []) {
                $redis->del($keys);
            }
        });
        $this->redis->dispose();
    }

    public function testNoRunIsReportedBeforeAWalkFinishes(): void
    {
        self::assertNull($this->fetches->latestRun());

        $this->fetches->startRun(Uuid::v7(), 60);

        self::assertNull($this->fetches->latestRun(), 'A run whose walk has not finished is not the latest run.');
    }

    public function testTheLatestRunReportsItsWalkAndEachFetchOnce(): void
    {
        $runId = Uuid::v7();
        $found = Uuid::v7();
        $this->fetches->startRun($runId, 60);
        $before = time();
        $this->fetches->finishRun($runId, new BulkFetchLyricsResult(queued: 4, skippedNoArtist: 2, skippedNoLength: 1), 'bulk-job', 90);

        $this->fetches->recordOutcome($runId, $found, LyricsFetchOutcome::Found);
        // A redelivered fetch of the same song counts once, whatever it comes to the second time.
        $this->fetches->recordOutcome($runId, $found, LyricsFetchOutcome::Found);
        $this->fetches->recordOutcome($runId, $found, LyricsFetchOutcome::Unavailable);
        $this->fetches->recordOutcome($runId, Uuid::v7(), LyricsFetchOutcome::NoMatch);
        $this->fetches->recordOutcome($runId, Uuid::v7(), LyricsFetchOutcome::Rejected);
        $this->fetches->recordOutcome($runId, Uuid::v7(), LyricsFetchOutcome::Unavailable);

        $tally = $this->fetches->latestRun();
        self::assertNotNull($tally);
        self::assertSame([
            'jobId' => 'bulk-job',
            'queued' => 4,
            'found' => 1,
            'noMatch' => 1,
            'skippedNoArtist' => 2,
            'skippedNoLength' => 1,
            'rejected' => 1,
            'unavailable' => 1,
        ], array_diff_key($tally->toArray(), ['finishedAt' => true]));
        self::assertGreaterThanOrEqual($before, $tally->finishedAt->getTimestamp());
        self::assertLessThanOrEqual(time(), $tally->finishedAt->getTimestamp());
    }

    /** The first fetch is due at once, so a worker can handle it before the walk finishes. */
    public function testAFetchCountedWhileTheWalkGoesOnIsKept(): void
    {
        $runId = Uuid::v7();
        $this->fetches->startRun($runId, 60);
        $this->fetches->recordOutcome($runId, Uuid::v7(), LyricsFetchOutcome::Found);
        // Its song lost its artist credit after the walk queued it.
        $this->fetches->recordOutcome($runId, Uuid::v7(), LyricsFetchOutcome::NoArtist);

        $this->fetches->finishRun($runId, new BulkFetchLyricsResult(queued: 2, skippedNoArtist: 3, skippedNoLength: 0), null, 90);

        $tally = $this->fetches->latestRun();
        self::assertNotNull($tally);
        self::assertSame(1, $tally->found);
        self::assertSame(4, $tally->skippedNoArtist);
    }

    public function testARunThatQueuedNothingIsReportedWithoutAJob(): void
    {
        $runId = Uuid::v7();
        $this->fetches->startRun($runId, 60);
        $this->fetches->finishRun($runId, new BulkFetchLyricsResult(queued: 0, skippedNoArtist: 77, skippedNoLength: 0), null, 86_400);

        $tally = $this->fetches->latestRun();
        self::assertNotNull($tally);
        self::assertNull($tally->jobId);
        self::assertSame(0, $tally->queued);
        self::assertSame(77, $tally->skippedNoArtist);
        self::assertSame(0, $tally->found);
    }

    public function testTheRunWhoseWalkFinishedLastIsTheLatest(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $this->fetches->startRun($first, 60);
        $this->fetches->startRun($second, 60);
        $this->fetches->finishRun($second, new BulkFetchLyricsResult(queued: 1, skippedNoArtist: 0, skippedNoLength: 0), 'second-job', 90);
        $this->fetches->finishRun($first, new BulkFetchLyricsResult(queued: 2, skippedNoArtist: 0, skippedNoLength: 0), 'first-job', 90);

        self::assertSame('first-job', $this->fetches->latestRun()?->jobId);
    }

    public function testNothingIsCountedForARunWithoutATally(): void
    {
        $runId = Uuid::v7();

        $this->fetches->recordOutcome($runId, Uuid::v7(), LyricsFetchOutcome::Found);

        self::assertSame(0, $this->exists('run:' . $runId->toString()), 'A fetch creates no tally, so none is left without an expiry.');
    }

    public function testTheTallyAndTheLatestRunExpireTogether(): void
    {
        $runId = Uuid::v7();
        $this->fetches->startRun($runId, 60);
        self::assertSame(60, $this->ttl('run:' . $runId->toString()));

        $this->fetches->recordOutcome($runId, Uuid::v7(), LyricsFetchOutcome::NoMatch);
        $this->fetches->finishRun($runId, new BulkFetchLyricsResult(queued: 1, skippedNoArtist: 0, skippedNoLength: 0), 'bulk-job', 90);
        $this->fetches->recordOutcome($runId, Uuid::v7(), LyricsFetchOutcome::Found);

        self::assertSame(90, $this->ttl('run:' . $runId->toString()), 'A counted fetch keeps the expiry the walk set.');
        self::assertSame(90, $this->ttl('latest_run'));

        $this->redis->borrow(fn (\Redis $redis): mixed => $redis->del(self::PREFIX . ':run:' . $runId->toString()));

        self::assertNull($this->fetches->latestRun(), 'An expired tally reports no run.');
    }

    private function ttl(string $key): int
    {
        return (int) $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->ttl(self::PREFIX . ':' . $key));
    }

    private function exists(string $key): int
    {
        return (int) $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->exists(self::PREFIX . ':' . $key));
    }
}
