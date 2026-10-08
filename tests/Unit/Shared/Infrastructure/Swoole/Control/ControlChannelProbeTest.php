<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ControlChannelProbeTest extends TestCase
{
    public function testRealThreeWorkerServerAnswersThroughTheControlSocket(): void
    {
        $probe = new Process([PHP_BINARY, __DIR__ . '/control-channel-probe.php']);
        // The native Swoole server probe runs in its own process. Xdebug
        // coverage of that child is not merged and crashes Swoole on startup.
        $probe->setEnv(['XDEBUG_MODE' => 'off']);
        $probe->setTimeout(30);
        $probe->run();
        self::assertTrue($probe->isSuccessful(), $probe->getOutput() . $probe->getErrorOutput());
        self::assertSame("Server control probe: 6 cases passed, server shut down.\n", $probe->getOutput());
        self::assertSame('', $probe->getErrorOutput());
    }
}
