<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure;

use App\Filesystem\Application\Port\MimeDetectorPortInterface;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Media\Domain\Model\StoredFile;
use App\Media\Infrastructure\ManagedVideoArtworkImporter;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ManagedVideoArtworkImporterTest extends TestCase
{
    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function testLocalImageBytesBecomeManagedLibraryOwnedArtworkOnlyWhenPersisted(): void
    {
        $bytes = base64_decode(self::PIXEL, true);
        $library = Uuid::v7();
        $files = $this->createMock(LibraryMediaFilesInterface::class);
        $files->expects(self::once())->method('readSidecar')->with($library, '/media/poster.png', 10 * 1024 * 1024)->willReturn($bytes);
        $storage = $this->createMock(StoragePortInterface::class);
        $storage->expects(self::once())->method('storeFromBytes')->willReturnCallback(static function (string $contents, string $path) use ($bytes): StoredFile {
            self::assertSame($bytes, $contents);
            self::assertStringStartsWith('images/video/', $path);
            self::assertStringEndsWith('.png', $path);

            return new StoredFile($path, 'application/octet-stream', strlen($contents));
        });
        $images = $this->createMock(ImagePortInterface::class);
        $images->method('findManagedVideoArtwork')->willReturn([]);
        $images->expects(self::once())->method('save')->willReturnCallback(static function (Image $image) use ($library): void {
            self::assertTrue($library->equals($image->getLibraryId()));
            self::assertSame('video', $image->getImageableType());
            self::assertSame('image/png', $image->getMimeType());
            self::assertSame(1, $image->getWidth());
            self::assertSame(1, $image->getHeight());
        });
        $importer = $this->importer($files, $storage, $images);
        $prepared = $importer->prepare($library, '/media/poster.png');
        self::assertSame('/api/images/' . $prepared->image->getPublicId()->toString() . '/file', $prepared->url);
        $importer->persist($prepared);
    }

    public function testMissingOrRetargetedFileCreatesNeitherImageNorStoredCopy(): void
    {
        $files = $this->createStub(LibraryMediaFilesInterface::class);
        $files->method('readSidecar')->willReturn(null);
        $storage = $this->createMock(StoragePortInterface::class);
        $storage->expects(self::never())->method('storeFromBytes');
        $images = $this->createMock(ImagePortInterface::class);
        $images->expects(self::never())->method('save');
        $this->expectException(ServiceUnavailableException::class);
        $this->importer($files, $storage, $images)->prepare(Uuid::v7(), '/media/retargeted.png');
    }

    public function testUnsupportedImageBytesAreRefusedBeforeStorage(): void
    {
        $files = $this->createStub(LibraryMediaFilesInterface::class);
        $files->method('readSidecar')->willReturn('<html>not an image</html>');
        $storage = $this->createMock(StoragePortInterface::class);
        $storage->expects(self::never())->method('storeFromBytes');
        $this->expectException(ServiceUnavailableException::class);
        $this->importer($files, $storage, $this->createStub(ImagePortInterface::class))->prepare(Uuid::v7(), '/media/poster.png');
    }

    public function testFailedApplyDiscardsItsOwnCopy(): void
    {
        $bytes = base64_decode(self::PIXEL, true);
        $files = $this->createStub(LibraryMediaFilesInterface::class);
        $files->method('readSidecar')->willReturn($bytes);
        $storage = $this->createMock(StoragePortInterface::class);
        $storage->method('storeFromBytes')->willReturnCallback(static fn (string $contents, string $path): StoredFile => new StoredFile($path, 'image/png', strlen($contents)));
        $storage->expects(self::once())->method('delete');
        $images = $this->createMock(ImagePortInterface::class);
        $images->expects(self::never())->method('save');
        $importer = $this->importer($files, $storage, $images);
        $prepared = $importer->prepare(Uuid::v7(), '/media/poster.png');
        $importer->discard($prepared);
    }

    public function testUnchangedExistingArtworkIsReusedAndNeverDiscardedOnRetry(): void
    {
        $bytes = base64_decode(self::PIXEL, true);
        $library = Uuid::v7();
        $path = 'images/video/' . $library->toString() . '/' . hash('sha256', $bytes) . '/stored.png';
        $existing = Image::create($path, 'png', 'image/png', strlen($bytes), 1, 1, 'video', libraryId: $library);
        $missing = Image::create(dirname($path) . '/missing.png', 'png', 'image/png', strlen($bytes), 1, 1, 'video', libraryId: $library);
        $files = $this->createStub(LibraryMediaFilesInterface::class);
        $files->method('readSidecar')->willReturn($bytes);
        $storage = $this->createMock(StoragePortInterface::class);
        $storage->expects(self::exactly(2))->method('exists')->willReturnCallback(static fn (string $candidate): bool => $candidate === $path);
        $storage->expects(self::never())->method('storeFromBytes');
        $storage->expects(self::never())->method('delete');
        $images = $this->createMock(ImagePortInterface::class);
        $prefix = 'images/video/' . $library->toString() . '/' . hash('sha256', $bytes) . '/';
        $images->expects(self::once())->method('findManagedVideoArtwork')->with($library, $prefix)->willReturn([$missing, $existing]);
        $images->expects(self::never())->method('save');
        $importer = $this->importer($files, $storage, $images);
        $prepared = $importer->prepare($library, '/media/poster.png');
        self::assertFalse($prepared->newlyStored);
        self::assertSame($existing, $prepared->image);
        $importer->persist($prepared);
        $importer->discard($prepared);
    }

    private function importer(LibraryMediaFilesInterface $files, StoragePortInterface $storage, ImagePortInterface $images): ManagedVideoArtworkImporter
    {
        $mime = $this->createStub(MimeDetectorPortInterface::class);
        $mime->method('detectFromBytes')->willReturn('image/png');

        return new ManagedVideoArtworkImporter($files, $storage, $images, $mime, new NullLogger());
    }
}
