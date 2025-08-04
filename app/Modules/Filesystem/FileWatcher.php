<?php

namespace App\Modules\Filesystem;

use App\Modules\Filesystem\Exceptions\InotifyException;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

class FileWatcher
{
    private InotifyWrapper $inotify;
    private array $callbacks = [];
    private bool $running = false;

    public function __construct()
    {
        $this->inotify = new InotifyWrapper();
    }

    /**
     * Check if file watching is available
     */
    public function isAvailable(): bool
    {
        return $this->inotify->isAvailable();
    }

    /**
     * Start watching files/directories
     *
     * @throws InotifyException
     */
    public function start(): void
    {
        if (!$this->inotify->isAvailable()) {
            throw new InotifyException('File watching is not available on this system');
        }

        $this->inotify->init();
        $this->running = true;
    }

    /**
     * Add a path to watch
     *
     * @param string $path Path to watch
     * @param array $events Events to watch for (e.g., ['IN_MODIFY', 'IN_CREATE'])
     * @param bool $recursive Watch recursively for directories
     * @return int Number of watches added
     * @throws InotifyException
     */
    public function watch(string $path, array $events = ['IN_MODIFY', 'IN_CREATE', 'IN_DELETE'], bool $recursive = false): int
    {
        $mask = $this->inotify->buildMask($events);

        if ($recursive && is_dir($path)) {
            return $this->inotify->addWatchRecursive($path, $mask);
        }

        $this->inotify->addWatch($path, $mask);
        return 1;
    }

    /**
     * Add a callback for file events
     *
     * @param Closure $callback Function to call when events occur
     *                         Receives (string $path, array $event) parameters
     */
    public function onEvent(Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /**
     * Poll for events (non-blocking)
     *
     * @return int Number of events processed
     */
    public function poll(): int
    {
        if (!$this->running) {
            return 0;
        }

        $events = $this->inotify->read();
        if (!$events) {
            return 0;
        }

        $eventCount = 0;
        foreach ($events as $event) {
            $path = $this->inotify->getWatchPath($event['wd']);
            if ($path) {
                $this->triggerCallbacks($path, $event);
                $eventCount++;
            }
        }

        return $eventCount;
    }

    /**
     * Watch for events with timeout
     *
     * @param int $timeoutMicroseconds Timeout in microseconds
     * @return int Number of events processed
     */
    public function waitForEvents(int $timeoutMicroseconds = 100000): int
    {
        if (!$this->running) {
            return 0;
        }

        $events = $this->inotify->waitForEvents($timeoutMicroseconds);
        if (!$events) {
            return 0;
        }

        $eventCount = 0;
        foreach ($events as $event) {
            $path = $this->inotify->getWatchPath($event['wd']);
            if ($path) {
                $this->triggerCallbacks($path, $event);
                $eventCount++;
            }
        }

        return $eventCount;
    }

    /**
     * Stop watching and cleanup
     */
    public function stop(): void
    {
        $this->running = false;
        $this->inotify->close();
    }

    /**
     * Get watch statistics
     */
    public function getStats(): array
    {
        return [
            'available' => $this->inotify->isAvailable(),
            'running' => $this->running,
            'watching' => count($this->inotify->getWatchDescriptors()),
            'callbacks' => count($this->callbacks),
        ];
    }

    /**
     * Trigger all registered callbacks
     */
    private function triggerCallbacks(string $path, array $event): void
    {
        foreach ($this->callbacks as $callback) {
            try {
                $callback($path, $event);
            } catch (Throwable $e) {
                Log::error("FileWatcher callback error: " . $e->getMessage());
            }
        }
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->stop();
    }
}