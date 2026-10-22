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
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFileCheck;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryMediaFileVerdict;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Tests\Fixtures\Catalog\DiscoveredFileObservations;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class FilesDiscoveredRenameTest extends TestCase
{
    use DiscoveredFileObservations;

    #[DataProvider('renameCases')]
    public function testOnlyEligibleSourcesAreReconciled(string $case, bool $expectedWrite): void
    {
        $libraryId = Uuid::v7();
        $album = Album::create($libraryId, 'Album', 'studio');
        $album->setCoverImage(Uuid::v7());
        $oldPath = $case === 'same path' ? '/music/Album/new.flac' : '/music/Album/old.flac';
        if ($case === 'different parent') {
            $oldPath = '/music/Other/old.flac';
        }
        $song = Song::create($case === 'different album' ? Uuid::v7() : $album->getId(), 'Edited title', $oldPath, 100, 'audio/flac', hash: 'hash');
        $song->lockField('title');
        $fresh = Song::reconstitute(clone $song->getState());
        $fresh->updateMetadata(comment: 'Concurrent edit');
        if ($case === 'changed path') {
            $fresh->getState()->path = '/music/Album/concurrent.flac';
        } elseif ($case === 'changed hash') {
            $fresh->updateAudioMetadata(hash: 'changed');
        } elseif ($case === 'changed album') {
            $fresh->moveToAlbum(Uuid::v7());
        }
        $songs = $this->createMock(SongPortInterface::class);
        $songs->method('findByHash')->willReturnCallback(function () use ($song, $case): Song {
            if ($case === 'superseded during lookup') {
                $this->availableHashes['/music/Album/new.flac'] = 'replacement';
            }

            return $song;
        });
        $songs->method('findFreshByUuid')->willReturn($case === 'deleted song' ? null : $fresh);
        $songs->expects($expectedWrite ? $this->once() : $this->never())->method('persist')->with($fresh);
        $songs->expects($this->never())->method('linkArtistToSong');
        $media = $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class));
        $media->method('inspect')->willReturn(new LibraryMediaFileInspection($libraryId, '/music', [
            new LibraryMediaFileCheck($oldPath, $case === 'old present' ? LibraryMediaFileVerdict::Deletable : LibraryMediaFileVerdict::Missing),
            new LibraryMediaFileCheck('/music/Album/new.flac', match ($case) {
                'new missing' => LibraryMediaFileVerdict::Missing,
                'outside root' => LibraryMediaFileVerdict::OutsideRoot,
                'read only' => LibraryMediaFileVerdict::DirectoryNotWritable,
                default => LibraryMediaFileVerdict::Deletable,
            }),
        ], $case === 'active scan', $case !== 'root unavailable'));
        $handler = $this->handler($album, $songs, $media);
        $message = $this->message($libraryId);
        if ($case === 'superseded observation') {
            $this->availableHashes['/music/Album/new.flac'] = 'replacement';
        }

        $handler($message);
        $handler($message);

        self::assertSame($expectedWrite ? '/music/Album/new.flac' : ($case === 'changed path' ? '/music/Album/concurrent.flac' : $oldPath), $fresh->getPath());
        self::assertSame('Concurrent edit', $fresh->getComment());
        self::assertSame('Edited title', $fresh->getTitle());
        self::assertSame($song->getId(), $fresh->getId());
        self::assertSame($song->getPublicId(), $fresh->getPublicId());
        self::assertTrue($fresh->isFieldLocked('title'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function renameCases(): iterable
    {
        foreach (['eligible', 'read only', 'same path', 'different parent', 'different album', 'old present', 'new missing', 'outside root', 'root unavailable', 'active scan', 'changed path', 'changed hash', 'changed album', 'deleted song', 'superseded observation', 'superseded during lookup'] as $case) {
            yield $case => [$case, in_array($case, ['eligible', 'read only', 'active scan'], true)];
        }
    }

    public function testASecondRenameKeepsTheOriginalSong(): void
    {
        $libraryId = Uuid::v7();
        $album = Album::create($libraryId, 'Album', 'studio');
        $album->setCoverImage(Uuid::v7());
        $song = Song::create($album->getId(), 'Song', '/music/Album/old.flac', 100, 'audio/flac', hash: 'hash');
        $songs = $this->createMock(SongPortInterface::class);
        $songs->method('findByHash')->willReturn($song);
        $songs->method('findFreshByUuid')->willReturn($song);
        $songs->expects($this->exactly(2))->method('persist')->with($song);
        $media = $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class));
        $media->method('inspect')->willReturnCallback(static fn (Uuid $id, array $paths): LibraryMediaFileInspection => new LibraryMediaFileInspection($id, '/music', [
            new LibraryMediaFileCheck($paths[0], LibraryMediaFileVerdict::Missing),
            new LibraryMediaFileCheck($paths[1], LibraryMediaFileVerdict::Deletable),
        ], false, true));
        $handler = $this->handler($album, $songs, $media);

        $handler($this->message($libraryId));
        $handler(new FilesDiscovered($libraryId, 'music', '/music/Album', [
            $this->observedFile(new DiscoveredFile('/music/Album/again.flac', 'Album/again.flac', 'flac', 100, 1_700_000_000, 'hash')),
        ]));

        self::assertSame('/music/Album/again.flac', $song->getPath());
    }

    public function testADeleteClaimedBeforeTheRenameWriteRequeuesIt(): void
    {
        $libraryId = Uuid::v7();
        $album = Album::create($libraryId, 'Album', 'studio');
        $album->setCoverImage(Uuid::v7());
        $song = Song::create($album->getId(), 'Song', '/music/Album/old.flac', 100, 'audio/flac', hash: 'hash');
        $songs = $this->createMock(SongPortInterface::class);
        $songs->method('findByHash')->willReturn($song);
        $songs->expects($this->never())->method('persist');
        $songs->expects($this->never())->method('findFreshByUuid');
        $media = $this->observedMediaFiles($this->createMock(LibraryMediaFilesInterface::class));
        $media->method('isHeldByDeleteForImport')->willReturn(true);
        $media->expects($this->never())->method('inspect');
        $bus = $this->createMock(MessageBusInterface::class);
        $message = $this->message($libraryId);
        $bus->expects($this->once())->method('dispatch')->with($this->callback(static fn (object $actual): bool => $actual instanceof FilesDiscovered && $actual->files === $message->files), $this->anything())->willReturn(new Envelope($message));

        $this->handler($album, $songs, $media, $bus)($message);
        self::assertSame('/music/Album/old.flac', $song->getPath());
    }

    public function testFailedWriteIsRetriedWithoutReplacingTheSong(): void
    {
        $libraryId = Uuid::v7();
        $album = Album::create($libraryId, 'Album', 'studio');
        $album->setCoverImage(Uuid::v7());
        $song = Song::create($album->getId(), 'Song', '/music/Album/old.flac', 100, 'audio/flac', hash: 'hash');
        $songs = $this->createMock(SongPortInterface::class);
        $songs->method('findByHash')->willReturn($song);
        $songs->method('findFreshByUuid')->willReturnCallback(static fn (): Song => Song::reconstitute(clone $song->getState()));
        $attempts = 0;
        $songs->expects($this->exactly(2))->method('persist')->willReturnCallback(static function (Song $fresh) use (&$attempts, $song): void {
            if (++$attempts === 1) {
                throw new RuntimeException('Write failed');
            }
            $song->getState()->path = $fresh->getPath();
        });
        $media = $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class));
        $media->method('inspect')->willReturn(new LibraryMediaFileInspection($libraryId, '/music', [
            new LibraryMediaFileCheck($song->getPath(), LibraryMediaFileVerdict::Missing),
            new LibraryMediaFileCheck('/music/Album/new.flac', LibraryMediaFileVerdict::Deletable),
        ], false, true));
        $handler = $this->handler($album, $songs, $media);
        try {
            $handler($this->message($libraryId));
            self::fail('A failed rename write must reach the queue for retry.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('Write failed', $failure->getMessage());
        }
        $handler($this->message($libraryId));
        self::assertSame('/music/Album/new.flac', $song->getPath());
    }

    private function message(Uuid $libraryId): FilesDiscovered
    {
        return new FilesDiscovered($libraryId, 'music', '/music/Album', [
            $this->observedFile(new DiscoveredFile('/music/Album/new.flac', 'Album/new.flac', 'flac', 100, 1_700_000_000, 'hash')),
        ]);
    }

    private function handler(Album $album, SongPortInterface $songs, LibraryMediaFilesInterface $media, ?MessageBusInterface $bus = null): FilesDiscoveredHandler
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturn($album);
        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturn((new ExtractedMetadata())->setAlbum('Album'));
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->expects($this->never())->method('findOrCreateByName');
        $lyrics = $this->createMock(LyricsFetchRequestInterface::class);
        $lyrics->expects($this->never())->method('requestFetch');
        $sync = $this->createMock(AlbumMetadataSyncRequestInterface::class);
        $sync->expects($this->never())->method('requestSync');
        if ($bus === null) {
            $bus = $this->createStub(MessageBusInterface::class);
            $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        }

        return new FilesDiscoveredHandler(
            $albums,
            $genres,
            $songs,
            $this->createStub(MoviePortInterface::class),
            $this->createStub(VideoRepositoryInterface::class),
            $metadata,
            new FFprobeAdapter(new JsonEncoder()),
            $bus,
            $lyrics,
            $sync,
            new NullLogger(),
            $media,
            new PassThroughTransaction(),
        );
    }
}
