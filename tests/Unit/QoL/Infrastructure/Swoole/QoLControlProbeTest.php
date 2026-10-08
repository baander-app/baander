<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class QoLControlProbeTest extends TestCase
{
    public function testEveryWorkerOfARealServerTakesQoLChangesAndKeepsThemAcrossReloads(): void
    {
        $probe = new Process([PHP_BINARY, __DIR__ . '/qol-control-probe.php']);
        // The native Swoole server probe runs in its own process. Xdebug
        // coverage of that child is not merged and crashes Swoole on startup.
        $probe->setEnv(['XDEBUG_MODE' => 'off']);
        $probe->setTimeout(60);
        $probe->run();
        self::assertTrue($probe->isSuccessful(), $probe->getOutput() . $probe->getErrorOutput());
        self::assertSame("QoL control probe: 7 cases passed, server shut down.\n", $probe->getOutput());
        self::assertSame('', $probe->getErrorOutput());
    }
}
