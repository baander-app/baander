<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Swoole;

use Symfony\Component\Process\Process;

final class CoroutineProbe
{
    /** @param list<string> $arguments */
    public static function create(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, ...$arguments]);
        // Child coverage is not merged into PHPUnit, and Xdebug coverage crashes
        // native Swoole coroutine switching. Keep coverage enabled in the parent.
        $process->setEnv(['XDEBUG_MODE' => 'off']);

        return $process;
    }
}
