<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Domain\Model;

use App\Activity\Domain\Model\MediaActivity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MediaActivityTest extends TestCase
{
    private Uuid $userId;
    private Uuid $songId;
    private Uuid $albumId;
    private Uuid $artistId;
    private Uuid $movieId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v4();
        $this->songId = Uuid::v4();
        $this->albumId = Uuid::v4();
        $this->artistId = Uuid::v4();
        $this->movieId = Uuid::v4();
    }

    public function testCreatePlayActivity(): void
    {
        $activity = MediaActivity::create(
            userId: $this->userId,
            activityType: 'play',
            songId: $this->songId,
        );

        $this->assertSame('play', $activity->getActivityType());
        $this->assertTrue($activity->getUserId()->equals($this->userId));
        $this->assertTrue($activity->getSongId()->equals($this->songId));
        $this->assertNull($activity->getAlbumId());
        $this->assertNull($activity->getArtistId());
        $this->assertNull($activity->getMovieId());
        $this->assertSame(0, $activity->getPlayCount());
        $this->assertFalse($activity->isLove());
        $this->assertNull($activity->getLastPlayedAt());
        $this->assertNull($activity->getLastPlatform());
        $this->assertNull($activity->getLastPlayer());
    }

    public function testCreateLoveActivity(): void
    {
        $activity = MediaActivity::create(
            userId: $this->userId,
            activityType: 'love',
            artistId: $this->artistId,
        );

        $this->assertSame('love', $activity->getActivityType());
        $this->assertNull($activity->getSongId());
        $this->assertTrue($activity->getArtistId()->equals($this->artistId));
    }

    public function testCreateSkipActivity(): void
    {
        $activity = MediaActivity::create(
            userId: $this->userId,
            activityType: 'skip',
            albumId: $this->albumId,
        );

        $this->assertSame('skip', $activity->getActivityType());
        $this->assertTrue($activity->getAlbumId()->equals($this->albumId));
    }

    public function testCreateWithAllMediaIds(): void
    {
        $activity = MediaActivity::create(
            userId: $this->userId,
            activityType: 'play',
            songId: $this->songId,
            albumId: $this->albumId,
            artistId: $this->artistId,
            movieId: $this->movieId,
        );

        $this->assertTrue($activity->getSongId()->equals($this->songId));
        $this->assertTrue($activity->getAlbumId()->equals($this->albumId));
        $this->assertTrue($activity->getArtistId()->equals($this->artistId));
        $this->assertTrue($activity->getMovieId()->equals($this->movieId));
    }

    public function testCreateWithNoMediaIds(): void
    {
        $activity = MediaActivity::create(
            userId: $this->userId,
            activityType: 'play',
        );

        $this->assertNull($activity->getSongId());
        $this->assertNull($activity->getAlbumId());
        $this->assertNull($activity->getArtistId());
        $this->assertNull($activity->getMovieId());
    }

    public function testCreateThrowsOnInvalidType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid activity type "purchase"');

        MediaActivity::create(userId: $this->userId, activityType: 'purchase');
    }

    public function testCreateThrowsOnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid activity type ""');

        MediaActivity::create(userId: $this->userId, activityType: '');
    }

    public function testCreateGeneratesIdsAndTimestamps(): void
    {
        $before = new DateTimeImmutable();

        $activity = MediaActivity::create(userId: $this->userId, activityType: 'play');

        $after = new DateTimeImmutable();

        $this->assertInstanceOf(Uuid::class, $activity->getId());
        $this->assertInstanceOf(PublicId::class, $activity->getPublicId());
        $this->assertGreaterThanOrEqual($before, $activity->getCreatedAt());
        $this->assertLessThanOrEqual($after, $activity->getCreatedAt());
        $this->assertGreaterThanOrEqual($before, $activity->getUpdatedAt());
        $this->assertLessThanOrEqual($after, $activity->getUpdatedAt());
    }

    public function testRecordPlayIncrementsCountAndSetsMetadata(): void
    {
        $activity = MediaActivity::create(userId: $this->userId, activityType: 'play');

        $activity->recordPlay('mobile', 'spotify');

        $this->assertSame(1, $activity->getPlayCount());
        $this->assertNotNull($activity->getLastPlayedAt());
        $this->assertSame('mobile', $activity->getLastPlatform());
        $this->assertSame('spotify', $activity->getLastPlayer());
    }

    public function testRecordPlayMultipleTimes(): void
    {
        $activity = MediaActivity::create(userId: $this->userId, activityType: 'play');

        $activity->recordPlay('web', 'browser');
        $activity->recordPlay('mobile', 'app');
        $activity->recordPlay('desktop', 'vlc');

        $this->assertSame(3, $activity->getPlayCount());
        $this->assertSame('desktop', $activity->getLastPlatform());
        $this->assertSame('vlc', $activity->getLastPlayer());
    }

    public function testRecordPlayUpdatesTimestamp(): void
    {
        $activity = MediaActivity::create(userId: $this->userId, activityType: 'play');
        $before = $activity->getUpdatedAt();

        $activity->recordPlay();

        $this->assertGreaterThan($before, $activity->getUpdatedAt());
    }

    public function testRecordPlayWithNoPlatformOrPlayer(): void
    {
        $activity = MediaActivity::create(userId: $this->userId, activityType: 'play');

        $activity->recordPlay();

        $this->assertSame(1, $activity->getPlayCount());
        $this->assertNull($activity->getLastPlatform());
        $this->assertNull($activity->getLastPlayer());
    }

    public function testToggleLoveFromFalse(): void
    {
        $activity = MediaActivity::create(userId: $this->userId, activityType: 'play');

        $this->assertFalse($activity->isLove());

        $activity->toggleLove();

        $this->assertTrue($activity->isLove());
    }

    public function testToggleLoveFromTrue(): void
    {
        $activity = MediaActivity::create(userId: $this->userId, activityType: 'play');
        $activity->toggleLove();

        $activity->toggleLove();

        $this->assertFalse($activity->isLove());
    }

    public function testSetLove(): void
    {
        $activity = MediaActivity::create(userId: $this->userId, activityType: 'play');

        $activity->setLove(true);

        $this->assertTrue($activity->isLove());

        $activity->setLove(false);

        $this->assertFalse($activity->isLove());
    }

    public function testSetLoveUpdatesTimestamp(): void
    {
        $activity = MediaActivity::create(userId: $this->userId, activityType: 'play');
        $before = $activity->getUpdatedAt();

        $activity->setLove(true);

        $this->assertGreaterThan($before, $activity->getUpdatedAt());
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $id = Uuid::v4();
        $publicId = PublicId::fromString(str_repeat('a', 21));
        $createdAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $updatedAt = new DateTimeImmutable('2025-01-15 12:00:00');
        $lastPlayedAt = new DateTimeImmutable('2025-01-15 11:00:00');

        $activity = MediaActivity::reconstitute(
            id: $id,
            publicId: $publicId,
            userId: $this->userId,
            activityType: 'play',
            songId: $this->songId,
            albumId: $this->albumId,
            artistId: $this->artistId,
            movieId: null,
            playCount: 42,
            love: true,
            lastPlayedAt: $lastPlayedAt,
            lastPlatform: 'desktop',
            lastPlayer: 'vlc',
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $this->assertTrue($activity->getId()->equals($id));
        $this->assertTrue($activity->getPublicId()->equals($publicId));
        $this->assertTrue($activity->getUserId()->equals($this->userId));
        $this->assertSame('play', $activity->getActivityType());
        $this->assertTrue($activity->getSongId()->equals($this->songId));
        $this->assertTrue($activity->getAlbumId()->equals($this->albumId));
        $this->assertTrue($activity->getArtistId()->equals($this->artistId));
        $this->assertNull($activity->getMovieId());
        $this->assertSame(42, $activity->getPlayCount());
        $this->assertTrue($activity->isLove());
        $this->assertEquals($lastPlayedAt, $activity->getLastPlayedAt());
        $this->assertSame('desktop', $activity->getLastPlatform());
        $this->assertSame('vlc', $activity->getLastPlayer());
        $this->assertEquals($createdAt, $activity->getCreatedAt());
        $this->assertEquals($updatedAt, $activity->getUpdatedAt());
    }

    public function testReconstituteWithMinimalFields(): void
    {
        $activity = MediaActivity::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('b', 21)),
            userId: $this->userId,
            activityType: 'skip',
            songId: null,
            albumId: null,
            artistId: null,
            movieId: null,
            playCount: 0,
            love: false,
            lastPlayedAt: null,
            lastPlatform: null,
            lastPlayer: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $this->assertSame('skip', $activity->getActivityType());
        $this->assertSame(0, $activity->getPlayCount());
        $this->assertFalse($activity->isLove());
        $this->assertNull($activity->getLastPlayedAt());
    }
}
