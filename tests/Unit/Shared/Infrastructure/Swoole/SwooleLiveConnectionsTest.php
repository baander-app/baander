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
            ->willReturn(new ServerControlResult([2 => ['closed' => 3]]));

        self::assertSame(3, (new SwooleLiveConnections($serverControl))->closeForUser($userId));
    }

    public function testAsksTheServerToDisconnectTheAccessTokensAndReturnsTheCount(): void
    {
        $serverControl = $this->createMock(ServerControlPortInterface::class);
        $serverControl->expects(self::once())->method('execute')
            ->with('websocket.access_token.disconnect', ['access_token_ids' => ['token-a', 'token-b']])
            ->willReturn(new ServerControlResult([1 => ['closed' => 2]]));

        self::assertSame(2, (new SwooleLiveConnections($serverControl))->closeForAccessTokens(['token-a', 'token-b']));
    }

    public function testNoAccessTokensMeansNothingToCloseAndNoServerCall(): void
    {
        $serverControl = $this->createMock(ServerControlPortInterface::class);
        $serverControl->expects(self::never())->method('execute');

        self::assertSame(0, (new SwooleLiveConnections($serverControl))->closeForAccessTokens([]));
    }

    public function testAWorkerErrorFailsTheAccessTokenClose(): void
    {
        $serverControl = $this->createStub(ServerControlPortInterface::class);
        $serverControl->method('execute')->willReturn(new ServerControlResult([], [0 => 'No WebSocket server is attached to this worker.']));

        $this->expectException(ServerControlException::class);
        (new SwooleLiveConnections($serverControl))->closeForAccessTokens(['token-a']);
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
