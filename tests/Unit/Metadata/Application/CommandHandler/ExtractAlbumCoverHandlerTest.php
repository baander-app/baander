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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ExtractAlbumCoverHandlerTest extends TestCase
{
    private MetadataContentReaderPortInterface&MockObject $metadataReader;
    private SongPortInterface&MockObject $songService;
    private AlbumPortInterface&MockObject $albumService;
    private ImagePortInterface&MockObject $imagePort;
    private StoragePortInterface&MockObject $storage;
    private EntityManagerInterface&MockObject $entityManager;
    private LoggerInterface&MockObject $logger;
    private ExtractAlbumCoverHandler $handler;

    protected function setUp(): void
    {
        $this->metadataReader = $this->createMock(MetadataContentReaderPortInterface::class);
        $this->songService = $this->createMock(SongPortInterface::class);
        $this->albumService = $this->createMock(AlbumPortInterface::class);
        $this->imagePort = $this->createMock(ImagePortInterface::class);
        $this->storage = $this->createMock(StoragePortInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new ExtractAlbumCoverHandler(
            $this->metadataReader,
            $this->songService,
            $this->albumService,
            $this->imagePort,
            $this->storage,
            $this->entityManager,
            $this->logger,
        );
    }

    public function testReturnsGracefullyWhenAlbumNotFound(): void
    {
        $albumId = Uuid::v7();

        $this->albumService->method('findByUuid')->with($albumId)->willReturn(null);
        $this->logger->expects($this->once())->method('warning')->with(
            'Album not found for cover extraction, skipping',
            $this->anything(),
        );

        ($this->handler)(new ExtractAlbumCoverCommand($albumId));
    }

    public function testSkipsWhenAlbumAlreadyHasCoverImage(): void
    {
        $album = Album::create(
            Uuid::v7(),
            'Test Album',
            'album',
        );
        $album->setCoverImage(Uuid::v7());

        $this->albumService->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->expects($this->never())->method('findByAlbum');
        $this->logger->expects($this->once())->method('debug')->with(
            'Album already has a cover image, skipping',
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));
    }

    public function testSkipsWhenNoSongsFound(): void
    {
        $album = Album::create(Uuid::v7(), 'Test Album', 'album');

        $this->albumService->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([]);
        $this->storage->expects($this->never())->method('storeFromBytes');

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));
    }

    public function testSkipsWhenNoCoverArtFound(): void
    {
        $album = Album::create(Uuid::v7(), 'Test Album', 'album');
        $tmpFile = tempnam(sys_get_temp_dir(), 'cover_test_');
        file_put_contents($tmpFile, 'fake audio data');

        $song = $this->createSongWithMockPath($tmpFile);

        $this->albumService->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->method('readMetadata')->with($tmpFile)->willReturn(
            new ExtractedMetadata(),
        );
        $this->storage->expects($this->never())->method('storeFromBytes');

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        @unlink($tmpFile);
    }

    public function testSkipsWhenCoverArtHasEmptyImageData(): void
    {
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

        $this->albumService->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->method('readMetadata')->with($tmpFile)->willReturn(
            (function() use ($coverArt) { $m = new ExtractedMetadata(); $m->setPictures([$coverArt]); return $m; })(),
        );
        $this->storage->expects($this->never())->method('storeFromBytes');
        $this->logger->expects($this->once())->method('debug')->with(
            'Cover art has no image data, skipping',
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        @unlink($tmpFile);
    }

    public function testSkipsWhenCoverArtHasUnsupportedMimeType(): void
    {
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

        $this->albumService->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->method('readMetadata')->with($tmpFile)->willReturn(
            (function() use ($coverArt) { $m = new ExtractedMetadata(); $m->setPictures([$coverArt]); return $m; })(),
        );
        $this->storage->expects($this->never())->method('storeFromBytes');
        $this->logger->expects($this->once())->method('warning')->with(
            'Cover art has unsupported MIME type, skipping',
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        @unlink($tmpFile);
    }

    public function testSkipsWhenCoverArtExceedsMaxSize(): void
    {
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

        $this->albumService->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->method('readMetadata')->with($tmpFile)->willReturn(
            (function() use ($coverArt) { $m = new ExtractedMetadata(); $m->setPictures([$coverArt]); return $m; })(),
        );
        $this->storage->expects($this->never())->method('storeFromBytes');
        $this->logger->expects($this->once())->method('warning')->with(
            'Cover art exceeds maximum size, skipping',
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        @unlink($tmpFile);
    }

    public function testExtractsCoverAndPersistsImage(): void
    {
        $album = Album::create(Uuid::v7(), 'Test Album', 'album');
        $tmpFile = tempnam(sys_get_temp_dir(), 'cover_test_');
        file_put_contents($tmpFile, 'fake audio data');

        $song = $this->createSongWithMockPath($tmpFile);
        $imageData = "\xff\xd8\xff\xe0\x00\x10JFIF"; // minimal JPEG header
        $coverArt = CoverArt::fromArray([
            'type' => CoverArt::TYPE_COVER_FRONT,
            'mimeType' => 'image/jpeg',
            'description' => 'Cover',
            'imageData' => $imageData,
            'width' => 600,
            'height' => 600,
        ]);

        $storedFile = new StoredFile(
            'images/album/' . $album->getId()->toString() . '.jpg',
            'image/jpeg',
            strlen($imageData),
        );

        $this->albumService->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->method('readMetadata')->with($tmpFile)->willReturn(
            (function() use ($coverArt) { $m = new ExtractedMetadata(); $m->setPictures([$coverArt]); return $m; })(),
        );
        $this->storage->method('storeFromBytes')
            ->with($imageData, 'images/album/' . $album->getId()->toString() . '.jpg')
            ->willReturn($storedFile);

        // Expect transaction begin, commit (no rollback)
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        $this->entityManager->expects($this->never())->method('rollback');

        $this->imagePort->expects($this->once())->method('save')->with($this->anything());
        $this->albumService->expects($this->once())->method('save')->with($album);
        $this->logger->expects($this->once())->method('info')->with(
            'Cover image extracted for album',
            $this->anything(),
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        // Verify album's cover image ID was set
        $this->assertNotNull($album->getCoverImageId());

        @unlink($tmpFile);
    }

    public function testRollsBackAndCleansUpFileOnDatabaseFailure(): void
    {
        $album = Album::create(Uuid::v7(), 'Test Album', 'album');
        $tmpFile = tempnam(sys_get_temp_dir(), 'cover_test_');
        file_put_contents($tmpFile, 'fake audio data');

        $song = $this->createSongWithMockPath($tmpFile);
        $imageData = "\xff\xd8\xff\xe0\x00\x10JFIF";
        $coverArt = CoverArt::fromArray([
            'type' => CoverArt::TYPE_COVER_FRONT,
            'mimeType' => 'image/jpeg',
            'description' => 'Cover',
            'imageData' => $imageData,
            'width' => 600,
            'height' => 600,
        ]);

        $storedFile = new StoredFile(
            'images/album/' . $album->getId()->toString() . '.jpg',
            'image/jpeg',
            strlen($imageData),
        );

        $this->albumService->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->method('readMetadata')->with($tmpFile)->willReturn(
            (function() use ($coverArt) { $m = new ExtractedMetadata(); $m->setPictures([$coverArt]); return $m; })(),
        );
        $this->storage->method('storeFromBytes')->willReturn($storedFile);

        // Make save throw an exception
        $this->imagePort->method('save')->willThrowException(new RuntimeException('DB error'));

        // Expect transaction begin, rollback (no commit)
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->never())->method('commit');
        $this->entityManager->expects($this->once())->method('rollback');

        // Expect file cleanup
        $this->storage->expects($this->once())->method('delete')->with($storedFile->getPath());

        // Expect error logging
        $this->logger->expects($this->once())->method('error')->with(
            'Failed to persist cover image for album',
            $this->anything(),
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        // Album's cover image ID should remain null (transaction rolled back)
        $this->assertNull($album->getCoverImageId());

        @unlink($tmpFile);
    }

    public function testReturnsGracefullyWhenFileStorageFails(): void
    {
        $album = Album::create(Uuid::v7(), 'Test Album', 'album');
        $tmpFile = tempnam(sys_get_temp_dir(), 'cover_test_');
        file_put_contents($tmpFile, 'fake audio data');

        $song = $this->createSongWithMockPath($tmpFile);
        $imageData = "\xff\xd8\xff\xe0\x00\x10JFIF";
        $coverArt = CoverArt::fromArray([
            'type' => CoverArt::TYPE_COVER_FRONT,
            'mimeType' => 'image/jpeg',
            'description' => 'Cover',
            'imageData' => $imageData,
        ]);

        $this->albumService->method('findByUuid')->with($album->getId())->willReturn($album);
        $this->songService->method('findByAlbum')->with($album->getId(), $this->anything())->willReturn([$song]);
        $this->metadataReader->method('readMetadata')->with($tmpFile)->willReturn(
            (function() use ($coverArt) { $m = new ExtractedMetadata(); $m->setPictures([$coverArt]); return $m; })(),
        );
        $this->storage->method('storeFromBytes')->willThrowException(new RuntimeException('Write failed'));
        $this->entityManager->expects($this->never())->method('beginTransaction');
        $this->imagePort->expects($this->never())->method('save');
        $this->logger->expects($this->once())->method('error')->with(
            'Failed to store cover art file',
        );

        ($this->handler)(new ExtractAlbumCoverCommand($album->getId()));

        @unlink($tmpFile);
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
