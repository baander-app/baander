<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Infrastructure\Service;

use App\Filesystem\Application\Port\FileHandle;
use App\Filesystem\Infrastructure\Service\LocalFilesystem;
use App\Filesystem\Infrastructure\Service\ReadOnlyFilesystem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReadOnlyFilesystemTest extends TestCase
{
    private string $basePath;
    private ReadOnlyFilesystem $filesystem;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/rofs_' . uniqid('', true);
        mkdir($this->basePath, 0o755, true);
        mkdir($this->basePath . '/album', 0o755, true);
        file_put_contents($this->basePath . '/song.mp3', 'audio-bytes');
        file_put_contents($this->basePath . '/album/track1.flac', 'flac-bytes');

        $this->filesystem = new ReadOnlyFilesystem(new LocalFilesystem($this->basePath), $this->basePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->basePath . '/album/track1.flac');
        @unlink($this->basePath . '/song.mp3');
        @rmdir($this->basePath . '/album');
        @rmdir($this->basePath);
    }

    public function testResolveReturnsExistingPathWithinBase(): void
    {
        $this->assertSame($this->basePath . '/song.mp3', $this->filesystem->resolve('song.mp3'));
    }

    public function testResolveAcceptsNonExistentPathWithinBase(): void
    {
        $this->assertSame(
            $this->basePath . '/future/file.txt',
            $this->filesystem->resolve('future/file.txt'),
        );
    }

    public function testResolveThrowsWhenPathEscapesBase(): void
    {
        $outside = realpath(sys_get_temp_dir());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Path escapes library base path');

        $this->filesystem->resolve((string) $outside);
    }

    public function testResolveThrowsWhenBasePathDoesNotExist(): void
    {
        $filesystem = new ReadOnlyFilesystem(new LocalFilesystem($this->basePath), $this->basePath . '/missing');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Library base path does not exist');

        $filesystem->resolve('song.mp3');
    }

    public function testExistsReturnsTrueForExistingFile(): void
    {
        $this->assertTrue($this->filesystem->exists('song.mp3'));
    }

    public function testExistsReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->filesystem->exists('missing.mp3'));
    }

    public function testSizeReturnsByteCountForExistingFile(): void
    {
        $this->assertSame(11, $this->filesystem->size('song.mp3'));
    }

    public function testSizeReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->filesystem->size('missing.mp3'));
    }

    public function testOpenRejectsWriteMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Read-only filesystem does not allow mode "w"');

        $this->filesystem->open('song.mp3', 'w');
    }

    public function testOpenRejectsAppendMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mode "a"');

        $this->filesystem->open('song.mp3', 'a');
    }

    public function testOpenRejectsReadWriteMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mode "r+"');

        $this->filesystem->open('song.mp3', 'r+');
    }

    public function testOpenAcceptsReadModeAndReturnsHandle(): void
    {
        $handle = $this->filesystem->open('song.mp3', 'r');

        try {
            $this->assertInstanceOf(FileHandle::class, $handle);
            $this->assertSame($this->basePath . '/song.mp3', $handle->path());
        } finally {
            $handle->close();
        }
    }

    public function testOpenAcceptsBinaryReadMode(): void
    {
        $handle = $this->filesystem->open('song.mp3', 'rb');

        try {
            $this->assertInstanceOf(FileHandle::class, $handle);
        } finally {
            $handle->close();
        }
    }

    public function testListReturnsEntriesWithoutDotAndDotDot(): void
    {
        $this->assertSame(['track1.flac'], $this->filesystem->list('album'));
    }

    public function testListBaseDirectory(): void
    {
        $this->assertSame(['album', 'song.mp3'], $this->filesystem->list(''));
    }

    public function testListReturnsFalseForNonDirectory(): void
    {
        $this->assertFalse($this->filesystem->list('song.mp3'));
    }

    public function testListReturnsFalseForMissingPath(): void
    {
        $this->assertFalse($this->filesystem->list('nope'));
    }

    public function testIsDirectoryReturnsTrueForDirectory(): void
    {
        $this->assertTrue($this->filesystem->isDirectory('album'));
    }

    public function testIsDirectoryReturnsFalseForFile(): void
    {
        $this->assertFalse($this->filesystem->isDirectory('song.mp3'));
    }

    public function testIsFileReturnsTrueForFile(): void
    {
        $this->assertTrue($this->filesystem->isFile('song.mp3'));
    }

    public function testIsFileReturnsFalseForDirectory(): void
    {
        $this->assertFalse($this->filesystem->isFile('album'));
    }

    public function testLastModifiedReturnsTimestampForExistingFile(): void
    {
        $mtime = $this->filesystem->lastModified('song.mp3');

        $this->assertIsInt($mtime);
        $this->assertGreaterThan(0, $mtime);
    }

    public function testLastModifiedReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->filesystem->lastModified('missing.mp3'));
    }
}
