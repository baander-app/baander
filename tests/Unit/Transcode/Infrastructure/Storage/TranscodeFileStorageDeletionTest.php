<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Storage;

use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscodeFileStorageDeletionTest extends TestCase
{
    private string $fixture;
    private string $root;
    private string $outside;
    private TranscodeFileStorage $storage;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/baander-transcode-delete-' . bin2hex(random_bytes(8));
        $this->root = $this->fixture . '/cache';
        $this->outside = $this->fixture . '/cache-other';
        mkdir($this->root, 0700, true);
        mkdir($this->outside, 0700);
        file_put_contents($this->outside . '/secret', 'outside content');
        $this->storage = new TranscodeFileStorage(new SegmentFileResolver($this->root));
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixture, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isLink() || !$entry->isDir()) {
                unlink($entry->getPathname());
            } else {
                rmdir($entry->getPathname());
            }
        }
        rmdir($this->fixture);
    }

    public function testDeletesNestedCacheAndLinksWithoutRemovingTheirTargets(): void
    {
        $video = $this->root . '/video';
        mkdir($video . '/quality/nested', 0700, true);
        file_put_contents($video . '/quality/nested/segment', 'segment');
        symlink($this->outside . '/secret', $video . '/file-link');
        symlink($this->outside, $video . '/directory-link');
        symlink($this->outside . '/missing', $video . '/dangling-link');
        symlink($this->root, $video . '/root-link');
        mkdir($this->root . '/retained', 0700);
        file_put_contents($this->root . '/retained/segment', 'retained');
        symlink($this->root . '/retained', $video . '/cache-directory-link');

        $this->storage->deleteDirectory($video);

        self::assertDirectoryDoesNotExist($video);
        $this->assertBoundaryPreserved();
        self::assertSame('retained', file_get_contents($this->root . '/retained/segment'));
    }

    #[DataProvider('linkTargets')]
    public function testDeletingSymlinkInputRemovesOnlyTheLink(string $target): void
    {
        $destination = match ($target) {
            'directory' => $this->outside,
            'file' => $this->outside . '/secret',
            'dangling' => $this->outside . '/missing',
            'root' => $this->root,
            default => throw new InvalidArgumentException('Unknown symlink fixture target.'),
        };
        $link = $this->root . '/video';
        symlink($destination, $link);

        $this->storage->deleteDirectory($link . '/');

        self::assertFalse(is_link($link));
        self::assertFileDoesNotExist($link);
        $this->assertBoundaryPreserved();
    }

    public function testRefusesPathThroughSymlinkAncestor(): void
    {
        mkdir($this->outside . '/nested', 0700);
        file_put_contents($this->outside . '/nested/secret', 'nested outside content');
        symlink($this->outside, $this->root . '/video');
        try {
            $this->storage->deleteDirectory($this->root . '/video/nested');
            self::fail('A symbolic link ancestor must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(is_link($this->root . '/video'));
            self::assertSame('nested outside content', file_get_contents($this->outside . '/nested/secret'));
            $this->assertBoundaryPreserved();
        }
    }

    #[DataProvider('forbiddenPaths')]
    public function testRefusesRootAndPathsOutsideItsDirectoryBoundary(string $suffix): void
    {
        $path = match ($suffix) {
            'root' => $this->root,
            'root-slash' => $this->root . '/',
            'root-dot' => $this->root . '/.',
            'parent' => $this->root . '/..',
            'outside' => $this->outside,
            'traversal' => $this->root . '/../cache-other',
            'relative' => 'video',
            default => throw new InvalidArgumentException('Unknown forbidden path fixture.'),
        };
        try {
            $this->storage->deleteDirectory($path);
            self::fail('The storage root and outside paths must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertBoundaryPreserved();
        }
    }

    public function testMissingDirectoryAndRegularFileInputsRemainHarmless(): void
    {
        file_put_contents($this->root . '/segment', 'segment');
        $this->storage->deleteDirectory($this->root . '/missing');
        $this->storage->deleteDirectory($this->root . '/segment');
        self::assertSame('segment', file_get_contents($this->root . '/segment'));
        $this->assertBoundaryPreserved();
    }

    /** @return iterable<string, array{string}> */
    public static function linkTargets(): iterable
    {
        foreach (['directory', 'file', 'dangling', 'root'] as $target) {
            yield $target => [$target];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenPaths(): iterable
    {
        foreach (['root', 'root-slash', 'root-dot', 'parent', 'outside', 'traversal', 'relative'] as $path) {
            yield $path => [$path];
        }
    }

    private function assertBoundaryPreserved(): void
    {
        self::assertDirectoryExists($this->root);
        self::assertDirectoryExists($this->outside);
        self::assertSame('outside content', file_get_contents($this->outside . '/secret'));
    }
}
