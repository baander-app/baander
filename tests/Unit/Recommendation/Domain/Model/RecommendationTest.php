<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Domain\Model;

use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\ValueObject\RecommendationType;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class RecommendationTest extends TestCase
{
    public function testCreateWithDefaults(): void
    {
        $rec = Recommendation::create(
            sourceType: 'song',
            sourceId: 'song-123',
            targetType: 'song',
            targetId: 'song-456',
            score: 95.0,
        );

        $this->assertSame('default', $rec->getName());
        $this->assertTrue($rec->getSourceType()->equals(RecommendationType::fromString('song')));
        $this->assertSame('song-123', $rec->getSourceId());
        $this->assertTrue($rec->getTargetType()->equals(RecommendationType::fromString('song')));
        $this->assertSame('song-456', $rec->getTargetId());
        $this->assertSame(95.0, $rec->getScore());
        $this->assertNull($rec->getPosition());
        $this->assertNull($rec->getUserId());
    }

    public function testCreateWithAllParameters(): void
    {
        $userId = Uuid::v4();

        $rec = Recommendation::create(
            sourceType: 'album',
            sourceId: 'album-789',
            targetType: 'artist',
            targetId: 'artist-101',
            score: 88.0,
            userId: $userId,
            name: 'similar_artists',
            position: 1,
        );

        $this->assertSame('similar_artists', $rec->getName());
        $this->assertTrue($rec->getSourceType()->equals(RecommendationType::fromString('album')));
        $this->assertSame('album-789', $rec->getSourceId());
        $this->assertTrue($rec->getTargetType()->equals(RecommendationType::fromString('artist')));
        $this->assertSame('artist-101', $rec->getTargetId());
        $this->assertSame(88.0, $rec->getScore());
        $this->assertSame(1, $rec->getPosition());
        $this->assertSame($userId, $rec->getUserId());
    }

    public function testCreateGeneratesNewId(): void
    {
        $rec1 = Recommendation::create('song', 's1', 'song', 's2', 50.0);
        $rec2 = Recommendation::create('song', 's3', 'song', 's4', 50.0);

        $this->assertNotSame($rec1->getId()->toString(), $rec2->getId()->toString());
    }

    public function testCreateSetsTimestamps(): void
    {
        $before = new \DateTimeImmutable();
        $rec = Recommendation::create('song', 's1', 'song', 's2', 50.0);
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $rec->getCreatedAt());
        $this->assertLessThanOrEqual($after, $rec->getCreatedAt());
        $this->assertEqualsWithDelta(0, $rec->getCreatedAt()->getTimestamp() - $rec->getUpdatedAt()->getTimestamp(), 1);
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $id = Uuid::v4();
        $userId = Uuid::v4();
        $createdAt = new \DateTimeImmutable('-1 day');
        $updatedAt = new \DateTimeImmutable('-1 hour');

        $rec = Recommendation::reconstitute(
            id: $id,
            name: 'genre_match',
            sourceType: 'song',
            sourceId: 'song-abc',
            targetType: 'song',
            targetId: 'song-def',
            score: 77.5,
            position: 3,
            userId: $userId,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $this->assertSame($id, $rec->getId());
        $this->assertSame('genre_match', $rec->getName());
        $this->assertTrue($rec->getSourceType()->equals(RecommendationType::fromString('song')));
        $this->assertSame('song-abc', $rec->getSourceId());
        $this->assertTrue($rec->getTargetType()->equals(RecommendationType::fromString('song')));
        $this->assertSame('song-def', $rec->getTargetId());
        $this->assertSame(77.5, $rec->getScore());
        $this->assertSame(3, $rec->getPosition());
        $this->assertSame($userId, $rec->getUserId());
        $this->assertSame($createdAt, $rec->getCreatedAt());
        $this->assertSame($updatedAt, $rec->getUpdatedAt());
    }

    public function testReconstituteWithNullUserId(): void
    {
        $rec = Recommendation::reconstitute(
            id: Uuid::v4(),
            name: 'global',
            sourceType: 'artist',
            sourceId: 'a1',
            targetType: 'artist',
            targetId: 'a2',
            score: 60.0,
            position: null,
            userId: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );

        $this->assertNull($rec->getUserId());
        $this->assertNull($rec->getPosition());
    }

    public function testUpdateScoreChangesScore(): void
    {
        $rec = Recommendation::create('song', 's1', 'song', 's2', 50.0);

        $rec->updateScore(75.5);

        $this->assertSame(75.5, $rec->getScore());
    }

    public function testUpdateScoreUpdatesTimestamp(): void
    {
        $rec = Recommendation::create('song', 's1', 'song', 's2', 50.0);
        $originalUpdatedAt = $rec->getUpdatedAt();

        usleep(1000);
        $rec->updateScore(80.0);

        $this->assertNotEquals($originalUpdatedAt, $rec->getUpdatedAt());
        $this->assertGreaterThan($originalUpdatedAt, $rec->getUpdatedAt());
    }

    public function testUpdateScoreDoesNotChangeCreatedAt(): void
    {
        $rec = Recommendation::create('song', 's1', 'song', 's2', 50.0);
        $originalCreatedAt = $rec->getCreatedAt();

        $rec->updateScore(90.0);

        $this->assertEquals($originalCreatedAt, $rec->getCreatedAt());
    }

    public function testPolymorphicSourceTypeAndTargetType(): void
    {
        $songToAlbum = Recommendation::create('song', 's1', 'album', 'a1', 80.0);
        $this->assertTrue($songToAlbum->getSourceType()->equals(RecommendationType::fromString('song')));
        $this->assertTrue($songToAlbum->getTargetType()->equals(RecommendationType::fromString('album')));

        $artistToArtist = Recommendation::create('artist', 'a1', 'artist', 'a2', 70.0);
        $this->assertTrue($artistToArtist->getSourceType()->equals(RecommendationType::fromString('artist')));
        $this->assertTrue($artistToArtist->getTargetType()->equals(RecommendationType::fromString('artist')));

        $albumToSong = Recommendation::create('album', 'a1', 'song', 's1', 60.0);
        $this->assertTrue($albumToSong->getSourceType()->equals(RecommendationType::fromString('album')));
        $this->assertTrue($albumToSong->getTargetType()->equals(RecommendationType::fromString('song')));
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $rec = Recommendation::create('song', 's1', 'song', 's2', 50.0);

        $this->assertInstanceOf(Uuid::class, $rec->getId());
        $this->assertInstanceOf(RecommendationType::class, $rec->getSourceType());
        $this->assertInstanceOf(RecommendationType::class, $rec->getTargetType());
        $this->assertIsString($rec->getName());
        $this->assertIsString($rec->getSourceId());
        $this->assertIsString($rec->getTargetId());
        $this->assertIsFloat($rec->getScore());
        $this->assertInstanceOf(\DateTimeImmutable::class, $rec->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $rec->getUpdatedAt());
    }

    public function testCreateWithInvalidSourceTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Recommendation::create('invalid_type', 's1', 'song', 's2', 50.0);
    }

    public function testCreateWithInvalidTargetTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Recommendation::create('song', 's1', 'invalid_type', 's2', 50.0);
    }

    public function testCreateAcceptsFloatScores(): void
    {
        $rec = Recommendation::create('song', 's1', 'song', 's2', 0.847);

        $this->assertSame(0.847, $rec->getScore());
    }
}
