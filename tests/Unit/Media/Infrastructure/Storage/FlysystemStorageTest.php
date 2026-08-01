<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Storage;

use App\Media\Domain\Model\StoredFile;
use App\Media\Infrastructure\Storage\FlysystemStorage;
use PHPUnit\Framework\TestCase;

final class FlysystemStorageTest extends TestCase
{
    private string $tempDir;
    private FlysystemStorage $storage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/flysystem_storage_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->storage = new FlysystemStorage($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    // --- storeFromBytes() tests ---

    public function testStoreFromBytesWritesContentToCorrectPath(): void
    {
        $contents = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 16);
        $result = $this->storage->storeFromBytes($contents, 'images/album/test.png');

        $fullPath = $this->tempDir . '/images/album/test.png';
        $this->assertFileExists($fullPath);
        $this->assertSame($contents, file_get_contents($fullPath));

        $this->assertInstanceOf(StoredFile::class, $result);
        $this->assertSame('images/album/test.png', $result->getPath());
        $this->assertSame(strlen($contents), $result->getSize());
    }

    public function testStoreFromBytesCreatesIntermediateDirectories(): void
    {
        $contents = 'test content';
        $this->storage->storeFromBytes($contents, 'deep/nested/path/file.txt');

        $this->assertDirectoryExists($this->tempDir . '/deep/nested/path');
        $this->assertFileExists($this->tempDir . '/deep/nested/path/file.txt');
    }

    public function testStoreFromBytesEmptyStringWritesZeroLengthFile(): void
    {
        $result = $this->storage->storeFromBytes('', 'empty.dat');

        $this->assertFileExists($this->tempDir . '/empty.dat');
        $this->assertSame('', file_get_contents($this->tempDir . '/empty.dat'));
        $this->assertSame(0, $result->getSize());
    }

    public function testStoreFromBytesPathTraversalThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Path traversal detected');

        $this->storage->storeFromBytes('malicious', '../../etc/passwd');
    }

    public function testStoreFromBytesWithAbsolutePathIsStrippedToRelative(): void
    {
        // Leading slashes are stripped by ltrim for filesystem ops, but StoredFile
        // stores the original relative destination path
        $result = $this->storage->storeFromBytes('data', '/etc/shadow');

        $this->assertFileExists($this->tempDir . '/etc/shadow');
        $this->assertSame('/etc/shadow', $result->getPath());
    }

    public function testStoreFromBytesReturnsStoredFileWithCorrectMetadata(): void
    {
        // 1x1 white PNG (valid PNG file bytes)
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');

        $result = $this->storage->storeFromBytes($pngData, 'cover.png');

        $this->assertSame('cover.png', $result->getPath());
        $this->assertSame('image/png', $result->getMimeType());
        $this->assertSame(strlen($pngData), $result->getSize());
    }

    // --- store() tests ---

    public function testStoreCopiesFileToDestination(): void
    {
        $sourceFile = tempnam(sys_get_temp_dir(), 'flysystem_src_');
        file_put_contents($sourceFile, 'source file content');

        try {
            $result = $this->storage->store($sourceFile, 'uploads/copy.txt');

            $this->assertFileExists($this->tempDir . '/uploads/copy.txt');
            $this->assertSame('source file content', file_get_contents($this->tempDir . '/uploads/copy.txt'));
            $this->assertInstanceOf(StoredFile::class, $result);
            $this->assertSame('uploads/copy.txt', $result->getPath());
        } finally {
            unlink($sourceFile);
        }
    }

    // --- exists() tests ---

    public function testExistsReturnsTrueForExistingFile(): void
    {
        $this->storage->storeFromBytes('data', 'present.txt');

        $this->assertTrue($this->storage->exists('present.txt'));
    }

    public function testExistsReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->storage->exists('missing.txt'));
    }

    // --- delete() tests ---

    public function testDeleteRemovesFile(): void
    {
        $this->storage->storeFromBytes('data', 'to_delete.txt');
        $this->assertFileExists($this->tempDir . '/to_delete.txt');

        $this->storage->delete('to_delete.txt');
        $this->assertFileDoesNotExist($this->tempDir . '/to_delete.txt');
    }

    public function testDeleteDoesNothingForMissingFile(): void
    {
        // Should not throw — verify file still doesn't exist after
        $this->storage->delete('nonexistent.txt');
        $this->assertFileDoesNotExist($this->tempDir . '/nonexistent.txt');
    }

    // --- fullPath() tests ---

    public function testFullPathReturnsExpectedPath(): void
    {
        $result = $this->storage->fullPath('images/test.png');

        $this->assertSame($this->tempDir . '/images/test.png', $result);
    }

    // --- resolve() tests ---

    public function testResolveReturnsFullFilesystemPath(): void
    {
        $result = $this->storage->resolve('images/test.png');

        $this->assertSame($this->tempDir . '/images/test.png', $result);
    }

    // --- deleteDerived() tests ---

    public function testDeleteDerivedRemovesWebPAndPresets(): void
    {
        // Create original + derived files directly (avoid storeFromBytes which needs mime_content_type)
        $dir = $this->tempDir . '/images/album';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/cover.jpg', 'original');
        file_put_contents($dir . '/cover.webp', 'webp');
        file_put_contents($dir . '/cover_thumb.webp', 'thumb');
        file_put_contents($dir . '/cover_small.webp', 'small');

        $this->storage->deleteDerived('images/album/cover.jpg', 'jpg');

        // Original survives
        $this->assertFileExists($dir . '/cover.jpg');
        // Derived files deleted
        $this->assertFileDoesNotExist($dir . '/cover.webp');
        $this->assertFileDoesNotExist($dir . '/cover_thumb.webp');
        $this->assertFileDoesNotExist($dir . '/cover_small.webp');
    }

    public function testDeleteDerivedSkipsOriginalWhenExtensionIsWebp(): void
    {
        // Original is already WebP — deleteDerived should not delete it
        $dir = $this->tempDir . '/images/album';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/cover.webp', 'original-webp');
        file_put_contents($dir . '/cover_thumb.webp', 'thumb');

        $this->storage->deleteDerived('images/album/cover.webp', 'webp');

        // Original WebP survives (guarded by $webpPath !== $fullPath)
        $this->assertFileExists($dir . '/cover.webp');
        // Preset deleted
        $this->assertFileDoesNotExist($dir . '/cover_thumb.webp');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
