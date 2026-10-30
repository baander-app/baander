<?php

declare(strict_types=1);

namespace Baander\Tooling\Registry;

use Baander\Tooling\Inventory\Collector;

final class Support
{
    public static function requireThat(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public static function temporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        self::requireThat(mkdir($directory, 0700), 'Cannot create temporary directory.');
        return $directory;
    }

    public static function removeTree(string $path): void
    {
        Collector::remove($path);
    }
}
