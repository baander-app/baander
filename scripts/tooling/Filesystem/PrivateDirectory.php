<?php

declare(strict_types=1);

namespace Baander\Tooling\Filesystem;

final class PrivateDirectory
{
    public static function prepare(string $path): void
    {
        self::inspect($path, true);
        self::assertOwned($path);
        if (!@chmod($path, 0700)) {
            throw new \RuntimeException("Cannot make development directory private: {$path}");
        }
    }

    public static function ensure(string $path): void
    {
        self::inspect($path, true);
        self::assertOwned($path);
        $info = stat($path);
        if ($info === false || ($info['mode'] & 0022) !== 0) {
            throw new \RuntimeException("The development directory permits writes by another user: {$path}");
        }
    }

    private static function assertOwned(string $path): void
    {
        $info = stat($path);
        $owner = stat('/proc/self');
        if ($info === false || $owner === false || $info['uid'] !== $owner['uid']) {
            throw new \RuntimeException("The development directory is owned by another user: {$path}");
        }
    }

    public static function assertNoSymlinks(string $path): void
    {
        self::inspect($path, false);
    }

    private static function inspect(string $path, bool $createDirectories): void
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \RuntimeException('Invalid development state path.');
        }
        $absolute = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
        $current = '';
        foreach (explode('/', $absolute) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                throw new \RuntimeException("Refusing parent traversal in development state path: {$path}");
            }
            $current .= '/' . $component;
            clearstatcache(true, $current);
            $info = @lstat($current);
            if ($info === false && $createDirectories) {
                if (!@mkdir($current, 0700) && !is_dir($current)) {
                    throw new \RuntimeException("Cannot create development directory: {$current}");
                }
                $info = lstat($current);
            }
            if ($info !== false && ($info['mode'] & 0170000) === 0120000) {
                throw new \RuntimeException("Refusing symlink development state path: {$current}");
            }
            if ($createDirectories && ($info === false || ($info['mode'] & 0170000) !== 0040000)) {
                throw new \RuntimeException("Not a development directory: {$current}");
            }
        }
    }
}
