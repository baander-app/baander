<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Infrastructure\Service;

use App\Filesystem\Application\Port\FileHandle;
use App\Filesystem\Infrastructure\Service\LocalFilesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;

final class LocalFilesystemTest extends TestCase
{
    private string $basePath;
    private LocalFilesystem $filesystem;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/localfs_' . uniqid('', true);
        mkdir($this->basePath, 0o755, true);
        $this->filesystem = new LocalFilesystem($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->basePath);
    }

    public function testResolveReturnsAbsolutePathAsIs(): void
    {
        $this->assertSame('/etc/hosts', $this->filesystem->resolve('/etc/hosts'));
    }

    public function testResolveJoinsRelativePathToBase(): void
    {
        $this->assertSame($this->basePath . '/song.mp3', $this->filesystem->resolve('song.mp3'));
    }

    public function testResolveJoinsNestedRelativePath(): void
    {
        $this->assertSame(
            $this->basePath . '/artist/album.flac',
            $this->filesystem->resolve('artist/album.flac'),
        );
    }

    public function testResolveReturnsVerbatimWhenPathStartsWithSlash(): void
    {
        // Any leading slash marks the path as absolute and is returned unchanged
        // (even when it is really intended to be relative).
        $this->assertSame('//var/data', $this->filesystem->resolve('//var/data'));
    }

    public function testExistsReturnsTrueForExistingFile(): void
    {
        $path = $this->basePath . '/real.txt';
        file_put_contents($path, 'data');

        $this->assertTrue($this->filesystem->exists('real.txt'));
    }

    public function testExistsReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->filesystem->exists('missing.txt'));
    }

    public function testSizeReturnsByteCount(): void
    {
        file_put_contents($this->basePath . '/data.bin', '12345');

        $this->assertSame(5, $this->filesystem->size('data.bin'));
    }

    public function testSizeReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->filesystem->size('nope.bin'));
    }

    public function testOpenReturnsFileHandleForReadableExistingFile(): void
    {
        file_put_contents($this->basePath . '/readable.txt', 'contents');

        $handle = $this->filesystem->open('readable.txt', 'r');

        try {
            $this->assertInstanceOf(FileHandle::class, $handle);
            $this->assertSame($this->basePath . '/readable.txt', $handle->path());
        } finally {
            $handle->close();
        }
    }

    public function testOpenThrowsWhenFileCannotBeOpened(): void
    {
        // fopen() emits a warning before returning false; suppress it so the
        // source's RuntimeException is the observable behaviour under test.
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Failed to open file');

            $this->filesystem->open('does-not-exist.txt', 'r');
        } finally {
            restore_error_handler();
        }
    }

    public function testReadReturnsFileContentsInCoroutine(): void
    {
        file_put_contents($this->basePath . '/read.txt', 'hello-world');

        $result = null;
        Coroutine\run(function () use (&$result): void {
            $result = $this->filesystem->read('read.txt');
        });

        $this->assertSame('hello-world', $result);
    }

    public function testWriteReturnsTrueAndPersistsContentInCoroutine(): void
    {
        $result = null;
        Coroutine\run(function () use (&$result): void {
            $result = $this->filesystem->write('written.txt', 'swoole-content');
        });

        $this->assertTrue($result);
        $this->assertSame('swoole-content', file_get_contents($this->basePath . '/written.txt'));
    }

    public function testWriteCreatesParentDirectories(): void
    {
        $result = null;
        Coroutine\run(function () use (&$result): void {
            $result = $this->filesystem->write('nested/deep/file.txt', 'nested-data');
        });

        $this->assertTrue($result);
        $this->assertSame('nested-data', file_get_contents($this->basePath . '/nested/deep/file.txt'));
    }

    public function testWriteOverwritesExistingContentByDefault(): void
    {
        file_put_contents($this->basePath . '/replace.txt', 'old');

        Coroutine\run(function (): void {
            $this->filesystem->write('replace.txt', 'new');
        });

        $this->assertSame('new', file_get_contents($this->basePath . '/replace.txt'));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
