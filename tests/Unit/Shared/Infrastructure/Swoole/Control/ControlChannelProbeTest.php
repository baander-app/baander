<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use PHPUnit\Framework\TestCase;

final class ControlChannelProbeTest extends TestCase
{
    public function testRealThreeWorkerServerAnswersThroughTheControlSocket(): void
    {
        $probe = CoroutineProbe::create([__DIR__ . '/control-channel-probe.php']);
        $probe->setTimeout(30);
        $probe->run();
        self::assertTrue($probe->isSuccessful(), $probe->getOutput() . $probe->getErrorOutput());
        self::assertSame("Server control probe: 6 cases passed, server shut down.\n", $probe->getOutput());
        self::assertSame('', $probe->getErrorOutput());
    }
}
