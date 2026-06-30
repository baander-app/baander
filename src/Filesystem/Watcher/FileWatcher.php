<?php

declare(strict_types=1);

namespace App\Filesystem\Watcher;

use App\Shared\Infrastructure\Swoole\Async;
use Psr\Log\LoggerInterface;

/**
 * Watches directories for file system changes using inotify.
 *
 * Requires the `inotify` PHP extension. Runs as a long-lived process
 * (typically managed by Supervisor alongside messenger workers).
 */
final class FileWatcher
{
    private const int WATCH_MASK = IN_CREATE | IN_DELETE | IN_MODIFY | IN_MOVED_FROM | IN_MOVED_TO | IN_CLOSE_WRITE | IN_ISDIR;

    private const array EVENT_MAP = [
        IN_CREATE => 'CREATE',
        IN_DELETE => 'DELETE',
        IN_MODIFY => 'MODIFY',
        IN_MOVED_FROM => 'MOVED_FROM',
        IN_MOVED_TO => 'MOVED_TO',
        IN_CLOSE_WRITE => 'CLOSE_WRITE',
    ];

    /** @var array<string, bool> Active watch descriptors keyed by path */
    private array $watches = [];

    /** @var array<int, string> Reverse map from watch descriptor to directory path */
    private array $wdToPath = [];

    private $inotifyFd = null;

    /** @var callable[] */
    private array $listeners = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        if (!function_exists('inotify_init')) {
            throw new \RuntimeException('The inotify PHP extension is required for FileWatcher.');
        }
    }

    /**
     * Add a directory to watch for changes.
     */
    public function watch(string $path): void
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            throw new \InvalidArgumentException(sprintf('Directory "%s" does not exist.', $path));
        }

        if ($this->inotifyFd === null) {
            $this->inotifyFd = inotify_init();
            if ($this->inotifyFd === false) {
                throw new \RuntimeException('Failed to initialize inotify.');
            }
        }

        if (isset($this->watches[$realPath])) {
            return;
        }

        $wd = inotify_add_watch($this->inotifyFd, $realPath, self::WATCH_MASK);
        if ($wd === false) {
            throw new \RuntimeException(sprintf('Failed to watch directory "%s".', $realPath));
        }

        $this->watches[$realPath] = true;
        $this->wdToPath[$wd] = $realPath;
        $this->logger->info('Watching directory', ['path' => $realPath, 'watch_descriptor' => $wd]);
    }

    /**
     * Register a listener callback invoked on file system events.
     *
     * @param callable(FileWatchEvent): void $listener
     */
    public function onEvent(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Block and process events. Returns when interrupted (e.g., SIGTERM).
     *
     * @param int $timeoutMs Timeout in milliseconds per read (default 5000ms)
     */
    public function run(int $timeoutMs = 5000): void
    {
        if ($this->inotifyFd === null) {
            throw new \RuntimeException('No directories are being watched. Call watch() first.');
        }

        $this->logger->info('File watcher started', ['watches' => count($this->watches)]);

        // Make the inotify fd non-blocking so we can handle signals
        stream_set_blocking($this->inotifyFd, false);

        pcntl_async_signals(true);
        $running = true;
        pcntl_signal(SIGTERM, function () use (&$running): void {
            $running = false;
        });
        pcntl_signal(SIGINT, function () use (&$running): void {
            $running = false;
        });

        while ($running) {
            pcntl_signal_dispatch();

            $events = inotify_read($this->inotifyFd);
            if ($events !== false) {
                foreach ($events as $event) {
                    $this->dispatchEvent($event);
                }
            } elseif (($errno = socket_last_error()) !== 0 && $errno !== 11) {
                // ENOTCONN or real error — EAGAIN (11) is expected for non-blocking
                $this->logger->warning('inotify_read error', ['errno' => $errno]);
            }

            Async::sleep($timeoutMs / 1000);
        }

        $this->cleanup();
        $this->logger->info('File watcher stopped');
    }

    private function dispatchEvent(array $inotifyEvent): void
    {
        $eventType = 0;
        foreach (self::EVENT_MAP as $flag => $name) {
            if (($inotifyEvent['mask'] & $flag) !== 0) {
                $eventType |= $flag;
            }
        }

        $isDirectory = ($inotifyEvent['mask'] & IN_ISDIR) !== 0;

        $dirPath = $this->wdToPath[$inotifyEvent['wd']] ?? '';
        $fullPath = $dirPath !== '' && $inotifyEvent['name'] !== ''
            ? $dirPath . '/' . $inotifyEvent['name']
            : $dirPath;

        $event = new FileWatchEvent(
            watchDescriptor: $inotifyEvent['wd'],
            path: $inotifyEvent['name'],
            fullPath: $fullPath,
            type: $eventType,
            isDirectory: $isDirectory,
        );

        foreach ($this->listeners as $listener) {
            $listener($event);
        }
    }

    private function cleanup(): void
    {
        if ($this->inotifyFd !== null) {
            fclose($this->inotifyFd);
            $this->inotifyFd = null;
        }
        $this->watches = [];
        $this->wdToPath = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
