<?php

declare(strict_types=1);

namespace Baander\Tooling\Filesystem;

final class Paths
{
    public static function absolutePath(string $path): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            $path = (getenv('HOME') ?: '') . substr($path, 1);
        }
        if (!str_starts_with($path, '/')) {
            $path = getcwd() . '/' . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        return '/' . implode('/', $parts);
    }

    public static function resolvedPath(string $path): string
    {
        $path = self::absolutePath($path);
        if (($resolved = realpath($path)) !== false) {
            return $resolved;
        }
        if ($path === '/') {
            return $path;
        }
        return rtrim(self::resolvedPath(dirname($path)), '/') . '/' . basename($path);
    }

}
