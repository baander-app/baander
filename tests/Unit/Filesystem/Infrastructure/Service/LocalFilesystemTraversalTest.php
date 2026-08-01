<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Infrastructure\Service;

use App\Filesystem\Infrastructure\Service\LocalFilesystem;
use PHPUnit\Framework\TestCase;

/**
 * Security-focused tests for LocalFilesystem path resolution.
 *
 * The current implementation of LocalFilesystem::resolve() returns absolute
 * paths verbatim and concatenates relative paths directly onto the base path,
 * which allows traversal outside basePath. These tests assert the expected
 * secure behaviour and therefore fail against the current production code.
 */
final class LocalFilesystemTraversalTest extends TestCase
{
    private string $basePath;
    private LocalFilesystem $filesystem;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/localfs_traversal_' . uniqid('', true);
        mkdir($this->basePath, 0o755, true);
        $this->filesystem = new LocalFilesystem($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->basePath);
    }

    public function testResolveRejectsAbsolutePathOutsideBase(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->filesystem->resolve('/etc/passwd');
    }

    public function testResolveRejectsParentDirectoryTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->filesystem->resolve('../secret.txt');
    }

    public function testResolveRejectsNestedParentDirectoryTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->filesystem->resolve('music/artist/../../etc/passwd');
    }

    public function testResolveAcceptsPathInsideBase(): void
    {
        $resolved = $this->filesystem->resolve('music/song.mp3');

        $this->assertSame($this->basePath . '/music/song.mp3', $resolved);
    }

    public function testResolveAcceptsNestedPathInsideBase(): void
    {
        $resolved = $this->filesystem->resolve('music/artist/album/song.mp3');

        $this->assertSame($this->basePath . '/music/artist/album/song.mp3', $resolved);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

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
