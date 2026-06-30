<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain\Model;

use App\Media\Domain\Model\StoredFile;
use PHPUnit\Framework\TestCase;

final class StoredFileTest extends TestCase
{
    public function testCreateStoredFile(): void
    {
        $file = new StoredFile(
            path: '/var/storage/image.jpg',
            mimeType: 'image/jpeg',
            size: 1024,
        );

        $this->assertSame('/var/storage/image.jpg', $file->getPath());
        $this->assertSame('image/jpeg', $file->getMimeType());
        $this->assertSame(1024, $file->getSize());
    }

    public function testGetExtensionFromPath(): void
    {
        $file = new StoredFile(path: '/uploads/photo.png', mimeType: 'image/png', size: 2048);

        $this->assertSame('png', $file->getExtension());
    }

    public function testGetExtensionFromPathWithMultipleDots(): void
    {
        $file = new StoredFile(path: '/uploads/my.photo.v2.jpg', mimeType: 'image/jpeg', size: 500);

        $this->assertSame('jpg', $file->getExtension());
    }

    public function testGetExtensionFromPathWithoutExtension(): void
    {
        $file = new StoredFile(path: '/uploads/noextension', mimeType: 'application/octet-stream', size: 100);

        $this->assertSame('', $file->getExtension());
    }

    public function testGetSizeFormattedBytes(): void
    {
        $file = new StoredFile(path: '/small.txt', mimeType: 'text/plain', size: 500);

        $this->assertSame('500 B', $file->getSizeFormatted());
    }

    public function testGetSizeFormattedKilobytes(): void
    {
        $file = new StoredFile(path: '/medium.txt', mimeType: 'text/plain', size: 2048);

        $this->assertSame('2 KB', $file->getSizeFormatted());
    }

    public function testGetSizeFormattedMegabytes(): void
    {
        // 1,048,576 bytes / 1024 = 1024.0 KB, which is not > 1024, so stays at KB
        $file = new StoredFile(path: '/large.jpg', mimeType: 'image/jpeg', size: 1_048_576);

        $this->assertSame('1024 KB', $file->getSizeFormatted());
    }

    public function testGetSizeFormattedMegabytesNonBoundary(): void
    {
        // 1,050,000 bytes / 1024 = ~1025.4 KB (> 1024), so moves to MB = ~1.0 MB
        $file = new StoredFile(path: '/large.jpg', mimeType: 'image/jpeg', size: 1_050_000);

        $this->assertSame('1 MB', $file->getSizeFormatted());
    }

    public function testGetSizeFormattedGigabytes(): void
    {
        // 1,073,741_824 bytes: 1,073,741,824 / 1024 = 1,048,576 KB (not > 1024 at that level)
        $file = new StoredFile(path: '/huge.mp4', mimeType: 'video/mp4', size: 1_073_741_824);

        $this->assertSame('1024 MB', $file->getSizeFormatted());
    }

    public function testGetSizeFormattedFractional(): void
    {
        // 1500 bytes = ~1.5 KB
        $file = new StoredFile(path: '/fractional.bin', mimeType: 'application/octet-stream', size: 1500);

        $this->assertSame('1.5 KB', $file->getSizeFormatted());
    }

    public function testExistsReturnsFalseForNonExistentFile(): void
    {
        $file = new StoredFile(path: '/nonexistent/path/file.jpg', mimeType: 'image/jpeg', size: 100);

        $this->assertFalse($file->exists());
    }

    public function testExistsReturnsTrueForExistingFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_stored_file_');

        try {
            $file = new StoredFile(path: $tempFile, mimeType: 'text/plain', size: 10);

            $this->assertTrue($file->exists());
        } finally {
            unlink($tempFile);
        }
    }

    public function testReadonlyClass(): void
    {
        $file = new StoredFile(path: '/immutable.jpg', mimeType: 'image/jpeg', size: 100);

        $this->assertSame('/immutable.jpg', $file->getPath());
        $this->assertSame('image/jpeg', $file->getMimeType());
        $this->assertSame(100, $file->getSize());
    }

    public function testZeroSize(): void
    {
        $file = new StoredFile(path: '/empty.txt', mimeType: 'text/plain', size: 0);

        $this->assertSame(0, $file->getSize());
        $this->assertSame('0 B', $file->getSizeFormatted());
    }
}
