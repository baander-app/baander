<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SwooleBinaryFileResponseTransportTest extends TestCase
{
    public function testRealSwooleEmitsPreparedFileBoundariesAndStopsCleanly(): void
    {
        $probe = new Process([PHP_BINARY, __DIR__ . '/swoole-binary-file-response-probe.php']);
        // The native Swoole server probe runs in its own process. Xdebug
        // coverage of that child is not merged and crashes Swoole on startup.
        $probe->setEnv(['XDEBUG_MODE' => 'off']);
        $probe->setTimeout(20);
        $probe->run();
        self::assertTrue($probe->isSuccessful(), $probe->getOutput() . $probe->getErrorOutput());
        self::assertSame("Real Swoole file transport: 4 cases passed, server shut down.\n", $probe->getOutput());
        self::assertSame('', $probe->getErrorOutput());
    }
}
