<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\DiscoveredFileObservations;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[AllowMockObjectsWithoutExpectations]
final class FilesDiscoveredCurrentObservationTest extends TestCase
{
    use DiscoveredFileObservations;

    private AlbumPortInterface&MockObject $albums;
    private SongPortInterface&MockObject $songs;
    private MoviePortInterface&MockObject $movies;
    private VideoRepositoryInterface&MockObject $videos;
    private MetadataContentReaderPortInterface&MockObject $metadata;
    private LibraryMediaFilesInterface&MockObject $media;
    private LyricsFetchRequestInterface&MockObject $lyrics;
    private AlbumMetadataSyncRequestInterface&MockObject $sync;
    private MessageBusInterface&MockObject $bus;
    private PassThroughTransaction $transaction;
    private Uuid $libraryId;

    protected function setUp(): void
    {
        $this->libraryId = Uuid::v7();
        $this->albums = $this->createMock(AlbumPortInterface::class);
        $this->songs = $this->createMock(SongPortInterface::class);
        $this->movies = $this->createMock(MoviePortInterface::class);
        $this->videos = $this->createMock(VideoRepositoryInterface::class);
        $this->metadata = $this->createMock(MetadataContentReaderPortInterface::class);
        $this->media = $this->observedMediaFiles($this->createMock(LibraryMediaFilesInterface::class));
        $this->lyrics = $this->createMock(LyricsFetchRequestInterface::class);
        $this->sync = $this->createMock(AlbumMetadataSyncRequestInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->transaction = new PassThroughTransaction();
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function staleDiscoveries(): iterable
    {
        foreach (['music', 'movie'] as $type) {
            yield $type . ' replacement' => [$type, 'replacement'];
            yield $type . ' absent observation' => [$type, null];
        }
    }

    #[DataProvider('staleDiscoveries')]
    public function testIneligibleDiscoveryDoesNoPreparationOrCatalogWork(string $type, ?string $currentHash): void
    {
        $file = $this->file('source', $type === 'music' ? 'flac' : 'mkv');
        $this->availableHashes = $currentHash === null ? [] : [$file->absolutePath => $currentHash];
        $this->media->expects($this->never())->method('markMissingUnavailable');
        $this->metadata->expects($this->never())->method('readMetadata');
        $this->albums->expects($this->never())->method('persist');
        $this->songs->expects($this->never())->method('persist');
        $this->movies->expects($this->never())->method('persist');
        $this->videos->expects($this->never())->method('findByHash');
        $this->videos->expects($this->never())->method('save');
        $this->lyrics->expects($this->never())->method('requestFetch');
        $this->sync->expects($this->never())->method('requestSync');
        $this->bus->expects($this->never())->method('dispatch');

        $this->handler()($this->message($type, $file));

        self::assertSame(0, $this->transaction->runs);
    }

    public function testSupersededAlbumAnchorIsReselectedAndRemainingTrackImports(): void
    {
        $first = $this->file('first');
        $second = $this->file('second');
        $this->metadata->method('readMetadata')->willReturnCallback(function (string $path) use ($first): ExtractedMetadata {
            self::assertFalse($this->transaction->active, 'Metadata reads must not hold import locks.');
            if ($path === $first->absolutePath) {
                $this->availableHashes[$path] = 'replacement';
                return new ExtractedMetadata(title: 'Stale song', album: 'Stale album');
            }
            return new ExtractedMetadata(title: 'Current song', album: 'Current album');
        });
        $album = null;
        $this->albums->expects($this->once())->method('persist')->willReturnCallback(function (Album $created) use (&$album): void {
            self::assertTrue($this->transaction->active);
            self::assertSame('Current album', $created->getTitle());
            $created->setCoverImage(Uuid::v7());
            $album = $created;
        });
        $this->songs->expects($this->once())->method('persist')->willReturnCallback(function (Song $song) use ($second, &$album): void {
            self::assertTrue($this->transaction->active);
            self::assertSame($second->absolutePath, $song->getPath());
            self::assertSame('Current song', $song->getTitle());
            self::assertTrue($song->getAlbumId()->equals($album->getId()));
        });
        $this->sync->expects($this->once())->method('requestSync');
        $this->lyrics->expects($this->once())->method('requestFetch');
        $this->bus->expects($this->never())->method('dispatch');

        $this->handler()($this->message('music', $first, $second));

    }

    public function testEverySupersededAnchorIsDiscardedAndReselectionTerminates(): void
    {
        $first = $this->file('first');
        $second = $this->file('second');
        $this->metadata->expects($this->exactly(2))->method('readMetadata')->willReturnCallback(function (string $path): ExtractedMetadata {
            unset($this->availableHashes[$path]);
            return new ExtractedMetadata(album: 'Superseded album');
        });
        $this->albums->expects($this->never())->method('persist');
        $this->songs->expects($this->never())->method('persist');
        $this->sync->expects($this->never())->method('requestSync');
        $this->lyrics->expects($this->never())->method('requestFetch');
        $this->bus->expects($this->never())->method('dispatch');

        $this->handler()($this->message('music', $first, $second));
    }

    public function testObservationChangedDuringSongMetadataReadPreventsSongAndLyricsWrites(): void
    {
        $file = $this->file('source');
        $album = Album::create($this->libraryId, 'Album', 'studio');
        $album->setCoverImage(Uuid::v7());
        $this->albums->method('findByTitleAndLibrary')->willReturn($album);
        $reads = 0;
        $this->metadata->method('readMetadata')->willReturnCallback(function (string $path) use (&$reads): ExtractedMetadata {
            self::assertFalse($this->transaction->active);
            if (++$reads === 2) {
                $this->availableHashes[$path] = 'replacement';
            }
            return new ExtractedMetadata(title: 'Song', album: 'Album');
        });
        $this->songs->expects($this->never())->method('persist');
        $this->lyrics->expects($this->never())->method('requestFetch');
        $this->sync->expects($this->never())->method('requestSync');
        $this->bus->expects($this->never())->method('dispatch');

        $this->handler()($this->message('music', $file));

        self::assertSame(2, $reads);
    }

    public function testSupersededVideoCannotCreateMovieOrLinkAnExistingVideo(): void
    {
        $file = $this->file('source', 'mkv');
        $video = Video::create($file->absolutePath, $file->hash, 1, 100, 100, 1000, 24);
        $this->videos->expects($this->once())->method('findByHash')->willReturnCallback(function () use ($file, $video): Video {
            self::assertFalse($this->transaction->active);
            $this->availableHashes[$file->absolutePath] = 'replacement';
            return $video;
        });
        $this->movies->expects($this->never())->method('findByTitleAndLibrary');
        $this->movies->expects($this->never())->method('persist');
        $this->videos->expects($this->never())->method('save');

        $this->handler()($this->message('movie', $file));
    }

    private function file(string $name, string $extension = 'flac'): DiscoveredFile
    {
        return $this->observedFile(new DiscoveredFile('/media/Album/' . $name . '.' . $extension, 'Album/' . $name . '.' . $extension, $extension, 100, 10, 'hash-' . $name));
    }

    private function message(string $type, DiscoveredFile ...$files): FilesDiscovered
    {
        return new FilesDiscovered($this->libraryId, $type, '/media/Album', array_values($files));
    }

    private function handler(): FilesDiscoveredHandler
    {
        return new FilesDiscoveredHandler($this->albums, $this->createStub(GenrePortInterface::class), $this->songs,
            $this->movies, $this->videos, $this->metadata, new FFprobeAdapter(new JsonEncoder()), $this->bus,
            $this->lyrics, $this->sync, new NullLogger(), $this->media, $this->transaction,
        );
    }
}
