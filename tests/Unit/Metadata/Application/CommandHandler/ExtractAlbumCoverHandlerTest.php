<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application\CommandHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\StoredFile;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\CommandHandler\ExtractAlbumCoverHandler;
use App\Metadata\Domain\Model\CoverArt;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ExtractAlbumCoverHandlerTest extends TestCase
{
    private MetadataContentReaderPortInterface&Stub $metadataReader;
    private SongPortInterface&Stub $songService;
    private AlbumPortInterface&Stub $albumService;
    private ImagePortInterface&Stub $imagePort;
    private StoragePortInterface&Stub $storage;
    private EntityManagerInterface&Stub $entityManager;
    private LoggerInterface&Stub $logger;
    private ExtractAlbumCoverHandler $handler;

    protected function setUp(): void
    {
        $this->metadataReader = $this->createStub(MetadataContentReaderPortInterface::class);
        $this->songService = $this->createStub(SongPortInterface::class);
        $this->albumService = $this->createStub(AlbumPortInterface::class);
        $this->imagePort = $this->createStub(ImagePortInterface::class);
        $this->storage = $this->createStub(StoragePortInterface::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);

        $this->handler = $this->createExtractAlbumCoverHandlerFixture();
    }

    private function createExtractAlbumCoverHandlerFixture(): ExtractAlbumCoverHandler
    {
        $fixture = new ExtractAlbumCoverHandler(
            $this->metadataReader,
            $this->songService,
            $this->albumService,
            $this->imagePort,
            $this->storage,
            $this->entityManager,
            $this->logger,
        );
        return $fixture;
    }

    public function testReturnsGracefullyWhenAlbumNotFound(): void
    {
        $this->albumService = $this->createMock(AlbumPortInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = $this->createExtractAlbumCoverHandlerFixture();

        $albumId = Uuid::v7();

        $this->albumService->expects($this->once())->method('findByUuid')->with($albumId)->willReturn(null);
        $this->logger->expects($this->once())->method('warning')->with(
            'Album not found for cover extraction, skipping',
            $this->anything(),
        );

        ($this->handler)(new ExtractAlbumCoverCommand($albumId));
    }

    public function testSkipsWhenAlbumAlreadyHasCoverImage(): void
    {
        $this->albumService = $this->createMock(AlbumPortInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->songService = $this->createMock(SongPortInterface::class);
        $this->handler = $this->createExtractAlbumCoverHandlerFixture();

        $album = Album::create(
            Uuid::v7(),
            'Test Album',
            'album',
        );
        $album->setCoverImage(Uuid::v7());

        $this->albumService->expects($this->once())->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->expects($this->never())->method('findByAlbum');
        $this->logger->expects($this->once())->method('debug')->with(
            'Album already has a cover image, skipping',
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));
    }

    public function testSkipsWhenNoSongsFound(): void
    {
        $this->albumService = $this->createMock(AlbumPortInterface::class);
        $this->songService = $this->createMock(SongPortInterface::class);
        $this->storage = $this->createMock(StoragePortInterface::class);
        $this->handler = $this->createExtractAlbumCoverHandlerFixture();

        $album = Album::create(Uuid::v7(), 'Test Album', 'album');

        $this->albumService->expects($this->once())->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->expects($this->once())->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([]);
        $this->storage->expects($this->never())->method('storeFromBytes');

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));
    }

    public function testSkipsWhenNoCoverArtFound(): void
    {
        $this->albumService = $this->createMock(AlbumPortInterface::class);
        $this->metadataReader = $this->createMock(MetadataContentReaderPortInterface::class);
        $this->songService = $this->createMock(SongPortInterface::class);
        $this->storage = $this->createMock(StoragePortInterface::class);
        $this->handler = $this->createExtractAlbumCoverHandlerFixture();

        $album = Album::create(Uuid::v7(), 'Test Album', 'album');
        $tmpFile = tempnam(sys_get_temp_dir(), 'cover_test_');
        file_put_contents($tmpFile, 'fake audio data');

        $song = $this->createSongWithMockPath($tmpFile);

        $this->albumService->expects($this->once())->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->expects($this->once())->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->expects($this->once())->method('readMetadata')->with($tmpFile)->willReturn(
            new ExtractedMetadata(),
        );
        $this->storage->expects($this->never())->method('storeFromBytes');

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        @unlink($tmpFile);
    }

    public function testSkipsWhenCoverArtHasEmptyImageData(): void
    {
        $this->albumService = $this->createMock(AlbumPortInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->metadataReader = $this->createMock(MetadataContentReaderPortInterface::class);
        $this->songService = $this->createMock(SongPortInterface::class);
        $this->storage = $this->createMock(StoragePortInterface::class);
        $this->handler = $this->createExtractAlbumCoverHandlerFixture();

        $album = Album::create(Uuid::v7(), 'Test Album', 'album');
        $tmpFile = tempnam(sys_get_temp_dir(), 'cover_test_');
        file_put_contents($tmpFile, 'fake audio data');

        $song = $this->createSongWithMockPath($tmpFile);
        $coverArt = CoverArt::fromArray([
            'type' => CoverArt::TYPE_COVER_FRONT,
            'mimeType' => 'image/jpeg',
            'description' => '',
            'imageData' => '',
        ]);

        $this->albumService->expects($this->once())->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->expects($this->once())->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->expects($this->once())->method('readMetadata')->with($tmpFile)->willReturn(
            (function() use ($coverArt) { $m = new ExtractedMetadata(); $m->setPictures([$coverArt]); return $m; })(),
        );
        self::assertInstanceOf(MockObject::class, $this->storage);
        self::assertInstanceOf(MockObject::class, $this->logger);
        $this->storage->expects($this->never())->method('storeFromBytes');
        $this->logger->expects($this->once())->method('debug')->with(
            'Cover art has no image data, skipping',
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        @unlink($tmpFile);
    }

    public function testSkipsWhenCoverArtHasUnsupportedMimeType(): void
    {
        $this->albumService = $this->createMock(AlbumPortInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->metadataReader = $this->createMock(MetadataContentReaderPortInterface::class);
        $this->songService = $this->createMock(SongPortInterface::class);
        $this->storage = $this->createMock(StoragePortInterface::class);
        $this->handler = $this->createExtractAlbumCoverHandlerFixture();

        $album = Album::create(Uuid::v7(), 'Test Album', 'album');
        $tmpFile = tempnam(sys_get_temp_dir(), 'cover_test_');
        file_put_contents($tmpFile, 'fake audio data');

        $song = $this->createSongWithMockPath($tmpFile);
        $coverArt = CoverArt::fromArray([
            'type' => CoverArt::TYPE_COVER_FRONT,
            'mimeType' => 'image/bmp',
            'description' => '',
            'imageData' => "\x00\x01\x02",
        ]);

        $this->albumService->expects($this->once())->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->expects($this->once())->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->expects($this->once())->method('readMetadata')->with($tmpFile)->willReturn(
            (function() use ($coverArt) { $m = new ExtractedMetadata(); $m->setPictures([$coverArt]); return $m; })(),
        );
        self::assertInstanceOf(MockObject::class, $this->storage);
        self::assertInstanceOf(MockObject::class, $this->logger);
        $this->storage->expects($this->never())->method('storeFromBytes');
        $this->logger->expects($this->once())->method('warning')->with(
            'Cover art has unsupported MIME type, skipping',
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        @unlink($tmpFile);
    }

    public function testSkipsWhenCoverArtExceedsMaxSize(): void
    {
        $this->albumService = $this->createMock(AlbumPortInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->metadataReader = $this->createMock(MetadataContentReaderPortInterface::class);
        $this->songService = $this->createMock(SongPortInterface::class);
        $this->storage = $this->createMock(StoragePortInterface::class);
        $this->handler = $this->createExtractAlbumCoverHandlerFixture();

        $album = Album::create(Uuid::v7(), 'Test Album', 'album');
        $tmpFile = tempnam(sys_get_temp_dir(), 'cover_test_');
        file_put_contents($tmpFile, 'fake audio data');

        $song = $this->createSongWithMockPath($tmpFile);
        $imageData = str_repeat('x', 10 * 1024 * 1024 + 1); // 10 MB + 1 byte
        $coverArt = CoverArt::fromArray([
            'type' => CoverArt::TYPE_COVER_FRONT,
            'mimeType' => 'image/jpeg',
            'description' => '',
            'imageData' => $imageData,
        ]);

        $this->albumService->expects($this->once())->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->expects($this->once())->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->expects($this->once())->method('readMetadata')->with($tmpFile)->willReturn(
            (function() use ($coverArt) { $m = new ExtractedMetadata(); $m->setPictures([$coverArt]); return $m; })(),
        );
        self::assertInstanceOf(MockObject::class, $this->storage);
        self::assertInstanceOf(MockObject::class, $this->logger);
        $this->storage->expects($this->never())->method('storeFromBytes');
        $this->logger->expects($this->once())->method('warning')->with(
            'Cover art exceeds maximum size, skipping',
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        @unlink($tmpFile);
    }

    public function testExtractsCoverAndPersistsImage(): void
    {
        [$album, $path] = $this->prepareCoverExtraction(mockImage: true);
        self::assertInstanceOf(MockObject::class, $this->imagePort);
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        $this->entityManager->expects($this->never())->method('rollback');
        $this->storage->expects($this->never())->method('delete');
        $this->imagePort->expects($this->once())->method('save');
        $this->albumService->expects($this->once())->method('save')->with($album);

        try {
            ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));
            $this->assertNotNull($album->getCoverImageId());
        } finally {
            unlink($path);
        }
    }

    public function testRollsBackAndCleansUpFileOnDatabaseFailure(): void
    {
        [$album, $path] = $this->prepareCoverExtraction();
        $failure = new RuntimeException('DB error');
        $this->imagePort->method('save')->willThrowException($failure);
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->never())->method('commit');
        $this->entityManager->expects($this->once())->method('rollback');
        $this->entityManager->expects($this->once())->method('clear');
        $this->storage->expects($this->once())->method('delete');

        try {
            $this->assertDeliveryFailure($album, $failure);
            $this->assertNull($album->getCoverImageId());
        } finally {
            unlink($path);
        }
    }

    public function testStorageFailureReachesMessenger(): void
    {
        // A failed storage write must reach Messenger for retry.
        [$album, $path] = $this->prepareCoverExtraction(store: false, mockImage: true);
        self::assertInstanceOf(MockObject::class, $this->imagePort);
        $failure = new RuntimeException('Write failed');
        $this->storage->method('storeFromBytes')->willThrowException($failure);
        $this->entityManager->expects($this->never())->method('beginTransaction');
        $this->imagePort->expects($this->never())->method('save');
        $this->storage->expects($this->never())->method('delete');
        $this->logger->method('error')->willThrowException(new RuntimeException('Logger unavailable'));

        try {
            $this->assertDeliveryFailure($album, $failure);
        } finally {
            unlink($path);
        }
    }

    public function testMetadataReadFailureReachesMessenger(): void
    {
        [$album, $path] = $this->prepareCoverExtraction(read: false, store: false);
        $failure = new RuntimeException('Metadata reader unavailable');
        $this->metadataReader->method('readMetadata')->willThrowException($failure);
        $this->storage->expects($this->never())->method('storeFromBytes');
        $this->entityManager->expects($this->never())->method('beginTransaction');
        $this->logger->method('warning')->willThrowException(new RuntimeException('Logger unavailable'));

        try {
            $this->assertDeliveryFailure($album, $failure);
        } finally {
            unlink($path);
        }
    }

    public function testSongLookupFailureReachesMessenger(): void
    {
        $album = Album::create(Uuid::v7(), 'Test Album', 'album');
        $this->albumService->method('findByUuid')->willReturn($album);
        $failure = new RuntimeException('Song repository unavailable');
        $this->songService->method('findByAlbum')->willThrowException($failure);
        $this->assertDeliveryFailure($album, $failure);
    }

    public function testNullMetadataRemainsASkip(): void
    {
        [$album, $path] = $this->prepareCoverExtraction(read: false, store: false);
        $this->metadataReader->method('readMetadata')->willReturn(null);
        $this->storage->expects($this->never())->method('storeFromBytes');
        $this->entityManager->expects($this->never())->method('beginTransaction');

        try {
            ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));
        } finally {
            unlink($path);
        }
    }

    public function testTransactionBeginFailureCleansUpStoredFileAndReachesMessenger(): void
    {
        [$album, $path] = $this->prepareCoverExtraction(mockImage: true);
        self::assertInstanceOf(MockObject::class, $this->imagePort);
        $failure = new RuntimeException('Cannot begin transaction');
        $this->entityManager->method('beginTransaction')->willThrowException($failure);
        $this->entityManager->expects($this->never())->method('rollback');
        $this->imagePort->expects($this->never())->method('save');
        $this->storage->expects($this->once())->method('delete');

        try {
            $this->assertDeliveryFailure($album, $failure);
        } finally {
            unlink($path);
        }
    }

    public function testAlbumSaveFailureRestoresDomainCoverAndCleansUpAfterRollback(): void
    {
        [$album, $path] = $this->prepareCoverExtraction();
        $failure = new RuntimeException('Album save failed');
        $this->albumService->method('save')->willThrowException($failure);
        $this->entityManager->expects($this->once())->method('rollback');
        $this->entityManager->expects($this->once())->method('clear');
        $this->entityManager->expects($this->never())->method('commit');
        $this->storage->expects($this->once())->method('delete');

        try {
            $this->assertDeliveryFailure($album, $failure);
            $this->assertNull($album->getCoverImageId());
        } finally {
            unlink($path);
        }
    }

    public function testCommitFailureRetainsFileForUncertainOutcomeAndReachesMessenger(): void
    {
        [$album, $path] = $this->prepareCoverExtraction();
        $failure = new RuntimeException('Commit acknowledgement lost');
        $this->entityManager->method('commit')->willThrowException($failure);
        $this->entityManager->expects($this->once())->method('rollback');
        $this->entityManager->expects($this->once())->method('clear');
        $this->storage->expects($this->never())->method('delete');

        try {
            $this->assertDeliveryFailure($album, $failure);
            $this->assertNull($album->getCoverImageId());
        } finally {
            unlink($path);
        }
    }

    public function testRollbackFailureRetainsFileAndPreservesOriginalDeliveryFailure(): void
    {
        [$album, $path] = $this->prepareCoverExtraction();
        $failure = new RuntimeException('Album save failed');
        $this->albumService->method('save')->willThrowException($failure);
        $this->entityManager->method('rollback')->willThrowException(new RuntimeException('Rollback failed'));
        $this->entityManager->expects($this->once())->method('clear');
        $this->storage->expects($this->never())->method('delete');
        $this->logger->method('error')->willThrowException(new RuntimeException('Logger unavailable'));

        try {
            $this->assertDeliveryFailure($album, $failure);
            $this->assertNull($album->getCoverImageId());
        } finally {
            unlink($path);
        }
    }

    public function testCleanupAndClearFailuresPreserveOriginalDeliveryFailure(): void
    {
        [$album, $path] = $this->prepareCoverExtraction();
        $failure = new RuntimeException('Album save failed');
        $this->albumService->method('save')->willThrowException($failure);
        $this->entityManager->expects($this->once())->method('rollback');
        $this->entityManager->expects($this->once())->method('clear')
            ->willThrowException(new RuntimeException('Entity manager clear failed'));
        $this->storage->expects($this->once())->method('delete')
            ->willThrowException(new RuntimeException('Cleanup failed'));
        $this->logger->method('error')->willThrowException(new RuntimeException('Logger unavailable'));

        try {
            $this->assertDeliveryFailure($album, $failure);
            $this->assertNull($album->getCoverImageId());
        } finally {
            unlink($path);
        }
    }

    public function testSuccessLoggingFailureDoesNotRetryOrDeleteCommittedCover(): void
    {
        [$album, $path] = $this->prepareCoverExtraction();
        $this->entityManager->expects($this->once())->method('commit');
        $this->entityManager->expects($this->never())->method('rollback');
        $this->entityManager->expects($this->never())->method('clear');
        $this->storage->expects($this->never())->method('delete');
        $this->logger->method('info')->willThrowException(new RuntimeException('Logger unavailable'));
        $this->logger->method('error')->willThrowException(new RuntimeException('Error logger unavailable'));

        try {
            ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));
            $this->assertNotNull($album->getCoverImageId());
        } finally {
            unlink($path);
        }
    }

    public function testEachDeliveryUsesItsOwnStoredDestination(): void
    {
        [$album, $path] = $this->prepareCoverExtraction(store: false);
        $this->entityManager->expects($this->exactly(2))->method('commit');
        $destinations = [];
        $this->storage->expects($this->exactly(2))->method('storeFromBytes')
            ->willReturnCallback(function (string $contents, string $destination) use (&$destinations): StoredFile {
                $destinations[] = $destination;
                return new StoredFile($destination, 'image/jpeg', strlen($contents));
            });

        try {
            ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));
            $album->setCoverImage(null);
            ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));
            $this->assertCount(2, $destinations);
            foreach ($destinations as $destination) {
                $this->assertStringStartsWith('images/album/' . $album->getId()->toString() . '/', $destination);
                $this->assertStringEndsWith('.jpg', $destination);
            }
            $this->assertNotSame($destinations[0], $destinations[1]);
        } finally {
            unlink($path);
        }
    }

    /**
     * @return array{Album, string}
     * @phpstan-assert AlbumPortInterface&MockObject $this->albumService
     * @phpstan-assert EntityManagerInterface&MockObject $this->entityManager
     * @phpstan-assert StoragePortInterface&MockObject $this->storage
     */
    private function prepareCoverExtraction(bool $read = true, bool $store = true, bool $mockImage = false): array
    {
        $this->albumService = $this->createMock(AlbumPortInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->imagePort = $mockImage ? $this->createMock(ImagePortInterface::class) : $this->createStub(ImagePortInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->metadataReader = $this->createStub(MetadataContentReaderPortInterface::class);
        $this->songService = $this->createStub(SongPortInterface::class);
        $this->storage = $this->createMock(StoragePortInterface::class);
        $this->handler = $this->createExtractAlbumCoverHandlerFixture();

        $album = Album::create(Uuid::v7(), 'Test Album', 'album');
        $path = tempnam(sys_get_temp_dir(), 'cover_test_');
        self::assertIsString($path);
        file_put_contents($path, 'fake audio data');
        $this->albumService->expects($this->atLeastOnce())->method('findByUuid')->willReturn($album);
        $this->songService->method('findByAlbum')->willReturn([$this->createSongWithMockPath($path)]);
        $data = "\xff\xd8\xff\xe0\x00\x10JFIF";
        if ($read) {
            $metadata = new ExtractedMetadata();
            $metadata->setPictures([new CoverArt(CoverArt::TYPE_COVER_FRONT, 'image/jpeg', 'Cover', $data, 600, 600)]);
            $this->metadataReader->method('readMetadata')->willReturn($metadata);
        }
        if ($store) {
            $this->storage->expects($this->once())->method('storeFromBytes')->willReturnCallback(
                static fn (string $contents, string $destination): StoredFile => new StoredFile($destination, 'image/jpeg', strlen($contents)),
            );
        }

        return [$album, $path];
    }

    private function assertDeliveryFailure(Album $album, RuntimeException $failure): void
    {
        try {
            ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual, 'Secondary failures must not replace the original operation failure.');
            return;
        }
        self::fail('The operation failure must reach Messenger.');
    }

    private function createSongWithMockPath(string $path): Song
    {
        // Song is a domain model — create via factory and use reflection to set path
        $albumId = Uuid::v7();
        $song = Song::create(
            album: $albumId,
            title: 'Test Song',
            path: $path,
            size: 1024,
            mimeType: 'audio/mpeg',
        );

        return $song;
    }
}
