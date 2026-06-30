<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Watcher;

use App\Filesystem\Watcher\FileWatchEvent;
use App\Filesystem\Watcher\FileWatcher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

final class FileWatcherTest extends TestCase
{
    private NullLogger $logger;

    protected function setUp(): void
    {
        // inotify is required by the FileWatcher constructor; these tests run
        // in the app container where the extension is available.
        $this->logger = new NullLogger();
    }

    public function testConstructorSucceedsWhenInotifyAvailable(): void
    {
        $watcher = new FileWatcher($this->logger);

        $this->assertInstanceOf(FileWatcher::class, $watcher);
    }

    public function testWatchRegistersExistingDirectory(): void
    {
        $dir = $this->createTempDir();
        $watcher = new FileWatcher($this->logger);

        try {
            $watcher->watch($dir);

            $watches = $this->watchDescriptors($watcher);

            $this->assertNotEmpty($watches);
            $this->assertContains(realpath($dir), $watches);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testWatchIsIdempotentForSameDirectory(): void
    {
        $dir = $this->createTempDir();
        $watcher = new FileWatcher($this->logger);

        try {
            $watcher->watch($dir);
            $watcher->watch($dir);

            $this->assertCount(1, $this->watchDescriptors($watcher));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testWatchThrowsForNonExistentDirectory(): void
    {
        $watcher = new FileWatcher($this->logger);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $watcher->watch('/no/such/dir/' . uniqid('watch_', true));
    }

    public function testWatchThrowsForFilePathInsteadOfDirectory(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'watch_file_');
        assert(is_string($file));
        $watcher = new FileWatcher($this->logger);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('does not exist');

            $watcher->watch($file);
        } finally {
            @unlink($file);
        }
    }

    public function testOnEventInvokesListenerWithJoinedFullPath(): void
    {
        $dir = $this->createTempDir();
        $watcher = new FileWatcher($this->logger);

        try {
            $watcher->watch($dir);

            $realDir = realpath($dir);
            $wd = (int) array_search($realDir, $this->watchDescriptors($watcher), true);

            $captured = [];
            $watcher->onEvent(static function (FileWatchEvent $event) use (&$captured): void {
                $captured[] = $event;
            });

            $this->dispatch($watcher, [
                'wd' => $wd,
                'mask' => IN_CREATE | IN_ISDIR,
                'cookie' => 0,
                'name' => 'newdir',
            ]);

            $this->assertCount(1, $captured);
            $event = $captured[0];
            $this->assertSame($wd, $event->watchDescriptor);
            $this->assertSame('newdir', $event->path);
            $this->assertSame($realDir . '/newdir', $event->fullPath);
            $this->assertTrue($event->isCreate());
            $this->assertTrue($event->isDirectory);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testOnEventMarksMoveEvents(): void
    {
        $dir = $this->createTempDir();
        $watcher = new FileWatcher($this->logger);

        try {
            $watcher->watch($dir);

            $realDir = realpath($dir);
            $wd = (int) array_search($realDir, $this->watchDescriptors($watcher), true);

            $captured = [];
            $watcher->onEvent(static function (FileWatchEvent $event) use (&$captured): void {
                $captured[] = $event;
            });

            $this->dispatch($watcher, [
                'wd' => $wd,
                'mask' => IN_MOVED_FROM,
                'cookie' => 0,
                'name' => 'old.mp3',
            ]);

            $this->assertCount(1, $captured);
            $this->assertTrue($captured[0]->isMove());
            $this->assertFalse($captured[0]->isCreate());
            $this->assertFalse($captured[0]->isDirectory);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testOnEventCanRegisterMultipleListeners(): void
    {
        $dir = $this->createTempDir();
        $watcher = new FileWatcher($this->logger);

        try {
            $watcher->watch($dir);

            $realDir = realpath($dir);
            $wd = (int) array_search($realDir, $this->watchDescriptors($watcher), true);

            $count = 0;
            $watcher->onEvent(static function () use (&$count): void { ++$count; });
            $watcher->onEvent(static function () use (&$count): void { ++$count; });

            $this->dispatch($watcher, [
                'wd' => $wd,
                'mask' => IN_MODIFY,
                'cookie' => 0,
                'name' => 'file.txt',
            ]);

            $this->assertSame(2, $count);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * run() blocks indefinitely waiting for inotify events (only exits on
     * SIGTERM/SIGINT) and FileWatcher is final so it cannot be mocked. It is
     * therefore not covered by unit tests.
     */
    public function testRunWithoutAnyWatchThrows(): void
    {
        $watcher = new FileWatcher($this->logger);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No directories are being watched');

        $watcher->run();
    }

    /**
     * @return array<string|int, string>
     */
    private function watchDescriptors(FileWatcher $watcher): array
    {
        $property = (new ReflectionClass($watcher))->getProperty('wdToPath');

        /** @var array<int|string, string> $value */
        $value = $property->getValue($watcher);

        return $value;
    }

    /**
     * @param array{wd: int, mask: int, cookie: int, name: string} $inotifyEvent
     */
    private function dispatch(FileWatcher $watcher, array $inotifyEvent): void
    {
        (new ReflectionClass($watcher))->getMethod('dispatchEvent')->invoke($watcher, $inotifyEvent);
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/filewatcher_' . uniqid('', true);
        mkdir($dir, 0o755, true);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }
}
