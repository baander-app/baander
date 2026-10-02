<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Process-lifetime duplicate-start guard on one trusted local filesystem. */
#[Exclude]
final class LocalSupervisorLock
{
    /** @var resource|null */
    private mixed $handle;

    /** @param resource $handle */
    private function __construct(mixed $handle)
    {
        $this->handle = $handle;
    }

    public static function acquire(string $directory, string $name): self
    {
        if ($directory === '') {
            throw new \InvalidArgumentException('Supervisor lock directory must not be empty.');
        }
        $directory = rtrim($directory, '/');
        if ($directory === '') {
            $directory = '/';
        }
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}\z/D', $name)) {
            throw new \InvalidArgumentException('Supervisor lock name must be a safe basename of at most 128 characters.');
        }
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        if ($owner === null || !str_starts_with($directory, '/') || realpath($directory) !== $directory
            || $stat === false || ($stat['mode'] & 0170000) !== 0040000
            || $stat['uid'] !== $owner || ($stat['mode'] & 0022) !== 0) {
            throw new \InvalidArgumentException('Supervisor lock directory must be an existing canonical absolute directory owned by the effective user and not writable by group or others.');
        }

        // A private child directory is insufficient if another user can rename
        // it through an untrusted parent. Root/user-owned sticky parents (such
        // as /tmp) protect entries owned by this user and are permitted.
        for ($parent = dirname($directory); ; $parent = dirname($parent)) {
            $ancestor = @lstat($parent);
            if ($ancestor === false || !in_array($ancestor['uid'], [0, $owner], true)
                || (($ancestor['mode'] & 0022) !== 0 && ($ancestor['mode'] & 01000) === 0)) {
                throw new \InvalidArgumentException('Supervisor lock directory has an untrusted parent: ' . $parent);
            }
            if ($parent === '/') {
                break;
            }
        }

        $path = $directory . '/' . $name . '.lock';
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            // Close on exec: unrelated children must not extend the owner's lock.
            $handle = @fopen($path, 'x+be');
            if ($handle !== false) {
                if (!@chmod($path, 0600)) {
                    fclose($handle);
                    throw new \RuntimeException('Cannot secure supervisor lock file: ' . $path);
                }
            } else {
                // Another trusted process may have created the persistent file.
                clearstatcache(true, $path);
                $stat = @lstat($path);
                $handle = false;
            }
        } else {
            $handle = false;
        }
        if ($handle === false) {
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000
                || $stat['uid'] !== $owner || ($stat['mode'] & 0022) !== 0) {
                throw new \RuntimeException('Cannot use unsafe or inaccessible supervisor lock file: ' . $path);
            }
            $handle = @fopen($path, 'r+be');
        }
        if ($handle === false) {
            throw new \RuntimeException('Cannot open supervisor lock file: ' . $path);
        }
        $opened = fstat($handle);
        clearstatcache(true, $path);
        $current = @lstat($path);
        if ($opened === false || $current === false || $opened['ino'] !== $current['ino']
            || $opened['dev'] !== $current['dev'] || ($current['mode'] & 0170000) !== 0100000
            || $current['uid'] !== $owner || ($current['mode'] & 0022) !== 0) {
            fclose($handle);
            throw new \RuntimeException('Supervisor lock file changed or is unsafe: ' . $path);
        }
        $wouldBlock = 0;
        if (!@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);
            throw new \RuntimeException($wouldBlock !== 0
                ? 'Supervisor already running: ' . $name
                : 'Cannot acquire supervisor lock: ' . $path);
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            // Keep the inode: unlinking permits a competing process to lock a
            // new file while an earlier contender still holds the old one.
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
