<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\Album\DeleteAlbumCommand;
use App\Catalog\Application\Command\Artist\DeleteArtistCommand;
use App\Catalog\Application\Command\Movie\DeleteMovieCommand;
use App\Catalog\Application\Command\Song\DeleteSongCommand;
use App\Catalog\Application\CommandHandler\Album\DeleteAlbumHandler;
use App\Catalog\Application\CommandHandler\Artist\DeleteArtistHandler;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Application\CommandHandler\Movie\DeleteMovieHandler;
use App\Catalog\Application\CommandHandler\Song\DeleteSongHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Port\LibraryMediaFileCheck;
use App\Library\Application\Port\LibraryMediaFileDeletionResult;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFileLeft;
use App\Library\Application\Port\LibraryMediaFileLeftReason;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryMediaFileVerdict;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The delete use cases check files before the transaction, delete rows and file index rows in
 * it, and unlink files and discard covers only after it commits.
 */
final class CatalogDeleteHandlersTest extends TestCase
{
    /** @var list<string> what happened, in order */
    private array $log = [];
    private ?\Throwable $prepareFailure = null;
    /** @var list<LibraryMediaFileLeft> */
    private array $leftOnDisk = [];
    /** @var list<array{Uuid, list<string>}> */
    private array $prepared = [];
    private ?Image $cover = null;

    public function testAnAlbumDeleteWithFilesChecksFirstThenDeletesRowsAndIndexRowsTogetherAndUnlinksAfterTheCommit(): void
    {
        $album = $this->albumWithCover();
        $songs = [$this->song($album, '/music/a.flac'), $this->song($album, '/music/b.flac')];

        $result = $this->albumHandler($album, $songs)(new DeleteAlbumCommand($album->getPublicId()->toString(), deleteFiles: true));

        self::assertSame(
            ['prepare', 'begin', 'delete album', 'delete index rows', 'commit', 'delete image', 'delete image file', 'delete files'],
            $this->log,
        );
        self::assertEquals([[$album->getLibraryId(), ['/music/a.flac', '/music/b.flac']]], $this->prepared);
        self::assertSame(['albums' => 1, 'songs' => 2, 'coverImages' => 1], $result->deleted);
        self::assertSame(['/music/a.flac', '/music/b.flac'], $result->removed);
        self::assertFalse($result->leftAny());
    }

    public function testARefusedFileCheckChangesNothing(): void
    {
        $album = $this->albumWithCover();
        $this->prepareFailure = new InvalidInputException('These files lie outside the root of the library.');

        try {
            $this->albumHandler($album, [$this->song($album, '/elsewhere/a.flac')])(new DeleteAlbumCommand($album->getPublicId()->toString(), deleteFiles: true));
            self::fail('The refused check must reach the caller.');
        } catch (InvalidInputException) {
        }

        self::assertSame(['prepare'], $this->log);
    }

    public function testAFileLeftOnDiskIsReportedAfterTheRowsAreDeleted(): void
    {
        $album = $this->albumWithCover();
        $this->leftOnDisk = [new LibraryMediaFileLeft('/music/a.flac', LibraryMediaFileLeftReason::UnlinkFailed, 'Permission denied')];

        $result = $this->albumHandler($album, [$this->song($album, '/music/a.flac')])(new DeleteAlbumCommand($album->getPublicId()->toString(), deleteFiles: true));

        self::assertContains('commit', $this->log);
        self::assertTrue($result->leftAny());
        self::assertSame([['path' => '/music/a.flac', 'reason' => 'unlink_failed', 'detail' => 'Permission denied']], $result->left);
        self::assertSame([], $result->removed);
    }

    public function testAnAlbumDeleteWithoutFilesLeavesTheMediaFilesAloneAndKeepCoverKeepsTheImage(): void
    {
        $album = $this->albumWithCover();

        $result = $this->albumHandler($album, [$this->song($album, '/music/a.flac')])(
            new DeleteAlbumCommand($album->getPublicId()->toString(), deleteFiles: false, deleteCover: false),
        );

        self::assertSame(['begin', 'delete album', 'commit'], $this->log);
        self::assertSame(['albums' => 1, 'songs' => 1, 'coverImages' => 0], $result->deleted);
        self::assertSame([[], [], []], [$result->removed, $result->missing, $result->left]);
    }

    public function testAnAlbumThatDoesNotExistOrAMalformedIdChangesNothing(): void
    {
        $album = $this->albumWithCover();
        $handler = $this->albumHandler(null, []);

        $this->expectOutcome(NotFoundException::class, fn () => $handler(new DeleteAlbumCommand($album->getPublicId()->toString(), deleteFiles: true)));
        $this->expectOutcome(InvalidInputException::class, fn () => $handler(new DeleteAlbumCommand('not a public id')));
        self::assertSame([], $this->log);
    }

    public function testASongDeleteWithItsFileChecksThePathAgainstItsAlbumsLibrary(): void
    {
        $album = Album::create(new Uuid(), 'Album', 'album');
        $song = $this->song($album, '/music/a.flac');
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturn($song);
        $songs->method('delete')->willReturnCallback(function (): void {
            $this->log[] = 'delete song';
        });
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuid')->willReturn($album);

        $result = (new DeleteSongHandler($songs, $albums, $this->mediaFiles(), $this->transaction()))(
            new DeleteSongCommand($song->getPublicId()->toString(), deleteFile: true),
        );

        self::assertSame(['prepare', 'begin', 'delete song', 'delete index rows', 'commit', 'delete files'], $this->log);
        self::assertEquals([[$album->getLibraryId(), ['/music/a.flac']]], $this->prepared);
        self::assertSame(['songs' => 1], $result->deleted);
        self::assertSame(['/music/a.flac'], $result->removed);
    }

    public function testAMovieDeleteRemovesTheVideosNoOtherMovieUsesInTheSameTransaction(): void
    {
        $movie = Movie::create(new Uuid(), 'Movie');
        $first = new Uuid();
        $second = new Uuid();
        $movie->getState()->videoIds = [$first->toString(), $second->toString()];
        $movies = $this->createStub(MoviePortInterface::class);
        $movies->method('findByPublicId')->willReturn($movie);
        $movies->method('delete')->willReturnCallback(function (): void {
            $this->log[] = 'delete movie';
        });
        $videos = $this->createMock(VideoRepositoryInterface::class);
        $videos->expects(self::once())->method('deleteUnlinked')
            ->with(self::callback(static fn (array $ids): bool => $ids == [$first, $second]))
            ->willReturnCallback(function (): int {
                $this->log[] = 'delete unlinked videos';

                return 1;
            });

        $result = (new DeleteMovieHandler($movies, $videos, $this->transaction()))(new DeleteMovieCommand($movie->getPublicId()->toString()));

        self::assertSame(['begin', 'delete movie', 'delete unlinked videos', 'commit'], $this->log);
        self::assertSame(['movies' => 1, 'videos' => 1], $result->deleted);
    }

    public function testAnArtistDeleteDiscardsItsCoverAfterTheArtistIsDeleted(): void
    {
        $artist = Artist::create('Artist');
        $this->cover = $this->image();
        $artist->setCoverImage($this->cover->getId());
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('findByPublicId')->willReturn($artist);
        $artists->method('delete')->willReturnCallback(function (): void {
            $this->log[] = 'delete artist';
        });

        $result = (new DeleteArtistHandler($artists, $this->discarder()))(new DeleteArtistCommand($artist->getPublicId()->toString()));

        self::assertSame(['delete artist', 'delete image', 'delete image file'], $this->log);
        self::assertSame(['artists' => 1, 'coverImages' => 1], $result->deleted);
    }

    public function testAnArtistWithoutACoverDeletesNoImage(): void
    {
        $artist = Artist::create('Artist');
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('findByPublicId')->willReturn($artist);

        $result = (new DeleteArtistHandler($artists, $this->discarder()))(new DeleteArtistCommand($artist->getPublicId()->toString()));

        self::assertSame([], $this->log);
        self::assertSame(['artists' => 1, 'coverImages' => 0], $result->deleted);
    }

    /** @param list<Song> $songs */
    private function albumHandler(?Album $album, array $songs): DeleteAlbumHandler
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturn($album);
        $albums->method('delete')->willReturnCallback(function (Album $deleted, bool $deleteCover): void {
            self::assertFalse($deleteCover, 'the handler discards the cover itself, after the commit');
            $this->log[] = 'delete album';
        });
        $songPort = $this->createStub(SongPortInterface::class);
        $songPort->method('findByAlbumSortedByTrack')->willReturn($songs);

        return new DeleteAlbumHandler($albums, $songPort, $this->mediaFiles(), $this->transaction(), $this->discarder());
    }

    private function albumWithCover(): Album
    {
        $album = Album::create(new Uuid(), 'Album', 'album');
        $this->cover = $this->image();
        $album->setCoverImage($this->cover->getId());

        return $album;
    }

    private function song(Album $album, string $path): Song
    {
        return Song::create($album->getId(), basename($path), $path, 7, 'audio/flac');
    }

    private function image(): Image
    {
        return Image::create(
            path: 'images/cover.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 10,
            width: 1,
            height: 1,
            imageableType: 'album',
        );
    }

    private function discarder(): CoverImageDiscarder
    {
        $images = $this->createStub(ImagePortInterface::class);
        $images->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?Image => $this->cover !== null && $this->cover->getId()->equals($id) ? $this->cover : null);
        $images->method('delete')->willReturnCallback(function (): void {
            $this->log[] = 'delete image';
        });
        $storage = $this->createStub(StoragePortInterface::class);
        $storage->method('delete')->willReturnCallback(function (): void {
            $this->log[] = 'delete image file';
        });

        return new CoverImageDiscarder($images, $storage, new NullLogger());
    }

    private function transaction(): TransactionPortInterface
    {
        return new class($this) implements TransactionPortInterface {
            public function __construct(private readonly CatalogDeleteHandlersTest $test)
            {
            }

            public function run(callable $operation): mixed
            {
                $this->test->record('begin');
                $result = $operation();
                $this->test->record('commit');

                return $result;
            }
        };
    }

    private function mediaFiles(): LibraryMediaFilesInterface
    {
        return new class($this) implements LibraryMediaFilesInterface {
            public function __construct(private readonly CatalogDeleteHandlersTest $test)
            {
            }

            public function inspect(Uuid $libraryId, array $paths): LibraryMediaFileInspection
            {
                throw new \LogicException('A delete does not inspect.');
            }

            public function prepareDeletion(Uuid $libraryId, array $paths): LibraryMediaFileInspection
            {
                return $this->test->recordPrepare($libraryId, $paths);
            }

            public function deleteIndexRows(LibraryMediaFileInspection $deletion): void
            {
                $this->test->record('delete index rows');
            }

            public function deleteFiles(LibraryMediaFileInspection $deletion): LibraryMediaFileDeletionResult
            {
                return $this->test->recordDeleteFiles($deletion);
            }
        };
    }

    /**
     * @internal for the media files fake
     * @param list<string> $paths
     */
    public function recordPrepare(Uuid $libraryId, array $paths): LibraryMediaFileInspection
    {
        $this->log[] = 'prepare';
        if ($this->prepareFailure !== null) {
            throw $this->prepareFailure;
        }
        $this->prepared[] = [$libraryId, $paths];

        return new LibraryMediaFileInspection(
            $libraryId,
            '/music',
            array_map(static fn (string $path): LibraryMediaFileCheck => new LibraryMediaFileCheck($path, LibraryMediaFileVerdict::Deletable), $paths),
            false,
            true,
        );
    }

    /** @internal for the transaction and media files fakes */
    public function record(string $event): void
    {
        $this->log[] = $event;
    }

    /** @internal for the media files fake */
    public function recordDeleteFiles(LibraryMediaFileInspection $deletion): LibraryMediaFileDeletionResult
    {
        $this->log[] = 'delete files';
        $left = array_map(static fn (LibraryMediaFileLeft $file): string => $file->path, $this->leftOnDisk);

        return new LibraryMediaFileDeletionResult(
            array_values(array_diff($deletion->paths(), $left)),
            [],
            $this->leftOnDisk,
        );
    }

    /**
     * @param class-string<\Throwable> $expected
     * @param callable(): mixed        $action
     */
    private function expectOutcome(string $expected, callable $action): void
    {
        try {
            $action();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($expected, $exception);

            return;
        }
        self::fail(sprintf('Expected %s.', $expected));
    }
}
