<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application\CommandHandler;

use App\Discovery\Application\Command\RegisterServerCommand;
use App\Discovery\Application\CommandHandler\RegisterServerHandler;
use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Discovery\Domain\Event\ServerRegistered;
use App\Discovery\Domain\Model\ServerInstance;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class RegisterServerHandlerTest extends TestCase
{
    private ServerInstancePortInterface&MockObject $serverPort;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private RegisterServerHandler $handler;

    protected function setUp(): void
    {
        $this->serverPort = $this->createMock(ServerInstancePortInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(fn (object $e) => $e);
        $this->handler = new RegisterServerHandler($this->serverPort, $this->eventDispatcher);
    }

    public function testRegistersServerAndDispatchesEvent(): void
    {
        $server = ServerInstance::create(
            serverUrl: 'https://music.example.com',
            name: 'Home Server',
            version: '1.2.3',
            apiKey: 'secret-key',
        );

        $this->serverPort->expects($this->once())
            ->method('register')
            ->with('https://music.example.com', 'Home Server', '1.2.3', 'secret-key')
            ->willReturn($server);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn (object $e) => $e instanceof ServerRegistered
                && $e->getServerUrl() === 'https://music.example.com'
                && $e->getName() === 'Home Server'));

        $result = ($this->handler)(new RegisterServerCommand(
            'https://music.example.com',
            'Home Server',
            '1.2.3',
            'secret-key',
        ));

        $this->assertSame($server, $result);
    }
}
