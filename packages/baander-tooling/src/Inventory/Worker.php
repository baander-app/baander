<?php

declare(strict_types=1);

namespace Baander\Tooling\Inventory;

use Baander\Tooling\Process\ProcessSpec;

final class Worker
{
    /** @param list<string> $arguments
     * @param array<string,string> $environment
     */
    public static function command(string $loader, array $arguments, array $environment, ?string $log = null): ProcessSpec
    {
        return new ProcessSpec(
            [PHP_BINARY, '-d', 'memory_limit=-1', dirname(__DIR__, 2) . '/bin/inventory-worker.php', '--composer-loader', $loader, ...$arguments],
            environment: $environment,
            processGroup: true,
            logPath: $log,
        );
    }
}
