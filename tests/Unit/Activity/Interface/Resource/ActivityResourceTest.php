<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Interface\Resource;

use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Interface\Resource\ActivityResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ActivityResourceTest extends TestCase
{
    public function testFromTransformsActivityToArray(): void
    {
        $id = Uuid::v4();
        $publicId = PublicId::fromString(str_repeat('a', 21));
        $userId = Uuid::v4();
        $songId = Uuid::v4();
        $createdAt = new DateTimeImmutable('2025-06-01 12:00:00');
        $updatedAt = new DateTimeImmutable('2025-06-02 15:30:00');
        $lastPlayedAt = new DateTimeImmutable('2025-06-02 15:00:00');

        $activity = MediaActivity::reconstitute(
            id: $id,
            publicId: $publicId,
            userId: $userId,
            activityType: 'play',
            songId: $songId,
            albumId: null,
            artistId: null,
            movieId: null,
            playCount: 10,
            love: true,
            lastPlayedAt: $lastPlayedAt,
            lastPlatform: 'mobile',
            lastPlayer: 'spotify',
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $result = ActivityResource::from($activity);

        $this->assertSame($id->toString(), $result['uuid']);
        $this->assertSame($publicId->toString(), $result['publicId']);
        $this->assertSame($userId->toString(), $result['userId']);
        $this->assertSame('play', $result['activityType']);
        $this->assertSame($songId->toString(), $result['songId']);
        $this->assertNull($result['albumId']);
        $this->assertNull($result['artistId']);
        $this->assertNull($result['movieId']);
        $this->assertSame(10, $result['playCount']);
        $this->assertTrue($result['love']);
        $this->assertSame('2025-06-02T15:00:00+00:00', $result['lastPlayedAt']);
        $this->assertSame('mobile', $result['lastPlatform']);
        $this->assertSame('spotify', $result['lastPlayer']);
        $this->assertSame('2025-06-01T12:00:00+00:00', $result['createdAt']);
    }

    public function testFromWithNullMediaIds(): void
    {
        $activity = MediaActivity::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('b', 21)),
            userId: Uuid::v4(),
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

        $result = ActivityResource::from($activity);

        $this->assertNull($result['songId']);
        $this->assertNull($result['albumId']);
        $this->assertNull($result['artistId']);
        $this->assertNull($result['movieId']);
        $this->assertSame(0, $result['playCount']);
        $this->assertFalse($result['love']);
        $this->assertNull($result['lastPlayedAt']);
        $this->assertNull($result['lastPlatform']);
        $this->assertNull($result['lastPlayer']);
    }

    public function testFromWithAllMediaIds(): void
    {
        $songId = Uuid::v4();
        $albumId = Uuid::v4();
        $artistId = Uuid::v4();
        $movieId = Uuid::v4();

        $activity = MediaActivity::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('c', 21)),
            userId: Uuid::v4(),
            activityType: 'play',
            songId: $songId,
            albumId: $albumId,
            artistId: $artistId,
            movieId: $movieId,
            playCount: 5,
            love: false,
            lastPlayedAt: null,
            lastPlatform: 'desktop',
            lastPlayer: 'vlc',
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $result = ActivityResource::from($activity);

        $this->assertSame($songId->toString(), $result['songId']);
        $this->assertSame($albumId->toString(), $result['albumId']);
        $this->assertSame($artistId->toString(), $result['artistId']);
        $this->assertSame($movieId->toString(), $result['movieId']);
        $this->assertSame(5, $result['playCount']);
        $this->assertSame('desktop', $result['lastPlatform']);
        $this->assertSame('vlc', $result['lastPlayer']);
    }

    public function testFromWithLoveActivityType(): void
    {
        $activity = MediaActivity::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('d', 21)),
            userId: Uuid::v4(),
            activityType: 'love',
            songId: null,
            albumId: null,
            artistId: Uuid::v4(),
            movieId: null,
            playCount: 0,
            love: true,
            lastPlayedAt: null,
            lastPlatform: null,
            lastPlayer: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $result = ActivityResource::from($activity);

        $this->assertSame('love', $result['activityType']);
        $this->assertTrue($result['love']);
        $this->assertNotNull($result['artistId']);
    }

    public function testCollectionTransformsMultipleActivities(): void
    {
        $activity1 = MediaActivity::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('e', 21)),
            userId: Uuid::v4(),
            activityType: 'play',
            songId: null,
            albumId: null,
            artistId: null,
            movieId: null,
            playCount: 1,
            love: false,
            lastPlayedAt: null,
            lastPlatform: null,
            lastPlayer: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $activity2 = MediaActivity::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('f', 21)),
            userId: Uuid::v4(),
            activityType: 'skip',
            songId: null,
            albumId: null,
            artistId: null,
            movieId: null,
            playCount: 0,
            love: true,
            lastPlayedAt: null,
            lastPlatform: null,
            lastPlayer: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $result = ActivityResource::collection([$activity1, $activity2]);

        $this->assertCount(2, $result);
        $this->assertSame('play', $result[0]['activityType']);
        $this->assertSame('skip', $result[1]['activityType']);
        $this->assertFalse($result[0]['love']);
        $this->assertTrue($result[1]['love']);
    }
}
