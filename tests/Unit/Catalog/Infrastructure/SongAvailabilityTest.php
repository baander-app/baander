<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Infrastructure\SongAvailability;
use App\Library\Application\Port\LibraryFileAvailabilityInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class SongAvailabilityTest extends TestCase
{
    public function testBatchesAlbumsAndPathsByLibraryWithoutConfusingSharedPaths(): void
    {
        $library = new Uuid();
        $otherLibrary = new Uuid();
        $album = Album::create($library, 'Album', 'studio');
        $secondAlbum = Album::create($library, 'Second album', 'studio');
        $otherAlbum = Album::create($otherLibrary, 'Other album', 'studio');
        $songs = [
            Song::create($album->getId(), 'Available', '/music/shared.flac', 1, 'audio/flac'),
            Song::create($album->getId(), 'Unavailable', '/music/absent.flac', 1, 'audio/flac'),
            Song::create($secondAlbum->getId(), 'Unindexed', '/music/unindexed.flac', 1, 'audio/flac'),
            Song::create($otherAlbum->getId(), 'Same path elsewhere', '/music/shared.flac', 1, 'audio/flac'),
            Song::create(new Uuid(), 'Missing album', '/music/orphan.flac', 1, 'audio/flac'),
        ];
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects(self::once())->method('findByUuids')
            ->with([$album->getId(), $secondAlbum->getId(), $otherAlbum->getId(), $songs[4]->getAlbumId()])
            ->willReturn([
                $album->getId()->toString() => $album,
                $secondAlbum->getId()->toString() => $secondAlbum,
                $otherAlbum->getId()->toString() => $otherAlbum,
            ]);
        $files = $this->createMock(LibraryFileAvailabilityInterface::class);
        $files->expects(self::exactly(2))->method('findAvailabilityByPaths')
            ->willReturnCallback(static function (Uuid $libraryId, array $paths) use ($library, $otherLibrary): array {
                if ($libraryId->equals($library)) {
                    self::assertSame(['/music/shared.flac', '/music/absent.flac', '/music/unindexed.flac'], $paths);
                    return ['/music/shared.flac' => true, '/music/absent.flac' => false];
                }
                self::assertTrue($libraryId->equals($otherLibrary));
                self::assertSame(['/music/shared.flac'], $paths);
                return ['/music/shared.flac' => false];
            });

        $actual = (new SongAvailability($albums, $files))->forSongs($songs);

        self::assertSame([true, false, false, false, false], array_map(
            static fn (Song $song): bool => $actual[$song->getId()->toString()], $songs,
        ));
    }

    public function testNoSongsRequireNoLookup(): void
    {
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects(self::never())->method('findByUuids');
        $files = $this->createMock(LibraryFileAvailabilityInterface::class);
        $files->expects(self::never())->method('findAvailabilityByPaths');

        self::assertSame([], (new SongAvailability($albums, $files))->forSongs([]));
    }
}
