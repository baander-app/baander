<?php

declare(strict_types=1);

namespace Baander\Tooling\Runtime;

final class Requirements
{
    /** @param list<string> $extensions */
    public static function check(bool $swoole = true, array $extensions = []): void
    {
        if (version_compare(PHP_VERSION, '8.5.0', '<')) {
            throw new \RuntimeException('PHP 8.5 or newer is required; running PHP ' . PHP_VERSION . '.');
        }
        if ($swoole && !extension_loaded('swoole')) {
            throw new \RuntimeException('PHP extension swoole is required for host tooling.');
        }
        foreach ($extensions as $extension) {
            if (!extension_loaded($extension)) {
                throw new \RuntimeException('PHP extension ' . $extension . ' is required for this command.');
            }
        }
    }
}
