<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use PHPUnit\Framework\TestCase;

final class ProcessExecutorTest extends TestCase
{
    public function testNativeProcessReportsSuccessfulOutput(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fwrite(STDOUT, "encoded");');

        $result = ProcessExecutor::exec($command);

        self::assertSame(['code' => 0, 'output' => 'encoded', 'error' => ''], $result);
    }

    public function testNativeProcessRetainsFailureExitCodeAndBothOutputStreams(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fwrite(STDOUT, "output"); fwrite(STDERR, "diagnostic"); exit(7);');

        $result = ProcessExecutor::exec($command);

        self::assertSame(['code' => 7, 'output' => 'output', 'error' => 'diagnostic'], $result);
    }
}
