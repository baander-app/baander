<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Domain\Model;

use App\Playlist\Domain\Model\PlaylistSong;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class PlaylistSongTest extends TestCase
{
    public function testCreatePlaylistSong(): void
    {
        $songId = Uuid::v4();
        $playlistSong = new PlaylistSong($songId, 0);

        $this->assertTrue($playlistSong->getSongId()->equals($songId));
        $this->assertSame(0, $playlistSong->getPosition());
    }

    public function testCreatePlaylistSongWithPosition(): void
    {
        $songId = Uuid::v4();
        $playlistSong = new PlaylistSong($songId, 5);

        $this->assertTrue($playlistSong->getSongId()->equals($songId));
        $this->assertSame(5, $playlistSong->getPosition());
    }

    public function testGetSongIdReturnsUuid(): void
    {
        $songId = Uuid::v4();
        $playlistSong = new PlaylistSong($songId, 0);

        $this->assertInstanceOf(Uuid::class, $playlistSong->getSongId());
    }

    public function testGetPositionReturnsInt(): void
    {
        $playlistSong = new PlaylistSong(Uuid::v4(), 42);

        $this->assertSame(42, $playlistSong->getPosition());
    }

    public function testTwoSongsWithSameIdAreEqual(): void
    {
        $songId = Uuid::v4();
        $song1 = new PlaylistSong($songId, 0);
        $song2 = new PlaylistSong($songId, 5);

        $this->assertTrue($song1->getSongId()->equals($song2->getSongId()));
        $this->assertNotSame($song1->getPosition(), $song2->getPosition());
    }

    public function testTwoSongsWithDifferentIdsAreNotEqual(): void
    {
        $song1 = new PlaylistSong(Uuid::v4(), 0);
        $song2 = new PlaylistSong(Uuid::v4(), 0);

        $this->assertFalse($song1->getSongId()->equals($song2->getSongId()));
    }

    public function testZeroPosition(): void
    {
        $playlistSong = new PlaylistSong(Uuid::v4(), 0);

        $this->assertSame(0, $playlistSong->getPosition());
    }

    public function testLargePosition(): void
    {
        $playlistSong = new PlaylistSong(Uuid::v4(), 9999);

        $this->assertSame(9999, $playlistSong->getPosition());
    }
}
