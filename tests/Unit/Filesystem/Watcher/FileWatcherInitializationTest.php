<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Watcher;

use App\Filesystem\Watcher\FileWatcher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FileWatcherInitializationTest extends TestCase
{
    #[WithoutErrorHandler]
    public function testNativeInitializationFailureLeavesTheWatcherRetryable(): void
    {
        $directory = sys_get_temp_dir() . '/baander-inotify-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $watcher = new FileWatcher(new NullLogger());
        $descriptor = new \ReflectionProperty(FileWatcher::class, 'inotifyFd');
        $limits = posix_getrlimit();
        self::assertIsArray($limits);
        $softLimit = $limits['soft openfiles'];
        $hardLimit = $limits['hard openfiles'];
        self::assertTrue(posix_setrlimit(POSIX_RLIMIT_NOFILE, 64, $hardLimit));
        $openFiles = [];
        $failure = null;

        try {
            // Exercise the native false result rather than replace inotify_init.
            // The separate child restores its descriptor limit before assertions.
            while (($file = @fopen('/dev/null', 'r')) !== false) {
                $openFiles[] = $file;
            }
            try {
                @$watcher->watch($directory);
            } catch (\Throwable $exception) {
                $failure = $exception;
            } finally {
                foreach ($openFiles as $file) {
                    fclose($file);
                }
                self::assertTrue(posix_setrlimit(POSIX_RLIMIT_NOFILE, $softLimit, $hardLimit));
            }

            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertSame('Failed to initialize inotify.', $failure->getMessage());
            self::assertNull($descriptor->getValue($watcher));

            $watcher->watch($directory);

            self::assertIsResource($descriptor->getValue($watcher));
        } finally {
            // Keep a failing regression from invoking fclose(false) at shutdown.
            if ($descriptor->getValue($watcher) === false) {
                $descriptor->setValue($watcher, null);
            }
            unset($watcher);
            rmdir($directory);
        }
    }
}
