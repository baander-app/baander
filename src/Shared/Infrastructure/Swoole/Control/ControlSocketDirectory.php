<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

use RuntimeException;

/**
 * Prepares the directory that holds the server control socket.
 *
 * File permissions are the socket's authentication, so the directory must be a
 * real directory (not a symlink), owned by the server's user and mode 0700.
 */
final class ControlSocketDirectory
{
    /**
     * @param int|null $effectiveUid the server process's effective uid; null when
     *                               ext-posix is missing, which leaves only the chmod check
     */
    public static function prepare(string $directory, ?int $effectiveUid): void
    {
        if (!is_dir($directory)) {
            $umask = umask(0077);
            try {
                if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new RuntimeException(sprintf('Cannot create the server control directory %s.', $directory));
                }
            } finally {
                umask($umask);
            }
        }
        // The default lives under the shared /tmp, where another user can create the
        // directory first. Its owner must be the server's user: root could chmod it
        // anyway, and only the owner can change the mode when the uid is unknown.
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        if ($stat === false
            || ($stat['mode'] & 0170000) !== 0040000 // a directory, not a symlink to one
            || ($effectiveUid !== null && $stat['uid'] !== $effectiveUid)
            || !@chmod($directory, 0700)
        ) {
            throw new RuntimeException(sprintf(
                'The server control directory %s must be a directory owned by the server user.',
                $directory,
            ));
        }
    }
}
