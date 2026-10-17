<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Interface\Resource;

use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Application\DTO\ActivityView;
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

        $result = $this->map($activity);

        $this->assertArrayNotHasKey('uuid', $result);
        $this->assertSame($publicId->toString(), $result['publicId']);
        $this->assertSame(str_repeat('u', 21), $result['userId']);
        $this->assertSame('play', $result['activityType']);
        $this->assertSame(str_repeat('s', 21), $result['songId']);
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

        $result = $this->map($activity);

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

        $result = $this->map($activity);

        $this->assertSame(str_repeat('s', 21), $result['songId']);
        $this->assertSame(str_repeat('l', 21), $result['albumId']);
        $this->assertSame(str_repeat('r', 21), $result['artistId']);
        $this->assertSame(str_repeat('m', 21), $result['movieId']);
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

        $result = $this->map($activity);

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

        $result = ActivityResource::collection([$this->view($activity1), $this->view($activity2)]);

        $this->assertCount(2, $result);
        $this->assertSame('play', $result[0]['activityType']);
        $this->assertSame('skip', $result[1]['activityType']);
        $this->assertFalse($result[0]['love']);
        $this->assertTrue($result[1]['love']);
    }
    /** @return array<string, mixed> */
    private function map(MediaActivity $activity): array
    {
        $result = ActivityResource::from($this->view($activity));
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([$activity->getId(), $activity->getUserId(), $activity->getSongId(), $activity->getAlbumId(), $activity->getArtistId(), $activity->getMovieId()] as $id) {
            if ($id !== null) {
                self::assertStringNotContainsString($id->toString(), $json);
            }
        }

        return $result;
    }

    private function view(MediaActivity $activity): ActivityView
    {
        return new ActivityView(
            $activity,
            PublicId::fromString(str_repeat('u', 21)),
            $activity->getSongId() === null ? null : PublicId::fromString(str_repeat('s', 21)),
            $activity->getAlbumId() === null ? null : PublicId::fromString(str_repeat('l', 21)),
            $activity->getArtistId() === null ? null : PublicId::fromString(str_repeat('r', 21)),
            $activity->getMovieId() === null ? null : PublicId::fromString(str_repeat('m', 21)),
        );
    }

}
