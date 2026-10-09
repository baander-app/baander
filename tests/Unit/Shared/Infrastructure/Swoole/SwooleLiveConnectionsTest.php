<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerControlResult;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\SwooleLiveConnections;
use PHPUnit\Framework\TestCase;

final class SwooleLiveConnectionsTest extends TestCase
{
    public function testAsksTheServerToDisconnectTheUserAndReturnsTheCount(): void
    {
        $userId = Uuid::generate();
        $serverControl = $this->createMock(ServerControlPortInterface::class);
        $serverControl->expects(self::once())->method('execute')
            ->with('websocket.user.disconnect', ['user_id' => $userId->toString()])
            ->willReturn(new ServerControlResult([2 => ['closed' => 3, 'reconnect_tokens_revoked' => 1]]));

        self::assertSame(3, (new SwooleLiveConnections($serverControl))->closeForUser($userId));
    }

    public function testAWorkerErrorFailsTheClose(): void
    {
        $this->expectException(ServerControlException::class);
        $this->expectExceptionMessage('No WebSocket server is attached to this worker.');

        $this->closeWith(new ServerControlResult([], [0 => 'No WebSocket server is attached to this worker.']));
    }

    public function testAnAnswerWithoutACountFailsTheClose(): void
    {
        $this->expectException(ServerControlException::class);

        $this->closeWith(new ServerControlResult([0 => true]));
    }

    public function testNoWebServerInThisContainerIsReportedAsSuch(): void
    {
        $serverControl = $this->createStub(ServerControlPortInterface::class);
        $serverControl->method('execute')->willThrowException(new ServerNotRunningException());

        $this->expectException(ServerNotRunningException::class);
        (new SwooleLiveConnections($serverControl))->closeForUser(Uuid::generate());
    }

    private function closeWith(ServerControlResult $result): void
    {
        $serverControl = $this->createStub(ServerControlPortInterface::class);
        $serverControl->method('execute')->willReturn($result);

        (new SwooleLiveConnections($serverControl))->closeForUser(Uuid::generate());
    }
}
