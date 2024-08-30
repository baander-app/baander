<?php

namespace App\Packages\Filesystem;

use App\Packages\Filesystem\Exceptions\UnreadablePathException;

class Path
{
    public static function exists(string $path): bool
    {
        $type = filetype($path);

        return $type === 'file' || $type === 'dir';
    }

    public static function isReadable(string $path): bool
    {
        return is_readable($path);
    }

    public static function isDirectory(string $path): bool
    {
        return filetype($path) === 'dir';
    }

    public static function isFile(string $path): bool
    {
        return filetype($path) === 'file';
    }

    /**
     * @param string $path
     *
     * @return int
     */
    public static function size(string $path): int
    {
        if (!static::isReadable($path)) {
            throw new UnreadablePathException($path);
        }

        if (static::isDirectory($path)) {
            $stat = stat($path);

            return $stat['size'];
        }

        return filesize($path);
    }
}
