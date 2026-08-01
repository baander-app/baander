<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Domain\Model;

use App\Playlist\Domain\Model\Playlist;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlaylistTest extends TestCase
{
    private Uuid $userId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v4();
    }

    public function testCreateBasicPlaylist(): void
    {
        $playlist = Playlist::create(
            name: 'My Playlist',
            userId: $this->userId,
        );

        $this->assertSame('My Playlist', $playlist->getName());
        $this->assertNull($playlist->getDescription());
        $this->assertFalse($playlist->isPublic());
        $this->assertFalse($playlist->isCollaborative());
        $this->assertFalse($playlist->isSmart());
        $this->assertSame([], $playlist->getSmartRules());
        $this->assertSame([], $playlist->getSongs());
    }

    public function testCreatePlaylistWithAllOptions(): void
    {
        $playlist = Playlist::create(
            name: 'Chill Vibes',
            userId: $this->userId,
            description: 'Relaxing tracks',
            isPublic: true,
            isCollaborative: true,
            isSmart: true,
            smartRules: [['field' => 'genre', 'operator' => 'equals', 'value' => 'chill']],
        );

        $this->assertSame('Chill Vibes', $playlist->getName());
        $this->assertSame('Relaxing tracks', $playlist->getDescription());
        $this->assertTrue($playlist->isPublic());
        $this->assertTrue($playlist->isCollaborative());
        $this->assertTrue($playlist->isSmart());
        $this->assertSame([['field' => 'genre', 'operator' => 'equals', 'value' => 'chill']], $playlist->getSmartRules());
    }

    public function testCreatePublicPlaylist(): void
    {
        $playlist = Playlist::create(
            name: 'Public Mix',
            userId: $this->userId,
            isPublic: true,
        );

        $this->assertTrue($playlist->isPublic());
        $this->assertFalse($playlist->isCollaborative());
        $this->assertFalse($playlist->isSmart());
    }

    public function testCreatePrivatePlaylist(): void
    {
        $playlist = Playlist::create(
            name: 'Private Mix',
            userId: $this->userId,
            isPublic: false,
        );

        $this->assertFalse($playlist->isPublic());
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Playlist name cannot be empty.');

        Playlist::create(name: '', userId: $this->userId);
    }

    public function testCreateThrowsOnWhitespaceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Playlist name cannot be empty.');

        Playlist::create(name: '   ', userId: $this->userId);
    }

    public function testCreateGeneratesIdsAndTimestamps(): void
    {
        $before = new DateTimeImmutable();

        $playlist = Playlist::create(name: 'New List', userId: $this->userId);

        $after = new DateTimeImmutable();

        $this->assertInstanceOf(Uuid::class, $playlist->getId());
        $this->assertInstanceOf(PublicId::class, $playlist->getPublicId());
        $this->assertSame($this->userId->toString(), $playlist->getUserId()->toString());
        $this->assertGreaterThanOrEqual($before, $playlist->getCreatedAt());
        $this->assertLessThanOrEqual($after, $playlist->getCreatedAt());
        $this->assertGreaterThanOrEqual($before, $playlist->getUpdatedAt());
        $this->assertLessThanOrEqual($after, $playlist->getUpdatedAt());
    }

    public function testUpdateMetadata(): void
    {
        $playlist = Playlist::create(name: 'Old Name', userId: $this->userId);

        $before = $playlist->getUpdatedAt();

        $playlist->updateMetadata(
            name: 'New Name',
            description: 'Updated description',
            isPublic: true,
        );

        $this->assertSame('New Name', $playlist->getName());
        $this->assertSame('Updated description', $playlist->getDescription());
        $this->assertTrue($playlist->isPublic());
        $this->assertGreaterThan($before, $playlist->getUpdatedAt());
    }

    public function testUpdateMetadataThrowsOnEmptyName(): void
    {
        $playlist = Playlist::create(name: 'Valid Name', userId: $this->userId);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Playlist name cannot be empty.');

        $playlist->updateMetadata(name: '', description: null, isPublic: false);
    }

    public function testAddSong(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);
        $songId = Uuid::v4();

        $playlist->addSong($songId, 0);

        $songs = $playlist->getSongs();
        $this->assertCount(1, $songs);
        $this->assertTrue($songs[0]->getSongId()->equals($songId));
        $this->assertSame(0, $songs[0]->getPosition());
    }

    public function testAddMultipleSongs(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);
        $song1 = Uuid::v4();
        $song2 = Uuid::v4();
        $song3 = Uuid::v4();

        $playlist->addSong($song1, 0);
        $playlist->addSong($song2, 1);
        $playlist->addSong($song3, 2);

        $this->assertCount(3, $playlist->getSongs());
        $this->assertSame(0, $playlist->getSongs()[0]->getPosition());
        $this->assertSame(1, $playlist->getSongs()[1]->getPosition());
        $this->assertSame(2, $playlist->getSongs()[2]->getPosition());
    }

    public function testAddSongThrowsOnDuplicate(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);
        $songId = Uuid::v4();

        $playlist->addSong($songId, 0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists in this playlist');

        $playlist->addSong($songId, 1);
    }

    public function testRemoveSong(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);
        $song1 = Uuid::v4();
        $song2 = Uuid::v4();
        $song3 = Uuid::v4();

        $playlist->addSong($song1, 0);
        $playlist->addSong($song2, 1);
        $playlist->addSong($song3, 2);

        $playlist->removeSong($song2);

        $songs = $playlist->getSongs();
        $this->assertCount(2, $songs);
        $this->assertTrue($songs[0]->getSongId()->equals($song1));
        $this->assertTrue($songs[1]->getSongId()->equals($song3));
        // Positions are re-indexed
        $this->assertSame(0, $songs[0]->getPosition());
        $this->assertSame(1, $songs[1]->getPosition());
    }

    public function testRemoveOnlySong(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);
        $songId = Uuid::v4();

        $playlist->addSong($songId, 0);
        $playlist->removeSong($songId);

        $this->assertSame([], $playlist->getSongs());
    }

    public function testClearSongs(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);

        $playlist->addSong(Uuid::v4(), 0);
        $playlist->addSong(Uuid::v4(), 1);
        $playlist->addSong(Uuid::v4(), 2);

        $playlist->clearSongs();

        $this->assertSame([], $playlist->getSongs());
    }

    public function testReorderSongs(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);
        $song1 = Uuid::v4();
        $song2 = Uuid::v4();
        $song3 = Uuid::v4();

        $playlist->addSong($song1, 0);
        $playlist->addSong($song2, 1);
        $playlist->addSong($song3, 2);

        // Reverse the order
        $playlist->reorderSongs([$song3, $song2, $song1]);

        $songs = $playlist->getSongs();
        $this->assertCount(3, $songs);
        $this->assertTrue($songs[0]->getSongId()->equals($song3));
        $this->assertSame(0, $songs[0]->getPosition());
        $this->assertTrue($songs[1]->getSongId()->equals($song2));
        $this->assertSame(1, $songs[1]->getPosition());
        $this->assertTrue($songs[2]->getSongId()->equals($song1));
        $this->assertSame(2, $songs[2]->getPosition());
    }

    public function testReorderSongsThrowsOnMissingSong(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);
        $song1 = Uuid::v4();
        $song2 = Uuid::v4();
        $missingSong = Uuid::v4();

        $playlist->addSong($song1, 0);
        $playlist->addSong($song2, 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not in this playlist');

        $playlist->reorderSongs([$missingSong, $song1]);
    }

    public function testUpdateSmartRulesOnSmartPlaylist(): void
    {
        $playlist = Playlist::create(
            name: 'Smart Mix',
            userId: $this->userId,
            isSmart: true,
        );

        $newRules = [
            ['field' => 'genre', 'operator' => 'equals', 'value' => 'rock'],
            ['field' => 'year', 'operator' => 'greater_than', 'value' => 2020],
        ];

        $playlist->updateSmartRules($newRules);

        $this->assertSame($newRules, $playlist->getSmartRules());
    }

    public function testUpdateSmartRulesThrowsOnNonSmartPlaylist(): void
    {
        $playlist = Playlist::create(name: 'Regular Playlist', userId: $this->userId);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot set smart rules on a non-smart playlist');

        $playlist->updateSmartRules([['field' => 'genre', 'operator' => 'equals', 'value' => 'pop']]);
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $id = Uuid::v4();
        $publicId = PublicId::fromString(str_repeat('p', 21));
        $createdAt = new DateTimeImmutable('2025-03-01 00:00:00');
        $updatedAt = new DateTimeImmutable('2025-03-15 00:00:00');
        $songId = Uuid::v4();

        $playlist = Playlist::reconstitute(
            id: $id,
            publicId: $publicId,
            userId: $this->userId,
            name: 'Restored Playlist',
            description: 'From persistence',
            isPublic: true,
            isCollaborative: false,
            isSmart: false,
            smartRules: [],
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            songs: [new \App\Playlist\Domain\Model\PlaylistSong($songId, 0)],
        );

        $this->assertTrue($playlist->getId()->equals($id));
        $this->assertTrue($playlist->getPublicId()->equals($publicId));
        $this->assertSame('Restored Playlist', $playlist->getName());
        $this->assertSame('From persistence', $playlist->getDescription());
        $this->assertTrue($playlist->isPublic());
        $this->assertFalse($playlist->isCollaborative());
        $this->assertFalse($playlist->isSmart());
        $this->assertSame([], $playlist->getSmartRules());
        $this->assertCount(1, $playlist->getSongs());
        $this->assertTrue($playlist->getSongs()[0]->getSongId()->equals($songId));
        $this->assertEquals($createdAt, $playlist->getCreatedAt());
        $this->assertEquals($updatedAt, $playlist->getUpdatedAt());
    }

    public function testAddSongUpdatesTimestamp(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);
        $before = $playlist->getUpdatedAt();

        $playlist->addSong(Uuid::v4(), 0);

        $this->assertGreaterThan($before, $playlist->getUpdatedAt());
    }

    public function testRemoveSongUpdatesTimestamp(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);
        $songId = Uuid::v4();
        $playlist->addSong($songId, 0);
        $afterAdd = $playlist->getUpdatedAt();

        $playlist->removeSong($songId);

        $this->assertGreaterThan($afterAdd, $playlist->getUpdatedAt());
    }

    public function testClearSongsUpdatesTimestamp(): void
    {
        $playlist = Playlist::create(name: 'My Playlist', userId: $this->userId);
        $playlist->addSong(Uuid::v4(), 0);
        $afterAdd = $playlist->getUpdatedAt();

        \App\Shared\Infrastructure\Swoole\Async::sleep(1);

        $playlist->clearSongs();

        $this->assertGreaterThan($afterAdd, $playlist->getUpdatedAt());
    }
}
