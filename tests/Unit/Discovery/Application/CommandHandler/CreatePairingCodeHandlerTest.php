<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application\CommandHandler;

use App\Discovery\Application\Command\CreatePairingCodeCommand;
use App\Discovery\Application\CommandHandler\CreatePairingCodeHandler;
use App\Discovery\Application\Port\DiscoveryPortInterface;
use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\Model\ServerInstance;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CreatePairingCodeHandlerTest extends TestCase
{
    private ServerInstancePortInterface&MockObject $serverPort;
    private DiscoveryPortInterface&MockObject $discoveryPort;
    private CreatePairingCodeHandler $handler;

    protected function setUp(): void
    {
        $this->serverPort = $this->createMock(ServerInstancePortInterface::class);
        $this->discoveryPort = $this->createMock(DiscoveryPortInterface::class);
        $this->handler = new CreatePairingCodeHandler($this->serverPort, $this->discoveryPort);
    }

    public function testCreatesPairingSessionWhenServerFound(): void
    {
        $server = ServerInstance::create(
            serverUrl: 'https://music.example.com',
            name: 'Home Server',
            version: '1.2.3',
            apiKey: 'secret-key',
        );
        $serverId = $server->getId();
        $serverPublicId = $server->getPublicId();

        $session = PairingSession::create(
            serverId: $serverId,
            serverPublicId: $serverPublicId,
            serverUrl: 'https://music.example.com',
            serverName: 'Home Server',
            method: AuthenticationMethod::QrCode,
        );

        $this->serverPort->expects($this->once())
            ->method('findByPublicId')
            ->with($serverPublicId)
            ->willReturn($server);
        $this->discoveryPort->expects($this->once())
            ->method('createPairingSession')
            ->with(
                $serverId,
                $serverPublicId,
                'https://music.example.com',
                'Home Server',
                AuthenticationMethod::QrCode,
            )
            ->willReturn($session);

        $result = ($this->handler)(new CreatePairingCodeCommand($serverPublicId, AuthenticationMethod::QrCode));

        $this->assertSame($session, $result);
        $this->assertSame(AuthenticationMethod::QrCode, $result->getMethod());
    }

    public function testThrowsWhenServerNotFound(): void
    {
        $serverPublicId = new PublicId();
        $this->serverPort->expects($this->once())
            ->method('findByPublicId')
            ->with($serverPublicId)
            ->willReturn(null);
        $this->discoveryPort->expects($this->never())->method('createPairingSession');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Server not found.');

        ($this->handler)(new CreatePairingCodeCommand($serverPublicId, AuthenticationMethod::QrCode));
    }
}
