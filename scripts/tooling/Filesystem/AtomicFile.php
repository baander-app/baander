<?php

declare(strict_types=1);

namespace Baander\Tooling\Filesystem;

final class AtomicFile
{
    public static function write(string $path, string $content, int $mode = 0600): void
    {
        PrivateDirectory::assertNoSymlinks($path);
        $parent = dirname($path);
        PrivateDirectory::ensure($parent);
        $temporary = $parent . '/.' . basename($path) . '.' . bin2hex(random_bytes(12));
        $previousMask = umask(0077);
        try {
            $stream = @fopen($temporary, 'x+b');
        } finally {
            umask($previousMask);
        }
        if ($stream === false) {
            throw new \RuntimeException("Cannot create temporary state file: {$path}");
        }
        try {
            if (!@chmod($temporary, $mode)) {
                throw new \RuntimeException("Cannot set state file permissions: {$path}");
            }
            $length = strlen($content);
            $offset = 0;
            while ($offset < $length) {
                $written = @fwrite($stream, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException("Cannot write state file: {$path}");
                }
                $offset += $written;
            }
            if (!@fflush($stream) || !@fsync($stream)) {
                throw new \RuntimeException("Cannot synchronize state file: {$path}");
            }
            if (!fclose($stream)) {
                throw new \RuntimeException("Cannot close state file: {$path}");
            }
            $stream = null;
            PrivateDirectory::assertNoSymlinks($path);
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException("Cannot replace state file: {$path}");
            }
            $directory = @fopen($parent, 'r');
            if ($directory === false) {
                throw new \RuntimeException("Cannot open state directory for synchronization: {$parent}");
            }
            try {
                if (!@fsync($directory)) {
                    throw new \RuntimeException("Cannot synchronize state directory; saved state durability is uncertain: {$parent}");
                }
            } finally {
                fclose($directory);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param array<array-key, mixed> $value */
    public static function writeJson(string $path, array $value): void
    {
        try {
            $json = json_encode(self::sorted($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException("Cannot encode saved state: {$path}", previous: $error);
        }
        self::write($path, $json . "\n");
    }

    /** @return array<array-key, mixed> */
    public static function readJson(string $path): array
    {
        PrivateDirectory::assertNoSymlinks($path);
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read saved state; preserve it for recovery: {$path}");
        }
        try {
            $value = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException("Cannot read saved state; preserve it for recovery: {$path}", previous: $error);
        }
        if (!is_array($value)) {
            throw new \RuntimeException("Invalid saved state; preserve it for recovery: {$path}");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $value
     *  @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::sorted($item);
            }
        }

        return $value;
    }
}
