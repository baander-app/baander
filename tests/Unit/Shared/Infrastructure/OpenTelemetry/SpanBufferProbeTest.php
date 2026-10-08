<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\OpenTelemetry;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SpanBufferProbeTest extends TestCase
{
    public function testSpansFromEveryWorkerReachTheCommandThroughTheSharedBuffer(): void
    {
        $probe = new Process([PHP_BINARY, __DIR__ . '/span-buffer-probe.php']);
        // The native Swoole server probe runs in its own process. Xdebug
        // coverage of that child is not merged and crashes Swoole on startup.
        $probe->setEnv(['XDEBUG_MODE' => 'off']);
        $probe->setTimeout(30);
        $probe->run();
        self::assertTrue($probe->isSuccessful(), $probe->getOutput() . $probe->getErrorOutput());
        self::assertSame("Span buffer probe: 5 cases passed, server shut down.\n", $probe->getOutput());
        self::assertSame('', $probe->getErrorOutput());
    }
}
