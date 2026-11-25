<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Infrastructure\Redis;

use App\Catalog\Infrastructure\Redis\RedisQueuedArtistSyncs;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 * @group redis
 */
final class RedisQueuedArtistSyncsTest extends TestCase
{
    private const string PREFIX = 'catalog:artist_sync:test';
    private const string MBID = 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19';
    private const int SPACING_MS = 2_000;
    private const int GRACE_SECONDS = 86_400;

    private RedisClientFactory $redis;
    private RedisQueuedArtistSyncs $syncs;

    protected function setUp(): void
    {
        $this->redis = new RedisClientFactory($_ENV['REDIS_URL'] ?? 'redis://redis:6379');
        $this->syncs = new RedisQueuedArtistSyncs($this->redis, self::PREFIX);
    }

    protected function tearDown(): void
    {
        $this->redis->borrow(static function (\Redis $redis): void {
            $keys = [];
            $cursor = null;
            // SCAN, not KEYS: KEYS is outside the application user's ACL.
            while (($batch = $redis->scan($cursor, self::PREFIX . ':*', 1000)) !== false) {
                $keys = [...$keys, ...$batch];
            }
            if ($keys !== []) {
                $redis->del($keys);
            }
        });
        $this->redis->dispose();
    }

    public function testSyncsFallDueOneStepApartAndTheFirstAtOnce(): void
    {
        $delays = array_map(fn (): ?int => $this->syncs->reserve(Uuid::v7(), self::MBID, self::SPACING_MS, self::GRACE_SECONDS), range(1, 3));

        self::assertSame(0, $delays[0]);
        self::assertEqualsWithDelta(2_000, $delays[1], 50);
        self::assertEqualsWithDelta(4_000, $delays[2], 50);
    }

    public function testAQueuedArtistIsNotReservedAgainUntilItsMarkIsCleared(): void
    {
        $artistId = Uuid::v7();

        self::assertNotNull($this->syncs->reserve($artistId, self::MBID, self::SPACING_MS, self::GRACE_SECONDS));
        self::assertNull($this->syncs->reserve($artistId, self::MBID, self::SPACING_MS, self::GRACE_SECONDS));
        self::assertEqualsWithDelta(self::GRACE_SECONDS, $this->ttl('queued:' . $artistId->toString()), 1);

        $this->syncs->clearQueued($artistId);

        self::assertNotNull($this->syncs->reserve($artistId, self::MBID, self::SPACING_MS, self::GRACE_SECONDS));
    }

    public function testAnArtistAnsweredAboutItsIdIsNotReservedButIsForAnotherId(): void
    {
        $artistId = Uuid::v7();
        $this->syncs->markAnswered($artistId, strtoupper(self::MBID), 30 * 86_400);

        self::assertTrue($this->syncs->isAnswered($artistId, self::MBID));
        self::assertNull($this->syncs->reserve($artistId, self::MBID, self::SPACING_MS, self::GRACE_SECONDS));
        self::assertNotNull($this->syncs->reserve($artistId, '2b1d4a4e-7c0f-4d6e-9a1b-3c5d7e9f1a02', self::SPACING_MS, self::GRACE_SECONDS));
        self::assertFalse($this->syncs->isAnswered(Uuid::v7(), self::MBID));
        self::assertEqualsWithDelta(30 * 86_400, $this->ttl('profile-answered:' . $artistId->toString() . ':' . self::MBID), 1);
    }

    public function testAnEarlierDetailsOnlyAnswerDoesNotSuppressPersonReconciliation(): void
    {
        $artistId = Uuid::v7();
        $this->redis->borrow(static function (\Redis $redis) use ($artistId): void {
            $redis->setex(self::PREFIX . ':answered:' . $artistId . ':' . self::MBID, 3600, '1');
        });

        self::assertFalse($this->syncs->isAnswered($artistId, self::MBID));
        self::assertNotNull($this->syncs->reserve($artistId, self::MBID, self::SPACING_MS, self::GRACE_SECONDS));
    }

    public function testASyncQueuedAgainWaitsItsPauseKeepsItsMarkAndStepsTheSyncsAfterIt(): void
    {
        $artistId = Uuid::v7();
        $this->syncs->reserve($artistId, self::MBID, self::SPACING_MS, self::GRACE_SECONDS);

        $delay = $this->syncs->reserveAgain($artistId, 300_000, self::SPACING_MS, self::GRACE_SECONDS);
        $next = $this->syncs->reserve(Uuid::v7(), self::MBID, self::SPACING_MS, self::GRACE_SECONDS);

        self::assertEqualsWithDelta(300_000, $delay, 50);
        self::assertEqualsWithDelta(302_000, $next, 50);
        self::assertEqualsWithDelta(300 + self::GRACE_SECONDS, $this->ttl('queued:' . $artistId->toString()), 1);
        self::assertNull($this->syncs->reserve($artistId, self::MBID, self::SPACING_MS, self::GRACE_SECONDS), 'The artist stays queued.');
    }

    public function testCancellingARunSetsTheSharedDueTimeBackToNow(): void
    {
        $runId = Uuid::v7();
        foreach (range(1, 3) as $ignored) {
            $this->syncs->reserve(Uuid::v7(), self::MBID, self::SPACING_MS, self::GRACE_SECONDS);
        }

        $this->syncs->markRunCancelled($runId, 90);

        self::assertTrue($this->syncs->isRunCancelled($runId));
        self::assertFalse($this->syncs->isRunCancelled(Uuid::v7()));
        self::assertSame(0, $this->syncs->reserve(Uuid::v7(), self::MBID, self::SPACING_MS, self::GRACE_SECONDS));
    }

    public function testCancellingAFinishedJobCancelsItsRunAndSetsTheSharedDueTimeBackToNow(): void
    {
        $runId = Uuid::v7();
        $this->syncs->reserve(Uuid::v7(), self::MBID, self::SPACING_MS, self::GRACE_SECONDS);
        $this->syncs->reserve(Uuid::v7(), self::MBID, self::SPACING_MS, self::GRACE_SECONDS);
        $this->syncs->recordJobRun('sync-job', $runId, 90);

        self::assertTrue($this->syncs->hasQueuedWork('sync-job'));
        self::assertFalse($this->syncs->hasQueuedWork('other-job'));
        self::assertTrue($this->syncs->cancelQueuedWork('sync-job'));
        self::assertFalse($this->syncs->cancelQueuedWork('other-job'));

        self::assertTrue($this->syncs->isRunCancelled($runId));
        self::assertEqualsWithDelta(90, $this->ttl('cancelled_run:' . $runId->toString()), 1);
        self::assertSame(0, $this->syncs->reserve(Uuid::v7(), self::MBID, self::SPACING_MS, self::GRACE_SECONDS));
    }

    private function ttl(string $key): int
    {
        $name = self::PREFIX . ':' . $key;

        return $this->redis->borrow(static fn (\Redis $redis): int => (int) $redis->ttl($name));
    }
}
