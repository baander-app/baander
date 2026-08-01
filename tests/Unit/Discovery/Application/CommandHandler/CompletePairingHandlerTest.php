<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application\CommandHandler;

use App\Discovery\Application\Command\CompletePairingCommand;
use App\Discovery\Application\CommandHandler\CompletePairingHandler;
use App\Discovery\Application\Port\DiscoveryPortInterface;
use App\Discovery\Domain\Event\PairingCompleted;
use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\Model\PairingSessionState;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class CompletePairingHandlerTest extends TestCase
{
    private DiscoveryPortInterface&MockObject $discoveryPort;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private CompletePairingHandler $handler;

    protected function setUp(): void
    {
        $this->discoveryPort = $this->createMock(DiscoveryPortInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(fn (object $e) => $e);
        $this->handler = new CompletePairingHandler($this->discoveryPort, $this->eventDispatcher);
    }

    public function testCompletesPendingSessionAndDispatchesEvent(): void
    {
        $serverPublicId = new PublicId();
        $session = $this->createPendingSession($serverPublicId);

        $this->discoveryPort->method('findByPairingCode')->willReturn($session);
        // The port is responsible for mutating the session; emulate that so we can
        // assert the handler wires through to a completed end-state.
        $this->discoveryPort->expects($this->once())
            ->method('completePairing')
            ->with($session)
            ->willReturnCallback(fn (PairingSession $s) => $s->complete());
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn (object $e) => $e instanceof PairingCompleted));

        $result = ($this->handler)(new CompletePairingCommand(
            $session->getPairingCode()->toString(),
            $serverPublicId,
        ));

        $this->assertTrue($result->isCompleted());
    }

    public function testReturnsAlreadyCompletedSessionWithoutDispatching(): void
    {
        $serverPublicId = new PublicId();
        $session = $this->createPendingSession($serverPublicId);
        $session->complete();

        $this->discoveryPort->method('findByPairingCode')->willReturn($session);
        $this->discoveryPort->expects($this->never())->method('completePairing');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $result = ($this->handler)(new CompletePairingCommand(
            $session->getPairingCode()->toString(),
            $serverPublicId,
        ));

        $this->assertTrue($result->isCompleted());
    }

    public function testThrowsWhenSessionNotFound(): void
    {
        $this->discoveryPort->method('findByPairingCode')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pairing session not found.');

        ($this->handler)(new CompletePairingCommand('BCDF-GHJK', new PublicId()));
    }

    public function testThrowsWhenSessionExpired(): void
    {
        $session = $this->createExpiredSession(new PublicId());
        $this->discoveryPort->method('findByPairingCode')->willReturn($session);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pairing session has expired.');

        ($this->handler)(new CompletePairingCommand(
            $session->getPairingCode()->toString(),
            $session->getServerPublicId(),
        ));
    }

    public function testThrowsWhenSessionBelongsToDifferentServer(): void
    {
        $session = $this->createPendingSession(new PublicId());
        $this->discoveryPort->method('findByPairingCode')->willReturn($session);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not belong to this server');

        ($this->handler)(new CompletePairingCommand(
            $session->getPairingCode()->toString(),
            new PublicId(),
        ));
    }

    private function createPendingSession(PublicId $serverPublicId): PairingSession
    {
        return PairingSession::create(
            serverId: Uuid::v4(),
            serverPublicId: $serverPublicId,
            serverUrl: 'https://music.example.com',
            serverName: 'Home Server',
            method: AuthenticationMethod::QrCode,
        );
    }

    private function createExpiredSession(PublicId $serverPublicId): PairingSession
    {
        return PairingSession::reconstitute(new PairingSessionState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            serverId: Uuid::v4(),
            serverPublicId: $serverPublicId,
            serverUrl: 'https://music.example.com',
            serverName: 'Home Server',
            pairingCode: PairingCode::fromString('BCDF-GHJK'),
            method: AuthenticationMethod::QrCode,
            expiresAt: new DateTimeImmutable('-1 minute'),
            createdAt: new DateTimeImmutable('-10 minutes'),
        ));
    }
}
