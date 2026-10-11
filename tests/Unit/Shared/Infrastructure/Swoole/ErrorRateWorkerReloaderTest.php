<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Infrastructure\Swoole\ErrorRateWorkerReloader;
use PHPUnit\Framework\TestCase;
use Swoole\Server;

final class ErrorRateWorkerReloaderTest extends TestCase
{
    public function testFiveConsecutive5xxResponsesReloadTheWorkers(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::once())->method('reload')->willReturn(true);
        $reloader = new ErrorRateWorkerReloader(drain: $this->drain(false));
        $reloader->setServer($server);

        for ($error = 0; $error < 5; ++$error) {
            $reloader->recordError(0, new \RuntimeException('Database connection lost'));
        }
    }

    public function testWhileTheServerDrainsFive5xxResponsesReloadNothing(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::never())->method('reload');
        $reloader = new ErrorRateWorkerReloader(drain: $this->drain(true));
        $reloader->setServer($server);

        for ($error = 0; $error < 10; ++$error) {
            $reloader->recordError(0, new \RuntimeException('Database connection lost'));
        }
    }

    private function drain(bool $draining): ServerDrainStateInterface
    {
        $drain = $this->createStub(ServerDrainStateInterface::class);
        $drain->method('isDraining')->willReturn($draining);

        return $drain;
    }
}
