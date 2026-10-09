<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\QueryHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Query\Album\GetAlbumDeletePreviewQuery;
use App\Catalog\Application\Query\Song\GetSongDeletePreviewQuery;
use App\Catalog\Application\QueryHandler\Album\GetAlbumDeletePreviewHandler;
use App\Catalog\Application\QueryHandler\Song\GetSongDeletePreviewHandler;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Interface\Resource\AlbumDeletePreviewResource;
use App\Catalog\Interface\Resource\SongDeletePreviewResource;
use App\Library\Application\Port\LibraryMediaFileCheck;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryMediaFileVerdict;
use App\Playlist\Application\Port\PlaylistDeletionPreviewPortInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class DeletePreviewHandlersTest extends TestCase
{
    public function testTheAlbumPreviewCountsEverySongAndKeepsItsResponseShape(): void
    {
        $album = Album::create(new Uuid(), 'Album', 'album');
        $first = Song::create($album->getId(), 'First', '/music/first.flac', 10, 'audio/flac');
        $second = Song::create($album->getId(), 'Second', '/music/second.flac', 20, 'audio/flac');
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturn($album);
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects(self::never())->method('findByAlbum');
        $songs->expects(self::once())->method('findByAlbumSortedByTrack')->with($album->getId())->willReturn([$first, $second]);
        $playlists = $this->createMock(PlaylistDeletionPreviewPortInterface::class);
        $playlists->expects(self::once())->method('findContainingSongs')
            ->with([$first->getId(), $second->getId()])
            ->willReturn([
                ['uuid' => (new Uuid())->toString(), 'name' => 'Favorites'],
                ['uuid' => (new Uuid())->toString(), 'name' => 'Favorites'],
            ]);
        $files = $this->createMock(LibraryMediaFilesInterface::class);
        $files->expects(self::never())->method('inspect');

        $preview = (new GetAlbumDeletePreviewHandler($albums, $songs, $playlists, $files))(new GetAlbumDeletePreviewQuery($album->getPublicId()->toString()));

        self::assertSame([
            'album' => ['id' => $album->getPublicId()->toString(), 'title' => 'Album', 'songCount' => 2],
            'files' => ['count' => 2, 'totalSize' => 30],
            'coverImage' => null,
            'affected' => ['playlists' => 2, 'playlistNames' => ['Favorites', 'Favorites']],
            'fileDeletion' => null,
        ], AlbumDeletePreviewResource::from($preview));
    }

    public function testTheAlbumPreviewChecksEverySongFileInTheAlbumsLibraryWhenAsked(): void
    {
        $album = Album::create(new Uuid(), 'Album', 'album');
        $inside = Song::create($album->getId(), 'Inside', '/music/inside.flac', 10, 'audio/flac');
        $outside = Song::create($album->getId(), 'Outside', '/elsewhere/outside.flac', 10, 'audio/flac');
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturn($album);
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByAlbumSortedByTrack')->willReturn([$inside, $outside]);
        $files = $this->createMock(LibraryMediaFilesInterface::class);
        $files->expects(self::once())->method('inspect')
            ->with($album->getLibraryId(), ['/music/inside.flac', '/elsewhere/outside.flac'])
            ->willReturn(new LibraryMediaFileInspection($album->getLibraryId(), '/music', [
                new LibraryMediaFileCheck('/music/inside.flac', LibraryMediaFileVerdict::Deletable),
                new LibraryMediaFileCheck('/elsewhere/outside.flac', LibraryMediaFileVerdict::OutsideRoot),
            ], false));

        $preview = (new GetAlbumDeletePreviewHandler($albums, $songs, $this->createStub(PlaylistDeletionPreviewPortInterface::class), $files))(
            new GetAlbumDeletePreviewQuery($album->getPublicId()->toString(), deleteFiles: true),
        );

        self::assertSame([
            'allowed' => false,
            'scanInProgress' => false,
            'files' => [
                ['path' => '/music/inside.flac', 'verdict' => 'deletable', 'directory' => null],
                ['path' => '/elsewhere/outside.flac', 'verdict' => 'outside_root', 'directory' => null],
            ],
        ], AlbumDeletePreviewResource::from($preview)['fileDeletion']);
    }

    public function testTheSongPreviewKeepsAMissingAlbumAsNull(): void
    {
        $song = Song::create(new Uuid(), 'Song', '/music/song.flac', 10, 'audio/flac');
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturn($song);
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuid')->willReturn(null);
        $playlists = $this->createMock(PlaylistDeletionPreviewPortInterface::class);
        $playlists->expects(self::once())->method('findContainingSongs')->with([$song->getId()])->willReturn([]);

        $data = SongDeletePreviewResource::from(
            (new GetSongDeletePreviewHandler($songs, $albums, $playlists, $this->createStub(LibraryMediaFilesInterface::class)))(
                new GetSongDeletePreviewQuery($song->getPublicId()->toString()),
            ),
        );

        self::assertNull($data['song']['album']);
        self::assertSame(['path' => '/music/song.flac', 'size' => 10], $data['file']);
        self::assertSame(['playlists' => 0, 'playlistNames' => []], $data['affected']);
        self::assertNull($data['fileDeletion']);
    }

    public function testTheSongPreviewChecksTheFileInItsAlbumsLibraryWhenAsked(): void
    {
        $album = Album::create(new Uuid(), 'Album', 'album');
        $song = Song::create($album->getId(), 'Song', '/music/song.flac', 10, 'audio/flac');
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturn($song);
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuid')->willReturn($album);
        $files = $this->createMock(LibraryMediaFilesInterface::class);
        $files->expects(self::once())->method('inspect')
            ->with($album->getLibraryId(), ['/music/song.flac'])
            ->willReturn(new LibraryMediaFileInspection($album->getLibraryId(), '/music', [
                new LibraryMediaFileCheck('/music/song.flac', LibraryMediaFileVerdict::Deletable),
            ], true));
        $playlists = $this->createStub(PlaylistDeletionPreviewPortInterface::class);
        $playlists->method('findContainingSongs')->willReturn([]);

        $data = SongDeletePreviewResource::from(
            (new GetSongDeletePreviewHandler($songs, $albums, $playlists, $files))(new GetSongDeletePreviewQuery($song->getPublicId()->toString(), deleteFile: true)),
        );

        self::assertSame(['id' => $album->getPublicId()->toString(), 'title' => 'Album'], $data['song']['album']);
        self::assertSame(
            ['allowed' => false, 'scanInProgress' => true, 'files' => [['path' => '/music/song.flac', 'verdict' => 'deletable', 'directory' => null]]],
            $data['fileDeletion'],
        );
    }

    public function testAMissingSongDoesNotLookUpPlaylists(): void
    {
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturn(null);
        $playlists = $this->createMock(PlaylistDeletionPreviewPortInterface::class);
        $playlists->expects(self::never())->method('findContainingSongs');

        $this->expectException(NotFoundException::class);
        (new GetSongDeletePreviewHandler($songs, $this->createStub(AlbumPortInterface::class), $playlists, $this->createStub(LibraryMediaFilesInterface::class)))(
            new GetSongDeletePreviewQuery((new PublicId())->toString()),
        );
    }
}
