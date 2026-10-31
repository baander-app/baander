<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use PHPUnit\Framework\TestCase;

final class WebSocketDisconnectProbeTest extends TestCase
{
    public function testARealServerClosesEveryConnectionOfAUserOnEveryWorker(): void
    {
        $probe = CoroutineProbe::create([__DIR__ . '/websocket-disconnect-probe.php']);
        $probe->setTimeout(30);
        $probe->run();
        self::assertTrue($probe->isSuccessful(), $probe->getOutput() . $probe->getErrorOutput());
        self::assertSame("WebSocket disconnect probe: 5 cases passed, server shut down.\n", $probe->getOutput());
        self::assertSame('', $probe->getErrorOutput());
    }
}
