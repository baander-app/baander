<?php

namespace App\Modules\Filesystem;

use App\Modules\Filesystem\Exceptions\InotifyException;


class InotifyWrapper
{
    // Inotify event constants with fallback values
    public const int IN_ACCESS = 1;          // File was accessed
    public const int IN_MODIFY = 2;          // File was modified
    public const int IN_ATTRIB = 4;          // Metadata changed
    public const int IN_CLOSE_WRITE = 8;     // Writable file was closed
    public const int IN_CLOSE_NOWRITE = 16;  // writable file closed
    public const int IN_OPEN = 32;           // File was opened
    public const int IN_MOVED_FROM = 64;     // File was moved from X
    public const int IN_MOVED_TO = 128;      // File was moved to Y
    public const int IN_CREATE = 256;        // Subfile was created
    public const int IN_DELETE = 512;        // Subfile was deleted
    public const int IN_DELETE_SELF = 1024;  // Self was deleted
    public const int IN_MOVE_SELF = 2048;    // Self was moved

    // Convenience constants
    public const int IN_CLOSE = 24;          // IN_CLOSE_WRITE | IN_CLOSE_NOWRITE
    public const int IN_MOVE = 192;          // IN_MOVED_FROM | IN_MOVED_TO
    public const int IN_ALL_EVENTS = 4095;   // All events

    // Special flags
    public const int IN_ONLYDIR = 16777216;    // Only watch the path if it's a directory
    public const int IN_DONT_FOLLOW = 33554432; // Don't follow a symlink
    public const int IN_EXCL_UNLINK = 67108864; // Exclude events on unlinked objects
    public const int IN_MASK_ADD = 536870912;   // Add to the mask of an already existing watch
    public const int IN_ONESHOT = 2147483648;   // Only send event once

    private bool $available;

    /**
     * @var resource|false
     */
    private $handle;

    private array $watchDescriptors = [];

    public function __construct()
    {
        $this->available = extension_loaded('inotify') && function_exists('inotify_init');
        $this->handle = false;
    }

    /**
     * Check if inotify is available
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Initialize an inotify instance
     *
     * @throws InotifyException
     */
    public function init(): void
    {
        if (!$this->available) {
            throw new InotifyException('inotify extension is not available');
        }

        if (!function_exists('inotify_init')) {
            throw new InotifyException('inotify_init function is not available');
        }

        $this->handle = inotify_init();

        if ($this->handle === false) {
            throw new InotifyException('Failed to initialize inotify');
        }

        // Set non-blocking mode
        stream_set_blocking($this->handle, false);
    }

    /**
     * Add a watch to an inotify instance
     *
     * @param string $pathname The file or directory to be watched
     * @param int $mask The events to be watched for
     * @return int The watch descriptor
     * @throws InotifyException
     */
    public function addWatch(string $pathname, int $mask): int
    {
        if (!$this->isInitialized()) {
            throw new InotifyException('inotify is not initialized');
        }

        if (!function_exists('inotify_add_watch')) {
            throw new InotifyException('inotify_add_watch function is not available');
        }

        $watchDescriptor = inotify_add_watch($this->handle, $pathname, $mask);

        $this->watchDescriptors[$watchDescriptor] = $pathname;

        return $watchDescriptor;
    }

    /**
     * Remove an existing watch from an inotify instance
     */
    public function removeWatch(int $watchDescriptor): bool
    {
        if (!$this->isInitialized()) {
            return false;
        }

        if (!function_exists('inotify_rm_watch')) {
            return false;
        }

        $result = inotify_rm_watch($this->handle, $watchDescriptor);

        if ($result) {
            unset($this->watchDescriptors[$watchDescriptor]);
        }

        return $result;
    }

    /**
     * Read events from an inotify instance
     *
     * @return array|false Array of events or false if no events
     */
    public function read(): array|false
    {
        if (!$this->isInitialized()) {
            return false;
        }

        if (!function_exists('inotify_read')) {
            return false;
        }

        return inotify_read($this->handle);
    }

    /**
     * Get the pathname for a watch descriptor
     */
    public function getWatchPath(int $watchDescriptor): ?string
    {
        return $this->watchDescriptors[$watchDescriptor] ?? null;
    }

    /**
     * Get all watch descriptors and their paths
     */
    public function getWatchDescriptors(): array
    {
        return $this->watchDescriptors;
    }

    /**
     * Close the inotify instance
     */
    public function close(): void
    {
        if ($this->handle && is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = false;
            $this->watchDescriptors = [];
        }
    }

    /**
     * Check if inotify is initialized
     */
    public function isInitialized(): bool
    {
        return $this->handle !== false && is_resource($this->handle);
    }

    /**
     * Get a constant value safely
     * @throws InotifyException
     */
    public function getConstant(string $constantName): int
    {
        if (defined($constantName)) {
            return constant($constantName);
        }

        // Return our fallback constants
        return match ($constantName) {
            'IN_ACCESS' => self::IN_ACCESS,
            'IN_MODIFY' => self::IN_MODIFY,
            'IN_ATTRIB' => self::IN_ATTRIB,
            'IN_CLOSE_WRITE' => self::IN_CLOSE_WRITE,
            'IN_CLOSE_NOWRITE' => self::IN_CLOSE_NOWRITE,
            'IN_OPEN' => self::IN_OPEN,
            'IN_MOVED_FROM' => self::IN_MOVED_FROM,
            'IN_MOVED_TO' => self::IN_MOVED_TO,
            'IN_CREATE' => self::IN_CREATE,
            'IN_DELETE' => self::IN_DELETE,
            'IN_DELETE_SELF' => self::IN_DELETE_SELF,
            'IN_MOVE_SELF' => self::IN_MOVE_SELF,
            'IN_CLOSE' => self::IN_CLOSE,
            'IN_MOVE' => self::IN_MOVE,
            'IN_ALL_EVENTS' => self::IN_ALL_EVENTS,
            'IN_ONLYDIR' => self::IN_ONLYDIR,
            'IN_DONT_FOLLOW' => self::IN_DONT_FOLLOW,
            'IN_EXCL_UNLINK' => self::IN_EXCL_UNLINK,
            'IN_MASK_ADD' => self::IN_MASK_ADD,
            'IN_ONESHOT' => self::IN_ONESHOT,
            default => throw new InotifyException("Unknown inotify constant: $constantName")
        };
    }

    /**
     * Build a mask from multiple constants
     * @throws InotifyException
     */
    public function buildMask(array $constants): int
    {
        $mask = 0;
        foreach ($constants as $constant) {
            if (is_string($constant)) {
                $mask |= $this->getConstant($constant);
            } else if (is_int($constant)) {
                $mask |= $constant;
            }
        }
        return $mask;
    }

    /**
     * Add recursive watch to a directory
     *
     * @param string $path Directory path to watch
     * @param int $mask Events to watch for
     * @return int Number of watches added
     * @throws InotifyException
     */
    public function addWatchRecursive(string $path, int $mask): int
    {
        if (!is_dir($path)) {
            throw new InotifyException("Path is not a directory: $path");
        }

        $watchCount = 0;

        // Add watch for the root directory
        $this->addWatch($path, $mask);
        $watchCount++;

        // Add watches for subdirectories
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                try {
                    $this->addWatch($file->getPathname(), $mask);
                    $watchCount++;
                } catch (InotifyException $e) {
                    // Log but continue with other directories
                    error_log("Failed to add watch for {$file->getPathname()}: {$e->getMessage()}");
                }
            }
        }

        return $watchCount;
    }

    /**
     * Wait for events with timeout
     *
     * @param int $timeoutMicroseconds Timeout in microseconds
     * @return array|false Events or false on timeout/error
     */
    public function waitForEvents(int $timeoutMicroseconds = 100000): array|false
    {
        if (!$this->isInitialized()) {
            return false;
        }

        // Use stream_select to wait for data
        $read = [$this->handle];
        $write = null;
        $except = null;

        $seconds = (int)($timeoutMicroseconds / 1000000);
        $microseconds = $timeoutMicroseconds % 1000000;

        $result = stream_select($read, $write, $except, $seconds, $microseconds);

        if ($result === false || $result === 0) {
            return false; // Error or timeout
        }

        return $this->read();
    }

    public function __destruct()
    {
        $this->close();
    }
}