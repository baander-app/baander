<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Interface\Controller\AdminAlbumController;
use App\Catalog\Interface\Controller\AdminSongController;
use App\Playlist\Application\Port\PlaylistDeletionPreviewPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class AdminDeletionPreviewTest extends TestCase
{
    public function testAlbumPreviewUsesAllSongIdsAndPreservesResponseShape(): void
    {
        $album = Album::create(new Uuid(), 'Album', 'album');
        $firstSong = Song::create($album->getId(), 'First', '/music/first.flac', 10, 'audio/flac');
        $secondSong = Song::create($album->getId(), 'Second', '/music/second.flac', 20, 'audio/flac');
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturn($album);
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects(self::once())->method('findByAlbum')
            ->with($album->getId(), 1000)
            ->willReturn([$firstSong, $secondSong]);

        $playlists = $this->createMock(PlaylistDeletionPreviewPortInterface::class);
        $playlists->expects(self::once())->method('findContainingSongs')
            ->with([$firstSong->getId(), $secondSong->getId()])
            ->willReturn([
                ['uuid' => (new Uuid())->toString(), 'name' => 'Favorites'],
                ['uuid' => (new Uuid())->toString(), 'name' => 'Favorites'],
            ]);

        $response = (new AdminAlbumController($albums, $songs, $playlists))
            ->deletePreview($album->getPublicId()->toString());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['id' => $album->getPublicId()->toString(), 'title' => 'Album', 'songCount' => 2],
            $data['album'],
        );
        self::assertSame(['count' => 2, 'totalSize' => 30], $data['files']);
        self::assertNull($data['coverImage']);
        self::assertSame(['playlists' => 2, 'playlistNames' => ['Favorites', 'Favorites']], $data['affected']);
    }

    public function testSongPreviewPassesSongIdentityAndRetainsMissingAlbum(): void
    {
        $song = Song::create(new Uuid(), 'Song', '/music/song.flac', 10, 'audio/flac');
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturn($song);
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuid')->willReturn(null);
        $playlists = $this->createMock(PlaylistDeletionPreviewPortInterface::class);
        $playlists->expects(self::once())->method('findContainingSongs')
            ->with([$song->getId()])
            ->willReturn([]);

        $response = (new AdminSongController($songs, $albums, $playlists))
            ->deletePreview($song->getPublicId()->toString());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($data['song']['album']);
        self::assertSame(['path' => '/music/song.flac', 'size' => 10], $data['file']);
        self::assertSame(['playlists' => 0, 'playlistNames' => []], $data['affected']);
    }

    public function testMissingSongDoesNotLookUpPlaylists(): void
    {
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturn(null);
        $playlists = $this->createMock(PlaylistDeletionPreviewPortInterface::class);
        $playlists->expects(self::never())->method('findContainingSongs');

        $response = (new AdminSongController($songs, $this->createStub(AlbumPortInterface::class), $playlists))
            ->deletePreview((new PublicId())->toString());

        self::assertSame(404, $response->getStatusCode());
    }
}
