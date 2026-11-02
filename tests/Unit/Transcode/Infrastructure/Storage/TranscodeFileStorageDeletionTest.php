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

    public function testRetentionPinsTheWholeVideoUntilTheLongestDeadline(): void
    {
        $generation = $this->root . '/video/copy/job-one/generation-1';
        mkdir($generation, 0700, true);
        file_put_contents($generation . '/segment.m4s', 'media');
        self::assertTrue($this->storage->retainUntil($generation, time() + 3600));
        self::assertTrue($this->storage->retainUntil($generation, time() - 1));
        self::assertTrue($this->storage->hasUnexpiredRetention($this->root . '/video'));
        self::assertFalse($this->storage->deleteIfUnretained($this->root . '/video'));
        self::assertSame('media', file_get_contents($generation . '/segment.m4s'));
        self::assertSame(['video'], $this->storage->getVideoDirectories());
    }

    public function testExpiredRetentionAllowsCleanupAndCannotResurrectDeletedOutput(): void
    {
        $directory = $this->root . '/video';
        mkdir($directory);
        self::assertTrue($this->storage->retainUntil($directory, time() - 1));
        self::assertFalse($this->storage->hasUnexpiredRetention($directory));
        self::assertTrue($this->storage->deleteIfUnretained($directory));
        self::assertDirectoryDoesNotExist($directory);
        self::assertFalse($this->storage->retainUntil($directory, time() + 3600));
        self::assertTrue($this->storage->deleteIfUnretained($directory));
    }

    public function testRetentionRejectsSymlinksAndOutsideDirectories(): void
    {
        symlink($this->outside, $this->root . '/linked');
        foreach ([$this->outside, $this->root . '/linked', $this->root . '/linked/child'] as $directory) {
            try {
                $this->storage->retainUntil($directory, time() + 3600);
                self::fail('Retention must not follow an outside path or symlink.');
            } catch (InvalidArgumentException) {
                $this->assertBoundaryPreserved();
            }
        }
    }

    #[DataProvider('waitingRetentionOperations')]
    public function testWaitingOperationRechecksDirectoryAndDeadlineUnderTheStableLock(string $operation): void
    {
        $directory = $this->root . '/video';
        mkdir($directory);
        $record = $this->root . '/.retention-' . hash('sha256', 'video');
        $lock = fopen($record . '.lock', 'x+b');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        $code = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $storage = new App\Transcode\Infrastructure\Storage\TranscodeFileStorage(new App\Transcode\Infrastructure\Storage\SegmentFileResolver($argv[2]));
            echo "ready\n";
            flush();
            $result = $argv[3] === 'grant' ? $storage->retainUntil($argv[2] . '/video', time() + 3600) : $storage->deleteIfUnretained($argv[2] . '/video');
            echo $result ? "true\n" : "false\n";
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 5), $this->root, $operation],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            self::assertSame("ready\n", fgets($pipes[1]));
            stream_set_blocking($pipes[1], false);
            usleep(50_000);
            self::assertSame('', stream_get_contents($pipes[1]), 'The lifecycle operation must wait for the stable root lock.');
            if ($operation === 'grant') {
                $this->storage->deleteDirectory($directory);
            } else {
                file_put_contents($record . '.lease', (time() + 3600) . "\n");
            }
            flock($lock, LOCK_UN);
            stream_set_blocking($pipes[1], true);
            self::assertSame("false\n", stream_get_contents($pipes[1]));
            self::assertSame('', stream_get_contents($pipes[2]));
            self::assertSame($operation === 'delete', is_dir($directory));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function waitingRetentionOperations(): iterable
    {
        yield 'grant after deletion' => ['grant'];
        yield 'delete after grant' => ['delete'];
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

    public function testAudioActivityTraversalIgnoresLinksAtEachGenerationPathLevel(): void
    {
        $signature = 'mp3-192k-0123456789abcdef.mp3';
        $track = $this->root . '/audio-renditions/track';
        $outsideGeneration = $this->outside . '/generation-1';
        mkdir($track, 0700, true);
        mkdir($outsideGeneration);
        file_put_contents($outsideGeneration . '/' . $signature . '.part', 'outside partial');
        $cutoff = new \DateTimeImmutable('-1 minute');

        symlink($this->outside, $track . '/' . $signature);
        self::assertFalse($this->storage->isAudioRenditionEncodingSince('track', $cutoff));
        unlink($track . '/' . $signature);
        mkdir($track . '/' . $signature);
        $generation = $track . '/' . $signature . '/generation-1';
        symlink($outsideGeneration, $generation);
        self::assertFalse($this->storage->isAudioRenditionEncodingSince('track', $cutoff));
        unlink($generation);
        mkdir($generation);
        $partial = $generation . '/' . $signature . '.part';
        symlink($outsideGeneration . '/' . $signature . '.part', $partial);
        self::assertFalse($this->storage->isAudioRenditionEncodingSince('track', $cutoff));
        unlink($partial);
        file_put_contents($partial, 'real generation partial');
        self::assertTrue($this->storage->isAudioRenditionEncodingSince('track', $cutoff));
        touch($partial, time() - 120);
        self::assertFalse($this->storage->isAudioRenditionEncodingSince('track', $cutoff));
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
