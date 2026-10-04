<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Transcode\Infrastructure\Swoole\TranscodePoolWorker;
use PHPUnit\Framework\TestCase;

final class TranscodePoolWorkerProcessTest extends TestCase
{
    public function testWorkerCapturesSuccessfulProcessOutput(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fwrite(STDOUT, "encoded");');
        $executor = new \ReflectionMethod(TranscodePoolWorker::class, 'exec');

        $result = $executor->invoke(new TranscodePoolWorker(), $command, 5);

        self::assertSame(['code' => 0, 'output' => 'encoded', 'stderr' => ''], $result);
    }

    public function testWorkerRetainsFailureExitCodeAndDiagnosticStreams(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fwrite(STDOUT, "output"); fwrite(STDERR, "diagnostic"); exit(7);');
        $executor = new \ReflectionMethod(TranscodePoolWorker::class, 'exec');

        $result = $executor->invoke(new TranscodePoolWorker(), $command, 5);

        self::assertSame(['code' => 7, 'output' => 'output', 'stderr' => 'diagnostic'], $result);
    }
}
