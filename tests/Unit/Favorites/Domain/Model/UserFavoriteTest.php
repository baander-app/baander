<?php

declare(strict_types=1);

namespace App\Tests\Unit\Favorites\Domain\Model;

use App\Favorites\Domain\Model\UserFavorite;
use App\Favorites\Domain\Model\UserFavoriteState;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class UserFavoriteTest extends TestCase
{
    private Uuid $userId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v4();
    }

    public function testCreateSetsAllFields(): void
    {
        $favorite = UserFavorite::create(
            userId: $this->userId,
            entityType: FavoriteType::Song,
            entityPublicId: 'song-pub-123',
        );

        $this->assertTrue($favorite->getUserId()->equals($this->userId));
        $this->assertSame(FavoriteType::Song, $favorite->getEntityType());
        $this->assertSame('song-pub-123', $favorite->getEntityPublicId());
    }

    public function testCreateGeneratesNewIdAndPublicId(): void
    {
        $favorite = UserFavorite::create(
            userId: $this->userId,
            entityType: FavoriteType::Album,
            entityPublicId: 'album-pub-456',
        );

        $this->assertInstanceOf(Uuid::class, $favorite->getId());
        $this->assertFalse($favorite->getId()->equals($this->userId));
        $this->assertInstanceOf(PublicId::class, $favorite->getPublicId());
    }

    public function testCreateSetsTimestamps(): void
    {
        $before = new DateTimeImmutable();

        $favorite = UserFavorite::create(
            userId: $this->userId,
            entityType: FavoriteType::Artist,
            entityPublicId: 'artist-pub-789',
        );

        $after = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $favorite->getCreatedAt());
        $this->assertLessThanOrEqual($after, $favorite->getCreatedAt());
        $this->assertGreaterThanOrEqual($before, $favorite->getUpdatedAt());
        $this->assertLessThanOrEqual($after, $favorite->getUpdatedAt());
    }

    public function testReconstitutePreservesAllFields(): void
    {
        $id = Uuid::v4();
        $publicId = new PublicId();
        $createdAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $updatedAt = new DateTimeImmutable('2025-06-15 12:30:00');

        $state = new UserFavoriteState(
            id: $id,
            publicId: $publicId,
            userId: $this->userId,
            entityType: FavoriteType::Album,
            entityPublicId: 'album-pub-xyz',
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $favorite = UserFavorite::reconstitute($state);

        $this->assertTrue($favorite->getId()->equals($id));
        $this->assertTrue($favorite->getPublicId()->equals($publicId));
        $this->assertTrue($favorite->getUserId()->equals($this->userId));
        $this->assertSame(FavoriteType::Album, $favorite->getEntityType());
        $this->assertSame('album-pub-xyz', $favorite->getEntityPublicId());
        $this->assertEquals($createdAt, $favorite->getCreatedAt());
        $this->assertEquals($updatedAt, $favorite->getUpdatedAt());
    }

    public function testReconstituteReturnsSameStateObject(): void
    {
        $state = new UserFavoriteState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            userId: $this->userId,
            entityType: FavoriteType::Song,
            entityPublicId: 'song-pub-1',
            createdAt: new DateTimeImmutable(),
        );

        $favorite = UserFavorite::reconstitute($state);

        $this->assertSame($state, $favorite->getState());
    }

    public function testReconstituteWithDefaultUpdatedAt(): void
    {
        $before = new DateTimeImmutable('-1 second');

        $state = new UserFavoriteState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            userId: $this->userId,
            entityType: FavoriteType::Artist,
            entityPublicId: 'artist-pub-2',
            createdAt: new DateTimeImmutable('2025-01-01'),
        );

        $favorite = UserFavorite::reconstitute($state);

        $this->assertGreaterThanOrEqual($before, $favorite->getUpdatedAt());
    }

    public function testSupportsAllFavoriteTypes(): void
    {
        $song = UserFavorite::create($this->userId, FavoriteType::Song, 's');
        $album = UserFavorite::create($this->userId, FavoriteType::Album, 'a');
        $artist = UserFavorite::create($this->userId, FavoriteType::Artist, 'r');

        $this->assertSame(FavoriteType::Song, $song->getEntityType());
        $this->assertSame(FavoriteType::Album, $album->getEntityType());
        $this->assertSame(FavoriteType::Artist, $artist->getEntityType());
    }
}
