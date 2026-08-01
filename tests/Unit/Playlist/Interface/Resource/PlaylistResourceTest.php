<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Interface\Resource;

use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Model\PlaylistSong;
use App\Playlist\Interface\Resource\PlaylistResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PlaylistResourceTest extends TestCase
{
    public function testFromTransformsPlaylistToArray(): void
    {
        $id = Uuid::v4();
        $publicId = PublicId::fromString(str_repeat('p', 21));
        $userId = Uuid::v4();
        $createdAt = new DateTimeImmutable('2025-06-01 12:00:00');

        $playlist = Playlist::reconstitute(
            id: $id,
            publicId: $publicId,
            userId: $userId,
            name: 'My Playlist',
            description: 'A great playlist',
            isPublic: true,
            isCollaborative: false,
            isSmart: false,
            smartRules: [],
            createdAt: $createdAt,
            updatedAt: $createdAt,
        );

        $result = PlaylistResource::from($playlist);

        $this->assertSame($id->toString(), $result['uuid']);
        $this->assertSame($publicId->toString(), $result['publicId']);
        $this->assertSame($userId->toString(), $result['userId']);
        $this->assertSame('My Playlist', $result['name']);
        $this->assertSame('A great playlist', $result['description']);
        $this->assertTrue($result['isPublic']);
        $this->assertFalse($result['isCollaborative']);
        $this->assertFalse($result['isSmart']);
        $this->assertSame(0, $result['songCount']);
        $this->assertSame('2025-06-01T12:00:00+00:00', $result['createdAt']);
    }

    public function testFromIncludesSongCount(): void
    {
        $song1 = Uuid::v4();
        $song2 = Uuid::v4();
        $song3 = Uuid::v4();

        $playlist = Playlist::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('q', 21)),
            userId: Uuid::v4(),
            name: 'Song List',
            description: null,
            isPublic: false,
            isCollaborative: true,
            isSmart: false,
            smartRules: [],
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            songs: [
                new PlaylistSong($song1, 0),
                new PlaylistSong($song2, 1),
                new PlaylistSong($song3, 2),
            ],
        );

        $result = PlaylistResource::from($playlist);

        $this->assertSame(3, $result['songCount']);
    }

    public function testFromWithSmartPlaylist(): void
    {
        $playlist = Playlist::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('r', 21)),
            userId: Uuid::v4(),
            name: 'Smart Mix',
            description: 'Auto-generated',
            isPublic: true,
            isCollaborative: false,
            isSmart: true,
            smartRules: [['field' => 'genre', 'operator' => 'equals', 'value' => 'jazz']],
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $result = PlaylistResource::from($playlist);

        $this->assertTrue($result['isSmart']);
        $this->assertSame('Smart Mix', $result['name']);
        $this->assertSame('Auto-generated', $result['description']);
    }

    public function testFromWithNullDescription(): void
    {
        $playlist = Playlist::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('s', 21)),
            userId: Uuid::v4(),
            name: 'No Desc',
            description: null,
            isPublic: false,
            isCollaborative: false,
            isSmart: false,
            smartRules: [],
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $result = PlaylistResource::from($playlist);

        $this->assertNull($result['description']);
    }

    public function testCollectionTransformsMultiplePlaylists(): void
    {
        $playlist1 = Playlist::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('t', 21)),
            userId: Uuid::v4(),
            name: 'First',
            description: null,
            isPublic: false,
            isCollaborative: false,
            isSmart: false,
            smartRules: [],
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $playlist2 = Playlist::reconstitute(
            id: Uuid::v4(),
            publicId: PublicId::fromString(str_repeat('u', 21)),
            userId: Uuid::v4(),
            name: 'Second',
            description: null,
            isPublic: true,
            isCollaborative: false,
            isSmart: false,
            smartRules: [],
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $result = PlaylistResource::collection([$playlist1, $playlist2]);

        $this->assertCount(2, $result);
        $this->assertSame('First', $result[0]['name']);
        $this->assertSame('Second', $result[1]['name']);
        $this->assertFalse($result[0]['isPublic']);
        $this->assertTrue($result[1]['isPublic']);
    }
}
